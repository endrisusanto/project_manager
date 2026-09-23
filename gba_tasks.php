<?php
// 1. INISIALISASI
require_once "config.php";
require_once "session.php"; // Memastikan pengguna sudah login
require_once "marketing_name_mapper.php";
$active_page = 'gba_tasks';

// 2. LOGIKA PENGAMBILAN & PEMROSESAN DATA
$all_tasks_sql = "
    SELECT t.model_name, t.ap, u.username 
    FROM gba_tasks t
    LEFT JOIN users u ON t.pic_email = u.email
    WHERE t.progress_status NOT IN ('Approved', 'Batal') AND t.model_name IS NOT NULL AND t.ap IS NOT NULL AND t.ap != ''
";
$all_tasks_result = $conn->query($all_tasks_sql);

$model_data = [];
if ($all_tasks_result) {
    while ($row = $all_tasks_result->fetch_assoc()) {
        $model_name_full = $row['model_name'];
        $model_data[$model_name_full][] = [
            'ap' => $row['ap'],
            'user' => $row['username'] ?? strtok($row['pic_email'], '@')
        ];
    }
}

$duplicate_ap_tasks = [];
foreach ($model_data as $model => $details) {
    $ap_groups = [];
    foreach ($details as $detail) {
        $ap_groups[$detail['ap']][] = $detail['user'];
    }

    if (count($ap_groups) > 1) {
        $duplicate_ap_tasks[$model] = $details;
        continue;
    }

    foreach ($ap_groups as $ap => $users) {
        if (count(array_unique($users)) > 1) {
            $duplicate_ap_tasks[$model] = $details;
            break;
        }
    }
}

// Query untuk menampilkan data di tabel (berlaku sesuai hak akses)
$sql = "SELECT * FROM gba_tasks";
$params = [];
$types = "";

$where_clauses = ["progress_status NOT IN ('Approved', 'Batal')"];
if (!is_admin()) {
    $where_clauses[] = "pic_email = ?";
    $params[] = $_SESSION['user_details']['email'];
    $types .= "s";
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$sql .= " ORDER BY id DESC";
$stmt = $conn->prepare($sql);

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $tasks_result = $stmt->get_result();
} else {
    $tasks_result = false;
}

$tasks = [];
$test_plan_items = [
    'Regular Variant' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
    'SKU' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
    'Normal MR' => ['CTS', 'GTS', 'CTS-Verifier', 'ATM'],
    'SMR' => ['CTS', 'GTS', 'STS', 'SCAT'],
    'Simple Exception MR' => ['STS']
];
$all_test_plans = array_keys($test_plan_items);
$all_statuses = ['Task Baru', 'Downloaded', 'Test Ongoing', 'Pending Feedback', 'Feedback Sent', 'Submitted', 'Passed', 'Approved', 'Batal'];


if ($tasks_result) {
    while ($row = $tasks_result->fetch_assoc()) {
        $row['request_date_obj'] = $row['request_date'] ? new DateTime($row['request_date']) : null;
        $row['submission_date_obj'] = $row['submission_date'] ? new DateTime($row['submission_date']) : null;
        $row['approved_date_obj'] = isset($row['approved_date']) && $row['approved_date'] ? new DateTime($row['approved_date']) : null;
        $deadline_date = $row['deadline'] ? new DateTime($row['deadline']) : null;

        if ($row['submission_date_obj'] && $row['request_date_obj']) {
            $submission_diff = $row['submission_date_obj']->diff($row['request_date_obj'])->days;
            $row['ontime_submission_status'] = $submission_diff <= 7 ? 'Ontime' : 'Delay';
        } else {
            $row['ontime_submission_status'] = null;
        }

        if ($row['approved_date_obj'] && $row['submission_date_obj']) {
            $approval_diff = $row['approved_date_obj']->diff($row['submission_date_obj'])->days;
            $row['ontime_approved_status'] = $approval_diff <= 3 ? 'Ontime' : 'Delay';
        } else {
            $row['ontime_approved_status'] = null;
        }

        $row['deadline_countdown'] = null;
        if (!$row['submission_date_obj'] && $deadline_date) {
            $now = new DateTime();
            $now->setTime(0, 0, 0);
            $deadline_date->setTime(0, 0, 0);
            $diff = $now->diff($deadline_date);
            $row['deadline_countdown'] = ($now <= $deadline_date) ? $diff->days : -$diff->days;
        }

        $row['approval_countdown'] = null;
        if ($row['submission_date_obj'] && !$row['approved_date_obj']) {
            $approval_deadline = (clone $row['submission_date_obj'])->modify('+3 days');
            $now = new DateTime();
            $now->setTime(0, 0, 0);
            $approval_deadline->setTime(0, 0, 0);
            $diff = $now->diff($approval_deadline);
            $row['approval_countdown'] = ($now <= $approval_deadline) ? $diff->days : -$diff->days;
        }

        $checklist = json_decode((string)($row['test_items_checklist'] ?? ''), true);
        $plan_type = $row['test_plan_type'];
        $total_items = isset($test_plan_items[$plan_type]) ? count($test_plan_items[$plan_type]) : 0;
        $completed_items = 0;
        if ($total_items > 0 && is_array($checklist)) {
            foreach ($test_plan_items[$plan_type] as $item) {
                $item_key = str_replace([' ', '-'], '_', $item);
                if (!empty($checklist[$item_key])) {
                    $completed_items++;
                }
            }
        }
        $row['progress_percentage'] = $total_items > 0 ? ($completed_items / $total_items) * 100 : 0;
        $tasks[] = $row;
    }
}

