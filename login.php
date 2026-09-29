<?php
session_start();
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: gba_tasks.php");
    exit;
}

// MODIFIKASI: Cek cookie untuk "Ingat Saya"
$username_cookie = $_COOKIE['username'] ?? '';
$password_cookie = $_COOKIE['password'] ?? '';
$remember_cookie = isset($_COOKIE['username']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background-color: #020617; }
        #neural-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; }
        .form-container { background: rgba(15, 23, 42, 0.8); backdrop-filter: blur(12px); border: 1px solid rgba(51, 65, 85, 0.6); }
    </style>
</head>
<body class="text-white flex items-center justify-center h-screen">
    <canvas id="neural-canvas"></canvas>
    <div class="w-full max-w-md p-8 space-y-6 form-container rounded-2xl shadow-lg">
        <h2 class="text-3xl font-bold text-center text-white">Login</h2>

        <?php if(isset($_GET['error'])): ?>
            <div class="bg-red-500/20 text-red-300 text-sm p-4 rounded-lg text-center">
                <?= htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>
        <?php if(isset($_GET['success'])): ?>
            <div class="bg-green-500/20 text-green-300 text-sm p-4 rounded-lg text-center">
                <?= htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>

        <form action="auth_handler.php" method="post">
            <input type="hidden" name="action" value="login">
            <div class="space-y-4">
                <div>
                    <label for="username" class="block mb-2 text-sm font-medium text-gray-300">Username</label>
                    <input type="text" name="username" id="username" class="w-full p-2.5 bg-gray-700 border border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500" value="<?= htmlspecialchars($username_cookie) ?>" required>
                </div>
                <div>
                    <label for="password" class="block mb-2 text-sm font-medium text-gray-300">Password</label>
                    <input type="password" name="password" id="password" class="w-full p-2.5 bg-gray-700 border border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500" value="<?= htmlspecialchars($password_cookie) ?>" required>
                </div>
            </div>
            
            <div class="flex items-center justify-between mt-6">
                <div class="flex items-center">
                    <input id="remember" name="remember" type="checkbox" class="h-4 w-4 text-blue-600 bg-gray-700 border-gray-600 rounded focus:ring-blue-500" <?= $remember_cookie ? 'checked' : '' ?>>
                    <label for="remember" class="ml-2 block text-sm text-gray-400">Ingat Saya</label>
                </div>
            </div>

            <button type="submit" class="w-full px-5 py-3 mt-4 text-sm font-medium text-center text-white bg-blue-600 rounded-lg hover:bg-blue-700 focus:ring-4 focus:outline-none focus:ring-blue-800 transition-transform transform hover:scale-105">Login</button>
            <p class="text-sm text-center mt-4 text-gray-400">Belum punya akun? <a href="register.php" class="font-medium text-blue-500 hover:underline">Daftar di sini</a></p>
        </form>
    </div>
<script>
    const canvas = document.getElementById('neural-canvas'), ctx = canvas.getContext('2d');
    let particles = [], hue = 210, lastTime = 0;
    function setCanvasSize(){canvas.width=window.innerWidth;canvas.height=window.innerHeight;}setCanvasSize();
    
    class Particle{
        constructor(x,y){
            this.x=x||Math.random()*canvas.width;
            this.y=y||Math.random()*canvas.height;
            this.vx=(Math.random()-.5)*.3;
            this.vy=(Math.random()-.5)*.3;
            this.size=Math.random()*1.8 + 1.2;
        }
        update(){
            this.x+=this.vx;this.y+=this.vy;
            if(this.x<0||this.x>canvas.width)this.vx*=-1;
            if(this.y<0||this.y>canvas.height)this.vy*=-1;
        }
        draw(){
            ctx.fillStyle=`hsl(${hue},100%,75%)`;
            ctx.beginPath();
            ctx.arc(this.x,this.y,this.size,0,Math.PI*2);
            ctx.fill();
        }
    }

    function init(num){
        particles = [];
        for(let i=0;i<num;i++)particles.push(new Particle())
    }

    function handleParticles() {
        for(let i = 0; i < particles.length; i++) {
            particles[i].update();
            particles[i].draw();
            for (let j = i + 1; j < particles.length; j++) {
                const dx = particles[i].x - particles[j].x;
                const dy = particles[i].y - particles[j].y;
                const distSq = dx * dx + dy * dy;
                if (distSq < 14400) { // 120^2 (eliminates Math.sqrt)
                    ctx.beginPath();
                    ctx.strokeStyle = `hsla(${hue}, 100%, 80%, 0.15)`; 
                    ctx.lineWidth = 1;
                    ctx.moveTo(particles[i].x, particles[i].y);
                    ctx.lineTo(particles[j].x, particles[j].y);
                    ctx.stroke();
                }
            }
        }
    }

    function animate(timestamp){
        if (!document.hidden && timestamp - lastTime >= 50) { // Throttled to ~20 FPS (saves 70% GPU)
            lastTime = timestamp;
            ctx.clearRect(0,0,canvas.width,canvas.height);
            hue = (hue + 0.2) % 360; 
            handleParticles();
        }
        requestAnimationFrame(animate);
    }
    
    const particleCount = window.innerWidth > 768 ? 25 : 14;
    init(particleCount);
    requestAnimationFrame(animate);
    window.addEventListener('resize',()=>{setCanvasSize();init(particleCount);}, {passive:true});
</script>
</body>
</html>