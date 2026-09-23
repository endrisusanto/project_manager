<?php
// 1. INISIALISASI
require_once "config.php";
require_once "session.php"; 
require_once "marketing_name_mapper.php";
$active_page = 'monthly_calendar';

// --- FUNGSI: Mengambil Data Hari Libur dari API dengan Caching ---
function fetchIndonesianHolidays($year) {
    $api_url = "https://api-harilibur.vercel.app/api?year=" . $year;
    $cache_file = __DIR__ . '/holidays_cache_' . $year . '.json';
    $cache_lifetime = 60 * 60 * 24 * 30; // 30 hari
    $response = false;

    // 1. Coba baca dari cache jika masih valid
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_lifetime)) {
        $response = @file_get_contents($cache_file);
    }
    
    // 2. Jika tidak ada cache atau cache kedaluwarsa, coba ambil dari API
    if (!$response) {
        $api_failed = false;
        $api_response = false;
        
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            $api_response = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200 || $api_response === FALSE) { $api_failed = true; }
            curl_close($ch);
            $response = $api_response;
        } else {
            $response = @file_get_contents($api_url);
            if ($response === FALSE) { $api_failed = true; }
        }

        if ($api_failed && file_exists($cache_file)) {
            $response = @file_get_contents($cache_file);
        } else if (!$api_failed && $response) {
            @file_put_contents($cache_file, $response);
        }
    }

    $holidays = [];
    $data = json_decode((string)$response, true);
    
    if (is_array($data) && !empty($data)) {
        foreach ($data as $item) {
            if (isset($item['holiday_date']) && isset($item['holiday_name']) && !empty($item['holiday_date'])) {
                $date_key = date('Y-m-d', strtotime($item['holiday_date'])); 
                $name_lower = strtolower($item['holiday_name']);
                if (!empty($item['holiday_name']) && strpos($name_lower, 'akhir pekan') === false && strpos($name_lower, 'tidak ada') === false) {
                    $holidays[$date_key] = $item['holiday_name'];
                }
            }
        }
    }
    return $holidays;
}

// 2. LOGIKA PENANGANAN WAKTU
date_default_timezone_set('Asia/Jakarta');
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$timestamp = strtotime($current_month . '-01');
$current_year = date('Y', $timestamp);
$current_mon = date('m', $timestamp);

$start_date = date('Y-m-01', $timestamp);
$end_date = date('Y-m-t', $timestamp);

$prev_month = date('Y-m', strtotime($start_date . ' -1 month'));
$next_month = date('Y-m', strtotime($start_date . ' +1 month'));

// Hitung hari pertama kalender (awal minggu pertama)
$first_day_of_month = date('w', $timestamp); // 0 (Sun) to 6 (Sat)
$start_day_timestamp = strtotime("-" . $first_day_of_month . " days", $timestamp);

$tasks_by_date = [];
$total_month_tasks = 0;
$total_month_notes = 0;

$indonesian_holidays = fetchIndonesianHolidays($current_year);

// 3A. LOGIKA PENGAMBILAN DATA GBA TASKS
$sql_tasks = "SELECT t.*, u.username, u.profile_picture 
        FROM gba_tasks t 
        LEFT JOIN users u ON t.pic_email = u.email
        WHERE (t.deadline BETWEEN ? AND ?) 
           OR (t.request_date BETWEEN ? AND ?)
           OR (t.submission_date BETWEEN ? AND ?)";
$stmt_tasks = $conn->prepare($sql_tasks);

