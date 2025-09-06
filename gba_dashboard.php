<?php
include 'config.php';

// --- PENGATURAN DATA ---

// Ambil semua task dari database
$tasks_result = $conn->query("SELECT * FROM gba_tasks WHERE request_date IS NOT NULL ORDER BY request_date ASC");
$all_tasks = [];
$available_years = [];
if ($tasks_result && $tasks_result->num_rows > 0) {
    while($row = $tasks_result->fetch_assoc()) {
        $all_tasks[] = $row;
        // Kumpulkan tahun-tahun yang tersedia untuk filter
        $year = date('Y', strtotime($row['request_date']));
        if (!in_array($year, $available_years)) {
            $available_years[] = $year;
        }
    }
}
sort($available_years);

// Inisialisasi variabel
$stats = ['total' => 0, 'new' => 0, 'ongoing' => 0, 'submitted' => 0, 'approved' => 0, 'cancelled' => 0, 'delay' => 0, 'ontime' => 0];
$weekly_pic_distribution = [];
$weekly_data = [];
$all_pics = [];

if (!empty($all_tasks)) {
    // --- 1. LOGIKA UNTUK DONUT CHART MINGGUAN (Rabu - Selasa) ---

    // Tentukan rentang minggu ini (Rabu s/d Selasa berikutnya)
    $today = new DateTime();
    $day_of_week = $today->format('w'); // 0 (Minggu) - 6 (Sabtu)
    // Hitung mundur ke hari Rabu terakhir (w=3)
    $days_to_subtract = ($day_of_week < 3) ? (7 + $day_of_week - 3) : ($day_of_week - 3);
    $start_of_week = (new DateTime())->modify("-$days_to_subtract days");
    $end_of_week = (clone $start_of_week)->modify("+6 days");

    // Filter tasks untuk minggu ini saja
    $tasks_this_week = array_filter($all_tasks, function($task) use ($start_of_week, $end_of_week) {
        $request_dt = new DateTime($task['request_date']);
        return $request_dt >= $start_of_week && $request_dt <= $end_of_week;
    });

    // Hitung distribusi PIC untuk donut chart berdasarkan data minggu ini
    foreach ($tasks_this_week as $task) {
        $pic = !empty($task['pic_email']) ? $task['pic_email'] : 'Unassigned';
        if (!isset($weekly_pic_distribution[$pic])) {
            $weekly_pic_distribution[$pic] = 0;
        }
        $weekly_pic_distribution[$pic]++;
    }
    arsort($weekly_pic_distribution);


    // --- 2. LOGIKA UNTUK CHART TAHUNAN DENGAN FILTER ---

    // Tentukan tahun yang akan ditampilkan
    $current_year = date('Y');
    $selected_year = isset($_GET['year']) && in_array($_GET['year'], $available_years) ? $_GET['year'] : end($available_years);

    // Filter tasks berdasarkan tahun yang dipilih
    $tasks_for_yearly_chart = array_filter($all_tasks, function($task) use ($selected_year) {
        return date('Y', strtotime($task['request_date'])) == $selected_year;
    });

    // Hitung statistik keseluruhan berdasarkan semua task
    foreach ($all_tasks as $task) {
        if ($task['progress_status'] == 'Task Baru') $stats['new']++;
        if ($task['progress_status'] == 'Test Ongoing') $stats['ongoing']++;
        if ($task['progress_status'] == 'Submitted') $stats['submitted']++;
        if ($task['progress_status'] == 'Approved') $stats['approved']++;
        if ($task['progress_status'] == 'Batal') $stats['cancelled']++;
        if ($task['submission_date']) {
            $request_dt = new DateTime($task['request_date']);
            $submission_dt = new DateTime($task['submission_date']);
            if ($submission_dt->diff($request_dt)->days > 7) $stats['delay']++; else $stats['ontime']++;
        }
    }
    $stats['total'] = count($all_tasks);


    // Siapkan data untuk chart tahunan
    $all_pics = array_unique(array_column($all_tasks, 'pic_email'));
    $weekly_summary = [];

    // Inisialisasi 52 minggu untuk tahun yang dipilih
    $year_start_date = new DateTime("{$selected_year}-01-01");
    for ($i = 0; $i < 52; $i++) {
        $week_date = (clone $year_start_date)->modify("+$i week");
        $week_key = $week_date->format("Y-W");
        $weekly_summary[$week_key] = ['total' => 0];
        foreach ($all_pics as $pic) {
            if(!empty($pic)) $weekly_summary[$week_key][$pic] = 0;
        }
    }

    foreach ($tasks_for_yearly_chart as $task) {
        $request_dt = new DateTime($task['request_date']);
        $week_key = $request_dt->format("Y-W");
        $pic = !empty($task['pic_email']) ? $task['pic_email'] : 'Unassigned';

        if (isset($weekly_summary[$week_key])) {
            $weekly_summary[$week_key]['total']++;
            if (isset($weekly_summary[$week_key][$pic])) {
                $weekly_summary[$week_key][$pic]++;
            }
        }
    }

    $weekly_data = ['labels' => array_keys($weekly_summary), 'datasets' => []];
    $weekly_data['datasets'][] = ['label' => 'Total Tasks', 'data' => array_column($weekly_summary, 'total'), 'type' => 'line', 'borderColor' => '#3b82f6', 'backgroundColor' => 'transparent', 'tension' => 0.4, 'yAxisID' => 'y', 'order' => 0, 'pointRadius' => 0, 'borderWidth' => 2];

    $pic_colors = [];
    $color_palette = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#0ea5e9', '#8b5cf6', '#ec4899', '#f43f5e', '#fb923c', '#facc15', '#4ade80', '#38bdf8', '#a855f7', '#f87171', '#fbbf24', '#fde047', '#86efad', '#7dd3fc', '#c084fc', '#e879f9'];
    $color_index = 0;
    foreach ($all_pics as $pic) {
        if(empty($pic)) continue;
        $color = $color_palette[$color_index % count($color_palette)];
        $pic_colors[$pic] = $color;
        $weekly_data['datasets'][] = ['label' => $pic, 'data' => array_column($weekly_summary, $pic), 'type' => 'bar', 'backgroundColor' => $color, 'yAxisID' => 'y', 'order' => 1, 'barPercentage' => 0.7, 'categoryPercentage' => 0.8];
        $color_index++;
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #020617; --text-primary: #e2e8f0; --text-secondary: #94a3b8; --glass-bg: rgba(15, 23, 42, 0.6); --glass-border: rgba(51, 65, 85, 0.6); --text-header: #ffffff; --text-icon: #94a3b8; --input-bg: rgba(30, 41, 59, 0.7);
        }
        html.light {
            --bg-primary: #f1f5f9; --text-primary: #0f172a; --text-secondary: #475569; --glass-bg: rgba(255, 255, 255, 0.7); --glass-border: rgba(0, 0, 0, 0.1); --text-header: #0f172a; --text-icon: #475569; --input-bg: #ffffff;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); }
        #neural-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; }
        .glass-header { background: var(--glass-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border-bottom: 1px solid var(--glass-border); }
        .bento-item { background: var(--glass-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid var(--glass-border); border-radius: 1.5rem; padding: 1.5rem; transition: transform 0.3s, box-shadow 0.3s; }
        .bento-item:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .nav-link { color: var(--text-secondary); transition: color 0.2s, border-color 0.2s; border-bottom: 2px solid transparent; }
        .nav-link-active { color: var(--text-primary) !important; font-weight: 500; border-bottom: 2px solid #3b82f6; }
        html, body { height: 100%; overflow: hidden; }
        main { height: calc(100% - 64px); overflow-y: auto; }
        .grid-container { height: 100%; grid-template-rows: auto 1fr 1fr; }
        .chart-card { min-height: 0; }
        .chart-card > div { flex-grow: 1; min-height: 0; }
        .chart-card canvas { max-height: 100%; }
        .year-picker { background-color: var(--input-bg); border: 1px solid var(--glass-border); color: var(--text-primary); }
    </style>
</head>
<body class="min-h-screen">
    <canvas id="neural-canvas"></canvas>

    <header class="glass-header sticky top-0 z-10 shadow-sm">
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

    <main class="py-8">
        <div class="max-w-screen-2xl h-full mx-auto px-4 md:px-6">
            <div class="grid grid-cols-12 grid-rows-[auto_1fr_1fr] gap-6 h-full grid-container">
                <div class="col-span-12 row-span-1 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4">
                    <div class="bento-item text-center"><p class="text-3xl font-bold"><?= $stats['total'] ?></p><p class="text-sm text-secondary">Total Task</p></div>
                    <div class="bento-item text-center"><p class="text-3xl font-bold text-blue-400"><?= $stats['new'] ?></p><p class="text-sm text-secondary">Task Baru</p></div>
                    <div class="bento-item text-center"><p class="text-3xl font-bold text-yellow-400"><?= $stats['ongoing'] ?></p><p class="text-sm text-secondary">Ongoing</p></div>
                    <div class="bento-item text-center"><p class="text-3xl font-bold text-purple-400"><?= $stats['submitted'] ?></p><p class="text-sm text-secondary">Submitted</p></div>
                    <div class="bento-item text-center"><p class="text-3xl font-bold text-green-400"><?= $stats['approved'] ?></p><p class="text-sm text-secondary">Approved</p></div>
                    <div class="bento-item text-center"><p class="text-3xl font-bold text-gray-400"><?= $stats['cancelled'] ?></p><p class="text-sm text-secondary">Batal</p></div>
                    <div class="bento-item text-center"><p class="text-3xl font-bold text-red-400"><?= $stats['delay'] ?></p><p class="text-sm text-secondary">Delay</p></div>
                    <div class="bento-item text-center"><p class="text-3xl font-bold text-teal-400"><?= $stats['ontime'] ?></p><p class="text-sm text-secondary">Ontime</p></div>
                </div>

                <div class="col-span-12 lg:col-span-8 row-span-2 bento-item flex flex-col chart-card">
                     <div class="flex justify-between items-center mb-4">
                         <h3 class="font-semibold text-lg text-header">Aktivitas Task Tahunan</h3>
                         <form method="GET" action="">
                            <select name="year" onchange="this.form.submit()" class="year-picker rounded-lg px-3 py-1 text-sm">
                                <?php if (empty($available_years)): ?>
                                    <option>No Data</option>
                                <?php else: ?>
                                    <?php foreach ($available_years as $year): ?>
                                        <option value="<?= $year ?>" <?= ($year == $selected_year) ? 'selected' : '' ?>><?= $year ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                         </form>
                     </div>
                     <div class="flex-grow">
                        <canvas id="weeklyTaskChart"></canvas>
                     </div>
                </div>

                <div class="col-span-12 sm:col-span-6 lg:col-span-4 row-span-1 bento-item flex flex-col chart-card">
                    <h3 class="font-semibold text-lg mb-2 text-header">Distribusi Task Mingguan</h3>
                    <p class="text-xs text-secondary mb-4">
                        (<?= $start_of_week->format('d M Y') ?> - <?= $end_of_week->format('d M Y') ?>)
                    </p>
                    <div class="flex-grow flex items-center justify-center">
                        <canvas id="picPieChart"></canvas>
                    </div>
                </div>

                <div class="col-span-12 sm:col-span-6 lg:col-span-4 row-span-1 bento-item">
                    <h3 class="font-semibold text-lg mb-4 text-header">World Time Comparison</h3>
                    <div id="world-clocks" class="space-y-4 text-sm"></div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // --- ANIMATION LOGIC ---
        const canvas = document.getElementById('neural-canvas'); const ctx = canvas.getContext('2d');
        let particles = []; let hue = 0; function setCanvasSize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
        setCanvasSize(); class Particle { constructor(x, y) { this.x = x || Math.random() * canvas.width; this.y = y || Math.random() * canvas.height; this.vx = (Math.random() - 0.5) * 0.5; this.vy = (Math.random() - 0.5) * 0.5; this.size = Math.random() * 1.5 + 1; } update() { this.x += this.vx; this.y += this.vy; if (this.x < 0 || this.x > canvas.width) this.vx *= -1; if (this.y < 0 || this.y > canvas.height) this.vy *= -1; } draw() { ctx.fillStyle = `hsl(${hue}, 100%, 70%)`; ctx.beginPath(); ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2); ctx.fill(); } }
        function init(num) { particles = []; for (let i = 0; i < num; i++) { particles.push(new Particle()); } }
        function handleParticles() { for (let i = 0; i < particles.length; i++) { particles[i].update(); particles[i].draw(); for (let j = i; j < particles.length; j++) { const dx = particles[i].x - particles[j].x; const dy = particles[i].y - particles[j].y; const distance = Math.sqrt(dx * dx + dy * dy); if (distance < 100) { ctx.beginPath(); ctx.strokeStyle = `hsla(${hue}, 100%, 70%, ${1 - distance / 100})`; ctx.lineWidth = 0.5; ctx.moveTo(particles[i].x, particles[i].y); ctx.lineTo(particles[j].x, particles[j].y); ctx.stroke(); ctx.closePath(); } } } }
        function animate() { ctx.clearRect(0, 0, canvas.width, canvas.height); hue = (hue + 0.5) % 360; ctx.shadowColor = `hsl(${hue}, 100%, 50%)`; ctx.shadowBlur = 10; handleParticles(); requestAnimationFrame(animate); }
        init(window.innerWidth > 768 ? 100 : 50); animate(); window.addEventListener('resize', () => { setCanvasSize(); init(window.innerWidth > 768 ? 100 : 50); renderCharts(); });

        // --- THEME & CHART LOGIC ---
        const themeToggleBtn = document.getElementById('theme-toggle'); const darkIcon = document.getElementById('theme-toggle-dark-icon'); const lightIcon = document.getElementById('theme-toggle-light-icon');
        let currentTheme = 'dark';

        const weeklyPicData = <?= json_encode($weekly_pic_distribution) ?>;
        const yearlyChartData = <?= json_encode($weekly_data) ?>;
        const picColors = <?= isset($pic_colors) ? json_encode($pic_colors) : '[]' ?>;

        function getChartColors() { return { ticksColor: currentTheme === 'light' ? '#475569' : '#94a3b8', gridColor: currentTheme === 'light' ? 'rgba(0, 0, 0, 0.05)' : 'rgba(255, 255, 255, 0.1)', borderColor: currentTheme === 'light' ? '#fff' : 'var(--bg-primary)' }; }

        function createPicDoughnutChart() {
            const ctx = document.getElementById('picPieChart');
            if (!ctx) return;
            if (window.picDoughnutChart instanceof Chart) window.picDoughnutChart.destroy();
            
            const labels = Object.keys(weeklyPicData);
            const data = Object.values(weeklyPicData);

            if (labels.length === 0) {
                ctx.style.display = 'none';
                const placeholder = document.createElement('p');
                placeholder.textContent = 'Tidak ada data task untuk minggu ini.';
                placeholder.className = 'text-center text-secondary';
                ctx.parentNode.appendChild(placeholder);
                return;
            }

            ctx.style.display = 'block';
            const existingPlaceholder = ctx.parentNode.querySelector('p');
            if (existingPlaceholder) existingPlaceholder.remove();

            window.picDoughnutChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{ label: 'Tasks', data: data, backgroundColor: Object.values(picColors), borderColor: getChartColors().borderColor, borderWidth: 4 }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: getChartColors().ticksColor, padding: 20, usePointStyle: true } } } }
            });
        }

        function createYearlyTaskChart() {
            const ctx = document.getElementById('weeklyTaskChart');
            if (!ctx || !yearlyChartData.labels || yearlyChartData.labels.length === 0) return;
            if (window.yearlyTaskChart instanceof Chart) window.yearlyTaskChart.destroy();
            window.yearlyTaskChart = new Chart(ctx, {
                type: 'bar',
                data: yearlyChartData,
                options: {
                    responsive: true, maintainAspectRatio: false,
                    scales: { x: { stacked: false, ticks: { color: getChartColors().ticksColor }, grid: { color: getChartColors().gridColor } }, y: { stacked: false, beginAtZero: true, ticks: { color: getChartColors().ticksColor }, grid: { color: getChartColors().gridColor } } },
                    plugins: { legend: { position: 'top', labels: { color: getChartColors().ticksColor } }, tooltip: { mode: 'index', intersect: false } },
                    interaction: { mode: 'index', intersect: false }
                }
            });
        }

        function renderCharts() { createPicDoughnutChart(); createYearlyTaskChart(); }

        function applyTheme(isLight) {
            currentTheme = isLight ? 'light' : 'dark';
            if (isLight) { document.documentElement.classList.add('light'); lightIcon.classList.remove('hidden'); darkIcon.classList.add('hidden'); } else { document.documentElement.classList.remove('light'); lightIcon.classList.add('hidden'); darkIcon.classList.remove('hidden'); }
            renderCharts();
        }
        
        themeToggleBtn.addEventListener('click', () => { const isCurrentlyLight = document.documentElement.classList.contains('light'); localStorage.setItem('theme', isCurrentlyLight ? 'dark' : 'light'); applyTheme(!isCurrentlyLight); });

        // --- WORLD CLOCKS LOGIC ---
        const clocksContainer = document.getElementById('world-clocks');
        const timezones = [{ name: 'Indonesia (WIB)', tz: 'Asia/Jakarta' }, { name: 'Korea Selatan', tz: 'Asia/Seoul' }, { name: 'Vietnam', tz: 'Asia/Ho_Chi_Minh' }, { name: 'China', tz: 'Asia/Shanghai' }, { name: 'Brazil', tz: 'America/Sao_Paulo' }];
        function pad(n) { return n < 10 ? '0' + n : n; }
        function updateClocks() {
            if (!clocksContainer) return;
            let clocksHTML = ''; const now = new Date();
            timezones.forEach(zone => {
                try {
                    const localTime = new Date(now.toLocaleString('en-US', { timeZone: zone.tz }));
                    const time = `${pad(localTime.getHours())}:${pad(localTime.getMinutes())}:${pad(localTime.getSeconds())}`;
                    const date = localTime.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
                    clocksHTML += `<div class="flex justify-between items-center"><span class="text-secondary">${zone.name}</span><div class="text-right"><div class="font-mono font-semibold text-primary">${time}</div><div class="text-xs text-secondary">${date}</div></div></div>`;
                } catch(e) { console.error("Could not format time for timezone: ", zone.tz); }
            });
            clocksContainer.innerHTML = clocksHTML;
        }

        // --- INITIALIZATION ---
        document.addEventListener('DOMContentLoaded', () => {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            applyTheme(savedTheme ? savedTheme === 'light' : !prefersDark);
            updateClocks(); setInterval(updateClocks, 1000);
        });
    </script>
</body>
</html>