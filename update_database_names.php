<?php
require_once "config.php";
require_once "session.php";
require_once "marketing_name_mapper.php";

// Hak akses: Super User / Admin
if (!is_admin() && !is_endri_or_admin()) {
    header("Location: index.php?error=permission_denied");
    exit;
}

$active_page = 'update_database_names';

// Helper: Cari marketing name berdasarkan mapping
function resolve_marketing_name($model_name, $mapping) {
    $model_upper = strtoupper(trim($model_name));
    foreach ($mapping as $key => $val) {
        if (strpos($model_upper, $key) === 0) {
            return $val;
        }
    }
    return null;
}

// Handle AJAX Sync Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_database') {
    header('Content-Type: application/json');
    try {
        $result = $conn->query("SELECT id, model_name, project_name FROM gba_tasks");
        if (!$result) {
            throw new Exception("Gagal membaca database: " . $conn->error);
        }

        $tasks = $result->fetch_all(MYSQLI_ASSOC);
        $updated_count = 0;
        $updated_logs = [];

        $stmt = $conn->prepare("UPDATE gba_tasks SET project_name = ? WHERE id = ?");

        foreach ($tasks as $task) {
            $model_name = $task['model_name'];
            $current_name = $task['project_name'];
            $new_name = resolve_marketing_name($model_name, $model_mapping);

            if ($new_name && $new_name !== $current_name) {
                $stmt->bind_param("si", $new_name, $task['id']);
                if ($stmt->execute()) {
                    $updated_count++;
                    $updated_logs[] = [
                        'id' => $task['id'],
                        'model' => $model_name,
                        'old_name' => $current_name ?: '(Kosong)',
                        'new_name' => $new_name
                    ];
                }
            }
        }

        $stmt->close();
        echo json_encode([
            'status' => 'success',
            'message' => "Sinkronisasi berhasil! Sebanyak {$updated_count} task telah diperbarui.",
            'updated_count' => $updated_count,
            'logs' => $updated_logs
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Analisis Data Saat Ini
$result = $conn->query("SELECT id, model_name, project_name, ap, progress_status FROM gba_tasks ORDER BY id DESC");
$all_tasks = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$tasks_analysis = [];
$stats = [
    'total' => count($all_tasks),
    'need_update' => 0,
    'synced' => 0,
    'unmapped' => 0
];

foreach ($all_tasks as $task) {
    $model_name = $task['model_name'];
    $current_name = $task['project_name'];
    $target_name = resolve_marketing_name($model_name, $model_mapping);

    $status = 'unmapped';
    if ($target_name) {
        if ($target_name !== $current_name) {
            $status = 'need_update';
            $stats['need_update']++;
        } else {
            $status = 'synced';
            $stats['synced']++;
        }
    } else {
        $stats['unmapped']++;
    }

    $tasks_analysis[] = [
        'id' => $task['id'],
        'model' => $model_name,
        'ap' => $task['ap'],
        'current_name' => $current_name,
        'target_name' => $target_name,
        'progress_status' => $task['progress_status'],
        'status' => $status
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Marketing Names - Project Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['class', '.never-match-dark']
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        :root {
            --bg-primary: #020617;
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --card-bg: #0f172a;
            --card-border: #1e293b;
            --panel-bg: #1e293b;
            --input-bg: #0b1329;
            --input-border: #334155;
            --input-text: #f8fafc;
            --badge-bg: #1e293b;
            --table-header-bg: #0f172a;
            --table-hover: #1e293b;
            --table-border: #1e293b;
        }

        html.light {
            --bg-primary: #f8fafc;
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --panel-bg: #f1f5f9;
            --input-bg: #ffffff;
            --input-border: #cbd5e1;
            --input-text: #0f172a;
            --badge-bg: #f1f5f9;
            --table-header-bg: #f1f5f9;
            --table-hover: #f1f5f9;
            --table-border: #e2e8f0;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            transition: background-color 0.18s ease, color 0.18s ease;
        }

        .mono {
            font-family: 'JetBrains Mono', monospace;
        }

        .content-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 0.875rem;
            transition: background-color 0.18s ease, border-color 0.18s ease;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            font-size: 0.8125rem;
            font-weight: 700;
            padding: 0.5rem 0.875rem;
            border-radius: 0.5rem;
            transition: all 0.15s ease;
            cursor: pointer;
            border: 1px solid transparent;
            user-select: none;
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn-primary {
            background-color: #2563eb;
            color: #ffffff;
        }
        .btn-primary:hover {
            background-color: #1d4ed8;
        }

        .btn-secondary {
            background-color: var(--panel-bg);
            border-color: var(--card-border);
            color: var(--text-primary);
        }
        .btn-secondary:hover {
            border-color: #94a3b8;
        }

        /* Toast */
        #toast {
            position: fixed;
            top: 1.25rem;
            right: 1.25rem;
            z-index: 9999;
            transform: translateX(calc(100% + 2rem));
            transition: transform 0.25s ease;
        }
        #toast.show {
            transform: translateX(0);
        }
    </style>
</head>
<body class="antialiased">
    <!-- Global Header Inclusion -->
    <?php include 'header.php'; ?>

    <!-- Toast Notification -->
    <div id="toast" class="max-w-md bg-slate-900 border border-slate-700 text-slate-100 p-4 rounded-xl shadow-lg flex items-center gap-3">
        <div id="toast-icon-wrap" class="w-8 h-8 rounded-lg flex items-center justify-center bg-emerald-500/10 text-emerald-400 shrink-0">
            <i id="toast-icon" class="fas fa-check-circle text-base"></i>
        </div>
        <div class="flex-1 min-w-0">
            <p id="toast-title" class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Notifikasi</p>
            <p id="toast-message" class="text-xs font-medium text-slate-200 truncate"></p>
        </div>
        <button id="toast-close-btn" class="text-slate-400 hover:text-white p-1 rounded">
            <i class="fas fa-times text-xs"></i>
        </button>
    </div>

    <!-- Main Container -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

        <!-- Top Action & Info Bar -->
        <div class="content-card p-5 sm:p-6">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-blue-500 shadow-sm shadow-blue-500/50"></span>
                        <h1 class="text-base font-bold uppercase tracking-wider" style="color: var(--text-primary);">
                            Sinkronisasi Database Marketing Names
                        </h1>
                        <span class="text-[10px] font-mono font-bold px-2 py-0.5 rounded bg-indigo-500/15 text-indigo-400 border border-indigo-500/30">
                            Super User Tool
                        </span>
                    </div>
                    <p class="text-xs mt-1" style="color: var(--text-secondary);">
                        Menyelaraskan field <code class="mono font-bold text-blue-400">project_name</code> pada tabel <code class="mono font-bold text-blue-400">gba_tasks</code> dengan kamus acuan <code class="mono font-bold">marketing_name_mapper.php</code>.
                    </p>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-wrap items-center gap-2">
                    <a href="edit_mapping.php" class="btn btn-secondary text-xs">
                        <i class="fas fa-edit text-indigo-400"></i>
                        <span>Edit Kamus Mapping</span>
                    </a>
                    <button type="button" id="btn-sync-now" class="btn btn-primary text-xs px-4 py-2 <?= $stats['need_update'] === 0 ? 'opacity-70' : '' ?>">
                        <i class="fas fa-rotate text-xs"></i>
                        <span id="btn-sync-text">Sinkronkan Database Sekarang (<?= $stats['need_update'] ?> Task)</span>
                    </button>
                </div>
            </div>

            <!-- 4 KPI Summary Metric Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5 mt-5 pt-5 border-t" style="border-color: var(--card-border);">
                <!-- Card 1: Total Tasks -->
                <div class="bg-[var(--panel-bg)]/40 border border-[var(--card-border)] rounded-xl p-3.5 flex flex-col justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-secondary">Total Task di Database</span>
                    <div class="flex items-baseline justify-between mt-2">
                        <span class="text-2xl font-bold mono" style="color: var(--text-primary);"><?= $stats['total'] ?></span>
                        <i class="fas fa-database text-blue-500 text-sm"></i>
                    </div>
                </div>

                <!-- Card 2: Need Update -->
                <div class="bg-amber-500/5 border border-amber-500/30 rounded-xl p-3.5 flex flex-col justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-amber-500">Perlu Diperbarui</span>
                    <div class="flex items-baseline justify-between mt-2">
                        <span class="text-2xl font-bold mono text-amber-500"><?= $stats['need_update'] ?></span>
                        <i class="fas fa-arrows-rotate text-amber-500 text-sm"></i>
                    </div>
                </div>

                <!-- Card 3: Already Synced -->
                <div class="bg-emerald-500/5 border border-emerald-500/30 rounded-xl p-3.5 flex flex-col justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-500">Sudah Sesuai Mapping</span>
                    <div class="flex items-baseline justify-between mt-2">
                        <span class="text-2xl font-bold mono text-emerald-500"><?= $stats['synced'] ?></span>
                        <i class="fas fa-check-double text-emerald-500 text-sm"></i>
                    </div>
                </div>

                <!-- Card 4: Unmapped -->
                <div class="bg-slate-500/5 border border-slate-500/30 rounded-xl p-3.5 flex flex-col justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-secondary">Belum Terpetakan</span>
                    <div class="flex items-baseline justify-between mt-2">
                        <span class="text-2xl font-bold mono text-secondary"><?= $stats['unmapped'] ?></span>
                        <i class="fas fa-question-circle text-slate-400 text-sm"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Task Preview & Comparison Table -->
        <div class="content-card p-5 sm:p-6">
            <!-- Filter & Search Toolbar -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 pb-3.5 mb-4 border-b" style="border-color: var(--card-border);">
                <!-- Filter Tabs -->
                <div class="flex items-center bg-[var(--panel-bg)] border border-[var(--card-border)] p-0.5 rounded-lg gap-1">
                    <button type="button" class="filter-tab-btn px-2.5 py-1 text-xs font-bold rounded-md transition-all bg-blue-600 text-white shadow-sm" data-filter="all">
                        Semua (<?= $stats['total'] ?>)
                    </button>
                    <button type="button" class="filter-tab-btn px-2.5 py-1 text-xs font-bold rounded-md transition-all text-secondary hover:text-primary" data-filter="need_update">
                        Perlu Update (<?= $stats['need_update'] ?>)
                    </button>
                    <button type="button" class="filter-tab-btn px-2.5 py-1 text-xs font-bold rounded-md transition-all text-secondary hover:text-primary" data-filter="synced">
                        Sesuai (<?= $stats['synced'] ?>)
                    </button>
                    <button type="button" class="filter-tab-btn px-2.5 py-1 text-xs font-bold rounded-md transition-all text-secondary hover:text-primary" data-filter="unmapped">
                        Belum Terdaftar (<?= $stats['unmapped'] ?>)
                    </button>
                </div>

                <!-- Search Input -->
                <div class="relative w-full sm:w-64">
                    <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-secondary pointer-events-none"></i>
                    <input type="text" id="table-search" placeholder="Cari Model, AP, atau Nama..." class="w-full bg-[var(--input-bg)] border border-[var(--input-border)] text-[var(--input-text)] rounded-lg pl-8 pr-3 py-1.5 text-xs outline-none focus:border-blue-500 transition">
                </div>
            </div>

            <!-- Table Container -->
            <div class="overflow-x-auto rounded-lg border border-[var(--table-border)]">
                <table id="previewTable" class="w-full text-left text-xs">
                    <thead>
                        <tr class="bg-[var(--table-header-bg)] border-b border-[var(--table-border)]">
                            <th class="py-2.5 px-3 font-bold uppercase tracking-wider text-[11px] text-secondary w-16 text-center">ID</th>
                            <th class="py-2.5 px-3 font-bold uppercase tracking-wider text-[11px] text-secondary">Model Device</th>
                            <th class="py-2.5 px-3 font-bold uppercase tracking-wider text-[11px] text-secondary">AP Version</th>
                            <th class="py-2.5 px-3 font-bold uppercase tracking-wider text-[11px] text-secondary">Marketing Name Saat Ini</th>
                            <th class="py-2.5 px-3 font-bold uppercase tracking-wider text-[11px] text-secondary">Target Name (Kamus)</th>
                            <th class="py-2.5 px-3 font-bold uppercase tracking-wider text-[11px] text-secondary w-32 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--table-border)]">
                        <?php if (empty($tasks_analysis)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-8 text-secondary font-medium">Tidak ada data task di tabel gba_tasks.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tasks_analysis as $row): ?>
                                <tr class="hover:bg-[var(--table-hover)] transition-colors task-row" data-status="<?= $row['status'] ?>">
                                    <td class="py-2 px-3 text-center mono font-bold text-secondary">#<?= $row['id'] ?></td>
                                    <td class="py-2 px-3 font-bold text-primary">
                                        <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-[var(--badge-bg)] border border-[var(--card-border)]">
                                            <?= htmlspecialchars($row['model']) ?>
                                        </span>
                                    </td>
                                    <td class="py-2 px-3 mono text-secondary"><?= htmlspecialchars($row['ap']) ?></td>
                                    <td class="py-2 px-3">
                                        <?php if (!empty($row['current_name'])): ?>
                                            <span class="font-medium text-primary"><?= htmlspecialchars($row['current_name']) ?></span>
                                        <?php else: ?>
                                            <span class="italic text-secondary opacity-60">(Kosong)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2 px-3">
                                        <?php if (!empty($row['target_name'])): ?>
                                            <span class="font-bold text-blue-500 dark:text-blue-400"><?= htmlspecialchars($row['target_name']) ?></span>
                                        <?php else: ?>
                                            <span class="italic text-rose-400 opacity-80">(Tidak ditemukan di mapping)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2 px-3 text-center">
                                        <?php if ($row['status'] === 'need_update'): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/15 text-amber-500 border border-amber-500/30">
                                                <i class="fas fa-arrows-rotate text-[9px]"></i> Perlu Update
                                            </span>
                                        <?php elseif ($row['status'] === 'synced'): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/15 text-emerald-500 border border-emerald-500/30">
                                                <i class="fas fa-check text-[9px]"></i> Sesuai
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-500/15 text-secondary border border-slate-500/30">
                                                <i class="fas fa-minus text-[9px]"></i> Belum Terdaftar
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div id="no-filter-match" class="hidden text-center py-8 text-secondary text-xs">
                Tidak ada data yang cocok dengan kriteria pencarian / filter.
            </div>
        </div>
    </main>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
        $(document).ready(function() {
            // --- Toast Controller ---
            const toast = $('#toast');
            const toastMessage = $('#toast-message');
            const toastIcon = $('#toast-icon');
            const toastIconWrap = $('#toast-icon-wrap');
            let toastTimer;

            function showToast(message, isError = false) {
                clearTimeout(toastTimer);
                toastMessage.text(message);
                if (isError) {
                    toastIconWrap.removeClass('bg-emerald-500/10 text-emerald-400').addClass('bg-rose-500/10 text-rose-400');
                    toastIcon.removeClass('fa-check-circle').addClass('fa-exclamation-circle');
                } else {
                    toastIconWrap.removeClass('bg-rose-500/10 text-rose-400').addClass('bg-emerald-500/10 text-emerald-400');
                    toastIcon.removeClass('fa-exclamation-circle').addClass('fa-check-circle');
                }
                toast.addClass('show');
                toastTimer = setTimeout(() => { toast.removeClass('show'); }, 4000);
            }
            $('#toast-close-btn').on('click', () => toast.removeClass('show'));

            // --- Filter & Search Controller ---
            let activeFilter = 'all';

            $('.filter-tab-btn').on('click', function() {
                $('.filter-tab-btn').removeClass('bg-blue-600 text-white shadow-sm').addClass('text-secondary hover:text-primary');
                $(this).removeClass('text-secondary hover:text-primary').addClass('bg-blue-600 text-white shadow-sm');
                activeFilter = $(this).data('filter');
                applyFilterAndSearch();
            });

            $('#table-search').on('input', function() {
                applyFilterAndSearch();
            });

            function applyFilterAndSearch() {
                const query = $('#table-search').val().toLowerCase().trim();
                let visibleRows = 0;

                $('.task-row').each(function() {
                    const row = $(this);
                    const status = row.data('status');
                    const text = row.text().toLowerCase();

                    const matchesFilter = (activeFilter === 'all' || status === activeFilter);
                    const matchesSearch = (query === '' || text.includes(query));

                    if (matchesFilter && matchesSearch) {
                        row.show();
                        visibleRows++;
                    } else {
                        row.hide();
                    }
                });

                if (visibleRows === 0) {
                    $('#no-filter-match').removeClass('hidden');
                } else {
                    $('#no-filter-match').addClass('hidden');
                }
            }

            // --- Sync Execution Button ---
            $('#btn-sync-now').on('click', function() {
                const btn = $(this);
                const originalHtml = btn.html();

                if (!confirm('Apakah Anda yakin ingin menyinkronkan seluruh marketing name di tabel gba_tasks dengan kamus marketing_name_mapper.php?')) {
                    return;
                }

                btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Menyinkronkan...');

                $.ajax({
                    url: 'update_database_names.php',
                    type: 'POST',
                    data: { action: 'sync_database' },
                    dataType: 'json',
                    success: function(response) {
                        btn.prop('disabled', false).html(originalHtml);
                        if (response.status === 'success') {
                            showToast(response.message);
                            setTimeout(() => location.reload(), 1200);
                        } else {
                            showToast(response.message, true);
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).html(originalHtml);
                        showToast('Terjadi kesalahan koneksi saat menyinkronkan database.', true);
                    }
                });
            });

            // Quick Action: Escape key to navigate back
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' || e.key === 'Esc') {
                    // Quick close toast
                    toast.removeClass('show');
                }
            });
        });
    </script>
</body>
</html>