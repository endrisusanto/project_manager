<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'config.php';

// Cek apakah data dikirim sebagai JSON (untuk drag & drop)
if (strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? null;
} else {
    // Jika tidak, gunakan _POST seperti biasa (untuk form)
    $data = $_POST;
    $action = $data['action'] ?? null;
}

if (!$action) {
    if (isset($_POST['add_project'])) $action = 'add_project';
    elseif (isset($_POST['update_project'])) $action = 'update_project';
    elseif (isset($_POST['delete_project'])) $action = 'delete_project';
    elseif (isset($_POST['delete_gba_task'])) $action = 'delete_gba_task';
    elseif (isset($_POST['action'])) $action = $_POST['action'];
    else die('Error: Aksi tidak ditentukan.');
}

function redirect($url) {
    header("Location: " . $url);
    exit();
}

function null_if_empty($value) {
    return trim($value) === '' ? null : trim($value);
}

switch ($action) {
    // --- Project Actions ---
    case 'add_project':
    case 'update_project':
        $software_released = isset($data['software_released']) ? 1 : 0;
        $use_gba_testing = isset($data['use_gba_testing']) ? 1 : 0;
        $project_id = $data['project_id'] ?? null;

        $current_status = 'Planning';
        if ($project_id) {
            $status_stmt = $conn->prepare("SELECT status FROM projects WHERE id = ?");
            $status_stmt->bind_param("i", $project_id);
            $status_stmt->execute();
            $result = $status_stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $current_status = $row['status'];
            }
        }
        
        $status = $current_status;
        if ($action === 'add_project') $status = 'Planning';

        if ($software_released) {
            if ($use_gba_testing) {
                $check_task_stmt = $conn->prepare("SELECT id FROM gba_tasks WHERE model_name = ? LIMIT 1");
                $check_task_stmt->bind_param("s", $data['product_model']);
                $check_task_stmt->execute();
                $task_result = $check_task_stmt->get_result();

                if ($task_result->num_rows > 0) {
                    $status = 'GBA Testing';
                } else {
                    redirect('index.php?error=no_gba_task&model=' . urlencode($data['product_model']));
                }
            } else {
                if ($status === 'GBA Testing') {
                     $status = 'Released';
                } else if ($action === 'add_project' || $software_released) {
                     $status = 'Released';
                }
            }
        } else {
             $status = 'In Development';
        }

        if ($action === 'add_project') {
            $stmt = $conn->prepare("INSERT INTO projects (project_name, product_model, project_type, status, description, ap, cp, csc, qb_user, qb_userdebug, software_released, use_gba_testing) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssssssii", 
                $data['project_name'], $data['product_model'], $data['project_type'], $status,
                null_if_empty($data['description']), null_if_empty($data['ap']), 
                null_if_empty($data['cp']), null_if_empty($data['csc']), null_if_empty($data['qb_user']), null_if_empty($data['qb_userdebug']),
                $software_released, $use_gba_testing
            );
        } else { // update_project
            $stmt = $conn->prepare("UPDATE projects SET project_name=?, product_model=?, project_type=?, status=?, description=?, ap=?, cp=?, csc=?, qb_user=?, qb_userdebug=?, software_released=?, use_gba_testing=? WHERE id=?");
            $stmt->bind_param("ssssssssssiii",
                $data['project_name'], $data['product_model'], $data['project_type'], $status,
                null_if_empty($data['description']), null_if_empty($data['ap']),
                null_if_empty($data['cp']), null_if_empty($data['csc']), null_if_empty($data['qb_user']), null_if_empty($data['qb_userdebug']),
                $software_released, $use_gba_testing, $project_id
            );
        }
        
        $stmt->execute();
        $new_project_id = ($action === 'add_project') ? $conn->insert_id : $project_id;

        if ($use_gba_testing && $status === 'GBA Testing') {
            redirect('index.php?highlight=' . $new_project_id);
        } else {
            redirect('index.php');
        }
        break;
    
    case 'delete_project':
        $stmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->bind_param("i", $data['project_id']);
        $stmt->execute();
        redirect('index.php');
        break;

    case 'update_status':
        header('Content-Type: application/json');
        $project_id = $data['project_id'];
        $new_status = $data['new_status'];

        $software_released = 0;
        $use_gba_testing = 0;
        if (in_array($new_status, ['Released', 'GBA Testing', 'Software Confirm / FOTA'])) {
            $software_released = 1;
        }
        if ($new_status === 'GBA Testing') {
            $use_gba_testing = 1;
        }

        $stmt = $conn->prepare("UPDATE projects SET status = ?, software_released = ?, use_gba_testing = ? WHERE id = ?");
        $stmt->bind_param("siii", $new_status, $software_released, $use_gba_testing, $project_id);
        
        if ($stmt->execute()) {
            if ($new_status === 'GBA Testing') {
                $proj_stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
                $proj_stmt->bind_param("i", $project_id);
                $proj_stmt->execute();
                $project_data = $proj_stmt->get_result()->fetch_assoc();

                if ($project_data) {
                    $check_task_stmt = $conn->prepare("SELECT id FROM gba_tasks WHERE model_name = ?");
                    $check_task_stmt->bind_param("s", $project_data['product_model']);
                    $check_task_stmt->execute();
                    $existing_task = $check_task_stmt->get_result()->fetch_assoc();

                    if (!$existing_task) {
                        $test_plan_type = 'Normal MR';
                        if ($project_data['project_type'] === 'Security Release') { $test_plan_type = 'SMR'; }
                        elseif ($project_data['project_type'] === 'New Launch') { $test_plan_type = 'Regular Variant'; }
                        
                        $notes = "<p>Task dibuat otomatis dari Project: " . $project_data['project_name'] . "</p>";
                        $today = date("Y-m-d");

                        $task_stmt = $conn->prepare("INSERT INTO gba_tasks (project_id, model_name, ap, cp, csc, qb_user, qb_userdebug, test_plan_type, progress_status, request_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $task_stmt->bind_param("issssssssss",
                            $project_id, $project_data['product_model'], $project_data['ap'], $project_data['cp'], $project_data['csc'],
                            $project_data['qb_user'], $project_data['qb_userdebug'],
                            $test_plan_type, 'Task Baru', $today, $notes
                        );
                        $task_stmt->execute();
                    }
                }
            }
            $select_stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
            $select_stmt->bind_param("i", $project_id);
            $select_stmt->execute();
            $updated_project_data = $select_stmt->get_result()->fetch_assoc();

            echo json_encode(['success' => true, 'updated_project' => $updated_project_data]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        exit();

    case 'check_gba_status':
        header('Content-Type: application/json');
        $project_id = $data['project_id'];
        
        $stmt = $conn->prepare("SELECT progress_status FROM gba_tasks WHERE project_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result && $result['progress_status'] === 'Approved') {
            echo json_encode(['is_approved' => true]);
        } else {
            echo json_encode(['is_approved' => false, 'status' => $result['progress_status'] ?? 'Not Found']);
        }
        exit();

    // --- GBA Task Actions ---
    case 'create_gba_task':
    case 'update_gba_task':
        $checklist_data = isset($data['checklist']) ? $data['checklist'] : [];
        $checklist_json = json_encode($checklist_data);
        
        if ($action === 'create_gba_task') {
            $stmt = $conn->prepare("INSERT INTO gba_tasks (model_name, pic_email, ap, cp, csc, qb_user, qb_userdebug, test_plan_type, progress_status, request_date, submission_date, deadline, sign_off_date, approved_date, base_submission_id, submission_id, reviewer_email, notes, test_items_checklist) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssssssssssssss",
                $data['model_name'], $data['pic_email'], null_if_empty($data['ap']), null_if_empty($data['cp']), null_if_empty($data['csc']),
                null_if_empty($data['qb_user']), null_if_empty($data['qb_userdebug']), $data['test_plan_type'], $data['progress_status'], null_if_empty($data['request_date']),
                null_if_empty($data['submission_date']), null_if_empty($data['deadline']), null_if_empty($data['sign_off_date']), null_if_empty($data['approved_date']),
                null_if_empty($data['base_submission_id']), null_if_empty($data['submission_id']), null_if_empty($data['reviewer_email']),
                $data['notes'], $checklist_json
            );
        } else { // update_gba_task
            $task_id = $data['id'];
            $new_progress_status = $data['progress_status'];
            $stmt = $conn->prepare("UPDATE gba_tasks SET model_name=?, pic_email=?, ap=?, cp=?, csc=?, qb_user=?, qb_userdebug=?, test_plan_type=?, progress_status=?, request_date=?, submission_date=?, deadline=?, sign_off_date=?, approved_date=?, base_submission_id=?, submission_id=?, reviewer_email=?, notes=?, test_items_checklist=? WHERE id=?");
            $stmt->bind_param("sssssssssssssssssssi",
                $data['model_name'], $data['pic_email'], null_if_empty($data['ap']), null_if_empty($data['cp']), null_if_empty($data['csc']),
                null_if_empty($data['qb_user']), null_if_empty($data['qb_userdebug']), $data['test_plan_type'], $new_progress_status, null_if_empty($data['request_date']),
                null_if_empty($data['submission_date']), null_if_empty($data['deadline']), null_if_empty($data['sign_off_date']), null_if_empty($data['approved_date']),
                null_if_empty($data['base_submission_id']), null_if_empty($data['submission_id']), null_if_empty($data['reviewer_email']),
                $data['notes'], $checklist_json, $task_id
            );
        }
        
        if ($stmt->execute()) {
            if ($action === 'update_gba_task' && $new_progress_status === 'Approved') {
                $task_proj_stmt = $conn->prepare("SELECT project_id FROM gba_tasks WHERE id = ?");
                $task_proj_stmt->bind_param("i", $task_id);
                $task_proj_stmt->execute();
                $task_data = $task_proj_stmt->get_result()->fetch_assoc();

                if ($task_data && !empty($task_data['project_id'])) {
                    $project_id_to_update = $task_data['project_id'];
                    $update_proj_stmt = $conn->prepare("UPDATE projects SET status = 'Software Confirm / FOTA' WHERE id = ?");
                    $update_proj_stmt->bind_param("i", $project_id_to_update);
                    $update_proj_stmt->execute();
                }
            }
        }
        redirect('gba_tasks.php');
        break;

    case 'delete_gba_task':
        $stmt = $conn->prepare("DELETE FROM gba_tasks WHERE id = ?");
        $stmt->bind_param("i", $data['task_id']);
        $stmt->execute();
        redirect('gba_tasks.php');
        break;

    default:
        die('Error: Aksi tidak valid atau tidak ditemukan.');
        break;
}

if (isset($stmt)) {
    $stmt->close();
}
$conn->close();
?>