<?php
// File ini mengambil data sesi yang sudah dimulai oleh file pemanggil (misal: index.php)
$user_details = $_SESSION['user_details'] ?? ['profile_picture' => 'default.png', 'email' => 'user@example.com'];
$username = $_SESSION['username'] ?? 'User';

// --- NEW HELPER FUNCTION ---
// function has_special_access() {
//     $is_admin = isset($_SESSION["role"]) && $_SESSION["role"] === 'admin';
//     $is_endri = (strtolower($_SESSION['user_details']['email'] ?? '') === 'endri@samsung.com');
//     return $is_admin || $is_endri;
// }
?>

<?php if (in_array($active_page, ['project_dashboard', 'gba_tasks', 'gba_tasks_summary'])): ?>
    <!-- ponytail: Spotlight Search Overlay — shared across pages via header.php -->
    <style>
        #spotlight-overlay {
            position: fixed;
            inset: 0;
            z-index: 9998;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            padding-bottom: 40px;
            background: transparent;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.18s ease;
        }

        #spotlight-overlay.sl-open {
            opacity: 1;
            pointer-events: auto;
        }

        @keyframes spotlightGlowDark {
            0%, 100% {
                border-color: rgba(99, 102, 241, 0.7);
                box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5),
                            0 0 16px rgba(99, 102, 241, 0.45),
                            0 0 32px rgba(129, 140, 248, 0.25);
            }
            50% {
                border-color: rgba(168, 85, 247, 0.85);
                box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5),
                            0 0 24px rgba(168, 85, 247, 0.6),
                            0 0 44px rgba(99, 102, 241, 0.35);
            }
        }

        /* --- Spotlight in Dark Theme (Default): Light/Bright Form & Button --- */
        #spotlight-box {
            width: 620px;
            max-width: calc(100vw - 32px);
            border-radius: 18px;
            background: #ffffff;
            border: 1px solid rgba(99, 102, 241, 0.6);
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
            transform: scale(0.95) translateY(12px);
            transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1);
            overflow: hidden;
        }

        html:not(.light) #spotlight-box {
            animation: spotlightGlowDark 3.5s ease-in-out infinite;
        }

        #spotlight-overlay.sl-open #spotlight-box {
            transform: scale(1) translateY(0);
        }

        #sl-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            border-bottom: 1px solid #e2e8f0;
        }

        #sl-row svg {
            flex-shrink: 0;
            color: #64748b;
        }

        #sl-input {
            flex: 1;
            background: transparent;
            border: none;
            outline: none;
            font-size: 18px;
            font-weight: 500;
            color: #0f172a;
            caret-color: #4f46e5;
            font-family: inherit;
        }

        #sl-input::placeholder {
            color: #94a3b8;
            font-weight: 400;
        }

        #sl-clear {
            display: none;
            align-items: center;
            gap: 4px;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            color: #475569;
            padding: 3px 8px;
            font-size: 11px;
            cursor: pointer;
            white-space: nowrap;
            font-weight: 500;
            transition: background 0.15s, color 0.15s, border-color 0.15s;
        }

        #sl-clear:hover {
            background: #e2e8f0;
            color: #0f172a;
            border-color: #94a3b8;
        }

        #sl-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 20px;
            font-size: 11px;
            color: #64748b;
            background: #f8fafc;
            border-top: 1px solid #f1f5f9;
        }

        .sl-key {
            display: inline-block;
            padding: 2px 7px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 5px;
            font-family: monospace;
            font-size: 11px;
            color: #334155;
            font-weight: 600;
            box-shadow: 0 1px 1px rgba(0, 0, 0, 0.05);
        }

        /* Search trigger button in header (Light contrast in Dark Theme) */
        #sl-trigger {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            padding: 6px 10px;
            cursor: pointer;
            background: #ffffff;
            color: #0f172a;
            white-space: nowrap;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
            transition: border-color 0.2s, background 0.2s, padding 0.25s, box-shadow 0.2s;
        }

        #sl-trigger:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
        }

        #sl-trigger svg {
            flex-shrink: 0;
            width: 18px;
            height: 18px;
            color: #334155;
        }

        #sl-trigger-text,
        #sl-x {
            max-width: 0;
            opacity: 0;
            overflow: hidden;
            pointer-events: none;
            transition: max-width 0.3s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.25s ease;
        }

        #sl-trigger-text {
            font-size: 13px;
            font-weight: 500;
            opacity: 0;
            white-space: nowrap;
            color: #0f172a;
        }

        #sl-trigger.has-query {
            border-color: #818cf8;
            background: #e0e7ff;
            padding: 6px 10px;
        }

        #sl-trigger.has-query #sl-trigger-text {
            max-width: 160px;
            opacity: 1;
            pointer-events: auto;
            color: #3730a3;
        }

        #sl-trigger-hint {
            font-size: 11px;
            color: #64748b;
            font-family: monospace;
            flex-shrink: 0;
            font-weight: 600;
            opacity: 0.85;
        }

        #sl-trigger.has-query #sl-trigger-hint {
            display: none;
        }

        #sl-x {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            flex-shrink: 0;
            background: rgba(0, 0, 0, 0.12);
            color: #334155;
            font-size: 9px;
            line-height: 1;
            transition: max-width 0.3s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.25s ease, background 0.15s;
        }

        #sl-x:hover {
            background: #ef4444;
            color: #ffffff;
        }

        #sl-trigger.has-query #sl-x {
            max-width: 20px;
            opacity: 1;
            pointer-events: auto;
        }

        /* --- Spotlight in Light Theme (html.light): Dark Form & Button --- */
        html.light #sl-trigger {
            background: #0f172a;
            color: #f8fafc;
            border: 1px solid #334155;
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.25);
        }

        html.light #sl-trigger:hover {
            background: #1e293b;
            border-color: #475569;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.35);
        }

        html.light #sl-trigger svg {
            color: #94a3b8;
        }

        html.light #sl-trigger-text {
            color: #f8fafc;
        }

        html.light #sl-trigger-hint {
            color: #94a3b8;
            opacity: 0.85;
        }

        html.light #sl-trigger.has-query {
            border-color: #6366f1;
            background: #1e1b4b;
        }

        html.light #sl-trigger.has-query #sl-trigger-text {
            color: #e0e7ff;
        }

        html.light #sl-trigger.has-query svg {
            color: #818cf8;
        }

        html.light #sl-x {
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
        }

        html.light #sl-x:hover {
            background: #ef4444;
            color: #ffffff;
        }

        html.light #spotlight-box {
            background: rgba(15, 20, 35, 0.95);
            border: 1px solid rgba(99, 102, 241, 0.35);
            box-shadow: 0 32px 80px rgba(0, 0, 0, 0.65), 0 0 0 1px rgba(99, 102, 241, 0.15);
        }

        html.light #sl-row {
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        html.light #sl-row svg {
            color: rgba(148, 163, 184, 0.85);
        }

        html.light #sl-input {
            color: #f8fafc;
            caret-color: #818cf8;
        }

        html.light #sl-input::placeholder {
            color: rgba(148, 163, 184, 0.5);
        }

        html.light #sl-clear {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #cbd5e1;
        }

        html.light #sl-clear:hover {
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.3);
        }

        html.light #sl-footer {
            background: rgba(15, 20, 35, 0.8);
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            color: rgba(148, 163, 184, 0.8);
        }

        html.light .sl-key {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: rgba(226, 232, 240, 0.9);
            box-shadow: none;
        }
    </style>

    <div id="spotlight-overlay">
        <div id="spotlight-box">
            <div id="sl-row">
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
                <input type="text" id="sl-input" placeholder="Cari task, model, status..." autocomplete="off"
                    spellcheck="false">
                <button id="sl-clear" title="Clear">
                    <svg width="9" height="9" viewBox="0 0 10 10" stroke="currentColor" fill="none">
                        <path d="M1 1l8 8M9 1l-8 8" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                    Clear
                </button>
            </div>
            <div id="sl-footer">
                <span>
                    <span class="sl-key">Esc</span> &nbsp;tutup &nbsp;&nbsp;
                    <span class="sl-key">Ctrl K</span> &nbsp;buka/tutup
                </span>
                <span>Ketik atau paste untuk mencari</span>
            </div>
            <!-- Hidden proxy input — page JS listen to this element -->
            <input type="search" id="search-input" aria-hidden="true"
                style="position:absolute;opacity:0;pointer-events:none;width:0;height:0;">
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var overlay = document.getElementById('spotlight-overlay');
            var box = document.getElementById('spotlight-box');
            var slInput = document.getElementById('sl-input');
            var clearBtn = document.getElementById('sl-clear');
            var hidden = document.getElementById('search-input');

            var trigger = document.getElementById('sl-trigger');
            var triggerText = document.getElementById('sl-trigger-text');
            var slX = document.getElementById('sl-x');

            function updateTrigger() {
                var q = slInput.value.trim();
                if (q) {
                    triggerText.textContent = q;
                    trigger.classList.add('has-query');
                } else {
                    triggerText.textContent = '';
                    trigger.classList.remove('has-query');
                }
            }

            function open(char) {
                overlay.classList.add('sl-open');
                slInput.focus();
                if (char && char.length === 1) { slInput.value = char; sync(); }
            }

            function close() {
                overlay.classList.remove('sl-open');
                slInput.blur();
                if (document.activeElement) document.activeElement.blur();
                // ponytail: update trigger pill to show last searched text
                updateTrigger();
            }

            function sync() {
                hidden.value = slInput.value;
                hidden.dispatchEvent(new Event('input', { bubbles: true }));
                clearBtn.style.display = slInput.value ? 'inline-flex' : 'none';
            }

            slInput.addEventListener('input', sync);

            clearBtn.addEventListener('click', function () {
                slInput.value = ''; sync(); slInput.focus();
            });

            // Trigger pill click → open; X button → clear
            trigger.addEventListener('click', function (e) {
                if (e.target === slX || slX.contains(e.target)) {
                    e.stopPropagation();
                    slInput.value = ''; sync(); updateTrigger();
                } else {
                    open();
                }
            });
            slX.addEventListener('click', function (e) {
                e.stopPropagation();
                slInput.value = ''; sync(); updateTrigger();
            });

            slInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { e.stopPropagation(); close(); }
            });

            // Close on backdrop click
            overlay.addEventListener('mousedown', function (e) {
                if (!box.contains(e.target)) close();
            });

            // Global shortcut
            document.addEventListener('keydown', function (e) {
                // Ctrl/Cmd + K
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                    e.preventDefault();
                    overlay.classList.contains('sl-open') ? close() : open();
                    return;
                }
                if (e.key === 'Escape' && overlay.classList.contains('sl-open')) { close(); return; }
                if (overlay.classList.contains('sl-open')) return;

                // Skip if focus is in any input/textarea (blur slInput if overlay is closed)
                var active = document.activeElement;
                if (active === slInput) {
                    active.blur();
                    active = document.activeElement;
                }
                var tag = active ? active.tagName : '';
                if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
                if (document.activeElement && document.activeElement.isContentEditable) return;
                // Skip if modal is open
                var modal = document.getElementById('task-modal');
                if (modal && !modal.classList.contains('hidden')) return;

                // Open on printable key (no ctrl/cmd/alt)
                if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
                    // ponytail: prevent default key insertion to avoid double first character
                    e.preventDefault();
                    open(e.key);
                }
            });

            // Global paste outside inputs
            document.addEventListener('paste', function (e) {
                var tag = document.activeElement ? document.activeElement.tagName : '';
                if (tag === 'INPUT' || tag === 'TEXTAREA') return;
                if (overlay.classList.contains('sl-open')) return;
                var text = (e.clipboardData || window.clipboardData || {}).getData('text') || '';
                if (text) { e.preventDefault(); open(); slInput.value = text; sync(); }
            });
        });
    </script>
