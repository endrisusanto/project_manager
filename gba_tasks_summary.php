<?php
// 1. INISIALISASI
require_once "config.php";
require_once "session.php"; // Memastikan pengguna sudah login
require_once "marketing_name_mapper.php";

$active_page = 'gba_tasks_summary';

// Definisikan item checklist (Sama persis dengan gba_tasks.php)
$test_plan_items = [
    'Regular Variant' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'], 
    'SKU' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
    'Normal MR' => ['CTS', 'GTS', 'CTS-Verifier', 'ATM'], 
    'SMR' => ['CTS', 'GTS', 'STS', 'SCAT'], 
    'Simple Exception MR' => ['STS']
];


// 3. FUNGSI HELPER TAMPILAN
function getDynamicColorClasses($identifier, $type = 'pic') {
    $pic_colors = ['sky', 'emerald', 'amber', 'rose', 'teal', 'cyan', 'indigo', 'lime', 'pink', 'fuchsia', 'purple', 'yellow', 'orange'];
    $plan_colors = ['indigo', 'lime', 'pink', 'orange', 'fuchsia'];
    $palette = ($type === 'plan') ? $plan_colors : $pic_colors;
    $hash = crc32($identifier);
    return "badge-color-" . $palette[abs($hash) % count($palette)];
}
function getStatusColorClasses($status) {
    $colors = [
        'Approved' => 'badge-color-green',
        'Passed' => 'badge-color-green',
        'Submitted' => 'badge-color-purple',
        'Test Ongoing' => 'badge-color-yellow',
        'Task Baru' => 'badge-color-blue',
        'Downloaded' => 'badge-color-cyan',
        'Batal' => 'badge-color-gray',
        'Pending Feedback' => 'badge-color-orange',
        'Feedback Sent' => 'badge-color-orange'
    ];
    return $colors[$status] ?? 'badge-color-gray';
}
function getStatusDotColor($status) {
    $map = [
        'Task Baru' => 'bg-blue-400 shadow-sm shadow-blue-400/50',
        'Downloaded' => 'bg-cyan-400 shadow-sm shadow-cyan-400/50',
        'Test Ongoing' => 'bg-amber-400 shadow-sm shadow-amber-400/50',
        'Pending Feedback' => 'bg-orange-400 shadow-sm shadow-orange-400/50',
        'Feedback Sent' => 'bg-orange-400 shadow-sm shadow-orange-400/50',
        'Submitted' => 'bg-purple-400 shadow-sm shadow-purple-400/50',
        'Passed' => 'bg-emerald-400 shadow-sm shadow-emerald-400/50',
        'Approved' => 'bg-emerald-400 shadow-sm shadow-emerald-400/50',
        'Batal' => 'bg-slate-400 shadow-sm shadow-slate-400/50'
    ];
    return $map[$status] ?? 'bg-slate-500 shadow-sm shadow-slate-500/50';
}
function getTestPlanBadgeClass($plan) {
    $plan_clean = strtoupper(trim((string)$plan));
    $map = [
        'SMR' => 'plan-pill-smr',
        'FULL TEST' => 'plan-pill-fulltest',
        'FULLTEST' => 'plan-pill-fulltest',
        'SANITY' => 'plan-pill-sanity',
        'MR' => 'plan-pill-mr',
        'NORMAL MR' => 'plan-pill-mr',
        'DELTA TEST' => 'plan-pill-delta',
        'DELTA' => 'plan-pill-delta',
        'REGRESSION' => 'plan-pill-regression'
    ];
    if (isset($map[$plan_clean])) {
        return 'plan-pill ' . $map[$plan_clean];
    }
    $palettes = ['plan-pill-smr', 'plan-pill-fulltest', 'plan-pill-sanity', 'plan-pill-mr', 'plan-pill-delta', 'plan-pill-regression'];
    $hash = abs(crc32($plan_clean));
    return 'plan-pill ' . $palettes[$hash % count($palettes)];
}

// 2. LOGIKA PENGAMBILAN DATA
$tasks_result = $conn->query("SELECT * FROM gba_tasks ORDER BY id DESC, request_date DESC");
$tasks = [];

$total_task_count = 0;
$active_task_count = 0;
$completed_task_count = 0;
$submission_ontime_count = 0;
$submission_total_evaluated = 0;
$approval_ontime_count = 0;
$approval_total_evaluated = 0;

if ($tasks_result) {
    while ($row = $tasks_result->fetch_assoc()) {
        $total_task_count++;
        $st = $row['progress_status'] ?? '';
        if (in_array($st, ['Task Baru', 'Downloaded', 'Test Ongoing', 'Pending Feedback', 'Feedback Sent'])) {
            $active_task_count++;
        } elseif (in_array($st, ['Passed', 'Approved', 'Submitted'])) {
            $completed_task_count++;
        }

        // Proses kalkulasi tanggal dan status di sisi server agar data siap pakai
        $deadline_date = $row['deadline'] ? new DateTime($row['deadline']) : null;
        $request_date_obj = $row['request_date'] ? new DateTime($row['request_date']) : null;
        $submission_date_obj = $row['submission_date'] ? new DateTime($row['submission_date']) : null;
        $approved_date_obj = isset($row['approved_date']) && $row['approved_date'] ? new DateTime($row['approved_date']) : null;

        if ($submission_date_obj && $request_date_obj) {
            $is_ontime = $submission_date_obj->diff($request_date_obj)->days <= 7;
            $row['ontime_submission_status'] = $is_ontime ? 'Ontime' : 'Delay';
            $submission_total_evaluated++;
            if ($is_ontime) $submission_ontime_count++;
        } else { $row['ontime_submission_status'] = null; }
        
        if ($approved_date_obj && $submission_date_obj) {
            $is_app_ontime = $approved_date_obj->diff($submission_date_obj)->days <= 3;
            $row['ontime_approved_status'] = $is_app_ontime ? 'Ontime' : 'Delay';
            $approval_total_evaluated++;
            if ($is_app_ontime) $approval_ontime_count++;
        } else { $row['ontime_approved_status'] = null; }

        $row['deadline_countdown'] = null;
        if (!$submission_date_obj && $deadline_date) {
            $now = new DateTime(); $now->setTime(0,0,0);
            $deadline_date->setTime(0,0,0);
            $diff = $now->diff($deadline_date);
            $row['deadline_countdown'] = ($now <= $deadline_date) ? $diff->days : -$diff->days;
        }

        $row['approval_countdown'] = null;
        if ($submission_date_obj && !$approved_date_obj) {
            $approval_deadline = (clone $submission_date_obj)->modify('+3 days');
            $now = new DateTime(); $now->setTime(0,0,0);
            $approval_deadline->setTime(0,0,0);
            $diff = $now->diff($approval_deadline);
            $row['approval_countdown'] = ($now <= $approval_deadline) ? $diff->days : -$diff->days;
        }
        
        // LOGIKA PERHITUNGAN PROGRESS
        $checklist = json_decode((string)($row['test_items_checklist'] ?? ''), true);
        $plan_type = $row['test_plan_type'];
        $total_items = isset($test_plan_items[$plan_type]) ? count($test_plan_items[$plan_type]) : 0;
        $completed_items = 0;
        if ($total_items > 0 && is_array($checklist)) {
            foreach ($test_plan_items[$plan_type] as $item) {
                $item_key = str_replace([' ', '-'], '_', $item);
                if (!empty($checklist[$item_key])) { $completed_items++; }
            }
        }
        $row['progress_percentage'] = $total_items > 0 ? ($completed_items / $total_items) * 100 : 0;

        // Menambahkan kelas warna ke data
        $row['pic_color_class'] = getDynamicColorClasses($row['pic_email'] ?? 'N/A', 'pic');
        $row['plan_color_class'] = getDynamicColorClasses($row['test_plan_type'] ?? 'N/A', 'plan');
        $row['status_color_class'] = getStatusColorClasses($row['progress_status']);

        // Data ini dikirim ke JavaScript
        $tasks[] = $row;
    }
}

$submission_ontime_pct = $submission_total_evaluated > 0 ? round(($submission_ontime_count / $submission_total_evaluated) * 100) : 100;
$approval_ontime_pct = $approval_total_evaluated > 0 ? round(($approval_ontime_count / $approval_total_evaluated) * 100) : 100;

