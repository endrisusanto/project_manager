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

if (!function_exists('get_header_bas_status')) {
    function get_header_bas_status() {
        global $conn;
        $candidate_paths = [
            __DIR__ . '/.bas_session.json',
            sys_get_temp_dir() . '/.bas_session.json',
            '/var/www/html/.bas_session.json',
            '/home/endri-pro/dev/App/project_manager/.bas_session.json',
            '/opt/lampp/htdocs/project_manager/.bas_session.json'
        ];
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $candidate_paths[] = 'C:/xampp/htdocs/project_manager/.bas_session.json';
            $candidate_paths[] = 'D:/xampp/htdocs/project_manager/.bas_session.json';
        }

        $best_data = null;
        $best_ts = 0;

        foreach ($candidate_paths as $sp) {
            if (file_exists($sp) && is_readable($sp)) {
                $raw = @file_get_contents($sp);
                $parsed = json_decode($raw, true);
                if ($parsed && !empty($parsed['sid'])) {
                    $ts = $parsed['timestamp'] ?? 0;
                    if ($ts > $best_ts) {
                        $best_ts = $ts;
                        $best_data = $parsed;
                    }
                }
            }
        }

        if ((!$best_data || (time() - $best_ts > 8 * 3600)) && isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
            try {
                $conn->query("CREATE TABLE IF NOT EXISTS `system_settings` (
                    `setting_key` VARCHAR(100) PRIMARY KEY,
                    `setting_value` LONGTEXT NOT NULL,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

                $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bas_session' LIMIT 1");
                if ($res && $row = $res->fetch_assoc()) {
                    $db_data = json_decode($row['setting_value'], true);
                    if ($db_data && !empty($db_data['sid'])) {
                        $db_ts = $db_data['timestamp'] ?? 0;
                        if ($db_ts > $best_ts) {
                            $best_ts = $db_ts;
                            $best_data = $db_data;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Table doesn't exist yet or query failed - gracefully fallback to local file
            }
        }

        if ($best_data && !empty($best_data['sid'])) {
            $age_sec = time() - $best_ts;
            $mins = max(1, round($age_sec / 60));
            $is_active = $age_sec < (8 * 3600);
            $updated_time = $best_data['updated_at'] ?? (isset($best_data['timestamp']) ? date('Y-m-d H:i', $best_data['timestamp']) : '-');
            $time_label = ($mins < 60) ? "{$mins}m ago" : round($mins / 60, 1) . "h ago";
            return [
                'active' => $is_active,
                'status' => $is_active ? 'Active' : 'Expired',
                'detail' => $time_label,
                'updated_at' => $updated_time
            ];
        }

        return [
            'active' => false,
            'status' => 'Disconnected',
            'detail' => 'No Token',
            'updated_at' => '-'
        ];
    }
}
$hdr_bas_status = get_header_bas_status();
?>

<?php if (in_array($active_page, ['project_dashboard', 'gba_tasks', 'gba_tasks_summary'])): ?>
    <!-- ponytail: Spotlight Search Overlay — shared across pages via header.php -->
    <style>
        html.disable-canvas-animation #neural-canvas {
            display: none !important;
        }

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
            pointer-events: none !important;
            visibility: hidden;
            transition: opacity 0.18s ease, visibility 0.18s ease, padding-bottom 0.28s cubic-bezier(0.16, 1, 0.3, 1);
        }

        /* Emil Design Eng + Better UI: Dynamic vertical shift when bulk action bar is active */
        body.has-bulk-bar #spotlight-overlay,
        body:has(#bulk-action-bar.active) #spotlight-overlay {
            padding-bottom: 96px;
        }

        #spotlight-overlay.sl-open {
            opacity: 1;
            pointer-events: auto !important;
            visibility: visible;
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
                if (char && char.length === 1) {
                    slInput.value = char;
                    sync();
                }
                slInput.focus({ preventScroll: true });
                var len = slInput.value.length;
                slInput.setSelectionRange(len, len);

                requestAnimationFrame(function () {
                    slInput.focus({ preventScroll: true });
                    var l = slInput.value.length;
                    slInput.setSelectionRange(l, l);
                });
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

            // Focus retention inside box
            box.addEventListener('click', function (e) {
                if (!e.target.closest('button, a, input')) {
                    slInput.focus();
                }
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

                // If overlay is already open
                if (overlay.classList.contains('sl-open')) {
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        close();
                        return;
                    }
                    // If focus was lost outside slInput, recover and append typed character
                    if (document.activeElement !== slInput && !e.ctrlKey && !e.metaKey && !e.altKey && e.key.length === 1) {
                        e.preventDefault();
                        slInput.value += e.key;
                        sync();
                        slInput.focus();
                        var l = slInput.value.length;
                        slInput.setSelectionRange(l, l);
                    }
                    return;
                }

                // Skip if focus is in any input/textarea
                var active = document.activeElement;
                var tag = active ? active.tagName : '';
                if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
                if (active && active.isContentEditable) return;
                
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

<!-- ponytail: Phase 1 Unified Design Tokens for Header & Navigation -->
<style>
    /* Better-UI & Emil-Design-Eng Header Styles */
    .app-header {
        background: rgba(15, 23, 42, 0.85);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        transition: background 0.2s ease, border-color 0.2s ease;
    }
    html.light .app-header {
        background: rgba(255, 255, 255, 0.85);
        border-bottom: 1px solid rgba(0, 0, 0, 0.06);
    }

    /* Segmented Navigation Links */
    .nav-pill-group {
        display: flex;
        align-items: center;
        gap: 4px;
        background: rgba(0, 0, 0, 0.15);
        padding: 4px;
        border-radius: 12px;
        border: 1px solid rgba(255, 255, 255, 0.05);
    }
    html.light .nav-pill-group {
        background: rgba(0, 0, 0, 0.03);
        border-color: rgba(0, 0, 0, 0.05);
    }

    .nav-link {
        display: inline-flex;
        align-items: center;
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 500;
        color: #94a3b8;
        border: 1px solid transparent;
        text-decoration: none;
        transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
        white-space: nowrap;
    }
    .nav-link:hover {
        color: #f8fafc;
        background: rgba(255, 255, 255, 0.07);
    }
    .nav-link:active {
        transform: scale(0.97);
    }
    html.light .nav-link {
        color: #64748b;
    }
    html.light .nav-link:hover {
        color: #0f172a;
        background: rgba(0, 0, 0, 0.05);
    }

    .nav-link-active {
        display: inline-flex;
        align-items: center;
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
        color: #38bdf8 !important;
        background: rgba(56, 189, 248, 0.12) !important;
        border: 1px solid rgba(56, 189, 248, 0.28) !important;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
    }
    html.light .nav-link-active {
        color: #0284c7 !important;
        background: rgba(14, 165, 233, 0.1) !important;
        border: 1px solid rgba(14, 165, 233, 0.25) !important;
    }

    /* Refined Action Buttons */
    .hdr-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 7px 13px;
        font-size: 13px;
        font-weight: 600;
        border-radius: 10px;
        transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
        cursor: pointer;
        text-decoration: none;
        white-space: nowrap;
        line-height: 1;
    }
    .hdr-btn:active {
        transform: scale(0.97);
    }

    .hdr-btn-primary {
        background: linear-gradient(135deg, #2563eb, #3b82f6);
        color: #ffffff;
        box-shadow: 0 2px 8px rgba(37, 99, 235, 0.35);
        border: 1px solid rgba(255, 255, 255, 0.15);
    }
    .hdr-btn-primary:hover {
        background: linear-gradient(135deg, #1d4ed8, #2563eb);
        box-shadow: 0 4px 14px rgba(37, 99, 235, 0.5);
    }

    .hdr-btn-emerald {
        background: rgba(16, 185, 129, 0.12);
        color: #34d399;
        border: 1px solid rgba(16, 185, 129, 0.28);
    }
    .hdr-btn-emerald:hover {
        background: rgba(16, 185, 129, 0.22);
        color: #6ee7b7;
        border-color: rgba(16, 185, 129, 0.45);
    }
    html.light .hdr-btn-emerald {
        background: #ecfdf5;
        color: #047857;
        border-color: #a7f3d0;
    }
    html.light .hdr-btn-emerald:hover {
        background: #d1fae5;
        color: #065f46;
    }

    .hdr-btn-purple {
        background: rgba(168, 85, 247, 0.12);
        color: #c084fc;
        border: 1px solid rgba(168, 85, 247, 0.28);
    }
    .hdr-btn-purple:hover {
        background: rgba(168, 85, 247, 0.22);
        color: #d8b4fe;
        border-color: rgba(168, 85, 247, 0.45);
    }
    html.light .hdr-btn-purple {
        background: #faf5ff;
        color: #7e22ce;
        border-color: #e9d5ff;
    }
    .hdr-btn-purple:hover {
        background: #f3e8ff;
        color: #6b21a8;
    }

    /* BAS Status Badge Styles */
    .hdr-btn-bas-active {
        background: rgba(16, 185, 129, 0.12);
        color: #34d399;
        border: 1px solid rgba(16, 185, 129, 0.28);
    }
    .hdr-btn-bas-active:hover {
        background: rgba(16, 185, 129, 0.22);
        color: #6ee7b7;
        border-color: rgba(16, 185, 129, 0.45);
    }
    html.light .hdr-btn-bas-active {
        background: #ecfdf5;
        color: #047857;
        border-color: #a7f3d0;
    }
    html.light .hdr-btn-bas-active:hover {
        background: #d1fae5;
        color: #065f46;
    }

    .hdr-btn-bas-expired {
        background: rgba(245, 158, 11, 0.12);
        color: #fbbf24;
        border: 1px solid rgba(245, 158, 11, 0.28);
    }
    .hdr-btn-bas-expired:hover {
        background: rgba(245, 158, 11, 0.22);
        color: #fcd34d;
        border-color: rgba(245, 158, 11, 0.45);
    }
    html.light .hdr-btn-bas-expired {
        background: #fffbeb;
        color: #b45309;
        border-color: #fde68a;
    }
    html.light .hdr-btn-bas-expired:hover {
        background: #fef3c7;
        color: #92400e;
    }

    .hdr-btn-bas-inactive {
        background: rgba(244, 63, 94, 0.10);
        color: #fb7185;
        border: 1px solid rgba(244, 63, 94, 0.25);
    }
    .hdr-btn-bas-inactive:hover {
        background: rgba(244, 63, 94, 0.18);
        color: #fda4af;
        border-color: rgba(244, 63, 94, 0.40);
    }
    html.light .hdr-btn-bas-inactive {
        background: #fff1f2;
        color: #be123c;
        border-color: #fecdd3;
    }
    html.light .hdr-btn-bas-inactive:hover {
        background: #ffe4e6;
        color: #9f1239;
    }

    .hdr-icon-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 10px;
        color: #94a3b8;
        border: 1px solid transparent;
        background: transparent;
        transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
        cursor: pointer;
    }
    .hdr-icon-btn:hover {
        background: rgba(255, 255, 255, 0.08);
        color: #f8fafc;
        border-color: rgba(255, 255, 255, 0.1);
    }
    .hdr-icon-btn:active {
        transform: scale(0.95);
    }
    html.light .hdr-icon-btn {
        color: #64748b;
    }
    html.light .hdr-icon-btn:hover {
        background: rgba(0, 0, 0, 0.05);
        color: #0f172a;
        border-color: rgba(0, 0, 0, 0.08);
    }

    /* Profile Dropdown with Emil-physics transition & Dark/Light adaptability */
    #profile-btn-trigger {
        color: #e2e8f0;
    }
    html.light #profile-btn-trigger {
        color: #0f172a;
    }
    #profile-btn-trigger:hover {
        background: rgba(255, 255, 255, 0.06);
    }
    html.light #profile-btn-trigger:hover {
        background: rgba(0, 0, 0, 0.04);
    }
    #profile-status-ring {
        box-shadow: 0 0 0 2px #0f172a;
    }
    html.light #profile-status-ring {
        box-shadow: 0 0 0 2px #ffffff;
    }

    .profile-dropdown-panel {
        background: rgba(15, 23, 42, 0.96);
        border: 1px solid rgba(255, 255, 255, 0.09);
        box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.05);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
    }
    html.light .profile-dropdown-panel {
        background: rgba(255, 255, 255, 0.96);
        border: 1px solid rgba(0, 0, 0, 0.08);
        box-shadow: 0 20px 45px -10px rgba(15, 23, 42, 0.15), 0 0 0 1px rgba(0, 0, 0, 0.04);
    }

    .profile-user-card {
        background: rgba(30, 41, 59, 0.6);
        border: 1px solid rgba(51, 65, 85, 0.4);
    }
    html.light .profile-user-card {
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
    }
    .profile-username {
        color: #f8fafc;
    }
    html.light .profile-username {
        color: #0f172a;
    }
    .profile-email {
        color: #94a3b8;
    }
    html.light .profile-email {
        color: #64748b;
    }

    .profile-nav-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        font-size: 12px;
        font-weight: 500;
        color: #cbd5e1;
        border-radius: 8px;
        text-decoration: none;
        transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .profile-nav-item svg {
        color: #94a3b8;
        transition: color 0.15s ease;
    }
    .profile-nav-item:hover {
        background: rgba(255, 255, 255, 0.07);
        color: #ffffff;
    }
    .profile-nav-item:hover svg {
        color: #38bdf8;
    }
    html.light .profile-nav-item {
        color: #334155;
    }
    html.light .profile-nav-item svg {
        color: #64748b;
    }
    html.light .profile-nav-item:hover {
        background: rgba(0, 0, 0, 0.05);
        color: #0f172a;
    }
    html.light .profile-nav-item:hover svg {
        color: #0284c7;
    }

    .profile-divider-line {
        margin: 4px 0;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
    }
    html.light .profile-divider-line {
        border-top-color: rgba(0, 0, 0, 0.06);
    }

    .profile-logout-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        font-size: 12px;
        font-weight: 500;
        color: #fb7185;
        border-radius: 8px;
        text-decoration: none;
        transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .profile-logout-item:hover {
        background: rgba(244, 63, 94, 0.12);
        color: #fda4af;
    }
    html.light .profile-logout-item {
        color: #e11d48;
    }
    html.light .profile-logout-item:hover {
        background: #ffe4e6;
        color: #be123c;
    }

    #profile-dropdown:not(.hidden) {
        display: block !important;
        animation: profileDropIn 0.18s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        transform-origin: top right;
    }
    @keyframes profileDropIn {
        0% {
            opacity: 0;
            transform: scale(0.95) translateY(-6px);
        }
        100% {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
    }
</style>

<header class="app-header sticky top-0 z-30 shadow-sm flex-shrink-0">
    <div class="w-full mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <!-- Left: Navigation Segmented Control -->
            <div class="flex items-center">
                <nav class="hidden md:flex nav-pill-group" aria-label="Main Navigation">
                    <a href="index.php"
                        class="<?php echo ($active_page === 'project_dashboard') ? 'nav-link-active' : 'nav-link'; ?>">Kanban</a>
                    <a href="gba_dashboard.php"
                        class="<?php echo ($active_page === 'gba_dashboard') ? 'nav-link-active' : 'nav-link'; ?>">Dashboard</a>
                    <a href="monthly_calendar.php"
                        class="<?php echo ($active_page === 'monthly_calendar') ? 'nav-link-active' : 'nav-link'; ?>">Calendar</a>
                    <a href="project_roadmap.php"
                        class="<?php echo ($active_page === 'project_roadmap') ? 'nav-link-active' : 'nav-link'; ?>">Roadmap</a>
                    <a href="gba_tasks.php"
                        class="<?php echo ($active_page === 'gba_tasks') ? 'nav-link-active' : 'nav-link'; ?>">Active Tasks</a>
                    <a href="gba_tasks_summary.php"
                        class="<?php echo ($active_page === 'gba_tasks_summary') ? 'nav-link-active' : 'nav-link'; ?>">Summary</a>
                    <a href="activity_log.php"
                        class="<?php echo ($active_page === 'activity_log') ? 'nav-link-active' : 'nav-link'; ?>">Log</a>
                    <a href="mcp_features.php"
                        class="<?php echo ($active_page === 'mcp_features') ? 'nav-link-active' : 'nav-link'; ?>">
                        <span class="inline-flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                            MCP Hub
                        </span>
                    </a>
                </nav>
            </div>

            <!-- Right: Actions, Search, View, Theme, Profile -->
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
                    <button id="view-toggle" type="button" class="hdr-icon-btn" title="Ganti Mode Tampilan">
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

                <button id="theme-toggle" type="button" class="hdr-icon-btn" title="Ganti Tema (Dark/Light)">
                    <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path>
                    </svg>
                    <svg id="theme-toggle-light-icon" class="hidden w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path
                            d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z"
                            fill-rule="evenodd" clip-rule="evenodd"></path>
                    </svg>
                </button>

                <!-- BAS Connection Status Badge -->
                <a href="ga_submission_tracker.php"
                    id="header-bas-badge"
                    class="hdr-btn <?= $hdr_bas_status['active'] ? 'hdr-btn-bas-active' : ($hdr_bas_status['status'] === 'Expired' ? 'hdr-btn-bas-expired' : 'hdr-btn-bas-inactive') ?>"
                    title="BAS Token Status: <?= htmlspecialchars($hdr_bas_status['status']) ?> (Last Update: <?= htmlspecialchars($hdr_bas_status['updated_at']) ?>). Klik untuk buka Reason OT / BAS Tracker.">
                    <span class="relative flex h-2 w-2">
                        <span id="header-bas-ping" class="animate-ping absolute inline-flex h-full w-full rounded-full <?= $hdr_bas_status['active'] ? 'bg-emerald-400 opacity-75' : 'hidden' ?>"></span>
                        <span id="header-bas-dot" class="relative inline-flex rounded-full h-2 w-2 <?= $hdr_bas_status['active'] ? 'bg-emerald-400' : ($hdr_bas_status['status'] === 'Expired' ? 'bg-amber-400' : 'bg-rose-400') ?>"></span>
                    </span>
                    <span class="text-xs font-semibold" id="header-bas-text">
                        BAS: <span id="header-bas-status-label"><?= htmlspecialchars($hdr_bas_status['status']) ?></span>
                    </span>
                    <span class="text-[11px] opacity-80 hidden lg:inline" id="header-bas-detail">(<?= htmlspecialchars($hdr_bas_status['detail']) ?>)</span>
                </a>

                <a href="smart_filter.php"
                    class="hdr-btn hdr-btn-purple <?= ($active_page == 'smart_filter') ? 'active' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                        class="w-4 h-4">
                        <path fill-rule="evenodd"
                            d="M2.628 1.601C5.028 1.206 7.49 1 10 1s4.973.206 7.372.601a.75.75 0 0 1 .628.74v2.288a2.25 2.25 0 0 1-.659 1.59l-4.682 4.683a2.25 2.25 0 0 0-.659 1.59v3.037c0 .684-.31 1.33-.844 1.757l-1.937 1.55A.75.75 0 0 1 8 18.25v-5.757a2.25 2.25 0 0 0-.659-1.59L2.659 6.22A2.25 2.25 0 0 1 2 4.629V2.34a.75.75 0 0 1 .628-.74Z"
                            clip-rule="evenodd" />
                    </svg>
                    <span>Smart Filter</span>
                </a>

                <a href="bulk_add.php"
                    class="hdr-btn hdr-btn-emerald">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.125 1.125 0 010 2.25H5.625a1.125 1.125 0 010-2.25z" />
                    </svg>
                    <span>Bulk Add</span>
                </a>

                <button onclick="openAddModal()"
                    class="hdr-btn hdr-btn-primary">
                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    <span>Task Baru</span>
                </button>

                <!-- Profile Menu -->
                <div class="relative" id="profile-menu">
                    <button id="profile-btn-trigger" class="flex items-center gap-2 p-1.5 rounded-xl transition-all focus:outline-none active:scale-95" aria-haspopup="true" aria-expanded="false">
                        <div class="relative flex-shrink-0">
                            <img src="uploads/<?php echo htmlspecialchars($user_details['profile_picture']); ?>"
                                alt="Avatar"
                                class="w-8 h-8 rounded-full object-cover ring-2 ring-blue-500/30">
                            <span id="profile-status-ring" class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-400"></span>
                        </div>
                        <span
                            class="text-xs font-semibold hidden md:block"><?php echo htmlspecialchars($username); ?></span>
                        <svg class="w-3.5 h-3.5 opacity-70 hidden md:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>
                    <div id="profile-dropdown"
                        class="hidden absolute right-0 mt-2 w-56 profile-dropdown-panel rounded-2xl p-1.5 z-50">
                        <div class="px-3 py-2.5 mb-1 rounded-xl profile-user-card">
                            <p class="text-xs font-bold profile-username"><?php echo htmlspecialchars($username); ?></p>
                            <p class="text-[11px] profile-email truncate">
                                <?php echo htmlspecialchars($user_details['email'] ?? ''); ?></p>
                        </div>
                        <a href="profile.php" class="profile-nav-item">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                            <span>Profil Saya</span>
                        </a>
                        <a href="ga_submission_tracker.php" class="profile-nav-item">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
                            <span>Reason OT</span>
                        </a>
                        <a href="monthly_calendar.php" class="profile-nav-item">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                            <span>Kalender</span>
                        </a>
                        <a href="daily_report_summary.php" class="profile-nav-item text-indigo-400 dark:text-indigo-300 hover:text-indigo-500">
                            <svg class="w-4 h-4 flex-shrink-0 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                            <span class="font-medium">Daily Insight Report</span>
                        </a>
                        <a href="weekly_report_summary.php" class="profile-nav-item text-emerald-400 dark:text-emerald-300 hover:text-emerald-500">
                            <svg class="w-4 h-4 flex-shrink-0 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zM9 14l2 2 4-4" /></svg>
                            <span class="font-medium">Weekly Insight Report</span>
                        </a>

                        <?php if (function_exists('is_endri_or_admin') && is_endri_or_admin()): ?>
                        <div class="profile-divider-line"></div>
                        <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">Admin Tools</div>
                        <a href="update_database_names.php" class="profile-nav-item text-blue-400 dark:text-blue-300 hover:text-blue-500">
                            <svg class="w-4 h-4 flex-shrink-0 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" /></svg>
                            <span>Sync Marketing Names</span>
                        </a>
                        <a href="edit_mapping.php" class="profile-nav-item">
                            <svg class="w-4 h-4 flex-shrink-0 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                            <span>Edit Model Mapping</span>
                        </a>
                        <?php endif; ?>
                        <div class="profile-divider-line"></div>
                        <a href="logout.php" class="profile-logout-item">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<script>
// ponytail: Universal Centralized Dark/Light Theme Controller & Profile Dropdown
(function() {
    if (localStorage.getItem('disable_canvas_animation') === 'true') {
        document.documentElement.classList.add('disable-canvas-animation');
    }
    function updateThemeIcons(isLight) {
        var lightIcon = document.getElementById('theme-toggle-light-icon');
        var darkIcon = document.getElementById('theme-toggle-dark-icon');
        if (lightIcon && darkIcon) {
            if (isLight) {
                lightIcon.classList.remove('hidden');
                darkIcon.classList.add('hidden');
            } else {
                lightIcon.classList.add('hidden');
                darkIcon.classList.remove('hidden');
            }
        }
    }

    function applyTheme(isLight) {
        if (isLight) {
            document.documentElement.classList.add('light');
            document.documentElement.classList.remove('dark');
            localStorage.setItem('theme', 'light');
        } else {
            document.documentElement.classList.remove('light');
            document.documentElement.classList.add('dark');
            localStorage.setItem('theme', 'dark');
        }
        updateThemeIcons(isLight);
        window.dispatchEvent(new CustomEvent('themechanged', { detail: { isLight: isLight, theme: isLight ? 'light' : 'dark' } }));
    }

    document.addEventListener('DOMContentLoaded', function() {
        var currentIsLight = localStorage.getItem('theme') === 'light';
        updateThemeIcons(currentIsLight);

        var toggleBtn = document.getElementById('theme-toggle');
        if (toggleBtn) {
            toggleBtn.onclick = function(e) {
                e.preventDefault();
                var newIsLight = !document.documentElement.classList.contains('light');
                applyTheme(newIsLight);
            };
        }

        // Profile Menu Dropdown
        var profileMenu = document.getElementById('profile-menu');
        if (!profileMenu) return;
        var btnTrigger = profileMenu.querySelector('button');
        var dropdown = document.getElementById('profile-dropdown');
        if (!btnTrigger || !dropdown) return;

        var lastToggle = 0;
        btnTrigger.addEventListener('click', function(e) {
            var now = Date.now();
            if (now - lastToggle < 50) return;
            lastToggle = now;
            e.stopPropagation();
            dropdown.classList.toggle('hidden');
        }, true);

        document.addEventListener('click', function(e) {
            if (!profileMenu.contains(e.target)) {
                dropdown.classList.add('hidden');
            }
        });
    });
})();
</script>

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

        // --- Live BAS Status Polling / Focus Refresh ---
        function updateHeaderBasBadge() {
            fetch('api_bas_bridge.php', { cache: 'no-store' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    var badge = document.getElementById('header-bas-badge');
                    var ping = document.getElementById('header-bas-ping');
                    var dot = document.getElementById('header-bas-dot');
                    var statusLabel = document.getElementById('header-bas-status-label');
                    var detail = document.getElementById('header-bas-detail');
                    if (!badge || !dot || !statusLabel) return;

                    var isActive = res.active === true;
                    var status = res.status ? (res.status.charAt(0).toUpperCase() + res.status.slice(1)) : (isActive ? 'Active' : 'Disconnected');
                    var ageHours = res.age_hours !== undefined ? res.age_hours : null;
                    var timeLabel = 'No Token';

                    if (ageHours !== null) {
                        var mins = Math.max(1, Math.round(ageHours * 60));
                        timeLabel = mins < 60 ? (mins + 'm ago') : (ageHours + 'h ago');
                    }

                    statusLabel.textContent = status;
                    if (detail) detail.textContent = '(' + timeLabel + ')';

                    badge.className = 'hdr-btn ' + (isActive ? 'hdr-btn-bas-active' : (status === 'Expired' ? 'hdr-btn-bas-expired' : 'hdr-btn-bas-inactive'));
                    
                    if (ping) {
                        if (isActive) {
                            ping.className = 'animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75';
                        } else {
                            ping.className = 'hidden';
                        }
                    }

                    if (dot) {
                        dot.className = 'relative inline-flex rounded-full h-2 w-2 ' + (isActive ? 'bg-emerald-400' : (status === 'Expired' ? 'bg-amber-400' : 'bg-rose-400'));
                    }

                    badge.title = 'BAS Token Status: ' + status + ' (Last Update: ' + (res.updated_at || '-') + '). Klik untuk buka Reason OT / BAS Tracker.';
                })
                .catch(function() {});
        }

        window.addEventListener('focus', updateHeaderBasBadge);
        setInterval(updateHeaderBasBadge, 30000); // 30s auto-refresh
    })();
</script>