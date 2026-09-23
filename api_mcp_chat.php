<?php
// PHP Reverse Proxy & Direct Client for MCP Chat Endpoint & LM Studio
// Compatible with Docker, XAMPP (Local PC, LAN PC, or Public Server)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?: [];

$chatInput      = $data['chatInput'] ?? $data['message'] ?? $data['prompt'] ?? '';
$messages       = $data['messages'] ?? [];
$userName       = $data['user'] ?? 'User';
$userRole       = $data['role'] ?? 'user';
$customEndpoint = $data['endpoint'] ?? $data['base_url'] ?? $_GET['endpoint'] ?? $_GET['base_url'] ?? null;
$selectedModel  = $data['model'] ?? getenv('LMSTUDIO_MODEL') ?: 'auto';

// Helper: ambil task terbaru langsung dari MySQL jika memanggil LM Studio secara direct
function getDbTasksContext() {
    $dbServer = getenv('DB_SERVER') ?: 'localhost';
    $dbUser   = getenv('DB_USERNAME') ?: 'root';
    $dbPass   = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '';
    $dbName   = getenv('DB_NAME') ?: 'project_manager_db';
    $dbPort   = (int)(getenv('DB_PORT') ?: 3306);

    try {
        $mysqli = @new mysqli($dbServer, $dbUser, $dbPass, $dbName, $dbPort);
        if ($mysqli->connect_error && $dbServer === 'localhost') {
            $mysqli = @new mysqli('127.0.0.1', $dbUser, $dbPass, $dbName, 3309);
        }
        if (!$mysqli->connect_error) {
            $mysqli->set_charset("utf8mb4");
            $res = $mysqli->query("SELECT id, model_name, ap, cp, csc, pic_email, test_plan_type, progress_status, deadline, submission_date, request_date, notes FROM gba_tasks ORDER BY id DESC LIMIT 15");
            if ($res) {
                $tasks = $res->fetch_all(MYSQLI_ASSOC);
                $mysqli->close();
                return $tasks;
            }
        }
    } catch (\Throwable $e) {}
    return [];
}

