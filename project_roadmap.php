<?php
// 1. INISIALISASI
require_once "config.php";
require_once "session.php";
$active_page = 'project_roadmap';

// 2. LOGIKA PENANGANAN WAKTU (YEAR FILTER)
date_default_timezone_set('Asia/Jakarta');
$current_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Validasi tahun
if (!is_numeric($current_year) || strlen($current_year) != 4) {
    $current_year = date('Y');
}

$start_date = $current_year . '-01-01';
$end_date = $current_year . '-12-31';

$prev_year = $current_year - 1;
$next_year = $current_year + 1;

// --- FUNGSI BARU: Mengambil Data Hari Libur dari API dengan Caching ---
function fetchIndonesianHolidays($year)
{
    $api_url = "https://api-harilibur.vercel.app/api?year=" . $year;
    $cache_file = __DIR__ . '/holidays_cache_' . $year . '.json';
    $cache_lifetime = 60 * 60 * 24 * 30; // 30 hari
    $response = false;

    if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_lifetime)) {
        $response = @file_get_contents($cache_file);
    }

    if (!$response) {
        $api_failed = false;
        $api_response = false;
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $api_response = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200 || $api_response === FALSE) {
                $api_failed = true;
            }
            curl_close($ch);
            $response = $api_response;
        } else {
            $response = @file_get_contents($api_url);
            if ($response === FALSE) {
                $api_failed = true;
            }
        }
        if ($api_failed && file_exists($cache_file)) {
            $response = @file_get_contents($cache_file);
        } else if (!$api_failed && $response) {
            @file_put_contents($cache_file, $response);
        }
    }
    $holidays = [];
    $data = json_decode($response, true);
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
$indonesian_holidays = fetchIndonesianHolidays($current_year);

// 3. GENERATE FULL YEAR DATES
// Instead of getting days in month, we get days in year
$year_dates = [];
$first_day_ts = strtotime($start_date);
$last_day_ts = strtotime($end_date);
$current_ts = $first_day_ts;

// Month separators/markers logic
$months = [];
while ($current_ts <= $last_day_ts) {
    $year_dates[] = $current_ts;

    // Store month info for month headers
    $m_key = date('Y-m', $current_ts);
    if (!isset($months[$m_key])) {
        $months[$m_key] = [
            'name' => date('F', $current_ts),
            'days' => 0,
            'start_idx' => count($year_dates) - 1
        ];
    }
    $months[$m_key]['days']++;

    $current_ts = strtotime('+1 day', $current_ts);
}
$total_days_in_view = count($year_dates);


// Ambil User List untuk Modal Edit
$users_result = $conn->query("SELECT email, username FROM users ORDER BY username ASC");
$users_list = [];
if ($users_result) {
    while ($user_row = $users_result->fetch_assoc()) {
        $users_list[] = $user_row;
    }
}

// 4. AMBIL DATA TASKS (Updated for Full Year)
$sql_tasks = "SELECT t.*, u.username, u.profile_picture 
        FROM gba_tasks t 
        LEFT JOIN users u ON t.pic_email = u.email
        WHERE (t.request_date <= ? AND (t.deadline >= ? OR t.deadline IS NULL))
        ORDER BY t.request_date ASC, t.model_name ASC";

$tasks = [];
if ($stmt = $conn->prepare($sql_tasks)) {
    $stmt->bind_param("ss", $end_date, $start_date);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Pre-encode JSON for edit modal
        $row['json_data'] = json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        $tasks[] = $row;
    }
    $stmt->close();
}

