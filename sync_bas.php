<?php
// sync_bas.php - Core Sync Engine connecting BAS (Build Approval System) to Project Manager local

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Register shutdown function to catch any fatal error and guarantee JSON response
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        if (!headers_sent()) {
            http_response_code(200);
            header("Content-Type: application/json; charset=UTF-8");
        }
        echo json_encode([
            'success' => false,
            'message' => 'PHP Fatal Error: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line'],
            'synced_count' => 0,
            'updated_tasks' => []
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
});

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Content-Type: application/json; charset=UTF-8");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

function respond_output($data, $is_cli, $status_code = 200) {
    if (!$is_cli) {
        if (!headers_sent()) {
            http_response_code(200); // Always return 200 for clean client-side JSON parsing
            header("Content-Type: application/json; charset=UTF-8");
        }
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
    exit(0);
}

try {
    require_once __DIR__ . '/config.php';
    if (file_exists(__DIR__ . '/sync_helper.php')) {
        @include_once __DIR__ . '/sync_helper.php';
    }

    // 1. Read BAS session with multi-tier fallback (Local File -> Fallback Paths -> Database system_settings)
    $session = null;
    $best_ts = 0;

    $candidate_session_paths = [
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
        $candidate_session_paths[] = 'C:/xampp/htdocs/project_manager/.bas_session.json';
        $candidate_session_paths[] = 'C:/xampp/htdocs/tkdn/.bas_session.json';
        $candidate_session_paths[] = 'D:/xampp/htdocs/project_manager/.bas_session.json';
        $candidate_session_paths[] = 'D:/xampp/htdocs/tkdn/.bas_session.json';
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
                        $session = $db_data;
                    }
                }
            }
        } catch (Throwable $e) {
            // Safe graceful fallback
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
        $cookies = ["login_type=sso", "sid={$sid}"];
        if (!empty($device)) {
            $cookies[] = "CF_VERIFIED_DEVICE={$device}";
        }
        $cookie_string = implode('; ', $cookies);
    } else {
        $cookie_string = $cookie_header;
        if (strpos($cookie_string, "login_type=") === false) {
            $cookie_string = "login_type=sso; " . $cookie_string;
        }
        if (strpos($cookie_string, "sid=") === false) {
            $cookie_string .= "; sid={$sid}";
        }
    }

    // 2. Fetch submissions from BAS (Search Submissions from beginning of month until today with exact KST payload)
    $today_str = date('Y-m-d');
    $month_start_ts = strtotime(date('Y-m-01 00:00:00'));
    
    // Exact KST ISO-8601 timestamps (+09:00) as used by BAS portal search
    $start_iso = date('Y-m-01\T00:00:00.000000+09:00');
    $end_iso = date('Y-m-d\T23:59:59.000000+09:00');

    $debug_logs = [];

    // Search payload (Exact specification from BAS portal createExcel)
    $search_payload = [
        'startDate' => $start_iso,
        'endDate' => $end_iso,
        'approvalType' => 'All',
        'group' => 'SRV',
        'carriers' => 'XID',
        'lastUpdateCheck' => 'false',
        'legacy' => true,
        'pageSize' => 500,
        'count' => true
    ];

    $search_urls = [
        "https://buildapprovalsystem.com/submission/search/createExcel",
        "https://buildapprovalsystem.com/submission/searchSubmissions",
        "https://buildapprovalsystem.com/searchSubmissions",
        "https://buildapprovalsystem.com/submission/search"
    ];

    $all_raw_submissions = [];
    $auth_error = null;
    $search_succeeded = false;

    $req_headers = [
        "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36",
        "Accept: application/json, text/plain, */*",
        "Content-Type: application/json; charset=UTF-8",
        "Origin: https://buildapprovalsystem.com",
        "Referer: https://buildapprovalsystem.com/",
        "Cookie: {$cookie_string}"
    ];

    // Helper function to execute POST or GET requests to BAS with 25s timeout
    $execute_bas_request = function($url, $method = 'POST', $json_payload = null, $timeout = 25) use ($cookie_string, $req_headers, &$auth_error, &$debug_logs) {
        $res_raw = false;
        $http_code = 0;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => $req_headers
            ];
            if ($method === 'POST') {
                $opts[CURLOPT_POST] = true;
                $opts[CURLOPT_POSTFIELDS] = is_array($json_payload) ? json_encode($json_payload) : (string)$json_payload;
            }
            curl_setopt_array($ch, $opts);
            $res_raw = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $context_opts = [
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $req_headers) . "\r\n",
                    'timeout' => $timeout,
                    'ignore_errors' => true
                ],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
            ];
            if ($method === 'POST' && $json_payload !== null) {
                $context_opts['http']['content'] = is_array($json_payload) ? json_encode($json_payload) : (string)$json_payload;
            }
            $context = stream_context_create($context_opts);
            $res_raw = @file_get_contents($url, false, $context);
            $http_code = 200;
            if (isset($http_response_header) && preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m)) {
                $http_code = (int)$m[1];
            }
        }

        $body_len = is_string($res_raw) ? strlen($res_raw) : 0;
        $debug_logs[] = "{$method} " . basename(parse_url($url, PHP_URL_PATH)) . " -> HTTP {$http_code} ({$body_len} bytes)";

        if ($http_code === 401 || $http_code === 403) {
            $auth_error = $http_code;
        }

        return ['code' => $http_code, 'body' => $res_raw];
    };

    // Helper to parse CSV export from BAS
    $parse_bas_csv = function($csv_content) {
        if (empty($csv_content)) return [];
        $lines = preg_split('/\r\n|\r|\n/', trim($csv_content));
        if (count($lines) < 2) return [];

        $header = str_getcsv(array_shift($lines));
        if (empty($header)) return [];

        $clean_header = array_map(function($h) {
            return trim(str_replace(['"', "'"], '', $h));
        }, $header);

        $items = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $row = str_getcsv($line);
            if (empty($row)) continue;
            if (count($row) < count($clean_header)) {
                $row = array_pad($row, count($clean_header), '');
            } else if (count($row) > count($clean_header)) {
                $row = array_slice($row, 0, count($clean_header));
            }

            $item = array_combine($clean_header, $row);
            if (!$item) continue;

            foreach ($item as $k => $v) {
                $item[$k] = trim(strval($v), "=\"' \t\n\r\0\x0B");
            }
            $items[] = $item;
        }
        return $items;
    };

    // Helper to extract rows from any BAS response format (JSON or CSV downloadLink)
    $extract_items_from_response = function($raw_body) use (&$parse_bas_csv, &$debug_logs) {
        if (empty($raw_body)) return [];
        $parsed = json_decode($raw_body, true);
        if (!is_array($parsed)) return [];

        // Check if response contains downloadLink for CSV export
        if (!empty($parsed['downloadLink'])) {
            $csv_url = $parsed['downloadLink'];
            $csv_content = false;
            if (function_exists('curl_init')) {
                $ch_csv = curl_init($csv_url);
                curl_setopt_array($ch_csv, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_TIMEOUT => 20
                ]);
                $csv_content = curl_exec($ch_csv);
                curl_close($ch_csv);
            } else {
                $csv_content = @file_get_contents($csv_url);
            }

            if ($csv_content) {
                $csv_items = $parse_bas_csv($csv_content);
                $debug_logs[] = "Downloaded SearchData.csv (" . count($csv_items) . " rows)";
                return $csv_items;
            }
        }

        $items = [];
        if (isset($parsed['data']) && is_array($parsed['data'])) {
            $items = $parsed['data'];
        } elseif (isset($parsed['submissions']) && is_array($parsed['submissions'])) {
            $items = $parsed['submissions'];
        } elseif (isset($parsed['rows']) && is_array($parsed['rows'])) {
            $items = $parsed['rows'];
        } elseif (isset($parsed['results']) && is_array($parsed['results'])) {
            $items = $parsed['results'];
        } elseif (array_values($parsed) === $parsed) {
            $items = $parsed;
        } elseif (isset($parsed['id']) || isset($parsed['Id']) || isset($parsed['submission_id'])) {
            $items = [$parsed];
        }
        return $items;
    };

    // 1. Try search endpoints with search payload
    foreach ($search_urls as $s_url) {
        $res = $execute_bas_request($s_url, 'POST', $search_payload, 25);
        if ($res['code'] >= 200 && $res['code'] < 400 && !empty($res['body'])) {
            $found_items = $extract_items_from_response($res['body']);
            if (!empty($found_items)) {
                foreach ($found_items as $it) {
                    if (is_array($it)) {
                        $sub_id_val = strval($it['Id'] ?? $it['id'] ?? $it['ID'] ?? $it['submission_id'] ?? $it['submissionId'] ?? '');
                        if (!empty($sub_id_val)) {
                            $all_raw_submissions[$sub_id_val] = $it;
                        } else {
                            $sub_key = ($it['AP'] ?? '') . '_' . ($it['CSC'] ?? '') . '_' . ($it['Fingerprint'] ?? rand());
                            $all_raw_submissions[$sub_key] = $it;
                        }
                    }
                }
                $search_succeeded = true;
                break; // Found successful search results!
            }
        }
    }

    // 2. Fallback to mySubmission only if general search completely returned nothing
    if (!$search_succeeded || empty($all_raw_submissions)) {
        $fallback_res = $execute_bas_request("https://buildapprovalsystem.com/submission/mySubmission/submitted/all", 'GET', null, 10);
        if ($fallback_res['code'] < 400 && !empty($fallback_res['body'])) {
            $fallback_items = $extract_items_from_response($fallback_res['body']);
            foreach ($fallback_items as $it) {
                if (is_array($it)) {
                    $sub_id_val = strval($it['Id'] ?? $it['id'] ?? $it['ID'] ?? $it['submission_id'] ?? $it['submissionId'] ?? '');
                    if (!empty($sub_id_val)) {
                        $all_raw_submissions[$sub_id_val] = $it;
                    } else {
                        $sub_key = ($it['AP'] ?? '') . '_' . ($it['CSC'] ?? '') . '_' . ($it['Fingerprint'] ?? rand());
                        $all_raw_submissions[$sub_key] = $it;
                    }
                }
            }
        }
    }

    if (empty($all_raw_submissions) && $auth_error !== null && ($auth_error === 401 || $auth_error === 403)) {
        respond_output([
            'success' => false,
            'message' => "Session BAS kedaluwarsa atau tidak valid (HTTP {$auth_error}). Silakan buka https://buildapprovalsystem.com di browser untuk refresh token otomatis.",
            'synced_count' => 0,
            'updated_tasks' => [],
            'debug_logs' => $debug_logs
        ], $is_cli, 401);
    }

    // Process all submissions returned by BAS search query
    $submissions = array_values($all_raw_submissions);