<?php endif; ?>

<header class="glass-container sticky top-0 z-20 shadow-sm flex-shrink-0">
    <div class="w-full mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <div class="flex items-center space-x-3">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                    stroke="currentColor" class="w-8 h-8 text-blue-600">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                </svg>
                <h1 class="text-xl font-bold text-header">GBA Task Manager</h1>
                <div class="hidden md:flex items-baseline space-x-4 ml-4">
                    <a href="index.php"
                        class="px-3 py-2 rounded-md text-sm font-medium <?php echo ($active_page === 'project_dashboard') ? 'nav-link-active' : 'nav-link'; ?>">Kanban
                        Board</a>
                    <a href="gba_dashboard.php"
                        class="px-3 py-2 rounded-md text-sm font-medium <?php echo ($active_page === 'gba_dashboard') ? 'nav-link-active' : 'nav-link'; ?>">Dashboard</a>
                    <a href="monthly_calendar.php"
                        class="px-3 py-2 rounded-md text-sm font-medium <?php echo ($active_page === 'monthly_calendar') ? 'nav-link-active' : 'nav-link'; ?>">Calendar</a>
                    <a href="project_roadmap.php"
                        class="px-3 py-2 rounded-md text-sm font-medium <?php echo ($active_page === 'project_roadmap') ? 'nav-link-active' : 'nav-link'; ?>">Roadmap</a>
                    <a href="gba_tasks.php"
                        class="px-3 py-2 rounded-md text-sm font-medium <?php echo ($active_page === 'gba_tasks') ? 'nav-link-active' : 'nav-link'; ?>">Active
                        Tasks</a>
                    <a href="gba_tasks_summary.php"
                        class="px-3 py-2 rounded-md text-sm font-medium <?php echo ($active_page === 'gba_tasks_summary') ? 'nav-link-active' : 'nav-link'; ?>">Summary</a>
                    <a href="activity_log.php"
                        class="px-3 py-2 rounded-md text-sm font-medium <?php echo ($active_page === 'activity_log') ? 'nav-link-active' : 'nav-link'; ?>">Activity
                        Log</a>
                    <a href="mcp_features.php"
                        class="px-3 py-2 rounded-md text-sm font-medium <?php echo ($active_page === 'mcp_features') ? 'nav-link-active' : 'nav-link'; ?>">
                        <span class="inline-flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                            MCP Hub
                        </span>
                    </a>
                </div>
            </div>

            <div class="flex items-center space-x-2">
                <?php if (in_array($active_page, ['project_dashboard', 'gba_tasks', 'gba_tasks_summary'])): ?>
                    <button id="sl-trigger" title="Cari (Ctrl+K)">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        <span id="sl-trigger-text"></span>
                        <span id="sl-trigger-hint">^K</span>
                        <span id="sl-x" title="Hapus pencarian">✕</span>
                    </button>
                <?php endif; ?>

                <?php if ($active_page === 'project_dashboard'): ?>
                    <button id="view-toggle" type="button" class="text-icon hover:bg-gray-500/10 rounded-lg text-sm p-2.5">
                        <svg id="view-toggle-full-icon" class="w-5 h-5" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                        <svg id="view-toggle-accordion-icon" class="w-5 h-5 hidden" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 6h16M4 12h16m-7 6h7"></path>
                        </svg>
                    </button>
                <?php endif; ?>

                <button id="theme-toggle" type="button" class="text-icon hover:bg-gray-500/10 rounded-lg text-sm p-2.5">
                    <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path>
                    </svg>
                    <svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path
                            d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z"
                            fill-rule="evenodd" clip-rule="evenodd"></path>
                    </svg>
                </button>

                <a href="http://107.102.39.55/smart_filter/" target="_blank"
                    class="inline-flex items-center justify-center rounded-lg bg-purple-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-500">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                        class="w-5 h-5 -ml-0.5 mr-1.5">
                        <path fill-rule="evenodd"
                            d="M2.628 1.601C5.028 1.206 7.49 1 10 1s4.973.206 7.372.601a.75.75 0 0 1 .628.74v2.288a2.25 2.25 0 0 1-.659 1.59l-4.682 4.683a2.25 2.25 0 0 0-.659 1.59v3.037c0 .684-.31 1.33-.844 1.757l-1.937 1.55A.75.75 0 0 1 8 18.25v-5.757a2.25 2.25 0 0 0-.659-1.59L2.659 6.22A2.25 2.25 0 0 1 2 4.629V2.34a.75.75 0 0 1 .628-.74Z"
                            clip-rule="evenodd" />
                    </svg>
                    Smart Filter
                </a>

                <a href="bulk_add.php"
                    class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500">
                    <svg class="-ml-0.5 mr-1.5 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.125 1.125 0 010 2.25H5.625a1.125 1.125 0 010-2.25z" />
                    </svg>
                    Bulk Add
                </a>

                <button onclick="openAddModal()"
                    class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500">
                    <svg class="-ml-0.5 mr-1.5 h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path
                            d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" />
                    </svg>
                    Task Baru
                </button>

                <div class="relative" id="profile-menu">
                    <button class="flex items-center space-x-2 focus:outline-none">
                        <img src="uploads/<?php echo htmlspecialchars($user_details['profile_picture']); ?>"
                            alt="Avatar"
                            class="w-9 h-9 rounded-full object-cover border-2 border-transparent hover:border-blue-500 transition">
                        <span
                            class="text-sm font-medium hidden md:block text-header"><?php echo htmlspecialchars($username); ?></span>
                    </button>
                    <div id="profile-dropdown"
                        class="hidden absolute right-0 mt-2 w-48 bg-gray-800 rounded-lg shadow-lg py-1 z-50 border border-gray-700">
                        <div class="px-4 py-3 border-b border-gray-700">
                            <p class="text-sm font-semibold text-white"><?php echo htmlspecialchars($username); ?></p>
                            <p class="text-xs text-gray-400 truncate">
                                <?php echo htmlspecialchars($user_details['email'] ?? ''); ?></p>
                        </div>
                        <a href="profile.php"
                            class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white">Profil
                            Saya</a>
                        <a href="ga_submission_tracker.php"
                            class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white">Reason
                            OT</a>
                        <a href="monthly_calendar.php"
                            class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white">Kalender</a>
                        <a href="logout.php"
                            class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white">Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- Modal Informasi Model Discontinue / Drop -->