// 3. FUNGSI HELPER TAMPILAN
function getDynamicColorClasses($identifier, $type = 'pic')
{
    $pic_colors = ['sky', 'emerald', 'amber', 'rose', 'violet', 'teal', 'cyan'];
    $plan_colors = ['indigo', 'lime', 'pink', 'orange', 'fuchsia'];
    $palette = ($type === 'plan') ? $plan_colors : $pic_colors;
    $hash = crc32($identifier);
    return "badge-color-" . $palette[abs($hash) % count($palette)];
}
function getStatusColorClasses($status)
{
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
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GBA Task Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['class', '.never-match-dark']
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        :root {
            --bg-primary: #020617;
            --text-primary: #e2e8f0;
            --text-secondary: #94a3b8;
            --glass-bg: rgba(15, 23, 42, .4);
            --glass-border: rgba(51, 65, 85, .4);
            --modal-bg: rgba(15, 23, 42, 0.95);
            --modal-border: rgba(51, 65, 85, 0.6);
            --input-bg: rgba(30, 41, 59, .7);
            --input-border: #475569;
            --progress-bg: #1e293b;
            --progress-fill: #3b82f6;
            --toast-bg: #22c55e;
            --toast-text: #fff;
            --filter-btn-bg: rgba(255, 255, 255, .05);
            --filter-btn-bg-active: #2563eb;
            --text-header: #fff;
            --text-icon: #94a3b8
        }

        html.light {
            --bg-primary: #f1f5f9;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --glass-bg: rgba(255, 255, 255, .7);
            --glass-border: rgba(0, 0, 0, .08);
            --modal-bg: #ffffff;
            --modal-border: rgba(0, 0, 0, 0.12);
            --input-bg: #fff;
            --input-border: #cbd5e1;
            --progress-bg: #e2e8f0;
            --toast-bg: #16a34a;
            --filter-btn-bg: rgba(0, 0, 0, .05);
            --text-header: #0f172a;
            --text-icon: #475569
        }

        html {
            scroll-behavior: smooth
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary)
        }

        html,
        body {
            height: 100%;
            overflow: hidden
        }

        main {
            height: calc(100% - 64px)
        }

        .table-container {
            scroll-behavior: smooth
        }

        #neural-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1
        }

        .glass-container {
            background: var(--glass-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--glass-border)
        }

        .glassmorphism-table {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border)
        }

        .modal-content-wrapper,
        .glassmorphism-modal {
            background: var(--modal-bg);
            border: 1px solid var(--modal-border);
            color: var(--text-primary);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        #confirm-modal-box {
            background: var(--modal-bg);
            border: 1px solid var(--modal-border);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        html.light #confirm-modal-box {
            background: #ffffff !important;
            border-color: rgba(0, 0, 0, 0.12) !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
        }
        html.light #confirm-modal-title {
            color: #0f172a !important;
        }
        html.light #confirm-modal-desc {
            color: #475569 !important;
        }
        html.light #confirm-modal-count {
            color: #0f172a !important;
        }
        html.light #confirm-modal-btn-cancel {
            color: #475569 !important;
        }
        html.light #confirm-modal-btn-cancel:hover {
            background: rgba(0, 0, 0, 0.06) !important;
            color: #0f172a !important;
        }

        .nav-link {
            color: var(--text-secondary);
            transition: color .2s, border-color .2s;
            border-bottom: 2px solid transparent
        }

        .nav-link:hover {
            color: var(--text-primary)
        }

        .nav-link-active {
            color: var(--text-primary) !important;
            font-weight: 500;
            border-bottom: 2px solid #3b82f6
        }

        .themed-input {
            background-color: var(--input-bg);
            border: 1px solid var(--input-border)
        }

        html.light .themed-input,
        html.light .ql-editor {
            color: var(--text-primary)
        }

        .themed-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, .5)
        }

        input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(var(--date-picker-invert, 1))
        }

        html.light {
            --date-picker-invert: 0
        }

        .ql-toolbar,
        .ql-container {
            border-color: var(--glass-border) !important
        }

        .ql-editor {
            color: var(--text-primary);
            min-height: 100px
        }

        .ql-snow .ql-stroke {
            stroke: var(--text-icon)
        }

        .ql-snow .ql-picker-label {
            color: var(--text-icon)
        }

        .progress-bar-bg {
            background-color: var(--progress-bg)
        }

        .progress-bar-fill {
            background-color: var(--progress-fill);
            transition: width .6s ease-in-out;
            background-image: linear-gradient(45deg, rgba(255, 255, 255, .15) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, .15) 50%, rgba(255, 255, 255, .15) 75%, transparent 75%, transparent);
            background-size: 1rem 1rem;
            animation: progress-bar-stripes 1s linear infinite
        }

        @keyframes progress-bar-stripes {
            from {
                background-position: 1rem 0
            }

            to {
                background-position: 0 0
            }
        }

        .progress-text {
            font-size: 10px;
            font-weight: 700;
            padding: 0 6px;
            border-radius: 4px;
            color: #fff;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.75);
        }

        #toast {
            position: fixed;
            bottom: -100px;
            left: 50%;
            transform: translateX(-50%);
            background-color: var(--toast-bg);
            color: var(--toast-text);
            padding: 12px 20px;
            border-radius: 8px;
            z-index: 1000;
            transition: bottom .5s ease-in-out
        }

        #toast.show {
            bottom: 30px
        }

        .filter-button {
            background-color: var(--filter-btn-bg, rgba(255, 255, 255, 0.05));
            color: var(--text-secondary);
            border: 1px solid var(--glass-border);
            border-radius: 9999px;
            font-weight: 600;
            font-size: 12px;
            padding: 6px 14px;
            transition: all .15s cubic-bezier(0.16, 1, 0.3, 1);
            white-space: nowrap;
        }

        .filter-button:hover {
            background-color: rgba(255, 255, 255, .1);
            color: var(--text-primary);
        }

        html.light .filter-button {
            background-color: #ffffff;
            border-color: #e2e8f0;
            color: #64748b;
        }

        html.light .filter-button:hover {
            background-color: #f8fafc;
            color: #0f172a;
            border-color: #cbd5e1;
        }

        .filter-button.active {
            background: linear-gradient(135deg, #2563eb, #3b82f6) !important;
            color: #ffffff !important;
            border-color: #3b82f6 !important;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.35);
        }

        /* Pill Shape Badges for Test Plans with distinctive semantic palettes */
        .plan-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            font-weight: 700;
            font-size: 11px;
            padding: 2px 8px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border: 1px solid transparent;
            line-height: 1.2;
            transition: all 0.15s ease;
        }
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

        .card-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 8px;
            color: var(--text-icon);
            transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .card-action-btn:hover {
            background: rgba(255, 255, 255, 0.08);
        }
        html.light .card-action-btn:hover {
            background: rgba(0, 0, 0, 0.06);
        }
        .card-action-btn:active {
            transform: scale(0.92);
        }

        .build-specs-box {
            display: flex;
            flex-direction: column;
            gap: 2.5px;
            padding: 5px 8px;
            margin-top: 3px;
            border-radius: 8px;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 11px;
            line-height: 1.35;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        html.light .build-specs-box {
            background: rgba(0, 0, 0, 0.035);
            border-color: rgba(0, 0, 0, 0.06);
        }

        tbody tr {
            transition: background-color 0.15s ease;
        }

        tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.04);
        }

        html.light tbody tr:hover {
            background-color: rgba(15, 23, 42, 0.03) !important;
        }

        .themed-input option {
            background-color: var(--bg-primary);
            color: var(--text-primary);
        }

        .badge {
            display: inline-block;
            padding: .25rem .6rem;
            font-size: .75rem;
            font-weight: 500;
            border-radius: .75rem;
            line-height: 1.2
        }

        .badge-color-sky {
            background-color: rgba(14, 165, 233, .2);
            color: #7dd3fc
        }

        .badge-color-emerald {
            background-color: rgba(16, 185, 129, .2);
            color: #6ee7b7
        }

        .badge-color-amber {
            background-color: rgba(245, 158, 11, .2);
            color: #fcd34d
        }

        .badge-color-rose {
            background-color: rgba(244, 63, 94, .2);
            color: #fda4af
        }

        .badge-color-violet {
            background-color: rgba(139, 92, 246, .2);
            color: #c4b5fd
        }

        .badge-color-teal {
            background-color: rgba(20, 184, 166, .2);
            color: #5eead4
        }

        .badge-color-cyan {
            background-color: rgba(6, 182, 212, .2);
            color: #67e8f9
        }

        .badge-color-indigo {
            background-color: rgba(99, 102, 241, .2);
            color: #a5b4fc
        }

        .badge-color-lime {
            background-color: rgba(132, 204, 22, .2);
            color: #bef264
        }

        .badge-color-pink {
            background-color: rgba(236, 72, 153, .2);
            color: #f9a8d4
        }

        .badge-color-fuchsia {
            background-color: rgba(217, 70, 239, .2);
            color: #f0abfc
        }

        .badge-color-green {
            background-color: rgba(34, 197, 94, .2);
            color: #86efac
        }

        .badge-color-purple {
            background-color: rgba(168, 85, 247, .2);
            color: #d8b4fe
        }

        .badge-color-yellow {
            background-color: rgba(234, 179, 8, .2);
            color: #fde047
        }

        .badge-color-blue {
            background-color: rgba(59, 130, 246, .2);
            color: #93c5fd
        }

        .badge-color-gray {
            background-color: rgba(107, 114, 128, .2);
            color: #d1d5db
        }

        .badge-color-orange {
            background-color: rgba(249, 115, 22, .2);
            color: #fdba74
        }

        html.light .badge-color-sky {
            background-color: #e0f2fe;
            color: #0369a1
        }

        html.light .badge-color-emerald {
            background-color: #d1fae5;
            color: #047857
        }

        html.light .badge-color-amber {
            background-color: #fef3c7;
            color: #92400e
        }

        html.light .badge-color-rose {
            background-color: #ffe4e6;
            color: #9f1239
        }

        html.light .badge-color-violet {
            background-color: #ede9fe;
            color: #5b21b6
        }

        html.light .badge-color-teal {
            background-color: #ccfbf1;
            color: #0d9488
        }

        html.light .badge-color-cyan {
            background-color: #cffafe;
            color: #0e7490
        }

        html.light .badge-color-indigo {
            background-color: #e0e7ff;
            color: #3730a3
        }

        html.light .badge-color-lime {
            background-color: #ecfccb;
            color: #4d7c0f
        }

        html.light .badge-color-pink {
            background-color: #fce7f3;
            color: #9d174d
        }

        html.light .badge-color-fuchsia {
            background-color: #fae8ff;
            color: #86198f
        }

        html.light .badge-color-green {
            background-color: #dcfce7;
            color: #15803d
        }

        html.light .badge-color-purple {
            background-color: #f3e8ff;
            color: #6b21a8
        }

        html.light .badge-color-yellow {
            background-color: #fef9c3;
            color: #854d0e
        }

        html.light .badge-color-blue {
            background-color: #dbeafe;
            color: #1e40af
        }

        html.light .badge-color-gray {
            background-color: #f3f4f6;
            color: #374151
        }

        html.light .badge-color-orange {
            background-color: #ffedd5;
            color: #9a3412
        }

        html.light .font-semibold.text-green-400 {
            color: #15803d
        }

        html.light .font-semibold.text-red-400 {
            color: #b91c1c
        }

        @keyframes pulse-alert {

            0%,
            100% {
                transform: scale(1);
                opacity: 1
            }

            50% {
                transform: scale(1.2);
                opacity: .7
            }
        }

        .animate-pulse-alert {
            animation: pulse-alert 1.5s infinite;
            color: #f87171
        }

        html.light .animate-pulse-alert {
            color: #dc2626
        }

        .qb-link {
            cursor: pointer;
            text-decoration: underline;
            color: #93c5fd;
            transition: color 0.15s ease;
        }

        .qb-link:hover {
            color: #60a5fa;
        }

        html.light .qb-link {
            color: #2563eb;
        }

        html.light .qb-link:hover {
            color: #1d4ed8;
        }

        .urgent-row {
            position: relative;
            border-left: 3px solid transparent;
            animation: urgent-row-glow 1.5s infinite;
        }

        @keyframes urgent-row-glow {

            0%,
            100% {
                border-left-color: rgba(239, 68, 68, 0.7);
                box-shadow: inset 3px 0 8px -2px rgba(239, 68, 68, 0.5);
            }

            50% {
                border-left-color: rgba(239, 68, 68, 0.4);
                box-shadow: inset 3px 0 15px -2px rgba(239, 68, 68, 0.3);
            }
        }

        .table-container td {
            vertical-align: middle;
        }

        #pagination-rows {
            color: var(--text-primary);
        }

        #pagination-rows option {
            background-color: var(--bg-primary);
            color: var(--text-primary);
        }

        #alert-carousel {
            position: relative;
            overflow: hidden;
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.35);
            border-radius: 1rem;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        html.light #alert-carousel {
            background: #fef3c7;
            border-color: #f59e0b;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
        }

        .alert-duplicate-title {
            color: #fcd34d;
            font-weight: 600;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }

        html.light .alert-duplicate-title {
            color: #78350f;
            font-weight: 700;
        }

        .alert-duplicate-model {
            color: #ffffff;
            font-weight: 700;
            text-decoration: underline;
            text-decoration-color: #f59e0b;
        }

        html.light .alert-duplicate-model {
            color: #451a03;
            text-decoration-color: #b45309;
        }

        .alert-duplicate-icon {
            color: #f59e0b;
            width: 1rem;
            height: 1rem;
            flex-shrink: 0;
        }

        html.light .alert-duplicate-icon {
            color: #b45309;
        }

        .alert-duplicate-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.2rem 0.625rem;
            border-radius: 0.5rem;
            background: rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(245, 158, 11, 0.25);
            font-size: 0.75rem;
        }

        html.light .alert-duplicate-chip {
            background: #ffffff;
            border-color: rgba(217, 119, 6, 0.35);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .alert-chip-ap {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-weight: 700;
            color: #fde68a;
        }

        html.light .alert-chip-ap {
            color: #78350f;
        }

        .alert-chip-pic {
            font-size: 0.6875rem;
            color: #cbd5e1;
            font-weight: 500;
        }

        html.light .alert-chip-pic {
            color: #92400e;
            font-weight: 600;
        }

        .alert-carousel-inner {
            display: flex;
            transition: transform 0.5s ease-in-out;
        }

        .alert-carousel-item {
            min-width: 100%;
            box-sizing: border-box;
        }

        .alert-content {
            padding-left: 3.5rem;
            padding-right: 3.5rem;
        }

        .carousel-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background-color: rgba(0, 0, 0, 0.3);
            border-radius: 9999px;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            z-index: 10;
            backdrop-filter: blur(4px);
            transition: background-color 0.15s ease;
        }

        .carousel-btn:hover {
            background-color: rgba(0, 0, 0, 0.6);
        }

        html.light .carousel-btn {
            background-color: rgba(255, 255, 255, 0.85);
            color: #0f172a;
            border: 1px solid rgba(0, 0, 0, 0.12);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
        }

        html.light .carousel-btn:hover {
            background-color: #ffffff;
        }

        .carousel-btn.prev {
            left: 0.75rem;
        }

        .carousel-btn.next {
            right: 0.75rem;
        }

        #task-modal .ql-editor {
            min-height: 42px;
            padding-top: 10px;
            padding-bottom: 10px;
        }

        #task-modal .ql-container {
            border-top: 1px solid var(--glass-border) !important;
            border-radius: .5rem;
        }

        /* --- CP MISMATCH GLOW --- */
        .glow-highlight-red {
            animation: red-glow-text 1.5s infinite alternate;
            color: #f87171 !important;
        }

        @keyframes red-glow-text {
            from {
                text-shadow: 0 0 2px #f87171, 0 0 4px rgba(255, 0, 0, 0.4);
            }

            to {
                text-shadow: 0 0 6px #fee2e2, 0 0 8px rgba(255, 0, 0, 0.7);
            }
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

        /* --- BULK ACTION BAR & SELECTION (better-ui / emil-design-eng) --- */
        tr.row-selected {
            background-color: rgba(244, 63, 94, 0.08) !important;
        }

        html.light tr.row-selected {
            background-color: rgba(244, 63, 94, 0.07) !important;
        }

        #bulk-action-bar {
            position: fixed;
            bottom: 1.5rem;
            left: 50%;
            transform: translateX(-50%) translateY(20px) scale(0.95);
            z-index: 9990;
            display: flex;
            align-items: center;
            gap: 0.875rem;
            padding: 0.625rem 1rem;
            border-radius: 1rem;
            background: rgba(15, 23, 42, 0.95);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 20px 35px -10px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.22s cubic-bezier(0.16, 1, 0.3, 1), transform 0.22s cubic-bezier(0.16, 1, 0.3, 1);
        }

        #bulk-action-bar.active {
            opacity: 1;
            pointer-events: auto;
            transform: translateX(-50%) translateY(0) scale(1);
        }

        html.light #bulk-action-bar {
            background: rgba(15, 23, 42, 0.94);
            border-color: rgba(0, 0, 0, 0.2);
            box-shadow: 0 20px 35px -10px rgba(0, 0, 0, 0.25);
        }
        /* --- END BULK ACTION BAR CSS --- */
    </style>
