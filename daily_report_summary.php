<?php
// ponytail: Daily Report Summary Insight - High Performance, Clean Minimal Analytics
require_once "config.php";
require_once "session.php";
require_once "marketing_name_mapper.php";

$active_page = 'daily_report_summary';

// 1. DATA RETRIEVAL
$sql = "SELECT t.*, u.username, u.profile_picture 
        FROM gba_tasks t 
        LEFT JOIN users u ON t.pic_email = u.email 
        ORDER BY t.deadline ASC, t.id DESC";
$res = $conn->query($sql);

$all_tasks = [];
if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $all_tasks[] = $row;
    }
}

// 2. ANALYTICS & INSIGHT COMPUTATION
$today_str = date('Y-m-d');
$today_dt = new DateTime($today_str);

$active_tasks = [];
$completed_tasks = [];
$late_tasks = [];
$due_soon_tasks = [];

$status_dist = [
    'Task Baru' => 0,
    'Downloaded' => 0,
    'Test Ongoing' => 0,
    'Pending Feedback' => 0,
    'Feedback Sent' => 0,
    'Submitted' => 0,
    'Approved' => 0,
    'Batal' => 0
];

$test_plan_dist = [];
$pic_load_dist = [];
$deadline_load_dist = [];

$total_active = 0;
$ongoing_count = 0;
$submitted_count = 0;
$pending_count = 0;

foreach ($all_tasks as &$task) {
    $st = $task['progress_status'] ?? 'Task Baru';
    if (!isset($status_dist[$st])) {
        $status_dist[$st] = 0;
    }
    $status_dist[$st]++;

    $is_completed = in_array($st, ['Approved', 'Passed', 'Batal']);
    
    // Countdown days left
    $days_left = null;
    $deadline_badge_type = 'normal';
    $deadline_badge_text = '-';

    if (!empty($task['deadline'])) {
        $dl_dt = new DateTime($task['deadline']);
        $diff = $today_dt->diff($dl_dt);
        $days_left = ($today_dt <= $dl_dt) ? $diff->days : -$diff->days;

        if ($days_left < 0) {
            $deadline_badge_type = 'late';
            $deadline_badge_text = 'Late ' . abs($days_left) . ' hari';
        } elseif ($days_left === 0) {
            $deadline_badge_type = 'today';
            $deadline_badge_text = 'Hari ini (H-0)';
        } elseif ($days_left <= 3) {
            $deadline_badge_type = 'urgent';
            $deadline_badge_text = $days_left . ' hari lagi';
        } else {
            $deadline_badge_type = 'safe';
            $deadline_badge_text = $days_left . ' hari lagi';
        }
    }

    $task['days_left'] = $days_left;
    $task['deadline_badge_type'] = $deadline_badge_type;
    $task['deadline_badge_text'] = $deadline_badge_text;
    $task['marketing_name'] = get_marketing_name($task['model_name'] ?? '');

    if (!$is_completed) {
        $total_active++;
        $active_tasks[] = $task;

        if (in_array($st, ['Task Baru', 'Downloaded', 'Test Ongoing'])) {
            $ongoing_count++;
        } elseif ($st === 'Submitted') {
            $submitted_count++;
        } elseif (in_array($st, ['Pending Feedback', 'Feedback Sent'])) {
            $pending_count++;
        }

        // Test Plan count
        $tp = trim($task['test_plan_type'] ?? 'Unassigned');
        if ($tp === '') $tp = 'Unassigned';
        $test_plan_dist[$tp] = ($test_plan_dist[$tp] ?? 0) + 1;

        // PIC Load count
        $pic_name = !empty($task['username']) ? $task['username'] : (!empty($task['pic_email']) ? explode('@', $task['pic_email'])[0] : 'Unassigned');
        $pic_load_dist[$pic_name] = ($pic_load_dist[$pic_name] ?? 0) + 1;

        // Deadline distribution
        if (!empty($task['deadline'])) {
            $deadline_load_dist[$task['deadline']] = ($deadline_load_dist[$task['deadline']] ?? 0) + 1;
            
            if ($days_left < 0) {
                $late_tasks[] = $task;
            } elseif ($days_left >= 0 && $days_left <= 3) {
                $due_soon_tasks[] = $task;
            }
        }
    } else {
        $completed_tasks[] = $task;
    }
}
unset($task);

// Sort distributions
arsort($test_plan_dist);
arsort($pic_load_dist);
ksort($deadline_load_dist);

// 3. GENERATE DYNAMIC INSIGHT QUOTES
$top_deadline_date = '-';
$top_deadline_count = 0;
if (!empty($deadline_load_dist)) {
    $filtered_dl = array_filter($deadline_load_dist, function($cnt, $d) use ($today_str) {
        return $d >= $today_str;
    }, ARRAY_FILTER_USE_BOTH);

    if (!empty($filtered_dl)) {
        $top_deadline_date = array_key_first($filtered_dl);
        $top_deadline_count = $filtered_dl[$top_deadline_date];
        foreach ($filtered_dl as $d => $cnt) {
            if ($cnt > $top_deadline_count) {
                $top_deadline_date = $d;
                $top_deadline_count = $cnt;
            }
        }
    } else {
        $top_deadline_date = array_key_first($deadline_load_dist);
        $top_deadline_count = $deadline_load_dist[$top_deadline_date];
    }
}

$top_pic_name = '-';
$top_pic_count = 0;
if (!empty($pic_load_dist)) {
    $top_pic_name = array_key_first($pic_load_dist);
    $top_pic_count = $pic_load_dist[$top_pic_name];
}

$top_testplan_name = '-';
$top_testplan_count = 0;
if (!empty($test_plan_dist)) {
    $top_testplan_name = array_key_first($test_plan_dist);
    $top_testplan_count = $test_plan_dist[$top_testplan_name];
}

$late_count = count($late_tasks);
$due_soon_count = count($due_soon_tasks);

// Antislop Copywriting: Factual, concise 1-paragraph Executive Narrative
$top_dl_formatted = ($top_deadline_date !== '-') ? date('d M Y', strtotime($top_deadline_date)) : '-';
$executive_narrative_raw = "Hari ini tercatat {$total_active} task aktif dalam pipeline pengujian dengan konsentrasi pengujian terbesar pada kategori {$top_testplan_name} ({$top_testplan_count} task). Beban verifikasi tertinggi saat ini dipegang oleh {$top_pic_name} ({$top_pic_count} task aktif), sementara titik kepadatan penyelesaian terpusat pada tanggal {$top_dl_formatted} ({$top_deadline_count} task). ";

if ($late_count > 0 && $due_soon_count > 0) {
    $executive_narrative_raw .= "Fokus mitigasi operasional mendesak tertuju pada {$late_count} task overdue dan {$due_soon_count} task yang mendekati batas waktu (H-0 hingga H-3) guna menjaga ketepatan jadwal rilis.";
} elseif ($late_count > 0) {
    $executive_narrative_raw .= "Fokus mitigasi operasional mendesak tertuju pada {$late_count} task overdue yang memerlukan tindak lanjut percepatan.";
} elseif ($due_soon_count > 0) {
    $executive_narrative_raw .= "Aktivitas pengujian berjalan on-track dengan pengawalan aktif pada {$due_soon_count} task yang akan jatuh tempo dalam 3 hari ke depan.";
} else {
    $executive_narrative_raw .= "Seluruh aktivitas verifikasi berjalan on-track tanpa adanya penumpukan keterlambatan maupun task kritis.";
}

// Structured Text Report for One-Click Clipboard
$formatted_date_id = date('d F Y');
$clipboard_report = "📊 *DAILY SUMMARY INSIGHT REPORT* - {$formatted_date_id}\n\n";
$clipboard_report .= "📝 *Executive Summary:*\n{$executive_narrative_raw}\n\n";
$clipboard_report .= "🚀 *Pipeline Breakdown:*\n";
$clipboard_report .= "• Total Task Aktif : {$total_active}\n";
$clipboard_report .= "• Test Ongoing     : {$ongoing_count}\n";
$clipboard_report .= "• Submitted        : {$submitted_count}\n";
$clipboard_report .= "• Pending Feedback : {$pending_count}\n";
$clipboard_report .= "• Task Late / Delay: {$late_count}\n";
$clipboard_report .= "• Jatuh Tempo (H0-3): {$due_soon_count}\n\n";

$clipboard_report .= "💡 *Key Insights:*\n";
$clipboard_report .= "• PIC Beban Tertinggi : {$top_pic_name} ({$top_pic_count} task aktif)\n";
$clipboard_report .= "• Test Plan Terbanyak : {$top_testplan_name} ({$top_testplan_count} task)\n";
if ($top_deadline_date !== '-') {
    $clipboard_report .= "• Deadline Terpadat   : " . date('d M Y', strtotime($top_deadline_date)) . " ({$top_deadline_count} task)\n";
}
if ($late_count > 0) {
    $clipboard_report .= "\n⚠️ *Peringatan Task Late ({$late_count}):*\n";
    foreach ($late_tasks as $lt) {
        $p = !empty($lt['username']) ? $lt['username'] : $lt['pic_email'];
        $clipboard_report .= "- [{$lt['model_name']}] {$lt['test_plan_type']} (PIC: {$p}) - Telat " . abs($lt['days_left']) . " hari (DL: " . date('d/m', strtotime($lt['deadline'])) . ")\n";
    }
}
if ($due_soon_count > 0) {
    $clipboard_report .= "\n⏳ *Task Mendekati Deadline ({$due_soon_count}):*\n";
    foreach ($due_soon_tasks as $dt) {
        $p = !empty($dt['username']) ? $dt['username'] : $dt['pic_email'];
        $clipboard_report .= "- [{$dt['model_name']}] {$dt['test_plan_type']} (PIC: {$p}) - Sisa {$dt['days_left']} hari (DL: " . date('d/m', strtotime($dt['deadline'])) . ")\n";
    }
}

