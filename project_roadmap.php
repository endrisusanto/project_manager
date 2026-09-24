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
                                $checklist = json_decode($task['test_items_checklist'] ?? '', true);
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
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Roadmap - Pipeline Board</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['class', '.never-match-dark']
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <!-- Three.js and OrbitControls for 3D Coffee Shop Playground -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
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
                <div class="flex items-center bg-[var(--input-bg)] border border-[var(--input-border)] p-1 rounded-xl gap-1 shadow-sm">
                    <button id="btn-view-summary" onclick="setViewMode('summary')" class="px-3.5 py-1.5 text-xs font-bold rounded-lg transition-all flex items-center gap-1.5 shadow-sm">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"></path></svg>
                        <span>Summary Flow</span>
                    </button>
                    <button id="btn-view-coffee" onclick="setViewMode('coffee')" class="px-3.5 py-1.5 text-xs font-bold rounded-lg transition-all flex items-center gap-1.5 text-[var(--text-secondary)] hover:text-[var(--text-primary)]" title="Playground 3D Antrean Layanan Cafe Coffee Shop">
                        <span>☕</span>
                        <span>Coffee Shop 3D</span>
                    </button>
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

            <!-- Coffee Shop 3D Playground Viewport (Three.js Service Queue Simulation) -->
            <div id="coffee-playground-wrapper" class="hidden relative w-full h-full min-h-[640px] flex flex-col rounded-2xl overflow-hidden border border-amber-500/30 bg-[#090d16] select-none shadow-2xl">
                <!-- HUD Top Bar: Stats, Zone Badges, Action Tools -->
                <div class="absolute top-4 left-4 right-4 z-20 flex flex-wrap items-center justify-between gap-3 pointer-events-none">
                    <!-- Zone Counter Badges / Legend (Modern Specialty Coffee Shop Flow) -->
                    <div class="flex flex-wrap items-center gap-2 pointer-events-auto bg-slate-900/90 backdrop-blur-md p-2 rounded-xl border border-amber-500/30 shadow-xl">
                        <div class="text-[11px] font-extrabold text-amber-400 flex items-center gap-1.5 pr-2.5 border-r border-slate-700/80">
                            <span class="text-base animate-pulse">☕</span>
                            <span class="tracking-wide">SPECIALTY CAFE</span>
                        </div>
                        <button type="button" onclick="openTasksDrawer('Task Baru')" class="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-amber-500/15 hover:bg-amber-500/30 border border-amber-500/40 text-amber-300 text-xs font-semibold transition-all hover:scale-105" title="1. Kasir & Pemesanan: Customer pesan & bayar di kasir (Task Baru / Downloaded)">
                            <span>💳 1. Kasir:</span>
                            <span id="coffee-cnt-queue" class="font-extrabold text-white">0</span>
                        </button>
                        <button type="button" onclick="openTasksDrawer('Test Ongoing')" class="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-blue-500/15 hover:bg-blue-500/30 border border-blue-500/40 text-blue-300 text-xs font-semibold transition-all hover:scale-105" title="3. Area Tunggu Racikan: Customer menunggu kopi diracik barista (Test Ongoing / Feedback)">
                            <span>⏳ 3. Menunggu Racikan:</span>
                            <span id="coffee-cnt-brewing" class="font-extrabold text-white">0</span>
                        </button>
                        <button type="button" onclick="openTasksDrawer('Submitted')" class="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-purple-500/15 hover:bg-purple-500/30 border border-purple-500/40 text-purple-300 text-xs font-semibold transition-all hover:scale-105" title="4. Meja Pickup: Kopi siap & nama customer dipanggil (Submission / Passed)">
                            <span>📢 4. Pick-up Calling:</span>
                            <span id="coffee-cnt-cashier" class="font-extrabold text-white">0</span>
                        </button>
                        <button type="button" onclick="openTasksDrawer('Approved')" class="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-500/15 hover:bg-emerald-500/30 border border-emerald-500/40 text-emerald-300 text-xs font-semibold transition-all hover:scale-105" title="5. Dine-in Lounge: Kopi dinikmati dengan puas (Approved)">
                            <span>✨ 5. Lounge Santai:</span>
                            <span id="coffee-cnt-approved" class="font-extrabold text-white">0</span>
                        </button>
                        <button type="button" onclick="openTasksDrawer('Batal')" class="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-red-500/15 hover:bg-red-500/30 border border-red-500/40 text-red-300 text-xs font-semibold transition-all hover:scale-105" title="6. Reject / Batal: Pesanan dibatalkan / kopi cacat dibuang">
                            <span>🗑️ 6. Reject:</span>
                            <span id="coffee-cnt-cancelled" class="font-extrabold text-white">0</span>
                        </button>
                    </div>

                    <!-- Camera Presets & Dynamic Shifting Tools -->
                    <div class="flex items-center gap-1.5 pointer-events-auto bg-slate-900/90 backdrop-blur-md p-1.5 rounded-xl border border-slate-700/80 shadow-xl">
                        <button type="button" onclick="setCoffeeCameraPreset('iso')" class="px-2.5 py-1 text-xs font-bold rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 transition-all" title="Sudut Pandang Isometrik 3D">📸 Iso</button>
                        <button type="button" onclick="setCoffeeCameraPreset('kasir')" class="px-2.5 py-1 text-xs font-bold rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 transition-all" title="1. Kamera Meja Kasir & Order">💳 Kasir</button>
                        <button type="button" onclick="setCoffeeCameraPreset('barista')" class="px-2.5 py-1 text-xs font-bold rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 transition-all" title="2. Kamera Meja Barista & Mesin Espresso">☕ Barista</button>
                        <button type="button" onclick="setCoffeeCameraPreset('tunggu')" class="px-2.5 py-1 text-xs font-bold rounded-lg text-sky-400 bg-sky-500/15 border border-sky-500/30 hover:bg-sky-500/30 transition-all" title="3. Kamera Area Tunggu Khusus Racikan (Testing)">⏳ Area Tunggu</button>
                        <button type="button" onclick="setCoffeeCameraPreset('pickup')" class="px-2.5 py-1 text-xs font-bold rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 transition-all" title="4. Kamera Meja Pickup Counter & Panggilan">📢 Pickup</button>
                        <button type="button" onclick="setCoffeeCameraPreset('lounge')" class="px-2.5 py-1 text-xs font-bold rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 transition-all" title="5. Kamera Meja Santai Dine-in">✨ Lounge</button>
                        <button type="button" onclick="setCoffeeCameraPreset('top')" class="px-2.5 py-1 text-xs font-bold rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 transition-all" title="Kamera Denah Cafe">🗺️ Denah</button>
                        <button type="button" id="btn-coffee-orbit" onclick="toggleCoffeeAutoOrbit()" class="px-2.5 py-1 text-xs font-bold rounded-lg text-amber-400 hover:bg-amber-500/20 transition-all" title="Putar Kamera Otomatis Keliling Cafe">🔄 Putar</button>
                    </div>
                </div>

                <!-- 3D Canvas Mount Point -->
                <div id="coffee-3d-canvas-container" class="w-full h-full flex-grow relative cursor-grab active:cursor-grabbing min-h-[580px]"></div>

                <!-- Floating Task Detail Popover Card -->
                <div id="coffee-task-card" class="hidden absolute bottom-5 left-5 z-30 bg-slate-900/95 backdrop-blur-md p-4 rounded-xl border border-amber-500/40 shadow-2xl max-w-sm pointer-events-auto transition-all">
                    <!-- Populated dynamically by JS -->
                </div>

                <!-- Guidance Footer Tooltip -->
                <div class="absolute bottom-3 right-5 z-20 pointer-events-none text-[11px] text-slate-400 bg-slate-900/80 backdrop-blur-md px-3.5 py-1.5 rounded-lg border border-slate-700/60 shadow-md flex items-center gap-2">
                    <span>🖱️ <span class="text-slate-300 font-semibold">Putar:</span> Klik Kiri Drag</span>
                    <span>•</span>
                    <span><span class="text-slate-300 font-semibold">Geser:</span> Klik Kanan</span>
                    <span>•</span>
                    <span><span class="text-slate-300 font-semibold">Zoom:</span> Scroll</span>
                    <span>•</span>
                    <span class="text-amber-300 font-semibold">Klik Karakter/Station:</span> <span>Detail Task</span>
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
        const root = document.documentElement;

        // --- 2. BACKGROUND ANIMATION (NEURAL NETWORK) ---
        const canvas = document.getElementById('neural-canvas'), ctx = canvas.getContext('2d');
        let particles = [], hue = 210;
        let isNeuralAnimRunning = true;
        let neuralAnimId = null;
        function setCanvasSize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
        class Particle { constructor() { this.x = Math.random() * canvas.width; this.y = Math.random() * canvas.height; this.vx = (Math.random() - .5) * .4; this.vy = (Math.random() - .5) * .4; this.size = Math.random() * 2 + 1.5; } update() { this.x += this.vx; this.y += this.vy; if (this.x < 0 || this.x > canvas.width) this.vx *= -1; if (this.y < 0 || this.y > canvas.height) this.vy *= -1; } draw() { ctx.fillStyle = `hsl(${hue},100%,75%)`; ctx.beginPath(); ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2); ctx.fill(); } }
        function initParticles(n) { particles = []; for (let i = 0; i < n; i++) particles.push(new Particle()); }
        function animateParticles() { 
            if (!isNeuralAnimRunning) return;
            ctx.clearRect(0, 0, canvas.width, canvas.height); 
            hue = (hue + .3) % 360; 
            particles.forEach(p => { p.update(); p.draw(); }); 
            neuralAnimId = requestAnimationFrame(animateParticles); 
        }
        function pauseNeuralAnimation() {
            isNeuralAnimRunning = false;
            if (neuralAnimId) cancelAnimationFrame(neuralAnimId);
        }
        function resumeNeuralAnimation() {
            if (!isNeuralAnimRunning) {
                isNeuralAnimRunning = true;
                animateParticles();
            }
        }
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
        const coffeeBaristasList = <?= json_encode($filter_pics_list ?: [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

        function setViewMode(mode) {
            if (mode !== 'coffee') mode = 'summary';
            currentViewMode = mode;
            const board = document.getElementById('pipeline-board');
            const zoomWrapper = document.getElementById('pipeline-zoom-wrapper');
            const coffeeWrapper = document.getElementById('coffee-playground-wrapper');
            const btnSummary = document.getElementById('btn-view-summary');
            const btnCoffee = document.getElementById('btn-view-coffee');
            const svg = document.getElementById('flow-svg');
            
            // Reset all toggle buttons styling
            [btnSummary, btnCoffee].forEach(btn => {
                if (btn) {
                    btn.classList.remove('bg-indigo-600', 'bg-amber-600', 'text-white', 'shadow-md');
                    btn.classList.add('text-[var(--text-secondary)]');
                }
            });

            if (mode === 'coffee') {
                pauseNeuralAnimation(); // Free up 100% 2D canvas CPU
                if (zoomWrapper) zoomWrapper.style.display = 'none';
                if (coffeeWrapper) coffeeWrapper.classList.remove('hidden');
                
                if (btnCoffee) {
                    btnCoffee.classList.add('bg-amber-600', 'text-white', 'shadow-md');
                    btnCoffee.classList.remove('text-[var(--text-secondary)]');
                }
                
                if (svg) svg.querySelectorAll('path, circle').forEach(el => el.remove());
                
                // Initialize or resume Three.js scene
                initOrResumeCoffeePlayground();
            } else {
                resumeNeuralAnimation();
                if (coffeeWrapper) coffeeWrapper.classList.add('hidden');
                if (zoomWrapper) zoomWrapper.style.display = '';
                
                // Pause Three.js loop to save 100% CPU when not in coffee mode
                pauseCoffeePlayground();

                if (board) {
                    board.classList.remove('view-kanban');
                    board.classList.add('view-summary');
                }
                
                if (btnSummary) {
                    btnSummary.classList.add('bg-indigo-600', 'text-white', 'shadow-md');
                    btnSummary.classList.remove('text-[var(--text-secondary)]');
                }
                
                setTimeout(drawConnections, 50);
            }
            
            localStorage.setItem('roadmap_view_mode', mode);
        }

        function updateZoom() {
            if (currentViewMode === 'coffee') {
                if (coffeeCamera) {
                    // Zoom camera smoothly
                    coffeeCamera.zoom = zoomScale;
                    coffeeCamera.updateProjectionMatrix();
                    document.getElementById('zoom-indicator').innerText = `${Math.round(zoomScale * 100)}%`;
                }
                return;
            }
            
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
            if (currentViewMode === 'coffee' && coffeeCamera) {
                coffeeCamera.zoom = 1.0;
                coffeeCamera.updateProjectionMatrix();
                setCoffeeCameraPreset('iso');
            }
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
            if (currentViewMode === 'coffee') {
                setTimeout(onCoffeeResize, 150);
            }
        }

        document.addEventListener('fullscreenchange', () => {
            if (currentViewMode === 'coffee') {
                setTimeout(onCoffeeResize, 150);
            }
        });

        // ==============================================================================
        // --- 4B. 3D THREE.JS COFFEE SHOP PLAYGROUND (SERVICE QUEUE SIMULATION) ---
        // ==============================================================================
        let coffeeScene = null, coffeeCamera = null, coffeeRenderer = null, coffeeControls = null;
        let coffeeAnimId = null;
        let isCoffeeInitialized = false;
        let isCoffeeRunning = false;
        let coffeeCustomers = [];
        let coffeeBaristas = [];
        let coffeeStaff = [];
        let steamParticles = [];
        let coffeeCamTargetPos = null;
        let coffeeCamLookTarget = null;
        let isAutoOrbiting = false;
        let hoveredMesh = null;
        const coffeeRaycaster = (typeof THREE !== 'undefined') ? new THREE.Raycaster() : null;
        const coffeeMouse = (typeof THREE !== 'undefined') ? new THREE.Vector2() : null;

        function getStatusBadgeColor(status) {
            switch(status) {
                case 'Task Baru': return '#d97706'; // amber-600
                case 'Downloaded': return '#b45309'; // amber-700
                case 'Test Ongoing': return '#2563eb'; // blue-600
                case 'Pending Feedback': return '#eab308'; // yellow-500
                case 'Feedback Sent': return '#ea580c'; // orange-600
                case 'Submitted': return '#7c3aed'; // violet-600
                case 'Passed': return '#4f46e5'; // indigo-600
                case 'Approved': return '#059669'; // emerald-600
                case 'Batal': return '#dc2626'; // red-600
                default: return '#475569';
            }
        }

        function getTaskMetaphorInfo(status) {
            switch(status) {
                case 'Task Baru':
                case 'Downloaded':
                    return {
                        zoneName: '1. Kasir & Pemesanan (POS)',
                        cafeAction: 'Customer Datang ke Kasir, Memesan Menu & Konfirmasi Order (Task Baru)',
                        statusClass: 'text-amber-400',
                        bgPill: 'bg-amber-500/20 text-amber-300 border-amber-500/30'
                    };
                case 'Test Ongoing':
                case 'Pending Feedback':
                case 'Feedback Sent':
                    return {
                        zoneName: '3. Area Tunggu Racikan (Testing Ongoing)',
                        cafeAction: 'Customer Duduk di Area Tunggu Khusus Memantau Kopi Sedang Diracik Barista (Testing Ongoing)',
                        statusClass: 'text-blue-400',
                        bgPill: 'bg-blue-500/20 text-blue-300 border-blue-500/30'
                    };
                case 'Passed':
                    return {
                        zoneName: 'Meja QC Barista & Verifikasi',
                        cafeAction: 'Kopi Selesai Diracik & Lolos Uji Kualitas Rasa / Latte Art (Passed)',
                        statusClass: 'text-indigo-400',
                        bgPill: 'bg-indigo-500/20 text-indigo-300 border-indigo-500/30'
                    };
                case 'Submitted':
                    return {
                        zoneName: '4. Meja Pick-up Counter & Panggilan',
                        cafeAction: 'Kopi Siap! Nama Customer Dipanggil di Meja Serah Terima (Submitted ke Manager)',
                        statusClass: 'text-purple-400',
                        bgPill: 'bg-purple-500/20 text-purple-300 border-purple-500/30'
                    };
                case 'Approved':
                    return {
                        zoneName: '5. Meja Santai / Dine-in Lounge',
                        cafeAction: 'Customer Menikmati Kopi Lezat di Meja Kafe (Approved & Sukses)',
                        statusClass: 'text-emerald-400',
                        bgPill: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30'
                    };
                case 'Batal':
                    return {
                        zoneName: '6. Tempat Sampah / Spill Bin',
                        cafeAction: 'Pesanan Dibatalkan / Kopi Cacat Dibuang ke Tong Sampah (Batal)',
                        statusClass: 'text-red-400',
                        bgPill: 'bg-red-500/20 text-red-300 border-red-500/30'
                    };
                default:
                    return {
                        zoneName: 'Specialty Cafe GBA',
                        cafeAction: 'Layanan Cafe GBA',
                        statusClass: 'text-slate-300',
                        bgPill: 'bg-slate-700/40 text-slate-300 border-slate-600'
                    };
            }
        }

        function createWoodTexture() {
            const canvas = document.createElement('canvas');
            canvas.width = 512;
            canvas.height = 512;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#26140b';
            ctx.fillRect(0, 0, 512, 512);

            const plankH = 32;
            const plankW = 128;
            for (let y = 0; y < 512; y += plankH) {
                const row = Math.floor(y / plankH);
                const offsetX = (row % 2 === 0) ? 0 : plankW / 2;
                for (let x = -plankW; x < 512 + plankW; x += plankW) {
                    const actualX = x + offsetX;
                    const tone = 40 + ((actualX * 9 + y * 17) % 24);
                    ctx.fillStyle = `rgb(${tone + 30}, ${Math.floor(tone * 0.75) + 12}, ${Math.floor(tone * 0.45) + 6})`;
                    ctx.fillRect(actualX + 1, y + 1, plankW - 2, plankH - 2);

                    // Subtle wood grain accent lines
                    ctx.fillStyle = 'rgba(0, 0, 0, 0.1)';
                    ctx.fillRect(actualX + 1, y + 8, plankW - 2, 2);
                    ctx.fillRect(actualX + 1, y + 20, plankW - 2, 1.5);
                }
            }

            const texture = new THREE.CanvasTexture(canvas);
            texture.wrapS = THREE.RepeatWrapping;
            texture.wrapT = THREE.RepeatWrapping;
            texture.repeat.set(6, 4);
            return texture;
        }

        function createBadgeSprite(text, bgColor = '#1e293b', textColor = '#ffffff', icon = '', subtext = '') {
            const canvas = document.createElement('canvas');
            canvas.width = 256;
            canvas.height = 84;
            const ctx = canvas.getContext('2d');

            // Draw pill background
            ctx.fillStyle = bgColor;
            if (ctx.roundRect) {
                ctx.beginPath();
                ctx.roundRect(6, 6, 244, 72, 18);
                ctx.fill();
            } else {
                ctx.fillRect(6, 6, 244, 72);
            }

            // Border stroke
            ctx.strokeStyle = 'rgba(255, 255, 255, 0.35)';
            ctx.lineWidth = 3;
            if (ctx.roundRect) {
                ctx.stroke();
            }

            // Main Text
            ctx.fillStyle = textColor;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = 'bold 22px Inter, sans-serif';

            const displayTitle = (icon ? icon + ' ' : '') + text;
            ctx.fillText(displayTitle, 128, subtext ? 32 : 42);

            // Subtext if present
            if (subtext) {
                ctx.fillStyle = 'rgba(255, 255, 255, 0.8)';
                ctx.font = 'bold 13px Inter, sans-serif';
                ctx.fillText(subtext, 128, 56);
            }

            const texture = new THREE.CanvasTexture(canvas);
            const material = new THREE.SpriteMaterial({ map: texture, transparent: true, depthTest: false });
            const sprite = new THREE.Sprite(material);
            sprite.scale.set(3.2, 1.05, 1.0);
            return sprite;
        }

        const ROBLOX_FACE_MATS = {};
        function getRobloxFaceMaterial(type = 'smile') {
            if (ROBLOX_FACE_MATS[type]) return ROBLOX_FACE_MATS[type];
            const canvas = document.createElement('canvas');
            canvas.width = 128;
            canvas.height = 128;
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, 128, 128);

            ctx.fillStyle = '#111827';
            ctx.strokeStyle = '#111827';
            ctx.lineWidth = 6;
            ctx.lineCap = 'round';

            if (type === 'wink') {
                // Classic Roblox Wink
                ctx.beginPath();
                ctx.arc(38, 48, 8, 0, Math.PI * 2);
                ctx.fill();
                ctx.beginPath();
                ctx.moveTo(74, 48); ctx.lineTo(94, 48);
                ctx.stroke();
                ctx.beginPath();
                ctx.arc(66, 72, 20, 0.2, Math.PI - 0.3);
                ctx.stroke();
            } else if (type === 'cool') {
                // Classic Roblox Sunglasses
                ctx.fillStyle = '#0f172a';
                ctx.fillRect(20, 38, 38, 22);
                ctx.fillRect(70, 38, 38, 22);
                ctx.fillRect(56, 44, 16, 6);
                ctx.beginPath();
                ctx.arc(64, 76, 20, 0.15, Math.PI - 0.15);
                ctx.stroke();
            } else if (type === 'barista') {
                // Dedicated Friendly Barista Face
                ctx.beginPath();
                ctx.arc(38, 48, 7.5, 0, Math.PI * 2);
                ctx.arc(90, 48, 7.5, 0, Math.PI * 2);
                ctx.fill();
                ctx.beginPath();
                ctx.moveTo(30, 36); ctx.lineTo(46, 38);
                ctx.moveTo(82, 38); ctx.lineTo(98, 36);
                ctx.stroke();
                ctx.beginPath();
                ctx.arc(64, 72, 22, 0.15, Math.PI - 0.15);
                ctx.stroke();
            } else {
                // Classic Roblox Big Smile :D
                ctx.beginPath();
                ctx.arc(38, 46, 7.5, 0, Math.PI * 2);
                ctx.arc(90, 46, 7.5, 0, Math.PI * 2);
                ctx.fill();
                ctx.beginPath();
                ctx.arc(64, 68, 22, 0.15, Math.PI - 0.15);
                ctx.stroke();
            }

            const tex = new THREE.CanvasTexture(canvas);
            ROBLOX_FACE_MATS[type] = new THREE.MeshBasicMaterial({ map: tex, transparent: true });
            return ROBLOX_FACE_MATS[type];
        }

        function createRobloxFacePlane(type = 'smile') {
            const mat = getRobloxFaceMaterial(type);
            const plane = new THREE.Mesh(new THREE.PlaneGeometry(0.5, 0.5), mat);
            plane.position.set(0, 1.66, 0.27);
            return plane;
        }

        function createRobloxRig({
            skinColor = 0xffcc00, // Classic Roblox Yellow
            shirtColor = 0x2563eb,
            pantsColor = 0x1e293b,
            faceType = 'smile',
            hairColor = 0x1c1917,
            hatType = 'none',     // 'none', 'cap', 'beanie', 'hair'
            hatColor = 0x064e3b,
            hasApron = false,
            apronColor = 0x78350f,
            armPose = 'normal'    // 'normal', 'barista', 'holding_cup', 'cashier'
        }) {
            const group = new THREE.Group();

            const skinMat = new THREE.MeshLambertMaterial({ color: skinColor, roughness: 0.7 });
            const shirtMat = new THREE.MeshLambertMaterial({ color: shirtColor, roughness: 0.7 });
            const pantsMat = new THREE.MeshLambertMaterial({ color: pantsColor, roughness: 0.8 });

            // 1. HEAD (Classic Roblox Blocky Box Head)
            const headGeo = new THREE.BoxGeometry(0.54, 0.54, 0.52);
            const head = new THREE.Mesh(headGeo, skinMat);
            head.position.y = 1.66;
            group.add(head);

            // Iconic Roblox Head Stud
            const studGeo = new THREE.CylinderGeometry(0.12, 0.12, 0.08, 16);
            const stud = new THREE.Mesh(studGeo, skinMat);
            stud.position.set(0, 1.97, 0);
            group.add(stud);

            // Face Plane Decal
            const face = createRobloxFacePlane(faceType);
            group.add(face);

            // Head Accessories (Cap / Hair / Beanie)
            if (hatType === 'cap' || hatType === 'beanie') {
                const capMat = new THREE.MeshLambertMaterial({ color: hatColor, roughness: 0.6 });
                const capTop = new THREE.Mesh(new THREE.BoxGeometry(0.58, 0.18, 0.56), capMat);
                capTop.position.set(0, 1.90, 0);
                group.add(capTop);

                if (hatType === 'cap') {
                    // Front Visor
                    const visor = new THREE.Mesh(new THREE.BoxGeometry(0.58, 0.04, 0.22), capMat);
                    visor.position.set(0, 1.83, 0.36);
                    group.add(visor);
                }
            } else if (hatType === 'hair') {
                const hairMat = new THREE.MeshLambertMaterial({ color: hairColor, roughness: 0.8 });
                const hairTop = new THREE.Mesh(new THREE.BoxGeometry(0.58, 0.22, 0.56), hairMat);
                hairTop.position.set(0, 1.88, -0.02);
                const hairBack = new THREE.Mesh(new THREE.BoxGeometry(0.58, 0.35, 0.12), hairMat);
                hairBack.position.set(0, 1.70, -0.22);
                group.add(hairTop, hairBack);
            }

            // 2. TORSO (Classic Roblox Boxy Torso)
            const torsoGeo = new THREE.BoxGeometry(0.86, 0.88, 0.44);
            const torso = new THREE.Mesh(torsoGeo, shirtMat);
            torso.position.y = 1.14;
            group.add(torso);

            // Apron Overlay if applicable
            if (hasApron) {
                const apronMat = new THREE.MeshLambertMaterial({ color: apronColor, roughness: 0.65 });
                const apronPlate = new THREE.Mesh(new THREE.BoxGeometry(0.74, 0.68, 0.04), apronMat);
                apronPlate.position.set(0, 1.10, 0.23);
                const apronNeck = new THREE.Mesh(new THREE.BoxGeometry(0.38, 0.18, 0.04), apronMat);
                apronNeck.position.set(0, 1.48, 0.23);
                group.add(apronPlate, apronNeck);
            }

            // 3. LEGS (Classic Roblox Two Blocky Legs)
            const legGeo = new THREE.BoxGeometry(0.40, 0.70, 0.40);
            const legL = new THREE.Mesh(legGeo, pantsMat);
            legL.position.set(-0.22, 0.35, 0);
            const legR = new THREE.Mesh(legGeo, pantsMat);
            legR.position.set(0.22, 0.35, 0);
            group.add(legL, legR);

            // 4. ARMS (Left & Right Arms)
            const armGeo = new THREE.BoxGeometry(0.38, 0.84, 0.38);
            const armL = new THREE.Mesh(armGeo, shirtMat);
            armL.position.set(-0.63, 1.14, 0);
            group.add(armL);

            const armR = new THREE.Mesh(armGeo, shirtMat);
            armR.position.set(0.63, 1.14, 0);

            if (armPose === 'barista') {
                // Right arm forward holding espresso portafilter
                armR.position.set(0.58, 1.20, 0.16);
                armR.rotation.x = Math.PI / 3.5;

                const pfGeo = new THREE.CylinderGeometry(0.08, 0.07, 0.12, 12);
                const pfMat = new THREE.MeshStandardMaterial({ color: 0xd1d5db, metalness: 0.85, roughness: 0.25 });
                const pf = new THREE.Mesh(pfGeo, pfMat);
                pf.position.set(0.58, 1.05, 0.45);

                const handleGeo = new THREE.CylinderGeometry(0.03, 0.03, 0.25, 8);
                const handleMat = new THREE.MeshLambertMaterial({ color: 0x78350f });
                const handle = new THREE.Mesh(handleGeo, handleMat);
                handle.rotation.x = Math.PI / 2;
                handle.position.set(0.58, 1.05, 0.60);
                group.add(pf, handle);
            } else if (armPose === 'cashier') {
                // Right arm forward at POS Terminal
                armR.position.set(0.58, 1.18, 0.22);
                armR.rotation.x = Math.PI / 3.0;
            } else if (armPose === 'holding_cup') {
                // Right arm holding takeaway coffee cup
                armR.position.set(0.58, 1.18, 0.22);
                armR.rotation.x = Math.PI / 3.8;

                const cupGroup = new THREE.Group();
                const cup = new THREE.Mesh(new THREE.CylinderGeometry(0.09, 0.07, 0.22, 12), new THREE.MeshLambertMaterial({ color: 0xf8fafc }));
                const sleeve = new THREE.Mesh(new THREE.CylinderGeometry(0.092, 0.08, 0.1, 12), new THREE.MeshLambertMaterial({ color: 0x92400e }));
                const lid = new THREE.Mesh(new THREE.CylinderGeometry(0.095, 0.095, 0.04, 12), new THREE.MeshLambertMaterial({ color: 0x1e293b }));
                lid.position.y = 0.12;
                cupGroup.add(cup, sleeve, lid);
                cupGroup.position.set(0.58, 1.05, 0.45);
                group.add(cupGroup);
            }

            group.add(armR);
            return group;
        }

        function createBaristaMesh(picName, x, z) {
            const group = createRobloxRig({
                skinColor: 0xffcc00, // Classic Roblox Yellow
                shirtColor: 0x292524, // Warm Black
                pantsColor: 0x1e293b, // Denim Slate
                faceType: 'barista',
                hatType: 'beanie',
                hatColor: 0x064e3b, // Deep Emerald
                hasApron: true,
                apronColor: 0x78350f, // Leather Barista Apron
                armPose: 'barista'
            });
            group.position.set(x, 0, z);

            // Floating 3D Badge: "☕ Barista [PIC Name]"
            const badge = createBadgeSprite('Barista ' + picName, '#78350f', '#fef3c7', '☕', 'PIC Roaster');
            badge.position.y = 2.55;
            group.add(badge);

            group.userData = {
                isBarista: true,
                picName: picName,
                baseY: 0,
                rotPhase: Math.random() * Math.PI * 2
            };

            return group;
        }

        function createKasirStaffMesh(x, z) {
            const group = createRobloxRig({
                skinColor: 0xffcc00, // Classic Roblox Yellow
                shirtColor: 0xd97706, // Amber Cashier Uniform
                pantsColor: 0x0f172a, // Black Pants
                faceType: 'smile',
                hatType: 'cap',
                hatColor: 0x92400e, // Warm Amber Visor Cap
                hasApron: true,
                apronColor: 0x451a03, // Coffee Apron
                armPose: 'cashier'
            });
            group.position.set(x, 0, z);

            const badge = createBadgeSprite('Staff Kasir', '#d97706', '#ffffff', '💳', 'Kasir Order & POS');
            badge.position.y = 2.55;
            group.add(badge);

            group.userData = {
                isStaff: true,
                staffName: 'Staff Kasir (Roblox)',
                roleTitle: 'Kasir & Order Front Desk',
                icon: '💳',
                iconBg: 'bg-amber-600',
                stationName: '1. Meja Kasir & POS Order',
                description: 'Staff kasir bertugas melayani pesanan task baru masuk, mengonfirmasi detail pembayaran, dan mendaftarkan pesanan ke sistem cafe.'
            };

            return group;
        }

        function createPickupStaffMesh(x, z) {
            const group = createRobloxRig({
                skinColor: 0xffcc00, // Classic Roblox Yellow
                shirtColor: 0x7c3aed, // Royal Purple Uniform
                pantsColor: 0x1e293b, // Slate Pants
                faceType: 'wink',
                hatType: 'cap',
                hatColor: 0x5b21b6, // Deep Purple Visor Cap
                hasApron: true,
                apronColor: 0x3b0764, // Dark Purple Apron
                armPose: 'holding_cup'
            });
            group.position.set(x, 0, z);

            const badge = createBadgeSprite('Staff Pick-up', '#7c3aed', '#ffffff', '📢', 'Serah Terima Pesanan');
            badge.position.y = 2.55;
            group.add(badge);

            group.userData = {
                isStaff: true,
                staffName: 'Staff Pick-up (Roblox)',
                roleTitle: 'Barista Serah Terima',
                icon: '📢',
                iconBg: 'bg-purple-600',
                stationName: '4. Meja Pick-up Counter & Panggilan',
                description: 'Staff pick-up bertugas memanggil nama customer saat racikan kopi (testing task) telah selesai dan siap diserahterimakan (Submitted / Passed).'
            };

            return group;
        }

        function createCustomerMesh(task, colorHex, idx = 0) {
            const skinTones = [0xffcc00, 0xfed7aa, 0xfcd34d, 0xe0a96d, 0xffcc00];
            const pantsList = [0x1e3a8a, 0x1e293b, 0x334155, 0x475569, 0x0f172a, 0x3b82f6];
            const faceList = ['smile', 'wink', 'cool', 'smile'];
            const hatList = ['hair', 'cap', 'none', 'hair'];

            const isHoldingCup = (task.progress_status === 'Approved' || task.progress_status === 'Passed');

            const group = createRobloxRig({
                skinColor: skinTones[idx % skinTones.length],
                shirtColor: colorHex,
                pantsColor: pantsList[idx % pantsList.length],
                faceType: faceList[idx % faceList.length],
                hatType: hatList[idx % hatList.length],
                hatColor: 0x1e293b,
                hairColor: [0x451a03, 0x1c1917, 0xd97706, 0x0f172a][idx % 4],
                hasApron: false,
                armPose: isHoldingCup ? 'holding_cup' : 'normal'
            });

            // Zone Specific Props:
            // 1. Approved / Passed -> Golden/Green Success Halo
            if (task.progress_status === 'Approved' || task.progress_status === 'Passed') {
                const halo = new THREE.Mesh(
                    new THREE.TorusGeometry(0.35, 0.04, 8, 24),
                    new THREE.MeshBasicMaterial({ color: 0x10b981 })
                );
                halo.rotation.x = Math.PI / 2;
                halo.position.y = 2.15;
                group.add(halo);
            }

            // 2. Batal -> Red Cancel Indicator Badge
            if (task.progress_status === 'Batal') {
                const cancelSprite = createBadgeSprite('BATAL', '#dc2626', '#ffffff', '❌');
                cancelSprite.position.y = 2.15;
                cancelSprite.scale.set(1.5, 0.5, 1);
                group.add(cancelSprite);
            }

            // Floating 3D Badge displaying Task Model Name
            const statusColor = getStatusBadgeColor(task.progress_status);
            const badge = createBadgeSprite(task.model_name || 'Task', statusColor, '#ffffff', '📱');
            badge.position.y = 2.45;
            group.add(badge);

            group.userData = {
                isCustomer: true,
                task: task,
                baseY: 0,
                targetX: 0,
                targetZ: 0,
                idleRotY: 0,
                bobSpeed: 2.2 + Math.random() * 0.8
            };

            return group;
        }

        function initOrResumeCoffeePlayground() {
            // 1. Update Top HUD Statistics Badges
            const queueList = [...(tasksDataByStatus['Task Baru'] || []), ...(tasksDataByStatus['Downloaded'] || [])];
            const brewingList = [...(tasksDataByStatus['Test Ongoing'] || []), ...(tasksDataByStatus['Pending Feedback'] || []), ...(tasksDataByStatus['Feedback Sent'] || [])];
            const cashierList = [...(tasksDataByStatus['Submitted'] || [])];
            const approvedList = [...(tasksDataByStatus['Approved'] || []), ...(tasksDataByStatus['Passed'] || [])];
            const cancelledList = [...(tasksDataByStatus['Batal'] || [])];

            const elQ = document.getElementById('coffee-cnt-queue');
            const elB = document.getElementById('coffee-cnt-brewing');
            const elC = document.getElementById('coffee-cnt-cashier');
            const elA = document.getElementById('coffee-cnt-approved');
            const elX = document.getElementById('coffee-cnt-cancelled');
            if (elQ) elQ.innerText = queueList.length;
            if (elB) elB.innerText = brewingList.length;
            if (elC) elC.innerText = cashierList.length;
            if (elA) elA.innerText = approvedList.length;
            if (elX) elX.innerText = cancelledList.length;

            if (!isCoffeeInitialized) {
                if (typeof THREE === 'undefined') {
                    console.warn("Three.js not loaded yet. Retrying in 150ms...");
                    setTimeout(initOrResumeCoffeePlayground, 150);
                    return;
                }
                setupThreeJsCoffeeShop();
                if (coffeeScene && coffeeRenderer) {
                    isCoffeeInitialized = true;
                }
            }

            onCoffeeResize();

            if (!isCoffeeRunning) {
                isCoffeeRunning = true;
                animateCoffee();
            }
        }

        function pauseCoffeePlayground() {
            if (coffeeAnimId) {
                cancelAnimationFrame(coffeeAnimId);
                coffeeAnimId = null;
            }
            isCoffeeRunning = false;
        }

        function setupThreeJsCoffeeShop() {
            const container = document.getElementById('coffee-3d-canvas-container');
            if (!container) return;
            if (typeof THREE === 'undefined') {
                console.warn("Three.js library is not ready.");
                return;
            }

            const width = container.clientWidth || 900;
            const height = container.clientHeight || 600;

            // 1. Scene & Cozy Dark Cafe Fog
            coffeeScene = new THREE.Scene();
            coffeeScene.background = new THREE.Color(0x0a0e17);
            coffeeScene.fog = new THREE.FogExp2(0x0a0e17, 0.014);

            // 2. Perspective Camera
            coffeeCamera = new THREE.PerspectiveCamera(45, width / height, 0.5, 200);
            coffeeCamera.position.set(0, 18, 25);

            // 3. WebGL Renderer
            try {
                coffeeRenderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' });
            } catch (err) {
                console.error("WebGL initialization error:", err);
                container.innerHTML = `
                    <div class="flex flex-col items-center justify-center h-full text-slate-400 p-8 text-center">
                        <span class="text-4xl mb-3">⚠️</span>
                        <h4 class="text-base font-bold text-white mb-1">WebGL Tidak Aktif atau Terkendala</h4>
                        <p class="text-xs max-w-md">Browser tidak dapat menjalankan WebGL. Pastikan fitur Hardware Acceleration / WebGL aktif di browser Anda.</p>
                    </div>
                `;
                return;
            }

            coffeeRenderer.setSize(width, height);
            coffeeRenderer.setPixelRatio(Math.min(window.devicePixelRatio, 1.25)); // Smooth 60fps on integrated GPU
            coffeeRenderer.shadowMap.enabled = false; // Disabled heavy shadow maps for maximum FPS
            container.innerHTML = '';
            container.appendChild(coffeeRenderer.domElement);

            // 4. Orbit Controls
            const OrbitControlsClass = THREE.OrbitControls || window.OrbitControls;
            if (OrbitControlsClass) {
                coffeeControls = new OrbitControlsClass(coffeeCamera, coffeeRenderer.domElement);
                coffeeControls.enableDamping = true;
                coffeeControls.dampingFactor = 0.08;
                coffeeControls.maxPolarAngle = Math.PI / 2.05; // Prevent viewing from under the floor
                coffeeControls.minDistance = 6;
                coffeeControls.maxDistance = 55;
                coffeeControls.target.set(0, 1.5, 0);
            }

            // 5. Lighting Setup (Lightweight warm ambient & directional sunlight)
            const ambient = new THREE.AmbientLight(0xfff5ea, 0.85);
            coffeeScene.add(ambient);

            const sunLight = new THREE.DirectionalLight(0xfff8ee, 0.65);
            sunLight.position.set(15, 22, 14);
            coffeeScene.add(sunLight);

            // 6. Build Coffee Shop Environment
            buildCafeEnvironment();

            // 7. Event Listeners for Raycasting & Tooltip
            container.addEventListener('mousemove', onCoffeeMouseMove);
            container.addEventListener('click', onCoffeeMouseClick);
            window.addEventListener('resize', onCoffeeResize);
        }

        function buildCafeEnvironment() {
            // Core Reusable Cafe Materials
            const counterMat = new THREE.MeshStandardMaterial({ color: 0x3e2723, roughness: 0.7, metalness: 0.1 });
            const counterTopMat = new THREE.MeshStandardMaterial({ color: 0xfaf5ee, roughness: 0.25, metalness: 0.15 });
            const chromeMat = new THREE.MeshStandardMaterial({ color: 0xe2e8f0, metalness: 0.9, roughness: 0.15 });
            const brassMat = new THREE.MeshStandardMaterial({ color: 0xf59e0b, metalness: 0.9, roughness: 0.2 });
            const metalMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, metalness: 0.8, roughness: 0.25 });
            const stoolTopMat = new THREE.MeshStandardMaterial({ color: 0x78350f, roughness: 0.5 });

            // Floor: Warm Parquet Wood Texture
            const woodTex = createWoodTexture();
            const floorGeo = new THREE.PlaneGeometry(44, 28);
            const floorMat = new THREE.MeshStandardMaterial({ map: woodTex, roughness: 0.6, metalness: 0.1 });
            const floor = new THREE.Mesh(floorGeo, floorMat);
            floor.rotation.x = -Math.PI / 2;
            floor.receiveShadow = true;
            coffeeScene.add(floor);

            // Wooden Baseboard Perimeter
            const baseboardMat = new THREE.MeshLambertMaterial({ color: 0x1c100a });
            const bb1 = new THREE.Mesh(new THREE.BoxGeometry(44, 0.4, 0.4), baseboardMat);
            bb1.position.set(0, 0.2, -14);
            const bb2 = new THREE.Mesh(new THREE.BoxGeometry(44, 0.4, 0.4), baseboardMat);
            bb2.position.set(0, 0.2, 14);
            const bb3 = new THREE.Mesh(new THREE.BoxGeometry(0.4, 0.4, 28), baseboardMat);
            bb3.position.set(-22, 0.2, 0);
            const bb4 = new THREE.Mesh(new THREE.BoxGeometry(0.4, 0.4, 28), baseboardMat);
            bb4.position.set(22, 0.2, 0);
            coffeeScene.add(bb1, bb2, bb3, bb4);

            // =========================================================================
            // 1. KASIR & POS ORDER (Task Baru & Downloaded) - Di Pintu Masuk Kiri
            // =========================================================================
            const zone1Title = createBadgeSprite('1. KASIR & ORDER', '#d97706', '#ffffff', '💳', 'Pesan & Bayar (Task Baru)');
            zone1Title.position.set(-11, 4.0, 0);
            coffeeScene.add(zone1Title);

            // Cashier Desk Counter
            const cashierBase = new THREE.Mesh(new THREE.BoxGeometry(3.6, 1.25, 2.0), counterMat);
            cashierBase.position.set(-10.5, 0.625, 0);
            const cashierTop = new THREE.Mesh(new THREE.BoxGeometry(3.8, 0.12, 2.2), counterTopMat);
            cashierTop.position.set(-10.5, 1.28, 0);
            coffeeScene.add(cashierBase, cashierTop);

            // POS Touchscreen Monitor
            const posBase = new THREE.Mesh(new THREE.CylinderGeometry(0.08, 0.1, 0.25, 8), chromeMat);
            posBase.position.set(-11.0, 1.45, 0.1);
            const posScreen = new THREE.Mesh(new THREE.BoxGeometry(0.65, 0.45, 0.05), new THREE.MeshStandardMaterial({ color: 0x0284c7, emissive: 0x0369a1, emissiveIntensity: 0.4 }));
            posScreen.rotation.x = -0.35;
            posScreen.position.set(-11.0, 1.7, 0.15);
            coffeeScene.add(posBase, posScreen);

            // Card Swipe EDC Payment Terminal
            const edcMat = new THREE.MeshLambertMaterial({ color: 0x1e293b });
            const edc = new THREE.Mesh(new THREE.BoxGeometry(0.28, 0.08, 0.4), edcMat);
            edc.position.set(-9.8, 1.38, 0.4);
            const edcLed = new THREE.Mesh(new THREE.SphereGeometry(0.03, 8, 8), new THREE.MeshBasicMaterial({ color: 0x22c55e }));
            edcLed.position.set(-9.8, 1.43, 0.52);
            coffeeScene.add(edc, edcLed);

            // Velvet Rope Stanchions for Entrance Queue leading to Cashier
            const ropeMat = new THREE.MeshLambertMaterial({ color: 0x991b1b });
            const stanchionZ = [-1, 1.2, 3.5];
            stanchionZ.forEach(z => {
                const pole = new THREE.Mesh(new THREE.CylinderGeometry(0.08, 0.08, 1.1, 12), brassMat);
                pole.position.set(-13.5, 0.55, z);
                const ball = new THREE.Mesh(new THREE.SphereGeometry(0.12, 12, 12), brassMat);
                ball.position.set(-13.5, 1.15, z);
                coffeeScene.add(pole, ball);
            });
            for (let i = 0; i < stanchionZ.length - 1; i++) {
                const midZ = (stanchionZ[i] + stanchionZ[i+1]) / 2;
                const rope = new THREE.Mesh(new THREE.CylinderGeometry(0.04, 0.04, 2.3, 8), ropeMat);
                rope.rotation.x = Math.PI / 2;
                rope.position.set(-13.5, 0.95, midZ);
                coffeeScene.add(rope);
            }

            // Cafe Menu Chalkboard Easel Stand
            const easelGroup = new THREE.Group();
            const woodLegMat = new THREE.MeshLambertMaterial({ color: 0x543310 });
            const leg1 = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 2.2, 8), woodLegMat);
            leg1.rotation.z = 0.2; leg1.position.set(-0.35, 1.0, 0);
            const leg2 = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 2.2, 8), woodLegMat);
            leg2.rotation.z = -0.2; leg2.position.set(0.35, 1.0, 0);
            const leg3 = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 2.0, 8), woodLegMat);
            leg3.rotation.x = -0.3; leg3.position.set(0, 0.9, -0.4);
            const board = new THREE.Mesh(new THREE.BoxGeometry(1.2, 1.0, 0.06), new THREE.MeshLambertMaterial({ color: 0x1c1917 }));
            board.position.set(0, 1.25, 0.08);
            easelGroup.add(leg1, leg2, leg3, board);
            easelGroup.position.set(-7.5, 0, 4.0);
            easelGroup.rotation.y = -Math.PI / 4;
            coffeeScene.add(easelGroup);

            // =========================================================================
            // 2. MEJA BARISTA & ESPRESSO BAR (Center-Left)
            // =========================================================================
            const zone2Title = createBadgeSprite('2. BARISTA BREW BAR', '#2563eb', '#ffffff', '☕', 'Meja Barista Meracik Kopi');
            zone2Title.position.set(-1.0, 4.2, -1.0);
            coffeeScene.add(zone2Title);

            // Main Barista Bar Counter
            const barBase = new THREE.Mesh(new THREE.BoxGeometry(7.5, 1.25, 2.2), counterMat);
            barBase.position.set(-1.0, 0.625, -1.0);
            const barTop = new THREE.Mesh(new THREE.BoxGeometry(7.8, 0.12, 2.4), counterTopMat);
            barTop.position.set(-1.0, 1.28, -1.0);
            coffeeScene.add(barBase, barTop);

            // Back Bar Cabinet & Shelves
            const backBar = new THREE.Mesh(new THREE.BoxGeometry(7.0, 2.8, 0.6), counterMat);
            backBar.position.set(-1.0, 1.4, -3.2);
            coffeeScene.add(backBar);

            // Chrome Espresso Machine
            const espMachine = new THREE.Mesh(new THREE.BoxGeometry(2.0, 1.15, 1.2), chromeMat);
            espMachine.position.set(-1.5, 1.88, -1.1);
            coffeeScene.add(espMachine);

            // Drip tray & portafilters
            const dripTray = new THREE.Mesh(new THREE.BoxGeometry(1.8, 0.08, 0.5), new THREE.MeshStandardMaterial({ color: 0x64748b, metalness: 0.8, roughness: 0.3 }));
            dripTray.position.set(-1.5, 1.34, -0.4);
            coffeeScene.add(dripTray);

            // Grinder
            const grinderBase = new THREE.Mesh(new THREE.CylinderGeometry(0.25, 0.28, 0.6, 12), chromeMat);
            grinderBase.position.set(0.4, 1.6, -1.1);
            const hopper = new THREE.Mesh(new THREE.ConeGeometry(0.32, 0.5, 12), new THREE.MeshStandardMaterial({ color: 0xa16207, roughness: 0.4, transparent: true, opacity: 0.85 }));
            hopper.rotation.x = Math.PI;
            hopper.position.set(0.4, 2.15, -1.1);
            coffeeScene.add(grinderBase, hopper);

            // Ceramic Cups on bar
            const cupMat = new THREE.MeshLambertMaterial({ color: 0xf8fafc });
            [-2.8, -2.4, -0.3, 1.4].forEach(cx => {
                const cup = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.09, 0.22, 12), cupMat);
                cup.position.set(cx, 1.42, -0.6);
                coffeeScene.add(cup);
            });

            // Rising Steam Particles from Espresso Machine
            const steamMat = new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0.65 });
            for (let i = 0; i < 24; i++) {
                const sp = new THREE.Mesh(new THREE.SphereGeometry(0.06 + Math.random() * 0.04, 8, 8), steamMat.clone());
                const startX = -1.5 + (Math.random() - 0.5) * 1.2;
                const startZ = -0.7 + (Math.random() - 0.5) * 0.4;
                const startY = 1.6 + Math.random() * 0.8;
                sp.position.set(startX, startY, startZ);
                sp.userData = {
                    startX: startX,
                    startZ: startZ,
                    startY: 1.5,
                    maxDist: 1.2 + Math.random() * 0.6,
                    speedY: 0.015 + Math.random() * 0.015,
                    baseOpacity: 0.6 + Math.random() * 0.3,
                    seed: Math.random() * 10
                };
                coffeeScene.add(sp);
                steamParticles.push(sp);
            }

            // =========================================================================
            // 3. AREA TUNGGU KHUSUS RACIKAN (Brew-Waiting Lounge / Testing Ongoing)
            // =========================================================================
            const zone3Title = createBadgeSprite('3. AREA TUNGGU RACIKAN', '#0284c7', '#ffffff', '⏳', 'Customer Menunggu Kopi (Testing)');
            zone3Title.position.set(-1.0, 3.8, 4.2);
            coffeeScene.add(zone3Title);

            // Dedicated Area Rug / Carpet Runner in Deep Cobalt Blue
            const rugMat = new THREE.MeshStandardMaterial({ color: 0x1e3a8a, roughness: 0.9, metalness: 0.1 });
            const waitingRug = new THREE.Mesh(new THREE.PlaneGeometry(8.0, 4.0), rugMat);
            waitingRug.rotation.x = -Math.PI / 2;
            waitingRug.position.set(-1.0, 0.015, 4.4);
            coffeeScene.add(waitingRug);

            // Long Wooden Waiting Bench with Dark Leather Cushion
            const benchMat = new THREE.MeshStandardMaterial({ color: 0x27160c, roughness: 0.5 });
            const cushionMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.7 });
            const waitingBench = new THREE.Mesh(new THREE.BoxGeometry(6.6, 0.45, 0.9), benchMat);
            waitingBench.position.set(-1.0, 0.25, 3.8);
            const cushion = new THREE.Mesh(new THREE.BoxGeometry(6.4, 0.1, 0.85), cushionMat);
            cushion.position.set(-1.0, 0.5, 3.8);
            coffeeScene.add(waitingBench, cushion);

            // 4 Cafe Waiting Bar Stools with Footrest Rail
            [-3.2, -1.8, -0.2, 1.2].forEach(sx => {
                const sTop = new THREE.Mesh(new THREE.CylinderGeometry(0.32, 0.32, 0.06, 16), stoolTopMat);
                sTop.position.set(sx, 0.65, 5.3);
                const sLeg = new THREE.Mesh(new THREE.CylinderGeometry(0.04, 0.04, 0.65, 8), metalMat);
                sLeg.position.set(sx, 0.325, 5.3);
                const sBase = new THREE.Mesh(new THREE.CylinderGeometry(0.24, 0.24, 0.03, 12), metalMat);
                sBase.position.set(sx, 0.02, 5.3);
                coffeeScene.add(sTop, sLeg, sBase);
            });

            // =========================================================================
            // 4. MEJA PICK-UP & SERAH TERIMA (Calling Counter / Submitted & Passed)
            // =========================================================================
            const zone4Title = createBadgeSprite('4. PICK-UP COUNTER', '#7c3aed', '#ffffff', '📢', 'Serah Terima Kopi (Submitted)');
            zone4Title.position.set(8.0, 4.0, -0.5);
            coffeeScene.add(zone4Title);

            // Pickup Desk Counter
            const pickupBase = new THREE.Mesh(new THREE.BoxGeometry(3.6, 1.25, 2.2), counterMat);
            pickupBase.position.set(8.0, 0.625, -0.5);
            const pickupTop = new THREE.Mesh(new THREE.BoxGeometry(3.8, 0.12, 2.4), counterTopMat);
            pickupTop.position.set(8.0, 1.28, -0.5);
            coffeeScene.add(pickupBase, pickupTop);

            // "ORDER PICK-UP" Neon/Display Board Sign
            const signMat = new THREE.MeshStandardMaterial({ color: 0x7c3aed, emissive: 0x6d28d9, emissiveIntensity: 0.6 });
            const pickupSign = new THREE.Mesh(new THREE.BoxGeometry(1.4, 0.35, 0.08), signMat);
            pickupSign.position.set(8.0, 2.0, -0.5);
            const post1 = new THREE.Mesh(new THREE.CylinderGeometry(0.02, 0.02, 0.6, 8), chromeMat);
            post1.position.set(7.5, 1.6, -0.5);
            const post2 = new THREE.Mesh(new THREE.CylinderGeometry(0.02, 0.02, 0.6, 8), chromeMat);
            post2.position.set(8.5, 1.6, -0.5);
            coffeeScene.add(pickupSign, post1, post2);

            // Calling Bell & Takeaway Tray on Pickup Counter
            const bell = new THREE.Mesh(new THREE.SphereGeometry(0.1, 12, 12), brassMat);
            bell.position.set(7.2, 1.42, -0.2);
            coffeeScene.add(bell);

            // =========================================================================
            // 5. MEJA SANTAI / DINE-IN LOUNGE (Approved)
            // =========================================================================
            const zone5Title = createBadgeSprite('5. DINE-IN LOUNGE', '#059669', '#ffffff', '✨', 'Lunas & Dinikmati (Approved)');
            zone5Title.position.set(15.5, 4.0, 0);
            coffeeScene.add(zone5Title);

            // Round Tables & Chairs
            const tableMat = new THREE.MeshStandardMaterial({ color: 0x451a03, roughness: 0.4 });
            const tableSpots = [{ x: 14.5, z: -1.5 }, { x: 16.8, z: 2.0 }];
            tableSpots.forEach(ts => {
                const top = new THREE.Mesh(new THREE.CylinderGeometry(1.1, 1.1, 0.08, 24), tableMat);
                top.position.set(ts.x, 1.1, ts.z);
                const leg = new THREE.Mesh(new THREE.CylinderGeometry(0.07, 0.07, 1.1, 12), metalMat);
                leg.position.set(ts.x, 0.55, ts.z);
                const base = new THREE.Mesh(new THREE.CylinderGeometry(0.4, 0.4, 0.05, 16), metalMat);
                base.position.set(ts.x, 0.03, ts.z);
                coffeeScene.add(top, leg, base);

                // 2 Stools per table
                [-0.9, 0.9].forEach(dx => {
                    const stoolTop = new THREE.Mesh(new THREE.CylinderGeometry(0.35, 0.35, 0.06, 16), stoolTopMat);
                    stoolTop.position.set(ts.x + dx, 0.65, ts.z);
                    const sLeg = new THREE.Mesh(new THREE.CylinderGeometry(0.04, 0.04, 0.65, 8), metalMat);
                    sLeg.position.set(ts.x + dx, 0.325, ts.z);
                    coffeeScene.add(stoolTop, sLeg);
                });
            });

            // Potted Cafe Plants (Monstera)
            const potMat = new THREE.MeshLambertMaterial({ color: 0xb45309 });
            const leafMat = new THREE.MeshLambertMaterial({ color: 0x15803d });
            [{ x: 19.0, z: -4 }, { x: 13.0, z: 5.5 }].forEach(p => {
                const pot = new THREE.Mesh(new THREE.CylinderGeometry(0.45, 0.32, 0.8, 12), potMat);
                pot.position.set(p.x, 0.4, p.z);
                const foliage = new THREE.Mesh(new THREE.DodecahedronGeometry(0.7, 1), leafMat);
                foliage.position.set(p.x, 1.1, p.z);
                coffeeScene.add(pot, foliage);
            });

            // =========================================================================
            // 6. TEMPAT SAMPAH / REJECT & SPILL BIN (Batal)
            // =========================================================================
            const zone6Title = createBadgeSprite('6. REJECT / BATAL', '#dc2626', '#ffffff', '🗑️', 'Pesanan Batal / Kopi Dibuang');
            zone6Title.position.set(8.0, 3.6, 6.5);
            coffeeScene.add(zone6Title);

            const binMat = new THREE.MeshStandardMaterial({ color: 0x334155, metalness: 0.8, roughness: 0.25 });
            const bin = new THREE.Mesh(new THREE.CylinderGeometry(0.45, 0.42, 1.3, 16), binMat);
            bin.position.set(8.0, 0.65, 6.5);
            const flap = new THREE.Mesh(new THREE.BoxGeometry(0.5, 0.3, 0.05), new THREE.MeshStandardMaterial({ color: 0x94a3b8, metalness: 0.9, roughness: 0.2 }));
            flap.position.set(8.0, 1.15, 6.75);
            flap.rotation.x = 0.4;
            coffeeScene.add(bin, flap);

            const ring = new THREE.Mesh(new THREE.RingGeometry(0.6, 0.8, 24), new THREE.MeshBasicMaterial({ color: 0xef4444, side: THREE.DoubleSide }));
            ring.rotation.x = -Math.PI / 2;
            ring.position.set(8.0, 0.02, 6.5);
            coffeeScene.add(ring);

            const crumpleCup = new THREE.Mesh(new THREE.DodecahedronGeometry(0.12, 0), new THREE.MeshLambertMaterial({ color: 0xf8fafc }));
            crumpleCup.position.set(8.0, 1.35, 6.5);
            coffeeScene.add(crumpleCup);

            // Populate Characters
            populateBaristasAndCustomers();
        }

        function populateBaristasAndCustomers() {
            // Clean up existing character meshes
            coffeeCustomers.forEach(c => coffeeScene.remove(c));
            coffeeBaristas.forEach(b => coffeeScene.remove(b));
            coffeeStaff.forEach(s => coffeeScene.remove(s));
            coffeeCustomers = [];
            coffeeBaristas = [];
            coffeeStaff = [];

            // 1. POPULATE BARISTAS (All PICs from Database / Filter)
            const baristasToSpawn = (coffeeBaristasList && coffeeBaristasList.length > 0) 
                ? coffeeBaristasList 
                : [{ username: 'Danar' }, { username: 'Endri' }];

            const totalBaristas = baristasToSpawn.length;
            const startX = -4.0;
            const endX = 2.0;

            baristasToSpawn.forEach((barista, idx) => {
                const posX = (totalBaristas === 1) 
                    ? -1.0 
                    : startX + idx * ((endX - startX) / (totalBaristas - 1));
                const posZ = (totalBaristas <= 4) ? -2.3 : (-2.25 - (idx % 2) * 0.35);
                const picName = barista.username || (barista.pic_email ? barista.pic_email.split('@')[0] : `Barista #${idx+1}`);
                const bMesh = createBaristaMesh(picName, posX, posZ);
                coffeeScene.add(bMesh);
                coffeeBaristas.push(bMesh);
            });

            // 2. POPULATE DUMMY CAFE CREW (Kasir & Pickup Counter Staff)
            const kasirStaff = createKasirStaffMesh(-10.5, -1.0);
            kasirStaff.rotation.y = 0; // Facing customer entrance/queue
            coffeeScene.add(kasirStaff);
            coffeeStaff.push(kasirStaff);

            const pickupStaff = createPickupStaffMesh(8.0, -1.5);
            pickupStaff.rotation.y = 0; // Facing pickup area customers
            coffeeScene.add(pickupStaff);
            coffeeStaff.push(pickupStaff);

            // 3. POPULATE CUSTOMERS (Tasks) with Zone Capping (Max 5-8 Avatars per Zone + Overflow Badge)
            const queueList = [...(tasksDataByStatus['Task Baru'] || []), ...(tasksDataByStatus['Downloaded'] || [])];
            const brewingList = [...(tasksDataByStatus['Test Ongoing'] || []), ...(tasksDataByStatus['Pending Feedback'] || []), ...(tasksDataByStatus['Feedback Sent'] || [])];
            const cashierList = [...(tasksDataByStatus['Submitted'] || []), ...(tasksDataByStatus['Passed'] || [])];
            const approvedList = [...(tasksDataByStatus['Approved'] || [])];
            const cancelledList = [...(tasksDataByStatus['Batal'] || [])];

            const colors = [0x38bdf8, 0x818cf8, 0xf472b6, 0x34d399, 0xfbbf24, 0xfb923c, 0xa78bfa, 0x2dd4bf];

            function spawnZoneCustomers(list, waypoints, zoneKey, statusForDrawer, badgePos, colorOffset = 0) {
                const maxVisible = waypoints.length;
                const visible = list.slice(0, maxVisible);
                visible.forEach((task, idx) => {
                    const wp = waypoints[idx];
                    const cMesh = createCustomerMesh(task, colors[(idx + colorOffset) % colors.length], idx + colorOffset);
                    cMesh.position.set(wp.x, 0, wp.z);
                    cMesh.rotation.y = wp.rot;
                    cMesh.userData.zone = zoneKey;
                    coffeeScene.add(cMesh);
                    coffeeCustomers.push(cMesh);
                });

                if (list.length > maxVisible) {
                    const extraCount = list.length - maxVisible;
                    const statusColor = getStatusBadgeColor(statusForDrawer);
                    const overflowSprite = createBadgeSprite(`+${extraCount} ${statusForDrawer}`, statusColor, '#ffffff', '📋', 'Klik untuk Buka Semua');
                    overflowSprite.position.set(badgePos.x, badgePos.y, badgePos.z);
                    overflowSprite.scale.set(3.0, 1.0, 1);
                    overflowSprite.userData = { isOverflowBadge: true, status: statusForDrawer };
                    coffeeScene.add(overflowSprite);
                    coffeeCustomers.push(overflowSprite);
                }
            }

            // A. Zone 1: Kasir & Order Queue (Task Baru / Downloaded)
            const queueWaypoints = [
                { x: -12.5, z: 3.5, rot: 0 },
                { x: -12.5, z: 2.0, rot: 0 },
                { x: -12.5, z: 0.6, rot: 0 },
                { x: -10.5, z: 1.4, rot: Math.PI },
                { x: -9.0, z: 1.4, rot: Math.PI }
            ];
            spawnZoneCustomers(queueList, queueWaypoints, 'queue', 'Task Baru', { x: -12.5, y: 1.6, z: 5.0 }, 0);

            // B. Zone 3: DEDICATED AREA TUNGGU KHUSUS RACIKAN (Test Ongoing / Feedback)
            const waitingWaypoints = [
                { x: -3.2, z: 3.8, rot: Math.PI },
                { x: -1.8, z: 3.8, rot: Math.PI },
                { x: -0.4, z: 3.8, rot: Math.PI },
                { x: 1.0, z: 3.8, rot: Math.PI },
                { x: -2.8, z: 5.3, rot: Math.PI * 0.95 },
                { x: -1.4, z: 5.3, rot: Math.PI },
                { x: 0.2, z: 5.3, rot: -Math.PI * 0.95 },
                { x: 1.6, z: 5.3, rot: -Math.PI * 0.9 }
            ];
            spawnZoneCustomers(brewingList, waitingWaypoints, 'brewing', 'Test Ongoing', { x: 3.2, y: 1.6, z: 4.6 }, 10);

            // C. Zone 4: Meja Pick-up Counter & Panggilan (Submitted / Passed)
            const pickupWaypoints = [
                { x: 7.4, z: 1.2, rot: Math.PI },
                { x: 8.6, z: 1.2, rot: Math.PI },
                { x: 8.0, z: 2.4, rot: Math.PI }
            ];
            spawnZoneCustomers(cashierList, pickupWaypoints, 'pickup', 'Submitted', { x: 8.0, y: 1.6, z: 3.6 }, 20);

            // D. Zone 5: Dine-in Lounge Santai (Approved)
            const loungeWaypoints = [
                { x: 13.6, z: -1.5, rot: Math.PI / 2 },
                { x: 15.4, z: -1.5, rot: -Math.PI / 2 },
                { x: 15.9, z: 2.0, rot: Math.PI / 2 },
                { x: 17.7, z: 2.0, rot: -Math.PI / 2 },
                { x: 15.0, z: 0.5, rot: 0 }
            ];
            spawnZoneCustomers(approvedList, loungeWaypoints, 'lounge', 'Approved', { x: 18.5, y: 1.6, z: 0.5 }, 30);

            // E. Zone 6: Reject & Batal (Batal)
            const cancelWaypoints = [
                { x: 9.0, z: 6.8, rot: Math.PI / 3 },
                { x: 7.0, z: 7.5, rot: -Math.PI / 4 }
            ];
            spawnZoneCustomers(cancelledList, cancelWaypoints, 'trash', 'Batal', { x: 8.0, y: 1.6, z: 8.2 }, 40);
        }

        function animateCoffee() {
            if (!isCoffeeRunning) return;
            coffeeAnimId = requestAnimationFrame(animateCoffee);

            const time = performance.now() * 0.001;

            // 1. Controls update
            if (coffeeControls) {
                coffeeControls.update();
            }

            // 2. Barista & Staff action animation loop
            coffeeBaristas.forEach(b => {
                const phase = time * 1.6 + b.userData.rotPhase;
                b.rotation.y = Math.sin(phase) * 0.35; // gentle body oscillation
                b.position.y = Math.abs(Math.sin(phase * 2)) * 0.04;
            });

            coffeeStaff.forEach((s, idx) => {
                s.position.y = Math.sin(time * 2.0 + idx) * 0.03;
            });

            // 3. Customer idle breathing
            coffeeCustomers.forEach((c, idx) => {
                const u = c.userData;
                // Gentle idle breathing bobbing
                c.position.y = u.baseY + Math.sin(time * u.bobSpeed + idx) * 0.04;
            });

            // 4. Steam particles animation
            steamParticles.forEach(p => {
                p.position.y += p.userData.speedY;
                p.position.x += Math.sin(time * 4 + p.userData.seed) * 0.003;
                p.position.z += Math.cos(time * 3 + p.userData.seed) * 0.003;
                const progress = (p.position.y - p.userData.startY) / p.userData.maxDist;
                p.material.opacity = p.userData.baseOpacity * (1 - Math.min(progress, 1));
                if (progress >= 1) {
                    p.position.y = p.userData.startY;
                    p.position.x = p.userData.startX + (Math.random() - 0.5) * 0.15;
                    p.position.z = p.userData.startZ + (Math.random() - 0.5) * 0.15;
                }
            });

            // 5. Smooth camera preset transition
            if (coffeeCamTargetPos && coffeeCamera && coffeeControls) {
                coffeeCamera.position.lerp(coffeeCamTargetPos, 0.05);
                coffeeControls.target.lerp(coffeeCamLookTarget, 0.05);
                if (coffeeCamera.position.distanceTo(coffeeCamTargetPos) < 0.08) {
                    coffeeCamTargetPos = null;
                }
            }

            // 6. Render scene
            if (coffeeRenderer && coffeeScene && coffeeCamera) {
                coffeeRenderer.render(coffeeScene, coffeeCamera);
            }
        }

        function setCoffeeCameraPreset(preset) {
            if (!coffeeCamera || !coffeeControls) return;
            switch(preset) {
                case 'iso':
                    coffeeCamTargetPos = new THREE.Vector3(0, 18, 25);
                    coffeeCamLookTarget = new THREE.Vector3(0, 1.5, 0);
                    break;
                case 'kasir':
                    coffeeCamTargetPos = new THREE.Vector3(-10.5, 5.0, 6.0);
                    coffeeCamLookTarget = new THREE.Vector3(-10.5, 1.5, 0);
                    break;
                case 'barista':
                    coffeeCamTargetPos = new THREE.Vector3(-1.0, 5.5, 5.0);
                    coffeeCamLookTarget = new THREE.Vector3(-1.0, 1.5, -1.0);
                    break;
                case 'tunggu':
                    coffeeCamTargetPos = new THREE.Vector3(-1.0, 5.5, 9.5);
                    coffeeCamLookTarget = new THREE.Vector3(-1.0, 1.2, 4.4);
                    break;
                case 'pickup':
                    coffeeCamTargetPos = new THREE.Vector3(8.0, 5.0, 5.5);
                    coffeeCamLookTarget = new THREE.Vector3(8.0, 1.5, -0.5);
                    break;
                case 'lounge':
                    coffeeCamTargetPos = new THREE.Vector3(15.5, 5.5, 7.0);
                    coffeeCamLookTarget = new THREE.Vector3(15.5, 1.5, 0);
                    break;
                case 'top':
                    coffeeCamTargetPos = new THREE.Vector3(0, 30, 0.1);
                    coffeeCamLookTarget = new THREE.Vector3(0, 0, 0);
                    break;
            }
        }

        function toggleCoffeeAutoOrbit() {
            if (!coffeeControls) return;
            isAutoOrbiting = !isAutoOrbiting;
            coffeeControls.autoRotate = isAutoOrbiting;
            coffeeControls.autoRotateSpeed = 1.2;
            const btn = document.getElementById('btn-coffee-orbit');
            if (btn) {
                if (isAutoOrbiting) {
                    btn.classList.add('bg-amber-500', 'text-white');
                    btn.classList.remove('text-amber-400');
                } else {
                    btn.classList.remove('bg-amber-500', 'text-white');
                    btn.classList.add('text-amber-400');
                }
            }
        }

        function onCoffeeResize() {
            const container = document.getElementById('coffee-3d-canvas-container');
            if (!container || !coffeeCamera || !coffeeRenderer) return;
            const width = container.clientWidth || 900;
            const height = container.clientHeight || 600;
            coffeeCamera.aspect = width / height;
            coffeeCamera.updateProjectionMatrix();
            coffeeRenderer.setSize(width, height);
        }

        function onCoffeeMouseMove(e) {
            const container = document.getElementById('coffee-3d-canvas-container');
            if (!container || !coffeeRaycaster || !coffeeCamera) return;

            const rect = container.getBoundingClientRect();
            coffeeMouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
            coffeeMouse.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;

            coffeeRaycaster.setFromCamera(coffeeMouse, coffeeCamera);
            const intersects = coffeeRaycaster.intersectObjects(coffeeScene.children, true);

            let found = null;
            for (let hit of intersects) {
                let curr = hit.object;
                while (curr && curr !== coffeeScene) {
                    if (curr.userData && (curr.userData.isCustomer || curr.userData.isBarista || curr.userData.isStaff)) {
                        found = curr;
                        break;
                    }
                    curr = curr.parent;
                }
                if (found) break;
            }

            if (found) {
                container.style.cursor = 'pointer';
            } else {
                container.style.cursor = 'grab';
            }
        }

        function onCoffeeMouseClick(e) {
            const container = document.getElementById('coffee-3d-canvas-container');
            if (!container || !coffeeRaycaster || !coffeeCamera) return;

            const rect = container.getBoundingClientRect();
            coffeeMouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
            coffeeMouse.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;

            coffeeRaycaster.setFromCamera(coffeeMouse, coffeeCamera);
            const intersects = coffeeRaycaster.intersectObjects(coffeeScene.children, true);

            let found = null;
            for (let hit of intersects) {
                let curr = hit.object;
                while (curr && curr !== coffeeScene) {
                    if (curr.userData && (curr.userData.isCustomer || curr.userData.isBarista || curr.userData.isStaff || curr.userData.isOverflowBadge)) {
                        found = curr;
                        break;
                    }
                    curr = curr.parent;
                }
                if (found) break;
            }

            if (found && found.userData.isCustomer) {
                showCoffeeTaskCard(found.userData.task);
            } else if (found && found.userData.isBarista) {
                showCoffeeBaristaCard(found.userData.picName);
            } else if (found && found.userData.isStaff) {
                showCoffeeStaffCard(found.userData);
            } else if (found && found.userData.isOverflowBadge) {
                openTasksDrawer(found.userData.status);
            } else {
                closeCoffeeCard();
            }
        }

        function showCoffeeStaffCard(staff) {
            const card = document.getElementById('coffee-task-card');
            if (!card) return;

            card.innerHTML = `
                <div class="flex items-start justify-between gap-3 mb-2.5 pb-2.5 border-b border-amber-500/25">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full ${staff.iconBg || 'bg-amber-600'} flex items-center justify-center text-white font-bold text-sm shadow">
                            ${staff.icon || '🧑‍🍳'}
                        </div>
                        <div>
                            <div class="text-sm font-extrabold text-amber-400">${staff.staffName}</div>
                            <div class="text-[11px] text-slate-400">${staff.roleTitle || 'Cafe Crew'}</div>
                        </div>
                    </div>
                    <button type="button" onclick="closeCoffeeCard()" class="text-slate-400 hover:text-white p-1 rounded hover:bg-slate-800 transition-all text-xs">✕</button>
                </div>
                <div class="text-xs text-slate-300 space-y-1.5">
                    <p>${staff.description}</p>
                    <div class="p-2 rounded-lg bg-slate-800/60 border border-slate-700/60 text-[11px] text-slate-300">
                        <span class="text-amber-400 font-bold">Stasiun:</span> ${staff.stationName}
                    </div>
                </div>
                <div class="mt-3 pt-2 border-t border-slate-800 flex justify-end">
                    <button type="button" onclick="closeCoffeeCard()" class="px-3 py-1 text-xs font-bold bg-slate-800 text-slate-200 rounded-lg hover:bg-slate-700">Tutup</button>
                </div>
            `;
            card.classList.remove('hidden');
        }

        function showCoffeeTaskCard(task) {
            const card = document.getElementById('coffee-task-card');
            if (!card) return;

            const meta = getTaskMetaphorInfo(task.progress_status);
            const baristaName = task.username || (task.pic_email ? task.pic_email.split('@')[0] : 'Barista Cafe');

            card.innerHTML = `
                <div class="flex items-start justify-between gap-3 mb-2.5 pb-2.5 border-b border-amber-500/25">
                    <div>
                        <div class="flex items-center gap-1.5">
                            <span class="text-sm">☕</span>
                            <span class="text-sm font-extrabold text-amber-400">${task.model_name || 'N/A'}</span>
                        </div>
                        <div class="text-[11px] text-slate-400 mt-0.5">${task.project_name || 'GBA Project'}</div>
                    </div>
                    <button type="button" onclick="closeCoffeeCard()" class="text-slate-400 hover:text-white p-1 rounded hover:bg-slate-800 transition-all text-xs">✕</button>
                </div>

                <div class="space-y-1.5 text-xs">
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400">Tahap Cafe:</span>
                        <span class="font-bold ${meta.statusClass}">${meta.zoneName}</span>
                    </div>
                    <div class="p-2 rounded-lg bg-slate-800/60 border border-slate-700/60 text-[11px] text-slate-200">
                        <span class="text-amber-400 font-bold">Arti Metaphor:</span> ${meta.cafeAction}
                    </div>
                    <div class="flex justify-between items-center pt-1">
                        <span class="text-slate-400">Status Database:</span>
                        <span class="px-2 py-0.5 rounded text-[10px] font-extrabold ${meta.bgPill} border">${task.progress_status}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400">Barista (PIC):</span>
                        <span class="font-bold text-amber-300">${baristaName}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400">Test Plan:</span>
                        <span class="font-semibold text-indigo-300">${task.test_plan_type || 'N/A'}</span>
                    </div>
                    ${task.deadline ? `
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400">Deadline:</span>
                        <span class="font-semibold text-slate-300">${task.deadline}</span>
                    </div>` : ''}
                </div>

                <div class="mt-3 pt-2.5 border-t border-slate-800 flex items-center justify-between gap-2">
                    <button type="button" onclick="openTasksDrawer('${task.progress_status}')" class="text-[11px] text-amber-400 hover:text-amber-300 font-semibold underline">
                        Buka Status Ini →
                    </button>
                    <button type="button" onclick="openEditModalFromCoffee('${task.id}')" class="px-3 py-1 text-xs font-bold bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg transition-all shadow-md">
                        Edit Task
                    </button>
                </div>
            `;
            card.classList.remove('hidden');
        }

        function showCoffeeBaristaCard(picName) {
            const card = document.getElementById('coffee-task-card');
            if (!card) return;

            card.innerHTML = `
                <div class="flex items-start justify-between gap-3 mb-2.5 pb-2.5 border-b border-amber-500/25">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-full bg-amber-600 flex items-center justify-center text-white font-bold text-sm shadow">
                            ☕
                        </div>
                        <div>
                            <div class="text-sm font-extrabold text-amber-400">Barista: ${picName}</div>
                            <div class="text-[11px] text-slate-400">PIC Roaster & Tester</div>
                        </div>
                    </div>
                    <button type="button" onclick="closeCoffeeCard()" class="text-slate-400 hover:text-white p-1 rounded hover:bg-slate-800 transition-all text-xs">✕</button>
                </div>
                <div class="text-xs text-slate-300 space-y-1.5">
                    <p>Barista bertugas meracik pesanan task yang sedang dalam tahap <span class="text-blue-400 font-bold">Testing & Feedback</span>.</p>
                </div>
                <div class="mt-3 pt-2 border-t border-slate-800 flex justify-end">
                    <button type="button" onclick="closeCoffeeCard()" class="px-3 py-1 text-xs font-bold bg-slate-800 text-slate-200 rounded-lg hover:bg-slate-700">Tutup</button>
                </div>
            `;
            card.classList.remove('hidden');
        }

        function closeCoffeeCard() {
            const card = document.getElementById('coffee-task-card');
            if (card) card.classList.add('hidden');
        }

        function openEditModalFromCoffee(taskId) {
            closeCoffeeCard();
            // Look up task in tasksDataByStatus
            let foundTask = null;
            for (const status in tasksDataByStatus) {
                const list = tasksDataByStatus[status] || [];
                const t = list.find(item => String(item.id) === String(taskId));
                if (t) {
                    foundTask = t;
                    break;
                }
            }
            if (foundTask) {
                openEditModal(foundTask);
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
            let savedMode = localStorage.getItem('roadmap_view_mode') || 'summary';
            if (savedMode === 'kanban') savedMode = 'summary';
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