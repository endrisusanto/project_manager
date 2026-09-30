<?php
// sync_bas.php - Core Sync Engine connecting BAS (Build Approval System) to Project Manager local

if (ob_get_level() === 0) {
    ob_start();
}
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Register shutdown function to catch any fatal error and guarantee JSON response
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
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
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
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

    // ponytail: Batch Fetch 2x 2 Minggu (Total 1 Bulan) to prevent BAS gateway timeout
    $today_str = date('Y-m-d');
    $month_start_ts = strtotime(date('Y-m-01 00:00:00'));

    $search_batches = [
        [
            'name' => 'Batch 1 (14 Hari Terakhir)',
            'startDate' => date('Y-m-d\T00:00:00.000000+09:00', strtotime('-14 days')),
            'endDate' => date('Y-m-d\T23:59:59.000000+09:00', strtotime('+1 day')),
        ],
        [
            'name' => 'Batch 2 (15 s/d 30 Hari Lalu)',
            'startDate' => date('Y-m-d\T00:00:00.000000+09:00', strtotime('-30 days')),
            'endDate' => date('Y-m-d\T23:59:59.000000+09:00', strtotime('-14 days')),
        ]
    ];

    $search_url = "https://buildapprovalsystem.com/submission/search/createExcel";
    $search_url_fallback = "https://buildapprovalsystem.com/submission/searchSubmissions";

    $all_raw_submissions = [];
    $auth_error = null;
    $debug_logs = [];

    $req_headers = [
        "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36",
        "Accept: application/json, text/plain, */*",
        "Content-Type: application/json; charset=UTF-8",
        "Origin: https://buildapprovalsystem.com",
        "Referer: https://buildapprovalsystem.com/",
        "Cookie: {$cookie_string}"
    ];

    // Helper function to execute POST or GET requests to BAS (with Host Proxy support for Docker)
    $execute_bas_request = function($url, $method = 'POST', $json_payload = null, $timeout = 45) use ($cookie_string, $req_headers, &$auth_error, &$debug_logs) {
        $res_raw = false;
        $http_code = 0;

        // 1. Try MCP Host Network Proxy first (for reliable Docker/Host bridge)
        $proxy_endpoints = [];
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            $proxy_endpoints[] = 'http://host.docker.internal:3800/api/bas/proxy';
        }
        $proxy_endpoints[] = 'http://127.0.0.1:3800/api/bas/proxy';

        $header_map = [];
        foreach ($req_headers as $h) {
            $parts = explode(':', $h, 2);
            if (count($parts) === 2) {
                $header_map[trim($parts[0])] = trim($parts[1]);
            }
        }
        
        foreach ($proxy_endpoints as $proxy_url) {
            if (function_exists('curl_init')) {
                $ch_p = curl_init($proxy_url);
                $proxy_payload = [
                    'url' => $url,
                    'method' => $method,
                    'headers' => $header_map,
                    'body' => $json_payload
                ];
                curl_setopt_array($ch_p, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($proxy_payload),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => 2
                ]);
                $p_res = curl_exec($ch_p);
                $p_code = curl_getinfo($ch_p, CURLINFO_HTTP_CODE);
                curl_close($ch_p);

                if ($p_code >= 200 && $p_code < 500 && !empty($p_res)) {
                    $res_raw = $p_res;
                    $http_code = $p_code;
                    break;
                }
            }
        }

        // 2. Direct cURL fallback
        if (empty($res_raw) && function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
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
    $extract_items_from_response = function($raw_body) use (&$parse_bas_csv, &$execute_bas_request, $req_headers, &$debug_logs) {
        if (empty($raw_body)) return [];
        $parsed = json_decode($raw_body, true);
        if (!is_array($parsed)) return [];

        // Check if response contains downloadLink for CSV export
        if (!empty($parsed['downloadLink'])) {
            $csv_url = $parsed['downloadLink'];
            $csv_content = null;

            // Try direct cURL download with authentication headers first
            if (function_exists('curl_init')) {
                $ch_d = curl_init($csv_url);
                curl_setopt_array($ch_d, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_TIMEOUT => 45,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_HTTPHEADER => $req_headers
                ]);
                $csv_content = curl_exec($ch_d);
                curl_close($ch_d);
            }

            if (empty($csv_content)) {
                $res_csv = $execute_bas_request($csv_url, 'GET', null, 45);
                $csv_content = $res_csv['body'];
            }

            if ($csv_content) {
                @file_put_contents(__DIR__ . '/SearchData_raw.csv', $csv_content);
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

    // Execute Batch Fetch: 2x 2 Minggu across all carriers to prevent timeout
    $last_http_code = 0;
    $live_fetched_count = 0;

    foreach ($search_batches as $b) {
        $batch_payload = [
            'startDate' => $b['startDate'],
            'endDate' => $b['endDate'],
            'approvalType' => 'All',
            'carriers' => '',
            'lastUpdateCheck' => 'false',
            'legacy' => true,
            'pageSize' => 1000,
            'count' => true
        ];

        $res_search = $execute_bas_request($search_url, 'POST', $batch_payload, 30);
        $last_http_code = $res_search['code'];

        if (!empty($res_search['body'])) {
            $found_items = $extract_items_from_response($res_search['body']);
            if (!empty($found_items)) {
                $batch_count = 0;
                foreach ($found_items as $it) {
                    if (is_array($it)) {
                        $sub_id_val = strval($it['Id'] ?? $it['id'] ?? $it['ID'] ?? $it['submission_id'] ?? $it['submissionId'] ?? '');
                        if (!empty($sub_id_val)) {
                            $all_raw_submissions[$sub_id_val] = $it;
                            $batch_count++;
                        } else {
                            $sub_key = ($it['AP'] ?? '') . '_' . ($it['CSC'] ?? '') . '_' . rand();
                            $all_raw_submissions[$sub_key] = $it;
                            $batch_count++;
                        }
                    }
                }
                $live_fetched_count += $batch_count;
                $debug_logs[] = "{$b['name']}: Mengambil {$batch_count} records (Total unik: " . count($all_raw_submissions) . ").";
            }
        }
    }

    // Auto-update SearchData_raw.csv whenever fresh items are retrieved from live API
    if ($live_fetched_count > 0 && !empty($all_raw_submissions)) {
        $first_item = reset($all_raw_submissions);
        if (is_array($first_item)) {
            $headers = array_keys($first_item);
            $csv_out = fopen('php://memory', 'r+');
            fputcsv($csv_out, $headers);
            foreach ($all_raw_submissions as $row_data) {
                $csv_row = [];
                foreach ($headers as $h) {
                    $csv_row[] = $row_data[$h] ?? '';
                }
                fputcsv($csv_out, $csv_row);
            }
            rewind($csv_out);
            $new_csv_content = stream_get_contents($csv_out);
            fclose($csv_out);
            if (!empty($new_csv_content)) {
                @file_put_contents(__DIR__ . '/SearchData_raw.csv', $new_csv_content);
                $debug_logs[] = "Auto-updated SearchData_raw.csv (" . count($all_raw_submissions) . " records saved)";
            }
        }
    }

    $is_fallback_cache = false;

    // Fallback: If Search API returned empty or failed, check local SearchData_raw.csv cache
    if (empty($all_raw_submissions) && file_exists(__DIR__ . '/SearchData_raw.csv')) {
        $cached_csv = @file_get_contents(__DIR__ . '/SearchData_raw.csv');
        if (!empty($cached_csv)) {
            $cached_items = $parse_bas_csv($cached_csv);
            foreach ($cached_items as $it) {
                if (is_array($it)) {
                    $sub_id_val = strval($it['Id'] ?? $it['id'] ?? $it['ID'] ?? $it['submission_id'] ?? $it['submissionId'] ?? '');
                    if (!empty($sub_id_val)) {
                        $all_raw_submissions[$sub_id_val] = $it;
                    } else {
                        $sub_key = ($it['AP'] ?? '') . '_' . ($it['CSC'] ?? '') . '_' . rand();
                        $all_raw_submissions[$sub_key] = $it;
                    }
                }
            }
            if (!empty($all_raw_submissions)) {
                $is_fallback_cache = true;
                $debug_logs[] = "[PERINGATAN] Gagal koneksi langsung ke portal BAS dengan SID saat ini (Timeout/HTTP {$last_http_code}).";
                $debug_logs[] = "Menggunakan fallback cache lokal SearchData_raw.csv (" . count($all_raw_submissions) . " submissions dimuat).";
            }
        }
    }

    // Fallback to mySubmission only if general search and cache completely returned nothing
    if (empty($all_raw_submissions)) {
        $fallback_res = $execute_bas_request("https://buildapprovalsystem.com/submission/mySubmission/submitted/all", 'GET', null, 15);
        if ($fallback_res['code'] < 400 && !empty($fallback_res['body'])) {
            $fallback_items = $extract_items_from_response($fallback_res['body']);
            foreach ($fallback_items as $it) {
                if (is_array($it)) {
                    $sub_id_val = strval($it['Id'] ?? $it['id'] ?? $it['ID'] ?? $it['submission_id'] ?? $it['submissionId'] ?? '');
                    if (!empty($sub_id_val)) {
                        $all_raw_submissions[$sub_id_val] = $it;
                    } else {
                        $sub_key = ($it['AP'] ?? '') . '_' . ($it['CSC'] ?? '') . '_' . rand();
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

    // 3. Process submissions and match with gba_tasks (In-Memory Fast Matching)
    $updated_tasks = [];
    $now_str = date('Y-m-d H:i:s');
    $today_str = date('Y-m-d');

    // Pre-load all local tasks into memory to avoid 10,000+ DB queries
    $local_tasks_by_id = [];
    $local_tasks_by_ap = [];
    $local_tasks_by_subid = [];

    $res_tasks = $conn->query("SELECT id, model_name, ap, cp, csc, test_plan_type, progress_status, reviewer_email, is_urgent, submission_id, base_submission_id, approved_date, submission_date, sign_off_date FROM gba_tasks");
    if ($res_tasks) {
        while ($t = $res_tasks->fetch_assoc()) {
            $tid = (int)$t['id'];
            $local_tasks_by_id[$tid] = $t;
            $ap_clean = strtoupper(trim((string)($t['ap'] ?? '')));
            if (!empty($ap_clean)) {
                $local_tasks_by_ap[$ap_clean][] = $tid;
            }
            $sub_clean = trim((string)($t['submission_id'] ?? ''));
            if (!empty($sub_clean) && $sub_clean !== '-' && $sub_clean !== '0') {
                $local_tasks_by_subid[$sub_clean][] = $tid;
            }
        }
    }

    // Helper to extract AP version
    function extract_ap_version($fingerprint, $raw_ap = '') {
        if (!empty($raw_ap)) {
            return trim($raw_ap);
        }
        if (empty($fingerprint)) {
            return '';
        }
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

    function get_status_priority_rank($status) {
        if (empty($status)) return 0;
        $s = strtoupper(trim(strval($status)));
        if (strpos($s, 'APPROV') !== false || strpos($s, 'COMPLET') !== false || strpos($s, 'HOLD') !== false) {
            return 100;
        }
        if (strpos($s, 'PENDING') !== false) {
            return 80;
        }
        if (strpos($s, 'SUBMIT') !== false || strpos($s, 'WAITING') !== false || strpos($s, 'REVIEW') !== false) {
            return 60;
        }
        if (strpos($s, 'ONGOING') !== false || strpos($s, 'PROGRESS') !== false || strpos($s, 'TEST') !== false) {
            return 40;
        }
        if (strpos($s, 'REJECT') !== false || strpos($s, 'FAIL') !== false || strpos($s, 'DROP') !== false) {
            return 20;
        }
        if (strpos($s, 'BATAL') !== false || strpos($s, 'CANCEL') !== false) {
            return 10;
        }
        return 0;
    }

    /**
     * Check if a submission belongs to disfavored regional variants (TUR = Turkey, EEA = Europe, SER = Russia, INS = India, etc.).
     * When multiple submissions exist for the same AP, we must NOT use single-region foreign builds.
     */
    if (!function_exists('is_disfavored_regional_build')) {
        function is_disfavored_regional_build($sub) {
            if (!is_array($sub)) return false;
            $fp = strtolower(trim(strval($sub['Fingerprint'] ?? $sub['fingerprint'] ?? $sub['binaryName'] ?? $sub['binary_name'] ?? '')));
            $dev = strtolower(trim(strval($sub['Device Code'] ?? $sub['deviceCode'] ?? $sub['device_code'] ?? '')));
            $carrier = strtoupper(trim(strval($sub['Carriers'] ?? $sub['Carrier'] ?? $sub['carrier'] ?? $sub['carriers'] ?? $sub['Buyer'] ?? $sub['buyer'] ?? '')));
            $csc = strtoupper(trim(strval($sub['CSC'] ?? $sub['csc'] ?? '')));

            // Match disfavored region suffix in device code
            if (preg_match('/(tur|eea|ser|ins|nser|nins|ntur)$/i', $dev) || preg_match('/_(tur|eea|ser|ins)($|_)/i', $dev)) return true;

            // Match in fingerprint product path
            if (preg_match('/^samsung\/[a-z0-9_]*(tur|eea|ser|ins|owo|ojm)\//i', $fp)) return true;

            // Match non-SEA regional CSC groups (e.g. Latin America, Middle East, India)
            if (preg_match('/(OWO|OJM|OWA|OWE|OXE|ODM)/i', $csc)) return true;

            // Match single-country non-Indonesia carrier code when only that single non-XID carrier is present
            $carriers = preg_split('/[\s,;]+/', $carrier);
            if (count($carriers) <= 2 && !preg_match('/\b(XID|OXM|OXT)\b/i', $carrier)) {
                $disfavored_singles = ['TUR', 'SER', 'EEA', 'EUX', 'INS', 'CAU', 'NPB', 'BKD', 'PNG', 'BNG'];
                foreach ($carriers as $c) {
                    if (in_array(trim($c), $disfavored_singles)) return true;
                }
            }

            return false;
        }
    }

    if (!function_exists('parse_csc_group_and_suffix')) {
        function parse_csc_group_and_suffix($csc_or_fp) {
            $str = strtoupper(trim((string)$csc_or_fp));
            if (empty($str)) return ['group' => '', 'suffix' => '', 'full' => ''];
            
            // If it's a fingerprint, extract the part after AP / after '_' or inside build id
            if (strpos($str, '/') !== false || strpos($str, ':') !== false) {
                if (preg_match('/_([A-Z0-9]{3,4}[A-Z0-9]{4,5})(:|$)/i', $str, $m)) {
                    $str = $m[1];
                } elseif (preg_match('/([A-Z0-9]{3,8}O[A-Z0-9]{2,4}[A-Z0-9]{4,8})/i', $str, $m)) {
                    $str = $m[1];
                }
            }
            
            $group = '';
            $suffix = '';
            
            if (preg_match('/(O[A-Z]{2,3})([A-Z0-9]{4,5})$/i', $str, $m)) {
                $group = strtoupper($m[1]);
                $suffix = strtoupper($m[2]);
            } elseif (preg_match('/([A-Z0-9]{4,5})$/i', $str, $m)) {
                $suffix = strtoupper($m[1]);
            }
            
            return [
                'group' => $group,
                'suffix' => $suffix,
                'full' => $str
            ];
        }
    }

    // Group submissions by AP version
    $submissions_by_ap = [];
    $all_submissions_list = [];

    foreach ($submissions as $sub) {
        if (!is_array($sub)) continue;

        $raw_ap = trim(strval($sub['AP'] ?? $sub['ap'] ?? $sub['ap_version'] ?? $sub['apVersion'] ?? ''));
        $fingerprint = trim(strval($sub['Fingerprint'] ?? $sub['fingerprint'] ?? $sub['binaryName'] ?? $sub['binary_name'] ?? $sub['build_number'] ?? ''));
        $ap_ver = extract_ap_version($fingerprint, $raw_ap);
        $sub_id_val = trim(strval($sub['Id'] ?? $sub['id'] ?? $sub['ID'] ?? $sub['submission_id'] ?? $sub['submissionId'] ?? $sub['Submission ID'] ?? ''), "=\"' \t\n\r\0\x0B");

        $approval_type = trim(strval($sub['Approval Type'] ?? $sub['approvalType'] ?? $sub['approval_type'] ?? ''));
        $is_vendor_or_safetynet = (stripos($approval_type, 'Vendor') !== false || stripos($approval_type, 'SafetyNet') !== false);
        if ($is_vendor_or_safetynet) continue;

        $key = !empty($ap_ver) ? strtoupper($ap_ver) : (!empty($sub_id_val) ? 'SUB_' . $sub_id_val : uniqid('sub_'));
        $submissions_by_ap[$key][] = $sub;
        $all_submissions_list[] = $sub;
    }

    // Helper to evaluate and rank candidate submissions for a specific task
    // ponytail: strictly match ONLY submissions whose carrier contains XID
    $evaluate_best_subs_for_task = function($candidate_subs, $task) {
        $best_xid_sub = null;
        $best_xid_rank = -9999;

        $task_ap = strtoupper(trim((string)($task['ap'] ?? '')));
        $task_csc = strtoupper(trim((string)($task['csc'] ?? '')));
        $task_csc_info = parse_csc_group_and_suffix($task_csc);
        $task_ap_suffix = strlen($task_ap) >= 5 ? substr($task_ap, -5) : '';

        foreach ($candidate_subs as $sub) {
            $raw_carrier = trim(strval($sub['Carriers'] ?? $sub['Carrier'] ?? $sub['carrier'] ?? $sub['carriers'] ?? $sub['Buyer'] ?? $sub['buyer'] ?? $sub['Sales Code'] ?? $sub['sales_code'] ?? ''));
            $raw_csc = trim(strval($sub['CSC'] ?? $sub['csc'] ?? $sub['csc_version'] ?? $sub['cscVersion'] ?? ''));
            $fingerprint = trim(strval($sub['Fingerprint'] ?? $sub['fingerprint'] ?? $sub['binaryName'] ?? $sub['binary_name'] ?? $sub['build_number'] ?? ''));

            // 100% Strict Carrier XID: must have carrier XID
            $is_xid = (!empty($raw_carrier) && preg_match('/\bXID\b/i', $raw_carrier));
            if (!$is_xid) {
                continue; // Hard skip any submission that does not have carrier XID
            }

            // Filter by Test Plan Type vs BAS Approval Type (Normal MR = NormalException, SMR = SMR)
            $task_plan = strtoupper(trim((string)($task['test_plan_type'] ?? '')));
            $cand_approval = strtoupper(trim(strval($sub['Approval Type'] ?? $sub['approvalType'] ?? $sub['approval_type'] ?? '')));

            if (!empty($task_plan) && !empty($cand_approval)) {
                if (strpos($task_plan, 'NORMAL') !== false) {
                    // Normal MR in GBA must strictly match NormalException in BAS (Regular is NOT Normal MR)
                    if (strpos($cand_approval, 'NORMAL') === false) {
                        continue;
                    }
                } elseif (strpos($task_plan, 'SMR') !== false) {
                    // SMR in GBA must match SMR in BAS
                    if (strpos($cand_approval, 'SMR') === false) {
                        continue;
                    }
                } elseif (strpos($task_plan, 'REGULAR') !== false) {
                    if (strpos($cand_approval, 'REGULAR') === false) {
                        continue;
                    }
                }
            }

            $cand_csc_info = parse_csc_group_and_suffix($raw_csc ?: $fingerprint);
            $raw_status = trim($sub['Status'] ?? $sub['status'] ?? $sub['progress_status'] ?? $sub['approvalStatus'] ?? $sub['submissionStatus'] ?? '');
            $base_rank = get_status_priority_rank($raw_status);
            $score = $base_rank;

            // CSC Suffix Matching
            if (!empty($cand_csc_info['suffix'])) {
                $exp_suffix = !empty($task_csc_info['suffix']) ? $task_csc_info['suffix'] : $task_ap_suffix;
                if (!empty($exp_suffix)) {
                    if ($cand_csc_info['suffix'] === $exp_suffix) {
                        $score += 150;
                    } else {
                        $score -= 300;
                    }
                }
            }

            if ($score > $best_xid_rank) {
                $best_xid_rank = $score;
                $best_xid_sub = $sub;
            }
        }

        return ['xid_sub' => $best_xid_sub];
    };

    foreach ($submissions_by_ap as $ap_key => $ap_candidates) {
        $lookup_ap = strtoupper(trim((string)$ap_key));

        // Fast In-Memory Task Matching
        $matched_task_ids = [];

        // Match 1: Exact AP
        if (!empty($lookup_ap) && isset($local_tasks_by_ap[$lookup_ap])) {
            foreach ($local_tasks_by_ap[$lookup_ap] as $tid) {
                $matched_task_ids[$tid] = true;
            }
        }

        // Match 2: AP substring fallback
        if (empty($matched_task_ids) && !empty($lookup_ap) && strlen($lookup_ap) >= 8) {
            foreach ($local_tasks_by_id as $tid => $t_row) {
                $db_ap = strtoupper(trim((string)($t_row['ap'] ?? '')));
                if (!empty($db_ap) && (strpos($db_ap, $lookup_ap) !== false || strpos($lookup_ap, $db_ap) !== false)) {
                    $matched_task_ids[$tid] = true;
                }
            }
        }

        foreach (array_keys($matched_task_ids) as $task_id) {
            $matched_task = $local_tasks_by_id[$task_id] ?? null;
            if (!$matched_task) continue;

            $subs_eval = $evaluate_best_subs_for_task($ap_candidates, $matched_task);
            $xid_sub = $subs_eval['xid_sub'] ?? null;

            // Strict XID only: If no XID submission exists in BAS, do not touch or update this task
            if (!$xid_sub || !is_array($xid_sub)) continue;

            $primary_sub = $xid_sub;
            $raw_ap = trim(strval($primary_sub['AP'] ?? $primary_sub['ap'] ?? $primary_sub['ap_version'] ?? $primary_sub['apVersion'] ?? ''));
            $raw_csc = trim(strval($primary_sub['CSC'] ?? $primary_sub['csc'] ?? $primary_sub['csc_version'] ?? $primary_sub['cscVersion'] ?? ''));
            $raw_cp = trim(strval($primary_sub['CP'] ?? $primary_sub['cp'] ?? $primary_sub['cp_version'] ?? $primary_sub['cpVersion'] ?? ''));
            $fingerprint = trim(strval($primary_sub['Fingerprint'] ?? $primary_sub['fingerprint'] ?? $primary_sub['binaryName'] ?? $primary_sub['binary_name'] ?? $primary_sub['build_number'] ?? ''));

            $ap_version = extract_ap_version($fingerprint, $raw_ap);
            $csc_version = extract_csc_version($fingerprint, $raw_csc);
            $model_name = trim($primary_sub['Model Name'] ?? $primary_sub['modelName'] ?? $primary_sub['model_name'] ?? $primary_sub['Model'] ?? $primary_sub['model'] ?? '');

            $xid_sub_id = trim(strval($xid_sub['Id'] ?? $xid_sub['id'] ?? $xid_sub['ID'] ?? $xid_sub['submission_id'] ?? $xid_sub['submissionId'] ?? ''), "=\"' \t\n\r\0\x0B");
            $xid_base_sub_id = trim(strval($xid_sub['Base Submission ID'] ?? $xid_sub['baseSubmissionId'] ?? $xid_sub['base_submission_id'] ?? $xid_sub['Base ID'] ?? $xid_sub['base_id'] ?? ''), "=\"' \t\n\r\0\x0B");

            $old_status = $matched_task['progress_status'];
            $old_reviewer = $matched_task['reviewer_email'];
            $old_urgent = (int)$matched_task['is_urgent'];
            $old_sub_id = $matched_task['submission_id'];
            $old_base_sub_id = $matched_task['base_submission_id'] ?? '';
            $old_sub_date = $matched_task['submission_date'];

            $need_update = false;
            $updates = [];
            $types = "";
            $params = [];
            $final_status = $old_status;

            $target_progress_status = null;
            $approved_date = null;
            $sub_date = null;
            $reviewer = '';
            $is_urgent = 0;
            
            // 1. Task progress, dates, reviewer, and urgent updates strictly from XID submission
            $raw_status = trim($xid_sub['Status'] ?? $xid_sub['status'] ?? $xid_sub['progress_status'] ?? $xid_sub['approvalStatus'] ?? $xid_sub['submissionStatus'] ?? '');
            $reviewer = trim(strval($xid_sub['Reviewer'] ?? $xid_sub['reviewer'] ?? $xid_sub['reviewer_email'] ?? $xid_sub['reviewerEmail'] ?? $xid_sub['reviewed_by'] ?? $xid_sub['PL Email'] ?? $xid_sub['Reviewer Group'] ?? ''), "=\"' \t\n\r\0\x0B");
            $urgent_raw = $xid_sub['Urgent'] ?? $xid_sub['urgent'] ?? $xid_sub['is_urgent'] ?? $xid_sub['isUrgent'] ?? false;
            $is_urgent = ($urgent_raw === true || $urgent_raw === 1 || $urgent_raw === '1' || strtolower(strval($urgent_raw)) === 'true' || strtolower(strval($urgent_raw)) === 'urgent' || strtolower(strval($urgent_raw)) === 'y') ? 1 : 0;

            $raw_submission_date = $xid_sub['Submission Date'] ?? $xid_sub['submissionDate'] ?? $xid_sub['submission_date'] ?? $xid_sub['submitted_at'] ?? $xid_sub['date'] ?? null;
            $raw_approval_date = $xid_sub['Approval Date'] ?? $xid_sub['approvalDate'] ?? $xid_sub['approved_date'] ?? $xid_sub['approvedAt'] ?? $xid_sub['Review Completion Time'] ?? $xid_sub['reviewCompletionTime'] ?? null;

            $sub_date = parse_bas_date($raw_submission_date);
            $approved_date = parse_bas_date($raw_approval_date);

            $target_progress_status = null;
            $norm_status_upper = strtoupper($raw_status);

            // ponytail: jika di BAS statusnya 'Passed', ignore / jangan ubah status task di project manager
            if (strpos($norm_status_upper, 'PASS') !== false) {
                $target_progress_status = null;
            } elseif (strpos($norm_status_upper, 'PENDING') !== false) {
                $target_progress_status = 'Pending Feedback';
            } elseif (strpos($norm_status_upper, 'APPROV') !== false || strpos($norm_status_upper, 'COMPLET') !== false || strpos($norm_status_upper, 'HOLD') !== false) {
                $target_progress_status = 'Approved';
                if (empty($approved_date)) {
                    $approved_date = $sub_date ?: $today_str;
                }
            } elseif (strpos($norm_status_upper, 'SUBMIT') !== false || strpos($norm_status_upper, 'WAITING') !== false || strpos($norm_status_upper, 'REVIEW') !== false) {
                $target_progress_status = 'Submitted';
            } elseif (strpos($norm_status_upper, 'REJECT') !== false || strpos($norm_status_upper, 'FAIL') !== false || strpos($norm_status_upper, 'DROP') !== false || strpos($norm_status_upper, 'BATAL') !== false || strpos($norm_status_upper, 'CANCEL') !== false) {
                $target_progress_status = 'Batal';
            } elseif (strpos($norm_status_upper, 'ONGOING') !== false || strpos($norm_status_upper, 'PROGRESS') !== false || strpos($norm_status_upper, 'TEST') !== false) {
                $target_progress_status = 'Test Ongoing';
            }

            $old_status_rank = get_status_priority_rank($old_status);
            $target_status_rank = get_status_priority_rank($target_progress_status);
            $is_rejected_or_batal = in_array($target_progress_status, ['Batal', 'Rejected']);

            if ($target_progress_status !== null && $target_progress_status !== $old_status && !$is_rejected_or_batal) {
                if ($target_status_rank >= $old_status_rank || $old_status !== 'Approved') {
                    $updates[] = "progress_status = ?";
                    $types .= "s";
                    $params[] = $target_progress_status;
                    $final_status = $target_progress_status;
                    $need_update = true;
                }
            }

            if ($approved_date && $approved_date !== $matched_task['approved_date']) {
                $updates[] = "approved_date = ?";
                $types .= "s";
                $params[] = $approved_date;
                $need_update = true;
            }

            if ($sub_date && $sub_date !== $matched_task['submission_date']) {
                $updates[] = "submission_date = ?";
                $types .= "s";
                $params[] = $sub_date;
                $need_update = true;
            }

            if (!empty($reviewer) && trim($reviewer) !== trim(strval($old_reviewer))) {
                $updates[] = "reviewer_email = ?";
                $types .= "s";
                $params[] = $reviewer;
                $need_update = true;
            }

            if ($is_urgent !== $old_urgent) {
                $updates[] = "is_urgent = ?";
                $types .= "i";
                $params[] = $is_urgent;
                $need_update = true;
            }

            // Strict XID submission ID: only stored in submission_id
            if (!empty($xid_sub_id) && trim($xid_sub_id) !== trim(strval($old_sub_id))) {
                $updates[] = "submission_id = ?";
                $types .= "s";
                $params[] = $xid_sub_id;
                $need_update = true;
            }

            // Base submission ID from XID submission's Base ID field
            if (!empty($xid_base_sub_id) && trim($xid_base_sub_id) !== trim(strval($old_base_sub_id))) {
                $updates[] = "base_submission_id = ?";
                $types .= "s";
                $params[] = $xid_base_sub_id;
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

                        $log_details = "AP: {$log_ap} | CSC: {$log_csc} | Status: [{$old_status} -> {$final_status}]";
                        $effective_sub_id = !empty($xid_sub_id) ? $xid_sub_id : $old_sub_id;
                        $effective_base_id = !empty($target_base_id) ? $target_base_id : $old_base_sub_id;
                        if (!empty($effective_sub_id)) {
                            $log_details .= " | SubID: {$effective_sub_id}";
                        }
                        if (!empty($effective_base_id)) {
                            $log_details .= " | BaseSubID: {$effective_base_id}";
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
                            'submission_id' => $effective_sub_id,
                            'base_submission_id' => $effective_base_id
                        ];

                        // Keep local cache up to date
                        $matched_task['progress_status'] = $final_status;
                        $matched_task['base_submission_id'] = $effective_base_id;
                        $matched_task['submission_id'] = $effective_sub_id;
                        $local_tasks_by_id[$task_id] = $matched_task;
                    }
                    $stmt_up->close();
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

// 5. Trigger Laundry Auto-Download in background asynchronously (Linux Docker Server only)
if (file_exists(__DIR__ . '/laundry_downloader.php')) {
    @require_once __DIR__ . '/laundry_downloader.php';
    if (function_exists('trigger_async_laundry_download')) {
        @trigger_async_laundry_download();
    }
}

// 5. Build summary and return response
$total_submissions = count($submissions);
$total_updated = count($updated_tasks);

if ($is_fallback_cache) {
    $message = "Gagal terhubung ke BAS dengan SID saat ini (menggunakan cache lokal): {$total_submissions} submission dicek, {$total_updated} task di-update. Silakan refresh session BAS di browser.";
} else {
    $message = "Sinkronisasi BAS berhasil: {$total_submissions} submission dicek, {$total_updated} task di-update.";
}

respond_output([
    'success' => true,
    'message' => $message,
    'is_fallback_cache' => $is_fallback_cache,
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
