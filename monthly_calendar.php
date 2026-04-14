<?php
// 1. INISIALISASI
require_once "config.php";
require_once "session.php"; 
$active_page = 'monthly_calendar';


// --- FUNGSI BARU: Mengambil Data Hari Libur dari API dengan Caching ---
function fetchIndonesianHolidays($year) {
    $api_url = "https://api-harilibur.vercel.app/api?year=" . $year;
    $cache_file = __DIR__ . '/holidays_cache_' . $year . '.json'; // Nama file cache yang diharapkan
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
            $api_response = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200 || $api_response === FALSE) { $api_failed = true; }
            curl_close($ch);
            $response = $api_response;
        } else {
            // Fallback ke file_get_contents
            $response = @file_get_contents($api_url);
            if ($response === FALSE) { $api_failed = true; }
        }

        // 3. Jika API fetch gagal, coba muat dari cache yang sudah kedaluwarsa (Redundansi)
        if ($api_failed && file_exists($cache_file)) {
            $response = @file_get_contents($cache_file);
        } 
        // 4. Jika API fetch berhasil, simpan ke cache
        else if (!$api_failed && $response) {
            @file_put_contents($cache_file, $response);
        }
    }

    $holidays = [];
    $data = json_decode($response, true);
    
    if (is_array($data) && !empty($data)) {
        foreach ($data as $item) {
            // FIX KRITIS: Menggunakan 'holiday_date' dan 'holiday_name'
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
// --- AKHIR FUNGSI API ---


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
// PANGGIL FUNGSI API OTOMATIS
$indonesian_holidays = fetchIndonesianHolidays($current_year);

// Cek keberadaan file cache untuk pesan peringatan
$cache_filename = 'holidays_cache_' . $current_year . '.json';
$cache_exists = file_exists(__DIR__ . '/' . $cache_filename);

// 3A. LOGIKA PENGAMBILAN DATA GBA TASKS (Tabel gba_tasks)
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
            }
        }
    }
    $stmt_tasks->close();
}

// 3B. LOGIKA PENGAMBILAN DATA USER NOTES (Tabel user_notes)
$sql_notes = "SELECT n.*, u.profile_picture 
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
        }
    }
    $stmt_notes->close();
}
// 4. LOGIKA PENGURUTAN: Memastikan 'note' muncul sebelum 'task'
foreach ($tasks_by_date as $date => $items) {
    usort($tasks_by_date[$date], function($a, $b) {
        if ($a['type'] === 'note' && $b['type'] === 'task') {
            return -1; // 'note' (a) datang sebelum 'task' (b)
        }
        if ($a['type'] === 'task' && $b['type'] === 'note') {
            return 1; // 'task' (a) datang setelah 'note' (b)
        }
        // Jika keduanya sama (keduanya note atau keduanya task), pertahankan urutan asli
        return 0;
    });
}
// --------------------------------------------------------------------------------

// Ambil daftar user untuk dropdown di modal edit
$users_result = $conn->query("SELECT email, username FROM users ORDER BY username ASC");
$users_list = [];
if ($users_result) {
    while($user_row = $users_result->fetch_assoc()) {
        $users_list[] = $user_row;
    }
}

// Helper untuk mendapatkan warna badge
function getStatusColorClasses($status) {
    $colors = ['Approved'=>'bg-green-500','Passed'=>'bg-green-500','Submitted'=>'bg-purple-500','Test Ongoing'=>'bg-yellow-500','Task Baru'=>'bg-blue-500','Batal'=>'bg-gray-500','Pending Feedback'=>'bg-orange-500','Feedback Sent'=>'bg-orange-500'];
    return $colors[$status] ?? 'bg-gray-500';
}
function getNotePriorityColor($priority) {
    $colors = ['High'=>'bg-red-500', 'Medium'=>'bg-indigo-500', 'Low'=>'bg-gray-500'];
    return $colors[$priority] ?? 'bg-gray-500';
}

