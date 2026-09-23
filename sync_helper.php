<?php
// ponytail: Non-blocking HTTP broadcast trigger to Node.js WSS Hub
function trigger_remote_sync_broadcast($table, $action, $data = null) {
    $hubUrl = 'http://127.0.0.1:3800/api/sync/broadcast';
    $token = 'gba-bridge-sync-key-2026';
    
    $payload = json_encode([
        'table' => $table,
        'action' => $action,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

    $ch = curl_init($hubUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500); // Non-blocking fast timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 300);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]);
    @curl_exec($ch);
    @curl_close($ch);
}
