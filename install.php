<?php
/**
 * ══════════════════════════════════════════════════════════════════
 * INSTALL APP — PWA Install Landing Page
 * ══════════════════════════════════════════════════════════════════
 * Beautiful page to guide users to install the QR Attendance app
 * on their phone. Works on Android (Chrome) and iOS (Safari).
 */
require_once 'config/database.php';
$conn = getDBConnection();

$systemLogo = '';
$lr = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='system_logo'");
if ($lr && $lrow = $lr->fetch_assoc()) {
    $lf = $lrow['setting_value'] ?? '';
    if ($lf && file_exists(__DIR__ . '/assets/uploads/logos/' . $lf)) $systemLogo = 'assets/uploads/logos/' . $lf;
}
$systemName = 'EduTrack';
$nr = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='system_name'");
if ($nr && $nrow = $nr->fetch_assoc()) $systemName = $nrow['setting_value'] ?: $systemName;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#4338ca">
    <title>Install App — <?= htmlspecialchars($systemName) ?></title>
    <link rel="manifest" href="manifest.json">
    <?php if ($systemLogo): ?><link rel="icon" type="image/png" href="<?= $systemLogo ?>"><?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root { --safe-top: env(safe-area-inset-top, 0px); --safe-bottom: env(safe-area-inset-bottom, 0px); }
        html, body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            background-color: #f1f5f9;
            background-image:
                linear-gradient(rgba(203, 213, 225, 0.45) 1px, transparent 1px),
                linear-gradient(90deg, rgba(203, 213, 225, 0.45) 1px, transparent 1px);
            background-size: 32px 32px;
            color: #0f172a;
            -webkit-tap-highlight-color: transparent;
        }
        body {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 20px calc(24px + var(--safe-bottom));
            padding-top: calc(24px + var(--safe-top));
        }

        .install-page { width: 100%; max-width: 420px; text-align: center; }

        /* Hero */
        .app-icon {
            width: 100px; height: 100px; border-radius: 26px; margin: 0 auto 24px;
            background: linear-gradient(135deg, #4338ca, #6366f1);
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 16px 48px rgba(67,56,202,0.3);
            overflow: hidden; position: relative;
        }
        .app-icon img { width: 100%; height: 100%; object-fit: cover; }
        .app-icon i { font-size: 2.4rem; color: #fff; }
        .app-icon::after {
            content: ''; position: absolute; inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.15) 0%, transparent 60%);
            border-radius: 26px;
        }

        .app-name {
            font-size: 1.6rem; font-weight: 900; color: #0f172a;
            letter-spacing: -0.03em; margin-bottom: 6px;
        }
        .app-tagline {
            font-size: 0.85rem; color: #64748b; font-weight: 500; margin-bottom: 32px;
            line-height: 1.5;
        }

        /* Screenshots / Feature cards */
        .features {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
            margin-bottom: 32px; text-align: left;
        }
        .feat {
            background: #fff; border-radius: 16px; padding: 18px 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .feat i {
            width: 36px; height: 36px; border-radius: 10px; display: flex;
            align-items: center; justify-content: center; font-size: 0.9rem;
            margin-bottom: 10px;
        }
        .feat:nth-child(1) i { background: #ede9fe; color: #7c3aed; }
        .feat:nth-child(2) i { background: #dbeafe; color: #2563eb; }
        .feat:nth-child(3) i { background: #dcfce7; color: #16a34a; }
        .feat:nth-child(4) i { background: #fef3c7; color: #d97706; }
        .feat strong { font-size: 0.78rem; font-weight: 700; color: #1e293b; display: block; }
        .feat span { font-size: 0.68rem; color: #94a3b8; font-weight: 500; line-height: 1.4; }

        /* Install Button */
        .install-btn {
            width: 100%; padding: 18px 24px; border: none; border-radius: 16px;
            font-size: 1.05rem; font-weight: 800; font-family: 'Inter', sans-serif;
            cursor: pointer; transition: all 0.2s; position: relative; overflow: hidden;
            background: linear-gradient(135deg, #4338ca, #6366f1);
            color: #fff;
            box-shadow: 0 8px 32px rgba(67,56,202,0.35);
            letter-spacing: -0.01em;
            display: flex; align-items: center; justify-content: center; gap: 10px;
        }
        .install-btn:active { transform: scale(0.97); }
        .install-btn:hover { box-shadow: 0 12px 40px rgba(67,56,202,0.45); transform: translateY(-2px); }
        .install-btn.installed {
            background: linear-gradient(135deg, #16a34a, #22c55e);
            box-shadow: 0 8px 32px rgba(22,163,74,0.35);
        }
        .install-btn i { font-size: 1.1rem; }

        .install-alt {
            margin-top: 16px; font-size: 0.75rem; color: #94a3b8; font-weight: 500;
            line-height: 1.6;
        }
        .install-alt a { color: #6366f1; text-decoration: none; font-weight: 700; }

        /* Manual steps (shown when auto-install not available) */
        .manual-steps {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 20px;
            padding: 24px 20px; margin-top: 20px; text-align: left;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            display: none;
        }
        .manual-steps.visible { display: block; }
        .manual-steps h3 {
            font-size: 0.9rem; font-weight: 800; color: #1e293b; margin-bottom: 16px;
            display: flex; align-items: center; gap: 8px;
        }
        .manual-steps h3 i { color: #6366f1; }
        .step {
            display: flex; align-items: flex-start; gap: 14px; margin-bottom: 16px;
        }
        .step:last-child { margin-bottom: 0; }
        .step-num {
            width: 28px; height: 28px; border-radius: 50%; background: #f1f5f9;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.72rem; font-weight: 800; color: #4338ca; flex-shrink: 0;
            border: 2px solid #e2e8f0;
        }
        .step-text { flex: 1; }
        .step-text strong { font-size: 0.8rem; font-weight: 700; color: #1e293b; display: block; }
        .step-text span {
            font-size: 0.72rem; color: #64748b; font-weight: 500; line-height: 1.5;
        }
        .step-text .key-icon {
            display: inline-flex; align-items: center; gap: 4px;
            background: #f1f5f9; padding: 2px 8px; border-radius: 6px;
            font-size: 0.7rem; font-weight: 700; color: #475569;
            border: 1px solid #e2e8f0;
        }

        /* Status badges */
        .status-bar {
            display: flex; align-items: center; justify-content: center; gap: 12px;
            margin-bottom: 24px; flex-wrap: wrap;
        }
        .badge {
            display: flex; align-items: center; gap: 6px; padding: 6px 12px;
            border-radius: 20px; font-size: 0.68rem; font-weight: 700;
        }
        .badge.ok { background: #dcfce7; color: #16a34a; }
        .badge.warn { background: #fef3c7; color: #d97706; }
        .badge.fail { background: #fee2e2; color: #dc2626; }
        .badge i { font-size: 0.6rem; }

        .login-link {
            margin-top: 20px; font-size: 0.78rem; color: #64748b; font-weight: 500;
        }
        .login-link a {
            color: #4338ca; text-decoration: none; font-weight: 700;
        }

        /* Animation */
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 16px 48px rgba(67,56,202,0.3); }
            50% { box-shadow: 0 16px 48px rgba(67,56,202,0.5), 0 0 0 8px rgba(67,56,202,0.08); }
        }
        .app-icon { animation: pulse-glow 3s ease-in-out infinite; }
    </style>
</head>
<body>
    <div class="install-page">
        <div class="app-icon">
            <?php if ($systemLogo): ?>
                <img src="<?= htmlspecialchars($systemLogo) ?>" alt="App Icon">
            <?php else: ?>
                <i class="fas fa-qrcode"></i>
            <?php endif; ?>
        </div>

        <div class="app-name"><?= htmlspecialchars($systemName) ?></div>
        <div class="app-tagline">
            Real-time attendance monitoring right from your phone.<br>
            Schools Division Office &mdash; Sipalay City
        </div>

        <!-- Status checks -->
        <div class="status-bar" id="statusBar">
            <div class="badge" id="swBadge"><i class="fas fa-circle"></i> Service Worker</div>
            <div class="badge" id="httpsBadge"><i class="fas fa-circle"></i> Connection</div>
        </div>

        <!-- Feature cards -->
        <div class="features">
            <div class="feat">
                <i><span class="fas fa-chart-line"></span></i>
                <strong>Live Dashboard</strong>
                <span>Real-time attendance rates &amp; stats</span>
            </div>
            <div class="feat">
                <i><span class="fas fa-bell"></span></i>
                <strong>Push Alerts</strong>
                <span>Absence notifications instantly</span>
            </div>
            <div class="feat">
                <i><span class="fas fa-school"></span></i>
                <strong>All Schools</strong>
                <span>Monitor every campus at once</span>
            </div>
            <div class="feat">
                <i><span class="fas fa-bolt"></span></i>
                <strong>Works Offline</strong>
                <span>Cached pages for fast access</span>
            </div>
        </div>

        <!-- Main install button -->
        <button class="install-btn" id="installBtn" disabled>
            <i class="fas fa-download"></i>
            <span id="installBtnText">Checking...</span>
        </button>

        <div class="install-alt">
            Or open this page in <strong>Chrome on Android</strong><br>
            to install as a native app.
        </div>

        <!-- Manual install steps (fallback) -->
        <div class="manual-steps" id="manualAndroid">
            <h3><i class="fas fa-android"></i> Install on Android</h3>
            <div class="step">
                <div class="step-num">1</div>
                <div class="step-text">
                    <strong>Open in Chrome</strong>
                    <span>Make sure you're using Google Chrome browser</span>
                </div>
            </div>
            <div class="step">
                <div class="step-num">2</div>
                <div class="step-text">
                    <strong>Tap the menu</strong>
                    <span>Tap the <span class="key-icon"><i class="fas fa-ellipsis-vertical"></i> 3 dots</span> in the top-right corner</span>
                </div>
            </div>
            <div class="step">
                <div class="step-num">3</div>
                <div class="step-text">
                    <strong>Add to Home Screen</strong>
                    <span>Tap <span class="key-icon"><i class="fas fa-plus"></i> Add to Home screen</span> or <span class="key-icon"><i class="fas fa-download"></i> Install app</span></span>
                </div>
            </div>
            <div class="step">
                <div class="step-num">4</div>
                <div class="step-text">
                    <strong>Done!</strong>
                    <span>The app icon will appear on your home screen just like a real app</span>
                </div>
            </div>
        </div>

        <div class="manual-steps" id="manualIOS">
            <h3><i class="fab fa-apple"></i> Install on iPhone / iPad</h3>
            <div class="step">
                <div class="step-num">1</div>
                <div class="step-text">
                    <strong>Open in Safari</strong>
                    <span>This only works in Safari, not Chrome on iOS</span>
                </div>
            </div>
            <div class="step">
                <div class="step-num">2</div>
                <div class="step-text">
                    <strong>Tap the Share button</strong>
                    <span>Tap <span class="key-icon"><i class="fas fa-arrow-up-from-bracket"></i> Share</span> at the bottom</span>
                </div>
            </div>
            <div class="step">
                <div class="step-num">3</div>
                <div class="step-text">
                    <strong>Add to Home Screen</strong>
                    <span>Scroll down and tap <span class="key-icon"><i class="fas fa-plus-square"></i> Add to Home Screen</span></span>
                </div>
            </div>
        </div>

        <div class="login-link">
            Already installed? <a href="app_login.php">Open App &rarr;</a>
        </div>
    </div>

    <script>
    // ═══ Service Worker Registration ═══
    let swReady = false;
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').then(() => {
            swReady = true;
            document.getElementById('swBadge').classList.add('ok');
            document.getElementById('swBadge').innerHTML = '<i class="fas fa-circle-check"></i> Service Worker';
            checkReady();
        }).catch(() => {
            document.getElementById('swBadge').classList.add('fail');
            document.getElementById('swBadge').innerHTML = '<i class="fas fa-circle-xmark"></i> SW Failed';
        });
    } else {
        document.getElementById('swBadge').classList.add('fail');
        document.getElementById('swBadge').innerHTML = '<i class="fas fa-circle-xmark"></i> Not Supported';
    }

    // HTTPS check
    const isSecure = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    const httpsBadge = document.getElementById('httpsBadge');
    if (isSecure) {
        httpsBadge.classList.add('ok');
        httpsBadge.innerHTML = '<i class="fas fa-circle-check"></i> Secure';
    } else {
        httpsBadge.classList.add('warn');
        httpsBadge.innerHTML = '<i class="fas fa-triangle-exclamation"></i> HTTP (use LAN IP)';
    }

    // ═══ Detect platform ═══
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
    const isAndroid = /Android/.test(navigator.userAgent);
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;

    // ═══ PWA Install Prompt ═══
    let deferredPrompt = null;
    const installBtn = document.getElementById('installBtn');
    const installBtnText = document.getElementById('installBtnText');

    if (isStandalone) {
        // Already installed
        installBtn.classList.add('installed');
        installBtnText.textContent = 'App Installed!';
        installBtn.querySelector('i').className = 'fas fa-circle-check';
        installBtn.disabled = true;
    } else {
        installBtnText.textContent = 'Install App';
        installBtn.disabled = false;
    }

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        installBtn.disabled = false;
        installBtnText.textContent = 'Install App';
        installBtn.querySelector('i').className = 'fas fa-download';
        // Hide manual steps when native prompt available
        document.getElementById('manualAndroid').classList.remove('visible');
        document.getElementById('manualIOS').classList.remove('visible');
    });

    installBtn.addEventListener('click', async () => {
        if (deferredPrompt) {
            // Use the native browser install prompt
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            if (outcome === 'accepted') {
                installBtn.classList.add('installed');
                installBtnText.textContent = 'Installing...';
                installBtn.querySelector('i').className = 'fas fa-spinner fa-spin';
                setTimeout(() => {
                    installBtnText.textContent = 'App Installed!';
                    installBtn.querySelector('i').className = 'fas fa-circle-check';
                    installBtn.disabled = true;
                }, 2000);
            }
            deferredPrompt = null;
        } else {
            // Show manual instructions
            if (isIOS) {
                document.getElementById('manualIOS').classList.toggle('visible');
                document.getElementById('manualAndroid').classList.remove('visible');
            } else {
                document.getElementById('manualAndroid').classList.toggle('visible');
                document.getElementById('manualIOS').classList.remove('visible');
            }
        }
    });

    window.addEventListener('appinstalled', () => {
        installBtn.classList.add('installed');
        installBtnText.textContent = 'App Installed!';
        installBtn.querySelector('i').className = 'fas fa-circle-check';
        installBtn.disabled = true;
        deferredPrompt = null;
    });

    function checkReady() {
        // Update UI once SW is registered
    }

    // Auto-show manual steps after 3 seconds if no native prompt fires
    setTimeout(() => {
        if (!deferredPrompt && !isStandalone) {
            if (isIOS) {
                document.getElementById('manualIOS').classList.add('visible');
            } else {
                document.getElementById('manualAndroid').classList.add('visible');
            }
        }
    }, 3000);
    </script>
</body>
</html>
