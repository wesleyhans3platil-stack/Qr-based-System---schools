/**
 * Real-time data sync via smart polling.
 * Polls the server every few seconds and refreshes page content when data changes.
 * Include this script on any page that needs live updates.
 */
(function() {
    let lastHash = null;
    let pollInterval = 5000; // 5 seconds
    let failCount = 0;
    let pollTimer = null;
    let isPaused = false;

    // Determine API path based on current page location
    const isInAdmin = window.location.pathname.includes('/admin/');
    const apiBase = isInAdmin ? '../api/' : 'api/';

    // Pages that should NOT auto-refresh (forms, settings, edit pages)
    const noRefreshPages = ['settings', 'register_user', 'bulk_import', 'print_qr', 'shs_students'];
    const currentPath = window.location.pathname;
    const shouldRefresh = !noRefreshPages.some(p => currentPath.includes(p));

    function poll() {
        if (isPaused) return;

        fetch(apiBase + 'realtime_poll.php', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                failCount = 0;
                pollInterval = 5000; // Reset to 5s on success

                if (data.error) return;

                if (lastHash === null) {
                    // First load — just store the hash
                    lastHash = data.hash;
                    updateSyncIndicator(true, data.server_time);
                    return;
                }

                if (data.hash !== lastHash) {
                    lastHash = data.hash;
                    if (shouldRefresh) refreshPageContent();
                }

                updateSyncIndicator(true, data.server_time);
            })
            .catch(() => {
                failCount++;
                // Back off on failures: 5s, 10s, 15s... up to 30s
                pollInterval = Math.min(30000, 5000 + (failCount * 5000));
                updateSyncIndicator(false);
            })
            .finally(() => {
                pollTimer = setTimeout(poll, pollInterval);
            });
    }

    function refreshPageContent() {
        // Fetch current page HTML and replace main-content
        const url = window.location.href;
        fetch(url, { credentials: 'same-origin' })
            .then(r => r.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newContent = doc.querySelector('.main-content');
                const currentContent = document.querySelector('.main-content');

                if (newContent && currentContent) {
                    // Preserve scroll position
                    const scrollTop = window.scrollY;

                    currentContent.innerHTML = newContent.innerHTML;

                    // Restore scroll
                    window.scrollTo(0, scrollTop);

                    // Re-execute inline scripts within main-content
                    currentContent.querySelectorAll('script').forEach(oldScript => {
                        const newScript = document.createElement('script');
                        if (oldScript.src) {
                            newScript.src = oldScript.src;
                        } else {
                            newScript.textContent = oldScript.textContent;
                        }
                        oldScript.parentNode.replaceChild(newScript, oldScript);
                    });

                    // Also re-execute page-level scripts (outside main-content) for charts
                    var pageScripts = doc.querySelectorAll('body > script:not([src])');
                    pageScripts.forEach(function(s) {
                        try { eval(s.textContent); } catch(e) {}
                    });

                    // Flash indicator
                    showSyncFlash();
                }
            })
            .catch(() => {});
    }

    function reinitScripts(container) {
        window.dispatchEvent(new CustomEvent('realtime:refresh'));
    }

    function showSyncFlash() {
        const indicator = document.getElementById('realtimeIndicator');
        if (indicator) {
            indicator.style.color = '#16a34a';
            indicator.innerHTML = '<i class="fas fa-sync fa-spin"></i>';
            setTimeout(() => {
                indicator.innerHTML = '<i class="fas fa-circle" style="font-size:6px;vertical-align:middle;"></i> Live';
                indicator.style.color = '#16a34a';
            }, 800);
        }
    }

    function updateSyncIndicator(connected, serverTime) {
        let indicator = document.getElementById('realtimeIndicator');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.id = 'realtimeIndicator';
            indicator.style.cssText = 'position:fixed;bottom:16px;right:16px;padding:6px 14px;border-radius:20px;font-size:0.72rem;font-weight:600;z-index:9999;display:flex;align-items:center;gap:6px;box-shadow:0 2px 8px rgba(0,0,0,0.1);cursor:pointer;transition:all 0.3s;';
            indicator.title = 'Real-time sync active';
            indicator.onclick = function() { this.style.display = 'none'; };
            document.body.appendChild(indicator);
        }

        if (connected) {
            indicator.style.background = '#f0fdf4';
            indicator.style.color = '#16a34a';
            indicator.style.border = '1px solid #bbf7d0';
            indicator.innerHTML = '<i class="fas fa-circle" style="font-size:6px;vertical-align:middle;"></i> Live';
        } else {
            indicator.style.background = '#fef2f2';
            indicator.style.color = '#dc2626';
            indicator.style.border = '1px solid #fecaca';
            indicator.innerHTML = '<i class="fas fa-circle" style="font-size:6px;vertical-align:middle;"></i> Reconnecting...';
        }
    }

    // Pause polling when tab is hidden, resume when visible
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            isPaused = true;
            if (pollTimer) clearTimeout(pollTimer);
        } else {
            isPaused = false;
            poll(); // Immediately check on tab focus
        }
    });

    // Start polling
    poll();
})();
