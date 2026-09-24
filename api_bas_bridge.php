<?php
// api_bas_bridge.php - Receiver endpoint for BAS session tokens from Browser Extension

// CORS Headers for Extension communication
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$session_file = __DIR__ . '/.bas_session.json';

// Handle GET request to check status
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!file_exists($session_file)) {
        echo json_encode([
            'success' => false,
            'status' => 'disconnected',
            'message' => 'No BAS session found. Please open Build Approval System in your browser.',
            'active' => false
        ]);
        exit;
    }

    $raw = @file_get_contents($session_file);
    $data = json_decode($raw, true);

    if (!$data || empty($data['sid'])) {
        echo json_encode([
            'success' => false,
            'status' => 'invalid',
            'message' => 'Invalid session data stored.',
            'active' => false
        ]);
        exit;
    }

    $updated_at = $data['updated_at'] ?? (isset($data['timestamp']) ? date('Y-m-d H:i:s', $data['timestamp']) : 'Unknown');
    $timestamp = $data['timestamp'] ?? 0;
    $age_seconds = time() - $timestamp;
    $age_hours = round($age_seconds / 3600, 1);
    $is_active = $age_seconds < (8 * 3600); // Active if < 8 hours

    echo json_encode([
        'success' => true,
        'status' => $is_active ? 'active' : 'expired',
        'active' => $is_active,
        'updated_at' => $updated_at,
        'age_hours' => $age_hours,
        'has_device' => !empty($data['device'])
    ]);
    exit;
}

// Handle POST request to save session token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_json = file_get_contents('php://input');
    $payload = json_decode($input_json, true);

    if (!$payload) {
        $payload = $_POST;
    }

    $sid = trim($payload['sid'] ?? '');
    $device = trim($payload['device'] ?? '');
    $cookie_header = trim($payload['cookie_header'] ?? '');
    $timestamp = isset($payload['timestamp']) ? (int)$payload['timestamp'] : time();

    if (empty($sid)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Parameter `sid` is required.'
        ]);
        exit;
    }

    date_default_timezone_set('Asia/Jakarta');
    $session_data = [
        'sid' => $sid,
        'device' => $device,
        'cookie_header' => $cookie_header,
        'timestamp' => $timestamp,
        'updated_at' => date('Y-m-d H:i:s', $timestamp),
        'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ];

    if (file_put_contents($session_file, json_encode($session_data, JSON_PRETTY_PRINT)) === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to write session file to disk.'
        ]);
        exit;
    }

    // Set restrictive permissions on file
    @chmod($session_file, 0600);

    echo json_encode([
        'success' => true,
        'message' => 'BAS session updated successfully.',
        'updated_at' => $session_data['updated_at']
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
