<?php
// 1. INISIALISASI SESSION DAN KONEKSI
require_once "config.php";
require_once "session.php"; // Memastikan pengguna sudah login

// Tentukan halaman aktif untuk navigasi header (kosongkan agar tidak ada yang aktif)
$active_page = 'profile'; 

// Ambil detail pengguna dari sesi untuk ditampilkan di form
$user = $_SESSION['user_details'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Saya - Project Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #020617; --text-primary: #e2e8f0; --text-secondary: #94a3b8;
            --glass-bg: rgba(15, 23, 42, 0.8); --glass-border: rgba(51, 65, 85, 0.6);
            --text-header: #ffffff; --input-bg: rgba(30, 41, 59, 0.7); 
            --input-border: #475569; --input-text: #e2e8f0;
        }
        html.light {
            --bg-primary: #f1f5f9; --text-primary: #0f172a; --text-secondary: #475569;
            --glass-bg: rgba(255, 255, 255, 0.7); --glass-border: rgba(0, 0, 0, 0.1);
            --text-header: #0f172a; --input-bg: #ffffff; 
            --input-border: #cbd5e1; --input-text: #0f172a;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); }
        .themed-input { background-color: var(--input-bg); border: 1px solid var(--input-border); color: var(--input-text); }
        .nav-link { color: var(--text-secondary); border-bottom: 2px solid transparent; transition: all 0.2s; }
        .nav-link:hover { border-color: var(--text-secondary); color: var(--text-primary); }
        .nav-link-active { color: var(--text-primary) !important; border-bottom: 2px solid #3b82f6; font-weight: 600; }
        #neural-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; }
        .form-container { background: var(--glass-bg); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <canvas id="neural-canvas"></canvas>

    <?php include 'header.php'; // Menyertakan header yang konsisten ?>

    <main class="w-full max-w-4xl mx-auto p-4 sm:p-8 flex-grow">
        <h1 class="text-3xl font-bold mb-8 text-header">Profil Saya</h1>

        <?php if(isset($_GET['success'])): ?>
            <div class="bg-green-500/20 text-green-300 text-sm p-4 rounded-lg mb-6">
                <?php 
                    if($_GET['success'] === 'picture_updated') echo 'Foto profil berhasil diperbarui.';
                    if($_GET['success'] === 'password_changed') echo 'Password berhasil diubah.';
                ?>
            </div>
        <?php endif; ?>
        <?php if(isset($_GET['error'])): ?>
            <div class="bg-red-500/20 text-red-300 text-sm p-4 rounded-lg mb-6">
                <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>

        <div class="form-container p-6 rounded-2xl mb-8">
            <h2 class="text-xl font-semibold mb-4 text-header">Update Foto Profil</h2>
            <form action="auth_handler.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile_picture">
                <div class="flex items-center space-x-6">
                    <img src="uploads/<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Foto Profil" class="w-24 h-24 rounded-full object-cover border-2 border-gray-600">
                    <div>
                        <label for="profile_picture" class="block mb-2 text-sm font-medium text-secondary">Pilih foto baru (JPG, PNG, GIF | max 2MB)</label>
                        <input type="file" name="profile_picture" id="profile_picture" class="text-sm file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-600/20 file:text-blue-300 hover:file:bg-blue-600/30" required>
                    </div>
                </div>
                <button type="submit" class="mt-4 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">Update Foto</button>
            </form>
        </div>

        <div class="form-container p-6 rounded-2xl">
            <h2 class="text-xl font-semibold mb-4 text-header">Ganti Password</h2>
            <form action="auth_handler.php" method="post">
                <input type="hidden" name="action" value="change_password">
                <div class="space-y-4">
                    <div>
                        <label for="current_password" class="block mb-2 text-sm text-secondary">Password Saat Ini</label>
                        <input type="password" name="current_password" id="current_password" class="w-full p-2.5 themed-input rounded-lg" required>
                    </div>
                    <div>
                        <label for="new_password" class="block mb-2 text-sm text-secondary">Password Baru</label>
                        <input type="password" name="new_password" id="new_password" class="w-full p-2.5 themed-input rounded-lg" required>
                    </div>
                    <div>
                        <label for="confirm_password" class="block mb-2 text-sm text-secondary">Konfirmasi Password Baru</label>
                        <input type="password" name="confirm_password" id="confirm_password" class="w-full p-2.5 themed-input rounded-lg" required>
                    </div>
                </div>
                <button type="submit" class="mt-6 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">Ganti Password</button>
            </form>
        </div>
    </main>

    <script>
        // Skrip untuk animasi latar belakang dan tema (sama seperti di index.php)
        const canvas = document.getElementById('neural-canvas');
        const ctx = canvas.getContext('2d');
        const themeToggleBtn = document.getElementById('theme-toggle');
        let particles = []; let hue = 0;
        function setCanvasSize(){canvas.width=window.innerWidth;canvas.height=window.innerHeight;}setCanvasSize();
        class Particle{constructor(x,y){this.x=x||Math.random()*canvas.width;this.y=y||Math.random()*canvas.height;this.vx=(Math.random()-.5)*.5;this.vy=(Math.random()-.5)*.5;this.size=Math.random()*1.5+1}update(){this.x+=this.vx;this.y+=this.vy;if(this.x<0||this.x>canvas.width)this.vx*=-1;if(this.y<0||this.y>canvas.height)this.vy*=-1}draw(){ctx.fillStyle=`hsl(${hue},100%,70%)`;ctx.beginPath();ctx.arc(this.x,this.y,this.size,0,Math.PI*2);ctx.fill()}}
        function initParticles(num){for(let i=0;i<num;i++)particles.push(new Particle)}
        function animateParticles(){ctx.clearRect(0,0,canvas.width,canvas.height);hue=(hue+.5)%360;particles.forEach(p=>{p.update();p.draw()});requestAnimationFrame(animateParticles)}initParticles(80);animateParticles();window.addEventListener('resize',setCanvasSize);
        function applyTheme(isLight){document.documentElement.classList.toggle('light',isLight);document.getElementById('theme-toggle-light-icon').classList.toggle('hidden',!isLight);document.getElementById('theme-toggle-dark-icon').classList.toggle('hidden',isLight)}const savedTheme=localStorage.getItem('theme');applyTheme(savedTheme==='light');themeToggleBtn.addEventListener('click',()=>{const isLight=!document.documentElement.classList.contains('light');localStorage.setItem('theme',isLight?'light':'dark');applyTheme(isLight)});
        
        // Skrip untuk dropdown profil
        document.addEventListener('DOMContentLoaded', function () {
            const profileMenu=document.getElementById('profile-menu');
            if(profileMenu){
                const profileButton=profileMenu.querySelector('button'),profileDropdown=document.getElementById('profile-dropdown');
                profileButton.addEventListener('click',e=>{e.stopPropagation();profileDropdown.classList.toggle('hidden')});
                document.addEventListener('click',e=>{if(!profileMenu.contains(e.target)){profileDropdown.classList.add('hidden')}});
            }
            // Hapus parameter notifikasi dari URL setelah ditampilkan
            if (window.location.search.includes('success=') || window.location.search.includes('error=')) {
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });
    </script>
</body>
</html>