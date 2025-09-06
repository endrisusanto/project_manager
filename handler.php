<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'config.php';

if (!isset($_POST['action'])) {
    die('Error: Aksi tidak ditentukan.');
}

$action = $_POST['action'];

// Helper function for redirection
function redirect($url) {
    header("Location: " . $url);
    exit();
}

// Helper function to handle empty strings as NULL
function null_if_empty($value) {
    return trim($value) === '' ? null : trim($value);
}

switch ($action) {
    // --- Project Actions ---
    case 'create':
        $stmt = $conn->prepare("INSERT INTO projects (project_name, product_model, project_type, status, due_date, description, ap, cp, csc, qb_user, qb_userdebug) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssssss", 
            $_POST['project_name'], $_POST['product_model'], $_POST['project_type'], $_POST['status'],
            null_if_empty($_POST['due_date']), null_if_empty($_POST['description']), null_if_empty($_POST['ap']), 
            null_if_empty($_POST['cp']), null_if_empty($_POST['csc']), null_if_empty($_POST['qb_user']), null_if_empty($_POST['qb_userdebug'])
        );
        $stmt->execute();
        redirect('index.php');
        break;

    case 'update':
        $stmt = $conn->prepare("UPDATE projects SET project_name=?, product_model=?, project_type=?, status=?, due_date=?, description=?, ap=?, cp=?, csc=?, qb_user=?, qb_userdebug=? WHERE id=?");
        $stmt->bind_param("sssssssssssi",
            $_POST['project_name'], $_POST['product_model'], $_POST['project_type'], $_POST['status'],
            null_if_empty($_POST['due_date']), null_if_empty($_POST['description']), null_if_empty($_POST['ap']),
            null_if_empty($_POST['cp']), null_if_empty($_POST['csc']), null_if_empty($_POST['qb_user']), null_if_empty($_POST['qb_userdebug']),
            $_POST['id']
        );
        $stmt->execute();
        redirect('index.php');
        break;

    case 'delete':
        $stmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->bind_param("i", $_POST['id']);
        $stmt->execute();
        redirect('index.php');
        break;

    case 'update_status':
        header('Content-Type: application/json');
        $stmt = $conn->prepare("UPDATE projects SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $_POST['status'], $_POST['id']);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        exit(); // Stop script execution for AJAX requests

    // --- GBA Task Actions ---
    case 'create_gba_task':
        $checklist_data = isset($_POST['checklist']) ? $_POST['checklist'] : [];
        $checklist_json = json_encode($checklist_data);

        $stmt = $conn->prepare("INSERT INTO gba_tasks (model_name, pic_email, ap, cp, csc, test_plan_type, progress_status, request_date, submission_date, deadline, sign_off_date, base_submission_id, submission_id, reviewer_email, notes, test_items_checklist) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssssssssss",
            $_POST['model_name'], $_POST['pic_email'], null_if_empty($_POST['ap']), null_if_empty($_POST['cp']), null_if_empty($_POST['csc']),
            $_POST['test_plan_type'], $_POST['progress_status'], null_if_empty($_POST['request_date']), null_if_empty($_POST['submission_date']),
            null_if_empty($_POST['deadline']), null_if_empty($_POST['sign_off_date']), null_if_empty($_POST['base_submission_id']),
            null_if_empty($_POST['submission_id']), null_if_empty($_POST['reviewer_email']), $_POST['notes'], $checklist_json
        );
        $stmt->execute();
        redirect('gba_tasks.php');
        break;

    case 'update_gba_task':
        $checklist_data = isset($_POST['checklist']) ? $_POST['checklist'] : [];
        $checklist_json = json_encode($checklist_data);
        
        $stmt = $conn->prepare("UPDATE gba_tasks SET model_name=?, pic_email=?, ap=?, cp=?, csc=?, test_plan_type=?, progress_status=?, request_date=?, submission_date=?, deadline=?, sign_off_date=?, base_submission_id=?, submission_id=?, reviewer_email=?, notes=?, test_items_checklist=? WHERE id=?");
        $stmt->bind_param("ssssssssssssssssi",
            $_POST['model_name'], $_POST['pic_email'], null_if_empty($_POST['ap']), null_if_empty($_POST['cp']), null_if_empty($_POST['csc']),
            $_POST['test_plan_type'], $_POST['progress_status'], null_if_empty($_POST['request_date']), null_if_empty($_POST['submission_date']),
            null_if_empty($_POST['deadline']), null_if_empty($_POST['sign_off_date']), null_if_empty($_POST['base_submission_id']),
            null_if_empty($_POST['submission_id']), null_if_empty($_POST['reviewer_email']), $_POST['notes'], $checklist_json,
            $_POST['id']
        );
        $stmt->execute();
        redirect('gba_tasks.php');
        break;

    default:
        die('Error: Aksi tidak valid.');
        break;
}

$stmt->close();
$conn->close();
?>

