<?php
include 'config.php';
$tasks_result = $conn->query("SELECT * FROM gba_tasks ORDER BY request_date DESC");
$tasks = [];
// Definisikan total item untuk setiap test plan
$test_plan_items = [
    'Regular Variant' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
    'SKU' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
    'Normal MR' => ['CTS', 'GTS', 'CTS-Verifier', 'ATM'],
    'SMR' => ['CTS', 'GTS', 'STS', 'SCAT'],
    'Simple Exception MR' => ['STS']
];

while ($row = $tasks_result->fetch_assoc()) {
    // Menghitung status ontime
    $request_date = new DateTime($row['request_date']);
    $submission_date = $row['submission_date'] ? new DateTime($row['submission_date']) : null;
    $sign_off_date = $row['sign_off_date'] ? new DateTime($row['sign_off_date']) : null;
    
    // Ontime Submission
    if ($submission_date) {
        $submission_diff = $submission_date->diff($request_date)->days;
        $row['ontime_submission_status'] = $submission_diff <= 5 ? 'Ontime' : 'Delay';
    } else {
        $row['ontime_submission_status'] = '-';
    }
    
    // Ontime Approved
    if ($sign_off_date && $submission_date) {
        $approval_diff = $sign_off_date->diff($submission_date)->days;
         $row['ontime_approved_status'] = $approval_diff <= 3 ? 'Ontime' : 'Delay';
    } else {
        $row['ontime_approved_status'] = '-';
    }

    // Menghitung progress bar
    $checklist = json_decode($row['test_items_checklist'], true);
    $plan_type = $row['test_plan_type'];
    $total_items = isset($test_plan_items[$plan_type]) ? count($test_plan_items[$plan_type]) : 0;
    $completed_items = 0;
    if ($total_items > 0 && is_array($checklist)) {
        foreach ($test_plan_items[$plan_type] as $item) {
            // Ganti spasi dengan underscore agar cocok dengan nama field
            $item_key = str_replace([' ', '-'], '_', $item);
            if (!empty($checklist[$item_key]) && $checklist[$item_key]) {
                $completed_items++;
            }
        }
    }
    $row['progress_percentage'] = $total_items > 0 ? ($completed_items / $total_items) * 100 : 0;

    $tasks[] = $row;
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
    <!-- Rich Text Editor (Quill) -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <style>
        :root {
            --bg-primary: #020617; --text-primary: #e2e8f0; --text-secondary: #94a3b8; --glass-bg: rgba(15, 23, 42, 0.6); --glass-border: rgba(51, 65, 85, 0.6); --text-header: #ffffff; --text-icon: #94a3b8; --input-bg: rgba(30, 41, 59, 0.7); --input-border: #475569; --progress-bg: #374151; --progress-fill: #3b82f6; --toast-bg: #22c55e; --toast-text: #ffffff;
        }
        html.light {
            --bg-primary: #f1f5f9; --text-primary: #0f172a; --text-secondary: #475569; --glass-bg: rgba(255, 255, 255, 0.6); --glass-border: rgba(0, 0, 0, 0.1); --text-header: #0f172a; --text-icon: #475569; --input-bg: #ffffff; --input-border: #cbd5e1; --progress-bg: #e5e7eb; --progress-fill: #3b82f6; --toast-bg: #16a34a; --toast-text: #ffffff;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); transition: background-color 0.3s, color 0.3s; }
        #neural-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; }
        .glass-container { background: var(--glass-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border-bottom: 1px solid var(--glass-border); }
        .glassmorphism { background: var(--glass-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid var(--glass-border); }
        .themed-text { color: var(--text-primary); }
        .themed-text-muted { color: var(--text-secondary); }
        .text-header { color: var(--text-header); }
        .text-icon { color: var(--text-icon); }
        .nav-link { color: var(--text-secondary); transition: color 0.2s, border-color 0.2s; border-bottom: 2px solid transparent; }
        .nav-link:hover { color: var(--text-primary); }
        .nav-link-active { color: var(--text-primary) !important; font-weight: 500; border-bottom: 2px solid #3b82f6; }
        .themed-input { background-color: var(--input-bg); border: 1px solid var(--input-border); color: var(--text-primary); }
        .themed-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.5); }
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); }
        html.light input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(0); }
        .ql-toolbar, .ql-container { border-color: var(--glass-border) !important; }
        .ql-editor { color: var(--text-primary); min-height: 100px; }
        .ql-snow .ql-stroke { stroke: var(--text-icon); }
        .ql-snow .ql-picker-label { color: var(--text-icon); }
        .progress-bar-bg { background-color: var(--progress-bg); }
        .progress-bar-fill { 
            background-color: var(--progress-fill);
            transition: width 0.6s ease-in-out;
            background-image: linear-gradient(45deg, rgba(255, 255, 255, .15) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, .15) 50%, rgba(255, 255, 255, .15) 75%, transparent 75%, transparent);
            background-size: 1rem 1rem;
            animation: progress-bar-stripes 1s linear infinite;
        }
        @keyframes progress-bar-stripes {
            from { background-position: 1rem 0; }
            to { background-position: 0 0; }
        }
        .progress-text {
            color: #ffffff;
            mix-blend-mode: difference;
        }
        #toast { position: fixed; bottom: -100px; left: 50%; transform: translateX(-50%); background-color: var(--toast-bg); color: var(--toast-text); padding: 12px 20px; border-radius: 8px; z-index: 1000; transition: bottom 0.5s ease-in-out; }
        #toast.show { bottom: 30px; }
    </style>
