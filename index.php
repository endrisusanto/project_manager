<?php
// 1. INISIALISASI
require_once "config.php";
require_once "session.php";
$active_page = 'project_dashboard';

// Tentukan halaman aktif untuk navigasi header
$active_page = 'project_dashboard';

// 2. LOGIKA PENGAMBILAN DATA TUGAS (TASK)
$statuses = ['Task Baru', 'Test Ongoing', 'Pending Feedback', 'Feedback Sent', 'Submitted', 'Passed', 'Approved', 'Batal'];
$tasksToDisplay = [];
foreach ($statuses as $status) {
    $tasksToDisplay[$status] = [];
}

// MODIFIKASI: Filter data berdasarkan peran pengguna
$one_month_ago = date('Y-m-d', strtotime('-1 month'));
$sql = "SELECT t.*, u.profile_picture 
        FROM gba_tasks t 
        LEFT JOIN users u ON t.pic_email = u.email";

$where_clauses = ["t.request_date >= ?"];
$params = [$one_month_ago];
$types = "s";

if (!is_admin()) {
    $where_clauses[] = "t.pic_email = ?";
    $params[] = $_SESSION['user_details']['email'];
    $types .= "s";
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

// $sql .= " ORDER BY t.is_urgent DESC, t.request_date DESC";
$sql .= " ORDER BY t.request_date DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = false; // Handle error jika statement gagal
}


if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Kalkulasi tanggal dan status
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

        if (isset($tasksToDisplay[$row['progress_status']])) {
            $tasksToDisplay[$row['progress_status']][] = $row;
        }
    }
}

