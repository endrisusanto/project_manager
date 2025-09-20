<?php
require_once "config.php";

// Daftar status progress untuk kolom Kanban
$statuses = ['Task Baru', 'Test Ongoing', 'Pending Feedback', 'Feedback Sent', 'Submitted', 'Passed', 'Approved', 'Batal'];
$tasksToDisplay = [];
foreach ($statuses as $status) {
    $tasksToDisplay[$status] = [];
}

// Mengambil semua task dari database
$sql = "SELECT * FROM gba_tasks ORDER BY id DESC";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $row['request_date_obj'] = $row['request_date'] ? new DateTime($row['request_date']) : null;
        $row['submission_date_obj'] = $row['submission_date'] ? new DateTime($row['submission_date']) : null;
        $row['approved_date_obj'] = isset($row['approved_date']) && $row['approved_date'] ? new DateTime($row['approved_date']) : null;
        $deadline_date = $row['deadline'] ? new DateTime($row['deadline']) : null;
        
        $row['ontime_submission_status'] = null;
        if ($row['submission_date_obj'] && $row['request_date_obj']) {
            $submission_diff = $row['submission_date_obj']->diff($row['request_date_obj'])->days;
            $row['ontime_submission_status'] = $submission_diff <= 7 ? 'Ontime' : 'Delay';
        }
        
        $row['ontime_approved_status'] = null;
        if ($row['approved_date_obj'] && $row['submission_date_obj']) {
            $approval_diff = $row['approved_date_obj']->diff($row['submission_date_obj'])->days;
            $row['ontime_approved_status'] = $approval_diff <= 3 ? 'Ontime' : 'Delay';
        }

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

        if (isset($tasksToDisplay[$row['progress_status']])) {
            $tasksToDisplay[$row['progress_status']][] = $row;
        }
    }
}

function getPicBadgeColor($identifier) {
    $colors = ['sky', 'emerald', 'amber', 'rose', 'violet', 'teal', 'cyan'];
    $hash = crc32($identifier);
    $color = $colors[$hash % count($colors)];
    return "badge-color-$color";
}

