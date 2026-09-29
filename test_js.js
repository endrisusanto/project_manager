if(localStorage.getItem('theme')==='light')document.documentElement.classList.add('light');

        tailwind.config = {
            darkMode: ['class', '.never-match-dark']
        }
    

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
        var mLightIcon = document.getElementById('mobile-theme-light-icon');
        var mDarkIcon = document.getElementById('mobile-theme-dark-icon');
        var mThemeLabel = document.getElementById('mobile-theme-label');
        if (mLightIcon && mDarkIcon) {
            if (isLight) {
                mLightIcon.classList.remove('hidden');
                mDarkIcon.classList.add('hidden');
                if (mThemeLabel) mThemeLabel.textContent = 'Light Mode';
            } else {
                mLightIcon.classList.add('hidden');
                mDarkIcon.classList.remove('hidden');
                if (mThemeLabel) mThemeLabel.textContent = 'Dark Mode';
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

        var mToggleBtn = document.getElementById('mobile-theme-toggle');
        if (mToggleBtn) {
            mToggleBtn.onclick = function(e) {
                e.preventDefault();
                var newIsLight = !document.documentElement.classList.contains('light');
                applyTheme(newIsLight);
            };
        }

        // Mobile Nav Drawer Toggle
        var mobileNavToggle = document.getElementById('mobile-nav-toggle');
        var mobileNavDrawer = document.getElementById('mobile-nav-drawer');
        var mobileNavClose = document.getElementById('mobile-nav-close');
        var mobileNavBackdrop = document.getElementById('mobile-nav-backdrop');

        function openMobileNav() {
            if (mobileNavDrawer) mobileNavDrawer.classList.add('nav-open');
        }
        function closeMobileNav() {
            if (mobileNavDrawer) mobileNavDrawer.classList.remove('nav-open');
        }
        window.openMobileNav = openMobileNav;
        window.closeMobileNav = closeMobileNav;

        if (mobileNavToggle) mobileNavToggle.addEventListener('click', openMobileNav);
        if (mobileNavClose) mobileNavClose.addEventListener('click', closeMobileNav);
        if (mobileNavBackdrop) mobileNavBackdrop.addEventListener('click', closeMobileNav);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && mobileNavDrawer && mobileNavDrawer.classList.contains('nav-open')) {
                closeMobileNav();
            }
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth >= 768 && mobileNavDrawer && mobileNavDrawer.classList.contains('nav-open')) {
                closeMobileNav();
            }
        });

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
        var userNameForChat = 'User';
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
                    user: 'User',
                    role: 'admin'
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

                    badge.className = 'hdr-btn hdr-desktop-only ' + (isActive ? 'hdr-btn-bas-active' : (status === 'Expired' ? 'hdr-btn-bas-expired' : 'hdr-btn-bas-inactive'));
                    
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

                    badge.title = 'BAS Token Status: ' + status + ' (Last Update: ' + (res.updated_at || '-') + '). Klik untuk membuka modal Live Sync & Breadcrumb Fetcher.';
                })
                .catch(function() {});
        }

        window.addEventListener('focus', updateHeaderBasBadge);
        setInterval(updateHeaderBasBadge, 30000); // 30s auto-refresh
    })();


