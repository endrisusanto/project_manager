<?php
// 1. INISIALISASI
require_once "config.php";
require_once "session.php";
require_once "marketing_name_mapper.php";

// Tentukan halaman aktif untuk navigasi header
$active_page = 'project_dashboard';

// 2. LOGIKA PENGAMBILAN DATA TUGAS (TASK)
// MODIFIKASI: Menambahkan 'Downloaded' agar muat 8 kolom (Passed disembunyikan)
// ponytail: remove 'Passed' from Kanban statuses per user request
$statuses = ['Task Baru', 'Downloaded', 'Test Ongoing', 'Pending Feedback', 'Feedback Sent', 'Submitted', 'Approved', 'Batal'];
$tasksToDisplay = [];
foreach ($statuses as $status) {
    $tasksToDisplay[$status] = [];
}

// MODIFIKASI: Filter data berdasarkan peran pengguna
$one_month_ago = date('Y-m-d', strtotime('-1 month'));
$sql = "SELECT t.*, u.profile_picture 
        FROM gba_tasks t 
        LEFT JOIN users u ON t.pic_email = u.email";

$where_clauses = ["t.request_date >= ?"];
$params = [$one_month_ago];
$types = "s";

if (!is_admin()) {
    $where_clauses[] = "t.pic_email = ?";
    $params[] = $_SESSION['user_details']['email'];
    $types .= "s";
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$sql .= " ORDER BY t.id DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = false; 
}


if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $row['request_date_obj'] = $row['request_date'] ? new DateTime($row['request_date']) : null;
        $row['submission_date_obj'] = $row['submission_date'] ? new DateTime($row['submission_date']) : null;
        $row['approved_date_obj'] = isset($row['approved_date']) && $row['approved_date'] ? new DateTime($row['approved_date']) : null;
        $deadline_date = $row['deadline'] ? new DateTime($row['deadline']) : null;
        
        if ($row['submission_date_obj'] && $row['request_date_obj']) {
            $submission_diff = $row['submission_date_obj']->diff($row['request_date_obj'])->days;
            $row['ontime_submission_status'] = $submission_diff <= 7 ? 'Ontime' : 'Delay';
        } else { $row['ontime_submission_status'] = null; }
        
        if ($row['approved_date_obj'] && $row['submission_date_obj']) {
            $approval_diff = $row['approved_date_obj']->diff($row['submission_date_obj'])->days;
            $row['ontime_approved_status'] = $approval_diff <= 3 ? 'Ontime' : 'Delay';
        } else { $row['ontime_approved_status'] = null; }

        $row['deadline_countdown'] = null;
        if (!$row['submission_date_obj'] && $deadline_date) {
            $now = new DateTime(); $now->setTime(0,0,0); $deadline_date->setTime(0,0,0);
            $diff = $now->diff($deadline_date);
            $row['deadline_countdown'] = ($now <= $deadline_date) ? $diff->days : -$diff->days;
        }

        $row['approval_countdown'] = null;
        if ($row['submission_date_obj'] && !$row['approved_date_obj']) {
            $approval_deadline = (clone $row['submission_date_obj'])->modify('+3 days');
            $now = new DateTime(); $now->setTime(0,0,0); $approval_deadline->setTime(0,0,0);
            $diff = $now->diff($approval_deadline);
            $row['approval_countdown'] = ($now <= $approval_deadline) ? $diff->days : -$diff->days;
        }

        if (isset($tasksToDisplay[$row['progress_status']])) {
            $tasksToDisplay[$row['progress_status']][] = $row;
        }
    }
}