</head>
<body class="min-h-screen">
    <canvas id="neural-canvas"></canvas>
    <div id="toast">Link QB berhasil disalin!</div>

    <!-- Header Aplikasi -->
    <header class="glass-container sticky top-0 z-10 shadow-sm">
        <div class="w-full mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center space-x-3">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-blue-600"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" /></svg>
                    <h1 class="text-xl font-bold text-header">Software Project Manager</h1>
                     <div class="flex items-baseline space-x-4 ml-4">
                        <a href="index.php" class="nav-link px-3 py-2 rounded-md text-sm font-medium">Project Dashboard</a>
                        <a href="gba_dashboard.php" class="nav-link px-3 py-2 rounded-md text-sm font-medium">GBA Dashboard</a>
                        <a href="gba_tasks.php" class="nav-link-active px-3 py-2 rounded-md text-sm font-medium">GBA Tasks</a>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                     <button id="theme-toggle" type="button" class="text-icon hover:bg-gray-500/10 rounded-lg text-sm p-2.5 transition-colors duration-200">
                        <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path></svg>
                        <svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path></svg>
                    </button>
                     <button onclick="openAddModal()" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500">
                        <svg class="-ml-0.5 mr-1.5 h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>
                        Tambah Task
                    </button>
                </div>
            </div>
        </div>
    </header>

    <main class="pt-24 pb-8">
        <div class="max-w-screen-2xl mx-auto p-4 md:p-6 space-y-6">
             <!-- Filter and Search Section -->
            <div class="w-full mx-auto">
                <div class="flex flex-col sm:flex-row gap-4">
                    <div class="relative flex-grow">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                           <svg class="h-5 w-5 text-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" /></svg>
                        </div>
                        <input type="search" id="filter-input" placeholder="Cari model, PIC, status..." class="themed-input block w-full rounded-lg py-2 pl-10 pr-3 focus:ring-2">
                    </div>
                     <div class="flex items-center gap-2">
                        <span class="text-sm themed-text-secondary">Baris:</span>
                        <select id="pagination-rows" class="themed-input p-2 rounded-lg text-sm bg-transparent">
                            <option value="5">5</option><option value="10" selected>10</option><option value="30">30</option><option value="50">50</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Tabel Task -->
            <div class="overflow-x-auto glassmorphism rounded-lg">
                <table class="w-full text-sm text-left">
                    <thead class="themed-bg">
                        <tr class="border-b themed-border">
                            <th class="p-3">Model & Build</th>
                            <th class="p-3">PIC</th>
                            <th class="p-3">Test Plan</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">Progress</th>
                            <th class="p-3">Tanggal</th>
                            <th class="p-3">Kinerja</th>
                            <th class="p-3">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="task-table-body">
                        <?php if (empty($tasks)): ?>
                            <tr><td colspan="8" class="text-center p-4 themed-text-muted">Tidak ada task yang ditemukan.</td></tr>
                        <?php else: ?>
                            <?php foreach ($tasks as $task): ?>
                            <tr class="themed-border border-b hover:bg-white/5">
                                <td class="p-3">
                                    <div class="font-medium themed-text"><?= htmlspecialchars($task['model_name']) ?></div>
                                    <div class="text-xs themed-text-muted font-mono space-y-0.5">
                                        <div>AP: <?= htmlspecialchars($task['ap'] ?: '-') ?></div>
                                        <div>CP: <?= htmlspecialchars($task['cp'] ?: '-') ?></div>
                                        <div>CSC: <?= htmlspecialchars($task['csc'] ?: '-') ?></div>
                                    </div>
                                </td>
                                <td class="p-3"><?= htmlspecialchars($task['pic_email']) ?></td>
                                <td class="p-3"><?= htmlspecialchars($task['test_plan_type']) ?></td>
                                <td class="p-3">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full
                                        <?= ($task['progress_status'] == 'Approved' || $task['progress_status'] == 'Passed') ? 'bg-green-500/30 text-green-300' : '' ?>
                                        <?= ($task['progress_status'] == 'Submitted') ? 'bg-purple-500/30 text-purple-300' : '' ?>
                                        <?= ($task['progress_status'] == 'Test Ongoing') ? 'bg-yellow-500/30 text-yellow-300' : '' ?>
                                        <?= ($task['progress_status'] == 'Task Baru') ? 'bg-blue-500/30 text-blue-300' : '' ?>
                                        <?= ($task['progress_status'] == 'Batal') ? 'bg-gray-500/30 text-gray-300' : '' ?>
                                        <?= ($task['progress_status'] == 'Pending Feedback' || $task['progress_status'] == 'Feedback Sent') ? 'bg-orange-500/30 text-orange-300' : '' ?>
                                    "><?= htmlspecialchars($task['progress_status']) ?></span>
                                </td>
                                <td class="p-3">
                                    <div class="w-28">
                                        <div class="progress-bar-bg w-full rounded-full h-4 relative flex items-center justify-center">
                                            <div class="progress-bar-fill h-4 rounded-full absolute top-0 left-0" style="width: <?= $task['progress_percentage'] ?>%;"></div>
                                            <span class="relative text-xs font-bold z-10 progress-text"><?= round($task['progress_percentage']) ?>%</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-3 text-xs">
                                    <div>Req: <?= date('d M Y', strtotime($task['request_date'])) ?></div>
                                    <div>Sub: <?= $task['submission_date'] ? date('d M Y', strtotime($task['submission_date'])) : '-' ?></div>
                                    <div class="font-bold">Deadline: <?= $task['deadline'] ? date('d M Y', strtotime($task['deadline'])) : '-' ?></div>
                                </td>
                                <td class="p-3 text-xs">
                                     <div>Sub: <span class="<?= $task['ontime_submission_status'] == 'Delay' ? 'text-red-400' : 'text-green-400' ?> font-semibold"><?= $task['ontime_submission_status'] ?></span></div>
                                     <div>Appr: <span class="<?= $task['ontime_approved_status'] == 'Delay' ? 'text-red-400' : 'text-green-400' ?> font-semibold"><?= $task['ontime_approved_status'] ?></span></div>
                                </td>
                                <td class="p-3">
                                    <button onclick='openEditModal(<?= json_encode($task, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)' class="p-1 rounded hover:bg-gray-600/50">
                                         <svg class="w-4 h-4 text-icon" fill="currentColor" viewBox="0 0 20 20"><path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z"></path><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd"></path></svg>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Navigasi Halaman -->
            <div id="pagination-nav" class="flex justify-center items-center gap-2 mt-4 themed-text-muted"></div>
        </div>
    </main>

    <!-- Modal Task -->
    <div id="task-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-70 hidden">
        <div class="glassmorphism rounded-lg shadow-xl p-6 w-full max-w-4xl mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
            <div class="flex justify-between items-center mb-4">
                <h2 id="modal-title" class="text-2xl font-bold themed-text">Tambah Task Baru</h2>
                <button onclick="closeModal()" class="themed-text-muted hover:themed-text text-3xl font-bold">&times;</button>
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
        // --- ANIMATION LOGIC ---
        const canvas = document.getElementById('neural-canvas');
        const ctx = canvas.getContext('2d');
        let particles = []; let hue = 0;
        function setCanvasSize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
        setCanvasSize();
        class Particle { constructor(x, y) { this.x = x || Math.random() * canvas.width; this.y = y || Math.random() * canvas.height; this.vx = (Math.random() - 0.5) * 0.5; this.vy = (Math.random() - 0.5) * 0.5; this.size = Math.random() * 1.5 + 1; } update() { this.x += this.vx; this.y += this.vy; if (this.x < 0 || this.x > canvas.width) this.vx *= -1; if (this.y < 0 || this.y > canvas.height) this.vy *= -1; } draw() { ctx.fillStyle = `hsl(${hue}, 100%, 70%)`; ctx.beginPath(); ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2); ctx.fill(); } }
        function init(num) { particles = []; for (let i = 0; i < num; i++) { particles.push(new Particle()); } }
        function handleParticles() { for (let i = 0; i < particles.length; i++) { particles[i].update(); particles[i].draw(); for (let j = i; j < particles.length; j++) { const dx = particles[i].x - particles[j].x; const dy = particles[i].y - particles[j].y; const distance = Math.sqrt(dx * dx + dy * dy); if (distance < 100) { ctx.beginPath(); ctx.strokeStyle = `hsla(${hue}, 100%, 70%, ${1 - distance / 100})`; ctx.lineWidth = 0.5; ctx.moveTo(particles[i].x, particles[i].y); ctx.lineTo(particles[j].x, particles[j].y); ctx.stroke(); ctx.closePath(); } } } }
        function animate() { ctx.clearRect(0, 0, canvas.width, canvas.height); hue = (hue + 0.5) % 360; ctx.shadowColor = `hsl(${hue}, 100%, 50%)`; ctx.shadowBlur = 10; handleParticles(); requestAnimationFrame(animate); }
        init(window.innerWidth > 768 ? 100 : 50); animate();
        window.addEventListener('resize', () => { setCanvasSize(); init(window.innerWidth > 768 ? 100 : 50); });

        // --- THEME LOGIC ---
        const themeToggleBtn = document.getElementById('theme-toggle');
        const darkIcon = document.getElementById('theme-toggle-dark-icon');
        const lightIcon = document.getElementById('theme-toggle-light-icon');
        function applyTheme(isLight) { if (isLight) { document.documentElement.classList.add('light'); } else { document.documentElement.classList.remove('light'); } if(isLight){lightIcon.classList.remove('hidden'); darkIcon.classList.add('hidden');}else{darkIcon.classList.remove('hidden'); lightIcon.classList.add('hidden');} }
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme) { applyTheme(savedTheme === 'light'); } else { applyTheme(window.matchMedia('(prefers-color-scheme: dark)').matches ? false : true); }
        themeToggleBtn.addEventListener('click', () => { const isCurrentlyLight = document.documentElement.classList.contains('light'); localStorage.setItem('theme', isCurrentlyLight ? 'dark' : 'light'); applyTheme(!isCurrentlyLight); });
        
        const modal = document.getElementById('task-modal');
        const modalTitle = document.getElementById('modal-title');
        const taskForm = document.getElementById('task-form');
        const formAction = document.getElementById('form-action');
        const taskId = document.getElementById('task-id');
        let quill;
        
        // --- MODAL & FORM LOGIC ---
        function openAddModal() { taskForm.reset(); modalTitle.innerText = 'Tambah Task Baru'; formAction.value = 'create_gba_task'; taskId.value = ''; setupQuill(''); updateChecklistVisibility(); modal.classList.remove('hidden'); }
        function openEditModal(task) { 
            taskForm.reset(); 
            modalTitle.innerText = 'Edit Task'; 
            formAction.value = 'update_gba_task'; 
            // Populate all form fields
            for (const key in task) {
                if (taskForm.elements[key]) {
                    taskForm.elements[key].value = task[key];
                }
            }
            setupQuill(task.notes || ''); 
            updateChecklistVisibility(); 
            // Handle checklist
            if (task.test_items_checklist) { 
                try { 
                    const checklist = JSON.parse(task.test_items_checklist); 
                    for(const itemName in checklist) { 
                        // The key from DB is 'CTS_SKU', but form name is 'checklist[CTS_SKU]'
                        const checkbox = taskForm.elements[`checklist[${itemName}]`]; 
                        if (checkbox) { 
                            checkbox.checked = checklist[itemName]; 
                        } 
                    } 
                } catch(e) { console.error("Could not parse checklist JSON:", e); } 
            } 
            modal.classList.remove('hidden'); 
        }
        function closeModal() { modal.classList.add('hidden'); }
        window.onclick = function(event) { if (event.target == modal) closeModal(); }
        document.getElementById('test_plan_type').addEventListener('change', updateChecklistVisibility);
        
        function setupQuill(content) { if (!quill) { quill = new Quill('#notes-editor', { theme: 'snow', modules: { toolbar: [ ['bold', 'italic', 'underline'], ['link'], [{ 'list': 'ordered'}, { 'list': 'bullet' }] ] }}); } quill.root.innerHTML = content; }
        
        taskForm.addEventListener('submit', function() { document.getElementById('notes-hidden-input').value = quill.root.innerHTML; });
        
        function updateChecklistVisibility() { 
            const testPlan = document.getElementById('test_plan_type').value; 
            const placeholder = document.getElementById('checklist-placeholder'); 
            let checklistVisible = false; 
            document.querySelectorAll('[id^="checklist-container-"]').forEach(el => { 
                const planName = el.id.replace('checklist-container-', '').replace(/_/g, ' ');
                if(planName === testPlan) { 
                    el.classList.remove('hidden'); 
                    checklistVisible = true; 
                } else { 
                    el.classList.add('hidden'); 
                } 
            }); 
            placeholder.style.display = checklistVisible ? 'none' : 'block'; 
        }
        
        // --- COPY QB LINK ---
        function copyQbLink(element, inputId) {
            const inputField = element.closest('.relative').querySelector(`#${inputId}`);
            const buildId = inputField.value;
            if (buildId && !isNaN(buildId)) {
                const url = `https://android.qb.sec.samsung.net/build/${buildId}`;
                navigator.clipboard.writeText(url).then(() => {
                    const toast = document.getElementById('toast');
                    toast.classList.add('show');
                    setTimeout(() => { toast.classList.remove('show'); }, 3000);
                }).catch(err => console.error('Gagal menyalin link: ', err));
            }
        }
        
        // --- TABLE FILTER & PAGINATION ---
        const filterInput = document.getElementById('filter-input');
        const rowsSelect = document.getElementById('pagination-rows');
        const tableBody = document.getElementById('task-table-body');
        const paginationNav = document.getElementById('pagination-nav');
        const allRows = Array.from(tableBody.querySelectorAll('tr'));
        let currentPage = 1;

        function renderTable() { const filterText = filterInput.value.toLowerCase(); const rowsPerPage = parseInt(rowsSelect.value); const filteredRows = allRows.filter(row => row.textContent.toLowerCase().includes(filterText)); const totalPages = Math.ceil(filteredRows.length / rowsPerPage); currentPage = Math.min(currentPage, totalPages) || 1; tableBody.innerHTML = ''; const start = (currentPage - 1) * rowsPerPage; const end = start + rowsPerPage; filteredRows.slice(start, end).forEach(row => tableBody.appendChild(row)); renderPagination(totalPages); }
        function renderPagination(totalPages) { paginationNav.innerHTML = ''; if (totalPages <= 1) return; for (let i = 1; i <= totalPages; i++) { const pageButton = document.createElement('button'); pageButton.textContent = i; pageButton.className = `px-3 py-1 rounded-lg text-sm ${i === currentPage ? 'bg-blue-600 text-white' : 'themed-input'}`; pageButton.onclick = () => { currentPage = i; renderTable(); }; paginationNav.appendChild(pageButton); } }
        
        filterInput.addEventListener('input', renderTable);
        rowsSelect.addEventListener('change', () => { currentPage = 1; renderTable(); });
        document.addEventListener('DOMContentLoaded', () => { renderTable(); setupQuill(''); updateChecklistVisibility(); });
    </script>
</body>
</html>