(function() {
    var isSyncing = false;

    window.openBasSyncModal = function() {
        var modal = document.getElementById('bas-sync-modal');
        if (!modal) return;
        modal.classList.add('active');
        checkBasInitialBridgeState();
    };

    window.closeBasSyncModal = function() {
        var modal = document.getElementById('bas-sync-modal');
        if (!modal) return;
        modal.classList.remove('active');
    };

    window.clearBasConsole = function() {
        var consoleBox = document.getElementById('bas-console-box');
        if (consoleBox) consoleBox.innerHTML = '';
    };

    function appendBasLog(tag, type, message) {
        var consoleBox = document.getElementById('bas-console-box');
        if (!consoleBox) return;

        var now = new Date();
        var ts = now.toTimeString().split(' ')[0];

        var tagClass = 'bas-tag-info';
        if (type === 'session') tagClass = 'bas-tag-session';
        else if (type === 'fetch') tagClass = 'bas-tag-fetch';
        else if (type === 'match') tagClass = 'bas-tag-match';
        else if (type === 'success') tagClass = 'bas-tag-success';
        else if (type === 'error') tagClass = 'bas-tag-error';

        var row = document.createElement('div');
        row.className = 'bas-log-entry';
        row.innerHTML = '<span class="bas-log-ts">' + ts + '</span>' +
                        '<span class="bas-log-tag ' + tagClass + '">' + tag + '</span>' +
                        '<span>' + message + '</span>';

        consoleBox.appendChild(row);
        consoleBox.scrollTop = consoleBox.scrollHeight;
    }

    function setStepState(stepNum, state, subtitle) {
        var item = document.getElementById('bas-step-' + stepNum);
        var node = document.getElementById('bas-node-' + stepNum);
        var sub = document.getElementById('bas-sub-' + stepNum);
        if (!item || !node) return;

        item.classList.remove('is-running', 'is-done', 'is-error');

        if (state === 'running') {
            item.classList.add('is-running');
            node.innerHTML = '<svg class="w-4 h-4 animate-spin text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>';
        } else if (state === 'done') {
            item.classList.add('is-done');
            node.innerHTML = '<svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>';
        } else if (state === 'error') {
            item.classList.add('is-error');
            node.innerHTML = '<svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>';
        } else {
            node.textContent = stepNum;
        }

        if (subtitle && sub) {
            sub.textContent = subtitle;
        }
    }

    function setTrackProgress(percent) {
        var bar = document.getElementById('bas-track-progress');
        if (bar) bar.style.width = percent + '%';
    }

    function checkBasInitialBridgeState() {
        var dot = document.getElementById('bas-metric-dot');
        var statusLabel = document.getElementById('bas-metric-status');
        var badge = document.getElementById('bas-modal-badge');

        fetch('api_bas_bridge.php', { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                var isActive = res.active === true;
                var st = res.status ? (res.status.charAt(0).toUpperCase() + res.status.slice(1)) : (isActive ? 'Active' : 'Disconnected');
                var age = res.age_hours !== undefined ? (res.age_hours + 'h') : '-';

                if (statusLabel) statusLabel.textContent = st + ' (' + age + ')';
                if (badge) {
                    badge.textContent = st;
                    badge.className = 'text-[10px] font-medium px-2 py-0.5 rounded ' + (isActive ? 'bg-emerald-500/15 text-emerald-500 dark:text-emerald-400 border border-emerald-500/20' : (st === 'Expired' ? 'bg-amber-500/15 text-amber-500 dark:text-amber-400 border border-amber-500/20' : 'bg-rose-500/15 text-rose-500 dark:text-rose-400 border border-rose-500/20'));
                }
                if (dot) {
                    dot.className = 'w-2 h-2 rounded-full ' + (isActive ? 'bg-emerald-500' : (st === 'Expired' ? 'bg-amber-500' : 'bg-rose-500'));
                }

                if (isActive) {
                    setStepState(1, 'done', 'Token Aktif');
                    setTrackProgress(25);
                } else if (st === 'Expired') {
                    setStepState(1, 'error', 'Token Expired');
                    setTrackProgress(25);
                } else {
                    setStepState(1, 'idle', 'Belum Konek');
                    setTrackProgress(0);
                }
            })
            .catch(function(err) {
                if (statusLabel) statusLabel.textContent = 'Offline';
            });
    }

    window.runBasSyncProcess = function() {
        if (isSyncing) return;
        isSyncing = true;

        var btn = document.getElementById('btn-run-bas-sync');
        var btnIcon = document.getElementById('btn-run-sync-icon');
        var btnText = document.getElementById('btn-run-sync-text');
        var badge = document.getElementById('bas-modal-badge');

        if (btn) btn.disabled = true;
        if (btnIcon) btnIcon.classList.add('animate-spin');
        if (btnText) btnText.textContent = 'Sedang Sinkron...';
        if (badge) {
            badge.textContent = 'Syncing...';
            badge.className = 'text-[10px] font-medium px-2 py-0.5 rounded bg-blue-500/15 text-blue-400 border border-blue-500/20';
        }

        // Step 1: Session Verification
        setStepState(1, 'running', 'Verifikasi SID...');
        setStepState(2, 'idle', 'Menunggu...');
        setStepState(3, 'idle', 'Menunggu...');
        setStepState(4, 'idle', 'Menunggu...');
        setTrackProgress(15);
        appendBasLog('SESSION', 'session', 'Memeriksa token session BAS dan bridge XAMPP/Port 80...');

        setTimeout(function() {
            setStepState(1, 'done', 'Session Valid');
            setStepState(2, 'running', 'Mengambil Submissions...');
            setTrackProgress(45);
            appendBasLog('BAS API', 'fetch', 'Mengirim query Search Submissions (Awal Bulan - Today, Carrier: XID, KST +09:00)...');

            var startTime = performance.now();

            fetch('sync_bas.php', { method: 'POST', cache: 'no-store' })
                .then(function(res) {
                    return res.text().then(function(text) {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            throw new Error('Server mengembalikan respon non-JSON (HTTP ' + res.status + '): ' + (text.substring(0, 120).replace(/<[^>]+>/g, '').trim() || 'Respon kosong/HTML.'));
                        }
                    });
                })
                .then(function(data) {
                    var elapsedSec = ((performance.now() - startTime) / 1000).toFixed(2) + 's';

                    if (!data.success) {
                        setStepState(2, 'error', 'Gagal Fetch');
                        setStepState(3, 'idle', 'Dibatalkan');
                        setStepState(4, 'idle', 'Dibatalkan');
                        appendBasLog('ERROR', 'error', data.message || 'Gagal mengambil data dari BAS.');
                        finishSync(false, data);
                        return;
                    }

                    if (data.debug_logs && Array.isArray(data.debug_logs)) {
                        data.debug_logs.forEach(function(dLog) {
                            appendBasLog('DEBUG', 'info', dLog);
                        });
                    }

                    // Step 2 Completed
                    var totalFound = data.total_submissions_found !== undefined ? data.total_submissions_found : (data.synced_count || 0);
                    setStepState(2, 'done', totalFound + ' Data KST');
                    setTrackProgress(70);
                    appendBasLog('BAS API', 'fetch', 'Berhasil menerima ' + totalFound + ' baris submission dari BAS.');

                    // Step 3: Match AP Version
                    setStepState(3, 'running', 'Matching AP Version...');
                    appendBasLog('MATCH', 'match', 'Mencocokkan AP version dengan database GBA Tasks (single source of truth)...');

                    setTimeout(function() {
                        var updatedTasks = data.updated_tasks || [];
                        setStepState(3, 'done', updatedTasks.length + ' Cocok');
                        setTrackProgress(90);

                        // Step 4: Database Sync Complete
                        setStepState(4, 'done', 'Tersimpan');
                        setTrackProgress(100);
                        appendBasLog('DATABASE', 'success', 'Berhasil memperbarui database (' + updatedTasks.length + ' task tersinkronisasi dalam ' + elapsedSec + ').');

                        finishSync(true, data);
                    }, 400);
                })
                .catch(function(err) {
                    setStepState(2, 'error', 'Network Error');
                    appendBasLog('ERROR', 'error', 'Network/Fetch error: ' + (err.message || 'Koneksi gagal.'));
                    finishSync(false, null);
                });
        }, 500);
    };

    function finishSync(isSuccess, data) {
        isSyncing = false;
        var btn = document.getElementById('btn-run-bas-sync');
        var btnIcon = document.getElementById('btn-run-sync-icon');
        var btnText = document.getElementById('btn-run-sync-text');
        var badge = document.getElementById('bas-modal-badge');
        var metricSubmissions = document.getElementById('bas-metric-submissions');
        var metricUpdated = document.getElementById('bas-metric-updated');
        var summaryBox = document.getElementById('bas-tasks-summary-box');
        var summaryList = document.getElementById('bas-tasks-list');
        var summaryCountPill = document.getElementById('bas-tasks-count-pill');

        if (btn) btn.disabled = false;
        if (btnIcon) btnIcon.classList.remove('animate-spin');
        if (btnText) btnText.textContent = 'Sinkronkan Lagi';

        if (badge) {
            badge.textContent = isSuccess ? 'Synced' : 'Failed';
            badge.className = 'text-[10px] font-medium px-2 py-0.5 rounded ' + (isSuccess ? 'bg-emerald-500/15 text-emerald-500 dark:text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/15 text-rose-500 dark:text-rose-400 border border-rose-500/20');
        }

        if (data) {
            if (metricSubmissions) metricSubmissions.textContent = (data.total_submissions_found || 0) + ' baris';
            if (metricUpdated) metricUpdated.textContent = (data.updated_tasks ? data.updated_tasks.length : (data.synced_count || 0)) + ' task';

            if (data.updated_tasks && data.updated_tasks.length > 0) {
                if (summaryBox) summaryBox.classList.remove('hidden');
                if (summaryCountPill) summaryCountPill.textContent = data.updated_tasks.length + ' task diperbarui';
                if (summaryList) {
                    summaryList.innerHTML = '';
                    data.updated_tasks.forEach(function(t) {
                        var div = document.createElement('div');
                        div.className = 'bas-task-chip';
                        div.innerHTML = '<div class="flex items-center gap-2">' +
                                            '<span class="font-medium text-slate-800 dark:text-slate-200">#' + t.id + ' ' + (t.model_name || '-') + '</span> ' +
                                            '<span class="px-1.5 py-0.2 rounded text-[10px] font-mono bg-slate-800/10 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-300 dark:border-slate-700">' + (t.ap || '-') + '</span>' +
                                        '</div>' +
                                        '<div class="flex items-center gap-2 text-right">' +
                                            '<span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">' + (t.new_status || 'Approved') + '</span>' +
                                            '<span class="text-[10px] text-slate-400 hidden sm:inline">' + (t.reviewer || '') + '</span>' +
                                        '</div>';
                        summaryList.appendChild(div);
                    });
                }
            }
        }

        // Refresh global header badge
        if (typeof updateHeaderBasBadge === 'function') {
            updateHeaderBasBadge();
        }
    }

    // Keyboard Escape handler
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeBasSyncModal();
        }
    });
})();

