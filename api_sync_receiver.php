<?php
// ponytail: API Sync Receiver Endpoint — receives batch JSON updates from Desktop Bridge via Cloudflare
header('Content-Type: application/json; charset=utf-8');

require_once 'config.php';

// 1. Configurable Sync Secret Token
$expected_token = getenv('BRIDGE_SYNC_TOKEN') ?: 'gba-bridge-sync-key-2026';

// 2. Authentication Verification
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (empty($auth_header) && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}

$provided_token = '';
if (preg_match('/Bearer\s+(.+)/i', $auth_header, $matches)) {
    $provided_token = trim($matches[1]);
} elseif (!empty($_SERVER['HTTP_X_BRIDGE_TOKEN'])) {
    $provided_token = trim($_SERVER['HTTP_X_BRIDGE_TOKEN']);
}

// 3. Healthcheck / Ping Handshake Support (GET request)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($provided_token !== $expected_token) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized: Invalid sync token']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'status' => 'online',
        'message' => 'Project Manager Sync Receiver is ready',
        'server_time' => date('Y-m-d H:i:s'),
        'supported_tables' => ['gba_tasks', 'projects', 'users', 'new_tasks']
    ]);
    exit;
}

// 4. Validate POST Method & Auth
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

if (empty($provided_token) || $provided_token !== $expected_token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Invalid or missing sync token']);
    exit;
}

// 5. Read & Decode JSON Payload
$raw_input = file_get_contents('php://input');
if (empty($raw_input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empty request payload']);
    exit;
}

$payload = json_decode($raw_input, true);
if (!is_array($payload) || !isset($payload['tables'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON structure. Expected {"tables": {...}}']);
    exit;
}

$tables_data = $payload['tables'];
$stats = [];

// 6. Generic Parameterized Upsert Helper Function
function upsert_table_data($conn, $table_name, $rows, $allowed_columns, $primary_keys = ['id']) {
    if (!is_array($rows) || empty($rows)) {
        return 0;
    }

    $affected_count = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;

        $filtered = [];
        foreach ($allowed_columns as $col) {
            if (array_key_exists($col, $row)) {
                $filtered[$col] = $row[$col];
            }
        }

        if (empty($filtered)) continue;

        $cols = array_keys($filtered);
        $col_names = implode(', ', array_map(function($c) { return "`$c`"; }, $cols));
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));

        $update_parts = [];
        foreach ($cols as $col) {
            if (!in_array($col, $primary_keys)) {
                $update_parts[] = "`$col` = VALUES(`$col`)";
            }
        }

        if (empty($update_parts)) {
            $sql = "INSERT IGNORE INTO `$table_name` ($col_names) VALUES ($placeholders)";
        } else {
            $update_sql = implode(', ', $update_parts);
            $sql = "INSERT INTO `$table_name` ($col_names) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $update_sql";
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed for table $table_name: " . $conn->error);
        }

        $types = '';
        $values = [];
        foreach ($cols as $col) {
            $val = $filtered[$col];
            if (is_int($val)) {
                $types .= 'i';
            } elseif (is_float($val) || is_double($val)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $values[] = $val;
        }

        $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed for table $table_name: " . $stmt->error);
        }
        $stmt->close();
        $affected_count++;
    }

    return $affected_count;
}

// 7. Execute Sync inside Atomic Transaction
$conn->begin_transaction();

try {
    // 7.1 Users
    if (isset($tables_data['users']) && is_array($tables_data['users'])) {
        $user_cols = ['id', 'username', 'email', 'password', 'role', 'profile_picture'];
        $stats['users'] = upsert_table_data($conn, 'users', $tables_data['users'], $user_cols, ['id']);
    }

    // 7.2 Projects
    if (isset($tables_data['projects']) && is_array($tables_data['projects'])) {
        $proj_cols = ['id', 'project_name', 'product_model', 'project_type', 'status', 'description', 'ap', 'cp', 'csc', 'qb_user', 'qb_userdebug', 'software_released', 'use_gba_testing'];
        $stats['projects'] = upsert_table_data($conn, 'projects', $tables_data['projects'], $proj_cols, ['id']);
    }

    // 7.3 GBA Tasks
    if (isset($tables_data['gba_tasks']) && is_array($tables_data['gba_tasks'])) {
        $gba_cols = [
            'id', 'project_id', 'model_name', 'ap', 'cp', 'csc', 'qb_user', 'qb_eng', 
            'pic_email', 'test_plan_type', 'progress_status', 'request_date', 'submission_date', 
            'deadline', 'sign_off_date', 'base_submission_id', 'submission_id', 'reviewer_email', 
            'notes', 'test_items_checklist', 'project_name', 'qb_userdebug', 'approved_date', 
            'is_urgent', 'updated_at', 'updated_by_email'
        ];
        $stats['gba_tasks'] = upsert_table_data($conn, 'gba_tasks', $tables_data['gba_tasks'], $gba_cols, ['id']);
    }

    // 7.4 New Tasks (Smart Filter)
    if (isset($tables_data['new_tasks']) && is_array($tables_data['new_tasks'])) {
        $nt_cols = ['id', 'model_name', 'ap', 'cp', 'csc', 'request_type', 'qb_user', 'qb_userdebug', 'is_manual', 'created_at'];
        $stats['new_tasks'] = upsert_table_data($conn, 'new_tasks', $tables_data['new_tasks'], $nt_cols, ['id']);
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Sync batch successfully processed',
        'synced_stats' => $stats,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database Sync Error: ' . $e->getMessage()
    ]);
}
