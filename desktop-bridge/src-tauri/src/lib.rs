// ponytail: Tauri v2 main library with Tray, IPC commands & Auto-Updater
mod config;
mod db;
mod sync;

use config::{load_config, save_config, SyncConfig};
use db::test_local_connection;
use std::sync::{Arc, Mutex};
use sync::{execute_sync, test_remote_url, SyncResult};
use tauri::menu::{MenuBuilder, MenuItemBuilder};
use tauri::tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Manager, State};
use tauri_plugin_updater::UpdaterExt;

#[derive(Default)]
pub struct AppState {
    pub last_result: Mutex<Option<SyncResult>>,
    pub is_syncing: Mutex<bool>,
}

#[tauri::command]
fn cmd_get_config() -> SyncConfig {
    load_config()
}

#[tauri::command]
fn cmd_save_config(config: SyncConfig) -> Result<String, String> {
    save_config(&config);
    Ok("Konfigurasi berhasil disimpan.".to_string())
}

#[tauri::command]
fn cmd_test_db(config: SyncConfig) -> Result<String, String> {
    test_local_connection(&config)
}

#[tauri::command]
fn cmd_test_remote(config: SyncConfig) -> Result<String, String> {
    test_remote_url(&config)
}

#[tauri::command]
fn cmd_trigger_sync(state: State<'_, Arc<AppState>>) -> Result<SyncResult, String> {
    let mut is_syncing = state.is_syncing.lock().unwrap();
    if *is_syncing {
        return Err("Proses sinkronisasi sedang berjalan...".to_string());
    }
    *is_syncing = true;
    drop(is_syncing);

    let config = load_config();
    let result = execute_sync(&config);

    let mut is_syncing = state.is_syncing.lock().unwrap();
    *is_syncing = false;

    match result {
        Ok(res) => {
            let mut last = state.last_result.lock().unwrap();
            *last = Some(res.clone());
            Ok(res)
        }
        Err(e) => Err(e),
    }
}

#[tauri::command]
fn cmd_get_last_status(state: State<'_, Arc<AppState>>) -> Option<SyncResult> {
    let last = state.last_result.lock().unwrap();
    last.clone()
}

#[tauri::command]
async fn cmd_check_update(app: AppHandle) -> Result<serde_json::Value, String> {
    let updater = app.updater().map_err(|e| format!("Inisialisasi Updater gagal: {}", e))?;
    let update = updater.check().await.map_err(|e| format!("Pemeriksaan update gagal: {}", e))?;

    if let Some(update) = update {
        let version = update.version.clone();
        let body = update.body.clone().unwrap_or_default();
        let date = update.date.map(|d| d.to_string()).unwrap_or_default();

        // Download and install automatically
        update.download_and_install(|_chunk, _total| {}, || {}).await
            .map_err(|e| format!("Download & Install update gagal: {}", e))?;

        Ok(serde_json::json!({
            "has_update": true,
            "version": version,
            "notes": body,
            "date": date,
            "message": "Update berhasil dipasang! Silakan restart aplikasi."
        }))
    } else {
        Ok(serde_json::json!({
            "has_update": false,
            "message": "Aplikasi Anda sudah versi terbaru."
        }))
    }
}

pub fn run() {
    let state = Arc::new(AppState::default());
    let state_for_tray = Arc::clone(&state);
    let state_for_bg = Arc::clone(&state);

    tauri::Builder::default()
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_process::init())
        .plugin(tauri_plugin_updater::Builder::new().build())
        .manage(state)
        .setup(move |app| {
            // 1. Setup System Tray Menu
            let toggle_item = MenuItemBuilder::with_id("toggle", "🖥️ Buka / Sembunyikan Dashboard").build(app)?;
            let sync_item = MenuItemBuilder::with_id("sync_now", "🔄 Sync Database Sekarang").build(app)?;
            let quit_item = MenuItemBuilder::with_id("quit", "❌ Keluar").build(app)?;

            let tray_menu = MenuBuilder::new(app)
                .items(&[&toggle_item, &sync_item, &quit_item])
                .build()?;

            let _tray = TrayIconBuilder::new()
                .icon(app.default_window_icon().unwrap().clone())
                .tooltip("GBA Bridge Sync (Active)")
                .menu(&tray_menu)
                .show_menu_on_left_click(false)
                .on_menu_event(move |app_handle, event| match event.id().as_ref() {
                    "toggle" => {
                        if let Some(window) = app_handle.get_webview_window("main") {
                            if window.is_visible().unwrap_or(false) {
                                let _ = window.hide();
                            } else {
                                let _ = window.show();
                                let _ = window.set_focus();
                            }
                        }
                    }
                    "sync_now" => {
                        let cfg = load_config();
                        let state_clone = Arc::clone(&state_for_tray);
                        std::thread::spawn(move || {
                            if let Ok(res) = execute_sync(&cfg) {
                                let mut last = state_clone.last_result.lock().unwrap();
                                *last = Some(res);
                            }
                        });
                    }
                    "quit" => {
                        app_handle.exit(0);
                    }
                    _ => {}
                })
                .on_tray_icon_event(|tray, event| {
                    if let TrayIconEvent::Click {
                        button: MouseButton::Left,
                        button_state: MouseButtonState::Up,
                        ..
                    } = event
                    {
                        let app = tray.app_handle();
                        if let Some(window) = app.get_webview_window("main") {
                            if window.is_visible().unwrap_or(false) {
                                let _ = window.hide();
                            } else {
                                let _ = window.show();
                                let _ = window.set_focus();
                            }
                        }
                    }
                })
                .build(app)?;

            // 2. Prevent window close from terminating app (Minimize to Tray instead)
            if let Some(window) = app.get_webview_window("main") {
                let win_clone = window.clone();
                window.on_window_event(move |event| {
                    if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                        api.prevent_close();
                        let _ = win_clone.hide();
                    }
                });
            }

            // 3. Background Sync Loop
            std::thread::spawn(move || loop {
                let cfg = load_config();
                let interval = if cfg.sync_interval_seconds < 5 { 5 } else { cfg.sync_interval_seconds };

                if cfg.auto_sync_enabled {
                    if let Ok(res) = execute_sync(&cfg) {
                        let mut last = state_for_bg.last_result.lock().unwrap();
                        *last = Some(res);
                    }
                }

                std::thread::sleep(std::time::Duration::from_secs(interval));
            });

            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            cmd_get_config,
            cmd_save_config,
            cmd_test_db,
            cmd_test_remote,
            cmd_trigger_sync,
            cmd_get_last_status,
            cmd_check_update
        ])
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