// Helper: Komunikasi langsung ke LM Studio endpoint (/chat/completions)
function callLmStudioDirect($rawEndpoint, $chatInput, $chatMessages, $userName = 'User', $userRole = 'user', $model = 'auto') {
    // ponytail: sanitize endpoint - buang trailing slash, /models, atau /chat/completions
    $baseUrl = trim($rawEndpoint);
    $baseUrl = preg_replace('#/+$#', '', $baseUrl);
    $baseUrl = preg_replace('#/(models|chat/completions)$#i', '', $baseUrl);
    $baseUrl = preg_replace('#/+$#', '', $baseUrl);
    if (!str_ends_with($baseUrl, '/v1') && !str_contains($baseUrl, '/v1')) {
        $baseUrl .= '/v1';
    }

    $apiKey = getenv('LMSTUDIO_API_KEY') ?: 'lm-studio';

    // Auto-detect model aktif dari /models jika model 'auto' atau tidak diset
    if (!$model || $model === 'auto') {
        $chM = curl_init($baseUrl . '/models');
        curl_setopt($chM, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chM, CURLOPT_TIMEOUT, 8);
        curl_setopt($chM, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($chM, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($chM, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $apiKey]);
        $modelsRaw = curl_exec($chM);
        curl_close($chM);
        if ($modelsRaw) {
            $mData = json_decode($modelsRaw, true);
            if (!empty($mData['data'])) {
                foreach ($mData['data'] as $m) {
                    if (!empty($m['id']) && stripos($m['id'], 'embed') === false) {
                        $model = $m['id'];
                        break;
                    }
                }
                if (!$model || $model === 'auto') {
                    $model = $mData['data'][0]['id'] ?? 'google/gemma-4-12b-qat';
                }
            }
        }
        if (!$model || $model === 'auto') {
            $model = 'google/gemma-4-12b-qat';
        }
    }

    $dbTasks = getDbTasksContext();
    $tasksJson = !empty($dbTasks) ? json_encode($dbTasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '[]';

    $systemMessage = "Anda adalah GBA AI Assistant untuk aplikasi PHP Project Manager yang menyajikan laporan harian untuk seluruh Tim GBA.\n"
        . "Pengguna/Pengakses: {$userName} (Role: {$userRole}).\n"
        . "Berikut adalah data task terbaru dari MySQL database:\n{$tasksJson}\n\n"
        . "Jawab pertanyaan secara profesional, ringkas, dan jelas dalam Bahasa Indonesia untuk {$userName}. DILARANG menggunakan emoji apapun dalam balasan teks atau tabel.\n"
        . "- Sapaan Resmi: Gunakan sapaan personal \"Halo {$userName}\". DILARANG MENGGUNAKAN \"Halo Tim GBA\" saat membalas chat ke pengakses pribadi.\n"
        . "- Analisis Deadline Fleksibel: Analisis tanggal deadline secara fleksibel dari data aktual database (sebutkan deadline terdekat, rentang tanggal deadline, serta highlight task yang mendekati deadline).\n"
        . "- Jika pengguna meminta data task, tampilkan dalam Markdown Table 7 kolom: | No | Model & Build (AP / CP / CSC) | PIC | Test Plan | Status | Kinerja | Tanggal |.\n"
        . "- Jika pengguna meminta diagram/chart:\n"
        . "  ATURAN MUTLAK DIAGRAM:\n"
        . "  - DILARANG KERAS membuat Pie Chart atau menggunakan elemen <path> dan <circle> (karena kalkulasi trigonometri busur lingkaran merusak visual).\n"
        . "  - WAJIB HANYA membuat Horizontal Bar Chart dengan elemen <rect> dan <text> yang rapi, proporsional, serta memiliki badge personal info {$userName} (Role: {$userRole}).\n"
        . "  - Semua batang grafik HARUS sejajar pada x=\"170\", label pada x=\"30\", dan angka pada x=\"375\".\n"
        . "  - Lebar batang grafik (width) bernilai proporsional antara 25 hingga 190 (DILARANG MELEBIHI 190).\n"
        . "  Format SVG:\n"
        . "  <svg viewBox=\"0 0 520 300\" preserveAspectRatio=\"xMidYMid meet\" xmlns=\"http://www.w3.org/2000/svg\">\n"
        . "    <rect width=\"520\" height=\"300\" rx=\"14\" fill=\"#0f172a\" stroke=\"#334155\" stroke-width=\"1.5\" />\n"
        . "    <text x=\"30\" y=\"37\" fill=\"#38bdf8\" font-size=\"16\" font-weight=\"700\">Analisis Distribusi Task GBA</text>\n"
        . "    <rect x=\"330\" y=\"18\" width=\"160\" height=\"28\" rx=\"14\" fill=\"#1e293b\" stroke=\"#38bdf8\" stroke-width=\"1.2\" />\n"
        . "    <text x=\"410\" y=\"36\" fill=\"#38bdf8\" font-size=\"11\" font-weight=\"700\" text-anchor=\"middle\">PIC: {$userName} ({$userRole})</text>\n"
        . "    <line x1=\"30\" y1=\"58\" x2=\"490\" y2=\"58\" stroke=\"#1e293b\" stroke-width=\"1.5\" />\n"
        . "    <text x=\"30\" y=\"93\" fill=\"#cbd5e1\" font-size=\"12\" font-weight=\"600\">[Kategori 1]</text>\n"
        . "    <rect x=\"170\" y=\"78\" width=\"190\" height=\"20\" rx=\"5\" fill=\"#1e293b\" />\n"
        . "    <rect x=\"170\" y=\"78\" width=\"[Lebar1_Antara25_sd_190]\" height=\"20\" rx=\"5\" fill=\"#38bdf8\" />\n"
        . "    <text x=\"375\" y=\"93\" fill=\"#f8fafc\" font-size=\"12\" font-weight=\"700\">[Nilai1] Tasks</text>\n"
        . "    <text x=\"30\" y=\"133\" fill=\"#cbd5e1\" font-size=\"12\" font-weight=\"600\">[Kategori 2]</text>\n"
        . "    <rect x=\"170\" y=\"118\" width=\"190\" height=\"20\" rx=\"5\" fill=\"#1e293b\" />\n"
        . "    <rect x=\"170\" y=\"118\" width=\"[Lebar2_Antara25_sd_190]\" height=\"20\" rx=\"5\" fill=\"#10b981\" />\n"
        . "    <text x=\"375\" y=\"133\" fill=\"#f8fafc\" font-size=\"12\" font-weight=\"700\">[Nilai2] Tasks</text>\n"
        . "    <text x=\"30\" y=\"173\" fill=\"#cbd5e1\" font-size=\"12\" font-weight=\"600\">[Kategori 3]</text>\n"
        . "    <rect x=\"170\" y=\"158\" width=\"190\" height=\"20\" rx=\"5\" fill=\"#1e293b\" />\n"
        . "    <rect x=\"170\" y=\"158\" width=\"[Lebar3_Antara25_sd_190]\" height=\"20\" rx=\"5\" fill=\"#f59e0b\" />\n"
        . "    <text x=\"375\" y=\"173\" fill=\"#f8fafc\" font-size=\"12\" font-weight=\"700\">[Nilai3] Tasks</text>\n"
        . "    <text x=\"30\" y=\"213\" fill=\"#cbd5e1\" font-size=\"12\" font-weight=\"600\">[Kategori 4]</text>\n"
        . "    <rect x=\"170\" y=\"198\" width=\"190\" height=\"20\" rx=\"5\" fill=\"#1e293b\" />\n"
        . "    <rect x=\"170\" y=\"198\" width=\"[Lebar4_Antara25_sd_190]\" height=\"20\" rx=\"5\" fill=\"#ef4444\" />\n"
        . "    <text x=\"375\" y=\"213\" fill=\"#f8fafc\" font-size=\"12\" font-weight=\"700\">[Nilai4] Tasks</text>\n"
        . "    <line x1=\"30\" y1=\"245\" x2=\"490\" y2=\"245\" stroke=\"#1e293b\" stroke-width=\"1.5\" />\n"
        . "    <text x=\"260\" y=\"275\" fill=\"#94a3b8\" font-size=\"11\" text-anchor=\"middle\">Laporan Personal untuk {$userName} | Data Aktual MySQL Database</text>\n"
        . "  </svg>";

    $messagesPayload = [['role' => 'system', 'content' => $systemMessage]];
    if (!empty($chatMessages) && is_array($chatMessages)) {
        foreach ($chatMessages as $msg) {
            if (isset($msg['role'], $msg['content'])) {
                $messagesPayload[] = [
                    'role' => $msg['role'],
                    'content' => (string)$msg['content']
                ];
            }
        }
    } else {
        $messagesPayload[] = ['role' => 'user', 'content' => $chatInput];
    }

    $lmPayload = json_encode([
        'model' => $model,
        'messages' => $messagesPayload,
        'temperature' => 0.7,
        'max_tokens' => 4096
    ]);

    $ch = curl_init($baseUrl . '/chat/completions');
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $lmPayload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200 && $response !== false) {
        $resData = json_decode($response, true);
        $reply = $resData['choices'][0]['message']['content'] 
            ?? $resData['choices'][0]['message']['reasoning_content'] 
            ?? "Tidak ada balasan dari model LM Studio.";
        return ['success' => true, 'output' => $reply, 'model' => $model];
    }

    return [
        'success' => false,
        'error' => "Gagal terhubung ke LM Studio ({$baseUrl}). [HTTP: {$httpCode}] [cURL: {$curlErr}]. Response: {$response}"
    ];
}

// Tentukan target URL (bisa MCP Server atau langsung LM Studio)
$configuredMcpUrl = getenv('MCP_SERVER_URL') ?: 'http://107.102.39.55:3800/api/mcp/chat';
$targetUrl = $customEndpoint ?: $configuredMcpUrl;

// Cek apakah target URL ditujukan langsung ke LM Studio (mengandung lmstudio, :1234, atau /models)
$isDirectLmStudio = (
    stripos($targetUrl, 'lmstudio') !== false ||
    stripos($targetUrl, ':1234') !== false ||
    preg_match('#/(models|chat/completions)$#i', $targetUrl)
);

if ($isDirectLmStudio) {
    // Mode 1: Panggilan langsung ke LM Studio dengan normalisasi URL
    $lmResult = callLmStudioDirect($targetUrl, $chatInput, $messages, $userName, $userRole, $selectedModel);
    if ($lmResult['success']) {
        echo json_encode(['output' => $lmResult['output'], 'model' => $lmResult['model']]);
    } else {
        echo json_encode(['output' => '⚠️ ' . $lmResult['error']]);
    }
    exit;
}

// Mode 2: Proksikan ke MCP Server HTTP Bridge
$inputPayload = $rawInput;
$isExternal = (strpos($targetUrl, 'localhost') === false && strpos($targetUrl, '127.0.0.1') === false);
if ($isExternal && !empty($inputPayload)) {
    $base64Payload = base64_encode($inputPayload);
    $inputPayload = json_encode([
        'isBase64' => true,
        'payload' => $base64Payload
    ]);
}

$isLocalTarget = (strpos($targetUrl, 'localhost') !== false || strpos($targetUrl, '127.0.0.1') !== false);

$ch = curl_init($targetUrl);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $inputPayload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 300);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $isLocalTarget ? 3 : 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer lm-studio'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($httpCode === 200 && $response !== false) {
    $resObj = json_decode($response, true);
    // Jika respons dari MCP server berisi pesan error gagal koneksi LM Studio, otomatis fallback ke LM Studio langsung
    $hasConnectionError = is_array($resObj) && !empty($resObj['output']) && (
        str_contains($resObj['output'], 'Gagal terhubung') ||
        str_contains($resObj['output'], 'could not be resolved') ||
        str_contains($resObj['output'], 'ECONNREFUSED') ||
        str_contains($resObj['output'], 'ENOTFOUND')
    );
    if (!$hasConnectionError) {
        echo $response;
        exit;
    }
}

// Mode 3: Fallback otomatis ke LM Studio langsung jika MCP Server lokal offline / error
$fallbackLmUrl = getenv('LMSTUDIO_BASE_URL') ?: 'https://lmstudio.endrisusanto.my.id/v1';
$lmResult = callLmStudioDirect($fallbackLmUrl, $chatInput, $messages, $userName, $userRole, $selectedModel);

if ($lmResult['success']) {
    echo json_encode(['output' => $lmResult['output'], 'model' => $lmResult['model']]);
} else {
    echo json_encode([
        'output' => "⚠️ MCP Server ({$targetUrl}) offline [HTTP: {$httpCode}, {$curlErr}], dan direct fallback ke LM Studio juga gagal: " . $lmResult['error']
    ]);
}
