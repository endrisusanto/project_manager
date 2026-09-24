<?php
// 1. INISIALISASI
require_once "config.php";
require_once "session.php"; 
require_once "marketing_name_mapper.php";
$active_page = 'activity_log'; // Set active page for header.php

// Cek apakah pengguna sudah login
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// 2. LOGIKA PENGAMBILAN DATA
$sql = "SELECT al.*, u.username, u.profile_picture, 
               CASE 
                   WHEN al.task_id IS NOT NULL THEN t.model_name 
                   ELSE NULL 
               END as task_model_name,
               CASE 
                   WHEN al.task_id IS NOT NULL THEN t.project_name 
                   ELSE NULL 
               END as task_project_name
        FROM activity_log al
        LEFT JOIN users u ON al.user_email = u.email
        LEFT JOIN gba_tasks t ON al.task_id = t.id
        ORDER BY al.action_time DESC 
        LIMIT 150";

$result = $conn->query($sql);
$logs = [];
if ($result) {
    while($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
}

// Helper untuk metadata action
function getActionMeta($action_type) {
    switch ($action_type) {
        case 'TASK_CREATED':
            return [
                'label' => 'Task Created',
                'badge' => 'action-badge-created',
                'dot' => 'dot-created',
                'icon' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>'
            ];
        case 'STATUS_CHANGE':
            return [
                'label' => 'Status Change',
                'badge' => 'action-badge-status',
                'dot' => 'dot-status',
                'icon' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>'
            ];
        case 'TOGGLE_URGENT':
            return [
                'label' => 'Urgent Flag',
                'badge' => 'action-badge-urgent',
                'dot' => 'dot-urgent',
                'icon' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>'
            ];
        case 'TASK_UPDATED':
            return [
                'label' => 'Task Updated',
                'badge' => 'action-badge-updated',
                'dot' => 'dot-updated',
                'icon' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>'
            ];
        case 'TASK_DELETED':
            return [
                'label' => 'Task Deleted',
                'badge' => 'action-badge-deleted',
                'dot' => 'dot-deleted',
                'icon' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>'
            ];
        default:
            return [
                'label' => str_replace('_', ' ', $action_type),
                'badge' => 'action-badge-default',
                'dot' => 'dot-default',
                'icon' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
            ];
    }
}

// Relative time helper
function getRelativeTime($datetime_str) {
    $time = strtotime($datetime_str);
    $diff = time() - $time;
    if ($diff < 45) return "Baru saja";
    if ($diff < 3600) return floor($diff / 60) . " mnt lalu";
    if ($diff < 86400) return floor($diff / 3600) . " jam lalu";
    if ($diff < 172800) return "Kemarin, " . date('H:i', $time);
    return date('d M Y, H:i', $time);
}

// Helper PIC initial
function getPicInitials($email) {
    if (empty($email)) return '?';
    $parts = explode('@', $email);
    return strtoupper(substr($parts[0], 0, 1));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log - Project Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['class', '.never-match-dark']
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #020617;
            --text-primary: #e2e8f0;
            --text-secondary: #94a3b8;
            --glass-bg: rgba(15, 23, 42, 0.7);
            --glass-border: rgba(51, 65, 85, 0.5);
            --card-bg: rgba(15, 23, 42, 0.65);
            --card-border: rgba(51, 65, 85, 0.6);
            --text-header: #ffffff;
            --text-icon: #94a3b8;
            --input-bg: rgba(30, 41, 59, 0.7);
            --input-border: #475569;
            --timeline-line: rgba(71, 85, 105, 0.45);
        }
        
        html.light {
            --bg-primary: #f1f5f9;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --glass-bg: rgba(255, 255, 255, 0.85);
            --glass-border: rgba(0, 0, 0, 0.08);
            --card-bg: rgba(255, 255, 255, 0.9);
            --card-border: rgba(0, 0, 0, 0.08);
            --text-header: #0f172a;
            --text-icon: #475569;
            --input-bg: #ffffff;
            --input-border: #cbd5e1;
            --timeline-line: rgba(203, 213, 225, 0.9);
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            overflow-x: hidden;
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

        .main-container {
            height: calc(100vh - 64px);
            overflow-y: auto;
            padding: 1.5rem;
        }

        .glass-card { 
            background: var(--card-bg); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px); 
            border: 1px solid var(--card-border); 
            border-radius: 1rem; 
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), 
                        box-shadow 0.2s cubic-bezier(0.16, 1, 0.3, 1), 
                        border-color 0.2s ease;
        }
        
        .glass-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px -6px rgba(0, 0, 0, 0.35);
            border-color: rgba(99, 102, 241, 0.4);
        }
        html.light .glass-card:hover {
            box-shadow: 0 10px 24px -4px rgba(0, 0, 0, 0.08);
            border-color: rgba(99, 102, 241, 0.3);
        }
        
        /* Timeline styling */
        .timeline-container {
            position: relative;
            padding-left: 2rem;
        }
        .timeline-container::before {
            content: '';
            position: absolute;
            top: 14px;
            bottom: 14px;
            left: 11px;
            width: 2px;
            background: var(--timeline-line);
            border-radius: 9999px;
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1.25rem;
        }

        .timeline-node {
            position: absolute;
            left: -2rem;
            top: 16px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            background: var(--bg-primary);
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .timeline-item:hover .timeline-node {
            transform: scale(1.15);
        }

        .node-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        /* Action Badges & Dots */
        .action-badge-created { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .dot-created { background: #10b981; box-shadow: 0 0 10px rgba(16, 185, 129, 0.5); }
        html.light .action-badge-created { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }

        .action-badge-status { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }
        .dot-status { background: #3b82f6; box-shadow: 0 0 10px rgba(59, 130, 246, 0.5); }
        html.light .action-badge-status { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }

        .action-badge-urgent { background: rgba(244, 63, 94, 0.15); color: #fb7185; border: 1px solid rgba(244, 63, 94, 0.3); }
        .dot-urgent { background: #f43f5e; box-shadow: 0 0 10px rgba(244, 63, 94, 0.5); }
        html.light .action-badge-urgent { background: #fff1f2; color: #be123c; border-color: #fecdd3; }

        .action-badge-updated { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .dot-updated { background: #f59e0b; box-shadow: 0 0 10px rgba(245, 158, 11, 0.5); }
        html.light .action-badge-updated { background: #fffbeb; color: #b45309; border-color: #fde68a; }

        .action-badge-deleted { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        .dot-deleted { background: #ef4444; box-shadow: 0 0 10px rgba(239, 68, 68, 0.5); }
        html.light .action-badge-deleted { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }

        .action-badge-default { background: rgba(148, 163, 184, 0.15); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.3); }
        .dot-default { background: #94a3b8; box-shadow: 0 0 8px rgba(148, 163, 184, 0.4); }
        html.light .action-badge-default { background: #f8fafc; color: #475569; border-color: #e2e8f0; }

        /* Filter Pills */
        .filter-pill {
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 9999px;
            border: 1px solid var(--glass-border);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text-secondary);
            transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
            white-space: nowrap;
        }
        .filter-pill:hover {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.08);
        }
        .filter-pill.active {
            background: #3b82f6;
            color: #ffffff;
            border-color: #3b82f6;
            box-shadow: 0 2px 10px rgba(59, 130, 246, 0.35);
        }
        html.light .filter-pill {
            background: rgba(0, 0, 0, 0.03);
        }
        html.light .filter-pill:hover {
            background: rgba(0, 0, 0, 0.06);
        }
        html.light .filter-pill.active {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
        }

        .profile-img {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
            border: 1.5px solid var(--card-border);
        }

        /* Changes diff chip styling */
        .diff-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11.5px;
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.06);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        html.light .diff-chip {
            background: rgba(0, 0, 0, 0.04);
            border-color: rgba(0, 0, 0, 0.08);
        }
    </style>
</head>
<body class="h-screen flex flex-col">
    <canvas id="neural-canvas"></canvas>
    
    <?php include 'header.php'; ?>

    <main class="main-container flex-grow">
        <div class="max-w-4xl mx-auto space-y-5">
            
            <!-- Top Controls Bar: Title, Count, Search & Filter Pills -->
            <div class="glass-card p-4 flex flex-col md:flex-row md:items-center justify-between gap-3 shadow-lg">
                <div class="flex items-center gap-3">
                    <div class="w-2.5 h-2.5 rounded-full bg-blue-500 shadow-sm shadow-blue-500/50"></div>
                    <div>
                        <h1 class="text-base font-bold text-header tracking-tight">Activity Timeline Log</h1>
                        <p class="text-xs text-secondary mt-0.5">Memantau riwayat aktivitas dan pembaruan task secara real-time</p>
                    </div>
                    <span id="log-counter" class="ml-auto md:ml-2 px-2.5 py-0.5 rounded-full text-xs font-bold font-mono bg-blue-500/15 text-blue-400 border border-blue-500/30">
                        <?= count($logs) ?> logs
                    </span>
                </div>

                <!-- Instant Filter & Search -->
                <div class="flex items-center gap-2">
                    <div class="relative flex-1 md:w-56">
                        <svg class="w-3.5 h-3.5 text-secondary absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                        <input type="text" id="log-search" placeholder="Cari user, model, aksi..." class="w-full pl-8 pr-3 py-1.5 text-xs rounded-lg border border-[var(--input-border)] bg-[var(--input-bg)] text-[var(--text-primary)] placeholder-secondary/60 focus:outline-none focus:ring-1 focus:ring-blue-500 transition">
                    </div>
                </div>
            </div>

            <!-- Filter Pills Row -->
            <div class="flex items-center gap-1.5 overflow-x-auto pb-1 scrollbar-none" id="action-filters">
                <button class="filter-pill active" data-filter="ALL">Semua (<?= count($logs) ?>)</button>
                <button class="filter-pill" data-filter="TASK_CREATED">Created</button>
                <button class="filter-pill" data-filter="STATUS_CHANGE">Status</button>
                <button class="filter-pill" data-filter="TOGGLE_URGENT">Urgent</button>
                <button class="filter-pill" data-filter="TASK_UPDATED">Updated</button>
                <button class="filter-pill" data-filter="TASK_DELETED">Deleted</button>
            </div>

            <!-- Timeline List -->
            <div class="timeline-container" id="timeline-list">
                <?php if (empty($logs)): ?>
                    <div class="text-center text-secondary py-12 glass-card">
                        <svg class="w-10 h-10 mx-auto text-secondary/50 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        <p class="font-medium text-sm">Tidak ada catatan aktivitas yang ditemukan.</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($logs as $log): 
                    $meta = getActionMeta($log['action_type']);
                    $username = htmlspecialchars($log['username'] ?? explode('@', $log['user_email'])[0]);
                    $user_email = htmlspecialchars($log['user_email']);
                    $raw_time = $log['action_time'];
                    $formatted_full_time = date('d M Y, H:i:s', strtotime($raw_time));
                    $relative_time = getRelativeTime($raw_time);
                    $profile_pic = $log['profile_picture'] ?? 'default.png';

                    // Marketing name mapper fallback
                    $model_name = $log['task_model_name'] ?? '';
                    $project_name = $log['task_project_name'] ?? '';
                    $marketing_name = !empty($project_name) ? $project_name : ($model_name && function_exists('get_marketing_name') ? get_marketing_name($model_name) : '');
                ?>
                    <div class="timeline-item" data-action="<?= htmlspecialchars($log['action_type']) ?>">
                        <!-- Timeline Node Dot -->
                        <div class="timeline-node">
                            <span class="node-dot <?= $meta['dot'] ?>"></span>
                        </div>

                        <!-- Card Content -->
                        <div class="glass-card p-4">
                            <!-- Header: Action Badge, Target Task & Relative Time -->
                            <div class="flex flex-wrap items-center justify-between gap-2 pb-2.5 mb-2.5 border-b border-[var(--glass-border)]">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $meta['badge'] ?>">
                                        <?= $meta['icon'] ?>
                                        <?= htmlspecialchars($meta['label']) ?>
                                    </span>

                                    <?php if ($model_name): ?>
                                        <div class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md text-xs font-mono bg-white/5 border border-white/10 text-primary font-medium">
                                            <span><?= htmlspecialchars($model_name) ?></span>
                                            <?php if ($marketing_name): ?>
                                                <span class="text-secondary font-sans font-normal text-[11px]">• <?= htmlspecialchars($marketing_name) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <span class="text-xs text-secondary font-medium font-mono" title="<?= $formatted_full_time ?>">
                                    <?= $relative_time ?>
                                </span>
                            </div>

                            <!-- User & Details Body -->
                            <div class="space-y-2">
                                <div class="flex items-center gap-2">
                                    <img src="uploads/<?= htmlspecialchars($profile_pic) ?>" 
                                         onerror="this.onerror=null; this.src='uploads/default.png';" 
                                         alt="User" 
                                         class="profile-img"
                                    >
                                    <span class="text-xs font-semibold text-primary"><?= $username ?></span>
                                    <span class="text-xs text-secondary font-mono text-[11px] opacity-75">(<?= $user_email ?>)</span>
                                </div>

                                <!-- Activity Details -->
                                <div class="text-xs leading-relaxed text-secondary pl-7">
                                    <?php
                                    $details_string = $log['details'];
                                    
                                    // Parse TASK_UPDATED changes list
                                    if ($log['action_type'] === 'TASK_UPDATED' && strpos($details_string, 'Changes: ') !== false) {
                                        $parts = explode(' Changes: ', $details_string, 2);
                                        $context_prefix = $parts[0] ?? '';
                                        $changes_list = $parts[1] ?? '';
                                        
                                        if (!empty($context_prefix)) {
                                            echo "<p class='mb-1.5 text-primary font-medium'>" . htmlspecialchars($context_prefix) . "</p>";
                                        }
                                        
                                        $changes = explode(' | ', $changes_list);
                                        echo "<div class='flex flex-wrap gap-1.5 mt-1'>";
                                        foreach ($changes as $change) {
                                            echo "<span class='diff-chip text-secondary'>" . htmlspecialchars($change) . "</span>";
                                        }
                                        echo "</div>";
                                    } else {
                                        echo "<p class='text-secondary/90'>" . htmlspecialchars($details_string) . "</p>";
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- No search results placeholder -->
            <div id="no-results" class="hidden text-center text-secondary py-12 glass-card">
                <svg class="w-10 h-10 mx-auto text-secondary/50 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                <p class="font-medium text-sm">Tidak ada log aktivitas yang sesuai dengan pencarian / filter.</p>
            </div>
        </div>
    </main>
    
    <script>
        // --- NEURAL CANVAS BACKGROUND (Consistent Emil Design & Aesthetics) ---
        const canvas = document.getElementById('neural-canvas'), ctx = canvas.getContext('2d');
        let particles = [], hue = 215;
        const mouse = { x: undefined, y: undefined, radius: 120 };

        function setCanvasSize(){ canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
        setCanvasSize();

        window.addEventListener('mousemove', e => { mouse.x = e.clientX; mouse.y = e.clientY; });
        window.addEventListener('mouseout', () => { mouse.x = undefined; mouse.y = undefined; });
        window.addEventListener('resize', () => { setCanvasSize(); init(particleCount); });

        class Particle {
            constructor() {
                this.x = Math.random() * canvas.width;
                this.y = Math.random() * canvas.height;
                this.vx = (Math.random() - 0.5) * 1.2;
                this.vy = (Math.random() - 0.5) * 1.2;
                this.size = Math.random() * 2 + 1;
            }
            update() {
                this.x += this.vx; this.y += this.vy;
                if (this.x < 0 || this.x > canvas.width) this.vx *= -1;
                if (this.y < 0 || this.y > canvas.height) this.vy *= -1;
            }
            draw() {
                ctx.fillStyle = `hsl(${hue}, 100%, 75%)`;
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                ctx.fill();
            }
        }

        const particleCount = window.innerWidth > 768 ? 90 : 45;
        function init(num) { particles = []; for (let i = 0; i < num; i++) particles.push(new Particle()); }
        init(particleCount);

        function handleParticles() {
            if (mouse.x !== undefined && mouse.y !== undefined) {
                ctx.beginPath();
                let gradient = ctx.createRadialGradient(mouse.x, mouse.y, 0, mouse.x, mouse.y, mouse.radius);
                gradient.addColorStop(0, `hsla(${hue}, 100%, 70%, 0.15)`);
                gradient.addColorStop(1, 'transparent');
                ctx.fillStyle = gradient;
                ctx.arc(mouse.x, mouse.y, mouse.radius, 0, Math.PI * 2);
                ctx.fill();
            }

            for (let i = 0; i < particles.length; i++) {
                particles[i].update();
                particles[i].draw();
                for (let j = i; j < particles.length; j++) {
                    const dx = particles[i].x - particles[j].x;
                    const dy = particles[i].y - particles[j].y;
                    const dist = Math.sqrt(dx * dx + dy * dy);
                    if (dist < 110) {
                        ctx.beginPath();
                        ctx.strokeStyle = `hsla(${hue}, 100%, 80%, ${1 - dist / 110})`;
                        ctx.lineWidth = 0.8;
                        ctx.moveTo(particles[i].x, particles[i].y);
                        ctx.lineTo(particles[j].x, particles[j].y);
                        ctx.stroke();
                        ctx.closePath();
                    }
                }
            }
        }

        function animate() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            hue = (hue + 0.3) % 360;
            handleParticles();
            requestAnimationFrame(animate);
        }
        animate();

        // --- REALTIME INSTANT SEARCH & FILTER LOGIC ---
        document.addEventListener('DOMContentLoaded', () => {
            const searchInput = document.getElementById('log-search');
            const filterBtns = document.querySelectorAll('.filter-pill');
            const timelineItems = Array.from(document.querySelectorAll('.timeline-item'));
            const counterEl = document.getElementById('log-counter');
            const noResultsEl = document.getElementById('no-results');

            let activeFilter = 'ALL';

            function filterLogs() {
                const query = searchInput.value.toLowerCase().trim();
                let visibleCount = 0;

                timelineItems.forEach(item => {
                    const itemAction = item.dataset.action || '';
                    const itemText = item.textContent.toLowerCase();

                    const matchesFilter = (activeFilter === 'ALL' || itemAction === activeFilter);
                    const matchesQuery = !query || itemText.includes(query);

                    if (matchesFilter && matchesQuery) {
                        item.style.display = '';
                        visibleCount++;
                    } else {
                        item.style.display = 'none';
                    }
                });

                if (counterEl) counterEl.textContent = `${visibleCount} logs`;
                if (noResultsEl) noResultsEl.classList.toggle('hidden', visibleCount > 0);
            }

            if (searchInput) searchInput.addEventListener('input', filterLogs);

            filterBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    filterBtns.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    activeFilter = btn.dataset.filter;
                    filterLogs();
                });
            });
        });
    </script>
</body>
</html>