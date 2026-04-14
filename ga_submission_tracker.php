<?php
// ga_submission_tracker.php

require_once "config.php";
require_once "session.php";

$active_page = 'ga_tracker';

// --- 1. Date Calculation ---
date_default_timezone_set('Asia/Jakarta');
$today_str = date('Y-m-d');

// --- 2. Fetch and Group Tasks ---
$sql = "
    SELECT t.id, t.model_name, t.ap, t.pic_email, t.progress_status, t.notes, u.username
    FROM gba_tasks t
    LEFT JOIN users u ON t.pic_email = u.email
    WHERE t.progress_status = 'Task Baru' OR (t.progress_status = 'Test Ongoing' AND t.notes IS NOT NULL AND t.notes LIKE 'GA%')
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

        // Task Baru masuk ke List 1
        if ($task['progress_status'] === 'Task Baru') {
            if (!isset($new_tasks_grouped[$pic_email])) {
                $new_tasks_grouped[$pic_email] = ['name' => $pic_name, 'tasks' => []];
            }
            $new_tasks_grouped[$pic_email]['tasks'][] = [
                'id' => $task['id'],
                'ap' => $task['ap'] ?: 'N/A',
                'model_name' => $task['model_name']
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GBA Submission Tracker</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #020617;
            --text-primary: #e2e8f0;
            --text-secondary: #94a3b8;
            --glass-bg: rgba(15, 23, 42, .8);
            --glass-border: rgba(51, 65, 85, .6);
            --text-header: #fff;
            --text-icon: #94a3b8;
            --input-bg: rgba(30, 41, 59, .7);
            --input-border: #475569;
            --card-bg: rgba(15, 23, 42, .6);
            --card-border: rgba(51, 65, 85, .6);
            --delete-btn-hover: #f87171;
        }

        html.light {
            --bg-primary: #f1f5f9;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --glass-bg: rgba(255, 255, 255, .7);
            --glass-border: rgba(0, 0, 0, .1);
            --text-header: #0f172a;
            --text-icon: #475569;
            --input-bg: #ffffff;
            --input-border: #cbd5e1;
            --card-bg: rgba(255, 255, 255, .8);
            --card-border: rgba(0, 0, 0, .1);
            --delete-btn-hover: #dc2626;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary)
        }

        html,
        body {
            height: 100%;
        }

        #neural-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1
        }

        .glass-container {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border)
        }

        .pic-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
        }

        .list-group {
            min-height: 50px;
        }

        .list-item {
            background-color: rgba(0, 0, 0, 0.1);
            padding: 8px;
            border-radius: 6px;
            margin-bottom: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .list-item:hover {
            background-color: rgba(0, 0, 0, 0.2);
        }

        html.light .list-item {
            background-color: rgba(0, 0, 0, 0.05);
        }

        html.light .list-item:hover {
            background-color: rgba(0, 0, 0, 0.1);
        }

        .nav-link {
            color: var(--text-secondary);
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }

        .nav-link:hover {
            border-color: var(--text-secondary);
            color: var(--text-primary);
        }

        .nav-link-active {
            color: var(--text-primary) !important;
            border-bottom: 2px solid #3b82f6;
            font-weight: 600;
        }

        /* Override Navbar z-index */
        nav,
        header,
        .navbar {
            z-index: 1 !important;
            /* Set rendah tapi tidak auto agar tetap terlihat */
        }

        /* Gaya untuk Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            /* Sangat tinggi agar di atas segalanya */
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            /* Hapus scroll di background */
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            /* Flexbox centering */
            display: none;
            /* Akan di-override JS menjadi flex */
            align-items: center;
            justify-content: center;
        }

        /* Class helper untuk display flex saat modal aktif */
        .modal.active-flex {
            display: flex !important;
        }

        .modal-content {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            padding: 20px;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 85vh;
            /* Batasi tinggi modal */
            display: flex;
            flex-direction: column;
            position: relative;
        }

        /* Activity Log Container Specific */
        #activity-log-container {
            overflow-y: auto;
            flex-grow: 1;
            margin-bottom: 10px;
            padding-right: 5px;
            /* Space for scrollbar */
        }

        .modal-buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .modal-buttons button {
            padding: 12px 20px;
            font-weight: 600;
            border-radius: 8px;
            background-color: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
            transition: background-color 0.2s;
        }

        .modal-buttons button:hover {
            background-color: rgba(59, 130, 246, 0.4);
        }

        /* Gaya untuk Output Card */
        .output-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            padding: 16px;
            border-radius: 12px;
        }

        .output-list {
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 0.9rem;
            color: #a5b4fc;
        }

        .clipboard-card {
            position: relative;
            border: 1px solid #3b82f6;
        }
    </style>
