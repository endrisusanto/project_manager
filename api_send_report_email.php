<?php
require_once "config.php";
require_once "session.php";
require_once "marketing_name_mapper.php";

// Corporate SMTP handshakes may require extended connection time.
@set_time_limit(300);
@ini_set('max_execution_time', '300');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Gunakan POST.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: [];

$rawTo = $input['to'] ?? [];
$rawCc = $input['cc'] ?? [];
$customSubject = trim($input['subject'] ?? '');

$toArray = is_array($rawTo) ? $rawTo : array_filter(array_map('trim', explode(',', (string)$rawTo)));
$ccArray = is_array($rawCc) ? $rawCc : array_filter(array_map('trim', explode(',', (string)$rawCc)));

$validTo = array_values(array_filter($toArray, function($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}));

$validCc = array_values(array_filter($ccArray, function($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}));

if (empty($validTo)) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => 'Daftar penerima (To) kosong atau tidak ada format email yang valid.'
    ]);
    exit;
}

$is_weekly = (isset($input['report_type']) && strtolower($input['report_type']) === 'weekly');
$target_date = isset($input['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['date']) ? $input['date'] : date('Y-m-d');

if ($is_weekly) {
    // --- 1. DATE CALCULATION: WEDNESDAY TO TUESDAY CYCLE ---
    $current_dt = new DateTime($target_date);
    $day_of_week = (int)$current_dt->format('w');
    $diff_to_wed = $day_of_week >= 3 ? $day_of_week - 3 : $day_of_week + 4;
    $start_dt = (clone $current_dt)->modify("-{$diff_to_wed} days");
    $end_dt = (clone $start_dt)->modify("+6 days");
    $start_date_str = $start_dt->format('Y-m-d');
    $end_date_str = $end_dt->format('Y-m-d');
    $range_label = $start_dt->format('d M Y') . ' - ' . $end_dt->format('d M Y');
    $subject = !empty($customSubject) ? $customSubject : "[WEEKLY REPORT GBA] Insight Summary ({$range_label})";

    // --- 1B. MULTI-WEEK MATRIX CALCULATION (Total Year + W-prev, W-curr, W-next) ---
    $curr_year = $start_dt->format('Y');
    $w_curr_num = (int)$start_dt->format('W');

    $prev_start_dt = (clone $start_dt)->modify('-7 days');
    $prev_end_dt = (clone $prev_start_dt)->modify('+6 days');
    $prev_start_str = $prev_start_dt->format('Y-m-d');
    $prev_end_str = $prev_end_dt->format('Y-m-d');
    $w_prev_num = (int)$prev_start_dt->format('W');

    $next_start_dt = (clone $start_dt)->modify('+7 days');
    $next_end_dt = (clone $next_start_dt)->modify('+6 days');
    $next_start_str = $next_start_dt->format('Y-m-d');
    $next_end_str = $next_end_dt->format('Y-m-d');
    $w_next_num = (int)$next_start_dt->format('W');

    // 8-Day Schedule Column Generation (Wednesday to next Wednesday)
    $schedule_days = [];
    for ($i = 0; $i < 8; $i++) {
        $d = (clone $start_dt)->modify("+{$i} days");
        $schedule_days[] = [
            'date_str' => $d->format('Y-m-d'),
            'day_label' => $d->format('j M'),
            'is_weekend' => in_array((int)$d->format('w'), [0, 6]),
            'day_name' => $d->format('D')
        ];
    }

    // Helper to check if task falls within date range
    function is_task_in_week_range_email($task, $start, $end) {
        $dates = [
            $task['request_date'] ?? null,
            $task['submission_date'] ?? null,
            $task['approved_date'] ?? null,
            $task['deadline'] ?? null
        ];
        if (!empty($task['updated_at'])) {
            $dates[] = substr($task['updated_at'], 0, 10);
        }
        foreach ($dates as $d) {
            if (!empty($d) && $d >= $start && $d <= $end) {
                return true;
            }
        }
        return false;
    }

    // Fetch all tasks for the current year to build the multi-week matrix
    $matrix_sql = "SELECT t.id, t.pic_email, t.progress_status, t.request_date, t.submission_date, t.approved_date, t.deadline, t.updated_at, u.username 
                   FROM gba_tasks t 
                   LEFT JOIN users u ON t.pic_email = u.email 
                   WHERE (
                       YEAR(t.request_date) = ? OR 
                       YEAR(t.submission_date) = ? OR 
                       YEAR(t.approved_date) = ? OR 
                       YEAR(t.deadline) = ? OR 
                       YEAR(t.updated_at) = ?
                   )";
    $stmt_m = $conn->prepare($matrix_sql);
    $stmt_m->bind_param("sssss", $curr_year, $curr_year, $curr_year, $curr_year, $curr_year);
    $stmt_m->execute();
    $res_m = $stmt_m->get_result();
    $matrix_tasks = [];
    if ($res_m && $res_m->num_rows > 0) {
        while ($r = $res_m->fetch_assoc()) {
            $matrix_tasks[] = $r;
        }
    }
    $stmt_m->close();

    // Initialize PIC Matrix
    $pic_matrix = [];
    $res_users = $conn->query("SELECT email, username FROM users WHERE email IS NOT NULL AND email != '' ORDER BY id ASC");
    if ($res_users) {
        while ($u = $res_users->fetch_assoc()) {
            $em = strtolower(trim($u['email']));
            $name = !empty($u['username']) ? explode(' ', trim($u['username']))[0] : ucfirst(explode('.', explode('@', $em)[0])[0]);
            $pic_matrix[$em] = [
                'display_name' => $name,
                'email' => $em,
                'total_year' => ['done' => 0, 'drop' => 0, 'prog' => 0],
                'w_prev' => ['done' => 0, 'drop' => 0, 'prog' => 0],
                'w_curr' => ['done' => 0, 'drop' => 0, 'prog' => 0],
                'w_next' => ['done' => 0, 'drop' => 0, 'prog' => 0]
            ];
        }
    }

    foreach ($matrix_tasks as $mt) {
        $em = strtolower(trim($mt['pic_email'] ?? ''));
        if (empty($em)) continue;

        if (!isset($pic_matrix[$em])) {
            $name = !empty($mt['username']) ? explode(' ', trim($mt['username']))[0] : ucfirst(explode('.', explode('@', $em)[0])[0]);
            $pic_matrix[$em] = [
                'display_name' => $name,
                'email' => $em,
                'total_year' => ['done' => 0, 'drop' => 0, 'prog' => 0],
                'w_prev' => ['done' => 0, 'drop' => 0, 'prog' => 0],
                'w_curr' => ['done' => 0, 'drop' => 0, 'prog' => 0],
                'w_next' => ['done' => 0, 'drop' => 0, 'prog' => 0]
            ];
        }

        $st = trim($mt['progress_status'] ?? '');
        if (in_array($st, ['Approved', 'Passed'])) {
            $cat = 'done';
        } elseif (in_array($st, ['Batal', 'Rejected', 'Drop'])) {
            $cat = 'drop';
        } else {
            $cat = 'prog';
        }

        $pic_matrix[$em]['total_year'][$cat]++;
        if (is_task_in_week_range_email($mt, $prev_start_str, $prev_end_str)) {
            $pic_matrix[$em]['w_prev'][$cat]++;
        }
        if (is_task_in_week_range_email($mt, $start_date_str, $end_date_str)) {
            $pic_matrix[$em]['w_curr'][$cat]++;
        }
        if (is_task_in_week_range_email($mt, $next_start_str, $next_end_str)) {
            $pic_matrix[$em]['w_next'][$cat]++;
        }
    }

    $active_pic_matrix = [];
    foreach ($pic_matrix as $em => $data) {
        $sum = $data['total_year']['done'] + $data['total_year']['drop'] + $data['total_year']['prog'] +
               $data['w_prev']['done'] + $data['w_prev']['drop'] + $data['w_prev']['prog'] +
               $data['w_curr']['done'] + $data['w_curr']['drop'] + $data['w_curr']['prog'] +
               $data['w_next']['done'] + $data['w_next']['drop'] + $data['w_next']['prog'];
        if ($sum > 0 || in_array(strtolower($data['display_name']), ['endri', 'lutfi', 'apta'])) {
            $active_pic_matrix[$em] = $data;
        }
    }

    $matrix_totals = [
        'total_year' => ['done' => 0, 'drop' => 0, 'prog' => 0],
        'w_prev' => ['done' => 0, 'drop' => 0, 'prog' => 0],
        'w_curr' => ['done' => 0, 'drop' => 0, 'prog' => 0],
        'w_next' => ['done' => 0, 'drop' => 0, 'prog' => 0]
    ];
    foreach ($active_pic_matrix as $p) {
        foreach (['total_year', 'w_prev', 'w_curr', 'w_next'] as $period) {
            $matrix_totals[$period]['done'] += $p[$period]['done'];
            $matrix_totals[$period]['drop'] += $p[$period]['drop'];
            $matrix_totals[$period]['prog'] += $p[$period]['prog'];
        }
    }

    // --- 2. DATA RETRIEVAL (ALL STATUSES IN WEEKLY RANGE) ---
    $sql = "SELECT t.*, u.username 
            FROM gba_tasks t 
            LEFT JOIN users u ON t.pic_email = u.email 
            WHERE (
                (t.request_date BETWEEN ? AND ?) OR
                (t.submission_date BETWEEN ? AND ?) OR
                (t.approved_date BETWEEN ? AND ?) OR
                (t.deadline BETWEEN ? AND ?) OR
                (DATE(t.updated_at) BETWEEN ? AND ?) OR
                (t.progress_status NOT IN ('Approved', 'Passed', 'Batal') AND (t.request_date <= ? OR t.request_date IS NULL))
            )
            ORDER BY t.deadline ASC, t.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssssssss", 
        $start_date_str, $end_date_str,
        $start_date_str, $end_date_str,
        $start_date_str, $end_date_str,
        $start_date_str, $end_date_str,
        $start_date_str, $end_date_str,
        $end_date_str
    );
    $stmt->execute();
    $res = $stmt->get_result();

    $all_tasks = [];
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $all_tasks[] = $row;
        }
    }
    $stmt->close();

    $today_str = date('Y-m-d');
    $today_dt = new DateTime($today_str);

    $status_dist = [
        'Approved' => 0,
        'Submitted' => 0,
        'Test Ongoing' => 0,
        'Downloaded' => 0,
        'Task Baru' => 0,
        'Pending Feedback' => 0,
        'Feedback Sent' => 0,
        'Batal' => 0
    ];

    $test_plan_dist = [];
    $pic_load_dist = [];
    $pic_approved_dist = [];
    $total_week_tasks = count($all_tasks);
    $approved_count = 0;
    $submitted_count = 0;
    $ongoing_count = 0;
    $downloaded_count = 0;
    $new_count = 0;
    $pending_count = 0;
    $batal_count = 0;
    $urgent_count = 0;
    $late_count = 0;

    $urgent_tasks = [];
    $late_tasks = [];
    $approved_tasks = [];

    foreach ($all_tasks as &$task) {
        $st = trim($task['progress_status'] ?? 'Task Baru');
        if ($st === 'Passed') $st = 'Approved';
        if (!isset($status_dist[$st])) {
            $status_dist[$st] = 0;
        }
        $status_dist[$st]++;

        if ($st === 'Approved') {
            $approved_count++;
            $approved_tasks[] = $task;
        } elseif ($st === 'Submitted') {
            $submitted_count++;
        } elseif ($st === 'Test Ongoing') {
            $ongoing_count++;
        } elseif ($st === 'Downloaded') {
            $downloaded_count++;
        } elseif ($st === 'Task Baru') {
            $new_count++;
        } elseif (in_array($st, ['Pending Feedback', 'Feedback Sent'])) {
            $pending_count++;
        } elseif ($st === 'Batal') {
            $batal_count++;
        }

        if (!empty($task['is_urgent'])) {
            $urgent_count++;
            $urgent_tasks[] = $task;
        }

        $days_left = null;
        if (!empty($task['deadline'])) {
            $dl_dt = new DateTime($task['deadline']);
            $diff = $today_dt->diff($dl_dt);
            $days_left = ($today_dt <= $dl_dt) ? $diff->days : -$diff->days;

            if ($st !== 'Approved' && $st !== 'Batal') {
                if ($days_left < 0) {
                    $late_count++;
                    $late_tasks[] = $task;
                }
            }
        }

        $task['days_left'] = $days_left;
        $task['marketing_name'] = get_marketing_name($task['model_name'] ?? '');

        $tp = trim($task['test_plan_type'] ?? 'Unassigned');
        if ($tp === '') $tp = 'Unassigned';
        $test_plan_dist[$tp] = ($test_plan_dist[$tp] ?? 0) + 1;

        $pic_name = !empty($task['username']) ? $task['username'] : (!empty($task['pic_email']) ? explode('@', $task['pic_email'])[0] : 'Unassigned');
        $pic_load_dist[$pic_name] = ($pic_load_dist[$pic_name] ?? 0) + 1;
        if ($st === 'Approved') {
            $pic_approved_dist[$pic_name] = ($pic_approved_dist[$pic_name] ?? 0) + 1;
        }
    }
    unset($task);

    arsort($test_plan_dist);
    arsort($pic_load_dist);

    $completion_rate = $total_week_tasks > 0 ? round(($approved_count / $total_week_tasks) * 100, 1) : 0;
    $active_in_pipeline = $total_week_tasks - $approved_count - $batal_count;

    $top_pic_name = !empty($pic_load_dist) ? array_key_first($pic_load_dist) : '-';
    $top_pic_count = !empty($pic_load_dist) ? $pic_load_dist[$top_pic_name] : 0;
    $top_testplan_name = !empty($test_plan_dist) ? array_key_first($test_plan_dist) : '-';
    $top_testplan_count = !empty($test_plan_dist) ? $test_plan_dist[$top_testplan_name] : 0;

    $executive_narrative = "Laporan mingguan periode siklus Rabu - Selasa ({$range_label}) mencatat total {$total_week_tasks} task dari seluruh status. Sebanyak {$approved_count} task ({$completion_rate}%) telah berhasil mendapatkan approval, sementara {$active_in_pipeline} task masih aktif berjalan dalam pipeline verifikasi. Kategori pengujian terbesar didominasi oleh {$top_testplan_name} ({$top_testplan_count} task), dengan alokasi penanganan tertinggi dipegang oleh {$top_pic_name} ({$top_pic_count} task). ";

    if ($urgent_count > 0 || $late_count > 0) {
        $executive_narrative .= "Perhatian operasional difokuskan pada {$urgent_count} task berlabel Urgent dan {$late_count} task overdue guna memastikan tidak ada rilis build yang terhambat menuju siklus berikutnya.";
    } else {
        $executive_narrative .= "Seluruh target pengujian mingguan berjalan kondusif tanpa kendala keterlambatan mayor.";
    }

} else {
    // --- DAILY REPORT CALCULATION ---
    $formattedDate = date('d F Y');
    $subject = !empty($customSubject) ? $customSubject : "[DAILY REPORT GBA] Summary Insight - {$formattedDate}";

    $sql = "SELECT t.*, u.username 
            FROM gba_tasks t 
            LEFT JOIN users u ON t.pic_email = u.email 
            ORDER BY t.deadline ASC, t.id DESC";
    $res = $conn->query($sql);

    $all_tasks = [];
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $all_tasks[] = $row;
        }
    }

    $today_str = date('Y-m-d');
    $today_dt = new DateTime($today_str);

    $active_tasks = [];
    $late_tasks = [];
    $due_soon_tasks = [];

    $status_dist = [
        'Task Baru' => 0,
        'Downloaded' => 0,
        'Test Ongoing' => 0,
        'Pending Feedback' => 0,
        'Feedback Sent' => 0,
        'Submitted' => 0
    ];

    $test_plan_dist = [];
    $pic_load_dist = [];
    $deadline_load_dist = [];
    $total_active = 0;
    $ongoing_count = 0;
    $submitted_count = 0;
    $pending_count = 0;

    foreach ($all_tasks as &$task) {
        $st = $task['progress_status'] ?? 'Task Baru';
        $is_completed = in_array($st, ['Approved', 'Passed', 'Batal']);
        
        $days_left = null;
        if (!empty($task['deadline'])) {
            $dl_dt = new DateTime($task['deadline']);
            $diff = $today_dt->diff($dl_dt);
            $days_left = ($today_dt <= $dl_dt) ? $diff->days : -$diff->days;
        }

        $task['days_left'] = $days_left;
        $task['marketing_name'] = get_marketing_name($task['model_name'] ?? '');

        if (!$is_completed) {
            $total_active++;
            $active_tasks[] = $task;

            if (isset($status_dist[$st])) {
                $status_dist[$st]++;
            }

            if (in_array($st, ['Task Baru', 'Downloaded', 'Test Ongoing'])) {
                $ongoing_count++;
            } elseif ($st === 'Submitted') {
                $submitted_count++;
            } elseif (in_array($st, ['Pending Feedback', 'Feedback Sent'])) {
                $pending_count++;
            }

            $tp = trim($task['test_plan_type'] ?? 'Unassigned');
            if ($tp === '') $tp = 'Unassigned';
            $test_plan_dist[$tp] = ($test_plan_dist[$tp] ?? 0) + 1;

            $pic_name = !empty($task['username']) ? $task['username'] : (!empty($task['pic_email']) ? explode('@', $task['pic_email'])[0] : 'Unassigned');
            $pic_load_dist[$pic_name] = ($pic_load_dist[$pic_name] ?? 0) + 1;

            if (!empty($task['deadline'])) {
                $deadline_load_dist[$task['deadline']] = ($deadline_load_dist[$task['deadline']] ?? 0) + 1;
            }

            if ($days_left !== null) {
                if ($days_left < 0) {
                    $late_tasks[] = $task;
                } elseif ($days_left >= 0 && $days_left <= 3) {
                    $due_soon_tasks[] = $task;
                }
            }
        }
    }
    unset($task);

    arsort($test_plan_dist);
    arsort($pic_load_dist);
    ksort($deadline_load_dist);

    $top_deadline_date = '-';
    $top_deadline_count = 0;
    if (!empty($deadline_load_dist)) {
        $filtered_dl = array_filter($deadline_load_dist, function($cnt, $d) use ($today_str) {
            return $d >= $today_str;
        }, ARRAY_FILTER_USE_BOTH);

        if (!empty($filtered_dl)) {
            $top_deadline_date = array_key_first($filtered_dl);
            $top_deadline_count = $filtered_dl[$top_deadline_date];
            foreach ($filtered_dl as $d => $cnt) {
                if ($cnt > $top_deadline_count) {
                    $top_deadline_date = $d;
                    $top_deadline_count = $cnt;
                }
            }
        } else {
            $top_deadline_date = array_key_first($deadline_load_dist);
            $top_deadline_count = $deadline_load_dist[$top_deadline_date];
        }
    }

    $top_pic_name = !empty($pic_load_dist) ? array_key_first($pic_load_dist) : '-';
    $top_pic_count = !empty($pic_load_dist) ? $pic_load_dist[$top_pic_name] : 0;
    $top_testplan_name = !empty($test_plan_dist) ? array_key_first($test_plan_dist) : '-';
    $top_testplan_count = !empty($test_plan_dist) ? $test_plan_dist[$top_testplan_name] : 0;
    $late_count = count($late_tasks);
    $due_soon_count = count($due_soon_tasks);

    $top_dl_formatted = ($top_deadline_date !== '-') ? date('d M Y', strtotime($top_deadline_date)) : '-';
    $narrative = "Hari ini tercatat {$total_active} task aktif dalam pipeline pengujian dengan konsentrasi pengujian terbesar pada kategori {$top_testplan_name} ({$top_testplan_count} task). Beban verifikasi tertinggi saat ini dipegang oleh {$top_pic_name} ({$top_pic_count} task aktif), sementara titik kepadatan penyelesaian terpusat pada tanggal {$top_dl_formatted} ({$top_deadline_count} task). ";
    if ($late_count > 0 && $due_soon_count > 0) {
        $narrative .= "Fokus mitigasi operasional mendesak tertuju pada {$late_count} task overdue dan {$due_soon_count} task yang mendekati batas waktu (H-0 hingga H-3) guna menjaga ketepatan jadwal rilis.";
    } elseif ($late_count > 0) {
        $narrative .= "Fokus mitigasi operasional mendesak tertuju pada {$late_count} task overdue yang memerlukan tindak lanjut percepatan.";
    } elseif ($due_soon_count > 0) {
        $narrative .= "Aktivitas pengujian berjalan on-track dengan pengawalan aktif pada {$due_soon_count} task yang akan jatuh tempo dalam 3 hari ke depan.";
    } else {
        $narrative .= "Seluruh aktivitas verifikasi berjalan on-track tanpa adanya penumpukan keterlambatan maupun task kritis.";
    }
}