// 3. Process submissions and match with gba_tasks
$updated_tasks = [];
$now_str = date('Y-m-d H:i:s');
$today_str = date('Y-m-d');

// Helper to extract AP version
function extract_ap_version($fingerprint, $raw_ap = '') {
    if (!empty($raw_ap)) {
        return trim($raw_ap);
    }
    if (empty($fingerprint)) {
        return '';
    }
    // Pattern example: S711BXXSJGZI6
    if (preg_match('/([A-Z0-9]{3,8}XX[A-Z0-9]{4,8}|[A-Z0-9]{12,16})/i', $fingerprint, $matches)) {
        return trim($matches[1]);
    }
    $parts = preg_split('/[\/\s,;]+/', trim($fingerprint));
    if (count($parts) >= 3) {
        $candidate = (count($parts) >= 4) ? $parts[1] : $parts[0];
        if (strlen(trim($candidate)) >= 8) return trim($candidate);
    }
    foreach ($parts as $part) {
        $clean = trim($part);
        if (strlen($clean) >= 10 && preg_match('/[0-9]/', $clean) && preg_match('/[A-Z]/i', $clean)) {
            return $clean;
        }
    }
    return trim($fingerprint);
}

// Helper to extract CSC version
function extract_csc_version($fingerprint, $raw_csc = '') {
    if (!empty($raw_csc)) {
        return trim($raw_csc);
    }
    if (empty($fingerprint)) {
        return '';
    }
    // Pattern example: S711BOLEJGZI6 or S711BOXMJGZI6
    if (preg_match('/([A-Z0-9]{3,8}O[A-Z0-9]{2,4}[A-Z0-9]{4,8})/i', $fingerprint, $matches)) {
        return trim($matches[1]);
    }
    $parts = preg_split('/[\/\s,;]+/', trim($fingerprint));
    if (count($parts) >= 3) {
        $candidate = (count($parts) >= 4) ? $parts[2] : $parts[1];
        if (strlen(trim($candidate)) >= 8) return trim($candidate);
    }
    return '';
}