<div id="dropped-model-modal" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/75 backdrop-blur-sm hidden" style="backdrop-filter: blur(8px);">
    <div class="relative w-full max-w-lg mx-4 rounded-2xl p-6 shadow-2xl transition-all border border-rose-500/30 bg-slate-900 text-slate-100" style="background: rgba(15, 23, 42, 0.95); border: 1px solid rgba(244, 63, 94, 0.35); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7), 0 0 25px rgba(244, 63, 94, 0.2);">
        <div class="flex items-start gap-4">
            <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-rose-500/20 border border-rose-500/30 flex items-center justify-center text-rose-400">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <h3 class="text-lg font-bold text-white flex items-center gap-2">
                    Model Discontinue / Drop
                </h3>
                <div class="mt-2 text-sm text-slate-300 space-y-2">
                    <p id="dropped-modal-message">
                        Task untuk model ini tidak tersimpan karena model sudah <strong>Discontinue / Drop</strong> dari proses development.
                    </p>
                    <div id="dropped-modal-list-container" class="hidden mt-3 max-h-48 overflow-y-auto rounded-lg p-3 bg-slate-950/60 border border-slate-800">
                        <ul id="dropped-modal-list" class="space-y-1.5 text-xs text-rose-300 font-mono"></ul>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-6 flex justify-end">
            <button type="button" id="btn-close-dropped-modal" onclick="closeDroppedModelModal()" class="px-5 py-2.5 bg-rose-600 hover:bg-rose-500 active:scale-95 text-white font-semibold rounded-xl text-sm transition shadow-lg shadow-rose-900/40">
                Saya Mengerti
            </button>
        </div>
    </div>
