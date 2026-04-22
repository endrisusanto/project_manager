<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'config.php';
session_start();

// Cek apakah pengguna sudah login
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    die('Akses ditolak. Silakan login terlebih dahulu.');
}

// Tambahkan variabel email pengguna yang sedang login
$user_email = $_SESSION['user_details']['email'] ?? null;
if (!$user_email) {
    // Jika sesi user_details belum diset dengan email, fallback ke error.
    die('Akses ditolak: Informasi pengguna tidak ditemukan.');
}

// --- NEW HELPER FOR LOGGING (Assumed to be present or re-added) ---
function log_activity($conn, $task_id, $action_type, $details, $user_email)
{
    $task_id_param = is_numeric($task_id) ? (int) $task_id : null;

    $log_stmt = $conn->prepare(
        "INSERT INTO activity_log (task_id, action_type, details, user_email) 
         VALUES (?, ?, ?, ?)"
    );
    $log_stmt->bind_param("isss", $task_id_param, $action_type, $details, $user_email);
    @$log_stmt->execute();
    @$log_stmt->close();
}


// --- FUNGSI HELPER LAIN ---

function is_admin()
{
    return isset($_SESSION["role"]) && $_SESSION["role"] === 'admin';
}

function is_endri_or_admin()
{
    $is_admin = isset($_SESSION["role"]) && $_SESSION["role"] === 'admin';
    $is_endri = (strtolower($_SESSION['user_details']['email'] ?? '') === 'endri@samsung.com');
    return $is_admin || $is_endri;
}

function redirect_with_error($message)
{
    $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
    $referer = strtok($referer, '?');
    header("Location: " . $referer . "?error=" . urlencode($message));
    exit();
}

if (!function_exists('null_if_empty')) {
    function null_if_empty($value)
    {
        return trim($value) === '' ? null : trim($value);
    }
}

function redirect($url)
{
    header("Location: " . $url);
    exit();
}

