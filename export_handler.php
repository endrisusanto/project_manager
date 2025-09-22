<?php
require_once "config.php";
session_start();

$filename = "gba_tasks_summary_" . date('Ymd') . ".xls";

header("Content-Disposition: attachment; filename=\"$filename\"");
header("Content-Type: application/vnd.ms-excel");

// Ambil parameter filter
$plan_filter = $_GET['plan'] ?? 'All';
$search_term = $_GET['search'] ?? '';

// Bangun query SQL dengan filter
$sql = "SELECT * FROM gba_tasks";
$where_clauses = [];
$params = [];
$types = "";

if ($plan_filter !== 'All') {
    $where_clauses[] = "test_plan_type = ?";
    $params[] = $plan_filter;
    $types .= "s";
}

if (!empty($search_term)) {
    $where_clauses[] = "(model_name LIKE ? OR pic_email LIKE ? OR progress_status LIKE ? OR ap LIKE ?)";
    $search_like = "%" . $search_term . "%";
    array_push($params, $search_like, $search_like, $search_like, $search_like);
    $types .= "ssss";
}


if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$sql .= " ORDER BY request_date DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = false;
}

// Buat output tabel HTML
$output = "<table>";
$output .= "<tr><th>ID</th><th>Model Name</th><th>AP</th><th>CP</th><th>CSC</th><th>PIC</th><th>Test Plan</th><th>Status</th><th>Request Date</th><th>Submission Date</th><th>Deadline</th></tr>";

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $output .= "<tr>";
        $output .= "<td>" . $row['id'] . "</td>";
        $output .= "<td>" . $row['model_name'] . "</td>";
        $output .= "<td>" . $row['ap'] . "</td>";
        $output .= "<td>" . $row['cp'] . "</td>";
        $output .= "<td>" . $row['csc'] . "</td>";
        $output .= "<td>" . $row['pic_email'] . "</td>";
        $output .= "<td>" . $row['test_plan_type'] . "</td>";
        $output .= "<td>" . $row['progress_status'] . "</td>";
        $output .= "<td>" . $row['request_date'] . "</td>";
        $output .= "<td>" . $row['submission_date'] . "</td>";
        $output .= "<td>" . $row['deadline'] . "</td>";
        $output .= "</tr>";
    }
} else {
    $output .= "<tr><td colspan='11'>No data found...</td></tr>";
}

$output .= "</table>";

echo $output;
exit();
?>