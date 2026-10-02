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

// ponytail: Ultra-light fast path for checking BAS session without disk or DB overhead
function load_bas_session_data() {
    global $conn;

    // 1. Fast Path: Check local directory file first (0.1ms)
    $local_file = __DIR__ . '/.bas_session.json';
    if (file_exists($local_file) && is_readable($local_file)) {
        $raw = @file_get_contents($local_file);
        $parsed = json_decode($raw, true);
        if ($parsed && !empty($parsed['sid'])) {
            $ts = $parsed['timestamp'] ?? 0;
            if (time() - $ts < 4 * 3600) {
                return $parsed;
            }
        }
    }

    $best_data = null;
    $best_ts = 0;

    // 2. Scan filesystem candidates if local file is missing/stale
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

    // 3. Simple DB query fallback without running CREATE TABLE on every read
    if ((!$best_data || (time() - $best_ts > 8 * 3600)) && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        try {
            $res = @$conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bas_session' LIMIT 1");
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

    // Mirror to local dir if found from fallback
    if ($best_data && !file_exists($local_file)) {
        @file_put_contents($local_file, json_encode($best_data, JSON_PRETTY_PRINT));
        @chmod($local_file, 0600);
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

// Handle GET request to check status or list tasks
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'list_tasks' || $action === 'get_tasks') {
        $tasks = [];
        $res = $conn->query("SELECT id, project_name, model_name, ap, cp, csc, qb_user, qb_userdebug, pic_email, test_plan_type, progress_status FROM gba_tasks ORDER BY id DESC LIMIT 50");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $tasks[] = [
                    'id' => (int)$row['id'],
                    'project_name' => $row['project_name'] ?? '',
                    'model_name' => $row['model_name'] ?? '',
                    'ap' => $row['ap'] ?? '',
                    'cp' => $row['cp'] ?? '',
                    'csc' => $row['csc'] ?? '',
                    'qb_user' => $row['qb_user'] ?? '',
                    'qb_userdebug' => $row['qb_userdebug'] ?? '',
                    'pic_email' => $row['pic_email'] ?? '',
                    'test_plan_type' => $row['test_plan_type'] ?? 'Normal MR',
                    'progress_status' => $row['progress_status'] ?? 'Pending'
                ];
            }
        }
        echo json_encode([
            'success' => true,
            'data' => $tasks
        ]);
        exit;
    }
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

// Handle POST request to save session token or import CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_json = file_get_contents('php://input');
    $payload = json_decode($input_json, true);
    if (!$payload) {
        $payload = $_POST;
    }

    // Handle CSV Import / Upload from Extension or UI
    $action = $_GET['action'] ?? ($payload['action'] ?? '');
    if ($action === 'upload_csv' || $action === 'import_csv' || !empty($_FILES['csv_file']) || !empty($payload['csv_content'])) {
        $csv_text = '';
        if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $csv_text = @file_get_contents($_FILES['csv_file']['tmp_name']);
        } elseif (!empty($payload['csv_content'])) {
            $csv_text = $payload['csv_content'];
        }

        if (empty($csv_text)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Konten CSV kosong atau gagal diunggah.'
            ]);
            exit;
        }

        $target_paths = [
            __DIR__ . '/SearchData_raw.csv',
            '/var/www/html/SearchData_raw.csv',
            '/home/endri-pro/dev/App/project_manager/SearchData_raw.csv'
        ];
        $written_any = false;
        foreach ($target_paths as $tp) {
            if (@file_put_contents($tp, $csv_text) !== false) {
                $written_any = true;
            }
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($csv_text));
        $row_count = max(0, count($lines) - 1);

        echo json_encode([
            'success' => true,
            'message' => "SearchData_raw.csv berhasil diperbarui ({$row_count} baris data).",
            'row_count' => $row_count
        ]);
        exit;
    }

    $sid = trim($payload['sid'] ?? '');
    $device = trim($payload['device'] ?? '');
    $cookie_header = trim($payload['cookie_header'] ?? '');
    $timestamp = isset($payload['timestamp']) ? (int)$payload['timestamp'] : time();

    if (empty($sid)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Parameter `sid` atau file CSV diperlukan.'
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