function get_email_testplan_pill($plan) {
    $plan_clean = strtoupper(trim((string)$plan));
    $style = 'display:inline-block; padding:3px 8px; border-radius:9999px; font-size:10px; font-weight:700; white-space:nowrap; letter-spacing:0.02em; ';
    switch ($plan_clean) {
        case 'NORMAL MR':
        case 'MR':
            $style .= 'background-color:#e0f2fe; color:#0369a1; border:1px solid #bae6fd;';
            break;
        case 'SMR':
            $style .= 'background-color:#e0e7ff; color:#4338ca; border:1px solid #c7d2fe;';
            break;
        case 'FULL TEST':
        case 'FULLTEST':
            $style .= 'background-color:#f3e8ff; color:#7e22ce; border:1px solid #e9d5ff;';
            break;
        case 'SANITY':
            $style .= 'background-color:#dcfce7; color:#15803d; border:1px solid #bbf7d0;';
            break;
        case 'DELTA':
        case 'DELTA TEST':
            $style .= 'background-color:#fef3c7; color:#b45309; border:1px solid #fde68a;';
            break;
        case 'PL':
            $style .= 'background-color:#ccfbf1; color:#0f766e; border:1px solid #99f6e4;';
            break;
        case 'REGRESSION':
            $style .= 'background-color:#ffe4e6; color:#be123c; border:1px solid #fecdd3;';
            break;
        default:
            $style .= 'background-color:#f1f5f9; color:#475569; border:1px solid #e2e8f0;';
            break;
    }
    return '<span style="' . $style . '">' . htmlspecialchars($plan ?: 'Unassigned') . '</span>';
}