function add_working_days($start_date_str, $days_to_add)
{
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

function add_working_days_tracker($start_date_str, $days_to_add)
{
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


// --- CREATE TABLE IF NOT EXISTS FOR ACTIVITY LOGS (TRACKER) ---
$conn->query("CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    pic_name VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// --- LOGIKA UTAMA ---
$is_json = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $is_json ? json_decode(file_get_contents('php://input'), true) : $_POST;
$action = $data['action'] ?? null;

if (!$action) {
    die('Error: Aksi tidak ditentukan.');
}

switch ($action) {
    // ... (existing cases) ...

    // ==========================================================
    // --- ACTIVITY LOG HANDLERS (TRACKER) ---
    // ==========================================================
    case 'save_activity_log':
        header('Content-Type: application/json');
        $pic_name = $data['pic_name'];
        $content = $data['content'];

        $stmt = $conn->prepare("INSERT INTO activity_logs (user_email, pic_name, content) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $user_email, $pic_name, $content);

        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        exit();

    case 'get_activity_logs':
        header('Content-Type: application/json');

        $result = $conn->query("SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 50");
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            // Format timestamp for display
            $row['timestamp'] = date('d-m H:i:s', strtotime($row['created_at']));
            $logs[] = $row;
        }

        echo json_encode(['success' => true, 'logs' => $logs]);
        exit();

    case 'delete_activity_log':
        header('Content-Type: application/json');
        $id = $data['id'];

        $stmt = $conn->prepare("DELETE FROM activity_logs WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        exit();

    // ... (rest of cases) ...

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
        $stmt->bind_param(
            "sssss",
            $pic_email,
            $note_date,
            $title,
            $content,
            $priority
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
        $stmt->bind_param(
            "sssssi",
            $pic_email,
            $note_date,
            $title,
            $content,
            $priority,
            $id
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

        $current_user_email = $_SESSION['user_details']['email'];
        if (!is_admin()) {
            $check_stmt = $conn->prepare("SELECT user_email FROM user_notes WHERE id = ?");
            $check_stmt->bind_param("i", $id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            if ($result->num_rows === 0 || $result->fetch_assoc()['user_email'] !== $current_user_email) {
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
    // --- GBA TASK HANDLERS (LOGGING IMPLEMENTED) ---
    // ==========================================================
    case 'create_bulk_gba_task':
        // MODIFIED ACCESS CHECK
        // if (!is_endri_or_admin()) {
        //     redirect_with_error('permission_denied');
        // }

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

        // START: MODIFIED LOGIC TO CONTINUE PIC PATTERN
        $pic_index = 0; // Default start from the first PIC
        $last_pic_email = null;

        // 1. Get the PIC from the last created task
        $last_task_stmt = $conn->prepare("SELECT pic_email FROM gba_tasks ORDER BY id DESC LIMIT 1");
        if ($last_task_stmt && $last_task_stmt->execute()) {
            $last_task_result = $last_task_stmt->get_result();

            if ($last_task_result->num_rows > 0) {
                $last_pic_email = $last_task_result->fetch_assoc()['pic_email'];
            }
            $last_task_stmt->close();
        }

        // 2. Find the index of the last PIC and set the starting index to the next PIC
        if ($last_pic_email) {
            $last_index = array_search($last_pic_email, $pic_list);
            if ($last_index !== false) {
                // Set pic_index to the index of the next person in the list (round-robin)
                $pic_index = ($last_index + 1) % count($pic_list);
            }
        }
        // END: MODIFIED LOGIC

        $created_count = 0;

        $stmt = $conn->prepare(
            "INSERT INTO gba_tasks (project_name, model_name, pic_email, ap, cp, csc, qb_user, qb_userdebug, progress_status, request_date, test_plan_type, deadline, sign_off_date, updated_by_email) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Task Baru', ?, ?, ?, ?, ?)"
        );

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line))
                continue;

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
                    case 'SMR':
                        $test_plan_type = 'SMR';
                        break;
                    case 'SKU':
                        $test_plan_type = 'SKU';
                        break;
                    case 'NORMAL':
                        $test_plan_type = 'Normal MR';
                        break;
                    case 'SIMPLE':
                        $test_plan_type = 'Simple Exception MR';
                        break;
                    case 'REGULAR':
                        $test_plan_type = 'Regular Variant';
                        break;
                }
            }

            $deadline = add_working_days($request_date, 7);
            $sign_off_date = $deadline;

            $stmt->bind_param(
                "sssssssssssss",
                $marketing_name,
                $model_name,
                $pic_email,
                $ap,
                $cp,
                $csc,
                $qb_user,
                $qb_userdebug,
                $request_date,
                $test_plan_type,
                $deadline,
                $sign_off_date,
                $user_email
            );

            if ($stmt->execute()) {
                $last_id = $conn->insert_id;
                log_activity($conn, $last_id, 'TASK_CREATED', "New task for model {$model_name} created via bulk add.", $user_email);
                $created_count++;
            }
        }

        $stmt->close();
        redirect('index.php?success=' . urlencode("Berhasil membuat {$created_count} task baru."));
        break;

    case 'create_gba_task':

        $raw_checklist = isset($data['checklist']) ? $data['checklist'] : [];
        $filtered_checklist = array_filter($raw_checklist, fn($v) => $v == 1);
        $checklist_json = !empty($filtered_checklist) ? json_encode($filtered_checklist) : null;
        $is_urgent = isset($data['is_urgent']) && $data['is_urgent'] == 1 ? 1 : 0;

        // Store null_if_empty results in variables first (bind_param requires variables by reference)
        $c_project_name  = $data['project_name'];
        $c_model_name    = $data['model_name'];
        $c_pic_email     = $data['pic_email'];
        $c_ap            = null_if_empty($data['ap']);
        $c_cp            = null_if_empty($data['cp']);
        $c_csc           = null_if_empty($data['csc']);
        $c_qb_user       = null_if_empty($data['qb_user']);
        $c_qb_userdebug  = null_if_empty($data['qb_userdebug']);
        $c_test_plan     = $data['test_plan_type'];
        $c_status        = $data['progress_status'];
        $c_request_date  = null_if_empty($data['request_date']);
        $c_submission    = null_if_empty($data['submission_date']);
        $c_deadline      = null_if_empty($data['deadline']);
        $c_sign_off      = null_if_empty($data['sign_off_date']);
        $c_approved      = null_if_empty($data['approved_date']);
        $c_base_sub_id   = null_if_empty($data['base_submission_id']);
        $c_sub_id        = null_if_empty($data['submission_id']);
        $c_reviewer      = null_if_empty($data['reviewer_email']);
        $c_notes         = $data['notes'];

        $stmt = $conn->prepare(
            "INSERT INTO gba_tasks (project_name, model_name, pic_email, ap, cp, csc, qb_user, qb_userdebug, test_plan_type, progress_status, request_date, submission_date, deadline, sign_off_date, approved_date, base_submission_id, submission_id, reviewer_email, is_urgent, notes, test_items_checklist, updated_by_email) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "sssssssssssssssssssssi",
            $c_project_name,
            $c_model_name,
            $c_pic_email,
            $c_ap,
            $c_cp,
            $c_csc,
            $c_qb_user,
            $c_qb_userdebug,
            $c_test_plan,
            $c_status,
            $c_request_date,
            $c_submission,
            $c_deadline,
            $c_sign_off,
            $c_approved,
            $c_base_sub_id,
            $c_sub_id,
            $c_reviewer,
            $is_urgent,
            $c_notes,
            $checklist_json,
            $user_email
        );

        $stmt->execute();
        $last_id = $conn->insert_id;
        log_activity($conn, $last_id, 'TASK_CREATED', "New task '{$data['model_name']}' created.", $user_email);
        redirect('index.php');
        break;

    case 'update_gba_task':
        $id = $data['id'];

        // 1. Fetch OLD data before update
        $old_task_stmt = $conn->prepare("SELECT * FROM gba_tasks WHERE id = ?");
        $old_task_stmt->bind_param("i", $id);
        $old_task_stmt->execute();
        $old_task_result = $old_task_stmt->get_result();
        $old_task_data = $old_task_result->fetch_assoc();
        $old_task_stmt->close();

        if (!$old_task_data) {
            redirect_with_error('Task tidak ditemukan.');
        }

        $raw_checklist = isset($data['checklist']) ? $data['checklist'] : [];
        $filtered_checklist = array_filter($raw_checklist, fn($v) => $v == 1);
        $checklist_json = !empty($filtered_checklist) ? json_encode($filtered_checklist) : null;
        $is_urgent = isset($data['is_urgent']) && $data['is_urgent'] == 1 ? 1 : 0;

        // --- 2. COMPARE DATA AND BUILD DETAILS LOG ---
        $changes = [];
        $fields_to_check = [
            'project_name' => 'Marketing Name',
            'model_name' => 'Model Name',
            'pic_email' => 'PIC',
            'ap' => 'AP',
            'cp' => 'CP',
            'csc' => 'CSC',
            'qb_user' => 'QB User',
            'qb_userdebug' => 'QB Userdebug',
            'test_plan_type' => 'Test Plan Type',
            'progress_status' => 'Status Progress',
            'request_date' => 'Request Date',
            'submission_date' => 'Submission Date',
            'deadline' => 'Deadline',
            'sign_off_date' => 'Sign-Off Date',
            'approved_date' => 'Approved Date',
            'base_submission_id' => 'Base Submission ID',
            'submission_id' => 'Submission ID',
            'reviewer_email' => 'Reviewer Email',
            'notes' => 'Notes'
        ];

        // Check simple fields
        foreach ($fields_to_check as $db_field => $display_name) {
            // Handle null_if_empty normalization for POST data
            $new_value = $db_field === 'notes' ? $data[$db_field] : null_if_empty($data[$db_field]);
            $old_value = $old_task_data[$db_field];

            // Normalize date and null values for comparison
            $normalized_new = $new_value === null ? '' : (is_string($new_value) ? trim($new_value) : $new_value);
            $normalized_old = $old_value === null ? '' : (is_string($old_value) ? trim($old_value) : $old_value);

            if ($db_field === 'notes') {
                // Notes use HTML content, just check if it's different.
                if ($normalized_new !== $normalized_old) {
                    // Check if there is meaningful content difference (e.g., ignoring whitespace/tags changes if content is same)
                    // For simplicity and assuming structured notes, we just flag it as changed.
                    $changes[] = "{$display_name} has been modified (view Task details for content).";
                }
            } else if ($normalized_new !== $normalized_old) {
                // For dates, display 'empty' instead of ''
                $old_display = empty($normalized_old) ? 'empty' : $normalized_old;
                $new_display = empty($normalized_new) ? 'empty' : $normalized_new;
                $changes[] = "{$display_name} changed from '{$old_display}' to '{$new_display}'";
            }
        }

        // Check is_urgent toggle
        $old_is_urgent = (int) ($old_task_data['is_urgent'] ?? 0);
        if ($old_is_urgent !== $is_urgent) {
            $old_display = $old_is_urgent ? 'YES' : 'NO';
            $new_display = $is_urgent ? 'YES' : 'NO';
            $changes[] = "Urgency status toggled from '{$old_display}' to '{$new_display}'";
        }

        // Check checklist changes (complex - assumes JSON content comparison)
        $old_checklist = json_decode($old_task_data['test_items_checklist'] ?? '{}', true) ?: [];
        $new_checklist = json_decode($checklist_json ?? '{}', true) ?: [];

        $checklist_changes = [];
        $all_keys = array_unique(array_merge(array_keys($old_checklist), array_keys($new_checklist)));

        foreach ($all_keys as $key) {
            // Convert to boolean check based on value '1'
            $old_val = isset($old_checklist[$key]) && (string) $old_checklist[$key] === '1';
            $new_val = isset($new_checklist[$key]) && (string) $new_checklist[$key] === '1';
            $item_name = str_replace('_', ' ', $key);

            if ($old_val !== $new_val) {
                $status = $new_val ? 'CHECKED' : 'UNCHECKED';
                $checklist_changes[] = "Item '{$item_name}' set to {$status}";
            }
        }

        if (!empty($checklist_changes)) {
            $changes[] = "Test Checklist modified: " . implode(', ', $checklist_changes);
        } else if (($old_task_data['test_items_checklist'] ?? '') !== ($checklist_json ?? '')) {
            // Fallback for technical JSON change (e.g., re-saving empty JSON, key order, etc.)
            $changes[] = "Test Checklist data was technically saved again.";
        }

        $log_details = empty($changes)
            ? "Task '{$old_task_data['model_name']}' updated, but no measurable field change detected."
            : "Task '{$old_task_data['model_name']}' updated. Changes: " . implode(' | ', $changes);

        // --- 3. Execute Update ---
        // Store null_if_empty results in variables first (bind_param requires variables by reference)
        $u_project_name  = $data['project_name'];
        $u_model_name    = $data['model_name'];
        $u_pic_email     = $data['pic_email'];
        $u_ap            = null_if_empty($data['ap']);
        $u_cp            = null_if_empty($data['cp']);
        $u_csc           = null_if_empty($data['csc']);
        $u_qb_user       = null_if_empty($data['qb_user']);
        $u_qb_userdebug  = null_if_empty($data['qb_userdebug']);
        $u_test_plan     = $data['test_plan_type'];
        $u_status        = $data['progress_status'];
        $u_request_date  = null_if_empty($data['request_date']);
        $u_submission    = null_if_empty($data['submission_date']);
        $u_deadline      = null_if_empty($data['deadline']);
        $u_sign_off      = null_if_empty($data['sign_off_date']);
        $u_approved      = null_if_empty($data['approved_date']);
        $u_base_sub_id   = null_if_empty($data['base_submission_id']);
        $u_sub_id        = null_if_empty($data['submission_id']);
        $u_reviewer      = null_if_empty($data['reviewer_email']);
        $u_notes         = $data['notes'];
        $u_task_id       = (int) $data['id'];

        $stmt = $conn->prepare(
            "UPDATE gba_tasks SET project_name=?, model_name=?, pic_email=?, ap=?, cp=?, csc=?, qb_user=?, qb_userdebug=?, test_plan_type=?, progress_status=?, request_date=?, submission_date=?, deadline=?, sign_off_date=?, approved_date=?, base_submission_id=?, submission_id=?, reviewer_email=?, is_urgent=?, notes=?, test_items_checklist=?, updated_by_email=?
            WHERE id=?"
        );

        $stmt->bind_param(
            "ssssssssssssssssssisssi",
            $u_project_name,
            $u_model_name,
            $u_pic_email,
            $u_ap,
            $u_cp,
            $u_csc,
            $u_qb_user,
            $u_qb_userdebug,
            $u_test_plan,
            $u_status,
            $u_request_date,
            $u_submission,
            $u_deadline,
            $u_sign_off,
            $u_approved,
            $u_base_sub_id,
            $u_sub_id,
            $u_reviewer,
            $is_urgent,
            $u_notes,
            $checklist_json,
            $user_email,
            $u_task_id
        );

        $stmt->execute();
        log_activity($conn, $data['id'], 'TASK_UPDATED', $log_details, $user_email);
        $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
        redirect($referer);
        break;


    case 'delete_gba_task':
        if (!is_admin()) {
            redirect_with_error('permission_denied');
        }

        // Ambil model name untuk log
        $get_model_name_stmt = $conn->prepare("SELECT model_name FROM gba_tasks WHERE id = ?");
        $get_model_name_stmt->bind_param("i", $data['id']);
        $get_model_name_stmt->execute();
        $model_name = $get_model_name_stmt->get_result()->fetch_assoc()['model_name'] ?? "ID #{$data['id']}";
        $get_model_name_stmt->close();

        $stmt = $conn->prepare("DELETE FROM gba_tasks WHERE id = ?");
        $stmt->bind_param("i", $data['id']);
        $stmt->execute();
        log_activity($conn, $data['id'], 'TASK_DELETED', "Task '{$model_name}' deleted by admin.", $user_email);
        $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
        redirect($referer);
        break;

    case 'update_task_status':
        header('Content-Type: application/json');

        $task_id = $data['task_id'];
        $new_status = $data['new_status'];
        $today = date('Y-m-d');

        // 1. Fetch original status and model name for logging
        $get_old_status_stmt = $conn->prepare("SELECT progress_status, model_name, test_plan_type FROM gba_tasks WHERE id = ?");
        $get_old_status_stmt->bind_param("i", $task_id);
        $get_old_status_stmt->execute();
        $old_task_data = $get_old_status_stmt->get_result()->fetch_assoc();
        $get_old_status_stmt->close();

        $original_status = $old_task_data['progress_status'] ?? 'N/A';
        $model_name = $old_task_data['model_name'] ?? "ID #{$task_id}";


        // 2. Prepare update query
        $sql = "UPDATE gba_tasks SET progress_status = ?, updated_by_email = ?";
        $params = [$new_status, $user_email];
        $types = "ss";

        if (in_array($new_status, ['Submitted', 'Approved', 'Passed'])) {
            $sql .= ", submission_date = COALESCE(submission_date, ?)";
            $params[] = $today;
            $types .= "s";

            if (in_array($new_status, ['Approved', 'Passed'])) {
                $sql .= ", approved_date = COALESCE(approved_date, ?)";
                $params[] = $today;
                $types .= "s";
            }

            if ($old_task_data) {
                // Logic to auto-check checklist when status moves past Task Baru
                $test_plan_items = [
                    'Regular Variant' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
                    'SKU' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
                    'Normal MR' => ['CTS', 'GTS', 'CTS-Verifier', 'ATM'],
                    'SMR' => ['CTS', 'GTS', 'STS', 'SCAT'],
                    'Simple Exception MR' => ['STS']
                ];
                $plan_type = $old_task_data['test_plan_type'];
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
            $details = "Status task '{$model_name}' updated: '{$original_status}' -> '{$new_status}' (via Kanban/Drag-Drop).";
            log_activity($conn, $task_id, 'STATUS_CHANGE', $details, $user_email);
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

        $current_user_email = $user_email;

        date_default_timezone_set('Asia/Jakarta');

        $now = new DateTime();
        $today = $now->format('Y-m-d');
        // Mendefinisikan HARI KERJA BERIKUTNYA
        $next_working_day = add_working_days_tracker($today, 1);

        $categorized_at = $now->format('Y-m-d H:i:s');

        $target_submit_date = '';
        $target_approved_date = '';

        // --- LOGIKA BARU UNTUK KATEGORI GA SUBMISSION TRACKER ---
        if ($category === 'GA Submit' || $category === 'GA Follow Up') {
            // Target Submit: HARI INI, Target Approved: HARI KERJA BERIKUTNYA
            $target_submit_date = $today;
            $target_approved_date = $next_working_day;
        } elseif (in_array($category, ['GA Follow Up Besok', 'GA First Run'])) {
            // Target Submit: HARI KERJA BERIKUTNYA, Target Approved: HARI KERJA BERIKUTNYA
            $target_submit_date = $next_working_day;
            $target_approved_date = $next_working_day;
        } else {
            echo json_encode(['success' => false, 'error' => 'Kategori tidak valid.']);
            exit();
        }

        $target_submit_display = date('d-m', strtotime($target_submit_date));
        $target_approved_display = date('d-m', strtotime($target_approved_date));

        $new_note_string = "{$category}: - {$ap_version} [Target Submit: {$target_submit_display}] [Target Approved: {$target_approved_display}] [CATEGORIZED_AT: {$categorized_at}]";

        $new_status = 'Test Ongoing';

        // 1. Kueri UPDATE Task (dengan updated_by_email)
        $sql = "UPDATE gba_tasks SET progress_status = ?, notes = ?, updated_by_email = ? WHERE id = ?";
        $params = [$new_status, $new_note_string, $current_user_email, $task_id];
        $types = "sssi"; // s (progress_status), s (notes), s (updated_by_email), i (id)

        $stmt = $conn->prepare($sql);

        if (!$stmt->bind_param($types, ...$params)) {
            echo json_encode(['success' => false, 'error' => 'Gagal binding parameter untuk update task.']);
            exit();
        }

        if ($stmt->execute()) {

            // 2. Logging Aktivitas
            $get_model_name_stmt = $conn->prepare("SELECT model_name FROM gba_tasks WHERE id = ?");
            $get_model_name_stmt->bind_param("i", $task_id);
            $get_model_name_stmt->execute();
            $model_name = $get_model_name_stmt->get_result()->fetch_assoc()['model_name'] ?? "ID #{$task_id}";
            $get_model_name_stmt->close();

            $details = "Task '{$model_name}' categorized as '{$category}' (Status set to Test Ongoing). Note detail: AP Version set to '{$ap_version}'.";
            log_activity($conn, $task_id, 'STATUS_TRACKER_CHANGE', $details, $current_user_email);

            // 3. Response Sukses
            echo json_encode([
                'success' => true,
                'category' => $category,
                'ap_version' => $ap_version,
                'target_submit' => $target_submit_display,
                'target_approved' => $target_approved_display,
                'categorized_at' => $categorized_at
            ]);
        } else {
            // Kegagalan eksekusi kueri
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        exit();

    case 'toggle_urgent': // HANDLER UNTUK TOMBOL DI KANBAN
        header('Content-Type: application/json');

        $task_id = $data['task_id'];

        // Fetch current status
        $stmt = $conn->prepare("SELECT is_urgent, model_name FROM gba_tasks WHERE id = ?");
        $stmt->bind_param("i", $task_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $current = $result->fetch_assoc();
        $new_status = $current['is_urgent'] == 1 ? 0 : 1;
        $model_name = $current['model_name'] ?? "ID #{$task_id}";
        $stmt->close();

        // Update status
        $stmt = $conn->prepare("UPDATE gba_tasks SET is_urgent = ?, updated_by_email = ? WHERE id = ?");
        $stmt->bind_param("isi", $new_status, $user_email, $task_id);

        $action_detail = $new_status == 1 ? 'marked as URGENT (NO -> YES)' : 'unmarked as URGENT (YES -> NO)';

        if ($stmt->execute()) {
            $details = "Task '{$model_name}' {$action_detail}.";
            log_activity($conn, $task_id, 'TOGGLE_URGENT', $details, $user_email);
            echo json_encode(['success' => true, 'is_urgent' => $new_status == 1]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        exit();

    // ==========================================================
    // --- REDISTRIBUTE REQUEST DATES ---
    // ==========================================================
    case 'redistribute_request_dates':
        header('Content-Type: application/json');

        $max_per_day = 3; // Maks task per PIC per hari

        // Ambil semua task yang belum submitted (Task Baru, Downloaded, Test Ongoing, Pending Feedback, Feedback Sent)
        // Urutkan per PIC, lalu request_date ASC, lalu id ASC — agar urutan existing date terjaga
        $pending_sql = "SELECT id, pic_email, request_date FROM gba_tasks 
                        WHERE (progress_status = 'Task Baru' OR progress_status = 'Test Ongoing' OR progress_status = 'Pending Feedback' OR progress_status = 'Feedback Sent' OR progress_status = 'Downloaded')
                        AND submission_date IS NULL AND is_urgent = 0
                        ORDER BY pic_email ASC, request_date ASC, id ASC";
        $pending_result = $conn->query($pending_sql);

        if (!$pending_result) {
            echo json_encode(['success' => false, 'error' => 'Query gagal: ' . $conn->error]);
            exit();
        }

        // Kelompokkan task per PIC
        $tasks_by_pic = [];
        while ($row = $pending_result->fetch_assoc()) {
            $tasks_by_pic[$row['pic_email']][] = $row;
        }

        $today = date('Y-m-d');
        $updated_count = 0;
        $preview = [];

        $update_stmt = $conn->prepare("UPDATE gba_tasks SET request_date = ?, deadline = ?, sign_off_date = ?, updated_by_email = ? WHERE id = ?");

        foreach ($tasks_by_pic as $pic_email => $tasks) {
            // Temukan request_date paling awal di antara task PIC ini (bisa tanggal lampau)
            $earliest_existing_date = null;
            foreach ($tasks as $task) {
                if ($task['request_date'] && ($earliest_existing_date === null || $task['request_date'] < $earliest_existing_date)) {
                    $earliest_existing_date = $task['request_date'];
                }
            }

            // Mulai distribusi dari tanggal paling awal yang ada, atau hari ini jika tidak ada
            $start_date = $earliest_existing_date ?: $today;

            // Koreksi: jika sudah ganti minggu maka start request datenya jadi senin
            if (date('oW', strtotime($start_date)) < date('oW', strtotime($today))) {
                $start_date = date('Y-m-d', strtotime('monday this week', strtotime($today)));
            }

            // Pastikan start dari hari kerja (skip Sabtu/Minggu)
            $current_date = $start_date;
            $dow = date('N', strtotime($current_date));
            while ($dow >= 6) {
                $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
                $dow = date('N', strtotime($current_date));
            }

            // Karena kita re-distribusi ulang semua task dari awal, mulai count dari 0
            $count_on_day = 0;

            foreach ($tasks as $task) {
                if ($count_on_day >= $max_per_day) {
                    // Geser ke hari kerja berikutnya
                    $next = date('Y-m-d', strtotime($current_date . ' +1 day'));
                    $dow2 = date('N', strtotime($next));
                    while ($dow2 >= 6) {
                        $next = date('Y-m-d', strtotime($next . ' +1 day'));
                        $dow2 = date('N', strtotime($next));
                    }
                    $current_date = $next;
                    $count_on_day = 0;
                }

                $new_request_date = $current_date;
                $new_deadline = add_working_days($current_date, 7);
                $new_sign_off = $new_deadline;

                $update_stmt->bind_param("ssssi", $new_request_date, $new_deadline, $new_sign_off, $user_email, $task['id']);
                if ($update_stmt->execute()) {
                    $updated_count++;
                    $preview[] = [
                        'id' => $task['id'],
                        'pic_email' => $pic_email,
                        'old_date' => $task['request_date'],
                        'new_date' => $new_request_date,
                        'deadline' => $new_deadline,
                    ];
                }

                $count_on_day++;
            }
        }

        $update_stmt->close();

        log_activity($conn, null, 'REDISTRIBUTE_DATES', "Request dates redistributed for {$updated_count} tasks (max {$max_per_day}/PIC/day) by {$user_email}.", $user_email);

        echo json_encode([
            'success' => true,
            'updated_count' => $updated_count,
            'preview' => $preview
        ]);
        exit();

    // ==========================================================
    // --- PREVIEW REDISTRIBUTE (DRY RUN, NO DB CHANGE) ---
    // ==========================================================
    case 'preview_redistribute_dates':
        header('Content-Type: application/json');

        $max_per_day = 3;

        $pending_sql = "SELECT id, pic_email, model_name, request_date FROM gba_tasks 
                        WHERE (progress_status = 'Task Baru' OR progress_status = 'Test Ongoing' OR progress_status = 'Pending Feedback' OR progress_status = 'Feedback Sent' OR progress_status = 'Downloaded')
                        AND submission_date IS NULL AND is_urgent = 0
                        ORDER BY pic_email ASC, request_date ASC, id ASC";
        $pending_result = $conn->query($pending_sql);

        if (!$pending_result) {
            echo json_encode(['success' => false, 'error' => 'Query gagal: ' . $conn->error]);
            exit();
        }

        $tasks_by_pic = [];
        while ($row = $pending_result->fetch_assoc()) {
            $tasks_by_pic[$row['pic_email']][] = $row;
        }

        $today = date('Y-m-d');
        $preview = [];

        foreach ($tasks_by_pic as $pic_email => $tasks) {
            // Temukan request_date paling awal (bisa tanggal lampau)
            $earliest_existing_date = null;
            foreach ($tasks as $task) {
                if ($task['request_date'] && ($earliest_existing_date === null || $task['request_date'] < $earliest_existing_date)) {
                    $earliest_existing_date = $task['request_date'];
                }
            }

            // Mulai distribusi dari tanggal paling awal yang ada, atau hari ini jika tidak ada
            $start_date = $earliest_existing_date ?: $today;

            // Koreksi: jika sudah ganti minggu maka start request datenya jadi senin
            if (date('oW', strtotime($start_date)) < date('oW', strtotime($today))) {
                $start_date = date('Y-m-d', strtotime('monday this week', strtotime($today)));
            }

            // Pastikan start dari hari kerja
            $current_date = $start_date;
            $dow = date('N', strtotime($current_date));
            while ($dow >= 6) {
                $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
                $dow = date('N', strtotime($current_date));
            }

            // Re-distribusi dari nol, count mulai dari 0
            $count_on_day = 0;

            foreach ($tasks as $task) {
                if ($count_on_day >= $max_per_day) {
                    $next = date('Y-m-d', strtotime($current_date . ' +1 day'));
                    $dow2 = date('N', strtotime($next));
                    while ($dow2 >= 6) {
                        $next = date('Y-m-d', strtotime($next . ' +1 day'));
                        $dow2 = date('N', strtotime($next));
                    }
                    $current_date = $next;
                    $count_on_day = 0;
                }

                $preview[] = [
                    'id' => $task['id'],
                    'pic_email' => $pic_email,
                    'model_name' => $task['model_name'],
                    'old_date' => $task['request_date'],
                    'new_date' => $current_date,
                    'deadline' => add_working_days($current_date, 7),
                ];

                $count_on_day++;
            }
        }

        echo json_encode(['success' => true, 'preview' => $preview]);
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