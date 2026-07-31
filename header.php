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
    position: fixed; inset: 0; z-index: 9998;
    display: flex; align-items: flex-end; justify-content: center;
    padding-bottom: 40px;
    background: rgba(0,0,0,0.4);
    opacity: 0; pointer-events: none;
    transition: opacity 0.18s ease;
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
}
#spotlight-overlay.sl-open { opacity: 1; pointer-events: auto; }
#spotlight-box {
    width: 620px; max-width: calc(100vw - 32px);
    border-radius: 18px;
    background: #0f1423;
    border: 1px solid rgba(99, 102, 241, 0.35);
    box-shadow: 0 24px 64px rgba(0,0,0,0.75),
                0 0 0 1px rgba(99,102,241,0.15),
                inset 0 1px 0 rgba(255,255,255,0.06);
    transform: scale(0.95) translateY(10px);
    transition: transform 0.22s cubic-bezier(0.16,1,0.3,1);
    overflow: hidden;
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
}
#spotlight-overlay.sl-open #spotlight-box { transform: scale(1) translateY(0); }
#sl-row {
    display: flex; align-items: center; gap: 12px;
    padding: 16px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
#sl-row svg { flex-shrink: 0; color: rgba(148,163,184,0.75); }
#sl-input {
    flex: 1; background: transparent; border: none; outline: none;
    font-size: 18px; font-weight: 400; color: #e2e8f0;
    caret-color: #6366f1; font-family: inherit;
}
#sl-input::placeholder { color: rgba(148,163,184,0.45); }
#sl-clear {
    display: none; align-items: center; gap: 4px;
    background: rgba(255,255,255,0.08); border: none; border-radius: 6px;
    color: rgba(148,163,184,0.7); padding: 3px 8px;
    font-size: 11px; cursor: pointer; white-space: nowrap;
    transition: background 0.15s, color 0.15s;
}
#sl-clear:hover { background: rgba(255,255,255,0.14); color: #e2e8f0; }
#sl-footer {
    display: flex; align-items: center; justify-content: space-between;
    padding: 9px 20px; font-size: 11px; color: rgba(100,116,139,0.8);
}
.sl-key {
    display: inline-block; padding: 2px 7px;
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.13); border-radius: 5px;
    font-family: monospace; font-size: 11px; color: rgba(148,163,184,0.8);
}
/* Search trigger pill in header */
#sl-trigger {
    display: inline-flex; align-items: center; gap: 6px;
    border-radius: 8px; border: 1px solid transparent;
    padding: 6px 8px; cursor: pointer; background: transparent;
    color: inherit; white-space: nowrap;
    transition: border-color 0.2s, background 0.2s, padding 0.25s;
}
#sl-trigger:hover { background: rgba(255,255,255,0.06); border-color: rgba(255,255,255,0.1); }
#sl-trigger svg { flex-shrink: 0; width: 18px; height: 18px; }
/* text and x: hidden by default, animated in */
#sl-trigger-text, #sl-x {
    max-width: 0; opacity: 0; overflow: hidden;
    pointer-events: none;
    transition: max-width 0.3s cubic-bezier(0.16,1,0.3,1), opacity 0.25s ease;
}
#sl-trigger-text {
    font-size: 13px; opacity: 0; white-space: nowrap;
}
#sl-trigger.has-query {
    border-color: rgba(99,102,241,0.45);
    background: rgba(99,102,241,0.1);
    padding: 6px 10px;
}
#sl-trigger.has-query #sl-trigger-text {
    max-width: 160px; opacity: 0.85; pointer-events: auto;
}
#sl-trigger-hint { font-size: 11px; opacity: 0.35; font-family: monospace; flex-shrink: 0; }
#sl-trigger.has-query #sl-trigger-hint { display: none; }
#sl-x {
    display: inline-flex; align-items: center; justify-content: center;
    width: 15px; height: 15px; border-radius: 50%; flex-shrink: 0;
    background: rgba(255,255,255,0.15); font-size: 9px; line-height: 1;
    transition: max-width 0.3s cubic-bezier(0.16,1,0.3,1), opacity 0.25s ease, background 0.15s;
}
#sl-x:hover { background: rgba(239,68,68,0.55); }
#sl-trigger.has-query #sl-x {
    max-width: 20px; opacity: 1; pointer-events: auto;
}
</style>

