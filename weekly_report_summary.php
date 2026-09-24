<?php
// ponytail: Weekly Report Summary Insight - Wednesday to Tuesday Cycle, All Statuses
require_once "config.php";
require_once "session.php";
require_once "marketing_name_mapper.php";

$active_page = 'weekly_report_summary';

// --- 1. DATE CALCULATION: WEDNESDAY TO TUESDAY CYCLE ---
date_default_timezone_set('Asia/Jakarta');
$target_date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');
$current_dt = new DateTime($target_date);
$day_of_week = (int)$current_dt->format('w'); // 0 (Sun) - 6 (Sat). Wednesday = 3

// Calculate offset to Wednesday (Start of weekly sprint)
if ($day_of_week >= 3) {
    $diff_to_wed = $day_of_week - 3;
} else {
    $diff_to_wed = $day_of_week + 4;
}

$start_dt = (clone $current_dt)->modify("-{$diff_to_wed} days");
$end_dt = (clone $start_dt)->modify("+6 days");

$start_date_str = $start_dt->format('Y-m-d');
$end_date_str = $end_dt->format('Y-m-d');

// Navigation links
$prev_week_date = (clone $start_dt)->modify('-7 days')->format('Y-m-d');
$next_week_date = (clone $start_dt)->modify('+7 days')->format('Y-m-d');
$today_str = date('Y-m-d');
$is_current_week = ($today_str >= $start_date_str && $today_str <= $end_date_str);

// Day names in Indonesian
$indo_days = [
    'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
    'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
];
$indo_months = [
    'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret', 'April' => 'April',
    'May' => 'Mei', 'June' => 'Juni', 'July' => 'Juli', 'August' => 'Agustus',
    'September' => 'September', 'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember'
];

function format_indo_date($dt_str) {
    global $indo_months;
    if (empty($dt_str)) return '-';
    $t = strtotime($dt_str);
    $m = date('F', $t);
    $ind_m = $indo_months[$m] ?? $m;
    return date('d', $t) . ' ' . $ind_m . ' ' . date('Y', $t);
}

$range_label = format_indo_date($start_date_str) . " - " . format_indo_date($end_date_str);

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
        'is_weekend' => in_array((int)$d->format('w'), [0, 6]), // 0: Sun, 6: Sat
        'day_name' => $d->format('D')
    ];
}

// Helper to check if task falls within date range
function is_task_in_week_range($task, $start, $end) {
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

// Seed from users table for consistent ordering
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

// Populate Matrix Data
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

    // Add to Total Year
    $pic_matrix[$em]['total_year'][$cat]++;

    // Add to W-prev
    if (is_task_in_week_range($mt, $prev_start_str, $prev_end_str)) {
        $pic_matrix[$em]['w_prev'][$cat]++;
    }

    // Add to W-curr
    if (is_task_in_week_range($mt, $start_date_str, $end_date_str)) {
        $pic_matrix[$em]['w_curr'][$cat]++;
    }

    // Add to W-next
    if (is_task_in_week_range($mt, $next_start_str, $next_end_str)) {
        $pic_matrix[$em]['w_next'][$cat]++;
    }
}

// Filter out PICs that have 0 in all columns if they have no tasks
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

// Calculate Matrix Totals
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
$sql = "SELECT t.*, u.username, u.profile_picture 
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

// --- 3. ANALYTICS & INSIGHT COMPUTATION ---
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
$daily_activity = [];

// Initialize 7 days array (Rabu to Selasa)
$curr = clone $start_dt;
while ($curr <= $end_dt) {
    $d_key = $curr->format('Y-m-d');
    $day_name = $curr->format('l');
    $daily_activity[$d_key] = [
        'day_name' => $indo_days[$day_name] ?? $day_name,
        'date_str' => $d_key,
        'formatted' => $curr->format('d/m'),
        'submissions' => 0,
        'approvals' => 0,
        'deadlines' => 0
    ];
    $curr->modify('+1 day');
}

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

    // Count categories
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

    // Days left / Late calculation
    $days_left = null;
    $deadline_badge_type = 'normal';
    $deadline_badge_text = '-';

    if (!empty($task['deadline'])) {
        $dl_dt = new DateTime($task['deadline']);
        $diff = $today_dt->diff($dl_dt);
        $days_left = ($today_dt <= $dl_dt) ? $diff->days : -$diff->days;

        if ($st !== 'Approved' && $st !== 'Batal') {
            if ($days_left < 0) {
                $deadline_badge_type = 'late';
                $deadline_badge_text = 'Late ' . abs($days_left) . ' hari';
                $late_count++;
                $late_tasks[] = $task;
            } elseif ($days_left === 0) {
                $deadline_badge_type = 'today';
                $deadline_badge_text = 'Hari ini (H-0)';
            } elseif ($days_left <= 3) {
                $deadline_badge_type = 'urgent';
                $deadline_badge_text = $days_left . ' hari lagi';
            } else {
                $deadline_badge_type = 'safe';
                $deadline_badge_text = $days_left . ' hari lagi';
            }
        } else {
            $deadline_badge_type = 'completed';
            $deadline_badge_text = ($st === 'Approved') ? 'Approved' : 'Batal';
        }
    }

    $task['days_left'] = $days_left;
    $task['deadline_badge_type'] = $deadline_badge_type;
    $task['deadline_badge_text'] = $deadline_badge_text;
    $task['marketing_name'] = get_marketing_name($task['model_name'] ?? '');

    // Test Plan distribution
    $tp = trim($task['test_plan_type'] ?? 'Unassigned');
    if ($tp === '') $tp = 'Unassigned';
    $test_plan_dist[$tp] = ($test_plan_dist[$tp] ?? 0) + 1;

    // PIC load distribution
    $pic_name = !empty($task['username']) ? $task['username'] : (!empty($task['pic_email']) ? explode('@', $task['pic_email'])[0] : 'Unassigned');
    $pic_load_dist[$pic_name] = ($pic_load_dist[$pic_name] ?? 0) + 1;
    if ($st === 'Approved') {
        $pic_approved_dist[$pic_name] = ($pic_approved_dist[$pic_name] ?? 0) + 1;
    }

    // Daily Timeline Mapping
    if (!empty($task['submission_date']) && isset($daily_activity[$task['submission_date']])) {
        $daily_activity[$task['submission_date']]['submissions']++;
    }
    if (!empty($task['approved_date']) && isset($daily_activity[$task['approved_date']])) {
        $daily_activity[$task['approved_date']]['approvals']++;
    }
    if (!empty($task['deadline']) && isset($daily_activity[$task['deadline']])) {
        $daily_activity[$task['deadline']]['deadlines']++;
    }
}
unset($task);

// Sort distributions
arsort($test_plan_dist);
arsort($pic_load_dist);

// Completion Rate
$completion_rate = $total_week_tasks > 0 ? round(($approved_count / $total_week_tasks) * 100, 1) : 0;
$active_in_pipeline = $total_week_tasks - $approved_count - $batal_count;

// Key Insights derivation
$top_pic_name = !empty($pic_load_dist) ? array_key_first($pic_load_dist) : '-';
$top_pic_count = !empty($pic_load_dist) ? $pic_load_dist[$top_pic_name] : 0;

$top_testplan_name = !empty($test_plan_dist) ? array_key_first($test_plan_dist) : '-';
$top_testplan_count = !empty($test_plan_dist) ? $test_plan_dist[$top_testplan_name] : 0;

// Top Approved PIC
$top_approved_pic = !empty($pic_approved_dist) ? array_key_first($pic_approved_dist) : '-';
$top_approved_count = !empty($pic_approved_dist) ? $pic_approved_dist[$top_approved_pic] : 0;

// --- 4. EXECUTIVE NARRATIVE (ANTISLOP COPYWRITING) ---
$executive_narrative = "Laporan mingguan periode siklus Rabu - Selasa ({$range_label}) mencatat total {$total_week_tasks} task dari seluruh status. Sebanyak {$approved_count} task ({$completion_rate}%) telah berhasil mendapatkan approval, sementara {$active_in_pipeline} task masih aktif berjalan dalam pipeline verifikasi. Kategori pengujian terbesar didominasi oleh {$top_testplan_name} ({$top_testplan_count} task), dengan alokasi penanganan tertinggi dipegang oleh {$top_pic_name} ({$top_pic_count} task). ";

if ($urgent_count > 0 || $late_count > 0) {
    $executive_narrative .= "Perhatian operasional difokuskan pada {$urgent_count} task berlabel Urgent dan {$late_count} task overdue guna memastikan tidak ada rilis build yang terhambat menuju siklus berikutnya.";
} else {
    $executive_narrative .= "Seluruh target pengujian mingguan berjalan kondusif tanpa kendala keterlambatan mayor.";
}

// Structured Text for Clipboard
$clipboard_report = "📊 *WEEKLY INSIGHT REPORT (Rabu - Selasa)*\n";
$clipboard_report .= "🗓 Periode: {$range_label}\n\n";
$clipboard_report .= "📝 *Executive Summary:*\n{$executive_narrative}\n\n";
$clipboard_report .= "📈 *Performance & Metrics:*\n";
$clipboard_report .= "• Total Task Terdata : {$total_week_tasks}\n";
$clipboard_report .= "• Approved / Passed  : {$approved_count} ({$completion_rate}%)\n";
$clipboard_report .= "• Submitted (BAS)    : {$submitted_count}\n";
$clipboard_report .= "• Test Ongoing       : {$ongoing_count}\n";
$clipboard_report .= "• Downloaded / Baru  : " . ($downloaded_count + $new_count) . "\n";
$clipboard_report .= "• Pending Feedback   : {$pending_count}\n";
$clipboard_report .= "• Task Urgent        : {$urgent_count}\n";
$clipboard_report .= "• Task Late / Delay  : {$late_count}\n\n";