// 5. HELPER WARNA
function getStatusColorClasses($status)
{
    $colors = ['Approved' => 'bg-green-500', 'Passed' => 'bg-green-500', 'Submitted' => 'bg-purple-500', 'Test Ongoing' => 'bg-yellow-500', 'Task Baru' => 'bg-blue-500', 'Batal' => 'bg-gray-500', 'Pending Feedback' => 'bg-orange-500', 'Feedback Sent' => 'bg-orange-500'];
    return $colors[$status] ?? 'bg-gray-500';
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Roadmap - <?= $current_year ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <style>
        :root {
            --bg-primary: #020617;
            --text-primary: #e2e8f0;
            --text-secondary: #94a3b8;
            --glass-bg: rgba(15, 23, 42, .4);
            --glass-border: rgba(51, 65, 85, .4);
            --card-bg: rgba(15, 23, 42, .6);
            --card-border: rgba(51, 65, 85, .6);
            --text-header: #fff;
            --text-icon: #94a3b8;
            --input-bg: rgba(30, 41, 59, .7);
            --input-border: #475569;
        }

        html.light {
            --bg-primary: #f1f5f9;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --glass-bg: rgba(255, 255, 255, .7);
            --glass-border: rgba(0, 0, 0, .1);
            --card-bg: rgba(255, 255, 255, .8);
            --card-border: rgba(0, 0, 0, .1);
            --text-header: #0f172a;
            --text-icon: #475569;
            --input-bg: #ffffff;
            --input-border: #cbd5e1;
            --modal-bg: rgba(255, 255, 255, 0.95);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary)
        }

        html,
        body {
            height: 100%;
            overflow: hidden;
        }

        #neural-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1
        }

        .main-container {
            height: calc(100vh - 64px);
            overflow-y: hidden;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
        }

        .roadmap-container {
            flex-grow: 1;
            overflow: auto;
            /* Handles both X and Y scrolling */
            border-radius: 0.75rem;
            border: 1px solid var(--glass-border);
            background: var(--card-bg);
            position: relative;
        }

        .roadmap-content {
            min-width: fit-content;
            display: flex;
            flex-direction: column;
        }

        /* Sticky Header Group */
        .sticky-top-section {
            position: sticky;
            top: 0;
            z-index: 30;
            /* High z-index to stay above tasks */
            background: var(--bg-primary);
            /* Ensure opaque background */
        }

        .header-row {
            display: flex;
            /* position: sticky;  <-- Removed individual sticky */
            /* top: 0; */
            background: rgba(15, 23, 42, 0.95);
            border-bottom: 1px solid var(--glass-border);
            backdrop-filter: blur(8px);
        }

        html.light .header-row {
            background: rgba(241, 245, 249, 0.95);
        }

        /* Sub-header for Months */
        .month-header-row {
            display: flex;
            border-bottom: 1px solid var(--glass-border);
            background: rgba(15, 23, 42, 0.95);
        }

        html.light .month-header-row {
            background: rgba(241, 245, 249, 0.95);
        }

        .task-row {
            display: flex;
            border-bottom: 1px solid var(--glass-border);
            min-height: 50px;
            position: relative;
        }

        /* Sidebar Column (Sticky Left) */
        .sidebar-column {
            width: 260px;
            min-width: 260px;
            position: sticky;
            left: 0;
            background: rgba(15, 23, 42, 0.95);
            border-right: 1px solid var(--glass-border);
            z-index: 40;
            /* Z-Index 40 ensures it floats above timeline bars (z-10) and day cells */
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            padding: 0 16px;
        }

        html.light .sidebar-column {
            background: rgba(241, 245, 249, 0.95);
        }

        /* Ensure sidebar in header stays on HIGHEST top of everything */
        .sticky-top-section .sidebar-column {
            z-index: 50;
        }

        /* Days Grid */
        .days-container {
            display: flex;
            flex-grow: 1;
        }

        .day-cell {
            flex: 1;
            min-width: 34px;
            /* Reduced specific width */
            border-right: 1px solid var(--glass-border);
            position: relative;
        }

        .day-cell-header {
            flex: 1;
            min-width: 34px;
            border-right: 1px solid var(--glass-border);
            text-align: center;
            padding: 4px 0;
            font-size: 0.75rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .day-cell.weekend,
        .day-cell-header.weekend {
            background-color: rgba(239, 68, 68, 0.05);
        }

        .day-cell.holiday,
        .day-cell-header.holiday {
            background-color: rgba(253, 230, 138, 0.1);
        }

        .day-cell.today,
        .day-cell-header.today {
            background-color: rgba(59, 130, 246, 0.15);
            border-left: 1px solid rgba(59, 130, 246, 0.3);
            border-right: 1px solid rgba(59, 130, 246, 0.3);
        }

        .timeline-bar-container {
            position: absolute;
            left: 260px;
            top: 0;
            bottom: 0;
            right: 0;
            pointer-events: none;
        }

        .task-bar {
            position: absolute;
            height: 28px;
            top: 50%;
            transform: translateY(-50%);
            border-radius: 9999px;
            display: flex;
            align-items: center;
            padding: 0 4px;
            font-size: 0.75rem;
            color: white;
            white-space: nowrap;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            cursor: pointer;
            z-index: 10;
            pointer-events: auto;
        }

        .modal-content-wrapper {
            background: rgba(15, 23, 42, 0.9);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(51, 65, 85, 0.6);
        }

        html.light .modal-content-wrapper {
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(0, 0, 0, 0.1);
        }

        .modal-backdrop-blur {
            backdrop-filter: blur(5px);
        }

        .ql-editor {
            min-height: 100px;
        }
    </style>
</head>

<body class="flex flex-col h-screen">
    <canvas id="neural-canvas"></canvas>
    <?php include 'header.php'; ?>

    <main class="main-container">
        <!-- Top Controls -->
        <div class="flex-shrink-0 flex justify-between items-center mb-4">
            <h1 class="text-3xl font-bold text-header">Project Roadmap - <?= $current_year ?></h1>
            <div class="flex items-center space-x-3">
                <a href="?year=<?= $prev_year ?>" class="p-2 rounded-full hover:bg-gray-700/50 text-icon">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7">
                        </path>
                    </svg>
                </a>
                <form method="GET" action="" class="flex items-center space-x-2">
                    <input type="number" name="year" value="<?= $current_year ?>" onchange="this.form.submit()"
                        class="bg-[var(--input-bg)] border border-[var(--input-border)] text-[var(--text-primary)] p-2 rounded-lg text-sm w-24 text-center font-bold shadow-sm focus:ring-2 focus:ring-blue-500 outline-none transition-all">
                </form>
                <a href="export_excel.php?year=<?= $current_year ?>"
                    class="p-2 rounded-full hover:bg-gray-700/50 text-green-500 hover:text-green-400 transform hover:scale-105 transition-all"
                    title="Export to Excel">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                        </path>
                    </svg>
                </a>
                <a href="?year=<?= $next_year ?>" class="p-2 rounded-full hover:bg-gray-700/50 text-icon">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </a>

                <!-- Removed Add Task Button -->
            </div>
        </div>

        <div class="roadmap-container custom-scrollbar">
            <div class="roadmap-content">

                <!-- 1. Sticky Header Section -->
                <div class="sticky-top-section">
                    <!-- Month Header Row -->
                    <div class="header-row month-header-row">
                        <div class="sidebar-column"
                            style="background:transparent; border-right: 1px solid var(--glass-border); border-bottom: 1px solid var(--glass-border);">
                        </div> <!-- Spacer matching border -->
                        <div class="days-container">
                            <?php foreach ($months as $m_data): ?>
                                <div class="text-center py-1 font-bold text-sm text-header border-r border-[var(--glass-border)] uppercase tracking-wider relative"
                                    style="flex: <?= $m_data['days'] ?>;">
                                    <?= $m_data['name'] ?>
                                    <!-- Visual bottom border for month row -->
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Days Header Row -->
                    <div class="header-row">
                        <div class="sidebar-column">
                            Project / Task
                        </div>
                        <?php foreach ($year_dates as $date_ts):
                            $d = date('j', $date_ts);
                            $date_str = date('Y-m-d', $date_ts);
                            $is_weekend = (date('N', $date_ts) >= 6);
                            $is_today = ($date_str == date('Y-m-d'));
                            $is_holiday = isset($indonesian_holidays[$date_str]);

                            $classes = "day-cell-header";
                            if ($is_weekend)
                                $classes .= " weekend";
                            if ($is_today)
                                $classes .= " today";
                            if ($is_holiday)
                                $classes .= " holiday";

                            $holiday_title = $is_holiday ? $indonesian_holidays[$date_str] : "";
                            ?>
                            <div class="<?= $classes ?>" title="<?= htmlspecialchars($holiday_title) ?>">
                                <span class="font-bold"><?= $d ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 3. Task Rows -->
                <?php if (empty($tasks)): ?>
                    <div class="p-12 text-center text-secondary italic">
                        Tidak ada task yang berjalan di tahun <?= $current_year ?>.
                    </div>
                <?php else: ?>
                    <?php foreach ($tasks as $task):
                        $req_ts = strtotime($task['request_date']);

                        // New Logic for End Date: Approved > Sign Off > Deadline
                        $end_date_str = $task['approved_date'];
                        if (empty($end_date_str)) {
                            $end_date_str = $task['sign_off_date'];
                        }
                        if (empty($end_date_str)) {
                            $end_date_str = $task['deadline'];
                        }

                        $deadline_ts = $end_date_str ? strtotime($end_date_str) : $req_ts;

                        $view_start = $first_day_ts;
                        $view_end = $last_day_ts;

                        // Full bounds check
                        if ($deadline_ts < $view_start || $req_ts > $view_end)
                            continue;

                        $draw_start = max($req_ts, $view_start);
                        $draw_end = min($deadline_ts, $view_end);

                        $offset_days = ($draw_start - $view_start) / (60 * 60 * 24);
                        $duration_days = (($draw_end - $draw_start) / (60 * 60 * 24)) + 1;

                        $left_percent = ($offset_days / $total_days_in_view) * 100;
                        $width_percent = ($duration_days / $total_days_in_view) * 100;

                        $status_color = getStatusColorClasses($task['progress_status']);

                        // Identify if task is active today for auto-scroll
                        $today_ts = strtotime(date('Y-m-d')); // Get timestamp for start of today
                        $is_active_today = ($req_ts <= $today_ts && $deadline_ts >= $today_ts);
                        $row_class = $is_active_today ? 'task-row active-now' : 'task-row';
                        ?>
                        <div class="<?= $row_class ?>">
                            <div class="sidebar-column">
                                <div class="flex-1 min-w-0">
                                    <div class="font-medium text-sm truncate text-primary"
                                        title="<?= htmlspecialchars($task['model_name']) ?>">
                                        <?= htmlspecialchars($task['model_name']) ?>
                                    </div>
                                    <div class="text-[9px] text-secondary truncate"
                                        title="AP: <?= htmlspecialchars($task['ap'] ?? '-') ?> | CP: <?= htmlspecialchars($task['cp'] ?? '-') ?> | CSC: <?= htmlspecialchars($task['csc'] ?? '-') ?>">
                                        <?php
                                        $ap = $task['ap'] ? $task['ap'] : '-';
                                        $cp = $task['cp'] ? substr($task['cp'], -5) : '-';
                                        $csc = $task['csc'] ? substr($task['csc'], -5) : '-';
                                        echo htmlspecialchars("$ap / $cp / $csc");
                                        ?>
                                    </div>
                                </div>
                                <img src="uploads/<?= htmlspecialchars($task['profile_picture'] ?? 'default.png') ?>"
                                    class="w-6 h-6 rounded-full ml-2 border border-gray-600">
                            </div>

                            <div class="days-container">
                                <?php foreach ($year_dates as $date_ts):
                                    $date_str = date('Y-m-d', $date_ts);
                                    $is_weekend = (date('N', $date_ts) >= 6);
                                    $is_today = ($date_str == date('Y-m-d'));
                                    $is_holiday = isset($indonesian_holidays[$date_str]);

                                    $classes = "day-cell";
                                    if ($is_weekend)
                                        $classes .= " weekend";
                                    if ($is_today)
                                        $classes .= " today";
                                    if ($is_holiday)
                                        $classes .= " holiday";
                                    ?>
                                    <div class="<?= $classes ?>"></div>
                                <?php endforeach; ?>
                            </div>

                            <div class="timeline-bar-container">
                                <div class="task-bar <?= $status_color ?>"
                                    onclick='event.stopPropagation(); openEditModal(<?= $task['json_data'] ?>)'
                                    style="left: <?= $left_percent ?>%; width: <?= $width_percent ?>%;"
                                    title="[<?= htmlspecialchars($task['progress_status']) ?>] <?= htmlspecialchars($task['model_name']) ?>">

                                    <div class="flex items-center h-full w-full select-none overflow-hidden px-1">
                                        <?php
                                        // Badge Config
                                        $milestones_def = [
                                            ['key' => 'request_date', 'label' => 'Request', 'base_color' => 'blue'],
                                            ['key' => 'submission_date', 'label' => 'Submission', 'base_color' => 'purple'],
                                            ['key' => 'approved_date', 'label' => 'Approved', 'base_color' => 'green']
                                        ];
                                        $count = count($milestones_def);

                                        // LOGIC: Find latest active milestone
                                        $last_active_index = -1;
                                        foreach ($milestones_def as $idx => $m) {
                                            if (!empty($task[$m['key']])) {
                                                $last_active_index = $idx;
                                            }
                                        }

                                        foreach ($milestones_def as $index => $m):
                                            $date_val = $task[$m['key']];
                                            $display_date = $date_val ? date('d/m', strtotime($date_val)) : 'TBD';
                                            $base = $m['base_color'];

                                            // Apply FILL only if this is the latest active status
                                            // Others get NoFill
                                            if ($index === $last_active_index) {
                                                $style_class = "bg-{$base}-600 border-{$base}-400 opacity-100";
                                            } else {
                                                $style_class = "bg-transparent border-{$base}-200 opacity-60";
                                            }
                                            ?>
                                            <div class="flex-1 flex items-center justify-center min-w-0 relative group">
                                                <div class="w-full mx-0.5 py-0.5 rounded-md border text-[9px] leading-tight text-white whitespace-nowrap overflow-hidden text-ellipsis shadow-sm backdrop-blur-md text-center <?= $style_class ?>"
                                                    title="<?= $m['label'] ?>: <?= $display_date ?>">
                                                    <span class="font-bold"><?= $m['label'] ?></span>
                                                    <span class="opacity-90 block sm:inline sm:ml-1"><?= $display_date ?></span>
                                                </div>
                                                <?php if ($index < $count - 1): ?>
                                                    <div class="absolute -right-1 text-white/80 z-10"
                                                        style="width: 10px; height: 10px; top: 50%; transform: translateY(-50%) translateX(2px);">
                                                        <svg viewBox="0 0 24 24" fill="none" class="w-full h-full drop-shadow-md"
                                                            stroke="currentColor" stroke-width="4" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <path d="M9 18l6-6-6-6" />
                                                        </svg>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- TASK MODAL (Kept same) -->
    <div id="task-modal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-70 hidden modal-backdrop-blur">
        <div class="modal-content-wrapper rounded-lg shadow-xl p-6 w-full max-w-6xl mx-4 max-h-[90vh] overflow-y-auto">
            <form id="task-form" action="handler.php" method="POST">
                <div class="flex justify-between items-center mb-4">
                    <h2 id="modal-title" class="text-2xl font-bold text-header">Edit Task</h2>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="closeModal()"
                            class="px-4 py-2 rounded-lg bg-[var(--input-bg)] text-[var(--text-primary)] border border-[var(--input-border)]">Batal</button>
                        <button type="submit"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Simpan
                            Perubahan</button>
                    </div>
                </div>
                <input type="hidden" name="id" id="task-id">
                <input type="hidden" name="action" id="form-action" value="update_gba_task">
                <input type="hidden" name="redirect_to" value="project_roadmap.php">
                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="form-label block mb-1 text-sm font-medium">Marketing Name</label>
                            <input type="text" id="project_name" name="project_name"
                                class="themed-input w-full p-2.5 text-sm rounded-lg" required>
                        </div>
                        <div>
                            <label class="form-label block mb-1 text-sm font-medium">Model Name</label>
                            <input type="text" id="model_name" name="model_name"
                                class="themed-input w-full p-2.5 text-sm rounded-lg" required>
                        </div>
                        <div>
                            <label class="form-label block mb-1 text-sm font-medium">PIC</label>
                            <select id="pic_email" name="pic_email" class="themed-input w-full p-2.5 text-sm rounded-lg"
                                required>
                                <option value="" disabled>Pilih PIC</option>
                                <?php foreach ($users_list as $user): ?>
                                    <option value="<?= htmlspecialchars($user['email']) ?>">
                                        <?= htmlspecialchars($user['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="progress_status" class="form-label block mb-1 text-sm font-medium">Status
                                Progress</label>
                            <select id="progress_status" name="progress_status"
                                class="themed-input w-full p-2.5 text-sm rounded-lg important-field" required>
                                <option>Task Baru</option>
                                <option>Test Ongoing</option>
                                <option>Passed</option>
                                <option>Submitted</option>
                                <option>Approved</option>
                                <option>Pending Feedback</option>
                                <option>Feedback Sent</option>
                                <option>Batal</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label block mb-1 text-sm font-medium">Type Test Plan</label>
                            <select id="test_plan_type" name="test_plan_type"
                                class="themed-input w-full p-2.5 text-sm rounded-lg">
                                <option>Regular Variant</option>
                                <option>SKU</option>
                                <option>Normal MR</option>
                                <option>SMR</option>
                                <option>Simple Exception MR</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                        <div><label class="form-label block mb-1 text-sm font-medium">Request Date</label><input
                                type="date" id="request_date" name="request_date"
                                class="themed-input w-full p-2 text-sm rounded-lg"></div>
                        <div><label class="form-label block mb-1 text-sm font-medium">Submission Date</label><input
                                type="date" id="submission_date" name="submission_date"
                                class="themed-input w-full p-2 text-sm rounded-lg"></div>
                        <div><label class="form-label block mb-1 text-sm font-medium">Approved Date</label><input
                                type="date" id="approved_date" name="approved_date"
                                class="themed-input w-full p-2 text-sm rounded-lg"></div>
                        <div><label class="form-label block mb-1 text-sm font-medium">Deadline</label><input type="date"
                                id="deadline" name="deadline" class="themed-input w-full p-2 text-sm rounded-lg"></div>
                        <div><label class="form-label block mb-1 text-sm font-medium">Sign-Off Date</label><input
                                type="date" id="sign_off_date" name="sign_off_date"
                                class="themed-input w-full p-2 text-sm rounded-lg"></div>
                    </div>
                    <div>
                        <label class="form-label block mb-1 text-sm font-medium">Notes</label>
                        <input type="hidden" name="notes" id="notes-hidden-input">
                        <div id="notes-editor" class="themed-input rounded-lg"></div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <script>
        const root = document.documentElement;
        // Function to update the icons manually if needed, checking the current class
        function updateThemeIcons() {
            const isLight = root.classList.contains('light');
            // If you have specific IDs for icons in header, toggle them here relative to 'hidden' class
            // Assuming header.php handles it, but if we need a refresh:
            const lightIcon = document.querySelector('#theme-toggle-light-icon');
            const darkIcon = document.querySelector('#theme-toggle-dark-icon');
            if (lightIcon && darkIcon) {
                if (isLight) { lightIcon.classList.remove('hidden'); darkIcon.classList.add('hidden'); }
                else { lightIcon.classList.add('hidden'); darkIcon.classList.remove('hidden'); }
            }
        }
        function applyTheme(isLight) {
            root.classList.toggle('light', isLight);
            root.classList.toggle('dark', !isLight);
            updateThemeIcons();
        }
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme) {
            applyTheme(savedTheme === 'light');
        } else {
            applyTheme(false); // Default logic
        }

        const themeBtn = document.getElementById('theme-toggle');
        if (themeBtn) themeBtn.addEventListener('click', () => {
            const isLight = !root.classList.contains('light');
            localStorage.setItem('theme', isLight ? 'light' : 'dark');
            applyTheme(isLight);
        });

        const canvas = document.getElementById('neural-canvas'), ctx = canvas.getContext('2d');
        let particles = [], hue = 210;
        function setCanvasSize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
        class Particle { constructor() { this.x = Math.random() * canvas.width; this.y = Math.random() * canvas.height; this.vx = (Math.random() - .5) * .4; this.vy = (Math.random() - .5) * .4; this.size = Math.random() * 2 + 1.5; } update() { this.x += this.vx; this.y += this.vy; if (this.x < 0 || this.x > canvas.width) this.vx *= -1; if (this.y < 0 || this.y > canvas.height) this.vy *= -1; } draw() { ctx.fillStyle = `hsl(${hue},100%,75%)`; ctx.beginPath(); ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2); ctx.fill(); } }
        function init(n) { particles = []; for (let i = 0; i < n; i++) particles.push(new Particle()); }
        function animate() { ctx.clearRect(0, 0, canvas.width, canvas.height); hue = (hue + .3) % 360; particles.forEach(p => { p.update(); p.draw(); }); requestAnimationFrame(animate); }
        window.addEventListener('resize', setCanvasSize); setCanvasSize(); init(80); animate();

        // Modal Logic
        var quill;
        function openAddModal() {
            // Deprecated/Removed from UI but function kept to prevent errors if called
        }
        function openEditModal(data) {
            document.getElementById('task-id').value = data.id; document.getElementById('form-action').value = 'update_gba_task';
            document.getElementById('modal-title').innerText = 'Edit Task: ' + data.model_name;
            ['project_name', 'model_name', 'pic_email', 'progress_status', 'test_plan_type', 'request_date', 'submission_date', 'approved_date', 'deadline', 'sign_off_date'].forEach(id => { if (document.getElementById(id)) document.getElementById(id).value = data[id] || ''; });
            if (!quill) { quill = new Quill('#notes-editor', { theme: 'snow' }); quill.on('text-change', function () { document.getElementById('notes-hidden-input').value = quill.root.innerHTML; }); }
            quill.root.innerHTML = data.notes || '';
            document.getElementById('task-modal').classList.remove('hidden');
        }
        function closeModal() { document.getElementById('task-modal').classList.add('hidden'); }
        document.getElementById('task-form').addEventListener('submit', function () { if (quill) document.getElementById('notes-hidden-input').value = quill.root.innerHTML; });

        // Auto-Scroll to Today & Active Task
        window.addEventListener('load', () => {
            // 1. Horizontal Scroll (Center Today)
            const todayHeader = document.querySelector('.day-cell-header.today');
            if (todayHeader) {
                todayHeader.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'center' });
            }

            // 2. Vertical Scroll (Active Task)
            const activeTask = document.querySelector('.task-row.active-now');
            if (activeTask) {
                // Scroll the main content container to show the active task
                // We use slightly delayed scroll to let the first scroll settle if needed, or just run it.
                // We target the .roadmap-container which handles Y scroll
                const container = document.querySelector('.roadmap-container');
                if (container) {
                    // specific scroll to element
                    const topPos = activeTask.offsetTop;
                    // Subtract header height (~100px) from position
                    container.scrollTop = topPos - 120;
                }
            }
        });
    </script>
</body>

</html>