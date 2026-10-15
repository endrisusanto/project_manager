<?php
// ponytail: lightweight API to resolve Submission ID by PDA / AP version
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "config.php";

$pda = trim($_GET['pda'] ?? $_GET['ap'] ?? '');

if (empty($pda)) {
    echo json_encode(['success' => false, 'message' => 'Parameter pda/ap tidak boleh kosong.']);
    exit;
}

// Clean input
$clean_pda = preg_replace('/[^a-zA-Z0-9]/', '', $pda);

$stmt = $conn->prepare("
    SELECT id, submission_id, base_submission_id, model_name, ap 
    FROM gba_tasks 
    WHERE ap = ? OR ap LIKE ? 
    ORDER BY (submission_id IS NOT NULL AND submission_id != '') DESC, id DESC 
    LIMIT 1
");

$like_pda = "%" . $clean_pda . "%";
$stmt->bind_param("ss", $clean_pda, $like_pda);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $sub_id = trim(strval($row['submission_id'] ?? '')) ?: trim(strval($row['base_submission_id'] ?? ''));
    if (!empty($sub_id)) {
        echo json_encode([
            'success' => true,
            'submission_id' => $sub_id,
            'model_name' => $row['model_name'],
            'ap' => $row['ap'],
            'task_id' => $row['id']
        ]);
        exit;
    }
}

echo json_encode([
    'success' => false,
    'submission_id' => null,
    'message' => 'Submission ID belum terdaftar untuk PDA: ' . $pda
]);