</div>

<script>
function showDroppedModelModal(modelData, customMsg) {
    const modal = document.getElementById('dropped-model-modal');
    const msgEl = document.getElementById('dropped-modal-message');
    const listContainer = document.getElementById('dropped-modal-list-container');
    const listEl = document.getElementById('dropped-modal-list');
    const closeBtn = document.getElementById('btn-close-dropped-modal');
    if (!modal) return;

    listEl.innerHTML = '';
    listContainer.classList.add('hidden');

    if (Array.isArray(modelData) && modelData.length > 0) {
        msgEl.innerHTML = customMsg || `Terdapat <strong>${modelData.length} model</strong> yang tidak dapat disimpan karena sudah berstatus <strong>Discontinue / Drop</strong> dari proses development:`;
        modelData.forEach(m => {
            const li = document.createElement('li');
            li.className = 'flex items-center gap-1.5';
            li.innerHTML = `<span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> <span>${m}</span>`;
            listEl.appendChild(li);
        });
        listContainer.classList.remove('hidden');
    } else if (typeof modelData === 'string' && modelData.trim()) {
        msgEl.innerHTML = customMsg || `Task untuk model <strong class="text-rose-400 font-mono">${modelData}</strong> tidak dapat disimpan karena model tersebut sudah <strong>Discontinue / Drop</strong> dari proses development.`;
    } else if (customMsg) {
        msgEl.innerHTML = customMsg;
    }

    modal.classList.remove('hidden');
    if (closeBtn) closeBtn.focus();
}

function closeDroppedModelModal() {
    const modal = document.getElementById('dropped-model-modal');
    if (modal) modal.classList.add('hidden');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDroppedModelModal();
    }
});
</script>

