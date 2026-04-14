<?php
require_once "config.php";

echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>Migration</title><style>body { font-family: sans-serif; background: #111; color: #eee; padding: 2em; } h1 { color: #4CAF50; } p { line-height: 1.6; } .success { color: #4CAF50; font-weight: bold; } .error { color: #F44336; font-weight: bold; } .info { color: #2196F3; }</style></head><body>";
echo "<h1>Memulai Proses Migrasi Data...</h1>";

// Fungsi untuk membersihkan tanggal
function clean_date($date_string) {
    if (empty($date_string) || $date_string === '0000-00-00' || strtoupper($date_string) === 'TBD') {
        return null;
    }
    // Coba konversi ke format Y-m-d jika valid
    $timestamp = strtotime($date_string);
    if ($timestamp === false) {
        return null;
    }
    return date('Y-m-d', $timestamp);
}


// 1. Ambil semua data dari tabel 'task' lama
$result_old = $conn->query("SELECT * FROM task");
if (!$result_old) {
    die("<p class='error'>Error: Tidak dapat mengambil data dari tabel 'task'. Pastikan Anda sudah mengimpor file `gba_task.sql`.</p></body></html>");
}

$tasks_to_migrate = $result_old->fetch_all(MYSQLI_ASSOC);
echo "<p class='info'>Ditemukan " . count($tasks_to_migrate) . " data untuk dimigrasikan.</p>";

// 2. Siapkan statement untuk memasukkan data ke tabel 'gba_tasks' baru
$stmt_new = $conn->prepare(
    "INSERT INTO gba_tasks (project_name, model_name, pic_email, ap, cp, csc, qb_user, qb_userdebug, test_plan_type, progress_status, request_date, submission_date, deadline, approved_date, base_submission_id, submission_id, reviewer_email, is_urgent, notes) 
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);

if (!$stmt_new) {
    die("<p class='error'>Error: Gagal mempersiapkan statement untuk tabel 'gba_tasks'. Error: " . $conn->error . "</p></body></html>");
}

$migrated_count = 0;
$skipped_count = 0;

foreach ($tasks_to_migrate as $task) {
    // 3. Mapping data dari kolom lama ke baru
    
    $pic_email = strtolower($task['nama']) . '@samsung.com';
    
    $test_plan_type = 'Normal MR';
    if (strtoupper($task['type']) === 'SMR') $test_plan_type = 'SMR';
    if (strtoupper($task['type']) === 'SKU') $test_plan_type = 'SKU';
    if (strtoupper($task['type']) === 'SIMPLE') $test_plan_type = 'Simple Exception MR';

    $progress_status = 'Task Baru';
    switch (strtoupper($task['status'])) {
        case 'APPROVED': $progress_status = 'Approved'; break;
        case 'SUBMITTED': $progress_status = 'Submitted'; break;
        case 'INPROGRESS': $progress_status = 'Test Ongoing'; break;
        case 'CANCEL': $progress_status = 'Batal'; break;
    }
    
    $is_urgent = (strtoupper($task['urgent']) === 'YA') ? 1 : 0;
    
    // MODIFIKASI: Bersihkan nilai tanggal
    $request_date = clean_date($task['request_date']);
    $submission_date = clean_date($task['submission_date']);
    $deadline = clean_date($task['deadline']);
    $approved_date = clean_date($task['tanggal_approve']);

    $check_stmt = $conn->prepare("SELECT id FROM gba_tasks WHERE project_name = ?");
    $check_stmt->bind_param("s", $task['issue_id']);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $skipped_count++;
        continue;
    }

    // 4. Bind parameter dan eksekusi
    $stmt_new->bind_param(
        "sssssssssssssssssis",
        $task['issue_id'],
        $task['DevModel'],
        $pic_email,
        $task['ap'],
        $task['cp'],
        $task['csc'],
        $task['qbuser'],
        $task['qbuserdebug'],
        $test_plan_type,
        $progress_status,
        $request_date,
        $submission_date,
        $deadline,
        $approved_date,
        $task['baseid'],
        $task['sid'],
        $task['reviewer'],
        $is_urgent,
        $task['note']
    );

    if ($stmt_new->execute()) {
        $migrated_count++;
    } else {
        echo "<p class='error'>Gagal memigrasikan ID Task Lama: " . $task['id'] . " - " . $stmt_new->error . "</p>";
    }
}

echo "<p class='success'>Proses migrasi selesai!</p>";
echo "<p>" . $migrated_count . " data berhasil dipindahkan.</p>";
echo "<p>" . $skipped_count . " data dilewati (karena sudah ada).</p>";

$stmt_new->close();
$conn->close();

echo "</body></html>";
?>