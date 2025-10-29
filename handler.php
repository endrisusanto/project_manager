<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'config.php';
session_start();

// Cek apakah pengguna sudah login
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    die('Akses ditolak. Silakan login terlebih dahulu.');
}

// --- FUNGSI HELPER ---

function is_admin() {
    return isset($_SESSION["role"]) && $_SESSION["role"] === 'admin';
}

// --- NEW HELPER FOR SPECIAL ACCESS ---
function is_endri_or_admin() {
    $is_admin = isset($_SESSION["role"]) && $_SESSION["role"] === 'admin';
    $is_endri = (strtolower($_SESSION['user_details']['email'] ?? '') === 'endri@samsung.com');
    return $is_admin || $is_endri;
}

function redirect_with_error($message) {
    $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
    $referer = strtok($referer, '?');
    header("Location: " . $referer . "?error=" . urlencode($message));
    exit();
}

if (!function_exists('null_if_empty')) {
    function null_if_empty($value) {
        return trim($value) === '' ? null : trim($value);
    }
}

function redirect($url) {
    header("Location: " . $url);
    exit();
}

function add_working_days($start_date_str, $days_to_add) {
    $current_date = new DateTime($start_date_str);
    $days_added = 0;
    while ($days_added < $days_to_add) {
        $current_date->modify('+1 day');
        $day_of_week = $current_date->format('N');
        if ($day_of_week < 6) {
            $days_added++;
        }
    }
    return $current_date->format('Y-m-d');
}

function add_working_days_tracker($start_date_str, $days_to_add) {
    $current_date = new DateTime($start_date_str);
    $days_added = 0;
    while ($days_added < $days_to_add) {
        $current_date->modify('+1 day');
        $day_of_week = $current_date->format('N');
        if ($day_of_week < 6) {
            $days_added++;
        }
    }
    return $current_date->format('Y-m-d');
}


// --- LOGIKA UTAMA ---

$is_json = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $is_json ? json_decode(file_get_contents('php://input'), true) : $_POST;
$action = $data['action'] ?? null;

if (!$action) {
    die('Error: Aksi tidak ditentukan.');
}

