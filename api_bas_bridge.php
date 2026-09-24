<?php
// api_bas_bridge.php - Receiver endpoint for BAS session tokens from Browser Extension
require_once "config.php";

// CORS Headers for Extension communication
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function get_bas_fallback_paths() {
    $paths = [
        __DIR__ . '/.bas_session.json',
        sys_get_temp_dir() . '/.bas_session.json',
        '/var/www/html/.bas_session.json',
        '/var/www/html/tkdn/.bas_session.json',
        '/var/www/html/project_manager/.bas_session.json',
        '/home/endri-pro/dev/App/project_manager/.bas_session.json',
        '/opt/lampp/htdocs/project_manager/.bas_session.json',
        '/opt/lampp/htdocs/tkdn/.bas_session.json'
    ];
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $paths[] = 'C:/xampp/htdocs/project_manager/.bas_session.json';
        $paths[] = 'C:/xampp/htdocs/tkdn/.bas_session.json';
        $paths[] = 'D:/xampp/htdocs/project_manager/.bas_session.json';
        $paths[] = 'D:/xampp/htdocs/tkdn/.bas_session.json';
    }
    return array_values(array_unique($paths));
}

function load_bas_session_data() {
    global $conn;
    $best_data = null;
    $best_ts = 0;

    // 1. Scan filesystem candidates
    foreach (get_bas_fallback_paths() as $p) {
        if (file_exists($p) && is_readable($p)) {
            $raw = @file_get_contents($p);
            $parsed = json_decode($raw, true);
            if ($parsed && !empty($parsed['sid'])) {
                $ts = $parsed['timestamp'] ?? 0;
                if ($ts > $best_ts) {
                    $best_ts = $ts;
                    $best_data = $parsed;
                }
            }
        }
    }

    // 2. Scan database system_settings table if local file missing or expired
    if ((!$best_data || (time() - $best_ts > 8 * 3600)) && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS `system_settings` (
                `setting_key` VARCHAR(100) PRIMARY KEY,
                `setting_value` LONGTEXT NOT NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bas_session' LIMIT 1");
            if ($res && $row = $res->fetch_assoc()) {
                $db_data = json_decode($row['setting_value'], true);
                if ($db_data && !empty($db_data['sid'])) {
                    $db_ts = $db_data['timestamp'] ?? 0;
                    if ($db_ts > $best_ts) {
                        $best_ts = $db_ts;
                        $best_data = $db_data;
                    }
                }
            }
        } catch (Throwable $e) {
            // Safe fallback
        }
    }

    // Mirror to current dir if found
    if ($best_data) {
        $local_file = __DIR__ . '/.bas_session.json';
        if (!file_exists($local_file)) {
            @file_put_contents($local_file, json_encode($best_data, JSON_PRETTY_PRINT));
            @chmod($local_file, 0600);
        }
    }

    return $best_data;
}

function save_bas_session_data($session_data) {
    global $conn;
    $json = json_encode($session_data, JSON_PRETTY_PRINT);

    // 1. Write to local and fallback files
    foreach (get_bas_fallback_paths() as $p) {
        if (file_exists($p) || @is_writable(dirname($p))) {
            @file_put_contents($p, $json);
            @chmod($p, 0600);
        }
    }

    // 2. Write to Database system_settings table
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        try {
            $conn->query("CREATE TABLE IF NOT EXISTS `system_settings` (
                `setting_key` VARCHAR(100) PRIMARY KEY,
                `setting_value` LONGTEXT NOT NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $stmt = $conn->prepare("INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES ('bas_session', ?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)");
            if ($stmt) {
                $stmt->bind_param('s', $json);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            // Safe fallback
        }
    }
}

// Handle GET request to check status
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $data = load_bas_session_data();

    if (!$data || empty($data['sid'])) {
        echo json_encode([
            'success' => false,
            'status' => 'disconnected',
            'message' => 'No BAS session found. Please open Build Approval System in your browser.',
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

    save_bas_session_data($session_data);

    echo json_encode([
        'success' => true,
        'message' => 'BAS session updated successfully.',
        'updated_at' => $session_data['updated_at']
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);

