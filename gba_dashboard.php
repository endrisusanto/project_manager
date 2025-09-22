<?php
// 1. INISIALISASI
require_once "config.php";
require_once "session.php"; // Memastikan pengguna sudah login

// Tentukan halaman aktif untuk navigasi header
$active_page = 'gba_dashboard';

// 2. LOGIKA PENGAMBILAN & PEMROSESAN DATA
$tasks_result = $conn->query("SELECT * FROM gba_tasks WHERE request_date IS NOT NULL ORDER BY request_date ASC");
$all_tasks = [];
$available_years = [];
if ($tasks_result && $tasks_result->num_rows > 0) {
    while($row = $tasks_result->fetch_assoc()) {
        $all_tasks[] = $row;
        $year = date('Y', strtotime($row['request_date']));
        if (!in_array($year, $available_years)) {
            $available_years[] = $year;
        }
    }
}
sort($available_years);

// Inisialisasi variabel statistik
$stats = ['total' => 0, 'new' => 0, 'ongoing' => 0, 'submitted' => 0, 'approved' => 0, 'cancelled' => 0, 'delay' => 0, 'ontime' => 0];
$weekly_pic_distribution = [];
$weekly_data = [];
$all_pics = [];
$start_of_week = null;
$end_of_week = null;

if (!empty($all_tasks)) {
    // Logika untuk Donut Chart Mingguan (Rabu - Selasa)
    $today = new DateTime();
    $day_of_week = $today->format('w');
    $days_to_subtract = ($day_of_week < 3) ? (7 + $day_of_week - 3) : ($day_of_week - 3);
    $start_of_week = (new DateTime())->modify("-$days_to_subtract days");
    $end_of_week = (clone $start_of_week)->modify("+6 days");

    $tasks_this_week = array_filter($all_tasks, function($task) use ($start_of_week, $end_of_week) {
        $request_dt = new DateTime($task['request_date']);
        return $request_dt >= $start_of_week && $request_dt <= $end_of_week;
    });

    foreach ($tasks_this_week as $task) {
        $pic = !empty($task['pic_email']) ? $task['pic_email'] : 'Unassigned';
        $weekly_pic_distribution[$pic] = ($weekly_pic_distribution[$pic] ?? 0) + 1;
    }
    arsort($weekly_pic_distribution);

    // Logika untuk Chart Tahunan
    $selected_year = isset($_GET['year']) && in_array($_GET['year'], $available_years) ? $_GET['year'] : (end($available_years) ?: date('Y'));
    $tasks_for_yearly_chart = array_filter($all_tasks, fn($task) => date('Y', strtotime($task['request_date'])) == $selected_year);

    // Hitung statistik keseluruhan
    foreach ($all_tasks as $task) {
        if ($task['progress_status'] == 'Task Baru') $stats['new']++;
        if (in_array($task['progress_status'], ['Test Ongoing', 'Pending Feedback', 'Feedback Sent'])) $stats['ongoing']++;
        if ($task['progress_status'] == 'Submitted') $stats['submitted']++;
        if ($task['progress_status'] == 'Approved' || $task['progress_status'] == 'Passed') $stats['approved']++;
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
    $year_start_date = new DateTime("{$selected_year}-01-01");
    for ($i = 0; $i < 52; $i++) {
        $week_key = (clone $year_start_date)->modify("+$i week")->format("Y-W");
        $weekly_summary[$week_key] = ['total' => 0];
        foreach ($all_pics as $pic) if(!empty($pic)) $weekly_summary[$week_key][$pic] = 0;
    }

    foreach ($tasks_for_yearly_chart as $task) {
        $week_key = (new DateTime($task['request_date']))->format("Y-W");
        $pic = !empty($task['pic_email']) ? $task['pic_email'] : 'Unassigned';
        if (isset($weekly_summary[$week_key])) {
            $weekly_summary[$week_key]['total']++;
            if (isset($weekly_summary[$week_key][$pic])) $weekly_summary[$week_key][$pic]++;
        }
    }

    $weekly_data = ['labels' => array_keys($weekly_summary), 'datasets' => []];
    $weekly_data['datasets'][] = ['label' => 'Total Tasks', 'data' => array_column($weekly_summary, 'total'), 'type' => 'line', 'borderColor' => '#3b82f6', 'backgroundColor' => 'transparent', 'tension' => 0.4, 'yAxisID' => 'y', 'order' => 0, 'pointRadius' => 0, 'borderWidth' => 2];

    $pic_colors = [];
    $color_palette = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#0ea5e9', '#8b5cf6', '#ec4899', '#f43f5e', '#fb923c', '#facc15', '#4ade80', '#38bdf8', '#a855f7', '#f87171', '#fbbf24', '#fde047', '#86efad', '#7dd3fc', '#c084fc', '#e879f9'];
    $color_index = 0;
    foreach ($all_pics as $pic) {
        if(empty($pic)) continue;
        $color = $color_palette[$color_index++ % count($color_palette)];
        $pic_colors[$pic] = $color;
        $weekly_data['datasets'][] = ['label' => $pic, 'data' => array_column($weekly_summary, $pic), 'type' => 'bar', 'backgroundColor' => $color, 'yAxisID' => 'y', 'order' => 1, 'barPercentage' => 0.7, 'categoryPercentage' => 0.8];
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
        /* MODIFIKASI: --glass-bg dan --glass-border diubah untuk efek lebih glassy */
        :root { --bg-primary: #020617; --text-primary: #e2e8f0; --text-secondary: #94a3b8; --glass-bg: rgba(15, 23, 42, 0.45); --glass-border: rgba(51, 65, 85, 0.3); --text-header: #ffffff; --text-icon: #94a3b8; --input-bg: rgba(30, 41, 59, 0.7); }
        html.light { --bg-primary: #f1f5f9; --text-primary: #0f172a; --text-secondary: #475569; --glass-bg: rgba(255, 255, 255, 0.5); --glass-border: rgba(0, 0, 0, 0.08); --text-header: #0f172a; --text-icon: #475569; --input-bg: #ffffff; }
        
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); }
        #neural-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; }
        
        /* MODIFIKASI: backdrop-filter blur ditingkatkan dari 12px ke 20px */
        .bento-item { background: var(--glass-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid var(--glass-border); border-radius: 1.5rem; padding: 1.5rem; transition: transform 0.3s, box-shadow 0.3s; }
        .bento-item:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        
        .nav-link { color: var(--text-secondary); border-bottom: 2px solid transparent; transition: all 0.2s; } .nav-link:hover { border-color: var(--text-secondary); color: var(--text-primary); }
        .nav-link-active { color: var(--text-primary) !important; border-bottom: 2px solid #3b82f6; font-weight: 600; }
        html, body { height: 100%; overflow: hidden; }
        main { height: calc(100% - 64px); overflow-y: auto; }
        .grid-container { height: 100%; grid-template-rows: auto 1fr 1fr; }
        .chart-card { min-height: 0; } .chart-card > div { flex-grow: 1; min-height: 0; } .chart-card canvas { max-height: 100%; }
        .year-picker { background-color: var(--input-bg); border: 1px solid var(--glass-border); color: var(--text-primary); }
    </style>
</head>
<body class="min-h-screen">
    <canvas id="neural-canvas"></canvas>

    <?php include 'header.php'; ?>

    <main class="py-8">
        <div class="max-w-full h-full mx-auto px-6">
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
                        (<?= $start_of_week ? $start_of_week->format('d M Y') : 'N/A' ?> - <?= $end_of_week ? $end_of_week->format('d M Y') : 'N/A' ?>)
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
    // --- (JavaScript tidak ada perubahan, tetap sama) ---
    const canvas = document.getElementById('neural-canvas'), ctx = canvas.getContext('2d');
    let particles = [], hue = 210;
    function setCanvasSize(){canvas.width=window.innerWidth;canvas.height=window.innerHeight;}setCanvasSize();
    class Particle{constructor(x,y){this.x=x||Math.random()*canvas.width;this.y=y||Math.random()*canvas.height;this.vx=(Math.random()-.5)*.4;this.vy=(Math.random()-.5)*.4;this.size=Math.random()*2+1.5}update(){this.x+=this.vx;this.y+=this.vy;if(this.x<0||this.x>canvas.width)this.vx*=-1;if(this.y<0||this.y>canvas.height)this.vy*=-1}draw(){ctx.fillStyle=`hsl(${hue},100%,75%)`;ctx.beginPath();ctx.arc(this.x,this.y,this.size,0,Math.PI*2);ctx.fill()}}
    function init(num){particles=[];for(let i=0;i<num;i++)particles.push(new Particle())}
    function handleParticles(){for(let i=0;i<particles.length;i++){particles[i].update();particles[i].draw();for(let j=i;j<particles.length;j++){const dx=particles[i].x-particles[j].x;const dy=particles[i].y-particles[j].y;const distance=Math.sqrt(dx*dx+dy*dy);if(distance<120){ctx.beginPath();ctx.strokeStyle=`hsla(${hue},100%,80%,${1-distance/120})`;ctx.lineWidth=1;ctx.moveTo(particles[i].x,particles[i].y);ctx.lineTo(particles[j].x,particles[j].y);ctx.stroke();ctx.closePath()}}}}
    function animate(){ctx.clearRect(0,0,canvas.width,canvas.height);hue=(hue+.3)%360;handleParticles();requestAnimationFrame(animate)}
    const particleCount=window.innerWidth>768?150:70;init(particleCount);animate();
    const themeToggleBtn=document.getElementById('theme-toggle');let currentTheme='dark';window.addEventListener('resize',()=>{setCanvasSize();init(particleCount);renderCharts()});
    const weeklyPicData=<?= json_encode($weekly_pic_distribution) ?>,yearlyChartData=<?= json_encode($weekly_data) ?>,picColors=<?= isset($pic_colors) ? json_encode($pic_colors) : '[]' ?>;
    function getChartColors(){return{ticksColor:currentTheme==='light'?'#475569':'#94a3b8',gridColor:currentTheme==='light'?'rgba(0,0,0,.05)':'rgba(255,255,255,.1)',borderColor:currentTheme==='light'?'#fff':'var(--bg-primary)'}}
    function createPicDoughnutChart(){const ctx=document.getElementById('picPieChart');if(!ctx)return;if(window.picDoughnutChart instanceof Chart)window.picDoughnutChart.destroy();const labels=Object.keys(weeklyPicData),data=Object.values(weeklyPicData);if(labels.length===0){ctx.style.display='none';const placeholder=document.createElement('p');placeholder.textContent='Tidak ada data task untuk minggu ini.';placeholder.className='text-center text-secondary';ctx.parentNode.appendChild(placeholder);return}ctx.style.display='block';const existingPlaceholder=ctx.parentNode.querySelector('p');if(existingPlaceholder)existingPlaceholder.remove();window.picDoughnutChart=new Chart(ctx,{type:'doughnut',data:{labels:labels,datasets:[{label:'Tasks',data:data,backgroundColor:Object.values(picColors),borderColor:getChartColors().borderColor,borderWidth:4}]},options:{responsive:!0,maintainAspectRatio:!1,plugins:{legend:{position:'bottom',labels:{color:getChartColors().ticksColor,padding:20,usePointStyle:!0}}}}})}
    function createYearlyTaskChart(){const ctx=document.getElementById('weeklyTaskChart');if(!ctx||!yearlyChartData.labels||yearlyChartData.labels.length===0)return;if(window.yearlyTaskChart instanceof Chart)window.yearlyTaskChart.destroy();window.yearlyTaskChart=new Chart(ctx,{type:'bar',data:yearlyChartData,options:{responsive:!0,maintainAspectRatio:!1,scales:{x:{stacked:!1,ticks:{color:getChartColors().ticksColor},grid:{color:getChartColors().gridColor}},y:{stacked:!1,beginAtZero:!0,ticks:{color:getChartColors().ticksColor},grid:{color:getChartColors().gridColor}}},plugins:{legend:{position:'top',labels:{color:getChartColors().ticksColor}},tooltip:{mode:'index',intersect:!1}},interaction:{mode:'index',intersect:!1}}})}
    function renderCharts(){createPicDoughnutChart();createYearlyTaskChart()}
    function applyTheme(isLight){currentTheme=isLight?'light':'dark';document.documentElement.classList.toggle('light',isLight);document.getElementById('theme-toggle-light-icon').classList.toggle('hidden',!isLight);document.getElementById('theme-toggle-dark-icon').classList.toggle('hidden',isLight);renderCharts()}
    themeToggleBtn.addEventListener('click',()=>{const isCurrentlyLight=document.documentElement.classList.contains('light');localStorage.setItem('theme',isCurrentlyLight?'dark':'light');applyTheme(!isCurrentlyLight)});
    const clocksContainer=document.getElementById('world-clocks'),timezones=[{name:'Indonesia (WIB)',tz:'Asia/Jakarta'},{name:'Korea Selatan',tz:'Asia/Seoul'},{name:'Vietnam',tz:'Asia/Ho_Chi_Minh'},{name:'China',tz:'Asia/Shanghai'},{name:'Brazil',tz:'America/Sao_Paulo'}];
    function pad(n){return n<10?'0'+n:n}
    function updateClocks(){if(!clocksContainer)return;let clocksHTML='';const now=new Date();timezones.forEach(zone=>{try{const localTime=new Date(now.toLocaleString('en-US',{timeZone:zone.tz})),time=`${pad(localTime.getHours())}:${pad(localTime.getMinutes())}:${pad(localTime.getSeconds())}`,date=localTime.toLocaleDateString('id-ID',{weekday:'long',day:'numeric',month:'long',year:'numeric'});clocksHTML+=`<div class="flex justify-between items-center"><span class="text-secondary">${zone.name}</span><div class="text-right"><div class="font-mono font-semibold text-primary">${time}</div><div class="text-xs text-secondary">${date}</div></div></div>`}catch(e){console.error("Could not format time for timezone: ",zone.tz)}});clocksContainer.innerHTML=clocksHTML}
    document.addEventListener('DOMContentLoaded',()=>{const savedTheme=localStorage.getItem('theme'),prefersDark=window.matchMedia('(prefers-color-scheme: dark)').matches;applyTheme(savedTheme?savedTheme==='light':!prefersDark);updateClocks();setInterval(updateClocks,1000);const profileMenu=document.getElementById('profile-menu');if(profileMenu){const profileButton=profileMenu.querySelector('button'),profileDropdown=document.getElementById('profile-dropdown');profileButton.addEventListener('click',e=>{e.stopPropagation();profileDropdown.classList.toggle('hidden')});document.addEventListener('click',e=>{if(!profileMenu.contains(e.target)){profileDropdown.classList.add('hidden')}})}});
</script>
</body>
</html>