$clipboard_report .= "💡 *Highlight Distribusi:*\n";
$clipboard_report .= "• PIC Beban Tertinggi   : {$top_pic_name} ({$top_pic_count} task)\n";
$clipboard_report .= "• PIC Approved Terbanyak: {$top_approved_pic} ({$top_approved_count} task)\n";
$clipboard_report .= "• Test Plan Utama       : {$top_testplan_name} ({$top_testplan_count} task)\n";

if ($urgent_count > 0) {
    $clipboard_report .= "\n🚨 *Daftar Task Urgent ({$urgent_count}):*\n";
    foreach (array_slice($urgent_tasks, 0, 8) as $ut) {
        $p = !empty($ut['username']) ? $ut['username'] : $ut['pic_email'];
        $clipboard_report .= "- [{$ut['model_name']}] {$ut['ap']} (PIC: {$p}, Status: {$ut['progress_status']})\n";
    }
}

function get_status_badge_class($st) {
    switch ($st) {
        case 'Approved':
        case 'Passed':
            return 'bg-emerald-500/15 text-emerald-500 dark:text-emerald-400 border-emerald-500/30';
        case 'Submitted':
            return 'bg-blue-500/15 text-blue-500 dark:text-blue-400 border-blue-500/30';
        case 'Test Ongoing':
            return 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30';
        case 'Downloaded':
            return 'bg-cyan-500/15 text-cyan-600 dark:text-cyan-400 border-cyan-500/30';
        case 'Task Baru':
            return 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border-indigo-500/30';
        case 'Pending Feedback':
        case 'Feedback Sent':
            return 'bg-purple-500/15 text-purple-600 dark:text-purple-400 border-purple-500/30';
        case 'Batal':
            return 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30';
        default:
            return 'bg-slate-500/15 text-slate-600 dark:text-slate-400 border-slate-500/30';
    }
}

function get_testplan_badge_class($tp) {
    $tp_clean = strtoupper(trim($tp ?? ''));
    if (strpos($tp_clean, 'SMR') !== false) {
        return 'bg-cyan-500/15 text-cyan-600 dark:text-cyan-400 border-cyan-500/30';
    } elseif (strpos($tp_clean, 'SKU') !== false) {
        return 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30';
    } elseif (strpos($tp_clean, 'MR') !== false) {
        return 'bg-purple-500/15 text-purple-600 dark:text-purple-400 border-purple-500/30';
    } elseif (strpos($tp_clean, 'PL') !== false || strpos($tp_clean, 'PRE') !== false) {
        return 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30';
    } elseif (strpos($tp_clean, 'GBA') !== false || strpos($tp_clean, 'GLOBAL') !== false) {
        return 'bg-blue-500/15 text-blue-600 dark:text-blue-400 border-blue-500/30';
    }
    return 'bg-slate-500/15 text-slate-600 dark:text-slate-400 border-slate-500/30';
}

