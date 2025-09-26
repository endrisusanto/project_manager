<?php
// 1. INISIALISASI
require_once "config.php";
require_once "session.php"; // Memastikan pengguna sudah login
$active_page = 'gba_tasks';

// 2. LOGIKA PENGAMBILAN & PEMROSESAN DATA
$all_tasks_sql = "
    SELECT t.model_name, t.ap, u.username, t.pic_email
    FROM gba_tasks t
    LEFT JOIN users u ON t.pic_email = u.email
    WHERE t.progress_status NOT IN ('Approved', 'Batal') AND t.model_name IS NOT NULL AND t.ap IS NOT NULL AND t.ap != ''
";
$all_tasks_result = $conn->query($all_tasks_sql);

$model_data = [];
if ($all_tasks_result) {
    while ($row = $all_tasks_result->fetch_assoc()) {
        $model_name_full = $row['model_name'];
        // Menggunakan username jika ada, jika tidak, ambil bagian sebelum '@' dari email
        $pic_identifier = $row['username'] ?? strtok($row['pic_email'], '@');
        $model_data[$model_name_full][] = [
            'ap' => $row['ap'],
            'user' => $pic_identifier
        ];
    }
}

// ===== LOGIKA DUPLIKAT YANG DIPERBARUI =====
$duplicate_ap_tasks = [];
foreach ($model_data as $model => $details) {
    // Lewati jika hanya ada satu task untuk model ini
    if (count($details) < 2) {
        continue;
    }

    $is_duplicate = false;
    $all_aps = array_column($details, 'ap');

    // KONDISI 1: Terdeteksi jika ada lebih dari satu AP unik untuk model yang sama.
    // Ini mencakup:
    // - PIC yang sama, AP berbeda.
    // - PIC berbeda, AP berbeda.
    if (count(array_unique($all_aps)) > 1) {
        $is_duplicate = true;
    }

    // KONDISI 2: Jika semua AP sama, periksa apakah ada duplikasi entri atau dikerjakan oleh PIC berbeda.
    if (!$is_duplicate) {
        // array_count_values akan menghasilkan [AP => jumlah]. Jika ada AP yang jumlahnya lebih dari 1, berarti duplikat.
        // Ini mencakup:
        // - AP sama, PIC sama (entri ganda).
        // - AP sama, PIC berbeda.
        $ap_counts = array_count_values($all_aps);
        foreach ($ap_counts as $count) {
            if ($count > 1) {
                $is_duplicate = true;
                break;
            }
        }
    }

    if ($is_duplicate) {
        $duplicate_ap_tasks[$model] = $details;
    }
}
// ===== AKHIR DARI LOGIKA DUPLIKAT =====


// Query untuk menampilkan data di tabel (berlaku sesuai hak akses)
$sql = "SELECT * FROM gba_tasks";
$params = [];
$types = "";

$where_clauses = ["progress_status NOT IN ('Approved', 'Batal')"];
if (!is_admin()) {
    $where_clauses[] = "pic_email = ?";
    $params[] = $_SESSION['user_details']['email'];
    $types .= "s";
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$sql .= " ORDER BY id DESC";
$stmt = $conn->prepare($sql);

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $tasks_result = $stmt->get_result();
} else {
    $tasks_result = false;
}

$tasks = [];
$test_plan_items = [
    'Regular Variant' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'], 'SKU' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
    'Normal MR' => ['CTS', 'GTS', 'CTS-Verifier', 'ATM'], 'SMR' => ['CTS', 'GTS', 'STS', 'SCAT'], 'Simple Exception MR' => ['STS']
];
$all_test_plans = array_keys($test_plan_items);
$all_statuses = ['Task Baru', 'Test Ongoing', 'Pending Feedback', 'Feedback Sent', 'Submitted', 'Passed', 'Batal'];


