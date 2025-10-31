<?php
// ga_submission_tracker.php

require_once "config.php";
require_once "session.php";

$active_page = 'ga_tracker';

// --- 1. Date Calculation ---
date_default_timezone_set('Asia/Jakarta');
$today_str = date('Y-m-d');

// --- 2. Fetch and Group Tasks (Both 'Task Baru' and 'Test Ongoing' with notes) ---
$sql = "
    SELECT t.id, t.model_name, t.ap, t.pic_email, t.progress_status, t.notes, u.username
    FROM gba_tasks t
    LEFT JOIN users u ON t.pic_email = u.email
    WHERE t.progress_status = 'Task Baru' OR (t.progress_status = 'Test Ongoing' AND t.notes IS NOT NULL AND t.notes LIKE 'GA%')
    ORDER BY t.pic_email, t.model_name
";

$tasks_result = $conn->query($sql);
$new_tasks_grouped = [];
$clipboard_tasks = [];
$cutoff_time = (new DateTime())->modify('-20 hours'); // Batas waktu 20 jam (PHP hanya filter saat load)

if ($tasks_result) {
    while ($task = $tasks_result->fetch_assoc()) {
        $pic_email = $task['pic_email'];
        $pic_name = $task['username'] ?? strtok($pic_email, '@');
        
        if ($task['progress_status'] === 'Task Baru') {
            // Grouping for the first list (Task Baru)
            if (!isset($new_tasks_grouped[$pic_email])) {
                $new_tasks_grouped[$pic_email] = ['name' => $pic_name, 'tasks' => []];
            }
            $new_tasks_grouped[$pic_email]['tasks'][] = [
                'id' => $task['id'],
                'ap' => $task['ap'] ?: 'N/A',
                'model_name' => $task['model_name']
            ];
        } elseif ($task['progress_status'] === 'Test Ongoing' && !empty($task['notes'])) {
            // Grouping for the second list (Already Ongoing with tracker info in notes)
            // Parse notes format: "GA Category: - AP [Target Submit: DD-MM] [Target Approved: DD-MM] [CATEGORIZED_AT: Y-m-d H:i:s]"
            if (preg_match('/(GA\s.+):\s-\s(.+)\s\[Target Submit:\s(.+)\]\s\[Target Approved:\s(.+)\]\s\[CATEGORIZED_AT:\s(.+)\]/', $task['notes'], $matches)) {
                
                $category = trim($matches[1]);
                $ap_version = trim($matches[2]);
                $target_submit = trim($matches[3]);
                $target_approved = trim($matches[4]);
                $categorized_at_str = trim($matches[5]);
                
                $categorized_at = DateTime::createFromFormat('Y-m-d H:i:s', $categorized_at_str);
                
                // --- 20-HOUR EXPIRY CHECK (Filter tasks older than 20 hours in PHP) ---
                if ($categorized_at && $categorized_at > $cutoff_time) {
                    if (!isset($clipboard_tasks[$pic_email])) {
                        $clipboard_tasks[$pic_email] = ['name' => $pic_name, 'categories' => [
                            // Inisialisasi semua kategori termasuk yang baru untuk konsistensi
                            'GA Submit' => [], 'GA Follow Up' => [], 'GA Follow Up Besok' => [], 'GA First Run' => [] 
                        ]];
                    }
                    
                    // Store as associative array so JS can read the timestamp
                    $clipboard_tasks[$pic_email]['categories'][$category][] = [
                        'formatted_string' => "- {$ap_version} [Target Submit: {$target_submit}] [Target Approved: {$target_approved}]",
                        'timestamp' => $categorized_at_str // Pass timestamp to JS
                    ];
                }
            }
        }
    }
}

