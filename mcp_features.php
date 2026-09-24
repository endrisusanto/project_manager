<?php
require_once "session.php";
require_once "config.php";

$active_page = 'mcp_features';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <script>if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Model Context Protocol (MCP) Hub | Samsung GBA Project Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['class', '.never-match-dark']
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --bg-primary: #020617;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --card-bg: rgba(15, 23, 42, 0.75);
            --card-border: rgba(51, 65, 85, 0.65);
            --sub-card-bg: rgba(30, 41, 59, 0.5);
            --sub-card-border: rgba(51, 65, 85, 0.6);
        }
        html.light {
            --bg-primary: #f8fafc;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --sub-card-bg: #f8fafc;
            --sub-card-border: #e2e8f0;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            transition: background-color 0.18s ease, color 0.18s ease;
        }

        .glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--card-border);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }
        html.light .glass-card {
            background-color: #ffffff;
            border-color: #e2e8f0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
        }

        .sub-box {
            background-color: var(--sub-card-bg);
            border: 1px solid var(--sub-card-border);
            border-radius: 0.75rem;
        }
        html.light .sub-box {
            background-color: #f8fafc;
            border-color: #e2e8f0;
        }

        .glow-effect {
            box-shadow: 0 0 40px -10px rgba(124, 58, 237, 0.22);
        }
        html.light .glow-effect {
            box-shadow: 0 10px 30px -10px rgba(124, 58, 237, 0.1);
        }

        .gradient-text {
            background: linear-gradient(135deg, #a78bfa 0%, #38bdf8 50%, #34d399 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        html.light .gradient-text {
            background: linear-gradient(135deg, #6d28d9 0%, #1d4ed8 50%, #047857 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Toast feedback */
        #copy-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            transform: translateY(20px);
            opacity: 0;
            pointer-events: none;
            transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
        }
        #copy-toast.show {
            transform: translateY(0);
            opacity: 1;
            pointer-events: auto;
        }
    </style>
</head>

<body class="min-h-screen flex flex-col">

    <?php include 'header.php'; ?>

    <main class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-8 flex-grow">

        <!-- Hero Section -->
        <div class="relative overflow-hidden rounded-3xl glass-card glow-effect p-6 sm:p-10 lg:p-12 border border-purple-500/25">
            <div class="absolute -right-20 -top-20 w-96 h-96 bg-purple-600/10 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute -left-20 -bottom-20 w-96 h-96 bg-blue-600/10 rounded-full blur-3xl pointer-events-none"></div>

            <div class="relative z-10 max-w-3xl space-y-5">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-purple-100 border border-purple-300 text-purple-900 text-xs font-bold tracking-wide">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    Official Model Context Protocol Server (Port 3800 • 107.102.39.55)
                </div>

                <h1 class="text-3xl sm:text-5xl font-black tracking-tight" style="color: var(--text-primary);">
                    Hub Integrasi AI & <span class="gradient-text">Model Context Protocol</span>
                </h1>

                <p class="text-sm sm:text-base lg:text-lg leading-relaxed font-medium" style="color: var(--text-secondary);">
                    Hubungkan seluruh data proyek, tugas model Samsung GBA, kalender, dan otomatisasi n8n langsung ke
                    ekosistem AI modern seperti <strong class="font-black" style="color: var(--text-primary);">ChatGPT Desktop</strong>, <strong class="font-black" style="color: var(--text-primary);">Claude Desktop</strong>, dan
                    <strong class="font-black" style="color: var(--text-primary);">Google Gemini</strong>.
                </p>

                <!-- Quick Action Links -->
                <div class="flex flex-wrap gap-3 pt-2">
                    <a href="#quick-config"
                        class="px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-500 text-white font-bold text-xs sm:text-sm shadow-md shadow-purple-600/25 transition flex items-center gap-2 active:scale-95">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        Panduan Setup Klien AI
                    </a>
                    <a href="#live-tester"
                        class="px-5 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 border border-slate-300 text-slate-800 font-bold text-xs sm:text-sm transition flex items-center gap-2 active:scale-95">
                        <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Uji Coba Endpoint Server
                    </a>
                    <a href="project_roadmap.php"
                        class="px-5 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 font-semibold text-xs sm:text-sm transition flex items-center gap-2 active:scale-95">
                        <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        3D Coffee Shop Simulation
                    </a>
                </div>
            </div>
        </div>

        <!-- Live Status & Endpoints Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            
            <!-- Endpoint 1: Streamable HTTP /mcp -->
            <div class="glass-card rounded-2xl p-6 relative overflow-hidden border border-purple-200 flex flex-col justify-between space-y-4">
                <div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="px-2.5 py-1 rounded-md bg-purple-100 border border-purple-300 text-purple-900 text-xs font-mono font-extrabold">STREAMABLE HTTP</span>
                        <span class="inline-flex items-center gap-1.5 text-xs text-emerald-700 font-bold">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span> Live SSE
                        </span>
                    </div>
                    <h3 class="text-base font-bold mb-1" style="color: var(--text-primary);">MCP Stream Endpoint</h3>
                    <p class="text-xs font-medium" style="color: var(--text-secondary);">Protokol Server-Sent Events untuk ChatGPT Desktop & Claude Desktop.</p>
                </div>
                <div>
                    <div class="flex items-center justify-between p-3 rounded-xl bg-slate-900 border border-slate-800 font-mono text-xs text-purple-300 select-all">
                        <span class="truncate font-semibold">http://107.102.39.55:3800/mcp</span>
                        <button onclick="copyToClipboard('http://107.102.39.55:3800/mcp')" class="ml-2 text-slate-400 hover:text-white transition p-1" title="Copy URL">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        </button>
                    </div>
                    <p class="text-[11px] mt-2 font-medium" style="color: var(--text-secondary);">Alias alternatif: <code class="text-purple-800 font-mono font-bold bg-purple-50 px-1 py-0.5 rounded border border-purple-200">/sse</code></p>
                </div>
            </div>

            <!-- Endpoint 2: Discovery /api/mcp/tools -->
            <div class="glass-card rounded-2xl p-6 relative overflow-hidden border border-blue-200 flex flex-col justify-between space-y-4">
                <div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="px-2.5 py-1 rounded-md bg-blue-100 border border-blue-300 text-blue-900 text-xs font-mono font-extrabold">TOOLS DISCOVERY</span>
                        <span class="text-xs text-blue-700 font-bold">JSON REST</span>
                    </div>
                    <h3 class="text-base font-bold mb-1" style="color: var(--text-primary);">Tools Schema Definition</h3>
                    <p class="text-xs font-medium" style="color: var(--text-secondary);">Daftar fungsi, skema parameter, dan deskripsi tool database.</p>
                </div>
                <div>
                    <div class="flex items-center justify-between p-3 rounded-xl bg-slate-900 border border-slate-800 font-mono text-xs text-blue-300 select-all">
                        <span class="truncate font-semibold">http://107.102.39.55:3800/api/mcp/tools</span>
                        <button onclick="copyToClipboard('http://107.102.39.55:3800/api/mcp/tools')" class="ml-2 text-slate-400 hover:text-white transition p-1" title="Copy URL">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        </button>
                    </div>
                    <p class="text-[11px] mt-2 font-medium" style="color: var(--text-secondary);">Bisa dibuka langsung di browser (GET)</p>
                </div>
            </div>

            <!-- Endpoint 3: AI Chat Bridge -->
            <div class="glass-card rounded-2xl p-6 relative overflow-hidden border border-emerald-200 flex flex-col justify-between space-y-4">
                <div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="px-2.5 py-1 rounded-md bg-emerald-100 border border-emerald-300 text-emerald-900 text-xs font-mono font-extrabold">RAG & CHAT BRIDGE</span>
                        <span class="text-xs text-emerald-700 font-bold">LM Studio & n8n</span>
                    </div>
                    <h3 class="text-base font-bold mb-1" style="color: var(--text-primary);">Contextual Chat API</h3>
                    <p class="text-xs font-medium" style="color: var(--text-secondary);">Endpoint inferensi AI yang otomatis diinjeksi konteks MySQL.</p>
                </div>
                <div>
                    <div class="flex items-center justify-between p-3 rounded-xl bg-slate-900 border border-slate-800 font-mono text-xs text-emerald-300 select-all">
                        <span class="truncate font-semibold">http://107.102.39.55:3800/api/mcp/chat</span>
                        <button onclick="copyToClipboard('http://107.102.39.55:3800/api/mcp/chat')" class="ml-2 text-slate-400 hover:text-white transition p-1" title="Copy URL">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        </button>
                    </div>
                    <p class="text-[11px] mt-2 font-medium" style="color: var(--text-secondary);">Format POST JSON: <code class="text-emerald-800 font-mono font-bold bg-emerald-50 px-1 py-0.5 rounded border border-emerald-200">{"prompt": "..."}</code></p>
                </div>
            </div>

        </div>

        <!-- Core Capabilities Showcase -->
        <div class="space-y-5">
            <div class="text-center max-w-2xl mx-auto space-y-1.5">
                <h2 class="text-2xl sm:text-3xl font-black tracking-tight" style="color: var(--text-primary);">Kapabilitas & MCP Tools Tersedia</h2>
                <p class="text-xs sm:text-sm font-medium" style="color: var(--text-secondary);">Setiap tool dilengkapi schema validasi JSON-RPC 2.0 yang otomatis dieksekusi oleh AI.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                
                <!-- Tool 1: db_list_tasks -->
                <div class="glass-card rounded-2xl p-5 border border-slate-200 hover:border-purple-500/50 transition flex flex-col justify-between">
                    <div>
                        <div class="w-10 h-10 rounded-xl bg-purple-100 border border-purple-300 text-purple-700 flex items-center justify-center mb-3">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                        </div>
                        <h3 class="text-base font-bold mb-1 font-mono" style="color: var(--text-primary);">db_list_tasks</h3>
                        <p class="text-xs mb-3 leading-relaxed font-medium" style="color: var(--text-secondary);">Membaca daftar task GBA, filter status progress (Pending, In Progress, Done), filter model Samsung, dan limit baris.</p>
                    </div>
                    <div class="sub-box p-2.5 text-[11px] font-mono" style="color: var(--text-secondary);">
                        Input: <span class="text-purple-800 font-bold">{ progress_status?, model_name?, limit? }</span>
                    </div>
                </div>

                <!-- Tool 2: db_list_projects -->
                <div class="glass-card rounded-2xl p-5 border border-slate-200 hover:border-blue-500/50 transition flex flex-col justify-between">
                    <div>
                        <div class="w-10 h-10 rounded-xl bg-blue-100 border border-blue-300 text-blue-700 flex items-center justify-center mb-3">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                        </div>
                        <h3 class="text-base font-bold mb-1 font-mono" style="color: var(--text-primary);">db_list_projects</h3>
                        <p class="text-xs mb-3 leading-relaxed font-medium" style="color: var(--text-secondary);">Mengambil data project manager secara umum beserta status milestone, PIC, dan deadline.</p>
                    </div>
                    <div class="sub-box p-2.5 text-[11px] font-mono" style="color: var(--text-secondary);">
                        Input: <span class="text-blue-800 font-bold">{ status?, limit? }</span>
                    </div>
                </div>

                <!-- Tool 3: db_create_task -->
                <div class="glass-card rounded-2xl p-5 border border-slate-200 hover:border-emerald-500/50 transition flex flex-col justify-between">
                    <div>
                        <div class="w-10 h-10 rounded-xl bg-emerald-100 border border-emerald-300 text-emerald-700 flex items-center justify-center mb-3">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        </div>
                        <h3 class="text-base font-bold mb-1 font-mono" style="color: var(--text-primary);">db_create_task</h3>
                        <p class="text-xs mb-3 leading-relaxed font-medium" style="color: var(--text-secondary);">Menambahkan task baru langsung ke tabel <code class="text-emerald-800 font-bold bg-emerald-50 px-1 py-0.5 rounded border border-emerald-200">gba_tasks</code> dengan validasi AP, CP, CSC, dan PIC Email.</p>
                    </div>
                    <div class="sub-box p-2.5 text-[11px] font-mono" style="color: var(--text-secondary);">
                        Input: <span class="text-emerald-800 font-bold">{ model_name, pic_email, test_plan_type, ... }</span>
                    </div>
                </div>

                <!-- Tool 4: trigger_n8n_webhook -->
                <div class="glass-card rounded-2xl p-5 border border-slate-200 hover:border-amber-500/50 transition flex flex-col justify-between">
                    <div>
                        <div class="w-10 h-10 rounded-xl bg-amber-100 border border-amber-300 text-amber-700 flex items-center justify-center mb-3">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </div>
                        <h3 class="text-base font-bold mb-1 font-mono" style="color: var(--text-primary);">trigger_n8n_webhook</h3>
                        <p class="text-xs mb-3 leading-relaxed font-medium" style="color: var(--text-secondary);">Memicu workflow otomatisasi n8n seperti pengiriman email notifikasi tugas baru dan generate laporan SMTP.</p>
                    </div>
                    <div class="sub-box p-2.5 text-[11px] font-mono" style="color: var(--text-secondary);">
                        Input: <span class="text-amber-800 font-bold">{ path_or_url, payload }</span>
                    </div>
                </div>

                <!-- Tool 5: db_delete_task -->
                <div class="glass-card rounded-2xl p-5 border border-slate-200 hover:border-rose-500/50 transition flex flex-col justify-between">
                    <div>
                        <div class="w-10 h-10 rounded-xl bg-rose-100 border border-rose-300 text-rose-700 flex items-center justify-center mb-3">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </div>
                        <h3 class="text-base font-bold mb-1 font-mono" style="color: var(--text-primary);">db_delete_task</h3>
                        <p class="text-xs mb-3 leading-relaxed font-medium" style="color: var(--text-secondary);">Menghapus task dengan proteksi Role-Based Access Control (RBAC) dan konfirmasi keamanan.</p>
                    </div>
                    <div class="sub-box p-2.5 text-[11px] font-mono" style="color: var(--text-secondary);">
                        Input: <span class="text-rose-800 font-bold">{ id?, model_name?, test_plan_type? }</span>
                    </div>
                </div>

                <!-- Tool 6: send_email_smtp -->
                <div class="glass-card rounded-2xl p-5 border border-slate-200 hover:border-cyan-500/50 transition flex flex-col justify-between">
                    <div>
                        <div class="w-10 h-10 rounded-xl bg-cyan-100 border border-cyan-300 text-cyan-700 flex items-center justify-center mb-3">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </div>
                        <h3 class="text-base font-bold mb-1 font-mono" style="color: var(--text-primary);">send_email_smtp</h3>
                        <p class="text-xs mb-3 leading-relaxed font-medium" style="color: var(--text-secondary);">Kirim email notifikasi, alert, atau laporan berkala dengan format HTML rapi via SMTP.</p>
                    </div>
                    <div class="sub-box p-2.5 text-[11px] font-mono" style="color: var(--text-secondary);">
                        Input: <span class="text-cyan-800 font-bold">{ to, subject, html, cc? }</span>
                    </div>
                </div>

            </div>
        </div>

        <!-- Live Tester Section -->
        <div id="live-tester" class="glass-card rounded-3xl p-6 sm:p-8 border border-slate-200 space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b pb-4" style="border-color: var(--card-border);">
                <div>
                    <h2 class="text-lg sm:text-xl font-black flex items-center gap-2" style="color: var(--text-primary);">
                        <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        Live Endpoint Diagnostic Tester
                    </h2>
                    <p class="text-xs mt-0.5 font-medium" style="color: var(--text-secondary);">Uji langsung status koneksi dan respon JSON dari MCP Server port 3800 di IP 107.102.39.55.</p>
                </div>
                <div class="flex items-center gap-2 self-start sm:self-auto">
                    <button onclick="testEndpoint('status')" class="px-3.5 py-2 rounded-xl bg-purple-100 hover:bg-purple-200 text-purple-900 border border-purple-300 text-xs font-bold transition active:scale-95">
                        Cek Status (GET /)
                    </button>
                    <button onclick="testEndpoint('tools')" class="px-3.5 py-2 rounded-xl bg-blue-100 hover:bg-blue-200 text-blue-900 border border-blue-300 text-xs font-bold transition active:scale-95">
                        Cek Tools (GET /tools)
                    </button>
                </div>
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between text-xs font-medium" style="color: var(--text-secondary);">
                    <span>Output Respon Server:</span>
                    <span id="test-latency" class="font-mono text-emerald-800 font-bold">Siap diuji</span>
                </div>
                <pre id="test-output" class="p-4 rounded-2xl bg-slate-900 border border-slate-800 text-xs font-mono text-emerald-300 overflow-x-auto max-h-64 font-semibold">// Klik salah satu tombol di atas untuk menguji koneksi real-time ke MCP Server port 3800...</pre>
            </div>
        </div>

        <!-- Quick Config Tabs -->
        <div id="quick-config" class="glass-card rounded-3xl p-6 sm:p-8 border border-slate-200 space-y-6">
            <div class="text-center max-w-2xl mx-auto space-y-1.5">
                <h2 class="text-xl sm:text-2xl font-black tracking-tight" style="color: var(--text-primary);">Panduan Setup Klien AI</h2>
                <p class="text-xs sm:text-sm font-medium" style="color: var(--text-secondary);">Pilih klien AI yang Anda gunakan dan salin konfigurasinya.</p>
            </div>

            <!-- Tab Buttons -->
            <div class="flex flex-wrap justify-center gap-2 border-b pb-4" style="border-color: var(--card-border);">
                <button onclick="switchTab('chatgpt')" id="tab-btn-chatgpt" class="px-4 py-2 rounded-xl font-bold text-xs transition bg-purple-600 text-white shadow-sm">
                    ChatGPT Desktop
                </button>
                <button onclick="switchTab('claude')" id="tab-btn-claude" class="px-4 py-2 rounded-xl font-bold text-xs transition bg-slate-100 text-slate-800 hover:text-slate-900 border border-slate-300">
                    Claude Desktop
                </button>
                <button onclick="switchTab('gemini')" id="tab-btn-gemini" class="px-4 py-2 rounded-xl font-bold text-xs transition bg-slate-100 text-slate-800 hover:text-slate-900 border border-slate-300">
                    Google Gemini / IDE
                </button>
                <button onclick="switchTab('openwebui')" id="tab-btn-openwebui" class="px-4 py-2 rounded-xl font-bold text-xs transition bg-slate-100 text-slate-800 hover:text-slate-900 border border-slate-300">
                    Open WebUI
                </button>
            </div>

            <!-- Tab 1: ChatGPT Desktop -->
            <div id="tab-chatgpt" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="p-4 rounded-2xl sub-box space-y-2">
                        <span class="text-xs font-bold text-purple-800">Form URL / Endpoint:</span>
                        <div class="font-mono text-xs text-purple-200 bg-slate-900 border border-slate-800 p-2.5 rounded-lg select-all flex justify-between items-center">
                            <span class="font-semibold">http://107.102.39.55:3800/mcp</span>
                            <button onclick="copyToClipboard('http://107.102.39.55:3800/mcp')" class="text-slate-300 hover:text-white text-xs font-bold px-2 py-0.5 rounded bg-white/15 hover:bg-white/25 transition">Copy</button>
                        </div>
                    </div>
                    <div class="p-4 rounded-2xl sub-box space-y-2">
                        <span class="text-xs font-bold text-purple-800">Authentication / Bearer:</span>
                        <div class="font-mono text-xs text-emerald-400 bg-slate-900 border border-slate-800 p-2.5 rounded-lg font-bold">
                            None / Kosongkan (Bebas Token)
                        </div>
                    </div>
                </div>
                <p class="text-xs leading-relaxed font-medium" style="color: var(--text-secondary);">
                    👉 Pada menu <strong class="font-bold" style="color: var(--text-primary);">Settings > Connected Apps / Add MCP Server</strong> di ChatGPT Desktop,
                    masukkan URL <code class="text-purple-800 font-mono font-bold bg-purple-50 px-1 py-0.5 rounded border border-purple-200">http://107.102.39.55:3800/mcp</code> dan biarkan seluruh kolom autentikasi kosong.
                </p>
            </div>

            <!-- Tab 2: Claude Desktop -->
            <div id="tab-claude" class="space-y-4 hidden">
                <p class="text-xs font-medium" style="color: var(--text-secondary);">
                    Tambahkan konfigurasi berikut ke file <code class="text-purple-800 font-mono font-bold bg-purple-50 px-1 py-0.5 rounded border border-purple-200">%APPDATA%\Claude\claude_desktop_config.json</code>:
                </p>
                <div class="relative">
                    <pre class="p-4 rounded-2xl bg-slate-900 border border-slate-800 text-xs font-mono text-slate-200 overflow-x-auto font-semibold">{
  "mcpServers": {
    "project-manager": {
      "url": "http://107.102.39.55:3800/sse"
    }
  }
}</pre>
                    <button onclick="copyToClipboard('{\n  &quot;mcpServers&quot;: {\n    &quot;project-manager&quot;: {\n      &quot;url&quot;: &quot;http://107.102.39.55:3800/sse&quot;\n    }\n  }\n}')"
                        class="absolute top-3 right-3 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs text-slate-100 transition font-bold">Copy JSON</button>
                </div>
            </div>

            <!-- Tab 3: Gemini / Antigravity IDE -->
            <div id="tab-gemini" class="space-y-4 hidden">
                <p class="text-xs font-medium" style="color: var(--text-secondary);">
                    Tambahkan konfigurasi Stdio transport berikut ke file <code class="text-purple-800 font-mono font-bold bg-purple-50 px-1 py-0.5 rounded border border-purple-200">~/.gemini/config/mcp_config.json</code>:
                </p>
                <div class="relative">
                    <pre class="p-4 rounded-2xl bg-slate-900 border border-slate-800 text-xs font-mono text-slate-200 overflow-x-auto font-semibold">{
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
                    <button onclick="copyToClipboard('{\n  &quot;mcpServers&quot;: {\n    &quot;project-manager-mcp&quot;: {\n      &quot;command&quot;: &quot;node&quot;,\n      &quot;args&quot;: [&quot;/home/endri-pro/dev/App/project_manager/mcp-server/index.js&quot;],\n      &quot;env&quot;: {\n        &quot;DB_HOST&quot;: &quot;127.0.0.1&quot;,\n        &quot;DB_PORT&quot;: &quot;3306&quot;,\n        &quot;DB_USER&quot;: &quot;root&quot;,\n        &quot;DB_NAME&quot;: &quot;project_manager_db&quot;\n      }\n    }\n  }\n}')"
                        class="absolute top-3 right-3 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs text-slate-100 transition font-bold">Copy JSON</button>
                </div>
            </div>

            <!-- Tab 4: Open WebUI -->
            <div id="tab-openwebui" class="space-y-4 hidden">
                <p class="text-xs font-medium" style="color: var(--text-secondary);">
                    Pada Open WebUI (Tools / Functions), gunakan REST Bridge AI chat endpoint:
                </p>
                <div class="p-4 rounded-2xl sub-box space-y-2">
                    <span class="text-xs font-bold text-emerald-800">Chat & Reasoning Endpoint:</span>
                    <div class="font-mono text-xs text-emerald-300 bg-slate-900 border border-slate-800 p-2.5 rounded-lg select-all font-semibold">
                        http://107.102.39.55:3800/api/mcp/chat
                    </div>
                </div>
            </div>

        </div>

    </main>

    <!-- Floating Toast Feedback -->
    <div id="copy-toast" class="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 border border-purple-500/40 text-purple-200 text-xs shadow-2xl">
        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        <span>Teks berhasil disalin ke clipboard!</span>
    </div>

    <script>
        // Non-intrusive Toast helper
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                showToast();
            }).catch(err => {
                console.error('Gagal menyalin:', err);
            });
        }

        function showToast() {
            const toast = document.getElementById('copy-toast');
            if (!toast) return;
            toast.classList.add('show');
            setTimeout(() => {
                toast.classList.remove('show');
            }, 2200);
        }

        // Tab switcher
        function switchTab(tab) {
            const tabs = ['chatgpt', 'claude', 'gemini', 'openwebui'];
            tabs.forEach(t => {
                const el = document.getElementById(`tab-${t}`);
                const btn = document.getElementById(`tab-btn-${t}`);
                if (t === tab) {
                    el.classList.remove('hidden');
                    btn.className = 'px-4 py-2 rounded-xl font-bold text-xs transition bg-purple-600 text-white shadow-sm';
                } else {
                    el.classList.add('hidden');
                    btn.className = 'px-4 py-2 rounded-xl font-bold text-xs transition bg-slate-100 text-slate-800 hover:text-slate-900 border border-slate-300';
                }
            });
        }

        // Live endpoint tester
        async function testEndpoint(type) {
            const out = document.getElementById('test-output');
            const lat = document.getElementById('test-latency');
            const targetUrl = type === 'tools' ? 'http://107.102.39.55:3800/api/mcp/tools' : 'http://107.102.39.55:3800/';

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
                out.textContent = `Error: ${err.message}\nPastikan MCP Server (node index.js atau container Docker) sudah aktif di port 3800 pada 107.102.39.55.`;
            }
        }

    </script>

</body>

</html>