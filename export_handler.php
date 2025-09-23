<?php
require_once "config.php";
session_start();

$filename = "gba_tasks_summary_" . date('Ymd') . ".xls";

header("Content-Disposition: attachment; filename=\"$filename\"");
header("Content-Type: application/vnd.ms-excel");

// Query SQL untuk mengambil semua data tanpa filter
$sql = "SELECT * FROM gba_tasks ORDER BY id DESC";
$result = $conn->query($sql);

// Buat output tabel HTML
$output = "<table>";
// MODIFIKASI: Menambahkan semua header yang diminta
$output .= "<tr>
                <th>ID</th>
                <th>Project Name</th>
                <th>Model Name</th>
                <th>AP</th>
                <th>CP</th>
                <th>CSC</th>
                <th>QB User</th>
                <th>QB Userdebug</th>
                <th>PIC Email</th>
                <th>Test Plan Type</th>
                <th>Progress Status</th>
                <th>Request Date</th>
                <th>Submission Date</th>
                <th>Approved Date</th>
                <th>Deadline</th>
                <th>Sign-Off Date</th>
                <th>Base Submission ID</th>
                <th>Submission ID</th>
                <th>Reviewer Email</th>
                <th>Urgent</th>
                <th>Notes</th>
                <th>Test Items Checklist</th>
            </tr>";

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $output .= "<tr>";
        // MODIFIKASI: Menambahkan semua sel data yang sesuai dengan header
        $output .= "<td>" . ($row['id'] ?? '') . "</td>";
        $output .= "<td>" . ($row['project_name'] ?? '') . "</td>";
        $output .= "<td>" . ($row['model_name'] ?? '') . "</td>";
        $output .= "<td>" . ($row['ap'] ?? '') . "</td>";
        $output .= "<td>" . ($row['cp'] ?? '') . "</td>";
        $output .= "<td>" . ($row['csc'] ?? '') . "</td>";
        $output .= "<td>" . ($row['qb_user'] ?? '') . "</td>";
        $output .= "<td>" . ($row['qb_userdebug'] ?? '') . "</td>";
        $output .= "<td>" . ($row['pic_email'] ?? '') . "</td>";
        $output .= "<td>" . ($row['test_plan_type'] ?? '') . "</td>";
        $output .= "<td>" . ($row['progress_status'] ?? '') . "</td>";
        $output .= "<td>" . ($row['request_date'] ?? '') . "</td>";
        $output .= "<td>" . ($row['submission_date'] ?? '') . "</td>";
        $output .= "<td>" . ($row['approved_date'] ?? '') . "</td>";
        $output .= "<td>" . ($row['deadline'] ?? '') . "</td>";
        $output .= "<td>" . ($row['sign_off_date'] ?? '') . "</td>";
        $output .= "<td>" . ($row['base_submission_id'] ?? '') . "</td>";
        $output .= "<td>" . ($row['submission_id'] ?? '') . "</td>";
        $output .= "<td>" . ($row['reviewer_email'] ?? '') . "</td>";
        $output .= "<td>" . (($row['is_urgent'] == 1) ? 'Yes' : 'No') . "</td>";
        $output .= "<td>" . ($row['notes'] ?? '') . "</td>";
        $output .= "<td>" . ($row['test_items_checklist'] ?? '') . "</td>";
        $output .= "</tr>";
    }
} else {
    $output .= "<tr><td colspan='22'>No data found...</td></tr>";
}

$output .= "</table>";

echo $output;
exit();
?>