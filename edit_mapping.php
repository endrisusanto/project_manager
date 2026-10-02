<?php
require_once "config.php";
require_once "session.php";

// Hanya admin yang bisa mengakses halaman ini
if (!is_admin()) {
    header("Location: index.php?error=permission_denied");
    exit;
}

$active_page = 'edit_mapping';
$mapping_file = 'marketing_name_mapper.php';
$message = '';

// Muat data mapping yang ada terlebih dahulu
require_once $mapping_file;
$current_mapping = $model_mapping;
$current_userdata = isset($userdata_models) ? $userdata_models : [];
$current_dropped = isset($dropped_models) ? $dropped_models : [];

// Pastikan semua model di $current_userdata dan $current_dropped juga muncul di $current_mapping
foreach ($current_userdata as $m_code => $is_req) {
    if ($is_req && !isset($current_mapping[$m_code])) {
        $current_mapping[$m_code] = "";
    }
}
foreach ($current_dropped as $m_code => $is_drp) {
    if ($is_drp && !isset($current_mapping[$m_code])) {
        $current_mapping[$m_code] = "";
    }
}
ksort($current_mapping);

// Proses penyimpanan data jika form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Logika untuk Bulk Add
    if (isset($_POST['bulk_models'])) {
        $bulk_data = trim($_POST['bulk_models']);
        $lines = explode("\n", $bulk_data);
        $new_bulk_mapping = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $parts = preg_split('/\s+/', $line, 2); 
            
            if (count($parts) >= 2) {
                $model_full = strtoupper(trim($parts[0]));
                $model_base = explode('_', $model_full)[0];
                
                $name = trim($parts[1]);
                if (!empty($model_base) && !empty($name)) {
                    $new_bulk_mapping[$model_base] = $name;
                }
            }
        }
        
        $current_mapping = array_merge($current_mapping, $new_bulk_mapping);
        $message = '<div class="bg-blue-500/20 text-blue-300 text-sm p-4 rounded-lg mb-6">Bulk data berhasil diproses. Klik "Simpan Semua Perubahan" untuk menyimpan ke file.</div>';

    } 
    // Logika untuk editor baris per baris
    elseif (isset($_POST['models'])) {
        $models = $_POST['models'];
        $names = $_POST['names'];
        $userdata_post = $_POST['userdata'] ?? [];
        $dropped_post = $_POST['dropped'] ?? [];
        
        $new_mapping = [];
        $new_userdata = [];
        $new_dropped = [];
        for ($i = 0; $i < count($models); $i++) {
            $model = strtoupper(trim($models[$i]));
            $name = trim($names[$i]);
            if (!empty($model)) {
                $new_mapping[$model] = $name;
                if (!empty($userdata_post[$i])) {
                    $new_userdata[$model] = true;
                }
                if (!empty($dropped_post[$i])) {
                    $new_dropped[$model] = true;
                }
            }
        }
        $current_mapping = $new_mapping;
        $current_userdata = $new_userdata;
        $current_dropped = $new_dropped;
    }

    // Urutkan berdasarkan key (model name)
    ksort($current_mapping);
    ksort($current_userdata);
    ksort($current_dropped);

    // Buat konten file PHP baru
    $file_content = "<?php\n\n// Kamus lokal untuk Model Name -> Marketing Name\n\$model_mapping = [\n";
    foreach ($current_mapping as $model => $name) {
        $file_content .= "    \"" . addslashes($model) . "\" => \"" . addslashes($name) . "\",\n";
    }
    $file_content .= "];\n\n";

    $file_content .= "// List model yang membutuhkan USERDATA saat download QB Build\n\$userdata_models = [\n";
    foreach ($current_userdata as $model => $is_req) {
        if ($is_req) {
            $file_content .= "    \"" . addslashes($model) . "\" => true,\n";
        }
    }
    $file_content .= "];\n\n";

    $file_content .= "// List model yang sudah Drop atau Discontinue dari proses development\n\$dropped_models = [\n";
    foreach ($current_dropped as $model => $is_drp) {
        if ($is_drp) {
            $file_content .= "    \"" . addslashes($model) . "\" => true,\n";
        }
    }
    $file_content .= "];\n\n";

    $file_content .= "if (!function_exists('is_userdata_required')) {\n";
    $file_content .= "    function is_userdata_required(\$model_name) {\n";
    $file_content .= "        global \$userdata_models;\n";
    $file_content .= "        if (empty(\$model_name) || empty(\$userdata_models)) return false;\n";
    $file_content .= "        \$model_name = strtoupper(\$model_name);\n";
    $file_content .= "        foreach (\$userdata_models as \$key => \$val) {\n";
    $file_content .= "            if (\$val && strpos(\$model_name, strtoupper(\$key)) !== false) {\n";
    $file_content .= "                return true;\n";
    $file_content .= "            }\n";
    $file_content .= "        }\n";
    $file_content .= "        return false;\n";
    $file_content .= "    }\n";
    $file_content .= "}\n\n";

    $file_content .= "if (!function_exists('is_model_dropped')) {\n";
    $file_content .= "    function is_model_dropped(\$model_name) {\n";
    $file_content .= "        global \$dropped_models;\n";
    $file_content .= "        if (empty(\$model_name) || empty(\$dropped_models)) return false;\n";
    $file_content .= "        \$model_name = strtoupper(\$model_name);\n";
    $file_content .= "        foreach (\$dropped_models as \$key => \$val) {\n";
    $file_content .= "            if (\$val && strpos(\$model_name, strtoupper(\$key)) !== false) {\n";
    $file_content .= "                return true;\n";
    $file_content .= "            }\n";
    $file_content .= "        }\n";
    $file_content .= "        return false;\n";
    $file_content .= "    }\n";
    $file_content .= "}\n\n";
    $file_content .= "if (!function_exists('get_marketing_name')) {\n";
    $file_content .= "    function get_marketing_name(\$model_name, \$default = '') {\n";
    $file_content .= "        global \$model_mapping;\n";
    $file_content .= "        if (empty(\$model_name) || empty(\$model_mapping)) return \$default;\n";
    $file_content .= "        \$clean_model = strtoupper(trim(\$model_name));\n";
    $file_content .= "        if (isset(\$model_mapping[\$clean_model])) {\n";
    $file_content .= "            return \$model_mapping[\$clean_model];\n";
    $file_content .= "        }\n";
    $file_content .= "        foreach (\$model_mapping as \$key => \$value) {\n";
    $file_content .= "            if (strpos(\$clean_model, strtoupper(\$key)) === 0) {\n";
    $file_content .= "                return \$value;\n";
    $file_content .= "            }\n";
    $file_content .= "        }\n";
    $file_content .= "        return !empty(\$default) ? \$default : '';\n";
    $file_content .= "    }\n";
    $file_content .= "}\n\n";
    $file_content .= "if (!function_exists('is_laundry_task')) {\n";
    $file_content .= "    function is_laundry_task(\$task) {\n";
    $file_content .= "        if (!is_array(\$task)) return false;\n";
    $file_content .= "        \$sub_id = trim((string)(\$task['submission_id'] ?? ''));\n";
    $file_content .= "        \$base_sub_id = trim((string)(\$task['base_submission_id'] ?? ''));\n";
    $file_content .= "        return (!empty(\$sub_id) && \$sub_id !== '-' && \$sub_id !== '0') || (!empty(\$base_sub_id) && \$base_sub_id !== '-' && \$base_sub_id !== '0');\n";
    $file_content .= "    }\n";
    $file_content .= "}\n\n";
    $file_content .= "if (!function_exists('render_laundry_icon')) {\n";
    $file_content .= "    function render_laundry_icon(\$task, \$extra_classes = '', \$size = 'w-3.5 h-3.5') {\n";
    $file_content .= "        if (!is_laundry_task(\$task)) return '';\n";
    $file_content .= "        \$task_id = intval(\$task['id'] ?? 0);\n";
    $file_content .= "        \$unique_id = 'laundry_ico_' . (\$task['id'] ?? '') . '_' . mt_rand(1000, 9999);\n";
    $file_content .= "        \$dl_url = \"api_download_laundry.php?task_id={\$task_id}&stream=1\";\n";
    $file_content .= "        \$title = \"BAS Verified: Build terdaftar di BAS System (Laundry Mode). Klik untuk download Laundry ZIP.\";\n";
    $file_content .= "        return '<a href=\"' . \$dl_url . '\" target=\"_blank\" onclick=\"event.stopPropagation();\" class=\"inline-flex items-center justify-center hover:opacity-80 transition-opacity ' . htmlspecialchars(\$extra_classes) . '\" title=\"' . htmlspecialchars(\$title) . '\">' \n";
    $file_content .= "            . '<svg class=\"' . htmlspecialchars(\$size) . ' laundry-sparkle-anim flex-shrink-0 cursor-pointer\" viewBox=\"0 0 24 24\" fill=\"none\">' \n";
    $file_content .= "            . '<defs><linearGradient id=\"' . \$unique_id . '_grad\" x1=\"0%\" y1=\"0%\" x2=\"100%\" y2=\"100%\"><stop offset=\"0%\" stop-color=\"#22d3ee\"/><stop offset=\"50%\" stop-color=\"#38bdf8\"/><stop offset=\"100%\" stop-color=\"#3b82f6\"/></linearGradient></defs>' \n";
    $file_content .= "            . '<path d=\"M 14 2 Q 14 12 5 12 Q 14 12 14 22 Q 14 12 23 12 Q 14 12 14 22 Z\" fill=\"url(#' . \$unique_id . '_grad)\" class=\"laundry-star-main\"/>' \n";
    $file_content .= "            . '<path d=\"M 5 1 Q 5 6 0.5 6 Q 5 6 5 11 Q 5 6 9.5 6 Q 5 6 5 1 Z\" fill=\"url(#' . \$unique_id . '_grad)\" class=\"laundry-star-sec\"/>' \n";
    $file_content .= "            . '<path d=\"M 6 15.5 Q 6 19 3 19 Q 6 19 6 22.5 Q 6 19 9 19 Q 6 19 6 15.5 Z\" fill=\"#2dd4bf\" class=\"laundry-star-tert\"/>' \n";
    $file_content .= "            . '</svg></a>';\n";
    $file_content .= "    }\n";
    $file_content .= "}\n\n";
    $file_content .= "if (!function_exists('render_laundry_badge')) {\n";
    $file_content .= "    function render_laundry_badge(\$task, \$extra_classes = '') {\n";
    $file_content .= "        if (!is_laundry_task(\$task)) return '';\n";
    $file_content .= "        \$task_id = intval(\$task['id'] ?? 0);\n";
    $file_content .= "        \$unique_id = 'laundry_' . \$task_id . '_' . mt_rand(1000, 9999);\n";
    $file_content .= "        \$dl_url = \"api_download_laundry.php?task_id={\$task_id}&stream=1\";\n";
    $file_content .= "        \$title = \"BAS Verified: Build terdaftar di BAS System (Laundry Mode). Klik untuk download Laundry ZIP.\";\n";
    $file_content .= "        return '<a href=\"' . \$dl_url . '\" target=\"_blank\" onclick=\"event.stopPropagation();\" class=\"badge-laundry cursor-pointer inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs leading-none font-bold bg-sky-500/10 text-sky-400 hover:text-sky-300 hover:bg-sky-500/20 border border-sky-400/25 transition-all shadow-none ' . htmlspecialchars(\$extra_classes) . '\" title=\"' . htmlspecialchars(\$title) . '\">' \n";
    $file_content .= "            . '<svg class=\"w-4 h-4 laundry-sparkle-anim flex-shrink-0\" viewBox=\"0 0 24 24\" fill=\"none\">' \n";
    $file_content .= "            . '<defs><linearGradient id=\"' . \$unique_id . '_grad\" x1=\"0%\" y1=\"0%\" x2=\"100%\" y2=\"100%\"><stop offset=\"0%\" stop-color=\"#22d3ee\"/><stop offset=\"50%\" stop-color=\"#38bdf8\"/><stop offset=\"100%\" stop-color=\"#3b82f6\"/></linearGradient></defs>' \n";
    $file_content .= "            . '<path d=\"M 14 2 Q 14 12 5 12 Q 14 12 14 22 Q 14 12 23 12 Q 14 12 14 22 Z\" fill=\"url(#' . \$unique_id . '_grad)\" class=\"laundry-star-main\"/>' \n";
    $file_content .= "            . '<path d=\"M 5 1 Q 5 6 0.5 6 Q 5 6 5 11 Q 5 6 9.5 6 Q 5 6 5 1 Z\" fill=\"url(#' . \$unique_id . '_grad)\" class=\"laundry-star-sec\"/>' \n";
    $file_content .= "            . '<path d=\"M 6 15.5 Q 6 19 3 19 Q 6 19 6 22.5 Q 6 19 9 19 Q 6 19 6 15.5 Z\" fill=\"#2dd4bf\" class=\"laundry-star-tert\"/>' \n";
    $file_content .= "            . '</svg>' \n";
    $file_content .= "            . '<span class=\"starlight-shimmer-text font-bold text-[11px] sm:text-xs\">Laundry</span></a>';\n";
    $file_content .= "    }\n";
    $file_content .= "}\n\n";
    $file_content .= "if (!function_exists('render_orbit_sync_badge')) {\n";
    $file_content .= "    function render_orbit_sync_badge(\$phrase = 'Orbit Sync', \$extra_classes = '') {\n";
    $file_content .= "        \$unique_id = 'orbit_' . uniqid();\n";
    $file_content .= "        return '<span class=\"orbit-sync-badge ' . htmlspecialchars(\$extra_classes) . '\" title=\"Orbit Sync: Active real-time synchronization\">' \n";
    $file_content .= "            . '<span class=\"orbit-sparkles-layer\" aria-hidden=\"true\"><svg class=\"w-3.5 h-3.5 flex-shrink-0\" viewBox=\"0 0 24 24\" fill=\"none\"><defs><linearGradient id=\"' . \$unique_id . '_grad\" x1=\"0%\" y1=\"0%\" x2=\"100%\" y2=\"100%\"><stop offset=\"0%\" stop-color=\"#22d3ee\"/><stop offset=\"50%\" stop-color=\"#38bdf8\"/><stop offset=\"100%\" stop-color=\"#3b82f6\"/></linearGradient></defs><path d=\"M15.5 3 Q15.5 9.5 9 9.5 Q15.5 9.5 15.5 16 Q15.5 9.5 22 9.5 Q15.5 9.5 15.5 3 Z\" fill=\"url(#' . \$unique_id . '_grad)\" class=\"orbit-sparkle-1\"/><path d=\"M7 11.5 Q7 16 2.5 16 Q7 16 7 20.5 Q7 16 11.5 16 Q7 16 7 11.5 Z\" fill=\"url(#' . \$unique_id . '_grad)\" class=\"orbit-sparkle-2\"/></svg></span>' \n";
    $file_content .= "            . '<span class=\"orbit-shimmer-text\">' . htmlspecialchars(\$phrase) . '</span></span>';\n";
    $file_content .= "    }\n";
    $file_content .= "}\n\n";
    $file_content .= "?>";

    // Simpan ke file jika ada aksi submit "Simpan Semua"
    if (isset($_POST['save_all'])) {
        if (file_put_contents($mapping_file, $file_content) !== false) {
            $message = '<div class="bg-green-500/20 text-green-300 text-sm p-4 rounded-lg mb-6">Mapping berhasil disimpan!</div>';
        } else {
            $message = '<div class="bg-red-500/20 text-red-300 text-sm p-4 rounded-lg mb-6">Gagal menyimpan file. Pastikan file marketing_name_mapper.php dapat ditulis (writable).</div>';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
    <meta charset="UTF-8">
    <title>Edit Model Mapping</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root{--bg-primary:#020617;--text-primary:#e2e8f0;--text-secondary:#94a3b8;--glass-bg:rgba(15,23,42,.8);--glass-border:rgba(51,65,85,.6);--input-bg:rgba(30,41,59,.7);--input-border:#475569;--input-text:#e2e8f0;}
        html.light{--bg-primary:#f1f5f9;--text-primary:#0f172a;--text-secondary:#475569;--glass-bg:rgba(255,255,255,.7);--glass-border:rgba(0,0,0,.1);--input-bg:#fff;--input-border:#cbd5e1;--input-text:#0f172a;}
        body{font-family:'Inter',sans-serif;background-color:var(--bg-primary);color:var(--text-primary)}
        #neural-canvas{position:fixed;top:0;left:0;width:100%;height:100%;z-index:-1}
        .form-container{background:var(--glass-bg);backdrop-filter:blur(12px);border:1px solid var(--glass-border); display: flex; flex-direction: column; height: 100%;}
        .themed-input{background-color:var(--input-bg);border:1px solid var(--input-border);color:var(--input-text)}
        #mapping-container { flex-grow: 1; overflow-y: auto; }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <canvas id="neural-canvas"></canvas>
    <?php include 'header.php'; ?>

    <main class="w-full max-w-7xl mx-auto p-4 sm:p-8 flex-grow">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-header">Edit Model Mapping</h1>
            <a href="update_database_names.php" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white font-semibold rounded-lg text-sm flex items-center gap-1.5 transition active:scale-95 shadow-sm">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
                </svg>
                <span>Sync Database Names</span>
            </a>
        </div>
        
        <?= $message ?>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div>
                <form method="POST" action="" class="h-full">
                    <div class="form-container p-6 rounded-2xl">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-xl font-semibold text-header">Bulk Add / Update</h2>
                            <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg text-sm">Proses Bulk Data</button>
                        </div>
                        <div>
                            <label for="bulk_models" class="block mb-2 text-sm font-medium text-secondary">
                                Paste dari tabel (Format: Model Name [Tab/Spasi] Marketing Name)
                            </label>
                            <textarea id="bulk_models" name="bulk_models" class="themed-input block w-full text-sm rounded-lg p-2.5 font-mono h-[60vh]" placeholder="SM-X520_EUR_16_XX Galaxy Tab S10 FE&#10;SM-X620B_SEA_16_DX Galaxy Tab S10 FE+"></textarea>
                        </div>
                    </div>
                </form>
            </div>

            <div>
                <form method="POST" action="" class="h-full" id="row-editor-form">
                    <input type="hidden" name="save_all" value="1">
                    <div class="form-container p-6 rounded-2xl">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-xl font-semibold text-header">Editor Baris per Baris</h2>
                            <button type="submit" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg text-sm">Simpan Semua Perubahan</button>
                        </div>

                        <div class="grid grid-cols-[1.1fr_1.8fr_90px_100px_auto] gap-x-3 gap-y-2 font-semibold text-secondary mb-2 border-b border-[var(--glass-border)] pb-2 items-center">
                            <span>Model Name</span>
                            <span>Marketing Name</span>
                            <span class="text-center text-xs" title="Membutuhkan USERDATA saat download QB Build">USERDATA</span>
                            <span class="text-center text-xs text-rose-400 font-bold" title="Tandai model jika sudah discontinue atau drop dari proses development">Drop / Disc.</span>
                            <button id="add-row" type="button" class="text-indigo-400 hover:text-indigo-300" title="Tambah Baris Baru">
                                <svg class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>
                            </button>
                        </div>
                        <div id="mapping-container" class="space-y-2 h-[55vh]">
                            <?php 
                            $rowIndex = 0;
                            foreach ($current_mapping as $model => $name): 
                                $is_ud = !empty($current_userdata[$model]);
                                $is_drp = !empty($current_dropped[$model]);
                            ?>
                            <div class="grid grid-cols-[1.1fr_1.8fr_90px_100px_auto] gap-x-3 gap-y-2 items-center mapping-row">
                                <input type="text" name="models[]" value="<?= htmlspecialchars($model) ?>" class="themed-input w-full p-2 text-sm rounded-lg uppercase" placeholder="SM-XXXXX">
                                <input type="text" name="names[]" value="<?= htmlspecialchars($name) ?>" class="themed-input w-full p-2 text-sm rounded-lg" placeholder="Galaxy ...">
                                <div class="flex justify-center items-center">
                                    <input type="checkbox" name="userdata[<?= $rowIndex ?>]" value="1" <?= $is_ud ? 'checked' : '' ?> class="cb-userdata w-4 h-4 rounded border-slate-600 bg-slate-700 text-amber-500 focus:ring-amber-400 cursor-pointer" title="USERDATA Required">
                                </div>
                                <div class="flex justify-center items-center">
                                    <input type="checkbox" name="dropped[<?= $rowIndex ?>]" value="1" <?= $is_drp ? 'checked' : '' ?> class="cb-dropped w-4 h-4 rounded border-slate-600 bg-slate-700 text-rose-500 focus:ring-rose-400 cursor-pointer accent-rose-600" title="Tandai jika model ini Drop / Discontinue">
                                </div>
                                <button type="button" class="remove-row p-2 text-red-400 hover:text-red-600" title="Hapus baris">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg>
                                </button>
                            </div>
                            <?php 
                            $rowIndex++;
                            endforeach; 
                            ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        // Note: #neural-canvas is now managed by the centralized lean engine in header.php
        // --- FORM LOGIC ---
        let rowCounter = <?= $rowIndex ?>;
        document.getElementById('add-row').addEventListener('click', function() {
            const container = document.getElementById('mapping-container');
            const newRow = document.createElement('div');
            newRow.className = 'grid grid-cols-[1.1fr_1.8fr_90px_100px_auto] gap-x-3 gap-y-2 items-center mapping-row';
            newRow.innerHTML = `
                <input type="text" name="models[]" class="themed-input w-full p-2 text-sm rounded-lg uppercase" placeholder="SM-XXXXX">
                <input type="text" name="names[]" class="themed-input w-full p-2 text-sm rounded-lg" placeholder="Galaxy ...">
                <div class="flex justify-center items-center">
                    <input type="checkbox" name="userdata[${rowCounter}]" value="1" class="cb-userdata w-4 h-4 rounded border-slate-600 bg-slate-700 text-amber-500 focus:ring-amber-400 cursor-pointer" title="USERDATA Required">
                </div>
                <div class="flex justify-center items-center">
                    <input type="checkbox" name="dropped[${rowCounter}]" value="1" class="cb-dropped w-4 h-4 rounded border-slate-600 bg-slate-700 text-rose-500 focus:ring-rose-400 cursor-pointer accent-rose-600" title="Tandai jika model ini Drop / Discontinue">
                </div>
                <button type="button" class="remove-row p-2 text-red-400 hover:text-red-600" title="Hapus baris">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg>
                </button>
            `;
            container.appendChild(newRow);
            rowCounter++;
            newRow.querySelector('input').focus();
        });

        const rowForm = document.getElementById('row-editor-form');
        if (rowForm) {
            rowForm.addEventListener('submit', function() {
                document.querySelectorAll('.mapping-row').forEach((row, i) => {
                    const cbUd = row.querySelector('.cb-userdata');
                    if (cbUd) cbUd.name = `userdata[${i}]`;
                    const cbDrop = row.querySelector('.cb-dropped');
                    if (cbDrop) cbDrop.name = `dropped[${i}]`;
                });
            });
        }

        document.getElementById('mapping-container').addEventListener('click', function(e) {
            if (e.target.closest('.remove-row')) {
                e.target.closest('.mapping-row').remove();
            }
        });
    </script>
</body>
</html>