<?php
require_once "config.php";

$statuses = ['Planning', 'In Development', 'Released', 'GBA Testing', 'Software Confirm / FOTA'];
$projectsToDisplay = [];
foreach ($statuses as $status) {
    $projectsToDisplay[$status] = [];
}

// **QUERY UTAMA DIUBAH: Menggunakan LEFT JOIN**
$sql = "
    SELECT 
        p.*, 
        gt.deadline AS gba_deadline, 
        gt.sign_off_date AS gba_sign_off_date
    FROM 
        projects p
    LEFT JOIN 
        gba_tasks gt ON p.id = gt.project_id
    ORDER BY 
        p.id ASC, gt.id DESC
";

$result = $conn->query($sql);
$allProjects = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $allProjects[] = $row;
    }
}

$processedModels = [];
foreach ($allProjects as $project) {
    $model = $project['product_model'];
    $type = $project['project_type'];
    $status = $project['status'];
    $key = $model;

    if ($type === 'Security Release' || $type === 'Maintenance Release') {
        $key = $model . '_' . $type;
    }

    if (!isset($processedModels[$key])) {
        if (isset($projectsToDisplay[$status])) {
            $projectsToDisplay[$status][] = $project;
            $processedModels[$key] = true;
        }
    }
}

function getBadgeColor($type) {
    switch ($type) {
        case 'New Launch': return 'badge-blue';
        case 'Maintenance Release': return 'badge-yellow';
        case 'Security Release': return 'badge-red';
        default: return 'badge-gray';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Manajemen Proyek Software</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #020617; --text-primary: #e2e8f0; --text-secondary: #94a3b8;
            --glass-bg: rgba(15, 23, 42, 0.6); --glass-border: rgba(51, 65, 85, 0.6);
            --column-bg: rgba(255, 255, 255, 0.03); --text-header: #ffffff;
            --text-card-title: #ffffff; --text-card-body: #cbd5e1; --text-icon: #94a3b8;
            --badge-blue-bg: rgba(59, 130, 246, 0.1); --badge-blue-text: #93c5fd;
            --badge-yellow-bg: rgba(234, 179, 8, 0.1); --badge-yellow-text: #fde047;
            --badge-red-bg: rgba(239, 68, 68, 0.1); --badge-red-text: #fca5a5;
            --badge-gray-bg: rgba(107, 114, 128, 0.1); --badge-gray-text: #d1d5db;
            --input-bg: rgba(30, 41, 59, 0.7); --input-border: #475569; --input-text: #e2e8f0;
            --input-placeholder: #64748b; --color-scheme: dark; --filter-btn-bg: rgba(255, 255, 255, 0.05);
            --filter-btn-bg-active: #2563eb; --filter-btn-text: #94a3b8;
            --filter-btn-text-active: #ffffff; --toast-bg: #22c55e; --toast-text: #ffffff;
        }
        html.light {
            --bg-primary: #f1f5f9; --text-primary: #0f172a; --text-secondary: #475569;
            --glass-bg: rgba(255, 255, 255, 0.6); --glass-border: rgba(0, 0, 0, 0.1);
            --column-bg: rgba(0, 0, 0, 0.03); --text-header: #0f172a; --text-card-title: #1e293b;
            --text-card-body: #334155; --text-icon: #475569; --badge-blue-bg: rgba(59, 130, 246, 0.1);
            --badge-blue-text: #2563eb; --badge-yellow-bg: rgba(234, 179, 8, 0.1); --badge-yellow-text: #b45309;
            --badge-red-bg: rgba(239, 68, 68, 0.1); --badge-red-text: #b91c1c;
            --badge-gray-bg: rgba(107, 114, 128, 0.1); --badge-gray-text: #374151;
            --input-bg: #ffffff; --input-border: #cbd5e1; --input-text: #0f172a;
            --input-placeholder: #94a3b8; --color-scheme: light; --filter-btn-bg: rgba(0, 0, 0, 0.05);
            --filter-btn-bg-active: #2563eb; --filter-btn-text: #334155; --filter-btn-text-active: #ffffff;
            --toast-bg: #16a34a; --toast-text: #ffffff;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-primary); color: var(--text-primary); transition: background-color 0.3s ease, color 0.3s ease; }
        #neural-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; }
        .glass-container { background: var(--glass-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid var(--glass-border); transition: all 0.3s; }
        .filter-button { background-color: var(--filter-btn-bg); color: var(--filter-btn-text); }
        .filter-button.active { background-color: var(--filter-btn-bg-active); color: var(--filter-btn-text-active); }
        .kanban-column { background: var(--column-bg); border-radius: 1rem; }
        .project-card { cursor: grab; transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out, opacity 0.3s ease; }
        .project-card:hover { transform: translateY(-4px) scale(1.02); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .sortable-ghost { background: rgba(59, 130, 246, 0.2); border: 2px dashed rgba(59, 130, 246, 0.5); }
        .text-header { color: var(--text-header); } .text-card-title { color: var(--text-card-title); }
        .text-card-body { color: var(--text-card-body); } .text-icon { color: var(--text-icon); }
        .badge { background-color: var(--bg); color: var(--text); }
        .badge-blue { --bg: var(--badge-blue-bg); --text: var(--badge-blue-text); }
        .badge-yellow { --bg: var(--badge-yellow-bg); --text: var(--badge-yellow-text); }
        .badge-red { --bg: var(--badge-red-bg); --text: var(--badge-red-text); }
        .badge-gray { --bg: var(--badge-gray-bg); --text: var(--badge-gray-text); }
        .form-label { color: var(--text-secondary); }
        .themed-input { background-color: var(--input-bg); border: 1px solid var(--input-border); color: var(--input-text); color-scheme: var(--color-scheme, dark); }
        .themed-input::placeholder { color: var(--input-placeholder); }
        .themed-input:focus { --tw-ring-color: rgb(59 130 246 / 0.5); border-color: #3b82f6; }
        .themed-input option { background: var(--input-bg); color: var(--input-text); }
        .nav-link { color: var(--text-secondary); border-bottom: 2px solid transparent; transition: all 0.2s; }
        .nav-link:hover { border-color: var(--text-secondary); color: var(--text-primary); }
        .nav-link-active { color: var(--text-primary); border-bottom: 2px solid #3b82f6; font-weight: 600; }
        @keyframes pulse-alert { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.2); opacity: 0.7; } }
        .animate-pulse-alert { animation: pulse-alert 1.5s infinite; }
        #toast { position: fixed; bottom: -100px; left: 50%; transform: translateX(-50%); background-color: var(--toast-bg); color: var(--toast-text); padding: 12px 20px; border-radius: 8px; z-index: 100; transition: bottom 0.5s ease-in-out; }
        #toast.show { bottom: 30px; }
        @keyframes blink-border { 50% { border-color: #ef4444; box-shadow: 0 0 15px rgba(239, 68, 68, 0.5); } }
        .anomaly-card { animation: blink-border 1.5s infinite; }
    </style>
</head>
<body>
    <canvas id="neural-canvas"></canvas>
    <div id="toast">Link berhasil disalin!</div>
    
    <nav class="glass-container sticky top-0 z-10 shadow-sm">
        <div class="w-full mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center space-x-3">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-blue-600"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" /></svg>
                    <h1 class="text-xl font-bold text-header">Software Project Manager</h1>
                    
                    <div class="hidden md:flex items-baseline space-x-4">
                        <a href="index.php" class="nav-link-active px-3 py-2 rounded-md text-sm font-medium">Project Dashboard</a>
                        <a href="gba_dashboard.php" class="nav-link px-3 py-2 rounded-md text-sm font-medium">GBA Dashboard</a>
                        <a href="gba_tasks.php" class="nav-link px-3 py-2 rounded-md text-sm font-medium">GBA Tasks</a>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <button id="theme-toggle" type="button" class="text-icon hover:bg-gray-500/10 rounded-lg text-sm p-2.5 transition-colors duration-200">
                        <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path></svg>
                        <svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path></svg>
                    </button>
                    <button onclick="openModal('addProjectModal')" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 transition-all duration-200 hover:shadow-lg hover:-translate-y-0.5">
                        <svg class="-ml-0.5 mr-1.5 h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>
                        Proyek Baru
                    </button>
                </div>
            </div>
            <div class="w-full mx-auto px-4 sm:px-6 lg:px-8 pb-4">
                <div class="flex flex-col sm:flex-row gap-4">
                    <div class="relative flex-grow">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                           <svg class="h-5 w-5 text-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" /></svg>
                        </div>
                        <input type="search" id="search-input" placeholder="Cari nama proyek atau model..." class="themed-input block w-full rounded-lg py-2 pl-10 pr-3 focus:ring-2">
                    </div>
                    <div id="filter-container" class="flex items-center space-x-2">
                        <button class="filter-button active px-3 py-1.5 text-sm font-medium rounded-md transition-colors" data-type="All">Semua</button>
                        <button class="filter-button px-3 py-1.5 text-sm font-medium rounded-md transition-colors" data-type="New Launch">New Launch</button>
                        <button class="filter-button px-3 py-1.5 text-sm font-medium rounded-md transition-colors" data-type="Maintenance Release">Maintenance</button>
                        <button class="filter-button px-3 py-1.5 text-sm font-medium rounded-md transition-colors" data-type="Security Release">Security</button>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    
    <main class="w-full px-4 sm:px-6 lg:px-8 py-8">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-6">
            <?php foreach ($statuses as $status): ?>
            <div class="p-4 rounded-lg">
                <h2 class="text-base font-semibold mb-4 flex items-center justify-between text-header">
                    <?php echo htmlspecialchars($status); ?>
                    <span class="text-sm font-bold bg-gray-500/10 text-header rounded-full px-2 py-0.5 count-<?php echo str_replace([' ', '/'], '', $status); ?>">
                        <?php echo count($projectsToDisplay[$status]); ?>
                    </span>
                </h2>
                <div id="status-<?php echo str_replace([' ', '/'], '', $status); ?>" data-status="<?php echo $status; ?>" class="kanban-column space-y-4 h-full p-2">
                    <?php if (!empty($projectsToDisplay[$status])): ?>
                        <?php foreach ($projectsToDisplay[$status] as $project): ?>
                        <div id="project-<?php echo $project['id']; ?>" data-id="<?php echo $project['id']; ?>" data-project='<?php echo json_encode($project, JSON_HEX_APOS | JSON_HEX_QUOT); ?>' class="project-card glass-container p-4 rounded-lg" data-type="<?php echo htmlspecialchars($project['project_type']); ?>">
                            <div class="flex justify-between items-start mb-2">
                                <h3 class="font-bold text-card-title flex-1 pr-2 project-name"><?php echo htmlspecialchars($project['project_name']); ?></h3>
                                <div class="flex items-center space-x-2">
                                    <button onclick='openEditModal(this)' class="text-icon hover:text-blue-600 transition-colors duration-200">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z" /><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd" /></svg>
                                    </button>
                                    <form action="handler.php" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus proyek ini?');" class="inline">
                                        <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
                                        <button type="submit" name="delete_project" class="text-icon hover:text-red-600 transition-colors duration-200">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm4 0a1 1 0 012 0v6a1 1 0 11-2 0V8z" clip-rule="evenodd" /></svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <p class="text-sm text-card-body mb-3 product-model"><?php echo htmlspecialchars($project['product_model']); ?></p>
                            <span class="badge text-xs font-medium px-2.5 py-0.5 rounded-full <?php echo getBadgeColor($project['project_type']); ?>">
                                <?php echo htmlspecialchars($project['project_type']); ?>
                            </span>

                            <?php
                            $display_deadline_section = false;
                            $deadline_to_show = null;
                            $deadline_label = 'Deadline';

                            if ($project['status'] === 'GBA Testing' && !empty($project['gba_deadline'])) {
                                $display_deadline_section = true;
                                $deadline_to_show = $project['gba_deadline'];
                                $deadline_label = 'GBA Deadline';
                            } elseif ($project['status'] === 'Software Confirm / FOTA' && !empty($project['gba_sign_off_date'])) {
                                $display_deadline_section = true;
                                $deadline_to_show = $project['gba_sign_off_date'];
                                $deadline_label = 'Sign-Off Date';
                            }
                            ?>

                            <?php if ($display_deadline_section): ?>
                            <div class="mt-3 pt-3 border-t border-[var(--glass-border)]">
                                <div class="anomaly-warning text-red-400 text-xs font-bold items-center hidden gap-1 mb-2">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg>
                                    <span>GBA Task Belum Approved!</span>
                                </div>
                                <div class="flex justify-between items-center text-xs text-card-body font-medium">
                                    <span class="font-bold"><?= $deadline_label ?>:</span>
                                    <span><?= date("d M Y", strtotime($deadline_to_show)) ?></span>
                                </div>
                                <div class="flex justify-between items-center text-xs text-card-body font-medium mt-1">
                                    <span class="countdown-timer" data-due-date="<?php echo htmlspecialchars($deadline_to_show); ?>"></span>
                                    <span class="alert-icon hidden text-red-500">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" />
                                        </svg>
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </main>
    
    <div id="modal-container" class="fixed inset-0 overflow-y-auto h-full w-full flex items-center justify-center hidden z-20" style="background: rgba(0,0,0,0.5);">
        <div id="addProjectModal" class="glass-container relative p-5 border w-full max-w-lg shadow-xl rounded-2xl hidden">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold text-header">Tambah Proyek Baru</h3>
                <button onclick="closeModal()" class="text-icon bg-transparent hover:bg-gray-500/10 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center transition-colors duration-200">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                </button>
            </div>
            <form action="handler.php" method="POST" class="space-y-4 p-2"><input type="hidden" name="add_project" value="1"><?php include 'project_form_fields.php'; ?><button type="submit" class="w-full text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center transition-all duration-200">Simpan</button></form>
        </div>
        <div id="editProjectModal" class="glass-container relative p-5 border w-full max-w-lg shadow-xl rounded-2xl hidden">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold text-header">Edit Proyek</h3>
                <button onclick="closeModal()" class="text-icon bg-transparent hover:bg-gray-500/10 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center transition-colors duration-200">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                </button>
            </div>
            <form id="editProjectForm" action="handler.php" method="POST" class="space-y-4 p-2"><input type="hidden" name="update_project" value="1"><input type="hidden" name="project_id" id="edit_project_id"><?php include 'project_form_fields.php'; ?><button type="submit" class="w-full text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center transition-all duration-200">Update</button></form>
        </div>
    </div>
    
    <script>
        const canvas = document.getElementById('neural-canvas'); const ctx = canvas.getContext('2d');
        let particles = []; let hue = 0; function setCanvasSize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
        setCanvasSize(); class Particle { constructor(x, y) { this.x = x || Math.random() * canvas.width; this.y = y || Math.random() * canvas.height; this.vx = (Math.random() - 0.5) * 0.5; this.vy = (Math.random() - 0.5) * 0.5; this.size = Math.random() * 1.5 + 1; } update() { this.x += this.vx; this.y += this.vy; if (this.x < 0 || this.x > canvas.width) this.vx *= -1; if (this.y < 0 || this.y > canvas.height) this.vy *= -1; } draw() { ctx.fillStyle = `hsl(${hue}, 100%, 70%)`; ctx.beginPath(); ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2); ctx.fill(); } }
        function init(num) { particles = []; for (let i = 0; i < num; i++) { particles.push(new Particle()); } }
        function handleParticles() { for (let i = 0; i < particles.length; i++) { particles[i].update(); particles[i].draw(); for (let j = i; j < particles.length; j++) { const dx = particles[i].x - particles[j].x; const dy = particles[i].y - particles[j].y; const distance = Math.sqrt(dx * dx + dy * dy); if (distance < 100) { ctx.beginPath(); ctx.strokeStyle = `hsla(${hue}, 100%, 70%, ${1 - distance / 100})`; ctx.lineWidth = 0.5; ctx.moveTo(particles[i].x, particles[i].y); ctx.lineTo(particles[j].x, particles[j].y); ctx.stroke(); ctx.closePath(); } } } }
        function animate() { ctx.clearRect(0, 0, canvas.width, canvas.height); hue = (hue + 0.5) % 360; ctx.shadowColor = `hsl(${hue}, 100%, 50%)`; ctx.shadowBlur = 10; handleParticles(); requestAnimationFrame(animate); }
        init(window.innerWidth > 768 ? 100 : 50); animate(); window.addEventListener('resize', () => { setCanvasSize(); init(window.innerWidth > 768 ? 100 : 50); });
        const themeToggleBtn = document.getElementById('theme-toggle'); const themeToggleDarkIcon = document.getElementById('theme-toggle-dark-icon'); const themeToggleLightIcon = document.getElementById('theme-toggle-light-icon');
        function applyTheme(theme) { if (theme === 'light') { document.documentElement.classList.add('light'); themeToggleLightIcon.classList.remove('hidden'); themeToggleDarkIcon.classList.add('hidden'); } else { document.documentElement.classList.remove('light'); themeToggleDarkIcon.classList.remove('hidden'); themeToggleLightIcon.classList.add('hidden'); } }
        themeToggleBtn.addEventListener('click', () => { const isLight = document.documentElement.classList.contains('light'); const newTheme = isLight ? 'dark' : 'light'; localStorage.setItem('theme', newTheme); applyTheme(newTheme); });
        const savedTheme = localStorage.getItem('theme'); if (savedTheme) { applyTheme(savedTheme); } else { applyTheme(window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); }
        const searchInput = document.getElementById('search-input'); const filterContainer = document.getElementById('filter-container'); const projectCards = document.querySelectorAll('.project-card'); let activeTypeFilter = 'All';
        function filterAndSearchProjects() { const searchTerm = searchInput.value.toLowerCase(); projectCards.forEach(card => { const projectName = card.querySelector('.project-name').textContent.toLowerCase(); const productModel = card.querySelector('.product-model').textContent.toLowerCase(); const projectType = card.dataset.type; const matchesSearch = projectName.includes(searchTerm) || productModel.includes(searchTerm); const matchesType = activeTypeFilter === 'All' || projectType === activeTypeFilter; if (matchesSearch && matchesType) { card.classList.remove('hidden'); } else { card.classList.add('hidden'); } }); }
        searchInput.addEventListener('input', filterAndSearchProjects); filterContainer.addEventListener('click', (e) => { if (e.target.tagName === 'BUTTON') { filterContainer.querySelector('.active').classList.remove('active'); e.target.classList.add('active'); activeTypeFilter = e.target.dataset.type; filterAndSearchProjects(); } });
        const modalContainer = document.getElementById('modal-container'); const addProjectModal = document.getElementById('addProjectModal'); const editProjectModal = document.getElementById('editProjectModal');
        function openModal(modalID) { modalContainer.classList.remove('hidden'); if (modalID === 'addProjectModal') addProjectModal.classList.remove('hidden'); if (modalID === 'editProjectModal') editProjectModal.classList.remove('hidden'); }
        function closeModal() { modalContainer.classList.add('hidden'); addProjectModal.classList.add('hidden'); editProjectModal.classList.add('hidden'); }
        function openEditModal(button) {
            const card = button.closest('.project-card');
            const projectData = JSON.parse(card.getAttribute('data-project'));
            const form = document.getElementById('editProjectForm');
            form.querySelector('#edit_project_id').value = projectData.id;
            form.querySelector('input[name="project_name"]').value = projectData.project_name;
            form.querySelector('input[name="product_model"]').value = projectData.product_model;
            form.querySelector('select[name="project_type"]').value = projectData.project_type;
            form.querySelector('textarea[name="description"]').value = projectData.description;
            form.querySelector('input[name="ap"]').value = projectData.ap || '';
            form.querySelector('input[name="cp"]').value = projectData.cp || '';
            form.querySelector('input[name="csc"]').value = projectData.csc || '';
            form.querySelector('input[name="qb_user"]').value = projectData.qb_user || '';
            form.querySelector('input[name="qb_userdebug"]').value = projectData.qb_userdebug || '';
            form.querySelector('input[name="software_released"]').checked = projectData.software_released == 1;
            form.querySelector('input[name="use_gba_testing"]').checked = projectData.use_gba_testing == 1;
            form.querySelector('input[name="status"]').value = projectData.status;
            openModal('editProjectModal');
        }
        function copyQbLink(element, inputId) { const inputField = element.parentElement.querySelector(`#${inputId}`); const buildId = inputField.value; if (buildId && !isNaN(buildId)) { const url = `https://android.qb.sec.samsung.net/build/${buildId}`; navigator.clipboard.writeText(url).then(() => { const toast = document.getElementById('toast'); toast.classList.add('show'); setTimeout(() => { toast.classList.remove('show'); }, 3000); }).catch(err => console.error('Gagal menyalin link: ', err)); } }
        document.addEventListener('DOMContentLoaded', function () {
            function updateAllCountdowns() {
                document.querySelectorAll('.countdown-timer').forEach(timer => {
                    const dueDateStr = timer.dataset.dueDate;
                    if (!dueDateStr) { timer.textContent = ''; return; }
                    const dueDate = new Date(dueDateStr);
                    const now = new Date();
                    dueDate.setHours(0, 0, 0, 0); now.setHours(0, 0, 0, 0);
                    const diffTime = dueDate - now;
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                    const alertIcon = timer.parentElement.querySelector('.alert-icon');
                    if (diffDays < 0) { timer.textContent = `Lewat ${Math.abs(diffDays)} hari`; timer.classList.add('text-red-500'); alertIcon.classList.add('hidden'); }
                    else if (diffDays === 0) { timer.textContent = 'Hari ini'; timer.classList.add('text-yellow-500'); alertIcon.classList.remove('hidden'); alertIcon.classList.add('animate-pulse-alert'); }
                    else {
                        timer.textContent = `${diffDays} hari lagi`;
                        timer.classList.remove('text-red-500', 'text-yellow-500');
                         if (diffDays <= 3) { alertIcon.classList.remove('hidden'); alertIcon.classList.add('animate-pulse-alert'); }
                         else { alertIcon.classList.add('hidden'); }
                    }
                });
            }
            updateAllCountdowns();
            const columns = document.querySelectorAll('.kanban-column');
            columns.forEach(column => {
                new Sortable(column, {
                    group: 'kanban', animation: 150, ghostClass: 'sortable-ghost',
                    onEnd: async function (evt) {
                        const card = evt.item;
                        const projectId = card.dataset.id;
                        const fromStatus = evt.from.dataset.status;
                        const toStatus = evt.to.dataset.status;

                        if (fromStatus === 'Software Confirm / FOTA') {
                            card.classList.remove('anomaly-card');
                            card.querySelector('.anomaly-warning').classList.add('hidden');
                        }
                        
                        if (toStatus === 'Software Confirm / FOTA') {
                            const response = await fetch('handler.php', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({ action: 'check_gba_status', project_id: projectId })
                            });
                            const result = await response.json();
                            if (!result.is_approved) {
                                evt.from.appendChild(card);
                                card.classList.add('anomaly-card');
                                card.querySelector('.anomaly-warning').classList.remove('hidden');
                                updateColumnCounts();
                                return;
                            }
                        }

                        fetch('handler.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ action: 'update_status', project_id: projectId, new_status: toStatus })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.updated_project) {
                                card.setAttribute('data-project', JSON.stringify(data.updated_project));
                                if (toStatus === 'GBA Testing' && fromStatus !== 'GBA Testing') {
                                    setTimeout(() => window.location.reload(), 300);
                                }
                            } else {
                                console.error('Gagal memperbarui status.');
                                evt.from.appendChild(card);
                            }
                            updateColumnCounts();
                        }).catch(error => {
                            console.error('Error:', error);
                            evt.from.appendChild(card);
                            updateColumnCounts();
                        });
                    }
                });
            });
            function updateColumnCounts() {
                 columns.forEach(column => {
                    const status = column.dataset.status.replace(/[\s\/]/g, '');
                    const count = column.querySelectorAll('.project-card:not(.hidden)').length;
                    document.querySelector(`.count-${status}`).textContent = count;
                });
            }
            searchInput.addEventListener('input', updateColumnCounts);
            filterContainer.addEventListener('click', (e) => {
                if(e.target.tagName === 'BUTTON') updateColumnCounts();
            });
        });
    </script>
</body>
</html>