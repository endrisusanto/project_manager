<?php
// PHP Proxy: Fetch Model List dari LM Studio API
// Dipanggil oleh Settings Panel di header.php via JavaScript fetch()

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Ambil base_url dari query param atau gunakan default tunnel LM Studio
$baseUrl = $_GET['base_url'] ?? 'https://lmstudio.endrisusanto.my.id/v1';
$baseUrl = rtrim($baseUrl, '/');
$baseUrl = preg_replace('#/model/v1$#', '/v1', $baseUrl);
$baseUrl = preg_replace('#/models$#', '', $baseUrl);
$modelsUrl = $baseUrl . '/models';

$ch = curl_init($modelsUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Authorization: Bearer lm-studio'
));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($httpCode === 200 && $response !== false) {
    $data = json_decode($response, true);
    $models = array_map(function($m) { return $m['id']; }, $data['data'] ?? []);
    echo json_encode(['success' => true, 'models' => $models]);
} else {
    echo json_encode([
        'success' => false,
        'error'   => "Gagal fetch model dari LM Studio ($modelsUrl). [HTTP: $httpCode] [cURL: $curlErr]",
        'models'  => []
    ]);
}