// =========================================================================
// PONTAIL LEAN & EMIL DESIGN ENG: Centralized Neural Canvas Engine
// Zero-Bloat, Throttled FPS & Static Mode to eliminate GPU overhead on budget laptops
// =========================================================================
(function() {
    function getCanvasMode() {
        var mode = localStorage.getItem('canvas_performance_mode');
        if (!mode) {
            // Check legacy key
            if (localStorage.getItem('disable_canvas_animation') === 'true') {
                mode = 'off';
            } else {
                mode = 'eco'; // Default to Eco (Throttled FPS) for best balance
            }
        }
        return mode;
    }

    window.initNeuralCanvasEngine = function() {
        var canvas = document.getElementById('neural-canvas');
        if (!canvas) return;

        // Ensure canvas has correct style
        canvas.style.position = 'fixed';
        canvas.style.top = '0';
        canvas.style.left = '0';
        canvas.style.width = '100%';
        canvas.style.height = '100%';
        canvas.style.zIndex = '-1';
        canvas.style.pointerEvents = 'none';

        var ctx = canvas.getContext('2d');
        if (!ctx) return;

        var width = 0, height = 0;
        var particles = [];
        var animId = null;
        var lastDrawTime = 0;
        var currentMode = getCanvasMode();

        function syncClassWithMode() {
            document.documentElement.setAttribute('data-canvas-mode', currentMode);
            if (currentMode === 'off') {
                document.documentElement.classList.add('disable-canvas-animation');
                canvas.style.display = 'none';
            } else {
                document.documentElement.classList.remove('disable-canvas-animation');
                canvas.style.display = 'block';
            }

            var isReduceMotion = (localStorage.getItem('reduce_ui_motion') === 'true') || (currentMode === 'static') || (currentMode === 'off');
            document.documentElement.classList.toggle('reduce-ui-motion', isReduceMotion);
        }

        function resize() {
            width = canvas.width = window.innerWidth;
            height = canvas.height = window.innerHeight;
            reinitParticles();
            if (currentMode === 'static') {
                renderSingleFrame();
            }
        }

        function reinitParticles() {
            particles = [];
            var isMobile = width < 768;
            var maxCount = 20;
            if (currentMode === 'full') {
                maxCount = isMobile ? 22 : 40;
            } else if (currentMode === 'static') {
                maxCount = isMobile ? 18 : 28;
            } else if (currentMode === 'eco') {
                maxCount = isMobile ? 12 : 22;
            }

            var count = Math.min(maxCount, Math.max(8, Math.floor((width * height) / 45000)));
            for (var i = 0; i < count; i++) {
                particles.push({
                    x: Math.random() * width,
                    y: Math.random() * height,
                    vx: (Math.random() - 0.5) * (currentMode === 'full' ? 0.4 : 0.25),
                    vy: (Math.random() - 0.5) * (currentMode === 'full' ? 0.4 : 0.25),
                    radius: Math.random() * 1.4 + 0.8
                });
            }
        }

        function getColors() {
            var isLight = document.documentElement.classList.contains('light');
            return {
                node: isLight ? 'rgba(59, 130, 246, 0.35)' : 'rgba(96, 165, 250, 0.35)',
                line: isLight ? 'rgba(59, 130, 246, 0.08)' : 'rgba(96, 165, 250, 0.08)'
            };
        }

        function drawNodes(colors) {
            ctx.clearRect(0, 0, width, height);
            var maxDistSq = 120 * 120; // 14400 (Avoid expensive Math.sqrt)

            for (var i = 0; i < particles.length; i++) {
                var p = particles[i];

                // Draw Particle Node
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
                ctx.fillStyle = colors.node;
                ctx.fill();

                // Draw Connections using Squared Distance
                for (var j = i + 1; j < particles.length; j++) {
                    var p2 = particles[j];
                    var dx = p.x - p2.x;
                    var dy = p.y - p2.y;
                    var distSq = dx * dx + dy * dy;

                    if (distSq < maxDistSq) {
                        ctx.beginPath();
                        ctx.moveTo(p.x, p.y);
                        ctx.lineTo(p2.x, p2.y);
                        ctx.strokeStyle = colors.line;
                        ctx.lineWidth = 1;
                        ctx.stroke();
                    }
                }
            }
        }

        function renderSingleFrame() {
            if (currentMode === 'off') {
                ctx.clearRect(0, 0, width, height);
                return;
            }
            drawNodes(getColors());
        }

        function loop(timestamp) {
            if (currentMode === 'off' || currentMode === 'static' || document.hidden) {
                animId = null;
                return;
            }

            // FPS Throttling: Eco = ~18 FPS (55ms interval), Full = 60 FPS (16ms interval)
            var targetInterval = (currentMode === 'eco') ? 55 : 16;
            if (timestamp - lastDrawTime >= targetInterval) {
                lastDrawTime = timestamp;

                // Update positions
                for (var i = 0; i < particles.length; i++) {
                    var p = particles[i];
                    p.x += p.vx;
                    p.y += p.vy;
                    if (p.x < 0) p.x = width;
                    if (p.x > width) p.x = 0;
                    if (p.y < 0) p.y = height;
                    if (p.y > height) p.y = 0;
                }

                drawNodes(getColors());
            }

            animId = requestAnimationFrame(loop);
        }

        function start() {
            syncClassWithMode();
            if (currentMode === 'off') {
                if (animId) cancelAnimationFrame(animId);
                animId = null;
                ctx.clearRect(0, 0, width, height);
                return;
            }
            if (currentMode === 'static') {
                if (animId) cancelAnimationFrame(animId);
                animId = null;
                renderSingleFrame();
                return;
            }
            if (!animId && !document.hidden) {
                lastDrawTime = performance.now();
                animId = requestAnimationFrame(loop);
            }
        }

        function stop() {
            if (animId) {
                cancelAnimationFrame(animId);
                animId = null;
            }
        }

        function applyMode(newMode) {
            currentMode = newMode;
            stop();
            syncClassWithMode();
            reinitParticles();
            if (currentMode === 'static') {
                renderSingleFrame();
            } else if (currentMode !== 'off') {
                start();
            } else {
                ctx.clearRect(0, 0, width, height);
            }
        }

        // Initialize
        resize();
        window.addEventListener('resize', resize, { passive: true });

        // Auto pause on tab hidden (Saves 100% CPU/GPU when backgrounded)
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                stop();
            } else if (currentMode !== 'off' && currentMode !== 'static') {
                start();
            }
        });

        // Listen to live profile setting change
        window.addEventListener('canvasperformancechanged', function(e) {
            var mode = (e && e.detail && e.detail.mode) ? e.detail.mode : getCanvasMode();
            applyMode(mode);
        });

        window.addEventListener('canvasanimationchanged', function(e) {
            var mode = (e && e.detail && e.detail.disabled) ? 'off' : getCanvasMode();
            applyMode(mode);
        });

        window.addEventListener('reducemotionchanged', function(e) {
            syncClassWithMode();
        });

        start();
        window.__neuralCanvasEngine = { applyMode: applyMode, resize: resize };
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', window.initNeuralCanvasEngine);
    } else {
        window.initNeuralCanvasEngine();
    }
})();