if ($tasks_result) {
    while ($row = $tasks_result->fetch_assoc()) {
        $row['request_date_obj'] = $row['request_date'] ? new DateTime($row['request_date']) : null;
        $row['submission_date_obj'] = $row['submission_date'] ? new DateTime($row['submission_date']) : null;
        $row['approved_date_obj'] = isset($row['approved_date']) && $row['approved_date'] ? new DateTime($row['approved_date']) : null;
        $deadline_date = $row['deadline'] ? new DateTime($row['deadline']) : null;
        
        if ($row['submission_date_obj'] && $row['request_date_obj']) {
            $submission_diff = $row['submission_date_obj']->diff($row['request_date_obj'])->days;
            $row['ontime_submission_status'] = $submission_diff <= 7 ? 'Ontime' : 'Delay';
        } else { $row['ontime_submission_status'] = null; }
        
        if ($row['approved_date_obj'] && $row['submission_date_obj']) {
            $approval_diff = $row['approved_date_obj']->diff($row['submission_date_obj'])->days;
            $row['ontime_approved_status'] = $approval_diff <= 3 ? 'Ontime' : 'Delay';
        } else { $row['ontime_approved_status'] = null; }
    
        $row['deadline_countdown'] = null;
        if (!$row['submission_date_obj'] && $deadline_date) {
            $now = new DateTime(); $now->setTime(0,0,0); $deadline_date->setTime(0,0,0);
            $diff = $now->diff($deadline_date);
            $row['deadline_countdown'] = ($now <= $deadline_date) ? $diff->days : -$diff->days;
        }
    
        $row['approval_countdown'] = null;
        if ($row['submission_date_obj'] && !$row['approved_date_obj']) {
            $approval_deadline = (clone $row['submission_date_obj'])->modify('+3 days');
            $now = new DateTime(); $now->setTime(0,0,0); $approval_deadline->setTime(0,0,0);
            $diff = $now->diff($approval_deadline);
            $row['approval_countdown'] = ($now <= $approval_deadline) ? $diff->days : -$diff->days;
        }
        
        $checklist = json_decode($row['test_items_checklist'], true);
        $plan_type = $row['test_plan_type'];
        $total_items = isset($test_plan_items[$plan_type]) ? count($test_plan_items[$plan_type]) : 0;
        $completed_items = 0;
        if ($total_items > 0 && is_array($checklist)) {
            foreach ($test_plan_items[$plan_type] as $item) {
                $item_key = str_replace([' ', '-'], '_', $item);
                if (!empty($checklist[$item_key])) { $completed_items++; }
            }
        }
        $row['progress_percentage'] = $total_items > 0 ? ($completed_items / $total_items) * 100 : 0;
        $tasks[] = $row;
    }
}

