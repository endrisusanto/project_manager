<?php
// sync_bas.php - Core Sync Engine connecting BAS (Build Approval System) to Project Manager local

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sync_helper.php';

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Content-Type: application/json; charset=UTF-8");
}

function respond_output($data, $is_cli, $status_code = 200) {
    if (!$is_cli) {
        http_response_code($status_code);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo ($data['success'] ? "[SUCCESS] " : "[ERROR] ") . ($data['message'] ?? '') . PHP_EOL;
        if (!empty($data['updated_tasks'])) {
            echo "Updated " . count($data['updated_tasks']) . " task(s):" . PHP_EOL;
            foreach ($data['updated_tasks'] as $t) {
                echo " - Task #{$t['id']} ({$t['model_name']} - {$t['ap']}): {$t['old_status']} -> {$t['new_status']} (Reviewer: {$t['reviewer']})" . PHP_EOL;
            }
        }
    }
    exit($data['success'] ? 0 : 1);
}

// 1. Read BAS session with multi-tier fallback (Local File -> Fallback Paths -> Database system_settings)
$session = null;
$best_ts = 0;

$candidate_session_paths = [
    __DIR__ . '/.bas_session.json',
    sys_get_temp_dir() . '/.bas_session.json',
    '/var/www/html/.bas_session.json',
    '/home/endri-pro/dev/App/project_manager/.bas_session.json',
    '/opt/lampp/htdocs/project_manager/.bas_session.json'
];
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $candidate_session_paths[] = 'C:/xampp/htdocs/project_manager/.bas_session.json';
    $candidate_session_paths[] = 'D:/xampp/htdocs/project_manager/.bas_session.json';
}

foreach ($candidate_session_paths as $sp) {
    if (file_exists($sp) && is_readable($sp)) {
        $raw = @file_get_contents($sp);
        $parsed = json_decode($raw, true);
        if ($parsed && !empty($parsed['sid'])) {
            $ts = $parsed['timestamp'] ?? 0;
            if ($ts > $best_ts) {
                $best_ts = $ts;
                $session = $parsed;
            }
        }
    }
}

// Check database system_settings table if file missing or expired
if ((!$session || (time() - $best_ts > 8 * 3600)) && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bas_session' LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        $db_data = json_decode($row['setting_value'], true);
        if ($db_data && !empty($db_data['sid'])) {
            $db_ts = $db_data['timestamp'] ?? 0;
            if ($db_ts > $best_ts) {
                $best_ts = $db_ts;
                $session = $db_data;
            }
        }
    }
}

if (!$session || empty($session['sid'])) {
    respond_output([
        'success' => false,
        'message' => 'Token session BAS (sid) tidak ditemukan atau kosong. Silakan buka Build Approval System di browser yang terpasang ekstensi untuk sinkronisasi token session.',
        'synced_count' => 0,
        'updated_tasks' => []
    ], $is_cli, 400);
}

$sid = trim($session['sid']);
$device = trim($session['device'] ?? '');
$cookie_header = trim($session['cookie_header'] ?? '');

// Build cookie string for BAS cURL
if (empty($cookie_header)) {
    $cookies = ["sid={$sid}"];
    if (!empty($device)) {
        $cookies[] = "CF_VERIFIED_DEVICE={$device}";
    }
    $cookie_string = implode('; ', $cookies);
} else {
    $cookie_string = $cookie_header;
    if (strpos($cookie_string, "sid=") === false) {
        $cookie_string .= "; sid={$sid}";
    }
}

// 2. Fetch submissions from BAS
$bas_url = "https://buildapprovalsystem.com/submission/mySubmission/submitted/all";

$ch = curl_init($bas_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
        "Accept: application/json, text/plain, */*",
        "Cookie: {$cookie_string}",
        "Referer: https://buildapprovalsystem.com/"
    ]
]);

$response_raw = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

if ($response_raw === false || !empty($curl_err)) {
    respond_output([
        'success' => false,
        'message' => "Gagal terhubung ke BAS: " . ($curl_err ?: 'Koneksi timeout'),
        'synced_count' => 0,
        'updated_tasks' => []
    ], $is_cli, 502);
}

