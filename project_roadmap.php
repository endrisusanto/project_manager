<?php
// 1. INISIALISASI
require_once "config.php";
require_once "session.php";
$active_page = 'project_roadmap';

// 2. LOGIKA PENANGANAN WAKTU & FILTER
date_default_timezone_set('Asia/Jakarta');
$current_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Validasi tahun
if (!is_numeric($current_year) || strlen($current_year) != 4) {
    $current_year = date('Y');
}

$filter_pic = isset($_GET['pic']) ? $_GET['pic'] : '';
$filter_test_plan = isset($_GET['test_plan']) ? $_GET['test_plan'] : '';
$filter_model = isset($_GET['model']) ? $_GET['model'] : '';
$filter_start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$filter_end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Default date range ke awal dan akhir tahun berjalan jika kosong
if (empty($filter_start_date) && empty($filter_end_date)) {
    $filter_start_date = $current_year . '-01-01';
    $filter_end_date = $current_year . '-12-31';
}

$prev_year = $current_year - 1;
$next_year = $current_year + 1;

// 3. AMBIL DATA FILTER DROPDOWNS
// Ambil List PIC yang memiliki task
$pics_query = $conn->query("SELECT DISTINCT t.pic_email, u.username FROM gba_tasks t JOIN users u ON t.pic_email = u.email ORDER BY u.username ASC");
$filter_pics_list = [];
if ($pics_query) {
    while ($p_row = $pics_query->fetch_assoc()) {
        $filter_pics_list[] = $p_row;
    }
}

// Ambil List Model yang tersedia
$models_query = $conn->query("SELECT DISTINCT model_name FROM gba_tasks WHERE model_name IS NOT NULL AND model_name != '' ORDER BY model_name ASC");
$filter_models_list = [];
if ($models_query) {
    while ($m_row = $models_query->fetch_assoc()) {
        $filter_models_list[] = $m_row['model_name'];
    }
}

$test_plan_types = ['Regular Variant', 'SKU', 'Normal MR', 'SMR', 'Simple Exception MR'];

// Ambil User List untuk Modal Edit
$users_result = $conn->query("SELECT email, username FROM users ORDER BY username ASC");
$users_list = [];
if ($users_result) {
    while ($user_row = $users_result->fetch_assoc()) {
        $users_list[] = $user_row;
    }
}

// 4. QUERY TASKS DENGAN FILTER AKTIF
$where_clauses = [];
$params = [];
$types = "";

if (!empty($filter_pic)) {
    $where_clauses[] = "t.pic_email = ?";
    $params[] = $filter_pic;
    $types .= "s";
}
if (!empty($filter_test_plan)) {
    $where_clauses[] = "t.test_plan_type = ?";
    $params[] = $filter_test_plan;
    $types .= "s";
}
if (!empty($filter_model)) {
    $where_clauses[] = "t.model_name = ?";
    $params[] = $filter_model;
    $types .= "s";
}
if (!empty($filter_start_date)) {
    $where_clauses[] = "(t.deadline >= ? OR t.deadline IS NULL OR t.sign_off_date >= ? OR t.approved_date >= ?)";
    $params[] = $filter_start_date;
    $params[] = $filter_start_date;
    $params[] = $filter_start_date;
    $types .= "sss";
}
if (!empty($filter_end_date)) {
    $where_clauses[] = "t.request_date <= ?";
    $params[] = $filter_end_date;
    $types .= "s";
}

$sql_tasks = "SELECT t.*, u.username, u.profile_picture 
        FROM gba_tasks t 
        LEFT JOIN users u ON t.pic_email = u.email";

if (!empty($where_clauses)) {
    $sql_tasks .= " WHERE " . implode(" AND ", $where_clauses);
}
$sql_tasks .= " ORDER BY t.request_date ASC, t.model_name ASC";

$tasks_by_status = [
    'Task Baru' => [],
    'Downloaded' => [],
    'Test Ongoing' => [],
    'Pending Feedback' => [],
    'Feedback Sent' => [],
    'Submitted' => [],
    'Passed' => [],
    'Approved' => [],
    'Batal' => []
];

$model_names_by_status = [];
foreach ($tasks_by_status as $st => $arr) {
    $model_names_by_status[$st] = [];
}

$stmt = $conn->prepare($sql_tasks);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['json_data'] = json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        
        $status = $row['progress_status'];
        if (isset($tasks_by_status[$status])) {
            $tasks_by_status[$status][] = $row;
            if (!empty($row['model_name'])) {
                $model_names_by_status[$status][$row['model_name']] = true;
            }
        }
    }
    $stmt->close();
}

$total_filtered_tasks = 0;
foreach ($tasks_by_status as $st => $list) {
    $total_filtered_tasks += count($list);
}

