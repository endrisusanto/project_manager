<?php
require_once "config.php";
require_once "session.php";

// Hanya admin yang bisa mengakses halaman ini
if (!is_admin()) {
    header("Location: index.php?error=permission_denied");
    exit;
}

$active_page = 'bulk_add';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Bulk Add GBA Tasks</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <style>
        :root{--bg-primary:#020617;--text-primary:#e2e8f0;--text-secondary:#94a3b8;--glass-bg:rgba(15,23,42,.8);--glass-border:rgba(51,65,85,.6);--input-bg:rgba(30,41,59,.7);--input-border:#475569;--input-text:#e2e8f0; --modal-bg:rgba(15,23,42,.6); --modal-border:rgba(51,65,85,.6);}
        html.light{--bg-primary:#f1f5f9;--text-primary:#0f172a;--text-secondary:#475569;--glass-bg:rgba(255,255,255,.7);--glass-border:rgba(0,0,0,.1);--input-bg:#fff;--input-border:#cbd5e1;--input-text:#0f172a; --modal-bg:rgba(255,255,255,.6); --modal-border:rgba(0,0,0,.1);}
        body{font-family:'Inter',sans-serif;background-color:var(--bg-primary);color:var(--text-primary)}
        #neural-canvas{position:fixed;top:0;left:0;width:100%;height:100%;z-index:-1}
        .form-container{background:var(--glass-bg);backdrop-filter:blur(12px);border:1px solid var(--glass-border)}
        .themed-input{background-color:var(--input-bg);border:1px solid var(--input-border);color:var(--input-text)}
        .glassmorphism-modal{background:var(--modal-bg);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid var(--modal-border)}
        .ql-toolbar,.ql-container{border-color:var(--glass-border)!important}.ql-editor{color:var(--text-primary);min-height:100px}
        .nav-link{color:var(--text-secondary);transition:color .2s,border-color .2s;border-bottom:2px solid transparent}.nav-link:hover{color:var(--text-primary)}.nav-link-active{color:var(--text-primary)!important;font-weight:500;border-bottom:2px solid #3b82f6}
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <canvas id="neural-canvas"></canvas>
    <?php include 'header.php'; ?>

    <main class="w-full max-w-4xl mx-auto p-4 sm:p-8 flex-grow">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-header">Bulk Add GBA Tasks</h1>
        </div>

        <div class="form-container p-6 rounded-2xl">
            <form action="handler.php" method="POST">
                <input type="hidden" name="action" value="create_bulk_gba_task">
                <div>
                    <label for="bulk_data" class="block mb-2 text-sm font-medium text-secondary">
                        Paste data dari Excel (Format: MODEL | AP | CP | CSC | TYPE REQUEST | QB USER | QB USERDEBUG)
                    </label>
                    <textarea id="bulk_data" name="bulk_data" rows="15" class="themed-input block w-full text-sm rounded-lg p-2.5 font-mono" placeholder="Contoh:&#10;model ap cp csc type qb_user qb_userdebug&#10;SM-S918B_SEA_15_DX S918BXXS8DYI3 S918BXXS8DYI3 S918BOLE8DYI3 SMR 100733179 100733181&#10;SM-F946B_SEA_16_DX F946BXXU5FYI8 F946BXXU5FYI8 F946BOLE5FYI8 NORMAL 100733177 100733180"></textarea>
                </div>
                <div class="mt-6 text-right">
                    <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg">
                        Tambah Tasks
                    </button>
                </div>
            </form>
        </div>
    </main>

    <div id="task-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-70 hidden">
        <div class="glassmorphism-modal rounded-lg shadow-xl p-6 w-full max-w-6xl mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
            <div class="flex justify-between items-center mb-4">
                <h2 id="modal-title" class="text-2xl font-bold text-header">Tambah Task Baru</h2>
                <button onclick="closeModal()" class="text-secondary hover:text-primary text-3xl font-bold">&times;</button>
            </div>
            <form id="task-form" action="handler.php" method="POST">
                <input type="hidden" name="id" id="task-id">
                <input type="hidden" name="action" id="form-action" value="create_gba_task">
                <?php include 'gba_task_form.php'; ?>
                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" onclick="closeModal()" class="px-4 py-2 rounded-lg themed-input">Batal</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">Simpan Task</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // --- Canvas Animation ---
        const canvas = document.getElementById('neural-canvas'), ctx = canvas.getContext('2d');
        let particles = [], hue = 210;
        function setCanvasSize(){canvas.width=window.innerWidth;canvas.height=window.innerHeight;}setCanvasSize();
        class Particle{constructor(x,y){this.x=x||Math.random()*canvas.width;this.y=y||Math.random()*canvas.height;this.vx=(Math.random()-.5)*.4;this.vy=(Math.random()-.5)*.4;this.size=Math.random()*2+1.5}update(){this.x+=this.vx;this.y+=this.vy;if(this.x<0||this.x>canvas.width)this.vx*=-1;if(this.y<0||this.y>canvas.height)this.vy*=-1}draw(){ctx.fillStyle=`hsl(${hue},100%,75%)`;ctx.beginPath();ctx.arc(this.x,this.y,this.size,0,Math.PI*2);ctx.fill()}}
        function init(num){particles=[];for(let i=0;i<num;i++)particles.push(new Particle())}
        function handleParticles(){for(let i=0;i<particles.length;i++){particles[i].update();particles[i].draw();for(let j=i;j<particles.length;j++){const dx=particles[i].x-particles[j].x;const dy=particles[i].y-particles[j].y;const distance=Math.sqrt(dx*dx+dy*dy);if(distance<120){ctx.beginPath();ctx.strokeStyle=`hsla(${hue},100%,80%,${1-distance/120})`;ctx.lineWidth=1;ctx.moveTo(particles[i].x,particles[i].y);ctx.lineTo(particles[j].x,particles[j].y);ctx.stroke();ctx.closePath()}}}}
        function animate(){ctx.clearRect(0,0,canvas.width,canvas.height);hue=(hue+.3)%360;handleParticles();requestAnimationFrame(animate)}
        const particleCount=window.innerWidth>768?150:70;init(particleCount);animate();
        window.addEventListener('resize',()=>{setCanvasSize();init(particleCount)});

        // --- Common Page Logic (Theme, Modal, Profile Dropdown) ---
        const themeToggleBtn = document.getElementById('theme-toggle'),
              modal = document.getElementById('task-modal'),
              modalTitle = document.getElementById('modal-title'),
              taskForm = document.getElementById('task-form');
        let quill;

        function applyTheme(isLight) {
            document.documentElement.classList.toggle('light', isLight);
            document.getElementById('theme-toggle-light-icon').classList.toggle('hidden', !isLight);
            document.getElementById('theme-toggle-dark-icon').classList.toggle('hidden', isLight);
        }
        const savedTheme = localStorage.getItem('theme');
        applyTheme(savedTheme === 'light');
        themeToggleBtn.addEventListener('click', () => {
            const isLight = !document.documentElement.classList.contains('light');
            localStorage.setItem('theme', isLight ? 'light' : 'dark');
            applyTheme(isLight);
        });
        
        function openAddModal() {
            taskForm.reset();
            modalTitle.innerText = 'Tambah Task Baru';
            taskForm.elements['action'].value = 'create_gba_task';
            taskForm.elements['id'].value = '';
            setDefaultDates(); // Panggil fungsi untuk set tanggal otomatis
            setupQuill('');
            updateChecklistVisibility();
            modal.classList.remove('hidden');
        }

        function closeModal() {
            modal.classList.add('hidden');
        }
        window.onclick = (event) => {
            if (event.target == modal) closeModal();
        };
        
        function setupQuill(content) {
            if (!quill) {
                quill = new Quill('#notes-editor', {
                    theme: 'snow',
                    modules: { toolbar: [['bold', 'italic'], ['link'], [{ 'list': 'ordered' }, { 'list': 'bullet' }]] }
                });
            }
            quill.root.innerHTML = content;
        }
        taskForm.addEventListener('submit', () => {
            document.getElementById('notes-hidden-input').value = quill.root.innerHTML;
        });

        document.getElementById('test_plan_type').addEventListener('change', updateChecklistVisibility);
        function updateChecklistVisibility() {
            const testPlan = document.getElementById('test_plan_type').value;
            const placeholder = document.getElementById('checklist-placeholder');
            let checklistVisible = false;
            document.querySelectorAll('[id^="checklist-container-"]').forEach(el => {
                const planName = el.id.replace('checklist-container-', '').replace(/_/g, ' ');
                if (planName === testPlan) {
                    el.classList.remove('hidden');
                    checklistVisible = true;
                } else {
                    el.classList.add('hidden');
                }
            });
            placeholder.style.display = checklistVisible ? 'none' : 'block';
        }
        
        function calculateWorkingDays(startDate, daysToAdd) {
            let currentDate = new Date(startDate);
            let addedDays = 0;
            while (addedDays < daysToAdd) {
                currentDate.setDate(currentDate.getDate() + 1);
                if (currentDate.getDay() !== 0 && currentDate.getDay() !== 6) {
                    addedDays++;
                }
            }
            return currentDate.toISOString().slice(0, 10);
        }

        function setDefaultDates() {
            const requestDateInput = document.getElementById('request_date');
            const deadlineInput = document.getElementById('deadline');
            const signOffDateInput = document.getElementById('sign_off_date');
            const today = new Date();
            const todayString = today.toISOString().slice(0, 10);

            requestDateInput.value = todayString;
            const futureDate = calculateWorkingDays(todayString, 7);
            deadlineInput.value = futureDate;
            signOffDateInput.value = futureDate;
        }

        document.addEventListener('DOMContentLoaded', function () {
            setupQuill('');
            updateChecklistVisibility();

            const profileMenu = document.getElementById('profile-menu');
            if (profileMenu) {
                const profileButton = profileMenu.querySelector('button');
                const profileDropdown = document.getElementById('profile-dropdown');
                profileButton.addEventListener('click', e => { e.stopPropagation(); profileDropdown.classList.toggle('hidden'); });
                document.addEventListener('click', e => { if (!profileMenu.contains(e.target)) { profileDropdown.classList.add('hidden'); } });
            }
        });
    </script>
</body>
</html>