<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'config.php';

// Menentukan sumber data (JSON atau POST)
$is_json = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $is_json ? json_decode(file_get_contents('php://input'), true) : $_POST;
$action = $data['action'] ?? null;

if (!$action) {
    die('Error: Aksi tidak ditentukan.');
}

// --- FUNGSI HELPER ---

if (!function_exists('null_if_empty')) {
    function null_if_empty($value) {
        return trim($value) === '' ? null : trim($value);
    }
}

function redirect($url) {
    header("Location: " . $url);
    exit();
}

// --- LOGIKA UTAMA ---

switch ($action) {
    case 'create_gba_task':
    case 'update_gba_task':
        $checklist_json = isset($data['checklist']) ? json_encode($data['checklist']) : null;
        $is_urgent = isset($data['is_urgent']) ? 1 : 0;
        
        if ($action === 'create_gba_task') {
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
        } else { // update_gba_task
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
        }
        
        $stmt->execute();
        redirect('index.php');
        break;

    case 'delete_gba_task':
        $stmt = $conn->prepare("DELETE FROM gba_tasks WHERE id = ?");
        $stmt->bind_param("i", $data['id']);
        $stmt->execute();
        redirect('index.php');
        break;

    case 'update_task_status':
        header('Content-Type: application/json');
        $task_id = $data['task_id'];
        $new_status = $data['new_status'];

        if ($new_status === 'Task Baru') {
            $stmt = $conn->prepare("UPDATE gba_tasks SET progress_status = ?, submission_date = NULL, approved_date = NULL, sign_off_date = NULL, test_items_checklist = NULL WHERE id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE gba_tasks SET progress_status = ? WHERE id = ?");
        }
        
        $stmt->bind_param("si", $new_status, $task_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
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