function getPicInitials($email) {
    if (empty($email)) return '??';
    $parts = explode('@', $email);
    $name = $parts[0];
    $initials = strtoupper(substr($name, 0, 2));
    return $initials;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GBA Task Kanban Board</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>
    <style>
        :root {
            --bg-primary: #020617; --text-primary: #e2e8f0; --text-secondary: #94a3b8;
            --glass-bg: rgba(15, 23, 42, 0.8); --glass-border: rgba(51, 65, 85, 0.6);
            --column-bg: rgba(255, 255, 255, 0.03); --text-header: #ffffff;
            --text-card-title: #ffffff; --text-card-body: #cbd5e1; --text-icon: #94a3b8;
            --input-bg: rgba(30, 41, 59, 0.7); --input-border: #475569; --input-text: #e2e8f0;
            --toast-bg: #22c55e; --toast-text: #ffffff;
        }
        html.light {
            --bg-primary: #f1f5f9; --text-primary: #0f172a; --text-secondary: #475569;
            --glass-bg: rgba(255, 255, 255, 0.7); --glass-border: rgba(0, 0, 0, 0.1);
            --column-bg: rgba(0, 0, 0, 0.03); --text-header: #0f172a; --text-card-title: #1e293b;
            --text-card-body: #334155; --text-icon: #475569;
            --input-bg: #ffffff; --input-border: #cbd5e1; --input-text: #0f172a;
            --toast-bg: #16a34a; --toast-text: #ffffff;
        }
        html, body { overflow-x: hidden; height: 100%; }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); }
        main { height: calc(100% - 64px); overflow-y: auto; }
        #neural-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; }
        .glass-container { background: var(--glass-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid var(--glass-border); }
        .kanban-column { background: var(--column-bg); border-radius: 1rem; }
        .task-card { position: relative; cursor: grab; transition: all 0.2s ease-in-out; border-radius: 0.75rem; }
        .task-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.2); }
        .sortable-ghost { opacity: 0.4; background: rgba(59, 130, 246, 0.2); border: 2px dashed #3b82f6; }
        .themed-input { background-color: var(--input-bg); border: 1px solid var(--input-border); color: var(--input-text); }
        .badge { display: inline-block; padding: 0.25rem 0.6rem; font-size: 0.75rem; font-weight: 500; border-radius: 0.75rem; line-height: 1.2; }
        .badge-color-sky { background-color: rgba(14, 165, 233, 0.2); color: #7dd3fc; }
        .badge-color-emerald { background-color: rgba(16, 185, 129, 0.2); color: #6ee7b7; }
        .badge-color-amber { background-color: rgba(245, 158, 11, 0.2); color: #fcd34d; }
        .badge-color-rose { background-color: rgba(244, 63, 94, 0.2); color: #fda4af; }
        .badge-color-violet { background-color: rgba(139, 92, 246, 0.2); color: #c4b5fd; }
        .badge-color-teal { background-color: rgba(20, 184, 166, 0.2); color: #5eead4; }
        .badge-color-cyan { background-color: rgba(6, 182, 212, 0.2); color: #67e8f9; }
        html.light .badge-color-sky { background-color: #e0f2fe; color: #0369a1; }
        html.light .badge-color-emerald { background-color: #d1fae5; color: #047857; }
        html.light .badge-color-amber { background-color: #fef3c7; color: #92400e; }
        html.light .badge-color-rose { background-color: #ffe4e6; color: #9f1239; }
        html.light .badge-color-violet { background-color: #ede9fe; color: #5b21b6; }
        html.light .badge-color-teal { background-color: #ccfbf1; color: #0d9488; }
        html.light .badge-color-cyan { background-color: #cffafe; color: #0e7490; }
        .nav-link { color: var(--text-secondary); border-bottom: 2px solid transparent; transition: all 0.2s; }
        .nav-link:hover { border-color: var(--text-secondary); color: var(--text-primary); }
        .nav-link-active { color: var(--text-primary); border-bottom: 2px solid #3b82f6; font-weight: 600; }
        .ql-toolbar, .ql-container { border-color: var(--glass-border) !important; }
        .ql-editor { color: var(--text-primary); min-height: 100px; }
        #toast { position: fixed; bottom: -100px; left: 50%; transform: translateX(-50%); background-color: var(--toast-bg); color: var(--toast-text); padding: 12px 20px; border-radius: 8px; z-index: 1000; transition: bottom 0.5s ease-in-out; }
        #toast.show { bottom: 30px; }
        @keyframes pulse-alert { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.1); opacity: 0.8; } }
        .animate-pulse-alert { animation: pulse-alert 1.5s infinite; }
        @keyframes underline-glow { 0%, 100% { box-shadow: 0 2px 4px -2px rgba(239, 68, 68, 0.3), 0 4px 12px -2px rgba(239, 68, 68, 0.2); } 50% { box-shadow: 0 2px 8px -2px rgba(239, 68, 68, 0.6), 0 4px 18px -2px rgba(239, 68, 68, 0.5); } }
        .underline-glow-effect { border-bottom: 2px solid rgba(239, 68, 68, 0.8); animation: underline-glow 2s infinite ease-in-out; }
        .sad-emoji { position: fixed; font-size: 2rem; animation: fall 5s linear forwards; opacity: 1; z-index: 9999; }
        @keyframes fall { to { transform: translateY(100vh) rotate(360deg); opacity: 0; } }
        
        .rainbow-border-effect {
            border-radius: 0.75rem;
            padding: 2px;
            background: linear-gradient(45deg, #ec4899, #f43f5e, #f97316, #eab308, #22c55e, #0ea5e9, #8b5cf6);
        }
        .rainbow-border-effect > .glass-container-content {
            background: var(--glass-bg);
            border-radius: 0.65rem;
            padding: 1rem;
            width: 100%;
            height: 100%;
        }

        .accordion-summary { display: none; }
        .view-accordion .accordion-summary { display: block; }
        .view-accordion .task-card-full-content { display: none; }
        
        /* [MODIFIKASI] Garis atas pada konten akordeon yang terbuka dihilangkan */
        .view-accordion .task-card.is-expanded .task-card-full-content {
            display: block;
            padding-top: 1rem;
            margin-top: 1rem;
            /* border-top: 1px solid var(--glass-border); <- Properti ini dihapus */
        }

        .view-accordion .task-card.is-expanded .accordion-summary { display: none; }
        .pic-icon { width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: bold; flex-shrink: 0; }
    </style>
</head>
<body class="h-screen flex flex-col">
    <canvas id="neural-canvas"></canvas>
    <div id="toast"></div>

    <header class="glass-container sticky top-0 z-20 shadow-sm flex-shrink-0">
        <div class="w-full mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center space-x-3">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-blue-600"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" /></svg>
                    <h1 class="text-xl font-bold text-header">Software Project Manager</h1>
                    <div class="hidden md:flex items-baseline space-x-4 ml-4">
                        <a href="index.php" class="nav-link-active px-3 py-2 rounded-md text-sm font-medium">Project Dashboard</a>
                        <a href="gba_dashboard.php" class="nav-link px-3 py-2 rounded-md text-sm font-medium">GBA Dashboard</a>
                        <a href="gba_tasks.php" class="nav-link px-3 py-2 rounded-md text-sm font-medium">GBA Tasks</a>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="relative flex-grow">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3"><svg class="h-5 w-5 text-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" /></svg></div>
                        <input type="search" id="search-input" placeholder="Cari model, PIC..." class="themed-input block w-full rounded-lg py-2 pl-10 pr-3 focus:ring-2">
                    </div>
                    <button id="view-toggle" type="button" class="text-icon hover:bg-gray-500/10 rounded-lg text-sm p-2.5">
                        <svg id="view-toggle-full-icon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                        <svg id="view-toggle-accordion-icon" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path></svg>
                    </button>
                    <button id="theme-toggle" type="button" class="text-icon hover:bg-gray-500/10 rounded-lg text-sm p-2.5"><svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path></svg><svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path></svg></button>
                    <button onclick="openAddModal()" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500"><svg class="-ml-0.5 mr-1.5 h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>Task Baru</button>
                </div>
            </div>
        </div>
    </header>

    <main class="w-full p-4 sm:p-6 lg:p-8 flex-grow">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-8 gap-6 h-full">
            <?php foreach ($statuses as $status): ?>
            <div class="flex flex-col">
                <h2 class="text-base font-semibold mb-4 flex items-center justify-between text-header">
                    <?php echo htmlspecialchars($status); ?>
                    <span class="text-sm font-bold bg-gray-500/10 text-header rounded-full px-2 py-0.5 count-<?php echo str_replace(' ', '', $status); ?>">
                        <?php echo count($tasksToDisplay[$status]); ?>
                    </span>
                </h2>
                <div id="status-<?php echo str_replace(' ', '', $status); ?>" data-status="<?php echo $status; ?>" class="kanban-column space-y-4 p-2 rounded-lg h-full overflow-y-auto">
                    <?php foreach ($tasksToDisplay[$status] as $task): ?>
                    <?php
                        $cardClasses = 'task-card flex flex-col';
                        if ($task['progress_status'] === 'Task Baru') $cardClasses .= ' underline-glow-effect';
                        if ($task['is_urgent'] == 1) {
                            $cardClasses .= ' rainbow-border-effect';
                        } else {
                            $cardClasses .= ' glass-container';
                        }
                    ?>
                    <div id="task-<?php echo $task['id']; ?>" data-id="<?php echo $task['id']; ?>" data-task='<?php echo json_encode($task, JSON_HEX_APOS | JSON_HEX_QUOT); ?>' class="<?php echo $cardClasses; ?>">
                        <div class="glass-container-content">
                            <div class="accordion-summary">
                               <div class="flex justify-between items-center">
                                    <h4 class="font-bold text-sm text-card-title truncate flex-grow"><?php echo htmlspecialchars($task['ap'] ?: 'N/A'); ?></h4>
                                    <div class="pic-icon <?php echo getPicBadgeColor($task['pic_email']); ?>"><?php echo getPicInitials($task['pic_email']); ?></div>
                               </div>
                               <div class="mt-2 pt-2 border-t border-[var(--glass-border)] text-xs space-y-1">
                                    <div class="flex items-center gap-2">
                                        <span class="w-16 inline-block text-secondary">Submission:</span>
                                        <?php if ($task['ontime_submission_status']): ?><span class="font-semibold <?= $task['ontime_submission_status'] == 'Delay' ? 'text-red-400' : 'text-green-400' ?>"><?= $task['ontime_submission_status'] ?></span>
                                        <?php elseif (isset($task['deadline_countdown'])): ?><span class="flex items-center gap-1 font-semibold <?= $task['deadline_countdown'] < 0 ? 'text-red-400' : ($task['deadline_countdown'] <= 3 ? 'text-yellow-400' : 'text-secondary') ?>"><?php if ($task['deadline_countdown'] <= 3 && $task['deadline_countdown'] >= 0): ?><svg class="w-4 h-4 animate-pulse-alert" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg><?php endif; ?><?php echo $task['deadline_countdown'] >= 0 ? $task['deadline_countdown'] . ' hari lagi' : 'Lewat ' . abs($task['deadline_countdown']) . ' hari'; ?></span>
                                        <?php else: echo '<span class="text-secondary">-</span>'; endif; ?>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="w-16 inline-block text-secondary">Approval:</span>
                                        <?php if ($task['ontime_approved_status']): ?><span class="font-semibold <?= $task['ontime_approved_status'] == 'Delay' ? 'text-red-400' : 'text-green-400' ?>"><?= $task['ontime_approved_status'] ?></span>
                                        <?php elseif (isset($task['approval_countdown'])): ?><span class="flex items-center gap-1 font-semibold <?= $task['approval_countdown'] < 0 ? 'text-red-400' : ($task['approval_countdown'] <= 1 ? 'text-yellow-400' : 'text-secondary') ?>"><?php if ($task['approval_countdown'] <= 1 && $task['approval_countdown'] >= 0): ?><svg class="w-4 h-4 animate-pulse-alert" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg><?php endif; ?><?php echo $task['approval_countdown'] >= 0 ? $task['approval_countdown'] . ' hari lagi' : 'Lewat ' . abs($task['approval_countdown']) . ' hari'; ?></span>
                                        <?php else: echo '<span class="text-secondary">-</span>'; endif; ?>
                                    </div>
                               </div>
                            </div>
                            
                            <div class="task-card-full-content">
                                <div class="flex justify-between items-start mb-2">
                                    <h3 class="font-bold text-card-title flex-1 pr-2 marketing-name"><?php echo htmlspecialchars($task['project_name']); ?></h3>
                                    <div class="flex items-center space-x-2 flex-shrink-0">
                                        <button onclick='openEditModal(this)' class="text-icon hover:text-blue-500"><svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z" /><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd" /></svg></button>
                                        <form action="handler.php" method="POST" onsubmit="return confirm('Yakin ingin menghapus task ini?');" class="inline"><input type="hidden" name="id" value="<?php echo $task['id']; ?>"><button type="submit" name="action" value="delete_gba_task" class="text-icon hover:text-red-500"><svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm4 0a1 1 0 012 0v6a1 1 0 11-2 0V8z" clip-rule="evenodd" /></svg></button></form>
                                    </div>
                                </div>
                                <p class="text-sm font-medium text-card-body model-name"><?php echo htmlspecialchars($task['model_name']); ?></p>
                                <div class="text-xs text-secondary font-mono space-y-0.5 mt-2 pt-2 border-t border-[var(--glass-border)]">
                                    <div>AP: <?php echo htmlspecialchars($task['ap'] ?: '-'); ?></div>
                                    <div>CP: <?php echo htmlspecialchars($task['cp'] ?: '-'); ?></div>
                                    <div>CSC: <?php echo htmlspecialchars($task['csc'] ?: '-'); ?></div>
                                </div>
                                
                                <div class="mt-2 pt-2 border-t border-[var(--glass-border)] text-xs space-y-1">
                                    <div class="flex items-center justify-between">
                                        <span class="text-secondary">Submission:</span>
                                        <?php if ($task['ontime_submission_status']): ?>
                                            <span class="font-semibold <?= $task['ontime_submission_status'] == 'Delay' ? 'text-red-400' : 'text-green-400' ?>"><?= $task['ontime_submission_status'] ?></span>
                                        <?php elseif (isset($task['deadline_countdown'])): ?>
                                            <span class="flex items-center gap-1 font-semibold <?= $task['deadline_countdown'] < 0 ? 'text-red-400' : ($task['deadline_countdown'] <= 3 ? 'text-yellow-400' : 'text-secondary') ?>">
                                                <?php if ($task['deadline_countdown'] <= 3 && $task['deadline_countdown'] >= 0): ?>
                                                    <svg class="w-4 h-4 animate-pulse-alert" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg>
                                                <?php endif; ?>
                                                <?php echo $task['deadline_countdown'] >= 0 ? $task['deadline_countdown'] . ' hari lagi' : 'Lewat ' . abs($task['deadline_countdown']) . ' hari'; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-secondary">-</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-secondary">Approval:</span>
                                        <?php if ($task['ontime_approved_status']): ?>
                                            <span class="font-semibold <?= $task['ontime_approved_status'] == 'Delay' ? 'text-red-400' : 'text-green-400' ?>"><?= $task['ontime_approved_status'] ?></span>
                                        <?php elseif (isset($task['approval_countdown'])): ?>
                                            <span class="flex items-center gap-1 font-semibold <?= $task['approval_countdown'] < 0 ? 'text-red-400' : ($task['approval_countdown'] <= 1 ? 'text-yellow-400' : 'text-secondary') ?>">
                                                <?php if ($task['approval_countdown'] <= 1 && $task['approval_countdown'] >= 0): ?>
                                                    <svg class="w-4 h-4 animate-pulse-alert" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg>
                                                <?php endif; ?>
                                                <?php echo $task['approval_countdown'] >= 0 ? $task['approval_countdown'] . ' hari lagi' : 'Lewat ' . abs($task['approval_countdown']) . ' hari'; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-secondary">-</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="mt-3 text-right">
                                    <span class="badge task-pic <?php echo getPicBadgeColor($task['pic_email']); ?>"><?php echo htmlspecialchars($task['pic_email']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </main>
    
    <div id="task-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-70 hidden">
        <div class="glass-container rounded-lg shadow-xl p-6 w-full max-w-6xl mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
            <div class="flex justify-between items-center mb-4">
                <h2 id="modal-title" class="text-2xl font-bold text-header">Tambah Task Baru</h2>
                <button onclick="closeModal()" class="text-secondary hover:text-primary text-3xl font-bold">&times;</button>
            </div>
            <form id="task-form" action="handler.php" method="POST">
                <input type="hidden" name="id" id="task-id">
                <input type="hidden" name="action" id="form-action" value="create_gba_task">
                <?php include 'gba_task_form.php'; ?>
                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" onclick="closeModal()" class="px-4 py-2 rounded-lg themed-input">Batal</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Simpan Task</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // --- ANIMASI & TEMA ---
        const canvas = document.getElementById('neural-canvas'); const ctx = canvas.getContext('2d');
        let particles = []; let hue = 0; function setCanvasSize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
        setCanvasSize(); class Particle { constructor(x, y) { this.x = x || Math.random() * canvas.width; this.y = y || Math.random() * canvas.height; this.vx = (Math.random() - 0.5) * 0.5; this.vy = (Math.random() - 0.5) * 0.5; this.size = Math.random() * 1.5 + 1; } update() { this.x += this.vx; this.y += this.vy; if (this.x < 0 || this.x > canvas.width) this.vx *= -1; if (this.y < 0 || this.y > canvas.height) this.vy *= -1; } draw() { ctx.fillStyle = `hsl(${hue}, 100%, 70%)`; ctx.beginPath(); ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2); ctx.fill(); } }
        function init(num) { particles = []; for (let i = 0; i < num; i++) particles.push(new Particle()); }
        function animate() { ctx.clearRect(0, 0, canvas.width, canvas.height); hue = (hue + 0.5) % 360; particles.forEach(p => { p.update(); p.draw(); }); requestAnimationFrame(animate); }
        init(80); animate(); window.addEventListener('resize', setCanvasSize);
        const themeToggleBtn = document.getElementById('theme-toggle');
        function applyTheme(isLight) {
            document.documentElement.classList.toggle('light', isLight);
            document.getElementById('theme-toggle-light-icon').classList.toggle('hidden', !isLight);
            document.getElementById('theme-toggle-dark-icon').classList.toggle('hidden', isLight);
        }
        const savedTheme = localStorage.getItem('theme'); applyTheme(savedTheme === 'light');
        themeToggleBtn.addEventListener('click', () => { const isLight = !document.documentElement.classList.contains('light'); localStorage.setItem('theme', isLight ? 'light' : 'dark'); applyTheme(isLight); });

        // --- FUNGSI MODAL ---
        const modal = document.getElementById('task-modal'); const modalTitle = document.getElementById('modal-title'); const taskForm = document.getElementById('task-form'); let quill;
        function openAddModal() {
            taskForm.reset();
            modalTitle.innerText = 'Tambah Task Baru';
            taskForm.elements['action'].value = 'create_gba_task';
            taskForm.elements['id'].value = '';
            document.getElementById('request_date').value = new Date().toISOString().slice(0, 10);
            setupQuill('');
            updateChecklistVisibility();
            modal.classList.remove('hidden');
        }
        function openEditModal(button) {
            const card = button.closest('.task-card');
            const taskData = JSON.parse(card.getAttribute('data-task'));
            taskForm.reset();
            modalTitle.innerText = 'Edit Task';
            taskForm.elements['action'].value = 'update_gba_task';
            for (const key in taskData) {
                if (taskForm.elements[key] && !key.endsWith('_obj')) {
                    if (taskForm.elements[key].type === 'checkbox') {
                         taskForm.elements[key].checked = taskData[key] == 1;
                    } else {
                        taskForm.elements[key].value = taskData[key];
                    }
                }
            }
            setupQuill(taskData.notes || '');
            updateChecklistVisibility();
            if (taskData.test_items_checklist) {
                try {
                    const checklist = JSON.parse(taskData.test_items_checklist);
                    for (const itemName in checklist) {
                        const checkbox = document.querySelector(`input[name="checklist[${itemName}]"]`);
                        if (checkbox) checkbox.checked = !!checklist[itemName];
                    }
                } catch (e) { console.error("Gagal parse checklist JSON:", e); }
            }
            modal.classList.remove('hidden');
        }
        function closeModal() { modal.classList.add('hidden'); }
        window.onclick = (event) => { if (event.target == modal) closeModal(); };

        // --- FUNGSI EDITOR & FORM ---
        function setupQuill(content) {
            if (!quill) quill = new Quill('#notes-editor', { theme: 'snow', modules: { toolbar: [['bold', 'italic'], ['link'], [{ 'list': 'ordered'}, { 'list': 'bullet' }]] }});
            quill.root.innerHTML = content;
        }
        taskForm.addEventListener('submit', () => { document.getElementById('notes-hidden-input').value = quill.root.innerHTML; });
        document.getElementById('test_plan_type').addEventListener('change', updateChecklistVisibility);
        function updateChecklistVisibility() {
            const testPlan = document.getElementById('test_plan_type').value;
            document.getElementById('checklist-placeholder').style.display = 'block';
            document.querySelectorAll('[id^="checklist-container-"]').forEach(el => {
                if (el.id.includes(testPlan.replace(/ /g, '_'))) {
                    el.classList.remove('hidden');
                    document.getElementById('checklist-placeholder').style.display = 'none';
                } else {
                    el.classList.add('hidden');
                }
            });
        }
        function showToast(message, isSuccess = true) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.style.backgroundColor = isSuccess ? 'var(--toast-bg)' : '#ef4444';
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 3000);
        }

        // --- FUNGSI ANIMASI ---
        function triggerConfetti() {
            const duration = 2.5 * 1000;
            const animationEnd = Date.now() + duration;
            const defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 9999 };
            function randomInRange(min, max) { return Math.random() * (max - min) + min; }
            const interval = setInterval(function() {
                const timeLeft = animationEnd - Date.now();
                if (timeLeft <= 0) return clearInterval(interval);
                const particleCount = 50 * (timeLeft / duration);
                confetti({ ...defaults, particleCount, origin: { x: randomInRange(0.1, 0.3), y: Math.random() - 0.2 } });
                confetti({ ...defaults, particleCount, origin: { x: randomInRange(0.7, 0.9), y: Math.random() - 0.2 } });
            }, 250);
        }
        function triggerSadAnimation() {
            const duration = 5000;
            for (let i = 0; i < 30; i++) {
                const emoji = document.createElement('div');
                emoji.className = 'sad-emoji';
                emoji.innerText = '😢';
                emoji.style.left = `${Math.random() * 100}vw`;
                emoji.style.animationDelay = `${Math.random() * 2}s`;
                document.body.appendChild(emoji);
                setTimeout(() => emoji.remove(), duration);
            }
        }

        // --- FUNGSI KANBAN & PENCARIAN ---
        document.addEventListener('DOMContentLoaded', function () {
            const viewToggleBtn = document.getElementById('view-toggle');
            const fullIcon = document.getElementById('view-toggle-full-icon');
            const accordionIcon = document.getElementById('view-toggle-accordion-icon');
            const mainContainer = document.querySelector('main');

            function applyViewMode(mode) {
                if (mode === 'accordion') {
                    mainContainer.classList.add('view-accordion');
                    fullIcon.classList.add('hidden');
                    accordionIcon.classList.remove('hidden');
                } else {
                    mainContainer.classList.remove('view-accordion');
                    fullIcon.classList.remove('hidden');
                    accordionIcon.classList.add('hidden');
                }
                localStorage.setItem('viewMode', mode);
            }
            
            viewToggleBtn.addEventListener('click', () => {
                const currentMode = mainContainer.classList.contains('view-accordion') ? 'full' : 'accordion';
                applyViewMode(currentMode);
            });
            
            const savedViewMode = localStorage.getItem('viewMode') || 'full';
            applyViewMode(savedViewMode);

            mainContainer.addEventListener('click', function(e) {
                if (mainContainer.classList.contains('view-accordion')) {
                    const card = e.target.closest('.task-card');
                    if (card) {
                        card.classList.toggle('is-expanded');
                    }
                }
            });

            const columns = document.querySelectorAll('.kanban-column');
            columns.forEach(column => {
                new Sortable(column, {
                    group: 'kanban',
                    animation: 150,
                    ghostClass: 'sortable-ghost',
                    onEnd: function (evt) {
                        const card = evt.item;
                        const taskId = card.dataset.id;
                        const newStatus = evt.to.dataset.status;
                        
                        let reloadDelay = 800;
                        if (newStatus === 'Approved') {
                            triggerConfetti();
                            reloadDelay = 3000;
                        }
                        if (newStatus === 'Batal') {
                            triggerSadAnimation();
                            reloadDelay = 3000;
                        }

                        card.classList.toggle('underline-glow-effect', newStatus === 'Task Baru');
                        
                        fetch('handler.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ action: 'update_task_status', task_id: taskId, new_status: newStatus })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                showToast(`Status task #${taskId} diperbarui`);
                                setTimeout(() => window.location.reload(), reloadDelay);
                            } else {
                                showToast(`Gagal: ${data.error || ''}`, false);
                                evt.from.appendChild(card);
                            }
                        }).catch(() => {
                            showToast('Kesalahan Jaringan.', false);
                            evt.from.appendChild(card);
                        });
                    }
                });
            });

            function updateColumnCounts() {
                 columns.forEach(column => {
                    const status = column.dataset.status.replace(/[\s\/]/g, '');
                    const count = column.querySelectorAll('.task-card:not(.hidden)').length;
                    document.querySelector(`.count-${status}`).textContent = count;
                });
            }

            const searchInput = document.getElementById('search-input');
            searchInput.addEventListener('input', () => {
                const searchTerm = searchInput.value.toLowerCase();
                document.querySelectorAll('.task-card').forEach(card => {
                    const marketingName = card.querySelector('.marketing-name').textContent.toLowerCase();
                    const modelName = card.querySelector('.model-name').textContent.toLowerCase();
                    const pic = card.querySelector('.task-pic').textContent.toLowerCase();
                    card.classList.toggle('hidden', !(marketingName.includes(searchTerm) || modelName.includes(searchTerm) || pic.includes(searchTerm)));
                });
                updateColumnCounts();
            });
            updateColumnCounts();
        });
    </script>
</body>
</html>