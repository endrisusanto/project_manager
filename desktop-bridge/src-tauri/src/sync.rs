// ponytail: Core synchronization engine (MySQL Local -> Cloudflare Remote Web)
use crate::config::SyncConfig;
use crate::db::{fetch_table_as_json, get_mysql_pool};
use serde::{Deserialize, Serialize};
use serde_json::json;
use std::collections::HashMap;
use std::time::Duration;

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
        .build()
        .map_err(|e| format!("HTTP Client error: {}", e))?;

    let resp = client
        .get(&config.remote_sync_url)
        .header("Authorization", format!("Bearer {}", config.sync_token))
        .header("X-Bridge-Token", &config.sync_token)
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

    // 2. Fetch required tables
    let mut tables_payload = serde_json::Map::new();
    let mut local_stats = HashMap::new();

    let target_tables = vec!["users", "projects", "gba_tasks", "new_tasks"];

    for table in target_tables {
        match fetch_table_as_json(&mut conn, table) {
            Ok(rows) => {
                local_stats.insert(table.to_string(), rows.len());
                tables_payload.insert(table.to_string(), serde_json::Value::Array(rows));
            }
            Err(e) => {
                // If table doesn't exist or query failed, log and continue with remaining tables
                eprintln!("Warning: skipping table '{}': {}", table, e);
            }
        }
    }

    let payload = json!({
        "tables": tables_payload,
        "sync_source": "Tauri-Desktop-Bridge",
        "client_timestamp": chrono::Local::now().to_rfc3339()
    });

    // 3. POST to Remote Cloudflare Endpoint
    let client = reqwest::blocking::Client::builder()
        .timeout(Duration::from_secs(30))
        .build()
        .map_err(|e| format!("HTTP Client error: {}", e))?;

    let response = client
        .post(&config.remote_sync_url)
        .header("Authorization", format!("Bearer {}", config.sync_token))
        .header("X-Bridge-Token", &config.sync_token)
        .header("Content-Type", "application/json")
        .json(&payload)
        .send()
        .map_err(|e| format!("HTTP POST failed: {}", e))?;

    let status = response.status();
    let resp_text = response.text().unwrap_or_default();

    if !status.is_success() {
        return Err(format!("Remote server error (HTTP {}): {}", status, resp_text));
    }

    let now_str = chrono::Local::now().format("%Y-%m-%d %H:%M:%S").to_string();

    Ok(SyncResult {
        success: true,
        message: format!("Sync berhasil dikirim ke {}", config.remote_sync_url),
        stats: local_stats,
        timestamp: now_str,
    })
}