$all_test_plans = array_keys($test_plan_items);
$all_statuses = ['Task Baru', 'Downloaded', 'Test Ongoing', 'Pending Feedback', 'Feedback Sent', 'Submitted', 'Passed', 'Approved', 'Batal'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['class', '.never-match-dark']
        }
    </script>
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <style>
        :root{--bg-primary:#020617;--text-primary:#e2e8f0;--text-secondary:#94a3b8;--glass-bg:rgba(15,23,42,.4);--glass-border:rgba(51,65,85,.4);--modal-bg:rgba(15,23,42,.6);--modal-border:rgba(51,65,85,.6);--input-bg:rgba(30,41,59,.7);--input-border:#475569;--progress-bg:#1e293b;--progress-fill:#3b82f6;--toast-bg:#22c55e;--toast-text:#fff;--filter-btn-bg:rgba(255,255,255,.05);--filter-btn-bg-active:#2563eb;--text-header:#fff;--text-icon:#94a3b8}
        html.light{--bg-primary:#f1f5f9;--text-primary:#0f172a;--text-secondary:#475569;--glass-bg:rgba(255,255,255,.35);--glass-border:rgba(0,0,0,.08);--modal-bg:rgba(255,255,255,.6);--modal-border:rgba(0,0,0,.1);--input-bg:#fff;--input-border:#cbd5e1;--progress-bg:#e2e8f0;--toast-bg:#16a34a;--filter-btn-bg:rgba(0,0,0,.05);--text-header:#0f172a;--text-icon:#475569}
        html{scroll-behavior:smooth}body{font-family:'Inter',sans-serif;background-color:var(--bg-primary);color:var(--text-primary)}html,body{height:100%;overflow:hidden}main{height:calc(100% - 64px)}.table-container{scroll-behavior:smooth}#neural-canvas{position:fixed;top:0;left:0;width:100%;height:100%;z-index:-1}.glass-container{background:var(--glass-bg);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border-bottom:1px solid var(--glass-border)}.glassmorphism-table{background:var(--glass-bg);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid var(--glass-border)}.glassmorphism-modal{background:var(--modal-bg);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid var(--modal-border)}
        .nav-link{color:var(--text-secondary);transition:color .2s,border-color .2s;border-bottom:2px solid transparent}.nav-link:hover{color:var(--text-primary)}.nav-link-active{color:var(--text-primary)!important;font-weight:500;border-bottom:2px solid #3b82f6}.themed-input{background-color:var(--input-bg);border:1px solid var(--input-border)}html.light .themed-input,html.light .ql-editor{color:var(--text-primary)}.themed-input:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 2px rgba(59,130,246,.5)}input[type="date"]::-webkit-calendar-picker-indicator{filter:invert(var(--date-picker-invert,1))}html.light{--date-picker-invert:0}.ql-toolbar,.ql-container{border-color:var(--glass-border)!important}.ql-editor{color:var(--text-primary);min-height:100px}.ql-snow .ql-stroke{stroke:var(--text-icon)}.ql-snow .ql-picker-label{color:var(--text-icon)}
        .progress-bar-bg{background-color:var(--progress-bg)}.progress-bar-fill{background-color:var(--progress-fill);transition:width .6s ease-in-out;background-image:linear-gradient(45deg,rgba(255,255,255,.15) 25%,transparent 25%,transparent 50%,rgba(255,255,255,.15) 50%,rgba(255,255,255,.15) 75%,transparent 75%,transparent);background-size:1rem 1rem;animation:progress-bar-stripes 1s linear infinite}@keyframes progress-bar-stripes{from{background-position:1rem 0}to{background-position:0 0}}.progress-text{font-size:10px;font-weight:700;padding:0 6px;border-radius:4px;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,0.75)}
        #toast{position:fixed;bottom:-100px;left:50%;transform:translateX(-50%);background-color:var(--toast-bg);color:var(--toast-text);padding:12px 20px;border-radius:8px;z-index:1000;transition:bottom .5s ease-in-out}#toast.show{bottom:30px}
        
        .filter-button{background-color:var(--filter-btn-bg, rgba(255,255,255,0.05));color:var(--text-secondary);border:1px solid var(--glass-border);border-radius:9999px;font-weight:600;font-size:12px;padding:6px 14px;transition:all .15s cubic-bezier(0.16,1,0.3,1);white-space:nowrap}
        .filter-button:hover{background-color:rgba(255,255,255,.1);color:var(--text-primary)}
        html.light .filter-button{background-color:#ffffff;border-color:#e2e8f0;color:#64748b}
        html.light .filter-button:hover{background-color:#f8fafc;color:#0f172a;border-color:#cbd5e1}
        .filter-button.active{background:linear-gradient(135deg, #2563eb, #3b82f6) !important;color:#ffffff !important;border-color:#3b82f6 !important;box-shadow:0 2px 8px rgba(37,99,235,0.35)}
        
        /* Pill Shape Badges for Test Plans with distinctive semantic palettes */
        .plan-pill { display: inline-flex; align-items: center; justify-content: center; border-radius: 9999px; font-weight: 700; font-size: 11px; padding: 2px 8px; text-transform: uppercase; letter-spacing: 0.04em; border: 1px solid transparent; line-height: 1.2; transition: all 0.15s ease; }
        .plan-pill-smr { background: rgba(99, 102, 241, 0.16); color: #a5b4fc; border-color: rgba(99, 102, 241, 0.35); }
        html.light .plan-pill-smr { background: #eef2ff; color: #4338ca; border-color: #c7d2fe; }
        .plan-pill-fulltest { background: rgba(245, 158, 11, 0.16); color: #fcd34d; border-color: rgba(245, 158, 11, 0.35); }
        html.light .plan-pill-fulltest { background: #fffbeb; color: #b45309; border-color: #fde68a; }
        .plan-pill-sanity { background: rgba(16, 185, 129, 0.16); color: #6ee7b7; border-color: rgba(16, 185, 129, 0.35); }
        html.light .plan-pill-sanity { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .plan-pill-mr { background: rgba(6, 182, 212, 0.16); color: #67e8f9; border-color: rgba(6, 182, 212, 0.35); }
        html.light .plan-pill-mr { background: #ecfeff; color: #0e7490; border-color: #a5f3fc; }
        .plan-pill-delta { background: rgba(217, 70, 239, 0.16); color: #f0abfc; border-color: rgba(217, 70, 239, 0.35); }
        html.light .plan-pill-delta { background: #fdf4ff; color: #a21caf; border-color: #f5d0fe; }
        .plan-pill-regression { background: rgba(244, 63, 94, 0.16); color: #fda4af; border-color: rgba(244, 63, 94, 0.35); }
        html.light .plan-pill-regression { background: #fff1f2; color: #be123c; border-color: #fecdd3; }

        .card-action-btn { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 8px; color: var(--text-icon); transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1); }
        .card-action-btn:hover { background: rgba(255, 255, 255, 0.08); }
        html.light .card-action-btn:hover { background: rgba(0, 0, 0, 0.06); }
        .card-action-btn:active { transform: scale(0.92); }

        .build-specs-box { display: flex; flex-direction: column; gap: 2.5px; padding: 5px 8px; margin-top: 3px; border-radius: 8px; background: rgba(0, 0, 0, 0.2); border: 1px solid rgba(255, 255, 255, 0.05); font-size: 11px; line-height: 1.35; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
        html.light .build-specs-box { background: rgba(0, 0, 0, 0.035); border-color: rgba(0, 0, 0, 0.06); }

        .kpi-card { background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: 1rem; padding: 1rem 1.25rem; backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); transition: transform 0.18s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.18s; }
        .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px -4px rgba(0, 0, 0, 0.15); }

        tbody tr { transition: background-color 0.15s ease; }
        tbody tr:hover{background-color:rgba(255,255,255,0.04)}
        html.light tbody tr:hover{background-color:rgba(15,23,42,0.03)!important}
        .themed-input option{background-color:var(--bg-primary);color:var(--text-primary)}
        
        .badge{display:inline-block;padding:.25rem .6rem;font-size:.75rem;font-weight:500;border-radius:.75rem;line-height:1.2}.badge-color-sky{background-color:rgba(14,165,233,.2);color:#7dd3fc}.badge-color-emerald{background-color:rgba(16,185,129,.2);color:#6ee7b7}.badge-color-amber{background-color:rgba(245,158,11,.2);color:#fcd34d}.badge-color-rose{background-color:rgba(244,63,94,.2);color:#fda4af}.badge-color-violet{background-color:rgba(139,92,246,.2);color:#c4b5fd}.badge-color-teal{background-color:rgba(20,184,166,.2);color:#5eead4}.badge-color-cyan{background-color:rgba(6,182,212,.2);color:#67e8f9}.badge-color-indigo{background-color:rgba(99,102,241,.2);color:#a5b4fc}.badge-color-lime{background-color:rgba(132,204,22,.2);color:#bef264}.badge-color-pink{background-color:rgba(236,72,153,.2);color:#f9a8d4}.badge-color-fuchsia{background-color:rgba(217,70,239,.2);color:#f0abfc}.badge-color-green{background-color:rgba(34,197,94,.2);color:#86efac}.badge-color-purple{background-color:rgba(168,85,247,.2);color:#d8b4fe}.badge-color-yellow{background-color:rgba(234,179,8,.2);color:#fde047}.badge-color-blue{background-color:rgba(59,130,246,.2);color:#93c5fd}.badge-color-gray{background-color:rgba(107,114,128,.2);color:#d1d5db}.badge-color-orange{background-color:rgba(249,115,22,.2);color:#fdba74}
        html.light .badge-color-sky{background-color:#e0f2fe;color:#0369a1}html.light .badge-color-emerald{background-color:#d1fae5;color:#047857}html.light .badge-color-amber{background-color:#fef3c7;color:#92400e}html.light .badge-color-rose{background-color:#ffe4e6;color:#9f1239}html.light .badge-color-violet{background-color:#ede9fe;color:#5b21b6}html.light .badge-color-teal{background-color:#ccfbf1;color:#0d9488}html.light .badge-color-cyan{background-color:#cffafe;color:#0e7490}html.light .badge-color-indigo{background-color:#e0e7ff;color:#3730a3}html.light .badge-color-lime{background-color:#ecfccb;color:#4d7c0f}html.light .badge-color-pink{background-color:#fce7f3;color:#9d174d}html.light .badge-color-fuchsia{background-color:#fae8ff;color:#86198f}html.light .badge-color-green{background-color:#dcfce7;color:#15803d}html.light .badge-color-purple{background-color:#f3e8ff;color:#6b21a8}html.light .badge-color-yellow{background-color:#fef9c3;color:#854d0e}html.light .badge-color-blue{background-color:#dbeafe;color:#1e40af}html.light .badge-color-gray{background-color:#f3f4f6;color:#374151}html.light .badge-color-orange{background-color:#ffedd5;color:#9a3412}html.light .font-semibold.text-green-400{color:#15803d}html.light .font-semibold.text-red-400{color:#b91c1c}
        @keyframes pulse-alert{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.2);opacity:.7}}.animate-pulse-alert{animation:pulse-alert 1.5s infinite;color:#f87171}html.light .animate-pulse-alert{color:#dc2626}
        .qb-link{cursor:pointer;text-decoration:underline;color:#93c5fd;transition:color 0.15s ease;}
        .qb-link:hover{color:#60a5fa;}
        html.light .qb-link{color:#2563eb}
        html.light .qb-link:hover{color:#1d4ed8}
        .urgent-row { position: relative; border-left: 3px solid transparent; animation: urgent-row-glow 1.5s infinite; }
        @keyframes urgent-row-glow {
            0%, 100% { border-left-color: rgba(239, 68, 68, 0.7); box-shadow: inset 3px 0 8px -2px rgba(239, 68, 68, 0.5); }
            50% { border-left-color: rgba(239, 68, 68, 0.4); box-shadow: inset 3px 0 15px -2px rgba(239, 68, 68, 0.3); }
        }
        .table-container td { vertical-align: middle; }
        #pagination-rows { color: var(--text-primary); }
        #pagination-rows option { background-color: var(--bg-primary); color: var(--text-primary); }

        /* --- CP MISMATCH GLOW --- */
        .glow-highlight-red {
            animation: red-glow-text 1.5s infinite alternate;
            color: #f87171 !important;
        }
        @keyframes red-glow-text {
            from { text-shadow: 0 0 2px #f87171, 0 0 4px rgba(255, 0, 0, 0.4); }
            to { text-shadow: 0 0 6px #fee2e2, 0 0 8px rgba(255, 0, 0, 0.7); }
        }
        html.light .glow-highlight-red {
            color: #dc2626 !important;
            text-shadow: none;
        }

        /* --- COPY TOOLTIP CSS (better-ui / antislop) --- */
        .copy-tooltip {
            position: fixed;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background-color: rgba(15, 23, 42, 0.94);
            color: #f8fafc;
            padding: 5px 11px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.01em;
            pointer-events: none;
            z-index: 99999;
            opacity: 0;
            transform: translate(-50%, -50%) scale(0.9);
            transition: opacity 0.18s cubic-bezier(0.16, 1, 0.3, 1), transform 0.18s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 10px 25px -4px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .copy-tooltip.show {
            opacity: 1;
            transform: translate(-50%, -100%) translateY(-8px) scale(1);
        }

        html.light .copy-tooltip {
            background-color: rgba(15, 23, 42, 0.92);
            color: #ffffff;
            box-shadow: 0 10px 25px -4px rgba(0, 0, 0, 0.2), 0 0 0 1px rgba(0, 0, 0, 0.12);
        }
        /* --- END COPY TOOLTIP CSS --- */
    </style>
</head>
<body class="min-h-screen">
    <canvas id="neural-canvas"></canvas>
    <div id="toast">Link QB berhasil disalin!</div>

    <?php include 'header.php'; ?>

    <main class="w-full h-full flex flex-col">
        <!-- KPI Metric Cards Grid -->
        <div class="px-4 sm:px-6 lg:px-8 pt-5 pb-2">
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                <!-- Card 1: Total Tasks -->
                <div class="kpi-card flex flex-col justify-between">
                    <div class="flex items-center justify-between text-secondary">
                        <span class="text-xs font-semibold uppercase tracking-wider">Total Task</span>
                        <div class="w-7 h-7 rounded-lg bg-blue-500/10 text-blue-400 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        </div>
                    </div>
                    <div class="mt-2">
                        <span class="text-2xl font-extrabold text-primary font-mono tracking-tight"><?= $total_task_count ?></span>
                        <span class="text-[11px] text-secondary ml-1">keseluruhan</span>
                    </div>
                </div>

                <!-- Card 2: Active Tasks -->
                <div class="kpi-card flex flex-col justify-between">
                    <div class="flex items-center justify-between text-secondary">
                        <span class="text-xs font-semibold uppercase tracking-wider">Task Aktif</span>
                        <div class="w-7 h-7 rounded-lg bg-amber-500/10 text-amber-400 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                    </div>
                    <div class="mt-2">
                        <span class="text-2xl font-extrabold text-amber-400 font-mono tracking-tight"><?= $active_task_count ?></span>
                        <span class="text-[11px] text-secondary ml-1">ongoing</span>
                    </div>
                </div>

                <!-- Card 3: Completed Tasks -->
                <div class="kpi-card flex flex-col justify-between">
                    <div class="flex items-center justify-between text-secondary">
                        <span class="text-xs font-semibold uppercase tracking-wider">Selesai / Passed</span>
                        <div class="w-7 h-7 rounded-lg bg-emerald-500/10 text-emerald-400 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                    </div>
                    <div class="mt-2">
                        <span class="text-2xl font-extrabold text-emerald-400 font-mono tracking-tight"><?= $completed_task_count ?></span>
                        <span class="text-[11px] text-secondary ml-1">task</span>
                    </div>
                </div>

                <!-- Card 4: Submission On-Time -->
                <div class="kpi-card flex flex-col justify-between">
                    <div class="flex items-center justify-between text-secondary">
                        <span class="text-xs font-semibold uppercase tracking-wider">On-Time Sub</span>
                        <div class="w-7 h-7 rounded-lg bg-indigo-500/10 text-indigo-400 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </div>
                    </div>
                    <div class="mt-2">
                        <span class="text-2xl font-extrabold text-indigo-400 font-mono tracking-tight"><?= $submission_ontime_pct ?>%</span>
                        <span class="text-[11px] text-secondary ml-1">(<?= $submission_ontime_count ?>/<?= $submission_total_evaluated ?>)</span>
                    </div>
                </div>

                <!-- Card 5: Approval On-Time -->
                <div class="kpi-card flex flex-col justify-between col-span-2 sm:col-span-1">
                    <div class="flex items-center justify-between text-secondary">
                        <span class="text-xs font-semibold uppercase tracking-wider">On-Time App</span>
                        <div class="w-7 h-7 rounded-lg bg-teal-500/10 text-teal-400 flex items-center justify-center">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </div>
                    </div>
                    <div class="mt-2">
                        <span class="text-2xl font-extrabold text-teal-400 font-mono tracking-tight"><?= $approval_ontime_pct ?>%</span>
                        <span class="text-[11px] text-secondary ml-1">(<?= $approval_ontime_count ?>/<?= $approval_total_evaluated ?>)</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Toolbar Section -->
        <div class="px-4 sm:px-6 lg:px-8 pt-3 pb-3">
            <div class="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3 bg-[var(--glass-bg)] border border-[var(--glass-border)] rounded-2xl p-3 backdrop-blur-md shadow-sm">
                <!-- Test Plan Pills -->
                <div id="testplan-filter-container" class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0 scrollbar-none">
                    <button class="filter-button active" data-plan="All">Semua</button>
                    <?php foreach($all_test_plans as $plan): ?>
                        <button class="filter-button" data-plan="<?= htmlspecialchars($plan) ?>"><?= htmlspecialchars($plan) ?></button>
                    <?php endforeach; ?>
                </div>

                <!-- Controls & Action Buttons -->
                <div class="flex flex-wrap items-center gap-2.5 ml-auto">
                    <!-- Status Filter -->
                    <select id="status-filter" class="themed-input h-9 px-3 text-xs font-medium rounded-xl border border-[var(--glass-border)] focus:ring-2 focus:ring-blue-500 transition-all cursor-pointer">
                        <option value="All">Semua Status</option>
                        <?php foreach($all_statuses as $status): ?>
                            <option value="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Export Excel -->
                    <a id="export-button" href="export_handler.php" class="h-9 px-3.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 active:scale-95 text-white text-xs font-medium inline-flex items-center gap-1.5 transition-all shadow-sm">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span>Export Excel</span>
                    </a>

                    <!-- Export All AP -->
                    <a id="export-latest-ap-button" href="export_latest_ap.php" class="h-9 px-3.5 rounded-xl bg-blue-600 hover:bg-blue-700 active:scale-95 text-white text-xs font-medium inline-flex items-center gap-1.5 transition-all shadow-sm">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        <span>Export All AP</span>
                    </a>

                    <?php
                    $new_tasks_qb_ids = [];
                    if (!empty($tasks)) {
                        foreach ($tasks as $t) {
                            if (isset($t['progress_status']) && $t['progress_status'] === 'Task Baru') {
                                if (!empty($t['qb_user']) && trim($t['qb_user']) !== '-') {
                                    $new_tasks_qb_ids[] = trim($t['qb_user']);
                                }
                                if (!empty($t['qb_userdebug']) && trim($t['qb_userdebug']) !== '-') {
                                    $new_tasks_qb_ids[] = trim($t['qb_userdebug']);
                                }
                            }
                        }
                    }
                    $new_tasks_qb_ids_str = implode(',', array_unique(array_filter($new_tasks_qb_ids)));
                    ?>
                    <button onclick="copyNewTasksQbIds(event, this)"
                        data-qb-ids="<?= htmlspecialchars($new_tasks_qb_ids_str) ?>"
                        title="Copy all QB Build IDs for New Tasks (Semua Plan)"
                        class="h-9 px-3.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 active:scale-95 text-white text-xs font-medium inline-flex items-center gap-1.5 transition-all shadow-sm">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        <span>Copy QB ID Baru</span>
                    </button>

                    <?php
                    $summary_active_build_rows = [];
                    $eligible_summary_statuses = ['task baru', 'downloaded', 'test ongoing', 'ongoing', 'pending feedback', 'feedback sent', 'submitted'];
                    if (!empty($tasks)) {
                        foreach ($tasks as $t) {
                            $st = strtolower(trim($t['progress_status'] ?? ''));
                            if (in_array($st, $eligible_summary_statuses)) {
                                $m = trim($t['model_name'] ?? '');
                                $a = trim($t['ap'] ?? '');
                                $c = trim($t['cp'] ?? '');
                                $csc = trim($t['csc'] ?? '');
                                if ($m !== '' || $a !== '' || $c !== '' || $csc !== '') {
                                    $summary_active_build_rows[] = "{$m}\t{$a}\t{$csc}\t{$c}";
                                }
                            }
                        }
                    }
                    $summary_active_builds_str = implode("\n", $summary_active_build_rows);
                    ?>
                    <button onclick="copyActiveTasksBuilds(event, this)"
                        data-build-info="<?= htmlspecialchars($summary_active_builds_str) ?>"
                        title="Copy Model, AP, CSC, CP untuk task status Task Baru, Downloaded, Ongoing, Pending Feedback, Feedback Sent, Submitted"
                        class="h-9 px-3.5 rounded-xl bg-amber-600 hover:bg-amber-700 active:scale-95 text-white text-xs font-medium inline-flex items-center gap-1.5 transition-all shadow-sm">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2" />
                        </svg>
                        <span>Build HomeBinary</span>
                    </button>

                    <!-- Rows Selector -->
                    <div class="flex items-center gap-1.5 text-xs text-secondary">
                        <span class="hidden sm:inline">Baris:</span>
                        <select id="pagination-rows" class="themed-input h-9 px-2.5 text-xs font-medium rounded-xl border border-[var(--glass-border)] focus:ring-2 focus:ring-blue-500 transition-all cursor-pointer">
                            <option value="10">10</option>
                            <option value="30" selected>30</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Table Container -->
        <div class="flex-grow overflow-auto px-4 sm:px-6 lg:px-8 pb-16 table-container">
            <div class="glassmorphism-table rounded-2xl border border-[var(--glass-border)] overflow-hidden shadow-sm backdrop-blur-md">
                <table class="w-full text-xs sm:text-sm text-left border-collapse">
                    <thead>
                        <tr class="border-b border-[var(--glass-border)] bg-[var(--glass-bg)] text-[11px] font-bold uppercase tracking-wider text-[var(--text-secondary)]">
                            <th class="py-3.5 px-3 text-center sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-md">No.</th>
                            <th class="py-3.5 px-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-md">Model & Build Specs</th>
                            <th class="py-3.5 px-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-md">QB Build</th>
                            <th class="py-3.5 px-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-md">PIC</th>
                            <th class="py-3.5 px-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-md">Test Plan</th>
                            <th class="py-3.5 px-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-md">Status</th>
                            <th class="py-3.5 px-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-md">Progress</th>
                            <th class="py-3.5 px-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-md">Tanggal</th>
                            <th class="py-3.5 px-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-md">Kinerja</th>
                            <th class="py-3.5 px-3 sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-md text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="task-table-body" class="divide-y divide-[var(--glass-border)]">
                        <tr><td colspan="10" class="text-center py-12 text-secondary font-medium">Memuat data...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="pagination-nav" class="flex justify-center items-center gap-1.5 py-5 text-secondary"></div>
        </div>
    </main>

    <!-- ============ REDISTRIBUTE MODAL ============ -->
    <div id="redistribute-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-70 hidden">
        <div class="glassmorphism-modal rounded-xl shadow-2xl p-6 w-full max-w-4xl mx-4 max-h-[90vh] flex flex-col">
            <div class="flex justify-between items-center mb-4 flex-shrink-0">
                <div>
                    <h2 class="text-xl font-bold text-primary">🗓️ Preview Distribusi Request Date</h2>
                    <p class="text-sm text-secondary mt-1">Maks <strong class="text-violet-400">3 task/PIC/hari</strong> — hanya task belum submit dan <strong class="text-red-400">bukan Urgent</strong> yang akan diubah</p>
                </div>
                <button onclick="closeRedistributeModal()" class="p-2 rounded-lg hover:bg-white/10 text-secondary">✕</button>
            </div>

            <!-- Loading State -->
            <div id="redistribute-loading" class="flex-grow flex items-center justify-center py-12">
                <div class="text-center">
                    <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-violet-400 mx-auto mb-3"></div>
                    <p class="text-secondary text-sm">Menghitung distribusi...</p>
                </div>
            </div>

            <!-- Preview Table -->
            <div id="redistribute-content" class="hidden flex-grow overflow-auto">
                <div id="redistribute-summary" class="mb-3 text-sm"></div>
                <div class="glassmorphism-table rounded-lg overflow-hidden">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="border-b border-[var(--glass-border)]">
                                <th class="p-3 sticky top-0 bg-[var(--glass-bg)] backdrop-blur-sm text-secondary">PIC</th>
                                <th class="p-3 sticky top-0 bg-[var(--glass-bg)] backdrop-blur-sm text-secondary">Model</th>
                                <th class="p-3 sticky top-0 bg-[var(--glass-bg)] backdrop-blur-sm text-secondary">Request Date Lama</th>
                                <th class="p-3 sticky top-0 bg-[var(--glass-bg)] backdrop-blur-sm text-secondary">Request Date Baru</th>
                                <th class="p-3 sticky top-0 bg-[var(--glass-bg)] backdrop-blur-sm text-secondary">Deadline Baru</th>
                            </tr>
                        </thead>
                        <tbody id="redistribute-table-body"></tbody>
                    </table>
                </div>
            </div>

            <!-- Error State -->
            <div id="redistribute-error" class="hidden flex-grow flex items-center justify-center text-red-400 text-sm py-8"></div>

            <!-- Footer Buttons -->
            <div class="flex justify-end gap-3 mt-4 flex-shrink-0">
                <button onclick="closeRedistributeModal()" class="px-4 py-2 rounded-lg themed-input text-sm">Batal</button>
                <button id="redistribute-confirm-btn" onclick="executeRedistribute()" class="hidden px-5 py-2 bg-violet-600 hover:bg-violet-500 text-white rounded-lg text-sm font-semibold">
                    ✅ Terapkan Perubahan
                </button>
            </div>
        </div>
    </div>
    <!-- ============ END REDISTRIBUTE MODAL ============ -->

    <div id="task-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/65 backdrop-blur-sm hidden">
        <div class="modal-content-wrapper rounded-2xl shadow-2xl p-4 sm:p-5 w-full max-w-5xl mx-3">
            <form id="task-form" action="handler.php" method="POST">
                <div class="flex justify-between items-center mb-3 pb-2 border-b border-[var(--glass-border)]">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-blue-500 shadow-sm shadow-blue-500/50"></div>
                        <h2 id="modal-title" class="text-base font-bold text-primary">Tambah Task Baru</h2>
                    </div>
                    <div class="flex justify-end items-center gap-2">
                        <button type="button" onclick="closeModal()" class="modal-btn-cancel" title="Tutup modal (Escape)">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            <span>Batal</span>
                            <kbd class="hidden sm:inline-flex items-center px-1.5 py-0.5 text-[9px] font-mono font-semibold text-secondary bg-white/5 border border-[var(--glass-border)] rounded shadow-sm">ESC</kbd>
                        </button>
                        <button type="submit" class="modal-btn-save">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span>Simpan Task</span>
                        </button>
                    </div>
                </div>
                <input type="hidden" name="id" id="task-id">
                <input type="hidden" name="action" id="form-action" value="create_gba_task">
                <?php include 'gba_task_form.php'; ?>
            </form>
        </div>
    </div>
    
    <script>
        const allTasksData = <?= json_encode($tasks) ?>;
        const isAdmin = <?= json_encode(is_admin()) ?>;
        const userdataModels = <?= json_encode(array_keys(array_filter($userdata_models ?? []))) ?>;
        const modelMapping = <?= json_encode($model_mapping ?? []) ?>;

        function isUserdataRequired(modelName) {
            if (!modelName) return false;
            const upperModel = modelName.toUpperCase();
            return userdataModels.some(prefix => upperModel.includes(prefix.toUpperCase()));
        }

        function getMarketingNameJs(modelName, projectName) {
            if (projectName && projectName.trim() !== '' && projectName.trim() !== '-') {
                return projectName.trim();
            }
            if (!modelName) return '';
            const clean = modelName.trim().toUpperCase();
            if (modelMapping[clean]) return modelMapping[clean];
            for (const key in modelMapping) {
                if (clean.startsWith(key.toUpperCase())) return modelMapping[key];
            }
            return '';
        }

        const canvas = document.getElementById('neural-canvas'), ctx = canvas.getContext('2d');
        let particles = [], hue = 210;
        function setCanvasSize(){canvas.width=window.innerWidth;canvas.height=window.innerHeight;}setCanvasSize();
        
        class Particle{
            constructor(x,y){
                this.x=x||Math.random()*canvas.width;
                this.y=y||Math.random()*canvas.height;
                this.vx=(Math.random()-.5)*.4;
                this.vy=(Math.random()-.5)*.4;
                this.size=Math.random()*2 + 1.5;
            }
            update(){
                this.x+=this.vx;this.y+=this.vy;
                if(this.x<0||this.x>canvas.width)this.vx*=-1;
                if(this.y<0||this.y>canvas.height)this.vy*=-1;
            }
            draw(){
                ctx.fillStyle=`hsl(${hue},100%,75%)`;
                ctx.beginPath();
                ctx.arc(this.x,this.y,this.size,0,Math.PI*2);
                ctx.fill();
            }
        }

        function init(num){
            particles = [];
            for(let i=0;i<num;i++)particles.push(new Particle())
        }

        function handleParticles() {
            for(let i = 0; i < particles.length; i++) {
                particles[i].update();
                particles[i].draw();
                for (let j = i; j < particles.length; j++) {
                    const dx = particles[i].x - particles[j].x;
                    const dy = particles[i].y - particles[j].y;
                    const distance = Math.sqrt(dx * dx + dy * dy);
                    if (distance < 120) {
                        ctx.beginPath();
                        ctx.strokeStyle = `hsla(${hue}, 100%, 80%, ${1 - distance / 120})`; 
                        ctx.lineWidth = 1;
                        ctx.moveTo(particles[i].x, particles[i].y);
                        ctx.lineTo(particles[j].x, particles[j].y);
                        ctx.stroke();
                        ctx.closePath();
                    }
                }
            }
        }

        function animate(){
            ctx.clearRect(0,0,canvas.width,canvas.height);
            hue = (hue + 0.3) % 360; 
            handleParticles();
            requestAnimationFrame(animate);
        }
        
        const particleCount = window.innerWidth > 768 ? 150 : 70;
        init(particleCount);
        animate();

        // --- PAGE SPECIFIC LOGIC ---
        const themeToggleBtn=document.getElementById('theme-toggle'),modal=document.getElementById('task-modal'),modalTitle=document.getElementById('modal-title'),taskForm=document.getElementById('task-form'),formAction=document.getElementById('form-action'),taskId=document.getElementById('task-id');let quill;
        window.addEventListener('resize',()=>{setCanvasSize();init(particleCount)});
        function applyTheme(isLight){document.documentElement.classList.toggle('light',isLight);document.getElementById('theme-toggle-light-icon').classList.toggle('hidden',!isLight);document.getElementById('theme-toggle-dark-icon').classList.toggle('hidden',isLight)}const savedTheme=localStorage.getItem('theme');applyTheme(savedTheme==='light');themeToggleBtn.addEventListener('click',()=>{const isLight=!document.documentElement.classList.contains('light');localStorage.setItem('theme',isLight?'light':'dark');applyTheme(isLight)});
        function openAddModal(){
            taskForm.reset();
            modalTitle.innerText='Tambah Task Baru';
            formAction.value='create_gba_task';
            taskId.value='';
            setupQuill('');
            updateChecklistVisibility();
            setDefaultDates();
            modal.classList.remove('modal-closing', 'hidden');
        }
        
        function openEditModal(task){
            taskForm.reset();
            modalTitle.innerText='Edit Task';
            formAction.value='update_gba_task';
            
            for(const key in task){
                if(taskForm.elements[key]&&!key.endsWith('_obj')){
                    taskForm.elements[key].value=task[key]
                }
            }
            
            document.getElementById('is_urgent_toggle').checked=task.is_urgent==1;
            setupQuill(task.notes||'');
            updateChecklistVisibility();

            document.querySelectorAll('[id^="checklist-container-"] input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });

            const autoCheckStatuses = ['Approved', 'Submitted', 'Passed', 'Pending Feedback', 'Feedback Sent'];
            if (autoCheckStatuses.includes(task.progress_status)) {
                checkAllVisibleCheckboxes(true);
            } else if(task.test_items_checklist){
                try{
                    const checklist=JSON.parse(task.test_items_checklist);
                    const visibleContainer = document.querySelector('[id^="checklist-container-"]:not(.hidden)');
                    if (visibleContainer) {
                        for(const itemName in checklist){
                            const checkbox=visibleContainer.querySelector(`input[name="checklist[${itemName}]"]`);
                            if(checkbox)checkbox.checked=!!checklist[itemName];
                        }
                    }
                }catch(e){
                    console.error("Could not parse checklist JSON:",e)
                }
            }
            
            modal.classList.remove('modal-closing', 'hidden');
        }
        
        let isTaskModalClosing = false;
        function closeModal(){
            if (!modal || modal.classList.contains('hidden') || isTaskModalClosing) return;
            isTaskModalClosing = true;
            modal.classList.add('modal-closing');
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('modal-closing');
                isTaskModalClosing = false;
            }, 160);
        }

        // Close on backdrop click with smooth motion
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal();
        });
        
        // Quick Action: Escape key to close modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' || e.key === 'Esc') {
                if (modal && !modal.classList.contains('hidden')) {
                    closeModal();
                }
                const redistModal = document.getElementById('redistribute-modal');
                if (redistModal && !redistModal.classList.contains('hidden')) {
                    closeRedistributeModal();
                }
            }
        });
        document.getElementById('test_plan_type').addEventListener('change',updateChecklistVisibility);function setupQuill(content){if(quill){quill.root.innerHTML=content}else{quill=new Quill('#notes-editor',{theme:'snow',modules:{toolbar:[['bold','italic','underline'],['link'],[{'list':'ordered'},{'list':'bullet'}]]}});quill.root.innerHTML=content}}
        taskForm.addEventListener('submit',function(){ document.getElementById('notes-hidden-input').value=quill.root.innerHTML; const visibleChecklist=document.querySelector('[id^="checklist-container-"]:not(.hidden)'); if(visibleChecklist){ document.querySelectorAll('[id^="checklist-hidden-"]').forEach(el=>el.remove()); visibleChecklist.querySelectorAll('input[type="checkbox"]').forEach(cb=>{ const hidden=document.createElement('input'); hidden.type='hidden'; hidden.id='checklist-hidden-'+cb.name.replace(/[\[\]]/g,'_'); hidden.name=cb.name; hidden.value=cb.checked?'1':'0'; taskForm.appendChild(hidden); cb.disabled=true; }); } });function updateChecklistVisibility(){const testPlan=document.getElementById('test_plan_type').value,placeholder=document.getElementById('checklist-placeholder');let checklistVisible=!1;document.querySelectorAll('[id^="checklist-container-"]').forEach(el=>{const planName=el.id.replace('checklist-container-','').replace(/_/g,' ');if(planName===testPlan){el.classList.remove('hidden');checklistVisible=!0}else{el.classList.add('hidden')}});placeholder.style.display=checklistVisible?'none':'block'}
        
        const searchInput=document.getElementById('search-input'),rowsSelect=document.getElementById('pagination-rows'),tableBody=document.getElementById('task-table-body'),paginationNav=document.getElementById('pagination-nav'),testplanFilterContainer=document.getElementById('testplan-filter-container'),statusFilter=document.getElementById('status-filter');
        let currentPage=1,activePlanFilter='All', activeStatusFilter='All';

        function renderTable() {
            const searchText = searchInput ? searchInput.value.toLowerCase() : "";
            const rowsPerPage = parseInt(rowsSelect.value);
            
            const filteredTasks = allTasksData.filter(task => {
                // Search di semua kolom yang ada di tabel
                const searchContent = (
                    (task.model_name || '') +
                    (task.pic_email || '') +
                    (task.progress_status || '') +
                    (task.ap || '') +
                    (task.cp || '') +
                    (task.csc || '') +
                    (task.project_name || '') +
                    (task.test_plan_type || '') +
                    (task.qb_user || '') +
                    (task.qb_userdebug || '') +
                    (task.request_date || '') +
                    (task.submission_date || '') +
                    (task.deadline || '') +
                    (task.approved_date || '') +
                    (task.notes || '')
                ).toLowerCase();
                const matchesSearch = searchContent.includes(searchText);
                const matchesPlan = activePlanFilter === 'All' || task.test_plan_type === activePlanFilter;
                const matchesStatus = activeStatusFilter === 'All' || task.progress_status === activeStatusFilter;
                return matchesSearch && matchesPlan && matchesStatus;
            });
            
            const totalPages = Math.ceil(filteredTasks.length / rowsPerPage);
            currentPage = Math.min(currentPage, totalPages) || 1;
            
            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const paginatedTasks = filteredTasks.slice(start, end);

            buildTableRows(paginatedTasks, start);
            renderPagination(totalPages);
        }

        function getTestPlanBadgeClassJs(plan) {
            if (!plan) return 'plan-pill plan-pill-smr';
            const planClean = plan.toUpperCase().trim();
            const map = {
                'SMR': 'plan-pill-smr',
                'FULL TEST': 'plan-pill-fulltest',
                'FULLTEST': 'plan-pill-fulltest',
                'SANITY': 'plan-pill-sanity',
                'MR': 'plan-pill-mr',
                'NORMAL MR': 'plan-pill-mr',
                'DELTA TEST': 'plan-pill-delta',
                'DELTA': 'plan-pill-delta',
                'REGRESSION': 'plan-pill-regression'
            };
            if (map[planClean]) return 'plan-pill ' + map[planClean];
            const palettes = ['plan-pill-smr', 'plan-pill-fulltest', 'plan-pill-sanity', 'plan-pill-mr', 'plan-pill-delta', 'plan-pill-regression'];
            let hash = 0;
            for (let i = 0; i < planClean.length; i++) hash = ((hash << 5) - hash) + planClean.charCodeAt(i);
            return 'plan-pill ' + palettes[Math.abs(hash) % palettes.length];
        }

        function getStatusDotColorJs(status) {
            const map = {
                'Task Baru': 'bg-blue-400 shadow-sm shadow-blue-400/50',
                'Downloaded': 'bg-cyan-400 shadow-sm shadow-cyan-400/50',
                'Test Ongoing': 'bg-amber-400 shadow-sm shadow-amber-400/50',
                'Pending Feedback': 'bg-orange-400 shadow-sm shadow-orange-400/50',
                'Feedback Sent': 'bg-orange-400 shadow-sm shadow-orange-400/50',
                'Submitted': 'bg-purple-400 shadow-sm shadow-purple-400/50',
                'Passed': 'bg-emerald-400 shadow-sm shadow-emerald-400/50',
                'Approved': 'bg-emerald-400 shadow-sm shadow-emerald-400/50',
                'Batal': 'bg-slate-400 shadow-sm shadow-slate-400/50'
            };
            return map[status] || 'bg-slate-500 shadow-sm shadow-slate-500/50';
        }

        function buildTableRows(tasks, startIndex) {
            tableBody.innerHTML = '';
            if (tasks.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="10" class="text-center py-12 text-secondary"><div class="flex flex-col items-center justify-center gap-2"><svg class="w-8 h-8 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg><span class="text-sm font-medium">Tidak ada task yang cocok dengan filter.</span></div></td></tr>';
                return;
            }
            
            tasks.forEach((task, index) => {
                const rowNumber = startIndex + index + 1;
                const urgentClass = task.is_urgent == 1 ? 'urgent-row' : '';
                
                const formatDate = (dateStr) => {
                    if (!dateStr) return '-';
                    const date = new Date(dateStr);
                    return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
                };

                const reqDate = formatDate(task.request_date);
                const subDate = formatDate(task.submission_date);
                const deadDate = formatDate(task.deadline);
                
                const isUdRequired = isUserdataRequired(task.model_name);
                const udBadge = isUdRequired 
                    ? `<div class="mt-1.5"><span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/15 text-rose-400 border border-rose-500/30" title="Download QB Build wajib menggunakan USERDATA"><svg class="w-3 h-3 text-rose-400 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd"/></svg>USERDATA Required</span></div>` 
                    : '';
                const qbUserLink = task.qb_user ? `<div>USER: <a href="https://android.qb.sec.samsung.net/build/${task.qb_user}" target="_blank" class="qb-link font-medium">${task.qb_user}</a></div>` : '';
                const qbUserdebugLink = task.qb_userdebug ? `<div class="mt-0.5">DEBUG: <a href="https://android.qb.sec.samsung.net/build/${task.qb_userdebug}" target="_blank" class="qb-link font-medium">${task.qb_userdebug}</a></div>` : '';
                
                const apVersion = (task.ap || '').trim();
                const cpVersion = (task.cp || '').trim();
                const isMismatch = apVersion && cpVersion && apVersion !== cpVersion;
                const cpMismatchClass = isMismatch ? 'text-red-400 font-bold glow-highlight-red' : 'text-secondary';

                let kinerjaHtml = '';
                if(task.progress_status === 'Batal') {
                    kinerjaHtml = `<div class="mb-1 flex items-center gap-1.5"><span class="text-secondary/70 w-16">Submission:</span><span class="font-semibold text-gray-400">Batal</span></div>`;
                    kinerjaHtml += `<div class="flex items-center gap-1.5"><span class="text-secondary/70 w-16">Approval:</span><span class="font-semibold text-gray-400">Batal</span></div>`;
                } else {
                    kinerjaHtml += `<div class="mb-1 flex items-center gap-1.5"><span class="text-secondary/70 w-16">Submission:</span>`;
                    if(task.ontime_submission_status) {
                        const colorClass = task.ontime_submission_status === 'Delay' ? 'text-red-400' : 'text-green-400';
                        kinerjaHtml += `<span class="font-semibold ${colorClass}">${task.ontime_submission_status}</span>`;
                    } else if (task.deadline_countdown !== null) {
                        const colorClass = task.deadline_countdown < 0 ? 'text-red-400' : (task.deadline_countdown <= 3 ? 'text-red-400' : 'text-secondary');
                        const iconHtml = (task.deadline_countdown <= 3 && task.deadline_countdown >= 0) ? `<svg class="w-3.5 h-3.5 animate-pulse-alert" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd"/></svg>` : '';
                        const text = task.deadline_countdown >= 0 ? `${task.deadline_countdown} hari lagi` : `Terlewat ${Math.abs(task.deadline_countdown)} hari`;
                        kinerjaHtml += `<span class="inline-flex items-center gap-1 font-medium ${colorClass}">${iconHtml}${text}</span>`;
                    } else {
                        kinerjaHtml += '-';
                    }
                    kinerjaHtml += `</div>`;
                    kinerjaHtml += `<div class="flex items-center gap-1.5"><span class="text-secondary/70 w-16">Approval:</span>`;
                     if(task.ontime_approved_status) {
                        const colorClass = task.ontime_approved_status === 'Delay' ? 'text-red-400' : 'text-green-400';
                        kinerjaHtml += `<span class="font-semibold ${colorClass}">${task.ontime_approved_status}</span>`;
                    } else if (task.approval_countdown !== null) {
                        const colorClass = task.approval_countdown < 0 ? 'text-red-400' : (task.approval_countdown <= 1 ? 'text-red-400' : 'text-secondary');
                        const iconHtml = (task.approval_countdown <= 1 && task.approval_countdown >= 0) ? `<svg class="w-3.5 h-3.5 animate-pulse-alert" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd"/></svg>` : '';
                        const text = task.approval_countdown >= 0 ? `${task.approval_countdown} hari lagi` : `Terlewat ${Math.abs(task.approval_countdown)} hari`;
                        kinerjaHtml += `<span class="inline-flex items-center gap-1 font-medium ${colorClass}">${iconHtml}${text}</span>`;
                    } else {
                        kinerjaHtml += '-';
                    }
                    kinerjaHtml += `</div>`;
                }

                let deleteButton = '';
                if(isAdmin) {
                    deleteButton = `<form action="handler.php" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus task ini?');" class="inline-block m-0"><input type="hidden" name="action" value="delete_gba_task"><input type="hidden" name="id" value="${task.id}"><button type="submit" class="card-action-btn hover:text-red-500" title="Hapus Task"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm4 0a1 1 0 012 0v6a1 1 0 11-2 0V8z" clip-rule="evenodd"></path></svg></button></form>`;
                }

                const taskJsonString = JSON.stringify(task).replace(/'/g, "\\'").replace(/"/g, '&quot;');
                const planBadgeClass = getTestPlanBadgeClassJs(task.test_plan_type);
                const dotColorClass = getStatusDotColorJs(task.progress_status);
                const marketingName = getMarketingNameJs(task.model_name, task.project_name);
                const marketingNameHtml = marketingName 
                    ? `<div class="text-[11px] text-secondary font-medium break-words leading-tight mt-0.5 mb-1" title="${marketingName}">${marketingName}</div>` 
                    : '';

                const rowHtml = `
                    <tr class="hover:bg-white/[0.03] dark:hover:bg-white/[0.04] transition-colors ${urgentClass}" data-plan="${task.test_plan_type || ''}" data-status="${task.progress_status || ''}">
                        <td class="py-3 px-3 text-center text-secondary font-mono text-xs">${rowNumber}</td>
                        <td class="py-3 px-3 min-w-[200px]">
                            <div class="font-semibold text-primary leading-tight"><span class="copy-text cursor-pointer hover:text-blue-400 transition" title="Klik kanan untuk copy">${task.model_name || '-'}</span></div>
                            ${marketingNameHtml}
                            <div class="build-specs-box">
                                <div><span class="text-secondary/70">AP:</span> <span class="copy-text cursor-pointer text-primary font-medium" title="Klik kanan untuk copy">${task.ap || '-'}</span></div> 
                                <div class="${cpMismatchClass}"><span class="text-secondary/70">CP:</span> <span class="copy-text cursor-pointer font-medium" title="Klik kanan untuk copy">${task.cp || '-'}</span></div> 
                                <div><span class="text-secondary/70">CSC:</span> <span class="copy-text cursor-pointer text-primary font-medium" title="Klik kanan untuk copy">${task.csc || '-'}</span></div>
                            </div>
                        </td>
                        <td class="py-3 px-3 text-xs text-secondary font-mono">${qbUserLink}${qbUserdebugLink}${udBadge}</td>
                        <td class="py-3 px-3"><span class="badge ${task.pic_color_class} font-medium">${task.pic_email || 'N/A'}</span></td>
                        <td class="py-3 px-3"><span class="${planBadgeClass}">${task.test_plan_type || 'N/A'}</span></td>
                        <td class="py-3 px-3">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${task.status_color_class}">
                                <span class="w-1.5 h-1.5 rounded-full ${dotColorClass}"></span>
                                <span>${task.progress_status || 'N/A'}</span>
                            </span>
                        </td>
                        <td class="py-3 px-3">
                            <div class="w-24"><div class="progress-bar-bg w-full rounded-full h-3.5 relative flex items-center overflow-hidden"><div class="progress-bar-fill h-3.5 rounded-full absolute top-0 left-0" style="width: ${task.progress_percentage || 0}%;"></div><span class="relative text-[10px] font-bold z-10 progress-text pl-1.5">${Math.round(task.progress_percentage || 0)}%</span></div></div>
                        </td>
                        <td class="py-3 px-3 text-xs text-secondary whitespace-nowrap">
                            <div><span class="text-secondary/70">Req:</span> ${reqDate}</div>
                            <div><span class="text-secondary/70">Sub:</span> ${subDate}</div>
                            <div class="font-bold text-primary"><span class="text-secondary/70">Deadline:</span> ${deadDate}</div>
                        </td>
                        <td class="py-3 px-3 text-xs whitespace-nowrap">${kinerjaHtml}</td>
                        <td class="py-3 px-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <button onclick='openEditModal(${taskJsonString})' class="card-action-btn hover:text-blue-500" title="Edit Task"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z"></path><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd"></path></svg></button>
                                ${deleteButton}
                            </div>
                        </td>
                    </tr>
                `;
                tableBody.insertAdjacentHTML('beforeend', rowHtml);
            });
        }

        function renderPagination(totalPages) {
            paginationNav.innerHTML = '';
            if (totalPages <= 1) return;
            const maxButtons = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxButtons / 2));
            let endPage = Math.min(totalPages, startPage + maxButtons - 1);
            if (endPage - startPage + 1 < maxButtons) {
                startPage = Math.max(1, endPage - maxButtons + 1);
            }
            if (startPage > 1) {
                paginationNav.appendChild(createPageButton(1, '«'));
                paginationNav.appendChild(createPageButton(currentPage - 1, '‹'));
            }
            for (let i = startPage; i <= endPage; i++) {
                paginationNav.appendChild(createPageButton(i, i));
            }
            if (endPage < totalPages) {
                paginationNav.appendChild(createPageButton(currentPage + 1, '›'));
                paginationNav.appendChild(createPageButton(totalPages, '»'));
            }
        }

        function createPageButton(page, text) {
            const pageButton = document.createElement('button');
            pageButton.textContent = text;
            pageButton.className = `px-3 py-1.5 rounded-xl text-xs font-semibold transition-all ${page === currentPage ? 'bg-blue-600 text-white shadow-sm shadow-blue-600/30' : 'bg-[var(--glass-bg)] border border-[var(--glass-border)] text-secondary hover:text-primary hover:bg-white/10'}`;
            pageButton.onclick = () => {
                currentPage = page;
                renderTable();
            };
            return pageButton;
        }
        
        function setDefaultDates() {
            const requestDateInput = document.getElementById('request_date');
            const deadlineInput = document.getElementById('deadline');
            const signOffDateInput = document.getElementById('sign_off_date');
            
            const today = new Date();
            const todayString = today.toISOString().slice(0, 10);
            
            // Assuming calculateWorkingDays logic is available or copied here if needed
            // For simplicity, using dummy logic if utility function is unavailable
            
            const futureDate = calculateWorkingDays(todayString, 7); 
            
            if (!requestDateInput.value) {
                requestDateInput.value = todayString;
            }
            if (!deadlineInput.value) {
                deadlineInput.value = futureDate;
            }
            if (!signOffDateInput.value) {
                signOffDateInput.value = futureDate;
            }
        }
        
        // Dummy implementation of missing utility function (based on previous context)
        function calculateWorkingDays(startDate, daysToAdd) {
            let currentDate = new Date(startDate);
            let addedDays = 0;
            while (addedDays < daysToAdd) {
                currentDate.setDate(currentDate.getDate() + 1);
                if (currentDate.getDay() !== 0 && currentDate.getDay() !== 6) {
                    addedDays++;
                }
            }
            return currentDate.toISOString().slice(0, 10);
        }

        const progressStatusSelect = document.getElementById('progress_status'), submissionDateInput = document.getElementById('submission_date'), approvedDateInput = document.getElementById('approved_date');
        
        function checkAllVisibleCheckboxes(checked = true) {
            const visibleChecklist = document.querySelector('[id^="checklist-container-"]:not(.hidden)');
            if (visibleChecklist) {
                visibleChecklist.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                    cb.checked = checked;
                });
            }
        }

        progressStatusSelect.addEventListener('change', e => {
            const status = e.target.value;
            if (status === 'Submitted' || status === 'Approved' || status === 'Passed') {
                if (!submissionDateInput.value) {
                    submissionDateInput.value = new Date().toISOString().slice(0, 10);
                }
                if (status === 'Approved' || status === 'Passed') {
                    if (!approvedDateInput.value) {
                        approvedDateInput.value = new Date().toISOString().slice(0, 10);
                    }
                }
                checkAllVisibleCheckboxes(true);
            } else if (status === 'Task Baru') {
                checkAllVisibleCheckboxes(false);
                submissionDateInput.value = '';
                approvedDateInput.value = '';
            }
        });
        taskForm.addEventListener('change', e => {
            if (e.target.matches('input[type="checkbox"][name^="checklist"]')) {
                const currentStatus = progressStatusSelect.value;
                if (currentStatus !== 'Approved' && currentStatus !== 'Submitted') {
                    progressStatusSelect.value = 'Test Ongoing';
                }
            }
        });

        // Jalankan semua fungsi setelah DOM siap
        document.addEventListener('DOMContentLoaded', () => {
            renderTable();
            setupQuill('');
            updateChecklistVisibility();
            
            const profileMenu = document.getElementById('profile-menu');
            if (profileMenu) {
                const profileButton = profileMenu.querySelector('button');
                const profileDropdown = document.getElementById('profile-dropdown');
                profileButton.addEventListener('click', e => { e.stopPropagation(); profileDropdown.classList.toggle('hidden'); });
                document.addEventListener('click', e => { if (!profileMenu.contains(e.target)) { profileDropdown.classList.add('hidden'); } });
            }

            if (searchInput) { searchInput.addEventListener('input', renderTable); }
            rowsSelect.addEventListener('change', () => { currentPage = 1; renderTable(); });
            testplanFilterContainer.addEventListener('click', e => {
                if (e.target.tagName === 'BUTTON') {
                    testplanFilterContainer.querySelector('.active').classList.remove('active');
                    e.target.classList.add('active');
                    activePlanFilter = e.target.dataset.plan;
                    currentPage = 1;
                    renderTable();
                }
            });
            statusFilter.addEventListener('change', () => {
                activeStatusFilter = statusFilter.value;
                currentPage = 1;
                renderTable();
            });
        });

        // ============ REDISTRIBUTE REQUEST DATE LOGIC ============
        function openRedistributeModal() {
            const redistModal = document.getElementById('redistribute-modal');
            if (redistModal) redistModal.classList.remove('modal-closing', 'hidden');
            document.getElementById('redistribute-loading').classList.remove('hidden');
            document.getElementById('redistribute-content').classList.add('hidden');
            document.getElementById('redistribute-error').classList.add('hidden');
            document.getElementById('redistribute-confirm-btn').classList.add('hidden');

            fetch('handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'preview_redistribute_dates' })
            })
            .then(r => r.json())
            .then(data => {
                document.getElementById('redistribute-loading').classList.add('hidden');
                if (!data.success) {
                    const errEl = document.getElementById('redistribute-error');
                    errEl.textContent = 'Error: ' + (data.error || 'Terjadi kesalahan.');
                    errEl.classList.remove('hidden');
                    return;
                }
                renderRedistributePreview(data.preview);
            })
            .catch(err => {
                document.getElementById('redistribute-loading').classList.add('hidden');
                const errEl = document.getElementById('redistribute-error');
                errEl.textContent = 'Gagal menghubungi server: ' + err.message;
                errEl.classList.remove('hidden');
            });
        }

        let isRedistModalClosing = false;
        function closeRedistributeModal() {
            const redistModal = document.getElementById('redistribute-modal');
            if (!redistModal || redistModal.classList.contains('hidden') || isRedistModalClosing) return;
            isRedistModalClosing = true;
            redistModal.classList.add('modal-closing');
            setTimeout(() => {
                redistModal.classList.add('hidden');
                redistModal.classList.remove('modal-closing');
                isRedistModalClosing = false;
            }, 160);
        }

        function renderRedistributePreview(preview) {
            const tbody = document.getElementById('redistribute-table-body');
            tbody.innerHTML = '';

            if (!preview || preview.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center p-6 text-secondary">Tidak ada task yang perlu didistribusikan ulang.</td></tr>';
                document.getElementById('redistribute-content').classList.remove('hidden');
                document.getElementById('redistribute-summary').innerHTML = '<span class="text-secondary">Semua task sudah terdistribusi dengan baik.</span>';
                return;
            }

            const fmt = (d) => {
                if (!d) return '-';
                return new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
            };

            let changedCount = 0;
            preview.forEach(row => {
                const changed = row.old_date !== row.new_date;
                if (changed) changedCount++;
                const rowClass = changed ? 'bg-violet-500/10' : '';
                const oldDateHtml = changed
                    ? `<span class="line-through text-secondary">${fmt(row.old_date)}</span>`
                    : `<span class="text-secondary">${fmt(row.old_date)}</span>`;
                const newDateHtml = changed
                    ? `<span class="text-violet-300 font-semibold">${fmt(row.new_date)}</span>`
                    : `<span class="text-secondary">${fmt(row.new_date)}</span>`;

                tbody.insertAdjacentHTML('beforeend', `
                    <tr class="border-b border-[var(--glass-border)] ${rowClass}">
                        <td class="p-3 text-xs">${row.pic_email}</td>
                        <td class="p-3 font-medium">${row.model_name}</td>
                        <td class="p-3 text-xs">${oldDateHtml}</td>
                        <td class="p-3 text-xs">${newDateHtml}</td>
                        <td class="p-3 text-xs text-secondary">${fmt(row.deadline)}</td>
                    </tr>
                `);
            });

            document.getElementById('redistribute-summary').innerHTML =
                `<span class="text-primary">Total <strong>${preview.length}</strong> task akan diproses — <strong class="text-violet-400">${changedCount}</strong> tanggal akan berubah.</span>`;

            document.getElementById('redistribute-content').classList.remove('hidden');
            if (changedCount > 0) {
                document.getElementById('redistribute-confirm-btn').classList.remove('hidden');
            }
        }

        function executeRedistribute() {
            const btn = document.getElementById('redistribute-confirm-btn');
            btn.disabled = true;
            btn.textContent = 'Memproses...';

            fetch('handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'redistribute_request_dates' })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    closeRedistributeModal();
                    // Reload page to refresh data
                    window.location.reload();
                } else {
                    btn.disabled = false;
                    btn.textContent = '✅ Terapkan Perubahan';
                    alert('Gagal: ' + (data.error || 'Terjadi kesalahan.'));
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.textContent = '✅ Terapkan Perubahan';
                alert('Gagal menghubungi server: ' + err.message);
            });
        }
        // ============ END REDISTRIBUTE LOGIC ============

        // --- Copy on Right Click QB Link & Model/Build ---
        document.addEventListener('contextmenu', function(e) {
            const isCopyText = e.target.closest('.copy-text');
            if (e.target.classList.contains('qb-link') || isCopyText) {
                e.preventDefault();
                const textToCopy = (isCopyText ? isCopyText.textContent : e.target.textContent).trim();
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(textToCopy).then(() => {
                        showCopyTooltip(e.clientX, e.clientY);
                    }).catch(err => console.error('Gagal menyalin:', err));
                } else {
                    // Fallback using textarea
                    const textarea = document.createElement('textarea');
                    textarea.value = textToCopy;
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.select();
                    try {
                        document.execCommand('copy');
                        showCopyTooltip(e.clientX, e.clientY);
                    } catch (err) {
                        console.error('Gagal menyalin via execCommand:', err);
                    }
                    document.body.removeChild(textarea);
                }
            }
        });

        function showCopyTooltip(x, y, text = 'Copied!') {
            const existing = document.querySelector('.copy-tooltip');
            if (existing) existing.remove();

            const tooltip = document.createElement('div');
            tooltip.className = 'copy-tooltip';
            tooltip.innerHTML = `
                <svg class="w-3.5 h-3.5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                </svg>
                <span>${text}</span>
            `;
            tooltip.style.left = x + 'px';
            tooltip.style.top = y + 'px';
            document.body.appendChild(tooltip);
            
            requestAnimationFrame(() => {
                tooltip.classList.add('show');
            });
            
            setTimeout(() => {
                tooltip.classList.remove('show');
                setTimeout(() => tooltip.remove(), 200);
            }, 1100);
        }

        function copyNewTasksQbIds(event, button) {
            const textToCopy = button.getAttribute('data-qb-ids');
            if (!textToCopy) {
                alert('Tidak ada QB Build ID untuk status "Task Baru" saat ini.');
                return;
            }
            
            const copyAction = () => {
                const rect = button.getBoundingClientRect();
                const x = rect.left + rect.width / 2;
                const y = rect.top;
                showCopyTooltip(x, y);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(textToCopy).then(copyAction).catch(err => {
                    console.error('Gagal menyalin:', err);
                    fallbackCopy(textToCopy, copyAction);
                });
            } else {
                fallbackCopy(textToCopy, copyAction);
            }
        }

        function copyActiveTasksBuilds(event, button) {
            const textToCopy = button.getAttribute('data-build-info');
            if (!textToCopy) {
                alert('Tidak ada data task aktif yang sesuai dengan status.');
                return;
            }
            
            const copyAction = () => {
                const rect = button.getBoundingClientRect();
                const x = rect.left + rect.width / 2;
                const y = rect.top;
                showCopyTooltip(x, y);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(textToCopy).then(copyAction).catch(err => {
                    console.error('Gagal menyalin:', err);
                    fallbackCopy(textToCopy, copyAction);
                });
            } else {
                fallbackCopy(textToCopy, copyAction);
            }
        }

        function fallbackCopy(text, callback) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                callback();
            } catch (err) {
                console.error('Gagal menyalin via execCommand:', err);
                alert('Gagal menyalin ke clipboard.');
            }
            document.body.removeChild(textarea);
        }
    </script>
</body>
</html>