// Check if response is HTTP error or HTML login redirect (session expired)
if ($http_code >= 400 || stripos($response_raw, '<html') !== false || (stripos($response_raw, 'login') !== false && stripos($response_raw, '"id"') === false)) {
    $err_detail = "Session BAS kedaluwarsa atau tidak valid (HTTP {$http_code}).";
    $json_err = json_decode($response_raw, true);
    if (is_array($json_err) && !empty($json_err['error'])) {
        $err_detail = "BAS Error ({$http_code}): " . $json_err['error'];
    }
    respond_output([
        'success' => false,
        'message' => "{$err_detail} Silakan buka https://buildapprovalsystem.com di browser untuk memperbarui session otomatis.",
        'synced_count' => 0,
        'updated_tasks' => []
    ], $is_cli, ($http_code === 401 || $http_code === 403 || $http_code === 500) ? 401 : 500);
}

$submissions_data = json_decode($response_raw, true);

if (!is_array($submissions_data) || isset($submissions_data['error'])) {
    $err_msg = is_array($submissions_data) && isset($submissions_data['error']) ? $submissions_data['error'] : 'Format data dari BAS tidak dikenali.';
    respond_output([
        'success' => false,
        'message' => "Gagal membaca data dari BAS: {$err_msg}",
        'synced_count' => 0,
        'updated_tasks' => []
    ], $is_cli, 500);
}

// Normalize submissions list
$submissions = [];
if (isset($submissions_data['data']) && is_array($submissions_data['data'])) {
    $submissions = $submissions_data['data'];
} elseif (isset($submissions_data['submissions']) && is_array($submissions_data['submissions'])) {
    $submissions = $submissions_data['submissions'];
} elseif (isset($submissions_data['rows']) && is_array($submissions_data['rows'])) {
    $submissions = $submissions_data['rows'];
} elseif (array_values($submissions_data) === $submissions_data) {
    // Top-level numeric indexed array
    $submissions = $submissions_data;
} else {
    // Single object or unexpected structure
    if (isset($submissions_data['id']) || isset($submissions_data['submission_id']) || isset($submissions_data['fingerprint'])) {
        $submissions = [$submissions_data];
    } else {
        $submissions = [];
    }
}


// 3. Process submissions and match with gba_tasks
$updated_tasks = [];
$now_str = date('Y-m-d H:i:s');
$today_str = date('Y-m-d');

// Helper to extract AP and Model from fingerprint / binary / item
function extract_ap_version($fingerprint, $raw_ap = '') {
    if (!empty($raw_ap)) {
        return trim($raw_ap);
    }
    if (empty($fingerprint)) {
        return '';
    }
    // Pattern example: SM-S711B/S711BXXSJGZI6/... or S711BXXSJGZI6
    if (preg_match('/([A-Z0-9]{3,8}XX[A-Z0-9]{4,8}|[A-Z0-9]{12,16})/i', $fingerprint, $matches)) {
        return trim($matches[1]);
    }
    // Fallback: split by / or whitespace
    $parts = preg_split('/[\/\s,;]+/', trim($fingerprint));
    foreach ($parts as $part) {
        $clean = trim($part);
        if (strlen($clean) >= 10 && preg_match('/[0-9]/', $clean) && preg_match('/[A-Z]/i', $clean)) {
            return $clean;
        }
    }
    return trim($fingerprint);
}

