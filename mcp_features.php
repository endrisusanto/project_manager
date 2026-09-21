<?php
require_once "session.php";
require_once "config.php";

$active_page = 'mcp_features';
?>
<!DOCTYPE html>
<html lang="id" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Model Context Protocol (MCP) Hub | Samsung GBA Project Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap"
        rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace']
                    },
                    colors: {
                        mcp: {
                            50: '#f5f3ff',
                            500: '#8b5cf6',
                            600: '#7c3aed',
                            700: '#6d28d9',
                            900: '#4c1d95'
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .glass-card {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        html.light .glass-card {
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid rgba(0, 0, 0, 0.08);
        }

        .glow-effect {
            box-shadow: 0 0 40px -10px rgba(124, 58, 237, 0.35);
        }

        .gradient-text {
            background: linear-gradient(135deg, #a78bfa 0%, #60a5fa 50%, #34d399 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
    </style>
</head>

<body class="bg-[#0f172a] text-slate-100 min-h-screen font-sans transition-colors duration-200">

    <?php include 'header.php'; ?>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-10">

        <!-- Hero Section -->
        <div
            class="relative overflow-hidden rounded-3xl glass-card glow-effect p-8 sm:p-12 border border-purple-500/20">
            <div
                class="absolute -right-20 -top-20 w-96 h-96 bg-purple-600/20 rounded-full blur-3xl pointer-events-none">
            </div>
            <div
                class="absolute -left-20 -bottom-20 w-96 h-96 bg-blue-600/20 rounded-full blur-3xl pointer-events-none">
            </div>

            <div class="relative z-10 max-w-3xl space-y-6">
                <div
                    class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-purple-500/10 border border-purple-500/30 text-purple-300 text-xs font-semibold tracking-wide">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    Official Model Context Protocol Server (Port 3800)
                </div>

                <h1 class="text-3xl sm:text-5xl font-extrabold tracking-tight">
                    Hub Integrasi AI & <span class="gradient-text">Model Context Protocol</span>
                </h1>

                <p class="text-slate-300 text-base sm:text-lg leading-relaxed">
                    Hubungkan seluruh data proyek, tugas model Samsung GBA, kalender, dan otomatisasi n8n langsung ke
                    ekosistem AI modern seperti <strong>ChatGPT Desktop</strong>, <strong>Claude Desktop</strong>, dan
                    <strong>Google Gemini</strong>.
                </p>

                <!-- Quick Action Links -->
                <div class="flex flex-wrap gap-3 pt-2">
                    <a href="#quick-config"
                        class="px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-500 text-white font-semibold text-sm shadow-lg shadow-purple-600/25 transition flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        Panduan Setup Klien AI
                    </a>
                    <a href="#live-tester"
                        class="px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 font-semibold text-sm transition flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Uji Coba Endpoint Server
                    </a>
                    <a href="project_roadmap.php"
                        class="px-5 py-2.5 rounded-xl bg-slate-800/60 hover:bg-slate-700/60 text-slate-300 border border-slate-700/60 font-semibold text-sm transition flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        3D Coffee Shop Simulation
                    </a>
                </div>
            </div>
        </div>

        <!-- Live Status & Endpoints Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Endpoint 1: Streamable HTTP /mcp -->
            <div class="glass-card rounded-2xl p-6 relative overflow-hidden border border-purple-500/20">
                <div class="flex items-center justify-between mb-4">
                    <span
                        class="px-2.5 py-1 rounded-md bg-purple-500/20 text-purple-300 text-xs font-mono font-bold">STREAMABLE
                        HTTP</span>
                    <span class="inline-flex items-center gap-1.5 text-xs text-emerald-400 font-medium">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span> Live SSE
                    </span>
                </div>
                <h3 class="text-lg font-bold text-white mb-1">MCP Stream Endpoint</h3>
                <p class="text-xs text-slate-400 mb-4">Protokol Server-Sent Events untuk ChatGPT Desktop & Claude
                    Desktop.</p>
                <div
                    class="flex items-center justify-between p-3 rounded-xl bg-slate-900/80 border border-slate-700/60 font-mono text-xs text-purple-300 select-all">
                    <span class="truncate">http://localhost:3800/mcp</span>
                    <button onclick="copyToClipboard('http://localhost:3800/mcp')"
                        class="ml-2 text-slate-400 hover:text-white transition p-1" title="Copy URL">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                    </button>
                </div>
                <p class="text-[11px] text-slate-400 mt-2">Alias alternatif: <code
                        class="text-slate-300 font-mono">/sse</code></p>
            </div>

            <!-- Endpoint 2: Discovery /api/mcp/tools -->
            <div class="glass-card rounded-2xl p-6 relative overflow-hidden border border-blue-500/20">
                <div class="flex items-center justify-between mb-4">
                    <span class="px-2.5 py-1 rounded-md bg-blue-500/20 text-blue-300 text-xs font-mono font-bold">TOOLS
                        DISCOVERY</span>
                    <span class="text-xs text-blue-400 font-medium">JSON REST</span>
                </div>
                <h3 class="text-lg font-bold text-white mb-1">Tools Schema Definition</h3>
                <p class="text-xs text-slate-400 mb-4">Daftar fungsi, skema parameter, dan deskripsi tool database.</p>
                <div
                    class="flex items-center justify-between p-3 rounded-xl bg-slate-900/80 border border-slate-700/60 font-mono text-xs text-blue-300 select-all">
                    <span class="truncate">http://localhost:3800/api/mcp/tools</span>
                    <button onclick="copyToClipboard('http://localhost:3800/api/mcp/tools')"
                        class="ml-2 text-slate-400 hover:text-white transition p-1" title="Copy URL">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                    </button>
                </div>
                <p class="text-[11px] text-slate-400 mt-2">Bisa dibuka langsung di browser (GET)</p>
            </div>

            <!-- Endpoint 3: AI Chat Bridge -->
            <div class="glass-card rounded-2xl p-6 relative overflow-hidden border border-emerald-500/20">
                <div class="flex items-center justify-between mb-4">
                    <span
                        class="px-2.5 py-1 rounded-md bg-emerald-500/20 text-emerald-300 text-xs font-mono font-bold">RAG
                        & CHAT BRIDGE</span>
                    <span class="text-xs text-emerald-400 font-medium">LM Studio & n8n</span>
                </div>
                <h3 class="text-lg font-bold text-white mb-1">Contextual Chat API</h3>
                <p class="text-xs text-slate-400 mb-4">Endpoint inferensi AI yang otomatis diinjeksi konteks MySQL.</p>
                <div
                    class="flex items-center justify-between p-3 rounded-xl bg-slate-900/80 border border-slate-700/60 font-mono text-xs text-emerald-300 select-all">
                    <span class="truncate">http://localhost:3800/api/mcp/chat</span>
                    <button onclick="copyToClipboard('http://localhost:3800/api/mcp/chat')"
                        class="ml-2 text-slate-400 hover:text-white transition p-1" title="Copy URL">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                    </button>
                </div>
                <p class="text-[11px] text-slate-400 mt-2">Format POST JSON: <code
                        class="text-slate-300 font-mono">{"prompt": "..."}</code></p>
            </div>
        </div>

        <!-- Core Capabilities Showcase -->
        <div class="space-y-6">
            <div class="text-center max-w-2xl mx-auto space-y-2">
                <h2 class="text-2xl sm:text-3xl font-bold text-white tracking-tight">Kapabilitas & MCP Tools Tersedia
                </h2>
                <p class="text-slate-400 text-sm">Setiap tool dilengkapi schema validasi JSON-RPC 2.0 yang otomatis
                    dieksekusi oleh AI.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Tool 1: db_list_tasks -->
                <div
                    class="glass-card rounded-2xl p-6 border border-slate-700/60 hover:border-purple-500/50 transition">
                    <div
                        class="w-10 h-10 rounded-xl bg-purple-500/10 text-purple-400 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-white mb-1 font-mono">db_list_tasks</h3>
                    <p class="text-xs text-slate-300 mb-3">Membaca daftar task GBA, filter status progress (Pending, In
                        Progress, Done), filter model Samsung, dan limit baris.</p>
                    <div class="bg-slate-900/60 rounded-lg p-2.5 text-[11px] font-mono text-slate-400">
                        Input: <span class="text-purple-300">{ progress_status?, model_name?, limit? }</span>
                    </div>
                </div>

                <!-- Tool 2: db_list_projects -->
                <div class="glass-card rounded-2xl p-6 border border-slate-700/60 hover:border-blue-500/50 transition">
                    <div
                        class="w-10 h-10 rounded-xl bg-blue-500/10 text-blue-400 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-white mb-1 font-mono">db_list_projects</h3>
                    <p class="text-xs text-slate-300 mb-3">Mengambil data project manager secara umum beserta status
                        milestone, PIC, dan deadline.</p>
                    <div class="bg-slate-900/60 rounded-lg p-2.5 text-[11px] font-mono text-slate-400">
                        Input: <span class="text-blue-300">{ status?, limit? }</span>
                    </div>
                </div>

                <!-- Tool 3: db_create_task -->
                <div
                    class="glass-card rounded-2xl p-6 border border-slate-700/60 hover:border-emerald-500/50 transition">
                    <div
                        class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-white mb-1 font-mono">db_create_task</h3>
                    <p class="text-xs text-slate-300 mb-3">Menambahkan task baru langsung ke tabel <code
                            class="text-emerald-300">gba_tasks</code> dengan validasi AP, CP, CSC, dan PIC Email.</p>
                    <div class="bg-slate-900/60 rounded-lg p-2.5 text-[11px] font-mono text-slate-400">
                        Input: <span class="text-emerald-300">{ model_name, pic_email, test_plan_type, ... }</span>
                    </div>
                </div>

                <!-- Tool 4: trigger_n8n_webhook -->
                <div class="glass-card rounded-2xl p-6 border border-slate-700/60 hover:border-amber-500/50 transition">
                    <div
                        class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-white mb-1 font-mono">trigger_n8n_webhook</h3>
                    <p class="text-xs text-slate-300 mb-3">Memicu workflow otomatisasi n8n seperti pengiriman email
                        notifikasi tugas baru dan generate laporan SMTP.</p>
                    <div class="bg-slate-900/60 rounded-lg p-2.5 text-[11px] font-mono text-slate-400">
                        Input: <span class="text-amber-300">{ path_or_url, payload }</span>
                    </div>
                </div>

                <!-- Tool 5: db_delete_task -->
                <div class="glass-card rounded-2xl p-6 border border-slate-700/60 hover:border-rose-500/50 transition">
                    <div
                        class="w-10 h-10 rounded-xl bg-rose-500/10 text-rose-400 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-white mb-1 font-mono">db_delete_task</h3>
                    <p class="text-xs text-slate-300 mb-3">Menghapus task dengan proteksi Role-Based Access Control
                        (RBAC) dan konfirmasi keamanan.</p>
                    <div class="bg-slate-900/60 rounded-lg p-2.5 text-[11px] font-mono text-slate-400">
                        Input: <span class="text-rose-300">{ id?, model_name?, test_plan_type? }</span>
                    </div>
                </div>

                <!-- Tool 6: send_email_smtp -->
                <div class="glass-card rounded-2xl p-6 border border-slate-700/60 hover:border-cyan-500/50 transition">
                    <div
                        class="w-10 h-10 rounded-xl bg-cyan-500/10 text-cyan-400 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-white mb-1 font-mono">send_email_smtp</h3>
                    <p class="text-xs text-slate-300 mb-3">Kirim email notifikasi, alert, atau laporan berkala dengan
                        format HTML rapi via SMTP.</p>
                    <div class="bg-slate-900/60 rounded-lg p-2.5 text-[11px] font-mono text-slate-400">
                        Input: <span class="text-cyan-300">{ to, subject, html, cc? }</span>
                    </div>
                </div>

                <!-- Tool 7: Dual Transport -->
                <div
                    class="glass-card rounded-2xl p-6 border border-slate-700/60 hover:border-indigo-500/50 transition">
                    <div
                        class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-400 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-white mb-1 font-mono">Dual-Mode Transport</h3>
                    <p class="text-xs text-slate-300 mb-3">Berjalan simultan via <strong>Stdio</strong> (IDE) dan
                        <strong>Streamable HTTP / SSE</strong> (Web/Desktop) tanpa bentrok socket port.</p>
                    <div class="bg-slate-900/60 rounded-lg p-2.5 text-[11px] font-mono text-slate-400">
                        Transports: <span class="text-indigo-300">Stdio + HTTP SSE Bridge</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Live Tester Section -->
        <div id="live-tester" class="glass-card rounded-3xl p-8 border border-slate-700/60 space-y-6">
            <div
                class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-700/60 pb-5">
                <div>
                    <h2 class="text-xl font-bold text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        Live Endpoint Diagnostic Tester
                    </h2>
                    <p class="text-xs text-slate-400">Uji langsung status koneksi dan respon JSON dari MCP Server port
                        3800.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="testEndpoint('status')"
                        class="px-3.5 py-2 rounded-xl bg-purple-600/20 hover:bg-purple-600/30 text-purple-300 border border-purple-500/30 text-xs font-semibold transition">
                        Cek Status (GET /)
                    </button>
                    <button onclick="testEndpoint('tools')"
                        class="px-3.5 py-2 rounded-xl bg-blue-600/20 hover:bg-blue-600/30 text-blue-300 border border-blue-500/30 text-xs font-semibold transition">
                        Cek Tools (GET /tools)
                    </button>
                </div>
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between text-xs text-slate-400">
                    <span>Output Respon Server:</span>
                    <span id="test-latency" class="font-mono text-emerald-400">Siap diuji</span>
                </div>
                <pre id="test-output"
                    class="p-4 rounded-2xl bg-slate-950 border border-slate-800 text-xs font-mono text-emerald-300 overflow-x-auto max-h-64">// Klik salah satu tombol di atas untuk menguji koneksi real-time ke MCP Server port 3800...</pre>
            </div>
        </div>

        <!-- Quick Config Tabs -->
        <div id="quick-config" class="glass-card rounded-3xl p-8 border border-slate-700/60 space-y-6">
            <div class="text-center max-w-2xl mx-auto space-y-2">
                <h2 class="text-2xl font-bold text-white tracking-tight">Panduan Setup Klien AI</h2>
                <p class="text-slate-400 text-sm">Pilih klien AI yang Anda gunakan dan salin konfigurasinya.</p>
            </div>

            <!-- Tab Buttons -->
            <div class="flex flex-wrap justify-center gap-2 border-b border-slate-700/60 pb-4">
                <button onclick="switchTab('chatgpt')" id="tab-btn-chatgpt"
                    class="px-4 py-2 rounded-xl font-semibold text-xs transition bg-purple-600 text-white">ChatGPT
                    Desktop</button>
                <button onclick="switchTab('claude')" id="tab-btn-claude"
                    class="px-4 py-2 rounded-xl font-semibold text-xs transition bg-slate-800 text-slate-400 hover:text-white">Claude
                    Desktop</button>
                <button onclick="switchTab('gemini')" id="tab-btn-gemini"
                    class="px-4 py-2 rounded-xl font-semibold text-xs transition bg-slate-800 text-slate-400 hover:text-white">Google
                    Gemini / IDE</button>
                <button onclick="switchTab('openwebui')" id="tab-btn-openwebui"
                    class="px-4 py-2 rounded-xl font-semibold text-xs transition bg-slate-800 text-slate-400 hover:text-white">Open
                    WebUI</button>
            </div>

            <!-- Tab 1: ChatGPT Desktop -->
            <div id="tab-chatgpt" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="p-4 rounded-2xl bg-slate-900/80 border border-slate-800 space-y-2">
                        <span class="text-xs font-semibold text-purple-300">Form URL / Endpoint:</span>
                        <div
                            class="font-mono text-xs text-white bg-slate-950 p-2.5 rounded-lg select-all flex justify-between items-center">
                            <span>http://localhost:3800/mcp</span>
                            <button onclick="copyToClipboard('http://localhost:3800/mcp')"
                                class="text-slate-400 hover:text-white text-xs">Copy</button>
                        </div>
                    </div>
                    <div class="p-4 rounded-2xl bg-slate-900/80 border border-slate-800 space-y-2">
                        <span class="text-xs font-semibold text-purple-300">Authentication / Bearer:</span>
                        <div class="font-mono text-xs text-emerald-400 bg-slate-950 p-2.5 rounded-lg">
                            None / Kosongkan (Bebas Token)
                        </div>
                    </div>
                </div>
                <p class="text-xs text-slate-400 leading-relaxed">
                    👉 Pada menu <strong>Settings > Connected Apps / Add MCP Server</strong> di ChatGPT Desktop,
                    masukkan URL <code class="text-purple-300 font-mono">http://localhost:3800/mcp</code> dan biarkan
                    seluruh kolom autentikasi kosong.
                </p>
            </div>

            <!-- Tab 2: Claude Desktop -->
            <div id="tab-claude" class="space-y-4 hidden">
                <p class="text-xs text-slate-400">
                    Tambahkan konfigurasi berikut ke file <code
                        class="text-purple-300 font-mono">%APPDATA%\Claude\claude_desktop_config.json</code>:
                </p>
                <div class="relative">
                    <pre
                        class="p-4 rounded-2xl bg-slate-950 border border-slate-800 text-xs font-mono text-slate-200 overflow-x-auto">{
  "mcpServers": {
    "project-manager": {
      "url": "http://localhost:3800/sse"
    }
  }
}</pre>
                    <button
                        onclick="copyToClipboard('{\n  &quot;mcpServers&quot;: {\n    &quot;project-manager&quot;: {\n      &quot;url&quot;: &quot;http://localhost:3800/sse&quot;\n    }\n  }\n}')"
                        class="absolute top-3 right-3 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs text-slate-300 transition">Copy
                        JSON</button>
                </div>
            </div>

            <!-- Tab 3: Gemini / Antigravity IDE -->
            <div id="tab-gemini" class="space-y-4 hidden">
                <p class="text-xs text-slate-400">
                    Tambahkan konfigurasi Stdio transport berikut ke file <code
                        class="text-purple-300 font-mono">~/.gemini/config/mcp_config.json</code>:
                </p>
                <div class="relative">
                    <pre
                        class="p-4 rounded-2xl bg-slate-950 border border-slate-800 text-xs font-mono text-slate-200 overflow-x-auto">{
  "mcpServers": {
    "project-manager-mcp": {
      "command": "node",
      "args": ["/home/endri-pro/dev/App/project_manager/mcp-server/index.js"],
      "env": {
        "DB_HOST": "127.0.0.1",
        "DB_PORT": "3306",
        "DB_USER": "root",
        "DB_NAME": "project_manager_db"
      }
    }
  }
}</pre>
                    <button
                        onclick="copyToClipboard('{\n  &quot;mcpServers&quot;: {\n    &quot;project-manager-mcp&quot;: {\n      &quot;command&quot;: &quot;node&quot;,\n      &quot;args&quot;: [&quot;/home/endri-pro/dev/App/project_manager/mcp-server/index.js&quot;],\n      &quot;env&quot;: {\n        &quot;DB_HOST&quot;: &quot;127.0.0.1&quot;,\n        &quot;DB_PORT&quot;: &quot;3306&quot;,\n        &quot;DB_USER&quot;: &quot;root&quot;,\n        &quot;DB_NAME&quot;: &quot;project_manager_db&quot;\n      }\n    }\n  }\n}')"
                        class="absolute top-3 right-3 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs text-slate-300 transition">Copy
                        JSON</button>
                </div>
            </div>

            <!-- Tab 4: Open WebUI -->
            <div id="tab-openwebui" class="space-y-4 hidden">
                <p class="text-xs text-slate-400">
                    Pada Open WebUI (Tools / Functions), gunakan REST Bridge AI chat endpoint:
                </p>
                <div class="p-4 rounded-2xl bg-slate-900/80 border border-slate-800 space-y-2">
                    <span class="text-xs font-semibold text-emerald-300">Chat & Reasoning Endpoint:</span>
                    <div class="font-mono text-xs text-white bg-slate-950 p-2.5 rounded-lg select-all">
                        http://localhost:3800/api/mcp/chat
                    </div>
                </div>
            </div>
        </div>

    </main>

    <script>
        // Copy to clipboard helper
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('Teks berhasil disalin ke clipboard!');
            }).catch(err => {
                console.error('Gagal menyalin:', err);
            });
        }

        // Tab switcher
        function switchTab(tab) {
            ['chatgpt', 'claude', 'gemini', 'openwebui'].forEach(t => {
                const el = document.getElementById(`tab-${t}`);
                const btn = document.getElementById(`tab-btn-${t}`);
                if (t === tab) {
                    el.classList.remove('hidden');
                    btn.className = 'px-4 py-2 rounded-xl font-semibold text-xs transition bg-purple-600 text-white';
                } else {
                    el.classList.add('hidden');
                    btn.className = 'px-4 py-2 rounded-xl font-semibold text-xs transition bg-slate-800 text-slate-400 hover:text-white';
                }
            });
        }

        // Live endpoint tester
        async function testEndpoint(type) {
            const out = document.getElementById('test-output');
            const lat = document.getElementById('test-latency');
            const targetUrl = type === 'tools' ? 'http://localhost:3800/api/mcp/tools' : 'http://localhost:3800/';

            out.textContent = `Mengirim permintaan GET ke ${targetUrl}...`;
            lat.textContent = 'Menghubungkan...';

            const startTime = performance.now();
            try {
                const res = await fetch(targetUrl);
                const endTime = performance.now();
                const latency = Math.round(endTime - startTime);
                const json = await res.json();

                lat.textContent = `200 OK (${latency}ms)`;
                out.textContent = JSON.stringify(json, null, 2);
            } catch (err) {
                lat.textContent = 'Gagal';
                out.textContent = `Error: ${err.message}\nPastikan MCP Server (node index.js atau container Docker) sudah aktif di port 3800.`;
            }
        }
    </script>

</body>

</html>