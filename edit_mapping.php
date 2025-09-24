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
        
        $new_mapping = [];
        for ($i = 0; $i < count($models); $i++) {
            $model = strtoupper(trim($models[$i]));
            $name = trim($names[$i]);
            if (!empty($model) && !empty($name)) {
                $new_mapping[$model] = $name;
            }
        }
        $current_mapping = $new_mapping;
    }

    // Urutkan berdasarkan key (model name)
    ksort($current_mapping);

    // Buat konten file PHP baru
    $file_content = "<?php\n\n// Kamus lokal untuk Model Name -> Marketing Name\n\$model_mapping = [\n";
    foreach ($current_mapping as $model => $name) {
        $file_content .= "    \"" . addslashes($model) . "\" => \"" . addslashes($name) . "\",\n";
    }
    $file_content .= "];\n\n?>";

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
            <form action="update_database_names.php" method="POST" onsubmit="return confirm('Anda yakin ingin memperbarui semua Marketing Name di database? Aksi ini tidak dapat dibatalkan.');">
                <button type="submit" class="px-5 py-2 bg-amber-600 hover:bg-amber-500 text-white font-semibold rounded-lg text-sm flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
                    </svg>
                    Update Database
                </button>
            </form>
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
                <form method="POST" action="" class="h-full">
                    <input type="hidden" name="save_all" value="1">
                    <div class="form-container p-6 rounded-2xl">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-xl font-semibold text-header">Editor Baris per Baris</h2>
                            <button type="submit" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg text-sm">Simpan Semua Perubahan</button>
                        </div>

                        <div class="grid grid-cols-[1fr_2fr_auto] gap-x-4 gap-y-2 font-semibold text-secondary mb-2 border-b border-[var(--glass-border)] pb-2">
                            <span>Model Name</span>
                            <span>Marketing Name</span>
                            <button id="add-row" type="button" class="text-indigo-400 hover:text-indigo-300">
                                <svg class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>
                            </button>
                        </div>
                        <div id="mapping-container" class="space-y-2 h-[55vh]">
                            <?php foreach ($current_mapping as $model => $name): ?>
                            <div class="grid grid-cols-[1fr_2fr_auto] gap-x-4 gap-y-2 items-center mapping-row">
                                <input type="text" name="models[]" value="<?= htmlspecialchars($model) ?>" class="themed-input w-full p-2 text-sm rounded-lg uppercase" placeholder="SM-XXXXX">
                                <input type="text" name="names[]" value="<?= htmlspecialchars($name) ?>" class="themed-input w-full p-2 text-sm rounded-lg" placeholder="Galaxy ...">
                                <button type="button" class="remove-row p-2 text-red-400 hover:text-red-600">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        // --- Canvas Animation ---
        const canvas = document.getElementById('neural-canvas'), ctx = canvas.getContext('2d');
        let particles = [], hue = 210;
        function setCanvasSize(){canvas.width=window.innerWidth;canvas.height=window.innerHeight;}setCanvasSize();
        class Particle{constructor(x,y){this.x=x||Math.random()*canvas.width;this.y=y||Math.random()*canvas.height;this.vx=(Math.random()-.5)*.4;this.vy=(Math.random()-.5)*.4;this.size=Math.random()*2+1.5}update(){this.x+=this.vx;this.y+=this.vy;if(this.x<0||this.x>canvas.width)this.vx*=-1;if(this.y<0||this.y>canvas.height)this.vy*=-1}draw(){ctx.fillStyle=`hsl(${hue},100%,75%)`;ctx.beginPath();ctx.arc(this.x,this.y,this.size,0,Math.PI*2);ctx.fill()}}
        function init(num){particles=[];for(let i=0;i<num;i++)particles.push(new Particle())}
        function handleParticles(){for(let i=0;i<particles.length;i++){particles[i].update();particles[i].draw();for(let j=i;j<particles.length;j++){const dx=particles[i].x-particles[j].x;const dy=particles[i].y-particles[j].y;const distance=Math.sqrt(dx*dx+dy*dy);if(distance<120){ctx.beginPath();ctx.strokeStyle=`hsla(${hue},100%,80%,${1-distance/120})`;ctx.lineWidth=1;ctx.moveTo(particles[i].x,particles[i].y);ctx.lineTo(particles[j].x,particles[j].y);ctx.stroke();ctx.closePath()}}}}
        function animate(){ctx.clearRect(0,0,canvas.width,canvas.height);hue=(hue+.3)%360;handleParticles();requestAnimationFrame(animate);}
        const particleCount=window.innerWidth>768?150:70;init(particleCount);animate();
        window.addEventListener('resize',()=>{setCanvasSize();init(particleCount);});
        
        // --- FORM LOGIC ---
        document.getElementById('add-row').addEventListener('click', function() {
            const container = document.getElementById('mapping-container');
            const newRow = document.createElement('div');
            newRow.className = 'grid grid-cols-[1fr_2fr_auto] gap-x-4 gap-y-2 items-center mapping-row';
            newRow.innerHTML = `
                <input type="text" name="models[]" class="themed-input w-full p-2 text-sm rounded-lg uppercase" placeholder="SM-XXXXX">
                <input type="text" name="names[]" class="themed-input w-full p-2 text-sm rounded-lg" placeholder="Galaxy ...">
                <button type="button" class="remove-row p-2 text-red-400 hover:text-red-600">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg>
                </button>
            `;
            container.appendChild(newRow);
            newRow.querySelector('input').focus();
        });

        document.getElementById('mapping-container').addEventListener('click', function(e) {
            if (e.target.closest('.remove-row')) {
                e.target.closest('.mapping-row').remove();
            }
        });
    </script>
</body>
</html>