// 3. FUNGSI HELPER TAMPILAN
function getStatusDotColor($status) {
    $map = [
        'Task Baru' => 'bg-blue-500 shadow-sm shadow-blue-500/50',
        'Downloaded' => 'bg-cyan-500 shadow-sm shadow-cyan-500/50',
        'Test Ongoing' => 'bg-amber-500 shadow-sm shadow-amber-500/50',
        'Pending Feedback' => 'bg-orange-500 shadow-sm shadow-orange-500/50',
        'Feedback Sent' => 'bg-rose-500 shadow-sm shadow-rose-500/50',
        'Submitted' => 'bg-purple-500 shadow-sm shadow-purple-500/50',
        'Passed' => 'bg-emerald-500 shadow-sm shadow-emerald-500/50',
        'Approved' => 'bg-emerald-400 shadow-sm shadow-emerald-400/50',
        'Batal' => 'bg-slate-400 shadow-sm shadow-slate-400/50'
    ];
    return $map[$status] ?? 'bg-slate-500 shadow-sm shadow-slate-500/50';
}
function getPicBadgeColor($identifier) {
    $colors = ['sky', 'emerald', 'amber', 'rose', 'violet', 'teal', 'cyan'];
    $hash = crc32($identifier);
    return "badge-color-" . $colors[$hash % count($colors)];
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
function getPicInitials($email) {
    if (empty($email)) return '??';
    $parts = explode('@', $email);
    $name_parts = explode('.', $parts[0]);
    $initials = '';
    foreach ($name_parts as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
    return strlen($initials) > 2 ? substr($initials, 0, 2) : $initials;
}
function render_kinerja_status($task) {
    ob_start();
    
    if ($task['progress_status'] === 'Batal') {
        echo '<div class="flex items-center justify-between text-[11.5px] leading-snug">';
        echo '<span class="text-secondary">Status:</span>';
        echo '<span class="font-semibold text-secondary">Batal</span>';
        echo '</div>';
        return ob_get_clean();
    }

    echo '<div class="flex items-center justify-between text-[11.5px] leading-snug">';
    echo '<span class="text-secondary">Submission:</span>';
    if ($task['ontime_submission_status']) {
        $color_class = $task['ontime_submission_status'] == 'Delay' ? 'text-red-400' : 'text-green-400';
        echo "<span class='font-semibold {$color_class}'>{$task['ontime_submission_status']}</span>";
    } elseif (isset($task['deadline_countdown'])) {
        $days = $task['deadline_countdown'];
        $color_class = $days < 0 ? 'text-red-400' : ($days <= 3 ? 'text-yellow-400' : 'text-secondary');
        $icon_html = ($days <= 3 && $days >= 0) ? '<svg class="w-3.5 h-3.5 animate-pulse-alert inline" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg>' : '';
        $text = $days >= 0 ? "{$days} hari lagi" : 'Lewat ' . abs($days) . ' hari';
        echo "<span class='flex items-center gap-1 font-semibold {$color_class}'>{$icon_html}{$text}</span>";
    } else {
        echo '<span class="text-secondary">-</span>';
    }
    echo '</div>';
    echo '<div class="flex items-center justify-between text-[11.5px] leading-snug">';
    echo '<span class="text-secondary">Approval:</span>';
    if ($task['ontime_approved_status']) {
        $color_class = $task['ontime_approved_status'] == 'Delay' ? 'text-red-400' : 'text-green-400';
        echo "<span class='font-semibold {$color_class}'>{$task['ontime_approved_status']}</span>";
    } elseif (isset($task['approval_countdown'])) {
        $days = $task['approval_countdown'];
        $color_class = $days < 0 ? 'text-red-400' : ($days <= 1 ? 'text-yellow-400' : 'text-secondary');
        $icon_html = ($days <= 1 && $days >= 0) ? '<svg class="w-3.5 h-3.5 animate-pulse-alert inline" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg>' : '';
        $text = $days >= 0 ? "{$days} hari lagi" : 'Lewat ' . abs($days) . ' hari';
        echo "<span class='flex items-center gap-1 font-semibold {$color_class}'>{$icon_html}{$text}</span>";
    } else {
        echo '<span class="text-secondary">-</span>';
    }
    echo '</div>';
    return ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GBA Task Kanban Board</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['class', '.never-match-dark']
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>
    <style>
        :root{--bg-primary:#020617;--text-primary:#e2e8f0;--text-secondary:#94a3b8;--glass-bg:rgba(15,23,42,.8);--glass-border:rgba(51,65,85,.6);--column-bg:rgba(255,255,255,.03);--text-header:#fff;--text-card-title:#fff;--text-card-body:#cbd5e1;--text-icon:#94a3b8;--input-bg:rgba(30,41,59,.7);--input-border:#475569;--input-text:#e2e8f0;--toast-bg:#22c55e;--toast-text:#fff;--modal-bg:#0f172a;--modal-border:#334155;--title-pill-bg:rgba(55, 65, 81, 0.4);}
        html.light{--bg-primary:#f1f5f9;--text-primary:#0f172a;--text-secondary:#475569;--glass-bg:rgba(255,255,255,.8);--glass-border:rgba(0,0,0,.08);--column-bg:rgba(0,0,0,.03);--text-header:#0f172a;--text-card-title:#1e293b;--text-card-body:#334155;--text-icon:#64748b;--input-bg:#fff;--input-border:#cbd5e1;--input-text:#0f172a;--toast-bg:#16a34a;--toast-text:#fff;--modal-bg:#ffffff;--modal-border:#e2e8f0;--title-pill-bg:rgba(226, 232, 240, 0.85);}
        html,body{overflow-x:hidden;height:100%}body{font-family:'Inter',sans-serif;background-color:var(--bg-primary);color:var(--text-primary)}
        
        /* REVERTING TO ORIGINAL SCROLL UX */
        main{height:calc(100% - 64px);overflow-y:auto}
        
        #neural-canvas{position:fixed;top:0;left:0;width:100%;height:100%;z-index:-1;pointer-events:none !important;}.glass-container{background:var(--glass-bg);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border:1px solid var(--glass-border)}
        
        .kanban-column {
            background: var(--column-bg);
            border-radius: 14px;
            border: 1.5px solid rgba(255, 255, 255, 0.04);
            padding: 8px !important;
            min-height: 120px;
            transition: border-color 0.18s cubic-bezier(0.16, 1, 0.3, 1), 
                        box-shadow 0.18s cubic-bezier(0.16, 1, 0.3, 1), 
                        background-color 0.18s cubic-bezier(0.16, 1, 0.3, 1);
        }
        html.light .kanban-column {
            border-color: rgba(0, 0, 0, 0.06);
        }
        
        /* Emil Design Eng + Better UI: Drag Hover Outline & Glow Effect */
        .kanban-column:has(.sortable-ghost),
        .kanban-column.is-drag-over {
            border-color: rgba(59, 130, 246, 0.65) !important;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25), inset 0 0 24px rgba(59, 130, 246, 0.08) !important;
            background-color: rgba(59, 130, 246, 0.04) !important;
        }
        html.light .kanban-column:has(.sortable-ghost),
        html.light .kanban-column.is-drag-over {
            border-color: rgba(37, 99, 235, 0.55) !important;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.18), inset 0 0 24px rgba(37, 99, 235, 0.04) !important;
            background-color: rgba(239, 246, 255, 0.8) !important;
        }

        .kanban-column-header {
            position: sticky; 
            top: 0;          
            z-index: 10;     
            padding: 4px 0 8px 0; 
            margin-bottom: 0 !important;
            background: transparent; 
        }
        .column-pill-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            background-color: var(--title-pill-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 7px 10px;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            transition: border-color 0.18s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.18s;
        }
        .count-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 9999px;
            background: rgba(0, 0, 0, 0.25);
            color: var(--text-header);
            border: 1px solid rgba(255, 255, 255, 0.06);
            font-variant-numeric: tabular-nums;
        }
        html.light .count-pill {
            background: rgba(0, 0, 0, 0.06);
            border-color: rgba(0, 0, 0, 0.06);
        }
        
        .task-card {
            position: relative;
            cursor: grab;
            border-radius: 12px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            transition: transform 0.18s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.18s, border-color 0.18s;
        }
        .task-card:hover {
            transform: translateY(-2.5px);
            box-shadow: 0 10px 24px -4px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(59, 130, 246, 0.3);
            border-color: rgba(59, 130, 246, 0.4);
        }
        .task-card:active {
            cursor: grabbing;
            transform: scale(0.985);
        }
        .sortable-chosen {
            transform: rotate(1.2deg) scale(1.02);
            box-shadow: 0 20px 30px rgba(0, 0, 0, 0.35);
            z-index: 50;
        }
        .sortable-ghost {
            opacity: 0.45;
            background: rgba(59, 130, 246, 0.08) !important;
            border: 1.5px dashed #3b82f6 !important;
            border-radius: 12px !important;
        }
        html.light .sortable-ghost {
            background: rgba(59, 130, 246, 0.05) !important;
            border: 1.5px dashed #2563eb !important;
        }
        .card-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 6px;
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
            gap: 4px;
            padding: 8px 10px;
            margin: 4px 0;
            border-radius: 9px;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 11.5px;
            line-height: 1.35;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        html.light .build-specs-box {
            background: rgba(0, 0, 0, 0.035);
            border-color: rgba(0, 0, 0, 0.06);
        }
        
        .themed-input{background-color:var(--input-bg);border:1px solid var(--input-border);color:var(--input-text)}.badge{display:inline-block;padding:.25rem .6rem;font-size:.75rem;font-weight:500;border-radius:.75rem;line-height:1.2}.badge-color-sky{background-color:rgba(14,165,233,.2);color:#7dd3fc}html.light .badge-color-sky{background-color:#e0f2fe;color:#0369a1}.badge-color-emerald{background-color:rgba(16,185,129,.2);color:#6ee7b7}html.light .badge-color-emerald{background-color:#d1fae5;color:#047857}.badge-color-amber{background-color:rgba(245,158,11,.2);color:#fcd34d}html.light .badge-color-amber{background-color:#fef3c7;color:#92400e}.badge-color-rose{background-color:rgba(244,63,94,.2);color:#fda4af}html.light .badge-color-rose{background-color:#ffe4e6;color:#9f1239}.badge-color-violet{background-color:rgba(139,92,246,.2);color:#c4b5fd}html.light .badge-color-violet{background-color:#ede9fe;color:#5b21b6}.badge-color-teal{background-color:rgba(20,184,166,.2);color:#5eead4}html.light .badge-color-teal{background-color:#ccfbf1;color:#0d9488}.badge-color-cyan{background-color:rgba(6,182,212,.2);color:#67e8f9}html.light .badge-color-cyan{background-color:cffafe;color:#0e7490}
        
        /* Better UI: Pill Shape Badges for Test Plans with distinctive semantic palettes */
        .plan-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
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

        .nav-link{color:var(--text-secondary);border-bottom:2px solid transparent;transition:all .2s}.nav-link:hover{border-color:var(--text-secondary);color:var(--text-primary)}.nav-link-active{color:var(--text-primary)!important;border-bottom:2px solid #3b82f6;font-weight:600}.ql-toolbar,.ql-container{border-color:var(--glass-border)!important}.ql-editor{color:var(--text-primary);min-height:80px}#toast{position:fixed;bottom:-100px;left:50%;transform:translateX(-50%);background-color:var(--toast-bg);color:var(--toast-text);padding:12px 20px;border-radius:8px;z-index:1000;transition:bottom .5s ease-in-out}#toast.show{bottom:30px}
        @keyframes pulse-alert{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.1);opacity:.8}}.animate-pulse-alert{animation:pulse-alert 1.5s infinite}html.light .animate-pulse-alert{color:#dc2626}html.light .font-semibold.text-green-400{color:#15803d}html.light .font-semibold.text-red-400{color:#b91c1c}html.light .font-semibold.text-yellow-400{color:#a16207}
        .sad-emoji{position:fixed;font-size:2rem;animation:fall 5s linear forwards;opacity:1;z-index:9999}@keyframes fall{to{transform:translateY(100vh) rotate(360deg);opacity:0}}
        .accordion-summary{display:none}.view-accordion .accordion-summary{display:block}.view-accordion .task-card-full-content{display:none}
        .view-accordion .task-card.is-expanded .task-card-full-content{display:block; padding-top: 1rem; }
        .view-accordion .task-card.is-expanded .accordion-summary{display:none}.pic-icon{width:20px;height:20px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:700;flex-shrink:0}
        
        .strobe-urgent-effect {
            position: relative;
            border-color: rgba(239, 68, 68, 0.5) !important;
            box-shadow: 0 0 0 1px rgba(239, 68, 68, 0.3), 0 4px 14px rgba(239, 68, 68, 0.12) !important;
            animation: urgent-glow 2.5s ease-in-out infinite;
        }
        @keyframes urgent-glow {
            0%, 100% { 
                border-color: rgba(239, 68, 68, 0.45); 
                box-shadow: 0 0 0 1px rgba(239, 68, 68, 0.25), 0 4px 12px rgba(239, 68, 68, 0.1); 
            }
            50% { 
                border-color: rgba(239, 68, 68, 0.85); 
                box-shadow: 0 0 0 1.5px rgba(239, 68, 68, 0.55), 0 4px 20px rgba(239, 68, 68, 0.22); 
            }
        }
        .modal-content-wrapper { 
            background: var(--modal-bg); 
            border: 1px solid var(--modal-border); 
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .glow-effect { animation: glow 1.5s infinite alternate; border-radius: 0.5rem; }
        @keyframes glow {
            from { box-shadow: 0 0 2px #3b82f6, 0 0 4px #3b82f6, 0 0 6px #3b82f6; }
            to { box-shadow: 0 0 4px #60a5fa, 0 0 8px #60a5fa, 0 0 12px #60a5fa; }
        }
        
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
    </style>
</head>
<body class="h-screen flex flex-col">
    <canvas id="neural-canvas"></canvas>
    <div id="toast"></div>

    <?php include 'header.php'; ?>

    <main class="w-full p-4 sm:p-6 lg:p-8 flex-grow">
        <!-- MODIFIKASI: Menggunakan grid-cols-8 untuk menampung seluruh status tanpa wrap pada layar lebar (Passed disembunyikan) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-8 gap-2.5 h-full">
            <?php foreach ($statuses as $status): ?>
            <div class="flex flex-col">
                
                <div class="kanban-column-header">
                    <div class="column-pill-header">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="w-2 h-2 rounded-full <?= getStatusDotColor($status) ?> flex-shrink-0"></span>
                            <span class="text-xs font-bold text-header truncate"><?= htmlspecialchars($status) ?></span>
                        </div>
                        <span class="count-pill count-<?= str_replace(' ', '', $status) ?>">
                            <?= count($tasksToDisplay[$status]) ?>
                        </span>
                    </div>
                </div>
                <!-- REVERTING TO INDEPENDENT COLUMN SCROLLING -->
                <div id="status-<?= str_replace(' ', '', $status) ?>" data-status="<?= $status ?>" class="kanban-column space-y-3 h-full overflow-y-auto">
                    <?php foreach ($tasksToDisplay[$status] as $task): ?>
                    <?php
                        $cardClasses = 'task-card flex flex-col';
                        if ($task['is_urgent'] == 1) $cardClasses .= ' strobe-urgent-effect';

                        $ap_version = trim($task['ap'] ?? '');
                        $cp_version = trim($task['cp'] ?? '');
                        $cp_class = (!empty($ap_version) && !empty($cp_version) && $ap_version !== $cp_version) ? 'text-red-400 font-bold glow-highlight-red' : '';
                        $marketing_title = !empty($task['project_name']) ? $task['project_name'] : (function_exists('get_marketing_name') ? get_marketing_name($task['model_name']) : $task['model_name']);
                    ?>
                    <div id="task-<?= $task['id'] ?>" data-id="<?= $task['id'] ?>" data-task='<?= json_encode($task, JSON_HEX_APOS | JSON_HEX_QUOT) ?>' class="<?= $cardClasses ?>">
                        <div class="task-card-inner p-3.5">
                            <!-- Compact Summary (when in accordion summary mode) -->
                            <div class="accordion-summary">
                               <!-- Full-Width Big AP Version -->
                               <div class="mb-1">
                                    <h4 class="font-extrabold text-[14px] sm:text-[15px] text-primary font-mono tracking-tight break-all select-all w-full leading-snug" title="<?= htmlspecialchars($task['ap'] ?: 'N/A') ?>">
                                        <?= htmlspecialchars($task['ap'] ?: 'N/A') ?>
                                    </h4>
                               </div>
                               <!-- Low Opacity / Samar Marketing Name & PIC Avatar -->
                               <div class="flex justify-between items-center gap-1.5 mt-0.5 mb-1.5">
                                    <p class="text-[11px] text-secondary opacity-50 font-normal tracking-tight truncate flex-1 min-w-0" title="<?= htmlspecialchars($marketing_title) ?>">
                                        <?= htmlspecialchars($marketing_title) ?>
                                    </p>
                                    <?php if (!empty($task['profile_picture']) && $task['profile_picture'] !== 'default.png'): ?>
                                        <img src="uploads/<?= htmlspecialchars($task['profile_picture']) ?>" alt="PIC" class="w-4 h-4 rounded-full object-cover flex-shrink-0 shadow-sm" title="<?= htmlspecialchars($task['pic_email']) ?>">
                                    <?php else: ?>
                                        <div class="pic-icon <?= getPicBadgeColor($task['pic_email']) ?> flex-shrink-0 shadow-sm !w-4 !h-4 !text-[9px]" title="<?= htmlspecialchars($task['pic_email']) ?>"><?= getPicInitials($task['pic_email']) ?></div>
                                    <?php endif; ?>
                               </div>
                               <div class="card-kinerja-container pt-1.5 border-t border-[var(--glass-border)] space-y-1">
                                    <?= render_kinerja_status($task) ?>
                                </div>
                                <!-- Fullwidth Test Plan Pill at Footer on Collapse -->
                                <div class="mt-1.5 pt-1.5 border-t border-[var(--glass-border)]">
                                    <div class="w-full <?= getTestPlanBadgeClass($task['test_plan_type'] ?? '') ?> py-0.5 px-2 text-[9.5px] text-center font-bold tracking-wider shadow-sm rounded-md">
                                        <?= htmlspecialchars($task['test_plan_type'] ?: 'NO PLAN') ?>
                                    </div>
                                </div>
                            </div>
                            <!-- Full Content -->
                            <div class="task-card-full-content flex flex-col">
                                <!-- Top Full-Width Action Header with Separator Line -->
                                <div class="flex justify-between items-center pb-1.5 mb-2 border-b border-[var(--glass-border)]">
                                    <span class="text-[10px] font-mono font-semibold text-secondary/70 tracking-wider">#<?= $task['id'] ?></span>
                                    <div class="flex items-center gap-1">
                                        <button onclick="toggleUrgent(event, this, <?= $task['id'] ?>)" title="Tandai sebagai Urgent" class="card-action-btn <?= $task['is_urgent'] ? 'text-red-400 bg-red-500/15' : 'text-icon hover:text-red-400' ?>">
                                            <svg class="h-3.5 w-3.5 <?= $task['is_urgent'] ? 'fill-red-400' : '' ?>" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg>
                                        </button>
                                        <button onclick='openEditModal(event, this)' title="Edit Task" class="card-action-btn text-icon hover:text-blue-400">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z" /><path fill-rule="evenodd" d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" clip-rule="evenodd" /></svg>
                                        </button>
                                        <?php if (is_admin()): ?>
                                            <form action="handler.php" method="POST" onsubmit="return confirm('Yakin ingin menghapus task ini?');" class="inline m-0"><input type="hidden" name="action" value="delete_gba_task"><input type="hidden" name="id" value="<?= $task['id'] ?>"><button type="submit" title="Hapus Task" class="card-action-btn text-icon hover:text-rose-500"><svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm4 0a1 1 0 012 0v6a1 1 0 11-2 0V8z" clip-rule="evenodd" /></svg></button></form>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Full-Width Title & Model Name -->
                                <div class="mb-2">
                                    <h3 class="font-bold text-xs leading-snug text-card-title marketing-name truncate" title="<?= htmlspecialchars($marketing_title) ?>">
                                        <?= htmlspecialchars($marketing_title) ?>
                                    </h3>
                                    <p class="text-[11px] font-mono font-medium text-secondary model-name mt-0.5 truncate" title="<?= htmlspecialchars($task['model_name']) ?>"><?= htmlspecialchars($task['model_name']) ?></p>
                                </div>

                                <!-- Build Specs Box: AP, CP, CSC -->
                                <div class="build-specs-box">
                                    <div class="flex items-center justify-between"><span class="text-secondary/70">AP</span><span class="font-mono text-primary font-medium select-all"><?= htmlspecialchars($task['ap'] ?: '-') ?></span></div>
                                    <div class="flex items-center justify-between <?= $cp_class ?>"><span class="text-secondary/70">CP</span><span class="font-mono font-medium select-all"><?= htmlspecialchars($task['cp'] ?: '-') ?></span></div>
                                    <div class="flex items-center justify-between"><span class="text-secondary/70">CSC</span><span class="font-mono text-primary font-medium select-all"><?= htmlspecialchars($task['csc'] ?: '-') ?></span></div>
                                </div>

                                <!-- Timeline & Performance Row -->
                                <div class="card-kinerja-container py-2 my-0.5 border-y border-[var(--glass-border)] space-y-1.5">
                                    <?= render_kinerja_status($task) ?>
                                </div>

                                <!-- Footer: PIC & Test Plan (Swapped) -->
                                <div class="flex justify-between items-center pt-2">
                                    <div class="flex items-center gap-1.5 flex-shrink-0 min-w-0">
                                        <?php if (!empty($task['profile_picture']) && $task['profile_picture'] !== 'default.png'): ?>
                                            <img src="uploads/<?= htmlspecialchars($task['profile_picture']) ?>" alt="PIC" class="w-5 h-5 rounded-full object-cover flex-shrink-0" title="<?= htmlspecialchars($task['pic_email']) ?>">
                                        <?php else: ?>
                                            <div class="pic-icon <?= getPicBadgeColor($task['pic_email']) ?> flex-shrink-0" title="<?= htmlspecialchars($task['pic_email']) ?>"><?= getPicInitials($task['pic_email']) ?></div>
                                        <?php endif; ?>
                                        <span class="badge task-pic <?= getPicBadgeColor($task['pic_email']) ?> truncate" style="font-size:0.65rem;padding:2px 6px;"><?= htmlspecialchars(explode('@', $task['pic_email'])[0]) ?></span>
                                    </div>
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-secondary/80 truncate max-w-[120px] text-right flex-shrink-0" title="<?= htmlspecialchars($task['test_plan_type'] ?? '') ?>">
                                        <?= htmlspecialchars($task['test_plan_type'] ?? '') ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </main>
    
    <div id="task-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/65 backdrop-blur-sm hidden">
        <div class="modal-content-wrapper rounded-2xl shadow-2xl p-4 sm:p-5 w-full max-w-5xl mx-3">
            <form id="task-form" action="handler.php" method="POST">
                <div class="flex justify-between items-center mb-3 pb-2 border-b border-[var(--glass-border)]">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-blue-500 shadow-sm shadow-blue-500/50"></div>
                        <h2 id="modal-title" class="text-base font-bold text-header">Tambah Task Baru</h2>
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
    // --- ANIMATION & THEME LOGIC (KEEPING IMPROVEMENTS) ---
    const canvas = document.getElementById('neural-canvas'), ctx = canvas.getContext('2d');
    let particles = [], hue = 210;
    
    const mouse = { x: undefined, y: undefined, radius: 120 };

    function setCanvasSize(){canvas.width=window.innerWidth;canvas.height=window.innerHeight;}setCanvasSize();

    window.addEventListener('mousemove', function(event) {
        mouse.x = event.clientX;
        mouse.y = event.clientY;
    });

    window.addEventListener('mouseout', function(){
        mouse.x = undefined;
        mouse.y = undefined;
    });
    
    class Particle{
        constructor(x, y){
            this.x = x || Math.random() * canvas.width;
            this.y = y || Math.random() * canvas.height;
            this.vx = (Math.random() - 0.5) * 1.5; 
            this.vy = (Math.random() - 0.5) * 1.5; 
            this.size = Math.random() * 2 + 1;
        }

        update(){
            this.x += this.vx;
            this.y += this.vy;
            if (this.x < 0 || this.x > canvas.width) this.vx *= -1;
            if (this.y < 0 || this.y > canvas.height) this.vy *= -1;
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
        if (mouse.x !== undefined && mouse.y !== undefined) {
            ctx.beginPath();
            let gradient = ctx.createRadialGradient(mouse.x, mouse.y, 0, mouse.x, mouse.y, mouse.radius);
            gradient.addColorStop(0, `hsla(${hue}, 100%, 70%, 0.15)`);
            gradient.addColorStop(1, 'transparent');
            ctx.fillStyle = gradient;
            ctx.arc(mouse.x, mouse.y, mouse.radius, 0, Math.PI * 2);
            ctx.fill();
        }

        for(let i = 0; i < particles.length; i++) {
            particles[i].update();
            particles[i].draw();

            if (mouse.x !== undefined && mouse.y !== undefined) {
                const dxM = particles[i].x - mouse.x;
                const dyM = particles[i].y - mouse.y;
                const distM = Math.sqrt(dxM * dxM + dyM * dyM);
                if (distM < mouse.radius) {
                    ctx.beginPath();
                    ctx.strokeStyle = `hsla(${hue}, 100%, 70%, ${1 - distM / mouse.radius})`;
                    ctx.lineWidth = 0.8;
                    ctx.moveTo(mouse.x, mouse.y);
                    ctx.lineTo(particles[i].x, particles[i].y);
                    ctx.stroke();
                }
            }

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
        hue = (hue + 1.0) % 360; 
        handleParticles();
        requestAnimationFrame(animate);
    }
    
    const particleCount = window.innerWidth > 768 ? 150 : 70;
    init(particleCount);
    animate();
    
    // --- PAGE SPECIFIC LOGIC ---
    const modal = document.getElementById('task-modal'), 
          modalTitle = document.getElementById('modal-title'), 
          taskForm = document.getElementById('task-form'), 
          searchInput = document.getElementById('search-input'), 
          viewToggleBtn = document.getElementById('view-toggle'), 
          mainContainer = document.querySelector('main'); 
    let quill;
    
    window.addEventListener('resize', () => { setCanvasSize(); init(particleCount); });

    function showToast(message, isSuccess = true) { 
        const toast = document.getElementById('toast'); 
        if (!toast) return;
        toast.textContent = message; 
        toast.style.backgroundColor = isSuccess ? 'var(--toast-bg)' : '#ef4444'; 
        toast.classList.add('show'); 
        setTimeout(() => toast.classList.remove('show'), 3000); 
    }

    function triggerConfetti() { 
        if (typeof confetti !== 'function') return;
        const duration = 2.5 * 1000, animationEnd = Date.now() + duration, defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 9999 }; 
        function randomInRange(min, max) { return Math.random() * (max - min) + min; } 
        const interval = setInterval(function () { 
            const timeLeft = animationEnd - Date.now(); 
            if (timeLeft <= 0) return clearInterval(interval); 
            const particleCount = 50 * (timeLeft / duration); 
            confetti({ ...defaults, particleCount, origin: { x: randomInRange(0.1, 0.3), y: Math.random() - 0.2 } }); 
            confetti({ ...defaults, particleCount, origin: { x: randomInRange(0.7, 0.9), y: Math.random() - 0.2 } }); 
        }, 250); 
    }

    function triggerSadAnimation() { 
        for (let i = 0; i < 30; i++) { 
            const e = document.createElement('div'); 
            e.className = 'sad-emoji'; 
            e.innerText = '😢'; 
            e.style.left = `${Math.random() * 100}vw`; 
            e.style.animationDelay = `${Math.random() * 2}s`; 
            document.body.appendChild(e); 
            setTimeout(() => e.remove(), 5000); 
        } 
    }

    function showAlertModal(title, message) { 
        const existingModal = document.getElementById('alert-modal'); 
        if (existingModal) existingModal.remove(); 
        const modalHtml = `<div id="alert-modal" class="fixed inset-0 z-[100] flex items-center justify-center bg-black bg-opacity-70" onclick="this.remove()"><div class="glass-container rounded-lg shadow-xl p-6 w-full max-w-sm mx-4" onclick="event.stopPropagation()"><h2 class="text-xl font-bold text-header mb-4">${title}</h2><p class="text-secondary mb-6">${message}</p><div class="flex justify-end"><button onclick="document.getElementById('alert-modal').remove()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Tutup</button></div></div></div>`; 
        document.body.insertAdjacentHTML('beforeend', modalHtml); 
    }
    
    function openAddModal() {
        if (!taskForm) return;
        taskForm.reset();
        if (modalTitle) modalTitle.innerText = 'Tambah Task Baru';
        if (taskForm.elements['action']) taskForm.elements['action'].value = 'create_gba_task';
        if (taskForm.elements['id']) taskForm.elements['id'].value = '';
        const today = new Date().toISOString().slice(0, 10);
        const reqDateEl = document.getElementById('request_date');
        const deadEl = document.getElementById('deadline');
        const signEl = document.getElementById('sign_off_date');
        if (reqDateEl) reqDateEl.value = today;
        const deadlineDate = calculateWorkingDays(today, 7);
        if (deadEl) deadEl.value = deadlineDate;
        if (signEl) signEl.value = deadlineDate;
        setupQuill('');
        updateChecklistVisibility();
        if (modal) modal.classList.remove('modal-closing', 'hidden');
    }

    function openEditModal(e, target) { 
        if (e && typeof e.stopPropagation === 'function') e.stopPropagation();
        else if (window.event && window.event.stopPropagation) window.event.stopPropagation();

        if (!target) return;
        const card = target.classList && target.classList.contains('task-card') ? target : target.closest('.task-card');
        if (!card || !taskForm) return;
        const taskData = JSON.parse(card.getAttribute('data-task')); 
        taskForm.reset(); 
        if (modalTitle) modalTitle.innerText = 'Edit Task'; 
        if (taskForm.elements['action']) taskForm.elements['action'].value = 'update_gba_task'; 
        for (const key in taskData) { 
            if (taskForm.elements[key] && !key.endsWith('_obj')) { 
                if (key === 'is_urgent') { 
                    const urgToggle = document.getElementById('is_urgent_toggle');
                    if (urgToggle) urgToggle.checked = taskData[key] == 1; 
                } else { 
                    taskForm.elements[key].value = taskData[key]; 
                } 
            } 
        } 
        setupQuill(taskData.notes || ''); 
        updateChecklistVisibility(); 
        if (taskData.test_items_checklist) { 
            try { 
                const checklist = JSON.parse(taskData.test_items_checklist); 
                const visibleContainer = document.querySelector('[id^="checklist-container-"]:not(.hidden)');
                if (visibleContainer) {
                    for (const itemName in checklist) { 
                        const checkbox = visibleContainer.querySelector(`input[name="checklist[${itemName}]"]`); 
                        if (checkbox) checkbox.checked = !!checklist[itemName]; 
                    } 
                }
            } catch (err) { console.error("Gagal parse checklist JSON:", err); } 
        } 
        if (modal) modal.classList.remove('modal-closing', 'hidden'); 
    }
    
    // Emil Design Eng + Ponytail: Quick Action Right-Click on Kanban Card directly opens Modal
    document.addEventListener('contextmenu', function(e) {
        const card = e.target.closest('.task-card');
        if (!card) return;
        
        // Ignore right click inside modal or on action buttons/forms
        if (e.target.closest('#task-modal') || e.target.closest('.card-action-btn') || e.target.closest('form')) {
            return;
        }
        
        e.preventDefault();
        
        // Tactile micro-animation feedback on right click
        card.style.transform = 'scale(0.98)';
        setTimeout(() => {
            card.style.transform = '';
            openEditModal(e, card);
        }, 50);
    });
    
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
        }
    });
    
    function setupQuill(content) { 
        const notesEditor = document.getElementById('notes-editor');
        if (!notesEditor) return;
        if (!quill && typeof Quill === 'function') { 
            quill = new Quill('#notes-editor', { theme: 'snow', modules: { toolbar: [['bold', 'italic'], ['link'], [{ 'list': 'ordered' }, { 'list': 'bullet' }]] } }); 
        } 
        if (quill) quill.root.innerHTML = content; 
    }

    if (taskForm) {
        taskForm.addEventListener('submit', () => { 
            const notesInput = document.getElementById('notes-hidden-input');
            if (notesInput && quill) notesInput.value = quill.root.innerHTML; 
        });
        taskForm.addEventListener('change', e => {
            if (e.target.matches('input[type="checkbox"][name^="checklist"]')) {
                const progSelect = document.getElementById('progress_status');
                if (progSelect && progSelect.value !== 'Approved' && progSelect.value !== 'Submitted') {
                    progSelect.value = 'Test Ongoing';
                }
            }
        });
    }

    const testPlanSelect = document.getElementById('test_plan_type');
    if (testPlanSelect) {
        testPlanSelect.addEventListener('change', updateChecklistVisibility);
    }

    function updateChecklistVisibility() { 
        const testPlanEl = document.getElementById('test_plan_type');
        const testPlan = testPlanEl ? testPlanEl.value : '';
        const placeholder = document.getElementById('checklist-placeholder'); 
        let checklistVisible = false; 
        document.querySelectorAll('[id^="checklist-container-"]').forEach(el => { 
            const planName = el.id.replace('checklist-container-', '').replace(/_/g, ' '); 
            if (planName === testPlan) { 
                el.classList.remove('hidden'); 
                checklistVisible = true; 
            } else { 
                el.classList.add('hidden'); 
            } 
        }); 
        if (placeholder) placeholder.style.display = checklistVisible ? 'none' : 'block'; 
    }

    function toggleUrgent(e, button, taskId) { 
        if (e && typeof e.stopPropagation === 'function') e.stopPropagation();
        else if (window.event && window.event.stopPropagation) window.event.stopPropagation();

        fetch('handler.php', { 
            method: 'POST', 
            headers: { 'Content-Type': 'application/json' }, 
            body: JSON.stringify({ action: 'toggle_urgent', task_id: taskId }) 
        })
        .then(response => response.json())
        .then(data => { 
            if (data.success) { 
                showToast('Status urgent diperbarui'); 
                const card = document.getElementById(`task-${taskId}`); 
                const icon = button.querySelector('svg'); 
                if (card) card.classList.toggle('strobe-urgent-effect', data.is_urgent); 
                if (icon) icon.classList.toggle('text-red-400', data.is_urgent); 
                button.classList.toggle('bg-red-500/15', data.is_urgent);
            } else { 
                showAlertModal('Gagal', data.error || 'Gagal memperbarui status urgent.'); 
            } 
        })
        .catch(() => showAlertModal('Error', 'Kesalahan jaringan.')); 
    }

    const progressStatusSelect = document.getElementById('progress_status'), 
          submissionDateInput = document.getElementById('submission_date'), 
          approvedDateInput = document.getElementById('approved_date'), 
          requestDateInput = document.getElementById('request_date'), 
          deadlineInput = document.getElementById('deadline'), 
          signOffDateInput = document.getElementById('sign_off_date');

    function calculateWorkingDays(startDate, daysToAdd) { 
        let currentDate = new Date(startDate); 
        let addedDays = 0; 
        while (addedDays < daysToAdd) { 
            currentDate.setDate(currentDate.getDate() + 1); 
            if (currentDate.getDay() !== 0 && currentDate.getDay() !== 6) { addedDays++; } 
        } 
        return currentDate.toISOString().slice(0, 10); 
    }

    function getTodayDate() { return new Date().toISOString().slice(0, 10); }

    function checkAllVisibleCheckboxes(checked = true) {
        const visibleChecklist = document.querySelector('[id^="checklist-container-"]:not(.hidden)');
        if (visibleChecklist) {
            visibleChecklist.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                cb.checked = checked;
            });
        }
    }

    if (requestDateInput) {
        requestDateInput.addEventListener('change', () => {
            if (requestDateInput.value) {
                const futureDate = calculateWorkingDays(requestDateInput.value, 7);
                if (deadlineInput) deadlineInput.value = futureDate;
                if (signOffDateInput) signOffDateInput.value = futureDate;
            }
        });
    }

    if (progressStatusSelect) {
        progressStatusSelect.addEventListener('change', e => {
            const status = e.target.value;
            if (status === 'Submitted' || status === 'Approved' || status === 'Passed') {
                if (submissionDateInput && !submissionDateInput.value) { submissionDateInput.value = getTodayDate(); }
                if (status === 'Approved' || status === 'Passed') {
                    if (approvedDateInput && !approvedDateInput.value) { approvedDateInput.value = getTodayDate(); }
                }
                checkAllVisibleCheckboxes(true);
            } else if (status === 'Task Baru') {
                checkAllVisibleCheckboxes(false);
                if (submissionDateInput) submissionDateInput.value = '';
                if (approvedDateInput) approvedDateInput.value = '';
            }
        });
    }

    function updateCardKinerja(card, task) {
        let html = '';
        if (task.progress_status === 'Batal') {
            html = `<div class="flex items-center justify-between text-[11.5px] leading-snug"><span class="text-secondary">Status:</span><span class="font-semibold text-secondary">Batal</span></div>`;
        } else {
            html += '<div class="flex items-center justify-between text-[11.5px] leading-snug"><span class="text-secondary">Submission:</span>';
            if (task.ontime_submission_status) {
                const colorClass = task.ontime_submission_status === 'Delay' ? 'text-red-400' : 'text-green-400';
                html += `<span class="font-semibold ${colorClass}">${task.ontime_submission_status}</span>`;
            } else if (task.deadline_countdown !== null && task.deadline_countdown !== undefined) {
                const days = parseInt(task.deadline_countdown);
                const colorClass = days < 0 ? 'text-red-400' : (days <= 3 ? 'text-yellow-400' : 'text-secondary');
                const iconHtml = (days <= 3 && days >= 0) ? '<svg class="w-3.5 h-3.5 animate-pulse-alert inline" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg>' : '';
                const text = days >= 0 ? `${days} hari lagi` : 'Lewat ' + Math.abs(days) + ' hari';
                html += `<span class="flex items-center gap-1 font-semibold ${colorClass}">${iconHtml}${text}</span>`;
            } else {
                html += '<span class="text-secondary">-</span>';
            }
            html += '</div>';

            html += '<div class="flex items-center justify-between text-[11.5px] leading-snug"><span class="text-secondary">Approval:</span>';
            if (task.ontime_approved_status) {
                const colorClass = task.ontime_approved_status === 'Delay' ? 'text-red-400' : 'text-green-400';
                html += `<span class="font-semibold ${colorClass}">${task.ontime_approved_status}</span>`;
            } else if (task.approval_countdown !== null && task.approval_countdown !== undefined) {
                const days = parseInt(task.approval_countdown);
                const colorClass = days < 0 ? 'text-red-400' : (days <= 1 ? 'text-yellow-400' : 'text-secondary');
                const iconHtml = (days <= 1 && days >= 0) ? '<svg class="w-3.5 h-3.5 animate-pulse-alert inline" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 100-2 1 1 0 000 2zm-1-8a1 1 0 011-1h.008a1 1 0 011 1v3.008a1 1 0 01-1 1H9a1 1 0 01-1-1V5z" clip-rule="evenodd" /></svg>' : '';
                const text = days >= 0 ? `${days} hari lagi` : 'Lewat ' + Math.abs(days) + ' hari';
                html += `<span class="flex items-center gap-1 font-semibold ${colorClass}">${iconHtml}${text}</span>`;
            } else {
                html += '<span class="text-secondary">-</span>';
            }
            html += '</div>';
        }
        card.querySelectorAll('.card-kinerja-container').forEach(div => {
            div.innerHTML = html;
        });
    }

    function initKanbanBoard() {
        if (viewToggleBtn && mainContainer) { 
            const fullIcon = document.getElementById('view-toggle-full-icon'); 
            const accordionIcon = document.getElementById('view-toggle-accordion-icon'); 
            function applyViewMode(mode) { 
                mainContainer.classList.toggle('view-accordion', mode === 'accordion'); 
                if (fullIcon) fullIcon.classList.toggle('hidden', mode === 'accordion'); 
                if (accordionIcon) accordionIcon.classList.toggle('hidden', mode !== 'accordion'); 
                localStorage.setItem('viewMode', mode); 
            } 
            viewToggleBtn.addEventListener('click', () => { 
                const currentMode = mainContainer.classList.contains('view-accordion') ? 'full' : 'accordion'; 
                applyViewMode(currentMode); 
            }); 
            const savedViewMode = localStorage.getItem('viewMode') || 'full'; 
            applyViewMode(savedViewMode); 
            
            mainContainer.addEventListener('click', function (e) { 
                if (mainContainer.classList.contains('view-accordion')) { 
                    if (e.target.closest('button, a, input, select, textarea, form, .card-action-btn')) {
                        return;
                    }
                    const card = e.target.closest('.task-card'); 
                    if (card) { 
                        card.classList.toggle('is-expanded'); 
                    } 
                } 
            }); 
        }

        const columns = document.querySelectorAll('.kanban-column');
        if (typeof Sortable === 'function') {
            columns.forEach(column => {
                new Sortable(column, {
                    group: 'kanban',
                    animation: 180,
                    ghostClass: 'sortable-ghost',
                    chosenClass: 'sortable-chosen',
                    dragClass: 'sortable-drag',
                    emptyInsertThreshold: 15,
                    fallbackTolerance: 3,
                    onStart: function () {
                        document.body.classList.add('is-dragging-card');
                    },
                    onEnd: function (evt) {
                        document.body.classList.remove('is-dragging-card');
                        document.querySelectorAll('.kanban-column').forEach(c => c.classList.remove('is-drag-over'));
                        
                        const card = evt.item;
                        const taskId = card.dataset.id;
                        const newStatus = evt.to.dataset.status;

                        if (newStatus === 'Approved') { triggerConfetti(); }
                        if (newStatus === 'Batal') { triggerSadAnimation(); }

                        fetch('handler.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'update_task_status', task_id: taskId, new_status: newStatus })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                showToast(`Status task #${taskId} diperbarui`);
                                card.setAttribute('data-task', JSON.stringify(data.task));
                                updateCardKinerja(card, data.task);
                                updateColumnCounts();
                            } else {
                                showAlertModal('Gagal Update', data.error || 'Gagal memperbarui status task.');
                                evt.from.appendChild(card);
                                updateColumnCounts();
                            }
                        })
                        .catch(() => {
                            showAlertModal('Error', 'Terjadi kesalahan jaringan.');
                            evt.from.appendChild(card);
                            updateColumnCounts();
                        });
                    }
                });
            });
        }

        function updateColumnCounts() { 
            columns.forEach(column => { 
                const status = column.dataset.status.replace(/[\s\/]/g, '');
                const count = column.querySelectorAll('.task-card:not(.hidden)').length;
                const countElement = document.querySelector(`.count-${status}`); 
                if (countElement) countElement.textContent = count; 
            }); 
        }

        if (searchInput) { 
            searchInput.addEventListener('input', () => { 
                const searchTerm = searchInput.value.toLowerCase(); 
                document.querySelectorAll('.task-card').forEach(card => { 
                    const cardContent = card.textContent.toLowerCase(); 
                    card.classList.toggle('hidden', !cardContent.includes(searchTerm)); 
                }); 
                updateColumnCounts(); 
            }); 
        } 
        updateColumnCounts();

        const urlParams = new URLSearchParams(window.location.search);
        const error = urlParams.get('error'); 
        if (error === 'permission_denied') { 
            showAlertModal('Akses Ditolak', 'Anda tidak memiliki izin untuk melakukan tindakan ini.'); 
            window.history.replaceState({}, document.title, window.location.pathname); 
        }

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
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initKanbanBoard);
    } else {
        initKanbanBoard();
    }
</script>
</body>
</html>