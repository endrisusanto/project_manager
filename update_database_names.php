<?php
require_once "config.php";
require_once "session.php";
require_once "marketing_name_mapper.php"; // Memuat kamus mapping

// Hanya admin yang bisa menjalankan skrip ini
if (!is_admin()) {
    header("Location: index.php?error=permission_denied");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: edit_mapping.php"); // Redirect jika diakses langsung
    exit;
}

echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>Updating Database...</title><style>body { font-family: sans-serif; background: #111; color: #eee; padding: 2em; } h1 { color: #2196F3; } .success { color: #4CAF50; } .info { color: #ffeb3b; }</style></head><body>";
echo "<h1>Memperbarui Marketing Name di Database...</h1>";
echo "<p>Proses ini mungkin memakan waktu beberapa saat. Mohon jangan tutup halaman ini.</p>";
flush(); // Tampilkan output segera

// Ambil semua task dari database
$result = $conn->query("SELECT id, model_name, project_name FROM gba_tasks");
if (!$result) {
    die("<p class='error'>Gagal mengambil data dari gba_tasks.</p></body></html>");
}

$tasks_to_update = $result->fetch_all(MYSQLI_ASSOC);
$updated_count = 0;

// Siapkan statement untuk update
$stmt = $conn->prepare("UPDATE gba_tasks SET project_name = ? WHERE id = ?");

foreach ($tasks_to_update as $task) {
    $model_name = strtoupper($task['model_name']);
    $current_marketing_name = $task['project_name'];
    $new_marketing_name = null;

    // Cari kecocokan di kamus
    foreach ($model_mapping as $key => $value) {
        if (strpos($model_name, $key) === 0) {
            $new_marketing_name = $value;
            break;
        }
    }

    // Jika ditemukan nama baru dan berbeda dari yang sekarang, update
    if ($new_marketing_name && $new_marketing_name !== $current_marketing_name) {
        $stmt->bind_param("si", $new_marketing_name, $task['id']);
        if ($stmt->execute()) {
            $updated_count++;
            echo "<p>ID #" . $task['id'] . ": Mengubah '" . htmlspecialchars($current_marketing_name) . "' menjadi '" . htmlspecialchars($new_marketing_name) . "'</p>";
            flush();
        }
    }
}

$stmt->close();
$conn->close();

echo "<p class='success'><strong>Selesai! " . $updated_count . " data berhasil diperbarui.</strong></p>";
echo "<a href='edit_mapping.php' style='color: #4CAF50; text-decoration: none;'>&larr; Kembali ke Halaman Edit Mapping</a>";
echo "</body></html>";
?>