<?php
$flash_dropped_single = $_SESSION['dropped_model_error'] ?? null;
$flash_dropped_bulk = $_SESSION['bulk_dropped_notice'] ?? null;
unset($_SESSION['dropped_model_error'], $_SESSION['bulk_dropped_notice']);
?>
<?php if (!empty($flash_dropped_single)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    showDroppedModelModal(<?= json_encode($flash_dropped_single) ?>);
});
</script>
<?php elseif (!empty($flash_dropped_bulk)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const createdCount = <?= (int)($flash_dropped_bulk['created'] ?? 0) ?>;
    const droppedList = <?= json_encode($flash_dropped_bulk['dropped'] ?? []) ?>;
    const msg = `Bulk Add selesai (${createdCount} task berhasil dibuat). Namun terdapat <strong>${droppedList.length} task</strong> yang tidak disimpan karena model berstatus <strong>Discontinue / Drop</strong>:`;
    showDroppedModelModal(droppedList, msg);
});
</script>
<?php endif; ?>

<!-- Speed Dial Chatbot Widget -->
<?php
if (isset($_GET['chat_popup'])) {
    echo '<style>
        body > *:not(#hermes-chat-widget):not(script) { display: none !important; }
        body { background: #09090b !important; margin: 0; padding: 0; overflow: hidden; }
        #hermes-chat-widget { display: flex !important; width: 100vw; height: 100vh; position: fixed; top: 0; left: 0; right: 0; bottom: 0; }
        #hermes-chat-window { display: flex !important; width: 100vw !important; height: 100vh !important; max-width: none !important; max-height: none !important; border-radius: 0 !important; margin: 0 !important; transform: none !important; visibility: visible !important; opacity: 1 !important; position: static !important; }
        #hermes-chat-toggle { display: none !important; }
        #hermes-btn-popup, #hermes-btn-expand, #hermes-btn-close, #hermes-btn-clear { display: none !important; }
    </style>';
    echo '<script>
        window.addEventListener("DOMContentLoaded", function() {
            var chatWin = document.getElementById("hermes-chat-window");
            if (chatWin) { chatWin.classList.remove("hermes-hidden"); chatWin.classList.add("hermes-visible"); }
        });
    </script>';
}
?>
<style>
    #hermes-chat-widget {
        position: fixed;
        bottom: 24px;
        right: 24px;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        pointer-events: none;
    }

    #hermes-chat-toggle {
        pointer-events: auto;
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: linear-gradient(135deg, #2563eb, #4f46e5);
        color: #fff;
        border: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 20px rgba(79, 70, 229, 0.5);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        position: relative;
        flex-shrink: 0;
    }

    #hermes-chat-toggle:hover {
        transform: scale(1.08);
        box-shadow: 0 6px 24px rgba(79, 70, 229, 0.7);
    }

    #hermes-chat-toggle:active {
        transform: scale(0.95);
    }

    #hermes-chat-toggle svg {
        width: 24px;
        height: 24px;
        flex-shrink: 0;
    }

    #hermes-pulse-ring {
        position: absolute;
        inset: 0;
        border-radius: 50%;
        background: rgba(99, 102, 241, 0.4);
        animation: hermesPing 2s cubic-bezier(0, 0, 0.2, 1) infinite;
    }

    #hermes-chat-toggle.chat-open #hermes-pulse-ring {
        display: none;
    }

    @keyframes hermesPing {

        75%,
        100% {
            transform: scale(2);
            opacity: 0;
        }
    }

    #hermes-chat-window {
        pointer-events: auto;
        width: 520px;
        height: 600px;
        max-width: calc(100vw - 32px);
        max-height: calc(100vh - 100px);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 20px 60px rgba(0,0,0,0.5), 0 0 0 1px rgba(51,65,85,0.5);
        background: #09090b;
        color: #f4f4f5;
        margin-bottom: 12px;
        transform-origin: bottom right;
        transition: transform 0.25s cubic-bezier(0.16,1,0.3,1), opacity 0.25s ease;
        display: flex;
        flex-direction: column;
        font-family: inherit;
    }

    #hermes-chat-window.hermes-hidden {
        transform: scale(0.88) translateY(8px);
        opacity: 0;
        pointer-events: none;
        visibility: hidden;
    }

    #hermes-chat-window.hermes-visible {
        transform: scale(1) translateY(0);
        opacity: 1;
        pointer-events: auto;
        visibility: visible;
    }

    #hermes-chat-window.hermes-chat-fullscreen {
        width: 100vw !important; height: 100vh !important;
        max-width: 100vw !important; max-height: 100vh !important;
        border-radius: 0 !important;
        position: fixed !important;
        top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important;
        margin: 0 !important; z-index: 100000 !important; transform: none !important;
    }

    .hermes-header {
        padding: 14px 16px;
        background: rgba(24,24,27,0.95);
        border-bottom: 1px solid rgba(63,63,70,0.6);
        display: flex; justify-content: space-between; align-items: center;
        flex-shrink: 0;
    }
    .hermes-header-title { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #f4f4f5; }
    .hermes-status-dot { width: 7px; height: 7px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,0.7); }
    .hermes-header-actions { display: flex; gap: 12px; align-items: center; }
    .hermes-header-btn { background: none; border: none; color: #94a3b8; cursor: pointer; padding: 0; display: flex; transition: color 0.2s; }
    .hermes-header-btn:hover { color: #f4f4f5; }

    .hermes-body {
        flex: 1; overflow-y: auto; padding: 16px;
        display: flex; flex-direction: column; gap: 12px;
        scrollbar-width: thin; scrollbar-color: #3f3f46 transparent;
    }
    .hermes-msg { max-width: 88%; padding: 10px 14px; border-radius: 12px; font-size: 13px; line-height: 1.55; word-break: break-word; }
    .hermes-msg-user { align-self: flex-end; background: #2563eb; color: #fff; border-bottom-right-radius: 4px; }
    .hermes-msg-ai { align-self: flex-start; background: #18181b; color: #e4e4e7; border-bottom-left-radius: 4px; border: 1px solid rgba(63,63,70,0.5); max-width: 96%; }
    .hermes-msg-ai table { width: 100%; border-collapse: collapse; font-size: 12px; margin: 8px 0; }
    .hermes-msg-ai th { background: rgba(99,102,241,0.2); color: #818cf8; padding: 6px 8px; text-align: left; border: 1px solid rgba(63,63,70,0.6); font-weight: 600; }
    .hermes-msg-ai td { padding: 5px 8px; border: 1px solid rgba(63,63,70,0.4); color: #d4d4d8; }
    .hermes-msg-ai tr:nth-child(even) td { background: rgba(24,24,27,0.5); }
    .hermes-msg-ai pre { background: #09090b; padding: 10px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
    .hermes-msg-ai code { font-size: 12px; color: #a5f3fc; }
    .hermes-msg-ai p { margin: 0 0 8px; } .hermes-msg-ai p:last-child { margin-bottom: 0; }
    .hermes-msg-ai ul, .hermes-msg-ai ol { padding-left: 18px; margin: 6px 0; }
    .hermes-msg-ai svg { width: 100% !important; height: auto !important; max-width: 100% !important; display: block; margin: 10px 0; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.2); }
    .hermes-dl-btn { display: inline-flex; align-items: center; gap: 6px; background: rgba(99,102,241,0.15); color: #818cf8; border: 1px solid rgba(99,102,241,0.4); border-radius: 6px; padding: 5px 10px; font-size: 11px; font-weight: 600; cursor: pointer; margin: 6px 4px 6px 0; transition: all 0.2s; font-family: inherit; }
    .hermes-dl-btn:hover { background: rgba(99,102,241,0.35); color: #fff; border-color: #6366f1; }
    .hermes-dl-excel { background: rgba(34,197,94,0.15); color: #4ade80; border-color: rgba(34,197,94,0.4); }
    .hermes-dl-excel:hover { background: rgba(34,197,94,0.35); color: #fff; border-color: #22c55e; }
    .hermes-confirm-box { display: flex; gap: 10px; margin-top: 12px; }
    .hermes-confirm-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; border: none; transition: all 0.2s; font-family: inherit; }
    .hermes-btn-danger { background: #dc2626; color: #fff; }
    .hermes-btn-danger:hover { background: #b91c1c; }
    .hermes-btn-cancel { background: rgba(63,63,70,0.6); color: #a1a1aa; border: 1px solid rgba(63,63,70,0.8); }
    .hermes-btn-cancel:hover { background: rgba(63,63,70,0.9); color: #f4f4f5; }
    .hermes-input-area { display: flex; gap: 8px; padding: 12px 14px; border-top: 1px solid rgba(63,63,70,0.5); background: rgba(24,24,27,0.8); flex-shrink: 0; }
    .hermes-input-area input { flex: 1; background: rgba(39,39,42,0.8); border: 1px solid rgba(63,63,70,0.6); border-radius: 8px; color: #f4f4f5; font-size: 13px; padding: 9px 12px; outline: none; font-family: inherit; transition: border-color 0.2s; }
    .hermes-input-area input:focus { border-color: #4f46e5; }
    .hermes-input-area input::placeholder { color: #71717a; }
    .hermes-input-area button { background: #4f46e5; color: #fff; border: none; border-radius: 8px; padding: 9px 16px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; transition: background 0.2s; }
    .hermes-input-area button:hover { background: #4338ca; }
    .hermes-typing-dots span { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #a1a1aa; animation: hermesTyping 1.4s infinite ease-in-out both; margin: 0 2px; }
    .hermes-typing-dots span:nth-child(1) { animation-delay: -0.32s; }
    .hermes-typing-dots span:nth-child(2) { animation-delay: -0.16s; }
    @keyframes hermesTyping { 0%, 80%, 100% { transform: scale(0); } 40% { transform: scale(1); } }
</style>

<div id="hermes-chat-widget">
    <div id="hermes-chat-window" class="hermes-hidden">
        <div class="hermes-header">
            <div class="hermes-header-title">
                <span class="hermes-status-dot"></span>
                <span>GBA AI Assistant</span>
            </div>
            <div class="hermes-header-actions">
                <button type="button" id="hermes-btn-clear" class="hermes-header-btn" title="Bersihkan Sesi (Clear History)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                </button>
                <button type="button" id="hermes-btn-popup" class="hermes-header-btn" title="Buka di jendela baru (Detach)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                </button>
                <button type="button" id="hermes-btn-expand" class="hermes-header-btn" title="Expand Fullscreen">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"></path></svg>
                </button>
                <button type="button" id="hermes-btn-close" class="hermes-header-btn" title="Tutup Chat">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
        </div>
        <div class="hermes-body" id="hermes-messages">
            <div class="hermes-msg hermes-msg-ai">
                Halo <strong><?php echo htmlspecialchars($username); ?></strong>! 👋<br/>Saya GBA AI Assistant. Tanyakan apa saja mengenai status project, task pending, atau update submission hari ini.
            </div>
        </div>
        <form class="hermes-input-area" id="hermes-form">
            <input type="text" id="hermes-input" placeholder="Tanyakan ke GBA AI..." autocomplete="off" required />
            <button type="submit" id="hermes-send-btn">Kirim</button>
        </form>
    </div>
    <button id="hermes-chat-toggle" title="Chat dengan GBA AI">
        <span id="hermes-pulse-ring"></span>
        <!-- Chat icon -->
        <svg id="hermes-icon-chat" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.12 2.83 2.62 2.95v3l3-3h7a2.25 2.25 0 0 0 2.25-2.25v-7a2.25 2.25 0 0 0-2.25-2.25h-10.5A2.25 2.25 0 0 0 2.25 4.5v7.5c0 .33.07.65.2.95Z" />
        </svg>
        <!-- Close icon -->
        <svg id="hermes-icon-close" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="display:none">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
        </svg>
    </button>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script>
    (function () {
        var HERMES_BASE_PATH = (function() {
            var base = window.location.origin;
            var parts = window.location.pathname.split('/');
            // ponytail: find /tkdn/ root from URL
            var idx = parts.indexOf('tkdn');
            if (idx >= 0) return base + parts.slice(0, idx + 1).join('/');
            return base;
        })();
        var N8N_WEBHOOK_URL = window.HERMES_N8N_WEBHOOK || (HERMES_BASE_PATH + '/api_mcp_chat.php');

        var toggleBtn = document.getElementById('hermes-chat-toggle');
        var chatWindow = document.getElementById('hermes-chat-window');
        var messagesBox = document.getElementById('hermes-messages');
        var chatForm = document.getElementById('hermes-form');
        var chatInput = document.getElementById('hermes-input');
        var iconChat = document.getElementById('hermes-icon-chat');
        var iconClose = document.getElementById('hermes-icon-close');
        var isOpen = false;

        toggleBtn.addEventListener('click', function () {
            isOpen = !isOpen;
            if (isOpen) {
                chatWindow.classList.remove('hermes-hidden');
                chatWindow.classList.add('hermes-visible');
                toggleBtn.classList.add('chat-open');
                iconChat.style.display = 'none';
                iconClose.style.display = '';
                chatInput.focus();
            } else {
                chatWindow.classList.remove('hermes-visible');
                chatWindow.classList.add('hermes-hidden');
                toggleBtn.classList.remove('chat-open');
                iconChat.style.display = '';
                iconClose.style.display = 'none';
            }
        });

        window.sendChatConfirmation = function(msg) {
            chatInput.value = msg;
            chatForm.dispatchEvent(new Event('submit'));
        };

        window.downloadSVGFromChat = function(btn) {
            var svg = btn.previousElementSibling;
            if (!svg || svg.tagName.toLowerCase() !== 'svg') return;
            var blob = new Blob([svg.outerHTML], { type: 'image/svg+xml' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url; a.download = 'chart_' + Date.now() + '.svg';
            document.body.appendChild(a); a.click();
            document.body.removeChild(a); URL.revokeObjectURL(url);
        };

        window.exportTableToExcelFromChat = function(btn) {
            var table = btn.previousElementSibling;
            if (!table || table.tagName.toLowerCase() !== 'table') return;
            var csv = Array.from(table.querySelectorAll('tr')).map(function(r) {
                return Array.from(r.querySelectorAll('th,td')).map(function(c) {
                    return '"' + c.innerText.replace(/"/g,'""') + '"';
                }).join(',');
            }).join('\n');
            var blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
            var url = URL.createObjectURL(blob);
            var link = document.createElement('a');
            link.href = url; link.download = 'export_table_' + Date.now() + '.csv';
            document.body.appendChild(link); link.click(); document.body.removeChild(link);
        };

        function attachDownloadHandlers(msgDiv) {
            msgDiv.querySelectorAll('svg').forEach(function(svg) {
                if (svg.id && svg.id.startsWith('hermes-')) return;
                if (svg.nextElementSibling && svg.nextElementSibling.classList && svg.nextElementSibling.classList.contains('hermes-dl-btn')) return;
                if (!svg.getAttribute('viewBox') && svg.getAttribute('width') && svg.getAttribute('height')) {
                    svg.setAttribute('viewBox', '0 0 ' + svg.getAttribute('width').replace('px','') + ' ' + svg.getAttribute('height').replace('px',''));
                }
                svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
                var dlBtn = document.createElement('button');
                dlBtn.className = 'hermes-dl-btn'; dlBtn.innerHTML = '📥 Download SVG';
                dlBtn.onclick = function() { window.downloadSVGFromChat(this); };
                svg.insertAdjacentElement('afterend', dlBtn);
            });
            msgDiv.querySelectorAll('table').forEach(function(table) {
                if (table.nextElementSibling && table.nextElementSibling.classList && table.nextElementSibling.classList.contains('hermes-dl-excel')) return;
                var dlBtn = document.createElement('button');
                dlBtn.className = 'hermes-dl-btn hermes-dl-excel'; dlBtn.innerHTML = '📊 Export Excel (.csv)';
                dlBtn.onclick = function() { window.exportTableToExcelFromChat(this); };
                table.insertAdjacentElement('afterend', dlBtn);
            });
        }

        // ponytail: stateless session history via localStorage, keyed per user
        var userNameForChat = '<?php echo htmlspecialchars($username); ?>';
        var sessionKey = 'hermes_chat_history_' + userNameForChat;
        var chatHistory = [];
        try { chatHistory = JSON.parse(localStorage.getItem(sessionKey)) || []; } catch(e) { chatHistory = []; }

        // ponytail: protect inline SVGs and un-fence markdown code blocks before marked.parse
        function renderMarkdown(content) {
            if (typeof content !== 'string') return '';
            // Un-fence code blocks wrapping SVG so they render as graphic elements instead of raw text code blocks
            var cleaned = content.replace(/```(?:xml|svg|html)?\s*(<svg[\s\S]*?<\/svg>)\s*```/gi, '$1');

            // Extract all SVGs to unique placeholders so marked never corrupts internal empty lines/indentation into <pre><code>
            var svgs = [];
            var withPlaceholders = cleaned.replace(/<svg[\s\S]*?<\/svg>/gi, function(match) {
                var idx = svgs.length;
                svgs.push(match);
                return '@@HERMES_SVG_' + idx + '@@';
            });

            var html = (typeof marked !== 'undefined' && marked.parse) ? marked.parse(withPlaceholders) : withPlaceholders;

            // Restore clean SVG markup back into the rendered HTML
            html = html.replace(/<p>\s*@@HERMES_SVG_(\d+)@@\s*<\/p>|@@HERMES_SVG_(\d+)@@/g, function(match, p1, p2) {
                var idx = parseInt(p1 || p2, 10);
                return svgs[idx] || '';
            });

            return html;
        }

        function appendMessage(text, isUser, skipSave) {
            var msgDiv = document.createElement('div');
            msgDiv.className = 'hermes-msg ' + (isUser ? 'hermes-msg-user' : 'hermes-msg-ai');
            if (isUser) {
                msgDiv.textContent = text;
            } else {
                msgDiv.innerHTML = renderMarkdown(text);
                attachDownloadHandlers(msgDiv);
            }
            if (!skipSave) {
                chatHistory.push({ role: isUser ? 'user' : 'assistant', content: text });
                localStorage.setItem(sessionKey, JSON.stringify(chatHistory));
            }
            messagesBox.appendChild(msgDiv);
            messagesBox.scrollTop = messagesBox.scrollHeight;
            return msgDiv;
        }

        // Render riwayat saat halaman dimuat
        if (chatHistory.length > 0) {
            messagesBox.innerHTML = '';
            chatHistory.forEach(function(msg) { appendMessage(msg.content, msg.role === 'user', true); });
        }

        var btnClear   = document.getElementById('hermes-btn-clear');
        var btnPopup   = document.getElementById('hermes-btn-popup');
        var btnExpand  = document.getElementById('hermes-btn-expand');
        var btnClose   = document.getElementById('hermes-btn-close');

        if (btnClear) btnClear.addEventListener('click', function() {
            if (confirm('Hapus semua history percakapan Anda?')) {
                chatHistory = []; localStorage.removeItem(sessionKey);
                messagesBox.innerHTML = '<div class="hermes-msg hermes-msg-ai">Halo <strong>' + userNameForChat + '</strong>! 👋<br/>History dibersihkan. Mulai percakapan baru.</div>';
            }
        });

        if (btnExpand) btnExpand.addEventListener('click', function() { chatWindow.classList.toggle('hermes-chat-fullscreen'); });

        if (btnClose) btnClose.addEventListener('click', function() { if (isOpen) toggleBtn.click(); });

        if (btnPopup) btnPopup.addEventListener('click', function() {
            var currentUrl = new URL(window.location.href);
            if (!currentUrl.searchParams.has('chat_popup')) {
                currentUrl.searchParams.set('chat_popup', '1');
                window.open(currentUrl.toString(), 'GBA_AI_Chat', 'width=550,height=700,menubar=no,toolbar=no,location=no,status=no,resizable=yes,scrollbars=no');
                if (isOpen) toggleBtn.click();
            }
        });

        chatForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var text = chatInput.value.trim();
            if (!text) return;

            appendMessage(text, true);
            chatInput.value = '';

            var typingDiv = document.createElement('div');
            typingDiv.className = 'hermes-msg hermes-msg-ai hermes-typing-dots';
            typingDiv.innerHTML = '<span></span><span></span><span></span>';
            messagesBox.appendChild(typingDiv);
            messagesBox.scrollTop = messagesBox.scrollHeight;

            fetch(N8N_WEBHOOK_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    chatInput: text,
                    messages: chatHistory,
                    user: '<?php echo htmlspecialchars($username); ?>',
                    role: '<?php echo htmlspecialchars($_SESSION['role'] ?? (strtolower($username) === 'endri' ? 'admin' : 'admin')); ?>'
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                messagesBox.removeChild(typingDiv);
                var reply = data.output || data.response || data.text || (typeof data === 'string' ? data : JSON.stringify(data));
                appendMessage(reply, false);
            })
            .catch(function () {
                messagesBox.removeChild(typingDiv);
                appendMessage('<em>⚠️ Gagal terhubung ke MCP Chat Proxy (' + N8N_WEBHOOK_URL + '). Pastikan MCP Server sudah aktif.</em>', false);
            });
        });
    })();
</script>