// Helper untuk inisial (fallback jika gambar gagal)
function getPicInitials($email) {
    if (empty($email)) return '?';
    $parts = explode('@', $email);
    return strtoupper(substr($parts[0], 0, 1));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Calendar View</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <style>
        :root{--bg-primary:#020617;--text-primary:#e2e8f0;--text-secondary:#94a3b8;--glass-bg:rgba(15,23,42,.4);--glass-border:rgba(51,65,85,.4);--card-bg:rgba(15,23,42,.6);--card-border:rgba(51,65,85,.6);--text-header:#fff;--text-icon:#94a3b8;--input-bg:rgba(30,41,59,.7);--input-border:#475569;}
        html.light{--bg-primary:#f1f5f9;--text-primary:#0f172a;--text-secondary:#475569;--glass-bg:rgba(255,255,255,.7);--glass-border:rgba(0,0,0,.1);--card-bg:rgba(255,255,255,.8);--card-border:rgba(0,0,0,.1);--text-header:#0f172a;--text-icon:#475569;--input-bg:#ffffff;--input-border:#cbd5e1;}
        
        body{font-family:'Inter',sans-serif;background-color:var(--bg-primary);color:var(--text-primary)}
        html, body { height: 100%; overflow: hidden; }
        #neural-canvas{position:fixed;top:0;left:0;width:100%;height:100%;z-index:-1}
        .main-container{height:calc(100vh - 64px);overflow-y:hidden; padding: 1.5rem; display: flex; flex-direction: column;}
        .calendar-wrapper { flex-grow: 1; overflow-y: auto; min-height: 0; }

        .glass-card { background: var(--card-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid var(--card-border); border-radius: 0.75rem; }
        .themed-input{background-color:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary)}
        .ql-toolbar,.ql-container{border-color:var(--glass-border)!important}.ql-editor{color:var(--text-primary);min-height:42px; padding-top: 10px; padding-bottom: 10px;}

        /* Modal Styles with Blur Backdrop */
        .modal-content-wrapper { 
            background: rgba(15, 23, 42, 0.8); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px); 
            border: 1px solid rgba(51, 65, 85, 0.6); 
        }
        html.light .modal-content-wrapper { 
            background: rgba(255, 255, 255, 0.8); 
            border: 1px solid rgba(0, 0, 0, 0.1); 
        }
        .modal-backdrop-blur {
            backdrop-filter: blur(5px); 
            -webkit-backdrop-filter: blur(5px);
        }
        
        /* --- START OF ADDED NAVIGATION STYLES --- */
        .nav-link{color:var(--text-secondary);transition:color .2s,border-color .2s;border-bottom:2px solid transparent}
        .nav-link:hover{color:var(--text-primary)}
        .nav-link-active{color:var(--text-primary)!important;font-weight:500;border-bottom:2px solid #3b82f6}
        /* --- END OF ADDED NAVIGATION STYLES --- */

        /* Calendar Styles */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            border-top: 1px solid var(--glass-border);
            border-left: 1px solid var(--glass-border);
            min-height: 100%; /* Fill parent wrapper */
        }
        .day-label {
            padding: 10px;
            font-weight: 600;
            text-align: center;
            border-right: 1px solid var(--glass-border);
            border-bottom: 1px solid var(--glass-border);
            background-color: rgba(59, 130, 246, 0.2);
            color: #fff;
        }
        .date-cell {
            padding: 4px;
            border-right: 1px solid var(--glass-border);
            border-bottom: 1px solid var(--glass-border);
            min-height: 100px; /* Base height */
            position: relative;
            background-color: rgba(255, 255, 255, 0.02);
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            cursor: pointer; 
            transition: background-color 0.1s;
        }
        .date-cell:hover:not(.other-month) {
            background-color: rgba(255, 255, 255, 0.05) !important;
        }
        html.light .date-cell:hover:not(.other-month) {
            background-color: rgba(0, 0, 0, 0.08) !important;
        }
        .date-cell.weekend {
            background-color: rgba(239, 68, 68, 0.1) !important;
        }
        .date-cell.holiday {
            background-color: rgba(253, 230, 138, 0.1) !important; /* Default Light Yellow */
        }
        .date-cell.holiday.weekend {
            background-color: rgba(239, 68, 68, 0.2) !important; /* Redder highlight if weekend is holiday */
        }
        .date-cell.today {
            border: 2px solid #3b82f6 !important;
        }
        .holiday-note {
            font-size: 0.65rem;
            font-weight: 600;
            color: #f59e0b; /* Amber */
            background-color: rgba(255, 255, 255, 0.1);
            padding: 1px 4px;
            border-radius: 4px;
            margin-top: 2px;
            max-width: 100%;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .date-number {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .task-list {
            width: 100%;
            overflow-y: auto;
            max-height: 70px;
            padding-right: 2px;
            flex-grow: 1;
        }
        .task-item {
            display: flex;
            align-items: center; 
            margin-bottom: 2px;
            padding: 2px 4px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 500;
            color: #fff;
            cursor: pointer;
            width: 100%;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            transition: background-color 0.1s;
        }
        .task-item:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }
        .pic-icon-sm {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 4px;
            flex-shrink: 0;
            font-size: 0.6rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background-color: #60a5fa;
        }
        /* Tambahkan CSS baru ini untuk efek kedipan pada catatan */
        .note-glow-effect {
            position: relative;
            animation: note-glow 2s infinite alternate; /* Animasi kedip */
            border: 1px solid transparent !important; /* Hilangkan border default */
        }
        
        /* Keyframes untuk efek glow/kedip (AKSEN MERAH) */
        @keyframes note-glow {
            0% { box-shadow: 0 0 2px #ef4444, 0 0 4px #ef4444; } /* Merah gelap (Red-600) */
            50% { box-shadow: 0 0 6px #fca5a5, 0 0 10px #fca5a5; } /* Merah terang (Red-300) */
            100% { box-shadow: 0 0 2px #ef4444, 0 0 4px #ef4444; }
        }
        
    </style>
</head>
<body class="h-screen flex flex-col">
    <canvas id="neural-canvas"></canvas>

    <?php include 'header.php'; ?>

    <main class="main-container">
        <div class="flex-shrink-0 flex justify-between items-center mb-4">
            <h1 class="text-3xl font-bold text-header">Schedule <?= date('F Y', $timestamp) ?></h1>
            <div class="flex items-center space-x-3">
                <a href="?month=<?= $prev_month ?>" class="p-2 rounded-full hover:bg-gray-700/50 text-icon">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                </a>
                <form method="GET" action="" class="flex items-center space-x-2">
                    <input type="month" name="month" value="<?= $current_month ?>" onchange="this.form.submit()" class="themed-input p-2 rounded-lg text-sm">
                </form>
                <a href="?month=<?= $next_month ?>" class="p-2 rounded-full hover:bg-gray-700/50 text-icon">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </a>
                
                <div class="flex items-center space-x-4 border-l border-gray-600 pl-4">
                    <div class="flex items-center">
                        <input id="filter-task" type="checkbox" class="w-4 h-4 text-blue-600 bg-gray-700 border-gray-600 rounded focus:ring-blue-500 cursor-pointer">
                        <label for="filter-task" class="ml-2 text-sm font-medium text-primary cursor-pointer">Tampilkan Task</label>
                    </div>
                    <div class="flex items-center">
                        <input id="filter-note" type="checkbox" checked class="w-4 h-4 text-indigo-600 bg-gray-700 border-gray-600 rounded focus:ring-indigo-500 cursor-pointer">
                        <label for="filter-note" class="ml-2 text-sm font-medium text-primary cursor-pointer">Tampilkan Catatan</label>
                    </div>
                </div>
                <a onclick="openAddModal()" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 cursor-pointer">
                    + Task Baru
                </a>
            </div>
        </div>

        <?php if(isset($_GET['success'])): ?>
            <div id="success-alert" class="bg-green-500/20 text-green-300 text-sm p-4 rounded-lg mb-4 flex-shrink-0">
                ✅ <?= htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_GET['error'])): ?>
            <div id="error-alert" class="bg-red-500/20 text-red-300 text-sm p-4 rounded-lg mb-4 flex-shrink-0">
                ❌ <?= htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>

        <?php 
        $current_year_for_display = date('Y', $timestamp);
        $cache_filename_expected = 'holidays_cache_' . $current_year_for_display . '.json';
        
        // Cek jika API gagal dan file cache tidak ada DENGAN NAMA YANG BENAR
        if (empty($indonesian_holidays) && !file_exists(__DIR__ . '/' . $cache_filename_expected)):
        ?>
            <div id="api-failure-alert" class="bg-red-500/20 text-red-300 text-sm p-4 rounded-lg mb-4 flex-shrink-0">
                ⚠️ **KRITIS: Hari Libur Nasional tidak dapat dimuat.**<br>
                Mohon lakukan langkah manual berikut untuk mengaktifkan fitur ini:
                <ol class="list-decimal list-inside ml-4 mt-2 text-white">
                    <li>Buka link ini di browser Anda: <a href="https://api-harilibur.vercel.app/api?year=<?= $current_year_for_display ?>" target="_blank" class="underline hover:text-blue-400">Ambil Data Libur <?= $current_year_for_display ?> (JSON)</a></li>
                    <li>Simpan seluruh konten halaman sebagai file bernama: **`<?= $cache_filename_expected ?>`**</li>
                    <li>**PASTIKAN TIDAK ADA EKSTENSI GANDA (.json.json)**.</li>
                    <li>Upload atau letakkan file **`<?= $cache_filename_expected ?>`** di direktori yang sama dengan `monthly_calendar.php`.</li>
                    <li>Refresh halaman.</li>
                </ol>
            </div>
        <?php endif; ?>
        
        <div class="calendar-wrapper glass-card p-0 overflow-hidden">
            <div class="calendar-grid">
                <?php $day_names = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab']; ?>
                <?php foreach ($day_names as $day_name): ?>
                    <div class="day-label"><?= $day_name ?></div>
                <?php endforeach; ?>

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
                    
                    <span class="date-number <?= $is_other_month ? 'text-secondary' : 'text-primary' ?>">
                        <?= $date_tracker->format('j') ?>
                    </span>
                    
                    <?php if ($is_holiday): ?>
                        <div class="holiday-note" title="<?= htmlspecialchars($holiday_note) ?>">
                            <?= htmlspecialchars($holiday_note) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($tasks_by_date[$current_date_str])): ?>
                        <div class="task-list">
                        <?php foreach ($tasks_by_date[$current_date_str] as $item): ?>
                            <?php 
                                $profile_pic = $item['profile_picture'] ?? 'default.png';
                                $item_title = $item['type'] === 'task' ? $item['model_name'] : $item['title'];
                            ?>
                            
                            <?php if ($item['type'] === 'task'): ?>
                                <?php $status_class = getStatusColorClasses($item['progress_status']); ?>
                                <div 
                                    class="task-item <?= $status_class ?> hover:bg-opacity-80" 
                                    title="[Task: Deadline] <?= htmlspecialchars($item_title) ?> - PIC: <?= htmlspecialchars($item['username'] ?: $item['pic_email']) ?>"
                                    onclick='event.stopPropagation(); openEditModal(<?= $item['json_data'] ?>)'
                                    data-item-type="task" >
                                    <img src="uploads/<?= htmlspecialchars($profile_pic) ?>" onerror="this.style.display='none'" alt="P" class="pic-icon-sm">
                                    <?= htmlspecialchars($item_title) ?>
                                </div>
                            <?php elseif ($item['type'] === 'note'): ?>
                                <?php $note_color = getNotePriorityColor($item['priority']); ?>
                                <div 
                                    class="task-item <?= $note_color ?> bg-opacity-70 hover:bg-opacity-90 note-glow-effect" 
                                    title="[Catatan: <?= htmlspecialchars($item['priority']) ?>] <?= htmlspecialchars($item_title) ?>"
                                    onclick='event.stopPropagation(); openEditNoteModal(<?= $item['json_data'] ?>)'
                                    data-item-type="note" >
                                    <img src="uploads/<?= htmlspecialchars($profile_pic) ?>" onerror="this.style.display='none'" alt="P" class="pic-icon-sm">
                                    <?= htmlspecialchars($item_title) ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php $date_tracker->modify('+1 day'); ?>
                <?php endfor; ?>
            </div>
        </div>
    </main>
    
    <div id="todo-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-70 hidden modal-backdrop-blur">
        <div class="modal-content-wrapper rounded-lg shadow-xl p-6 w-full max-w-xl mx-4 max-h-[90vh] overflow-y-auto">
            <form id="todo-form" action="handler.php" method="POST">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-2xl font-bold text-header" id="todo-modal-title">Tambah Catatan / To-Do</h2>
                    <div class="flex justify-end gap-3">
                         <button type="button" onclick="closeTodoModal()" class="px-4 py-2 rounded-lg themed-input">Batal</button>
                         <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg">Simpan Catatan</button>
                    </div>
                </div>
                <input type="hidden" name="action" id="todo-action" value="create_todo_note">
                <input type="hidden" name="todo_id" id="todo-id-input">
                <input type="hidden" name="todo_date" id="todo-date-input">

                <div class="space-y-4">
                    <div class="text-sm text-secondary">
                        Tanggal: <span id="todo-date-display" class="font-semibold text-primary"></span>
                    </div>
                    <div>
                        <label for="todo_title" class="form-label block mb-1 text-sm font-medium">Judul Singkat (Contoh: Rapat Harian)</label>
                        <input type="text" name="todo_title" id="todo-title-input" class="themed-input w-full p-2.5 text-sm rounded-lg" required>
                    </div>
                    <div>
                        <label for="todo-notes-editor" class="form-label block mb-1 text-sm font-medium">Detail Catatan / To-Do List</label>
                        <input type="hidden" name="todo_notes_content" id="todo-notes-hidden-input">
                        <div id="todo-notes-editor" class="themed-input rounded-lg"></div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="todo_pic_email" class="form-label block mb-1 text-sm font-medium">PIC (Optional)</label>
                            <select id="todo_pic_email" name="todo_pic_email" class="themed-input w-full p-2.5 text-sm rounded-lg">
                                <option value="" selected>Pilih PIC</option>
                                <?php foreach ($users_list as $user): ?>
                                    <option value="<?= htmlspecialchars($user['email']) ?>">
                                        <?= htmlspecialchars($user['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="todo_priority" class="form-label block mb-1 text-sm font-medium">Prioritas</label>
                            <select id="todo_priority" name="todo_priority" class="themed-input w-full p-2.5 text-sm rounded-lg">
                                <option value="Low" selected>Low</option>
                                <option value="Medium">Medium</option>
                                <option value="High">High</option>
                            </select>
                        </div>
                    </div>
                    <div id="todo-delete-container" class="mt-4 pt-4 border-t border-gray-600 hidden">
                        <button type="button" onclick="deleteTodoNote(document.getElementById('todo-id-input').value, '<?= $current_month ?>')" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg">Hapus Catatan Ini</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div id="task-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-70 hidden modal-backdrop-blur">
        <div class="modal-content-wrapper rounded-lg shadow-xl p-6 w-full max-w-6xl mx-4 max-h-[90vh] overflow-y-auto">
            <form id="task-form" action="handler.php" method="POST">
                <div class="flex justify-between items-center mb-4">
                    <h2 id="modal-title" class="text-2xl font-bold text-header">Tambah Task Baru</h2>
                    <div class="flex justify-end gap-3">
                         <button type="button" onclick="closeModal()" class="px-4 py-2 rounded-lg themed-input">Batal</button>
                         <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Simpan Task</button>
                    </div>
                </div>
                <input type="hidden" name="id" id="task-id">
                <input type="hidden" name="action" id="form-action" value="create_gba_task">
                
                <?php $test_plan_items_form = [
                    'Regular Variant' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
                    'SKU' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
                    'Normal MR' => ['CTS', 'GTS', 'CTS-Verifier', 'ATM'],
                    'SMR' => ['CTS', 'GTS', 'STS', 'SCAT'],
                    'Simple Exception MR' => ['STS']
                ]; ?>
                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="project_name" class="form-label block mb-1 text-sm font-medium">Marketing Name</label>
                            <input type="text" id="project_name" name="project_name" class="themed-input w-full p-2.5 text-sm rounded-lg" required>
                        </div>
                        <div>
                            <label for="model_name" class="form-label block mb-1 text-sm font-medium">Model Name</label>
                            <input type="text" id="model_name" name="model_name" class="themed-input w-full p-2.5 text-sm rounded-lg" required>
                        </div>
                        <div>
                            <label for="pic_email" class="form-label block mb-1 text-sm font-medium">PIC</label>
                            <select id="pic_email" name="pic_email" class="themed-input w-full p-2.5 text-sm rounded-lg" required>
                                <option value="" disabled selected>Pilih PIC</option>
                                <?php foreach ($users_list as $user): ?>
                                    <option value="<?= htmlspecialchars($user['email']) ?>">
                                        <?= htmlspecialchars($user['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="flex items-center pt-2">
                        <label for="is_urgent_toggle" class="relative inline-flex items-center cursor-pointer">
                            <input type="hidden" name="is_urgent" value="0">
                            <input type="checkbox" value="1" id="is_urgent_toggle" name="is_urgent" class="sr-only peer">
                            <div class="w-11 h-6 bg-gray-600 rounded-full peer peer-focus:ring-4 peer-focus:ring-red-800 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-red-600"></div>
                            <span class="ml-3 text-sm font-medium text-black-300 peer-checked:text-red-900">
                                Tandai sebagai Task Urgent
                            </span>
                        </label>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="ap" class="form-label block mb-1 text-sm font-medium">AP</label>
                            <input type="text" id="ap" name="ap" class="themed-input w-full p-2.5 text-sm rounded-lg">
                        </div>
                        <div>
                            <label for="cp" class="form-label block mb-1 text-sm font-medium">CP</label>
                            <input type="text" id="cp" name="cp" class="themed-input w-full p-2.5 text-sm rounded-lg">
                        </div>
                        <div>
                            <label for="csc" class="form-label block mb-1 text-sm font-medium">CSC</label>
                            <input type="text" id="csc" name="csc" class="themed-input w-full p-2.5 text-sm rounded-lg">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="qb_user" class="form-label block mb-1 text-sm font-medium">QB USER</label>
                            <input type="text" id="qb_user" name="qb_user" class="themed-input w-full p-2.5 text-sm rounded-lg" placeholder="e.g., 1234567">
                        </div>
                        <div>
                            <label for="qb_userdebug" class="form-label block mb-1 text-sm font-medium">QB USERDEBUG</label>
                            <input type="text" id="qb_userdebug" name="qb_userdebug" class="themed-input w-full p-2.5 text-sm rounded-lg" placeholder="e.g., 1234568">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="test_plan_type" class="form-label block mb-1 text-sm font-medium">Type Test Plan</label>
                            <select id="test_plan_type" name="test_plan_type" class="themed-input w-full p-2.5 text-sm rounded-lg" required>
                                <option>Regular Variant</option>
                                <option>SKU</option>
                                <option>Normal MR</option>
                                <option>SMR</option>
                                <option>Simple Exception MR</option>
                            </select>
                        </div>
                        <div>
                            <label for="progress_status" class="form-label block mb-1 text-sm font-medium">Status Progress</label>
                            <select id="progress_status" name="progress_status" class="themed-input w-full p-2.5 text-sm rounded-lg important-field" required>
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
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                        <div>
                            <label for="request_date" class="form-label block mb-1 text-sm font-medium">Request Date</label>
                            <input type="date" id="request_date" name="request_date" class="themed-input w-full p-2 text-sm rounded-lg">
                        </div>
                        <div>
                            <label for="submission_date" class="form-label block mb-1 text-sm font-medium">Submission Date</label>
                            <input type="date" id="submission_date" name="submission_date" class="themed-input w-full p-2 text-sm rounded-lg important-field">
                        </div>
                        <div>
                            <label for="approved_date" class="form-label block mb-1 text-sm font-medium">Approved Date</label>
                            <input type="date" id="approved_date" name="approved_date" class="themed-input w-full p-2 text-sm rounded-lg important-field">
                        </div>
                        <div>
                            <label for="deadline" class="form-label block mb-1 text-sm font-medium">Deadline</label>
                            <input type="date" id="deadline" name="deadline" class="themed-input w-full p-2 text-sm rounded-lg">
                        </div>
                        <div>
                            <label for="sign_off_date" class="form-label block mb-1 text-sm font-medium">Sign-Off Date</label>
                            <input type="date" id="sign_off_date" name="sign_off_date" class="themed-input w-full p-2 text-sm rounded-lg">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="base_submission_id" class="form-label block mb-1 text-sm font-medium">Base Submission ID</label>
                            <input type="text" id="base_submission_id" name="base_submission_id" class="themed-input w-full p-2.5 text-sm rounded-lg">
                        </div>
                        <div>
                            <label for="submission_id" class="form-label block mb-1 text-sm font-medium">Submission ID</label>
                            <input type="text" id="submission_id" name="submission_id" class="themed-input w-full p-2.5 text-sm rounded-lg important-field">
                        </div>
                        <div>
                            <label for="reviewer_email" class="form-label block mb-1 text-sm font-medium">Reviewer Email</label>
                            <input type="email" id="reviewer_email" name="reviewer_email" class="themed-input w-full p-2.5 text-sm rounded-lg important-field">
                        </div>
                    </div>
                    
                    <div>
                        <label class="form-label block mb-1 text-sm font-medium">Test Items Checklist</label>
                        <div class="glass-card p-4 rounded-lg">
                            <div id="checklist-placeholder" class="text-sm text-secondary">Pilih Tipe Test Plan untuk melihat checklist.</div>
                            
                            <?php foreach($test_plan_items_form as $plan => $items):
                                $plan_id = str_replace(' ', '_', $plan); ?>
                            <div id="checklist-container-<?= $plan_id ?>" class="hidden space-y-2">
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                    <?php foreach($items as $item): 
                                        $item_id = str_replace([' ', '-'], '_', $item); ?>
                                    <div class="flex items-center">
                                        <input id="checklist_<?= $plan_id ?>_<?= $item_id ?>" name="checklist[<?= $item_id ?>]" type="checkbox" value="1" class="w-4 h-4 text-blue-600 bg-gray-700 border-gray-600 rounded">
                                        <label for="checklist_<?= $plan_id ?>_<?= $item_id ?>" class="ml-2 text-sm text-primary"><?= htmlspecialchars($item) ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div>
                        <label for="notes-editor" class="form-label block mb-1 text-sm font-medium">Notes</label>
                        <input type="hidden" name="notes" id="notes-hidden-input">
                        <div id="notes-editor" class="themed-input rounded-lg"></div>
                    </div>
                </div>
                </form>
        </div>
    </div>
    
<script>
    // =========================================================================
    // DARK MODE TOGGLE SCRIPT (REAL-TIME FIX)
    // Diletakkan di awal agar tema diterapkan secepat mungkin
    // =========================================================================
    const root = document.documentElement;
    const themeToggleBtn = document.getElementById('theme-toggle');

    function applyTheme(isLight) {
        // Toggles 'light' class on <html> element
        root.classList.toggle('light', isLight);
        root.classList.toggle('dark', !isLight); 
        
        // Toggles icons (Icons diasumsikan ada di header.php)
        const lightIcon = document.getElementById('theme-toggle-light-icon');
        const darkIcon = document.getElementById('theme-toggle-dark-icon');
        if (lightIcon) lightIcon.classList.toggle('hidden', !isLight);
        if (darkIcon) darkIcon.classList.toggle('hidden', isLight);
    }

    // 1. Muat tema awal dari Local Storage (Default ke dark)
    const savedTheme = localStorage.getItem('theme') || 'dark';
    const initialIsLight = savedTheme === 'light';
    applyTheme(initialIsLight);

    // 2. Attach click listener untuk toggle real-time
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const isCurrentlyLight = root.classList.contains('light');
            const newIsLight = !isCurrentlyLight;

            localStorage.setItem('theme', newIsLight ? 'light' : 'dark');
            applyTheme(newIsLight);
        });
    }
    
    // =========================================================================
    // INISIALISASI VARIABEL GLOBAL & ANIMASI
    // =========================================================================
    const canvas = document.getElementById('neural-canvas'), ctx = canvas.getContext('2d');
    const modal = document.getElementById('task-modal'); 
    const modalTitle = document.getElementById('modal-title');
    const taskForm = document.getElementById('task-form'); 
    const todoModal = document.getElementById('todo-modal');
    
    // Variabel Quill Editor
    let quill, todoQuill; 
    
    let particles = [], hue = 210;
    
    // Fungsi Animasi (tetap sama)
    function setCanvasSize(){canvas.width=window.innerWidth;canvas.height=window.innerHeight;}setCanvasSize();
    class Particle{constructor(x,y){this.x=x||Math.random()*canvas.width;this.y=y||Math.random()*canvas.height;this.vx=(Math.random()-.5)*.4;this.vy=(Math.random()-.5)*.4;this.size=Math.random()*2+1.5}update(){this.x+=this.vx;this.y+=this.vy;if(this.x<0||this.x>canvas.width)this.vx*=-1;if(this.y<0||this.y>canvas.height)this.vy*=-1}draw(){ctx.fillStyle=`hsl(${hue},100%,75%)`;ctx.beginPath();ctx.arc(this.x,this.y,this.size,0,Math.PI*2);ctx.fill()}}
    function init(num){particles=[];for(let i=0;i<num;i++)particles.push(new Particle())}
    function handleParticles(){for(let i=0;i<particles.length;i++){particles[i].update();particles[i].draw();for(let j=i;j<particles.length;j++){const dx=particles[i].x-particles[j].x;const dy=particles[i].y-particles[j].y;const distance=Math.sqrt(dx*dx+dy*dy);if(distance<120){ctx.beginPath();ctx.strokeStyle=`hsla(${hue},100%,80%,${1-distance/120})`;ctx.lineWidth=1;ctx.moveTo(particles[i].x,particles[i].y);ctx.lineTo(particles[j].x,particles[j].y);ctx.stroke();ctx.closePath()}}}}
    function animate(){ctx.clearRect(0,0,canvas.width,canvas.height);hue=(hue+.3)%360;handleParticles();requestAnimationFrame(animate)}
    const particleCount=window.innerWidth>768?150:70;init(particleCount);animate();

    // Event Listener Resize
    window.addEventListener('resize',()=>{setCanvasSize();init(particleCount);});
    
    // =========================================================================
    // HELPER & MODAL LOGIC (Unchanged from user's submission)
    // =========================================================================

    const actionChooserModal = document.getElementById('action-chooser-modal');
    const actionChooserDateDisplay = document.getElementById('action-chooser-date');
    const actionChooserDateInput = document.getElementById('selected-date-input');

    const currentMonth = '<?= $current_month ?>';
    const currentEmail = '<?= $_SESSION['user_details']['email'] ?>';

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
    function getTodayDate(){return new Date().toISOString().slice(0, 10)}

    function setupTodoQuill(content){
        if(!todoQuill){
            todoQuill=new Quill('#todo-notes-editor',{
                theme:'snow',
                modules:{toolbar:[['bold','italic','underline'],['link'],[{'list':'ordered'},{'list':'bullet'}]]}
            });
        }
        todoQuill.root.innerHTML = content;
    }

    document.getElementById('todo-form').addEventListener('submit', (e) => {
        document.getElementById('todo-notes-hidden-input').value = todoQuill.root.innerHTML;
    });

    // Main Modal Functions
    // FIX: openDateCellAction now calls startTodo directly
    window.openDateCellAction = function(cellElement, dateStr, isOtherMonth) {
        if (isOtherMonth || event.target.closest('.task-item')) {
            return;
        }
        
        // Langsung panggil startTodo()
        startTodo(dateStr);
    }

    window.closeActionChooserModal = function() {
        actionChooserModal.classList.add('hidden');
    }

    window.closeTodoModal = function() {
        todoModal.classList.add('hidden');
    }
    
    // Action Chooser handlers (Keep these functions as they are called by the action chooser buttons)
    window.startNewTask = function() {
        // closeActionChooserModal(); // Ini tidak diperlukan jika action chooser tidak dipanggil
        const dateStr = getTodayDate(); // Menggunakan tanggal hari ini jika dipanggil dari tombol header

        taskForm.reset();
        modalTitle.innerText = 'Tambah Task Baru';
        taskForm.elements['action'].value = 'create_gba_task';
        taskForm.elements['id'].value = '';
        
        document.getElementById('request_date').value = dateStr;
        const deadlineDate = calculateWorkingDays(dateStr, 7);
        document.getElementById('deadline').value = deadlineDate;
        document.getElementById('sign_off_date').value = deadlineDate;
        
        document.getElementById('submission_date').value = '';
        document.getElementById('approved_date').value = '';
        document.getElementById('progress_status').value = 'Task Baru';
        setupQuill('');
        updateChecklistVisibility();
        modal.classList.remove('hidden');
    }
    
    // FIX: startTodo modified to accept date directly and skips action chooser
    window.startTodo = function(dateStr) {
        // Jika dipanggil dari tombol header 'Task Baru', dateStr mungkin kosong, ambil dari current date
        dateStr = dateStr || getTodayDate();
        const dateObj = new Date(dateStr + 'T00:00:00');
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        
        // Setup for CREATE mode
        document.getElementById('todo-form').reset();
        document.getElementById('todo-modal-title').textContent = 'Tambah Catatan / To-Do';
        document.getElementById('todo-action').value = 'create_todo_note';
        document.getElementById('todo-id-input').value = '';
        document.getElementById('todo-delete-container').classList.add('hidden');
        
        // Prefill data
        document.getElementById('todo-date-input').value = dateStr;
        document.getElementById('todo-date-display').textContent = dateObj.toLocaleDateString('id-ID', options);
        setupTodoQuill(''); 

        todoModal.classList.remove('hidden');
    }

    window.openEditNoteModal = function(noteData) {
        const dateObj = new Date(noteData.note_date + 'T00:00:00');
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        
        // Setup for EDIT mode
        document.getElementById('todo-form').reset();
        document.getElementById('todo-modal-title').textContent = 'Edit Catatan / To-Do';
        document.getElementById('todo-action').value = 'update_todo_note';
        document.getElementById('todo-id-input').value = noteData.id;
        
        // Prefill fields
        document.getElementById('todo-date-input').value = noteData.note_date;
        document.getElementById('todo-date-display').textContent = dateObj.toLocaleDateString('id-ID', options);
        document.getElementById('todo-title-input').value = noteData.title;
        document.getElementById('todo_pic_email').value = noteData.user_email || '';
        document.getElementById('todo_priority').value = noteData.priority || 'Low';
        setupTodoQuill(noteData.content || '');
        
        // Show delete button only if it's the current user's note
        const isUserNote = noteData.user_email === currentEmail;
        if (isUserNote) {
             document.getElementById('todo-delete-container').classList.remove('hidden');
        } else {
             document.getElementById('todo-delete-container').classList.add('hidden');
        }

        todoModal.classList.remove('hidden');
    }
    
    window.deleteTodoNote = function(noteId, currentMonth) {
        if (!confirm("Apakah Anda yakin ingin menghapus catatan ini?")) {
            return;
        }

        fetch('handler.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'delete_todo_note',
                id: noteId,
                return_month: currentMonth
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert("Catatan berhasil dihapus! Halaman akan di-refresh.");
                window.location.reload();
            } else {
                alert(`Gagal menghapus catatan: ${data.error}.`);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert("Terjadi kesalahan jaringan saat menghapus.");
        });
    }

    // Existing functions (reused)
    function openAddModal() {
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
        modal.classList.remove('hidden');
    }

    function openEditModal(taskData) { 
        taskForm.reset(); 
        modalTitle.innerText = 'Edit Task'; 
        taskForm.elements['action'].value = 'update_gba_task'; 
        
        for (const key in taskData) { 
            if (taskForm.elements[key] && !key.endsWith('_obj')) { 
                if (key === 'is_urgent') { 
                    document.getElementById('is_urgent_toggle').checked = taskData[key] == 1; 
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
        modal.classList.remove('hidden'); 
    }
    
    function closeModal() { 
        modal.classList.add('hidden'); 
    }
    
    function setupQuill(content){if(!quill){quill=new Quill('#notes-editor',{theme:'snow',modules:{toolbar:[['bold','italic','underline'],['link'],[{'list':'ordered'},{'list':'bullet'}]]}});quill.root.innerHTML=content}else{quill.root.innerHTML=content}}
    
    function updateChecklistVisibility(){
        const testPlan=document.getElementById('test_plan_type').value,
              placeholder=document.getElementById('checklist-placeholder');
        let checklistVisible=!1;
        document.querySelectorAll('[id^="checklist-container-"]').forEach(el=>{
            const planName=el.id.replace('checklist-container-','').replace(/_/g,' ');
            if(planName===testPlan){el.classList.remove('hidden');checklistVisible=!0}
            else{el.classList.add('hidden')}
        });
        placeholder.style.display=checklistVisible?'none':'block';
    }
    
    taskForm.addEventListener('submit', () => {
        document.getElementById('notes-hidden-input').value = quill.root.innerHTML;
    });

    // Event listener untuk auto-fill tanggal di modal
    const submissionDateInput = document.getElementById('submission_date');
    const approvedDateInput = document.getElementById('approved_date');
    const progressStatusSelect = document.getElementById('progress_status');
    
    progressStatusSelect.addEventListener('change',e=>{
        const status=e.target.value;
        if(status === 'Submitted' || status === 'Approved' || status === 'Passed'){
            if(!submissionDateInput.value){submissionDateInput.value=getTodayDate()}
            if(status === 'Approved' || status === 'Passed'){
                if(!approvedDateInput.value){approvedDateInput.value=getTodayDate()}
            }
            const visibleChecklist = document.querySelector('[id^="checklist-container-"]:not(.hidden)');
            if (visibleChecklist) { visibleChecklist.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = true; }); }

        } else if (status === 'Task Baru') {
            const visibleChecklist = document.querySelector('[id^="checklist-container-"]:not(.hidden)');
            if (visibleChecklist) { visibleChecklist.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = false; }); }
            submissionDateInput.value = '';
            approvedDateInput.value = '';
        }
    });


    // =========================================================================
    // CALENDAR FILTER LOGIC (MODIFIED DEFAULT)
    // =========================================================================
    const filterTaskCheckbox = document.getElementById('filter-task');
    const filterNoteCheckbox = document.getElementById('filter-note');

    function loadFilters() {
        // Load state from localStorage, default to:
        // filterTask: false (default tidak tampil)
        // filterNote: true (default tampil)
        
        // Cek apakah ada nilai di localStorage. Jika tidak ada, gunakan default.
        // Jika localStorage.getItem('filterTask') MENGEMBALIKAN NULL, maka defaultnya adalah 'false'.
        const showTasks = localStorage.getItem('filterTask') !== null 
                         ? localStorage.getItem('filterTask') === 'true' 
                         : false; // DEFAULT BARU: false
                         
        const showNotes = localStorage.getItem('filterNote') !== null 
                         ? localStorage.getItem('filterNote') === 'true' 
                         : true; // DEFAULT: true
        
        if (filterTaskCheckbox) {
            filterTaskCheckbox.checked = showTasks;
            filterTaskCheckbox.addEventListener('change', saveAndApplyFilters);
        }
        if (filterNoteCheckbox) {
            filterNoteCheckbox.checked = showNotes;
            filterNoteCheckbox.addEventListener('change', saveAndApplyFilters);
        }
    }

    function saveAndApplyFilters() {
        const showTasks = filterTaskCheckbox ? filterTaskCheckbox.checked : true;
        const showNotes = filterNoteCheckbox ? filterNoteCheckbox.checked : true;
        
        localStorage.setItem('filterTask', showTasks);
        localStorage.setItem('filterNote', showNotes);
        
        applyFilters(showTasks, showNotes);
    }
    
    function applyFilters(showTasks, showNotes) {
        // Gunakan nilai dari checkbox jika parameter tidak disediakan (hanya dipanggil saat DOMContentLoaded)
        showTasks = showTasks === undefined ? (filterTaskCheckbox ? filterTaskCheckbox.checked : true) : showTasks;
        showNotes = showNotes === undefined ? (filterNoteCheckbox ? filterNoteCheckbox.checked : true) : showNotes;
        
        // Iterate through all task items and toggle visibility
        document.querySelectorAll('.task-item').forEach(item => {
            const itemType = item.getAttribute('data-item-type');
            let isVisible = true;
            
            if (itemType === 'task' && !showTasks) {
                isVisible = false;
            } else if (itemType === 'note' && !showNotes) {
                isVisible = false;
            }
            
            // Menggunakan style.display: 'flex' karena task-item memiliki display: flex
            item.style.display = isVisible ? 'flex' : 'none';
        });
    }

    // --- DOM Load ---
    document.addEventListener('DOMContentLoaded', () => {
        setupQuill('');
        setupTodoQuill(''); 
        updateChecklistVisibility();
        
        // Panggil fungsi filter baru
        // Ini akan memuat status dari localStorage atau menggunakan default (Task: false, Note: true)
        loadFilters();
        applyFilters(); 
        
        const profileMenu = document.getElementById('profile-menu');
        if (profileMenu) {
            const profileButton = profileMenu.querySelector('button');
            const profileDropdown = document.getElementById('profile-dropdown');
            profileButton.addEventListener('click', e => { e.stopPropagation(); profileDropdown.classList.toggle('hidden'); });
            document.addEventListener('click', e => { if (!profileMenu.contains(e.target)) { profileDropdown.classList.add('hidden'); } });
        }
        
        // Auto-close success/error alerts and clean URL
        const successAlert = document.getElementById('success-alert');
        const errorAlert = document.getElementById('error-alert');
        
        if (successAlert || errorAlert) {
            setTimeout(() => {
                if (successAlert) successAlert.remove();
                if (errorAlert) errorAlert.remove();
                
                // Clean URL after timeout
                if (window.location.search.includes('success=') || window.location.search.includes('error=')) {
                     window.history.replaceState({}, document.title, "monthly_calendar.php?month=<?= $current_month ?>");
                }
            }, 3000);
        }
        
        // Close modal on outside click (for all 3 modals)
        window.onclick = function(event) {
            if (event.target == document.getElementById('todo-modal')) {
                if (event.target.classList.contains('modal-backdrop-blur')) {
                    closeTodoModal();
                }
            } else if (event.target == document.getElementById('task-modal')) {
                if (event.target.classList.contains('modal-backdrop-blur')) {
                    closeModal();
                }
            }
        }
    });
</script>
</body>
</html>