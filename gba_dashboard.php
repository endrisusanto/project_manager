<?php
// 1. INISIALISASI
require_once "config.php";
require_once "session.php"; 
require_once "marketing_name_mapper.php";

$active_page = 'gba_dashboard';

// 2. LOGIKA PENGAMBILAN & PEMROSESAN DATA
$tasks_result = $conn->query("SELECT * FROM gba_tasks WHERE request_date IS NOT NULL ORDER BY request_date ASC");
$all_tasks = [];
$available_years = [];
if ($tasks_result && $tasks_result->num_rows > 0) {
    while($row = $tasks_result->fetch_assoc()) {
        $all_tasks[] = $row;
        $year = date('Y', strtotime($row['request_date']));
        if (!in_array($year, $available_years)) {
            $available_years[] = $year;
        }
    }
}
sort($available_years);

// Inisialisasi variabel statistik
$stats = [
    'total' => 0, 
    'new' => 0, 
    'ongoing' => 0, 
    'submitted' => 0, 
    'approved' => 0, 
    'cancelled' => 0, 
    'delay' => 0, 
    'ontime' => 0,
    'urgent' => 0
];
$weekly_pic_distribution = [];
$test_plan_distribution = [];
$monthly_summary = [];
$weekly_summary = [];
$all_pics = [];

$today = new DateTime();
$day_of_week = (int)$today->format('w');
$days_to_subtract = ($day_of_week < 3) ? (7 + $day_of_week - 3) : ($day_of_week - 3);
$start_of_week = (new DateTime())->modify("-$days_to_subtract days");
$end_of_week = (clone $start_of_week)->modify("+6 days");

if (!empty($all_tasks)) {
    // 1. Distribusi Mingguan PIC (Rabu - Selasa) dengan Dukungan Multi-Minggu (Previous / Next Week)
    $all_weeks_pic_data = [];
    $max_week_offset = 26; // 26 minggu ke belakang (~6 bulan)
    
    for ($w = 0; $w <= $max_week_offset; $w++) {
        $sub = $days_to_subtract + ($w * 7);
        $w_start = (new DateTime())->modify("-$sub days");
        $w_end = (clone $w_start)->modify("+6 days");
        
        $w_tasks = array_filter($all_tasks, function($task) use ($w_start, $w_end) {
            $request_dt = new DateTime($task['request_date']);
            return $request_dt >= $w_start && $request_dt <= $w_end;
        });
        
        $w_dist = [];
        foreach ($w_tasks as $task) {
            $pic = !empty($task['pic_email']) ? explode('@', $task['pic_email'])[0] : 'Unassigned';
            $w_dist[$pic] = ($w_dist[$pic] ?? 0) + 1;
        }
        arsort($w_dist);
        
        $all_weeks_pic_data[$w] = [
            'offset' => $w,
            'tag' => ($w === 0) ? 'Minggu Ini' : ($w === 1 ? '1 Minggu Lalu' : "{$w} Mgg Lalu"),
            'date_range' => $w_start->format('d M') . ' - ' . $w_end->format('d M Y'),
            'total_tasks' => count($w_tasks),
            'distribution' => $w_dist
        ];
    }
    
    $weekly_pic_distribution = $all_weeks_pic_data[0]['distribution'] ?? [];
    $tasks_this_week = array_filter($all_tasks, function($task) use ($start_of_week, $end_of_week) {
        $request_dt = new DateTime($task['request_date']);
        return $request_dt >= $start_of_week && $request_dt <= $end_of_week;
    });

    // 2. Tahun Terpilih
    $selected_year = isset($_GET['year']) && in_array($_GET['year'], $available_years) ? $_GET['year'] : (end($available_years) ?: date('Y'));
    $tasks_for_yearly_chart = array_filter($all_tasks, fn($task) => date('Y', strtotime($task['request_date'])) == $selected_year);

    // 3. Statistik Keseluruhan & Test Plan
    foreach ($all_tasks as $task) {
        if ($task['progress_status'] == 'Task Baru') $stats['new']++;
        if (in_array($task['progress_status'], ['Test Ongoing', 'Pending Feedback', 'Feedback Sent'])) $stats['ongoing']++;
        if ($task['progress_status'] == 'Submitted') $stats['submitted']++;
        if ($task['progress_status'] == 'Approved' || $task['progress_status'] == 'Passed') $stats['approved']++;
        if ($task['progress_status'] == 'Batal') $stats['cancelled']++;
        if (!empty($task['is_urgent']) && $task['is_urgent'] == 1) $stats['urgent']++;

        if ($task['submission_date']) {
            $request_dt = new DateTime($task['request_date']);
            $submission_dt = new DateTime($task['submission_date']);
            if ($submission_dt->diff($request_dt)->days > 7) $stats['delay']++; else $stats['ontime']++;
        }

        $plan = strtoupper(trim($task['test_plan_type'] ?? 'NO PLAN'));
        if (empty($plan)) $plan = 'NO PLAN';
        $test_plan_distribution[$plan] = ($test_plan_distribution[$plan] ?? 0) + 1;
    }
    $stats['total'] = count($all_tasks);
    arsort($test_plan_distribution);

    // 4. Data PIC List
    $all_pics_raw = array_unique(array_column($all_tasks, 'pic_email'));
    foreach ($all_pics_raw as $p) {
        if (!empty($p)) $all_pics[] = explode('@', $p)[0];
    }
    $all_pics = array_values(array_unique($all_pics));

    // 5. Data Chart Tahunan (Mingguan W01 - W52)
    $year_start_date = new DateTime("{$selected_year}-01-01");
    for ($i = 0; $i < 52; $i++) {
        $week_key = "W" . sprintf('%02d', $i + 1);
        $weekly_summary[$week_key] = ['total' => 0];
        foreach ($all_pics as $pic) {
            $weekly_summary[$week_key][$pic] = 0;
        }
    }

    // 6. Data Bulanan (Jan - Des)
    $month_names = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    foreach ($month_names as $idx => $mName) {
        $mKey = sprintf('%02d', $idx + 1);
        $monthly_summary[$mName] = ['total' => 0, 'approved' => 0, 'cancelled' => 0];
    }

    foreach ($tasks_for_yearly_chart as $task) {
        $reqDt = new DateTime($task['request_date']);
        $week_num = (int)$reqDt->format("W");
        $week_key = "W" . sprintf('%02d', $week_num);
        $pic = !empty($task['pic_email']) ? explode('@', $task['pic_email'])[0] : 'Unassigned';
        
        if (isset($weekly_summary[$week_key])) {
            $weekly_summary[$week_key]['total']++;
            if (isset($weekly_summary[$week_key][$pic])) {
                $weekly_summary[$week_key][$pic]++;
            }
        }

        $mIdx = (int)$reqDt->format("n") - 1;
        if (isset($month_names[$mIdx])) {
            $mName = $month_names[$mIdx];
            $monthly_summary[$mName]['total']++;
            if ($task['progress_status'] === 'Approved' || $task['progress_status'] === 'Passed') {
                $monthly_summary[$mName]['approved']++;
            }
            if ($task['progress_status'] === 'Batal') {
                $monthly_summary[$mName]['cancelled']++;
            }
        }
    }

    $weekly_chart_data = ['labels' => array_keys($weekly_summary), 'datasets' => []];
    $weekly_chart_data['datasets'][] = [
        'label' => 'Total Velocity', 
        'data' => array_column($weekly_summary, 'total'), 
        'type' => 'line', 
        'borderColor' => '#38bdf8', 
        'backgroundColor' => 'rgba(56, 189, 248, 0.12)', 
        'fill' => true,
        'tension' => 0.4, 
        'yAxisID' => 'y', 
        'order' => 0, 
        'pointRadius' => 2, 
        'pointHoverRadius' => 6,
        'borderWidth' => 2.5
    ];

    $pic_colors = [];
    $color_palette = [
        '#6366f1', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', 
        '#06b6d4', '#14b8a6', '#f43f5e', '#fb923c', '#3b82f6', 
        '#84cc16', '#eab308', '#d946ef', '#64748b'
    ];
    $color_index = 0;
    
    // Helper function for transparent bar styling (better-ui / ponytail)
    $hex_to_rgba = function($hex, $alpha = 0.40) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) == 3) {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        return "rgba($r, $g, $b, $alpha)";
    };

    foreach ($all_pics as $pic) {
        $color = $color_palette[$color_index++ % count($color_palette)];
        $pic_colors[$pic] = $color;
        $weekly_chart_data['datasets'][] = [
            'label' => $pic, 
            'data' => array_column($weekly_summary, $pic), 
            'type' => 'bar', 
            'backgroundColor' => $hex_to_rgba($color, 0.40), 
            'hoverBackgroundColor' => $hex_to_rgba($color, 0.80),
            'borderColor' => $color,
            'borderWidth' => 1.5,
            'borderRadius' => 4,
            'borderSkipped' => false,
            'yAxisID' => 'y', 
            'order' => 1, 
            'barPercentage' => 0.75, 
            'categoryPercentage' => 0.85
        ];
    }
}