<div id="spotlight-overlay">
    <div id="spotlight-box">
        <div id="sl-row">
            <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
            </svg>
            <input type="text" id="sl-input" placeholder="Cari task, model, status..." autocomplete="off" spellcheck="false">
            <button id="sl-clear" title="Clear">
                <svg width="9" height="9" viewBox="0 0 10 10" stroke="currentColor" fill="none">
                    <path d="M1 1l8 8M9 1l-8 8" stroke-width="1.6" stroke-linecap="round"/>
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
        <input type="search" id="search-input" aria-hidden="true" style="position:absolute;opacity:0;pointer-events:none;width:0;height:0;">
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var overlay = document.getElementById('spotlight-overlay');
    var box     = document.getElementById('spotlight-box');
    var slInput = document.getElementById('sl-input');
    var clearBtn = document.getElementById('sl-clear');
    var hidden  = document.getElementById('search-input');

    var trigger     = document.getElementById('sl-trigger');
    var triggerText = document.getElementById('sl-trigger-text');
    var slX         = document.getElementById('sl-x');

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
        // ponytail: update trigger pill to show last searched text
        updateTrigger();
    }

    function sync() {
        hidden.value = slInput.value;
        hidden.dispatchEvent(new Event('input', { bubbles: true }));
        clearBtn.style.display = slInput.value ? 'inline-flex' : 'none';
    }

    slInput.addEventListener('input', sync);

    clearBtn.addEventListener('click', function() {
        slInput.value = ''; sync(); slInput.focus();
    });

    // Trigger pill click → open; X button → clear
    trigger.addEventListener('click', function(e) {
        if (e.target === slX || slX.contains(e.target)) {
            e.stopPropagation();
            slInput.value = ''; sync(); updateTrigger();
        } else {
            open();
        }
    });
    slX.addEventListener('click', function(e) {
        e.stopPropagation();
        slInput.value = ''; sync(); updateTrigger();
    });

    slInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { e.stopPropagation(); close(); }
    });

    // Close on backdrop click
    overlay.addEventListener('mousedown', function(e) {
        if (!box.contains(e.target)) close();
    });

    // Global shortcut
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + K
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            overlay.classList.contains('sl-open') ? close() : open();
            return;
        }
        if (e.key === 'Escape' && overlay.classList.contains('sl-open')) { close(); return; }
        if (overlay.classList.contains('sl-open')) return;

        // Skip if focus is in any input/textarea
        var tag = document.activeElement ? document.activeElement.tagName : '';
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
    document.addEventListener('paste', function(e) {
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
                </div>
            </div>

            <div class="flex items-center space-x-2">
                <?php if (in_array($active_page, ['project_dashboard', 'gba_tasks', 'gba_tasks_summary'])): ?>
                <button id="sl-trigger" title="Cari (Ctrl+K)">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
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

                <button id="theme-toggle" type="button"
                    class="text-icon hover:bg-gray-500/10 rounded-lg text-sm p-2.5">
                    <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path>
                    </svg>
                    <svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z"
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
                        <path d="M10.75 4.75a.75.75 0 00-1.5 0v4.5h-4.5a.75.75 0 000 1.5h4.5v4.5a.75.75 0 001.5 0v-4.5h4.5a.75.75 0 000-1.5h-4.5v-4.5z" />
                    </svg>
                    Task Baru
                </button>

                <div class="relative" id="profile-menu">
                    <button class="flex items-center space-x-2 focus:outline-none">
                        <img src="uploads/<?php echo htmlspecialchars($user_details['profile_picture']); ?>"
                            alt="Avatar"
                            class="w-9 h-9 rounded-full object-cover border-2 border-transparent hover:border-blue-500 transition">
                        <span class="text-sm font-medium hidden md:block text-header"><?php echo htmlspecialchars($username); ?></span>
                    </button>
                    <div id="profile-dropdown"
                        class="hidden absolute right-0 mt-2 w-48 bg-gray-800 rounded-lg shadow-lg py-1 z-50 border border-gray-700">
                        <div class="px-4 py-3 border-b border-gray-700">
                            <p class="text-sm font-semibold text-white"><?php echo htmlspecialchars($username); ?></p>
                            <p class="text-xs text-gray-400 truncate"><?php echo htmlspecialchars($user_details['email'] ?? ''); ?></p>
                        </div>
                        <a href="profile.php"
                            class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white">Profil Saya</a>
                        <a href="ga_submission_tracker.php"
                            class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white">Reason OT</a>
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

<!-- Speed Dial Chatbot Widget -->
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
#hermes-chat-toggle:hover { transform: scale(1.08); box-shadow: 0 6px 24px rgba(79, 70, 229, 0.7); }
#hermes-chat-toggle:active { transform: scale(0.95); }
#hermes-chat-toggle svg { width: 24px; height: 24px; flex-shrink: 0; }
#hermes-pulse-ring {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: rgba(99, 102, 241, 0.4);
    animation: hermesPing 2s cubic-bezier(0,0,0.2,1) infinite;
}
#hermes-chat-toggle.chat-open #hermes-pulse-ring { display: none; }
@keyframes hermesPing {
    75%, 100% { transform: scale(2); opacity: 0; }
}
#hermes-chat-window {
    pointer-events: auto;
    width: 380px;
    height: 560px;
    max-width: calc(100vw - 32px);
    max-height: calc(100vh - 120px);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5), 0 0 0 1px rgba(51,65,85,0.5);
    background: #09090b;
    margin-bottom: 12px;
    transform-origin: bottom right;
    transition: transform 0.25s cubic-bezier(0.16,1,0.3,1), opacity 0.25s ease;
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
#hermes-chat-iframe {
    width: 100%;
    height: 100%;
    border: none;
    display: block;
}
</style>

<div id="hermes-chat-widget">
    <div id="hermes-chat-window" class="hermes-hidden">
        <iframe id="hermes-chat-iframe" src="about:blank"></iframe>
    </div>
    <button id="hermes-chat-toggle" title="Chat dengan Hermes AI">
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

<script>
(function() {
    var CHAT_URL = 'https://ai.endrisusanto.my.id/';
    var loaded = false;
    var isOpen = false;

    var toggleBtn  = document.getElementById('hermes-chat-toggle');
    var chatWindow = document.getElementById('hermes-chat-window');
    var iframe     = document.getElementById('hermes-chat-iframe');
    var iconChat   = document.getElementById('hermes-icon-chat');
    var iconClose  = document.getElementById('hermes-icon-close');

    toggleBtn.addEventListener('click', function() {
        isOpen = !isOpen;
        if (isOpen) {
            // ponytail: lazy load iframe only on first open
            if (!loaded) { iframe.src = CHAT_URL; loaded = true; }
            chatWindow.classList.remove('hermes-hidden');
            chatWindow.classList.add('hermes-visible');
            toggleBtn.classList.add('chat-open');
            iconChat.style.display = 'none';
            iconClose.style.display = '';
        } else {
            chatWindow.classList.remove('hermes-visible');
            chatWindow.classList.add('hermes-hidden');
            toggleBtn.classList.remove('chat-open');
            iconChat.style.display = '';
            iconClose.style.display = 'none';
        }
    });
})();
</script>