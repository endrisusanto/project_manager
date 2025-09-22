<?php
header('Content-Type: application/json');

// Memanggil kamus lokal kita
require_once "marketing_name_mapper.php";

$model_name = strtoupper($_GET['model_name'] ?? '');

if (empty($model_name)) {
    echo json_encode(['success' => false, 'message' => 'Model name tidak boleh kosong.']);
    exit;
}

// Cari model name di dalam array mapping
if (isset($model_mapping[$model_name])) {
    echo json_encode(['success' => true, 'marketing_name' => $model_mapping[$model_name]]);
} else {
    // Cari pencocokan parsial (misal: SM-S928B dari SM-S928B_SEA_DX)
    $found_name = null;
    foreach ($model_mapping as $key => $value) {
        if (strpos($model_name, $key) === 0) {
            $found_name = $value;
            break;
        }
    }

    if ($found_name) {
        echo json_encode(['success' => true, 'marketing_name' => $found_name]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Nama pemasaran tidak ditemukan.']);
    }
}
?>