// ponytail: Config management for GBA Bridge Sync
use serde::{Deserialize, Serialize};
use std::fs;
use std::path::PathBuf;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SyncConfig {
    pub local_db_host: String,
    pub local_db_port: u16,
    pub local_db_user: String,
    pub local_db_password: String,
    pub local_db_name: String,
    pub remote_sync_url: String,
    pub sync_token: String,
    pub sync_interval_seconds: u64,
    pub auto_sync_enabled: bool,
}

impl Default for SyncConfig {
    fn default() -> Self {
        Self {
            local_db_host: "127.0.0.1".to_string(),
            local_db_port: 3306,
            local_db_user: "root".to_string(),
            local_db_password: "".to_string(),
            local_db_name: "project_manager_db".to_string(),
            remote_sync_url: "https://gba.endrisusanto.my.id/api_sync_receiver.php".to_string(),
            sync_token: "gba-bridge-sync-key-2026".to_string(),
            sync_interval_seconds: 30,
            auto_sync_enabled: true,
        }
    }
}

pub fn get_config_path() -> PathBuf {
    let mut path = dirs::config_dir().unwrap_or_else(|| PathBuf::from("."));
    path.push("gba-bridge-sync");
    let _ = fs::create_dir_all(&path);
    path.push("bridge_config.json");
    path
}

pub fn load_config() -> SyncConfig {
    let path = get_config_path();
    if path.exists() {
        if let Ok(content) = fs::read_to_string(&path) {
            if let Ok(cfg) = serde_json::from_str::<SyncConfig>(&content) {
                return cfg;
            }
        }
    }
    let default_cfg = SyncConfig::default();
    save_config(&default_cfg);
    default_cfg
}

pub fn save_config(config: &SyncConfig) {
    let path = get_config_path();
    if let Ok(content) = serde_json::to_string_pretty(config) {
        let _ = fs::write(path, content);
    }
}
