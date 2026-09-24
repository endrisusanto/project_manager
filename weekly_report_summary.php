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
            --table-row-hover: rgba(255, 255, 255, 0.04);
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
            --table-row-hover: rgba(0, 0, 0, 0.025);
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
            border-color: rgba(16, 185, 129, 0.4);
        }
        html.light .glass-card {
            box-shadow: 0 4px 16px -2px rgba(0, 0, 0, 0.05);
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

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.3); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 184, 0.5); }
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

    <main class="w-full flex-grow p-4 sm:p-6 sm:py-8 flex flex-col max-w-7xl mx-auto space-y-6">
        
        <!-- HEADER HUD & WEEK CYCLE SELECTOR -->
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 flex-shrink-0">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-2xl bg-emerald-500/15 border border-emerald-500/30 flex items-center justify-center text-emerald-500 flex-shrink-0 shadow-sm">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zM9 14l2 2 4-4" />
                    </svg>
                </div>
                <div>
                    <div class="flex items-center gap-2.5 flex-wrap">
                        <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-adaptive-main">Weekly Insight Report</h1>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30">
                            Siklus Rabu - Selasa
                        </span>
                        <?php if ($is_current_week): ?>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-500/15 text-blue-600 dark:text-blue-400 border border-blue-500/30">
                                Current Week
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs sm:text-sm font-medium text-adaptive-sub">
                        Executive Summary & Analitik Menyeluruh (Semua Status) Periode: <span class="font-bold text-emerald-500 dark:text-emerald-400"><?= $range_label ?></span>
                    </p>
                </div>
            </div>

            <!-- WEEK SELECTOR CONTROLLER -->
            <div class="flex items-center gap-2 flex-wrap sm:flex-nowrap">
                <a href="weekly_report_summary.php?date=<?= $prev_week_date ?>" class="btn-action-tactile nav-week-btn shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    <span>Prev Week</span>
                </a>

                <?php if (!$is_current_week): ?>
                <a href="weekly_report_summary.php" class="btn-action-tactile bg-emerald-600/15 hover:bg-emerald-600/25 text-emerald-600 dark:text-emerald-300 border border-emerald-500/30">
                    <span>Current Week</span>
                </a>
                <?php endif; ?>

                <a href="weekly_report_summary.php?date=<?= $next_week_date ?>" class="btn-action-tactile nav-week-btn shadow-sm">
                    <span>Next Week</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>

                <button id="btn-copy-weekly" onclick="copyWeeklyReport()" class="btn-action-tactile bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white shadow-sm shadow-emerald-600/25 flex-shrink-0">
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

        <!-- FULL TASK DATA TABLE (ALL STATUSES) -->
        <div class="glass-card p-5 flex flex-col space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b pb-4" style="border-color: var(--card-border);">
                <div>
                    <h3 class="text-base font-bold text-adaptive-main">Daftar Lengkap Task Mingguan</h3>
                    <p class="text-xs text-adaptive-sub">Total <?= $total_week_tasks ?> task terdata dalam siklus Rabu - Selasa</p>
                </div>
                
                <div class="flex items-center gap-2 flex-wrap">
                    <!-- Search Input -->
                    <div class="relative">
                        <input type="text" id="table-search" placeholder="Cari model, AP, PIC..." onkeyup="filterWeeklyTable()" class="px-3 py-1.5 text-xs rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-800 dark:text-slate-200 placeholder-slate-400 focus:outline-none focus:border-emerald-500 pl-8 w-48 sm:w-60">
                        <svg class="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>

                    <!-- Status Filter Dropdown -->
                    <select id="status-filter" onchange="filterWeeklyTable()" class="px-3 py-1.5 text-xs rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-800 dark:text-slate-200 focus:outline-none focus:border-emerald-500">
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

            <!-- Table responsive -->
            <div class="overflow-x-auto">
                <table id="weekly-table" class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="border-b text-adaptive-sub font-semibold" style="border-color: var(--card-border);">
                            <th class="py-2.5 px-3">#</th>
                            <th class="py-2.5 px-3">Model & Marketing</th>
                            <th class="py-2.5 px-3">AP Version</th>
                            <th class="py-2.5 px-3">PIC</th>
                            <th class="py-2.5 px-3">Test Plan</th>
                            <th class="py-2.5 px-3">Status</th>
                            <th class="py-2.5 px-3">Deadline</th>
                            <th class="py-2.5 px-3">Approved Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800/40">
                        <?php if (empty($all_tasks)): ?>
                            <tr>
                                <td colspan="8" class="py-8 text-center text-adaptive-sub">
                                    Tidak ada task yang terdata pada siklus mingguan ini.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($all_tasks as $idx => $t): 
                                $pic_display = !empty($t['username']) ? $t['username'] : (!empty($t['pic_email']) ? explode('@', $t['pic_email'])[0] : '-');
                                $badge_cls = get_status_badge_class($t['progress_status']);
                            ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors task-row" 
                                data-status="<?= htmlspecialchars($t['progress_status']) ?>"
                                data-urgent="<?= !empty($t['is_urgent']) ? '1' : '0' ?>"
                                data-search="<?= strtolower(htmlspecialchars(($t['model_name'] ?? '') . ' ' . ($t['marketing_name'] ?? '') . ' ' . ($t['ap'] ?? '') . ' ' . $pic_display . ' ' . ($t['test_plan_type'] ?? ''))) ?>">
                                <td class="py-2.5 px-3 text-adaptive-sub"><?= $idx + 1 ?></td>
                                <td class="py-2.5 px-3">
                                    <div class="font-bold text-adaptive-main flex items-center gap-1.5">
                                        <span><?= htmlspecialchars($t['model_name']) ?></span>
                                        <?php if (!empty($t['is_urgent'])): ?>
                                            <span class="px-1.5 py-0.5 text-[9px] font-bold rounded bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/30">URGENT</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($t['marketing_name'])): ?>
                                        <div class="text-[11px] text-adaptive-sub"><?= htmlspecialchars($t['marketing_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 px-3 font-mono font-medium text-adaptive-main"><?= htmlspecialchars($t['ap'] ?: '-') ?></td>
                                <td class="py-2.5 px-3">
                                    <span class="px-2 py-0.5 rounded-md text-[11px] font-semibold bg-slate-100 dark:bg-slate-800 text-adaptive-main border border-slate-300 dark:border-slate-700">
                                        <?= htmlspecialchars($pic_display) ?>
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 text-adaptive-main"><?= htmlspecialchars($t['test_plan_type'] ?: '-') ?></td>
                                <td class="py-2.5 px-3">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border <?= $badge_cls ?>">
                                        <?= htmlspecialchars($t['progress_status']) ?>
                                    </span>
                                </td>
                                <td class="py-2.5 px-3">
                                    <?php if (!empty($t['deadline'])): ?>
                                        <div class="font-medium text-adaptive-main"><?= date('d/m/Y', strtotime($t['deadline'])) ?></div>
                                        <div class="text-[10px] <?= $t['deadline_badge_type'] === 'late' ? 'text-rose-500 dark:text-rose-400 font-bold' : 'text-adaptive-sub' ?>">
                                            <?= $t['deadline_badge_text'] ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-adaptive-sub">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 px-3">
                                    <?php if (!empty($t['approved_date'])): ?>
                                        <span class="text-emerald-600 dark:text-emerald-400 font-medium"><?= date('d/m/Y', strtotime($t['approved_date'])) ?></span>
                                    <?php else: ?>
                                        <span class="text-adaptive-sub">-</span>
                                    <?php endif; ?>
                                </td>
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
    </script>
</body>
</html>