// Helper untuk Render Kotak Proses di Pipeline
function renderPipelineBox($statusKey, $boxId, $label, $colorClass, $tasks_by_status, $model_names_by_status, $hasLeftNode = true, $hasRightNode = true, $hasBottomNode = false) {
    $count = count($tasks_by_status[$statusKey]);
    $modelsCount = count($model_names_by_status[$statusKey]);
    ?>
    <div id="<?= $boxId ?>" class="pipeline-box" data-status="<?= htmlspecialchars($statusKey) ?>">
        <!-- Summary Card -->
        <div onclick="openTasksDrawer('<?= htmlspecialchars($statusKey) ?>')" class="summary-card pointer-events-auto cursor-pointer p-4 bg-[var(--card-bg)] rounded-xl border border-[var(--card-border)] hover:scale-105 transition-all shadow-md">
            <div class="text-[10px] font-extrabold <?= $colorClass ?> uppercase text-center mb-1"><?= htmlspecialchars($label) ?></div>
            <div class="text-3xl font-extrabold text-[var(--text-primary)] text-center mb-1"><?= $count ?></div>
            <div class="text-[9px] text-[var(--text-secondary)] text-center"><?= $modelsCount ?> Models</div>
            


            <?php if ($hasLeftNode): ?>
                <div class="node-connector node-left <?= $colorClass ?>"></div>
            <?php endif; ?>
            <?php if ($hasRightNode): ?>
                <div class="node-connector node-right <?= $colorClass ?>"></div>
            <?php endif; ?>
            <?php if ($hasBottomNode): ?>
                <div class="node-connector node-connector-bottom <?= $colorClass ?>" style="top: auto; bottom: -4px; left: 50%; transform: translateX(-50%) translateY(0);"></div>
            <?php endif; ?>
        </div>
        
        <!-- Kanban Cards container -->
        <div class="kanban-cards-container hidden flex flex-col gap-2 p-2 min-h-[120px] border border-dashed border-[var(--glass-border)] rounded-xl bg-slate-950/10 max-h-[400px] overflow-y-auto custom-scrollbar" ondragover="allowDrop(event)" ondrop="handleDrop(event, '<?= htmlspecialchars($statusKey) ?>')">
            <?php if (empty($tasks_by_status[$statusKey])): ?>
                <div class="text-[10px] text-center text-[var(--text-secondary)] italic py-8">Kosong</div>
            <?php else: ?>
                <?php foreach ($tasks_by_status[$statusKey] as $task): 
                    $is_urgent = (isset($task['is_urgent']) && $task['is_urgent'] == 1);
                    $card_class = "kanban-card p-3 rounded-lg border text-left bg-[var(--card-bg)] border-[var(--card-border)] hover:border-indigo-500/50 hover:shadow-md cursor-grab active:cursor-grabbing transition-all select-none relative";
                    if ($is_urgent) {
                        $card_class .= " border-red-500/40 bg-red-950/5 hover:border-red-500";
                    }
                    ?>
                    <div class="<?= $card_class ?>" draggable="true" ondragstart="handleDragStart(event, '<?= $task['id'] ?>')" data-task-id="<?= $task['id'] ?>">
                        <?php if ($is_urgent): ?>
                            <div class="absolute top-1.5 right-1.5 flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                            </div>
                        <?php endif; ?>
                        <div class="text-xs font-bold text-[var(--text-primary)] truncate pr-4" title="<?= htmlspecialchars($task['model_name']) ?>">
                            <?= htmlspecialchars($task['model_name']) ?>
                        </div>
                        <div class="text-[9px] text-[var(--text-secondary)] truncate mb-1.5">
                            <?= htmlspecialchars($task['project_name'] ?: 'N/A') ?>
                        </div>
                        
                        <div class="flex items-center justify-between mt-2 pt-1.5 border-t border-[var(--glass-border)]">
                            <span class="text-[9px] px-1.5 py-0.5 rounded-md font-bold bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">
                                <?= htmlspecialchars($task['test_plan_type']) ?>
                            </span>
                            <div class="flex items-center gap-1.5">
                                <button type="button" onclick="event.stopPropagation(); openEditModal(<?= $task['json_data'] ?>)" class="text-[10px] text-indigo-400 hover:text-indigo-300 font-bold" title="Edit Task">
                                    Edit
                                </button>
                            </div>
                        </div>
                        
                        <!-- PIC and Progress -->
                        <div class="flex items-center justify-between mt-2">
                            <div class="flex items-center gap-1">
                                <img src="uploads/<?= htmlspecialchars($task['profile_picture'] ?? 'default.png') ?>" class="w-4 h-4 rounded-full border border-[var(--glass-border)]">
                                <span class="text-[8px] text-[var(--text-secondary)] max-w-[60px] truncate" title="<?= htmlspecialchars($task['username'] ?? strtok($task['pic_email'], '@')) ?>">
                                    <?= htmlspecialchars($task['username'] ?? strtok($task['pic_email'], '@')) ?>
                                </span>
                            </div>
                            
                            <?php
                            // Hitung progress checklist
                            $total_items = 0;
                            $completed_items = 0;
                            $test_plan_items = [
                                'Regular Variant' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
                                'SKU' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
                                'Normal MR' => ['CTS', 'GTS', 'CTS-Verifier', 'ATM'],
                                'SMR' => ['CTS', 'GTS', 'STS', 'SCAT'],
                                'Simple Exception MR' => ['STS']
                            ];
                            $plan_type = $task['test_plan_type'];
                            if (isset($test_plan_items[$plan_type])) {
                                $total_items = count($test_plan_items[$plan_type]);
                                $checklist = json_decode($task['test_items_checklist'], true);
                                if (is_array($checklist)) {
                                    foreach ($test_plan_items[$plan_type] as $item) {
                                        $item_key = str_replace([' ', '-'], '_', $item);
                                        if (!empty($checklist[$item_key])) {
                                            $completed_items++;
                                        }
                                    }
                                }
                            }
                            $pct = $total_items > 0 ? round(($completed_items / $total_items) * 100) : 0;
                            ?>
                            
                            <div class="flex items-center gap-1">
                                <span class="text-[8px] font-bold text-[var(--text-secondary)]"><?= $pct ?>%</span>
                                <div class="w-8 bg-slate-800 rounded-full h-1 overflow-hidden">
                                    <div class="bg-indigo-500 h-1" style="width: <?= $pct ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Roadmap - Pipeline Board</title>
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
            --modal-bg: rgba(15, 23, 42, 0.95);
        }

        html.light {
            --bg-primary: #f1f5f9;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --glass-bg: rgba(255, 255, 255, .7);
            --glass-border: rgba(0, 0, 0, .1);
            --card-bg: rgba(255, 255, 255, .85);
            --card-border: rgba(0, 0, 0, .1);
            --text-header: #0f172a;
            --text-icon: #475569;
            --input-bg: #ffffff;
            --input-border: #cbd5e1;
            --modal-bg: rgba(255, 255, 255, 0.98);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            height: 100vh;
            overflow: hidden;
        }

        #neural-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
        }

        .main-container {
            height: calc(100vh - 64px);
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .glass-container {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
        }

        /* Dot matrix grid background */
        .pipeline-bg-grid {
            background-image: radial-gradient(var(--glass-border) 1.5px, transparent 1.5px);
            background-size: 20px 20px;
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: transparent;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: var(--glass-border);
            border-radius: 9999px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: var(--text-secondary);
        }

        /* Pipeline columns and boxes structure */
        .pipeline-column {
            display: flex;
            flex-direction: column;
            border-radius: 1.25rem;
            padding: 1rem;
            border: 1.5px solid var(--glass-border);
            background: var(--glass-bg);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            width: 255px;
            min-width: 255px;
            position: relative;
            z-index: 20;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }

        html.light .pipeline-column {
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
        }

        .pipeline-box {
            margin-bottom: 1.25rem;
            position: relative;
        }

        /* Node connectors at box borders */
        .node-connector {
            width: 8px;
            height: 8px;
            border-radius: 9999px;
            background-color: currentColor;
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            box-shadow: 0 0 6px currentColor;
        }

        .node-left {
            left: -4px;
        }

        .node-right {
            right: -4px;
        }

        .node-connector-bottom {
            box-shadow: 0 0 6px currentColor;
        }

        .pipeline-container-header {
            font-size: 0.75rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 0.5rem 0.75rem;
            border-radius: 0.5rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.05);
        }

        html.light .pipeline-container-header {
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.3);
        }

        .pipeline-box .summary-card {
            background: var(--card-bg);
            border: 1.5px solid var(--card-border);
            position: relative;
        }

        html.light .pipeline-box .summary-card {
            background: #ffffff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
        }

        /* Date picker indicators invert logic */
        input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(var(--date-picker-invert, 1));
        }

        html.light input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(0);
        }

        /* Interactive toggle switch visual states */
        .view-summary .summary-card {
            display: block;
        }

        .view-summary .kanban-cards-container {
            display: none;
        }

        .view-kanban .summary-card {
            display: none;
        }

        .view-kanban .kanban-cards-container {
            display: flex;
        }

        /* Drag and Drop styling overlays */
        .kanban-cards-container.dragover-active {
            border-color: #6366f1 !important;
            background: rgba(99, 102, 241, 0.1) !important;
        }

        .modal-content-wrapper {
            background: var(--modal-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--glass-border);
        }

        .modal-backdrop-blur {
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
        }

        .ql-editor {
            min-height: 100px;
        }

        /* Fullscreen styles override */
        .roadmap-container:fullscreen {
            padding: 2.5rem;
            background-color: var(--bg-primary);
            overflow: auto;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Line flow animations - mathematically seamless to prevent glitches */
        @keyframes flow-dash-normal {
            to {
                stroke-dashoffset: -36;
            }
        }
        @keyframes flow-dash-dashed {
            to {
                stroke-dashoffset: -24;
            }
        }

        .flow-animation-line {
            stroke-dasharray: 6 12;
            animation: flow-dash-normal 1.5s linear infinite;
        }

        .flow-animation-line-dashed {
            stroke-dasharray: 4 8;
            animation: flow-dash-dashed 1.2s linear infinite;
        }

        /* Navigation link styles for header */
        .nav-link {
            color: var(--text-secondary);
            transition: color .2s, border-color .2s;
            border-bottom: 2px solid transparent;
        }

        .nav-link:hover {
            color: var(--text-primary);
        }

        .nav-link-active {
            color: var(--text-primary) !important;
            font-weight: 500;
            border-bottom: 2px solid #3b82f6;
        }

        /* Hide Manager avatar in Kanban view */
        .view-kanban #node-manager-avatar {
            display: none !important;
        }
    </style>
