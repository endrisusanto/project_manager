<?php
// 1. INISIALISASI
require_once "config.php";
require_once "session.php"; 
$active_page = 'activity_log'; // Set active page for header.php

// Cek apakah pengguna sudah login
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// 2. LOGIKA PENGAMBILAN DATA
// Ambil data log aktivitas, gabungkan dengan tabel users untuk mendapatkan username dan profile_picture
$sql = "SELECT al.*, u.username, u.profile_picture, 
               CASE 
                   WHEN al.task_id IS NOT NULL THEN t.model_name 
                   ELSE NULL 
               END as task_model_name
        FROM activity_log al
        LEFT JOIN users u ON al.user_email = u.email
        LEFT JOIN gba_tasks t ON al.task_id = t.id
        ORDER BY al.action_time DESC 
        LIMIT 100"; // Batasi hingga 100 log terbaru

$result = $conn->query($sql);
$logs = [];
if ($result) {
    while($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
}

// Helper untuk mendapatkan warna berdasarkan action_type
function getLogColor($action_type) {
    switch ($action_type) {
        case 'TASK_CREATED':
            return 'bg-green-500';
        case 'TASK_UPDATED':
            return 'bg-yellow-500';
        case 'STATUS_CHANGE':
            return 'bg-blue-500';
        case 'STATUS_TRACKER_CHANGE':
            return 'bg-indigo-500';
        case 'TOGGLE_URGENT':
            return 'bg-purple-500';
        case 'TASK_DELETED':
            return 'bg-red-500';
        default:
            return 'bg-gray-500';
    }
}

// Helper untuk inisial (fallback jika gambar gagal)
function getPicInitials($email) {
    if (empty($email)) return '?';
    $parts = explode('@', $email);
    return strtoupper(substr($parts[0], 0, 1));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log - Timeline</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* CSS Variables for Dark/Light Mode */
        :root{--bg-primary:#020617;--text-primary:#e2e8f0;--text-secondary:#94a3b8;--glass-bg:rgba(15,23,42,.4);--glass-border:rgba(51,65,85,.4);--card-bg:rgba(15,23,42,.6);--card-border:rgba(51,65,85,.6);--text-header:#fff;--text-icon:#94a3b8;--input-bg:rgba(30,41,59,.7);--input-border:#475569;}
        html.light{--bg-primary:#f1f5f9;--text-primary:#0f172a;--text-secondary:#475569;--glass-bg:rgba(255,255,255,.7);--glass-border:rgba(0,0,0,.1);--card-bg:rgba(255,255,255,.8);--card-border:rgba(0,0,0,.1);--text-header:#0f172a;--text-icon:#475569;--input-bg:#ffffff;--input-border:#cbd5e1;}
        
        body{font-family:'Inter',sans-serif;background-color:var(--bg-primary);color:var(--text-primary)}
        .main-container{height:calc(100vh - 64px);overflow-y:auto; padding: 1.5rem;}
        .glass-card { 
            background: var(--card-bg); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px); 
            border: 1px solid var(--card-border); 
            border-radius: 0.75rem; 
            /* Tambahkan transisi untuk animasi hover */
            transition: transform 0.2s ease-out, box-shadow 0.2s ease-out, background 0.2s ease-out;
        }
        
        /* Animasi Hover Card Log */
        .glass-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }
        html.light .glass-card:hover {
             box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }
        
        /* Timeline specific CSS */
        .timeline {
            border-left: 2px solid var(--card-border);
            position: relative;
            padding-left: 20px;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 30px;
        }
        .timeline-badge {
            position: absolute;
            left: -11px;
            top: 4px; /* Adjust top position */
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 3px solid var(--bg-primary);
            z-index: 10;
        }
        html.light .timeline-badge {
            border-color: #f1f5f9; /* Match light background */
        }
        .profile-img {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
            border: 1px solid var(--text-secondary);
        }

        /* Scroll Animation CSS */
        .fade-in {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.6s ease-out, transform 0.6s ease-out;
        }

        .fade-in.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .fade-in:nth-child(even) {
            transition-delay: 0.1s; /* Staggered effect for every item */
        }
        /* End Scroll Animation CSS */
        .nav-link-active{
            color:var(--text-primary)!important;
            font-weight:500;
            border-bottom:2px solid #3b82f6 /* Ini yang menciptakan underline */
        }
    </style>
</head>
<body class="h-screen flex flex-col">
    <?php include 'header.php'; ?>

    <main class="main-container">
        <!-- <h1 class="text-3xl font-bold text-header mb-6">Activity Timeline Log</h1> -->

        <div class="max-w-4xl mx-auto">
            <div class="timeline">
                <?php if (empty($logs)): ?>
                    <div class="text-center text-secondary p-8 glass-card">
                        Tidak ada catatan aktivitas yang ditemukan.
                    </div>
                <?php endif; ?>

                <?php foreach ($logs as $log): 
                    $color_class = getLogColor($log['action_type']);
                    $username = htmlspecialchars($log['username'] ?? $log['user_email']);
                    $action_time = date('d M Y, H:i:s', strtotime($log['action_time']));
                    $profile_pic = $log['profile_picture'] ?? 'default.png';
                ?>
                    <div class="timeline-item fade-in"> <div class="timeline-badge <?= $color_class ?>"></div>
                        <div class="glass-card p-4 ml-4">
                            
                            <div class="flex items-center mb-1">
                                <img src="uploads/<?= htmlspecialchars($profile_pic) ?>" 
                                     onerror="this.onerror=null; this.src='uploads/default.png';" 
                                     alt="P" 
                                     class="profile-img mr-2"
                                >
                                <p class="text-sm text-secondary">
                                    <span class="font-bold text-primary"><?= $username ?></span>
                                    melakukan aksi
                                </p>
                            </div>
                            
                            <h3 class="font-semibold text-lg mb-2 text-primary border-b pb-2 border-gray-700/50">
                                <?= htmlspecialchars(str_replace('_', ' ', $log['action_type'])) ?>
                                <?php if ($log['task_model_name']): ?>
                                    <span class='text-base font-normal text-secondary'>— Task: <?= htmlspecialchars($log['task_model_name']) ?></span>
                                <?php endif; ?>
                            </h3>
                            
                            <div class="text-sm text-secondary mt-2">
                                <?php
                                $details_string = $log['details'];
                                
                                // Cek apakah itu adalah log TASK_UPDATED dengan banyak perubahan
                                if ($log['action_type'] === 'TASK_UPDATED' && strpos($details_string, 'Changes: ') !== false) {
                                    
                                    // Pisahkan konteks awal dan daftar perubahan
                                    $parts = explode(' Changes: ', $details_string, 2);
                                    $context_prefix = $parts[0] ?? '';
                                    $changes_list = $parts[1] ?? '';
                                    
                                    // Tampilkan konteks awal
                                    if (!empty($context_prefix)) {
                                        echo "<p class='mb-2 text-primary'>" . htmlspecialchars($context_prefix) . "</p>";
                                    }
                                    
                                    // Pecah daftar perubahan berdasarkan pemisah ' | '
                                    $changes = explode(' | ', $changes_list);
                                    
                                    // Tampilkan sebagai bullet list
                                    echo "<ul class='list-disc list-inside space-y-1 pl-4 text-primary'>";
                                    foreach ($changes as $change) {
                                        echo "<li class='text-sm text-secondary'>". htmlspecialchars($change) . "</li>";
                                    }
                                    echo "</ul>";
                                    
                                } else {
                                    // Untuk semua log jenis lain (CREATED, DELETED, STATUS_CHANGE, dll.)
                                    echo "<p class='border-l-2 border-gray-600 pl-2 text-secondary'>". htmlspecialchars($details_string) . "</p>";
                                }
                                ?>
                            </div>
                            
                            <p class="text-xs mt-3 text-secondary text-right">
                                **Waktu Aksi:** <?= $action_time ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
    
    <script>
        // =========================================================================
        // DARK MODE TOGGLE SCRIPT
        // =========================================================================
        const root = document.documentElement;
        
        function applyTheme(isLight) {
            root.classList.toggle('light', isLight);
            root.classList.toggle('dark', !isLight); 
            
            const lightIcon = document.getElementById('theme-toggle-light-icon');
            const darkIcon = document.getElementById('theme-toggle-dark-icon');
            if (lightIcon) lightIcon.classList.toggle('hidden', !isLight);
            if (darkIcon) darkIcon.classList.toggle('hidden', isLight);
        }

        // =========================================================================
        // SCROLL ANIMATION (Intersection Observer)
        // =========================================================================
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target); // Stop observing once visible
                }
            });
        }, {
            threshold: 0.1 // Mulai animasi ketika 10% elemen terlihat
        });

        document.addEventListener('DOMContentLoaded', () => {
            
            // 1. Inisialisasi Tema
            const savedTheme = localStorage.getItem('theme') || 'dark';
            const initialIsLight = savedTheme === 'light';
            applyTheme(initialIsLight);
            
            const themeToggleBtn = document.getElementById('theme-toggle');
            if (themeToggleBtn) {
                themeToggleBtn.addEventListener('click', () => {
                    const isCurrentlyLight = root.classList.contains('light');
                    const newIsLight = !isCurrentlyLight;

                    localStorage.setItem('theme', newIsLight ? 'light' : 'dark');
                    applyTheme(newIsLight);
                });
            }

            // 2. Observer Setup (Scroll Animation)
            document.querySelectorAll('.fade-in').forEach(item => {
                observer.observe(item);
            });
            
            // 3. Script untuk memastikan gambar default dimuat jika ada error
            document.querySelectorAll('.profile-img').forEach(img => {
                img.onerror = function() {
                    this.onerror = null; 
                    this.src = 'uploads/default.png';
                };
            });
            
            // 4. Script untuk mengaktifkan dropdown profil
            const profileMenu = document.getElementById('profile-menu');
            if (profileMenu) {
                const profileButton = profileMenu.querySelector('button');
                const profileDropdown = document.getElementById('profile-dropdown');
                
                if (profileButton && profileDropdown) {
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
            }
        });
    </script>
    </body>
</html>