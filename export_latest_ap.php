<?php
require_once "config.php";
session_start();

$filename = "gba_all_ap_summary_" . date('Ymd') . ".xls";

header("Content-Disposition: attachment; filename=\"$filename\"");
header("Content-Type: application/vnd.ms-excel");

// Query SQL untuk mengambil semua data AP
$sql = "SELECT model_name, ap AS latest_ap, cp AS latest_cp, csc AS latest_csc
        FROM gba_tasks 
        WHERE ap IS NOT NULL AND ap != '' 
        ORDER BY model_name ASC, id DESC";
        
$result = $conn->query($sql);

// Buat output tabel HTML
$output = "<table border='1' cellpadding='10'>";
$output .= "<tr>
                <th style='background-color:#020617; color:#ffffff;'>Model Name</th>
                <th style='background-color:#020617; color:#ffffff;'>AP Version</th>
                <th style='background-color:#020617; color:#ffffff;'>CP Version</th>
                <th style='background-color:#020617; color:#ffffff;'>CSC Version</th>
            </tr>";

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $output .= "<tr>";
        $output .= "<td>" . ($row['model_name'] ?? '') . "</td>";
        $output .= "<td>" . ($row['latest_ap'] ?? '') . "</td>";
        $output .= "<td>" . ($row['latest_cp'] ?? '') . "</td>";
        $output .= "<td>" . ($row['latest_csc'] ?? '') . "</td>";
        $output .= "</tr>";
    }
} else {
    $output .= "<tr><td colspan='4'>No data found...</td></tr>";
}

$output .= "</table>";

echo $output;
exit();
?>