foreach ($submissions as $sub) {
    if (!is_array($sub)) continue;

    $sub_id = strval($sub['id'] ?? $sub['submission_id'] ?? $sub['submissionId'] ?? '');
    $fingerprint = $sub['fingerprint'] ?? $sub['binaryName'] ?? $sub['binary_name'] ?? $sub['build_number'] ?? '';
    $raw_ap = $sub['ap'] ?? $sub['ap_version'] ?? '';
    $ap_version = extract_ap_version($fingerprint, $raw_ap);
    $model_name = trim($sub['model'] ?? $sub['model_name'] ?? $sub['modelName'] ?? '');
    
    // Status normalization
    $raw_status = trim($sub['status'] ?? $sub['progress_status'] ?? $sub['approvalStatus'] ?? $sub['submissionStatus'] ?? '');
    $reviewer = trim($sub['reviewer'] ?? $sub['reviewer_email'] ?? $sub['reviewerEmail'] ?? $sub['reviewed_by'] ?? '');
    $urgent_raw = $sub['urgent'] ?? $sub['is_urgent'] ?? $sub['isUrgent'] ?? false;
    $is_urgent = ($urgent_raw === true || $urgent_raw === 1 || $urgent_raw === '1' || strtolower(strval($urgent_raw)) === 'true' || strtolower(strval($urgent_raw)) === 'urgent' || strtolower(strval($urgent_raw)) === 'y') ? 1 : 0;
    
    $raw_submission_date = $sub['submissionDate'] ?? $sub['submission_date'] ?? $sub['submitted_at'] ?? $sub['date'] ?? null;
    $sub_date = null;
    if (!empty($raw_submission_date)) {
        $time_val = strtotime($raw_submission_date);
        if ($time_val) {
            $sub_date = date('Y-m-d', $time_val);
        }
    }

    $target_progress_status = null;
    $approved_date = null;

    if (stripos($raw_status, 'Approve') !== false || stripos($raw_status, 'Pass') !== false) {
        $target_progress_status = 'Approved';
        $approved_date = $sub_date ?: $today_str;
    } elseif (stripos($raw_status, 'Submit') !== false || stripos($raw_status, 'Waiting') !== false || stripos($raw_status, 'Review') !== false) {
        $target_progress_status = 'Submitted';
    } elseif (stripos($raw_status, 'Pending') !== false || stripos($raw_status, 'Ongoing') !== false || stripos($raw_status, 'Testing') !== false) {
        $target_progress_status = 'Test Ongoing';
    } elseif (stripos($raw_status, 'Reject') !== false || stripos($raw_status, 'Fail') !== false) {
        $target_progress_status = 'Rejected';
    }

    if (empty($sub_id) && empty($ap_version)) {
        continue;
    }

    // Match candidate task in database
    $matched_task = null;

    // Match 1: By submission_id if exists
    if (!empty($sub_id)) {
        $stmt = $conn->prepare("SELECT id, model_name, ap, progress_status, reviewer_email, is_urgent, submission_id, approved_date, submission_date FROM gba_tasks WHERE submission_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $sub_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $matched_task = $row;
            }
            $stmt->close();
        }
    }

    // Match 2: By exact AP
    if (!$matched_task && !empty($ap_version)) {
        $stmt = $conn->prepare("SELECT id, model_name, ap, progress_status, reviewer_email, is_urgent, submission_id, approved_date, submission_date FROM gba_tasks WHERE TRIM(ap) = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $ap_version);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $matched_task = $row;
            }
            $stmt->close();
        }
    }

    // Match 3: By AP containing / LIKE
    if (!$matched_task && !empty($ap_version) && strlen($ap_version) >= 6) {
        $like_ap = "%" . $ap_version . "%";
        $stmt = $conn->prepare("SELECT id, model_name, ap, progress_status, reviewer_email, is_urgent, submission_id, approved_date, submission_date FROM gba_tasks WHERE ap LIKE ? OR ? LIKE CONCAT('%', ap, '%') LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("ss", $like_ap, $ap_version);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $matched_task = $row;
            }
            $stmt->close();
        }
    }

    // Match 4: By Model Name and AP prefix
    if (!$matched_task && !empty($model_name) && !empty($ap_version)) {
        $stmt = $conn->prepare("SELECT id, model_name, ap, progress_status, reviewer_email, is_urgent, submission_id, approved_date, submission_date FROM gba_tasks WHERE model_name = ? AND ap LIKE ? LIMIT 1");
        if ($stmt) {
            $like_ap = "%" . substr($ap_version, 0, 8) . "%";
            $stmt->bind_param("ss", $model_name, $like_ap);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $matched_task = $row;
            }
            $stmt->close();
        }
    }

    // If task found, evaluate if changes are needed
    if ($matched_task) {
        $task_id = (int)$matched_task['id'];
        $old_status = $matched_task['progress_status'];
        $old_reviewer = $matched_task['reviewer_email'];
        $old_urgent = (int)$matched_task['is_urgent'];
        $old_sub_id = $matched_task['submission_id'];
        $old_sub_date = $matched_task['submission_date'];

        $need_update = false;
        $updates = [];
        $types = "";
        $params = [];

        // Progress status update
        $final_status = $old_status;
        if ($target_progress_status !== null && $target_progress_status !== $old_status) {
            $updates[] = "progress_status = ?";
            $types .= "s";
            $params[] = $target_progress_status;
            $final_status = $target_progress_status;
            $need_update = true;
        }

        // Approved date & sign off date update
        if ($target_progress_status === 'Approved') {
            if (empty($matched_task['approved_date'])) {
                $updates[] = "approved_date = ?";
                $types .= "s";
                $params[] = $approved_date;
                $need_update = true;
            }
        }

        // Reviewer update
        if (!empty($reviewer) && $reviewer !== $old_reviewer) {
            $updates[] = "reviewer_email = ?";
            $types .= "s";
            $params[] = $reviewer;
            $need_update = true;
        }

        // Urgent flag update
        if ($is_urgent !== $old_urgent) {
            $updates[] = "is_urgent = ?";
            $types .= "i";
            $params[] = $is_urgent;
            $need_update = true;
        }

        // Submission ID update
        if (!empty($sub_id) && $sub_id !== $old_sub_id) {
            $updates[] = "submission_id = ?";
            $types .= "s";
            $params[] = $sub_id;
            $need_update = true;
        }

        // Submission date update if empty and different
        if ($sub_date && empty($old_sub_date)) {
            $updates[] = "submission_date = ?";
            $types .= "s";
            $params[] = $sub_date;
            $need_update = true;
        }

        if ($need_update && !empty($updates)) {
            $updates[] = "updated_at = NOW()";
            $updates[] = "updated_by_email = 'BAS-AutoSync'";

            $sql_update = "UPDATE gba_tasks SET " . implode(", ", $updates) . " WHERE id = ?";
            $types .= "i";
            $params[] = $task_id;

            $stmt_up = $conn->prepare($sql_update);
            if ($stmt_up) {
                $stmt_up->bind_param($types, ...$params);
                $exec_ok = $stmt_up->execute();
                
                if ($exec_ok) {
                    // Record in activity_log
                    $log_details = "BAS Auto-Sync: Status [{$old_status} -> {$final_status}]";
                    if (!empty($reviewer) && $reviewer !== $old_reviewer) {
                        $log_details .= ", Reviewer: {$reviewer}";
                    }
                    if ($is_urgent !== $old_urgent) {
                        $log_details .= ", Urgent: " . ($is_urgent ? 'Yes' : 'No');
                    }
                    if (!empty($sub_id) && $sub_id !== $old_sub_id) {
                        $log_details .= ", SubID: {$sub_id}";
                    }

                    $stmt_log = $conn->prepare("INSERT INTO activity_log (task_id, action_type, details, user_email) VALUES (?, 'BAS Auto-Sync', ?, 'BAS-AutoSync')");
                    if ($stmt_log) {
                        $stmt_log->bind_param("is", $task_id, $log_details);
                        @$stmt_log->execute();
                        $stmt_log->close();
                    }

                    $updated_tasks[] = [
                        'id' => $task_id,
                        'model_name' => $matched_task['model_name'],
                        'ap' => $matched_task['ap'],
                        'old_status' => $old_status,
                        'new_status' => $final_status,
                        'reviewer' => $reviewer ?: $old_reviewer,
                        'is_urgent' => $is_urgent,
                        'submission_id' => $sub_id ?: $old_sub_id
                    ];
                } else {
                    error_log("Execute failed for task #{$task_id}: " . $stmt_up->error);
                }
                $stmt_up->close();
            } else {
                error_log("Prepare failed for task #{$task_id}: " . $conn->error);
            }
        }
    }
}

// 4. Trigger WSS broadcast if available
if (function_exists('trigger_remote_sync_broadcast') && !empty($updated_tasks)) {
    trigger_remote_sync_broadcast('gba_tasks', 'BAS_SYNC', [
        'count' => count($updated_tasks),
        'tasks' => $updated_tasks
    ]);
}

// 5. Build summary and return response
$total_submissions = count($submissions);
$total_updated = count($updated_tasks);

$message = "Sinkronisasi BAS berhasil: {$total_submissions} submission dicek, {$total_updated} task di-update.";

respond_output([
    'success' => true,
    'message' => $message,
    'synced_count' => $total_submissions,
    'updated_count' => $total_updated,
    'updated_tasks' => $updated_tasks,
    'synced_at' => $now_str
], $is_cli, 200);
