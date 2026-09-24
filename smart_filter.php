<?php
require_once "config.php";
require_once "session.php";

$active_page = 'smart_filter';

// 1. PDO Connection using config.php constants
try {
    $pdo = new PDO("mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Auto-create new_tasks table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS new_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        model_name VARCHAR(100) NULL,
        ap VARCHAR(255) NOT NULL UNIQUE,
        cp VARCHAR(255) NULL,
        csc VARCHAR(255) NULL,
        request_type VARCHAR(50) DEFAULT 'Normal',
        qb_user VARCHAR(100) NULL,
        qb_userdebug VARCHAR(100) NULL,
        is_manual TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    die("Koneksi ke database gagal: " . $e->getMessage());
}

// 2. Helper Functions for Parsing
function display_value($value)
{
    if ($value === null || trim($value) === '') {
        return '-';
    }
    return htmlspecialchars($value);
}

function parse_request_list($data)
{
    $lines = explode("\n", str_replace("\r", "", trim($data)));
    if (empty($lines)) return [];

    $header_line = strtolower(trim($lines[0]));
    $is_header = (strpos($header_line, 'ap') !== false || strpos($header_line, 'version') !== false);

    $ap_idx = 0;
    $cp_idx = 1;
    $csc_idx = 2;
    $type_idx = -1;
    $model_idx = -1;

    if ($is_header) {
        $headers = explode("\t", $header_line);
        foreach ($headers as $idx => $header) {
            $h = trim($header);
            if (strpos($h, 'ap') !== false) $ap_idx = $idx;
            elseif (strpos($h, 'cp') !== false) $cp_idx = $idx;
            elseif (strpos($h, 'csc') !== false) $csc_idx = $idx;
            elseif (strpos($h, 'type') !== false) $type_idx = $idx;
            elseif (strpos($h, 'model') !== false) $model_idx = $idx;
        }
        $start_row = 1;
    } else {
        $start_row = 0;
    }

    $requests = [];
    for ($i = $start_row; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (empty($line)) continue;
        $row = explode("\t", $line);

        $ap = trim($row[$ap_idx] ?? '');
        $cp = trim($row[$cp_idx] ?? '');
        $csc = trim($row[$csc_idx] ?? '');
        $type = $type_idx !== -1 ? trim($row[$type_idx] ?? '') : '';
        $model = $model_idx !== -1 ? trim($row[$model_idx] ?? '') : '';

        // Fallback detection if headers were missing or shifted
        if (is_numeric($ap) && strlen($ap) < 5 && isset($row[$ap_idx + 1])) {
            $next_col = trim($row[$ap_idx + 1]);
            if (strpos(strtoupper($next_col), 'SM-') === 0 && isset($row[$ap_idx + 2])) {
                $model = trim($row[$ap_idx + 1]);
                $ap = trim($row[$ap_idx + 2] ?? '');
                $cp = trim($row[$cp_idx + 2] ?? '');
                $csc = trim($row[$csc_idx + 2] ?? '');
                $type = $type_idx !== -1 ? trim($row[$type_idx + 2] ?? '') : trim($row[5] ?? '');
            } elseif (strlen($next_col) > 5) {
                $ap = trim($row[$ap_idx + 1] ?? '');
                $cp = trim($row[$cp_idx + 1] ?? '');
                $csc = trim($row[$csc_idx + 1] ?? '');
                $type = $type_idx !== -1 ? trim($row[$type_idx + 1] ?? '') : trim($row[4] ?? '');
            }
        }

        if (!empty($ap)) {
            $requests[] = [
                'ap' => $ap,
                'cp' => $cp,
                'csc' => $csc,
                'type' => $type,
                'model' => $model
            ];
        }
    }
    return $requests;
}

function parse_summary_list($data)
{
    $lines = explode("\n", str_replace("\r", "", trim($data)));
    if (empty($lines)) return ['aps' => [], 'prefixes_to_models' => [], 'prefixes_to_csc' => []];

    $header_line = strtolower(trim($lines[0]));
    $is_header = (strpos($header_line, 'model') !== false || strpos($header_line, 'ap') !== false);
    $start_row = $is_header ? 1 : 0;

    $summary_data = [
        'aps' => [],
        'prefixes_to_models' => [],
        'prefixes_to_csc' => []
    ];
    for ($i = $start_row; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (empty($line)) continue;
        $row = explode("\t", $line);
        $model = trim($row[0] ?? '');
        $ap = trim($row[1] ?? '');
        $csc = trim($row[3] ?? '');
        if (!empty($ap)) {
            $summary_data['aps'][] = $ap;
            if (!empty($model)) {
                $prefix = substr($ap, 0, 6);
                $summary_data['prefixes_to_models'][$prefix] = $model;
                if (!empty($csc)) {
                    $summary_data['prefixes_to_csc'][$prefix] = $csc;
                }
            }
        }
    }
    $summary_data['aps'] = array_unique($summary_data['aps']);
    return $summary_data;
}

// 3. AJAX Request Handler
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        try {
            switch ($_POST['action']) {
                case 'add_manual':
                    if (empty($_POST['ap'])) {
                        throw new Exception('AP Version wajib diisi.');
                    }
                    $stmt = $pdo->prepare(
                        "INSERT INTO new_tasks (model_name, ap, cp, csc, request_type, qb_user, qb_userdebug, is_manual) VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
                    );
                    $stmt->execute([
                        $_POST['model_name'] ?? '',
                        trim($_POST['ap']),
                        trim($_POST['cp'] ?? ''),
                        trim($_POST['csc'] ?? ''),
                        $_POST['request_type'] ?? 'Normal',
                        trim($_POST['qb_user'] ?? ''),
                        trim($_POST['qb_userdebug'] ?? '')
                    ]);
                    echo json_encode(['status' => 'success', 'message' => 'Task manual berhasil ditambahkan.']);
                    break;

                case 'delete_task':
                    if (empty($_POST['task_id'])) {
                        throw new Exception('ID Task tidak valid.');
                    }
                    $stmt = $pdo->prepare("DELETE FROM new_tasks WHERE id = ?");
                    $stmt->execute([$_POST['task_id']]);
                    echo json_encode(['status' => 'success', 'message' => 'Task berhasil dihapus.']);
                    break;

                case 'bulk_delete':
                    if (empty($_POST['ids']) || !is_array($_POST['ids'])) {
                        throw new Exception('Tidak ada task yang dipilih.');
                    }
                    $ids = array_map('intval', $_POST['ids']);
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $pdo->prepare("DELETE FROM new_tasks WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    echo json_encode(['status' => 'success', 'message' => count($ids) . ' task berhasil dihapus.']);
                    break;

                case 'reset':
                    $pdo->exec("TRUNCATE TABLE new_tasks");
                    echo json_encode(['status' => 'success', 'message' => 'Semua data task berhasil direset.']);
                    break;

                case 'update_task':
                    if (empty($_POST['ap']) || empty($_POST['task_id'])) {
                        echo json_encode(['status' => 'error', 'message' => 'Gagal: AP Version dan Task ID tidak boleh kosong.']);
                    } else {
                        $stmt = $pdo->prepare(
                            "UPDATE new_tasks SET 
                                model_name = ?, ap = ?, cp = ?, csc = ?, 
                                request_type = ?, qb_user = ?, qb_userdebug = ? 
                            WHERE id = ?"
                        );
                        $stmt->execute([
                            $_POST['model_name'] ?? '',
                            trim($_POST['ap']),
                            trim($_POST['cp'] ?? ''),
                            trim($_POST['csc'] ?? ''),
                            $_POST['request_type'] ?? 'Normal',
                            trim($_POST['qb_user'] ?? ''),
                            trim($_POST['qb_userdebug'] ?? ''),
                            $_POST['task_id']
                        ]);
                        echo json_encode(['status' => 'success', 'message' => 'Task berhasil diperbarui.']);
                    }
                    break;

                case 'compare':
                    $manual_tasks = $pdo->query("SELECT * FROM new_tasks WHERE is_manual = 1")->fetchAll(PDO::FETCH_ASSOC);
                    $pdo->exec("TRUNCATE TABLE new_tasks");

                    $input_data['request_list'] = trim($_POST['request_list'] ?? '');
                    $input_data['gba_summary'] = trim($_POST['gba_summary'] ?? '');

                    $requests = [];
                    if (!empty($input_data['request_list'])) {
                        $requests = parse_request_list($input_data['request_list']);
                    }

                    $summary_data = ['aps' => [], 'prefixes_to_models' => [], 'prefixes_to_csc' => []];
                    if (!empty($input_data['gba_summary'])) {
                        $summary_data = parse_summary_list($input_data['gba_summary']);
                    }

                    $summary_aps = $summary_data['aps'];
                    $summary_prefixes = $summary_data['prefixes_to_models'];
                    $summary_cscs = $summary_data['prefixes_to_csc'];

                    $max_request_aps = [];
                    foreach ($requests as $req) {
                        $ap = $req['ap'];
                        $ap_prefix = substr($ap, 0, 8);
                        $last5_val = base_convert(substr($ap, -5), 36, 10);

                        if (!isset($max_request_aps[$ap_prefix]) || $last5_val > $max_request_aps[$ap_prefix]['val']) {
                            $max_request_aps[$ap_prefix] = [
                                'val' => $last5_val,
                                'req' => $req
                            ];
                        }
                    }

                    $max_summary_aps = [];
                    foreach ($summary_aps as $sap) {
                        $ap_prefix = substr($sap, 0, 8);
                        $last5_val = base_convert(substr($sap, -5), 36, 10);
                        if (!isset($max_summary_aps[$ap_prefix]) || $last5_val > $max_summary_aps[$ap_prefix]) {
                            $max_summary_aps[$ap_prefix] = $last5_val;
                        }
                    }

                    $new_entries_count = 0;
                    if (!empty($max_request_aps)) {
                        $stmt_insert = $pdo->prepare(
                            "INSERT IGNORE INTO new_tasks (model_name, ap, cp, csc, request_type) VALUES (?, ?, ?, ?, ?)"
                        );

                        foreach ($max_request_aps as $ap_prefix => $data) {
                            $req = $data['req'];
                            $ap = $req['ap'];
                            $req_val = $data['val'];
                            $prefix = substr($ap, 0, 6);

                            if (isset($summary_prefixes[$prefix])) {
                                $model_name = $summary_prefixes[$prefix];
                                $should_insert = true;

                                if (isset($max_summary_aps[$ap_prefix])) {
                                    $max_sap_val = $max_summary_aps[$ap_prefix];
                                    if ($req_val <= $max_sap_val) {
                                        $should_insert = false;
                                    }
                                }

                                if ($should_insert) {
                                    $req_csc = $req['csc'];

                                    if (isset($summary_cscs[$prefix])) {
                                        $sum_csc = $summary_cscs[$prefix];
                                        $gba_csc_code = null;
                                        if (strpos($sum_csc, 'OLM') !== false) $gba_csc_code = 'OLM';
                                        elseif (strpos($sum_csc, 'OXM') !== false) $gba_csc_code = 'OXM';
                                        elseif (strpos($sum_csc, 'OLE') !== false) $gba_csc_code = 'OLE';
                                        elseif (strpos($sum_csc, 'OLP') !== false) $gba_csc_code = 'OLP';
                                        elseif (strpos($sum_csc, 'OXT') !== false) $gba_csc_code = 'OXT';

                                        if ($gba_csc_code) {
                                            $req_csc = str_replace(['OXM', 'OLM', 'OLE', 'OXT', 'OLP'], $gba_csc_code, $req_csc);
                                        } else {
                                            $req_csc = str_replace(['OXM', 'OLM'], 'OLE', $req_csc);
                                            $req_csc = str_replace('OXT', 'OLP', $req_csc);
                                        }
                                    } else {
                                        $req_csc = str_replace(['OXM', 'OLM'], 'OLE', $req_csc);
                                        $req_csc = str_replace('OXT', 'OLP', $req_csc);
                                    }

                                    $raw_type = !empty($req['type']) ? strtoupper(trim($req['type'])) : '';
                                    $request_type = 'Normal';
                                    if (strpos($raw_type, 'SMR') !== false) {
                                        $request_type = 'SMR';
                                    } elseif (strpos($raw_type, 'SKU') !== false) {
                                        $request_type = 'SKU';
                                    }

                                    $stmt_insert->execute([
                                        $model_name,
                                        $ap,
                                        $req['cp'],
                                        $req_csc,
                                        $request_type
                                    ]);
                                    if ($stmt_insert->rowCount() > 0) {
                                        $new_entries_count++;
                                    }
                                }
                            }
                        }
                    }

                    if (!empty($manual_tasks)) {
                        $stmt_reinsert = $pdo->prepare(
                            "INSERT INTO new_tasks (model_name, ap, cp, csc, request_type, qb_user, qb_userdebug, is_manual) 
                             VALUES (:model_name, :ap, :cp, :csc, :request_type, :qb_user, :qb_userdebug, 1)"
                        );
                        foreach ($manual_tasks as $task) {
                            $stmt_reinsert->execute([
                                ':model_name' => $task['model_name'],
                                ':ap' => $task['ap'],
                                ':cp' => $task['cp'],
                                ':csc' => $task['csc'],
                                ':request_type' => $task['request_type'],
                                ':qb_user' => $task['qb_user'],
                                ':qb_userdebug' => $task['qb_userdebug']
                            ]);
                        }
                    }
                    $manual_count = count($manual_tasks);
                    echo json_encode([
                        'status' => 'success',
                        'message' => "Filter selesai: {$new_entries_count} task baru ditemukan & {$manual_count} entri manual dipertahankan."
                    ]);
                    break;

                default:
                    echo json_encode(['status' => 'error', 'message' => 'Aksi tidak diketahui.']);
                    break;
            }
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                echo json_encode(['status' => 'error', 'message' => 'Gagal: AP Version sudah ada di database.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
            }
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Fetch all tasks
$all_tasks = $pdo->query("SELECT * FROM new_tasks ORDER BY is_manual DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Filter - Project Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['class', '.never-match-dark']
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/2.0.7/css/dataTables.tailwindcss.css">
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

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
            --input-placeholder: #64748b;
            --badge-bg: #1e293b;
            --badge-text: #e2e8f0;
            --table-hover: #1e293b;
            --table-stripe: #090f20;
            --table-header-bg: #0f172a;
            --table-header-text: #94a3b8;
            --table-header-border: #1e293b;
            --table-cell-border: #1e293b;
            --btn-sec-bg: #1e293b;
            --btn-sec-border: #334155;
            --btn-sec-text: #f1f5f9;
            --btn-reset-bg: #1e293b;
            --btn-reset-border: #334155;
            --btn-reset-text: #f87171;
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
            --input-placeholder: #475569;
            --badge-bg: #f1f5f9;
            --badge-text: #0f172a;
            --table-hover: #f1f5f9;
            --table-stripe: #f8fafc;
            --table-header-bg: #f1f5f9;
            --table-header-text: #0f172a;
            --table-header-border: #cbd5e1;
            --table-cell-border: #e2e8f0;
            --btn-sec-bg: #ffffff;
            --btn-sec-border: #cbd5e1;
            --btn-sec-text: #0f172a;
            --btn-reset-bg: #ffffff;
            --btn-reset-border: #fecaca;
            --btn-reset-text: #dc2626;
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

        .editor-textarea {
            width: 100%;
            background: var(--input-bg);
            border: 1.5px solid var(--input-border);
            color: var(--input-text);
            border-radius: 0.5rem;
            padding: 0.75rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.8125rem;
            line-height: 1.5;
            resize: vertical;
            transition: border-color 0.15s ease, background-color 0.15s ease, color 0.15s ease;
        }

        .editor-textarea:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
        }

        .editor-textarea::placeholder {
            color: var(--input-placeholder) !important;
            opacity: 0.9 !important;
            font-family: 'JetBrains Mono', monospace !important;
            font-size: 0.75rem !important;
        }

        /* Buttons */
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

        .btn-success {
            background-color: #16a34a;
            color: #ffffff;
        }
        .btn-success:hover {
            background-color: #15803d;
        }

        .btn-danger {
            background-color: #dc2626;
            color: #ffffff;
        }
        .btn-danger:hover {
            background-color: #b91c1c;
        }

        .btn-secondary {
            background-color: var(--btn-sec-bg);
            border-color: var(--btn-sec-border);
            color: var(--btn-sec-text);
        }
        .btn-secondary:hover {
            background-color: var(--panel-bg);
            border-color: #94a3b8;
        }

        .btn-reset {
            background-color: var(--btn-reset-bg);
            border-color: var(--btn-reset-border);
            color: var(--btn-reset-text);
        }
        .btn-reset:hover {
            background-color: #fef2f2;
            border-color: #f87171;
        }
        html:not(.light) .btn-reset:hover {
            background-color: rgba(239, 68, 68, 0.15);
        }

        /* Modal Inputs */
        .modal-input {
            width: 100%;
            background: var(--input-bg);
            border: 1.5px solid var(--input-border);
            color: var(--input-text);
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            font-size: 0.8125rem;
            font-weight: 500;
            transition: all 0.15s ease;
        }
        .modal-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
        }

        /* DataTables 2.0 Comprehensive Theme Sync & Contrast */
        .dt-container {
            color: var(--text-primary) !important;
            font-size: 0.8125rem !important;
        }

        .dt-container .dt-search,
        .dt-container .dt-length {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-primary) !important;
            font-weight: 700 !important;
            font-size: 0.8125rem !important;
        }

        .dt-container .dt-search input,
        div.dt-container div.dt-search input {
            background-color: var(--input-bg) !important;
            border: 1.5px solid var(--input-border) !important;
            color: var(--input-text) !important;
            font-weight: 600 !important;
            border-radius: 0.5rem !important;
            padding: 0.4rem 0.75rem !important;
            font-size: 0.8125rem !important;
            outline: none !important;
            box-shadow: none !important;
            transition: all 0.15s ease !important;
        }

        .dt-container .dt-search input:focus,
        div.dt-container div.dt-search input:focus {
            border-color: #2563eb !important;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2) !important;
        }

        .dt-container .dt-length select,
        div.dt-container div.dt-length select {
            background-color: var(--input-bg) !important;
            border: 1.5px solid var(--input-border) !important;
            color: var(--input-text) !important;
            font-weight: 700 !important;
            border-radius: 0.5rem !important;
            padding: 0.4rem 2rem 0.4rem 0.75rem !important;
            font-size: 0.8125rem !important;
            outline: none !important;
            box-shadow: none !important;
            transition: all 0.15s ease !important;
        }

        .dt-container .dt-length select:focus,
        div.dt-container div.dt-length select:focus {
            border-color: #2563eb !important;
        }

        .dt-container .dt-layout-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.75rem 0;
            flex-wrap: wrap;
        }

        .dt-container .dt-info,
        div.dt-container div.dt-info {
            color: var(--text-secondary) !important;
            font-weight: 700 !important;
            font-size: 0.8125rem !important;
            padding: 0 !important;
        }

        .dt-container .dt-paging,
        div.dt-container div.dt-paging {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0 !important;
        }

        .dt-container .dt-paging .dt-paging-button,
        div.dt-container div.dt-paging button.dt-paging-button {
            background: var(--btn-sec-bg) !important;
            color: var(--text-primary) !important;
            border: 1.5px solid var(--input-border) !important;
            border-radius: 0.375rem !important;
            padding: 0.375rem 0.75rem !important;
            font-size: 0.75rem !important;
            font-weight: 700 !important;
            cursor: pointer !important;
            min-width: 2rem !important;
            text-align: center !important;
            box-shadow: none !important;
            transition: all 0.15s ease !important;
        }

        .dt-container .dt-paging .dt-paging-button:hover:not(.disabled),
        div.dt-container div.dt-paging button.dt-paging-button:hover:not(.disabled) {
            background: var(--panel-bg) !important;
            border-color: #94a3b8 !important;
            color: var(--text-primary) !important;
        }

        .dt-container .dt-paging .dt-paging-button.current,
        .dt-container .dt-paging .dt-paging-button.current:hover,
        div.dt-container div.dt-paging button.dt-paging-button.current {
            background: #2563eb !important;
            color: #ffffff !important;
            border-color: #2563eb !important;
        }

        .dt-container .dt-paging .dt-paging-button.disabled,
        div.dt-container div.dt-paging button.dt-paging-button.disabled {
            opacity: 0.45 !important;
            cursor: not-allowed !important;
            color: var(--text-secondary) !important;
        }

        table.dataTable {
            border-collapse: separate !important;
            border-spacing: 0 !important;
            width: 100% !important;
            border-radius: 0.5rem !important;
            overflow: hidden !important;
        }

        table.dataTable thead th {
            background: var(--table-header-bg) !important;
            color: var(--table-header-text) !important;
            font-weight: 800 !important;
            font-size: 0.75rem !important;
            text-transform: uppercase !important;
            letter-spacing: 0.05em !important;
            border-top: 1px solid var(--table-header-border) !important;
            border-bottom: 2px solid var(--table-header-border) !important;
            padding: 0.75rem 0.875rem !important;
        }

        table.dataTable tbody tr,
        table.dataTable tbody tr td,
        table.dataTable tbody tr th {
            border-bottom: 1px solid var(--table-cell-border) !important;
            padding: 0.65rem 0.875rem !important;
            color: var(--text-primary) !important;
            vertical-align: middle;
            font-size: 0.8125rem !important;
        }

        table.dataTable tbody tr,
        table.dataTable tbody tr.odd,
        table.dataTable tbody tr:nth-child(odd),
        table.dataTable tbody tr.odd > td,
        table.dataTable tbody tr.odd > th,
        table.dataTable tbody tr:nth-child(odd) > td,
        table.dataTable tbody tr:nth-child(odd) > th,
        table.dataTable.display > tbody > tr.odd > *,
        table.dataTable.stripe > tbody > tr.odd > *,
        table.dataTable.display > tbody > tr:nth-child(odd) > *,
        table.dataTable.stripe > tbody > tr:nth-child(odd) > * {
            background-color: transparent !important;
            color: var(--text-primary) !important;
        }

        table.dataTable tbody tr.even,
        table.dataTable tbody tr:nth-child(even),
        table.dataTable tbody tr.even > td,
        table.dataTable tbody tr.even > th,
        table.dataTable tbody tr:nth-child(even) > td,
        table.dataTable tbody tr:nth-child(even) > th,
        table.dataTable.display > tbody > tr.even > *,
        table.dataTable.stripe > tbody > tr.even > *,
        table.dataTable.display > tbody > tr:nth-child(even) > *,
        table.dataTable.stripe > tbody > tr:nth-child(even) > * {
            background-color: var(--table-stripe) !important;
            color: var(--text-primary) !important;
        }

        table.dataTable tbody tr:hover,
        table.dataTable tbody tr:hover > td,
        table.dataTable tbody tr:hover > th,
        table.dataTable.display > tbody > tr:hover > *,
        table.dataTable.stripe > tbody > tr:hover > * {
            background-color: var(--table-hover) !important;
        }

        table.dataTable tbody td.dt-empty {
            text-align: center !important;
            padding: 2.5rem 1rem !important;
            color: var(--text-secondary) !important;
            font-weight: 700 !important;
            font-style: normal !important;
            background: transparent !important;
        }

        tr.manual-row,
        tr.manual-row > td,
        table.dataTable tbody tr.manual-row,
        table.dataTable tbody tr.manual-row > td,
        table.dataTable.display > tbody > tr.manual-row > *,
        table.dataTable.stripe > tbody > tr.manual-row > * {
            background-color: rgba(16, 185, 129, 0.08) !important;
        }
        tr.manual-row:hover,
        tr.manual-row:hover > td,
        table.dataTable tbody tr.manual-row:hover,
        table.dataTable tbody tr.manual-row:hover > td,
        table.dataTable.display > tbody > tr.manual-row:hover > *,
        table.dataTable.stripe > tbody > tr.manual-row:hover > * {
            background-color: rgba(16, 185, 129, 0.16) !important;
        }
        tr.manual-row td:first-child,
        table.dataTable tbody tr.manual-row td:first-child {
            border-left: 3px solid #10b981 !important;
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

        <!-- Section 1: Parser & Filter -->
        <div class="content-card p-5 sm:p-6">
            <div class="flex items-center justify-between pb-3.5 mb-4 border-b" style="border-color: var(--card-border);">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                    <h2 class="text-sm font-bold uppercase tracking-wider" style="color: var(--text-primary);">1. Parser & Bandingkan Data</h2>
                </div>
                <span class="text-xs px-2.5 py-1 rounded font-semibold" style="background: var(--badge-bg); color: var(--text-primary); border: 1px solid var(--card-border);">
                    TSV / Tab-Separated format
                </span>
            </div>

            <form id="compareForm" action="smart_filter.php" method="POST">
                <input type="hidden" name="action" value="compare">

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-stretch">
                    <!-- Left: Card A - Request List -->
                    <div class="flex flex-col h-full bg-[var(--panel-bg)]/40 border border-[var(--card-border)] rounded-xl p-4 space-y-3">
                        <!-- Card A Header -->
                        <div class="flex items-center justify-between pb-2 border-b border-[var(--card-border)]">
                            <label class="text-xs font-bold uppercase tracking-wider flex items-center gap-1.5" style="color: var(--text-primary);">
                                <span class="w-4 h-4 rounded font-bold text-[10px] flex items-center justify-center bg-[var(--panel-bg)] border border-[var(--card-border)]">A</span>
                                Request List (Email / Excel)
                            </label>
                            <span id="req-line-count" class="text-[11px] mono font-bold" style="color: var(--text-secondary);">0 baris</span>
                        </div>

                        <!-- Card A Subheader: Tab Switcher (Upload Excel default selected) -->
                        <div class="flex items-center justify-between min-h-[34px]">
                            <div class="flex items-center bg-[var(--card-bg)] border border-[var(--card-border)] p-0.5 rounded-lg gap-1">
                                <button type="button" id="tab-btn-converter" class="px-2.5 py-1 text-xs font-bold rounded-md transition-all bg-blue-600 text-white shadow-sm flex items-center gap-1.5">
                                    <i class="fas fa-file-excel text-emerald-300"></i>
                                    <span>Upload Excel</span>
                                </button>
                                <button type="button" id="tab-btn-direct" class="px-2.5 py-1 text-xs font-bold rounded-md transition-all text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white flex items-center gap-1.5">
                                    <i class="fas fa-table-list"></i>
                                    <span>Direct Input (TSV)</span>
                                </button>
                            </div>
                            <span class="text-[11px] font-medium hidden sm:inline" style="color: var(--text-secondary);">Format: AP, CP, CSC, Type</span>
                        </div>

                        <!-- Card A Content Area -->
                        <div class="flex-1 flex flex-col min-h-[170px]">
                            <!-- Tab 1 (Default): Upload Excel File & Auto-Parsing -->
                            <div id="tab-content-converter" class="flex-1 flex flex-col">
                                <div id="dropzone-excel" class="flex-1 border-2 border-dashed border-[var(--input-border)] hover:border-blue-500 rounded-lg p-5 text-center cursor-pointer transition-colors bg-[var(--input-bg)] flex flex-col items-center justify-center gap-2 min-h-[170px]">
                                    <input type="file" id="excel-file-input" accept=".xlsx, .xls, .csv, .txt, .tsv" class="hidden">
                                    <div class="w-10 h-10 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-lg">
                                        <i class="fas fa-file-excel"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold" style="color: var(--text-primary);">
                                            Klik atau Tarik File Excel ke Sini (.xlsx, .xls, .csv)
                                        </p>
                                        <p class="text-[11px] mt-0.5" style="color: var(--text-secondary);">
                                            Otomatis diparsing dan langsung diterapkan ke Request List
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Tab 2: Direct Input (TSV) -->
                            <div id="tab-content-direct" class="hidden flex-1 flex flex-col">
                                <textarea id="req-textarea" name="request_list" class="editor-textarea flex-1 min-h-[170px]"
                                    placeholder="Contoh format:&#10;AP               CP               CSC              Type&#10;A556EXXS4AXB1    A556EXXS4AXB1    A556EOLE4AXB1    Normal&#10;S928BXXS3AYB2    S928BXXS3AYB2    S928BOLE3AYB2    SMR"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Card B - GBA Summary Baseline -->
                    <div class="flex flex-col h-full bg-[var(--panel-bg)]/40 border border-[var(--card-border)] rounded-xl p-4 space-y-3">
                        <!-- Card B Header -->
                        <div class="flex items-center justify-between pb-2 border-b border-[var(--card-border)]">
                            <label class="text-xs font-bold uppercase tracking-wider flex items-center gap-1.5" style="color: var(--text-primary);">
                                <span class="w-4 h-4 rounded font-bold text-[10px] flex items-center justify-center bg-[var(--panel-bg)] border border-[var(--card-border)]">B</span>
                                GBA Task Summary (Baseline)
                            </label>
                            <span id="sum-line-count" class="text-[11px] mono font-bold" style="color: var(--text-secondary);">0 baris</span>
                        </div>

                        <!-- Card B Subheader (Matching height for vertical alignment) -->
                        <div class="flex items-center justify-between min-h-[34px]">
                            <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-semibold bg-[var(--card-bg)] border border-[var(--card-border)]" style="color: var(--text-secondary);">
                                <i class="fas fa-database text-blue-500"></i>
                                <span>Firmware Baseline Database</span>
                            </div>
                            <span class="text-[11px] font-medium hidden sm:inline" style="color: var(--text-secondary);">Format: Model, AP, CP, CSC</span>
                        </div>

                        <!-- Card B Content Area -->
                        <div class="flex-1 flex flex-col min-h-[170px]">
                            <textarea id="sum-textarea" name="gba_summary" class="editor-textarea flex-1 min-h-[170px]"
                                placeholder="Contoh format:&#10;Model        AP               CP               CSC&#10;SM-A556E     A556EXXS3AXA9    A556EXXS3AXA9    A556EOLE3AXA9&#10;SM-S928B     S928BXXS2AYA1    S928BXXS2AYA1    S928BOLE2AYA1"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Submit Bar -->
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 pt-3.5 border-t" style="border-color: var(--card-border);">
                    <p class="text-xs flex items-center gap-1.5 font-medium" style="color: var(--text-secondary);">
                        <i class="fas fa-info-circle text-blue-600"></i> Data manual yang sudah ditambahkan sebelumnya akan tetap dipertahankan otomatis.
                    </p>
                    <button type="submit" id="btn-submit-compare" class="btn btn-primary px-5 py-2.5">
                        <i class="fas fa-filter"></i>
                        <span>Proses & Bandingkan Sekarang</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Section 2: Table Management -->
        <div class="content-card p-5 sm:p-6">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 pb-3.5 mb-4 border-b" style="border-color: var(--card-border);">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-600"></span>
                    <h2 class="text-sm font-bold uppercase tracking-wider" style="color: var(--text-primary);">2. Task Tersaring & Terdaftar</h2>
                </div>

                <!-- Inline Search & Action Buttons Group -->
                <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto justify-start sm:justify-end">
                    <!-- Inline Search Bar -->
                    <div class="relative flex-1 sm:flex-initial">
                        <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 text-xs pointer-events-none" style="color: var(--text-secondary);"></i>
                        <input type="text" id="custom-table-search" placeholder="Cari di tabel..." class="modal-input pl-8 pr-3 py-1.5 text-xs w-full sm:w-48 h-[34px]">
                    </div>

                    <button id="open-add-modal-btn" class="btn btn-primary h-[34px]">
                        <i class="fas fa-plus"></i>
                        <span>Tambah Manual</span>
                    </button>

                    <div id="bulk-actions" class="hidden items-center gap-2">
                        <button id="copySelectedBtn" class="btn btn-success h-[34px]">
                            <i class="fas fa-copy"></i>
                            <span>Copy Selected</span>
                        </button>
                        <button id="deleteSelectedBtn" class="btn btn-danger h-[34px]">
                            <i class="fas fa-trash-alt"></i>
                            <span>Delete Selected</span>
                        </button>
                    </div>

                    <button id="copyTableBtn" class="btn btn-secondary h-[34px]">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Copy All</span>
                    </button>

                    <button id="reset-btn" class="btn btn-reset h-[34px]">
                        <i class="fas fa-rotate-left"></i>
                        <span>Reset All</span>
                    </button>
                </div>
            </div>

            <!-- Table Container -->
            <div class="overflow-x-auto rounded-lg border" style="border-color: var(--card-border);">
                <table id="tasksTable" class="w-full text-left text-xs">
                    <thead>
                        <tr>
                            <th class="w-8 text-center"><input type="checkbox" id="select-all" class="w-3.5 h-3.5 rounded cursor-pointer accent-blue-600"></th>
                            <th class="w-10 text-center">No</th>
                            <th>Model</th>
                            <th>AP Version</th>
                            <th>CP Version</th>
                            <th>CSC Version</th>
                            <th>Type</th>
                            <th>QB User</th>
                            <th>QB Userdebug</th>
                            <th>Source</th>
                            <th class="text-center w-24">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach ($all_tasks as $task): ?>
                            <tr data-id="<?= $task['id'] ?>" class="<?= $task['is_manual'] ? 'manual-row' : '' ?>">
                                <td class="text-center">
                                    <input type="checkbox" class="task-checkbox w-3.5 h-3.5 rounded cursor-pointer accent-blue-600" value="<?= $task['id'] ?>">
                                </td>
                                <td class="text-center mono font-bold" style="color: var(--text-secondary);">
                                    <?= $no++ ?>
                                </td>
                                <td class="font-bold">
                                    <span class="px-2 py-0.5 rounded text-[11px] font-bold" style="background: var(--badge-bg); border: 1px solid var(--card-border); color: var(--text-primary);">
                                        <?= display_value($task['model_name']) ?>
                                    </span>
                                </td>
                                <td class="mono font-bold" style="color: var(--text-primary);">
                                    <?= display_value($task['ap']) ?>
                                </td>
                                <td class="mono font-medium" style="color: var(--text-secondary);">
                                    <?= display_value($task['cp']) ?>
                                </td>
                                <td class="mono font-bold" style="color: var(--text-primary);">
                                    <?= display_value($task['csc']) ?>
                                </td>
                                <td>
                                    <?php
                                    $req_type = $task['request_type'] ?? 'Normal';
                                    $type_color = 'bg-blue-500/10 text-blue-700 dark:text-blue-400 border-blue-500/30';
                                    if ($req_type === 'SMR') $type_color = 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/30';
                                    elseif ($req_type === 'SKU') $type_color = 'bg-purple-500/10 text-purple-700 dark:text-purple-400 border-purple-500/30';
                                    ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold border <?= $type_color ?>">
                                        <?= htmlspecialchars($req_type) ?>
                                    </span>
                                </td>
                                <td class="mono text-[11px] font-semibold" style="color: var(--text-secondary);">
                                    <?= htmlspecialchars($task['qb_user'] ?? '-') ?>
                                </td>
                                <td class="mono text-[11px] font-semibold" style="color: var(--text-secondary);">
                                    <?= htmlspecialchars($task['qb_userdebug'] ?? '-') ?>
                                </td>
                                <td>
                                    <?php if ($task['is_manual']): ?>
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/30">
                                            Manual
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-blue-500/10 text-blue-700 dark:text-blue-400 border border-blue-500/30">
                                            Filtered
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="inline-flex items-center gap-1">
                                        <button class="copy-row-btn p-1.5 rounded text-slate-500 dark:text-slate-400 hover:text-blue-600 hover:bg-slate-200 dark:hover:bg-slate-800 transition" title="Copy Baris">
                                            <i class="fas fa-copy text-xs"></i>
                                        </button>
                                        <button class="edit-btn p-1.5 rounded text-slate-500 dark:text-slate-400 hover:text-amber-600 hover:bg-slate-200 dark:hover:bg-slate-800 transition" title="Edit Task">
                                            <i class="fas fa-pencil-alt text-xs"></i>
                                        </button>
                                        <button class="delete-btn p-1.5 rounded text-slate-500 dark:text-slate-400 hover:text-rose-600 hover:bg-slate-200 dark:hover:bg-slate-800 transition" title="Hapus Task">
                                            <i class="fas fa-trash-alt text-xs"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal: Tambah Task Manual -->
    <div id="addModal" class="fixed inset-0 bg-black/60 z-50 hidden flex items-center justify-center p-4">
        <div class="content-card w-full max-w-lg p-5 relative shadow-xl">
            <div class="flex items-center justify-between pb-3 mb-4 border-b" style="border-color: var(--card-border);">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                    <h3 class="text-sm font-bold" style="color: var(--text-primary);">Tambah Task Manual</h3>
                </div>
                <button type="button" class="close-add-modal text-slate-400 hover:text-slate-600 dark:hover:text-white p-1">
                    <i class="fas fa-times text-sm"></i>
                </button>
            </div>

            <form id="addManualForm" class="space-y-3.5">
                <input type="hidden" name="action" value="add_manual">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider mb-1" style="color: var(--text-secondary);">Model</label>
                        <input type="text" name="model_name" class="modal-input" placeholder="Misal: SM-S928B">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider mb-1" style="color: var(--text-secondary);">Request Type</label>
                        <select name="request_type" class="modal-input">
                            <option value="Normal">Normal</option>
                            <option value="SMR">SMR</option>
                            <option value="SKU">SKU</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider mb-1" style="color: var(--text-secondary);">AP Version <span class="text-rose-500">*</span></label>
                    <input type="text" name="ap" class="modal-input mono font-bold" placeholder="Misal: S928BXXS3AYB2" required>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider mb-1" style="color: var(--text-secondary);">CP Version</label>
                        <input type="text" name="cp" class="modal-input mono" placeholder="Misal: S928BXXS3AYB2">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider mb-1" style="color: var(--text-secondary);">CSC Version</label>
                        <input type="text" name="csc" class="modal-input mono" placeholder="Misal: S928BOLE3AYB2">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider mb-1" style="color: var(--text-secondary);">QB User ID</label>
                        <input type="text" name="qb_user" class="modal-input mono" placeholder="Optional">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider mb-1" style="color: var(--text-secondary);">QB Userdebug ID</label>
                        <input type="text" name="qb_userdebug" class="modal-input mono" placeholder="Optional">
                    </div>
                </div>

                <div class="mt-5 pt-3.5 flex justify-end gap-2 border-t" style="border-color: var(--card-border);">
                    <button type="button" class="close-add-modal btn btn-secondary">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check"></i>
                        <span>Simpan Task</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Task -->
    <div id="editModal" class="fixed inset-0 bg-black/60 z-50 hidden flex items-center justify-center p-4">
        <div class="content-card w-full max-w-lg p-5 relative shadow-xl">
            <div class="flex items-center justify-between pb-3 mb-4 border-b" style="border-color: var(--card-border);">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                    <h3 class="text-sm font-bold" style="color: var(--text-primary);">Edit Detail Task</h3>
                </div>
                <button type="button" id="closeEditModal" class="text-slate-400 hover:text-slate-600 dark:hover:text-white p-1">
                    <i class="fas fa-times text-sm"></i>
                </button>
            </div>

            <form id="editTaskForm" class="space-y-3.5">
                <input type="hidden" name="action" value="update_task">
                <input type="hidden" id="edit-task-id" name="task_id">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider mb-1" style="color: var(--text-secondary);">Model</label>
                        <input type="text" id="edit-model_name" name="model_name" class="modal-input">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider mb-1" style="color: var(--text-secondary);">Request Type</label>
                        <select id="edit-request_type" name="request_type" class="modal-input">
                            <option value="Normal">Normal</option>
                            <option value="SMR">SMR</option>
                            <option value="SKU">SKU</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider mb-1" style="color: var(--text-secondary);">AP Version <span class="text-rose-500">*</span></label>
                    <input type="text" id="edit-ap" name="ap" class="modal-input mono font-bold" required>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider mb-1" style="color: var(--text-secondary);">CP Version</label>
                        <input type="text" id="edit-cp" name="cp" class="modal-input mono">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider mb-1" style="color: var(--text-secondary);">CSC Version</label>
                        <input type="text" id="edit-csc" name="csc" class="modal-input mono">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider mb-1" style="color: var(--text-secondary);">QB User ID</label>
                        <input type="text" id="edit-qb_user" name="qb_user" class="modal-input mono">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider mb-1" style="color: var(--text-secondary);">QB Userdebug ID</label>
                        <input type="text" id="edit-qb_userdebug" name="qb_userdebug" class="modal-input mono">
                    </div>
                </div>

                <div class="mt-5 pt-3.5 flex justify-end gap-2 border-t" style="border-color: var(--card-border);">
                    <button type="button" id="cancelEditModal" class="btn btn-secondary">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <span>Simpan Perubahan</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/2.0.7/js/dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/2.0.7/js/dataTables.tailwindcss.min.js"></script>

    <script>
        // --- Theme Synchronization & Toggle Logic ---
        function applyTheme(isLight) {
            document.documentElement.classList.toggle('light', isLight);
            const lightIcon = document.getElementById('theme-toggle-light-icon');
            const darkIcon = document.getElementById('theme-toggle-dark-icon');
            if (lightIcon) lightIcon.classList.toggle('hidden', !isLight);
            if (darkIcon) darkIcon.classList.toggle('hidden', isLight);
        }

        const savedTheme = localStorage.getItem('theme');
        applyTheme(savedTheme === 'light');

        const themeToggleBtn = document.getElementById('theme-toggle');
        if (themeToggleBtn) {
            themeToggleBtn.addEventListener('click', () => {
                const isLight = !document.documentElement.classList.contains('light');
                localStorage.setItem('theme', isLight ? 'light' : 'dark');
                applyTheme(isLight);
            });
        }

        $(document).ready(function () {
            // --- Tab Switching for Request List ---
            const tabBtnDirect = $('#tab-btn-direct');
            const tabBtnConverter = $('#tab-btn-converter');
            const tabContentDirect = $('#tab-content-direct');
            const tabContentConverter = $('#tab-content-converter');

            function switchReqTab(toConverter) {
                if (toConverter) {
                    tabBtnConverter.removeClass('text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white').addClass('bg-blue-600 text-white shadow-sm');
                    tabBtnDirect.removeClass('bg-blue-600 text-white shadow-sm').addClass('text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white');
                    tabContentDirect.addClass('hidden');
                    tabContentConverter.removeClass('hidden');
                } else {
                    tabBtnDirect.removeClass('text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white').addClass('bg-blue-600 text-white shadow-sm');
                    tabBtnConverter.removeClass('bg-blue-600 text-white shadow-sm').addClass('text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white');
                    tabContentConverter.addClass('hidden');
                    tabContentDirect.removeClass('hidden');
                }
            }

            tabBtnDirect.on('click', () => switchReqTab(false));
            tabBtnConverter.on('click', () => switchReqTab(true));

            // --- Excel Upload & Auto-Parsing ---
            const dropzone = $('#dropzone-excel');
            const fileInput = $('#excel-file-input');

            dropzone.on('click', () => fileInput.click());
            dropzone.on('dragover', function(e) {
                e.preventDefault();
                $(this).addClass('border-blue-500 bg-blue-500/5');
            });
            dropzone.on('dragleave drop', function(e) {
                e.preventDefault();
                $(this).removeClass('border-blue-500 bg-blue-500/5');
            });
            dropzone.on('drop', function(e) {
                const dt = e.originalEvent.dataTransfer;
                if (dt && dt.files && dt.files.length > 0) {
                    processExcelFile(dt.files[0]);
                }
            });

            fileInput.on('change', function(e) {
                const file = e.target.files[0];
                if (file) processExcelFile(file);
            });

            function processExcelFile(file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    try {
                        const data = new Uint8Array(e.target.result);
                        const workbook = XLSX.read(data, { type: 'array' });
                        const firstSheetName = workbook.SheetNames[0];
                        const worksheet = workbook.Sheets[firstSheetName];
                        
                        const rows = XLSX.utils.sheet_to_json(worksheet, { header: 1, defval: '' });
                        if (!rows || rows.length === 0) {
                            showToast('File Excel kosong atau tidak terbaca.', true);
                            return;
                        }

                        let headerRowIdx = -1;
                        let apIdx = -1, cpIdx = -1, cscIdx = -1, typeIdx = -1, modelIdx = -1;

                        for (let r = 0; r < Math.min(rows.length, 5); r++) {
                            const row = rows[r];
                            for (let c = 0; c < row.length; c++) {
                                const val = String(row[c]).toLowerCase().trim();
                                if (val.includes('ap') || val.includes('pda') || val.includes('version')) {
                                    apIdx = c; headerRowIdx = r;
                                } else if (val.includes('cp') || val.includes('phone') || val.includes('modem')) {
                                    cpIdx = c;
                                } else if (val.includes('csc')) {
                                    cscIdx = c;
                                } else if (val.includes('type') || val.includes('tipe')) {
                                    typeIdx = c;
                                } else if (val.includes('model') || val.includes('perangkat')) {
                                    modelIdx = c;
                                }
                            }
                            if (apIdx !== -1) break;
                        }

                        if (headerRowIdx === -1) {
                            headerRowIdx = 0;
                            apIdx = 0; cpIdx = 1; cscIdx = 2; typeIdx = 3;
                        } else {
                            if (cpIdx === -1) cpIdx = apIdx + 1;
                            if (cscIdx === -1) cscIdx = apIdx + 2;
                        }

                        let tsv = 'AP\tCP\tCSC\tType\n';
                        let parsedCount = 0;

                        for (let r = headerRowIdx + (apIdx !== -1 && headerRowIdx !== 0 ? 1 : 0); r < rows.length; r++) {
                            const row = rows[r];
                            if (!row || row.length === 0) continue;

                            let ap = String(row[apIdx] || '').trim();
                            let cp = String(row[cpIdx] || '').trim();
                            let csc = String(row[cscIdx] || '').trim();
                            let type = typeIdx !== -1 ? String(row[typeIdx] || '').trim() : '';

                            if (/^\d+$/.test(ap) && ap.length < 5 && row[apIdx + 1]) {
                                if (String(row[apIdx + 1]).toUpperCase().startsWith('SM-')) {
                                    ap = String(row[apIdx + 2] || '').trim();
                                    cp = String(row[cpIdx + 2] || '').trim();
                                    csc = String(row[cscIdx + 2] || '').trim();
                                    type = String(row[typeIdx + 2] || row[5] || '').trim();
                                } else {
                                    ap = String(row[apIdx + 1] || '').trim();
                                    cp = String(row[cpIdx + 1] || '').trim();
                                    csc = String(row[cscIdx + 1] || '').trim();
                                    type = String(row[typeIdx + 1] || row[4] || '').trim();
                                }
                            }

                            if (!ap || ap.toLowerCase() === 'ap' || ap.toLowerCase() === 'version') continue;

                            let normType = 'Normal';
                            const upType = type.toUpperCase();
                            if (upType.includes('SMR')) normType = 'SMR';
                            else if (upType.includes('SKU')) normType = 'SKU';

                            tsv += `${ap}\t${cp || ap}\t${csc || ap}\t${normType}\n`;
                            parsedCount++;
                        }

                        if (parsedCount === 0) {
                            showToast('Gagal memparsing baris firmware dari Excel.', true);
                            return;
                        }

                        $('#req-textarea').val(tsv.trim());
                        updateLineCount('#req-textarea', '#req-line-count');
                        switchReqTab(false);
                        showToast(`File "${file.name}" berhasil diupload: ${parsedCount} baris otomatis diparsing!`);
                    } catch (err) {
                        console.error(err);
                        showToast('Gagal membaca file Excel. Pastikan format file valid.', true);
                    }
                };
                reader.readAsArrayBuffer(file);
            }

            // DataTable Initialization (Sorting & Pagination removed, Custom Inline Search connected)
            const table = new DataTable('#tasksTable', {
                ordering: false,
                paging: false,
                searching: true,
                info: true,
                layout: {
                    topStart: null,
                    topEnd: null,
                    bottomStart: 'info',
                    bottomEnd: null
                },
                language: {
                    info: "Menampilkan total _TOTAL_ task",
                    infoEmpty: "Tidak ada data",
                    infoFiltered: "(disaring dari _MAX_ total task)"
                }
            });

            // Connect Custom Inline Search Input
            $('#custom-table-search').on('input', function() {
                table.search(this.value).draw();
            });

            // Live Line Counters
            function updateLineCount(id, targetId) {
                const text = $(id).val().trim();
                const lines = text ? text.split('\n').filter(l => l.trim().length > 0).length : 0;
                $(targetId).text(lines + ' baris');
            }
            $('#req-textarea').on('input', function() { updateLineCount('#req-textarea', '#req-line-count'); });
            $('#sum-textarea').on('input', function() { updateLineCount('#sum-textarea', '#sum-line-count'); });

            // Toast Management
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

            // Fallback Clipboard Copy
            function copyToClipboard(text) {
                if (navigator.clipboard && window.isSecureContext) {
                    return navigator.clipboard.writeText(text);
                } else {
                    const textArea = document.createElement("textarea");
                    textArea.value = text;
                    textArea.style.position = "fixed";
                    textArea.style.left = "-999999px";
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    return new Promise((res, rej) => {
                        document.execCommand('copy') ? res() : rej();
                        textArea.remove();
                    });
                }
            }

            // Compare Form Submission
            $('#compareForm').on('submit', function (e) {
                e.preventDefault();
                const formData = $(this).serialize();
                const submitBtn = $('#btn-submit-compare');
                const origHtml = submitBtn.html();

                submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Memproses...');

                $.ajax({
                    url: 'smart_filter.php',
                    type: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function (response) {
                        submitBtn.prop('disabled', false).html(origHtml);
                        if (response.status === 'success') {
                            showToast(response.message);
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            showToast(response.message, true);
                        }
                    },
                    error: function () {
                        submitBtn.prop('disabled', false).html(origHtml);
                        showToast('Terjadi kesalahan saat menghubungi server.', true);
                    }
                });
            });

            // Modal: Tambah Manual
            const addModal = $('#addModal');
            $('#open-add-modal-btn').on('click', () => addModal.removeClass('hidden'));
            $('.close-add-modal').on('click', () => addModal.addClass('hidden'));

            $('#addManualForm').on('submit', function (e) {
                e.preventDefault();
                $.ajax({
                    url: 'smart_filter.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function (response) {
                        if (response.status === 'success') {
                            showToast(response.message);
                            addModal.addClass('hidden');
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            showToast(response.message, true);
                        }
                    },
                    error: function () {
                        showToast('Gagal menyimpan task manual.', true);
                    }
                });
            });

            // Modal: Edit Task
            const editModal = $('#editModal');
            $('#tasksTable tbody').on('click', '.edit-btn', function () {
                const row = $(this).closest('tr');
                const taskId = row.data('id');
                const rowData = table.row(row).data();
                const clean = rowData.map(c => $('<div>').html(c).text().trim().replace(/^-\s*$/, ''));

                $('#edit-task-id').val(taskId);
                $('#edit-model_name').val(clean[2]);
                $('#edit-ap').val(clean[3]);
                $('#edit-cp').val(clean[4]);
                $('#edit-csc').val(clean[5]);
                $('#edit-request_type').val(clean[6] || 'Normal');
                $('#edit-qb_user').val(clean[7]);
                $('#edit-qb_userdebug').val(clean[8]);

                editModal.removeClass('hidden');
            });

            $('#closeEditModal, #cancelEditModal').on('click', () => editModal.addClass('hidden'));

            // Quick Action: Escape key to close modals
            $(document).on('keydown', function (e) {
                if (e.key === 'Escape' || e.key === 'Esc') {
                    addModal.addClass('hidden');
                    editModal.addClass('hidden');
                }
            });

            $('#editTaskForm').on('submit', function (e) {
                e.preventDefault();
                $.ajax({
                    url: 'smart_filter.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function (response) {
                        if (response.status === 'success') {
                            showToast(response.message);
                            editModal.addClass('hidden');
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            showToast(response.message, true);
                        }
                    },
                    error: function () {
                        showToast('Gagal memperbarui task.', true);
                    }
                });
            });

            // Single Delete
            $('#tasksTable tbody').on('click', '.delete-btn', function () {
                const row = $(this).closest('tr');
                const taskId = row.data('id');
                if (confirm('Yakin ingin menghapus task ini?')) {
                    $.ajax({
                        url: 'smart_filter.php',
                        type: 'POST',
                        data: { action: 'delete_task', task_id: taskId },
                        dataType: 'json',
                        success: function (response) {
                            if (response.status === 'success') {
                                showToast(response.message);
                                table.row(row).remove().draw(false);
                            } else {
                                showToast(response.message, true);
                            }
                        },
                        error: function () {
                            showToast('Gagal menghapus task.', true);
                        }
                    });
                }
            });

            // Copy Single Row
            $('#tasksTable tbody').on('click', '.copy-row-btn', function () {
                const rowData = table.row($(this).closest('tr')).data();
                const headers = ['Model', 'AP', 'CP', 'CSC', 'Type', 'QB User', 'QB Userdebug'];
                const cleanValues = [
                    $('<div>').html(rowData[2]).text().trim(),
                    $('<div>').html(rowData[3]).text().trim(),
                    $('<div>').html(rowData[4]).text().trim(),
                    $('<div>').html(rowData[5]).text().trim(),
                    $('<div>').html(rowData[6]).text().trim(),
                    $('<div>').html(rowData[7]).text().trim(),
                    $('<div>').html(rowData[8]).text().trim()
                ];
                const text = headers.join('\t') + '\n' + cleanValues.join('\t');
                copyToClipboard(text).then(() => {
                    showToast('Baris berhasil disalin ke clipboard.');
                });
            });

            // Bulk Actions
            const selectAll = $('#select-all');
            const bulkActions = $('#bulk-actions');

            function updateBulkState() {
                const count = $('.task-checkbox:checked').length;
                if (count > 0) {
                    bulkActions.removeClass('hidden').addClass('flex');
                } else {
                    bulkActions.removeClass('flex').addClass('hidden');
                }
            }

            $('#tasksTable').on('change', '#select-all', function () {
                $('.task-checkbox').prop('checked', this.checked);
                updateBulkState();
            });

            $('#tasksTable').on('change', '.task-checkbox', function () {
                if (!this.checked) {
                    selectAll.prop('checked', false);
                } else if ($('.task-checkbox:checked').length === $('.task-checkbox').length) {
                    selectAll.prop('checked', true);
                }
                updateBulkState();
            });

            $('#deleteSelectedBtn').on('click', function () {
                const ids = $('.task-checkbox:checked').map(function () { return $(this).val(); }).get();
                if (ids.length === 0) return;

                if (confirm(`Yakin ingin menghapus ${ids.length} task yang dipilih?`)) {
                    $.ajax({
                        url: 'smart_filter.php',
                        type: 'POST',
                        data: { action: 'bulk_delete', ids: ids },
                        dataType: 'json',
                        success: function (response) {
                            if (response.status === 'success') {
                                showToast(response.message);
                                setTimeout(() => location.reload(), 1000);
                            } else {
                                showToast(response.message, true);
                            }
                        },
                        error: function () {
                            showToast('Gagal menghapus task terpilih.', true);
                        }
                    });
                }
            });

            $('#copySelectedBtn').on('click', function () {
                const selectedRows = $('.task-checkbox:checked').closest('tr');
                if (selectedRows.length === 0) return;

                const headers = ['Model', 'AP', 'CP', 'CSC', 'Type', 'QB User', 'QB Userdebug'];
                let content = headers.join('\t') + '\n';

                selectedRows.each(function () {
                    const rowData = table.row(this).data();
                    const cleanValues = [
                        $('<div>').html(rowData[2]).text().trim(),
                        $('<div>').html(rowData[3]).text().trim(),
                        $('<div>').html(rowData[4]).text().trim(),
                        $('<div>').html(rowData[5]).text().trim(),
                        $('<div>').html(rowData[6]).text().trim(),
                        $('<div>').html(rowData[7]).text().trim(),
                        $('<div>').html(rowData[8]).text().trim()
                    ];
                    content += cleanValues.join('\t') + '\n';
                });

                copyToClipboard(content.trim()).then(() => {
                    showToast(`${selectedRows.length} baris terpilih berhasil disalin.`);
                });
            });

            // Copy All
            $('#copyTableBtn').on('click', function () {
                const headers = ['Model', 'AP', 'CP', 'CSC', 'Type', 'QB User', 'QB Userdebug'];
                let content = headers.join('\t') + '\n';
                let count = 0;

                table.rows({ search: 'applied' }).every(function () {
                    const rowData = this.data();
                    const cleanValues = [
                        $('<div>').html(rowData[2]).text().trim(),
                        $('<div>').html(rowData[3]).text().trim(),
                        $('<div>').html(rowData[4]).text().trim(),
                        $('<div>').html(rowData[5]).text().trim(),
                        $('<div>').html(rowData[6]).text().trim(),
                        $('<div>').html(rowData[7]).text().trim(),
                        $('<div>').html(rowData[8]).text().trim()
                    ];
                    content += cleanValues.join('\t') + '\n';
                    count++;
                });

                copyToClipboard(content.trim()).then(() => {
                    showToast(`${count} task berhasil disalin ke clipboard.`);
                });
            });

            // Reset All
            $('#reset-btn').on('click', function () {
                if (confirm('PERINGATAN: Apakah Anda yakin ingin menghapus SEMUA data task? Tindakan ini tidak dapat dibatalkan.')) {
                    $.ajax({
                        url: 'smart_filter.php',
                        type: 'POST',
                        data: { action: 'reset' },
                        dataType: 'json',
                        success: function (response) {
                            if (response.status === 'success') {
                                showToast(response.message);
                                setTimeout(() => location.reload(), 1000);
                            } else {
                                showToast(response.message, true);
                            }
                        },
                        error: function () {
                            showToast('Gagal mereset data.', true);
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>