</head>

<body class="h-screen flex flex-col overflow-hidden">
    <canvas id="neural-canvas"></canvas>
    <div id="toast"
        style="position: fixed; bottom: -100px; left: 50%; transform: translateX(-50%); background-color: #22c55e; color: #fff; padding: 12px 20px; border-radius: 8px; z-index: 1000; transition: bottom 0.5s ease-in-out;">
    </div>

    <?php include 'header.php'; ?>

    <main class="w-full p-4 flex-grow overflow-y-hidden">
        <div class="flex justify-between items-center mb-4 flex-shrink-0">
            <h1 class="text-3xl font-bold text-header">GBA Tracker & Reason OT</h1>
            <button onclick="openActivityLog()"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition-colors flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Activity Log
            </button>
        </div>

        <div class="overflow-y-auto flex-grow">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-3 gap-4">
                <?php if (empty($new_tasks_grouped) && empty($processed_tasks) && empty($clipboard_tasks)): ?>
                    <div class="text-center p-8 bg-gray-700/50 rounded-xl text-md text-secondary col-span-full">
                        Tidak ada task yang ditemukan.
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
                        <!-- Container untuk satu PIC (3 cards vertikal) -->
                        <div class="flex flex-col space-y-3" data-pic-email="<?= htmlspecialchars($email) ?>">

                            <!-- List 1: Daftar Task Baru -->
                            <?php if (isset($new_tasks_grouped[$email])): ?>
                                <div id="list-card-<?= $picHash ?>"
                                    class="pic-card rounded-xl p-4 shadow-lg flex flex-col space-y-2 overflow-hidden">
                                    <div
                                        class="flex items-center justify-between border-b border-[var(--card-border)] pb-2 flex-shrink-0">
                                        <div class="flex items-center gap-2">
                                            <span class="px-2 py-1 bg-blue-600 text-white text-xs font-bold rounded-full">1</span>
                                            <h3 class="text-lg font-bold text-blue-400">
                                                <?= htmlspecialchars($pic_name) ?>
                                            </h3>
                                        </div>
                                        <span class="px-2 py-1 bg-blue-600 text-white text-xs font-bold rounded-full">
                                            <?= count($new_tasks_grouped[$email]['tasks']) ?>
                                        </span>
                                    </div>
                                    <div class="overflow-y-auto flex-grow min-h-0 max-h-48">
                                        <ul class="list-group list-disc pl-0 space-y-1">
                                            <?php foreach ($new_tasks_grouped[$email]['tasks'] as $task): ?>
                                                <li class="list-item text-sm text-secondary" data-task-id="<?= $task['id'] ?>"
                                                    data-ap="<?= htmlspecialchars($task['ap']) ?>"
                                                    data-model-name="<?= htmlspecialchars($task['model_name']) ?>"
                                                    onclick="openTaskModal(<?= $task['id'] ?>, '<?= htmlspecialchars($email) ?>', '<?= htmlspecialchars($task['ap']) ?>')">
                                                    - <?= htmlspecialchars($task['ap']) ?>
                                                    (<?= htmlspecialchars($task['model_name']) ?>)
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- List 2: Template Reason OT (Diproses) - Placeholder, akan diisi oleh JS -->
                            <div id="processed-card-<?= $picHash ?>-container"></div>

                            <!-- List 3: Template Reason OT Final - Placeholder, akan diisi oleh JS -->
                            <div id="clipboard-card-<?= $picHash ?>-container"></div>

                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <div id="task-category-modal" class="modal">
        <div class="modal-content">
            <h2 class="text-xl font-bold text-header mb-4">Kategorikan Task</h2>
            <p class="text-secondary mb-4">Pilih kategori untuk AP: <span id="modal-ap-display"
                    class="font-bold text-primary"></span></p>

            <input type="hidden" id="modal-task-id">
            <input type="hidden" id="modal-pic-email">
            <input type="hidden" id="modal-ap-version">

            <div class="modal-buttons">
                <button onclick="processTaskCategory('GA Submit')">GA Submit (Target Submit: Hari Ini, Target Approved:
                    Besok)</button>
                <button onclick="processTaskCategory('GA Follow Up')">GA Follow Up (Target Submit: Hari Ini, Target
                    Approved: Besok)</button>
                <button onclick="processTaskCategory('GA Follow Up Besok')">GA Follow Up (Target Submit: Besok, Target
                    Approved: Besok)</button>
                <button onclick="processTaskCategory('GA First Run')">GA First Run (Target Submit: Besok, Target
                    Approved: Besok)</button>
            </div>

            <button onclick="closeTaskModal()"
                class="mt-6 w-full text-sm px-4 py-2 rounded-lg text-secondary border border-gray-600 hover:border-red-400 hover:text-red-400 transition-colors">Batal</button>
        </div>
    </div>

    <!-- Activity Log Modal -->
    <div id="activity-log-modal" class="modal">
        <div class="modal-content w-full max-w-4xl max-h-[80vh] flex flex-col">
            <div class="flex justify-between items-center mb-4 border-b border-gray-700 pb-2">
                <h2 class="text-xl font-bold text-header">Activity Log (Copied Items)</h2>
                <button onclick="closeActivityLog()" class="text-secondary hover:text-white text-2xl">&times;</button>
            </div>

            <div class="overflow-y-auto flex-grow pr-2" id="activity-log-container">
                <!-- Log items will be injected here -->
                <div class="text-center text-secondary py-8">Belum ada aktivitas copy.</div>
            </div>

            <div class="mt-4 pt-2 border-t border-gray-700 flex justify-end">
                <button onclick="closeActivityLog()"
                    class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg transition-colors">Close</button>
            </div>
        </div>
    </div>

    <script>
        // --- Setup Data & Constants ---
        let clipboardData = <?= $initial_clipboard_data_json ?>;
        let processedData = <?= $initial_processed_data_json ?>; // Data dari PHP untuk list 2
        let countdownTimers = [];
        let activityLog = [];

        // --- Activity Log Functions ---
        function openActivityLog() {
            fetchActivityLogs();
            document.getElementById('activity-log-modal').style.display = 'flex';
        }

        function closeActivityLog() {
            document.getElementById('activity-log-modal').style.display = 'none';
        }

        function fetchActivityLogs() {
            const container = document.getElementById('activity-log-container');
            container.innerHTML = '<div class="text-center text-secondary py-8">Loading...</div>';

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
                        container.innerHTML = '<div class="text-center text-red-400 py-8">Gagal memuat log.</div>';
                    }
                })
                .catch(err => {
                    console.error(err);
                    container.innerHTML = '<div class="text-center text-red-400 py-8">Terjadi kesalahan koneksi.</div>';
                });
        }

        function renderActivityLog(logs) {
            const container = document.getElementById('activity-log-container');
            if (!logs || logs.length === 0) {
                container.innerHTML = '<div class="text-center text-secondary py-8">Belum ada aktivitas copy.</div>';
                return;
            }

            container.innerHTML = logs.map(log => `
                <div class="bg-gray-800/50 rounded-lg p-4 mb-3 border border-gray-700 relative group">
                    <div class="flex justify-between items-start mb-2">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 bg-blue-900/50 text-blue-400 text-xs rounded border border-blue-800">${log.timestamp}</span>
                            <span class="font-bold text-primary">${log.pic_name}</span>
                        </div>
                        <button onclick="deleteActivityLog(${log.id})" class="text-gray-500 hover:text-red-400 transition-colors p-1" title="Hapus Log">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>
                    <div class="bg-gray-900 rounded p-3 font-mono text-sm text-gray-300 whitespace-pre-wrap border border-gray-800">${log.content}</div>
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
                        fetchActivityLogs(); // Refresh list
                    } else {
                        alert('Gagal menghapus log: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Terjadi kesalahan koneksi.');
                });
        }


        // Helper function for Clipboard API fallback
        function fallbackCopyToClipboard(textToCopy) {
            const textArea = document.createElement("textarea");
            textArea.value = textToCopy;

            // Pastikan textarea tidak mengganggu tampilan atau scroll
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
            // Expiry time is 20 hours (20 * 60 * 60 * 1000 milliseconds)
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
                    // Hapus kartu dari tampilan dan struktur data
                    if (clipboardData[picEmail]) {
                        // Hapus data card secara total karena sudah kedaluwarsa
                        delete clipboardData[picEmail];
                    }
                    cardElement.remove();

                    clearInterval(cardElement.timerId);
                    // Tidak perlu panggil renderClipboardOutput() lagi karena card sudah dihapus
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
            document.getElementById('task-category-modal').style.display = 'flex';
        }

        function closeTaskModal() {
            document.getElementById('task-category-modal').style.display = 'none';
        }

        // --- Core Processing Logic ---
        function processTaskCategory(category) {
            const taskId = document.getElementById('modal-task-id').value;
            const picEmail = document.getElementById('modal-pic-email').value;
            const apVersion = document.getElementById('modal-ap-version').value;

            closeTaskModal();
            showToast(`Memproses task #${taskId} ke ${category}...`, true);

            // --- CATCH PIC NAME BEFORE REMOVAL ---
            const listItem = document.querySelector(`.list-item[data-task-id="${taskId}"]`);
            const card = listItem ? listItem.closest('.pic-card') : null;
            let picName = picEmail;

            if (card) {
                picName = card.querySelector('h3').textContent.trim();
                if (picName.includes('-')) {
                    picName = picName.substring(0, picName.indexOf('-')).trim();
                } else if (picName.includes('(')) {
                    picName = picName.substring(0, picName.indexOf('(')).trim();
                }
            }
            // ------------------------------------

            // 1. Send API Call to Update Status and Notes
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
                    // 2. Determine destination based on category
                    if (category === 'GA Follow Up Besok' || category === 'GA First Run') {
                        // Add to List 2 (Processed - clickable)
                        if (!processedData[picEmail]) {
                            processedData[picEmail] = { 'name': picName, 'tasks': [] };
                        }

                        // Remove task with same AP from processedData
                        processedData[picEmail].tasks = processedData[picEmail].tasks.filter(
                            task => task.ap !== apVersion
                        );

                        // Add new task
                        processedData[picEmail].tasks.push({
                            id: taskId,
                            ap: data.ap_version,
                            model_name: data.model_name || apVersion,
                            category: category
                        });
                    } else if (category === 'GA Submit' || category === 'GA Follow Up') {
                        // Add to List 3 (Final - with timer)
                        if (!clipboardData[picEmail]) {
                            clipboardData[picEmail] = { 'name': picName, 'categories': { 'GA Submit': [], 'GA Follow Up': [] } };
                        }

                        // Remove from all categories in clipboardData
                        ['GA Submit', 'GA Follow Up'].forEach(cat => {
                            if (clipboardData[picEmail].categories[cat]) {
                                clipboardData[picEmail].categories[cat] = clipboardData[picEmail].categories[cat].filter(
                                    taskObj => !taskObj.formatted_string.includes(apVersion)
                                );
                            }
                        });

                        // Add to new category
                        if (!clipboardData[picEmail].categories[category]) {
                            clipboardData[picEmail].categories[category] = [];
                        }

                        clipboardData[picEmail].categories[category].push({
                            formatted_string: `- ${data.ap_version} [Target Submit: ${data.target_submit}] [Target Approved: ${data.target_approved}]`,
                            timestamp: data.categorized_at
                        });

                        // Remove from processedData if exists
                        if (processedData[picEmail]) {
                            processedData[picEmail].tasks = processedData[picEmail].tasks.filter(
                                task => task.ap !== apVersion
                            );
                            if (processedData[picEmail].tasks.length === 0) {
                                delete processedData[picEmail];
                            }
                        }
                    }

                    // 3. Remove from List 1 or List 2 (DOM)
                    if (listItem) {
                        const listGroup = listItem.closest('.list-group');
                        const picCard = listItem.closest('.pic-card');
                        listItem.remove();

                        if (listGroup && listGroup.children.length === 0 && picCard) {
                            picCard.remove();
                        }
                    }

                    // 4. Render both lists
                    renderProcessedOutput();
                    renderClipboardOutput();
                    showToast(`Task #${taskId} berhasil diubah status menjadi 'Test Ongoing' dan dikategorikan sebagai ${category}.`, true);
                } else {
                    showToast(`Gagal update status task #${taskId}: ${data.error}`, false);
                    console.error("API Error:", data.error);
                }
            }).catch(error => {
                showToast(`Error Jaringan saat update task #${taskId}.`, false);
                console.error("Fetch Error:", error);
            });
        }





        // --- Output Rendering for List 2 (Processed - Clickable like List 1) ---
        function renderProcessedOutput() {
            // Clear all processed containers first
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
                        <div id="processed-card-${picHash}" class="pic-card rounded-xl p-4 shadow-lg flex flex-col space-y-2 overflow-hidden border-l-4 border-amber-600">
                            <div class="flex items-center justify-between border-b border-[var(--card-border)] pb-2 flex-shrink-0">
                                <div class="flex items-center gap-2">
                                    <span class="px-2 py-1 bg-amber-600 text-white text-xs font-bold rounded-full">2</span>
                                    <h3 class="text-lg font-bold text-amber-400">
                                        ${data.name} - Diproses
                                    </h3>
                                </div>
                                <span class="px-2 py-1 bg-amber-600 text-white text-xs font-bold rounded-full">
                                    ${data.tasks.length}
                                </span>
                            </div>
                            <div class="overflow-y-auto flex-grow min-h-0 max-h-48">
                                <ul class="list-group list-disc pl-0 space-y-1">
                                    ${data.tasks.map(task => `
                                        <li class="list-item text-sm text-secondary cursor-pointer hover:text-primary transition-colors" 
                                            data-task-id="${task.id}" 
                                            data-ap="${task.ap}" 
                                            data-model-name="${task.model_name}"
                                            onclick="openTaskModal(${task.id}, '${picEmail}', '${task.ap}')">
                                            - ${task.ap} [Target Submit: ${task.target_submit}] [Target Approved: ${task.target_approved}] <span class="text-xs text-blue-400">[${task.category}]</span>
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
            // Clear all clipboard containers first
            document.querySelectorAll('[id$="-container"]').forEach(container => {
                if (container.id.includes('clipboard-card-')) {
                    container.innerHTML = '';
                }
            });

            // Clear all existing timers before redrawing
            countdownTimers.forEach(clearInterval);
            countdownTimers = [];

            // Gabungkan data dari processedData (List 2) dan clipboardData
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

                // Get PIC name from either source
                if (processedTasks) picName = processedTasks.name;
                if (clipboardTasks) picName = clipboardTasks.name;

                // 1. Prepare Data
                let gaSubmitTasks = [];
                let gaFollowUpTasks = [];
                let gaFirstRunTasks = [];

                // From clipboardData
                if (clipboardTasks && clipboardTasks.categories) {
                    if (clipboardTasks.categories['GA Submit']) {
                        gaSubmitTasks = [...clipboardTasks.categories['GA Submit']];
                    }
                    if (clipboardTasks.categories['GA Follow Up']) {
                        gaFollowUpTasks = [...clipboardTasks.categories['GA Follow Up']];
                    }
                }

                // From processedData
                if (processedTasks && processedTasks.tasks) {
                    processedTasks.tasks.forEach(task => {
                        const taskObj = {
                            formatted_string: `- ${task.ap} [Target Submit: ${task.target_submit}] [Target Approved: ${task.target_approved}]`,
                            timestamp: null // No timer for processed tasks
                        };

                        if (task.category === 'GA Follow Up Besok') {
                            gaFollowUpTasks.push(taskObj);
                        } else if (task.category === 'GA First Run') {
                            gaFirstRunTasks.push(taskObj);
                        }
                    });
                }

                // 2. Render Categories
                const categoriesToRender = [
                    { name: 'GA Submit', tasks: gaSubmitTasks },
                    { name: 'GA Follow Up', tasks: gaFollowUpTasks },
                    { name: 'GA First Run', tasks: gaFirstRunTasks }
                ];

                categoriesToRender.forEach(cat => {
                    if (cat.tasks.length > 0) {
                        outputContent += `<div class="category-group mb-4 last:mb-0" data-category="${cat.name}">\n`;
                        outputContent += `<h4 class="font-semibold text-primary mb-1">${cat.name}:</h4>\n`;
                        outputContent += `<ul class="output-list pl-4 list-none text-secondary">`;

                        cat.tasks.forEach(taskObj => {
                            // Update newestTimestamp only if timestamp exists (from clipboardData)
                            if (taskObj.timestamp) {
                                if (!newestTimestamp || new Date(taskObj.timestamp) > new Date(newestTimestamp)) {
                                    newestTimestamp = taskObj.timestamp;
                                }
                            }
                            outputContent += `<li>${taskObj.formatted_string.trim()}</li>`;
                            totalItems++;
                        });
                        outputContent += `</ul></div>\n`;
                        hasContent = true;
                    }
                });

                if (hasContent) {
                    const cardHTML = `
                        <div id="clipboard-card-${picHash}" class="output-card clipboard-card shadow-lg flex flex-col space-y-3 overflow-hidden border-l-4 border-green-600" data-pic-email="${picEmail}">
                            <div class="flex justify-between items-center border-b border-green-600 pb-2 flex-shrink-0">
                                <div class="flex items-center gap-2">
                                    <span class="px-2 py-1 bg-green-600 text-white text-xs font-bold rounded-full">3</span>
                                    <h3 class="text-lg font-bold text-green-400">${picName} - Final</h3>
                                    <span class="px-2 py-1 bg-green-600 text-white text-xs font-bold rounded-full">${totalItems}</span>
                                </div>
                                ${newestTimestamp ? `<span class="text-xs text-secondary countdown-label flex-shrink-0">Expired in: <span class="countdown-timer font-mono text-amber-400"></span></span>` : ''}
                            </div>
                            <div class="overflow-y-auto flex-grow min-h-0 max-h-64">
                                <div class="space-y-4">
                                    ${outputContent}
                                </div>
                            </div>
                            <div class="flex-shrink-0 pt-2 border-t border-green-600">
                                <button onclick="copyCardContent(this, '${picEmail}')" class="copy-btn text-xs w-full px-3 py-1 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors">
                                    Salin ke Clipboard
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

        // --- Clipboard Helper (Copy all data including List 2) ---
        function copyCardContent(button, picEmail) {
            const clipboardTasks = clipboardData[picEmail];
            const processedTasks = processedData[picEmail];

            if (!clipboardTasks && !processedTasks) return;

            let textToCopy = '';

            // 1. Prepare Data Arrays
            let gaSubmitTasks = [];
            let gaFollowUpTasks = [];
            let gaFirstRunTasks = [];

            // From clipboardData
            if (clipboardTasks && clipboardTasks.categories) {
                if (clipboardTasks.categories['GA Submit']) {
                    // Extract raw data if possible, or use formatted string but stripped
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

            // From processedData
            if (processedTasks && processedTasks.tasks) {
                processedTasks.tasks.forEach(task => {
                    // Format: - AP [Target Submit: ...] [Target Approved: ...]
                    const formattedString = `- ${task.ap} [Target Submit: ${task.target_submit}] [Target Approved: ${task.target_approved}]`;

                    if (task.category === 'GA Follow Up Besok') {
                        gaFollowUpTasks.push(formattedString);
                    } else if (task.category === 'GA First Run') {
                        gaFirstRunTasks.push(formattedString);
                    }
                });
            }

            // 2. Build Text Output
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

                // Get PIC Name for log
                let picName = picEmail;
                if (clipboardTasks && clipboardTasks.name) picName = clipboardTasks.name;
                else if (processedTasks && processedTasks.name) picName = processedTasks.name;

                // Add to Activity Log (Database)
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
                    .then(data => {
                        if (!data.success) {
                            console.error('Gagal menyimpan log:', data.error);
                        }
                    })
                    .catch(err => console.error('Error saving log:', err));

                // 1. Coba API Clipboard modern
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(finalContent)
                        .then(() => {
                            showToast('Text berhasil disalin ke clipboard!', true);
                        })
                        .catch(err => {
                            // 2. Gunakan Fallback jika API modern gagal
                            console.error('Gagal menyalin dengan API modern. Mencoba fallback:', err);
                            const success = fallbackCopyToClipboard(finalContent);
                            if (success) {
                                showToast('Text berhasil disalin ke clipboard!', true);
                            } else {
                                showToast('Gagal menyalin text.', false);
                            }
                        });
                } else {
                    // 3. Langsung gunakan Fallback jika navigator.clipboard tidak tersedia
                    const success = fallbackCopyToClipboard(finalContent);
                    if (success) {
                        showToast('Text berhasil disalin ke clipboard!', true);
                    } else {
                        showToast('Gagal menyalin text.', false);
                    }
                }
            }
        }
        // --- Utility Functions ---
        function showToast(message, isSuccess) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.style.backgroundColor = isSuccess ? '#22c55e' : '#ef4444';
            toast.classList.add('show');
            toast.style.bottom = '30px';
            setTimeout(() => {
                toast.style.bottom = '-100px';
                toast.classList.remove('show');
            }, 3000);
        }

        document.addEventListener('DOMContentLoaded', function () {
            console.log('DOMContentLoaded triggered');
            console.log('processedData:', processedData);
            console.log('clipboardData:', clipboardData);

            renderProcessedOutput();
            renderClipboardOutput();

            // Tutup modal ketika di luar area modal diklik
            // Tutup modal ketika di luar area modal diklik
            window.onclick = function (event) {
                const taskModal = document.getElementById('task-category-modal');
                const logModal = document.getElementById('activity-log-modal');

                if (event.target == taskModal) {
                    closeTaskModal();
                }
                if (event.target == logModal) {
                    closeActivityLog();
                }
            }

            // FIX: Tambahkan logika dropdown profile yang hilang
            const profileMenu = document.getElementById('profile-menu');
            if (profileMenu) {
                const profileButton = profileMenu.querySelector('button');
                const profileDropdown = document.getElementById('profile-dropdown');
                profileButton.addEventListener('click', e => {
                    e.stopPropagation();
                    profileDropdown.classList.toggle('hidden');
                });
                document.addEventListener('click', e => {
                    if (!profileMenu.contains(e.target)) {
                        profileDropdown.classList.add('hidden');
                    }
                });
            }
        });
    </script>
</body>

</html>