</head>

<body class="min-h-screen">
    <canvas id="neural-canvas"></canvas>
    <div id="toast">Link QB berhasil disalin!</div>

    <?php include 'header.php'; ?>

    <main class="w-full h-full flex flex-col">
        <?php if (!empty($duplicate_ap_tasks)): ?>
            <div class="px-4 sm:px-6 lg:px-8 pt-4">
                <div id="alert-carousel" class="alert-carousel overflow-hidden" onmouseenter="pauseCarousel()"
                    onmouseleave="resumeCarousel()">
                    <div class="alert-carousel-inner">
                        <?php foreach ($duplicate_ap_tasks as $model => $details): ?>
                            <div class="alert-carousel-item relative py-2.5 px-12 text-center" role="alert">
                                <div class="flex flex-col sm:flex-row items-center justify-center gap-2.5">
                                    <div class="alert-duplicate-title">
                                        <svg class="alert-duplicate-icon animate-pulse" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd"></path>
                                        </svg>
                                        <span>Duplikat Task Model: <strong class="alert-duplicate-model"><?= htmlspecialchars($model); ?></strong></span>
                                    </div>
                                    <div class="flex flex-wrap items-center justify-center gap-2 text-xs">
                                        <?php foreach ($details as $info): ?>
                                            <span class="alert-duplicate-chip">
                                                <span class="alert-chip-ap"><?= htmlspecialchars($info['ap']); ?></span>
                                                <span class="alert-chip-pic">(PIC: <?= htmlspecialchars($info['user']); ?>)</span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($duplicate_ap_tasks) > 1): ?>
                        <button type="button" class="carousel-btn prev" onclick="moveSlide(-1)">&#10094;</button>
                        <button type="button" class="carousel-btn next" onclick="moveSlide(1)">&#10095;</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Toolbar Section -->
        <div class="px-4 sm:px-6 lg:px-8 pt-5 pb-3">
            <div class="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3 bg-[var(--glass-bg)] border border-[var(--glass-border)] rounded-2xl p-3 backdrop-blur-md shadow-sm">
                <!-- Test Plan Pills -->
                <div id="testplan-filter-container" class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0 scrollbar-none">
                    <button class="filter-button active" data-plan="All">Semua</button>
                    <?php foreach ($all_test_plans as $plan): ?>
                        <button class="filter-button" data-plan="<?= htmlspecialchars($plan) ?>"><?= htmlspecialchars($plan) ?></button>
                    <?php endforeach; ?>
                </div>

                <!-- Controls & Action Buttons -->
                <div class="flex flex-wrap items-center gap-2.5 ml-auto">
                    <!-- Status Filter -->
                    <select id="status-filter" class="themed-input h-9 px-3 text-xs font-medium rounded-xl border border-[var(--glass-border)] focus:ring-2 focus:ring-blue-500 transition-all cursor-pointer">
                        <option value="All">Semua Status</option>
                        <?php foreach ($all_statuses as $status): ?>
                            <option value="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Rows Selector -->
                    <div class="flex items-center gap-1.5 text-xs text-secondary">
                        <span class="hidden sm:inline">Baris:</span>
                        <select id="pagination-rows" class="themed-input h-9 px-2.5 text-xs font-medium rounded-xl border border-[var(--glass-border)] focus:ring-2 focus:ring-blue-500 transition-all cursor-pointer">
                            <option value="5">5</option>
                            <option value="10" selected>10</option>
                            <option value="30">30</option>
                            <option value="50">50</option>
                        </select>
                    </div>

                    <!-- Export Image -->
                    <button onclick="exportNewTasksImage()"
                        class="h-9 px-3.5 rounded-xl bg-blue-600 hover:bg-blue-700 active:scale-95 text-white text-xs font-medium inline-flex items-center gap-1.5 transition-all shadow-sm">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        <span>Export Task Baru</span>
                    </button>

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
                    $active_task_build_rows = [];
                    $eligible_statuses = ['task baru', 'downloaded', 'test ongoing', 'ongoing', 'pending feedback', 'feedback sent', 'submitted'];
                    if (!empty($tasks)) {
                        foreach ($tasks as $t) {
                            $st = strtolower(trim($t['progress_status'] ?? ''));
                            if (in_array($st, $eligible_statuses)) {
                                $m = trim($t['model_name'] ?? '');
                                $a = trim($t['ap'] ?? '');
                                $c = trim($t['cp'] ?? '');
                                $csc = trim($t['csc'] ?? '');
                                if ($m !== '' || $a !== '' || $c !== '' || $csc !== '') {
                                    $active_task_build_rows[] = "{$m}\t{$a}\t{$csc}\t{$c}";
                                }
                            }
                        }
                    }
                    $active_task_builds_str = implode("\n", $active_task_build_rows);
                    ?>
                    <button onclick="copyActiveTasksBuilds(event, this)"
                        data-build-info="<?= htmlspecialchars($active_task_builds_str) ?>"
                        title="Copy Model, AP, CSC, CP untuk task status Task Baru, Downloaded, Ongoing, Pending Feedback, Feedback Sent, Submitted"
                        class="h-9 px-3.5 rounded-xl bg-amber-600 hover:bg-amber-700 active:scale-95 text-white text-xs font-medium inline-flex items-center gap-1.5 transition-all shadow-sm">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2" />
                        </svg>
                        <span>Build HomeBinary</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Table Container -->
        <div class="flex-grow overflow-auto px-4 sm:px-6 lg:px-8 pb-16 table-container">
            <div class="glassmorphism-table rounded-2xl border border-[var(--glass-border)] overflow-hidden shadow-sm backdrop-blur-md">
                <table class="w-full text-xs sm:text-sm text-left border-collapse">
                    <thead>
                        <tr class="border-b border-[var(--glass-border)] bg-[var(--glass-bg)] text-[11px] font-bold uppercase tracking-wider text-[var(--text-secondary)]">
                            <th class="py-3.5 px-3 text-center sticky top-0 bg-[var(--glass-bg)] z-10 backdrop-blur-md w-14">
                                <?php if (is_admin() || is_endri_or_admin()): ?>
                                    <div class="flex items-center justify-center">
                                        <input type="checkbox" id="select-all-tasks" onchange="toggleSelectAllTasks(this)" title="Pilih Semua Task di Halaman Ini" class="w-4 h-4 rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-rose-600 focus:ring-rose-500 focus:ring-offset-0 cursor-pointer transition-all">
                                    </div>
                                <?php else: ?>
                                    No.
                                <?php endif; ?>
                            </th>
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
                        <?php if (empty($tasks)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-12 text-secondary">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <svg class="w-8 h-8 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        <span class="text-sm font-medium">Tidak ada task aktif yang ditemukan.</span>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $row_number = 1; ?>
                            <?php foreach ($tasks as $task):
                                $ap_version = trim($task['ap'] ?? '');
                                $cp_version = trim($task['cp'] ?? '');
                                $is_mismatch = !empty($ap_version) && !empty($cp_version) && $ap_version !== $cp_version;
                                $cp_mismatch_class = $is_mismatch ? 'text-red-400 font-bold glow-highlight-red' : 'text-secondary';
                                ?>
                                <tr class="hover:bg-white/[0.03] dark:hover:bg-white/[0.04] transition-colors <?php if ($task['is_urgent']) echo 'urgent-row'; ?>"
                                    data-plan="<?= htmlspecialchars($task['test_plan_type']) ?>"
                                    data-status="<?= htmlspecialchars($task['progress_status']) ?>">
                                    <td class="py-3 px-3 text-center text-secondary font-mono text-xs w-14">
                                        <?php if (is_admin() || is_endri_or_admin()): ?>
                                            <div class="flex items-center justify-center gap-1.5">
                                                <input type="checkbox" class="task-select-checkbox w-4 h-4 rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-rose-600 focus:ring-rose-500 focus:ring-offset-0 cursor-pointer transition-all" value="<?= $task['id'] ?>" onchange="updateSelectedTasksState()">
                                                <span class="opacity-70 text-[11px]"><?= $row_number++ ?></span>
                                            </div>
                                        <?php else: ?>
                                            <?= $row_number++ ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-3 min-w-[200px]">
                                        <div class="font-semibold text-primary leading-tight">
                                            <span class="copy-text cursor-pointer hover:text-blue-400 transition" title="Klik kanan untuk copy"><?= htmlspecialchars($task['model_name']) ?></span>
                                        </div>
                                        <?php 
                                        $marketing_name = !empty($task['project_name']) ? $task['project_name'] : (function_exists('get_marketing_name') ? get_marketing_name($task['model_name']) : '');
                                        if (!empty($marketing_name)): 
                                        ?>
                                            <div class="text-[11px] text-secondary font-medium break-words leading-tight mt-0.5 mb-1" title="<?= htmlspecialchars($marketing_name) ?>">
                                                <?= htmlspecialchars($marketing_name) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="build-specs-box">
                                            <div><span class="text-secondary/70">AP:</span> <span class="copy-text cursor-pointer text-primary font-medium" title="Klik kanan untuk copy"><?= htmlspecialchars($task['ap'] ?: '-') ?></span></div>
                                            <div class="<?= $cp_mismatch_class ?>"><span class="text-secondary/70">CP:</span> <span class="copy-text cursor-pointer font-medium" title="Klik kanan untuk copy"><?= htmlspecialchars($task['cp'] ?: '-') ?></span></div>
                                            <div><span class="text-secondary/70">CSC:</span> <span class="copy-text cursor-pointer text-primary font-medium" title="Klik kanan untuk copy"><?= htmlspecialchars($task['csc'] ?: '-') ?></span></div>
                                        </div>
                                    </td>
                                    <td class="py-3 px-3 text-xs text-secondary font-mono">
                                        <?php if ($task['qb_user']): ?>
                                            <div>USER: <a href="https://android.qb.sec.samsung.net/build/<?= htmlspecialchars($task['qb_user']) ?>" target="_blank" class="qb-link font-medium"><?= htmlspecialchars($task['qb_user']) ?></a></div>
                                        <?php endif; ?>
                                        <?php if ($task['qb_userdebug']): ?>
                                            <div class="mt-0.5">DEBUG: <a href="https://android.qb.sec.samsung.net/build/<?= htmlspecialchars($task['qb_userdebug']) ?>" target="_blank" class="qb-link font-medium"><?= htmlspecialchars($task['qb_userdebug']) ?></a></div>
                                        <?php endif; ?>
                                        <?php if (function_exists('is_userdata_required') && is_userdata_required($task['model_name'])): ?>
                                            <div class="mt-1.5"><span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/15 text-rose-400 border border-rose-500/30" title="Download QB Build wajib menggunakan USERDATA"><svg class="w-3 h-3 text-rose-400 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd"/></svg>USERDATA Required</span></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-3">
                                        <span class="badge <?= getDynamicColorClasses($task['pic_email'], 'pic') ?> font-medium"><?= htmlspecialchars($task['pic_email']) ?></span>
                                    </td>
                                    <td class="py-3 px-3">
                                        <span class="<?= getTestPlanBadgeClass($task['test_plan_type']) ?>"><?= htmlspecialchars($task['test_plan_type']) ?></span>
                                    </td>
                                    <td class="py-3 px-3">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium <?= getStatusColorClasses($task['progress_status']) ?>">
                                            <span class="w-1.5 h-1.5 rounded-full <?= getStatusDotColor($task['progress_status']) ?>"></span>
                                            <span><?= htmlspecialchars($task['progress_status']) ?></span>
                                        </span>
                                    </td>
                                    <td class="py-3 px-3">
                                        <div class="w-24">
                                            <div class="progress-bar-bg w-full rounded-full h-3.5 relative flex items-center overflow-hidden">
                                                <div class="progress-bar-fill h-3.5 rounded-full absolute top-0 left-0" style="width: <?= $task['progress_percentage'] ?>%;"></div>
                                                <span class="relative text-[10px] font-bold z-10 progress-text pl-1.5"><?= round($task['progress_percentage']) ?>%</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 px-3 text-xs text-secondary whitespace-nowrap">
                                        <div><span class="text-secondary/70">Req:</span> <?= $task['request_date_obj'] ? $task['request_date_obj']->format('d M Y') : '-' ?></div>
                                        <div><span class="text-secondary/70">Sub:</span> <?= $task['submission_date_obj'] ? $task['submission_date_obj']->format('d M Y') : '-' ?></div>
                                        <div class="font-bold text-primary"><span class="text-secondary/70">Deadline:</span> <?= $task['deadline'] ? date('d M Y', strtotime($task['deadline'])) : '-' ?></div>
                                    </td>
                                    <td class="py-3 px-3 text-xs whitespace-nowrap">
                                        <div class="mb-1 flex items-center gap-1.5">
                                            <span class="text-secondary/70 w-16">Submission:</span>
                                            <?php if ($task['ontime_submission_status']): ?>
                                                <span class="font-semibold <?= $task['ontime_submission_status'] == 'Delay' ? 'text-red-400' : 'text-green-400' ?>"><?= $task['ontime_submission_status'] ?></span>
                                            <?php elseif (isset($task['deadline_countdown'])): ?>
                                                <span class="inline-flex items-center gap-1 font-medium <?= $task['deadline_countdown'] < 0 ? 'text-red-400' : ($task['deadline_countdown'] <= 3 ? 'text-red-400' : 'text-secondary') ?>">
                                                    <?php if ($task['deadline_countdown'] <= 3 && $task['deadline_countdown'] >= 0): ?>
                                                         <svg class="w-3.5 h-3.5 animate-pulse-alert" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg>
                                                    <?php endif; ?>
                                                    <?= $task['deadline_countdown'] >= 0 ? $task['deadline_countdown'] . ' hari lagi' : 'Terlewat ' . abs($task['deadline_countdown']) . ' hari'; ?>
                                                </span>
                                            <?php else: echo '-'; endif; ?>
                                        </div>
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-secondary/70 w-16">Approval:</span>
                                            <?php if ($task['ontime_approved_status']): ?>
                                                <span class="font-semibold <?= $task['ontime_approved_status'] == 'Delay' ? 'text-red-400' : 'text-green-400' ?>"><?= $task['ontime_approved_status'] ?></span>
                                            <?php elseif (isset($task['approval_countdown'])): ?>
                                                <span class="inline-flex items-center gap-1 font-medium <?= $task['approval_countdown'] < 0 ? 'text-red-400' : ($task['approval_countdown'] <= 1 ? 'text-red-400' : 'text-secondary') ?>">
                                                    <?php if ($task['approval_countdown'] <= 1 && $task['approval_countdown'] >= 0): ?>
                                                        <svg class="w-3.5 h-3.5 animate-pulse-alert" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg>
                                                    <?php endif; ?>
                                                    <?= $task['approval_countdown'] >= 0 ? $task['approval_countdown'] . ' hari lagi' : 'Terlewat ' . abs($task['approval_countdown']) . ' hari'; ?>
                                                </span>
                                            <?php else: echo '-'; endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-3 px-3 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <button
                                                onclick='openEditModal(<?= json_encode($task, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)'
                                                class="card-action-btn hover:text-blue-500" title="Edit Task">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                    <path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z"></path>
                                                    <path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd"></path>
                                                </svg>
                                            </button>
                                            <?php if (is_admin() || is_endri_or_admin()): ?>
                                                <form action="handler.php" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus task ini?');" class="inline-block m-0">
                                                    <input type="hidden" name="action" value="delete_gba_task">
                                                    <input type="hidden" name="id" value="<?= $task['id'] ?>">
                                                    <button type="submit" class="card-action-btn hover:text-red-500" title="Hapus Task">
                                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm4 0a1 1 0 012 0v6a1 1 0 11-2 0V8z" clip-rule="evenodd"></path>
                                                        </svg>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div id="pagination-nav" class="flex justify-center items-center gap-1.5 py-5 text-secondary"></div>
        </div>

        <!-- Floating Bulk Action Bar -->
        <?php if (is_admin() || is_endri_or_admin()): ?>
            <div id="bulk-action-bar">
                <div class="flex items-center gap-2 pr-3 border-r border-slate-700/80 text-xs">
                    <span class="w-2 h-2 rounded-full bg-rose-500 animate-pulse"></span>
                    <span class="font-medium text-slate-300"><strong id="bulk-selected-count" class="text-white font-bold font-mono text-sm">0</strong> task dipilih</span>
                </div>
                <button type="button" id="btn-cancel-selected" onclick="cancelSelectedTasks()" class="h-8 px-3.5 rounded-xl bg-rose-600 hover:bg-rose-500 active:scale-95 text-white text-xs font-semibold inline-flex items-center gap-1.5 transition-all shadow-sm shadow-rose-600/30">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    <span>Batalkan Task</span>
                </button>
                <button type="button" onclick="clearAllSelections()" class="h-8 px-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 active:scale-95 text-slate-300 hover:text-white text-xs font-medium transition-all">
                    Batal Pilih
                </button>
            </div>
        <?php endif; ?>
    </main>

    <div id="task-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 backdrop-blur-sm hidden" style="backdrop-filter: blur(8px);">
        <div class="modal-content-wrapper glassmorphism-modal rounded-2xl shadow-2xl p-4 sm:p-5 w-full max-w-5xl mx-3">
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

    <!-- Better UI & Emil Design Eng: Custom Confirmation Modal -->
    <div id="confirm-modal" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/70 backdrop-blur-md hidden transition-all duration-200">
        <div id="confirm-modal-box" class="modal-content-wrapper rounded-2xl shadow-2xl p-5 sm:p-6 w-full max-w-md mx-4 transform scale-95 opacity-0 transition-all duration-200" onclick="event.stopPropagation()">
            <div class="flex items-start gap-3.5">
                <div id="confirm-modal-icon-bg" class="w-10 h-10 rounded-xl bg-rose-500/15 border border-rose-500/30 flex items-center justify-center flex-shrink-0 text-rose-400 mt-0.5">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 id="confirm-modal-title" class="text-base font-bold text-header">Batalkan Task Terpilih</h3>
                    <p id="confirm-modal-desc" class="text-xs text-secondary mt-1.5 leading-relaxed">Apakah Anda yakin ingin membatalkan <span id="confirm-modal-count" class="font-bold text-primary">0 task</span> yang dipilih? Status task akan diubah menjadi <strong class="text-rose-400">Batal</strong>.</p>
                </div>
            </div>
            <div class="flex justify-end items-center gap-2.5 mt-6 pt-4 border-t border-[var(--glass-border)]">
                <button type="button" id="confirm-modal-btn-cancel" class="h-9 px-4 rounded-xl text-xs font-semibold text-secondary hover:text-primary hover:bg-white/5 active:scale-95 transition-all">
                    Batal
                </button>
                <button type="button" id="confirm-modal-btn-action" class="h-9 px-4 rounded-xl text-xs font-semibold bg-rose-600 hover:bg-rose-500 text-white shadow-sm shadow-rose-600/30 active:scale-95 transition-all inline-flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    <span id="confirm-modal-btn-text">Ya, Batalkan Task</span>
                </button>
            </div>
        </div>
    </div>

    <script>
        const canvas = document.getElementById('neural-canvas'), ctx = canvas.getContext('2d');
        let particles = [], hue = 210;
        function setCanvasSize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; } setCanvasSize();

        class Particle {
            constructor(x, y) {
                this.x = x || Math.random() * canvas.width;
                this.y = y || Math.random() * canvas.height;
                this.vx = (Math.random() - .5) * .4;
                this.vy = (Math.random() - .5) * .4;
                this.size = Math.random() * 2 + 1.5;
            }
            update() {
                this.x += this.vx; this.y += this.vy;
                if (this.x < 0 || this.x > canvas.width) this.vx *= -1;
                if (this.y < 0 || this.y > canvas.height) this.vy *= -1;
            }
            draw() {
                ctx.fillStyle = `hsl(${hue},100%,75%)`;
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                ctx.fill();
            }
        }

        function init(num) {
            particles = [];
            for (let i = 0; i < num; i++)particles.push(new Particle())
        }

        function handleParticles() {
            for (let i = 0; i < particles.length; i++) {
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

        function animate() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            hue = (hue + 0.3) % 360;
            handleParticles();
            requestAnimationFrame(animate);
        }

        const particleCount = window.innerWidth > 768 ? 150 : 70;
        init(particleCount);
        animate();

        const themeToggleBtn = document.getElementById('theme-toggle'); let quill;
        function applyTheme(isLight) { document.documentElement.classList.toggle('light', isLight); document.getElementById('theme-toggle-light-icon').classList.toggle('hidden', !isLight); document.getElementById('theme-toggle-dark-icon').classList.toggle('hidden', isLight) } const savedTheme = localStorage.getItem('theme'); applyTheme(savedTheme === 'light'); themeToggleBtn.addEventListener('click', () => { const isLight = !document.documentElement.classList.contains('light'); localStorage.setItem('theme', isLight ? 'light' : 'dark'); applyTheme(isLight) });

        const modal = document.getElementById('task-modal'), modalTitle = document.getElementById('modal-title'), taskForm = document.getElementById('task-form'), formAction = document.getElementById('form-action'), taskId = document.getElementById('task-id');

        function openAddModal() { taskForm.reset(); modalTitle.innerText = 'Tambah Task Baru'; formAction.value = 'create_gba_task'; taskId.value = ''; setupQuill(''); updateChecklistVisibility(); setDefaultDates(); if (modal) { modal.classList.remove('modal-closing', 'hidden'); } }
        function openEditModal(task) { taskForm.reset(); modalTitle.innerText = 'Edit Task'; formAction.value = 'update_gba_task'; for (const key in task) { if (taskForm.elements[key] && !key.endsWith('_obj')) { taskForm.elements[key].value = task[key] } } document.getElementById('is_urgent_toggle').checked = task.is_urgent == 1; setupQuill(task.notes || ''); updateChecklistVisibility(); const autoCheckStatuses = ['Approved', 'Submitted', 'Passed', 'Pending Feedback', 'Feedback Sent']; if (autoCheckStatuses.includes(task.progress_status)) { checkAllVisibleCheckboxes(true); } else if (task.test_items_checklist) { try { const checklist = JSON.parse(task.test_items_checklist); const visibleContainer = document.querySelector('[id^="checklist-container-"]:not(.hidden)'); if (visibleContainer) { for (const itemName in checklist) { const checkbox = visibleContainer.querySelector(`input[name="checklist[${itemName}]"]`); if (checkbox) checkbox.checked = !!checklist[itemName]; } } } catch (e) { console.error("Could not parse checklist JSON:", e) } } if (modal) { modal.classList.remove('modal-closing', 'hidden'); } }
        let isTaskModalClosing = false;
        function closeModal() {
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
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeModal();
            });
        }
        
        // Quick Action: Escape key to close modal
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' || e.key === 'Esc') {
                if (modal && !modal.classList.contains('hidden')) {
                    closeModal();
                }
                const confirmModal = document.getElementById('confirm-modal');
                if (confirmModal && !confirmModal.classList.contains('hidden')) {
                    confirmModal.classList.add('hidden');
                }
            }
        });
        document.getElementById('test_plan_type').addEventListener('change', updateChecklistVisibility); function setupQuill(content) { if (quill) { quill.root.innerHTML = content } else { quill = new Quill('#notes-editor', { theme: 'snow', modules: { toolbar: [['bold', 'italic', 'underline'], ['link'], [{ 'list': 'ordered' }, { 'list': 'bullet' }]] } }); quill.root.innerHTML = content } }
        taskForm.addEventListener('submit', function () { document.getElementById('notes-hidden-input').value = quill.root.innerHTML; const visibleChecklist = document.querySelector('[id^="checklist-container-"]:not(.hidden)'); if (visibleChecklist) { document.querySelectorAll('[id^="checklist-hidden-"]').forEach(el => el.remove()); visibleChecklist.querySelectorAll('input[type="checkbox"]').forEach(cb => { const hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.id = 'checklist-hidden-' + cb.name.replace(/[\[\]]/g,'_'); hidden.name = cb.name; hidden.value = cb.checked ? '1' : '0'; taskForm.appendChild(hidden); cb.disabled = true; }); } }); function updateChecklistVisibility() { const testPlan = document.getElementById('test_plan_type').value, placeholder = document.getElementById('checklist-placeholder'); let checklistVisible = !1; document.querySelectorAll('[id^="checklist-container-"]').forEach(el => { const planName = el.id.replace('checklist-container-', '').replace(/_/g, ' '); if (planName === testPlan) { el.classList.remove('hidden'); checklistVisible = !0 } else { el.classList.add('hidden') } }); placeholder.style.display = checklistVisible ? 'none' : 'block' }
        const searchInput = document.getElementById('search-input'), rowsSelect = document.getElementById('pagination-rows'), tableBody = document.getElementById('task-table-body'), paginationNav = document.getElementById('pagination-nav'), testplanFilterContainer = document.getElementById('testplan-filter-container'), statusFilter = document.getElementById('status-filter'), allRows = Array.from(tableBody.querySelectorAll('tr')); let currentPage = 1, activePlanFilter = 'All', activeStatusFilter = 'All'; function renderTable() { const searchText = searchInput.value.toLowerCase(), rowsPerPage = parseInt(rowsSelect.value), filteredRows = allRows.filter(row => { const matchesSearch = row.textContent.toLowerCase().includes(searchText), matchesPlan = activePlanFilter === 'All' || row.dataset.plan === activePlanFilter, matchesStatus = activeStatusFilter === 'All' || (Array.isArray(activeStatusFilter) ? activeStatusFilter.includes(row.dataset.status) : row.dataset.status === activeStatusFilter); return matchesSearch && matchesPlan && matchesStatus }), totalPages = Math.ceil(filteredRows.length / rowsPerPage); currentPage = Math.min(currentPage, totalPages) || 1; tableBody.innerHTML = ''; const start = (currentPage - 1) * rowsPerPage, end = start + rowsPerPage; filteredRows.slice(start, end).forEach(row => tableBody.appendChild(row)); renderPagination(totalPages); if (typeof updateSelectedTasksState === 'function') updateSelectedTasksState(); }
        function renderPagination(totalPages) { paginationNav.innerHTML = ''; if (totalPages <= 1) return; const maxButtons = 5; let startPage = Math.max(1, currentPage - Math.floor(maxButtons / 2)), endPage = Math.min(totalPages, startPage + maxButtons - 1); if (endPage - startPage + 1 < maxButtons) { startPage = Math.max(1, endPage - maxButtons + 1) } if (startPage > 1) { paginationNav.appendChild(createPageButton(1, '«')); paginationNav.appendChild(createPageButton(currentPage - 1, '‹')) } for (let i = startPage; i <= endPage; i++) { paginationNav.appendChild(createPageButton(i, i)) } if (endPage < totalPages) { paginationNav.appendChild(createPageButton(currentPage + 1, '›')); paginationNav.appendChild(createPageButton(totalPages, '»')) } }
        function createPageButton(page, text) { const pageButton = document.createElement('button'); pageButton.textContent = text; pageButton.className = `px-3 py-1.5 rounded-xl text-xs font-semibold transition-all ${page === currentPage ? 'bg-blue-600 text-white shadow-sm shadow-blue-600/30' : 'bg-[var(--glass-bg)] border border-[var(--glass-border)] text-secondary hover:text-primary hover:bg-white/10'}`; pageButton.onclick = () => { currentPage = page; renderTable() }; return pageButton }
        const progressStatusSelect = document.getElementById('progress_status'), submissionDateInput = document.getElementById('submission_date'), approvedDateInput = document.getElementById('approved_date'), requestDateInput = document.getElementById('request_date'), deadlineInput = document.getElementById('deadline'), signOffDateInput = document.getElementById('sign_off_date');
        function calculateWorkingDays(startDate, daysToAdd) { let currentDate = new Date(startDate); let addedDays = 0; while (addedDays < daysToAdd) { currentDate.setDate(currentDate.getDate() + 1); if (currentDate.getDay() !== 0 && currentDate.getDay() !== 6) { addedDays++ } } return currentDate.toISOString().slice(0, 10) }
        function getTodayDate() { return new Date().toISOString().slice(0, 10) }
        function setDefaultDates() { const today = getTodayDate(); if (!requestDateInput.value) { requestDateInput.value = today } const deadline = calculateWorkingDays(requestDateInput.value, 7); deadlineInput.value = deadline; signOffDateInput.value = deadline }

        function checkAllVisibleCheckboxes(checked = true) {
            const visibleChecklist = document.querySelector('[id^="checklist-container-"]:not(.hidden)');
            if (visibleChecklist) {
                visibleChecklist.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                    cb.checked = checked;
                });
            }
        }

        requestDateInput.addEventListener('change', () => { if (requestDateInput.value) { const futureDate = calculateWorkingDays(requestDateInput.value, 7); deadlineInput.value = futureDate; signOffDateInput.value = futureDate } });

        progressStatusSelect.addEventListener('change', e => {
            const status = e.target.value;
            if (status === 'Submitted' || status === 'Approved' || status === 'Passed') {
                if (!submissionDateInput.value) {
                    submissionDateInput.value = getTodayDate();
                }
                if (status === 'Approved' || status === 'Passed') {
                    if (!approvedDateInput.value) {
                        approvedDateInput.value = getTodayDate();
                    }
                }
                checkAllVisibleCheckboxes(true);
            } else if (status === 'Task Baru') {
                checkAllVisibleCheckboxes(false);
                submissionDateInput.value = '';
                approvedDateInput.value = '';
            }
        });

        taskForm.addEventListener('change', e => { if (e.target.matches('input[type="checkbox"][name^="checklist"]')) { const currentStatus = progressStatusSelect.value; if (currentStatus !== 'Approved' && currentStatus !== 'Submitted') { progressStatusSelect.value = 'Test Ongoing' } } });

        // --- Carousel Logic ---
        const carousel = document.getElementById('alert-carousel');
        const inner = carousel ? carousel.querySelector('.alert-carousel-inner') : null;
        const items = inner ? inner.children : [];
        let carouselInterval;
        let currentIndex = 1;
        let isTransitioning = false;

        function setupCarousel() {
            if (!inner || items.length <= 1) return;
            const firstClone = items[0].cloneNode(true);
            const lastClone = items[items.length - 1].cloneNode(true);
            inner.appendChild(firstClone);
            inner.insertBefore(lastClone, items[0]);
            inner.style.transform = `translateX(-100%)`;
        }

        function moveSlide(direction) {
            if (isTransitioning) return;
            isTransitioning = true;
            inner.style.transition = 'transform 0.6s cubic-bezier(0.25, 0.8, 0.25, 1)';
            currentIndex += direction;
            inner.style.transform = `translateX(-${currentIndex * 100}%)`;
        }

        if (inner) {
            inner.addEventListener('transitionend', () => {
                const totalItemsWithClones = items.length;
                if (currentIndex === 0) {
                    inner.style.transition = 'none';
                    currentIndex = totalItemsWithClones - 2;
                    inner.style.transform = `translateX(-${currentIndex * 100}%)`;
                }
                if (currentIndex === totalItemsWithClones - 1) {
                    inner.style.transition = 'none';
                    currentIndex = 1;
                    inner.style.transform = `translateX(-${currentIndex * 100}%)`;
                }
                isTransitioning = false;
            });
        }

        function startCarousel() {
            if (carousel && items.length > 1) {
                carouselInterval = setInterval(() => moveSlide(1), 5000);
            }
        }
        function pauseCarousel() { clearInterval(carouselInterval); }
        function resumeCarousel() { startCarousel(); }

        async function exportNewTasksImage() {
            const button = document.querySelector('button[onclick="exportNewTasksImage()"]');
            const originalText = button.innerHTML;
            button.innerHTML = '<span class="animate-pulse">Generating...</span>';
            button.disabled = true;

            const originalStatus = statusFilter.value;
            const originalActiveStatus = activeStatusFilter;
            const originalRowsParams = rowsSelect.value;
            const originalSearch = searchInput ? searchInput.value : '';

            try {
                // Set filter to 'Task Baru', 'Downloaded', and 'Test Ongoing' and show all
                // ponytail: simplify status filter check for multi-state export
                statusFilter.value = 'All';
                activeStatusFilter = ['Task Baru', 'Downloaded', 'Test Ongoing'];
                if (searchInput) searchInput.value = '';

                const hugeOption = document.createElement('option');
                hugeOption.value = '2000';
                hugeOption.text = 'All';
                rowsSelect.add(hugeOption);
                rowsSelect.value = '2000';

                renderTable();

                // Check if any rows are visible
                const visibleRows = document.querySelectorAll('#task-table-body tr');
                if (visibleRows.length === 0 || (visibleRows.length === 1 && visibleRows[0].innerText.includes('Tidak ada task'))) {
                    // No new tasks
                    // We can still proceed to show the "empty" table or alert.
                    // Proceeding allows capturing the empty state which is valid.
                }

                await new Promise(r => setTimeout(r, 800));

                const tableContainer = document.querySelector('.glassmorphism-table');

                // Hide 'Aksi' column (last column)
                const hiddenElements = [];
                const actionHeader = tableContainer.querySelector('th:nth-child(10)');
                if (actionHeader) {
                    actionHeader.style.display = 'none';
                    hiddenElements.push(actionHeader);
                }
                tableContainer.querySelectorAll('td:nth-child(10)').forEach(td => {
                    td.style.display = 'none';
                    hiddenElements.push(td);
                });

                const canvas = await html2canvas(tableContainer, {
                    backgroundColor: getComputedStyle(document.body).backgroundColor,
                    scale: 2,
                    useCORS: true,
                    logging: false
                });

                hiddenElements.forEach(el => el.style.display = '');

                const link = document.createElement('a');
                link.download = 'GBA_New_Tasks_' + new Date().toISOString().slice(0, 10) + '.png';
                link.href = canvas.toDataURL('image/png');
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);

            } catch (err) {
                console.error("Export failed:", err);
                alert("Gagal melakukan export image: " + err.message);
            } finally {
                statusFilter.value = originalStatus;
                activeStatusFilter = originalActiveStatus;
                if (searchInput) searchInput.value = originalSearch;

                const dummy = Array.from(rowsSelect.options).find(opt => opt.value === '2000');
                if (dummy) rowsSelect.removeChild(dummy);
                rowsSelect.value = originalRowsParams;

                renderTable();

                button.innerHTML = originalText;
                button.disabled = false;
            }
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


        document.addEventListener('DOMContentLoaded', () => {
            if (carousel && inner && items.length > 0) {
                setupCarousel();
                startCarousel();
            }
            renderTable(); setupQuill(''); updateChecklistVisibility(); const profileMenu = document.getElementById('profile-menu'); if (profileMenu) { const profileButton = profileMenu.querySelector('button'), profileDropdown = document.getElementById('profile-dropdown'); profileButton.addEventListener('click', e => { e.stopPropagation(); profileDropdown.classList.toggle('hidden') }); document.addEventListener('click', e => { if (!profileMenu.contains(e.target)) { profileDropdown.classList.add('hidden') } }) }
        });
        if (searchInput) { searchInput.addEventListener('input', renderTable) }; rowsSelect.addEventListener('change', () => { currentPage = 1; renderTable() }); testplanFilterContainer.addEventListener('click', e => { if (e.target.tagName === 'BUTTON') { testplanFilterContainer.querySelector('.active').classList.remove('active'); e.target.classList.add('active'); activePlanFilter = e.target.dataset.plan; currentPage = 1; renderTable() } });
        statusFilter.addEventListener('change', () => { activeStatusFilter = statusFilter.value; currentPage = 1; renderTable() });

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

        // ponytail & better-ui: superuser bulk task cancel handlers
        function toggleSelectAllTasks(master) {
            const checkboxes = document.querySelectorAll('#task-table-body .task-select-checkbox');
            checkboxes.forEach(cb => {
                const tr = cb.closest('tr');
                if (tr && tr.style.display !== 'none') {
                    cb.checked = master.checked;
                    tr.classList.toggle('row-selected', master.checked);
                }
            });
            updateSelectedTasksState();
        }

        function clearAllSelections() {
            const checkboxes = document.querySelectorAll('.task-select-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = false;
                const tr = cb.closest('tr');
                if (tr) tr.classList.remove('row-selected');
            });
            const master = document.getElementById('select-all-tasks');
            if (master) {
                master.checked = false;
                master.indeterminate = false;
            }
            updateSelectedTasksState();
        }

        function updateSelectedTasksState() {
            const allCheckboxes = Array.from(document.querySelectorAll('#task-table-body .task-select-checkbox')).filter(cb => {
                const tr = cb.closest('tr');
                return tr && tr.style.display !== 'none';
            });
            
            allCheckboxes.forEach(cb => {
                const tr = cb.closest('tr');
                if (tr) tr.classList.toggle('row-selected', cb.checked);
            });

            const checkedBoxes = allCheckboxes.filter(cb => cb.checked);
            const master = document.getElementById('select-all-tasks');
            const bulkBar = document.getElementById('bulk-action-bar');
            const countLabel = document.getElementById('bulk-selected-count');

            if (master) {
                master.checked = allCheckboxes.length > 0 && checkedBoxes.length === allCheckboxes.length;
                master.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < allCheckboxes.length;
            }

            if (bulkBar) {
                if (checkedBoxes.length > 0) {
                    bulkBar.classList.add('active');
                    document.body.classList.add('has-bulk-bar');
                    if (countLabel) countLabel.textContent = checkedBoxes.length;
                } else {
                    bulkBar.classList.remove('active');
                    document.body.classList.remove('has-bulk-bar');
                    if (countLabel) countLabel.textContent = '0';
                }
            }
        }

        function openConfirmDialog({ count, onConfirm }) {
            const modal = document.getElementById('confirm-modal');
            const box = document.getElementById('confirm-modal-box');
            const countSpan = document.getElementById('confirm-modal-count');
            const btnAction = document.getElementById('confirm-modal-btn-action');
            const btnCancel = document.getElementById('confirm-modal-btn-cancel');

            if (!modal || !box) return;

            if (countSpan) countSpan.textContent = `${count} task`;

            modal.classList.remove('hidden');
            // Emil design smooth spring entrance
            requestAnimationFrame(() => {
                box.classList.remove('scale-95', 'opacity-0');
                box.classList.add('scale-100', 'opacity-100');
            });

            function close() {
                box.classList.remove('scale-100', 'opacity-100');
                box.classList.add('scale-95', 'opacity-0');
                setTimeout(() => {
                    modal.classList.add('hidden');
                }, 180);
                cleanup();
            }

            function handleConfirm() {
                close();
                if (typeof onConfirm === 'function') onConfirm();
            }

            function handleKey(e) {
                if (e.key === 'Escape') close();
                else if (e.key === 'Enter') handleConfirm();
            }

            function cleanup() {
                modal.removeEventListener('click', close);
                if (btnCancel) btnCancel.removeEventListener('click', close);
                if (btnAction) btnAction.removeEventListener('click', handleConfirm);
                document.removeEventListener('keydown', handleKey);
            }

            modal.addEventListener('click', close);
            if (btnCancel) btnCancel.addEventListener('click', close);
            if (btnAction) btnAction.addEventListener('click', handleConfirm, { once: true });
            document.addEventListener('keydown', handleKey);
        }

        async function cancelSelectedTasks() {
            const checkedBoxes = Array.from(document.querySelectorAll('#task-table-body .task-select-checkbox:checked'));
            const selectedIds = checkedBoxes.map(cb => parseInt(cb.value)).filter(Boolean);
            if (selectedIds.length === 0) {
                return;
            }

            openConfirmDialog({
                count: selectedIds.length,
                onConfirm: async () => {
                    const btnCancel = document.getElementById('btn-cancel-selected');
                    if (btnCancel) {
                        btnCancel.disabled = true;
                        btnCancel.innerHTML = '<span class="animate-pulse">Memproses...</span>';
                    }

                    try {
                        const response = await fetch('handler.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'cancel_bulk_gba_tasks',
                                task_ids: selectedIds
                            })
                        });
                        const result = await response.json();
                        if (result.success) {
                            window.location.reload();
                        } else {
                            alert('Gagal membatalkan task: ' + (result.error || 'Terjadi kesalahan'));
                            if (btnCancel) {
                                btnCancel.disabled = false;
                                updateSelectedTasksState();
                            }
                        }
                    } catch (err) {
                        console.error('Error cancel task:', err);
                        alert('Terjadi kesalahan jaringan saat membatalkan task.');
                        if (btnCancel) {
                            btnCancel.disabled = false;
                            updateSelectedTasksState();
                        }
                    }
                }
            });
        }
    </script>
</body>

</html>