function parse_bas_date($date_str) {
    if (empty($date_str)) return null;
    $clean_str = trim(strval($date_str));
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $clean_str, $m)) {
        return $m[1];
    }
    $time_val = strtotime($clean_str);
    return $time_val ? date('Y-m-d', $time_val) : null;
}

foreach ($submissions as $sub) {
    if (!is_array($sub)) continue;

    $sub_id = trim(strval($sub['Id'] ?? $sub['id'] ?? $sub['ID'] ?? $sub['submission_id'] ?? $sub['submissionId'] ?? $sub['Submission ID'] ?? ''), "'\" \t\n\r\0\x0B");
    $fingerprint = trim(strval($sub['Fingerprint'] ?? $sub['fingerprint'] ?? $sub['binaryName'] ?? $sub['binary_name'] ?? $sub['build_number'] ?? ''));
    $raw_ap = trim(strval($sub['AP'] ?? $sub['ap'] ?? $sub['ap_version'] ?? $sub['apVersion'] ?? ''));
    $raw_csc = trim(strval($sub['CSC'] ?? $sub['csc'] ?? $sub['csc_version'] ?? $sub['cscVersion'] ?? ''));
    $raw_cp = trim(strval($sub['CP'] ?? $sub['cp'] ?? $sub['cp_version'] ?? $sub['cpVersion'] ?? ''));

    $ap_version = extract_ap_version($fingerprint, $raw_ap);
    $csc_version = extract_csc_version($fingerprint, $raw_csc);
    $model_name = trim($sub['Model Name'] ?? $sub['modelName'] ?? $sub['model_name'] ?? $sub['Model'] ?? $sub['model'] ?? '');
    
    // Status normalization
    $raw_status = trim($sub['Status'] ?? $sub['status'] ?? $sub['progress_status'] ?? $sub['approvalStatus'] ?? $sub['submissionStatus'] ?? '');
    $reviewer = trim(strval($sub['Reviewer'] ?? $sub['reviewer'] ?? $sub['reviewer_email'] ?? $sub['reviewerEmail'] ?? $sub['reviewed_by'] ?? $sub['PL Email'] ?? $sub['Reviewer Group'] ?? ''), "'\" \t\n\r\0\x0B");
    $urgent_raw = $sub['Urgent'] ?? $sub['urgent'] ?? $sub['is_urgent'] ?? $sub['isUrgent'] ?? false;
    $is_urgent = ($urgent_raw === true || $urgent_raw === 1 || $urgent_raw === '1' || strtolower(strval($urgent_raw)) === 'true' || strtolower(strval($urgent_raw)) === 'urgent' || strtolower(strval($urgent_raw)) === 'y') ? 1 : 0;
    
    $raw_submission_date = $sub['Submission Date'] ?? $sub['submissionDate'] ?? $sub['submission_date'] ?? $sub['submitted_at'] ?? $sub['date'] ?? null;
    $raw_approval_date = $sub['Approval Date'] ?? $sub['approvalDate'] ?? $sub['approved_date'] ?? $sub['approvedAt'] ?? $sub['Review Completion Time'] ?? $sub['reviewCompletionTime'] ?? null;

    $sub_date = parse_bas_date($raw_submission_date);
    $approved_date = parse_bas_date($raw_approval_date);

    $target_progress_status = null;

    // Comprehensive status normalization from BAS: 'Status' column is primary reference
    $norm_status_upper = strtoupper($raw_status);

    if (strpos($norm_status_upper, 'PENDING') !== false) {
        $target_progress_status = 'Pending Feedback';
    } elseif (strpos($norm_status_upper, 'APPROV') !== false || strpos($norm_status_upper, 'PASS') !== false || strpos($norm_status_upper, 'COMPLET') !== false) {
        $target_progress_status = 'Approved';
        if (empty($approved_date)) {
            $approved_date = $sub_date ?: $today_str;
        }
    } elseif (strpos($norm_status_upper, 'SUBMIT') !== false || strpos($norm_status_upper, 'WAITING') !== false || strpos($norm_status_upper, 'REVIEW') !== false) {
        $target_progress_status = 'Submitted';
    } elseif (strpos($norm_status_upper, 'REJECT') !== false || strpos($norm_status_upper, 'FAIL') !== false || strpos($norm_status_upper, 'DROP') !== false) {
        $target_progress_status = 'Rejected';
    } elseif (strpos($norm_status_upper, 'ONGOING') !== false || strpos($norm_status_upper, 'PROGRESS') !== false || strpos($norm_status_upper, 'TEST') !== false) {
        $target_progress_status = 'Test Ongoing';
    } elseif (strpos($norm_status_upper, 'BATAL') !== false || strpos($norm_status_upper, 'CANCEL') !== false) {
        $target_progress_status = 'Batal';
    }

    if (empty($sub_id) && empty($ap_version)) {
        continue;
    }

    // Match candidate task in database strictly by AP Version (Direct single-key matching)
    $matched_task = null;

    // Match 1: Exact match by AP Version
    if (!empty($ap_version)) {
        $stmt = $conn->prepare("SELECT id, model_name, ap, cp, csc, progress_status, reviewer_email, is_urgent, submission_id, approved_date, submission_date, sign_off_date FROM gba_tasks WHERE TRIM(ap) = ? LIMIT 1");
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

    // Match 2: By submission_id if exists
    if (!$matched_task && !empty($sub_id)) {
        $stmt = $conn->prepare("SELECT id, model_name, ap, cp, csc, progress_status, reviewer_email, is_urgent, submission_id, approved_date, submission_date, sign_off_date FROM gba_tasks WHERE submission_id = ? LIMIT 1");
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

    // Match 3: Fallback by AP substring / LIKE
    if (!$matched_task && !empty($ap_version) && strlen($ap_version) >= 8) {
        $like_ap = "%" . $ap_version . "%";
        $stmt = $conn->prepare("SELECT id, model_name, ap, cp, csc, progress_status, reviewer_email, is_urgent, submission_id, approved_date, submission_date, sign_off_date FROM gba_tasks WHERE ap LIKE ? OR ? LIKE CONCAT('%', ap, '%') LIMIT 1");
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

        // Progress status update (Pending Feedback / Approved / Submitted / Test Ongoing / Rejected)
        $final_status = $old_status;
        if ($target_progress_status !== null && $target_progress_status !== $old_status) {
            $updates[] = "progress_status = ?";
            $types .= "s";
            $params[] = $target_progress_status;
            $final_status = $target_progress_status;
            $need_update = true;
        }

        // Update Approved Date directly from table if available
        if ($approved_date && $approved_date !== $matched_task['approved_date']) {
            $updates[] = "approved_date = ?";
            $types .= "s";
            $params[] = $approved_date;
            $need_update = true;
        }

        // Update Sign Off Date when Approved
        if (($target_progress_status === 'Approved' || $old_status === 'Approved' || $old_status === 'Passed') && $approved_date && $approved_date !== $matched_task['sign_off_date']) {
            $updates[] = "sign_off_date = ?";
            $types .= "s";
            $params[] = $approved_date;
            $need_update = true;
        }

        // Update Submission Date directly from table if available
        if ($sub_date && $sub_date !== $matched_task['submission_date']) {
            $updates[] = "submission_date = ?";
            $types .= "s";
            $params[] = $sub_date;
            $need_update = true;
        }

        // Reviewer update
        if (!empty($reviewer) && trim($reviewer) !== trim(strval($old_reviewer))) {
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
        if (!empty($sub_id) && trim($sub_id) !== trim(strval($old_sub_id))) {
            $updates[] = "submission_id = ?";
            $types .= "s";
            $params[] = $sub_id;
            $need_update = true;
        }

        // Auto-fill CSC if empty in DB
        if (!empty($csc_version) && (empty($matched_task['csc']) || trim($matched_task['csc']) === '-')) {
            $updates[] = "csc = ?";
            $types .= "s";
            $params[] = $csc_version;
            $need_update = true;
        }

        // Auto-fill CP if empty in DB
        if (!empty($raw_cp) && (empty($matched_task['cp']) || trim($matched_task['cp']) === '-')) {
            $updates[] = "cp = ?";
            $types .= "s";
            $params[] = $raw_cp;
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
                    $log_ap = !empty($matched_task['ap']) ? $matched_task['ap'] : ($ap_version ?: 'N/A');
                    $log_csc = !empty($matched_task['csc']) && trim($matched_task['csc']) !== '-' ? $matched_task['csc'] : ($csc_version ?: 'N/A');
                    $log_model = !empty($matched_task['model_name']) ? $matched_task['model_name'] : 'N/A';

                    // Record in activity_log with AP and CSC prominently displayed to prevent false positives
                    $log_details = "AP: {$log_ap} | CSC: {$log_csc} | Status: [{$old_status} -> {$final_status}]";
                    if (!empty($sub_id) && $sub_id !== $old_sub_id) {
                        $log_details .= " | SubID: {$sub_id}";
                    } elseif (!empty($old_sub_id)) {
                        $log_details .= " | SubID: {$old_sub_id}";
                    }
                    if (!empty($reviewer) && $reviewer !== $old_reviewer) {
                        $log_details .= " | Reviewer: {$reviewer}";
                    }
                    if ($is_urgent !== $old_urgent) {
                        $log_details .= " | Urgent: " . ($is_urgent ? 'Yes' : 'No');
                    }

                    $stmt_log = $conn->prepare("INSERT INTO activity_log (task_id, action_type, details, user_email) VALUES (?, 'BAS Auto-Sync', ?, 'BAS-AutoSync')");
                    if ($stmt_log) {
                        $stmt_log->bind_param("is", $task_id, $log_details);
                        @$stmt_log->execute();
                        $stmt_log->close();
                    }

                    $updated_tasks[] = [
                        'id' => $task_id,
                        'model_name' => $log_model,
                        'ap' => $log_ap,
                        'csc' => $log_csc,
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
    'total_submissions_found' => $total_submissions,
    'synced_count' => $total_submissions,
    'updated_count' => $total_updated,
    'updated_tasks' => $updated_tasks,
    'synced_at' => $now_str,
    'debug_logs' => $debug_logs
], $is_cli, 200);

} catch (Throwable $e) {
    respond_output([
        'success' => false,
        'message' => 'Error eksekusi sinkronisasi: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
        'synced_count' => 0,
        'updated_tasks' => []
    ], $is_cli, 500);
}