// 3. FUNGSI HELPER TAMPILAN
function getPicBadgeColor($identifier) {
    $colors = ['sky', 'emerald', 'amber', 'rose', 'violet', 'teal', 'cyan'];
    $hash = crc32($identifier);
    return "badge-color-" . $colors[$hash % count($colors)];
}
function getPicInitials($email) {
    if (empty($email)) return '??';
    $parts = explode('@', $email);
    $name_parts = explode('.', $parts[0]);
    $initials = '';
    foreach ($name_parts as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
    return strlen($initials) > 2 ? substr($initials, 0, 2) : $initials;
}
function render_kinerja_status($task) {
    ob_start();
    echo '<div class="flex items-center justify-between">';
    echo '<span class="text-secondary">Submission:</span>';
    if ($task['ontime_submission_status']) {
        $color_class = $task['ontime_submission_status'] == 'Delay' ? 'text-red-400' : 'text-green-400';
        echo "<span class='font-semibold {$color_class}'>{$task['ontime_submission_status']}</span>";
    } elseif (isset($task['deadline_countdown'])) {
        $days = $task['deadline_countdown'];
        $color_class = $days < 0 ? 'text-red-400' : ($days <= 3 ? 'text-yellow-400' : 'text-secondary');
        $icon_html = ($days <= 3 && $days >= 0) ? '<svg class="w-4 h-4 animate-pulse-alert" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg>' : '';
        $text = $days >= 0 ? "{$days} hari lagi" : 'Lewat ' . abs($days) . ' hari';
        echo "<span class='flex items-center gap-1 font-semibold {$color_class}'>{$icon_html}{$text}</span>";
    } else {
        echo '<span class="text-secondary">-</span>';
    }
    echo '</div>';
    echo '<div class="flex items-center justify-between">';
    echo '<span class="text-secondary">Approval:</span>';
    if ($task['ontime_approved_status']) {
        $color_class = $task['ontime_approved_status'] == 'Delay' ? 'text-red-400' : 'text-green-400';
        echo "<span class='font-semibold {$color_class}'>{$task['ontime_approved_status']}</span>";
    } elseif (isset($task['approval_countdown'])) {
        $days = $task['approval_countdown'];
        $color_class = $days < 0 ? 'text-red-400' : ($days <= 1 ? 'text-yellow-400' : 'text-secondary');
        $icon_html = ($days <= 1 && $days >= 0) ? '<svg class="w-4 h-4 animate-pulse-alert" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg>' : '';
        $text = $days >= 0 ? "{$days} hari lagi" : 'Lewat ' . abs($days) . ' hari';
        echo "<span class='flex items-center gap-1 font-semibold {$color_class}'>{$icon_html}{$text}</span>";
    } else {
        echo '<span class="text-secondary">-</span>';
    }
    echo '</div>';
    return ob_get_clean();
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
        :root{--bg-primary:#020617;--text-primary:#e2e8f0;--text-secondary:#94a3b8;--glass-bg:rgba(15,23,42,.8);--glass-border:rgba(51,65,85,.6);--column-bg:rgba(255,255,255,.03);--text-header:#fff;--text-card-title:#fff;--text-card-body:#cbd5e1;--text-icon:#94a3b8;--input-bg:rgba(30,41,59,.7);--input-border:#475569;--input-text:#e2e8f0;--toast-bg:#22c55e;--toast-text:#fff}html.light{--bg-primary:#f1f5f9;--text-primary:#0f172a;--text-secondary:#475569;--glass-bg:rgba(255,255,255,.7);--glass-border:rgba(0,0,0,.1);--column-bg:rgba(0,0,0,.03);--text-header:#0f172a;--text-card-title:#1e293b;--text-card-body:#334155;--text-icon:#475569;--input-bg:#fff;--input-border:#cbd5e1;--input-text:#0f172a;--toast-bg:#16a34a;--toast-text:#fff}
        html,body{overflow-x:hidden;height:100%}body{font-family:'Inter',sans-serif;background-color:var(--bg-primary);color:var(--text-primary)}main{height:calc(100% - 64px);overflow-y:auto}#neural-canvas{position:fixed;top:0;left:0;width:100%;height:100%;z-index:-1}.glass-container{background:var(--glass-bg);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border:1px solid var(--glass-border)}.kanban-column{background:var(--column-bg);border-radius:1rem}.task-card{position:relative;cursor:grab;transition:all .2s ease-in-out;border-radius:.75rem}.task-card:hover{transform:translateY(-4px);box-shadow:0 10px 20px rgba(0,0,0,.2)}.sortable-ghost{opacity:.4;background:rgba(59,130,246,.2);border:2px dashed #3b82f6}.themed-input{background-color:var(--input-bg);border:1px solid var(--input-border);color:var(--input-text)}.badge{display:inline-block;padding:.25rem .6rem;font-size:.75rem;font-weight:500;border-radius:.75rem;line-height:1.2}.badge-color-sky{background-color:rgba(14,165,233,.2);color:#7dd3fc}html.light .badge-color-sky{background-color:#e0f2fe;color:#0369a1}.badge-color-emerald{background-color:rgba(16,185,129,.2);color:#6ee7b7}html.light .badge-color-emerald{background-color:#d1fae5;color:#047857}.badge-color-amber{background-color:rgba(245,158,11,.2);color:#fcd34d}html.light .badge-color-amber{background-color:#fef3c7;color:#92400e}.badge-color-rose{background-color:rgba(244,63,94,.2);color:#fda4af}html.light .badge-color-rose{background-color:#ffe4e6;color:#9f1239}.badge-color-violet{background-color:rgba(139,92,246,.2);color:#c4b5fd}html.light .badge-color-violet{background-color:#ede9fe;color:#5b21b6}.badge-color-teal{background-color:rgba(20,184,166,.2);color:#5eead4}html.light .badge-color-teal{background-color:#ccfbf1;color:#0d9488}.badge-color-cyan{background-color:rgba(6,182,212,.2);color:#67e8f9}html.light .badge-color-cyan{background-color:#cffafe;color:#0e7490}
        .nav-link{color:var(--text-secondary);border-bottom:2px solid transparent;transition:all .2s}.nav-link:hover{border-color:var(--text-secondary);color:var(--text-primary)}.nav-link-active{color:var(--text-primary)!important;border-bottom:2px solid #3b82f6;font-weight:600}.ql-toolbar,.ql-container{border-color:var(--glass-border)!important}.ql-editor{color:var(--text-primary);min-height:100px}#toast{position:fixed;bottom:-100px;left:50%;transform:translateX(-50%);background-color:var(--toast-bg);color:var(--toast-text);padding:12px 20px;border-radius:8px;z-index:1000;transition:bottom .5s ease-in-out}#toast.show{bottom:30px}
        @keyframes pulse-alert{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.1);opacity:.8}}.animate-pulse-alert{animation:pulse-alert 1.5s infinite}html.light .animate-pulse-alert{color:#dc2626}html.light .font-semibold.text-green-400{color:#15803d}html.light .font-semibold.text-red-400{color:#b91c1c}html.light .font-semibold.text-yellow-400{color:#a16207}
        @keyframes underline-glow{0%,100%{box-shadow:0 2px 4px -2px rgba(249,115,22,.3),0 4px 12px -2px rgba(249,115,22,.2)}50%{box-shadow:0 2px 8px -2px rgba(249,115,22,.6),0 4px 18px -2px rgba(249,115,22,.5)}}.underline-glow-effect{border-bottom:2px solid rgba(249,115,22,.8);animation:underline-glow 2s infinite ease-in-out}.sad-emoji{position:fixed;font-size:2rem;animation:fall 5s linear forwards;opacity:1;z-index:9999}@keyframes fall{to{transform:translateY(100vh) rotate(360deg);opacity:0}}
        .accordion-summary{display:none}.view-accordion .accordion-summary{display:block}.view-accordion .task-card-full-content{display:none}
        .view-accordion .task-card.is-expanded .task-card-full-content{display:block; padding-top: 1rem; }
        .view-accordion .task-card.is-expanded .accordion-summary{display:none}.pic-icon{width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;flex-shrink:0}
        
        .strobe-urgent-effect {
            position: relative;
            border-radius: .75rem;
            animation: strobe-effect 1.5s infinite;
        }
        @keyframes strobe-effect {
            0%, 20% { box-shadow: inset 2px 0 0 0 rgba(59, 130, 246, 1), 0 0 15px rgba(59, 130, 246, 0.7); }
            21%, 24% { box-shadow: none; }
            25%, 45% { box-shadow: inset 2px 0 0 0 rgba(59, 130, 246, 1), 0 0 15px rgba(59, 130, 246, 0.7); }
            46%, 49% { box-shadow: none; }
            50%, 70% { box-shadow: inset -2px 0 0 0 rgba(239, 68, 68, 1), 0 0 15px rgba(239, 68, 68, 0.6); }
            71%, 74% { box-shadow: none; }
            75%, 95% { box-shadow: inset -2px 0 0 0 rgba(239, 68, 68, 1), 0 0 15px rgba(239, 68, 68, 0.6); }
            96%, 100% { box-shadow: none; }
        }
    </style>
</head>
<body class="h-screen flex flex-col">
    <canvas id="neural-canvas"></canvas>
    <div id="toast"></div>

    <?php include 'header.php'; ?>

    <main class="w-full p-4 sm:p-6 lg:p-8 flex-grow">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-8 gap-6 h-full">
            <?php foreach ($statuses as $status): ?>
            <div class="flex flex-col">
                <h2 class="text-base font-semibold mb-4 flex items-center justify-between text-header">
                    <?= htmlspecialchars($status) ?>
                    <span class="text-sm font-bold bg-gray-500/10 text-header rounded-full px-2 py-0.5 count-<?= str_replace(' ', '', $status) ?>">
                        <?= count($tasksToDisplay[$status]) ?>
                    </span>
                </h2>
                <div id="status-<?= str_replace(' ', '', $status) ?>" data-status="<?= $status ?>" class="kanban-column space-y-4 p-2 rounded-lg h-full overflow-y-auto">
                    <?php foreach ($tasksToDisplay[$status] as $task): ?>
                    <?php
                        $cardClasses = 'task-card flex flex-col';
                        if ($task['progress_status'] === 'Task Baru') $cardClasses .= ' underline-glow-effect';
                        $cardClasses .= ($task['is_urgent'] == 1) ? ' strobe-urgent-effect' : ' glass-container';
                    ?>
                    <div id="task-<?= $task['id'] ?>" data-id="<?= $task['id'] ?>" data-task='<?= json_encode($task, JSON_HEX_APOS | JSON_HEX_QUOT) ?>' class="<?= $cardClasses ?>">
                        <div class="glass-container-content p-4">
                            <div class="accordion-summary">
                               <div class="flex justify-between items-center">
                                    <h4 class="font-bold text-sm text-card-title truncate flex-grow pr-2"><?= htmlspecialchars($task['ap'] ?: 'N/A') ?></h4>
                                    <?php if (!empty($task['profile_picture']) && $task['profile_picture'] !== 'default.png'): ?>
                                        <img src="uploads/<?= htmlspecialchars($task['profile_picture']) ?>" alt="PIC" class="w-6 h-6 rounded-full object-cover flex-shrink-0">
                                    <?php else: ?>
                                        <div class="pic-icon <?= getPicBadgeColor($task['pic_email']) ?>"><?= getPicInitials($task['pic_email']) ?></div>
                                    <?php endif; ?>
                               </div>
                               <div class="mt-2 pt-2 border-t border-[var(--glass-border)] text-xs space-y-1">
                                    <?= render_kinerja_status($task) ?>
                               </div>
                            </div>
                            <div class="task-card-full-content">
                                <div class="flex justify-between items-start mb-2">
                                    <h3 class="font-bold text-card-title flex-1 pr-2 marketing-name"><?= htmlspecialchars($task['project_name']) ?></h3>
                                    <div class="flex items-center space-x-1 flex-shrink-0">
                                        <button onclick="toggleUrgent(this, <?= $task['id'] ?>)" title="Tandai sebagai Urgent" class="text-icon hover:text-red-400 p-1 rounded-full">
                                            <svg class="h-5 w-5 <?= $task['is_urgent'] ? 'text-red-500' : '' ?>" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg>
                                        </button>
                                        <button onclick='openEditModal(this)' title="Edit Task" class="text-icon hover:text-blue-500 p-1 rounded-full"><svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z" /><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd" /></svg></button>
                                        <?php if (is_admin()): ?>
                                            <form action="handler.php" method="POST" onsubmit="return confirm('Yakin ingin menghapus task ini?');" class="inline"><input type="hidden" name="action" value="delete_gba_task"><input type="hidden" name="id" value="<?= $task['id'] ?>"><button type="submit" title="Hapus Task" class="text-icon hover:text-red-500 p-1 rounded-full"><svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm4 0a1 1 0 012 0v6a1 1 0 11-2 0V8z" clip-rule="evenodd" /></svg></button></form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <p class="text-sm font-medium text-card-body model-name"><?= htmlspecialchars($task['model_name']) ?></p>
                                <div class="text-xs text-secondary font-mono space-y-0.5 mt-2 pt-2 border-t border-[var(--glass-border)]">
                                    <div>AP: <?= htmlspecialchars($task['ap'] ?: '-') ?></div>
                                    <div>CP: <?= htmlspecialchars($task['cp'] ?: '-') ?></div>
                                    <div>CSC: <?= htmlspecialchars($task['csc'] ?: '-') ?></div>
                                </div>
                                <div class="mt-2 pt-2 border-t border-[var(--glass-border)] text-xs space-y-1">
                                    <?= render_kinerja_status($task) ?>
                                </div>
                                <div class="mt-3 text-right">
                                    <span class="badge task-pic <?= getPicBadgeColor($task['pic_email']) ?>"><?= htmlspecialchars($task['pic_email']) ?></span>
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
    // --- ANIMATION & THEME LOGIC ---
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
    
    // --- PAGE SPECIFIC LOGIC ---
    const themeToggleBtn = document.getElementById('theme-toggle'), modal = document.getElementById('task-modal'), modalTitle = document.getElementById('modal-title'), taskForm = document.getElementById('task-form'), searchInput = document.getElementById('search-input'), viewToggleBtn = document.getElementById('view-toggle'), mainContainer = document.querySelector('main'); let quill;
    
    window.addEventListener('resize',()=>{setCanvasSize();init(particleCount);});
    function applyTheme(isLight) { document.documentElement.classList.toggle('light', isLight); document.getElementById('theme-toggle-light-icon').classList.toggle('hidden', !isLight); document.getElementById('theme-toggle-dark-icon').classList.toggle('hidden', isLight); } const savedTheme = localStorage.getItem('theme'); applyTheme(savedTheme === 'light'); themeToggleBtn.addEventListener('click', () => { const isLight = !document.documentElement.classList.contains('light'); localStorage.setItem('theme', isLight ? 'light' : 'dark'); applyTheme(isLight); });
    function showToast(message, isSuccess = true) { const toast = document.getElementById('toast'); toast.textContent = message; toast.style.backgroundColor = isSuccess ? 'var(--toast-bg)' : '#ef4444'; toast.classList.add('show'); setTimeout(() => toast.classList.remove('show'), 3000); }
    function triggerConfetti() { const duration = 2.5 * 1000, animationEnd = Date.now() + duration, defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 9999 }; function randomInRange(min, max) { return Math.random() * (max - min) + min; } const interval = setInterval(function () { const timeLeft = animationEnd - Date.now(); if (timeLeft <= 0) return clearInterval(interval); const particleCount = 50 * (timeLeft / duration); confetti({ ...defaults, particleCount, origin: { x: randomInRange(0.1, 0.3), y: Math.random() - 0.2 } }); confetti({ ...defaults, particleCount, origin: { x: randomInRange(0.7, 0.9), y: Math.random() - 0.2 } }); }, 250); }
    function triggerSadAnimation() { for (let i = 0; i < 30; i++) { const e = document.createElement('div'); e.className = 'sad-emoji'; e.innerText = '😢'; e.style.left = `${Math.random() * 100}vw`; e.style.animationDelay = `${Math.random() * 2}s`; document.body.appendChild(e); setTimeout(() => e.remove(), 5000); } }
    function showAlertModal(title, message) { const existingModal = document.getElementById('alert-modal'); if (existingModal) existingModal.remove(); const modalHtml = `<div id="alert-modal" class="fixed inset-0 z-[100] flex items-center justify-center bg-black bg-opacity-70" onclick="this.remove()"><div class="glass-container rounded-lg shadow-xl p-6 w-full max-w-sm mx-4" onclick="event.stopPropagation()"><h2 class="text-xl font-bold text-header mb-4">${title}</h2><p class="text-secondary mb-6">${message}</p><div class="flex justify-end"><button onclick="document.getElementById('alert-modal').remove()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Tutup</button></div></div></div>`; document.body.insertAdjacentHTML('beforeend', modalHtml); }
    function openAddModal() { taskForm.reset(); modalTitle.innerText = 'Tambah Task Baru'; taskForm.elements['action'].value = 'create_gba_task'; taskForm.elements['id'].value = ''; document.getElementById('request_date').value = new Date().toISOString().slice(0, 10); setupQuill(''); updateChecklistVisibility(); modal.classList.remove('hidden'); }
    function openEditModal(button) { const card = button.closest('.task-card'), taskData = JSON.parse(card.getAttribute('data-task')); taskForm.reset(); modalTitle.innerText = 'Edit Task'; taskForm.elements['action'].value = 'update_gba_task'; for (const key in taskData) { if (taskForm.elements[key] && !key.endsWith('_obj')) { if (key === 'is_urgent') { document.getElementById('is_urgent_toggle').checked = taskData[key] == 1; } else { taskForm.elements[key].value = taskData[key]; } } } setupQuill(taskData.notes || ''); updateChecklistVisibility(); if (taskData.test_items_checklist) { try { const checklist = JSON.parse(taskData.test_items_checklist); for (const itemName in checklist) { const checkbox = document.querySelector(`input[name="checklist[${itemName}]"]`); if (checkbox) checkbox.checked = !!checklist[itemName]; } } catch (e) { console.error("Gagal parse checklist JSON:", e); } } modal.classList.remove('hidden'); }
    function closeModal() { modal.classList.add('hidden'); } window.onclick = (event) => { if (event.target == modal) closeModal(); };
    function setupQuill(content) { if (!quill) { quill = new Quill('#notes-editor', { theme: 'snow', modules: { toolbar: [['bold', 'italic'], ['link'], [{ 'list': 'ordered' }, { 'list': 'bullet' }]] } }); } quill.root.innerHTML = content; }
    taskForm.addEventListener('submit', () => { document.getElementById('notes-hidden-input').value = quill.root.innerHTML; }); document.getElementById('test_plan_type').addEventListener('change', updateChecklistVisibility); function updateChecklistVisibility() { const testPlan = document.getElementById('test_plan_type').value, placeholder = document.getElementById('checklist-placeholder'); let checklistVisible = !1; document.querySelectorAll('[id^="checklist-container-"]').forEach(el => { const planName = el.id.replace('checklist-container-', '').replace(/_/g, ' '); if (planName === testPlan) { el.classList.remove('hidden'); checklistVisible = !0 } else { el.classList.add('hidden') } }); placeholder.style.display = checklistVisible ? 'none' : 'block'; }
    function toggleUrgent(button, taskId) { event.stopPropagation(); fetch('handler.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'toggle_urgent', task_id: taskId }) }).then(response => response.json()).then(data => { if (data.success) { showToast('Status urgent diperbarui'); const card = document.getElementById(`task-${taskId}`); const icon = button.querySelector('svg'); card.classList.toggle('strobe-urgent-effect', data.is_urgent); card.classList.toggle('glass-container', !data.is_urgent); icon.classList.toggle('text-red-500', data.is_urgent); } else { showAlertModal('Gagal', data.error || 'Gagal memperbarui status urgent.'); } }).catch(() => showAlertModal('Error', 'Kesalahan jaringan.')); }
    const progressStatusSelect = document.getElementById('progress_status'), submissionDateInput = document.getElementById('submission_date'), approvedDateInput = document.getElementById('approved_date'), requestDateInput = document.getElementById('request_date'), deadlineInput = document.getElementById('deadline'), signOffDateInput = document.getElementById('sign_off_date');
    function calculateWorkingDays(startDate,daysToAdd){let currentDate=new Date(startDate);let addedDays=0;while(addedDays<daysToAdd){currentDate.setDate(currentDate.getDate()+1);if(currentDate.getDay()!==0&&currentDate.getDay()!==6){addedDays++}}return currentDate.toISOString().slice(0,10)}
    function getTodayDate(){return new Date().toISOString().slice(0,10)}
    function checkAllVisibleCheckboxes(){const visibleChecklist=document.querySelector('[id^="checklist-container-"]:not(.hidden)');if(visibleChecklist){visibleChecklist.querySelectorAll('input[type="checkbox"]').forEach(cb=>{cb.checked=!0})}}
    requestDateInput.addEventListener('change',()=>{if(requestDateInput.value){const futureDate=calculateWorkingDays(requestDateInput.value,7);deadlineInput.value=futureDate;signOffDateInput.value=futureDate}});
    progressStatusSelect.addEventListener('change',e=>{const status=e.target.value;if(status==='Submitted'){if(!submissionDateInput.value){submissionDateInput.value=getTodayDate()}checkAllVisibleCheckboxes()}else if(status==='Approved'){if(!submissionDateInput.value){submissionDateInput.value=getTodayDate()}if(!approvedDateInput.value){approvedDateInput.value=getTodayDate()}checkAllVisibleCheckboxes()}});
    taskForm.addEventListener('change',e=>{if(e.target.matches('input[type="checkbox"][name^="checklist"]')){const currentStatus=progressStatusSelect.value;if(currentStatus!=='Approved'&&currentStatus!=='Submitted'){progressStatusSelect.value='Test Ongoing'}}});
    document.addEventListener('DOMContentLoaded', function () {
        if (viewToggleBtn) { const fullIcon = document.getElementById('view-toggle-full-icon'); const accordionIcon = document.getElementById('view-toggle-accordion-icon'); function applyViewMode(mode) { mainContainer.classList.toggle('view-accordion', mode === 'accordion'); fullIcon.classList.toggle('hidden', mode === 'accordion'); accordionIcon.classList.toggle('hidden', mode !== 'accordion'); localStorage.setItem('viewMode', mode); } viewToggleBtn.addEventListener('click', () => { const currentMode = mainContainer.classList.contains('view-accordion') ? 'full' : 'accordion'; applyViewMode(currentMode); }); const savedViewMode = localStorage.getItem('viewMode') || 'full'; applyViewMode(savedViewMode); mainContainer.addEventListener('click', function (e) { if (mainContainer.classList.contains('view-accordion')) { const card = e.target.closest('.task-card'); if (card) { card.classList.toggle('is-expanded'); } } }); }
        const columns = document.querySelectorAll('.kanban-column'); columns.forEach(column => { new Sortable(column, { group: 'kanban', animation: 150, ghostClass: 'sortable-ghost', onEnd: function (evt) { const card = evt.item, taskId = card.dataset.id, newStatus = evt.to.dataset.status; let reloadDelay = 800; if (newStatus === 'Approved') { triggerConfetti(); reloadDelay = 3000; } if (newStatus === 'Batal') { triggerSadAnimation(); reloadDelay = 3000; } card.classList.toggle('underline-glow-effect', newStatus === 'Task Baru'); fetch('handler.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'update_task_status', task_id: taskId, new_status: newStatus }) }).then(response => response.json()).then(data => { if (data.success) { showToast(`Status task #${taskId} diperbarui`); setTimeout(() => window.location.reload(), reloadDelay); } else { showAlertModal('Gagal Update', data.error || 'Gagal memperbarui status task.'); evt.from.appendChild(card); } }).catch(() => { showAlertModal('Error', 'Terjadi kesalahan jaringan.'); evt.from.appendChild(card); }); } }); });
        function updateColumnCounts() { columns.forEach(column => { const status = column.dataset.status.replace(/[\s\/]/g, ''), count = column.querySelectorAll('.task-card:not(.hidden)').length, countElement = document.querySelector(`.count-${status}`); if (countElement) countElement.textContent = count; }); }
        if (searchInput) { searchInput.addEventListener('input', () => { const searchTerm = searchInput.value.toLowerCase(); document.querySelectorAll('.task-card').forEach(card => { const cardContent = card.textContent.toLowerCase(); card.classList.toggle('hidden', !cardContent.includes(searchTerm)); }); updateColumnCounts(); }); } updateColumnCounts();
        const urlParams = new URLSearchParams(window.location.search), error = urlParams.get('error'); if (error === 'permission_denied') { showAlertModal('Akses Ditolak', 'Anda tidak memiliki izin untuk melakukan tindakan ini.'); window.history.replaceState({}, document.title, window.location.pathname); }
        const profileMenu = document.getElementById('profile-menu'); if (profileMenu) { const profileButton = profileMenu.querySelector('button'), profileDropdown = document.getElementById('profile-dropdown'); profileButton.addEventListener('click', e => { e.stopPropagation(); profileDropdown.classList.toggle('hidden'); }); document.addEventListener('click', e => { if (!profileMenu.contains(e.target)) { profileDropdown.classList.add('hidden'); } }); }
    });
</script>
</body>
</html>