// Clean up empty categories from clipboard_tasks before JSON encoding
foreach ($clipboard_tasks as $email => &$data) {
    $data['categories'] = array_filter($data['categories']);
    // NOTE: Tidak menghapus 'GA Follow Up Besok' jika kosong di sini. Biarkan JS yang mengurus penggabungan.
    if (empty($data['categories']) && !isset($data['categories']['GA Follow Up']) && !isset($data['categories']['GA Follow Up Besok'])) {
        unset($clipboard_tasks[$email]);
    }
}
unset($data);

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
        :root{--bg-primary:#020617;--text-primary:#e2e8f0;--text-secondary:#94a3b8;--glass-bg:rgba(15,23,42,.8);--glass-border:rgba(51,65,85,.6);--text-header:#fff;--text-icon:#94a3b8;--input-bg:rgba(30,41,59,.7);--input-border:#475569;--card-bg:rgba(15,23,42,.6);--card-border:rgba(51,65,85,.6);--delete-btn-hover:#f87171;}
        html.light{--bg-primary:#f1f5f9;--text-primary:#0f172a;--text-secondary:#475569;--glass-bg:rgba(255,255,255,.7);--glass-border:rgba(0,0,0,.1);--text-header:#0f172a;--text-icon:#475569;--input-bg:#ffffff;--input-border:#cbd5e1;--card-bg:rgba(255,255,255,.8);--card-border:rgba(0,0,0,.1);--delete-btn-hover:#dc2626;}
        
        body{font-family:'Inter',sans-serif;background-color:var(--bg-primary);color:var(--text-primary)}
        html, body { height: 100%; } 
        #neural-canvas{position:fixed;top:0;left:0;width:100%;height:100%;z-index:-1}
        .glass-container{background:var(--glass-bg);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border:1px solid var(--glass-border)}
        .pic-card { background: var(--card-bg); border: 1px solid var(--card-border); }
        .list-group { min-height: 50px; }
        .list-item { background-color: rgba(0,0,0,0.1); padding: 8px; border-radius: 6px; margin-bottom: 4px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: background-color 0.2s; }
        .list-item:hover { background-color: rgba(0,0,0,0.2); }
        html.light .list-item { background-color: rgba(0,0,0,0.05); }
        html.light .list-item:hover { background-color: rgba(0,0,0,0.1); }
        .nav-link { color: var(--text-secondary); border-bottom: 2px solid transparent; transition: all 0.2s; } .nav-link:hover { border-color: var(--text-secondary); color: var(--text-primary); }
        .nav-link-active { color: var(--text-primary) !important; border-bottom: 2px solid #3b82f6; font-weight: 600; }
        
        /* Gaya untuk Modal */
        .modal { display: none; position: fixed; z-index: 100; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.7); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); }
        .modal-content { background: var(--card-bg); border: 1px solid var(--card-border); margin: 15% auto; padding: 20px; border-radius: 12px; width: 90%; max-width: 500px; }
        .modal-buttons { display: flex; flex-direction: column; gap: 12px; }
        .modal-buttons button { padding: 12px 20px; font-weight: 600; border-radius: 8px; background-color: rgba(59, 130, 246, 0.2); color: #3b82f6; transition: background-color 0.2s; }
        .modal-buttons button:hover { background-color: rgba(59, 130, 246, 0.4); }

        /* Gaya untuk Output Card */
        .output-card { background: var(--card-bg); border: 1px solid var(--card-border); padding: 16px; border-radius: 12px; }
        .output-list { white-space: pre-wrap; font-family: monospace; font-size: 0.9rem; color: #a5b4fc; }
        .clipboard-card { position: relative; border: 1px solid #3b82f6; }
    </style>
</head>
<body class="h-screen flex flex-col overflow-hidden">
    <canvas id="neural-canvas"></canvas>
    <div id="toast" style="position: fixed; bottom: -100px; left: 50%; transform: translateX(-50%); background-color: #22c55e; color: #fff; padding: 12px 20px; border-radius: 8px; z-index: 1000; transition: bottom 0.5s ease-in-out;"></div>
    
    <?php include 'header.php'; ?>

    <main class="w-full p-4 flex-grow overflow-y-hidden">
        <h1 class="text-3xl font-bold mb-4 text-header flex-shrink-0">GBA Tracker & Reason OT</h1>
        
        <div class="flex flex-col h-[calc(100%-4rem)] space-y-4"> 

            <div class="flex flex-col flex-1 min-h-0">
                <h2 class="text-xl font-bold text-header mb-2 flex-shrink-0">1. Daftar Task Baru (Klik untuk Kategorikan)</h2>
                <div id="new-task-container" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-3 gap-3 overflow-y-auto flex-grow">
                    <?php if (empty($new_tasks_grouped)): ?>
                        <div class="text-center p-8 bg-gray-700/50 rounded-xl text-md text-secondary col-span-full">
                            Tidak ada task dengan status "Task Baru" yang ditemukan.
                        </div>
                    <?php else: ?>
                        <?php foreach ($new_tasks_grouped as $email => $data): ?>
                            <div id="list-card-<?= hash('md5', $email) ?>" class="pic-card rounded-xl p-4 shadow-lg flex flex-col space-y-2 overflow-hidden" data-pic-email="<?= htmlspecialchars($email) ?>">
                                <h3 class="text-lg font-bold text-blue-400 border-b border-[var(--card-border)] pb-2 flex-shrink-0"><?= htmlspecialchars($data['name']) ?></h3>
                                <div class="overflow-y-auto flex-grow min-h-0">
                                    <ul class="list-group list-disc pl-0 space-y-1">
                                        <?php foreach ($data['tasks'] as $task): ?>
                                            <li class="list-item text-sm text-secondary" 
                                                data-task-id="<?= $task['id'] ?>" 
                                                data-ap="<?= htmlspecialchars($task['ap']) ?>" 
                                                data-model-name="<?= htmlspecialchars($task['model_name']) ?>"
                                                onclick="openTaskModal(<?= $task['id'] ?>, '<?= htmlspecialchars($email) ?>', '<?= htmlspecialchars($task['ap']) ?>')">
                                                - <?= htmlspecialchars($task['ap']) ?> (<?= htmlspecialchars($task['model_name']) ?>)
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex flex-col flex-1 min-h-0">
                <h2 class="text-xl font-bold text-header mb-2 flex-shrink-0">2. Template Reason OT</h2>
                <div id="clipboard-output-container" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-3 gap-3 overflow-y-auto flex-grow">
                    </div>
            </div>
        </div>
    </main>

    <div id="task-category-modal" class="modal">
        <div class="modal-content">
            <h2 class="text-xl font-bold text-header mb-4">Kategorikan Task</h2>
            <p class="text-secondary mb-4">Pilih kategori untuk AP: <span id="modal-ap-display" class="font-bold text-primary"></span></p>
            
            <input type="hidden" id="modal-task-id">
            <input type="hidden" id="modal-pic-email">
            <input type="hidden" id="modal-ap-version">

            <div class="modal-buttons">
                <button onclick="processTaskCategory('GA Submit')">GA Submit (Target Submit: Hari Ini, Target Approved: Besok)</button>
                <button onclick="processTaskCategory('GA Follow Up')">GA Follow Up (Target Submit: Hari Ini, Target Approved: Besok)</button>
                <button onclick="processTaskCategory('GA Follow Up Besok')">GA Follow Up (Target Submit: Besok, Target Approved: Besok)</button>
                <button onclick="processTaskCategory('GA First Run')">GA First Run (Target Submit: Besok, Target Approved: Besok)</button>
            </div>
            
            <button onclick="closeTaskModal()" class="mt-6 w-full text-sm px-4 py-2 rounded-lg text-secondary border border-gray-600 hover:border-red-400 hover:text-red-400 transition-colors">Batal</button>
        </div>
    </div>

    <script>
        // --- Setup Data & Constants ---
        let clipboardData = <?= $initial_clipboard_data_json ?>;
        let countdownTimers = []; 

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
            document.getElementById('task-category-modal').style.display = 'block';
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
                    // 2. Update Clipboard Data Structure (UI side)
                    if (!clipboardData[picEmail]) {
                        clipboardData[picEmail] = { 'name': picName, 'categories': {} };
                    }
                    if (!clipboardData[picEmail].categories[category]) {
                        clipboardData[picEmail].categories[category] = [];
                    }
                    
                    const newFormattedTaskData = {
                        formatted_string: `- ${data.ap_version} [Target Submit: ${data.target_submit}] [Target Approved: ${data.target_approved}]`,
                        timestamp: data.categorized_at 
                    };
                    
                    // Mencegah duplikasi
                    const isDuplicate = clipboardData[picEmail].categories[category].some(s => s.formatted_string.includes(apVersion));
                    
                    if (!isDuplicate) {
                        clipboardData[picEmail].categories[category].push(newFormattedTaskData);
                    }
                    
                    // 3. Update UI & Remove from Initial List
                    if (listItem) {
                        const listGroup = listItem.closest('.list-group');
                        const picCard = listItem.closest('.pic-card');
                        listItem.remove();
                        
                        if (listGroup.children.length === 0 && picCard) {
                            const remainingUl = picCard.querySelector('.list-group');
                            if (!remainingUl) {
                                picCard.remove(); 
                            }
                        }
                    }

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
        
        // --- Output Rendering ---
        function renderClipboardOutput() {
            const container = document.getElementById('clipboard-output-container');
            // Clear all existing timers before redrawing
            countdownTimers.forEach(clearInterval);
            countdownTimers = [];
            container.innerHTML = ''; 

            for (const picEmail in clipboardData) {
                const data = clipboardData[picEmail];
                const picHash = btoa(picEmail).replace(/=/g, ''); 
                
                let outputContent = '';
                let hasContent = false;
                let newestTimestamp = null; // Track newest time for countdown

                // 1. Definisikan Kategori yang Digabungkan
                let groupedCategories = {
                    'GA Submit': data.categories['GA Submit'] || [],
                    // GABUNGKAN: GA Follow Up (Hari Ini) + GA Follow Up Besok
                    'GA Follow Up': [
                        ...(data.categories['GA Follow Up'] || []),
                        ...(data.categories['GA Follow Up Besok'] || [])
                    ],
                    'GA First Run': data.categories['GA First Run'] || []
                };

                // 2. Definisikan Urutan Tampilan Akhir (tanpa 'GA Follow Up Besok')
                const categoriesOrder = ['GA Submit', 'GA Follow Up', 'GA First Run'];

                categoriesOrder.forEach(category => {
                    const tasks = groupedCategories[category];
                    
                    // Hanya tampilkan jika grup gabungan/tunggal memiliki item
                    if (tasks && tasks.length > 0) {
                        outputContent += `<div class="category-group mb-4 last:mb-0" data-category="${category}">\n`;
                        outputContent += `<h4 class="font-semibold text-primary mb-1">${category}:</h4>\n`;
                        outputContent += `<ul class="output-list pl-4 list-none text-secondary">`;
                        
                        tasks.forEach(taskObj => {
                            // Update newest timestamp (must check all tasks, including merged ones)
                            const currentTimestamp = taskObj.timestamp;
                            if (!newestTimestamp || new Date(currentTimestamp) > new Date(newestTimestamp)) {
                                newestTimestamp = currentTimestamp;
                            }
                            
                            outputContent += `<li>${taskObj.formatted_string.trim()}</li>`; 
                        });
                        outputContent += `</ul></div>\n`;
                        hasContent = true;
                    }
                });

                if (hasContent) {
                     const cardHTML = `
                        <div id="clipboard-card-${picHash}" class="output-card clipboard-card shadow-lg flex flex-col space-y-3 overflow-hidden" data-pic-email="${picEmail}">
                            <div class="flex justify-between items-center border-b border-blue-600 pb-2 flex-shrink-0">
                                <h3 class="text-lg font-bold text-blue-400">${data.name} - Summary</h3>
                                <span class="text-xs text-secondary countdown-label flex-shrink-0">Expired in: <span class="countdown-timer font-mono text-amber-400"></span></span>
                            </div>
                            <div class="overflow-y-auto flex-grow min-h-0">
                                <div class="space-y-4">
                                    ${outputContent}
                                </div>
                            </div>
                            <div class="flex-shrink-0 pt-2 border-t border-blue-600">
                                <button onclick="copyCardContent(this, '${picEmail}')" class="copy-btn text-xs w-full px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                                    Salin ke Clipboard
                                </button>
                            </div>
                        </div>
                    `;
                    container.insertAdjacentHTML('beforeend', cardHTML);
                    
                    const newCardElement = document.getElementById(`clipboard-card-${picHash}`);
                    startCountdown(newCardElement, newestTimestamp);
                }
            }
        }
        
        // --- Clipboard Helper ---
        function copyCardContent(button, picEmail) {
            const data = clipboardData[picEmail];
            if (!data) return;
            
            let textToCopy = '';
            
            // 1. Definisikan Kategori yang Digabungkan
            let groupedCategories = {
                'GA Submit': data.categories['GA Submit'] || [],
                // GABUNGKAN: GA Follow Up (Hari Ini) + GA Follow Up Besok
                'GA Follow Up': [
                    ...(data.categories['GA Follow Up'] || []),
                    ...(data.categories['GA Follow Up Besok'] || [])
                ],
                'GA First Run': data.categories['GA First Run'] || []
            };
            
            const categoriesOrder = ['GA Submit', 'GA Follow Up', 'GA First Run'];

            categoriesOrder.forEach(category => {
                const tasks = groupedCategories[category];
                if (tasks && tasks.length > 0) {
                    textToCopy += `${category}:\n`;
                    tasks.forEach(taskObj => {
                        textToCopy += ` ${taskObj.formatted_string}\n`; 
                    });
                }
            });
            
            if (textToCopy) {
                const finalContent = textToCopy.trim();
                
                // 1. Coba API Clipboard modern
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(finalContent)
                        .then(() => {
                            showToast('Text berhasil disalin ke clipboard!', true);
                        })
                        .catch(err => {
                            // 2. Gunakan Fallback jika API modern gagal
                            console.error('Gagal menyalin dengan API modern. Mencoba fallback:', err);
                            if (fallbackCopyToClipboard(finalContent)) {
                                showToast('Text berhasil disalin ke clipboard! (Fallback)', true);
                            } else {
                                showToast('Gagal menyalin text. Browser Anda tidak mendukung penyalinan otomatis.', false);
                            }
                        });
                } else {
                    // 3. Langsung gunakan Fallback jika navigator.clipboard tidak tersedia
                    if (fallbackCopyToClipboard(finalContent)) {
                        showToast('Text berhasil disalin ke clipboard! (Fallback)', true);
                    } else {
                        showToast('Gagal menyalin text. Browser Anda tidak mendukung penyalinan otomatis.', false);
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
            renderClipboardOutput();
            
            // Tutup modal ketika di luar area modal diklik
            window.onclick = function(event) {
                const modal = document.getElementById('task-category-modal');
                if (event.target == modal) {
                    closeTaskModal();
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