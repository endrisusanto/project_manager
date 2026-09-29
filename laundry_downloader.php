<?php
// laundry_downloader.php - Automated Downloader for BAS Laundry Mode Result ZIP files

require_once __DIR__ . '/config.php';

/**
 * Check if current execution environment is the Linux Docker server.
 */
function is_docker_linux_server() {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        return false;
    }
    return file_exists('/home/endri-pro/Downloads/CUCIAN') || file_exists('/var/www/html/downloads/cucian') || file_exists('/.dockerenv');
}

/**
 * Trigger background async download process (completely non-blocking for HTTP web requests)
 */
function trigger_async_laundry_download() {
    if (!is_docker_linux_server()) {
        return false;
    }

    $script_path = __DIR__ . '/laundry_downloader.php';
    if (!file_exists($script_path)) {
        return false;
    }

    // Spawn detached non-blocking CLI process in background
    $cmd = "nohup php " . escapeshellarg($script_path) . " --all > /dev/null 2>&1 &";
    @exec($cmd);
    return true;
}

/**
 * Get or initialize the target storage directory for Laundry ZIP downloads.
 * Default path on Linux Docker Server: /home/endri-pro/Downloads/CUCIAN/
 */
function get_laundry_download_dir() {
    $candidates = [
        '/home/endri-pro/Downloads/CUCIAN',
        '/var/www/html/downloads/cucian',
        __DIR__ . '/downloads/cucian',
        sys_get_temp_dir() . '/CUCIAN'
    ];

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $user_profile = getenv('USERPROFILE') ?: (getenv('HOMEDRIVE') . getenv('HOMEPATH'));
        if (!empty($user_profile)) {
            array_unshift($candidates, str_replace('\\', '/', $user_profile) . '/Downloads/CUCIAN');
        }
        array_unshift($candidates, 'C:/Downloads/CUCIAN', 'D:/Downloads/CUCIAN');
    }

    foreach ($candidates as $dir) {
        if (!file_exists($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (file_exists($dir) && is_writable($dir)) {
            return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
        }
    }

    $fallback = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'CUCIAN' . DIRECTORY_SEPARATOR;
    if (!file_exists($fallback)) {
        @mkdir($fallback, 0777, true);
    }
    return $fallback;
}

/**
 * Format bytes into human readable size
 */
function format_bytes_clean($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Retrieve active BAS session token and cookies.
 */
function get_bas_active_session($conn = null) {
    $session = null;
    $best_ts = 0;

    $candidate_session_paths = [
        __DIR__ . '/.bas_session.json',
        sys_get_temp_dir() . '/.bas_session.json',
        '/var/www/html/.bas_session.json',
        '/var/www/html/tkdn/.bas_session.json',
        '/var/www/html/project_manager/.bas_session.json',
        '/home/endri-pro/dev/App/project_manager/.bas_session.json'
    ];

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $candidate_session_paths[] = 'C:/xampp/htdocs/project_manager/.bas_session.json';
        $candidate_session_paths[] = 'C:/xampp/htdocs/tkdn/.bas_session.json';
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

    if ((!$session || (time() - $best_ts > 8 * 3600)) && $conn instanceof mysqli && !$conn->connect_error) {
        try {
            $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bas_session' LIMIT 1");
            if ($res && $row = $res->fetch_assoc()) {
                $db_data = json_decode($row['setting_value'], true);
                if ($db_data && !empty($db_data['sid'])) {
                    $db_ts = $db_data['timestamp'] ?? 0;
                    if ($db_ts > $best_ts) {
                        $session = $db_data;
                    }
                }
            }
        } catch (Throwable $e) {
            // Graceful fallback
        }
    }

    return $session;
}

/**
 * Check if a submission belongs to disfavored regional variants (TUR = Turkey, EEA = Europe, SER = Russia).
 */
if (!function_exists('is_disfavored_regional_build')) {
    function is_disfavored_regional_build($sub) {
        if (!is_array($sub)) return false;
        $fp = strtolower(trim(strval($sub['Fingerprint'] ?? $sub['fingerprint'] ?? $sub['binaryName'] ?? $sub['binary_name'] ?? '')));
        $dev = strtolower(trim(strval($sub['Device Code'] ?? $sub['deviceCode'] ?? $sub['device_code'] ?? '')));
        $carrier = strtoupper(trim(strval($sub['Carriers'] ?? $sub['Carrier'] ?? $sub['carrier'] ?? $sub['carriers'] ?? $sub['Buyer'] ?? $sub['buyer'] ?? '')));
        $csc = strtoupper(trim(strval($sub['CSC'] ?? $sub['csc'] ?? '')));

        // Match tur, eea, ser in device code (e.g. a15nstur, a15nseea, a15nsser, a33xnseea)
        if (preg_match('/(tur|eea|ser)($|\/|:|_)/i', $dev)) return true;

        // Match in fingerprint product path (e.g. samsung/a15nstur/..., samsung/a15nseea/..., samsung/a15nsser/...)
        if (preg_match('/samsung\/[a-z0-9_]*(tur|eea|ser)[\/:_]/i', $fp)) return true;

        // Match in carriers or buyer sales code (e.g. TUR, SER, EEA)
        $carriers = preg_split('/[\s,;]+/', $carrier);
        foreach ($carriers as $c) {
            $ct = trim($c);
            if ($ct === 'TUR' || $ct === 'SER' || $ct === 'EEA') return true;
        }

        // Match in CSC (e.g. TUR, SER, EEA)
        if (preg_match('/(TUR|SER|EEA)/i', $csc)) return true;

        return false;
    }
}

/**
 * Lookup preferred non-disfavored submission and fingerprint for a given AP or Base Submission ID.
 * Avoids TUR, EEA, and SER when multiple submissions exist.
 */
function resolve_best_submission_for_task($ap, $current_sub_id = '') {
    $candidate_csv_paths = [
        __DIR__ . '/SearchData_raw.csv',
        '/var/www/html/SearchData_raw.csv',
        '/home/endri-pro/dev/App/project_manager/SearchData_raw.csv',
        'C:/xampp/htdocs/tkdn/SearchData_raw.csv',
        'C:/xampp/htdocs/project_manager/SearchData_raw.csv'
    ];

    $clean_ap = strtoupper(trim(strval($ap)));
    $clean_sub_id = trim(strval($current_sub_id), "=\"' \t\n\r\0\x0B");

    $best_sub_id = $clean_sub_id;
    $best_fp = '';
    $best_score = -9999;

    foreach ($candidate_csv_paths as $csv_file) {
        if (file_exists($csv_file) && is_readable($csv_file)) {
            $fp = @fopen($csv_file, 'r');
            if ($fp) {
                $header = fgetcsv($fp);
                $col_map = [];
                if ($header) {
                    foreach ($header as $idx => $col) {
                        $col_map[strtolower(trim($col))] = $idx;
                    }
                }
                
                $id_idx = $col_map['id'] ?? $col_map['submission_id'] ?? -1;
                $fp_idx = $col_map['fingerprint'] ?? $col_map['binaryname'] ?? -1;
                $ap_idx = $col_map['ap'] ?? $col_map['ap_version'] ?? -1;
                $dev_idx = $col_map['device code'] ?? $col_map['device_code'] ?? -1;
                $carrier_idx = $col_map['carriers'] ?? $col_map['carrier'] ?? -1;
                $csc_idx = $col_map['csc'] ?? $col_map['csc_version'] ?? -1;
                $type_idx = $col_map['approval type'] ?? $col_map['approval_type'] ?? -1;

                if ($id_idx >= 0 && $fp_idx >= 0) {
                    while (($row = fgetcsv($fp)) !== false) {
                        $row_id = trim(strval($row[$id_idx] ?? ''), "=\"' \t\n\r\0\x0B");
                        $row_fp = trim(strval($row[$fp_idx] ?? ''), "=\"' \t\n\r\0\x0B");
                        $row_ap = $ap_idx >= 0 ? strtoupper(trim(strval($row[$ap_idx] ?? ''))) : '';
                        $row_type = $type_idx >= 0 ? trim(strval($row[$type_idx] ?? '')) : '';

                        if (stripos($row_type, 'Vendor') !== false || stripos($row_type, 'SafetyNet') !== false) {
                            continue;
                        }

                        $is_match = false;
                        if (!empty($clean_sub_id) && $row_id === $clean_sub_id) {
                            $is_match = true;
                        } elseif (!empty($clean_ap) && ($row_ap === $clean_ap || stripos($row_fp, $clean_ap) !== false)) {
                            $is_match = true;
                        }

                        if ($is_match) {
                            $sub_data = [
                                'Fingerprint' => $row_fp,
                                'Device Code' => $dev_idx >= 0 ? ($row[$dev_idx] ?? '') : '',
                                'Carriers' => $carrier_idx >= 0 ? ($row[$carrier_idx] ?? '') : '',
                                'CSC' => $csc_idx >= 0 ? ($row[$csc_idx] ?? '') : ''
                            ];

                            $is_disfavored = is_disfavored_regional_build($sub_data);
                            $is_xx = (stripos($sub_data['Device Code'], 'xx') !== false || preg_match('/samsung\/[a-z0-9_]*xx[\/:_]/i', strtolower($row_fp)));

                            $score = 100 + ($is_xx ? 200 : 0) - ($is_disfavored ? 500 : 0);
                            if ($row_id === $clean_sub_id) {
                                $score += 10; // Slight preference to existing sub id if equally scored
                            }

                            if ($score > $best_score) {
                                $best_score = $score;
                                $best_sub_id = $row_id;
                                $best_fp = $row_fp;
                            }
                        }
                    }
                }
                fclose($fp);
            }
        }
    }

    return [
        'submission_id' => $best_sub_id ?: $clean_sub_id,
        'fingerprint' => $best_fp
    ];
}

/**
 * Lookup fingerprint string by Submission ID.
 * Searches SearchData_raw.csv cache first, then BAS API fallback.
 */
function get_submission_fingerprint($submission_id) {
    if (empty($submission_id)) return '';
    $clean_sub_id = trim(strval($submission_id), "=\"' \t\n\r\0\x0B");

    $res = resolve_best_submission_for_task('', $clean_sub_id);
    return $res['fingerprint'] ?? '';
}

/**
 * Sanitize fingerprint string to be safe for filesystem filenames.
 * Replaces '/' and ':' with '_' and removes illegal filesystem characters.
 */
function sanitize_fingerprint_for_filename($fingerprint) {
    if (empty($fingerprint)) return '';
    $clean = trim($fingerprint);
    $clean = str_replace(['/', ':', '\\', '*', '?', '"', '<', '>', '|'], '_', $clean);
    $clean = preg_replace('/_+/', '_', $clean);
    return trim($clean, '_');
}

/**
 * Download Laundry ZIP for a specific task.
 * Renames All_{base_submission_id}.zip -> {AP}_Laundry_{base_sub_id}_{fingerprint}.zip
 *
 * @param array|int|string $task_or_id Task row or Task ID
 * @param bool $force Force re-download even if file exists
 * @param bool $is_auto_scan Set to true if called from background auto-downloader (applies status exclusion)
 * @return array Result metadata
 */
function download_laundry_zip($task_or_id, $force = false, $is_auto_scan = false) {
    global $conn;

    $task = null;
    if (is_array($task_or_id)) {
        $task = $task_or_id;
    } else {
        $task_id = intval($task_or_id);
        if ($task_id > 0 && isset($conn)) {
            $stmt = $conn->prepare("SELECT id, model_name, ap, base_submission_id, progress_status FROM gba_tasks WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $task_id);
            $stmt->execute();
            $task = $stmt->get_result()->fetch_assoc();
        }
    }

    if (!$task) {
        return ['success' => false, 'message' => 'Task not found.'];
    }

    $base_sub_id = trim($task['base_submission_id'] ?? '');
    if (empty($base_sub_id)) {
        return [
            'success' => false,
            'message' => "Task #{$task['id']} does not have a Base Submission ID (Not in laundry mode)."
        ];
    }

    $status = trim($task['progress_status'] ?? '');
    $status_upper = strtoupper($status);
    $is_excluded_status = (
        strpos($status_upper, 'APPROV') !== false ||
        strpos($status_upper, 'PASS') !== false ||
        strpos($status_upper, 'BATAL') !== false ||
        strpos($status_upper, 'CANCEL') !== false ||
        strpos($status_upper, 'REJECT') !== false ||
        strpos($status_upper, 'PENDING') !== false
    );

    // Only skip based on progress status if this is an automated background scan
    if ($is_auto_scan && !$force && $is_excluded_status) {
        return [
            'success' => false,
            'skipped' => true,
            'task_id' => $task['id'],
            'status' => $status,
            'message' => "Task #{$task['id']} dilewati auto-download karena berstatus '{$status}' (bukan task aktif/ongoing)."
        ];
    }

    $ap = trim($task['ap'] ?? '');
    $clean_ap = preg_replace('/[^a-zA-Z0-9_-]/', '', $ap);
    if (empty($clean_ap)) {
        $clean_ap = "Sub_{$base_sub_id}";
    }

    $resolved = resolve_best_submission_for_task($ap, $base_sub_id);
    if (!empty($resolved['submission_id'])) {
        $base_sub_id = $resolved['submission_id'];
    }
    $raw_fp = $resolved['fingerprint'] ?: get_submission_fingerprint($base_sub_id);
    $safe_fp = sanitize_fingerprint_for_filename($raw_fp);

    if (!empty($safe_fp)) {
        $filename = "{$clean_ap}_Laundry_{$base_sub_id}_{$safe_fp}.zip";
    } else {
        $filename = "{$clean_ap}_Laundry_{$base_sub_id}.zip";
    }

    $dir = get_laundry_download_dir();
    $target_file = $dir . $filename;
    $tmp_file = $dir . $filename . '.tmp.' . uniqid();

    // Check if already downloaded (either with full fingerprint suffix or legacy {clean_ap}_Laundry.zip)
    $legacy_target_file = $dir . "{$clean_ap}_Laundry.zip";
    if (!$force && file_exists($target_file) && filesize($target_file) > 1024) {
        $size = filesize($target_file);
        return [
            'success' => true,
            'skipped' => true,
            'filename' => $filename,
            'path' => $target_file,
            'fingerprint' => $raw_fp,
            'size' => $size,
            'size_formatted' => format_bytes_clean($size),
            'message' => "File {$filename} already exists (" . format_bytes_clean($size) . ")."
        ];
    } elseif (!$force && file_exists($legacy_target_file) && filesize($legacy_target_file) > 1024) {
        // Automatically rename legacy filename to the new descriptive filename with fingerprint
        @rename($legacy_target_file, $target_file);
        if (file_exists($target_file)) {
            $size = filesize($target_file);
            return [
                'success' => true,
                'skipped' => true,
                'filename' => $filename,
                'path' => $target_file,
                'fingerprint' => $raw_fp,
                'size' => $size,
                'size_formatted' => format_bytes_clean($size),
                'message' => "Renamed existing file to {$filename} (" . format_bytes_clean($size) . ")."
            ];
        }
    }

    $session = get_bas_active_session($conn);
    if (!$session || empty($session['sid'])) {
        return [
            'success' => false,
            'message' => 'Token session BAS (sid) tidak ditemukan. Silakan buka BAS di browser untuk sinkronisasi token.'
        ];
    }

    $sid = trim($session['sid']);
    $cookie_header = trim($session['cookie_header'] ?? '');
    if (empty($cookie_header)) {
        $cookie_string = "login_type=sso; sid={$sid}";
    } else {
        $cookie_string = $cookie_header;
        if (strpos($cookie_string, "login_type=") === false) {
            $cookie_string = "login_type=sso; " . $cookie_string;
        }
        if (strpos($cookie_string, "sid=") === false) {
            $cookie_string .= "; sid={$sid}";
        }
    }

    $download_url = "https://buildapprovalsystem.com/download/all?id=" . urlencode($base_sub_id);

    // Open file pointer for stream writing
    $fp = @fopen($tmp_file, 'w+b');
    if (!$fp) {
        return [
            'success' => false,
            'message' => "Cannot create temporary file at {$tmp_file}. Check directory permissions."
        ];
    }

    $proxy_urls = [
        'http://host.docker.internal:3800/api/bas/proxy',
        'http://127.0.0.1:3800/api/bas/proxy'
    ];

    $downloaded_successfully = false;
    $http_code = 0;
    $err_msg = '';

    // Attempt 1: Via MCP Proxy
    foreach ($proxy_urls as $proxy_url) {
        fseek($fp, 0);
        ftruncate($fp, 0);

        $ch = curl_init($proxy_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'url' => $download_url,
            'method' => 'GET',
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36',
                'Cookie' => $cookie_string,
                'Referer' => 'https://buildapprovalsystem.com/'
            ]
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        
        $exec_res = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($exec_res && $http_code === 200) {
            $downloaded_successfully = true;
            break;
        } else {
            $err_msg = $curl_err ?: "HTTP code {$http_code}";
        }
    }

    // Attempt 2: Direct BAS cURL fallback if proxy unreachable
    if (!$downloaded_successfully) {
        fseek($fp, 0);
        ftruncate($fp, 0);

        $ch = curl_init($download_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36",
            "Cookie: {$cookie_string}",
            "Referer: https://buildapprovalsystem.com/"
        ]);

        $exec_res = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($exec_res && $http_code === 200) {
            $downloaded_successfully = true;
        } else {
            $err_msg = $curl_err ?: "HTTP code {$http_code}";
        }
    }

    fflush($fp);
    fclose($fp);

    if (!$downloaded_successfully) {
        @unlink($tmp_file);
        return [
            'success' => false,
            'message' => "Download failed from BAS: {$err_msg}"
        ];
    }

    // Validate ZIP magic header (0x50 0x4B = "PK")
    $fh = @fopen($tmp_file, 'rb');
    $header = $fh ? fread($fh, 4) : '';
    if ($fh) fclose($fh);

    if (substr($header, 0, 2) !== "PK" || filesize($tmp_file) < 100) {
        $error_content = @file_get_contents($tmp_file, false, null, 0, 500);
        @unlink($tmp_file);
        return [
            'success' => false,
            'message' => "Downloaded payload is not a valid ZIP file. Server returned: " . strip_tags($error_content)
        ];
    }

    // Atomic rename to target destination
    if (!@rename($tmp_file, $target_file)) {
        @copy($tmp_file, $target_file);
        @unlink($tmp_file);
    }
    @chmod($target_file, 0777);

    $final_size = filesize($target_file);
    $size_formatted = format_bytes_clean($final_size);

    // Record activity log if database connection available
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        try {
            $task_id_log = intval($task['id'] ?? 0);
            $log_action = 'Laundry Auto-Download';
            $log_desc = "Downloaded {$filename} ({$size_formatted}) for Task #{$task_id_log} ({$task['model_name']} - {$ap}) from Base Sub ID #{$base_sub_id}";
            $user = $_SESSION['username'] ?? 'BAS-AutoSync';

            $stmt_log = $conn->prepare("INSERT INTO activity_log (task_id, action_type, details, user_email) VALUES (?, ?, ?, ?)");
            if ($stmt_log) {
                $stmt_log->bind_param("isss", $task_id_log, $log_action, $log_desc, $user);
                $stmt_log->execute();
                $stmt_log->close();
            }
        } catch (Throwable $e) {
            // Ignore logging failures
        }
    }

    return [
        'success' => true,
        'skipped' => false,
        'filename' => $filename,
        'path' => $target_file,
        'size' => $final_size,
        'size_formatted' => $size_formatted,
        'base_submission_id' => $base_sub_id,
        'message' => "Successfully downloaded {$filename} ({$size_formatted}) to {$target_file}"
    ];
}

/**
 * Scan database for all active laundry tasks and download missing ZIPs.
 * Only executes auto-downloads when running on the Linux Docker Server.
 */
function auto_download_all_pending_laundry($force = false) {
    global $conn;

    if (!is_docker_linux_server() && !$force) {
        return [
            'success' => true,
            'skipped' => true,
            'total' => 0,
            'message' => 'Auto-download Laundry hanya diaktifkan di Server Docker Linux.'
        ];
    }

    if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_error) {
        return ['success' => false, 'message' => 'Database connection unavailable.'];
    }

    $sql = "SELECT id, model_name, ap, base_submission_id, progress_status 
            FROM gba_tasks 
            WHERE base_submission_id IS NOT NULL 
              AND base_submission_id != '' 
              AND progress_status NOT IN ('Approved', 'Passed', 'Batal', 'Cancelled', 'Rejected', 'Pending Feedback', 'Pending', 'Feedback Sent')
              AND progress_status NOT LIKE '%Pending%'
              AND progress_status NOT LIKE '%Approv%'
              AND progress_status NOT LIKE '%Batal%'
            ORDER BY id DESC";

    $res = $conn->query($sql);
    if (!$res) {
        return ['success' => false, 'message' => $conn->error];
    }

    $results = [
        'total' => $res->num_rows,
        'downloaded' => 0,
        'skipped' => 0,
        'failed' => 0,
        'details' => []
    ];

    while ($row = $res->fetch_assoc()) {
        $res_dl = download_laundry_zip($row, $force, true);
        $results['details'][] = [
            'task_id' => $row['id'],
            'model' => $row['model_name'],
            'ap' => $row['ap'],
            'result' => $res_dl
        ];

        if ($res_dl['success']) {
            if (!empty($res_dl['skipped'])) {
                $results['skipped']++;
            } else {
                $results['downloaded']++;
            }
        } else {
            $results['failed']++;
        }
    }

    $results['success'] = true;
    return $results;
}

// CLI Execution Handler
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $options = getopt('', ['task-id::', 'all', 'force']);
    $force = isset($options['force']);

    if (isset($options['task-id'])) {
        $task_id = intval($options['task-id']);
        echo "[INFO] Downloading Laundry ZIP for Task #{$task_id}...\n";
        $res = download_laundry_zip($task_id, $force);
        echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } elseif (isset($options['all'])) {
        echo "[INFO] Scanning all pending Laundry tasks...\n";
        $res = auto_download_all_pending_laundry($force);
        echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo "Usage: php laundry_downloader.php [--task-id=<ID>] [--all] [--force]\n";
    }
}