function get_email_status_pill($status) {
    $status_clean = trim((string)$status);
    $style = 'display:inline-block; padding:3px 8px; border-radius:9999px; font-size:10px; font-weight:700; white-space:nowrap; letter-spacing:0.02em; ';
    switch ($status_clean) {
        case 'Task Baru':
            $style .= 'background-color:#f1f5f9; color:#475569; border:1px solid #cbd5e1;';
            break;
        case 'Downloaded':
            $style .= 'background-color:#cffafe; color:#0891b2; border:1px solid #a5f3fc;';
            break;
        case 'Test Ongoing':
            $style .= 'background-color:#dbeafe; color:#1d4ed8; border:1px solid #bfdbfe;';
            break;
        case 'Pending Feedback':
            $style .= 'background-color:#ede9fe; color:#6d28d9; border:1px solid #ddd6fe;';
            break;
        case 'Feedback Sent':
            $style .= 'background-color:#fae8ff; color:#a21caf; border:1px solid #f5d0fe;';
            break;
        case 'Submitted':
            $style .= 'background-color:#d1fae5; color:#047857; border:1px solid #a7f3d0;';
            break;
        case 'Approved':
        case 'Passed':
            $style .= 'background-color:#bbf7d0; color:#15803d; border:1px solid #86efac;';
            break;
        case 'Batal':
            $style .= 'background-color:#fee2e2; color:#b91c1c; border:1px solid #fecaca;';
            break;
        default:
            $style .= 'background-color:#f1f5f9; color:#64748b; border:1px solid #e2e8f0;';
            break;
    }
    return '<span style="' . $style . '">' . htmlspecialchars($status ?: '-') . '</span>';
}