document.addEventListener('DOMContentLoaded', function() {
    const modelNameInput = document.getElementById('model_name');
    const projectNameInput = document.getElementById('project_name');
    const requestDateInput = document.getElementById('request_date');
    const deadlineInput = document.getElementById('deadline');
    const signOffDateInput = document.getElementById('sign_off_date');

    if (modelNameInput && projectNameInput) {
        modelNameInput.addEventListener('change', function() {
            const modelName = this.value.trim();
            if (modelName.length > 3) {
                projectNameInput.value = 'Mencari...';
                fetch(`get_marketing_name.php?model_name=${encodeURIComponent(modelName)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.is_dropped) {
                            modelNameInput.dataset.isDropped = "1";
                            modelNameInput.classList.add('border-rose-500', 'text-rose-400');
                            projectNameInput.value = '';
                            projectNameInput.placeholder = 'Model sudah Discontinue / Drop';
                            if (typeof showDroppedModelModal === 'function') {
                                showDroppedModelModal(modelName);
                            } else {
                                alert(`Task untuk model ${modelName} tidak dapat disimpan karena sudah Discontinue / Drop dari proses development.`);
                            }
                            return;
                        } else {
                            delete modelNameInput.dataset.isDropped;
                            modelNameInput.classList.remove('border-rose-500', 'text-rose-400');
                        }

                        if (data.success && data.marketing_name) {
                            projectNameInput.value = data.marketing_name;
                        } else {
                            projectNameInput.value = '';
                            projectNameInput.placeholder = 'Nama pemasaran tidak ditemukan';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        projectNameInput.value = '';
                        projectNameInput.placeholder = 'Gagal mengambil data';
                    });
            }
        });
    }

    // Intercept form submit jika model yang diinput drop
    const taskForms = document.querySelectorAll('form[action="handler.php"]');
    taskForms.forEach(f => {
        f.addEventListener('submit', function(e) {
            const mInput = f.querySelector('#model_name');
            if (mInput && mInput.dataset.isDropped === "1") {
                e.preventDefault();
                e.stopPropagation();
                if (typeof showDroppedModelModal === 'function') {
                    showDroppedModelModal(mInput.value.trim());
                } else {
                    alert(`Task untuk model ${mInput.value} tidak dapat disimpan karena sudah Discontinue / Drop.`);
                }
                return false;
            }
        });
    });

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
        const today = new Date();
        const todayString = today.toISOString().slice(0, 10);
        
        const taskIdInput = document.getElementById('task-id');
        if (!taskIdInput || !taskIdInput.value) {
            if (requestDateInput) requestDateInput.value = todayString;
            
            const futureDate = calculateWorkingDays(todayString, 7);
            if (deadlineInput) deadlineInput.value = futureDate;
            if (signOffDateInput) signOffDateInput.value = futureDate;
        }
    }

    setDefaultDates();

    if (requestDateInput && deadlineInput && signOffDateInput) {
        requestDateInput.addEventListener('change', function() {
            if (this.value) {
                const futureDate = calculateWorkingDays(this.value, 7);
                deadlineInput.value = futureDate;
                signOffDateInput.value = futureDate;
            }
        });
    }
});
