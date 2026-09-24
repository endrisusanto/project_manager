<?php
// ga_submission_tracker.php

require_once "config.php";
require_once "session.php";

$active_page = 'ga_tracker';

// --- 1. Date & BAS Status Calculation ---
date_default_timezone_set('Asia/Jakarta');
$today_str = date('Y-m-d');

// Read BAS session bridge file status
$session_file = __DIR__ . '/.bas_session.json';
$bas_session_active = false;
$bas_session_status_label = 'Disconnected';
$bas_session_detail = 'Belum ada token';

if (file_exists($session_file)) {
    $bas_raw = @file_get_contents($session_file);
    $bas_data = json_decode($bas_raw, true);
    if ($bas_data && !empty($bas_data['sid'])) {
        $bas_ts = $bas_data['timestamp'] ?? 0;
        $bas_age = time() - $bas_ts;
        if ($bas_age < (8 * 3600)) {
            $bas_session_active = true;
            $mins = max(1, round($bas_age / 60));
            $bas_session_status_label = 'Active';
            $bas_session_detail = ($mins < 60) ? "Updated {$mins}m ago" : "Updated " . round($mins / 60, 1) . "h ago";
        } else {
            $hours = round($bas_age / 3600, 1);
            $bas_session_status_label = 'Expired';
            $bas_session_detail = "Expired ({$hours}h ago)";
        }
    }
}


// --- 2. Fetch and Group Tasks ---
$sql = "
    SELECT t.id, t.model_name, t.ap, t.pic_email, t.progress_status, t.notes, u.username
    FROM gba_tasks t
    LEFT JOIN users u ON t.pic_email = u.email
    WHERE t.progress_status IN ('Task Baru', 'Downloaded') OR (t.progress_status = 'Test Ongoing' AND t.notes IS NOT NULL AND t.notes LIKE 'GA%')
    ORDER BY t.pic_email, t.model_name
";

$tasks_result = $conn->query($sql);
$new_tasks_grouped = [];
$processed_tasks = []; // List 2: GA Follow Up Besok & GA First Run (bisa dikategorikan ulang)
$clipboard_tasks = []; // List 3: GA Submit & GA Follow Up (final dengan timer)
$cutoff_time = (new DateTime())->modify('-20 hours');

if ($tasks_result) {
    while ($task = $tasks_result->fetch_assoc()) {
        $pic_email = $task['pic_email'];
        $pic_name = $task['username'] ?? strtok($pic_email, '@');

        // Task Baru & Downloaded masuk ke List 1 (Menunggu Review/Kategorisasi)
        if ($task['progress_status'] === 'Task Baru' || $task['progress_status'] === 'Downloaded') {
            if (!isset($new_tasks_grouped[$pic_email])) {
                $new_tasks_grouped[$pic_email] = ['name' => $pic_name, 'tasks' => []];
            }
            $new_tasks_grouped[$pic_email]['tasks'][] = [
                'id' => $task['id'],
                'ap' => $task['ap'] ?: 'N/A',
                'model_name' => $task['model_name'],
                'progress_status' => $task['progress_status']
            ];
        }

        // Task dengan notes GA yang sudah dikategorikan
        if ($task['progress_status'] === 'Test Ongoing' && !empty($task['notes'])) {
            if (preg_match('/(GA\s.+):\s-\s(.+)\s\[Target Submit:\s(.+)\]\s\[Target Approved:\s(.+)\]\s\[CATEGORIZED_AT:\s(.+)\]/', $task['notes'], $matches)) {

                $category = trim($matches[1]);
                $ap_version = trim($matches[2]);
                $target_submit = trim($matches[3]);
                $target_approved = trim($matches[4]);
                $categorized_at_str = trim($matches[5]);

                $categorized_at = DateTime::createFromFormat('Y-m-d H:i:s', $categorized_at_str);

                // Check 20-hour expiry (ONLY for List 3)
                // List 2 (GA Follow Up Besok & GA First Run) should NOT expire
                $is_list_2 = ($category === 'GA Follow Up Besok' || $category === 'GA First Run');
                $is_list_3 = ($category === 'GA Submit' || $category === 'GA Follow Up');

                if ($is_list_2 || ($is_list_3 && $categorized_at && $categorized_at > $cutoff_time)) {
                    // Pisahkan berdasarkan kategori
                    if ($is_list_2) {
                        // Masuk ke List 2 (Processed - bisa dikategorikan ulang)
                        if (!isset($processed_tasks[$pic_email])) {
                            $processed_tasks[$pic_email] = ['name' => $pic_name, 'tasks' => []];
                        }
                        $processed_tasks[$pic_email]['tasks'][] = [
                            'id' => $task['id'],
                            'ap' => $ap_version,
                            'model_name' => $task['model_name'],
                            'category' => $category,
                            'target_submit' => $target_submit,
                            'target_approved' => $target_approved
                        ];
                    } elseif ($is_list_3) {
                        // Masuk ke List 3 (Final - dengan timer)
                        if (!isset($clipboard_tasks[$pic_email])) {
                            $clipboard_tasks[$pic_email] = [
                                'name' => $pic_name,
                                'categories' => [
                                    'GA Submit' => [],
                                    'GA Follow Up' => []
                                ]
                            ];
                        }

                        $clipboard_tasks[$pic_email]['categories'][$category][] = [
                            'formatted_string' => "- {$ap_version} [Target Submit: {$target_submit}] [Target Approved: {$target_approved}]",
                            'timestamp' => $categorized_at_str
                        ];
                    }
                }
            }
        }
    }
}

