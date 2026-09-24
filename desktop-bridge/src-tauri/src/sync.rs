// ponytail: Core synchronization engine with chunked batching (MySQL Local -> Cloudflare Remote Web)
use crate::config::SyncConfig;
use crate::db::{fetch_table_as_json, get_mysql_pool};
use serde::{Deserialize, Serialize};
use serde_json::json;
use std::collections::HashMap;
use std::time::Duration;

const CHUNK_SIZE: usize = 50;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SyncResult {
    pub success: bool,
    pub message: String,
    pub stats: HashMap<String, usize>,
    pub timestamp: String,
}

pub fn test_remote_url(config: &SyncConfig) -> Result<String, String> {
    let client = reqwest::blocking::Client::builder()
        .timeout(Duration::from_secs(10))
        .danger_accept_invalid_certs(true)
        .build()
        .map_err(|e| format!("HTTP Client error: {}", e))?;

    let resp = client
        .get(&config.remote_sync_url)
        .header("Authorization", format!("Bearer {}", config.sync_token))
        .header("X-Bridge-Token", &config.sync_token)
        .header("User-Agent", "Tauri-Desktop-Bridge/1.0")
        .send()
        .map_err(|e| format!("Request failed: {}", e))?;

    let status = resp.status();
    let body_text = resp.text().unwrap_or_default();

    if status.is_success() {
        Ok(format!("Remote endpoint reached! Status: {}", status))
    } else {
        Err(format!("Remote returned HTTP {}: {}", status, body_text))
    }
}

pub fn execute_sync(config: &SyncConfig) -> Result<SyncResult, String> {
    // 1. Connect to Local MySQL
    let pool = get_mysql_pool(config)?;
    let mut conn = pool.get_conn().map_err(|e| format!("MySQL connection failed: {}", e))?;

    // 2. HTTP Client with resilient timeout & headers
    let client = reqwest::blocking::Client::builder()
        .timeout(Duration::from_secs(30))
        .danger_accept_invalid_certs(true)
        .build()
        .map_err(|e| format!("HTTP Client error: {}", e))?;


    let target_tables = vec!["users", "projects", "gba_tasks", "new_tasks"];
    let mut local_stats = HashMap::new();
    let mut total_chunks_sent = 0;

    // 3. Process table by table and chunk by chunk
    for table in target_tables {
        match fetch_table_as_json(&mut conn, table) {
            Ok(rows) => {
                let total_rows = rows.len();
                local_stats.insert(table.to_string(), total_rows);

                if total_rows == 0 {
                    continue;
                }

                // Chunk into small batches to bypass intranet/proxy payload limits
                for (chunk_idx, chunk) in rows.chunks(CHUNK_SIZE).enumerate() {
                    let mut table_map = serde_json::Map::new();
                    table_map.insert(table.to_string(), serde_json::Value::Array(chunk.to_vec()));

                    let payload = json!({
                        "tables": table_map,
                        "sync_source": "Tauri-Desktop-Bridge",
                        "client_timestamp": chrono::Local::now().to_rfc3339()
                    });

                    let response = client
                        .post(&config.remote_sync_url)
                        .header("Authorization", format!("Bearer {}", config.sync_token))
                        .header("X-Bridge-Token", &config.sync_token)
                        .header("User-Agent", "Tauri-Desktop-Bridge/1.0")
                        .header("Content-Type", "application/json")
                        .json(&payload)
                        .send()
                        .map_err(|e| {
                            format!(
                                "HTTP POST gagal pada tabel '{}' (chunk #{}/{}): {}",
                                table,
                                chunk_idx + 1,
                                (total_rows + CHUNK_SIZE - 1) / CHUNK_SIZE,
                                e
                            )
                        })?;

                    let status = response.status();
                    if !status.is_success() {
                        let resp_text = response.text().unwrap_or_default();
                        return Err(format!(
                            "Server error pada tabel '{}' chunk #{} (HTTP {}): {}",
                            table,
                            chunk_idx + 1,
                            status,
                            resp_text
                        ));
                    }

                    total_chunks_sent += 1;
                }
            }
            Err(e) => {
                eprintln!("Warning: skipping table '{}': {}", table, e);
            }
        }
    }

    let now_str = chrono::Local::now().format("%Y-%m-%d %H:%M:%S").to_string();

    Ok(SyncResult {
        success: true,
        message: format!("Sync berhasil ({total_chunks_sent} chunk terkirim ke remote)"),
        stats: local_stats,
        timestamp: now_str,
    })
}