</head>

<body class="flex flex-col h-screen">
    <canvas id="neural-canvas"></canvas>
    <?php include 'header.php'; ?>

    <main class="main-container">
        <!-- Top Controls / Layout & Mode Selector -->
        <div class="flex-shrink-0 flex justify-between items-center mb-4">
            <div class="flex items-center gap-3">
                <h1 class="text-3xl font-extrabold text-header tracking-tight">Project Roadmap</h1>
                <!-- View Mode Toggle -->
                <div class="flex bg-[var(--input-bg)] border border-[var(--input-border)] p-0.5 rounded-lg">
                    <button id="btn-view-summary" onclick="setViewMode('summary')" class="px-3 py-1 text-xs font-bold rounded-md transition-all">Summary Flow</button>
                    <button id="btn-view-kanban" onclick="setViewMode('kanban')" class="px-3 py-1 text-xs font-bold rounded-md transition-all">Kanban Board</button>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <!-- Zoom Controls -->
                <div class="flex items-center bg-[var(--input-bg)] border border-[var(--input-border)] p-1 rounded-lg gap-1">
                    <button onclick="zoomOut()" class="p-1 px-2.5 text-xs font-extrabold text-[var(--text-secondary)] hover:text-white hover:bg-slate-800/40 rounded transition-all" title="Zoom Out">-</button>
                    <button onclick="zoomReset()" id="zoom-indicator" class="px-2 text-xs font-bold text-[var(--text-primary)]" title="Reset Zoom">100%</button>
                    <button onclick="zoomIn()" class="p-1 px-2.5 text-xs font-extrabold text-[var(--text-secondary)] hover:text-white hover:bg-slate-800/40 rounded transition-all" title="Zoom In">+</button>
                </div>
                
                <!-- Year filter navigator -->
                <div class="flex items-center space-x-2 bg-[var(--input-bg)] border border-[var(--input-border)] p-1.5 rounded-lg">
                    <a href="?year=<?= $prev_year ?>" class="p-1 rounded hover:bg-slate-800/50 text-[var(--text-secondary)]">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    </a>
                    <span class="text-xs font-extrabold px-1 text-[var(--text-primary)]"><?= $current_year ?></span>
                    <a href="?year=<?= $next_year ?>" class="p-1 rounded hover:bg-slate-800/50 text-[var(--text-secondary)]">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                    </a>
                </div>
                
                <!-- Excel Export -->
                <a href="export_excel.php?year=<?= $current_year ?>" class="p-2 rounded-lg bg-[var(--input-bg)] border border-[var(--input-border)] hover:bg-slate-800/50 text-green-500 hover:text-green-400 transition-all flex items-center gap-1.5" title="Export to Excel">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    <span class="text-xs font-bold">Export</span>
                </a>

                <!-- Fullscreen Button -->
                <button onclick="toggleFullscreen()" class="p-2 rounded-lg bg-[var(--input-bg)] border border-[var(--input-border)] hover:bg-slate-800/50 text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-all flex items-center gap-1.5" title="Toggle Fullscreen">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4"></path></svg>
                    <span class="text-xs font-bold">Fullscreen</span>
                </button>
            </div>
        </div>

        <!-- Advanced Filter Row (Matching style in reference image) -->
        <form method="GET" action="" class="flex-shrink-0 glass-container p-4 rounded-2xl border border-[var(--glass-border)] flex flex-wrap items-center gap-4 justify-between mb-4">
            <div class="flex flex-wrap items-center gap-3">
                <!-- PIC Filter -->
                <div class="flex flex-col">
                    <label class="text-[10px] font-bold text-[var(--text-secondary)] uppercase mb-1">PIC</label>
                    <select name="pic" onchange="this.form.submit()" class="bg-[var(--input-bg)] border border-[var(--input-border)] text-[var(--text-primary)] px-3 py-1.5 rounded-lg text-xs font-semibold focus:ring-2 focus:ring-blue-500 outline-none transition-all">
                        <option value="">Semua</option>
                        <?php foreach ($filter_pics_list as $pic): ?>
                            <option value="<?= htmlspecialchars($pic['pic_email']) ?>" <?= ($filter_pic === $pic['pic_email']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pic['username'] ?: strtok($pic['pic_email'], '@')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Test Plan Filter -->
                <div class="flex flex-col">
                    <label class="text-[10px] font-bold text-[var(--text-secondary)] uppercase mb-1">Test Plan</label>
                    <select name="test_plan" onchange="this.form.submit()" class="bg-[var(--input-bg)] border border-[var(--input-border)] text-[var(--text-primary)] px-3 py-1.5 rounded-lg text-xs font-semibold focus:ring-2 focus:ring-blue-500 outline-none transition-all">
                        <option value="">Semua</option>
                        <?php foreach ($test_plan_types as $plan): ?>
                            <option value="<?= htmlspecialchars($plan) ?>" <?= ($filter_test_plan === $plan) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($plan) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Model Filter -->
                <div class="flex flex-col">
                    <label class="text-[10px] font-bold text-[var(--text-secondary)] uppercase mb-1">Model</label>
                    <select name="model" onchange="this.form.submit()" class="bg-[var(--input-bg)] border border-[var(--input-border)] text-[var(--text-primary)] px-3 py-1.5 rounded-lg text-xs font-semibold focus:ring-2 focus:ring-blue-500 outline-none transition-all max-w-[150px]">
                        <option value="">Semua</option>
                        <?php foreach ($filter_models_list as $m_name): ?>
                            <option value="<?= htmlspecialchars($m_name) ?>" <?= ($filter_model === $m_name) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m_name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date Dari Filter -->
                <div class="flex flex-col">
                    <label class="text-[10px] font-bold text-[var(--text-secondary)] uppercase mb-1">Dari</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($filter_start_date) ?>" class="bg-[var(--input-bg)] border border-[var(--input-border)] text-[var(--text-primary)] px-3 py-1.5 rounded-lg text-xs font-semibold focus:ring-2 focus:ring-blue-500 outline-none transition-all">
                </div>

                <!-- Date Sampai Filter -->
                <div class="flex flex-col">
                    <label class="text-[10px] font-bold text-[var(--text-secondary)] uppercase mb-1">Sampai</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($filter_end_date) ?>" class="bg-[var(--input-bg)] border border-[var(--input-border)] text-[var(--text-primary)] px-3 py-1.5 rounded-lg text-xs font-semibold focus:ring-2 focus:ring-blue-500 outline-none transition-all">
                </div>

                <!-- Apply Button -->
                <div class="flex items-end h-full pt-4">
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-1.5 rounded-lg text-xs transition-all shadow-md">TERAPKAN</button>
                </div>
                
                <!-- Reset Button -->
                <div class="flex items-end h-full pt-4">
                    <a href="project_roadmap.php" class="bg-[var(--input-bg)] border border-[var(--input-border)] hover:bg-slate-800/40 text-[var(--text-secondary)] hover:text-[var(--text-primary)] font-bold px-3 py-1.5 rounded-lg text-xs transition-all">Reset</a>
                </div>
            </div>

            <!-- Total Badge -->
            <div class="flex flex-col items-end">
                <label class="text-[10px] font-bold text-[var(--text-secondary)] uppercase mb-1">TOTAL TASKS</label>
                <div class="bg-indigo-600/10 border border-indigo-500/30 text-indigo-400 font-extrabold px-4 py-1.5 rounded-lg text-sm tracking-wider">
                    <?= number_format($total_filtered_tasks, 0, ',', '.') ?>
                </div>
            </div>
        </form>

        <!-- Pipeline Board Viewport -->
        <div class="roadmap-container custom-scrollbar relative flex-grow overflow-auto p-6 pipeline-bg-grid rounded-2xl border border-[var(--glass-border)] bg-[var(--card-bg)]" style="min-height: 550px;">
            <!-- Zoom wrapper -->
            <div id="pipeline-zoom-wrapper" class="relative origin-top-left transition-transform duration-200 ease-out" style="width: max-content; margin: 0 auto; min-height: 500px;">
                
                <!-- SVG Connector Canvas overlay -->
                <svg id="flow-svg" class="absolute inset-0 pointer-events-none w-full h-full" style="z-index: 30;">
                    <defs>
                        <marker id="arrow-f59e0b" viewBox="0 0 10 10" refX="6" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M 0 1.5 L 7 5 L 0 8.5 z" fill="#f59e0b" /></marker>
                        <marker id="arrow-ef4444" viewBox="0 0 10 10" refX="6" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M 0 1.5 L 7 5 L 0 8.5 z" fill="#ef4444" /></marker>
                        <marker id="arrow-3b82f6" viewBox="0 0 10 10" refX="6" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M 0 1.5 L 7 5 L 0 8.5 z" fill="#3b82f6" /></marker>
                        <marker id="arrow-0ea5e9" viewBox="0 0 10 10" refX="6" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M 0 1.5 L 7 5 L 0 8.5 z" fill="#0ea5e9" /></marker>
                        <marker id="arrow-eab308" viewBox="0 0 10 10" refX="6" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M 0 1.5 L 7 5 L 0 8.5 z" fill="#eab308" /></marker>
                        <marker id="arrow-8b5cf6" viewBox="0 0 10 10" refX="6" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M 0 1.5 L 7 5 L 0 8.5 z" fill="#8b5cf6" /></marker>
                        <marker id="arrow-d946ef" viewBox="0 0 10 10" refX="6" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M 0 1.5 L 7 5 L 0 8.5 z" fill="#d946ef" /></marker>
                        <marker id="arrow-22c55e" viewBox="0 0 10 10" refX="6" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M 0 1.5 L 7 5 L 0 8.5 z" fill="#22c55e" /></marker>
                    </defs>
                </svg>

                <!-- Board Content -->
                <div id="pipeline-board" class="view-summary flex gap-8 justify-center mx-auto pl-16 pb-12 z-20 relative select-none">
                    
                    <!-- COLUMN 1: REQUEST -->
                    <div class="pipeline-column border-amber-500/40 relative">
                        <div class="pipeline-container-header bg-amber-500/10 text-amber-500 border border-amber-500/20">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                            Request
                        </div>
                        
                        <!-- Manager Avatar as Input Source, positioned in between New and Downloaded -->
                        <div id="node-manager-avatar" class="absolute -left-16 top-[152px] -translate-y-1/2 flex flex-col items-center justify-center z-30 pointer-events-auto" title="Requested by Manager">
                            <img src="uploads/default.png" class="w-6 h-6 rounded-full border border-amber-500/60 shadow-md">
                            <span class="text-[6px] text-[var(--text-secondary)] font-extrabold uppercase mt-0.5 tracking-wider">Manager</span>
                            <!-- Right node connector for the avatar itself -->
                            <div class="node-connector node-right text-amber-500" style="right: -4px;"></div>
                        </div>

                        <!-- Box: Task Baru -->
                        <?php renderPipelineBox('Task Baru', 'box-task-baru', 'New', 'text-amber-500', $tasks_by_status, $model_names_by_status, true, true, true); ?>
                        
                        <!-- Box: Downloaded -->
                        <?php renderPipelineBox('Downloaded', 'box-downloaded', 'Downloaded', 'text-amber-450', $tasks_by_status, $model_names_by_status, true, true, true); ?>
                        

                    </div>

                    <!-- COLUMN 2: TESTING -->
                    <div class="pipeline-column border-blue-500/40">
                        <div class="pipeline-container-header bg-blue-500/10 text-blue-500 border border-blue-500/20">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path></svg>
                            Testing
                        </div>
                        
                        <!-- Box: Test Ongoing -->
                        <?php renderPipelineBox('Test Ongoing', 'box-test-ongoing', 'Test Ongoing', 'text-blue-500', $tasks_by_status, $model_names_by_status, true, true, true); ?>
                    </div>

                    <!-- COLUMN 3: FEEDBACK -->
                    <div class="pipeline-column border-cyan-500/40">
                        <div class="pipeline-container-header bg-cyan-500/10 text-cyan-500 border border-cyan-500/20">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>
                            Feedback
                        </div>
                        
                        <!-- Box: Pending Feedback -->
                        <?php renderPipelineBox('Pending Feedback', 'box-pending-feedback', 'Pending Feedback', 'text-yellow-500', $tasks_by_status, $model_names_by_status); ?>
                        
                        <!-- Box: Feedback Sent -->
                        <?php renderPipelineBox('Feedback Sent', 'box-feedback-sent', 'Feedback Sent', 'text-orange-500', $tasks_by_status, $model_names_by_status, true, false, true); ?>
                    </div>

                    <!-- COLUMN 4: VERIFICATION -->
                    <div class="pipeline-column border-violet-500/40">
                        <div class="pipeline-container-header bg-violet-500/10 text-violet-500 border border-violet-500/20">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Verification
                        </div>
                        
                        <!-- Box: Passed -->
                        <?php renderPipelineBox('Passed', 'box-passed', 'Passed', 'text-indigo-500', $tasks_by_status, $model_names_by_status); ?>
                    </div>

                    <!-- COLUMN 5: SUBMISSION -->
                    <div class="pipeline-column border-purple-500/40">
                        <div class="pipeline-container-header bg-purple-500/10 text-purple-500 border border-purple-500/20">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                            Submission
                        </div>
                        
                        <!-- Box: Submitted -->
                        <?php renderPipelineBox('Submitted', 'box-submitted', 'Submitted', 'text-purple-500', $tasks_by_status, $model_names_by_status); ?>
                    </div>

                    <!-- COLUMN 6: APPROVAL -->
                    <div class="pipeline-column border-emerald-500/40">
                        <div class="pipeline-container-header bg-emerald-500/10 text-emerald-500 border border-emerald-500/20">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            Approval
                        </div>
                        
                        <!-- Box: Approved -->
                        <?php renderPipelineBox('Approved', 'box-approved', 'Approved', 'text-green-500', $tasks_by_status, $model_names_by_status, true, false); ?>

                        <!-- Box: Batal (placed below inside Column 6) -->
                        <div id="box-batal" class="pipeline-box mt-auto" data-status="Batal">
                            <div onclick="openTasksDrawer('Batal')" class="summary-card pointer-events-auto cursor-pointer p-4 bg-red-500/5 hover:bg-red-500/10 rounded-xl border border-dashed border-red-500/40 hover:scale-105 transition-all shadow-sm">
                                <div class="text-[10px] font-extrabold text-red-500 uppercase text-center mb-1">Cancelled</div>
                                <div class="text-3xl font-extrabold text-red-500 text-center mb-1"><?= count($tasks_by_status['Batal']) ?></div>
                                <div class="text-[9px] text-[var(--text-secondary)] text-center"><?= count($model_names_by_status['Batal']) ?> Models</div>
                                <div class="node-connector node-left text-red-500"></div>
                                <div class="node-connector node-connector-bottom text-red-500" style="top: auto; bottom: -4px; left: 50%; transform: translateX(-50%) translateY(0);"></div>
                            </div>
                            <div class="kanban-cards-container hidden flex flex-col gap-2 p-2 min-h-[120px] border border-dashed border-red-500/30 rounded-xl bg-red-500/5 max-h-[400px] overflow-y-auto custom-scrollbar" ondragover="allowDrop(event)" ondrop="handleDrop(event, 'Batal')">
                                <?php if (empty($tasks_by_status['Batal'])): ?>
                                    <div class="text-[10px] text-center text-[var(--text-secondary)] italic py-8">Kosong</div>
                                <?php else: ?>
                                    <?php foreach ($tasks_by_status['Batal'] as $task): 
                                        $is_urgent = (isset($task['is_urgent']) && $task['is_urgent'] == 1);
                                        $card_class = "kanban-card p-3 rounded-lg border text-left bg-[var(--card-bg)] border-[var(--card-border)] hover:border-red-500/50 hover:shadow-md cursor-grab active:cursor-grabbing transition-all select-none relative";
                                        ?>
                                        <div class="<?= $card_class ?>" draggable="true" ondragstart="handleDragStart(event, '<?= $task['id'] ?>')" data-task-id="<?= $task['id'] ?>">
                                            <div class="text-xs font-bold text-[var(--text-primary)] truncate" title="<?= htmlspecialchars($task['model_name']) ?>">
                                                <?= htmlspecialchars($task['model_name']) ?>
                                            </div>
                                            <div class="text-[9px] text-[var(--text-secondary)] truncate mb-1">
                                                <?= htmlspecialchars($task['project_name'] ?: 'N/A') ?>
                                            </div>
                                            <div class="flex items-center justify-between mt-2 pt-1.5 border-t border-[var(--glass-border)]">
                                                <span class="text-[8px] px-1 py-0.5 rounded font-bold bg-red-500/10 text-red-400 border border-red-500/20">
                                                    <?= htmlspecialchars($task['test_plan_type']) ?>
                                                </span>
                                                <button type="button" onclick="event.stopPropagation(); openEditModal(<?= $task['json_data'] ?>)" class="text-[9px] text-indigo-400 font-bold">Edit</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>
    </main>

    <!-- SLIDE-OVER DRAWER (For detailed status review) -->
    <div id="task-drawer" class="fixed inset-y-0 right-0 w-[420px] max-w-full bg-[var(--modal-bg)] backdrop-blur-xl border-l border-[var(--glass-border)] shadow-2xl z-[80] transform translate-x-full transition-transform duration-300 flex flex-col">
        <!-- Header -->
        <div class="p-5 border-b border-[var(--glass-border)] flex justify-between items-center bg-slate-900/10">
            <div>
                <h3 id="drawer-title" class="text-xl font-bold text-header">Tasks</h3>
                <p id="drawer-subtitle" class="text-xs text-[var(--text-secondary)] mt-1">Status: -</p>
            </div>
            <button onclick="closeDrawer()" class="p-2 text-[var(--text-secondary)] hover:text-white rounded-lg hover:bg-slate-800/40 transition-all">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        
        <!-- Search bar inside Drawer -->
        <div class="px-5 py-3 border-b border-[var(--glass-border)] bg-slate-900/5">
            <input type="text" id="drawer-search" placeholder="Cari task..." oninput="filterDrawerTasks()" class="w-full p-2 text-xs rounded-lg themed-input focus:ring-2 focus:ring-blue-500 outline-none transition-all">
        </div>
        
        <!-- Drawer Content (Scrollable list of tasks) -->
        <div id="drawer-tasks-list" class="flex-grow overflow-y-auto p-5 space-y-4 custom-scrollbar">
            <!-- populated dynamically -->
        </div>
    </div>

    <!-- TASK EDIT MODAL (Kept identical to original to prevent regressions) -->
    <div id="task-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-70 hidden modal-backdrop-blur">
        <div class="modal-content-wrapper rounded-lg shadow-xl p-6 w-full max-w-6xl mx-4 max-h-[90vh] overflow-y-auto">
            <form id="task-form" action="handler.php" method="POST">
                <div class="flex justify-between items-center mb-4">
                    <h2 id="modal-title" class="text-2xl font-bold text-header">Edit Task</h2>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="closeModal()" class="px-4 py-2 rounded-lg bg-[var(--input-bg)] text-[var(--text-primary)] border border-[var(--input-border)]">Batal</button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Simpan Perubahan</button>
                    </div>
                </div>
                <input type="hidden" name="id" id="task-id">
                <input type="hidden" name="action" id="form-action" value="update_gba_task">
                <input type="hidden" name="redirect_to" value="project_roadmap.php">
                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="form-label block mb-1 text-sm font-medium">Marketing Name</label>
                            <input type="text" id="project_name" name="project_name" class="themed-input w-full p-2.5 text-sm rounded-lg" required>
                        </div>
                        <div>
                            <label class="form-label block mb-1 text-sm font-medium">Model Name</label>
                            <input type="text" id="model_name" name="model_name" class="themed-input w-full p-2.5 text-sm rounded-lg" required>
                        </div>
                        <div>
                            <label class="form-label block mb-1 text-sm font-medium">PIC</label>
                            <select id="pic_email" name="pic_email" class="themed-input w-full p-2.5 text-sm rounded-lg" required>
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
                        <div>
                            <label class="form-label block mb-1 text-sm font-medium">Type Test Plan</label>
                            <select id="test_plan_type" name="test_plan_type" class="themed-input w-full p-2.5 text-sm rounded-lg">
                                <option>Regular Variant</option>
                                <option>SKU</option>
                                <option>Normal MR</option>
                                <option>SMR</option>
                                <option>Simple Exception MR</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                        <div><label class="form-label block mb-1 text-sm font-medium">Request Date</label><input type="date" id="request_date" name="request_date" class="themed-input w-full p-2 text-sm rounded-lg"></div>
                        <div><label class="form-label block mb-1 text-sm font-medium">Submission Date</label><input type="date" id="submission_date" name="submission_date" class="themed-input w-full p-2 text-sm rounded-lg"></div>
                        <div><label class="form-label block mb-1 text-sm font-medium">Approved Date</label><input type="date" id="approved_date" name="approved_date" class="themed-input w-full p-2 text-sm rounded-lg"></div>
                        <div><label class="form-label block mb-1 text-sm font-medium">Deadline</label><input type="date" id="deadline" name="deadline" class="themed-input w-full p-2 text-sm rounded-lg"></div>
                        <div><label class="form-label block mb-1 text-sm font-medium">Sign-Off Date</label><input type="date" id="sign_off_date" name="sign_off_date" class="themed-input w-full p-2 text-sm rounded-lg"></div>
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

    <!-- JAVASCRIPT LOGIC -->
    <script>
        // --- 1. CONFIG & LIGHT/DARK THEME IMPLEMENTATION ---
        const root = document.documentElement;

        function updateThemeIcons() {
            const isLight = root.classList.contains('light');
            const lightIcon = document.querySelector('#theme-toggle-light-icon');
            const darkIcon = document.querySelector('#theme-toggle-dark-icon');
            if (lightIcon && darkIcon) {
                if (isLight) {
                    lightIcon.classList.remove('hidden');
                    darkIcon.classList.add('hidden');
                } else {
                    lightIcon.classList.add('hidden');
                    darkIcon.classList.remove('hidden');
                }
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
            applyTheme(false); // Default ke dark
        }

        const themeBtn = document.getElementById('theme-toggle');
        if (themeBtn) {
            themeBtn.addEventListener('click', () => {
                const isLight = !root.classList.contains('light');
                localStorage.setItem('theme', isLight ? 'light' : 'dark');
                applyTheme(isLight);
            });
        }

        // --- 2. BACKGROUND ANIMATION (NEURAL NETWORK) ---
        const canvas = document.getElementById('neural-canvas'), ctx = canvas.getContext('2d');
        let particles = [], hue = 210;
        function setCanvasSize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
        class Particle { constructor() { this.x = Math.random() * canvas.width; this.y = Math.random() * canvas.height; this.vx = (Math.random() - .5) * .4; this.vy = (Math.random() - .5) * .4; this.size = Math.random() * 2 + 1.5; } update() { this.x += this.vx; this.y += this.vy; if (this.x < 0 || this.x > canvas.width) this.vx *= -1; if (this.y < 0 || this.y > canvas.height) this.vy *= -1; } draw() { ctx.fillStyle = `hsl(${hue},100%,75%)`; ctx.beginPath(); ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2); ctx.fill(); } }
        function initParticles(n) { particles = []; for (let i = 0; i < n; i++) particles.push(new Particle()); }
        function animateParticles() { ctx.clearRect(0, 0, canvas.width, canvas.height); hue = (hue + .3) % 360; particles.forEach(p => { p.update(); p.draw(); }); requestAnimationFrame(animateParticles); }
        window.addEventListener('resize', setCanvasSize); setCanvasSize(); initParticles(80); animateParticles();

        // --- 3. MODAL LOGIC (QUIL EDITOR) ---
        let quill;
        function openEditModal(data) {
            document.getElementById('task-id').value = data.id; 
            document.getElementById('form-action').value = 'update_gba_task';
            document.getElementById('modal-title').innerText = 'Edit Task: ' + data.model_name;
            ['project_name', 'model_name', 'pic_email', 'progress_status', 'test_plan_type', 'request_date', 'submission_date', 'approved_date', 'deadline', 'sign_off_date'].forEach(id => { 
                if (document.getElementById(id)) document.getElementById(id).value = data[id] || ''; 
            });
            if (!quill) { 
                quill = new Quill('#notes-editor', { theme: 'snow' }); 
                quill.on('text-change', function () { 
                    document.getElementById('notes-hidden-input').value = quill.root.innerHTML; 
                }); 
            }
            quill.root.innerHTML = data.notes || '';
            document.getElementById('task-modal').classList.remove('hidden');
        }
        
        function closeModal() { 
            document.getElementById('task-modal').classList.add('hidden'); 
        }
        
        document.getElementById('task-form').addEventListener('submit', function () { 
            if (quill) document.getElementById('notes-hidden-input').value = quill.root.innerHTML; 
        });

        // --- 4. VIEW CONFIGS & INTERACTIVE LAYOUT CONTROLS ---
        let zoomScale = 1.0;
        let currentViewMode = 'summary';
        const tasksDataByStatus = <?= json_encode($tasks_by_status, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

        function setViewMode(mode) {
            currentViewMode = mode;
            const board = document.getElementById('pipeline-board');
            const btnSummary = document.getElementById('btn-view-summary');
            const btnKanban = document.getElementById('btn-view-kanban');
            
            if (mode === 'summary') {
                board.classList.remove('view-kanban');
                board.classList.add('view-summary');
                
                btnSummary.classList.add('bg-indigo-650', 'bg-indigo-600', 'text-white');
                btnSummary.classList.remove('text-[var(--text-secondary)]');
                btnKanban.classList.remove('bg-indigo-600', 'text-white');
                btnKanban.classList.add('text-[var(--text-secondary)]');
                
                setTimeout(drawConnections, 50);
            } else {
                board.classList.remove('view-summary');
                board.classList.add('view-kanban');
                
                btnKanban.classList.add('bg-indigo-650', 'bg-indigo-600', 'text-white');
                btnKanban.classList.remove('text-[var(--text-secondary)]');
                btnSummary.classList.remove('bg-indigo-600', 'text-white');
                btnSummary.classList.add('text-[var(--text-secondary)]');
                
                const svg = document.getElementById('flow-svg');
                if (svg) svg.querySelectorAll('path, circle').forEach(el => el.remove());
            }
            
            localStorage.setItem('roadmap_view_mode', mode);
        }

        function updateZoom() {
            const wrapper = document.getElementById('pipeline-zoom-wrapper');
            if (wrapper) {
                wrapper.style.transform = `scale(${zoomScale})`;
                document.getElementById('zoom-indicator').innerText = `${Math.round(zoomScale * 100)}%`;
                if (currentViewMode === 'summary') {
                    drawConnections();
                }
            }
        }

        function zoomIn() {
            if (zoomScale < 1.4) {
                zoomScale += 0.1;
                updateZoom();
            }
        }

        function zoomOut() {
            if (zoomScale > 0.6) {
                zoomScale -= 0.1;
                updateZoom();
            }
        }

        function zoomReset() {
            zoomScale = 1.0;
            updateZoom();
        }

        function toggleFullscreen() {
            const container = document.querySelector('.roadmap-container');
            if (!document.fullscreenElement) {
                container.requestFullscreen().catch(err => {
                    alert(`Gagal mengaktifkan mode layar penuh: ${err.message}`);
                });
            } else {
                document.exitFullscreen();
            }
        }

        // --- 5. SLIDE-OVER DRAWER LOGIC ---
        let activeDrawerStatus = '';

        function openTasksDrawer(status) {
            activeDrawerStatus = status;
            const drawer = document.getElementById('task-drawer');
            const drawerSubtitle = document.getElementById('drawer-subtitle');
            const searchInput = document.getElementById('drawer-search');
            
            searchInput.value = ''; // Reset pencarian
            drawerSubtitle.innerText = `Status: ${status}`;
            
            renderDrawerTasks(status);
            drawer.classList.remove('translate-x-full');
        }

        function closeDrawer() {
            const drawer = document.getElementById('task-drawer');
            drawer.classList.add('translate-x-full');
        }

        function renderDrawerTasks(status, searchFilter = '') {
            const listContainer = document.getElementById('drawer-tasks-list');
            listContainer.innerHTML = '';
            
            const tasks = tasksDataByStatus[status] || [];
            const filteredTasks = tasks.filter(task => {
                const query = searchFilter.toLowerCase();
                return (
                    task.model_name.toLowerCase().includes(query) ||
                    (task.project_name && task.project_name.toLowerCase().includes(query)) ||
                    (task.username && task.username.toLowerCase().includes(query)) ||
                    task.pic_email.toLowerCase().includes(query)
                );
            });
            
            document.getElementById('drawer-title').innerText = `Tasks (${filteredTasks.length})`;
            
            if (filteredTasks.length === 0) {
                listContainer.innerHTML = `<div class="text-center text-[var(--text-secondary)] italic py-12 text-xs">Tidak ada task yang ditemukan</div>`;
                return;
            }
            
            filteredTasks.forEach(task => {
                const isUrgent = task.is_urgent == 1;
                const progressPct = calculateProgressPercent(task);
                
                const card = document.createElement('div');
                card.className = `p-4 rounded-xl border bg-slate-900/25 border-[var(--glass-border)] hover:border-indigo-500/40 hover:bg-slate-900/45 transition-all flex flex-col relative ${isUrgent ? 'border-red-500/40 bg-red-950/5' : ''}`;
                
                card.innerHTML = `
                    ${isUrgent ? `
                    <div class="absolute top-3 right-3 flex h-2.5 w-2.5">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
                    </div>
                    ` : ''}
                    
                    <div class="font-bold text-sm text-[var(--text-primary)] pr-6 truncate">${task.model_name}</div>
                    <div class="text-xs text-[var(--text-secondary)] truncate mb-3">${task.project_name || 'N/A'}</div>
                    
                    <div class="grid grid-cols-2 gap-2 text-[10px] text-[var(--text-secondary)] mb-3 bg-slate-950/20 p-2 rounded-lg border border-[var(--glass-border)]">
                        <div>Req: <span class="font-semibold text-[var(--text-primary)]">${task.request_date || '-'}</span></div>
                        <div>Deadline: <span class="font-semibold text-[var(--text-primary)]">${task.deadline || '-'}</span></div>
                        <div>Subm: <span class="font-semibold text-[var(--text-primary)]">${task.submission_date || '-'}</span></div>
                        <div>Appr: <span class="font-semibold text-[var(--text-primary)]">${task.approved_date || '-'}</span></div>
                    </div>
                    
                    <div class="flex items-center justify-between mb-3.5">
                        <div class="text-[10px] font-bold text-[var(--text-secondary)]">Progress Checklist</div>
                        <div class="flex items-center gap-1.5">
                            <span class="text-[10px] font-bold text-[var(--text-primary)]">${progressPct}%</span>
                            <div class="w-16 bg-slate-800 rounded-full h-1.5 overflow-hidden">
                                <div class="bg-indigo-500 h-1.5" style="width: ${progressPct}%"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-between items-center mt-auto pt-2.5 border-t border-[var(--glass-border)]">
                        <div class="flex items-center gap-1.5">
                            <img src="uploads/${task.profile_picture || 'default.png'}" class="w-5 h-5 rounded-full border border-[var(--glass-border)]">
                            <span class="text-[10px] text-[var(--text-secondary)] truncate font-medium max-w-[100px]">${task.username || task.pic_email.split('@')[0]}</span>
                        </div>
                        <div class="flex gap-2">
                            <button class="px-2.5 py-1 text-[10px] font-bold bg-indigo-650/10 border border-indigo-500/20 hover:bg-indigo-600 text-indigo-400 hover:text-white rounded transition-all btn-edit-trigger">Edit</button>
                            <a href="gba_tasks.php?search=${encodeURIComponent(task.model_name)}" class="px-2.5 py-1 text-[10px] font-bold bg-[var(--input-bg)] border border-[var(--input-border)] hover:bg-slate-800/40 text-[var(--text-secondary)] hover:text-white rounded transition-all">Detail</a>
                        </div>
                    </div>
                `;
                
                card.querySelector('.btn-edit-trigger').addEventListener('click', (e) => {
                    e.stopPropagation();
                    openEditModal(task);
                });
                
                listContainer.appendChild(card);
            });
        }

        function filterDrawerTasks() {
            const query = document.getElementById('drawer-search').value;
            renderDrawerTasks(activeDrawerStatus, query);
        }

        function calculateProgressPercent(task) {
            const testPlanItems = {
                'Regular Variant': ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
                'SKU': ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
                'Normal MR': ['CTS', 'GTS', 'CTS-Verifier', 'ATM'],
                'SMR': ['CTS', 'GTS', 'STS', 'SCAT'],
                'Simple Exception MR': ['STS']
            };
            
            const planType = task.test_plan_type;
            const items = testPlanItems[planType];
            if (!items) return 0;
            
            let completed = 0;
            if (task.test_items_checklist) {
                try {
                    const checklist = JSON.parse(task.test_items_checklist);
                    items.forEach(item => {
                        const key = item.replace(/ /g, '_').replace(/-/g, '_');
                        if (checklist[key]) completed++;
                    });
                } catch(e) {}
            }
            return Math.round((completed / items.length) * 100);
        }

        // --- 6. DRAG AND DROP KANBAN IMPLEMENTATION ---
        let draggedTaskId = null;

        function handleDragStart(event, taskId) {
            draggedTaskId = taskId;
            event.dataTransfer.setData('text/plain', taskId);
            event.dataTransfer.effectAllowed = 'move';
            
            document.querySelectorAll('.kanban-cards-container').forEach(el => {
                el.classList.add('dragover-active');
            });
        }

        function allowDrop(event) {
            event.preventDefault();
        }

        function handleDrop(event, columnStatus) {
            event.preventDefault();
            
            document.querySelectorAll('.kanban-cards-container').forEach(el => {
                el.classList.remove('dragover-active');
            });
            
            const taskId = event.dataTransfer.getData('text/plain') || draggedTaskId;
            if (!taskId) return;
            
            fetch('handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'update_task_status',
                    task_id: taskId,
                    new_status: columnStatus
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToastSuccess(`Status task berhasil diubah ke: ${columnStatus}`);
                    setTimeout(() => {
                        location.reload();
                    }, 500);
                } else {
                    alert('Gagal memperbarui status: ' + (data.error || 'Terjadi kesalahan'));
                }
            })
            .catch(err => {
                console.error('Error dragging status update:', err);
            });
        }

        function showToastSuccess(message) {
            const toast = document.createElement('div');
            toast.className = 'fixed bottom-5 left-1/2 transform -translate-x-1/2 bg-green-600 text-white font-bold px-6 py-3 rounded-lg shadow-xl z-[99] transition-all duration-300';
            toast.innerText = message;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.remove();
            }, 2500);
        }

        // --- 7. SVG DYNAMIC CONNECTIONS CANVA DRAWING ---
        function drawConnections() {
            const svg = document.getElementById('flow-svg');
            if (!svg) return;
            
            // Hapus jalur lama kecuali <defs>
            svg.querySelectorAll('path, circle').forEach(el => el.remove());
            
            const board = document.getElementById('pipeline-board');
            if (!board.classList.contains('view-summary')) return;
            
            const boardRect = board.getBoundingClientRect();
            
            const getElPoint = (elId, position) => {
                const el = document.getElementById(elId);
                if (!el) return null;
                const rect = el.getBoundingClientRect();
                
                // Koordinat relatif terhadap zoomable board container
                const x_left = rect.left - boardRect.left;
                const x_right = rect.right - boardRect.left;
                const x_center = x_left + rect.width / 2;
                const y_top = rect.top - boardRect.top;
                const y_bottom = rect.bottom - boardRect.top;
                const y_center = y_top + rect.height / 2;
                
                switch(position) {
                    case 'left': return { x: x_left, y: y_center };
                    case 'right': return { x: x_right, y: y_center };
                    case 'top': return { x: x_center, y: y_top };
                    case 'bottom': return { x: x_center, y: y_bottom };
                    case 'center': return { x: x_center, y: y_center };
                }
                return null;
            };
            
            const drawPath = (fromId, fromPos, toId, toPos, color, isDashed = false, isLoopback = false) => {
                const start = getElPoint(fromId, fromPos);
                const end = getElPoint(toId, toPos);
                if (!start || !end) return;
                
                let d = '';
                if (isLoopback) {
                    const cpY = Math.max(start.y, end.y) + 70; // buat lengkungan yang cukup melengkung ke bawah
                    d = `M ${start.x} ${start.y} C ${start.x} ${cpY}, ${end.x} ${cpY}, ${end.x} ${end.y}`;
                } else if (fromId === 'box-test-ongoing' && toId === 'box-passed') {
                    // Arch up by 105px to bypass Column 3
                    const cpY = start.y - 105;
                    const dx = end.x - start.x;
                    d = `M ${start.x} ${start.y} C ${start.x + dx * 0.3} ${cpY}, ${end.x - dx * 0.3} ${cpY}, ${end.x} ${end.y}`;
                } else if (toId === 'box-batal' && fromId !== 'box-submitted') {
                    // Custom routing below columns via a bottom lane.
                    // Each source gets its own staggered laneY so lines don't overlap.
                    const baseLaneY = boardRect.height - 12;
                    let laneOffset = 0;
                    if (fromId === 'box-task-baru')    laneOffset = 0;
                    if (fromId === 'box-downloaded')   laneOffset = -16;
                    if (fromId === 'box-test-ongoing') laneOffset = -32;
                    const laneY = baseLaneY + laneOffset;

                    // Smooth cubic Bézier: drop down → sweep horizontally → rise up
                    const midY = (start.y + laneY) * 0.5;
                    const riseY = (laneY + end.y) * 0.5;
                    d = `M ${start.x} ${start.y} ` +
                        `C ${start.x} ${midY + 30}, ${start.x} ${laneY}, ${start.x + 40} ${laneY} ` +
                        `C ${start.x + 80} ${laneY}, ${end.x - 80} ${laneY}, ${end.x} ${laneY} ` +
                        `C ${end.x} ${laneY}, ${end.x} ${riseY}, ${end.x} ${end.y}`;
                } else if (isDashed && fromPos === 'bottom' && toPos === 'top') {
                    d = `M ${start.x} ${start.y} L ${end.x} ${end.y}`;
                } else {
                    const dx = end.x - start.x;
                    const cpOffset = dx * 0.45;
                    d = `M ${start.x} ${start.y} C ${start.x + cpOffset} ${start.y}, ${end.x - cpOffset} ${end.y}, ${end.x} ${end.y}`;
                }
                
                // 1. Background tube (thick, semi-transparent)
                const pathBg = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                pathBg.setAttribute('d', d);
                pathBg.setAttribute('fill', 'none');
                pathBg.setAttribute('stroke', color);
                pathBg.setAttribute('stroke-width', '4');
                pathBg.setAttribute('opacity', '0.22');
                svg.appendChild(pathBg);
                
                // 2. Animated flow line on top
                const pathFlow = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                pathFlow.setAttribute('d', d);
                pathFlow.setAttribute('fill', 'none');
                pathFlow.setAttribute('stroke', color);
                pathFlow.setAttribute('stroke-width', '1.8');
                if (isDashed) {
                    pathFlow.setAttribute('class', 'flow-animation-line-dashed');
                } else {
                    pathFlow.setAttribute('class', 'flow-animation-line');
                }
                pathFlow.setAttribute('marker-end', `url(#arrow-${color.replace('#', '')})`);
                svg.appendChild(pathFlow);
                
                // 3. Circle node at start
                const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                circle.setAttribute('cx', start.x);
                circle.setAttribute('cy', start.y);
                circle.setAttribute('r', '4.5');
                circle.setAttribute('fill', color);
                svg.appendChild(circle);
            };
            
            // Peta Jalur Alur Proses
            drawPath('node-manager-avatar', 'right', 'box-task-baru', 'left', '#f59e0b');
            drawPath('box-task-baru', 'right', 'box-downloaded', 'left', '#f59e0b');
            drawPath('box-downloaded', 'right', 'box-test-ongoing', 'left', '#3b82f6');
            drawPath('box-test-ongoing', 'right', 'box-pending-feedback', 'left', '#0ea5e9');
            drawPath('box-pending-feedback', 'right', 'box-feedback-sent', 'left', '#0ea5e9');
            
            // Loopback: Feedback Sent -> Test Ongoing (goes backward from bottom to bottom)
            drawPath('box-feedback-sent', 'bottom', 'box-test-ongoing', 'bottom', '#eab308', false, true);
            
            drawPath('box-test-ongoing', 'right', 'box-passed', 'left', '#8b5cf6');
            drawPath('box-passed', 'right', 'box-submitted', 'left', '#d946ef');
            drawPath('box-submitted', 'right', 'box-approved', 'left', '#22c55e');
            drawPath('box-submitted', 'right', 'box-batal', 'left', '#ef4444', true);
            
            // Cancelled connections from New, Downloaded, and Testing
            // All start from bottom connector and end at bottom of box-batal
            drawPath('box-task-baru', 'bottom', 'box-batal', 'bottom', '#ef4444', true);
            drawPath('box-downloaded', 'bottom', 'box-batal', 'bottom', '#ef4444', true);
            drawPath('box-test-ongoing', 'bottom', 'box-batal', 'bottom', '#ef4444', true);
        }

        // --- 8. INITIALIZERS & CLEANUPS ---
        window.addEventListener('load', () => {
            const savedMode = localStorage.getItem('roadmap_view_mode') || 'summary';
            setViewMode(savedMode);
            
            document.addEventListener('dragend', () => {
                document.querySelectorAll('.kanban-cards-container').forEach(el => {
                    el.classList.remove('dragover-active');
                });
            });
            
            // Profile dropdown toggle
            const profileMenu = document.getElementById('profile-menu');
            if (profileMenu) {
                const profileButton = profileMenu.querySelector('button');
                const profileDropdown = document.getElementById('profile-dropdown');
                profileButton.addEventListener('click', (e) => {
                    e.stopPropagation();
                    profileDropdown.classList.toggle('hidden');
                });
                document.addEventListener('click', (e) => {
                    if (!profileMenu.contains(e.target)) {
                        profileDropdown.classList.add('hidden');
                    }
                });
            }

            // Event listener klik di luar untuk menutup slide-over drawer
            document.addEventListener('click', (e) => {
                const drawer = document.getElementById('task-drawer');
                if (!drawer.classList.contains('translate-x-full')) {
                    // Cek jika klik berada di luar drawer dan di luar summary cards yang memicunya
                    if (!drawer.contains(e.target) && !e.target.closest('.summary-card') && !e.target.closest('.themed-input') && !e.target.closest('#task-modal')) {
                        closeDrawer();
                    }
                }
            });
        });

        window.addEventListener('resize', () => {
            if (currentViewMode === 'summary') {
                drawConnections();
            }
        });
    </script>
</body>

</html>