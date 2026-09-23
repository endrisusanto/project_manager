<?php
require_once "config.php";
require_once "session.php";

function is_admin_check() {
    return isset($_SESSION["role"]) && $_SESSION["role"] === 'admin';
}

$active_page = 'bulk_add';

// Hitung next PIC untuk modal (round-robin)
$next_pic_email = null;
$users_result_pic = $conn->query("SELECT email, username FROM users WHERE role = 'user' ORDER BY id ASC");
$pic_list_for_modal = [];
$users_for_bulk = [];
if ($users_result_pic) {
    while ($u = $users_result_pic->fetch_assoc()) {
        $pic_list_for_modal[] = $u['email'];
        $users_for_bulk[] = $u;
    }
}
if (!empty($pic_list_for_modal)) {
    $pic_index_modal = 0;
    $last_task_stmt_modal = $conn->prepare("SELECT pic_email FROM gba_tasks ORDER BY id DESC LIMIT 1");
    if ($last_task_stmt_modal && $last_task_stmt_modal->execute()) {
        $last_task_result_modal = $last_task_stmt_modal->get_result();
        if ($last_task_result_modal->num_rows > 0) {
            $last_pic_email_modal = $last_task_result_modal->fetch_assoc()['pic_email'];
            $last_index_modal = array_search($last_pic_email_modal, $pic_list_for_modal);
            if ($last_index_modal !== false) {
                $pic_index_modal = ($last_index_modal + 1) % count($pic_list_for_modal);
            }
        }
        $last_task_stmt_modal->close();
    }
    $next_pic_email = $pic_list_for_modal[$pic_index_modal];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Add GBA Tasks - Project Manager</title>
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
            --panel-bg: rgba(30, 41, 59, 0.5);
            --panel-border: rgba(51, 65, 85, 0.6);
            --input-bg: rgba(30, 41, 59, 0.75);
            --input-border: #475569;
            --input-text: #f1f5f9;
        }
        html.light {
            --bg-primary: #f8fafc;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --panel-bg: #f8fafc;
            --panel-border: #e2e8f0;
            --input-bg: #ffffff;
            --input-border: #cbd5e1;
            --input-text: #0f172a;
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

        .sub-panel {
            background: var(--panel-bg);
            border: 1px solid var(--panel-border);
            border-radius: 1rem;
        }
        html.light .sub-panel {
            background: #f8fafc;
            border-color: #e2e8f0;
        }

        .themed-input {
            background-color: var(--input-bg);
            border: 1px solid var(--input-border);
            color: var(--input-text);
            border-radius: 0.625rem;
            outline: none;
            transition: all 0.15s ease;
        }
        .themed-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25);
        }
        html.light .themed-input {
            background-color: #ffffff;
            border-color: #cbd5e1;
            color: #0f172a;
        }
        html.light textarea::placeholder {
            color: #64748b;
            opacity: 1;
        }

        /* PIC Mode Toggle */
        .pic-toggle-track {
            display: flex;
            align-items: center;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 0.75rem;
            padding: 3px;
            gap: 3px;
        }
        html.light .pic-toggle-track {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        .pic-toggle-track button {
            padding: 6px 14px;
            border-radius: 0.625rem;
            font-size: 12px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
            color: var(--text-secondary);
            background: transparent;
            white-space: nowrap;
        }
        html.light .pic-toggle-track button {
            color: #334155;
        }
        html.light .pic-toggle-track button:hover {
            color: #0f172a;
            background: #e2e8f0;
        }
        .pic-toggle-track button.active-rr {
            background: #4f46e5 !important;
            color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.35);
        }
        .pic-toggle-track button.active-hist {
            background: #059669 !important;
            color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(5, 150, 105, 0.35);
        }
        .pic-toggle-track button.active-spec {
            background: #d97706 !important;
            color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(217, 119, 6, 0.35);
        }

        /* Card styles for RR PICs */
        .rr-pic-card {
            background-color: var(--panel-bg);
            border: 1px solid var(--panel-border);
            color: var(--text-primary);
            transition: all 0.15s ease;
        }
        html.light .rr-pic-card {
            background-color: #ffffff;
            border-color: #cbd5e1;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        html.light .rr-pic-card:hover {
            border-color: #94a3b8;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
        .rr-pic-card.card-disabled {
            opacity: 0.45;
            filter: grayscale(0.8);
        }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <canvas id="neural-canvas"></canvas>
    <?php include 'header.php'; ?>

    <main class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 flex-grow space-y-6">
        
        <!-- Header Section -->
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-indigo-500/15 border border-indigo-500/30 flex items-center justify-center text-indigo-500 flex-shrink-0 shadow-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-black tracking-tight" style="color: var(--text-primary);">Bulk Add GBA Tasks</h1>
                <p class="text-xs sm:text-sm font-medium" style="color: var(--text-secondary);">Tambah batch tugas baru sekaligus dari data Excel/tabel dengan distribusi cerdas</p>
            </div>
        </div>

        <div class="glass-panel p-5 sm:p-8 w-full space-y-6">
            <form action="handler.php" method="POST" id="bulk-form" class="space-y-6">
                <input type="hidden" name="action" value="create_bulk_gba_task">
                <input type="hidden" name="pic_mode" id="pic_mode_input" value="round_robin">

                <!-- PIC Mode Selector HUD -->
                <div class="sub-panel flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-4">
                    <div>
                        <p class="text-sm font-bold" style="color: var(--text-primary);">Mode Assign PIC</p>
                        <p id="pic-mode-desc" class="text-xs font-medium mt-0.5" style="color: var(--text-secondary);">
                            Distribusi merata ke semua PIC secara bergantian
                        </p>
                    </div>
                    <div class="flex items-center">
                        <div class="pic-toggle-track overflow-x-auto max-w-full" id="pic-toggle-track">
                            <button type="button" id="btn-rr" onclick="setPicMode('round_robin')" class="active-rr">
                                ↺ Round-Robin
                            </button>
                            <button type="button" id="btn-hist" onclick="setPicMode('history')">
                                ⏱ History PIC
                            </button>
                            <button type="button" id="btn-spec" onclick="setPicMode('specific')">
                                👤 Specific PIC
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Specific PIC Dropdown Container -->
                <div id="specific-pic-container" class="hidden sub-panel p-4 border border-amber-300 transition-all">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-amber-500/15 border border-amber-500/30 flex items-center justify-center text-amber-600 font-bold">
                                👤
                            </div>
                            <div>
                                <label for="specific_pic" class="text-xs sm:text-sm font-bold block" style="color: var(--text-primary);">Pilih PIC Tujuan</label>
                                <p class="text-xs font-medium" style="color: var(--text-secondary);">Seluruh task dari teks yang di-paste akan ditugaskan ke PIC ini</p>
                            </div>
                        </div>
                        <div class="w-full sm:w-80">
                            <select id="specific_pic" name="specific_pic" class="themed-input w-full p-2.5 text-xs font-bold focus:ring-2 focus:ring-amber-500 focus:outline-none">
                                <option value="" disabled selected>-- Pilih PIC --</option>
                                <?php foreach ($users_for_bulk as $user): ?>
                                    <option value="<?= htmlspecialchars($user['email']) ?>">
                                        <?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars($user['email']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Round-Robin PIC Checklist & Smart Auto Load Balance Container -->
                <div id="rr-pic-container" class="sub-panel p-4 sm:p-5 rounded-2xl transition-all space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b" style="border-color: var(--panel-border);">
                        <div>
                            <div class="flex items-center gap-2">
                                <div class="w-2 h-2 rounded-full bg-indigo-500"></div>
                                <h3 class="text-xs sm:text-sm font-bold" style="color: var(--text-primary);">Smart Load Balance & PIC Selector (Round-Robin)</h3>
                            </div>
                            <p class="text-xs font-medium mt-0.5" style="color: var(--text-secondary);">Pilih PIC aktif. Beban kerja terdistribusi otomatis (100%), atau aktifkan manual override untuk atur persentase spesifik.</p>
                        </div>
                        <div class="flex items-center gap-2 self-start sm:self-auto">
                            <span id="rr-total-badge" class="px-3 py-1 text-xs font-extrabold rounded-lg border transition-all bg-emerald-100 text-emerald-800 border-emerald-300">
                                Total: <span id="rr-total-pct">100</span>%
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3" id="rr-pic-list">
                        <?php 
                        $user_count = count($users_for_bulk);
                        $default_pct = $user_count > 0 ? floor(100 / $user_count) : 0;
                        $remainder = $user_count > 0 ? (100 - ($default_pct * $user_count)) : 0;
                        foreach ($users_for_bulk as $idx => $user): 
                            $initial_pct = $default_pct + ($idx < $remainder ? 1 : 0);
                        ?>
                            <div class="rr-pic-card p-3 rounded-xl border transition-all flex flex-col justify-between gap-2.5">
                                <div class="flex items-center justify-between gap-2">
                                    <label class="flex items-center gap-2 cursor-pointer select-none flex-grow min-w-0">
                                        <input type="checkbox" name="rr_selected_pics[]" value="<?= htmlspecialchars($user['email']) ?>" checked 
                                            onchange="onRRPicToggle(this)" 
                                            class="rr-pic-checkbox w-4 h-4 rounded border-slate-400 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
                                        <div class="truncate">
                                            <div class="text-xs font-bold truncate" style="color: var(--text-primary);"><?= htmlspecialchars($user['username']) ?></div>
                                            <div class="text-[10px] font-mono truncate" style="color: var(--text-secondary);"><?= htmlspecialchars($user['email']) ?></div>
                                        </div>
                                    </label>
                                    <span class="rr-status-badge px-1.5 py-0.5 text-[9px] font-extrabold rounded border tracking-wider bg-indigo-100 text-indigo-800 border-indigo-300">
                                        AUTO
                                    </span>
                                </div>

                                <div class="flex items-center justify-between gap-2 pt-2 border-t" style="border-color: var(--panel-border);">
                                    <label class="flex items-center gap-1.5 cursor-pointer select-none rr-manual-label" title="Aktifkan untuk ubah persentase PIC ini secara manual">
                                        <input type="checkbox" class="rr-manual-toggle w-3.5 h-3.5 rounded border-slate-400 text-amber-600 focus:ring-amber-500 cursor-pointer"
                                            onchange="onRRManualToggle(this)">
                                        <span class="text-[10px] font-bold" style="color: var(--text-secondary);">Manual</span>
                                    </label>
                                    <div class="flex items-center gap-1">
                                        <input type="number" name="rr_pic_weights[<?= htmlspecialchars($user['email']) ?>]" 
                                            value="<?= $initial_pct ?>" min="1" max="99" readonly
                                            oninput="onRRManualInput(this)" 
                                            class="rr-pct-input w-14 p-1 text-xs text-center font-black rounded themed-input">
                                        <span class="text-xs font-bold" style="color: var(--text-secondary);">%</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="space-y-2">
                    <label for="bulk_data" class="block text-xs sm:text-sm font-bold" style="color: var(--text-primary);">
                        Paste Data dari Excel (Format: MODEL | AP | CP | CSC | TYPE REQUEST | QB USER | QB USERDEBUG)
                    </label>
                    <textarea id="bulk_data" name="bulk_data" rows="12" 
                        class="themed-input block w-full text-xs font-mono rounded-xl p-3.5 leading-relaxed" 
                        placeholder="Contoh:&#10;model ap cp csc type qb_user qb_userdebug&#10;SM-S918B_SEA_15_DX S918BXXS8DYI3 S918BXXS8DYI3 S918BOLE8DYI3 SMR 100733179 100733181&#10;SM-F946B_SEA_16_DX F946BXXU5FYI8 F946BXXU5FYI8 F946BOLE5FYI8 NORMAL 100733177 100733180"></textarea>
                </div>

                <div class="pt-2 flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-t" style="border-color: var(--card-border);">
                    <p class="text-xs font-medium" style="color: var(--text-secondary);">
                        <span id="bulk-mode-hint-rr">↺ <strong style="color: var(--text-primary);">Round-Robin:</strong> PIC dibagi rata secara bergantian melanjutkan dari task terakhir.</span>
                        <span id="bulk-mode-hint-hist" class="hidden">⏱ <strong style="color: var(--text-primary);">History PIC:</strong> Jika model pernah dikerjakan, PIC yang sama akan dipakai. Model baru → round-robin.</span>
                        <span id="bulk-mode-hint-spec" class="hidden">👤 <strong style="color: var(--text-primary);">Specific PIC:</strong> Semua task pada batch ini akan langsung ditugaskan ke satu PIC yang dipilih.</span>
                    </p>
                    <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-500 text-white font-bold text-xs sm:text-sm rounded-xl shadow-lg shadow-blue-600/25 transition-all flex-shrink-0 active:scale-95">
                        + Tambah Tasks
                    </button>
                </div>
            </form>
        </div>
    </main>

    <script>
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
                const pColor = isLight ? 'rgba(99, 102, 241, 0.25)' : 'rgba(129, 140, 248, 0.25)';
                const lColor = isLight ? 'rgba(99, 102, 241, 0.05)' : 'rgba(129, 140, 248, 0.05)';

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

        // --- PIC Mode Controller ---
        const PIC_MODE_KEY = 'bulk_add_pic_mode';
        function setPicMode(mode) {
            document.getElementById('pic_mode_input').value = mode;
            try { localStorage.setItem(PIC_MODE_KEY, mode); } catch(e){}

            const btnRR = document.getElementById('btn-rr');
            const btnHist = document.getElementById('btn-hist');
            const btnSpec = document.getElementById('btn-spec');
            const desc = document.getElementById('pic-mode-desc');
            const specContainer = document.getElementById('specific-pic-container');
            const rrContainer = document.getElementById('rr-pic-container');

            const hintRR = document.getElementById('bulk-mode-hint-rr');
            const hintHist = document.getElementById('bulk-mode-hint-hist');
            const hintSpec = document.getElementById('bulk-mode-hint-spec');

            btnRR.className = '';
            btnHist.className = '';
            btnSpec.className = '';

            hintRR.classList.add('hidden');
            hintHist.classList.add('hidden');
            hintSpec.classList.add('hidden');

            if (mode === 'round_robin') {
                btnRR.className = 'active-rr';
                desc.textContent = 'Distribusi merata ke semua PIC secara bergantian';
                specContainer.classList.add('hidden');
                rrContainer.classList.remove('hidden');
                hintRR.classList.remove('hidden');
                recalculateRRLoadBalances();
            } else if (mode === 'history') {
                btnHist.className = 'active-hist';
                desc.textContent = 'Meniru PIC yang pernah mengerjakan model yang sama';
                specContainer.classList.add('hidden');
                rrContainer.classList.remove('hidden');
                hintHist.classList.remove('hidden');
            } else if (mode === 'specific') {
                btnSpec.className = 'active-spec';
                desc.textContent = 'Seluruh task akan ditugaskan ke satu PIC pilihan';
                specContainer.classList.remove('hidden');
                rrContainer.classList.add('hidden');
                hintSpec.classList.remove('hidden');
            }
        }

        function onRRPicToggle(checkbox) {
            const card = checkbox.closest('.rr-pic-card');
            const manualToggle = card.querySelector('.rr-manual-toggle');
            const pctInput = card.querySelector('.rr-pct-input');
            const statusBadge = card.querySelector('.rr-status-badge');

            if (!checkbox.checked) {
                card.classList.add('card-disabled');
                manualToggle.checked = false;
                pctInput.value = 0;
                pctInput.readOnly = true;
                statusBadge.textContent = 'OFF';
                statusBadge.className = 'rr-status-badge px-1.5 py-0.5 text-[9px] font-extrabold rounded border tracking-wider bg-slate-200 text-slate-600 border-slate-300';
            } else {
                card.classList.remove('card-disabled');
                statusBadge.textContent = 'AUTO';
                statusBadge.className = 'rr-status-badge px-1.5 py-0.5 text-[9px] font-extrabold rounded border tracking-wider bg-indigo-100 text-indigo-800 border-indigo-300';
            }
            recalculateRRLoadBalances();
        }

        function onRRManualToggle(checkbox) {
            const card = checkbox.closest('.rr-pic-card');
            const pctInput = card.querySelector('.rr-pct-input');
            const picCheckbox = card.querySelector('.rr-pic-checkbox');
            const statusBadge = card.querySelector('.rr-status-badge');

            if (!picCheckbox.checked) {
                checkbox.checked = false;
                return;
            }

            if (checkbox.checked) {
                pctInput.readOnly = false;
                pctInput.focus();
                pctInput.select();
                statusBadge.textContent = 'MANUAL';
                statusBadge.className = 'rr-status-badge px-1.5 py-0.5 text-[9px] font-extrabold rounded border tracking-wider bg-amber-100 text-amber-800 border-amber-300';
            } else {
                pctInput.readOnly = true;
                statusBadge.textContent = 'AUTO';
                statusBadge.className = 'rr-status-badge px-1.5 py-0.5 text-[9px] font-extrabold rounded border tracking-wider bg-indigo-100 text-indigo-800 border-indigo-300';
                recalculateRRLoadBalances();
            }
        }

        function onRRManualInput(input) {
            let val = parseInt(input.value) || 0;
            if (val < 1) val = 1;
            if (val > 99) val = 99;
            input.value = val;
            recalculateRRLoadBalances(input);
        }

        function recalculateRRLoadBalances(sourceInput = null) {
            const cards = document.querySelectorAll('.rr-pic-card');
            let manualTotal = 0;
            let autoCards = [];

            cards.forEach(card => {
                const picCheckbox = card.querySelector('.rr-pic-checkbox');
                const manualToggle = card.querySelector('.rr-manual-toggle');
                const pctInput = card.querySelector('.rr-pct-input');

                if (picCheckbox.checked) {
                    if (manualToggle.checked) {
                        manualTotal += parseInt(pctInput.value) || 0;
                    } else {
                        autoCards.push(card);
                    }
                }
            });

            const remainingPct = Math.max(0, 100 - manualTotal);
            if (autoCards.length > 0) {
                const basePct = Math.floor(remainingPct / autoCards.length);
                const remainder = remainingPct - (basePct * autoCards.length);

                autoCards.forEach((card, idx) => {
                    const pctInput = card.querySelector('.rr-pct-input');
                    pctInput.value = basePct + (idx < remainder ? 1 : 0);
                });
            }

            // Hitung grand total
            let grandTotal = 0;
            cards.forEach(card => {
                const picCheckbox = card.querySelector('.rr-pic-checkbox');
                const pctInput = card.querySelector('.rr-pct-input');
                if (picCheckbox.checked) {
                    grandTotal += parseInt(pctInput.value) || 0;
                }
            });

            const totalPctSpan = document.getElementById('rr-total-pct');
            const totalBadge = document.getElementById('rr-total-badge');
            totalPctSpan.textContent = grandTotal;

            if (grandTotal === 100) {
                totalBadge.className = 'px-3 py-1 text-xs font-extrabold rounded-lg border transition-all bg-emerald-100 text-emerald-800 border-emerald-300';
            } else {
                totalBadge.className = 'px-3 py-1 text-xs font-extrabold rounded-lg border transition-all bg-rose-100 text-rose-800 border-rose-300';
            }
        }

        // --- Init State ---
        document.addEventListener('DOMContentLoaded', () => {
            const savedMode = localStorage.getItem(PIC_MODE_KEY) || 'round_robin';
            setPicMode(savedMode);

            // Form Validation before submit
            const form = document.getElementById('bulk-form');
            form.addEventListener('submit', (e) => {
                const currentMode = document.getElementById('pic_mode_input').value;
                if (currentMode === 'specific') {
                    const select = document.getElementById('specific_pic');
                    if (!select.value) {
                        e.preventDefault();
                        alert('Silakan pilih PIC Tujuan terlebih dahulu.');
                        select.focus();
                        return;
                    }
                } else if (currentMode === 'round_robin') {
                    const checkedPics = document.querySelectorAll('.rr-pic-checkbox:checked');
                    if (checkedPics.length === 0) {
                        e.preventDefault();
                        alert('Minimal 1 PIC harus aktif untuk mode Round-Robin.');
                        return;
                    }
                }
            });
        });
    </script>
</body>
</html>