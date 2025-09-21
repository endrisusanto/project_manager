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

// --- LOGIKA UTAMA ---

$is_json = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$data = $is_json ? json_decode(file_get_contents('php://input'), true) : $_POST;
$action = $data['action'] ?? null;

if (!$action) {
    die('Error: Aksi tidak ditentukan.');
}

switch ($action) {
    case 'create_gba_task':
        if (!is_admin()) {
            redirect_with_error('permission_denied');
        }
        
        $checklist_json = isset($data['checklist']) ? json_encode($data['checklist']) : null;
        // MODIFIKASI: Menggunakan nilai langsung dari form
        $is_urgent = (int)($data['is_urgent'] ?? 0);
        
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
        // MODIFIKASI: Menggunakan nilai langsung dari form
        $is_urgent = (int)($data['is_urgent'] ?? 0);
        
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
        $task_id = $data['task_id'];
        $new_status = $data['new_status'];

        $stmt = $conn->prepare("UPDATE gba_tasks SET progress_status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $task_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        exit();
        
    case 'toggle_urgent':
        header('Content-Type: application/json');
        $task_id = $data['task_id'] ?? 0;
        
        $stmt = $conn->prepare("SELECT is_urgent FROM gba_tasks WHERE id = ?");
        $stmt->bind_param("i", $task_id);
        $stmt->execute();
        $current_status = $stmt->get_result()->fetch_assoc()['is_urgent'] ?? 0;
        $stmt->close();
        
        $new_status = $current_status ? 0 : 1;
        
        $stmt = $conn->prepare("UPDATE gba_tasks SET is_urgent = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_status, $task_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'is_urgent' => (bool)$new_status]);
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