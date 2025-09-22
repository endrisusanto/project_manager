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

// Proses penyimpanan data jika form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['models'])) {
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
    
    // Urutkan berdasarkan key (model name)
    ksort($new_mapping);

    // Buat konten file PHP baru
    $file_content = "<?php\n\n// Kamus lokal untuk Model Name -> Marketing Name\n\$model_mapping = [\n";
    foreach ($new_mapping as $model => $name) {
        $file_content .= "    \"" . addslashes($model) . "\" => \"" . addslashes($name) . "\",\n";
    }
    $file_content .= "];\n\n?>";

    // Simpan ke file
    if (file_put_contents($mapping_file, $file_content) !== false) {
        $message = '<div class="bg-green-500/20 text-green-300 text-sm p-4 rounded-lg mb-6">Mapping berhasil disimpan!</div>';
    } else {
        $message = '<div class="bg-red-500/20 text-red-300 text-sm p-4 rounded-lg mb-6">Gagal menyimpan file. Pastikan file marketing_name_mapper.php dapat ditulis (writable).</div>';
    }
}

// Muat data mapping yang ada
require_once $mapping_file;

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
        .form-container{background:var(--glass-bg);backdrop-filter:blur(12px);border:1px solid var(--glass-border)}
        .themed-input{background-color:var(--input-bg);border:1px solid var(--input-border);color:var(--input-text)}
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <canvas id="neural-canvas"></canvas>
    <?php include 'header.php'; ?>

    <main class="w-full max-w-4xl mx-auto p-4 sm:p-8 flex-grow">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-header">Edit Model Mapping</h1>
            <div class="flex items-center gap-4">
                <form id="update-form" action="update_database_names.php" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin memperbarui semua Marketing Name di database berdasarkan daftar ini? Aksi ini tidak dapat dibatalkan.');">
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-500">
                        Update Database
                    </button>
                </form>
                <button id="add-row" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500">
                    <svg class="-ml-0.5 mr-1.5 h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" /></svg>
                    Tambah Baris
                </button>
            </div>
        </div>

        <?= $message ?>

        <form method="POST" action="">
            <div class="form-container p-6 rounded-2xl">
                <div class="grid grid-cols-[1fr_2fr_auto] gap-x-4 gap-y-2 font-semibold text-secondary mb-2 border-b border-[var(--glass-border)] pb-2">
                    <span>Model Name (e.g., SM-S928B)</span>
                    <span>Marketing Name (e.g., Galaxy S24 Ultra)</span>
                    <span>Aksi</span>
                </div>
                <div id="mapping-container" class="space-y-2">
                    <?php foreach ($model_mapping as $model => $name): ?>
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
            <div class="mt-6 text-right">
                <button type="submit" class="px-6 py-3 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg">Simpan Perubahan Mapping</button>
            </div>
        </form>
    </main>

    <script>
        // --- (Script animasi tetap sama, tidak perlu diubah) ---
        const canvas = document.getElementById('neural-canvas'), ctx = canvas.getContext('2d');
        let particles = [], hue = 210;
        function setCanvasSize(){canvas.width=window.innerWidth;canvas.height=window.innerHeight;}setCanvasSize();
        class Particle{constructor(x,y){this.x=x||Math.random()*canvas.width;this.y=y||Math.random()*canvas.height;this.vx=(Math.random()-.5)*.4;this.vy=(Math.random()-.5)*.4;this.size=Math.random()*2 + 1.5;}update(){this.x+=this.vx;this.y+=this.vy;if(this.x<0||this.x>canvas.width)this.vx*=-1;if(this.y<0||this.y>canvas.height)this.vy*=-1;}draw(){ctx.fillStyle=`hsl(${hue},100%,75%)`;ctx.beginPath();ctx.arc(this.x,this.y,this.size,0,Math.PI*2);ctx.fill();}}
        function init(num){particles=[];for(let i=0;i<num;i++)particles.push(new Particle())}
        function handleParticles(){for(let i=0;i<particles.length;i++){particles[i].update();particles[i].draw();for(let j=i;j<particles.length;j++){const dx=particles[i].x-particles[j].x;const dy=particles[i].y-particles[j].y;const distance=Math.sqrt(dx*dx+dy*dy);if(distance<120){ctx.beginPath();ctx.strokeStyle=`hsla(${hue},100%,80%,${1-distance/120})`;ctx.lineWidth=1;ctx.moveTo(particles[i].x,particles[i].y);ctx.lineTo(particles[j].x,particles[j].y);ctx.stroke();ctx.closePath();}}}}
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
        });

        document.getElementById('mapping-container').addEventListener('click', function(e) {
            if (e.target.closest('.remove-row')) {
                e.target.closest('.mapping-row').remove();
            }
        });
    </script>
</body>
</html>