function get_pic_badge_class($name) {
    $palette = [
        'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border-indigo-500/30',
        'bg-sky-500/15 text-sky-600 dark:text-sky-400 border-sky-500/30',
        'bg-teal-500/15 text-teal-600 dark:text-teal-400 border-teal-500/30',
        'bg-violet-500/15 text-violet-600 dark:text-violet-400 border-violet-500/30',
        'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30',
        'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30',
        'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30',
        'bg-fuchsia-500/15 text-fuchsia-600 dark:text-fuchsia-400 border-fuchsia-500/30'
    ];
    $idx = abs(crc32(strtolower(trim($name ?? '')))) % count($palette);
    return $palette[$idx];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly Insight Report (Rabu - Selasa) | Project Manager</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['class', '.never-match-dark']
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <style>
        :root { 
            --bg-primary: #020617; 
            --text-primary: #f1f5f9; 
            --text-secondary: #94a3b8; 
            --card-bg: rgba(15, 23, 42, 0.75); 
            --card-border: rgba(51, 65, 85, 0.6); 
            --metric-bg: rgba(30, 41, 59, 0.6);
            --input-bg: rgba(30, 41, 59, 0.75); 
            --input-border: #475569;
            --table-header-bg: rgba(15, 23, 42, 0.9);
            --table-row-hover: rgba(255, 255, 255, 0.05);
            --btn-nav-bg: #1e293b;
            --btn-nav-text: #e2e8f0;
            --btn-nav-border: #334155;
            --tag-bg: rgba(51, 65, 85, 0.5);
            --tag-text: #cbd5e1;
        }
        html.light { 
            --bg-primary: #f8fafc; 
            --text-primary: #0f172a; 
            --text-secondary: #475569; 
            --card-bg: #ffffff; 
            --card-border: #e2e8f0; 
            --metric-bg: #f8fafc;
            --input-bg: #ffffff; 
            --input-border: #cbd5e1;
            --table-header-bg: #f1f5f9;
            --table-row-hover: #f1f5f9;
            --btn-nav-bg: #f1f5f9;
            --btn-nav-text: #1e293b;
            --btn-nav-border: #cbd5e1;
            --tag-bg: #e2e8f0;
            --tag-text: #334155;
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--bg-primary); 
            color: var(--text-primary); 
            min-height: 100vh;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .text-adaptive-main { color: var(--text-primary) !important; }
        .text-adaptive-sub { color: var(--text-secondary) !important; }

        #neural-canvas { 
            position: fixed; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            z-index: -1; 
            pointer-events: none !important; 
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 18px;
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.2s ease;
        }
        .glass-card:hover {
            border-color: rgba(56, 189, 248, 0.35);
        }
        html.light .glass-card {
            box-shadow: 0 4px 16px -2px rgba(0, 0, 0, 0.05);
        }

        /* Emil Kowalski Design Engineering & Better UI Tables */
        .better-table-wrap {
            border-radius: 16px;
            border: 1px solid var(--card-border);
            background: var(--card-bg);
            overflow: hidden;
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.08);
        }
        .better-table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            font-feature-settings: "tnum" 1, "ss01" 1, "cv01" 1;
        }
        .better-table th, .better-table td {
            border-right: 1px solid var(--card-border);
            border-bottom: 1px solid var(--card-border);
        }
        .better-table tr th:last-child, .better-table tr td:last-child {
            border-right: none;
        }
        .better-table tbody tr:last-child td {
            border-bottom: none;
        }
        .better-table-row {
            transition: background-color 0.15s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .better-table-row:hover {
            background-color: var(--table-row-hover) !important;
        }

        .header-sapphire {
            background: linear-gradient(180deg, #0284c7 0%, #0369a1 100%);
            color: #ffffff;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
        }
        .header-amber-week {
            background: linear-gradient(180deg, #f59e0b 0%, #d97706 100%);
            color: #ffffff;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
        }
        html.light .header-amber-week {
            background: linear-gradient(180deg, #fbbf24 0%, #f59e0b 100%);
            color: #451a03;
            text-shadow: none;
        }
        .header-crimson-weekend {
            background: linear-gradient(180deg, #f43f5e 0%, #e11d48 100%);
            color: #ffffff;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
        }

        .sub-done {
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
        }
        html.dark .sub-done {
            background: rgba(16, 185, 129, 0.2);
            color: #34d399;
        }
        .sub-drop {
            background: rgba(245, 158, 11, 0.12);
            color: #d97706;
        }
        html.dark .sub-drop {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
        }
        .sub-prog {
            background: rgba(14, 165, 233, 0.12);
            color: #0284c7;
        }
        html.dark .sub-prog {
            background: rgba(14, 165, 233, 0.2);
            color: #38bdf8;
        }

        .total-foot {
            background: linear-gradient(180deg, rgba(14, 165, 233, 0.16) 0%, rgba(14, 165, 233, 0.26) 100%);
            font-weight: 800;
        }

        .csc-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 7px;
            border-radius: 6px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.25);
            color: #d97706;
        }
        html.dark .csc-badge {
            background: rgba(245, 158, 11, 0.18);
            border-color: rgba(245, 158, 11, 0.35);
            color: #fcd34d;
        }

        .badge-test {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            background: rgba(245, 158, 11, 0.14);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #d97706;
        }
        html.dark .badge-test {
            color: #fbbf24;
            background: rgba(245, 158, 11, 0.22);
            border-color: rgba(245, 158, 11, 0.45);
        }

        .badge-submit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            background: rgba(14, 165, 233, 0.14);
            border: 1px solid rgba(14, 165, 233, 0.3);
            color: #0284c7;
        }
        html.dark .badge-submit {
            color: #38bdf8;
            background: rgba(14, 165, 233, 0.22);
            border-color: rgba(14, 165, 233, 0.45);
        }

        .badge-approve {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            background: rgba(16, 185, 129, 0.14);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #059669;
        }
        html.dark .badge-approve {
            color: #34d399;
            background: rgba(16, 185, 129, 0.22);
            border-color: rgba(16, 185, 129, 0.45);
        }

        .badge-submit-approve {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.15) 0%, rgba(16, 185, 129, 0.15) 100%);
            border: 1px solid rgba(16, 185, 129, 0.35);
            color: #0f766e;
        }
        html.dark .badge-submit-approve {
            color: #2dd4bf;
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.22) 0%, rgba(16, 185, 129, 0.22) 100%);
            border-color: rgba(45, 212, 191, 0.45);
        }

        .btn-action-tactile {
            padding: 8px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
        }
        .btn-action-tactile:active {
            transform: scale(0.96);
        }

        .nav-week-btn {
            background-color: var(--btn-nav-bg);
            color: var(--btn-nav-text);
            border: 1px solid var(--btn-nav-border);
        }
        .nav-week-btn:hover {
            opacity: 0.9;
        }

        .task-table-row {
            transition: background-color 0.15s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .task-table-row:hover {
            background-color: var(--table-row-hover) !important;
        }
        html.light .task-table-row:hover {
            background-color: #f1f5f9 !important;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.3); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 184, 0.5); }

        /* Chip Input & Email Config Styles */
        .chip-container {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            padding: 8px 10px;
            border-radius: 12px;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            min-height: 44px;
            cursor: text;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .chip-container:focus-within {
            border-color: #38bdf8;
            box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.2);
        }
        .chip-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 8px 3px 10px;
            border-radius: 9999px;
            font-size: 11.5px;
            font-weight: 500;
            background: rgba(56, 189, 248, 0.15);
            border: 1px solid rgba(56, 189, 248, 0.35);
            color: #38bdf8;
            animation: chipPop 0.15s cubic-bezier(0.16, 1, 0.3, 1);
        }
        html.light .chip-tag {
            background: #e0f2fe;
            border-color: #bae6fd;
            color: #0369a1;
        }
        .chip-tag.invalid {
            background: rgba(239, 68, 68, 0.15);
            border-color: rgba(239, 68, 68, 0.4);
            color: #ef4444;
        }
        .chip-remove {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            cursor: pointer;
            opacity: 0.75;
            transition: opacity 0.1s, transform 0.1s, background 0.1s;
            font-size: 13px;
            line-height: 1;
        }
        .chip-remove:hover {
            opacity: 1;
            transform: scale(1.15);
            background: rgba(0,0,0,0.15);
        }
        .chip-input {
            flex: 1 1 140px;
            min-width: 120px;
            border: none;
            outline: none;
            background: transparent;
            color: var(--text-primary);
            font-size: 12px;
            padding: 3px 2px;
        }
        .chip-input::placeholder {
            color: var(--text-secondary);
            opacity: 0.65;
        }

        @keyframes chipPop {
            0% { transform: scale(0.85); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }

        /* Modal Transitions */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.68);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .modal-backdrop.active {
            opacity: 1;
            pointer-events: auto;
        }
        .modal-dialog {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
            border-radius: 16px;
            width: 100%;
            max-width: 580px;
            transform: scale(0.95) translateY(8px);
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            overflow: hidden;
        }
        .modal-backdrop.active .modal-dialog {
            transform: scale(1) translateY(0);
        }

        /* Custom Scrollbar for tables/consoles */
        .custom-scroll::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }
        .custom-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-scroll::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.25);
            border-radius: 4px;
        }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <canvas id="neural-canvas"></canvas>

    <!-- Toast Notification -->
    <div id="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[10000] px-4 py-2.5 rounded-xl text-xs font-semibold text-white shadow-2xl flex items-center gap-2 transition-all duration-300 pointer-events-none opacity-0 translate-y-4">
        <span id="toast-icon"></span>
        <span id="toast-msg"></span>
    </div>

    <?php include 'header.php'; ?>

    <main class="w-full flex-grow max-w-[1720px] mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
        
        <!-- HEADER HUD & WEEK CYCLE SELECTOR -->
        <div class="flex flex-col 2xl:flex-row 2xl:items-center justify-between gap-4 flex-shrink-0">
            <div class="flex items-center gap-3.5 min-w-0">
                <div class="w-12 h-12 rounded-2xl bg-emerald-500/15 border border-emerald-500/30 flex items-center justify-center text-emerald-500 flex-shrink-0 shadow-sm">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zM9 14l2 2 4-4" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <div class="flex items-center gap-2.5 flex-wrap">
                        <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-adaptive-main truncate">Weekly Insight Report</h1>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30 whitespace-nowrap">
                            Siklus Rabu - Selasa
                        </span>
                        <?php if ($is_current_week): ?>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-500/15 text-blue-600 dark:text-blue-400 border border-blue-500/30 whitespace-nowrap">
                                Current Week
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs sm:text-sm font-medium text-adaptive-sub truncate">
                        Executive Summary & Analitik Menyeluruh (Semua Status) Periode: <span class="font-bold text-emerald-500 dark:text-emerald-400"><?= $range_label ?></span>
                    </p>
                </div>
            </div>

            <!-- WEEK SELECTOR CONTROLLER & ACTION BUTTONS (NO WRAP ON DESKTOP) -->
            <div class="flex items-center gap-2 overflow-x-auto pb-1 2xl:pb-0 flex-nowrap flex-shrink-0">
                <a href="weekly_report_summary.php?date=<?= $prev_week_date ?>" class="btn-action-tactile nav-week-btn shadow-sm flex-shrink-0 whitespace-nowrap">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    <span>Prev Week</span>
                </a>

                <?php if (!$is_current_week): ?>
                <a href="weekly_report_summary.php" class="btn-action-tactile bg-emerald-600/15 hover:bg-emerald-600/25 text-emerald-600 dark:text-emerald-300 border border-emerald-500/30 flex-shrink-0 whitespace-nowrap">
                    <span>Current Week</span>
                </a>
                <?php endif; ?>

                <a href="weekly_report_summary.php?date=<?= $next_week_date ?>" class="btn-action-tactile nav-week-btn shadow-sm flex-shrink-0 whitespace-nowrap">
                    <span>Next Week</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>

                <!-- Send Mail Button (Left-click: Send | Right-click: Configure) -->
                <button id="btn-send-email-report" 
                        onclick="handleSendEmailClick()" 
                        oncontextmenu="openEmailConfigModal(event)" 
                        class="btn-action-tactile bg-sky-600 hover:bg-sky-500 text-white shadow-sm shadow-sky-600/30 group relative flex-shrink-0 whitespace-nowrap"
                        title="Klik kiri: Kirim Email Report langsung | Klik kanan: Atur Subject & Penerima">
                    <span id="send-mail-icon-wrap" class="flex items-center justify-center w-4 h-4">
                        <svg id="send-mail-icon" class="w-4 h-4 transition-transform group-hover:-translate-y-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </span>
                    <span id="send-mail-text">Kirim Email Report</span>
                    <span class="text-[9px] text-sky-100 bg-sky-700/80 px-1.5 py-0.5 rounded font-mono border border-sky-400/30 hidden xl:inline">R-Click ⚙️</span>
                </button>

                <button id="btn-copy-weekly" onclick="copyWeeklyReport()" class="btn-action-tactile bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white shadow-sm shadow-emerald-600/25 flex-shrink-0 whitespace-nowrap">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                    </svg>
                    <span>Copy Report</span>
                </button>
            </div>
        </div>

        <!-- KPI SUMMARY CARDS -->
        <div class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 sm:gap-4">
            <!-- Total Tasks -->
            <div class="glass-card p-4 flex flex-col justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-adaptive-sub">Total Task</span>
                <div class="flex items-baseline justify-between mt-2">
                    <span class="text-2xl sm:text-3xl font-black text-adaptive-main"><?= $total_week_tasks ?></span>
                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-slate-500/15 text-adaptive-sub">Semua Status</span>
                </div>
            </div>

            <!-- Approved (Passed) -->
            <div class="glass-card p-4 flex flex-col justify-between border-l-4 border-l-emerald-500">
                <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-500 dark:text-emerald-400">Approved / Passed</span>
                <div class="flex items-baseline justify-between mt-2">
                    <span class="text-2xl sm:text-3xl font-black text-emerald-500 dark:text-emerald-400"><?= $approved_count ?></span>
                    <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400"><?= $completion_rate ?>%</span>
                </div>
            </div>

            <!-- Submitted (BAS) -->
            <div class="glass-card p-4 flex flex-col justify-between border-l-4 border-l-blue-500">
                <span class="text-[11px] font-bold uppercase tracking-wider text-blue-500 dark:text-blue-400">Submitted</span>
                <div class="flex items-baseline justify-between mt-2">
                    <span class="text-2xl sm:text-3xl font-black text-blue-500 dark:text-blue-400"><?= $submitted_count ?></span>
                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-blue-500/15 text-blue-600 dark:text-blue-300">Review</span>
                </div>
            </div>

            <!-- Test Ongoing -->
            <div class="glass-card p-4 flex flex-col justify-between border-l-4 border-l-amber-500">
                <span class="text-[11px] font-bold uppercase tracking-wider text-amber-500 dark:text-amber-400">Test Ongoing</span>
                <div class="flex items-baseline justify-between mt-2">
                    <span class="text-2xl sm:text-3xl font-black text-amber-500 dark:text-amber-400"><?= $ongoing_count ?></span>
                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-600 dark:text-amber-300">Testing</span>
                </div>
            </div>

            <!-- Task Urgent -->
            <div class="glass-card p-4 flex flex-col justify-between border-l-4 border-l-rose-500">
                <span class="text-[11px] font-bold uppercase tracking-wider text-rose-500 dark:text-rose-400">Task Urgent</span>
                <div class="flex items-baseline justify-between mt-2">
                    <span class="text-2xl sm:text-3xl font-black text-rose-500 dark:text-rose-400"><?= $urgent_count ?></span>
                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-rose-500/15 text-rose-600 dark:text-rose-300">Priority</span>
                </div>
            </div>

            <!-- Overdue / Late -->
            <div class="glass-card p-4 flex flex-col justify-between border-l-4 border-l-purple-500">
                <span class="text-[11px] font-bold uppercase tracking-wider text-purple-500 dark:text-purple-400">Late / Due Soon</span>
                <div class="flex items-baseline justify-between mt-2">
                    <span class="text-2xl sm:text-3xl font-black text-purple-500 dark:text-purple-400"><?= $late_count ?></span>
                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-purple-500/15 text-purple-600 dark:text-purple-300">Delay</span>
                </div>
            </div>
        </div>

        <!-- EXECUTIVE NARRATIVE CARD -->
        <div class="glass-card p-5 sm:p-6 border-l-4 border-l-emerald-500">
            <div class="flex items-center gap-2.5 mb-2.5">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                <h3 class="text-xs sm:text-sm font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Executive Summary Mingguan</h3>
            </div>
            <p class="text-xs sm:text-sm leading-relaxed text-adaptive-main font-medium">
                <?= htmlspecialchars($executive_narrative) ?>
            </p>
        </div>

        <!-- CHARTS & DISTRIBUTION BENTO -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
            <!-- Daily Activity Timeline (Rabu - Selasa) -->
            <div class="glass-card p-5 lg:col-span-2 flex flex-col justify-between">
                <div class="flex items-center justify-between border-b pb-3 mb-4" style="border-color: var(--card-border);">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        <h4 class="text-sm font-bold text-adaptive-main">Timeline Aktivitas Harian (Rabu - Selasa)</h4>
                    </div>
                    <span class="text-xs font-semibold text-adaptive-sub">Submissions & Approvals</span>
                </div>
                <div class="h-64 w-full relative">
                    <canvas id="weeklyTimelineChart"></canvas>
                </div>
            </div>

            <!-- Status Doughnut Chart -->
            <div class="glass-card p-5 flex flex-col justify-between">
                <div class="flex items-center justify-between border-b pb-3 mb-4" style="border-color: var(--card-border);">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"/></svg>
                        <h4 class="text-sm font-bold text-adaptive-main">Distribusi Status</h4>
                    </div>
                    <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400"><?= $completion_rate ?>% Pass</span>
                </div>
                <div class="h-56 w-full relative flex items-center justify-center">
                    <canvas id="statusDoughnutChart"></canvas>
                </div>
                <div class="grid grid-cols-2 gap-2 mt-3 pt-3 border-t text-[11px]" style="border-color: var(--card-border);">
                    <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-500"></span><span class="text-adaptive-sub">Approved: <?= $approved_count ?></span></div>
                    <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-blue-500"></span><span class="text-adaptive-sub">Submitted: <?= $submitted_count ?></span></div>
                    <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-500"></span><span class="text-adaptive-sub">Ongoing: <?= $ongoing_count ?></span></div>
                    <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-indigo-500"></span><span class="text-adaptive-sub">Baru/DL: <?= $downloaded_count + $new_count ?></span></div>
                </div>
            </div>
        </div>

        <!-- PIC LOAD & TEST PLAN BREAKDOWN -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <!-- PIC Workload Card -->
            <div class="glass-card p-5">
                <div class="flex items-center justify-between border-b pb-3 mb-4" style="border-color: var(--card-border);">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        <h4 class="text-sm font-bold text-adaptive-main">Beban Task per PIC (Mingguan)</h4>
                    </div>
                    <span class="text-xs font-semibold text-adaptive-sub"><?= count($pic_load_dist) ?> PIC</span>
                </div>
                <div class="space-y-3">
                    <?php foreach (array_slice($pic_load_dist, 0, 6, true) as $p_name => $cnt): 
                        $pct = round(($cnt / max(1, $total_week_tasks)) * 100);
                        $app_cnt = $pic_approved_dist[$p_name] ?? 0;
                    ?>
                    <div>
                        <div class="flex items-center justify-between text-xs font-semibold mb-1">
                            <span class="text-adaptive-main"><?= htmlspecialchars($p_name) ?></span>
                            <span class="text-adaptive-sub"><?= $cnt ?> Task (<?= $app_cnt ?> Pass) • <?= $pct ?>%</span>
                        </div>
                        <div class="w-full h-2 rounded-full bg-slate-200 dark:bg-slate-700/50 overflow-hidden flex">
                            <div class="bg-emerald-500 h-full" style="width: <?= ($cnt > 0 ? ($app_cnt / $cnt) * 100 : 0) ?>%"></div>
                            <div class="bg-blue-500 h-full" style="width: <?= ($cnt > 0 ? (($cnt - $app_cnt) / $cnt) * 100 : 0) ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Test Plan Type Breakdown -->
            <div class="glass-card p-5">
                <div class="flex items-center justify-between border-b pb-3 mb-4" style="border-color: var(--card-border);">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        <h4 class="text-sm font-bold text-adaptive-main">Kategori Test Plan Type</h4>
                    </div>
                    <span class="text-xs font-semibold text-adaptive-sub"><?= count($test_plan_dist) ?> Kategori</span>
                </div>
                <div class="space-y-3">
                    <?php foreach ($test_plan_dist as $tp_name => $cnt): 
                        $pct = round(($cnt / max(1, $total_week_tasks)) * 100);
                    ?>
                    <div>
                        <div class="flex items-center justify-between text-xs font-semibold mb-1">
                            <span class="text-adaptive-main"><?= htmlspecialchars($tp_name) ?></span>
                            <span class="text-adaptive-sub"><?= $cnt ?> Task • <?= $pct ?>%</span>
                        </div>
                        <div class="w-full h-2 rounded-full bg-slate-200 dark:bg-slate-700/50 overflow-hidden">
                            <div class="bg-gradient-to-r from-cyan-500 to-blue-500 h-full rounded-full" style="width: <?= $pct ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- TOP MULTI-WEEK PIC MATRIX SUMMARY (Emil Design Engineering & Better UI) -->
        <div class="glass-card p-4 sm:p-5 space-y-3.5">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <div class="w-3 h-3 rounded-full bg-sky-500 shadow-sm shadow-sky-500/50 animate-pulse"></div>
                    <h3 class="text-sm font-bold uppercase tracking-wider text-adaptive-main">Summary Project GA per PIC & Mingguan</h3>
                </div>
                <span class="text-xs font-semibold px-2.5 py-1 rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400 border border-sky-500/20 shadow-sm">
                    Tahun <?= $curr_year ?> • W-<?= $w_prev_num ?>, W-<?= $w_curr_num ?>, W-<?= $w_next_num ?>
                </span>
            </div>

            <div class="better-table-wrap overflow-x-auto">
                <table class="better-table text-xs select-none">
                    <thead>
                        <tr>
                            <th rowspan="2" class="header-sapphire py-2.5 px-4 font-bold text-center whitespace-nowrap text-xs">GBA PIC</th>
                            <th colspan="3" class="header-sapphire py-2 px-4 font-bold text-center whitespace-nowrap text-xs">Total GA Project Y<?= $curr_year ?></th>
                            <th colspan="3" class="header-amber-week py-2 px-4 font-bold text-center whitespace-nowrap text-xs">W-<?= $w_prev_num ?></th>
                            <th colspan="3" class="header-amber-week py-2 px-4 font-bold text-center whitespace-nowrap text-xs">W-<?= $w_curr_num ?></th>
                            <th colspan="3" class="header-amber-week py-2 px-4 font-bold text-center whitespace-nowrap text-xs">W-<?= $w_next_num ?></th>
                        </tr>
                        <tr>
                            <!-- Total GA Project Y... subheaders -->
                            <th class="sub-done py-1.5 px-3 font-bold text-center text-[11px]">Done</th>
                            <th class="sub-drop py-1.5 px-3 font-bold text-center text-[11px]">Drop</th>
                            <th class="sub-prog py-1.5 px-3 font-bold text-center text-[11px]">Prog.</th>
                            <!-- W-prev subheaders -->
                            <th class="sub-done py-1.5 px-3 font-bold text-center text-[11px]">Done</th>
                            <th class="sub-drop py-1.5 px-3 font-bold text-center text-[11px]">Drop</th>
                            <th class="sub-prog py-1.5 px-3 font-bold text-center text-[11px]">Prog.</th>
                            <!-- W-curr subheaders -->
                            <th class="sub-done py-1.5 px-3 font-bold text-center text-[11px]">Done</th>
                            <th class="sub-drop py-1.5 px-3 font-bold text-center text-[11px]">Drop</th>
                            <th class="sub-prog py-1.5 px-3 font-bold text-center text-[11px]">Prog.</th>
                            <!-- W-next subheaders -->
                            <th class="sub-done py-1.5 px-3 font-bold text-center text-[11px]">Done</th>
                            <th class="sub-drop py-1.5 px-3 font-bold text-center text-[11px]">Drop</th>
                            <th class="sub-prog py-1.5 px-3 font-bold text-center text-[11px]">Prog.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($active_pic_matrix)): ?>
                        <tr>
                            <td colspan="13" class="py-6 text-center text-adaptive-sub font-medium">Belum ada data task per PIC pada periode ini.</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($active_pic_matrix as $p_em => $p_data): ?>
                            <tr class="better-table-row">
                                <td class="py-2.5 px-3.5 font-bold text-adaptive-main text-left"><?= htmlspecialchars($p_data['display_name']) ?></td>
                                <td class="py-2.5 px-3 text-center font-semibold text-adaptive-main tabular-nums"><?= $p_data['total_year']['done'] ?: '-' ?></td>
                                <td class="py-2.5 px-3 text-center font-semibold text-adaptive-main tabular-nums"><?= $p_data['total_year']['drop'] ?: '-' ?></td>
                                <td class="py-2.5 px-3 text-center font-semibold text-adaptive-main tabular-nums"><?= $p_data['total_year']['prog'] ?: '-' ?></td>
                                <td class="py-2.5 px-3 text-center font-semibold text-adaptive-main tabular-nums"><?= $p_data['w_prev']['done'] ?: '-' ?></td>
                                <td class="py-2.5 px-3 text-center font-semibold text-adaptive-main tabular-nums"><?= $p_data['w_prev']['drop'] ?: '-' ?></td>
                                <td class="py-2.5 px-3 text-center font-semibold text-adaptive-main tabular-nums"><?= $p_data['w_prev']['prog'] ?: '-' ?></td>
                                <td class="py-2.5 px-3 text-center font-semibold text-adaptive-main tabular-nums"><?= $p_data['w_curr']['done'] ?: '-' ?></td>
                                <td class="py-2.5 px-3 text-center font-semibold text-adaptive-main tabular-nums"><?= $p_data['w_curr']['drop'] ?: '-' ?></td>
                                <td class="py-2.5 px-3 text-center font-semibold text-adaptive-main tabular-nums"><?= $p_data['w_curr']['prog'] ?: '-' ?></td>
                                <td class="py-2.5 px-3 text-center font-semibold text-adaptive-main tabular-nums"><?= $p_data['w_next']['done'] ?: '-' ?></td>
                                <td class="py-2.5 px-3 text-center font-semibold text-adaptive-main tabular-nums"><?= $p_data['w_next']['drop'] ?: '-' ?></td>
                                <td class="py-2.5 px-3 text-center font-semibold text-adaptive-main tabular-nums"><?= $p_data['w_next']['prog'] ?: '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="total-foot text-slate-900 dark:text-sky-100">
                            <td class="py-2.5 px-3.5 text-left font-black tracking-wide">Total</td>
                            <td class="py-2.5 px-3 text-center font-black tabular-nums"><?= $matrix_totals['total_year']['done'] ?></td>
                            <td class="py-2.5 px-3 text-center font-black tabular-nums"><?= $matrix_totals['total_year']['drop'] ?></td>
                            <td class="py-2.5 px-3 text-center font-black tabular-nums"><?= $matrix_totals['total_year']['prog'] ?></td>
                            <td class="py-2.5 px-3 text-center font-black tabular-nums"><?= $matrix_totals['w_prev']['done'] ?></td>
                            <td class="py-2.5 px-3 text-center font-black tabular-nums"><?= $matrix_totals['w_prev']['drop'] ?></td>
                            <td class="py-2.5 px-3 text-center font-black tabular-nums"><?= $matrix_totals['w_prev']['prog'] ?></td>
                            <td class="py-2.5 px-3 text-center font-black tabular-nums"><?= $matrix_totals['w_curr']['done'] ?></td>
                            <td class="py-2.5 px-3 text-center font-black tabular-nums"><?= $matrix_totals['w_curr']['drop'] ?></td>
                            <td class="py-2.5 px-3 text-center font-black tabular-nums"><?= $matrix_totals['w_curr']['prog'] ?></td>
                            <td class="py-2.5 px-3 text-center font-black tabular-nums"><?= $matrix_totals['w_next']['done'] ?></td>
                            <td class="py-2.5 px-3 text-center font-black tabular-nums"><?= $matrix_totals['w_next']['drop'] ?></td>
                            <td class="py-2.5 px-3 text-center font-black tabular-nums"><?= $matrix_totals['w_next']['prog'] ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- FULL TASK DATA TABLE (Emil Design Engineering & Better UI) -->
        <div class="glass-card p-5 flex flex-col space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b pb-4" style="border-color: var(--card-border);">
                <div>
                    <h3 class="text-base font-bold text-adaptive-main">Jadwal & Task Pengujian Mingguan</h3>
                    <p class="text-xs text-adaptive-sub">Total <?= $total_week_tasks ?> task terdata dalam siklus Rabu - Selasa</p>
                </div>
                
                <div class="flex items-center gap-2 flex-wrap">
                    <!-- Search Input -->
                    <div class="relative">
                        <input type="text" id="table-search" placeholder="Cari model, AP, PIC..." onkeyup="filterWeeklyTable()" class="px-3 py-1.5 text-xs rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700 text-slate-800 dark:text-slate-200 placeholder-slate-400 focus:outline-none focus:border-sky-500 pl-8 w-48 sm:w-60 transition-all shadow-sm">
                        <svg class="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>

                    <!-- Status Filter Dropdown -->
                    <select id="status-filter" onchange="filterWeeklyTable()" class="px-3 py-1.5 text-xs rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700 text-slate-800 dark:text-slate-200 focus:outline-none focus:border-sky-500 transition-all shadow-sm">
                        <option value="ALL">Semua Status (<?= $total_week_tasks ?>)</option>
                        <option value="Approved">Approved (<?= $approved_count ?>)</option>
                        <option value="Submitted">Submitted (<?= $submitted_count ?>)</option>
                        <option value="Test Ongoing">Test Ongoing (<?= $ongoing_count ?>)</option>
                        <option value="Task Baru">Task Baru (<?= $new_count ?>)</option>
                        <option value="Downloaded">Downloaded (<?= $downloaded_count ?>)</option>
                        <option value="URGENT">Urgent Only (<?= $urgent_count ?>)</option>
                    </select>
                </div>
            </div>

            <!-- Table responsive (Grid Layout Sesuai Gambar) -->
            <div class="better-table-wrap overflow-x-auto">
                <table id="weekly-table" class="better-table text-left text-xs">
                    <thead>
                        <tr class="select-none font-bold">
                            <th class="header-sapphire py-3 px-2.5 text-center whitespace-nowrap w-10">No</th>
                            <th class="header-sapphire py-3 px-3 text-left whitespace-nowrap">Model Name</th>
                            <th class="header-sapphire py-3 px-3 text-left whitespace-nowrap">AP(Code) ver.</th>
                            <th class="header-sapphire py-3 px-3 text-left whitespace-nowrap">CP(BB) ver.</th>
                            <th class="header-sapphire py-3 px-3 text-left whitespace-nowrap">CSC</th>
                            <th class="header-sapphire py-3 px-3 text-center whitespace-nowrap">GA Date Line</th>
                            <th class="header-sapphire py-3 px-3 text-left whitespace-nowrap">GA PIC</th>
                            <th class="header-sapphire py-3 px-3 text-left whitespace-nowrap">GA Type</th>
                            <?php foreach ($schedule_days as $s_day): ?>
                                <th class="<?= $s_day['is_weekend'] ? 'header-crimson-weekend' : 'header-sapphire' ?> py-3 px-2.5 text-center whitespace-nowrap min-w-[76px]">
                                    <?= htmlspecialchars($s_day['day_label']) ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_tasks)): ?>
                            <tr>
                                <td colspan="<?= 8 + count($schedule_days) ?>" class="py-8 text-center text-adaptive-sub font-medium">
                                    Tidak ada task yang terdata pada siklus mingguan ini.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($all_tasks as $idx => $t): 
                                $pic_email = $t['pic_email'] ?? '';
                                $date_line = !empty($t['deadline']) ? date('n/j/Y', strtotime($t['deadline'])) : (!empty($t['request_date']) ? date('n/j/Y', strtotime($t['request_date'])) : '-');
                                $ga_type = !empty($t['test_plan_type']) ? $t['test_plan_type'] : '-';
                                $has_csc = !empty($t['csc']) && trim($t['csc']) !== '-';
                            ?>
                            <tr class="better-table-row task-row" 
                                data-status="<?= htmlspecialchars($t['progress_status']) ?>"
                                data-urgent="<?= !empty($t['is_urgent']) ? '1' : '0' ?>"
                                data-search="<?= strtolower(htmlspecialchars(($t['model_name'] ?? '') . ' ' . ($t['marketing_name'] ?? '') . ' ' . ($t['ap'] ?? '') . ' ' . ($t['cp'] ?? '') . ' ' . ($t['csc'] ?? '') . ' ' . $pic_email . ' ' . $ga_type)) ?>">
                                
                                <td class="py-2.5 px-2.5 text-center font-medium text-adaptive-sub tabular-nums"><?= $idx + 1 ?></td>
                                
                                <td class="py-2.5 px-3 font-semibold text-adaptive-main whitespace-nowrap">
                                    <span><?= htmlspecialchars($t['model_name']) ?></span>
                                    <?php if (!empty($t['is_urgent'])): ?>
                                        <span class="ml-1 px-1.5 py-0.2 text-[9px] font-black tracking-wider rounded bg-rose-500/15 text-rose-500 border border-rose-500/30">URGENT</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="py-2.5 px-3 font-mono text-[11px] text-adaptive-main whitespace-nowrap">
                                    <?= htmlspecialchars($t['ap'] ?: '-') ?>
                                </td>
                                
                                <td class="py-2.5 px-3 font-mono text-[11px] text-adaptive-main whitespace-nowrap">
                                    <?= htmlspecialchars($t['cp'] ?: '-') ?>
                                </td>
                                
                                <td class="py-2.5 px-3 whitespace-nowrap">
                                    <?php if ($has_csc): ?>
                                        <span class="csc-badge"><?= htmlspecialchars($t['csc']) ?></span>
                                    <?php else: ?>
                                        <span class="text-adaptive-sub font-mono text-[11px]">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="py-2.5 px-3 text-center whitespace-nowrap font-medium text-adaptive-main tabular-nums">
                                    <?= htmlspecialchars($date_line) ?>
                                </td>
                                
                                <td class="py-2.5 px-3 text-left whitespace-nowrap text-adaptive-main">
                                    <?= htmlspecialchars($pic_email) ?>
                                </td>
                                
                                <td class="py-2.5 px-3 text-left whitespace-nowrap font-medium text-adaptive-main">
                                    <?= htmlspecialchars($ga_type) ?>
                                </td>

                                <!-- 8 Daily Columns -->
                                <?php foreach ($schedule_days as $s_day): 
                                    $d_str = $s_day['date_str'];
                                    $has_test = (!empty($t['request_date']) && $t['request_date'] === $d_str) ||
                                                (empty($t['request_date']) && !empty($t['updated_at']) && substr($t['updated_at'], 0, 10) === $d_str && in_array($t['progress_status'], ['Test Ongoing', 'Task Baru']));
                                    $has_submit = (!empty($t['submission_date']) && $t['submission_date'] === $d_str);
                                    $has_approve = (!empty($t['approved_date']) && $t['approved_date'] === $d_str);
                                ?>
                                <td class="py-2 px-1.5 text-center whitespace-nowrap text-xs <?= $s_day['is_weekend'] ? 'bg-slate-500/[0.03] dark:bg-slate-900/40' : '' ?>">
                                    <div class="flex items-center justify-center gap-1 flex-wrap">
                                        <?php if ($has_test): ?>
                                            <span class="badge-test">Test</span>
                                        <?php endif; ?>
                                        <?php if ($has_submit && $has_approve): ?>
                                            <span class="badge-submit-approve">Submit & Approve</span>
                                        <?php elseif ($has_submit): ?>
                                            <span class="badge-submit">Submit</span>
                                        <?php elseif ($has_approve): ?>
                                            <span class="badge-approve">Approve</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php endforeach; ?>

                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- HIDDEN CLIPBOARD TEXT FOR JAVASCRIPT COPY -->
    <textarea id="hidden-clipboard-report" class="hidden"><?= htmlspecialchars($clipboard_report) ?></textarea>

    <!-- SCRIPTS -->
    <script>
        // --- Copy Weekly Report to Clipboard ---
        function copyWeeklyReport() {
            const text = document.getElementById('hidden-clipboard-report').value;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    showToast('Laporan Mingguan berhasil disalin ke clipboard!', true);
                }).catch(() => {
                    fallbackCopy(text);
                });
            } else {
                fallbackCopy(text);
            }
        }

        function fallbackCopy(text) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
                showToast('Laporan Mingguan berhasil disalin ke clipboard!', true);
            } catch (err) {
                showToast('Gagal menyalin laporan.', false);
            }
            document.body.removeChild(ta);
        }

        // --- Toast Function ---
        function showToast(message, isSuccess) {
            const toast = document.getElementById('toast');
            const icon = document.getElementById('toast-icon');
            const msg = document.getElementById('toast-msg');

            msg.textContent = message;
            if (isSuccess) {
                toast.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 z-[10000] px-4 py-2.5 rounded-xl text-xs font-semibold text-white shadow-2xl flex items-center gap-2 bg-emerald-600 border border-emerald-500/50 opacity-100 translate-y-0 transition-all duration-300';
                icon.innerHTML = '<svg class="w-4 h-4 text-emerald-100" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
            } else {
                toast.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 z-[10000] px-4 py-2.5 rounded-xl text-xs font-semibold text-white shadow-2xl flex items-center gap-2 bg-rose-600 border border-rose-500/50 opacity-100 translate-y-0 transition-all duration-300';
                icon.innerHTML = '<svg class="w-4 h-4 text-rose-100" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>';
            }

            setTimeout(() => {
                toast.classList.remove('opacity-100', 'translate-y-0');
                toast.classList.add('opacity-0', 'translate-y-4');
            }, 3000);
        }

        // --- Filter Table Search & Status ---
        function filterWeeklyTable() {
            const search = document.getElementById('table-search').value.toLowerCase().trim();
            const statusFilter = document.getElementById('status-filter').value;
            const rows = document.querySelectorAll('.task-row');

            rows.forEach(row => {
                const rowSearch = row.getAttribute('data-search') || '';
                const rowStatus = row.getAttribute('data-status') || '';
                const isUrgent = row.getAttribute('data-urgent') === '1';

                let matchesSearch = !search || rowSearch.includes(search);
                let matchesStatus = (statusFilter === 'ALL') || 
                                    (statusFilter === 'URGENT' && isUrgent) || 
                                    (rowStatus === statusFilter);

                if (matchesSearch && matchesStatus) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // --- Chart.js Initializations & Theme Reactivity ---
        let timelineChart = null;
        let doughnutChart = null;

        function getChartColors() {
            const isLight = document.documentElement.classList.contains('light');
            return {
                text: isLight ? '#475569' : '#94a3b8',
                grid: isLight ? 'rgba(0, 0, 0, 0.06)' : 'rgba(148, 163, 184, 0.1)'
            };
        }

        function initOrUpdateCharts() {
            const colors = getChartColors();
            const dailyData = <?= json_encode(array_values($daily_activity)) ?>;

            // 1. Timeline Chart
            const timelineCtx = document.getElementById('weeklyTimelineChart');
            if (timelineCtx) {
                const labels = dailyData.map(d => `${d.day_name} (${d.formatted})`);
                const submissions = dailyData.map(d => d.submissions);
                const approvals = dailyData.map(d => d.approvals);

                if (timelineChart) {
                    timelineChart.options.scales.x.ticks.color = colors.text;
                    timelineChart.options.scales.y.ticks.color = colors.text;
                    timelineChart.options.scales.x.grid.color = colors.grid;
                    timelineChart.options.scales.y.grid.color = colors.grid;
                    timelineChart.options.plugins.legend.labels.color = colors.text;
                    timelineChart.update();
                } else {
                    timelineChart = new Chart(timelineCtx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [
                                {
                                    label: 'Submissions (BAS)',
                                    data: submissions,
                                    backgroundColor: 'rgba(59, 130, 246, 0.75)',
                                    borderColor: 'rgb(59, 130, 246)',
                                    borderWidth: 1,
                                    borderRadius: 6
                                },
                                {
                                    label: 'Approved Builds',
                                    data: approvals,
                                    backgroundColor: 'rgba(16, 185, 129, 0.75)',
                                    borderColor: 'rgb(16, 185, 129)',
                                    borderWidth: 1,
                                    borderRadius: 6
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    labels: { color: colors.text, font: { family: 'Inter', size: 11, weight: '600' } }
                                }
                            },
                            scales: {
                                x: {
                                    grid: { color: colors.grid },
                                    ticks: { color: colors.text, font: { family: 'Inter', size: 10 } }
                                },
                                y: {
                                    beginAtZero: true,
                                    grid: { color: colors.grid },
                                    ticks: { color: colors.text, font: { family: 'Inter', size: 10 }, stepSize: 1 }
                                }
                            }
                        }
                    });
                }
            }

            // 2. Status Doughnut Chart
            const doughnutCtx = document.getElementById('statusDoughnutChart');
            if (doughnutCtx && !doughnutChart) {
                doughnutChart = new Chart(doughnutCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Approved', 'Submitted', 'Ongoing', 'Downloaded/Baru', 'Pending/Feedback'],
                        datasets: [{
                            data: [
                                <?= $approved_count ?>,
                                <?= $submitted_count ?>,
                                <?= $ongoing_count ?>,
                                <?= $downloaded_count + $new_count ?>,
                                <?= $pending_count ?>
                            ],
                            backgroundColor: [
                                '#10b981',
                                '#3b82f6',
                                '#f59e0b',
                                '#6366f1',
                                '#a855f7'
                            ],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        cutout: '72%'
                    }
                });
            }
        }

        document.addEventListener('DOMContentLoaded', initOrUpdateCharts);
        window.addEventListener('themechanged', initOrUpdateCharts);

        // --- Neural Canvas Network ---
        (function() {
            const canvas = document.getElementById('neural-canvas');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            let w, h, particles = [];

            function resize() {
                w = canvas.width = window.innerWidth;
                h = canvas.height = window.innerHeight;
            }
            window.addEventListener('resize', resize);
            resize();

            const count = Math.min(25, Math.floor((w * h) / 40000));
            for (let i = 0; i < count; i++) {
                particles.push({
                    x: Math.random() * w,
                    y: Math.random() * h,
                    vx: (Math.random() - 0.5) * 0.4,
                    vy: (Math.random() - 0.5) * 0.4,
                    radius: Math.random() * 1.5 + 0.8
                });
            }

            function draw() {
                ctx.clearRect(0, 0, w, h);
                const isLight = document.documentElement.classList.contains('light');
                const pColor = isLight ? 'rgba(16, 185, 129, 0.25)' : 'rgba(16, 185, 129, 0.35)';
                const lColor = isLight ? 'rgba(16, 185, 129, 0.08)' : 'rgba(16, 185, 129, 0.12)';

                for (let i = 0; i < particles.length; i++) {
                    const p = particles[i];
                    p.x += p.vx;
                    p.y += p.vy;
                    if (p.x < 0) p.x = w;
                    if (p.x > w) p.x = 0;
                    if (p.y < 0) p.y = h;
                    if (p.y > h) p.y = 0;

                    ctx.beginPath();
                    ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
                    ctx.fillStyle = pColor;
                    ctx.fill();

                    for (let j = i + 1; j < particles.length; j++) {
                        const p2 = particles[j];
                        const dx = p.x - p2.x;
                        const dy = p.y - p2.y;
                        const dist = Math.sqrt(dx * dx + dy * dy);
                        if (dist < 120) {
                            ctx.beginPath();
                            ctx.moveTo(p.x, p.y);
                            ctx.lineTo(p2.x, p2.y);
                            ctx.strokeStyle = lColor;
                            ctx.lineWidth = 1;
                            ctx.stroke();
                        }
                    }
                }
                requestAnimationFrame(draw);
            }
            draw();
        })();

        // ----------------------------------------------------
        // EMAIL CONFIGURATION & MCP SENDMAIL CLIENT (Emil-Design-Eng)
        // ----------------------------------------------------
        const defaultEmailSubject = "<?php echo addslashes("[WEEKLY REPORT GBA] Insight Summary (" . $range_label . ")"); ?>";
        const defaultEmailTo = ["<?php echo addslashes($_SESSION['user_details']['email'] ?? 'endri.s@samsung.com'); ?>"];

        let emailSettings = {
            subject: localStorage.getItem('gba_weekly_report_email_subject') || defaultEmailSubject,
            to: JSON.parse(localStorage.getItem('gba_report_email_to') || 'null') || defaultEmailTo,
            cc: JSON.parse(localStorage.getItem('gba_report_email_cc') || 'null') || []
        };

        const circleSpinnerSvg = `<svg class="animate-spin w-4 h-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>`;
        const envelopeIconSvg = `<svg id="send-mail-icon" class="w-4 h-4 transition-transform group-hover:-translate-y-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>`;

        // Console Log Management Functions
        function openSmtpConsole() {
            const widget = document.getElementById('smtp-console-widget');
            if (widget) {
                widget.classList.remove('translate-y-8', 'opacity-0', 'pointer-events-none');
                widget.classList.add('translate-y-0', 'opacity-100', 'pointer-events-auto');
            }
        }

        function closeSmtpConsole() {
            const widget = document.getElementById('smtp-console-widget');
            if (widget) {
                widget.classList.remove('translate-y-0', 'opacity-100', 'pointer-events-auto');
                widget.classList.add('translate-y-8', 'opacity-0', 'pointer-events-none');
            }
        }

        function toggleSmtpConsoleMinimize() {
            const body = document.getElementById('smtp-console-body');
            if (body) {
                body.classList.toggle('hidden');
            }
        }

        function clearSmtpConsole() {
            const body = document.getElementById('smtp-console-body');
            if (body) body.innerHTML = '';
        }

        function logSmtpConsole(message, type = 'info') {
            const body = document.getElementById('smtp-console-body');
            if (!body) return;

            const now = new Date();
            const timeStr = now.toTimeString().split(' ')[0];
            
            let colorClass = 'text-slate-300';
            if (type === 'success') colorClass = 'text-emerald-400 font-semibold';
            else if (type === 'error') colorClass = 'text-rose-400 font-semibold';
            else if (type === 'warn') colorClass = 'text-amber-400 font-semibold';
            else if (type === 'step') colorClass = 'text-sky-300';

            const line = document.createElement('div');
            line.className = `flex items-start gap-1.5 ${colorClass}`;
            line.innerHTML = `<span class="text-slate-500 flex-shrink-0 select-none">[${timeStr}]</span> <span class="break-all flex-1">${escapeHtml(message)}</span>`;
            body.appendChild(line);
            body.scrollTop = body.scrollHeight;
        }

        function setSmtpConsoleStatus(status) {
            const dot = document.getElementById('smtp-console-status-dot');
            if (!dot) return;
            dot.className = 'w-2.5 h-2.5 rounded-full';
            if (status === 'busy') {
                dot.classList.add('bg-amber-400', 'animate-pulse');
            } else if (status === 'success') {
                dot.classList.add('bg-emerald-400');
            } else if (status === 'error') {
                dot.classList.add('bg-rose-500');
            }
        }

        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        function isValidEmail(email) {
            return emailRegex.test(String(email).toLowerCase());
        }

        function escapeHtml(text) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(text).replace(/[&<>"']/g, m => map[m]);
        }

        // Render Chip Tags for a given target ('to' or 'cc')
        function renderChips(target) {
            const listEl = document.getElementById(`chip-list-${target}`);
            if (!listEl) return;
            listEl.innerHTML = '';

            const items = emailSettings[target] || [];
            items.forEach((email, idx) => {
                const valid = isValidEmail(email);
                const tag = document.createElement('span');
                tag.className = `chip-tag ${valid ? '' : 'invalid'}`;
                tag.innerHTML = `
                    <span>${escapeHtml(email)}</span>
                    <button type="button" class="chip-remove" onclick="removeEmailChip('${target}', ${idx}, event)" title="Hapus email">×</button>
                `;
                listEl.appendChild(tag);
            });
        }

        function removeEmailChip(target, index, event) {
            if (event) event.stopPropagation();
            if (emailSettings[target] && emailSettings[target].length > index) {
                emailSettings[target].splice(index, 1);
                renderChips(target);
            }
        }

        function addEmailChipsFromText(target, rawText) {
            if (!rawText) return;
            const tokens = rawText.split(/[\s,;]+/).map(t => t.trim()).filter(t => t.length > 0);
            if (tokens.length === 0) return;

            if (!emailSettings[target]) emailSettings[target] = [];
            tokens.forEach(tok => {
                if (!emailSettings[target].includes(tok)) {
                    emailSettings[target].push(tok);
                }
            });
            renderChips(target);
        }

        function focusChipInput(target) {
            const inp = document.getElementById(`chip-input-${target}`);
            if (inp) inp.focus();
        }

        // Setup Chip Input Listeners
        function setupChipInput(target) {
            const inp = document.getElementById(`chip-input-${target}`);
            if (!inp) return;

            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ',' || e.key === ';' || e.key === 'Tab') {
                    if (this.value.trim().length > 0) {
                        e.preventDefault();
                        addEmailChipsFromText(target, this.value);
                        this.value = '';
                    }
                } else if (e.key === 'Backspace' && this.value === '') {
                    if (emailSettings[target] && emailSettings[target].length > 0) {
                        emailSettings[target].pop();
                        renderChips(target);
                    }
                }
            });

            inp.addEventListener('paste', function(e) {
                e.preventDefault();
                const pasteText = (e.clipboardData || window.clipboardData).getData('text');
                if (pasteText) {
                    addEmailChipsFromText(target, pasteText);
                    this.value = '';
                }
            });

            inp.addEventListener('blur', function() {
                if (this.value.trim().length > 0) {
                    addEmailChipsFromText(target, this.value);
                    this.value = '';
                }
            });
        }

        // Open Modal (Right Click Trigger or Config button)
        function openEmailConfigModal(event) {
            if (event) {
                event.preventDefault();
            }
            const modal = document.getElementById('email-config-modal');
            const subjInput = document.getElementById('input-email-subject');
            if (subjInput) {
                subjInput.value = emailSettings.subject || defaultEmailSubject;
            }
            renderChips('to');
            renderChips('cc');
            if (modal) {
                modal.classList.add('active');
            }
        }

        function closeEmailConfigModal() {
            const modal = document.getElementById('email-config-modal');
            if (modal) {
                modal.classList.remove('active');
            }
        }

        function handleBackdropClick(event) {
            if (event.target && event.target.id === 'email-config-modal') {
                closeEmailConfigModal();
            }
        }

        function resetEmailSubjectToDefault() {
            const subjInput = document.getElementById('input-email-subject');
            if (subjInput) {
                subjInput.value = defaultEmailSubject;
            }
        }

        function saveEmailSettingsState() {
            const subjInput = document.getElementById('input-email-subject');
            if (subjInput) {
                emailSettings.subject = subjInput.value.trim() || defaultEmailSubject;
            }
            localStorage.setItem('gba_weekly_report_email_subject', emailSettings.subject);
            localStorage.setItem('gba_report_email_to', JSON.stringify(emailSettings.to || []));
            localStorage.setItem('gba_report_email_cc', JSON.stringify(emailSettings.cc || []));
        }

        function saveEmailSettingsOnly() {
            saveEmailSettingsState();
            closeEmailConfigModal();
            showToast('Daftar penerima dan subjek email berhasil diperbarui.', true);
        }

        function saveAndSendEmailNow() {
            saveEmailSettingsState();
            closeEmailConfigModal();
            triggerSendEmailRequest();
        }

        function handleSendEmailClick() {
            // If recipient list is empty, open configuration modal
            if (!emailSettings.to || emailSettings.to.length === 0) {
                showToast('Silakan masukkan minimal 1 email penerima terlebih dahulu.', false);
                openEmailConfigModal();
                return;
            }
            triggerSendEmailRequest();
        }

        async function triggerSendEmailRequest() {
            const btn = document.getElementById('btn-send-email-report');
            const textEl = document.getElementById('send-mail-text');
            const iconWrap = document.getElementById('send-mail-icon-wrap');

            const originalText = textEl ? textEl.innerHTML : 'Kirim Email Report';
            if (btn) btn.disabled = true;
            if (textEl) textEl.textContent = 'Mengirim email...';
            if (iconWrap) iconWrap.innerHTML = circleSpinnerSvg;

            // Open & Initialize Console Widget
            openSmtpConsole();
            clearSmtpConsole();
            setSmtpConsoleStatus('busy');

            logSmtpConsole("🚀 Memulai proses pengiriman Weekly Report via SMTP...", "step");
            logSmtpConsole(`📋 Periode: "<?= addslashes($range_label) ?>"`, "info");
            logSmtpConsole(`📋 Subjek: "${emailSettings.subject || defaultEmailSubject}"`, "info");
            logSmtpConsole(`👥 Penerima (${(emailSettings.to || []).length} alamat): ${(emailSettings.to || []).join(', ')}`, "info");
            if (emailSettings.cc && emailSettings.cc.length) {
                logSmtpConsole(`👥 Tembusan CC (${emailSettings.cc.length} alamat): ${emailSettings.cc.join(', ')}`, "info");
            }
            logSmtpConsole("📡 Menghubungkan ke backend bridge & MCP SMTP Mailer (Port 3800)...", "step");
            logSmtpConsole("⏳ Mengirim payload HTML ke server SMTP (timeout diset hingga 5 menit)...", "warn");

            const startTime = Date.now();
            const heartbeatInterval = setInterval(() => {
                const elapsedSec = Math.floor((Date.now() - startTime) / 1000);
                logSmtpConsole(`⏳ Masih memproses pengiriman SMTP... (${elapsedSec} detik)`, "info");
            }, 5000);

            try {
                const payload = {
                    report_type: 'weekly',
                    date: '<?= $target_date ?>',
                    subject: emailSettings.subject || defaultEmailSubject,
                    to: emailSettings.to || [],
                    cc: emailSettings.cc || []
                };

                const res = await fetch('api_send_report_email.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                clearInterval(heartbeatInterval);
                const elapsedTotal = ((Date.now() - startTime) / 1000).toFixed(1);

                const data = await res.json();

                if (res.ok && data.success) {
                    setSmtpConsoleStatus('success');
                    logSmtpConsole(`✅ Email berhasil dikirim dalam ${elapsedTotal}s!`, "success");
                    if (data.mcp_response && data.mcp_response.result) {
                        const mId = data.mcp_response.result.messageId || data.mcp_response.result.response || 'OK';
                        logSmtpConsole(`📬 Respon SMTP: ${mId}`, "success");
                    }
                    showToast(data.message || `Laporan terkirim ke ${data.recipients.length} penerima.`, true);
                } else {
                    setSmtpConsoleStatus('error');
                    const errMsg = data.error || 'Terjadi kesalahan saat memproses email via MCP SMTP.';
                    logSmtpConsole(`❌ Pengiriman gagal (${elapsedTotal}s): ${errMsg}`, "error");
                    showToast(errMsg, false);
                }
            } catch (err) {
                clearInterval(heartbeatInterval);
                const elapsedTotal = ((Date.now() - startTime) / 1000).toFixed(1);
                setSmtpConsoleStatus('error');
                logSmtpConsole(`❌ Koneksi terputus (${elapsedTotal}s): ${err.message}`, "error");
                showToast(err.message || 'Gagal menghubungi server bridge.', false);
            } finally {
                clearInterval(heartbeatInterval);
                if (btn) btn.disabled = false;
                if (textEl) textEl.innerHTML = originalText;
                if (iconWrap) iconWrap.innerHTML = envelopeIconSvg;
            }
        }

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEmailConfigModal();
            }
        });

        // Initialize chip input listeners
        setupChipInput('to');
        setupChipInput('cc');
    </script>

    <!-- EMAIL DISPATCH & RECIPIENT CONFIG MODAL -->
    <div id="email-config-modal" class="modal-backdrop" onclick="handleBackdropClick(event)">
        <div class="modal-dialog" onclick="event.stopPropagation()">
            <!-- Modal Header -->
            <div class="px-5 py-4 border-b border-[var(--card-border)] flex items-center justify-between bg-[var(--table-header-bg)]">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-sky-500/15 border border-sky-500/30 flex items-center justify-center text-sky-400 flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-primary">Kirim Weekly Report via Email</h3>
                        <p class="text-[11px] text-secondary">Pengaturan subjek & daftar penerima laporan mingguan</p>
                    </div>
                </div>
                <button type="button" onclick="closeEmailConfigModal()" class="w-7 h-7 rounded-lg text-secondary hover:text-primary hover:bg-[var(--metric-bg)] flex items-center justify-center transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <!-- Modal Body -->
            <div class="p-5 space-y-4 max-h-[70vh] overflow-y-auto custom-scroll">
                <!-- Subject input -->
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-semibold text-primary">Subjek Email</label>
                        <button type="button" onclick="resetEmailSubjectToDefault()" class="text-[11px] text-sky-400 hover:text-sky-300 transition-colors">Reset Default</button>
                    </div>
                    <input type="text" id="input-email-subject" class="w-full px-3.5 py-2 rounded-xl text-xs bg-[var(--input-bg)] border border-[var(--input-border)] text-primary placeholder-[var(--text-secondary)] focus:outline-none focus:border-sky-400 transition-all font-medium">
                </div>

                <!-- Recipient (TO) Chip Input -->
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-semibold text-primary">Penerima Utama (To) <span class="text-rose-400">*</span></label>
                        <span class="text-[10px] text-secondary">Ketik atau paste email (koma / enter)</span>
                    </div>
                    <div id="chip-container-to" class="chip-container" onclick="focusChipInput('to')">
                        <div id="chip-list-to" class="flex flex-wrap gap-1.5"></div>
                        <input type="text" id="chip-input-to" class="chip-input" placeholder="Ketik email lalu tekan Enter...">
                    </div>
                </div>

                <!-- Recipient (CC) Chip Input -->
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-semibold text-primary">Tembusan (CC) <span class="text-secondary font-normal">(Opsional)</span></label>
                        <span class="text-[10px] text-secondary">Ketik atau paste email (koma / enter)</span>
                    </div>
                    <div id="chip-container-cc" class="chip-container" onclick="focusChipInput('cc')">
                        <div id="chip-list-cc" class="flex flex-wrap gap-1.5"></div>
                        <input type="text" id="chip-input-cc" class="chip-input" placeholder="Ketik email CC...">
                    </div>
                </div>

                <!-- Info note -->
                <div class="p-3 rounded-xl bg-sky-500/10 border border-sky-500/20 text-[11px] text-sky-400 flex items-start gap-2">
                    <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span>Email akan dikirimkan otomatis dalam format HTML responsif yang memuat rekap mingguan, matriks penyelesaian, dan tabel breakdown task via MCP SMTP Mailer.</span>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="px-5 py-3.5 border-t border-[var(--card-border)] bg-[var(--metric-bg)] flex items-center justify-between gap-2">
                <button type="button" onclick="closeEmailConfigModal()" class="px-3.5 py-1.5 rounded-lg border border-[var(--card-border)] text-secondary hover:text-primary text-xs font-medium transition-colors">
                    Tutup
                </button>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="saveEmailSettingsOnly()" class="px-3.5 py-1.5 rounded-lg bg-[var(--input-bg)] hover:opacity-90 border border-[var(--input-border)] text-primary text-xs font-semibold transition-colors">
                        Simpan Pengaturan
                    </button>
                    <button type="button" onclick="saveAndSendEmailNow()" class="btn-action-tactile bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold shadow-sm shadow-sky-600/30">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" /></svg>
                        <span>Simpan & Kirim</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- SMTP MAILER CONSOLE LOG WIDGET (Emil-Design-Engineering & Better-UI) -->
    <div id="smtp-console-widget" class="fixed bottom-5 right-5 z-[10001] w-80 sm:w-[420px] rounded-2xl shadow-2xl border border-[var(--card-border)] bg-[var(--card-bg)] backdrop-blur-xl overflow-hidden transform translate-y-8 opacity-0 pointer-events-none transition-all duration-300">
        <!-- Console Header -->
        <div class="px-3.5 py-2.5 bg-[var(--table-header-bg)] border-b border-[var(--card-border)] flex items-center justify-between select-none">
            <div class="flex items-center gap-2">
                <span id="smtp-console-status-dot" class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse"></span>
                <span class="text-xs font-mono font-bold text-primary flex items-center gap-1.5">
                    <span>SMTP Mailer Console</span>
                    <span class="text-[9px] text-sky-400 bg-sky-500/10 px-1 py-0.2 rounded border border-sky-400/20">LIVE</span>
                </span>
            </div>
            <div class="flex items-center gap-1">
                <button type="button" onclick="toggleSmtpConsoleMinimize()" class="p-1 rounded text-secondary hover:text-primary transition-colors text-xs" title="Minimize / Expand">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                </button>
                <button type="button" onclick="closeSmtpConsole()" class="p-1 rounded text-secondary hover:text-primary transition-colors text-xs" title="Tutup console">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
        </div>
        <!-- Console Output Terminal -->
        <div id="smtp-console-body" class="p-3 font-mono text-[11px] leading-relaxed max-h-56 overflow-y-auto custom-scroll space-y-1.5 bg-slate-950/90 text-slate-200">
            <!-- Dynamic logs -->
        </div>
    </div>
</body>
</html>
