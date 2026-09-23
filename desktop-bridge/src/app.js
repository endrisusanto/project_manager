// ponytail: Tauri Frontend Bridge Controller
const { invoke } = window.__TAURI__.core;

function switchTab(tabId) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));

    const activeBtn = document.querySelector(`.tab-btn[onclick="switchTab('${tabId}')"]`);
    if (activeBtn) activeBtn.classList.add('active');

    const activePane = document.getElementById(`tab-${tabId}`);
    if (activePane) activePane.classList.add('active');
}

function appendLog(message, type = 'info') {
    const box = document.getElementById('terminal-logs');
    if (!box) return;

    const time = new Date().toLocaleTimeString('id-ID');
    const entry = document.createElement('div');
    entry.className = `log-entry ${type}`;
    entry.innerHTML = `<span class="time">[${time}]</span> ${message}`;
    box.appendChild(entry);
    box.scrollTop = box.scrollHeight;
}

function clearLogs() {
    const box = document.getElementById('terminal-logs');
    if (box) box.innerHTML = '';
}

async function loadSettingsIntoForm() {
    try {
        const cfg = await invoke('cmd_get_config');
        document.getElementById('cfg-db-host').value = cfg.local_db_host || '127.0.0.1';
        document.getElementById('cfg-db-port').value = cfg.local_db_port || 3306;
        document.getElementById('cfg-db-user').value = cfg.local_db_user || 'root';
        document.getElementById('cfg-db-pass').value = cfg.local_db_password || '';
        document.getElementById('cfg-db-name').value = cfg.local_db_name || 'project_manager_db';
        document.getElementById('cfg-remote-url').value = cfg.remote_sync_url || '';
        document.getElementById('cfg-sync-token').value = cfg.sync_token || '';
        document.getElementById('cfg-sync-interval').value = cfg.sync_interval_seconds || 30;
        document.getElementById('cfg-auto-sync').checked = !!cfg.auto_sync_enabled;

        const autoSyncEl = document.getElementById('display-auto-sync');
        if (autoSyncEl) {
            if (cfg.auto_sync_enabled) {
                autoSyncEl.className = 'badge-pill active';
                autoSyncEl.textContent = `Aktif (${cfg.sync_interval_seconds}s)`;
            } else {
                autoSyncEl.className = 'badge-pill inactive';
                autoSyncEl.textContent = 'Nonaktif';
            }
        }
    } catch (err) {
        appendLog('Gagal memuat konfigurasi: ' + err, 'error');
    }
}

function getFormConfig() {
    return {
        local_db_host: document.getElementById('cfg-db-host').value.trim(),
        local_db_port: parseInt(document.getElementById('cfg-db-port').value) || 3306,
        local_db_user: document.getElementById('cfg-db-user').value.trim(),
        local_db_password: document.getElementById('cfg-db-pass').value,
        local_db_name: document.getElementById('cfg-db-name').value.trim(),
        remote_sync_url: document.getElementById('cfg-remote-url').value.trim(),
        sync_token: document.getElementById('cfg-sync-token').value.trim(),
        sync_interval_seconds: parseInt(document.getElementById('cfg-sync-interval').value) || 30,
        auto_sync_enabled: document.getElementById('cfg-auto-sync').checked,
    };
}

async function saveSettings() {
    try {
        const config = getFormConfig();
        const msg = await invoke('cmd_save_config', { config });
        appendLog(msg, 'success');
        loadSettingsIntoForm();
    } catch (err) {
        appendLog('Gagal menyimpan: ' + err, 'error');
    }
}

async function testLocalDb() {
    appendLog('Menguji koneksi ke MySQL Localhost...', 'info');
    try {
        const config = getFormConfig();
        const msg = await invoke('cmd_test_db', { config });
        appendLog('MySQL: ' + msg, 'success');
    } catch (err) {
        appendLog('MySQL Error: ' + err, 'error');
    }
}

async function testRemoteEndpoint() {
    appendLog('Menguji koneksi ke endpoint Cloudflare remote...', 'info');
    try {
        const config = getFormConfig();
        const msg = await invoke('cmd_test_remote', { config });
        appendLog('Remote Cloudflare: ' + msg, 'success');
    } catch (err) {
        appendLog('Remote Error: ' + err, 'error');
    }
}

async function triggerManualSync() {
    const btn = document.getElementById('btn-manual-sync');
    const statusPill = document.getElementById('global-status-pill');
    const statusText = document.getElementById('global-status-text');

    if (btn) {
        btn.disabled = true;
        btn.classList.add('spinning');
    }
    if (statusText) statusText.textContent = 'Syncing...';

    appendLog('Memulai sinkronisasi manual...', 'info');

    try {
        const res = await invoke('cmd_trigger_sync');
        appendLog(`Sync Berhasil! (${res.timestamp})`, 'success');
        updateStatsView(res);
        if (statusText) statusText.textContent = 'Siap';
    } catch (err) {
        appendLog('Sync Gagal: ' + err, 'error');
        if (statusText) statusText.textContent = 'Error';
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.classList.remove('spinning');
        }
    }
}

function updateStatsView(result) {
    if (!result) return;

    const timeEl = document.getElementById('display-last-sync');
    if (timeEl && result.timestamp) {
        timeEl.textContent = result.timestamp;
    }

    if (result.stats) {
        const gbaEl = document.getElementById('stat-gba-tasks');
        const projEl = document.getElementById('stat-projects');
        const userEl = document.getElementById('stat-users');
        const ntEl = document.getElementById('stat-new-tasks');

        if (gbaEl) gbaEl.textContent = result.stats['gba_tasks'] || 0;
        if (projEl) projEl.textContent = result.stats['projects'] || 0;
        if (userEl) userEl.textContent = result.stats['users'] || 0;
        if (ntEl) ntEl.textContent = result.stats['new_tasks'] || 0;
    }
}

async function checkAppUpdate() {
    const btn = document.getElementById('btn-check-update');
    const msgEl = document.getElementById('update-status-msg');

    if (btn) {
        btn.disabled = true;
        btn.classList.add('spinning');
    }
    if (msgEl) {
        msgEl.textContent = 'Memeriksa pembaruan & verifikasi signature...';
        msgEl.style.color = '#38bdf8';
    }
    appendLog('Memeriksa server pembaruan software (Ed25519 Minisign)...', 'info');

    try {
        const res = await invoke('cmd_check_update');
        if (res.has_update) {
            appendLog(`Update v${res.version} ditemukan: ${res.message}`, 'success');
            if (msgEl) {
                msgEl.textContent = `Update v${res.version} terpasang! Restart aplikasi.`;
                msgEl.style.color = '#4ade80';
            }
        } else {
            appendLog(res.message, 'info');
            if (msgEl) {
                msgEl.textContent = res.message;
                msgEl.style.color = '#94a3b8';
            }
        }
    } catch (err) {
        appendLog('Pemeriksaan update gagal: ' + err, 'error');
        if (msgEl) {
            msgEl.textContent = 'Gagal memeriksa update: ' + err;
            msgEl.style.color = '#f87171';
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.classList.remove('spinning');
        }
    }
}

// Periodic status poll from background thread
async function pollBackgroundStatus() {
    try {
        const last = await invoke('cmd_get_last_status');
        if (last) {
            updateStatsView(last);
        }
    } catch (_) {}
}

document.addEventListener('DOMContentLoaded', () => {
    loadSettingsIntoForm();
    pollBackgroundStatus();
    setInterval(pollBackgroundStatus, 5000);
});