function get_pic_badge_class($pic_name) {
    $clean = trim((string)$pic_name);
    if (empty($clean) || $clean === '-') return 'badge-pill badge-slate';
    
    // Curated vibrant high-contrast semantic badge classes for dark and light themes
    $classes = [
        'badge-pill badge-indigo',
        'badge-pill badge-teal',
        'badge-pill badge-emerald',
        'badge-pill badge-sky',
        'badge-pill badge-purple',
        'badge-pill badge-amber',
        'badge-pill badge-rose',
        'badge-pill badge-cyan',
        'badge-pill badge-fuchsia',
        'badge-pill badge-violet',
        'badge-pill badge-orange',
    ];
    $hash = crc32(strtolower($clean));
    return $classes[abs($hash) % count($classes)];
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Report Summary Insight | Project Manager</title>
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['class', '.never-match-dark'],
            theme: {
                extend: {
                    colors: {
                        primary: 'var(--text-primary)',
                        secondary: 'var(--text-secondary)',
                        'card-bg': 'var(--card-bg)',
                        'card-border': 'var(--card-border)',
                        'glass-bg': 'var(--glass-bg)',
                        'glass-border': 'var(--glass-border)',
                        'input-bg': 'var(--input-bg)',
                        'input-border': 'var(--input-border)'
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- Emil-Design-Eng & Better-UI Stylesheet -->
    <style>
        :root { 
            --bg-primary: #020617; 
            --text-primary: #f1f5f9; 
            --text-secondary: #94a3b8; 
            --glass-bg: rgba(15, 23, 42, 0.75); 
            --glass-border: rgba(51, 65, 85, 0.6); 
            --card-bg: rgba(15, 23, 42, 0.7);
            --card-border: rgba(51, 65, 85, 0.55);
            --metric-bg: rgba(30, 41, 59, 0.5);
            --input-bg: rgba(30, 41, 59, 0.75); 
            --input-border: #475569;
            --box-sub-bg: rgba(0, 0, 0, 0.3);
            --box-sub-border: rgba(255, 255, 255, 0.06);
            --table-header-bg: rgba(15, 23, 42, 0.85);
            --table-row-hover: rgba(255, 255, 255, 0.03);
            --alert-late-bg: rgba(239, 68, 68, 0.08);
            --alert-late-border: rgba(239, 68, 68, 0.25);
            --alert-due-bg: rgba(245, 158, 11, 0.08);
            --alert-due-border: rgba(245, 158, 11, 0.25);
            --alert-empty-bg: rgba(0, 0, 0, 0.15);
        }
        html.light { 
            --bg-primary: #f8fafc; 
            --text-primary: #0f172a; 
            --text-secondary: #475569; 
            --glass-bg: rgba(255, 255, 255, 0.9); 
            --glass-border: rgba(0, 0, 0, 0.08); 
            --card-bg: rgba(255, 255, 255, 0.95);
            --card-border: rgba(226, 232, 240, 0.9);
            --metric-bg: rgba(241, 245, 249, 0.8);
            --input-bg: #ffffff; 
            --input-border: #cbd5e1;
            --box-sub-bg: #f8fafc;
            --box-sub-border: #e2e8f0;
            --table-header-bg: #f1f5f9;
            --table-row-hover: rgba(0, 0, 0, 0.025);
            --alert-late-bg: #fef2f2;
            --alert-late-border: #fecaca;
            --alert-due-bg: #fffbeb;
            --alert-due-border: #fde68a;
            --alert-empty-bg: #f1f5f9;
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--bg-primary); 
            color: var(--text-primary); 
            overflow-x: hidden;
            min-height: 100vh;
            transition: background-color 0.2s ease, color 0.2s ease;
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

        .glass-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 18px;
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.2s ease, background 0.2s ease;
        }
        .glass-card:hover {
            border-color: rgba(99, 102, 241, 0.35);
        }
        html.light .glass-card {
            box-shadow: 0 4px 16px -2px rgba(0, 0, 0, 0.04);
        }

        .metric-subcard {
            background: var(--metric-bg);
            border: 1px solid var(--card-border);
            border-radius: 14px;
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.18s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.18s ease, background 0.2s ease;
        }
        .metric-subcard:hover {
            transform: translateY(-2px);
            border-color: rgba(99, 102, 241, 0.4);
        }

        .insight-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        /* Unified High-Contrast Badge & Pill System */
        .badge-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            padding: 2.5px 10px;
            font-size: 10.5px;
            font-weight: 700;
            border-width: 1px;
            letter-spacing: 0.015em;
            white-space: nowrap;
            transition: all 0.15s ease;
        }

        .badge-marketing {
            display: inline-flex;
            align-items: center;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 6px;
            background: rgba(99, 102, 241, 0.18);
            color: #a5b4fc;
            border: 1px solid rgba(99, 102, 241, 0.35);
        }
        html.light .badge-marketing {
            background: #e0e7ff;
            color: #3730a3;
            border-color: #c7d2fe;
        }

        /* Color theme definitions for default dark and light mode */
        .badge-sky { background: rgba(14, 165, 233, 0.16); color: #38bdf8; border-color: rgba(56, 189, 248, 0.35); }
        html.light .badge-sky { background: #e0f2fe; color: #0369a1; border-color: #7dd3fc; font-weight: 800; }

        .badge-blue { background: rgba(59, 130, 246, 0.16); color: #60a5fa; border-color: rgba(96, 165, 250, 0.35); }
        html.light .badge-blue { background: #dbeafe; color: #1d4ed8; border-color: #93c5fd; font-weight: 800; }

        .badge-indigo { background: rgba(99, 102, 241, 0.16); color: #a5b4fc; border-color: rgba(129, 140, 248, 0.35); }
        html.light .badge-indigo { background: #e0e7ff; color: #3730a3; border-color: #a5b4fc; font-weight: 800; }

        .badge-amber { background: rgba(245, 158, 11, 0.16); color: #fbbf24; border-color: rgba(251, 191, 36, 0.35); }
        html.light .badge-amber { background: #fef3c7; color: #92400e; border-color: #fcd34d; font-weight: 800; }

        .badge-orange { background: rgba(249, 115, 22, 0.16); color: #fb923c; border-color: rgba(251, 146, 60, 0.35); }
        html.light .badge-orange { background: #ffedd5; color: #9a3412; border-color: #fdba74; font-weight: 800; }

        .badge-emerald { background: rgba(16, 185, 129, 0.16); color: #34d399; border-color: rgba(52, 211, 153, 0.35); }
        html.light .badge-emerald { background: #d1fae5; color: #065f46; border-color: #6ee7b7; font-weight: 800; }

        .badge-purple { background: rgba(168, 85, 247, 0.16); color: #c084fc; border-color: rgba(192, 132, 252, 0.35); }
        html.light .badge-purple { background: #f3e8ff; color: #6b21a8; border-color: #d8b4fe; font-weight: 800; }

        .badge-fuchsia { background: rgba(217, 70, 239, 0.16); color: #e879f9; border-color: rgba(232, 121, 249, 0.35); }
        html.light .badge-fuchsia { background: #fae8ff; color: #86198f; border-color: #f0abfc; font-weight: 800; }

        .badge-rose { background: rgba(244, 63, 94, 0.16); color: #fb7185; border-color: rgba(251, 113, 133, 0.35); }
        html.light .badge-rose { background: #ffe4e6; color: #9f1239; border-color: #fda4af; font-weight: 800; }

        .badge-teal { background: rgba(20, 184, 166, 0.16); color: #2dd4bf; border-color: rgba(45, 212, 191, 0.35); }
        html.light .badge-teal { background: #ccfbf1; color: #115e59; border-color: #5eead4; font-weight: 800; }

        .badge-cyan { background: rgba(6, 182, 212, 0.16); color: #22d3ee; border-color: rgba(34, 211, 238, 0.35); }
        html.light .badge-cyan { background: #cffafe; color: #155e75; border-color: #67e8f9; font-weight: 800; }

        .badge-violet { background: rgba(139, 92, 246, 0.16); color: #a78bfa; border-color: rgba(167, 139, 250, 0.35); }
        html.light .badge-violet { background: #ede9fe; color: #5b21b6; border-color: #c4b5fd; font-weight: 800; }

        .badge-slate { background: rgba(100, 116, 139, 0.16); color: #cbd5e1; border-color: rgba(148, 163, 184, 0.35); }
        html.light .badge-slate { background: #f1f5f9; color: #334155; border-color: #cbd5e1; font-weight: 800; }

        .badge-countdown {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.02em;
            white-space: nowrap;
            border-width: 1px;
        }
        .badge-countdown.late { background: rgba(239, 68, 68, 0.2); color: #f87171; border-color: rgba(239, 68, 68, 0.4); }
        html.light .badge-countdown.late { background: #fee2e2; color: #991b1b; border-color: #fca5a5; font-weight: 800; }

        .badge-countdown.urgent { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border-color: rgba(245, 158, 11, 0.4); }
        html.light .badge-countdown.urgent { background: #fef3c7; color: #92400e; border-color: #fde68a; font-weight: 800; }

        .badge-countdown.safe { background: rgba(16, 185, 129, 0.15); color: #34d399; border-color: rgba(16, 185, 129, 0.3); }
        html.light .badge-countdown.safe { background: #d1fae5; color: #065f46; border-color: #a7f3d0; font-weight: 700; }

        .badge-countdown.default { background: rgba(100, 116, 139, 0.15); color: #cbd5e1; border-color: rgba(100, 116, 139, 0.3); }
        html.light .badge-countdown.default { background: #f1f5f9; color: #334155; border-color: #cbd5e1; }

        .btn-action-tactile {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
            user-select: none;
        }
        .btn-action-tactile:active {
            transform: scale(0.96);
        }

        /* Emil-Design-Eng Chip Input & Settings Modal */
        .chip-container {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 10px;
            min-height: 44px;
            cursor: text;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .chip-container:focus-within {
            border-color: #38bdf8;
            box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.2);
        }
        .chip-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 6px;
            background: rgba(56, 189, 248, 0.15);
            border: 1px solid rgba(56, 189, 248, 0.35);
            color: #38bdf8;
            font-size: 11px;
            font-weight: 600;
            line-height: 1.2;
            animation: chipPop 0.18s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        html.light .chip-tag {
            background: #e0f2fe;
            border-color: #bae6fd;
            color: #0369a1;
        }
        .chip-tag.invalid {
            background: rgba(239, 68, 68, 0.15);
            border-color: rgba(239, 68, 68, 0.4);
            color: #ef4444;
        }
        .chip-remove {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            cursor: pointer;
            opacity: 0.75;
            transition: opacity 0.1s, transform 0.1s, background 0.1s;
            font-size: 13px;
            line-height: 1;
        }
        .chip-remove:hover {
            opacity: 1;
            transform: scale(1.15);
            background: rgba(0,0,0,0.15);
        }
        .chip-input {
            flex: 1 1 140px;
            min-width: 120px;
            border: none;
            outline: none;
            background: transparent;
            color: var(--text-primary);
            font-size: 12px;
            padding: 3px 2px;
        }
        .chip-input::placeholder {
            color: var(--text-secondary);
            opacity: 0.65;
        }

        @keyframes chipPop {
            0% { transform: scale(0.85); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }

        /* Modal Transitions */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.68);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .modal-backdrop.active {
            opacity: 1;
            pointer-events: auto;
        }
        .modal-dialog {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
            border-radius: 16px;
            width: 100%;
            max-width: 580px;
            transform: scale(0.95) translateY(8px);
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            overflow: hidden;
        }
        .modal-backdrop.active .modal-dialog {
            transform: scale(1) translateY(0);
        }

        /* Tactile Toast */
        .toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }
        .toast-item {
            pointer-events: auto;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 12px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(12px);
            max-width: 360px;
            font-size: 12px;
            transform: translateY(20px) scale(0.95);
            opacity: 0;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .toast-item.active {
            transform: translateY(0) scale(1);
            opacity: 1;
        }

        /* Custom scrollbar */
        .custom-scroll::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }
        .custom-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-scroll::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.25);
            border-radius: 4px;
        }
    </style>
</head>
<body class="flex flex-col min-h-screen">
    <canvas id="neural-canvas"></canvas>

    <?php include 'header.php'; ?>

    <main class="flex-grow max-w-[1720px] w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

        <!-- 1. HEADER & ACTION TOOLBAR -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 glass-card p-5 sm:p-6">
            <div class="min-w-0">
                <h1 class="text-lg sm:text-xl font-extrabold text-primary tracking-tight">
                    Daily Report Summary Insight
                </h1>
                <p class="text-xs text-secondary mt-0.5">
                    Rekapitulasi beban kerja harian, monitoring risiko deadline, serta insight terintegrasi MCP AI.
                </p>
            </div>

            <!-- Action Buttons (Strictly Inline & Non-wrapping, overflow visible for floating tooltips) -->
            <div class="flex items-center gap-2 sm:gap-2.5 flex-nowrap flex-shrink-0 overflow-visible py-0.5">
                <!-- Send Mail Button (Left-click: Send | Right-click: Configure) -->
                <button id="btn-send-email-report" 
                        onclick="handleSendEmailClick()" 
                        oncontextmenu="openEmailConfigModal(event)" 
                        class="btn-action-tactile bg-sky-600 hover:bg-sky-500 text-white shadow-sm shadow-sky-600/30 group relative flex-shrink-0 whitespace-nowrap"
                        title="Klik kiri: Kirim Email Report langsung | Klik kanan: Atur Subject & Penerima">
                    <span id="send-mail-icon-wrap" class="flex items-center justify-center w-4 h-4">
                        <svg id="send-mail-icon" class="w-4 h-4 transition-transform group-hover:-translate-y-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </span>
                    <span id="send-mail-text">Kirim Email Report</span>
                    <span class="text-[9px] text-sky-100 bg-sky-700/80 px-1.5 py-0.5 rounded font-mono border border-sky-400/30 hidden xl:inline">R-Click ⚙️</span>
                </button>

                <div class="relative inline-flex flex-shrink-0">
                    <button id="btn-copy-report" onclick="copyDailyReport()" class="btn-action-tactile bg-indigo-600 hover:bg-indigo-700 text-white shadow-sm shadow-indigo-600/30 flex-shrink-0 whitespace-nowrap relative group">
                        <svg id="copy-icon" class="w-4 h-4 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" /></svg>
                        <span id="copy-text">Salin Laporan (Markdown)</span>
                    </button>
                    <!-- Floating Copied Tooltip -->
                    <div id="copy-tooltip" class="absolute -top-9 left-1/2 -translate-x-1/2 px-2.5 py-1 bg-emerald-600 text-white text-[11px] font-bold rounded-lg shadow-xl shadow-emerald-950/60 pointer-events-none transition-all duration-200 flex items-center gap-1 z-[9999] whitespace-nowrap opacity-0 translate-y-1 scale-95" style="display:none;">
                        <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                        </svg>
                        <span>Copied!</span>
                        <span class="absolute -bottom-1 left-1/2 -translate-x-1/2 w-2 h-2 bg-emerald-600 rotate-45"></span>
                    </div>
                </div>

                <button onclick="window.location.reload()" class="btn-action-tactile bg-[var(--metric-bg)] hover:opacity-90 text-secondary hover:text-primary border border-[var(--card-border)] flex-shrink-0" title="Muat ulang data">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                    <span class="sr-only sm:not-sr-only sm:inline-block">Refresh</span>
                </button>
            </div>
        </div>

        <!-- 2. PIPELINE BREAKDOWN METRIC CARDS -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            <!-- Card 1: Total Active -->
            <div class="metric-subcard">
                <div class="flex items-center justify-between text-secondary mb-1">
                    <span class="text-[11px] font-bold uppercase tracking-wider">Total Aktif</span>
                    <div class="w-2 h-2 rounded-full bg-blue-500"></div>
                </div>
                <div class="text-2xl font-black text-primary"><?php echo $total_active; ?></div>
                <div class="text-[10px] text-secondary mt-1">Task dalam pipeline</div>
            </div>

            <!-- Card 2: Ongoing -->
            <div class="metric-subcard">
                <div class="flex items-center justify-between text-secondary mb-1">
                    <span class="text-[11px] font-bold uppercase tracking-wider">Ongoing</span>
                    <div class="w-2 h-2 rounded-full bg-sky-400"></div>
                </div>
                <div class="text-2xl font-black text-sky-500"><?php echo $ongoing_count; ?></div>
                <div class="text-[10px] text-secondary mt-1">Proses pengujian</div>
            </div>

            <!-- Card 3: Submitted -->
            <div class="metric-subcard">
                <div class="flex items-center justify-between text-secondary mb-1">
                    <span class="text-[11px] font-bold uppercase tracking-wider">Submitted</span>
                    <div class="w-2 h-2 rounded-full bg-emerald-400"></div>
                </div>
                <div class="text-2xl font-black text-emerald-500"><?php echo $submitted_count; ?></div>
                <div class="text-[10px] text-secondary mt-1">Menunggu approval</div>
            </div>

            <!-- Card 4: Pending Feedback -->
            <div class="metric-subcard">
                <div class="flex items-center justify-between text-secondary mb-1">
                    <span class="text-[11px] font-bold uppercase tracking-wider">Pending FB</span>
                    <div class="w-2 h-2 rounded-full bg-purple-400"></div>
                </div>
                <div class="text-2xl font-black text-purple-500"><?php echo $pending_count; ?></div>
                <div class="text-[10px] text-secondary mt-1">Diskusi / klarifikasi</div>
            </div>

            <!-- Card 5: Task Late (Alert) -->
            <div class="metric-subcard <?php echo $late_count > 0 ? 'border-red-500/40 bg-red-500/10' : ''; ?>">
                <div class="flex items-center justify-between text-secondary mb-1">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-red-500">Late (Overdue)</span>
                    <div class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></div>
                </div>
                <div class="text-2xl font-black text-red-500"><?php echo $late_count; ?></div>
                <div class="text-[10px] text-red-500/80 mt-1">Melewati deadline</div>
            </div>

            <!-- Card 6: Due 0-3 Days -->
            <div class="metric-subcard <?php echo $due_soon_count > 0 ? 'border-amber-500/40 bg-amber-500/10' : ''; ?>">
                <div class="flex items-center justify-between text-secondary mb-1">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-amber-500">Due 0-3 Hari</span>
                    <div class="w-2 h-2 rounded-full bg-amber-500"></div>
                </div>
                <div class="text-2xl font-black text-amber-500"><?php echo $due_soon_count; ?></div>
                <div class="text-[10px] text-amber-500/80 mt-1">Prioritas segera</div>
            </div>
        </div>

        <!-- 3. HERO INSIGHT QUOTE BANNER -->
        <div class="glass-card p-5 border-l-4 border-l-indigo-500 relative overflow-hidden">
            <div class="absolute -right-10 -bottom-10 w-40 h-40 bg-indigo-500/10 rounded-full blur-2xl pointer-events-none"></div>
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div class="space-y-2 w-full">
                    <div class="flex items-center justify-between gap-2 flex-wrap">
                        <div class="flex items-center gap-2">
                            <span class="insight-pill bg-indigo-500/15 text-indigo-500 border border-indigo-500/20">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                DAILY EXECUTIVE INSIGHT
                            </span>
                            <span class="text-xs text-secondary font-medium"><?php echo date('l, d F Y'); ?></span>
                        </div>
                    </div>

                    <!-- Factual, Antislop 1-Paragraph Summary Narrative -->
                    <p class="text-xs sm:text-sm text-primary leading-relaxed pt-1">
                        Hari ini tercatat <strong class="text-indigo-500 font-bold"><?php echo $total_active; ?> task aktif</strong> dalam pipeline pengujian dengan konsentrasi pengujian terbesar pada kategori <strong class="text-emerald-500 font-bold"><?php echo htmlspecialchars($top_testplan_name); ?></strong> (<?php echo $top_testplan_count; ?> task). Beban verifikasi tertinggi saat ini dipegang oleh <strong class="text-sky-500 font-bold"><?php echo htmlspecialchars($top_pic_name); ?></strong> (<?php echo $top_pic_count; ?> task aktif), sementara titik kepadatan penyelesaian terpusat pada tanggal <strong class="text-primary font-bold"><?php echo $top_dl_formatted; ?></strong> (<?php echo $top_deadline_count; ?> task). 
                        <?php if ($late_count > 0 && $due_soon_count > 0): ?>
                            Fokus mitigasi operasional mendesak tertuju pada <strong class="text-red-500 font-bold"><?php echo $late_count; ?> task overdue</strong> dan <strong class="text-amber-500 font-bold"><?php echo $due_soon_count; ?> task yang mendekati batas waktu (H-0 hingga H-3)</strong> guna menjaga ketepatan jadwal rilis.
                        <?php elseif ($late_count > 0): ?>
                            Fokus mitigasi operasional mendesak tertuju pada <strong class="text-red-500 font-bold"><?php echo $late_count; ?> task overdue</strong> yang memerlukan tindak lanjut percepatan.
                        <?php elseif ($due_soon_count > 0): ?>
                            Aktivitas pengujian berjalan on-track dengan pengawalan aktif pada <strong class="text-amber-500 font-bold"><?php echo $due_soon_count; ?> task yang akan jatuh tempo dalam 3 hari ke depan</strong>.
                        <?php else: ?>
                            Seluruh aktivitas verifikasi berjalan on-track tanpa adanya penumpukan keterlambatan maupun task kritis.
                        <?php endif; ?>
                    </p>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 pt-2">
                        <div class="p-3 rounded-xl bg-[var(--box-sub-bg)] border border-[var(--box-sub-border)]">
                            <span class="text-[10px] font-semibold text-secondary uppercase tracking-wider block">Deadline Terpadat</span>
                            <p class="text-xs font-bold text-primary mt-0.5">
                                <?php echo $top_deadline_date !== '-' ? date('d M Y', strtotime($top_deadline_date)) : '-'; ?> 
                                <span class="text-indigo-500 font-normal">(<?php echo $top_deadline_count; ?> task)</span>
                            </p>
                        </div>
                        <div class="p-3 rounded-xl bg-[var(--box-sub-bg)] border border-[var(--box-sub-border)]">
                            <span class="text-[10px] font-semibold text-secondary uppercase tracking-wider block">PIC Beban Tertinggi</span>
                            <p class="text-xs font-bold text-primary mt-0.5">
                                <?php echo htmlspecialchars($top_pic_name); ?> 
                                <span class="text-sky-500 font-normal">(<?php echo $top_pic_count; ?> task aktif)</span>
                            </p>
                        </div>
                        <div class="p-3 rounded-xl bg-[var(--box-sub-bg)] border border-[var(--box-sub-border)]">
                            <span class="text-[10px] font-semibold text-secondary uppercase tracking-wider block">Test Plan Terbanyak</span>
                            <p class="text-xs font-bold text-primary mt-0.5">
                                <?php echo htmlspecialchars($top_testplan_name); ?> 
                                <span class="text-emerald-500 font-normal">(<?php echo $top_testplan_count; ?> task)</span>
                            </p>
                        </div>
                        <div class="p-3 rounded-xl bg-[var(--box-sub-bg)] border border-[var(--box-sub-border)]">
                            <span class="text-[10px] font-semibold text-secondary uppercase tracking-wider block">Status Kritis</span>
                            <p class="text-xs font-bold <?php echo ($late_count > 0 || $due_soon_count > 0) ? 'text-amber-500' : 'text-emerald-500'; ?> mt-0.5">
                                <?php echo $late_count; ?> Late · <?php echo $due_soon_count; ?> Mendekati Deadline
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. VISUAL DISTRIBUTION CHARTS (STATUS & TEST PLAN) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Chart 1: Status Distribution -->
            <div class="glass-card p-5 flex flex-col justify-between">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h3 class="text-sm font-bold text-primary">Distribusi Status Task Aktif</h3>
                        <p class="text-xs text-secondary">Komposisi task berjalan dalam tahapan verifikasi</p>
                    </div>
                    <span class="px-2 py-1 text-[10px] font-semibold rounded-md bg-[var(--metric-bg)] border border-[var(--card-border)] text-secondary">Status Active</span>
                </div>
                <div class="relative h-64 w-full flex items-center justify-center">
                    <canvas id="chart-status-dist"></canvas>
                </div>
            </div>

            <!-- Chart 2: Test Plan Comparison -->
            <div class="glass-card p-5 flex flex-col justify-between">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h3 class="text-sm font-bold text-primary">Perbandingan Volume Test Plan</h3>
                        <p class="text-xs text-secondary">Beban task aktif berdasarkan kategori test plan</p>
                    </div>
                    <span class="px-2 py-1 text-[10px] font-semibold rounded-md bg-[var(--metric-bg)] border border-[var(--card-border)] text-secondary">Test Plans</span>
                </div>
                <div class="relative h-64 w-full flex items-center justify-center">
                    <canvas id="chart-testplan-dist"></canvas>
                </div>
            </div>
        </div>

        <!-- 5. CRITICAL FOCUS DUAL CARDS: LATE TASKS & DEADLINE 0-3 HARI -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Card 1: Late Tasks Alert -->
            <div class="glass-card p-5 border-t-4 border-t-red-500">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <span class="p-1.5 rounded-lg bg-red-500/10 text-red-500">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                        </span>
                        <div>
                            <h3 class="text-sm font-bold text-primary flex items-center gap-2">
                                Peringatan Task Late (Overdue)
                                <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-red-500/20 text-red-500"><?php echo $late_count; ?></span>
                            </h3>
                            <p class="text-xs text-secondary">Task yang telah melewati tanggal deadline dan butuh eskalasi</p>
                        </div>
                    </div>
                </div>

                <div class="space-y-2 max-h-72 overflow-y-auto custom-scroll pr-1">
                    <?php if (empty($late_tasks)): ?>
                        <div class="p-6 text-center text-secondary text-xs bg-[var(--alert-empty-bg)] rounded-xl border border-dashed border-[var(--card-border)]">
                            <svg class="w-8 h-8 mx-auto text-emerald-500 mb-1.5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            Tidak ada task late. Seluruh pengujian berjalan on-track!
                        </div>
                    <?php else: ?>
                        <?php foreach ($late_tasks as $lt): ?>
                            <div class="p-3 rounded-xl bg-[var(--alert-late-bg)] border border-[var(--alert-late-border)] hover:opacity-90 transition-opacity flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-bold text-primary truncate"><?php echo htmlspecialchars($lt['model_name']); ?></span>
                                        <?php if (!empty($lt['marketing_name'])): ?>
                                            <span class="badge-marketing"><?php echo htmlspecialchars($lt['marketing_name']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-[11px] text-secondary flex items-center gap-2 mt-1 flex-wrap">
                                        <span class="<?php echo get_pic_badge_class(!empty($lt['username']) ? $lt['username'] : $lt['pic_email']); ?>">
                                            PIC: <?php echo htmlspecialchars(!empty($lt['username']) ? $lt['username'] : (!empty($lt['pic_email']) ? explode('@', $lt['pic_email'])[0] : '-')); ?>
                                        </span>
                                        <span>•</span>
                                        <span class="font-medium text-primary"><?php echo htmlspecialchars($lt['test_plan_type']); ?></span>
                                    </div>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <span class="badge-countdown late">
                                        Late <?php echo abs($lt['days_left']); ?> hari
                                    </span>
                                    <div class="text-[10px] text-secondary mt-0.5">DL: <?php echo date('d M Y', strtotime($lt['deadline'])); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Card 2: Deadline 0-3 Hari Alert -->
            <div class="glass-card p-5 border-t-4 border-t-amber-500">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <span class="p-1.5 rounded-lg bg-amber-500/10 text-amber-500">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </span>
                        <div>
                            <h3 class="text-sm font-bold text-primary flex items-center gap-2">
                                Mendekati Deadline (0 - 3 Hari)
                                <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-amber-500/20 text-amber-500"><?php echo $due_soon_count; ?></span>
                            </h3>
                            <p class="text-xs text-secondary">Task prioritas tinggi yang akan jatuh tempo dalam waktu dekat</p>
                        </div>
                    </div>
                </div>

                <div class="space-y-2 max-h-72 overflow-y-auto custom-scroll pr-1">
                    <?php if (empty($due_soon_tasks)): ?>
                        <div class="p-6 text-center text-secondary text-xs bg-[var(--alert-empty-bg)] rounded-xl border border-dashed border-[var(--card-border)]">
                            <svg class="w-8 h-8 mx-auto text-sky-500 mb-1.5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                            Tidak ada task kritis yang jatuh tempo dalam rentang 0-3 hari.
                        </div>
                    <?php else: ?>
                        <?php foreach ($due_soon_tasks as $dt): ?>
                            <div class="p-3 rounded-xl bg-[var(--alert-due-bg)] border border-[var(--alert-due-border)] hover:opacity-90 transition-opacity flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-bold text-primary truncate"><?php echo htmlspecialchars($dt['model_name']); ?></span>
                                        <?php if (!empty($dt['marketing_name'])): ?>
                                            <span class="badge-marketing"><?php echo htmlspecialchars($dt['marketing_name']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-[11px] text-secondary flex items-center gap-2 mt-1 flex-wrap">
                                        <span class="<?php echo get_pic_badge_class(!empty($dt['username']) ? $dt['username'] : $dt['pic_email']); ?>">
                                            PIC: <?php echo htmlspecialchars(!empty($dt['username']) ? $dt['username'] : (!empty($dt['pic_email']) ? explode('@', $dt['pic_email'])[0] : '-')); ?>
                                        </span>
                                        <span>•</span>
                                        <span class="font-medium text-primary"><?php echo htmlspecialchars($dt['test_plan_type']); ?></span>
                                    </div>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <span class="badge-countdown <?php echo $dt['days_left'] === 0 ? 'late' : 'urgent'; ?>">
                                        <?php echo $dt['deadline_badge_text']; ?>
                                    </span>
                                    <div class="text-[10px] text-secondary mt-0.5">DL: <?php echo date('d M Y', strtotime($dt['deadline'])); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 6. REKAP TASK AKTIF TABLE -->
        <div class="glass-card p-5 sm:p-6 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h2 class="text-base font-bold text-primary flex items-center gap-2">
                        Rekap Task Aktif & Status Countdown
                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-[var(--metric-bg)] text-primary"><?php echo count($active_tasks); ?> Task</span>
                    </h2>
                    <p class="text-xs text-secondary">Daftar lengkap seluruh task yang sedang berjalan beserta sisa hari pengerjaan</p>
                </div>

                <!-- Search & Filter Controls -->
                <div class="flex items-center gap-2 flex-wrap">
                    <div class="relative">
                        <input type="text" id="task-search-input" placeholder="Cari model, PIC, test plan..." class="px-3 py-1.5 pl-8 rounded-xl text-xs bg-[var(--input-bg)] border border-[var(--input-border)] text-primary placeholder-[var(--text-secondary)] focus:outline-none focus:border-indigo-500 transition-colors w-48 sm:w-64" onkeyup="filterActiveTasksTable()">
                        <svg class="w-3.5 h-3.5 absolute left-2.5 top-2.5 text-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    </div>
                    
                    <select id="status-filter-select" onchange="filterActiveTasksTable()" class="px-3 py-1.5 rounded-xl text-xs bg-[var(--input-bg)] border border-[var(--input-border)] text-primary focus:outline-none focus:border-indigo-500">
                        <option value="ALL" class="bg-[var(--bg-primary)] text-primary">Semua Status</option>
                        <option value="Test Ongoing" class="bg-[var(--bg-primary)] text-primary">Test Ongoing</option>
                        <option value="Task Baru" class="bg-[var(--bg-primary)] text-primary">Task Baru</option>
                        <option value="Submitted" class="bg-[var(--bg-primary)] text-primary">Submitted</option>
                        <option value="Pending Feedback" class="bg-[var(--bg-primary)] text-primary">Pending Feedback</option>
                    </select>
                </div>
            </div>

            <!-- Table Responsive Container -->
            <div class="overflow-x-auto rounded-xl border border-[var(--card-border)] custom-scroll">
                <table class="w-full text-left text-xs" id="active-tasks-table">
                    <thead class="bg-[var(--table-header-bg)] text-secondary uppercase tracking-wider text-[10px] border-b border-[var(--card-border)]">
                        <tr>
                            <th class="py-3 px-3.5">Model & Build Specs</th>
                            <th class="py-3 px-3">Test Plan</th>
                            <th class="py-3 px-3">Status</th>
                            <th class="py-3 px-3">PIC</th>
                            <th class="py-3 px-3 text-right">Deadline & Days Left</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--card-border)]" id="active-tasks-body">
                        <?php if (empty($active_tasks)): ?>
                            <tr>
                                <td colspan="5" class="py-8 text-center text-secondary">Tidak ada task aktif saat ini.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($active_tasks as $at): 
                                $pic_display = !empty($at['username']) ? $at['username'] : (!empty($at['pic_email']) ? explode('@', $at['pic_email'])[0] : 'Unassigned');
                                
                                // Status badge color (Pill shape) - High contrast & WCAG AA compliant
                                $st_clean = trim((string)$at['progress_status']);
                                $status_class = 'badge-pill badge-slate';
                                if ($st_clean === 'Task Baru') $status_class = 'badge-pill badge-sky';
                                elseif ($st_clean === 'Downloaded') $status_class = 'badge-pill badge-cyan';
                                elseif ($st_clean === 'Test Ongoing') $status_class = 'badge-pill badge-blue';
                                elseif ($st_clean === 'Submitted') $status_class = 'badge-pill badge-emerald';
                                elseif ($st_clean === 'Pending Feedback') $status_class = 'badge-pill badge-purple';
                                elseif ($st_clean === 'Feedback Sent') $status_class = 'badge-pill badge-fuchsia';
                                elseif (in_array($st_clean, ['Approved', 'Passed'])) $status_class = 'badge-pill badge-emerald';
                                elseif ($st_clean === 'Batal') $status_class = 'badge-pill badge-rose';

                                // Test Plan pill badge color - High contrast & WCAG AA compliant
                                $tp_clean = strtoupper(trim((string)$at['test_plan_type']));
                                $tp_badge_class = 'badge-pill badge-slate';
                                if ($tp_clean === 'SKU') $tp_badge_class = 'badge-pill badge-amber';
                                elseif ($tp_clean === 'NORMAL MR' || $tp_clean === 'MR') $tp_badge_class = 'badge-pill badge-sky';
                                elseif ($tp_clean === 'SMR') $tp_badge_class = 'badge-pill badge-indigo';
                                elseif ($tp_clean === 'FULL TEST' || $tp_clean === 'FULLTEST') $tp_badge_class = 'badge-pill badge-purple';
                                elseif ($tp_clean === 'SANITY') $tp_badge_class = 'badge-pill badge-emerald';
                                elseif ($tp_clean === 'DELTA' || $tp_clean === 'DELTA TEST') $tp_badge_class = 'badge-pill badge-orange';
                                elseif ($tp_clean === 'PL' || $tp_clean === 'PRE') $tp_badge_class = 'badge-pill badge-teal';
                                elseif ($tp_clean === 'REGRESSION') $tp_badge_class = 'badge-pill badge-rose';

                                // Countdown badge color
                                $cd_class = 'badge-countdown default';
                                if ($at['deadline_badge_type'] === 'late') $cd_class = 'badge-countdown late animate-pulse';
                                elseif ($at['deadline_badge_type'] === 'today') $cd_class = 'badge-countdown late';
                                elseif ($at['deadline_badge_type'] === 'urgent') $cd_class = 'badge-countdown urgent';
                                elseif ($at['deadline_badge_type'] === 'safe') $cd_class = 'badge-countdown safe';
                            ?>
                                <tr class="hover:bg-[var(--table-row-hover)] transition-colors task-row" data-search="<?php echo htmlspecialchars(strtolower($at['model_name'] . ' ' . $at['marketing_name'] . ' ' . $pic_display . ' ' . $at['test_plan_type'] . ' ' . $at['progress_status'])); ?>" data-status="<?php echo htmlspecialchars($at['progress_status']); ?>">
                                    <!-- Model & Specs -->
                                    <td class="py-3 px-3.5">
                                        <div class="flex items-center gap-2">
                                            <span class="font-bold text-primary text-xs"><?php echo htmlspecialchars($at['model_name']); ?></span>
                                            <?php if (!empty($at['marketing_name'])): ?>
                                                <span class="badge-marketing"><?php echo htmlspecialchars($at['marketing_name']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($at['is_urgent']) && $at['is_urgent'] == 1): ?>
                                                <span class="px-1.5 py-0.2 text-[9px] font-bold rounded bg-red-500/20 text-red-500 border border-red-500/30">URGENT</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php
                                            $ap_val = trim($at['ap'] ?? '');
                                            $cp_val = trim($at['cp'] ?? '');
                                            $csc_val = trim($at['csc'] ?? '');
                                            $cp_diff = (!empty($ap_val) && !empty($cp_val) && $ap_val !== $cp_val);
                                        ?>
                                        <div class="text-[10px] text-secondary font-mono mt-0.5 space-x-1.5 flex items-center flex-wrap">
                                            <span>AP: <strong class="text-primary"><?php echo htmlspecialchars($ap_val ?: '-'); ?></strong></span>
                                            <span>·</span>
                                            <span>CP: <strong class="<?php echo $cp_diff ? 'text-red-400 font-bold' : 'text-primary'; ?>" title="<?php echo $cp_diff ? 'CP berbeda dengan AP' : ''; ?>"><?php echo htmlspecialchars($cp_val ?: '-'); ?></strong></span>
                                            <span>·</span>
                                            <span>CSC: <strong class="text-primary"><?php echo htmlspecialchars($csc_val ?: '-'); ?></strong></span>
                                        </div>
                                    </td>

                                    <!-- Test Plan (Pill Shape) -->
                                    <td class="py-3 px-3">
                                        <span class="<?php echo $tp_badge_class; ?>">
                                            <?php echo htmlspecialchars($at['test_plan_type'] ?: 'Unassigned'); ?>
                                        </span>
                                    </td>

                                    <!-- Status (Pill Shape) -->
                                    <td class="py-3 px-3">
                                        <span class="<?php echo $status_class; ?>">
                                            <?php echo htmlspecialchars($at['progress_status']); ?>
                                        </span>
                                    </td>

                                    <!-- PIC (Pill Shape with Avatar/Initials) -->
                                    <td class="py-3 px-3">
                                        <span class="inline-flex items-center gap-1.5 <?php echo get_pic_badge_class($pic_display); ?>">
                                            <?php if (!empty($at['profile_picture']) && $at['profile_picture'] !== 'default.png' && file_exists('uploads/' . $at['profile_picture'])): ?>
                                                <img src="uploads/<?php echo htmlspecialchars($at['profile_picture']); ?>" alt="PIC" class="w-4 h-4 rounded-full object-cover -ml-1 flex-shrink-0" title="<?php echo htmlspecialchars($at['pic_email']); ?>">
                                            <?php else: ?>
                                                <span class="w-4 h-4 rounded-full bg-current/20 flex items-center justify-center text-[9px] font-bold -ml-1 flex-shrink-0" title="<?php echo htmlspecialchars($at['pic_email']); ?>">
                                                    <?php echo strtoupper(substr($pic_display, 0, 1)); ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="truncate"><?php echo htmlspecialchars($pic_display); ?></span>
                                        </span>
                                    </td>

                                    <!-- Deadline & Days Left -->
                                    <td class="py-3 px-3 text-right">
                                        <span class="<?php echo $cd_class; ?>">
                                            <?php echo $at['deadline_badge_text']; ?>
                                        </span>
                                        <div class="text-[10px] text-secondary mt-0.5">
                                            <?php echo !empty($at['deadline']) ? date('d M Y', strtotime($at['deadline'])) : '-'; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Hidden Clipboard Content for Quick Copy -->
    <textarea id="raw-clipboard-text" class="hidden"><?php echo htmlspecialchars($clipboard_report); ?></textarea>

    <!-- Ambient Neural Network Canvas Animation -->
    <script>
        const canvas = document.getElementById('neural-canvas');
        if (canvas) {
            const ctx = canvas.getContext('2d');
            let width = canvas.width = window.innerWidth;
            let height = canvas.height = window.innerHeight;
            let particles = [];

            window.addEventListener('resize', () => {
                width = canvas.width = window.innerWidth;
                height = canvas.height = window.innerHeight;
            });

            class Particle {
                constructor() {
                    this.x = Math.random() * width;
                    this.y = Math.random() * height;
                    this.vx = (Math.random() - 0.5) * 0.4;
                    this.vy = (Math.random() - 0.5) * 0.4;
                    this.radius = Math.random() * 1.5 + 0.5;
                }
                update() {
                    this.x += this.vx;
                    this.y += this.vy;
                    if (this.x < 0 || this.x > width) this.vx *= -1;
                    if (this.y < 0 || this.y > height) this.vy *= -1;
                }
                draw() {
                    ctx.beginPath();
                    ctx.arc(this.x, this.y, this.radius, 0, Math.PI * 2);
                    ctx.fillStyle = document.documentElement.classList.contains('light') ? 'rgba(99, 102, 241, 0.15)' : 'rgba(99, 102, 241, 0.25)';
                    ctx.fill();
                }
            }

            const pCount = window.innerWidth > 768 ? 60 : 30;
            for (let i = 0; i < pCount; i++) particles.push(new Particle());

            function animate() {
                ctx.clearRect(0, 0, width, height);
                for (let i = 0; i < particles.length; i++) {
                    particles[i].update();
                    particles[i].draw();
                    for (let j = i + 1; j < particles.length; j++) {
                        const dx = particles[i].x - particles[j].x;
                        const dy = particles[i].y - particles[j].y;
                        const dist = Math.sqrt(dx * dx + dy * dy);
                        if (dist < 120) {
                            ctx.beginPath();
                            ctx.moveTo(particles[i].x, particles[i].y);
                            ctx.lineTo(particles[j].x, particles[j].y);
                            ctx.strokeStyle = document.documentElement.classList.contains('light') 
                                ? `rgba(99, 102, 241, ${0.12 * (1 - dist / 120)})` 
                                : `rgba(99, 102, 241, ${0.15 * (1 - dist / 120)})`;
                            ctx.stroke();
                        }
                    }
                }
                requestAnimationFrame(animate);
            }
            animate();
        }

        // Universal Copy Helper for all browser environments (HTTP, HTTPS, Local, Legacy)
        function fallbackCopyText(text) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.top = "0";
            textArea.style.left = "0";
            textArea.style.position = "fixed";
            textArea.style.opacity = "0";
            textArea.style.pointerEvents = "none";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            let success = false;
            try {
                success = document.execCommand('copy');
            } catch (err) {
                console.error('Fallback copy error:', err);
            }
            document.body.removeChild(textArea);
            return success;
        }

        // Copy Daily Report to Clipboard with Floating Tooltip Only
        let copyTooltipTimer = null;
        function copyDailyReport() {
            const el = document.getElementById('raw-clipboard-text');
            const rawText = el ? el.value : '';
            const tooltip = document.getElementById('copy-tooltip');

            function triggerSuccessUI() {
                if (tooltip) {
                    if (copyTooltipTimer) clearTimeout(copyTooltipTimer);
                    tooltip.style.display = 'inline-flex';
                    // Force browser reflow to trigger CSS transition smoothly
                    void tooltip.offsetWidth;
                    tooltip.classList.remove('opacity-0', 'translate-y-1', 'scale-95');
                    tooltip.classList.add('opacity-100', 'translate-y-0', 'scale-100');

                    copyTooltipTimer = setTimeout(() => {
                        tooltip.classList.remove('opacity-100', 'translate-y-0', 'scale-100');
                        tooltip.classList.add('opacity-0', 'translate-y-1', 'scale-95');
                        setTimeout(() => {
                            if (tooltip.classList.contains('opacity-0')) {
                                tooltip.style.display = 'none';
                            }
                        }, 220);
                    }, 1800);
                }
            }

            // Universal execution: Try modern clipboard first, fallback to execCommand, always trigger feedback
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function' && window.isSecureContext) {
                navigator.clipboard.writeText(rawText)
                    .then(triggerSuccessUI)
                    .catch(() => {
                        fallbackCopyText(rawText);
                        triggerSuccessUI();
                    });
            } else {
                fallbackCopyText(rawText);
                triggerSuccessUI();
            }
        }

        // Live Filter Table
        function filterActiveTasksTable() {
            const query = document.getElementById('task-search-input').value.toLowerCase();
            const statusFilter = document.getElementById('status-filter-select').value;
            const rows = document.querySelectorAll('#active-tasks-body .task-row');

            rows.forEach(row => {
                const searchData = row.getAttribute('data-search') || '';
                const statusData = row.getAttribute('data-status') || '';

                const matchQuery = query === '' || searchData.includes(query);
                const matchStatus = statusFilter === 'ALL' || statusData === statusFilter;

                if (matchQuery && matchStatus) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Theme synchronization with global header controller
        window.addEventListener('themechanged', function(e) {
            const isLight = e.detail ? e.detail.isLight : document.documentElement.classList.contains('light');
            // Re-render chart colors if needed
            if (typeof updateChartsTheme === 'function') {
                updateChartsTheme(isLight);
            }
        });

        // Render Charts
        document.addEventListener('DOMContentLoaded', function() {
            const isLightInit = document.documentElement.classList.contains('light');
            const textColor = isLightInit ? '#475569' : '#94a3b8';
            const gridColor = isLightInit ? 'rgba(0, 0, 0, 0.06)' : 'rgba(255, 255, 255, 0.05)';
            const borderColor = isLightInit ? '#ffffff' : '#0f172a';

            // 1. Status Distribution Chart
            const ctxStatus = document.getElementById('chart-status-dist');
            let statusChart = null;
            if (ctxStatus) {
                statusChart = new Chart(ctxStatus, {
                    type: 'doughnut',
                    data: {
                        labels: ['Ongoing', 'Submitted', 'Pending FB', 'Task Baru'],
                        datasets: [{
                            data: [
                                <?php echo $status_dist['Test Ongoing'] ?? 0; ?>,
                                <?php echo $status_dist['Submitted'] ?? 0; ?>,
                                <?php echo ($status_dist['Pending Feedback'] ?? 0) + ($status_dist['Feedback Sent'] ?? 0); ?>,
                                <?php echo ($status_dist['Task Baru'] ?? 0) + ($status_dist['Downloaded'] ?? 0); ?>
                            ],
                            backgroundColor: [
                                'rgba(56, 189, 248, 0.85)',
                                'rgba(52, 211, 153, 0.85)',
                                'rgba(192, 132, 252, 0.85)',
                                'rgba(148, 163, 184, 0.85)'
                            ],
                            borderWidth: 2,
                            borderColor: borderColor
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: { color: textColor, font: { size: 11, weight: 600 } }
                            }
                        },
                        cutout: '68%'
                    }
                });
            }

            // 2. Test Plan Distribution Bar Chart
            const ctxTestPlan = document.getElementById('chart-testplan-dist');
            let testPlanChart = null;
            if (ctxTestPlan) {
                const testPlanLabels = <?php echo json_encode(array_keys($test_plan_dist)); ?>;
                const testPlanData = <?php echo json_encode(array_values($test_plan_dist)); ?>;

                const testPlanPalette = [
                    'rgba(99, 102, 241, 0.85)',   // Indigo
                    'rgba(16, 185, 129, 0.85)',   // Emerald
                    'rgba(245, 158, 11, 0.85)',   // Amber
                    'rgba(6, 182, 212, 0.85)',    // Cyan
                    'rgba(217, 70, 239, 0.85)',   // Fuchsia
                    'rgba(244, 63, 94, 0.85)',    // Rose
                    'rgba(59, 130, 246, 0.85)',   // Blue
                    'rgba(168, 85, 247, 0.85)',   // Purple
                    'rgba(20, 184, 166, 0.85)',   // Teal
                    'rgba(249, 115, 22, 0.85)'    // Orange
                ];
                const testPlanBorderPalette = [
                    '#6366f1',
                    '#10b981',
                    '#f59e0b',
                    '#06b6d4',
                    '#d946ef',
                    '#f43f5e',
                    '#3b82f6',
                    '#a855f7',
                    '#14b8a6',
                    '#f97316'
                ];

                testPlanChart = new Chart(ctxTestPlan, {
                    type: 'bar',
                    data: {
                        labels: testPlanLabels,
                        datasets: [{
                            label: 'Jumlah Task',
                            data: testPlanData,
                            backgroundColor: testPlanPalette.slice(0, testPlanLabels.length),
                            borderColor: testPlanBorderPalette.slice(0, testPlanLabels.length),
                            borderWidth: 1.5,
                            borderRadius: 8,
                            borderSkipped: false
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { color: textColor, font: { size: 10, weight: 600 } }
                            },
                            y: {
                                grid: { color: gridColor },
                                ticks: { color: textColor, stepSize: 1, font: { size: 10 } },
                                beginAtZero: true
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(ctx) {
                                        return ' ' + ctx.parsed.y + ' Task';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Re-render chart colors dynamically on theme change
            window.addEventListener('themechanged', (e) => {
                const isLightNow = (e.detail && e.detail.isLight !== undefined) ? e.detail.isLight : document.documentElement.classList.contains('light');
                const newTextColor = isLightNow ? '#475569' : '#94a3b8';
                const newGridColor = isLightNow ? 'rgba(0, 0, 0, 0.06)' : 'rgba(255, 255, 255, 0.05)';
                const newBorderColor = isLightNow ? '#ffffff' : '#0f172a';

                if (statusChart) {
                    statusChart.data.datasets[0].borderColor = newBorderColor;
                    statusChart.options.plugins.legend.labels.color = newTextColor;
                    statusChart.update();
                }
                if (testPlanChart) {
                    testPlanChart.options.scales.x.ticks.color = newTextColor;
                    testPlanChart.options.scales.y.ticks.color = newTextColor;
                    testPlanChart.options.scales.y.grid.color = newGridColor;
                    testPlanChart.update();
                }
            });
        });
    </script>

    <!-- EMAIL CONFIGURATION MODAL (Emil-Design-Engineering) -->
    <div id="email-config-modal" class="modal-backdrop" onclick="handleBackdropClick(event)">
        <div class="modal-dialog" onclick="event.stopPropagation()">
            <!-- Modal Header -->
            <div class="px-5 py-4 border-b border-[var(--card-border)] flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-sky-500/15 border border-sky-500/30 flex items-center justify-center text-sky-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-primary">Pengaturan Email Daily Report</h3>
                        <p class="text-[11px] text-secondary">Kustomisasi judul subjek & daftar penerima bulk via MCP SMTP.</p>
                    </div>
                </div>
                <button type="button" onclick="closeEmailConfigModal()" class="text-secondary hover:text-primary p-1.5 rounded-lg hover:bg-[var(--metric-bg)] transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <!-- Modal Body -->
            <div class="p-5 space-y-4 max-h-[70vh] overflow-y-auto custom-scroll">
                <!-- Subject Line Input -->
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between">
                        <label for="input-email-subject" class="text-xs font-semibold text-primary">Subjek / Judul Email</label>
                        <button type="button" onclick="resetEmailSubjectToDefault()" class="text-[10px] text-sky-400 hover:underline">Reset Default</button>
                    </div>
                    <input type="text" id="input-email-subject" class="w-full px-3 py-2 rounded-lg bg-[var(--input-bg)] border border-[var(--input-border)] text-xs text-primary focus:outline-none focus:border-sky-500 transition-colors" placeholder="Masukkan judul email...">
                </div>

                <!-- Recipient (TO) Chip Input -->
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-semibold text-primary">Penerima Utama (To) <span class="text-rose-400">*</span></label>
                        <span class="text-[10px] text-secondary">Ketik atau paste email (koma / enter)</span>
                    </div>
                    <div id="chip-container-to" class="chip-container" onclick="focusChipInput('to')">
                        <div id="chip-list-to" class="flex flex-wrap gap-1.5"></div>
                        <input type="text" id="chip-input-to" class="chip-input" placeholder="Ketik email lalu tekan Enter atau koma (,)...">
                    </div>
                </div>

                <!-- Recipient (CC) Chip Input -->
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-semibold text-primary">Tembusan (CC) <span class="text-secondary font-normal">(Opsional)</span></label>
                        <span class="text-[10px] text-secondary">Ketik atau paste email (koma / enter)</span>
                    </div>
                    <div id="chip-container-cc" class="chip-container" onclick="focusChipInput('cc')">
                        <div id="chip-list-cc" class="flex flex-wrap gap-1.5"></div>
                        <input type="text" id="chip-input-cc" class="chip-input" placeholder="Ketik email CC...">
                    </div>
                </div>

                <!-- Info note -->
                <div class="p-3 rounded-xl bg-sky-500/10 border border-sky-500/20 text-[11px] text-sky-400 flex items-start gap-2">
                    <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span>Email akan dikirimkan otomatis dalam format HTML responsif yang memuat KPI metrics, executive narrative, dan tabel task aktif via MCP SMTP Mailer.</span>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="px-5 py-3.5 border-t border-[var(--card-border)] bg-[var(--metric-bg)] flex items-center justify-between gap-2">
                <button type="button" onclick="closeEmailConfigModal()" class="px-3.5 py-1.5 rounded-lg border border-[var(--card-border)] text-secondary hover:text-primary text-xs font-medium transition-colors">
                    Tutup
                </button>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="saveEmailSettingsOnly()" class="px-3.5 py-1.5 rounded-lg bg-[var(--input-bg)] hover:opacity-90 border border-[var(--input-border)] text-primary text-xs font-semibold transition-colors">
                        Simpan Pengaturan
                    </button>
                    <button type="button" onclick="saveAndSendEmailNow()" class="btn-action-tactile bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold shadow-sm shadow-sky-600/30">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" /></svg>
                        <span>Simpan & Kirim</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- SMTP MAILER CONSOLE LOG WIDGET (Emil-Design-Engineering & Better-UI) -->
    <div id="smtp-console-widget" class="fixed bottom-5 right-5 z-[10001] w-80 sm:w-[420px] rounded-2xl shadow-2xl border border-[var(--card-border)] bg-[var(--card-bg)] backdrop-blur-xl overflow-hidden transform translate-y-8 opacity-0 pointer-events-none transition-all duration-300">
        <!-- Console Header -->
        <div class="px-3.5 py-2.5 bg-[var(--table-header-bg)] border-b border-[var(--card-border)] flex items-center justify-between select-none">
            <div class="flex items-center gap-2">
                <span id="smtp-console-status-dot" class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse"></span>
                <span class="text-xs font-mono font-bold text-primary flex items-center gap-1.5">
                    <span>SMTP Mailer Console</span>
                    <span class="text-[9px] text-sky-400 bg-sky-500/10 px-1 py-0.2 rounded border border-sky-400/20">LIVE</span>
                </span>
            </div>
            <div class="flex items-center gap-1">
                <button type="button" onclick="toggleSmtpConsoleMinimize()" class="p-1 rounded text-secondary hover:text-primary transition-colors text-xs" title="Minimize / Expand">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                </button>
                <button type="button" onclick="closeSmtpConsole()" class="p-1 rounded text-secondary hover:text-primary transition-colors text-xs" title="Tutup console">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
        </div>
        <!-- Console Output Terminal -->
        <div id="smtp-console-body" class="p-3 font-mono text-[11px] leading-relaxed max-h-56 overflow-y-auto custom-scroll space-y-1.5 bg-slate-950/90 text-slate-200">
            <!-- Dynamic logs -->
        </div>
    </div>

    <!-- TACTILE TOAST NOTIFICATION CONTAINER -->
    <div id="toast-container" class="toast-container"></div>

    <script>
        // ----------------------------------------------------
        // EMAIL CONFIGURATION & MCP SENDMAIL CLIENT (Emil-Design-Eng)
        // ----------------------------------------------------
        const defaultEmailSubject = "<?php echo addslashes("[DAILY REPORT GBA] Summary Insight - " . date('d F Y')); ?>";
        const defaultEmailTo = ["<?php echo addslashes($_SESSION['user_details']['email'] ?? 'endri.s@samsung.com'); ?>"];

        let emailSettings = {
            subject: localStorage.getItem('gba_report_email_subject') || defaultEmailSubject,
            to: JSON.parse(localStorage.getItem('gba_report_email_to') || 'null') || defaultEmailTo,
            cc: JSON.parse(localStorage.getItem('gba_report_email_cc') || 'null') || []
        };

        const circleSpinnerSvg = `<svg class="animate-spin w-4 h-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>`;
        const envelopeIconSvg = `<svg id="send-mail-icon" class="w-4 h-4 transition-transform group-hover:-translate-y-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>`;

        // Console Log Management Functions
        function openSmtpConsole() {
            const widget = document.getElementById('smtp-console-widget');
            if (widget) {
                widget.classList.remove('translate-y-8', 'opacity-0', 'pointer-events-none');
                widget.classList.add('translate-y-0', 'opacity-100', 'pointer-events-auto');
            }
        }

        function closeSmtpConsole() {
            const widget = document.getElementById('smtp-console-widget');
            if (widget) {
                widget.classList.remove('translate-y-0', 'opacity-100', 'pointer-events-auto');
                widget.classList.add('translate-y-8', 'opacity-0', 'pointer-events-none');
            }
        }

        function toggleSmtpConsoleMinimize() {
            const body = document.getElementById('smtp-console-body');
            if (body) {
                body.classList.toggle('hidden');
            }
        }

        function clearSmtpConsole() {
            const body = document.getElementById('smtp-console-body');
            if (body) body.innerHTML = '';
        }

        function logSmtpConsole(message, type = 'info') {
            const body = document.getElementById('smtp-console-body');
            if (!body) return;

            const now = new Date();
            const timeStr = now.toTimeString().split(' ')[0];
            
            let colorClass = 'text-slate-300';
            if (type === 'success') colorClass = 'text-emerald-400 font-semibold';
            else if (type === 'error') colorClass = 'text-rose-400 font-semibold';
            else if (type === 'warn') colorClass = 'text-amber-400 font-semibold';
            else if (type === 'step') colorClass = 'text-sky-300';

            const line = document.createElement('div');
            line.className = `flex items-start gap-1.5 ${colorClass}`;
            line.innerHTML = `<span class="text-slate-500 flex-shrink-0 select-none">[${timeStr}]</span> <span class="break-all flex-1">${escapeHtml(message)}</span>`;
            body.appendChild(line);
            body.scrollTop = body.scrollHeight;
        }

        function setSmtpConsoleStatus(status) {
            const dot = document.getElementById('smtp-console-status-dot');
            if (!dot) return;
            dot.className = 'w-2.5 h-2.5 rounded-full';
            if (status === 'busy') {
                dot.classList.add('bg-amber-400', 'animate-pulse');
            } else if (status === 'success') {
                dot.classList.add('bg-emerald-400');
            } else if (status === 'error') {
                dot.classList.add('bg-rose-500');
            }
        }

        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        function isValidEmail(email) {
            return emailRegex.test(String(email).toLowerCase());
        }

        // Render Chip Tags for a given target ('to' or 'cc')
        function renderChips(target) {
            const listEl = document.getElementById(`chip-list-${target}`);
            if (!listEl) return;
            listEl.innerHTML = '';

            const items = emailSettings[target] || [];
            items.forEach((email, idx) => {
                const valid = isValidEmail(email);
                const tag = document.createElement('span');
                tag.className = `chip-tag ${valid ? '' : 'invalid'}`;
                tag.innerHTML = `
                    <span>${escapeHtml(email)}</span>
                    <button type="button" class="chip-remove" onclick="removeEmailChip('${target}', ${idx}, event)" title="Hapus email">×</button>
                `;
                listEl.appendChild(tag);
            });
        }

        function escapeHtml(text) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(text).replace(/[&<>"']/g, m => map[m]);
        }

        function removeEmailChip(target, index, event) {
            if (event) event.stopPropagation();
            if (emailSettings[target] && emailSettings[target].length > index) {
                emailSettings[target].splice(index, 1);
                renderChips(target);
            }
        }

        function addEmailChipsFromText(target, rawText) {
            if (!rawText) return;
            // Split by comma, semicolon, space, tab, newline
            const tokens = rawText.split(/[\s,;]+/).map(t => t.trim()).filter(t => t.length > 0);
            if (tokens.length === 0) return;

            if (!emailSettings[target]) emailSettings[target] = [];
            tokens.forEach(tok => {
                if (!emailSettings[target].includes(tok)) {
                    emailSettings[target].push(tok);
                }
            });
            renderChips(target);
        }

        function focusChipInput(target) {
            const inp = document.getElementById(`chip-input-${target}`);
            if (inp) inp.focus();
        }

        // Setup Chip Input Listeners
        function setupChipInput(target) {
            const inp = document.getElementById(`chip-input-${target}`);
            if (!inp) return;

            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ',' || e.key === ';' || e.key === 'Tab') {
                    if (this.value.trim().length > 0) {
                        e.preventDefault();
                        addEmailChipsFromText(target, this.value);
                        this.value = '';
                    }
                } else if (e.key === 'Backspace' && this.value === '') {
                    if (emailSettings[target] && emailSettings[target].length > 0) {
                        emailSettings[target].pop();
                        renderChips(target);
                    }
                }
            });

            inp.addEventListener('paste', function(e) {
                e.preventDefault();
                const pasteText = (e.clipboardData || window.clipboardData).getData('text');
                if (pasteText) {
                    addEmailChipsFromText(target, pasteText);
                    this.value = '';
                }
            });

            inp.addEventListener('blur', function() {
                if (this.value.trim().length > 0) {
                    addEmailChipsFromText(target, this.value);
                    this.value = '';
                }
            });
        }

        // Open Modal (Right Click Trigger or Config button)
        function openEmailConfigModal(event) {
            if (event) {
                event.preventDefault();
            }
            const modal = document.getElementById('email-config-modal');
            const subjInput = document.getElementById('input-email-subject');
            if (subjInput) {
                subjInput.value = emailSettings.subject || defaultEmailSubject;
            }
            renderChips('to');
            renderChips('cc');
            if (modal) {
                modal.classList.add('active');
            }
        }

        function closeEmailConfigModal() {
            const modal = document.getElementById('email-config-modal');
            if (modal) {
                modal.classList.remove('active');
            }
        }

        function handleBackdropClick(event) {
            if (event.target && event.target.id === 'email-config-modal') {
                closeEmailConfigModal();
            }
        }

        function resetEmailSubjectToDefault() {
            const subjInput = document.getElementById('input-email-subject');
            if (subjInput) {
                subjInput.value = defaultEmailSubject;
            }
        }

        function saveEmailSettingsState() {
            const subjInput = document.getElementById('input-email-subject');
            if (subjInput) {
                emailSettings.subject = subjInput.value.trim() || defaultEmailSubject;
            }
            localStorage.setItem('gba_report_email_subject', emailSettings.subject);
            localStorage.setItem('gba_report_email_to', JSON.stringify(emailSettings.to || []));
            localStorage.setItem('gba_report_email_cc', JSON.stringify(emailSettings.cc || []));
        }

        function saveEmailSettingsOnly() {
            saveEmailSettingsState();
            closeEmailConfigModal();
            showToast('success', 'Pengaturan Tersimpan', 'Daftar penerima dan subjek email berhasil diperbarui.');
        }

        function saveAndSendEmailNow() {
            saveEmailSettingsState();
            closeEmailConfigModal();
            triggerSendEmailRequest();
        }

        function handleSendEmailClick() {
            // If recipient list is empty, open configuration modal
            if (!emailSettings.to || emailSettings.to.length === 0) {
                showToast('warning', 'Penerima Belum Diisi', 'Silakan masukkan minimal 1 email penerima terlebih dahulu.');
                openEmailConfigModal();
                return;
            }
            triggerSendEmailRequest();
        }

        async function triggerSendEmailRequest() {
            const btn = document.getElementById('btn-send-email-report');
            const textEl = document.getElementById('send-mail-text');
            const iconWrap = document.getElementById('send-mail-icon-wrap');

            const originalText = textEl ? textEl.innerHTML : 'Kirim Email Report';
            if (btn) btn.disabled = true;
            if (textEl) textEl.textContent = 'Mengirim email...';
            if (iconWrap) iconWrap.innerHTML = circleSpinnerSvg;

            // Open & Initialize Console Widget
            openSmtpConsole();
            clearSmtpConsole();
            setSmtpConsoleStatus('busy');

            logSmtpConsole("🚀 Memulai proses pengiriman Daily Report via SMTP...", "step");
            logSmtpConsole(`📋 Subjek: "${emailSettings.subject || defaultEmailSubject}"`, "info");
            logSmtpConsole(`👥 Penerima (${(emailSettings.to || []).length} alamat): ${(emailSettings.to || []).join(', ')}`, "info");
            if (emailSettings.cc && emailSettings.cc.length) {
                logSmtpConsole(`👥 Tembusan CC (${emailSettings.cc.length} alamat): ${emailSettings.cc.join(', ')}`, "info");
            }
            logSmtpConsole("📡 Menghubungkan ke backend bridge & MCP SMTP Mailer (Port 3800)...", "step");
            logSmtpConsole("⏳ Mengirim payload HTML ke server SMTP (timeout diset hingga 5 menit)...", "warn");

            const startTime = Date.now();
            const heartbeatInterval = setInterval(() => {
                const elapsedSec = Math.floor((Date.now() - startTime) / 1000);
                logSmtpConsole(`⏳ Masih memproses pengiriman SMTP... (${elapsedSec} detik)`, "info");
            }, 5000);

            try {
                const payload = {
                    subject: emailSettings.subject || defaultEmailSubject,
                    to: emailSettings.to || [],
                    cc: emailSettings.cc || []
                };

                const res = await fetch('api_send_report_email.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                clearInterval(heartbeatInterval);
                const elapsedTotal = ((Date.now() - startTime) / 1000).toFixed(1);

                const data = await res.json();

                if (res.ok && data.success) {
                    setSmtpConsoleStatus('success');
                    logSmtpConsole(`✅ Email berhasil dikirim dalam ${elapsedTotal}s!`, "success");
                    if (data.mcp_response && data.mcp_response.result) {
                        const mId = data.mcp_response.result.messageId || data.mcp_response.result.response || 'OK';
                        logSmtpConsole(`📬 Respon SMTP: ${mId}`, "success");
                    }
                    showToast('success', 'Email Berhasil Dikirim', data.message || `Laporan terkirim ke ${data.recipients.length} penerima.`);
                } else {
                    setSmtpConsoleStatus('error');
                    const errMsg = data.error || 'Terjadi kesalahan saat memproses email via MCP SMTP.';
                    logSmtpConsole(`❌ Pengiriman gagal (${elapsedTotal}s): ${errMsg}`, "error");
                    showToast('error', 'Gagal Mengirim Email', errMsg);
                }
            } catch (err) {
                clearInterval(heartbeatInterval);
                const elapsedTotal = ((Date.now() - startTime) / 1000).toFixed(1);
                setSmtpConsoleStatus('error');
                logSmtpConsole(`❌ Koneksi terputus (${elapsedTotal}s): ${err.message}`, "error");
                showToast('error', 'Koneksi Terputus', err.message || 'Gagal menghubungi server bridge.');
            } finally {
                clearInterval(heartbeatInterval);
                if (btn) btn.disabled = false;
                if (textEl) textEl.innerHTML = originalText;
                if (iconWrap) iconWrap.innerHTML = envelopeIconSvg;
            }
        }

        // Emil-Design-Eng Toast Notification
        function showToast(type, title, description) {
            const container = document.getElementById('toast-container');
            if (!container) return;

            const toast = document.createElement('div');
            toast.className = 'toast-item';

            let iconColor = 'text-sky-400';
            let iconSvg = `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />`;

            if (type === 'success') {
                iconColor = 'text-emerald-400';
                iconSvg = `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />`;
            } else if (type === 'error') {
                iconColor = 'text-rose-400';
                iconSvg = `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />`;
            } else if (type === 'warning') {
                iconColor = 'text-amber-400';
                iconSvg = `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />`;
            }

            toast.innerHTML = `
                <div class="flex-shrink-0 mt-0.5 ${iconColor}">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">${iconSvg}</svg>
                </div>
                <div class="flex-grow">
                    <h4 class="font-bold text-primary text-xs">${escapeHtml(title)}</h4>
                    <p class="text-secondary text-[11px] mt-0.5">${escapeHtml(description)}</p>
                </div>
                <button onclick="this.parentElement.remove()" class="text-secondary hover:text-primary text-sm leading-none opacity-60 hover:opacity-100">&times;</button>
            `;

            container.appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('active'));

            setTimeout(() => {
                toast.classList.remove('active');
                setTimeout(() => toast.remove(), 250);
            }, 4500);
        }

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEmailConfigModal();
            }
        });

        // Initialize chip input listeners
        setupChipInput('to');
        setupChipInput('cc');
    </script>
</body>
</html>

