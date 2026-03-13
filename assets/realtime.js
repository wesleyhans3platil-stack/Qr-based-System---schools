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

    // Toggle whether the little "Live" status pill shows on the page
    // Set to false to remove the green "Live" indicator.
    const SHOW_LIVE_INDICATOR = false;

    // Pages that should NOT auto-refresh (forms, settings, edit pages)
    const noRefreshPages = ['settings', 'register_user', 'bulk_import', 'print_qr', 'shs_students'];
    const currentPath = window.location.pathname;
    const shouldRefresh = !noRefreshPages.some(p => currentPath.includes(p));

    // Auto-refresh interval (in ms). Set to 0 to disable.
    const isAppWebView = /QRAttendanceApp|wv/.test(navigator.userAgent);
    const AUTO_REFRESH_INTERVAL_MS = isAppWebView ? 0 : 2 * 60 * 1000; // 2 minutes (disabled in Android app)
    let autoRefreshTimer = null;

    // Add a class for CSS tweaks when inside the Android WebView
    if (isAppWebView) document.documentElement.classList.add('app-webview');

    const getMainContent = () => document.getElementById('mainContent') || document.querySelector('.main-content');

    const setContentTransition = (phase) => {
        const el = getMainContent();
        if (!el) return;
        el.classList.remove('slide-enter', 'slide-enter-active', 'slide-exit', 'fade-out', 'fade-in');
        if (phase === 'exit') {
            el.classList.add('slide-exit');
        } else if (phase === 'enter') {
            el.classList.add('slide-enter');
            requestAnimationFrame(() => el.classList.add('slide-enter-active'));
        }
    };

    function updateNavActive(url) {
        const path = new URL(url, window.location.origin).pathname;
        document.querySelectorAll('.nav-bar .nav-item').forEach(el => {
            const href = el.getAttribute('href');
            if (!href) return;
            const linkPath = new URL(href, window.location.origin).pathname;
            el.classList.toggle('active', linkPath === path);
        });
    }

    function scheduleAutoRefresh() {
        if (!AUTO_REFRESH_INTERVAL_MS) return;
        if (autoRefreshTimer) clearTimeout(autoRefreshTimer);
        autoRefreshTimer = setTimeout(() => {
            if (!shouldRefresh) return;
            navigateTo(window.location.href, true);
        }, AUTO_REFRESH_INTERVAL_MS);
    }

    function navigateTo(url, replaceHistory = false) {
        // In the Android WebView, use normal navigation to avoid extra DOM swaps/stutter
        if (isAppWebView) {
            if (replaceHistory) {
                location.replace(url);
            } else {
                location.href = url;
            }
            return;
        }

        setContentTransition('exit');
        fetch(url, { credentials: 'same-origin' })
            .then(r => r.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newContent = doc.getElementById('mainContent') || doc.querySelector('.main-content');
                const currentContent = getMainContent();

                if (newContent && currentContent) {
                    // Preserve scroll position
                    const scrollTop = window.scrollY;

                    currentContent.innerHTML = newContent.innerHTML;
                    window.scrollTo(0, scrollTop);

                    // Re-run any inline scripts inside the refreshed content
                    currentContent.querySelectorAll('script').forEach(oldScript => {
                        const newScript = document.createElement('script');
                        if (oldScript.src) {
                            newScript.src = oldScript.src;
                        } else {
                            newScript.textContent = oldScript.textContent;
                        }
                        oldScript.parentNode.replaceChild(newScript, oldScript);
                    });

                    // Update document title if provided
                    const newTitle = doc.querySelector('title');
                    if (newTitle) document.title = newTitle.textContent;

                    // Update navigation state
                    updateNavActive(url);

                    // Notify any listeners about the refresh
                    reinitScripts(currentContent);

                    // Flash indicator
                    showSyncFlash();

                    // Animate in
                    setContentTransition('enter');

                // Schedule next auto-refresh
                scheduleAutoRefresh();
                    history.replaceState({ url }, '', url);
                } else {
                    history.pushState({ url }, '', url);
                }
            });
    }

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
                    if (shouldRefresh) navigateTo(window.location.href, true);
                }

                updateSyncIndicator(true, data.server_time);
                scheduleAutoRefresh();
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

    function reinitScripts(container) {
        window.dispatchEvent(new CustomEvent('realtime:refresh'));
    }

    function showSyncFlash() {
        if (!SHOW_LIVE_INDICATOR) return;
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
        if (!SHOW_LIVE_INDICATOR) return;

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

    // Smooth navigation: intercept bottom nav clicks and load content via AJAX
    document.addEventListener('click', function(e) {
        const anchor = e.target.closest('.nav-bar .nav-item');
        if (!anchor) return;
        if (e.defaultPrevented) return;
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const href = anchor.getAttribute('href');
        if (!href) return;
        e.preventDefault();
        navigateTo(href);
    });

    window.addEventListener('popstate', function(e) {
        const url = (e.state && e.state.url) ? e.state.url : window.location.href;
        navigateTo(url, true);
    });

    // Mark current nav item active on load
    updateNavActive(window.location.href);

    // Start polling
    poll();
})();
