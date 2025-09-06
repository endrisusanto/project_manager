<?php
include 'config.php';

// Inisialisasi variabel untuk menghindari error jika tabel kosong
$stats = [
    'total' => 0, 'new' => 0, 'ongoing' => 0, 'submitted' => 0, 
    'approved' => 0, 'cancelled' => 0, 'delay' => 0, 'ontime' => 0
];
$pic_distribution = [];
$weekly_data = [];
$all_pics = [];

// Fetch all tasks
$tasks_result = $conn->query("SELECT * FROM gba_tasks");
$all_tasks = [];
if ($tasks_result && $tasks_result->num_rows > 0) {
    while($row = $tasks_result->fetch_assoc()) {
        $all_tasks[] = $row;
    }
}

if (!empty($all_tasks)) {
    // Hitung statistik
    $stats['total'] = count($all_tasks);
    foreach ($all_tasks as $task) {
        if ($task['progress_status'] == 'Task Baru') $stats['new']++;
        if ($task['progress_status'] == 'Test Ongoing') $stats['ongoing']++;
        if ($task['progress_status'] == 'Submitted') $stats['submitted']++;
        if ($task['progress_status'] == 'Approved') $stats['approved']++;
        if ($task['progress_status'] == 'Batal') $stats['cancelled']++;

        // Kalkulasi ontime submission
        if ($task['submission_date']) {
            $request_dt = new DateTime($task['request_date']);
            $submission_dt = new DateTime($task['submission_date']);
            if ($submission_dt->diff($request_dt)->days > 5) {
                $stats['delay']++;
            } else {
                $stats['ontime']++;
            }
        }
        
        // Kalkulasi distribusi PIC
        $pic = !empty($task['pic_email']) ? $task['pic_email'] : 'Unassigned';
        if (!isset($pic_distribution[$pic])) {
            $pic_distribution[$pic] = 0;
        }
        $pic_distribution[$pic]++;
    }

    // Persiapan data Chart Mingguan
    $weekly_summary = [];
    $all_pics = array_keys($pic_distribution);
    
    // Inisialisasi data untuk 12 minggu terakhir
    for ($i = 11; $i >= 0; $i--) {
        $date = new DateTime();
        $date->modify("-$i week");
        $week_number = $date->format("W");
        $year = $date->format("Y");
        $week_key = "$year-W$week_number";
        $weekly_summary[$week_key] = ['total' => 0];
        foreach ($all_pics as $pic) {
            $weekly_summary[$week_key][$pic] = 0;
        }
    }

    foreach ($all_tasks as $task) {
        $request_dt = new DateTime($task['request_date']);
        $week_number = $request_dt->format("W");
        $year = $request_dt->format("Y");
        $week_key = "$year-W$week_number";
        $pic = !empty($task['pic_email']) ? $task['pic_email'] : 'Unassigned';

        if (isset($weekly_summary[$week_key])) {
            $weekly_summary[$week_key]['total']++;
            if (isset($weekly_summary[$week_key][$pic])) {
                $weekly_summary[$week_key][$pic]++;
            }
        }
    }
    
    $weekly_data = [
        'labels' => array_keys($weekly_summary),
        'datasets' => []
    ];
    // Garis total
    $weekly_data['datasets'][] = [
        'label' => 'Overall Tasks',
        'data' => array_column($weekly_summary, 'total'),
        'type' => 'line',
        'borderColor' => '#3b82f6',
        'backgroundColor' => 'transparent',
        'tension' => 0.3,
        'yAxisID' => 'y',
    ];
    // Batang per PIC
    foreach ($all_pics as $pic) {
        $color_hash = crc32($pic);
        $weekly_data['datasets'][] = [
            'label' => $pic,
            'data' => array_column($weekly_summary, $pic),
            'type' => 'bar',
            'backgroundColor' => 'hsla(' . ($color_hash % 360) . ', 70%, 50%, 0.7)',
            'yAxisID' => 'y',
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GBA Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #020617; --text-primary: #e2e8f0; --text-secondary: #94a3b8; --glass-bg: rgba(15, 23, 42, 0.6); --glass-border: rgba(51, 65, 85, 0.6); --text-header: #ffffff; --text-icon: #94a3b8;
        }
        html.light {
            --bg-primary: #f1f5f9; --text-primary: #0f172a; --text-secondary: #475569; --glass-bg: rgba(255, 255, 255, 0.6); --glass-border: rgba(0, 0, 0, 0.1); --text-header: #0f172a; --text-icon: #475569;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); }
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
    </style>
</head>
<body class="min-h-screen">
    <canvas id="neural-canvas"></canvas>

    <!-- Header Aplikasi -->
    <header class="glass-container sticky top-0 z-10 shadow-sm">
        <div class="w-full mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center space-x-3">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-blue-600"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" /></svg>
                    <h1 class="text-xl font-bold text-header">Software Project Manager</h1>
                     <div class="flex items-baseline space-x-4 ml-4">
                        <a href="index.php" class="nav-link px-3 py-2 rounded-md text-sm font-medium">Project Dashboard</a>
                        <a href="gba_dashboard.php" class="nav-link-active px-3 py-2 rounded-md text-sm font-medium">GBA Dashboard</a>
                        <a href="gba_tasks.php" class="nav-link px-3 py-2 rounded-md text-sm font-medium">GBA Tasks</a>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                     <button id="theme-toggle" type="button" class="text-icon hover:bg-gray-500/10 rounded-lg text-sm p-2.5 transition-colors duration-200">
                        <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path></svg>
                        <svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path></svg>
                    </button>
                </div>
            </div>
        </div>
    </header>
    
    <main class="pt-24 pb-8">
        <div class="max-w-screen-2xl mx-auto p-4 md:p-6 space-y-6">
            <!-- Stat Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4">
                <div class="glassmorphism p-4 rounded-lg text-center"><p class="text-2xl font-bold"><?= $stats['total'] ?></p><p class="text-sm themed-text-muted">Total Task</p></div>
                <div class="glassmorphism p-4 rounded-lg text-center"><p class="text-2xl font-bold text-blue-400"><?= $stats['new'] ?></p><p class="text-sm themed-text-muted">Task Baru</p></div>
                <div class="glassmorphism p-4 rounded-lg text-center"><p class="text-2xl font-bold text-yellow-400"><?= $stats['ongoing'] ?></p><p class="text-sm themed-text-muted">Ongoing</p></div>
                <div class="glassmorphism p-4 rounded-lg text-center"><p class="text-2xl font-bold text-purple-400"><?= $stats['submitted'] ?></p><p class="text-sm themed-text-muted">Submitted</p></div>
                <div class="glassmorphism p-4 rounded-lg text-center"><p class="text-2xl font-bold text-green-400"><?= $stats['approved'] ?></p><p class="text-sm themed-text-muted">Approved</p></div>
                <div class="glassmorphism p-4 rounded-lg text-center"><p class="text-2xl font-bold text-gray-400"><?= $stats['cancelled'] ?></p><p class="text-sm themed-text-muted">Batal</p></div>
                <div class="glassmorphism p-4 rounded-lg text-center"><p class="text-2xl font-bold text-red-400"><?= $stats['delay'] ?></p><p class="text-sm themed-text-muted">Delay</p></div>
                <div class="glassmorphism p-4 rounded-lg text-center"><p class="text-2xl font-bold text-teal-400"><?= $stats['ontime'] ?></p><p class="text-sm themed-text-muted">Ontime</p></div>
            </div>

            <!-- Charts & Clocks -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Pie Chart & World Clocks -->
                <div class="lg:col-span-1 space-y-6">
                    <div class="glassmorphism p-4 rounded-lg">
                        <h3 class="font-semibold mb-2 themed-text">Distribusi Task per PIC</h3>
                        <canvas id="picPieChart"></canvas>
                    </div>
                    <div class="glassmorphism p-4 rounded-lg">
                        <h3 class="font-semibold mb-4 themed-text">World Time Comparison</h3>
                        <div id="world-clocks" class="space-y-3 text-sm"></div>
                    </div>
                </div>
                <!-- Weekly Chart -->
                <div class="lg:col-span-2 glassmorphism p-4 rounded-lg">
                     <h3 class="font-semibold mb-2 themed-text">Aktivitas Task Mingguan</h3>
                     <canvas id="weeklyTaskChart" style="min-height: 300px;"></canvas>
                </div>
            </div>
        </div>
    </main>

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
        let currentTheme = 'dark';
        function applyTheme(isLight) { 
            currentTheme = isLight ? 'light' : 'dark';
            if (isLight) { 
                document.documentElement.classList.add('light'); 
                lightIcon.classList.remove('hidden'); darkIcon.classList.add('hidden'); 
            } else { 
                document.documentElement.classList.remove('light'); 
                lightIcon.classList.add('hidden'); darkIcon.classList.remove('hidden'); 
            }
            // Re-render charts on theme change
            if(window.picPieChart) window.picPieChart.destroy();
            if(window.weeklyTaskChart) window.weeklyTaskChart.destroy();
            createPicPieChart();
            createWeeklyTaskChart();
        }
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme) { applyTheme(savedTheme === 'light'); } else { applyTheme(window.matchMedia('(prefers-color-scheme: dark)').matches ? false : true); }
        themeToggleBtn.addEventListener('click', () => { 
            const isCurrentlyLight = document.documentElement.classList.contains('light');
            localStorage.setItem('theme', isCurrentlyLight ? 'dark' : 'light');
            applyTheme(!isCurrentlyLight);
        });

        // --- CHART & CLOCKS LOGIC ---
        document.addEventListener('DOMContentLoaded', () => {
            const picData = <?= json_encode($pic_distribution) ?>;
            const weeklyChartData = <?= json_encode($weekly_data) ?>;
            
            // Helper function to get theme-aware colors for charts
            function getChartColors() { 
                return { 
                    ticksColor: currentTheme === 'light' ? '#475569' : '#94a3b8', 
                    gridColor: currentTheme === 'light' ? 'rgba(0, 0, 0, 0.1)' : 'rgba(255, 255, 255, 0.1)' 
                }; 
            }

            // Function to generate a color from a string
            const crc32 = (function() {
                let table = new Uint32Array(256);
                for (let i = 0; i < 256; i++) {
                    let c = i;
                    for (let k = 0; k < 8; k++) {
                        c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
                    }
                    table[i] = c;
                }
                return function(str) {
                    let crc = -1;
                    for (let i = 0; i < str.length; i++) {
                        crc = (crc >>> 8) ^ table[(crc ^ str.charCodeAt(i)) & 0xFF];
                    }
                    return (crc ^ -1) >>> 0;
                };
            })();

            // Create Pie Chart
            window.createPicPieChart = function() {
                const ctx = document.getElementById('picPieChart');
                if (!ctx || !picData || Object.keys(picData).length === 0) return;
                window.picPieChart = new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: Object.keys(picData),
                        datasets: [{
                            label: 'Tasks',
                            data: Object.values(picData),
                            backgroundColor: Object.keys(picData).map(pic => `hsla(${crc32(pic) % 360}, 70%, 60%, 0.8)`),
                            borderColor: currentTheme === 'light' ? '#fff' : '#1f2937', borderWidth: 2
                        }]
                    },
                    options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { color: getChartColors().ticksColor } } } }
                });
            }
            
            // Create Weekly Chart
            window.createWeeklyTaskChart = function() {
                const ctx = document.getElementById('weeklyTaskChart');
                if (!ctx || !weeklyChartData.labels || weeklyChartData.labels.length === 0) return;
                window.weeklyTaskChart = new Chart(ctx, {
                    type: 'bar', 
                    data: weeklyChartData,
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        scales: { 
                            x: { stacked: true, ticks: { color: getChartColors().ticksColor }, grid: { color: getChartColors().gridColor } }, 
                            y: { stacked: true, ticks: { color: getChartColors().ticksColor }, grid: { color: getChartColors().gridColor } } 
                        }, 
                        plugins: { legend: { position: 'top', labels: { color: getChartColors().ticksColor } } } 
                    }
                });
            }

            // --- WORLD CLOCKS ---
            const clocksContainer = document.getElementById('world-clocks');
            const timezones = [ { name: 'Indonesia (WIB)', tz: 'Asia/Jakarta' }, { name: 'Korea Selatan', tz: 'Asia/Seoul' }, { name: 'Vietnam', tz: 'Asia/Ho_Chi_Minh' }, { name: 'India', tz: 'Asia/Kolkata' }, { name: 'China', tz: 'Asia/Shanghai' }, { name: 'Brazil', tz: 'America/Sao_Paulo' }, ];
            function updateClocks() { 
                if (!clocksContainer) return;
                let clocksHTML = ''; 
                const now = new Date(); 
                timezones.forEach(zone => { 
                    try {
                        const time = now.toLocaleTimeString('en-US', { timeZone: zone.tz, hour: '2-digit', minute: '2-digit', hour12: false }); 
                        const date = now.toLocaleDateString('en-GB', { timeZone: zone.tz, weekday: 'short', day: 'numeric', month: 'short' }); 
                        clocksHTML += `<div class="flex justify-between items-center"><span class="themed-text-muted">${zone.name}</span><span class="font-mono font-semibold themed-text">${time} <span class="text-xs themed-text-muted">${date}</span></span></div>`; 
                    } catch(e) {
                        console.error("Could not format time for timezone: ", zone.tz);
                    }
                }); 
                clocksContainer.innerHTML = clocksHTML; 
            }

            // Initial Calls
            createPicPieChart(); 
            createWeeklyTaskChart();
            updateClocks(); 
            setInterval(updateClocks, 1000); // Update every second
        });
    </script>
</body>
</html>