// KPI Ratios
$active_tasks_count = $stats['new'] + $stats['ongoing'] + $stats['submitted'];
$ontime_rate = ($stats['ontime'] + $stats['delay'] > 0) ? round(($stats['ontime'] / ($stats['ontime'] + $stats['delay'])) * 100, 1) : 100;
$approval_rate = ($stats['total'] > 0) ? round(($stats['approved'] / $stats['total']) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GBA Executive Analytics Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['class', '.never-match-dark']
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <style>
        :root { 
            --bg-primary: #020617; 
            --text-primary: #f1f5f9; 
            --text-secondary: #94a3b8; 
            --glass-bg: rgba(15, 23, 42, 0.75); 
            --glass-border: rgba(51, 65, 85, 0.6); 
            --card-bg: rgba(15, 23, 42, 0.68);
            --card-border: rgba(51, 65, 85, 0.65);
            --text-header: #ffffff; 
            --text-icon: #94a3b8; 
            --input-bg: rgba(30, 41, 59, 0.75); 
            --input-border: #475569; 
            --modal-bg: rgba(15, 23, 42, 0.92); 
            --modal-border: rgba(51, 65, 85, 0.6); 
        }
        html.light { 
            --bg-primary: #f8fafc; 
            --text-primary: #0f172a; 
            --text-secondary: #475569; 
            --glass-bg: rgba(255, 255, 255, 0.88); 
            --glass-border: rgba(0, 0, 0, 0.08); 
            --card-bg: rgba(255, 255, 255, 0.95);
            --card-border: rgba(0, 0, 0, 0.09);
            --text-header: #0f172a; 
            --text-icon: #475569; 
            --input-bg: #ffffff; 
            --input-border: #cbd5e1; 
            --modal-bg: rgba(255, 255, 255, 0.96); 
            --modal-border: rgba(0, 0, 0, 0.1); 
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--bg-primary); 
            color: var(--text-primary); 
            overflow-x: hidden;
        }

        #neural-canvas { 
            position: fixed; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            z-index: -1; 
            pointer-events: none !important; 
        }

        .main-container {
            height: calc(100vh - 64px);
            padding: 0.75rem 1rem;
            display: flex;
            flex-direction: column;
        }

        @media (max-width: 1023px) {
            .main-container {
                height: auto;
                min-height: calc(100vh - 64px);
                overflow-y: auto;
                padding: 1rem;
            }
        }

        /* Executive Hero KPI Card */
        .kpi-hero-card {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--card-border);
            border-radius: 0.875rem;
            padding: 0.75rem 0.875rem;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.18s cubic-bezier(0.16, 1, 0.3, 1), 
                        box-shadow 0.18s cubic-bezier(0.16, 1, 0.3, 1),
                        border-color 0.18s ease;
        }
        .kpi-hero-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px -6px rgba(0, 0, 0, 0.35);
            border-color: rgba(99, 102, 241, 0.4);
        }
        html.light .kpi-hero-card:hover {
            box-shadow: 0 8px 20px -4px rgba(0, 0, 0, 0.06);
            border-color: rgba(99, 102, 241, 0.35);
        }

        /* Glass Panel */
        .glass-panel {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--card-border);
            border-radius: 0.875rem;
            padding: 0.75rem 1rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        /* Pipeline Step Card */
        .pipeline-step {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 0.5rem 0.625rem;
            border-radius: 0.625rem;
            background: rgba(255, 255, 255, 0.025);
            border: 1px solid rgba(255, 255, 255, 0.05);
            transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .pipeline-step:hover {
            background: rgba(255, 255, 255, 0.06);
            transform: translateY(-1px);
        }
        html.light .pipeline-step {
            background: rgba(0, 0, 0, 0.025);
            border-color: rgba(0, 0, 0, 0.05);
        }
        html.light .pipeline-step:hover {
            background: rgba(0, 0, 0, 0.05);
        }

        /* Timezone Rows */
        .tz-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.375rem 0.625rem;
            border-radius: 0.5rem;
            background: rgba(255, 255, 255, 0.025);
            border: 1px solid rgba(255, 255, 255, 0.04);
            transition: all 0.15s ease;
        }
        .tz-card:hover {
            background: rgba(255, 255, 255, 0.06);
        }
        html.light .tz-card {
            background: rgba(0, 0, 0, 0.02);
            border-color: rgba(0, 0, 0, 0.04);
        }
        html.light .tz-card:hover {
            background: rgba(0, 0, 0, 0.04);
        }

        .select-pill {
            background-color: var(--input-bg);
            border: 1px solid var(--input-border);
            color: var(--text-primary);
            border-radius: 0.5rem;
            padding: 0.25rem 0.625rem;
            font-size: 0.75rem;
            font-weight: 600;
            outline: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .select-pill:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.3);
        }

        .modal-content-wrapper { 
            background: var(--modal-bg); 
            border: 1px solid var(--modal-border); 
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
    </style>
</head>
<body class="h-screen flex flex-col overflow-hidden">
    <canvas id="neural-canvas"></canvas>

    <?php include 'header.php'; ?>

    <main class="main-container flex-grow overflow-y-auto lg:overflow-hidden">
        <div class="max-w-[1720px] w-full mx-auto flex flex-col flex-1 min-h-0 gap-2.5">
            
            <!-- Top Row: Executive Hero KPI & Pipeline Strip -->
            <div class="grid grid-cols-12 gap-2.5 flex-shrink-0">
                
                <!-- Left 7 cols: 4 Primary KPI Tiles -->
                <div class="col-span-12 lg:col-span-7 grid grid-cols-2 sm:grid-cols-4 gap-2">
                    
                    <!-- KPI 1: Total Workload -->
                    <div class="kpi-hero-card">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-[10px] font-bold text-secondary uppercase tracking-wider">Total Workload</span>
                                <h3 class="text-2xl font-black font-mono tracking-tight text-primary mt-0.5 tabular-nums">
                                    <?= number_format($stats['total']) ?>
                                </h3>
                            </div>
                            <div class="w-7 h-7 rounded-lg bg-blue-500/15 border border-blue-500/30 flex items-center justify-center text-blue-400 flex-shrink-0">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                            </div>
                        </div>
                        <div class="mt-1.5 pt-1.5 border-t border-[var(--glass-border)] flex items-center justify-between text-[11px] text-secondary">
                            <span>Aktif</span>
                            <span class="font-bold text-blue-400 font-mono"><?= $active_tasks_count ?> task</span>
                        </div>
                    </div>

                    <!-- KPI 2: SLA Ontime Rate -->
                    <div class="kpi-hero-card">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-[10px] font-bold text-secondary uppercase tracking-wider">SLA Ontime</span>
                                <h3 class="text-2xl font-black font-mono tracking-tight text-emerald-400 mt-0.5 tabular-nums">
                                    <?= $ontime_rate ?>%
                                </h3>
                            </div>
                            <div class="w-7 h-7 rounded-lg bg-emerald-500/15 border border-emerald-500/30 flex items-center justify-center text-emerald-400 flex-shrink-0">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            </div>
                        </div>
                        <div class="mt-1.5 pt-1.5 border-t border-[var(--glass-border)] flex items-center justify-between text-[11px] text-secondary">
                            <span class="text-emerald-400 font-semibold font-mono"><?= $stats['ontime'] ?> Ontime</span>
                            <span class="text-rose-400 font-semibold font-mono"><?= $stats['delay'] ?> Delay</span>
                        </div>
                    </div>

                    <!-- KPI 3: Approval Rate -->
                    <div class="kpi-hero-card">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-[10px] font-bold text-secondary uppercase tracking-wider">Completion</span>
                                <h3 class="text-2xl font-black font-mono tracking-tight text-cyan-400 mt-0.5 tabular-nums">
                                    <?= $approval_rate ?>%
                                </h3>
                            </div>
                            <div class="w-7 h-7 rounded-lg bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center text-cyan-400 flex-shrink-0">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            </div>
                        </div>
                        <div class="mt-1.5 pt-1.5 border-t border-[var(--glass-border)] flex items-center justify-between text-[11px] text-secondary">
                            <span>Approved</span>
                            <span class="font-bold text-cyan-400 font-mono"><?= $stats['approved'] ?> task</span>
                        </div>
                    </div>

                    <!-- KPI 4: Urgent Flagged -->
                    <div class="kpi-hero-card">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-[10px] font-bold text-secondary uppercase tracking-wider">Urgent Flag</span>
                                <h3 class="text-2xl font-black font-mono tracking-tight text-rose-400 mt-0.5 tabular-nums">
                                    <?= $stats['urgent'] ?>
                                </h3>
                            </div>
                            <div class="w-7 h-7 rounded-lg bg-rose-500/15 border border-rose-500/30 flex items-center justify-center text-rose-400 flex-shrink-0">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            </div>
                        </div>
                        <div class="mt-1.5 pt-1.5 border-t border-[var(--glass-border)] flex items-center justify-between text-[11px] text-secondary">
                            <span>Batal</span>
                            <span class="font-bold text-slate-400 font-mono"><?= $stats['cancelled'] ?> task</span>
                        </div>
                    </div>

                </div>

                <!-- Right 5 cols: 5 Pipeline Steps -->
                <div class="col-span-12 lg:col-span-5 glass-panel flex flex-col justify-between py-2 px-3">
                    <div class="flex items-center justify-between pb-1 mb-1.5 border-b border-[var(--glass-border)]">
                        <div class="flex items-center gap-1.5">
                            <div class="w-1.5 h-1.5 rounded-full bg-blue-500 shadow-sm shadow-blue-500/50"></div>
                            <h2 class="text-[11px] font-bold uppercase tracking-wider text-header">Pipeline Breakdown</h2>
                        </div>
                        <span class="text-[10px] font-mono text-secondary"><?= $stats['total'] ?> tasks tracked</span>
                    </div>

                    <div class="grid grid-cols-5 gap-1.5">
                        
                        <!-- 1. Task Baru -->
                        <div class="pipeline-step">
                            <div class="flex items-center justify-between text-[10px] mb-0.5">
                                <span class="font-bold text-blue-400 truncate">Baru</span>
                                <span class="text-[9px] font-mono text-secondary"><?= ($stats['total'] > 0) ? round(($stats['new'] / $stats['total']) * 100) : 0 ?>%</span>
                            </div>
                            <div class="text-base font-black font-mono text-primary tabular-nums leading-none"><?= $stats['new'] ?></div>
                            <div class="w-full h-1 rounded-full bg-white/10 mt-1 overflow-hidden">
                                <div class="h-full bg-blue-500 rounded-full" style="width: <?= ($stats['total'] > 0) ? ($stats['new'] / $stats['total']) * 100 : 0 ?>%"></div>
                            </div>
                        </div>

                        <!-- 2. Ongoing -->
                        <div class="pipeline-step">
                            <div class="flex items-center justify-between text-[10px] mb-0.5">
                                <span class="font-bold text-amber-400 truncate">Ongoing</span>
                                <span class="text-[9px] font-mono text-secondary"><?= ($stats['total'] > 0) ? round(($stats['ongoing'] / $stats['total']) * 100) : 0 ?>%</span>
                            </div>
                            <div class="text-base font-black font-mono text-primary tabular-nums leading-none"><?= $stats['ongoing'] ?></div>
                            <div class="w-full h-1 rounded-full bg-white/10 mt-1 overflow-hidden">
                                <div class="h-full bg-amber-500 rounded-full" style="width: <?= ($stats['total'] > 0) ? ($stats['ongoing'] / $stats['total']) * 100 : 0 ?>%"></div>
                            </div>
                        </div>

                        <!-- 3. Submitted -->
                        <div class="pipeline-step">
                            <div class="flex items-center justify-between text-[10px] mb-0.5">
                                <span class="font-bold text-purple-400 truncate">Submit</span>
                                <span class="text-[9px] font-mono text-secondary"><?= ($stats['total'] > 0) ? round(($stats['submitted'] / $stats['total']) * 100) : 0 ?>%</span>
                            </div>
                            <div class="text-base font-black font-mono text-primary tabular-nums leading-none"><?= $stats['submitted'] ?></div>
                            <div class="w-full h-1 rounded-full bg-white/10 mt-1 overflow-hidden">
                                <div class="h-full bg-purple-500 rounded-full" style="width: <?= ($stats['total'] > 0) ? ($stats['submitted'] / $stats['total']) * 100 : 0 ?>%"></div>
                            </div>
                        </div>

                        <!-- 4. Approved -->
                        <div class="pipeline-step">
                            <div class="flex items-center justify-between text-[10px] mb-0.5">
                                <span class="font-bold text-emerald-400 truncate">Approve</span>
                                <span class="text-[9px] font-mono text-secondary"><?= ($stats['total'] > 0) ? round(($stats['approved'] / $stats['total']) * 100) : 0 ?>%</span>
                            </div>
                            <div class="text-base font-black font-mono text-primary tabular-nums leading-none"><?= $stats['approved'] ?></div>
                            <div class="w-full h-1 rounded-full bg-white/10 mt-1 overflow-hidden">
                                <div class="h-full bg-emerald-500 rounded-full" style="width: <?= ($stats['total'] > 0) ? ($stats['approved'] / $stats['total']) * 100 : 0 ?>%"></div>
                            </div>
                        </div>

                        <!-- 5. Batal -->
                        <div class="pipeline-step">
                            <div class="flex items-center justify-between text-[10px] mb-0.5">
                                <span class="font-bold text-slate-400 truncate">Batal</span>
                                <span class="text-[9px] font-mono text-secondary"><?= ($stats['total'] > 0) ? round(($stats['cancelled'] / $stats['total']) * 100) : 0 ?>%</span>
                            </div>
                            <div class="text-base font-black font-mono text-primary tabular-nums leading-none"><?= $stats['cancelled'] ?></div>
                            <div class="w-full h-1 rounded-full bg-white/10 mt-1 overflow-hidden">
                                <div class="h-full bg-slate-500 rounded-full" style="width: <?= ($stats['total'] > 0) ? ($stats['cancelled'] / $stats['total']) * 100 : 0 ?>%"></div>
                            </div>
                        </div>

                    </div>
                </div>

            </div>

            <!-- Bottom Row: Deep Analytics Visual Charts Grid (8 cols + 4 cols) -->
            <div class="grid grid-cols-12 gap-2.5 flex-1 min-h-0">
                
                <!-- Left 8 Columns: Throughput & Alokasi Task Tahunan (flex-1 min-h-0) -->
                <div class="col-span-12 lg:col-span-8 glass-panel flex flex-col min-h-0 p-3">
                    <div class="flex flex-wrap justify-between items-center gap-2 mb-2 pb-2 border-b border-[var(--glass-border)] flex-shrink-0">
                        <div class="flex items-center gap-2">
                            <div class="w-2 h-2 rounded-full bg-indigo-500 shadow-sm shadow-indigo-500/50"></div>
                            <div>
                                <h3 class="font-bold text-sm text-header tracking-tight">Throughput & Alokasi Task Tahunan</h3>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <form method="GET" action="" class="flex items-center gap-1.5">
                                <span class="text-xs text-secondary font-medium">Periode Tahun:</span>
                                <select name="year" onchange="this.form.submit()" class="select-pill">
                                    <?php if (empty($available_years)): ?>
                                        <option>No Data</option>
                                    <?php else: ?>
                                        <?php foreach ($available_years as $year): ?>
                                            <option value="<?= $year ?>" <?= ($year == $selected_year) ? 'selected' : '' ?>><?= $year ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </form>
                            <span class="text-[10px] font-mono font-medium px-2 py-0.5 rounded bg-white/5 border border-white/10 text-secondary hidden sm:inline-block">
                                52 Minggu (W01 - W52)
                            </span>
                        </div>
                    </div>
                    <div class="flex-1 min-h-0 w-full relative">
                        <canvas id="weeklyTaskChart"></canvas>
                    </div>
                </div>

                <!-- Right 4 Columns: Weekly PIC Donut & World Time (flex flex-col min-h-0) -->
                <div class="col-span-12 lg:col-span-4 flex flex-col gap-2.5 min-h-0">
                    
                    <!-- Upper: Weekly PIC Donut Card (flex-1 min-h-0) -->
                    <div class="glass-panel flex flex-col min-h-0 flex-1 p-3">
                        <div class="flex items-center justify-between pb-1.5 mb-1.5 border-b border-[var(--glass-border)] flex-shrink-0 gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1.5">
                                    <h3 class="font-bold text-xs text-header truncate">Beban Kerja PIC</h3>
                                    <span id="workload-week-tag" class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-indigo-500/15 text-indigo-400 border border-indigo-500/30">
                                        <?= htmlspecialchars($all_weeks_pic_data[0]['tag'] ?? 'Minggu Ini') ?>
                                    </span>
                                </div>
                                <p id="workload-date-range" class="text-[10px] font-mono text-secondary mt-0.5 truncate">
                                    <?= htmlspecialchars($all_weeks_pic_data[0]['date_range'] ?? ($start_of_week ? $start_of_week->format('d M') : 'N/A')) ?>
                                </p>
                            </div>
                            
                            <div class="flex items-center gap-1.5 shrink-0">
                                <!-- Week Navigation Quick Actions (Prev / Now / Next) -->
                                <div class="flex items-center bg-white/5 border border-[var(--glass-border)] rounded-lg p-0.5 gap-0.5">
                                    <button type="button" id="btn-prev-week" class="p-1 w-6 h-6 flex items-center justify-center rounded text-secondary hover:text-primary hover:bg-white/10 active:scale-95 transition" title="Lihat Minggu Sebelumnya">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                                    </button>
                                    <button type="button" id="btn-curr-week" class="px-1.5 h-6 text-[10px] font-semibold text-secondary hover:text-primary hover:bg-white/10 rounded active:scale-95 transition hidden" title="Kembali ke Minggu Ini">
                                        Now
                                    </button>
                                    <button type="button" id="btn-next-week" class="p-1 w-6 h-6 flex items-center justify-center rounded text-secondary opacity-40 cursor-not-allowed" title="Minggu Berikutnya" disabled>
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                                    </button>
                                </div>
                                
                                <span id="workload-task-count" class="text-[10px] font-bold font-mono px-2 py-0.5 rounded-full bg-cyan-500/15 text-cyan-400 border border-cyan-500/30">
                                    <?= (int)($all_weeks_pic_data[0]['total_tasks'] ?? count($tasks_this_week ?? [])) ?> tasks
                                </span>
                            </div>
                        </div>
                        <div class="flex-1 min-h-0 w-full relative flex items-center justify-center">
                            <canvas id="picPieChart"></canvas>
                        </div>
                    </div>

                    <!-- Lower: World Operations Clocks Panel (flex-shrink-0) -->
                    <div class="glass-panel flex flex-col flex-shrink-0 p-2.5">
                        <div class="flex items-center justify-between pb-1 mb-1.5 border-b border-[var(--glass-border)]">
                            <div class="flex items-center gap-1.5">
                                <div class="w-1.5 h-1.5 rounded-full bg-emerald-500 shadow-sm shadow-emerald-500/50"></div>
                                <h3 class="font-bold text-xs text-header">Global Operations Time</h3>
                            </div>
                            <span class="text-[10px] text-secondary font-mono">Live</span>
                        </div>
                        <div id="world-clocks" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-2 gap-1.5"></div>
                    </div>

                </div>

            </div>

        </div>
    </main>

    <!-- Task Modal (Add/Edit) -->
    <div id="task-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 backdrop-blur-sm hidden" style="backdrop-filter: blur(8px);">
        <div class="modal-content-wrapper rounded-2xl shadow-2xl p-4 sm:p-5 w-full max-w-5xl mx-3">
            <form id="task-form" action="handler.php" method="POST">
                <div class="flex justify-between items-center mb-3 pb-2 border-b border-[var(--glass-border)]">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-blue-500 shadow-sm shadow-blue-500/50"></div>
                        <h2 id="modal-title" class="text-base font-bold text-header">Tambah Task Baru</h2>
                    </div>
                    <div class="flex justify-end items-center gap-2">
                        <button type="button" onclick="closeModal()" class="modal-btn-cancel">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            <span>Batal</span>
                        </button>
                        <button type="submit" class="modal-btn-save">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span>Simpan Task</span>
                        </button>
                    </div>
                </div>
                <input type="hidden" name="id" id="task-id">
                <input type="hidden" name="action" id="form-action" value="create_gba_task">
                <?php include 'gba_task_form.php'; ?>
            </form>
        </div>
    </div>

<script>
    // --- ANIMATION & THEME LOGIC ---
    const canvas = document.getElementById('neural-canvas'), ctx = canvas.getContext('2d');
    let particles = [], hue = 215;
    const mouse = { x: undefined, y: undefined, radius: 120 };

    function setCanvasSize(){ canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
    setCanvasSize();

    window.addEventListener('mousemove', e => { mouse.x = e.clientX; mouse.y = e.clientY; });
    window.addEventListener('mouseout', () => { mouse.x = undefined; mouse.y = undefined; });
    window.addEventListener('resize', () => { setCanvasSize(); init(particleCount); renderCharts(); });

    class Particle {
        constructor() {
            this.x = Math.random() * canvas.width;
            this.y = Math.random() * canvas.height;
            this.vx = (Math.random() - 0.5) * 1.2;
            this.vy = (Math.random() - 0.5) * 1.2;
            this.size = Math.random() * 2 + 1;
        }
        update() {
            this.x += this.vx; this.y += this.vy;
            if (this.x < 0 || this.x > canvas.width) this.vx *= -1;
            if (this.y < 0 || this.y > canvas.height) this.vy *= -1;
        }
        draw() {
            ctx.fillStyle = `hsl(${hue}, 100%, 75%)`;
            ctx.beginPath();
            ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
            ctx.fill();
        }
    }

    const particleCount = window.innerWidth > 768 ? 120 : 60;
    function init(num) { particles = []; for (let i = 0; i < num; i++) particles.push(new Particle()); }
    init(particleCount);

    function handleParticles() {
        if (mouse.x !== undefined && mouse.y !== undefined) {
            ctx.beginPath();
            let gradient = ctx.createRadialGradient(mouse.x, mouse.y, 0, mouse.x, mouse.y, mouse.radius);
            gradient.addColorStop(0, `hsla(${hue}, 100%, 70%, 0.15)`);
            gradient.addColorStop(1, 'transparent');
            ctx.fillStyle = gradient;
            ctx.arc(mouse.x, mouse.y, mouse.radius, 0, Math.PI * 2);
            ctx.fill();
        }

        for (let i = 0; i < particles.length; i++) {
            particles[i].update();
            particles[i].draw();
            for (let j = i; j < particles.length; j++) {
                const dx = particles[i].x - particles[j].x;
                const dy = particles[i].y - particles[j].y;
                const dist = Math.sqrt(dx * dx + dy * dy);
                if (dist < 110) {
                    ctx.beginPath();
                    ctx.strokeStyle = `hsla(${hue}, 100%, 80%, ${1 - dist / 110})`;
                    ctx.lineWidth = 0.8;
                    ctx.moveTo(particles[i].x, particles[i].y);
                    ctx.lineTo(particles[j].x, particles[j].y);
                    ctx.stroke();
                    ctx.closePath();
                }
            }
        }
    }

    function animate() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hue = (hue + 0.3) % 360;
        handleParticles();
        requestAnimationFrame(animate);
    }
    animate();

    // --- CHART & THEME LOGIC ---
    let currentTheme = document.documentElement.classList.contains('light') ? 'light' : 'dark';
    const themeToggleBtn = document.getElementById('theme-toggle');

    const allWeeksPicData = <?= json_encode($all_weeks_pic_data ?? []) ?>;
    const yearlyChartData = <?= json_encode($weekly_chart_data) ?>;
    const picColors = <?= isset($pic_colors) ? json_encode($pic_colors) : '[]' ?>;
    let currentWeekOffset = 0;
    const maxWeekOffset = <?= count($all_weeks_pic_data ?? []) > 0 ? count($all_weeks_pic_data) - 1 : 0 ?>;

    const modal = document.getElementById('task-modal'), modalTitle = document.getElementById('modal-title'), taskForm = document.getElementById('task-form'); 
    let quill;

    function getChartColors() {
        return {
            ticksColor: currentTheme === 'light' ? '#475569' : '#94a3b8',
            gridColor: currentTheme === 'light' ? 'rgba(0,0,0,0.05)' : 'rgba(255,255,255,0.07)',
            borderColor: currentTheme === 'light' ? '#f8fafc' : '#0f172a'
        };
    }

    function createPicDoughnutChart() {
        if (allWeeksPicData && allWeeksPicData[currentWeekOffset]) {
            renderPicDoughnutChartWithData(allWeeksPicData[currentWeekOffset].distribution);
        } else {
            renderPicDoughnutChartWithData({});
        }
    }

    function renderPicDoughnutChartWithData(distData) {
        const ctx = document.getElementById('picPieChart');
        if (!ctx) return;
        if (window.picDoughnutChart instanceof Chart) window.picDoughnutChart.destroy();
        
        const labels = Object.keys(distData || {});
        const data = Object.values(distData || {});
        
        const existingPlaceholder = ctx.parentNode.querySelector('.no-data-msg');
        if (existingPlaceholder) existingPlaceholder.remove();

        if (labels.length === 0) {
            ctx.style.display = 'none';
            const placeholder = document.createElement('div');
            placeholder.className = 'no-data-msg text-center text-secondary py-4';
            placeholder.innerHTML = '<p class="text-xs">Tidak ada data task pada periode minggu ini.</p>';
            ctx.parentNode.appendChild(placeholder);
            return;
        }

        ctx.style.display = 'block';
        const colors = labels.map(l => picColors[l] || '#38bdf8');

        window.picDoughnutChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Tasks',
                    data: data,
                    backgroundColor: colors,
                    borderColor: getChartColors().borderColor,
                    borderWidth: 2,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: getChartColors().ticksColor,
                            padding: 8,
                            boxWidth: 8,
                            boxHeight: 8,
                            usePointStyle: true,
                            font: { size: 10, family: 'Inter', weight: '500' }
                        }
                    }
                }
            }
        });
    }

    function updateWorkloadView(offset) {
        if (!allWeeksPicData || !allWeeksPicData[offset]) return;
        currentWeekOffset = offset;
        const weekInfo = allWeeksPicData[offset];
        
        const tagEl = document.getElementById('workload-week-tag');
        const rangeEl = document.getElementById('workload-date-range');
        const countEl = document.getElementById('workload-task-count');
        const btnPrev = document.getElementById('btn-prev-week');
        const btnNext = document.getElementById('btn-next-week');
        const btnCurr = document.getElementById('btn-curr-week');

        if (tagEl) {
            tagEl.textContent = weekInfo.tag;
            if (offset === 0) {
                tagEl.className = 'text-[9px] font-bold px-1.5 py-0.5 rounded bg-indigo-500/15 text-indigo-400 border border-indigo-500/30';
            } else {
                tagEl.className = 'text-[9px] font-bold px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-400 border border-amber-500/30';
            }
        }
        if (rangeEl) rangeEl.textContent = weekInfo.date_range;
        if (countEl) countEl.textContent = `${weekInfo.total_tasks} tasks`;
        
        if (btnPrev) {
            const isPrevDisabled = (currentWeekOffset >= maxWeekOffset);
            btnPrev.disabled = isPrevDisabled;
            btnPrev.classList.toggle('opacity-40', isPrevDisabled);
            btnPrev.classList.toggle('cursor-not-allowed', isPrevDisabled);
        }
        if (btnNext) {
            const isNextDisabled = (currentWeekOffset <= 0);
            btnNext.disabled = isNextDisabled;
            btnNext.classList.toggle('opacity-40', isNextDisabled);
            btnNext.classList.toggle('cursor-not-allowed', isNextDisabled);
        }
        if (btnCurr) {
            btnCurr.classList.toggle('hidden', currentWeekOffset === 0);
        }
        
        renderPicDoughnutChartWithData(weekInfo.distribution);
    }

    const btnPrevWeek = document.getElementById('btn-prev-week');
    const btnNextWeek = document.getElementById('btn-next-week');
    const btnCurrWeek = document.getElementById('btn-curr-week');

    if (btnPrevWeek) {
        btnPrevWeek.addEventListener('click', () => {
            if (currentWeekOffset < maxWeekOffset) {
                updateWorkloadView(currentWeekOffset + 1);
            }
        });
    }

    if (btnNextWeek) {
        btnNextWeek.addEventListener('click', () => {
            if (currentWeekOffset > 0) {
                updateWorkloadView(currentWeekOffset - 1);
            }
        });
    }

    if (btnCurrWeek) {
        btnCurrWeek.addEventListener('click', () => {
            updateWorkloadView(0);
        });
    }

    function createYearlyTaskChart() {
        const ctx = document.getElementById('weeklyTaskChart');
        if (!ctx || !yearlyChartData.labels || yearlyChartData.labels.length === 0) return;
        if (window.yearlyTaskChart instanceof Chart) window.yearlyTaskChart.destroy();

        window.yearlyTaskChart = new Chart(ctx, {
            type: 'bar',
            data: yearlyChartData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        stacked: true,
                        ticks: {
                            color: getChartColors().ticksColor,
                            font: { size: 10, family: 'Inter', weight: '500' },
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 26
                        },
                        grid: { color: getChartColors().gridColor }
                    },
                    y: {
                        stacked: false,
                        beginAtZero: true,
                        ticks: {
                            color: getChartColors().ticksColor,
                            font: { size: 10, family: 'Inter' },
                            precision: 0
                        },
                        grid: { color: getChartColors().gridColor }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            color: getChartColors().ticksColor,
                            boxWidth: 8,
                            boxHeight: 8,
                            usePointStyle: true,
                            font: { size: 10, family: 'Inter', weight: '500' },
                            padding: 6
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        padding: 10,
                        cornerRadius: 8,
                        titleFont: { size: 11, weight: 'bold' },
                        bodyFont: { size: 10 }
                    }
                },
                interaction: {
                    mode: 'index',
                    intersect: false
                }
            }
        });
    }

    function renderCharts() {
        createPicDoughnutChart();
        createYearlyTaskChart();
    }

    function applyTheme(isLight) {
        currentTheme = isLight ? 'light' : 'dark';
        document.documentElement.classList.toggle('light', isLight);
        const lightIcon = document.getElementById('theme-toggle-light-icon');
        const darkIcon = document.getElementById('theme-toggle-dark-icon');
        if (lightIcon) lightIcon.classList.toggle('hidden', !isLight);
        if (darkIcon) darkIcon.classList.toggle('hidden', isLight);
        renderCharts();
    }

    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const isCurrentlyLight = document.documentElement.classList.contains('light');
            const newIsLight = !isCurrentlyLight;
            localStorage.setItem('theme', newIsLight ? 'light' : 'dark');
            applyTheme(newIsLight);
        });
    }

    // Modal helpers
    function openAddModal() {
        if (!taskForm) return;
        taskForm.reset();
        if (modalTitle) modalTitle.innerText = 'Tambah Task Baru';
        if (taskForm.elements['action']) taskForm.elements['action'].value = 'create_gba_task';
        if (taskForm.elements['id']) taskForm.elements['id'].value = '';
        const today = new Date().toISOString().slice(0, 10);
        const reqDateEl = document.getElementById('request_date');
        const deadEl = document.getElementById('deadline');
        const signEl = document.getElementById('sign_off_date');
        if (reqDateEl) reqDateEl.value = today;
        const deadlineDate = calculateWorkingDays(today, 7);
        if (deadEl) deadEl.value = deadlineDate;
        if (signEl) signEl.value = deadlineDate;
        setupQuill('');
        updateChecklistVisibility();
        if (modal) modal.classList.remove('modal-closing', 'hidden');
    }
    let isTaskModalClosing = false;
    function closeModal() {
        if (!modal || modal.classList.contains('hidden') || isTaskModalClosing) return;
        isTaskModalClosing = true;
        modal.classList.add('modal-closing');
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('modal-closing');
            isTaskModalClosing = false;
        }, 160);
    }
    
    // Close on backdrop click with smooth motion
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal();
        });
    }

    // Quick Action: Escape key to close modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' || e.key === 'Esc') {
            if (modal && !modal.classList.contains('hidden')) {
                closeModal();
            }
        }
    });
    function calculateWorkingDays(startDate, daysToAdd) {
        let currentDate = new Date(startDate);
        let addedDays = 0;
        while (addedDays < daysToAdd) {
            currentDate.setDate(currentDate.getDate() + 1);
            if (currentDate.getDay() !== 0 && currentDate.getDay() !== 6) addedDays++;
        }
        return currentDate.toISOString().slice(0, 10);
    }
    function setupQuill(content) {
        const notesEditor = document.getElementById('notes-editor');
        if (!notesEditor) return;
        if (!quill && typeof Quill === 'function') {
            quill = new Quill('#notes-editor', {
                theme: 'snow',
                modules: { toolbar: [['bold', 'italic'], ['link'], [{ 'list': 'ordered' }, { 'list': 'bullet' }]] }
            });
        }
        if (quill) quill.root.innerHTML = content;
    }
    if (taskForm) {
        taskForm.addEventListener('submit', () => {
            const notesHidden = document.getElementById('notes-hidden-input');
            if (notesHidden && quill) notesHidden.value = quill.root.innerHTML;
        });
    }
    const testPlanSelect = document.getElementById('test_plan_type');
    if (testPlanSelect) {
        testPlanSelect.addEventListener('change', updateChecklistVisibility);
    }
    function updateChecklistVisibility() {
        const testPlan = testPlanSelect ? testPlanSelect.value : '';
        const placeholder = document.getElementById('checklist-placeholder');
        let checklistVisible = false;
        document.querySelectorAll('[id^="checklist-container-"]').forEach(el => {
            const planName = el.id.replace('checklist-container-', '').replace(/_/g, ' ');
            if (planName === testPlan) {
                el.classList.remove('hidden');
                checklistVisible = true;
            } else {
                el.classList.add('hidden');
            }
        });
        if (placeholder) placeholder.style.display = checklistVisible ? 'none' : 'block';
    }

    // --- WORLD TIME COMPARISON (2-Column Compact Grid) ---
    const clocksContainer = document.getElementById('world-clocks');
    const timezones = [
        { name: 'Indonesia', tz: 'Asia/Jakarta', flag: '🇮🇩', offset: 'WIB' },
        { name: 'S. Korea', tz: 'Asia/Seoul', flag: '🇰🇷', offset: 'KST' },
        { name: 'Vietnam', tz: 'Asia/Ho_Chi_Minh', flag: '🇻🇳', offset: 'ICT' },
        { name: 'China', tz: 'Asia/Shanghai', flag: '🇨🇳', offset: 'CST' },
        { name: 'India', tz: 'Asia/Kolkata', flag: '🇮🇳', offset: 'IST' },
        { name: 'Brazil', tz: 'America/Sao_Paulo', flag: '🇧🇷', offset: 'BRT' }
    ];

    function pad(n) { return n < 10 ? '0' + n : n; }

    function updateClocks() {
        if (!clocksContainer) return;
        let clocksHTML = '';
        const now = new Date();
        timezones.forEach(zone => {
            try {
                const localTime = new Date(now.toLocaleString('en-US', { timeZone: zone.tz }));
                const hours = localTime.getHours();
                const isWorkingHour = hours >= 9 && hours < 18;
                const time = `${pad(hours)}:${pad(localTime.getMinutes())}:${pad(localTime.getSeconds())}`;
                const statusDot = isWorkingHour 
                    ? '<span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>'
                    : '<span class="w-1.5 h-1.5 rounded-full bg-slate-500"></span>';

                clocksHTML += `
                    <div class="tz-card">
                        <div class="flex items-center gap-1.5 overflow-hidden">
                            <span class="text-sm select-none leading-none">${zone.flag}</span>
                            <div class="truncate">
                                <div class="text-[11px] font-bold text-primary flex items-center gap-1 leading-tight">
                                    <span class="truncate">${zone.name}</span>
                                    ${statusDot}
                                </div>
                                <div class="text-[9px] text-secondary font-mono leading-none">${zone.offset}</div>
                            </div>
                        </div>
                        <div class="text-right flex-shrink-0 pl-1">
                            <div class="font-mono text-xs font-bold text-primary tabular-nums tracking-tight">${time}</div>
                        </div>
                    </div>
                `;
            } catch (e) {
                console.error("Could not format time for timezone:", zone.tz);
            }
        });
        clocksContainer.innerHTML = clocksHTML;
    }

    document.addEventListener('DOMContentLoaded', () => {
        const savedTheme = localStorage.getItem('theme');
        applyTheme(savedTheme === 'light');
        updateClocks();
        setInterval(updateClocks, 1000);
    });
</script>
</body>
</html>