function get_email_pic_pill($name, $show_prefix = false) {
    $clean = trim((string)$name);
    if (empty($clean) || $clean === '-') $clean = 'Unassigned';

    $palettes = [
        ['bg' => '#e0e7ff', 'color' => '#3730a3', 'border' => '#c7d2fe'],
        ['bg' => '#ccfbf1', 'color' => '#0f766e', 'border' => '#99f6e4'],
        ['bg' => '#d1fae5', 'color' => '#065f46', 'border' => '#a7f3d0'],
        ['bg' => '#e0f2fe', 'color' => '#0369a1', 'border' => '#bae6fd'],
        ['bg' => '#f3e8ff', 'color' => '#6b21a8', 'border' => '#e9d5ff'],
        ['bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fde68a'],
        ['bg' => '#ffe4e6', 'color' => '#9f1239', 'border' => '#fecdd3'],
        ['bg' => '#cffafe', 'color' => '#155e75', 'border' => '#a5f3fc'],
        ['bg' => '#fae8ff', 'color' => '#86198f', 'border' => '#f5d0fe'],
        ['bg' => '#ede9fe', 'color' => '#5b21b6', 'border' => '#ddd6fe'],
        ['bg' => '#ffedd5', 'color' => '#9a3412', 'border' => '#fed7aa']
    ];

    $hash = crc32(strtolower($clean));
    $palette = $palettes[abs($hash) % count($palettes)];

    $style = "display:inline-block; padding:2px 8px; border-radius:9999px; font-size:10px; font-weight:700; white-space:nowrap; letter-spacing:0.02em; background-color:{$palette['bg']}; color:{$palette['color']}; border:1px solid {$palette['border']};";
    $label = ($show_prefix ? 'PIC: ' : '') . $clean;
    return '<span style="' . $style . '">' . htmlspecialchars($label) . '</span>';
}

ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($subject); ?></title>
    <style>
        body {
            margin: 0;
            padding: 0;
            width: 100% !important;
            min-width: 100%;
            background-color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #0f172a;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }
        .wrapper {
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 auto;
            background-color: #ffffff;
            box-sizing: border-box;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        .task-table th {
            background-color: #0f172a;
            color: #f8fafc;
            font-size: 11px;
            font-weight: 700;
            padding: 9px 10px;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .task-table td {
            padding: 8px 10px;
            font-size: 11.5px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        .matrix-table th, .matrix-table td {
            border: 1px solid #cbd5e1;
        }
        @media only screen and (max-width: 640px) {
            .header-padding { padding: 18px 14px !important; }
            .content-padding { padding: 14px !important; }
            .kpi-col { width: 50% !important; display: inline-block !important; box-sizing: border-box !important; }
            .stack-col { width: 100% !important; display: block !important; padding: 0 0 12px 0 !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; width:100% !important; background-color:#f8fafc; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; color:#0f172a;">
    <div class="wrapper" style="width:100% !important; max-width:100% !important; margin:0 auto; background-color:#ffffff; border-bottom:1px solid #e2e8f0;">
        
        <!-- HEADER HUD -->
        <div class="header-padding" style="background: linear-gradient(135deg, #0f172a 0%, #0369a1 100%); padding:24px 28px; color:#ffffff; border-bottom:3px solid #38bdf8;">
            <div style="display:inline-block; background:rgba(56, 189, 248, 0.15); border:1px solid rgba(56, 189, 248, 0.4); color:#38bdf8; padding:3px 10px; border-radius:9999px; font-size:10px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; margin-bottom:8px;">
                Samsung GBA &bull; Quality Engineering
            </div>
            <h1 style="margin:0; font-size:22px; font-weight:800; color:#f8fafc; letter-spacing:-0.02em;">
                <?php echo $is_weekly ? 'Weekly Insight Report' : 'Daily Report Summary Insight'; ?>
            </h1>
            <p style="margin:6px 0 0 0; font-size:12px; color:#cbd5e1;">
                <?php if ($is_weekly): ?>
                    Periode: <strong style="color:#ffffff;"><?php echo $range_label; ?> (Siklus Rabu - Selasa)</strong> &bull; Waktu Kirim: <?php echo date('H:i'); ?> WIB &bull; Generator: MCP SMTP Mailer
                <?php else: ?>
                    Tanggal Laporan: <strong style="color:#ffffff;"><?php echo $formattedDate; ?></strong> &bull; Waktu Kirim: <?php echo date('H:i'); ?> WIB &bull; Generator: MCP SMTP Mailer
                <?php endif; ?>
            </p>
        </div>

        <div class="content-padding" style="padding:24px 28px; box-sizing:border-box;">
            
            <?php if ($is_weekly): ?>
                <!-- ========================================== -->
                <!-- WEEKLY REPORT SUMMARY BODY (MATCHING PAGE) -->
                <!-- ========================================== -->

                <!-- 1. KPI SUMMARY CARDS (6 CARDS) -->
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px; width:100%;">
                    <tr>
                        <td class="kpi-col" width="16.66%" style="padding:4px;">
                            <div style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; text-align:left;">
                                <div style="font-size:9.5px; font-weight:700; color:#64748b; text-transform:uppercase;">Total Task</div>
                                <div style="font-size:22px; font-weight:800; color:#0f172a; margin-top:3px; line-height:1;"><?php echo $total_week_tasks; ?></div>
                                <div style="font-size:9.5px; color:#94a3b8; margin-top:4px;">Semua status</div>
                            </div>
                        </td>
                        <td class="kpi-col" width="16.66%" style="padding:4px;">
                            <div style="background-color:#f0fdf4; border:1px solid #bbf7d0; border-left:4px solid #10b981; border-radius:10px; padding:12px 14px; text-align:left;">
                                <div style="font-size:9.5px; font-weight:700; color:#15803d; text-transform:uppercase;">Approved / Passed</div>
                                <div style="font-size:22px; font-weight:800; color:#15803d; margin-top:3px; line-height:1;"><?php echo $approved_count; ?></div>
                                <div style="font-size:9.5px; color:#16a34a; font-weight:700; margin-top:4px;"><?php echo $completion_rate; ?>% pass</div>
                            </div>
                        </td>
                        <td class="kpi-col" width="16.66%" style="padding:4px;">
                            <div style="background-color:#eff6ff; border:1px solid #bfdbfe; border-left:4px solid #3b82f6; border-radius:10px; padding:12px 14px; text-align:left;">
                                <div style="font-size:9.5px; font-weight:700; color:#1d4ed8; text-transform:uppercase;">Submitted (BAS)</div>
                                <div style="font-size:22px; font-weight:800; color:#1d4ed8; margin-top:3px; line-height:1;"><?php echo $submitted_count; ?></div>
                                <div style="font-size:9.5px; color:#2563eb; margin-top:4px;">Review pengajuan</div>
                            </div>
                        </td>
                        <td class="kpi-col" width="16.66%" style="padding:4px;">
                            <div style="background-color:#fffbeb; border:1px solid #fde68a; border-left:4px solid #f59e0b; border-radius:10px; padding:12px 14px; text-align:left;">
                                <div style="font-size:9.5px; font-weight:700; color:#b45309; text-transform:uppercase;">Test Ongoing</div>
                                <div style="font-size:22px; font-weight:800; color:#b45309; margin-top:3px; line-height:1;"><?php echo $ongoing_count; ?></div>
                                <div style="font-size:9.5px; color:#d97706; margin-top:4px;">Testing proses</div>
                            </div>
                        </td>
                        <td class="kpi-col" width="16.66%" style="padding:4px;">
                            <div style="background-color:#fff1f2; border:1px solid #fecdd3; border-left:4px solid #f43f5e; border-radius:10px; padding:12px 14px; text-align:left;">
                                <div style="font-size:9.5px; font-weight:700; color:#be123c; text-transform:uppercase;">Task Urgent</div>
                                <div style="font-size:22px; font-weight:800; color:#be123c; margin-top:3px; line-height:1;"><?php echo $urgent_count; ?></div>
                                <div style="font-size:9.5px; color:#e11d48; margin-top:4px;">Prioritas tinggi</div>
                            </div>
                        </td>
                        <td class="kpi-col" width="16.66%" style="padding:4px;">
                            <div style="background-color:#faf5ff; border:1px solid #e9d5ff; border-left:4px solid #a855f7; border-radius:10px; padding:12px 14px; text-align:left;">
                                <div style="font-size:9.5px; font-weight:700; color:#7e22ce; text-transform:uppercase;">Late / Due Soon</div>
                                <div style="font-size:22px; font-weight:800; color:#7e22ce; margin-top:3px; line-height:1;"><?php echo $late_count; ?></div>
                                <div style="font-size:9.5px; color:#9333ea; margin-top:4px;">Perlu eskalasi</div>
                            </div>
                        </td>
                    </tr>
                </table>

                <!-- 2. EXECUTIVE NARRATIVE CARD -->
                <div style="background-color:#ffffff; border:1px solid #e2e8f0; border-left:4px solid #10b981; border-radius:0 10px 10px 0; padding:16px 20px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                        <span style="display:inline-block; background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; padding:2px 8px; border-radius:9999px; font-size:10px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase;">
                            Executive Summary Mingguan
                        </span>
                        <span style="font-size:11.5px; color:#64748b; font-weight:500;"><?php echo htmlspecialchars($range_label); ?></span>
                    </div>
                    <p style="margin:0; font-size:12.5px; color:#334155; line-height:1.65;">
                        <?php echo htmlspecialchars($executive_narrative); ?>
                    </p>
                </div>

                <!-- 3. PIC WORKLOAD & TEST PLAN DISTRIBUTION -->
                <div style="margin-bottom:20px;">
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
                        <tr>
                            <td class="stack-col" width="50%" style="padding-right:8px; vertical-align:top;">
                                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; box-sizing:border-box;">
                                    <div style="font-size:11.5px; font-weight:700; color:#0f172a; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:12px; padding-bottom:6px; border-bottom:1px solid #f1f5f9;">
                                        Beban Task per PIC (Mingguan)
                                    </div>
                                    <?php 
                                    $pic_idx = 0;
                                    $pic_colors = ['#0f172a', '#0284c7', '#64748b', '#7c3aed', '#059669'];
                                    foreach (array_slice($pic_load_dist, 0, 6, true) as $p_name => $p_cnt): 
                                        $pct = ($total_week_tasks > 0) ? round(($p_cnt / $total_week_tasks) * 100) : 0;
                                        $app_cnt = $pic_approved_dist[$p_name] ?? 0;
                                        $bar_color = $pic_colors[$pic_idx % count($pic_colors)];
                                        $pic_idx++;
                                    ?>
                                    <div style="margin-bottom:10px;">
                                        <div style="display:flex; justify-content:space-between; font-size:11px; font-weight:600; color:#334155; margin-bottom:3px;">
                                            <span><?php echo htmlspecialchars($p_name); ?></span>
                                            <span style="color:#64748b;"><?php echo $p_cnt; ?> Task (<?php echo $app_cnt; ?> Pass) &bull; <?php echo $pct; ?>%</span>
                                        </div>
                                        <div style="width:100%; height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden;">
                                            <div style="width:<?php echo $pct; ?>%; height:100%; background:<?php echo $bar_color; ?>; border-radius:3px;"></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </td>

                            <td class="stack-col" width="50%" style="padding-left:8px; vertical-align:top;">
                                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; box-sizing:border-box;">
                                    <div style="font-size:11.5px; font-weight:700; color:#0f172a; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:12px; padding-bottom:6px; border-bottom:1px solid #f1f5f9;">
                                        Kategori Test Plan Type
                                    </div>
                                    <?php 
                                    $tp_idx = 0;
                                    $tp_colors = ['#0284c7', '#0ea5e9', '#38bdf8', '#6366f1', '#10b981'];
                                    foreach ($test_plan_dist as $tp_name => $tp_cnt): 
                                        $pct = ($total_week_tasks > 0) ? round(($tp_cnt / $total_week_tasks) * 100) : 0;
                                        $bar_color = $tp_colors[$tp_idx % count($tp_colors)];
                                        $tp_idx++;
                                    ?>
                                    <div style="margin-bottom:10px;">
                                        <div style="display:flex; justify-content:space-between; font-size:11px; font-weight:600; color:#334155; margin-bottom:3px;">
                                            <span><?php echo htmlspecialchars($tp_name); ?></span>
                                            <span style="color:#64748b;"><?php echo $tp_cnt; ?> Task &bull; <?php echo $pct; ?>%</span>
                                        </div>
                                        <div style="width:100%; height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden;">
                                            <div style="width:<?php echo $pct; ?>%; height:100%; background:<?php echo $bar_color; ?>; border-radius:3px;"></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- 4. SUMMARY PROJECT GA PER PIC & MINGGUAN (MULTI-WEEK MATRIX TABLE) -->
                <div style="margin-bottom:24px;">
                    <div style="font-size:12.5px; font-weight:700; color:#0f172a; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center;">
                        <span>Summary Project GA per PIC & Mingguan</span>
                        <span style="font-size:11px; color:#0369a1; font-weight:600;">Tahun <?php echo $curr_year; ?> &bull; W-<?php echo $w_prev_num; ?>, W-<?php echo $w_curr_num; ?>, W-<?php echo $w_next_num; ?></span>
                    </div>
                    <div style="border:1px solid #cbd5e1; border-radius:10px; overflow-x:auto;">
                        <table class="matrix-table" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:11px; text-align:center;">
                            <thead>
                                <tr>
                                    <th rowspan="2" style="background-color:#0284c7; color:#ffffff; font-weight:700; padding:8px 10px; text-align:left; border:1px solid #0369a1;">GBA PIC</th>
                                    <th colspan="3" style="background-color:#0284c7; color:#ffffff; font-weight:700; padding:6px 8px; border:1px solid #0369a1;">Total GA Project Y<?php echo $curr_year; ?></th>
                                    <th colspan="3" style="background-color:#d97706; color:#ffffff; font-weight:700; padding:6px 8px; border:1px solid #b45309;">W-<?php echo $w_prev_num; ?></th>
                                    <th colspan="3" style="background-color:#d97706; color:#ffffff; font-weight:700; padding:6px 8px; border:1px solid #b45309;">W-<?php echo $w_curr_num; ?></th>
                                    <th colspan="3" style="background-color:#d97706; color:#ffffff; font-weight:700; padding:6px 8px; border:1px solid #b45309;">W-<?php echo $w_next_num; ?></th>
                                </tr>
                                <tr style="font-size:10px;">
                                    <th style="background-color:#e0f2fe; color:#0369a1; padding:4px 6px; border:1px solid #bae6fd;">Done</th>
                                    <th style="background-color:#fef3c7; color:#b45309; padding:4px 6px; border:1px solid #fde68a;">Drop</th>
                                    <th style="background-color:#e0f2fe; color:#0369a1; padding:4px 6px; border:1px solid #bae6fd;">Prog</th>
                                    <th style="background-color:#e0f2fe; color:#0369a1; padding:4px 6px; border:1px solid #bae6fd;">Done</th>
                                    <th style="background-color:#fef3c7; color:#b45309; padding:4px 6px; border:1px solid #fde68a;">Drop</th>
                                    <th style="background-color:#e0f2fe; color:#0369a1; padding:4px 6px; border:1px solid #bae6fd;">Prog</th>
                                    <th style="background-color:#e0f2fe; color:#0369a1; padding:4px 6px; border:1px solid #bae6fd;">Done</th>
                                    <th style="background-color:#fef3c7; color:#b45309; padding:4px 6px; border:1px solid #fde68a;">Drop</th>
                                    <th style="background-color:#e0f2fe; color:#0369a1; padding:4px 6px; border:1px solid #bae6fd;">Prog</th>
                                    <th style="background-color:#e0f2fe; color:#0369a1; padding:4px 6px; border:1px solid #bae6fd;">Done</th>
                                    <th style="background-color:#fef3c7; color:#b45309; padding:4px 6px; border:1px solid #fde68a;">Drop</th>
                                    <th style="background-color:#e0f2fe; color:#0369a1; padding:4px 6px; border:1px solid #bae6fd;">Prog</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($active_pic_matrix as $p_em => $p_data): ?>
                                <tr style="border-bottom:1px solid #e2e8f0;">
                                    <td style="padding:6px 10px; text-align:left; font-weight:700; color:#0f172a; border:1px solid #cbd5e1;"><?php echo htmlspecialchars($p_data['display_name']); ?></td>
                                    <td style="padding:6px; font-weight:600; color:#0f172a; border:1px solid #cbd5e1;"><?php echo $p_data['total_year']['done'] ?: '-'; ?></td>
                                    <td style="padding:6px; font-weight:600; color:#0f172a; border:1px solid #cbd5e1;"><?php echo $p_data['total_year']['drop'] ?: '-'; ?></td>
                                    <td style="padding:6px; font-weight:600; color:#0f172a; border:1px solid #cbd5e1;"><?php echo $p_data['total_year']['prog'] ?: '-'; ?></td>
                                    <td style="padding:6px; font-weight:600; color:#0f172a; border:1px solid #cbd5e1;"><?php echo $p_data['w_prev']['done'] ?: '-'; ?></td>
                                    <td style="padding:6px; font-weight:600; color:#0f172a; border:1px solid #cbd5e1;"><?php echo $p_data['w_prev']['drop'] ?: '-'; ?></td>
                                    <td style="padding:6px; font-weight:600; color:#0f172a; border:1px solid #cbd5e1;"><?php echo $p_data['w_prev']['prog'] ?: '-'; ?></td>
                                    <td style="padding:6px; font-weight:600; color:#0f172a; border:1px solid #cbd5e1;"><?php echo $p_data['w_curr']['done'] ?: '-'; ?></td>
                                    <td style="padding:6px; font-weight:600; color:#0f172a; border:1px solid #cbd5e1;"><?php echo $p_data['w_curr']['drop'] ?: '-'; ?></td>
                                    <td style="padding:6px; font-weight:600; color:#0f172a; border:1px solid #cbd5e1;"><?php echo $p_data['w_curr']['prog'] ?: '-'; ?></td>
                                    <td style="padding:6px; font-weight:600; color:#0f172a; border:1px solid #cbd5e1;"><?php echo $p_data['w_next']['done'] ?: '-'; ?></td>
                                    <td style="padding:6px; font-weight:600; color:#0f172a; border:1px solid #cbd5e1;"><?php echo $p_data['w_next']['drop'] ?: '-'; ?></td>
                                    <td style="padding:6px; font-weight:600; color:#0f172a; border:1px solid #cbd5e1;"><?php echo $p_data['w_next']['prog'] ?: '-'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background-color:#bae6fd; font-weight:800; color:#0369a1;">
                                    <td style="padding:8px 10px; text-align:left; border:1px solid #7dd3fc;">Total</td>
                                    <td style="padding:8px 6px; border:1px solid #7dd3fc;"><?php echo $matrix_totals['total_year']['done']; ?></td>
                                    <td style="padding:8px 6px; border:1px solid #7dd3fc;"><?php echo $matrix_totals['total_year']['drop']; ?></td>
                                    <td style="padding:8px 6px; border:1px solid #7dd3fc;"><?php echo $matrix_totals['total_year']['prog']; ?></td>
                                    <td style="padding:8px 6px; border:1px solid #7dd3fc;"><?php echo $matrix_totals['w_prev']['done']; ?></td>
                                    <td style="padding:8px 6px; border:1px solid #7dd3fc;"><?php echo $matrix_totals['w_prev']['drop']; ?></td>
                                    <td style="padding:8px 6px; border:1px solid #7dd3fc;"><?php echo $matrix_totals['w_prev']['prog']; ?></td>
                                    <td style="padding:8px 6px; border:1px solid #7dd3fc;"><?php echo $matrix_totals['w_curr']['done']; ?></td>
                                    <td style="padding:8px 6px; border:1px solid #7dd3fc;"><?php echo $matrix_totals['w_curr']['drop']; ?></td>
                                    <td style="padding:8px 6px; border:1px solid #7dd3fc;"><?php echo $matrix_totals['w_curr']['prog']; ?></td>
                                    <td style="padding:8px 6px; border:1px solid #7dd3fc;"><?php echo $matrix_totals['w_next']['done']; ?></td>
                                    <td style="padding:8px 6px; border:1px solid #7dd3fc;"><?php echo $matrix_totals['w_next']['drop']; ?></td>
                                    <td style="padding:8px 6px; border:1px solid #7dd3fc;"><?php echo $matrix_totals['w_next']['prog']; ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- 5. JADWAL & TASK PENGUJIAN MINGGUAN (8-DAY SCHEDULE MATRIX TABLE) -->
                <div style="margin-bottom:24px;">
                    <div style="font-size:12.5px; font-weight:700; color:#0f172a; margin-bottom:8px;">
                        Jadwal & Task Pengujian Mingguan (Total <?php echo $total_week_tasks; ?> Task)
                    </div>
                    <div style="border:1px solid #cbd5e1; border-radius:10px; overflow-x:auto;">
                        <table class="matrix-table" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:11px; text-align:left;">
                            <thead>
                                <tr style="background-color:#0284c7; color:#ffffff; font-weight:700; font-size:11px;">
                                    <th style="padding:8px 6px; text-align:center; width:30px; border:1px solid #0369a1;">No</th>
                                    <th style="padding:8px; min-width:110px; border:1px solid #0369a1;">Model Name</th>
                                    <th style="padding:8px; min-width:120px; border:1px solid #0369a1;">AP(Code) ver.</th>
                                    <th style="padding:8px; min-width:120px; border:1px solid #0369a1;">CP(BB) ver.</th>
                                    <th style="padding:8px; width:45px; border:1px solid #0369a1;">CSC</th>
                                    <th style="padding:8px; text-align:center; min-width:85px; border:1px solid #0369a1;">GA Date Line</th>
                                    <th style="padding:8px; min-width:110px; border:1px solid #0369a1;">GA PIC</th>
                                    <th style="padding:8px; min-width:80px; border:1px solid #0369a1;">GA Type</th>
                                    <?php foreach ($schedule_days as $s_day): ?>
                                        <th style="padding:8px 4px; text-align:center; min-width:55px; border:1px solid <?php echo $s_day['is_weekend'] ? '#be123c' : '#0369a1'; ?>; background-color:<?php echo $s_day['is_weekend'] ? '#e11d48' : '#0284c7'; ?>;">
                                            <?php echo htmlspecialchars($s_day['day_label']); ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_tasks)): ?>
                                    <tr>
                                        <td colspan="<?php echo 8 + count($schedule_days); ?>" style="padding:16px; text-align:center; color:#64748b;">
                                            Tidak ada task yang terdata pada siklus mingguan ini.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($all_tasks as $idx => $t): 
                                        $pic_email = $t['pic_email'] ?? '';
                                        $date_line = !empty($t['deadline']) ? date('n/j/Y', strtotime($t['deadline'])) : (!empty($t['request_date']) ? date('n/j/Y', strtotime($t['request_date'])) : '-');
                                        $ga_type = !empty($t['test_plan_type']) ? $t['test_plan_type'] : '-';
                                        $bg_tr = ($idx % 2 === 0) ? '#ffffff' : '#f8fafc';
                                    ?>
                                    <tr style="background-color:<?php echo $bg_tr; ?>; border-bottom:1px solid #e2e8f0;">
                                        <td style="padding:6px; text-align:center; color:#64748b; font-weight:600; border:1px solid #cbd5e1;"><?php echo $idx + 1; ?></td>
                                        <td style="padding:6px 8px; font-weight:700; color:#0f172a; border:1px solid #cbd5e1;">
                                            <?php echo htmlspecialchars($t['model_name']); ?>
                                            <?php if (!empty($t['is_urgent'])): ?>
                                                <span style="display:inline-block; margin-left:3px; padding:1px 4px; border-radius:3px; background:#ffe4e6; color:#e11d48; font-size:9px; font-weight:800; border:1px solid #fecdd3;">URGENT</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding:6px 8px; font-family:ui-monospace, monospace; font-size:10.5px; color:#0f172a; border:1px solid #cbd5e1;">
                                            <?php echo htmlspecialchars($t['ap'] ?: '-'); ?>
                                        </td>
                                        <td style="padding:6px 8px; font-family:ui-monospace, monospace; font-size:10.5px; color:#334155; border:1px solid #cbd5e1;">
                                            <?php echo htmlspecialchars($t['cp'] ?: '-'); ?>
                                        </td>
                                        <td style="padding:6px 8px; font-family:ui-monospace, monospace; font-size:10.5px; color:#475569; border:1px solid #cbd5e1;">
                                            <?php if (!empty($t['csc']) && trim($t['csc']) !== '-'): ?>
                                                <span style="display:inline-block; padding:1px 5px; border-radius:3px; background:#fef3c7; color:#92400e; font-size:10px; font-weight:600; border:1px solid #fde68a;"><?php echo htmlspecialchars($t['csc']); ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding:6px 8px; text-align:center; color:#0f172a; font-weight:500; border:1px solid #cbd5e1;">
                                            <?php echo htmlspecialchars($date_line); ?>
                                        </td>
                                        <td style="padding:6px 8px; color:#334155; border:1px solid #cbd5e1;">
                                            <?php echo htmlspecialchars($pic_email); ?>
                                        </td>
                                        <td style="padding:6px 8px; color:#0f172a; font-weight:500; border:1px solid #cbd5e1;">
                                            <?php echo htmlspecialchars($ga_type); ?>
                                        </td>
                                        <?php foreach ($schedule_days as $s_day): 
                                            $d_str = $s_day['date_str'];
                                            $has_test = (!empty($t['request_date']) && $t['request_date'] === $d_str) ||
                                                        (empty($t['request_date']) && !empty($t['updated_at']) && substr($t['updated_at'], 0, 10) === $d_str && in_array($t['progress_status'], ['Test Ongoing', 'Task Baru']));
                                            $has_submit = (!empty($t['submission_date']) && $t['submission_date'] === $d_str);
                                            $has_approve = (!empty($t['approved_date']) && $t['approved_date'] === $d_str);
                                        ?>
                                        <td style="padding:4px 3px; text-align:center; border:1px solid #cbd5e1; background-color:<?php echo $s_day['is_weekend'] ? '#fff5f5' : 'transparent'; ?>;">
                                            <?php if ($has_test): ?>
                                                <span style="display:inline-block; padding:1px 4px; border-radius:3px; font-size:9.5px; font-weight:700; background:#fef3c7; color:#b45309; border:1px solid #fde68a; margin:1px;">Test</span>
                                            <?php endif; ?>
                                            <?php if ($has_submit && $has_approve): ?>
                                                <span style="display:inline-block; padding:1px 4px; border-radius:3px; font-size:9.5px; font-weight:700; background:#ccfbf1; color:#0f766e; border:1px solid #99f6e4; margin:1px;">S/A</span>
                                            <?php elseif ($has_submit): ?>
                                                <span style="display:inline-block; padding:1px 4px; border-radius:3px; font-size:9.5px; font-weight:700; background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; margin:1px;">Submit</span>
                                            <?php elseif ($has_approve): ?>
                                                <span style="display:inline-block; padding:1px 4px; border-radius:3px; font-size:9.5px; font-weight:700; background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; margin:1px;">Approve</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php else: ?>
                <!-- ========================================== -->
                <!-- DAILY REPORT SUMMARY BODY                  -->
                <!-- ========================================== -->

                <div style="background-color:#ffffff; border:1px solid #e2e8f0; border-left:4px solid #6366f1; border-radius:0 10px 10px 0; padding:18px 20px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                        <span style="display:inline-block; background:#eef2ff; color:#4f46e5; border:1px solid #c7d2fe; padding:2px 8px; border-radius:9999px; font-size:10px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase;">
                            Daily Executive Insight
                        </span>
                        <span style="font-size:11.5px; color:#64748b; font-weight:500;"><?php echo date('l, d F Y'); ?></span>
                    </div>
                    <p style="margin:0; font-size:13px; color:#334155; line-height:1.65;">
                        <?php echo htmlspecialchars($narrative); ?>
                    </p>

                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px; width:100%;">
                        <tr>
                            <td class="submetric-col" width="25%" style="padding:3px;">
                                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px;">
                                    <div style="font-size:9.5px; font-weight:700; color:#64748b; text-transform:uppercase;">Deadline Terpadat</div>
                                    <div style="font-size:12px; font-weight:700; color:#0f172a; margin-top:2px;"><?php echo $top_deadline_date !== '-' ? date('d M Y', strtotime($top_deadline_date)) : '-'; ?> <span style="color:#6366f1; font-weight:600;">(<?php echo $top_deadline_count; ?> task)</span></div>
                                </div>
                            </td>
                            <td class="submetric-col" width="25%" style="padding:3px;">
                                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px;">
                                    <div style="font-size:9.5px; font-weight:700; color:#64748b; text-transform:uppercase;">PIC Beban Tertinggi</div>
                                    <div style="font-size:12px; font-weight:700; color:#0f172a; margin-top:2px;"><?php echo htmlspecialchars($top_pic_name); ?> <span style="color:#0284c7; font-weight:600;">(<?php echo $top_pic_count; ?> task)</span></div>
                                </div>
                            </td>
                            <td class="submetric-col" width="25%" style="padding:3px;">
                                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px;">
                                    <div style="font-size:9.5px; font-weight:700; color:#64748b; text-transform:uppercase;">Test Plan Terbanyak</div>
                                    <div style="font-size:12px; font-weight:700; color:#0f172a; margin-top:2px;"><?php echo htmlspecialchars($top_testplan_name); ?> <span style="color:#16a34a; font-weight:600;">(<?php echo $top_testplan_count; ?> task)</span></div>
                                </div>
                            </td>
                            <td class="submetric-col" width="25%" style="padding:3px;">
                                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px;">
                                    <div style="font-size:9.5px; font-weight:700; color:#64748b; text-transform:uppercase;">Status Kritis</div>
                                    <div style="font-size:12px; font-weight:700; color:<?php echo ($late_count > 0 || $due_soon_count > 0) ? '#d97706' : '#16a34a'; ?>; margin-top:2px;"><?php echo $late_count; ?> Late &bull; <?php echo $due_soon_count; ?> Due H0-3</div>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px; width:100%;">
                    <tr>
                        <td class="kpi-col" width="16.66%" style="padding:4px;">
                            <div style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; text-align:left;">
                                <div style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase;">Total Aktif</div>
                                <div style="font-size:22px; font-weight:800; color:#0f172a; margin-top:3px; line-height:1;"><?php echo $total_active; ?></div>
                                <div style="font-size:9.5px; color:#94a3b8; margin-top:4px;">Dalam pipeline</div>
                            </div>
                        </td>
                        <td class="kpi-col" width="16.66%" style="padding:4px;">
                            <div style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; text-align:left;">
                                <div style="font-size:10px; font-weight:700; color:#0284c7; text-transform:uppercase;">Ongoing</div>
                                <div style="font-size:22px; font-weight:800; color:#0284c7; margin-top:3px; line-height:1;"><?php echo $ongoing_count; ?></div>
                                <div style="font-size:9.5px; color:#94a3b8; margin-top:4px;">Proses pengujian</div>
                            </div>
                        </td>
                        <td class="kpi-col" width="16.66%" style="padding:4px;">
                            <div style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; text-align:left;">
                                <div style="font-size:10px; font-weight:700; color:#16a34a; text-transform:uppercase;">Submitted</div>
                                <div style="font-size:22px; font-weight:800; color:#16a34a; margin-top:3px; line-height:1;"><?php echo $submitted_count; ?></div>
                                <div style="font-size:9.5px; color:#94a3b8; margin-top:4px;">Menunggu approval</div>
                            </div>
                        </td>
                        <td class="kpi-col" width="16.66%" style="padding:4px;">
                            <div style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; text-align:left;">
                                <div style="font-size:10px; font-weight:700; color:#7c3aed; text-transform:uppercase;">Pending FB</div>
                                <div style="font-size:22px; font-weight:800; color:#7c3aed; margin-top:3px; line-height:1;"><?php echo $pending_count; ?></div>
                                <div style="font-size:9.5px; color:#94a3b8; margin-top:4px;">Diskusi klarifikasi</div>
                            </div>
                        </td>
                        <td class="kpi-col" width="16.66%" style="padding:4px;">
                            <div style="background-color:<?php echo $late_count > 0 ? '#fef2f2' : '#f8fafc'; ?>; border:1px solid <?php echo $late_count > 0 ? '#fecaca' : '#e2e8f0'; ?>; border-radius:8px; padding:12px 14px; text-align:left;">
                                <div style="font-size:10px; font-weight:700; color:#dc2626; text-transform:uppercase;">Late (Overdue)</div>
                                <div style="font-size:22px; font-weight:800; color:#dc2626; margin-top:3px; line-height:1;"><?php echo $late_count; ?></div>
                                <div style="font-size:9.5px; color:<?php echo $late_count > 0 ? '#b91c1c' : '#94a3b8'; ?>; margin-top:4px;">Melewati deadline</div>
                            </div>
                        </td>
                        <td class="kpi-col" width="16.66%" style="padding:4px;">
                            <div style="background-color:<?php echo $due_soon_count > 0 ? '#fffbeb' : '#f8fafc'; ?>; border:1px solid <?php echo $due_soon_count > 0 ? '#fde68a' : '#e2e8f0'; ?>; border-radius:8px; padding:12px 14px; text-align:left;">
                                <div style="font-size:10px; font-weight:700; color:#d97706; text-transform:uppercase;">Due 0-3 Hari</div>
                                <div style="font-size:22px; font-weight:800; color:#d97706; margin-top:3px; line-height:1;"><?php echo $due_soon_count; ?></div>
                                <div style="font-size:9.5px; color:<?php echo $due_soon_count > 0 ? '#b45309' : '#94a3b8'; ?>; margin-top:4px;">Prioritas segera</div>
                            </div>
                        </td>
                    </tr>
                </table>

                <div style="margin-bottom:24px;">
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
                        <tr>
                            <td class="stack-col" width="50%" style="padding-right:8px; vertical-align:top;">
                                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:16px; box-sizing:border-box;">
                                    <div style="font-size:11.5px; font-weight:700; color:#0f172a; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:12px; padding-bottom:6px; border-bottom:1px solid #f1f5f9;">
                                        Distribusi Beban Kerja PIC
                                    </div>
                                    <?php 
                                    $pic_idx = 0;
                                    $pic_colors = ['#0f172a', '#0284c7', '#64748b', '#7c3aed', '#059669'];
                                    foreach ($pic_load_dist as $p_name => $p_cnt): 
                                        $pct = ($total_active > 0) ? round(($p_cnt / $total_active) * 100) : 0;
                                        $bar_color = $pic_colors[$pic_idx % count($pic_colors)];
                                        $pic_idx++;
                                    ?>
                                    <div style="margin-bottom:10px;">
                                        <div style="display:flex; justify-content:space-between; font-size:11px; font-weight:600; color:#334155; margin-bottom:3px;">
                                            <span><?php echo htmlspecialchars($p_name); ?></span>
                                            <span style="color:#64748b;"><?php echo $p_cnt; ?> Task (<?php echo $pct; ?>%)</span>
                                        </div>
                                        <div style="width:100%; height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden;">
                                            <div style="width:<?php echo $pct; ?>%; height:100%; background:<?php echo $bar_color; ?>; border-radius:3px;"></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </td>

                            <td class="stack-col" width="50%" style="padding-left:8px; vertical-align:top;">
                                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:16px; box-sizing:border-box;">
                                    <div style="font-size:11.5px; font-weight:700; color:#0f172a; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:12px; padding-bottom:6px; border-bottom:1px solid #f1f5f9;">
                                        Distribusi Volume Test Plan
                                    </div>
                                    <?php 
                                    $tp_idx = 0;
                                    $tp_colors = ['#0284c7', '#0ea5e9', '#38bdf8', '#6366f1', '#10b981'];
                                    foreach ($test_plan_dist as $tp_name => $tp_cnt): 
                                        $pct = ($total_active > 0) ? round(($tp_cnt / $total_active) * 100) : 0;
                                        $bar_color = $tp_colors[$tp_idx % count($tp_colors)];
                                        $tp_idx++;
                                    ?>
                                    <div style="margin-bottom:10px;">
                                        <div style="display:flex; justify-content:space-between; font-size:11px; font-weight:600; color:#334155; margin-bottom:3px;">
                                            <span><?php echo htmlspecialchars($tp_name); ?></span>
                                            <span style="color:#64748b;"><?php echo $tp_cnt; ?> Task (<?php echo $pct; ?>%)</span>
                                        </div>
                                        <div style="width:100%; height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden;">
                                            <div style="width:<?php echo $pct; ?>%; height:100%; background:<?php echo $bar_color; ?>; border-radius:3px;"></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <div style="margin-bottom:24px;">
                    <div style="font-size:12.5px; font-weight:700; color:#0f172a; margin-bottom:10px;">
                        Ringkasan Seluruh Task Aktif Pipeline (<?php echo count($active_tasks); ?> Task)
                    </div>
                    <div class="table-responsive" style="border:1px solid #e2e8f0; border-radius:8px; overflow-x:auto;">
                        <table class="task-table" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:collapse; font-size:11.5px; text-align:left;">
                            <thead>
                                <tr style="background-color:#0f172a; color:#f8fafc;">
                                    <th style="padding:10px 12px; width:4%; text-align:left;">No</th>
                                    <th style="padding:10px 12px; width:22%; text-align:left;">Model & Marketing Name</th>
                                    <th style="padding:10px 12px; width:26%; text-align:left;">Build Specs (AP / CP / CSC)</th>
                                    <th style="padding:10px 12px; width:12%; text-align:left;">PIC</th>
                                    <th style="padding:10px 12px; width:12%; text-align:left;">Test Plan</th>
                                    <th style="padding:10px 12px; width:12%; text-align:left;">Status</th>
                                    <th style="padding:10px 12px; width:12%; text-align:left;">Deadline</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $count = 0;
                                foreach ($active_tasks as $t): 
                                    $count++;
                                    $bg = ($count % 2 === 0) ? '#f8fafc' : '#ffffff';
                                    $p = !empty($t['username']) ? $t['username'] : (!empty($t['pic_email']) ? explode('@', $t['pic_email'])[0] : '-');
                                    $mkt = !empty($t['marketing_name']) ? " (" . htmlspecialchars($t['marketing_name']) . ")" : "";
                                    $dl_str = !empty($t['deadline']) ? date('d M Y', strtotime($t['deadline'])) : '-';
                                    $is_late = ($t['days_left'] !== null && $t['days_left'] < 0);
                                    $ap_val = trim($t['ap'] ?? '');
                                    $cp_val = trim($t['cp'] ?? '');
                                    $csc_val = trim($t['csc'] ?? '');
                                    $cp_is_mismatch = (!empty($ap_val) && !empty($cp_val) && $ap_val !== $cp_val);
                                ?>
                                <tr style="background-color:<?php echo $bg; ?>; border-bottom:1px solid #e2e8f0;">
                                    <td style="padding:8px 12px; text-align:left; color:#64748b; font-weight:600;"><?php echo $count; ?></td>
                                    <td style="padding:8px 12px;">
                                        <strong style="color:#0f172a; font-size:12px;"><?php echo htmlspecialchars($t['model_name']); ?></strong>
                                        <?php if (!empty($mkt)): ?>
                                            <div style="color:#64748b; font-size:10.5px;"><?php echo htmlspecialchars($t['marketing_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:8px 12px; font-family:ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, 'Liberation Mono', monospace; font-size:11px;">
                                        <div><span style="color:#64748b; font-weight:600;">AP:</span> <span style="color:#0f172a;"><?php echo htmlspecialchars($ap_val ?: '-'); ?></span></div>
                                        <div><span style="color:#64748b; font-weight:600;">CP:</span> <?php if ($cp_is_mismatch): ?><span style="color:#dc2626; font-weight:700;"><?php echo htmlspecialchars($cp_val); ?></span><?php else: ?><span style="color:#334155;"><?php echo htmlspecialchars($cp_val ?: '-'); ?></span><?php endif; ?></div>
                                        <div><span style="color:#64748b; font-weight:600;">CSC:</span> <span style="color:#475569;"><?php echo htmlspecialchars($csc_val ?: '-'); ?></span></div>
                                    </td>
                                    <td style="padding:8px 12px;">
                                        <?php echo get_email_pic_pill($p, false); ?>
                                    </td>
                                    <td style="padding:8px 12px;">
                                        <?php echo get_email_testplan_pill($t['test_plan_type'] ?? ''); ?>
                                    </td>
                                    <td style="padding:8px 12px;">
                                        <?php echo get_email_status_pill($t['progress_status'] ?? ''); ?>
                                    </td>
                                    <td style="padding:8px 12px; color:<?php echo $is_late ? '#dc2626; font-weight:700;' : '#334155;'; ?>">
                                        <div><?php echo $dl_str; ?></div>
                                        <?php if ($is_late): ?>
                                            <div style="font-size:10px; color:#dc2626; font-weight:700;">(Late <?php echo abs($t['days_left']); ?> hari)</div>
                                        <?php elseif ($t['days_left'] !== null && $t['days_left'] <= 3): ?>
                                            <div style="font-size:10px; color:#d97706; font-weight:600;">(Sisa <?php echo $t['days_left']; ?> hari)</div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php endif; ?>

            <!-- CTA BUTTON -->
            <div style="text-align:left; margin-top:20px; margin-bottom:16px;">
                <a href="<?php echo $is_weekly ? 'http://107.102.39.55:8089/weekly_report_summary.php?date=' . urlencode($target_date) : 'http://107.102.39.55:8089/daily_report_summary.php'; ?>" target="_blank" style="display:inline-block; background-color:#0f172a; color:#ffffff !important; text-decoration:none; padding:10px 20px; border-radius:6px; font-size:12px; font-weight:600; letter-spacing:0.02em;">
                    Buka <?php echo $is_weekly ? 'Weekly' : 'Daily'; ?> Report Summary Dashboard
                </a>
            </div>

            <!-- FOOTER -->
            <div style="border-top:1px solid #e2e8f0; padding-top:16px; font-size:11px; color:#94a3b8; text-align:center;">
                Laporan ini dibuat otomatis dari sistem <strong>Project Manager GBA</strong> via MCP SMTP Bridge.<br/>
                Pengirim: <?php echo htmlspecialchars($_SESSION['username'] ?? 'System'); ?> (<?php echo htmlspecialchars($_SESSION['user_details']['email'] ?? 'Project Manager'); ?>)
            </div>
        </div>
    </div>
</body>
</html>
<?php
$htmlContent = ob_get_clean();

$toFormatted = implode(', ', $validTo);
$ccFormatted = !empty($validCc) ? implode(', ', $validCc) : null;

$mcpPayload = [
    'to' => $toFormatted,
    'subject' => $subject,
    'html' => $htmlContent
];
if ($ccFormatted) {
    $mcpPayload['cc'] = $ccFormatted;
}

$mcpEndpoints = [
    getenv('MCP_SERVER_URL') ? str_replace('/chat', '/send_email_smtp', getenv('MCP_SERVER_URL')) : null,
    'http://host.docker.internal:3800/api/mcp/send_email_smtp',
    'http://127.0.0.1:3800/api/mcp/send_email_smtp',
    'http://localhost:3800/api/mcp/send_email_smtp',
    'http://107.102.39.55:3800/api/mcp/send_email_smtp'
];
$mcpEndpoints = array_values(array_filter(array_unique($mcpEndpoints)));

$sendSuccess = false;
$sendResponse = null;
$lastError = '';

foreach ($mcpEndpoints as $endpoint) {
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($mcpPayload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200 && $resp !== false) {
        $jsonResp = json_decode($resp, true);
        if (!empty($jsonResp['success'])) {
            $sendSuccess = true;
            $sendResponse = $jsonResp;
            break;
        } else {
            $lastError = $jsonResp['error'] ?? 'Gagal dari MCP server';
            break;
        }
    } else {
        if ($resp !== false) {
            $jsonResp = json_decode($resp, true);
            if (!empty($jsonResp['error'])) {
                $lastError = $jsonResp['error'];
                break;
            }
        }
        $lastError = $curlErr ?: "HTTP {$httpCode} connecting to {$endpoint}";
    }
}

if ($sendSuccess) {
    echo json_encode([
        'success' => true,
        'message' => 'Email report berhasil dikirim ke ' . count($validTo) . ' penerima.',
        'recipients' => $validTo,
        'cc' => $validCc,
        'subject' => $subject,
        'mcp_response' => $sendResponse
    ]);
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $lastError ?: 'Gagal menghubungi MCP Mailer Server port 3800.',
        'recipients' => $validTo,
        'subject' => $subject
    ]);
}
