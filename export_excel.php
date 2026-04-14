<?php
require_once "config.php";
require_once "session.php";

$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$start_date = $year . '-01-01';
$end_date = $year . '-12-31';

$filename = "roadmap_" . $year . "_" . date('Ymd') . ".xls";

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// 1. Generate Dates for Header
$year_dates = [];
$first_day_ts = strtotime($start_date);
$last_day_ts = strtotime($end_date);
$current_ts = $first_day_ts;

$months = [];
while ($current_ts <= $last_day_ts) {
    $year_dates[] = $current_ts;
    $m_key = date('Y-m', $current_ts);
    if (!isset($months[$m_key])) {
        $months[$m_key] = ['name' => date('F', $current_ts), 'days' => 0];
    }
    $months[$m_key]['days']++;
    $current_ts = strtotime('+1 day', $current_ts);
}

// Fetch Tasks
$sql_tasks = "SELECT t.*, u.username 
        FROM gba_tasks t 
        LEFT JOIN users u ON t.pic_email = u.email
        WHERE (t.request_date <= ? AND (t.deadline >= ? OR t.deadline IS NULL))
        ORDER BY t.request_date ASC, t.model_name ASC";

$tasks = [];
if ($stmt = $conn->prepare($sql_tasks)) {
    $stmt->bind_param("ss", $end_date, $start_date);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $tasks[] = $row;
    }
    $stmt->close();
}
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"
    xmlns="http://www.w3.org/TR/REC-html40">

<head>
    <!--[if gte mso 9]>
    <xml>
        <x:ExcelWorkbook>
            <x:ExcelWorksheets>
                <x:ExcelWorksheet>
                    <x:Name>Roadmap</x:Name>
                    <x:WorksheetOptions>
                        <x:FreezePanes/>
                        <x:FrozenNoSplit/>
                        <x:SplitHorizontal>2</x:SplitHorizontal>
                        <x:TopRowBottomPane>2</x:TopRowBottomPane>
                        <x:SplitVertical>7</x:SplitVertical>
                        <x:LeftColumnRightPane>7</x:LeftColumnRightPane>
                        <x:ActivePane>0</x:ActivePane>
                    </x:WorksheetOptions>
                </x:ExcelWorksheet>
            </x:ExcelWorksheets>
        </x:ExcelWorkbook>
    </xml>
    <![endif]-->
    <style>
        th {
            background-color: #f0f0f0;
            border: 1px solid #999;
        }

        td {
            border: 1px solid #999;
            vertical-align: middle;
        }
    </style>
</head>

<body>
    <table border="1">
        <thead>
            <!-- Month Header -->
            <tr>
                <th colspan="7" style="background-color: #e2e8f0; font-weight: bold; height: 30px;">Project Details</th>
                <?php foreach ($months as $m): ?>
                    <th colspan="<?= $m['days'] ?>"
                        style="background-color: #cbd5e1; text-align: center; font-weight: bold; border: 1px solid #94a3b8;">
                        <?= $m['name'] ?>
                    </th>
                <?php endforeach; ?>
            </tr>
            <!-- Day Header -->
            <tr>
                <th style="background-color: #f1f5f9; width: 50px;">ID</th>
                <th style="background-color: #f1f5f9; width: 150px;">Marketing Name</th>
                <th style="background-color: #f1f5f9; width: 150px;">Model Name</th>
                <th style="background-color: #f1f5f9; width: 100px;">PIC</th>
                <th style="background-color: #f1f5f9; width: 120px;">Versi</th>
                <th style="background-color: #f1f5f9; width: 100px;">Status</th>
                <th style="background-color: #f1f5f9; width: 100px;">End Date</th>
                <?php foreach ($year_dates as $ts):
                    $d = date('j', $ts);
                    $is_weekend = (date('N', $ts) >= 6);
                    $bg = $is_weekend ? '#fca5a5' : '#f8fafc'; // Red-ish for weekend
                    ?>
                    <th style="background-color: <?= $bg ?>; text-align: center; width: 30px; font-size: 10px;">
                        <?= $d ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tasks as $task):
                $ap = $task['ap'] ? $task['ap'] : '-';
                $cp = $task['cp'] ? substr($task['cp'], -5) : '-';
                $csc = $task['csc'] ? substr($task['csc'], -5) : '-';
                $ver_str = "$ap / $cp / $csc";

                // End Date Logic
                $end_date_str = $task['approved_date'];
                if (empty($end_date_str))
                    $end_date_str = $task['sign_off_date'];
                if (empty($end_date_str))
                    $end_date_str = $task['deadline'];

                $start_ts = strtotime($task['request_date']);
                $end_ts = $end_date_str ? strtotime($end_date_str) : $start_ts;

                // Ensure valid range
                if ($end_ts < $start_ts)
                    $end_ts = $start_ts;
                ?>
                <tr>
                    <td><?= $task['id'] ?></td>
                    <td><?= htmlspecialchars($task['project_name']) ?></td>
                    <td><?= htmlspecialchars($task['model_name']) ?></td>
                    <td><?= htmlspecialchars($task['username']) ?></td>
                    <td style="text-align: center;"><?= htmlspecialchars($ver_str) ?></td>
                    <td><?= htmlspecialchars($task['progress_status']) ?></td>
                    <td><?= $end_date_str ? date('Y-m-d', strtotime($end_date_str)) : '-' ?></td>

                        <?php foreach ($year_dates as $ts):
                            // Check if date is within task range
                            $in_range = ($ts >= $start_ts && $ts <= $end_ts);
                            $is_weekend = (date('N', $ts) >= 6);

                            // Color Logic
                            $cell_bg = '#ffffff'; // Default white
                            if ($in_range) {
                                $cell_bg = '#3b82f6'; // Blue for active task
                            } elseif ($is_weekend) {
                                $cell_bg = '#fee2e2'; // Light red for weekend background
                            }
                            ?>
                        <td style="background-color: <?= $cell_bg ?>;"></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>

</html>
<?php exit; ?>