if ($stmt_tasks) {
    $stmt_tasks->bind_param("ssssss", $start_date, $end_date, $start_date, $end_date, $start_date, $end_date);
    $stmt_tasks->execute();
    $tasks_result = $stmt_tasks->get_result();
    
    if ($tasks_result) {
        while ($task = $tasks_result->fetch_assoc()) {
            $dates_to_mark = [];
            if ($task['deadline']) $dates_to_mark[] = $task['deadline'];
            
            $unique_dates = array_unique(array_filter($dates_to_mark, function($date) use ($start_date, $end_date) {
                return $date >= $start_date && $date <= $end_date;
            }));
            
            foreach ($unique_dates as $date) {
                if (!isset($tasks_by_date[$date])) {
                    $tasks_by_date[$date] = [];
                }
                $task['type'] = 'task';
                $task['json_data'] = json_encode($task, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
                $tasks_by_date[$date][] = $task;
                $total_month_tasks++;
            }
        }
    }
    $stmt_tasks->close();
}

// 3B. LOGIKA PENGAMBILAN DATA USER NOTES
$sql_notes = "SELECT n.*, u.profile_picture, u.username 
              FROM user_notes n 
              LEFT JOIN users u ON n.user_email = u.email 
              WHERE n.note_date BETWEEN ? AND ?";
$stmt_notes = $conn->prepare($sql_notes);

if ($stmt_notes) {
    $stmt_notes->bind_param("ss", $start_date, $end_date);
    $stmt_notes->execute();
    $notes_result = $stmt_notes->get_result();
    
    if ($notes_result) {
        while ($note = $notes_result->fetch_assoc()) {
            $date = $note['note_date'];
            if (!isset($tasks_by_date[$date])) {
                $tasks_by_date[$date] = [];
            }
            $note['type'] = 'note';
            $note['json_data'] = json_encode($note, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
            $tasks_by_date[$date][] = $note;
            $total_month_notes++;
        }
    }
    $stmt_notes->close();
}

// 4. SORTING: note muncul sebelum task
foreach ($tasks_by_date as $date => $items) {
    usort($tasks_by_date[$date], function($a, $b) {
        if ($a['type'] === 'note' && $b['type'] === 'task') return -1;
        if ($a['type'] === 'task' && $b['type'] === 'note') return 1;
        return 0;
    });
}

// Daftar user untuk dropdown
$users_result = $conn->query("SELECT email, username FROM users ORDER BY username ASC");
$users_list = [];
if ($users_result) {
    while($user_row = $users_result->fetch_assoc()) {
        $users_list[] = $user_row;
    }
}

// Helper untuk status badge styles
function getStatusBadgeStyles($status) {
    switch ($status) {
        case 'Approved':
        case 'Passed':
            return 'bg-emerald-500/15 border-emerald-500/30 text-emerald-400';
        case 'Submitted':
            return 'bg-purple-500/15 border-purple-500/30 text-purple-400';
        case 'Test Ongoing':
        case 'Pending Feedback':
        case 'Feedback Sent':
            return 'bg-amber-500/15 border-amber-500/30 text-amber-400';
        case 'Task Baru':
            return 'bg-blue-500/15 border-blue-500/30 text-blue-400';
        case 'Batal':
            return 'bg-slate-500/15 border-slate-500/30 text-slate-400';
        default:
            return 'bg-slate-500/15 border-slate-500/30 text-slate-400';
    }
}

function getNotePriorityStyles($priority) {
    switch ($priority) {
        case 'High':
            return 'bg-rose-500/15 border-rose-500/30 text-rose-400';
        case 'Medium':
            return 'bg-indigo-500/15 border-indigo-500/30 text-indigo-400';
        default:
            return 'bg-slate-500/15 border-slate-500/30 text-slate-400';
    }
}

function getPicInitials($email) {
    if (empty($email)) return '?';
    $parts = explode('@', $email);
    return strtoupper(substr($parts[0], 0, 1));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Schedule & Calendar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['class', '.never-match-dark']
        }
    </script>
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
            --modal-bg: rgba(15, 23, 42, 0.94);
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
            --modal-bg: rgba(255, 255, 255, 0.98);
            --modal-border: rgba(0, 0, 0, 0.1);
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            overflow: hidden;
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
            gap: 0.625rem;
        }

        .glass-panel {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--card-border);
            border-radius: 0.875rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .themed-input {
            background-color: var(--input-bg);
            border: 1px solid var(--input-border);
            color: var(--text-primary);
            border-radius: 0.5rem;
            outline: none;
            transition: all 0.15s ease;
        }
        .themed-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25);
        }

        /* Calendar Grid */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            grid-template-rows: auto repeat(6, 1fr);
            height: 100%;
            width: 100%;
        }

        .day-label {
            padding: 0.4rem 0.5rem;
            font-size: 0.75rem;
            font-weight: 700;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-right: 1px solid var(--glass-border);
            border-bottom: 1px solid var(--glass-border);
            background: rgba(59, 130, 246, 0.08);
            color: var(--text-secondary);
        }
        .day-label:last-child {
            border-right: none;
        }
        .day-label.weekend-col {
            background: rgba(244, 63, 94, 0.06);
            color: #f43f5e;
        }

        .date-cell {
            padding: 0.35rem;
            border-right: 1px solid var(--glass-border);
            border-bottom: 1px solid var(--glass-border);
            position: relative;
            background: rgba(255, 255, 255, 0.015);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: background-color 0.15s ease;
            min-height: 0;
        }
        .date-cell:nth-child(7n) {
            border-right: none;
        }
        .date-cell:hover:not(.other-month) {
            background: rgba(59, 130, 246, 0.06) !important;
        }
        html.light .date-cell:hover:not(.other-month) {
            background: rgba(59, 130, 246, 0.08) !important;
        }

        .date-cell.weekend {
            background: rgba(244, 63, 94, 0.025);
        }
        .date-cell.holiday {
            background: rgba(245, 158, 11, 0.04);
        }
        .date-cell.today {
            box-shadow: inset 0 0 0 2px #3b82f6;
            background: rgba(59, 130, 246, 0.04) !important;
        }

        .date-cell.other-month {
            opacity: 0.38;
            background: rgba(0, 0, 0, 0.05);
        }

        /* Holiday Tag */
        .holiday-tag {
            font-size: 0.625rem;
            font-weight: 600;
            color: #f59e0b;
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.25);
            padding: 0.1rem 0.35rem;
            border-radius: 0.25rem;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }

        /* Task & Note Item Pills */
        .cal-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.15rem 0.35rem;
            border-radius: 0.375rem;
            font-size: 0.6875rem;
            font-weight: 600;
            border: 1px solid transparent;
            margin-bottom: 0.2rem;
            cursor: pointer;
            transition: transform 0.12s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.12s ease;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex-shrink: 0;
            user-select: none;
        }
        .cal-item:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px -2px rgba(0, 0, 0, 0.25);
        }

        .item-list-container {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            padding-right: 0.1rem;
        }
        .item-list-container::-webkit-scrollbar {
            width: 3px;
        }
        .item-list-container::-webkit-scrollbar-thumb {
            background: var(--glass-border);
            border-radius: 4px;
        }

        .avatar-mini {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
            font-size: 0.5rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #3b82f6;
            color: #fff;
            font-weight: 700;
        }

        .pulsing-note-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
            background: #ef4444;
            box-shadow: 0 0 6px #ef4444;
        }

        /* Quick Add Button inside Date Cell on Hover */
        .cell-add-btn {
            opacity: 0;
            transform: scale(0.85);
            transition: opacity 0.15s ease, transform 0.15s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .date-cell:hover .cell-add-btn {
            opacity: 1;
            transform: scale(1);
        }

        .modal-content-wrapper { 
            background: var(--modal-bg); 
            border: 1px solid var(--modal-border); 
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Pill Buttons */
        .btn-nav-circle {
            width: 2rem;
            height: 2rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            color: var(--text-secondary);
            transition: all 0.15s ease;
        }
        .btn-nav-circle:hover {
            color: var(--text-primary);
            border-color: rgba(99, 102, 241, 0.4);
            background: rgba(99, 102, 241, 0.1);
        }

        .filter-toggle-pill {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.3rem 0.625rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid var(--card-border);
            background: var(--card-bg);
            color: var(--text-secondary);
            transition: all 0.15s ease;
            user-select: none;
        }
        .filter-toggle-pill.active {
            border-color: rgba(59, 130, 246, 0.5);
            background: rgba(59, 130, 246, 0.12);
            color: #38bdf8;
        }
    </style>
</head>
<body class="h-screen flex flex-col">
    <canvas id="neural-canvas"></canvas>

    <?php include 'header.php'; ?>

    <main class="main-container flex-grow">
        
        <!-- Header HUD Control Bar -->
        <div class="glass-panel p-2.5 flex flex-wrap items-center justify-between gap-2.5 flex-shrink-0">
            
            <!-- Left: Title & Month Total Count -->
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-blue-500/15 border border-blue-500/30 flex items-center justify-center text-blue-400 flex-shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </div>
                <div>
                    <h1 class="text-base font-bold text-header tracking-tight flex items-center gap-2">
                        <span><?= date('F Y', $timestamp) ?></span>
                        <span class="text-[11px] font-mono font-medium px-2 py-0.5 rounded-full bg-blue-500/15 text-blue-400 border border-blue-500/30">
                            <?= $total_month_tasks ?> Task • <?= $total_month_notes ?> Note
                        </span>
                    </h1>
                </div>
            </div>

            <!-- Center: Date Pagination Cluster -->
            <div class="flex items-center gap-1.5">
                <a href="?month=<?= $prev_month ?>" class="btn-nav-circle" title="Bulan Sebelumnya">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
                
                <form method="GET" action="" class="flex items-center">
                    <input type="month" name="month" value="<?= $current_month ?>" onchange="this.form.submit()" class="themed-input py-1 px-2.5 text-xs font-semibold font-mono cursor-pointer">
                </form>

                <a href="?month=<?= $next_month ?>" class="btn-nav-circle" title="Bulan Berikutnya">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>

                <?php if ($current_month !== date('Y-m')): ?>
                    <a href="?month=<?= date('Y-m') ?>" class="px-2 py-1 text-xs font-semibold rounded-md border border-[var(--glass-border)] bg-[var(--card-bg)] text-secondary hover:text-primary transition-colors ml-1">
                        Hari Ini
                    </a>
                <?php endif; ?>
            </div>

            <!-- Right: Filter Toggles -->
            <div class="flex items-center gap-1.5">
                <div id="filter-task-pill" class="filter-toggle-pill" onclick="toggleFilter('task')">
                    <span class="w-2 h-2 rounded-full bg-blue-400"></span>
                    <span>Task</span>
                    <span class="font-mono text-[10px] opacity-75">(<?= $total_month_tasks ?>)</span>
                </div>
                <div id="filter-note-pill" class="filter-toggle-pill" onclick="toggleFilter('note')">
                    <span class="w-2 h-2 rounded-full bg-rose-400"></span>
                    <span>Catatan</span>
                    <span class="font-mono text-[10px] opacity-75">(<?= $total_month_notes ?>)</span>
                </div>
            </div>

        </div>

        <!-- Alerts -->
        <?php if(isset($_GET['success'])): ?>
            <div id="success-alert" class="bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 text-xs px-3 py-2 rounded-lg flex items-center justify-between flex-shrink-0">
                <span class="flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <?= htmlspecialchars($_GET['success']); ?>
                </span>
                <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-emerald-200">✕</button>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_GET['error'])): ?>
            <div id="error-alert" class="bg-rose-500/15 border border-rose-500/30 text-rose-300 text-xs px-3 py-2 rounded-lg flex items-center justify-between flex-shrink-0">
                <span class="flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <?= htmlspecialchars($_GET['error']); ?>
                </span>
                <button onclick="this.parentElement.remove()" class="text-rose-400 hover:text-rose-200">✕</button>
            </div>
        <?php endif; ?>

        <!-- Main Calendar Card -->
        <div class="glass-panel flex-1 min-h-0 overflow-hidden flex flex-col p-0">
            <div class="calendar-grid flex-1">
                
                <!-- Day Column Headers -->
                <?php 
                $day_names = [
                    ['name' => 'Min', 'full' => 'Minggu', 'weekend' => true],
                    ['name' => 'Sen', 'full' => 'Senin', 'weekend' => false],
                    ['name' => 'Sel', 'full' => 'Selasa', 'weekend' => false],
                    ['name' => 'Rab', 'full' => 'Rabu', 'weekend' => false],
                    ['name' => 'Kam', 'full' => 'Kamis', 'weekend' => false],
                    ['name' => 'Jum', 'full' => 'Jumat', 'weekend' => false],
                    ['name' => 'Sab', 'full' => 'Sabtu', 'weekend' => true]
                ]; 
                ?>
                <?php foreach ($day_names as $day): ?>
                    <div class="day-label <?= $day['weekend'] ? 'weekend-col' : '' ?>"><?= $day['name'] ?></div>
                <?php endforeach; ?>

                <!-- Date Cells (42 cells: 6 weeks x 7 days) -->
                <?php 
                $date_tracker = new DateTime(date('Y-m-d', $start_day_timestamp));
                $today_date = date('Y-m-d');
                $num_cells = 42;
                
                for ($i = 0; $i < $num_cells; $i++):
                    $current_date_str = $date_tracker->format('Y-m-d');
                    $is_weekend = ($date_tracker->format('N') >= 6);
                    $is_other_month = ($date_tracker->format('m') != $current_mon);
                    $is_today = ($current_date_str == $today_date);
                    $is_holiday = isset($indonesian_holidays[$current_date_str]);
                    $holiday_note = $is_holiday ? $indonesian_holidays[$current_date_str] : null;
                    
                    $cell_classes = "date-cell";
                    if ($is_weekend) $cell_classes .= " weekend";
                    if ($is_other_month) $cell_classes .= " other-month";
                    if ($is_today) $cell_classes .= " today";
                    if ($is_holiday) $cell_classes .= " holiday";
                ?>
                
                <div 
                    class="<?= $cell_classes ?>" 
                    data-date="<?= $current_date_str ?>"
                    onclick="openDateCellAction(this, '<?= $current_date_str ?>', <?= $is_other_month ? 'true' : 'false' ?>)">
                    
                    <!-- Date Number & Header Controls -->
                    <div class="flex items-center justify-between mb-1 flex-shrink-0">
                        <span class="text-xs font-bold font-mono <?= $is_today ? 'text-blue-400 bg-blue-500/20 px-1.5 py-0.5 rounded-full' : ($is_other_month ? 'text-secondary/50' : 'text-primary') ?>">
                            <?= $date_tracker->format('j') ?>
                        </span>

                        <?php if (!$is_other_month): ?>
                            <button type="button" onclick="event.stopPropagation(); startTodo('<?= $current_date_str ?>')" class="cell-add-btn w-4 h-4 rounded flex items-center justify-center bg-white/10 hover:bg-white/20 text-secondary hover:text-primary text-[10px]" title="Tambah Catatan pada tanggal ini">
                                +
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Holiday Badge -->
                    <?php if ($is_holiday): ?>
                        <div class="holiday-tag" title="<?= htmlspecialchars($holiday_note) ?>">
                            ★ <?= htmlspecialchars($holiday_note) ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Items Container -->
                    <div class="item-list-container">
                        <?php if (isset($tasks_by_date[$current_date_str])): ?>
                            <?php foreach ($tasks_by_date[$current_date_str] as $item): ?>
                                <?php 
                                    $profile_pic = $item['profile_picture'] ?? '';
                                    $username = $item['username'] ?? ($item['user_email'] ?? $item['pic_email'] ?? '');
                                    $initial = getPicInitials($username);
                                ?>
                                
                                <?php if ($item['type'] === 'task'): ?>
                                    <?php 
                                        $badge_style = getStatusBadgeStyles($item['progress_status']); 
                                        $item_title = $item['model_name'] ?: 'Task';
                                    ?>
                                    <div 
                                        class="cal-item <?= $badge_style ?>" 
                                        title="[Task: <?= htmlspecialchars($item['progress_status']) ?>] <?= htmlspecialchars($item_title) ?> - PIC: <?= htmlspecialchars($username) ?>"
                                        onclick='event.stopPropagation(); openEditModal(<?= $item['json_data'] ?>)'
                                        data-item-type="task">
                                        
                                        <?php if (!empty($profile_pic) && file_exists('uploads/' . $profile_pic)): ?>
                                            <img src="uploads/<?= htmlspecialchars($profile_pic) ?>" alt="" class="avatar-mini">
                                        <?php else: ?>
                                            <span class="avatar-mini"><?= $initial ?></span>
                                        <?php endif; ?>
                                        
                                        <span class="truncate"><?= htmlspecialchars($item_title) ?></span>
                                    </div>

                                <?php elseif ($item['type'] === 'note'): ?>
                                    <?php 
                                        $priority_style = getNotePriorityStyles($item['priority']); 
                                        $note_title = $item['title'] ?: 'Catatan';
                                    ?>
                                    <div 
                                        class="cal-item <?= $priority_style ?>" 
                                        title="[Catatan: <?= htmlspecialchars($item['priority']) ?>] <?= htmlspecialchars($note_title) ?>"
                                        onclick='event.stopPropagation(); openEditNoteModal(<?= $item['json_data'] ?>)'
                                        data-item-type="note">
                                        
                                        <span class="pulsing-note-dot"></span>
                                        <span class="truncate"><?= htmlspecialchars($note_title) ?></span>
                                    </div>
                                <?php endif; ?>

                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php $date_tracker->modify('+1 day'); ?>
                <?php endfor; ?>
            </div>
        </div>
    </main>
    
    <!-- Modal: Tambah / Edit Catatan To-Do -->
    <div id="todo-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 backdrop-blur-sm hidden" style="backdrop-filter: blur(8px);">
        <div class="modal-content-wrapper rounded-2xl shadow-2xl p-4 sm:p-5 w-full max-w-xl mx-3">
            <form id="todo-form" action="handler.php" method="POST">
                
                <div class="flex justify-between items-center mb-3 pb-2 border-b border-[var(--glass-border)]">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-indigo-500 shadow-sm shadow-indigo-500/50"></div>
                        <h2 class="text-base font-bold text-header" id="todo-modal-title">Tambah Catatan / To-Do</h2>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="closeTodoModal()" class="px-3 py-1.5 rounded-lg themed-input text-xs font-semibold hover:bg-white/5 transition-all">
                            Batal
                        </button>
                        <button type="submit" class="px-3.5 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold shadow-md transition-all">
                            Simpan Catatan
                        </button>
                    </div>
                </div>

                <input type="hidden" name="action" id="todo-action" value="create_todo_note">
                <input type="hidden" name="todo_id" id="todo-id-input">
                <input type="hidden" name="todo_date" id="todo-date-input">

                <div class="space-y-3">
                    
                    <div class="flex items-center justify-between text-xs text-secondary px-1">
                        <span>Tanggal: <strong id="todo-date-display" class="text-primary font-mono font-bold"></strong></span>
                    </div>

                    <div>
                        <label for="todo-title-input" class="block mb-1 text-xs font-semibold text-secondary">Judul Catatan / Agenda</label>
                        <input type="text" name="todo_title" id="todo-title-input" placeholder="Contoh: Diskusi Evaluasi Test Plan QA" class="themed-input w-full p-2 text-xs rounded-lg" required>
                    </div>

                    <div>
                        <label class="block mb-1 text-xs font-semibold text-secondary">Detail Catatan / To-Do List</label>
                        <input type="hidden" name="todo_notes_content" id="todo-notes-hidden-input">
                        <div id="todo-notes-editor" class="themed-input rounded-lg text-xs" style="min-height: 90px;"></div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="todo_pic_email" class="block mb-1 text-xs font-semibold text-secondary">PIC (Optional)</label>
                            <select id="todo_pic_email" name="todo_pic_email" class="themed-input w-full p-2 text-xs rounded-lg">
                                <option value="" selected>Pilih PIC</option>
                                <?php foreach ($users_list as $user): ?>
                                    <option value="<?= htmlspecialchars($user['email']) ?>">
                                        <?= htmlspecialchars($user['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="todo_priority" class="block mb-1 text-xs font-semibold text-secondary">Prioritas</label>
                            <select id="todo_priority" name="todo_priority" class="themed-input w-full p-2 text-xs rounded-lg">
                                <option value="Low" selected>Low (Normal)</option>
                                <option value="Medium">Medium</option>
                                <option value="High">High (Penting)</option>
                            </select>
                        </div>
                    </div>

                    <div id="todo-delete-container" class="mt-3 pt-2.5 border-t border-[var(--glass-border)] flex justify-between items-center hidden">
                        <span class="text-[11px] text-secondary">Catatan dibuat oleh Anda</span>
                        <button type="button" onclick="confirmDeleteTodo()" class="px-3 py-1 rounded-lg bg-rose-500/15 hover:bg-rose-500/25 text-rose-400 border border-rose-500/30 text-xs font-semibold transition-all">
                            Hapus Catatan
                        </button>
                    </div>

                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Tambah / Edit Task GBA -->
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

    <!-- Custom Confirmation Modal (Better UI / Emil Design Eng) -->
    <div id="confirm-modal" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/75 backdrop-blur-sm hidden" style="backdrop-filter: blur(8px);">
        <div id="confirm-modal-box" class="modal-content-wrapper rounded-2xl shadow-2xl p-5 w-full max-w-md mx-4 transform transition-all scale-95 opacity-0">
            <div class="flex items-start gap-3.5">
                <div id="confirm-icon-wrapper" class="w-10 h-10 rounded-xl bg-rose-500/15 border border-rose-500/30 flex items-center justify-center text-rose-400 flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </div>
                <div class="flex-1">
                    <h3 id="confirm-title" class="text-base font-bold text-header">Hapus Catatan</h3>
                    <p id="confirm-desc" class="text-xs text-secondary mt-1 leading-relaxed">Apakah Anda yakin ingin menghapus catatan ini? Tindakan ini tidak dapat dibatalkan.</p>
                </div>
            </div>
            <div class="mt-5 pt-3 border-t border-[var(--glass-border)] flex items-center justify-end gap-2">
                <button type="button" id="confirm-btn-cancel" class="px-3.5 py-1.5 rounded-lg themed-input text-xs font-semibold hover:bg-white/5 transition-all">
                    Batal
                </button>
                <button type="button" id="confirm-btn-action" class="px-4 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-500 text-white text-xs font-semibold shadow-md shadow-rose-600/25 transition-all">
                    Hapus
                </button>
            </div>
        </div>
    </div>

<script>
    // --- NEURAL CANVAS ANIMATION ---
    const canvas = document.getElementById('neural-canvas'), ctx = canvas.getContext('2d');
    let particles = [], hue = 215;
    const mouse = { x: undefined, y: undefined, radius: 120 };

    function setCanvasSize(){ canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
    setCanvasSize();

    window.addEventListener('mousemove', e => { mouse.x = e.clientX; mouse.y = e.clientY; });
    window.addEventListener('mouseout', () => { mouse.x = undefined; mouse.y = undefined; });
    window.addEventListener('resize', () => { setCanvasSize(); init(particleCount); });

    class Particle {
        constructor() {
            this.x = Math.random() * canvas.width;
            this.y = Math.random() * canvas.height;
            this.vx = (Math.random() - 0.5) * 1.0;
            this.vy = (Math.random() - 0.5) * 1.0;
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

    const particleCount = window.innerWidth > 768 ? 100 : 50;
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

    // --- GLOBAL VARIABLES & MODAL HANDLERS ---
    const modal = document.getElementById('task-modal'); 
    const modalTitle = document.getElementById('modal-title');
    const taskForm = document.getElementById('task-form'); 
    const todoModal = document.getElementById('todo-modal');
    const currentMonth = '<?= $current_month ?>';
    const currentEmail = '<?= $_SESSION['user_details']['email'] ?? '' ?>';
    
    let quill, todoQuill; 

    function getTodayDate() { return new Date().toISOString().slice(0, 10); }

    function calculateWorkingDays(startDate, daysToAdd) {
        let currentDate = new Date(startDate);
        let addedDays = 0;
        while (addedDays < daysToAdd) {
            currentDate.setDate(currentDate.getDate() + 1);
            if (currentDate.getDay() !== 0 && currentDate.getDay() !== 6) {
                addedDays++;
            }
        }
        return currentDate.toISOString().slice(0, 10);
    }

    function setupTodoQuill(content) {
        if (!todoQuill) {
            todoQuill = new Quill('#todo-notes-editor', {
                theme: 'snow',
                modules: { toolbar: [['bold', 'italic', 'underline'], ['link'], [{ 'list': 'ordered' }, { 'list': 'bullet' }]] }
            });
        }
        todoQuill.root.innerHTML = content;
    }

    document.getElementById('todo-form').addEventListener('submit', () => {
        document.getElementById('todo-notes-hidden-input').value = todoQuill ? todoQuill.root.innerHTML : '';
    });

    window.openDateCellAction = function(cellElement, dateStr, isOtherMonth) {
        if (isOtherMonth || event.target.closest('.cal-item') || event.target.closest('button')) {
            return;
        }
        startTodo(dateStr);
    }

    window.closeTodoModal = function() {
        if (todoModal) todoModal.classList.add('hidden');
    }

    window.startTodo = function(dateStr) {
        dateStr = dateStr || getTodayDate();
        const dateObj = new Date(dateStr + 'T00:00:00');
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        
        document.getElementById('todo-form').reset();
        document.getElementById('todo-modal-title').textContent = 'Tambah Catatan / To-Do';
        document.getElementById('todo-action').value = 'create_todo_note';
        document.getElementById('todo-id-input').value = '';
        document.getElementById('todo-delete-container').classList.add('hidden');
        
        document.getElementById('todo-date-input').value = dateStr;
        document.getElementById('todo-date-display').textContent = dateObj.toLocaleDateString('id-ID', options);
        setupTodoQuill(''); 

        if (todoModal) todoModal.classList.remove('hidden');
    }

    window.openEditNoteModal = function(noteData) {
        const dateObj = new Date(noteData.note_date + 'T00:00:00');
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        
        document.getElementById('todo-form').reset();
        document.getElementById('todo-modal-title').textContent = 'Edit Catatan / To-Do';
        document.getElementById('todo-action').value = 'update_todo_note';
        document.getElementById('todo-id-input').value = noteData.id;
        
        document.getElementById('todo-date-input').value = noteData.note_date;
        document.getElementById('todo-date-display').textContent = dateObj.toLocaleDateString('id-ID', options);
        document.getElementById('todo-title-input').value = noteData.title;
        document.getElementById('todo_pic_email').value = noteData.user_email || '';
        document.getElementById('todo_priority').value = noteData.priority || 'Low';
        setupTodoQuill(noteData.content || '');
        
        const isUserNote = noteData.user_email === currentEmail;
        if (isUserNote) {
            document.getElementById('todo-delete-container').classList.remove('hidden');
        } else {
            document.getElementById('todo-delete-container').classList.add('hidden');
        }

        if (todoModal) todoModal.classList.remove('hidden');
    }

    // --- CUSTOM CONFIRMATION DIALOG (Emil Design Eng) ---
    function openConfirmDialog({ title, desc, onConfirm }) {
        const confirmModal = document.getElementById('confirm-modal');
        const box = document.getElementById('confirm-modal-box');
        const titleEl = document.getElementById('confirm-title');
        const descEl = document.getElementById('confirm-desc');
        const btnAction = document.getElementById('confirm-btn-action');
        const btnCancel = document.getElementById('confirm-btn-cancel');

        if (!confirmModal || !box) return;

        titleEl.textContent = title || 'Konfirmasi';
        descEl.textContent = desc || 'Apakah Anda yakin ingin melanjutkan?';

        confirmModal.classList.remove('hidden');
        requestAnimationFrame(() => {
            box.classList.remove('scale-95', 'opacity-0');
            box.classList.add('scale-100', 'opacity-100');
        });

        const closeDialog = () => {
            box.classList.remove('scale-100', 'opacity-100');
            box.classList.add('scale-95', 'opacity-0');
            setTimeout(() => confirmModal.classList.add('hidden'), 150);
            cleanup();
        };

        const handleKey = (e) => {
            if (e.key === 'Escape') closeDialog();
        };

        const cleanup = () => {
            btnAction.onclick = null;
            btnCancel.onclick = null;
            document.removeEventListener('keydown', handleKey);
        };

        btnCancel.onclick = closeDialog;
        btnAction.onclick = () => {
            closeDialog();
            if (typeof onConfirm === 'function') onConfirm();
        };

        document.addEventListener('keydown', handleKey);
    }

    function confirmDeleteTodo() {
        const noteId = document.getElementById('todo-id-input').value;
        if (!noteId) return;

        openConfirmDialog({
            title: 'Hapus Catatan Ini?',
            desc: 'Catatan dan to-do list ini akan dihapus secara permanen dari kalender.',
            onConfirm: () => {
                fetch('handler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete_todo_note',
                        id: noteId,
                        return_month: currentMonth
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert('Gagal menghapus catatan: ' + (data.error || 'Terjadi kesalahan.'));
                    }
                })
                .catch(err => {
                    console.error('Error:', err);
                    alert('Terjadi kesalahan jaringan.');
                });
            }
        });
    }

    // --- TASK MODAL HANDLERS ---
    function openAddModal() {
        if (!taskForm) return;
        taskForm.reset();
        modalTitle.innerText = 'Tambah Task Baru';
        taskForm.elements['action'].value = 'create_gba_task';
        taskForm.elements['id'].value = '';
        const today = getTodayDate();
        document.getElementById('request_date').value = today;
        const deadlineDate = calculateWorkingDays(today, 7);
        document.getElementById('deadline').value = deadlineDate;
        document.getElementById('sign_off_date').value = deadlineDate;
        document.getElementById('submission_date').value = '';
        document.getElementById('approved_date').value = '';
        document.getElementById('progress_status').value = 'Task Baru';
        setupQuill('');
        updateChecklistVisibility();
        if (modal) modal.classList.remove('modal-closing', 'hidden');
    }

    function openEditModal(taskData) { 
        if (!taskForm) return;
        taskForm.reset(); 
        modalTitle.innerText = 'Edit Task'; 
        taskForm.elements['action'].value = 'update_gba_task'; 
        
        for (const key in taskData) { 
            if (taskForm.elements[key] && !key.endsWith('_obj')) { 
                if (key === 'is_urgent') { 
                    const urgentEl = document.getElementById('is_urgent_toggle');
                    if (urgentEl) urgentEl.checked = taskData[key] == 1; 
                } else { 
                    taskForm.elements[key].value = taskData[key] || ''; 
                } 
            } 
        } 
        setupQuill(taskData.notes || ''); 
        updateChecklistVisibility(); 
        if (taskData.test_items_checklist) { 
            try { 
                const checklist = JSON.parse(taskData.test_items_checklist); 
                document.querySelectorAll('[name^="checklist["]').forEach(cb => cb.checked = false); 
                for (const itemName in checklist) { 
                    const checkbox = document.querySelector(`input[name="checklist[${itemName}]"]`); 
                    if (checkbox) checkbox.checked = !!checklist[itemName]; 
                } 
            } catch (e) { 
                console.error("Gagal parse checklist JSON:", e); 
            } 
        } 
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
    
    function setupQuill(content) {
        const notesEditor = document.getElementById('notes-editor');
        if (!notesEditor) return;
        if (!quill && typeof Quill === 'function') {
            quill = new Quill('#notes-editor', {
                theme: 'snow',
                modules: { toolbar: [['bold', 'italic', 'underline'], ['link'], [{ 'list': 'ordered' }, { 'list': 'bullet' }]] }
            });
        }
        if (quill) quill.root.innerHTML = content;
    }
    
    function updateChecklistVisibility() {
        const planEl = document.getElementById('test_plan_type');
        const placeholder = document.getElementById('checklist-placeholder');
        if (!planEl) return;
        const testPlan = planEl.value;
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
    
    if (taskForm) {
        taskForm.addEventListener('submit', (e) => {
            const mInput = document.getElementById('model_name');
            if (mInput && mInput.dataset.isDropped === "1") {
                e.preventDefault();
                e.stopPropagation();
                alert(`Task untuk model ${mInput.value} tidak dapat disimpan karena sudah Discontinue / Drop.`);
                return false;
            }
            const hiddenNotes = document.getElementById('notes-hidden-input');
            if (hiddenNotes && quill) hiddenNotes.value = quill.root.innerHTML;
        });
    }

    const testPlanSelect = document.getElementById('test_plan_type');
    if (testPlanSelect) {
        testPlanSelect.addEventListener('change', updateChecklistVisibility);
    }

    const progressStatusSelect = document.getElementById('progress_status');
    const submissionDateInput = document.getElementById('submission_date');
    const approvedDateInput = document.getElementById('approved_date');
    if (progressStatusSelect) {
        progressStatusSelect.addEventListener('change', e => {
            const status = e.target.value;
            if (status === 'Submitted' || status === 'Approved' || status === 'Passed') {
                if (submissionDateInput && !submissionDateInput.value) submissionDateInput.value = getTodayDate();
                if (status === 'Approved' || status === 'Passed') {
                    if (approvedDateInput && !approvedDateInput.value) approvedDateInput.value = getTodayDate();
                }
                const visibleChecklist = document.querySelector('[id^="checklist-container-"]:not(.hidden)');
                if (visibleChecklist) { visibleChecklist.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = true; }); }
            } else if (status === 'Task Baru') {
                const visibleChecklist = document.querySelector('[id^="checklist-container-"]:not(.hidden)');
                if (visibleChecklist) { visibleChecklist.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = false; }); }
                if (submissionDateInput) submissionDateInput.value = '';
                if (approvedDateInput) approvedDateInput.value = '';
            }
        });
    }

    // --- CALENDAR ITEM FILTER TOGGLES ---
    let filters = {
        task: localStorage.getItem('calFilter_task') !== null ? localStorage.getItem('calFilter_task') === 'true' : true,
        note: localStorage.getItem('calFilter_note') !== null ? localStorage.getItem('calFilter_note') === 'true' : true
    };

    function updateFilterPillUI() {
        const taskPill = document.getElementById('filter-task-pill');
        const notePill = document.getElementById('filter-note-pill');
        if (taskPill) taskPill.classList.toggle('active', filters.task);
        if (notePill) notePill.classList.toggle('active', filters.note);
    }

    function toggleFilter(type) {
        filters[type] = !filters[type];
        localStorage.setItem(`calFilter_${type}`, filters[type]);
        updateFilterPillUI();
        applyCalendarFilters();
    }

    function applyCalendarFilters() {
        document.querySelectorAll('.cal-item').forEach(item => {
            const type = item.getAttribute('data-item-type');
            if (type === 'task') {
                item.style.display = filters.task ? 'flex' : 'none';
            } else if (type === 'note') {
                item.style.display = filters.note ? 'flex' : 'none';
            }
        });
    }

    // --- THEME TOGGLE LOGIC ---
    let currentTheme = document.documentElement.classList.contains('light') ? 'light' : 'dark';
    const themeToggleBtn = document.getElementById('theme-toggle');

    function applyTheme(isLight) {
        currentTheme = isLight ? 'light' : 'dark';
        document.documentElement.classList.toggle('light', isLight);
        const lightIcon = document.getElementById('theme-toggle-light-icon');
        const darkIcon = document.getElementById('theme-toggle-dark-icon');
        if (lightIcon) lightIcon.classList.toggle('hidden', !isLight);
        if (darkIcon) darkIcon.classList.toggle('hidden', isLight);
    }

    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const isCurrentlyLight = document.documentElement.classList.contains('light');
            const newIsLight = !isCurrentlyLight;
            localStorage.setItem('theme', newIsLight ? 'light' : 'dark');
            applyTheme(newIsLight);
        });
    }

    // --- INITIALIZATION ---
    document.addEventListener('DOMContentLoaded', () => {
        const savedTheme = localStorage.getItem('theme');
        applyTheme(savedTheme === 'light');

        setupQuill('');
        setupTodoQuill('');
        updateChecklistVisibility();
        updateFilterPillUI();
        applyCalendarFilters();

        // Auto close alerts
        const successAlert = document.getElementById('success-alert');
        const errorAlert = document.getElementById('error-alert');
        if (successAlert || errorAlert) {
            setTimeout(() => {
                if (successAlert) successAlert.remove();
                if (errorAlert) errorAlert.remove();
                if (window.location.search.includes('success=') || window.location.search.includes('error=')) {
                    window.history.replaceState({}, document.title, "monthly_calendar.php?month=<?= $current_month ?>");
                }
            }, 3000);
        }

        // Close modal on backdrop click
        window.addEventListener('click', e => {
            if (e.target === todoModal) closeTodoModal();
            if (e.target === modal) closeModal();
        });
    });
</script>
</body>
</html>