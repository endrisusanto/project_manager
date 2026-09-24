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
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
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

        <!-- Card 2: Password Change -->
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
        // Background neural particles
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

        document.addEventListener('DOMContentLoaded', function () {

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