// 3. FUNGSI HELPER TAMPILAN
function getDynamicColorClasses($identifier, $type = 'pic') {
    $pic_colors = ['sky', 'emerald', 'amber', 'rose', 'violet', 'teal', 'cyan'];
    $plan_colors = ['indigo', 'lime', 'pink', 'orange', 'fuchsia'];
    $palette = ($type === 'plan') ? $plan_colors : $pic_colors;
    $hash = crc32($identifier);
    return "badge-color-" . $palette[$hash % count($palette)];
}
function getStatusColorClasses($status) {
    $colors = ['Approved'=>'badge-color-green','Passed'=>'badge-color-green','Submitted'=>'badge-color-purple','Test Ongoing'=>'badge-color-yellow','Task Baru'=>'badge-color-blue','Batal'=>'badge-color-gray','Pending Feedback'=>'badge-color-orange','Feedback Sent'=>'badge-color-orange'];
    return $colors[$status] ?? 'badge-color-gray';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GBA Task Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <style>
        :root{--bg-primary:#020617;--text-primary:#e2e8f0;--text-secondary:#94a3b8;--glass-bg:rgba(15,23,42,.4);--glass-border:rgba(51,65,85,.4);--modal-bg:rgba(15,23,42,.6);--modal-border:rgba(51,65,85,.6);--input-bg:rgba(30,41,59,.7);--input-border:#475569;--progress-bg:#1e293b;--progress-fill:#3b82f6;--toast-bg:#22c55e;--toast-text:#fff;--filter-btn-bg:rgba(255,255,255,.05);--filter-btn-bg-active:#2563eb;--text-header:#fff;--text-icon:#94a3b8}html.light{--bg-primary:#f1f5f9;--text-primary:#0f172a;--text-secondary:#475569;--glass-bg:rgba(255,255,255,.35);--glass-border:rgba(0,0,0,.08);--modal-bg:rgba(255,255,255,.6);--modal-border:rgba(0,0,0,.1);--input-bg:#fff;--input-border:#cbd5e1;--progress-bg:#e2e8f0;--toast-bg:#16a34a;--filter-btn-bg:rgba(0,0,0,.05);--text-header:#0f172a;--text-icon:#475569}
        html{scroll-behavior:smooth}body{font-family:'Inter',sans-serif;background-color:var(--bg-primary);color:var(--text-primary)}html,body{height:100%;overflow:hidden}main{height:calc(100% - 64px)}.table-container{scroll-behavior:smooth}#neural-canvas{position:fixed;top:0;left:0;width:100%;height:100%;z-index:-1}.glass-container{background:var(--glass-bg);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border-bottom:1px solid var(--glass-border)}.glassmorphism-table{background:var(--glass-bg);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid var(--glass-border)}.glassmorphism-modal{background:var(--modal-bg);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid var(--modal-border)}
        .nav-link{color:var(--text-secondary);transition:color .2s,border-color .2s;border-bottom:2px solid transparent}.nav-link:hover{color:var(--text-primary)}.nav-link-active{color:var(--text-primary)!important;font-weight:500;border-bottom:2px solid #3b82f6}.themed-input{background-color:var(--input-bg);border:1px solid var(--input-border)}html.light .themed-input,html.light .ql-editor{color:var(--text-primary)}.themed-input:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 2px rgba(59,130,246,.5)}input[type="date"]::-webkit-calendar-picker-indicator{filter:invert(var(--date-picker-invert,1))}html.light{--date-picker-invert:0}.ql-toolbar,.ql-container{border-color:var(--glass-border)!important}.ql-editor{color:var(--text-primary);min-height:100px}.ql-snow .ql-stroke{stroke:var(--text-icon)}.ql-snow .ql-picker-label{color:var(--text-icon)}
        .progress-bar-bg{background-color:var(--progress-bg)}.progress-bar-fill{background-color:var(--progress-fill);transition:width .6s ease-in-out;background-image:linear-gradient(45deg,rgba(255,255,255,.15) 25%,transparent 25%,transparent 50%,rgba(255,255,255,.15) 50%,rgba(255,255,255,.15) 75%,transparent 75%,transparent);background-size:1rem 1rem;animation:progress-bar-stripes 1s linear infinite}@keyframes progress-bar-stripes{from{background-position:1rem 0}to{background-position:0 0}}.progress-text{background-color:rgba(0,0,0,.4);padding:0 6px;border-radius:6px;color:#fff}
        #toast{position:fixed;bottom:-100px;left:50%;transform:translateX(-50%);background-color:var(--toast-bg);color:var(--toast-text);padding:12px 20px;border-radius:8px;z-index:1000;transition:bottom .5s ease-in-out}#toast.show{bottom:30px}
        .filter-button{background-color:var(--filter-btn-bg);color:var(--text-secondary);transition:all .2s}.filter-button:hover{background-color:rgba(255,255,255,.1)}html.light .filter-button:hover{background-color:rgba(0,0,0,.1)}.filter-button.active{background-color:var(--filter-btn-bg-active);color:#fff}
        .badge{display:inline-block;padding:.25rem .6rem;font-size:.75rem;font-weight:500;border-radius:.75rem;line-height:1.2}.badge-color-sky{background-color:rgba(14,165,233,.2);color:#7dd3fc}.badge-color-emerald{background-color:rgba(16,185,129,.2);color:#6ee7b7}.badge-color-amber{background-color:rgba(245,158,11,.2);color:#fcd34d}.badge-color-rose{background-color:rgba(244,63,94,.2);color:#fda4af}.badge-color-violet{background-color:rgba(139,92,246,.2);color:#c4b5fd}.badge-color-teal{background-color:rgba(20,184,166,.2);color:#5eead4}.badge-color-cyan{background-color:rgba(6,182,212,.2);color:#67e8f9}.badge-color-indigo{background-color:rgba(99,102,241,.2);color:#a5b4fc}.badge-color-lime{background-color:rgba(132,204,22,.2);color:#bef264}.badge-color-pink{background-color:rgba(236,72,153,.2);color:#f9a8d4}.badge-color-fuchsia{background-color:rgba(217,70,239,.2);color:#f0abfc}.badge-color-green{background-color:rgba(34,197,94,.2);color:#86efac}.badge-color-purple{background-color:rgba(168,85,247,.2);color:#d8b4fe}.badge-color-yellow{background-color:rgba(234,179,8,.2);color:#fde047}.badge-color-blue{background-color:rgba(59,130,246,.2);color:#93c5fd}.badge-color-gray{background-color:rgba(107,114,128,.2);color:#d1d5db}.badge-color-orange{background-color:rgba(249,115,22,.2);color:#fdba74}
        html.light .badge-color-sky{background-color:#e0f2fe;color:#0369a1}html.light .badge-color-emerald{background-color:#d1fae5;color:#047857}html.light .badge-color-amber{background-color:#fef3c7;color:#92400e}html.light .badge-color-rose{background-color:#ffe4e6;color:#9f1239}html.light .badge-color-violet{background-color:#ede9fe;color:#5b21b6}html.light .badge-color-teal{background-color:#ccfbf1;color:#0d9488}html.light .badge-color-cyan{background-color:#cffafe;color:#0e7490}html.light .badge-color-indigo{background-color:#e0e7ff;color:#3730a3}html.light .badge-color-lime{background-color:#ecfccb;color:#4d7c0f}html.light .badge-color-pink{background-color:#fce7f3;color:#9d174d}html.light .badge-color-fuchsia{background-color:#fae8ff;color:#86198f}html.light .badge-color-green{background-color:#dcfce7;color:#15803d}html.light .badge-color-purple{background-color:#f3e8ff;color:#6b21a8}html.light .badge-color-yellow{background-color:#fef9c3;color:#854d0e}html.light .badge-color-blue{background-color:#dbeafe;color:#1e40af}html.light .badge-color-gray{background-color:#f3f4f6;color:#374151}html.light .badge-color-orange{background-color:#ffedd5;color:#9a3412}html.light .font-semibold.text-green-400{color:#15803d}html.light .font-semibold.text-red-400{color:#b91c1c}
        @keyframes pulse-alert{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.2);opacity:.7}}.animate-pulse-alert{animation:pulse-alert 1.5s infinite;color:#f87171}html.light .animate-pulse-alert{color:#dc2626}
        .qb-link{cursor:pointer;text-decoration:underline;color:#93c5fd}html.light .qb-link{color:#1e40af}
        .urgent-row { position: relative; border-left: 3px solid transparent; animation: urgent-row-glow 1.5s infinite; }
        @keyframes urgent-row-glow {
            0%, 100% { border-left-color: rgba(239, 68, 68, 0.7); box-shadow: inset 3px 0 8px -2px rgba(239, 68, 68, 0.5); }
            50% { border-left-color: rgba(239, 68, 68, 0.4); box-shadow: inset 3px 0 15px -2px rgba(239, 68, 68, 0.3); }
        }
        .table-container td { vertical-align: middle; }
        #pagination-rows { color: var(--text-primary); }
        #pagination-rows option { background-color: var(--bg-primary); color: var(--text-primary); }
        
        #alert-carousel { position: relative; overflow: hidden; }
        .alert-carousel-inner { display: flex; transition: transform 0.5s ease-in-out; }
        .alert-carousel-item { min-width: 100%; box-sizing: border-box; }
        .alert-content { padding-left: 3.5rem; padding-right: 3.5rem; }
        .carousel-btn { position: absolute; top: 50%; transform: translateY(-50%); background-color: rgba(0,0,0,0.2); border-radius: 9999px; padding: 0.5rem; color: #fff; z-index: 10; }
        .carousel-btn:hover { background-color: rgba(0,0,0,0.4); }
        .carousel-btn.prev { left: 0.75rem; }
        .carousel-btn.next { right: 0.75rem; }
        #task-modal .ql-editor { min-height: 42px; padding-top: 10px; padding-bottom: 10px; }
        #task-modal .ql-container { border-top: 1px solid var(--glass-border) !important; border-radius: .5rem; }
    </style>
</head>
<body class="min-h-screen">
    <canvas id="neural-canvas"></canvas>
    <div id="toast">Link QB berhasil disalin!</div>

    <?php include 'header.php'; ?>

    <main class="w-full h-full flex flex-col">
        <?php if (!empty($duplicate_ap_tasks)): ?>
        <div class="px-4 sm:px-6 lg:px-8 pt-4">
            <div id="alert-carousel" class="alert-carousel rounded-lg" onmouseenter="pauseCarousel()" onmouseleave="resumeCarousel()">
                <div class="alert-carousel-inner">
                    <?php
                    $alert_colors = ['bg-yellow-400', 'bg-red-400', 'bg-blue-400'];
                    $color_index = 0;
                    foreach ($duplicate_ap_tasks as $model => $details):
                        $color_class = $alert_colors[$color_index % count($alert_colors)];
                        $color_index++;
                    ?>
                    <div class="alert-carousel-item relative p-1 border <?php echo $color_class; ?> text-gray-800 grid items-center text-center" role="alert">
                        <div>
                            <div class="flex items-center justify-center">
                                <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd"></path></svg>
                                <p class="text-xs font-semibold">Peringatan Duplikat Task untuk Model <span class="font-bold underline"><?php echo htmlspecialchars($model); ?></span></p>
                            </div>
                            <div class="mt-1 text-xs">
                                <?php foreach ($details as $info): ?>
                                    <div>
                                        <span class="font-semibold"><?php echo htmlspecialchars($info['ap']); ?></span>
                                        <span class="font-medium">(PIC: <?php echo htmlspecialchars($info['user']); ?>)</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($duplicate_ap_tasks) > 1): ?>
                <button type="button" class="carousel-btn prev" onclick="moveSlide(-1)">&#10094;</button>
                <button type="button" class="carousel-btn next" onclick="moveSlide(1)">&#10095;</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="px-4 sm:px-6 lg:px-8 pt-6 pb-4">
            <div class="flex flex-col sm:flex-row gap-4">
                <div id="testplan-filter-container" class="flex items-center space-x-2 flex-shrink-0 overflow-x-auto pb-2">
                    <button class="filter-button active px-3 py-1.5 text-sm font-medium rounded-md" data-plan="All">Semua</button>
                    <?php foreach($all_test_plans as $plan): ?>
                        <button class="filter-button px-3 py-1.5 text-sm font-medium rounded-md" data-plan="<?= htmlspecialchars($plan) ?>"><?= htmlspecialchars($plan) ?></button>
                    <?php endforeach; ?>
                </div>
                 <div class="flex items-center gap-4 ml-auto">
                    <div>
                        <select id="status-filter" class="themed-input p-2 rounded-lg text-sm">
                            <option value="All">Semua Status</option>
                            <?php foreach($all_statuses as $status): ?>
                                <option value="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-secondary">Baris:</span>
                        <select id="pagination-rows" class="themed-input p-2 rounded-lg text-sm"><option value="5">5</option><option value="10" selected>10</option><option value="30">30</option><option value="50">50</option></select>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="flex-grow overflow-auto px-4 sm:px-6 lg:px-8 pb-16 table-container">
            <div class="glassmorphism-table rounded-lg">
                <table class="w-full text-sm text-left">
                    <thead class="themed-bg">
                        <tr class="border-b border-[var(--glass-border)]">
                            <th class="p-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-sm">No.</th>
                            <th class="p-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-sm">Model & Build</th>
                            <th class="p-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-sm">QB Build</th>
                            <th class="p-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-sm">PIC</th>
                            <th class="p-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-sm">Test Plan</th>
                            <th class="p-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-sm">Status</th>
                            <th class="p-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-sm">Progress</th>
                            <th class="p-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-sm">Tanggal</th>
                            <th class="p-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-sm">Kinerja</th>
                            <th class="p-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-sm">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="task-table-body">
                        <?php if (empty($tasks)): ?>
                            <tr><td colspan="10" class="text-center p-4 text-secondary">Tidak ada task aktif yang ditemukan.</td></tr>
                        <?php else: ?>
                            <?php $row_number = 1; ?>
                            <?php foreach ($tasks as $task): ?>
                            <tr class="border-b border-[var(--glass-border)] hover:bg-white/5 <?php if ($task['is_urgent']) echo 'urgent-row'; ?>" data-plan="<?= htmlspecialchars($task['test_plan_type']) ?>" data-status="<?= htmlspecialchars($task['progress_status']) ?>">
                                <td class="p-3 text-center text-secondary"><?= $row_number++ ?></td>
                                <td class="p-3">
                                    <div class="font-medium text-primary"><?= htmlspecialchars($task['model_name']) ?></div>
                                    <div class="text-xs text-secondary font-mono space-y-0.5 mt-1">
                                        <div>AP: <?= htmlspecialchars($task['ap'] ?: '-') ?></div> <div>CP: <?= htmlspecialchars($task['cp'] ?: '-') ?></div> <div>CSC: <?= htmlspecialchars($task['csc'] ?: '-') ?></div>
                                    </div>
                                </td>
                                <td class="p-3 text-xs text-secondary font-mono">
                                    <?php if ($task['qb_user']): ?><div>USER: <a href="https://android.qb.sec.samsung.net/build/<?= htmlspecialchars($task['qb_user']) ?>" target="_blank" class="qb-link"><?= htmlspecialchars($task['qb_user']) ?></a></div><?php endif; ?>
                                    <?php if ($task['qb_userdebug']): ?><div>USERDEBUG: <a href="https://android.qb.sec.samsung.net/build/<?= htmlspecialchars($task['qb_userdebug']) ?>" target="_blank" class="qb-link"><?= htmlspecialchars($task['qb_userdebug']) ?></a></div><?php endif; ?>
                                </td>
                                <td class="p-3"><span class="badge <?= getDynamicColorClasses($task['pic_email'], 'pic') ?>"><?= htmlspecialchars($task['pic_email']) ?></span></td>
                                <td class="p-3"><span class="badge <?= getDynamicColorClasses($task['test_plan_type'], 'plan') ?>"><?= htmlspecialchars($task['test_plan_type']) ?></span></td>
                                <td class="p-3"><span class="badge <?= getStatusColorClasses($task['progress_status']) ?>"><?= htmlspecialchars($task['progress_status']) ?></span></td>
                                <td class="p-3">
                                    <div class="w-28"><div class="progress-bar-bg w-full rounded-full h-4 relative flex items-center overflow-hidden"><div class="progress-bar-fill h-4 rounded-full absolute top-0 left-0" style="width: <?= $task['progress_percentage'] ?>%;"></div><span class="relative text-xs font-bold z-10 progress-text pl-2"><?= round($task['progress_percentage']) ?>%</span></div></div>
                                </td>
                                <td class="p-3 text-xs text-secondary">
                                    <div>Req: <?= $task['request_date_obj'] ? $task['request_date_obj']->format('d M Y') : '-' ?></div>
                                    <div>Sub: <?= $task['submission_date_obj'] ? $task['submission_date_obj']->format('d M Y') : '-' ?></div>
                                    <div class="font-bold text-primary">Deadline: <?= $task['deadline'] ? date('d M Y', strtotime($task['deadline'])) : '-' ?></div>
                                </td>
                                <td class="p-3 text-xs">
                                    <div class="mb-1 flex items-center gap-1">
                                        <span class="w-20 inline-block">Submission:</span>
                                        <?php if ($task['ontime_submission_status']): ?>
                                            <span class="font-semibold <?= $task['ontime_submission_status'] == 'Delay' ? 'text-red-400' : 'text-green-400' ?>"><?= $task['ontime_submission_status'] ?></span>
                                        <?php elseif (isset($task['deadline_countdown'])): ?>
                                            <span class="flex items-center gap-1 <?= $task['deadline_countdown'] < 0 ? 'text-red-400' : ($task['deadline_countdown'] <= 3 ? 'text-red-400' : 'text-secondary') ?>">
                                                <?php if ($task['deadline_countdown'] <= 3 && $task['deadline_countdown'] >= 0): ?><svg class="w-4 h-4 animate-pulse-alert" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg><?php endif; ?>
                                                <?= $task['deadline_countdown'] >= 0 ? $task['deadline_countdown'] . ' hari lagi' : 'Terlewat ' . abs($task['deadline_countdown']) . ' hari'; ?>
                                            </span>
                                        <?php else: echo '-'; endif; ?>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <span class="w-20 inline-block">Approval:</span>
                                        <?php if ($task['ontime_approved_status']): ?>
                                            <span class="font-semibold <?= $task['ontime_approved_status'] == 'Delay' ? 'text-red-400' : 'text-green-400' ?>"><?= $task['ontime_approved_status'] ?></span>
                                        <?php elseif (isset($task['approval_countdown'])): ?>
                                            <span class="flex items-center gap-1 <?= $task['approval_countdown'] < 0 ? 'text-red-400' : ($task['approval_countdown'] <= 1 ? 'text-red-400' : 'text-secondary') ?>">
                                                <?php if ($task['approval_countdown'] <= 1 && $task['approval_countdown'] >= 0): ?><svg class="w-4 h-4 animate-pulse-alert" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg><?php endif; ?>
                                                <?= $task['approval_countdown'] >= 0 ? $task['approval_countdown'] . ' hari lagi' : 'Terlewat ' . abs($task['approval_countdown']) . ' hari'; ?>
                                            </span>
                                        <?php else: echo '-'; endif; ?>
                                    </div>
                                </td>
                                <td class="p-3">
                                    <div class="flex items-center">
                                        <button onclick='openEditModal(<?= json_encode($task, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)' class="p-1 rounded hover:bg-gray-600/50"><svg class="w-4 h-4 text-icon" fill="currentColor" viewBox="0 0 20 20"><path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z"></path><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd"></path></svg></button>
                                        <?php if (is_admin()): ?>
                                            <form action="handler.php" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus task ini?');"><input type="hidden" name="action" value="delete_gba_task"><input type="hidden" name="id" value="<?= $task['id'] ?>"><button type="submit" class="p-1 rounded hover:bg-gray-600/50"><svg class="w-4 h-4 text-icon" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm4 0a1 1 0 012 0v6a1 1 0 11-2 0V8z" clip-rule="evenodd"></path></svg></button></form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div id="pagination-nav" class="flex justify-center items-center gap-2 py-4 text-secondary"></div>
        </div>
    </main>

    <div id="task-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-70 hidden">
        <div class="glassmorphism-modal rounded-lg shadow-xl p-6 w-full max-w-6xl mx-4 max-h-[90vh] overflow-y-auto">
             <form id="task-form" action="handler.php" method="POST">
                <div class="flex justify-between items-center mb-4">
                    <h2 id="modal-title" class="text-2xl font-bold text-primary">Tambah Task Baru</h2>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="closeModal()" class="px-4 py-2 rounded-lg themed-input">Batal</button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Simpan Task</button>
                    </div>
                </div>
                <input type="hidden" name="id" id="task-id">
                <input type="hidden" name="action" id="form-action" value="create_gba_task">
                <?php include 'gba_task_form.php'; ?>
            </form>
        </div>
    </div>
    
    <script>
        const canvas = document.getElementById('neural-canvas'), ctx = canvas.getContext('2d');
        let particles = [], hue = 210;
        function setCanvasSize(){canvas.width=window.innerWidth;canvas.height=window.innerHeight;}setCanvasSize();
        
        class Particle{
            constructor(x,y){
                this.x=x||Math.random()*canvas.width;
                this.y=y||Math.random()*canvas.height;
                this.vx=(Math.random()-.5)*.4;
                this.vy=(Math.random()-.5)*.4;
                this.size=Math.random()*2 + 1.5;
            }
            update(){
                this.x+=this.vx;this.y+=this.vy;
                if(this.x<0||this.x>canvas.width)this.vx*=-1;
                if(this.y<0||this.y>canvas.height)this.vy*=-1;
            }
            draw(){
                ctx.fillStyle=`hsl(${hue},100%,75%)`;
                ctx.beginPath();
                ctx.arc(this.x,this.y,this.size,0,Math.PI*2);
                ctx.fill();
            }
        }

        function init(num){
            particles = [];
            for(let i=0;i<num;i++)particles.push(new Particle())
        }

        function handleParticles() {
            for(let i = 0; i < particles.length; i++) {
                particles[i].update();
                particles[i].draw();
                for (let j = i; j < particles.length; j++) {
                    const dx = particles[i].x - particles[j].x;
                    const dy = particles[i].y - particles[j].y;
                    const distance = Math.sqrt(dx * dx + dy * dy);
                    if (distance < 120) {
                        ctx.beginPath();
                        ctx.strokeStyle = `hsla(${hue}, 100%, 80%, ${1 - distance / 120})`; 
                        ctx.lineWidth = 1;
                        ctx.moveTo(particles[i].x, particles[i].y);
                        ctx.lineTo(particles[j].x, particles[j].y);
                        ctx.stroke();
                        ctx.closePath();
                    }
                }
            }
        }

        function animate(){
            ctx.clearRect(0,0,canvas.width,canvas.height);
            hue = (hue + 0.3) % 360; 
            handleParticles();
            requestAnimationFrame(animate);
        }
        
        const particleCount = window.innerWidth > 768 ? 150 : 70;
        init(particleCount);
        animate();
        
        const themeToggleBtn=document.getElementById('theme-toggle');let quill;
        function applyTheme(isLight){document.documentElement.classList.toggle('light',isLight);document.getElementById('theme-toggle-light-icon').classList.toggle('hidden',!isLight);document.getElementById('theme-toggle-dark-icon').classList.toggle('hidden',isLight)}const savedTheme=localStorage.getItem('theme');applyTheme(savedTheme==='light');themeToggleBtn.addEventListener('click',()=>{const isLight=!document.documentElement.classList.contains('light');localStorage.setItem('theme',isLight?'light':'dark');applyTheme(isLight)});

        const modal=document.getElementById('task-modal'),modalTitle=document.getElementById('modal-title'),taskForm=document.getElementById('task-form'),formAction=document.getElementById('form-action'),taskId=document.getElementById('task-id');
        
        function openAddModal(){taskForm.reset();modalTitle.innerText='Tambah Task Baru';formAction.value='create_gba_task';taskId.value='';setupQuill('');updateChecklistVisibility();setDefaultDates();modal.classList.remove('hidden')}
        function openEditModal(task){taskForm.reset();modalTitle.innerText='Edit Task';formAction.value='update_gba_task';for(const key in task){if(taskForm.elements[key]&&!key.endsWith('_obj')){taskForm.elements[key].value=task[key]}}document.getElementById('is_urgent_toggle').checked=task.is_urgent==1;setupQuill(task.notes||'');updateChecklistVisibility();if(task.test_items_checklist){try{const checklist=JSON.parse(task.test_items_checklist);for(const itemName in checklist){const checkbox=document.querySelector(`input[name="checklist[${itemName}]"]`);if(checkbox)checkbox.checked=!!checklist[itemName]}}catch(e){console.error("Could not parse checklist JSON:",e)}}modal.classList.remove('hidden')}
        function closeModal(){modal.classList.add('hidden')}
        document.getElementById('test_plan_type').addEventListener('change',updateChecklistVisibility);function setupQuill(content){if(quill){quill.root.innerHTML=content}else{quill=new Quill('#notes-editor',{theme:'snow',modules:{toolbar:[['bold','italic','underline'],['link'],[{'list':'ordered'},{'list':'bullet'}]]}});quill.root.innerHTML=content}}
        taskForm.addEventListener('submit',function(){document.getElementById('notes-hidden-input').value=quill.root.innerHTML});function updateChecklistVisibility(){const testPlan=document.getElementById('test_plan_type').value,placeholder=document.getElementById('checklist-placeholder');let checklistVisible=!1;document.querySelectorAll('[id^="checklist-container-"]').forEach(el=>{const planName=el.id.replace('checklist-container-','').replace(/_/g,' ');if(planName===testPlan){el.classList.remove('hidden');checklistVisible=!0}else{el.classList.add('hidden')}});placeholder.style.display=checklistVisible?'none':'block'}
        const searchInput=document.getElementById('search-input'),rowsSelect=document.getElementById('pagination-rows'),tableBody=document.getElementById('task-table-body'),paginationNav=document.getElementById('pagination-nav'),testplanFilterContainer=document.getElementById('testplan-filter-container'),statusFilter=document.getElementById('status-filter'),allRows=Array.from(tableBody.querySelectorAll('tr'));let currentPage=1,activePlanFilter='All',activeStatusFilter='All';function renderTable(){const searchText=searchInput.value.toLowerCase(),rowsPerPage=parseInt(rowsSelect.value),filteredRows=allRows.filter(row=>{const matchesSearch=row.textContent.toLowerCase().includes(searchText),matchesPlan=activePlanFilter==='All'||row.dataset.plan===activePlanFilter,matchesStatus=activeStatusFilter==='All'||row.dataset.status===activeStatusFilter;return matchesSearch&&matchesPlan&&matchesStatus}),totalPages=Math.ceil(filteredRows.length/rowsPerPage);currentPage=Math.min(currentPage,totalPages)||1;tableBody.innerHTML='';const start=(currentPage-1)*rowsPerPage,end=start+rowsPerPage;filteredRows.slice(start,end).forEach(row=>tableBody.appendChild(row));renderPagination(totalPages)}
        function renderPagination(totalPages){paginationNav.innerHTML='';if(totalPages<=1)return;const maxButtons=5;let startPage=Math.max(1,currentPage-Math.floor(maxButtons/2)),endPage=Math.min(totalPages,startPage+maxButtons-1);if(endPage-startPage+1<maxButtons){startPage=Math.max(1,endPage-maxButtons+1)}if(startPage>1){paginationNav.appendChild(createPageButton(1,'«'));paginationNav.appendChild(createPageButton(currentPage-1,'‹'))}for(let i=startPage;i<=endPage;i++){paginationNav.appendChild(createPageButton(i,i))}if(endPage<totalPages){paginationNav.appendChild(createPageButton(currentPage+1,'›'));paginationNav.appendChild(createPageButton(totalPages,'»'))}}
        function createPageButton(page,text){const pageButton=document.createElement('button');pageButton.textContent=text;pageButton.className=`px-3 py-1 rounded-lg text-sm ${page===currentPage?'bg-blue-600 text-white':'themed-input'}`;pageButton.onclick=()=>{currentPage=page;renderTable()};return pageButton}
        const progressStatusSelect=document.getElementById('progress_status'),submissionDateInput=document.getElementById('submission_date'),approvedDateInput=document.getElementById('approved_date'),requestDateInput=document.getElementById('request_date'),deadlineInput=document.getElementById('deadline'),signOffDateInput=document.getElementById('sign_off_date');
        function calculateWorkingDays(startDate,daysToAdd){let currentDate=new Date(startDate);let addedDays=0;while(addedDays<daysToAdd){currentDate.setDate(currentDate.getDate()+1);if(currentDate.getDay()!==0&&currentDate.getDay()!==6){addedDays++}}return currentDate.toISOString().slice(0,10)}
        function getTodayDate(){return new Date().toISOString().slice(0,10)}
        function setDefaultDates(){const today=getTodayDate();if(!requestDateInput.value){requestDateInput.value=today}const deadline=calculateWorkingDays(requestDateInput.value,7);deadlineInput.value=deadline;signOffDateInput.value=deadline}
        function checkAllVisibleCheckboxes(){const visibleChecklist=document.querySelector('[id^="checklist-container-"]:not(.hidden)');if(visibleChecklist){visibleChecklist.querySelectorAll('input[type="checkbox"]').forEach(cb=>{cb.checked=!0})}}
        requestDateInput.addEventListener('change',()=>{if(requestDateInput.value){const futureDate=calculateWorkingDays(requestDateInput.value,7);deadlineInput.value=futureDate;signOffDateInput.value=futureDate}});
        progressStatusSelect.addEventListener('change',e=>{const status=e.target.value;if(status==='Submitted'){if(!submissionDateInput.value){submissionDateInput.value=getTodayDate()}checkAllVisibleCheckboxes()}else if(status==='Approved'){if(!submissionDateInput.value){submissionDateInput.value=getTodayDate()}if(!approvedDateInput.value){approvedDateInput.value=getTodayDate()}checkAllVisibleCheckboxes()}});
        taskForm.addEventListener('change',e=>{if(e.target.matches('input[type="checkbox"][name^="checklist"]')){const currentStatus=progressStatusSelect.value;if(currentStatus!=='Approved'&&currentStatus!=='Submitted'){progressStatusSelect.value='Test Ongoing'}}});
        
        // --- Carousel Logic ---
        const carousel = document.getElementById('alert-carousel');
        const inner = carousel ? carousel.querySelector('.alert-carousel-inner') : null;
        const items = inner ? inner.children : [];
        let carouselInterval;
        let currentIndex = 1;
        let isTransitioning = false;

        function setupCarousel() {
            if (!inner || items.length <= 1) return;
            const firstClone = items[0].cloneNode(true);
            const lastClone = items[items.length - 1].cloneNode(true);
            inner.appendChild(firstClone);
            inner.insertBefore(lastClone, items[0]);
            inner.style.transform = `translateX(-100%)`;
        }

        function moveSlide(direction) {
            if (isTransitioning) return;
            isTransitioning = true;
            currentIndex += direction;
            inner.style.transition = 'transform 0.6s cubic-bezier(0.25, 0.8, 0.25, 1)';
            inner.style.transform = `translateX(-${currentIndex * 100}%)`;
        }
        
        if (inner) {
            inner.addEventListener('transitionend', () => {
                const totalItemsWithClones = items.length;
                if (currentIndex === 0) {
                    inner.style.transition = 'none';
                    currentIndex = totalItemsWithClones - 2;
                    inner.style.transform = `translateX(-${currentIndex * 100}%)`;
                }
                if (currentIndex === totalItemsWithClones - 1) {
                    inner.style.transition = 'none';
                    currentIndex = 1;
                    inner.style.transform = `translateX(-${currentIndex * 100}%)`;
                }
                isTransitioning = false;
            });
        }

        function startCarousel() {
            if (carousel && items.length > 1) {
                carouselInterval = setInterval(() => moveSlide(1), 5000);
            }
        }
        function pauseCarousel() { clearInterval(carouselInterval); }
        function resumeCarousel() { startCarousel(); }


        document.addEventListener('DOMContentLoaded',()=>{
            if (carousel && inner && items.length > 0) {
                setupCarousel();
                startCarousel();
            }
            renderTable();setupQuill('');updateChecklistVisibility();const profileMenu=document.getElementById('profile-menu');if(profileMenu){const profileButton=profileMenu.querySelector('button'),profileDropdown=document.getElementById('profile-dropdown');profileButton.addEventListener('click',e=>{e.stopPropagation();profileDropdown.classList.toggle('hidden')});document.addEventListener('click',e=>{if(!profileMenu.contains(e.target)){profileDropdown.classList.add('hidden')}})}});
        if(searchInput){searchInput.addEventListener('input',renderTable)};rowsSelect.addEventListener('change',()=>{currentPage=1;renderTable()});testplanFilterContainer.addEventListener('click',e=>{if(e.target.tagName==='BUTTON'){testplanFilterContainer.querySelector('.active').classList.remove('active');e.target.classList.add('active');activePlanFilter=e.target.dataset.plan;currentPage=1;renderTable()}});
        statusFilter.addEventListener('change',()=>{activeStatusFilter=statusFilter.value;currentPage=1;renderTable()});
    </script>
</body>
</html>