switch ($action) {
    
    // ==========================================================
    // --- USER NOTES (To-Do List/Catatan) HANDLERS ---
    // ==========================================================
    case 'create_todo_note':
        $note_date = $_POST['todo_date'];
        $title = $_POST['todo_title'];
        $content = $_POST['todo_notes_content'];
        $priority = $_POST['todo_priority'];
        $pic_email = $_POST['todo_pic_email'] ?: $_SESSION['user_details']['email']; 

        $stmt = $conn->prepare(
            "INSERT INTO user_notes (user_email, note_date, title, content, priority) 
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("sssss", 
            $pic_email, $note_date, $title, $content, $priority
        );
        
        if ($stmt->execute()) {
            redirect('monthly_calendar.php?month=' . date('Y-m', strtotime($note_date)) . '&success=' . urlencode('Catatan berhasil disimpan!'));
        } else {
            redirect_with_error('Gagal menyimpan catatan. Error: ' . $stmt->error);
        }
        break;

    case 'update_todo_note':
        $id = $_POST['todo_id'];
        $note_date = $_POST['todo_date'];
        $title = $_POST['todo_title'];
        $content = $_POST['todo_notes_content'];
        $priority = $_POST['todo_priority'];
        $pic_email = $_POST['todo_pic_email'];

        $stmt = $conn->prepare(
            "UPDATE user_notes SET user_email=?, note_date=?, title=?, content=?, priority=? WHERE id=?"
        );
        $stmt->bind_param("sssssi", 
            $pic_email, $note_date, $title, $content, $priority, $id
        );
        
        if ($stmt->execute()) {
            redirect('monthly_calendar.php?month=' . date('Y-m', strtotime($note_date)) . '&success=' . urlencode('Catatan berhasil diperbarui!'));
        } else {
            redirect_with_error('Gagal memperbarui catatan. Error: ' . $stmt->error);
        }
        break;

    case 'delete_todo_note':
        $id = $data['id'] ?? null;
        $return_month = $data['return_month'] ?? date('Y-m');

        if (!$id) {
            redirect_with_error('ID Catatan tidak valid.');
        }

        $user_email = $_SESSION['user_details']['email'];
        if (!is_admin()) {
            $check_stmt = $conn->prepare("SELECT user_email FROM user_notes WHERE id = ?");
            $check_stmt->bind_param("i", $id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            if ($result->num_rows === 0 || $result->fetch_assoc()['user_email'] !== $user_email) {
                redirect_with_error('permission_denied');
            }
        }
        
        $stmt = $conn->prepare("DELETE FROM user_notes WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            if ($is_json) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit();
            }
            redirect('monthly_calendar.php?month=' . $return_month . '&success=' . urlencode('Catatan berhasil dihapus!'));
        } else {
             if ($is_json) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $stmt->error]);
                exit();
            }
            redirect_with_error('Gagal menghapus catatan. Error: ' . $stmt->error);
        }
        break;
        
    // ==========================================================
    // --- GBA TASK HANDLERS (Permission checks added) ---
    // ==========================================================
    case 'create_bulk_gba_task':
        // MODIFIED ACCESS CHECK
        if (!is_endri_or_admin()) {
            redirect_with_error('permission_denied');
        }
        
        $bulk_data = trim($_POST['bulk_data']);
        $lines = explode("\n", $bulk_data);
        array_shift($lines);

        $users_result = $conn->query("SELECT email FROM users WHERE role = 'user' ORDER BY id ASC");
        $pic_list = [];
        while ($user = $users_result->fetch_assoc()) {
            $pic_list[] = $user['email'];
        }

        if (empty($pic_list)) {
            redirect_with_error('Tidak ada user yang bisa di-assign sebagai PIC.');
        }

        require_once 'marketing_name_mapper.php';
        $pic_index = 0;
        
        $stmt = $conn->prepare(
            "INSERT INTO gba_tasks (project_name, model_name, pic_email, ap, cp, csc, qb_user, qb_userdebug, progress_status, request_date, test_plan_type, deadline, sign_off_date) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Task Baru', ?, ?, ?, ?)"
        );

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $parts = preg_split('/\s+/', $line);
            
            $model_name = $parts[0] ?? '';
            $ap = $parts[1] ?? '';
            $cp = $parts[2] ?? '';
            $csc = $parts[3] ?? '';
            $type_request_raw = $parts[4] ?? 'Normal MR';
            $qb_user = $parts[5] ?? '';
            $qb_userdebug = $parts[6] ?? '';
            
            $marketing_name = 'N/A';
            foreach ($model_mapping as $key => $value) {
                if (strpos(strtoupper($model_name), $key) === 0) {
                    $marketing_name = $value;
                    break;
                }
            }

            $pic_email = $pic_list[$pic_index % count($pic_list)];
            $pic_index++;
            
            $request_date = date('Y-m-d');
            
            $type_request = strtoupper($type_request_raw);
            $valid_types = ['Regular Variant', 'SKU', 'Normal MR', 'SMR', 'Simple Exception MR'];
            $test_plan_type = 'Normal MR';
            
            if (in_array($type_request_raw, $valid_types)) {
                $test_plan_type = $type_request_raw;
            } else {
                switch ($type_request) {
                    case 'SMR': $test_plan_type = 'SMR'; break;
                    case 'SKU': $test_plan_type = 'SKU'; break;
                    case 'NORMAL': $test_plan_type = 'Normal MR'; break;
                    case 'SIMPLE': $test_plan_type = 'Simple Exception MR'; break;
                    case 'REGULAR': $test_plan_type = 'Regular Variant'; break;
                }
            }
            
            $deadline = add_working_days($request_date, 7);
            $sign_off_date = $deadline;
            
            $stmt->bind_param("ssssssssssss", 
                $marketing_name, $model_name, $pic_email, $ap, $cp, $csc, 
                $qb_user, $qb_userdebug, $request_date, $test_plan_type,
                $deadline, $sign_off_date
            );
            $stmt->execute();
        }

        $stmt->close();
        redirect('index.php');
        break;

    case 'create_gba_task':
        
        $checklist_json = isset($data['checklist']) ? json_encode($data['checklist']) : null;
        $is_urgent = isset($data['is_urgent']) && $data['is_urgent'] == 1 ? 1 : 0;
        
        $stmt = $conn->prepare(
            "INSERT INTO gba_tasks (project_name, model_name, pic_email, ap, cp, csc, qb_user, qb_userdebug, test_plan_type, progress_status, request_date, submission_date, deadline, sign_off_date, approved_date, base_submission_id, submission_id, reviewer_email, is_urgent, notes, test_items_checklist) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("ssssssssssssssssssiss",
            $data['project_name'], $data['model_name'], $data['pic_email'], 
            null_if_empty($data['ap']), null_if_empty($data['cp']), null_if_empty($data['csc']),
            null_if_empty($data['qb_user']), null_if_empty($data['qb_userdebug']), $data['test_plan_type'], $data['progress_status'],
            null_if_empty($data['request_date']), null_if_empty($data['submission_date']), null_if_empty($data['deadline']),
            null_if_empty($data['sign_off_date']), null_if_empty($data['approved_date']),
            null_if_empty($data['base_submission_id']), null_if_empty($data['submission_id']), null_if_empty($data['reviewer_email']),
            $is_urgent, $data['notes'], $checklist_json
        );
        
        $stmt->execute();
        redirect('index.php');
        break;

    case 'update_gba_task':
        $checklist_json = isset($data['checklist']) ? json_encode($data['checklist']) : null;
        $is_urgent = isset($data['is_urgent']) && $data['is_urgent'] == 1 ? 1 : 0;
        
        $stmt = $conn->prepare(
            "UPDATE gba_tasks SET project_name=?, model_name=?, pic_email=?, ap=?, cp=?, csc=?, qb_user=?, qb_userdebug=?, test_plan_type=?, progress_status=?, request_date=?, submission_date=?, deadline=?, sign_off_date=?, approved_date=?, base_submission_id=?, submission_id=?, reviewer_email=?, is_urgent=?, notes=?, test_items_checklist=?
            WHERE id=?"
        );
        $stmt->bind_param("ssssssssssssssssssissi",
            $data['project_name'], $data['model_name'], $data['pic_email'], 
            null_if_empty($data['ap']), null_if_empty($data['cp']), null_if_empty($data['csc']),
            null_if_empty($data['qb_user']), null_if_empty($data['qb_userdebug']), $data['test_plan_type'], $data['progress_status'],
            null_if_empty($data['request_date']), null_if_empty($data['submission_date']), null_if_empty($data['deadline']),
            null_if_empty($data['sign_off_date']), null_if_empty($data['approved_date']),
            null_if_empty($data['base_submission_id']), null_if_empty($data['submission_id']), null_if_empty($data['reviewer_email']),
            $is_urgent, $data['notes'], $checklist_json, $data['id']
        );
        
        $stmt->execute();
        $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
        redirect($referer);
        break;


    case 'delete_gba_task':
        if (!is_admin()) {
            redirect_with_error('permission_denied');
        }
        $stmt = $conn->prepare("DELETE FROM gba_tasks WHERE id = ?");
        $stmt->bind_param("i", $data['id']);
        $stmt->execute();
        $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
        redirect($referer);
        break;

    case 'update_task_status':
        header('Content-Type: application/json');
        
        // Cek izin (Kanban drag and drop hanya untuk admin atau endri)
        // if (!is_endri_or_admin()) {
        //     echo json_encode(['success' => false, 'error' => 'Akses ditolak: Anda tidak diizinkan mengubah status task via drag and drop.']);
        //     exit();
        // }

        $task_id = $data['task_id'];
        $new_status = $data['new_status'];
        
        $today = date('Y-m-d');
        
        $get_task_stmt = $conn->prepare("SELECT test_plan_type FROM gba_tasks WHERE id = ?");
        $get_task_stmt->bind_param("i", $task_id);
        $get_task_stmt->execute();
        $task_result = $get_task_stmt->get_result();
        $task_data = $task_result->fetch_assoc();
        $get_task_stmt->close();

        $sql = "UPDATE gba_tasks SET progress_status = ?";
        $params = [$new_status];
        $types = "s";

        if (in_array($new_status, ['Submitted', 'Approved', 'Passed'])) {
            $sql .= ", submission_date = COALESCE(submission_date, ?)";
            $params[] = $today;
            $types .= "s";

            if (in_array($new_status, ['Approved', 'Passed'])) {
                $sql .= ", approved_date = COALESCE(approved_date, ?)";
                $params[] = $today;
                $types .= "s";
            }
            
            if ($task_data) {
                $test_plan_items = [
                    'Regular Variant' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'], 
                    'SKU' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
                    'Normal MR' => ['CTS', 'GTS', 'CTS-Verifier', 'ATM'], 
                    'SMR' => ['CTS', 'GTS', 'STS', 'SCAT'], 
                    'Simple Exception MR' => ['STS']
                ];
                $plan_type = $task_data['test_plan_type'];
                if (isset($test_plan_items[$plan_type])) {
                    $checklist = [];
                    foreach ($test_plan_items[$plan_type] as $item) {
                        $item_key = str_replace([' ', '-'], '_', $item);
                        $checklist[$item_key] = "1";
                    }
                    $sql .= ", test_items_checklist = ?";
                    $params[] = json_encode($checklist);
                    $types .= "s";
                }
            }
        } elseif ($new_status === 'Task Baru') {
            $sql .= ", test_items_checklist = NULL, submission_date = NULL, approved_date = NULL";
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $task_id;
        $types .= "i";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        exit();

    case 'update_task_status_tracker':
        header('Content-Type: application/json');
        $task_id = $data['task_id'] ?? 0;
        $category = $data['category'] ?? '';
        $ap_version = $data['ap_version'] ?? '';
        
        date_default_timezone_set('Asia/Jakarta');
        
        $now = new DateTime();
        $today = $now->format('Y-m-d');
        $tomorrow = add_working_days_tracker($today, 1);
        $categorized_at = $now->format('Y-m-d H:i:s');

        $target_submit_date = '';
        $target_approved_date = '';
        
        if (in_array($category, ['GA Submit', 'GA Follow Up'])) {
            $target_submit_date = $today;
            $target_approved_date = $tomorrow;
        } elseif ($category === 'GA First Run') {
            $target_submit_date = $tomorrow;
            $target_approved_date = $tomorrow;
        } else {
            echo json_encode(['success' => false, 'error' => 'Kategori tidak valid.']);
            exit();
        }
        
        $target_submit_display = date('d-m', strtotime($target_submit_date));
        $target_approved_display = date('d-m', strtotime($target_approved_date));

        $new_note_string = "{$category}: - {$ap_version} [Target Submit: {$target_submit_display}] [Target Approved: {$target_approved_display}] [CATEGORIZED_AT: {$categorized_at}]";

        $new_status = 'Test Ongoing';

        $sql = "UPDATE gba_tasks SET progress_status = ?, notes = ? WHERE id = ?";
        $params = [$new_status, $new_note_string, $task_id];
        $types = "ssi";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'category' => $category,
                'ap_version' => $ap_version,
                'target_submit' => $target_submit_display,
                'target_approved' => $target_approved_display,
                'categorized_at' => $categorized_at
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        exit();

    case 'toggle_urgent': // HANDLER UNTUK TOMBOL DI KANBAN
        header('Content-Type: application/json');
        
        // MODIFIED ACCESS CHECK
        // if (!is_endri_or_admin()) {
        //     echo json_encode(['success' => false, 'error' => 'Akses ditolak: Anda tidak diizinkan mengubah status urgent.']);
        //     exit();
        // }

        $task_id = $data['task_id'];
        
        // Fetch current status
        $stmt = $conn->prepare("SELECT is_urgent FROM gba_tasks WHERE id = ?");
        $stmt->bind_param("i", $task_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $current = $result->fetch_assoc();
        $new_status = $current['is_urgent'] == 1 ? 0 : 1;
        $stmt->close();
        
        // Update status
        $stmt = $conn->prepare("UPDATE gba_tasks SET is_urgent = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_status, $task_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'is_urgent' => $new_status == 1]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        exit();

    default:
        if ($is_json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Aksi tidak valid atau tidak ditemukan.']);
        } else {
            die('Error: Aksi tidak valid atau tidak ditemukan.');
        }
        break;
}

if (isset($stmt)) {
    $stmt->close();
}
$conn->close();
?>