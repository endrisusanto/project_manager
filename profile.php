<?php
// profile.php
require_once "config.php";
require_once "session.php";

$active_page = 'profile'; 
$user = $_SESSION['user_details'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <script>
        if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');
        if(localStorage.getItem('disable_canvas_animation')==='true')document.documentElement.classList.add('disable-canvas-animation');
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Saya - Project Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['class', '.never-match-dark']
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #020617;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --card-bg: rgba(15, 23, 42, 0.75);
            --card-border: rgba(51, 65, 85, 0.65);
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
            background-color: #ffffff;
            border-color: #e2e8f0;
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05);
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
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <canvas id="neural-canvas"></canvas>

    <?php include 'header.php'; ?>

    <main class="w-full max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 flex-grow space-y-6">
        
        <!-- Header Banner -->
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-500/15 border border-blue-500/30 flex items-center justify-center text-blue-600 flex-shrink-0 shadow-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-black tracking-tight" style="color: var(--text-primary);">Profil Pengguna</h1>
                <p class="text-xs sm:text-sm font-medium" style="color: var(--text-secondary);">Kelola informasi akun, avatar, dan keamanan kata sandi Anda</p>
            </div>
        </div>

        <?php if(isset($_GET['success'])): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs sm:text-sm p-4 rounded-xl flex items-center gap-2 font-medium">
                <svg class="w-4 h-4 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span><?= htmlspecialchars($_GET['success']); ?></span>
            </div>
        <?php endif; ?>

        <?php if(isset($_GET['error'])): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 text-xs sm:text-sm p-4 rounded-xl flex items-center gap-2 font-medium">
                <svg class="w-4 h-4 text-rose-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <span><?= htmlspecialchars($_GET['error']); ?></span>
            </div>
        <?php endif; ?>

        <!-- Card 1: Avatar Update -->
        <div class="glass-panel p-5 sm:p-8 space-y-4">
            <div class="flex items-center gap-2 border-b pb-3" style="border-color: var(--card-border);">
                <div class="w-2.5 h-2.5 rounded-full bg-blue-600"></div>
                <h2 class="text-base font-bold" style="color: var(--text-primary);">Foto Profil</h2>
            </div>
            
            <form action="auth_handler.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile_picture">
                <div class="flex flex-col sm:flex-row items-center sm:items-start gap-6 pt-2">
                    <div class="relative group flex-shrink-0">
                        <img src="uploads/<?= htmlspecialchars($user['profile_picture'] ?? 'default.png'); ?>" 
                             alt="Foto Profil" 
                             onerror="this.src='uploads/default.png'"
                             class="w-24 h-24 sm:w-28 sm:h-28 rounded-2xl object-cover border-2 border-blue-500/40 shadow-md group-hover:scale-105 transition-transform duration-200">
                    </div>
                    <div class="flex-1 space-y-3.5 text-center sm:text-left w-full">
                        <div>
                            <span class="text-base font-black block" style="color: var(--text-primary);"><?= htmlspecialchars($_SESSION['username'] ?? 'User'); ?></span>
                            <span class="text-xs font-mono font-medium" style="color: var(--text-secondary);"><?= htmlspecialchars($user['email'] ?? ''); ?></span>
                        </div>
                        <div>
                            <label for="profile_picture" class="block mb-1.5 text-xs font-bold" style="color: var(--text-primary);">Pilih Foto Baru (JPG, PNG, GIF | max 2MB)</label>
                            <input type="file" name="profile_picture" id="profile_picture" 
                                   class="w-full sm:w-auto text-xs file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer" style="color: var(--text-secondary);" required>
                        </div>
                        <div class="pt-1">
                            <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold rounded-xl shadow-md shadow-blue-600/25 transition-all active:scale-95">
                                Simpan Foto Baru
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Card 2: Performance & Appearance Settings (Ponytail Lean Resource Saver) -->
        <div class="glass-panel p-5 sm:p-8 space-y-4">
            <div class="flex items-center justify-between border-b pb-3" style="border-color: var(--card-border);">
                <div class="flex items-center gap-2">
                    <div class="w-2.5 h-2.5 rounded-full bg-emerald-500"></div>
                    <h2 class="text-base font-bold" style="color: var(--text-primary);">Tampilan & Penghemat Resource (GPU/CPU)</h2>
                </div>
                <span id="canvas-active-badge" class="text-[11px] font-semibold px-2.5 py-0.5 rounded-full bg-emerald-500/15 text-emerald-600 border border-emerald-500/30 flex items-center gap-1.5">
                    <svg class="w-3 h-3 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Hemat Resource
                </span>
            </div>

            <div class="space-y-3 pt-1">
                <p class="text-xs leading-relaxed" style="color: var(--text-secondary);">
                    Pilih mode animasi latar belakang canvas (Neural Particles) yang sesuai dengan spesifikasi perangkat Anda. Mode <strong>Hemat GPU</strong> dan <strong>Statis</strong> dirancang khusus untuk laptop standar agar browsing tetap sangat ringan, hemat baterai, dan responsif.
                </p>

                <!-- 4-Tier Performance Option Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1" id="canvas-mode-options">
                    
                    <!-- Option 1: Eco Mode (Default & Recommended) -->
                    <label class="relative flex flex-col p-3.5 rounded-xl border cursor-pointer transition-all hover:border-emerald-500/50 canvas-mode-card" data-mode="eco" style="background: var(--input-bg); border-color: var(--card-border);">
                        <input type="radio" name="canvas_perf_mode" value="eco" class="sr-only">
                        <div class="flex items-center justify-between mb-1.5">
                            <div class="flex items-center gap-2">
                                <span class="text-base">🌿</span>
                                <span class="text-xs font-bold" style="color: var(--text-primary);">Eco / Hemat GPU</span>
                            </div>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">Rekomendasi</span>
                        </div>
                        <p class="text-[11px] leading-relaxed" style="color: var(--text-secondary);">
                            Animasi tetap berjalan perlahan (~18 FPS, 20 partikel), memangkas 75% beban GPU, dan auto-pause saat tab tidak aktif.
                        </p>
                    </label>

                    <!-- Option 2: Static Mode (0% GPU) -->
                    <label class="relative flex flex-col p-3.5 rounded-xl border cursor-pointer transition-all hover:border-blue-500/50 canvas-mode-card" data-mode="static" style="background: var(--input-bg); border-color: var(--card-border);">
                        <input type="radio" name="canvas_perf_mode" value="static" class="sr-only">
                        <div class="flex items-center justify-between mb-1.5">
                            <div class="flex items-center gap-2">
                                <span class="text-base">🖼️</span>
                                <span class="text-xs font-bold" style="color: var(--text-primary);">Statis (0% GPU)</span>
                            </div>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-blue-500/20 text-blue-400 border border-blue-500/30">0% GPU Load</span>
                        </div>
                        <p class="text-[11px] leading-relaxed" style="color: var(--text-secondary);">
                            Jaring neural digambar 1 kali saja saat halaman dibuka. Visual tetap estetik tanpa ada looping animasi sama sekali.
                        </p>
                    </label>

                    <!-- Option 3: Full 60 FPS -->
                    <label class="relative flex flex-col p-3.5 rounded-xl border cursor-pointer transition-all hover:border-purple-500/50 canvas-mode-card" data-mode="full" style="background: var(--input-bg); border-color: var(--card-border);">
                        <input type="radio" name="canvas_perf_mode" value="full" class="sr-only">
                        <div class="flex items-center justify-between mb-1.5">
                            <div class="flex items-center gap-2">
                                <span class="text-base">⚡</span>
                                <span class="text-xs font-bold" style="color: var(--text-primary);">Penuh (60 FPS)</span>
                            </div>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-purple-500/20 text-purple-400 border border-purple-500/30">Visual Maksimal</span>
                        </div>
                        <p class="text-[11px] leading-relaxed" style="color: var(--text-secondary);">
                            Animasi 60 FPS dengan partikel penuh (40 partikel). Untuk laptop/PC berspesifikasi tinggi atau dedicated GPU.
                        </p>
                    </label>

                    <!-- Option 4: Disabled -->
                    <label class="relative flex flex-col p-3.5 rounded-xl border cursor-pointer transition-all hover:border-slate-500/50 canvas-mode-card" data-mode="off" style="background: var(--input-bg); border-color: var(--card-border);">
                        <input type="radio" name="canvas_perf_mode" value="off" class="sr-only">
                        <div class="flex items-center justify-between mb-1.5">
                            <div class="flex items-center gap-2">
                                <span class="text-base">🚫</span>
                                <span class="text-xs font-bold" style="color: var(--text-primary);">Nonaktif</span>
                            </div>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-slate-500/20 text-slate-400 border border-slate-500/30">Tanpa Canvas</span>
                        </div>
                        <p class="text-[11px] leading-relaxed" style="color: var(--text-secondary);">
                            Sembunyikan background canvas sepenuhnya untuk latar belakang solid murni tanpa efek visual grafis.
                        </p>
                    </label>

                </div>

                <!-- Granular UX Motion Reduction Toggle -->
                <div class="flex items-center justify-between gap-4 p-3.5 rounded-xl border mt-3 transition-colors" style="background: var(--input-bg); border-color: var(--card-border);">
                    <div class="space-y-0.5 pr-2">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold" style="color: var(--text-primary);">Kurangi Efek Animasi UI (CSS Glow & Infinite Pulses)</span>
                            <span class="text-[9px] font-bold px-1.5 py-0.2 rounded bg-indigo-500/15 text-indigo-400 border border-indigo-500/30">UX Motion</span>
                        </div>
                        <p class="text-[11px] leading-relaxed" style="color: var(--text-secondary);">
                            Hentikan animasi CSS looping seperti glowing box-shadow, pulse alert, dan efek sparkle agar GPU laptop tidak bekerja di latar belakang.
                        </p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer select-none flex-shrink-0">
                        <input type="checkbox" id="toggle-reduce-motion" class="sr-only peer">
                        <div class="w-10 h-5 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                    </label>
                </div>
            </div>
        </div>

        <!-- Card 3: Password Change -->
        <div class="glass-panel p-5 sm:p-8 space-y-4">
            <div class="flex items-center gap-2 border-b pb-3" style="border-color: var(--card-border);">
                <div class="w-2.5 h-2.5 rounded-full bg-amber-500"></div>
                <h2 class="text-base font-bold" style="color: var(--text-primary);">Keamanan & Ganti Password</h2>
            </div>

            <form action="auth_handler.php" method="post" class="space-y-4 pt-2">
                <input type="hidden" name="action" value="change_password">
                
                <div class="space-y-3.5">
                    <div>
                        <label for="current_password" class="block mb-1 text-xs font-bold" style="color: var(--text-primary);">Password Saat Ini</label>
                        <input type="password" name="current_password" id="current_password" class="w-full p-2.5 themed-input text-xs" required autocomplete="current-password">
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label for="new_password" class="block mb-1 text-xs font-bold" style="color: var(--text-primary);">Password Baru</label>
                            <input type="password" name="new_password" id="new_password" class="w-full p-2.5 themed-input text-xs" required autocomplete="new-password">
                        </div>
                        <div>
                            <label for="confirm_password" class="block mb-1 text-xs font-bold" style="color: var(--text-primary);">Konfirmasi Password Baru</label>
                            <input type="password" name="confirm_password" id="confirm_password" class="w-full p-2.5 themed-input text-xs" required autocomplete="new-password">
                        </div>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold rounded-xl shadow-md shadow-blue-600/25 transition-all active:scale-95">
                        Perbarui Password
                    </button>
                </div>
            </form>
        </div>

    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Multi-Tier Canvas Performance Mode Controller
            var modeCards = document.querySelectorAll('.canvas-mode-card');
            var activeBadge = document.getElementById('canvas-active-badge');
            var reduceMotionToggle = document.getElementById('toggle-reduce-motion');

            function getActiveCanvasMode() {
                var saved = localStorage.getItem('canvas_performance_mode');
                if (!saved) {
                    if (localStorage.getItem('disable_canvas_animation') === 'true') return 'off';
                    return 'eco';
                }
                return saved;
            }

            function updateModeUI(selectedMode) {
                modeCards.forEach(function(card) {
                    var mode = card.getAttribute('data-mode');
                    var radio = card.querySelector('input[type="radio"]');
                    if (mode === selectedMode) {
                        radio.checked = true;
                        card.style.borderColor = (mode === 'eco' ? '#10b981' : (mode === 'static' ? '#3b82f6' : (mode === 'full' ? '#a855f7' : '#94a3b8')));
                        card.style.boxShadow = '0 0 0 2px ' + (mode === 'eco' ? 'rgba(16,185,129,0.3)' : (mode === 'static' ? 'rgba(59,130,246,0.3)' : (mode === 'full' ? 'rgba(168,85,247,0.3)' : 'rgba(148,163,184,0.3)')));
                    } else {
                        radio.checked = false;
                        card.style.borderColor = 'var(--card-border)';
                        card.style.boxShadow = 'none';
                    }
                });

                if (activeBadge) {
                    if (selectedMode === 'eco') {
                        activeBadge.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Eco (18 FPS)';
                        activeBadge.className = 'text-[11px] font-semibold px-2.5 py-0.5 rounded-full bg-emerald-500/15 text-emerald-500 border border-emerald-500/30 flex items-center gap-1.5';
                    } else if (selectedMode === 'static') {
                        activeBadge.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span> Statis (0% GPU)';
                        activeBadge.className = 'text-[11px] font-semibold px-2.5 py-0.5 rounded-full bg-blue-500/15 text-blue-500 border border-blue-500/30 flex items-center gap-1.5';
                    } else if (selectedMode === 'full') {
                        activeBadge.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span> Full 60 FPS';
                        activeBadge.className = 'text-[11px] font-semibold px-2.5 py-0.5 rounded-full bg-purple-500/15 text-purple-500 border border-purple-500/30 flex items-center gap-1.5';
                    } else {
                        activeBadge.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-slate-500"></span> Nonaktif';
                        activeBadge.className = 'text-[11px] font-semibold px-2.5 py-0.5 rounded-full bg-slate-500/15 text-slate-400 border border-slate-500/30 flex items-center gap-1.5';
                    }
                }
            }

            var currentMode = getActiveCanvasMode();
            updateModeUI(currentMode);

            // Initialize reduce motion toggle
            var savedReduceMotion = localStorage.getItem('reduce_ui_motion');
            if (reduceMotionToggle) {
                reduceMotionToggle.checked = savedReduceMotion === 'true';
                reduceMotionToggle.addEventListener('change', function() {
                    var enabled = this.checked;
                    localStorage.setItem('reduce_ui_motion', enabled ? 'true' : 'false');
                    document.documentElement.classList.toggle('reduce-ui-motion', enabled);
                    window.dispatchEvent(new CustomEvent('reducemotionchanged', { detail: { enabled: enabled } }));
                });
            }

            modeCards.forEach(function(card) {
                card.addEventListener('click', function() {
                    var mode = this.getAttribute('data-mode');
                    localStorage.setItem('canvas_performance_mode', mode);
                    localStorage.setItem('disable_canvas_animation', mode === 'off' ? 'true' : 'false');
                    
                    updateModeUI(mode);

                    // Real-time notify canvas engine in header.php
                    window.dispatchEvent(new CustomEvent('canvasperformancechanged', { detail: { mode: mode } }));
                });
            });

            // URL Cleanup
            if (window.location.search.includes('success=') || window.location.search.includes('error=')) {
                setTimeout(() => {
                    window.history.replaceState({}, document.title, window.location.pathname);
                }, 3000);
            }
        });
    </script>
</body>
</html>