$initial_processed_data_json = json_encode($processed_tasks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
$initial_clipboard_data_json = json_encode($clipboard_tasks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GBA Submission Tracker - Project Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['class', '.never-match-dark']
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #020617;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --card-bg: rgba(15, 23, 42, 0.75);
            --card-border: rgba(51, 65, 85, 0.65);
            --item-bg: rgba(30, 41, 59, 0.6);
            --item-hover: rgba(51, 65, 85, 0.75);
            --item-border: rgba(51, 65, 85, 0.6);
            --mono-font: 'JetBrains Mono', monospace;
            --output-bg: rgba(2, 6, 23, 0.8);
            --output-border: rgba(51, 65, 85, 0.6);
            --output-text: #e2e8f0;
        }
        html.light {
            --bg-primary: #f8fafc;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --item-bg: #f8fafc;
            --item-hover: #f1f5f9;
            --item-border: #e2e8f0;
            --output-bg: #f8fafc;
            --output-border: #e2e8f0;
            --output-text: #0f172a;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            transition: background-color 0.18s ease, color 0.18s ease;
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

        .glass-panel {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--card-border);
            border-radius: 1.25rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        html.light .glass-panel {
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05);
        }

        .sub-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 1rem;
            transition: all 0.2s ease;
        }
        html.light .sub-card {
            background-color: #ffffff;
            border-color: #e2e8f0;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
        }

        .tracker-item {
            background-color: var(--item-bg);
            border: 1px solid var(--item-border);
            border-radius: 0.625rem;
            padding: 0.625rem 0.875rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
        }
        html.light .tracker-item {
            background-color: #f8fafc;
            border-color: #e2e8f0;
        }
        html.light .tracker-item:hover {
            background-color: #f1f5f9;
            border-color: #cbd5e1;
        }
        .tracker-item:hover {
            background-color: var(--item-hover);
            transform: translateY(-1px);
        }
        .tracker-item:active {
            transform: translateY(0);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            align-items: center;
            justify-content: center;
            padding: 1rem;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .modal.active-flex {
            display: flex !important;
            opacity: 1;
            pointer-events: auto;
        }

        .modal-content {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 1.25rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.3);
            transform: scale(0.96);
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        html.light .modal-content {
            background-color: #ffffff;
            border-color: #e2e8f0;
        }
        .modal.active-flex .modal-content {
            transform: scale(1);
        }

        .output-mono {
            font-family: var(--mono-font);
            background-color: var(--output-bg);
            border: 1px solid var(--output-border);
            color: var(--output-text);
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.3);
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.5);
        }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <canvas id="neural-canvas"></canvas>

    <!-- Toast Notification -->
    <div id="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[10000] px-4 py-2.5 rounded-xl text-sm font-semibold text-white shadow-2xl flex items-center gap-2 transition-all duration-300 pointer-events-none opacity-0 translate-y-4">
        <span id="toast-icon"></span>
        <span id="toast-msg"></span>
    </div>

    <?php include 'header.php'; ?>

    <main class="w-full flex-grow p-4 sm:p-6 sm:py-8 flex flex-col max-w-7xl mx-auto space-y-6">
        
        <!-- Header HUD -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 flex-shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-blue-500/15 border border-blue-500/30 flex items-center justify-center text-blue-600 flex-shrink-0 shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                </div>
                <div>
                    <div class="flex items-center gap-2.5 flex-wrap">
                        <h1 class="text-2xl sm:text-3xl font-black tracking-tight" style="color: var(--text-primary);">GBA Tracker & Reason OT</h1>
                        
                        <!-- BAS Status Badge -->
                        <div id="bas-status-badge" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold border transition-colors <?= $bas_session_active ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30' : 'bg-rose-500/15 text-rose-400 border-rose-500/30' ?>">
                            <span id="bas-status-dot" class="w-2 h-2 rounded-full <?= $bas_session_active ? 'bg-emerald-400 animate-pulse' : 'bg-rose-400' ?>"></span>
                            <span id="bas-status-text">BAS: <?= $bas_session_status_label ?> (<?= $bas_session_detail ?>)</span>
                        </div>
                    </div>
                    <p class="text-xs sm:text-sm font-medium" style="color: var(--text-secondary);">Monitoring pipeline submission GA, reason overtime, dan otomasi salin template</p>
                </div>
            </div>
            
            <div class="flex items-center gap-2.5 flex-wrap sm:flex-nowrap">
                <!-- Sync BAS Now Button -->
                <button id="btn-sync-bas" onclick="triggerBasSync()" class="inline-flex items-center gap-2 px-3.5 py-2.5 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white rounded-xl text-xs sm:text-sm font-semibold transition-all shadow-sm active:scale-95 flex-shrink-0">
                    <svg id="sync-bas-icon" class="w-4 h-4 text-emerald-100 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    <span id="sync-bas-text">Sync BAS Now</span>
                </button>

                <button onclick="openActivityLog()" class="inline-flex items-center gap-2 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs sm:text-sm font-semibold transition-all shadow-sm active:scale-95 flex-shrink-0">
                    <svg class="w-4 h-4 text-blue-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>Activity Log</span>
                </button>
            </div>
        </div>


        <!-- Main Content Area (Responsive Bento Grid) -->
        <div class="w-full flex-grow pb-8">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                <?php if (empty($new_tasks_grouped) && empty($processed_tasks) && empty($clipboard_tasks)): ?>
                    <div class="glass-panel p-12 text-center col-span-full flex flex-col items-center justify-center space-y-3">
                        <div class="w-16 h-16 rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400 border border-slate-200">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                        </div>
                        <h3 class="text-base font-bold" style="color: var(--text-primary);">Tidak ada task yang perlu diproses</h3>
                        <p class="text-xs max-w-sm" style="color: var(--text-secondary);">Semua task GBA baru atau ongoing GA telah terselesaikan atau belum ditugaskan.</p>
                    </div>
                <?php else: ?>
                    <?php
                    // Gabungkan semua PIC dari 3 sumber
                    $all_pic_emails = array_unique(array_merge(
                        array_keys($new_tasks_grouped),
                        array_keys($processed_tasks),
                        array_keys($clipboard_tasks)
                    ));

                    foreach ($all_pic_emails as $email):
                        $pic_name = '';
                        if (isset($new_tasks_grouped[$email]))
                            $pic_name = $new_tasks_grouped[$email]['name'];
                        elseif (isset($processed_tasks[$email]))
                            $pic_name = $processed_tasks[$email]['name'];
                        elseif (isset($clipboard_tasks[$email]))
                            $pic_name = $clipboard_tasks[$email]['name'];

                        $picHash = str_replace('=', '', base64_encode($email));
                    ?>
                        <!-- Container untuk satu PIC (Stack 3 Card Vertikal) -->
                        <div class="flex flex-col space-y-4" data-pic-email="<?= htmlspecialchars($email) ?>">

                            <!-- List 1: Daftar Task Baru -->
                            <?php if (isset($new_tasks_grouped[$email])): ?>
                                <div id="list-card-<?= $picHash ?>" class="sub-card p-4 sm:p-5 flex flex-col space-y-3 overflow-hidden border-l-4 border-l-blue-600">
                                    <div class="flex items-center justify-between border-b pb-2.5 flex-shrink-0" style="border-color: var(--card-border);">
                                        <div class="flex items-center gap-2">
                                            <span class="w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-black flex items-center justify-center shadow-sm">1</span>
                                            <div>
                                                <h3 class="text-sm font-bold" style="color: var(--text-primary);">
                                                    <?= htmlspecialchars($pic_name) ?>
                                                </h3>
                                                <p class="text-[10px] font-medium" style="color: var(--text-secondary);">Task Baru & Downloaded</p>
                                            </div>
                                        </div>
                                        <span class="px-2 py-0.5 bg-blue-100 text-blue-800 border border-blue-200 text-xs font-bold rounded-lg">
                                            <?= count($new_tasks_grouped[$email]['tasks']) ?> Task
                                        </span>
                                    </div>
                                    <div class="overflow-y-auto max-h-56 pr-1">
                                        <ul class="list-group space-y-2">
                                            <?php foreach ($new_tasks_grouped[$email]['tasks'] as $task): ?>
                                                <li class="tracker-item text-xs" data-task-id="<?= $task['id'] ?>"
                                                    data-ap="<?= htmlspecialchars($task['ap']) ?>"
                                                    data-model-name="<?= htmlspecialchars($task['model_name']) ?>"
                                                    onclick="openTaskModal(<?= $task['id'] ?>, '<?= htmlspecialchars($email) ?>', '<?= htmlspecialchars($task['ap']) ?>')">
                                                    <div class="flex items-center gap-2 truncate flex-1 min-w-0">
                                                        <span class="w-2 h-2 rounded-full <?= ($task['progress_status'] ?? '') === 'Downloaded' ? 'bg-cyan-500' : 'bg-blue-600' ?> flex-shrink-0"></span>
                                                        <span class="font-bold truncate" style="color: var(--text-primary);"><?= htmlspecialchars($task['ap']) ?></span>
                                                        <span class="truncate text-[11px]" style="color: var(--text-secondary);">(<?= htmlspecialchars($task['model_name']) ?>)</span>
                                                        <?php if (($task['progress_status'] ?? '') === 'Downloaded'): ?>
                                                            <span class="px-1.5 py-0.5 text-[9px] font-bold rounded bg-cyan-500/15 text-cyan-400 border border-cyan-500/30 flex-shrink-0">Downloaded</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <svg class="w-3.5 h-3.5 text-slate-400 flex-shrink-0 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- List 2: Template Reason OT (Diproses) - Placeholder JS -->
                            <div id="processed-card-<?= $picHash ?>-container"></div>

                            <!-- List 3: Template Reason OT Final - Placeholder JS -->
                            <div id="clipboard-card-<?= $picHash ?>-container"></div>

                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Modal Kategori Task -->
    <div id="task-category-modal" class="modal">
        <div class="modal-content w-full max-w-lg p-6 flex flex-col space-y-5">
            <div class="flex items-center justify-between border-b pb-3" style="border-color: var(--card-border);">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-blue-500/15 border border-blue-500/30 flex items-center justify-center text-blue-600 font-bold">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold" style="color: var(--text-primary);">Kategorikan Task GBA</h2>
                        <p class="text-xs" style="color: var(--text-secondary);">Pilih target timeline dan jenis proses</p>
                    </div>
                </div>
                <button onclick="closeTaskModal()" class="w-7 h-7 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 flex items-center justify-center transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="p-3 bg-blue-50 border border-blue-200 rounded-xl flex items-center justify-between">
                <span class="text-xs font-medium text-slate-700">Target AP:</span>
                <span id="modal-ap-display" class="font-mono font-bold text-sm text-blue-800"></span>
            </div>

            <input type="hidden" id="modal-task-id">
            <input type="hidden" id="modal-pic-email">
            <input type="hidden" id="modal-ap-version">

            <div class="grid grid-cols-1 gap-2.5">
                <button type="button" onclick="processTaskCategory('GA Submit')" class="w-full text-left p-3.5 rounded-xl border border-blue-200 bg-blue-50 hover:bg-blue-100 transition-all flex items-center justify-between group active:scale-[0.99]">
                    <div>
                        <div class="text-sm font-bold text-blue-900 flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                            GA Submit
                        </div>
                        <div class="text-[11px] text-slate-700 mt-0.5">Target Submit: <span class="font-bold text-slate-900">Hari Ini</span> &bull; Target Approved: <span class="font-bold text-slate-900">Besok</span></div>
                    </div>
                    <svg class="w-4 h-4 text-blue-600 opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>

                <button type="button" onclick="processTaskCategory('GA Follow Up')" class="w-full text-left p-3.5 rounded-xl border border-indigo-200 bg-indigo-50 hover:bg-indigo-100 transition-all flex items-center justify-between group active:scale-[0.99]">
                    <div>
                        <div class="text-sm font-bold text-indigo-900 flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-indigo-600"></span>
                            GA Follow Up
                        </div>
                        <div class="text-[11px] text-slate-700 mt-0.5">Target Submit: <span class="font-bold text-slate-900">Hari Ini</span> &bull; Target Approved: <span class="font-bold text-slate-900">Besok</span></div>
                    </div>
                    <svg class="w-4 h-4 text-indigo-600 opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>

                <button type="button" onclick="processTaskCategory('GA Follow Up Besok')" class="w-full text-left p-3.5 rounded-xl border border-amber-200 bg-amber-50 hover:bg-amber-100 transition-all flex items-center justify-between group active:scale-[0.99]">
                    <div>
                        <div class="text-sm font-bold text-amber-900 flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-amber-600"></span>
                            GA Follow Up Besok
                        </div>
                        <div class="text-[11px] text-slate-700 mt-0.5">Target Submit: <span class="font-bold text-slate-900">Besok</span> &bull; Target Approved: <span class="font-bold text-slate-900">Besok</span></div>
                    </div>
                    <svg class="w-4 h-4 text-amber-600 opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>

                <button type="button" onclick="processTaskCategory('GA First Run')" class="w-full text-left p-3.5 rounded-xl border border-purple-200 bg-purple-50 hover:bg-purple-100 transition-all flex items-center justify-between group active:scale-[0.99]">
                    <div>
                        <div class="text-sm font-bold text-purple-900 flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-purple-600"></span>
                            GA First Run
                        </div>
                        <div class="text-[11px] text-slate-700 mt-0.5">Target Submit: <span class="font-bold text-slate-900">Besok</span> &bull; Target Approved: <span class="font-bold text-slate-900">Besok</span></div>
                    </div>
                    <svg class="w-4 h-4 text-purple-600 opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>

            <div class="pt-2">
                <button type="button" onclick="closeTaskModal()" class="w-full py-2.5 px-4 rounded-xl text-xs font-semibold border border-slate-300 hover:bg-slate-100 transition-colors" style="color: var(--text-secondary);">
                    Batal
                </button>
            </div>
        </div>
    </div>

    <!-- Activity Log Modal -->
    <div id="activity-log-modal" class="modal">
        <div class="modal-content w-full max-w-3xl max-h-[85vh] p-6 flex flex-col space-y-4">
            <div class="flex justify-between items-center border-b pb-3 flex-shrink-0" style="border-color: var(--card-border);">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-blue-500/15 border border-blue-500/30 flex items-center justify-center text-blue-600">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold" style="color: var(--text-primary);">Activity Log (Copied Items)</h2>
                        <p class="text-xs" style="color: var(--text-secondary);">Riwayat salin template reason OT ke clipboard</p>
                    </div>
                </div>
                <button onclick="closeActivityLog()" class="w-7 h-7 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 flex items-center justify-center transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="overflow-y-auto flex-grow pr-1 space-y-3" id="activity-log-container">
                <div class="text-center text-slate-400 py-8 text-xs">Memuat log aktivitas...</div>
            </div>

            <div class="pt-3 border-t flex justify-end flex-shrink-0" style="border-color: var(--card-border);">
                <button onclick="closeActivityLog()" class="px-5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-800 rounded-xl text-xs font-semibold transition-colors">
                    Tutup
                </button>
            </div>
        </div>
    </div>

    <script>
        // --- Setup Data & Constants ---
        let clipboardData = <?= $initial_clipboard_data_json ?>;
        let processedData = <?= $initial_processed_data_json ?>; 
        let countdownTimers = [];

        // --- Activity Log Functions ---
        function openActivityLog() {
            fetchActivityLogs();
            const modal = document.getElementById('activity-log-modal');
            modal.classList.add('active-flex');
        }

        function closeActivityLog() {
            const modal = document.getElementById('activity-log-modal');
            modal.classList.remove('active-flex');
        }

        function fetchActivityLogs() {
            const container = document.getElementById('activity-log-container');
            container.innerHTML = '<div class="text-center text-slate-400 py-8 text-xs">Memuat log aktivitas...</div>';

            fetch('handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_activity_logs' })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderActivityLog(data.logs);
                } else {
                    container.innerHTML = '<div class="text-center text-rose-600 py-8 text-xs font-semibold">Gagal memuat log aktivitas.</div>';
                }
            })
            .catch(err => {
                console.error(err);
                container.innerHTML = '<div class="text-center text-rose-600 py-8 text-xs font-semibold">Terjadi kesalahan koneksi.</div>';
            });
        }

        function renderActivityLog(logs) {
            const container = document.getElementById('activity-log-container');
            if (!logs || logs.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-10 flex flex-col items-center justify-center space-y-2">
                        <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center text-slate-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                        <p class="text-xs font-medium" style="color: var(--text-secondary);">Belum ada aktivitas copy tercatat.</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = logs.map(log => `
                <div class="sub-card p-3.5 space-y-2">
                    <div class="flex justify-between items-center">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 bg-blue-100 text-blue-800 text-[10px] font-mono font-bold rounded-md border border-blue-200">${log.timestamp}</span>
                            <span class="text-xs font-bold" style="color: var(--text-primary);">${log.pic_name}</span>
                        </div>
                        <button onclick="deleteActivityLog(${log.id})" class="text-slate-400 hover:text-rose-600 transition-colors p-1 rounded-md" title="Hapus Log">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>
                    <div class="output-mono rounded-lg p-3 text-xs whitespace-pre-wrap">${log.content}</div>
                </div>
            `).join('');
        }

        function deleteActivityLog(id) {
            if (!confirm('Hapus log aktivitas ini?')) return;

            fetch('handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_activity_log', id: id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    fetchActivityLogs();
                } else {
                    showToast('Gagal menghapus log: ' + (data.error || 'Unknown error'), false);
                }
            })
            .catch(err => {
                console.error(err);
                showToast('Terjadi kesalahan koneksi.', false);
            });
        }

        // Helper function for Clipboard API fallback
        function fallbackCopyToClipboard(textToCopy) {
            const textArea = document.createElement("textarea");
            textArea.value = textToCopy;
            textArea.style.top = "0";
            textArea.style.left = "0";
            textArea.style.position = "fixed";
            textArea.style.opacity = "0";

            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();

            try {
                const successful = document.execCommand('copy');
                document.body.removeChild(textArea);
                return successful;
            } catch (err) {
                document.body.removeChild(textArea);
                return false;
            }
        }

        // --- Countdown Logic ---
        function calculateTimeRemaining(categorizedAtTime) {
            const categorizedTime = new Date(categorizedAtTime).getTime();
            const expiryTime = categorizedTime + (20 * 3600 * 1000);
            const now = new Date().getTime();
            const remainingMilliseconds = expiryTime - now;

            if (remainingMilliseconds <= 0) {
                return "Expired";
            }

            const seconds = Math.floor((remainingMilliseconds / 1000) % 60);
            const minutes = Math.floor((remainingMilliseconds / 1000 / 60) % 60);
            const hours = Math.floor((remainingMilliseconds / (1000 * 60 * 60)));

            return `${String(hours).padStart(2, '0')}j ${String(minutes).padStart(2, '0')}m ${String(seconds).padStart(2, '0')}d`;
        }

        function startCountdown(cardElement, categorizedAtTime) {
            const countdownElement = cardElement.querySelector('.countdown-timer');
            if (!countdownElement) return;

            function updateTimer() {
                const remaining = calculateTimeRemaining(categorizedAtTime);
                countdownElement.textContent = remaining;

                if (remaining === "Expired") {
                    const picEmail = cardElement.dataset.picEmail;
                    if (clipboardData[picEmail]) {
                        delete clipboardData[picEmail];
                    }
                    cardElement.remove();
                    clearInterval(cardElement.timerId);
                }
            }

            updateTimer();
            cardElement.timerId = setInterval(updateTimer, 1000);
            countdownTimers.push(cardElement.timerId);
        }

        // --- Modal Logic ---
        function openTaskModal(taskId, picEmail, apVersion) {
            document.getElementById('modal-ap-display').textContent = apVersion;
            document.getElementById('modal-task-id').value = taskId;
            document.getElementById('modal-pic-email').value = picEmail;
            document.getElementById('modal-ap-version').value = apVersion;
            const modal = document.getElementById('task-category-modal');
            modal.classList.add('active-flex');
        }

        function closeTaskModal() {
            const modal = document.getElementById('task-category-modal');
            modal.classList.remove('active-flex');
        }

        // --- Core Processing Logic ---
        function processTaskCategory(category) {
            const taskId = document.getElementById('modal-task-id').value;
            const picEmail = document.getElementById('modal-pic-email').value;
            const apVersion = document.getElementById('modal-ap-version').value;

            closeTaskModal();
            showToast(`Memproses task #${taskId} ke ${category}...`, true);

            const listItem = document.querySelector(`.tracker-item[data-task-id="${taskId}"]`);
            let picName = picEmail;

            if (listItem) {
                const picContainer = listItem.closest('[data-pic-email]');
                if (picContainer) {
                    const h3 = picContainer.querySelector('h3');
                    if (h3) picName = h3.textContent.trim();
                }
            }

            fetch('handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update_task_status_tracker',
                    task_id: taskId,
                    category: category,
                    ap_version: apVersion
                })
            }).then(response => response.json()).then(data => {
                if (data.success) {
                    if (category === 'GA Follow Up Besok' || category === 'GA First Run') {
                        if (!processedData[picEmail]) {
                            processedData[picEmail] = { 'name': picName, 'tasks': [] };
                        }
                        processedData[picEmail].tasks = processedData[picEmail].tasks.filter(
                            task => task.ap !== apVersion
                        );
                        processedData[picEmail].tasks.push({
                            id: taskId,
                            ap: data.ap_version,
                            model_name: data.model_name || apVersion,
                            category: category,
                            target_submit: data.target_submit,
                            target_approved: data.target_approved
                        });
                    } else if (category === 'GA Submit' || category === 'GA Follow Up') {
                        if (!clipboardData[picEmail]) {
                            clipboardData[picEmail] = { 'name': picName, 'categories': { 'GA Submit': [], 'GA Follow Up': [] } };
                        }

                        ['GA Submit', 'GA Follow Up'].forEach(cat => {
                            if (clipboardData[picEmail].categories[cat]) {
                                clipboardData[picEmail].categories[cat] = clipboardData[picEmail].categories[cat].filter(
                                    taskObj => !taskObj.formatted_string.includes(apVersion)
                                );
                            }
                        });

                        if (!clipboardData[picEmail].categories[category]) {
                            clipboardData[picEmail].categories[category] = [];
                        }

                        clipboardData[picEmail].categories[category].push({
                            formatted_string: `- ${data.ap_version} [Target Submit: ${data.target_submit}] [Target Approved: ${data.target_approved}]`,
                            timestamp: data.categorized_at
                        });

                        if (processedData[picEmail]) {
                            processedData[picEmail].tasks = processedData[picEmail].tasks.filter(
                                task => task.ap !== apVersion
                            );
                            if (processedData[picEmail].tasks.length === 0) {
                                delete processedData[picEmail];
                            }
                        }
                    }

                    if (listItem) {
                        const listGroup = listItem.closest('.list-group');
                        const picCard = listItem.closest('.sub-card');
                        listItem.remove();

                        if (listGroup && listGroup.children.length === 0 && picCard) {
                            picCard.remove();
                        }
                    }

                    renderProcessedOutput();
                    renderClipboardOutput();
                    showToast(`Task #${taskId} berhasil dikategorikan sebagai ${category}.`, true);
                } else {
                    showToast(`Gagal update task #${taskId}: ${data.error}`, false);
                    console.error("API Error:", data.error);
                }
            }).catch(error => {
                showToast(`Error Jaringan saat update task #${taskId}.`, false);
                console.error("Fetch Error:", error);
            });
        }

        // --- Output Rendering for List 2 (Processed - Clickable) ---
        function renderProcessedOutput() {
            document.querySelectorAll('[id$="-container"]').forEach(container => {
                if (container.id.includes('processed-card-')) {
                    container.innerHTML = '';
                }
            });

            for (const picEmail in processedData) {
                const data = processedData[picEmail];
                const picHash = btoa(picEmail).replace(/=/g, '');
                const container = document.getElementById(`processed-card-${picHash}-container`);

                if (!container) continue;

                if (data.tasks && data.tasks.length > 0) {
                    const cardHTML = `
                        <div id="processed-card-${picHash}" class="sub-card p-4 sm:p-5 flex flex-col space-y-3 overflow-hidden border-l-4 border-l-amber-500">
                            <div class="flex items-center justify-between border-b pb-2.5 flex-shrink-0" style="border-color: var(--card-border);">
                                <div class="flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-full bg-amber-500 text-white text-xs font-black flex items-center justify-center shadow-sm">2</span>
                                    <div>
                                        <h3 class="text-sm font-bold" style="color: var(--text-primary);">
                                            ${data.name}
                                        </h3>
                                        <p class="text-[10px] font-medium" style="color: var(--text-secondary);">Diproses (Jadwal Besok)</p>
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 bg-amber-100 text-amber-900 border border-amber-300 text-xs font-bold rounded-lg">
                                    ${data.tasks.length} Task
                                </span>
                            </div>
                            <div class="overflow-y-auto max-h-56 pr-1">
                                <ul class="list-group space-y-2">
                                    ${data.tasks.map(task => `
                                        <li class="tracker-item text-xs" 
                                            data-task-id="${task.id}" 
                                            data-ap="${task.ap}" 
                                            data-model-name="${task.model_name}"
                                            onclick="openTaskModal(${task.id}, '${picEmail}', '${task.ap}')">
                                            <div class="flex items-center gap-2 truncate">
                                                <span class="w-2 h-2 rounded-full bg-amber-500 flex-shrink-0"></span>
                                                <span class="font-bold truncate" style="color: var(--text-primary);">${task.ap}</span>
                                                <span class="text-[10px] px-1.5 py-0.5 bg-amber-100 text-amber-900 rounded font-bold border border-amber-300">${task.category}</span>
                                            </div>
                                            <svg class="w-3.5 h-3.5 text-slate-400 flex-shrink-0 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                        </li>
                                    `).join('')}
                                </ul>
                            </div>
                        </div>
                    `;
                    container.innerHTML = cardHTML;
                }
            }
        }

        // --- Output Rendering for List 3 (Final - includes List 2 data) ---
        function renderClipboardOutput() {
            document.querySelectorAll('[id$="-container"]').forEach(container => {
                if (container.id.includes('clipboard-card-')) {
                    container.innerHTML = '';
                }
            });

            countdownTimers.forEach(clearInterval);
            countdownTimers = [];

            const allPicEmails = new Set([...Object.keys(processedData), ...Object.keys(clipboardData)]);

            for (const picEmail of allPicEmails) {
                const processedTasks = processedData[picEmail];
                const clipboardTasks = clipboardData[picEmail];
                const picHash = btoa(picEmail).replace(/=/g, '');
                const container = document.getElementById(`clipboard-card-${picHash}-container`);

                if (!container) continue;

                let outputContent = '';
                let hasContent = false;
                let newestTimestamp = null;
                let totalItems = 0;
                let picName = '';

                if (processedTasks) picName = processedTasks.name;
                if (clipboardTasks) picName = clipboardTasks.name;

                let gaSubmitTasks = [];
                let gaFollowUpTasks = [];
                let gaFirstRunTasks = [];

                if (clipboardTasks && clipboardTasks.categories) {
                    if (clipboardTasks.categories['GA Submit']) {
                        gaSubmitTasks = [...clipboardTasks.categories['GA Submit']];
                    }
                    if (clipboardTasks.categories['GA Follow Up']) {
                        gaFollowUpTasks = [...clipboardTasks.categories['GA Follow Up']];
                    }
                }

                if (processedTasks && processedTasks.tasks) {
                    processedTasks.tasks.forEach(task => {
                        const taskObj = {
                            formatted_string: `- ${task.ap} [Target Submit: ${task.target_submit}] [Target Approved: ${task.target_approved}]`,
                            timestamp: null
                        };

                        if (task.category === 'GA Follow Up Besok') {
                            gaFollowUpTasks.push(taskObj);
                        } else if (task.category === 'GA First Run') {
                            gaFirstRunTasks.push(taskObj);
                        }
                    });
                }

                const categoriesToRender = [
                    { name: 'GA Submit', tasks: gaSubmitTasks, color: 'text-blue-800' },
                    { name: 'GA Follow Up', tasks: gaFollowUpTasks, color: 'text-indigo-800' },
                    { name: 'GA First Run', tasks: gaFirstRunTasks, color: 'text-purple-800' }
                ];

                categoriesToRender.forEach(cat => {
                    if (cat.tasks.length > 0) {
                        outputContent += `
                            <div class="space-y-1" data-category="${cat.name}">
                                <h4 class="text-xs font-black ${cat.color} uppercase tracking-wider">${cat.name}:</h4>
                                <ul class="output-mono text-[11px] space-y-0.5 p-2.5 rounded-lg">
                        `;

                        cat.tasks.forEach(taskObj => {
                            if (taskObj.timestamp) {
                                if (!newestTimestamp || new Date(taskObj.timestamp) > new Date(newestTimestamp)) {
                                    newestTimestamp = taskObj.timestamp;
                                }
                            }
                            outputContent += `<li class="break-all">${taskObj.formatted_string.trim()}</li>`;
                            totalItems++;
                        });
                        outputContent += `</ul></div>`;
                        hasContent = true;
                    }
                });

                if (hasContent) {
                    const cardHTML = `
                        <div id="clipboard-card-${picHash}" class="sub-card p-4 sm:p-5 flex flex-col space-y-3 overflow-hidden border-l-4 border-l-emerald-600" data-pic-email="${picEmail}">
                            <div class="flex justify-between items-center border-b pb-2.5 flex-shrink-0" style="border-color: var(--card-border);">
                                <div class="flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-full bg-emerald-600 text-white text-xs font-black flex items-center justify-center shadow-sm">3</span>
                                    <div>
                                        <h3 class="text-sm font-bold" style="color: var(--text-primary);">${picName}</h3>
                                        <p class="text-[10px] font-medium" style="color: var(--text-secondary);">Final Template</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    ${newestTimestamp ? `<span class="text-[11px] font-mono text-amber-800 font-bold bg-amber-50 px-2 py-0.5 rounded border border-amber-300 countdown-timer"></span>` : ''}
                                    <span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 border border-emerald-300 text-xs font-bold rounded-lg">${totalItems} AP</span>
                                </div>
                            </div>
                            <div class="overflow-y-auto max-h-64 space-y-2.5 pr-1">
                                ${outputContent}
                            </div>
                            <div class="flex-shrink-0 pt-2 border-t" style="border-color: var(--card-border);">
                                <button onclick="copyCardContent(this, '${picEmail}')" class="w-full py-2.5 px-3 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl transition-all shadow-sm active:scale-95 flex items-center justify-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                                    <span>Salin Template ke Clipboard</span>
                                </button>
                            </div>
                        </div>
                    `;
                    container.innerHTML = cardHTML;

                    const newCardElement = document.getElementById(`clipboard-card-${picHash}`);
                    if (newestTimestamp) {
                        startCountdown(newCardElement, newestTimestamp);
                    }
                }
            }
        }

        // --- Clipboard Helper ---
        function copyCardContent(button, picEmail) {
            const clipboardTasks = clipboardData[picEmail];
            const processedTasks = processedData[picEmail];

            if (!clipboardTasks && !processedTasks) return;

            let textToCopy = '';
            let gaSubmitTasks = [];
            let gaFollowUpTasks = [];
            let gaFirstRunTasks = [];

            if (clipboardTasks && clipboardTasks.categories) {
                if (clipboardTasks.categories['GA Submit']) {
                    clipboardTasks.categories['GA Submit'].forEach(task => {
                        gaSubmitTasks.push(task.formatted_string);
                    });
                }
                if (clipboardTasks.categories['GA Follow Up']) {
                    clipboardTasks.categories['GA Follow Up'].forEach(task => {
                        gaFollowUpTasks.push(task.formatted_string);
                    });
                }
            }

            if (processedTasks && processedTasks.tasks) {
                processedTasks.tasks.forEach(task => {
                    const formattedString = `- ${task.ap} [Target Submit: ${task.target_submit}] [Target Approved: ${task.target_approved}]`;
                    if (task.category === 'GA Follow Up Besok') {
                        gaFollowUpTasks.push(formattedString);
                    } else if (task.category === 'GA First Run') {
                        gaFirstRunTasks.push(formattedString);
                    }
                });
            }

            const categoriesToCopy = [
                { name: 'GA Submit', tasks: gaSubmitTasks },
                { name: 'GA Follow Up', tasks: gaFollowUpTasks },
                { name: 'GA First Run', tasks: gaFirstRunTasks }
            ];

            categoriesToCopy.forEach(cat => {
                if (cat.tasks.length > 0) {
                    textToCopy += `${cat.name}:\n`;
                    cat.tasks.forEach(str => {
                        textToCopy += ` ${str.trim()}\n`;
                    });
                }
            });

            if (textToCopy) {
                const finalContent = textToCopy.trim();

                let picName = picEmail;
                if (clipboardTasks && clipboardTasks.name) picName = clipboardTasks.name;
                else if (processedTasks && processedTasks.name) picName = processedTasks.name;

                fetch('handler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_activity_log',
                        pic_name: picName,
                        content: finalContent
                    })
                })
                .then(response => response.json())
                .catch(err => console.error('Error saving log:', err));

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(finalContent)
                        .then(() => showToast('Template berhasil disalin ke clipboard!', true))
                        .catch(err => {
                            const success = fallbackCopyToClipboard(finalContent);
                            showToast(success ? 'Template berhasil disalin ke clipboard!' : 'Gagal menyalin text.', success);
                        });
                } else {
                    const success = fallbackCopyToClipboard(finalContent);
                    showToast(success ? 'Template berhasil disalin ke clipboard!' : 'Gagal menyalin text.', success);
                }
            }
        }

        // --- BAS Sync Action ---
        let isSyncingBas = false;
        async function triggerBasSync() {
            if (isSyncingBas) return;
            isSyncingBas = true;

            const btn = document.getElementById('btn-sync-bas');
            const icon = document.getElementById('sync-bas-icon');
            const btnText = document.getElementById('sync-bas-text');
            const badge = document.getElementById('bas-status-badge');
            const dot = document.getElementById('bas-status-dot');
            const badgeText = document.getElementById('bas-status-text');

            if (icon) icon.classList.add('animate-spin');
            if (btnText) btnText.textContent = 'Syncing...';
            if (btn) btn.disabled = true;

            showToast('Menghubungkan ke Build Approval System...', true);

            try {
                const response = await fetch('sync_bas.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                });
                const data = await response.json();

                if (data.success) {
                    showToast(data.message || 'Sinkronisasi BAS berhasil!', true);
                    
                    if (badge && dot && badgeText) {
                        badge.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold border transition-colors bg-emerald-500/15 text-emerald-400 border-emerald-500/30';
                        dot.className = 'w-2 h-2 rounded-full bg-emerald-400 animate-pulse';
                        badgeText.textContent = 'BAS: Active (Just synced)';
                    }

                    if (data.updated_count > 0) {
                        setTimeout(() => {
                            window.location.reload();
                        }, 1200);
                    }
                } else {
                    showToast(data.message || 'Gagal sinkronisasi BAS.', false);
                    if (badge && dot && badgeText && (data.message.includes('Session') || data.message.includes('kedaluwarsa') || data.message.includes('tidak ditemukan'))) {
                        badge.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold border transition-colors bg-rose-500/15 text-rose-400 border-rose-500/30';
                        dot.className = 'w-2 h-2 rounded-full bg-rose-400';
                        badgeText.textContent = 'BAS: Disconnected / Expired';
                    }
                }
            } catch (err) {
                showToast('Kesalahan jaringan: ' + err.message, false);
            } finally {
                isSyncingBas = false;
                if (icon) icon.classList.remove('animate-spin');
                if (btnText) btnText.textContent = 'Sync BAS Now';
                if (btn) btn.disabled = false;
            }
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

            const count = Math.min(30, Math.floor((w * h) / 35000));
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
                const pColor = isLight ? 'rgba(59, 130, 246, 0.25)' : 'rgba(96, 165, 250, 0.25)';
                const lColor = isLight ? 'rgba(59, 130, 246, 0.05)' : 'rgba(96, 165, 250, 0.05)';

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

        // --- Theme Sync & Init ---
        document.addEventListener('DOMContentLoaded', function () {
            renderProcessedOutput();
            renderClipboardOutput();

            // Backdrop click closing
            window.addEventListener('click', function (event) {
                const taskModal = document.getElementById('task-category-modal');
                const logModal = document.getElementById('activity-log-modal');

                if (event.target === taskModal) closeTaskModal();
                if (event.target === logModal) closeActivityLog();
            });

            // Theme toggle listener from header
            const themeBtn = document.getElementById('theme-toggle');
            if (themeBtn) {
                themeBtn.addEventListener('click', () => {
                    setTimeout(() => {
                        renderProcessedOutput();
                        renderClipboardOutput();
                    }, 50);
                });
            }
        });
    </script>
</body>
</html>