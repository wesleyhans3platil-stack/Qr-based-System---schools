const CACHE_NAME = 'qr-dashboard-v2';
const ASSETS_TO_CACHE = [
    'app_dashboard.php',
    'app_login.php',
    'install.php',
    'admin/includes/styles.css',
    'assets/icons/icon-192.png',
    'assets/icons/icon-512.png',
    'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css'
];

// Install — cache static assets
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(ASSETS_TO_CACHE).catch(() => {
                // Some assets may fail (cross-origin), that's OK
            });
        })
    );
    self.skipWaiting();
});

// Activate — clean old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

// Fetch — network-first for PHP pages (live data), cache-first for static assets
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Always go to network for PHP pages and API calls (live data)
    if (url.pathname.endsWith('.php') || url.pathname.includes('/api/')) {
        event.respondWith(
            fetch(event.request).catch(() => {
                // Offline fallback
                return new Response(
                    '<html><body style="font-family:Inter,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background:#f1f5f9;"><div style="text-align:center;"><h2 style="color:#1e293b;">You\'re Offline</h2><p style="color:#64748b;">Please check your internet connection and try again.</p><button onclick="location.reload()" style="margin-top:16px;padding:12px 24px;background:#4338ca;color:#fff;border:none;border-radius:10px;font-weight:600;cursor:pointer;">Retry</button></div></body></html>',
                    { headers: { 'Content-Type': 'text/html' } }
                );
            })
        );
        return;
    }

    // Cache-first for static assets (CSS, fonts, icons)
    event.respondWith(
        caches.match(event.request).then(cached => {
            return cached || fetch(event.request).then(response => {
                if (response.ok) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
                }
                return response;
            });
        })
    );
});

// ══════════════════════════════════════════════════════════════════
// PUSH NOTIFICATIONS — Handle incoming push events
// ══════════════════════════════════════════════════════════════════
self.addEventListener('push', event => {
    let data = { title: 'EduTrack Alert', body: 'You have a new notification.' };

    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            data.body = event.data.text();
        }
    }

    const options = {
        body: data.body || '',
        icon: data.icon || 'assets/icons/icon-192.svg',
        badge: data.badge || 'assets/icons/icon-192.svg',
        tag: data.tag || 'qr-attendance',
        vibrate: [200, 100, 200],
        requireInteraction: true,
        data: data.data || { url: 'app_dashboard.php' },
        actions: [
            { action: 'open', title: 'View Dashboard' },
            { action: 'dismiss', title: 'Dismiss' }
        ]
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

// Handle notification click — open the dashboard
self.addEventListener('notificationclick', event => {
    event.notification.close();

    if (event.action === 'dismiss') return;

    const url = event.notification.data?.url || 'app_dashboard.php';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
            // If dashboard is already open, focus it
            for (const client of clientList) {
                if (client.url.includes('app_dashboard') && 'focus' in client) {
                    return client.focus();
                }
            }
            // Otherwise, open a new window
            if (clients.openWindow) {
                return clients.openWindow(url);
            }
        })
    );
});
