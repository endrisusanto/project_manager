<?php
// api_download_laundry.php - Direct Browser Stream & API Endpoint for Laundry ZIP Downloads

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/laundry_downloader.php';

$task_id = isset($_GET['task_id']) ? intval($_GET['task_id']) : (isset($_POST['task_id']) ? intval($_POST['task_id']) : 0);
$all = isset($_GET['all']) || isset($_POST['all']);
$force = isset($_GET['force']) || isset($_POST['force']);
$stream = isset($_GET['stream']) || (!isset($_GET['json']) && !isset($_POST['json']) && $task_id > 0);
$wants_json = isset($_GET['json']) || isset($_POST['json']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false && !isset($_GET['stream']));

if ($task_id > 0) {
    // Manual user download request - do not skip based on task progress status
    $result = download_laundry_zip($task_id, $force, false);

    if ($stream && !empty($result['success']) && !empty($result['path']) && file_exists($result['path'])) {
        $filepath = $result['path'];
        $filename = $result['filename'];
        $filesize = filesize($filepath);

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . $filesize);
        
        $fp = @fopen($filepath, 'rb');
        if ($fp) {
            while (!feof($fp)) {
                echo fread($fp, 65536);
                flush();
            }
            fclose($fp);
        } else {
            readfile($filepath);
        }
        exit(0);
    }

    if ($wants_json) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit(0);
    }

    // Friendly HTML Error Page for Direct Browser Clicks
    $err_msg = htmlspecialchars($result['message'] ?? 'Gagal mengunduh file Laundry.');
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Download Laundry Gagal</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'Inter', sans-serif; background: #0f172a; color: #f8fafc; }
            .font-mono { font-family: 'JetBrains Mono', monospace; }
        </style>
    </head>
    <body class="min-h-screen flex items-center justify-center p-4">
        <div class="max-w-md w-full bg-slate-900/90 border border-slate-700/80 rounded-2xl p-6 shadow-2xl backdrop-blur-xl text-center">
            <div class="w-14 h-14 mx-auto mb-4 rounded-full bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h2 class="text-xl font-bold text-white mb-2">Download Laundry Gagal</h2>
            <p class="text-sm text-slate-400 mb-4"><?= $err_msg ?></p>
            <div class="bg-slate-950/60 rounded-xl p-3 text-xs font-mono text-slate-300 border border-slate-800 text-left mb-6 break-all">
                <div><strong>Task ID:</strong> #<?= $task_id ?></div>
                <?php if (!empty($result['base_submission_id'])): ?>
                    <div><strong>Base Sub ID:</strong> <?= htmlspecialchars($result['base_submission_id']) ?></div>
                <?php endif; ?>
            </div>
            <div class="flex gap-3 justify-center">
                <button onclick="window.close(); if(history.length > 1) history.back();" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 text-sm font-semibold rounded-xl transition">
                    Tutup / Kembali
                </button>
                <a href="https://buildapprovalsystem.com/" target="_blank" class="px-4 py-2 bg-sky-600 hover:bg-sky-500 text-white text-sm font-semibold rounded-xl transition shadow-lg shadow-sky-600/20">
                    Buka BAS System
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit(0);

} elseif ($all) {
    $result = auto_download_all_pending_laundry($force);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
} else {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Parameter task_id or all is required.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(0);
}
