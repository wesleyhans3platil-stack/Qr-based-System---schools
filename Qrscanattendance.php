<?php
require_once 'config/database.php';
require_once 'config/school_days.php';
$conn = getDBConnection();

// Use the cached getSetting() function — no extra query needed
$systemLogo = '';
$logoFile = getSetting('system_logo');
if (!empty($logoFile) && file_exists('assets/uploads/logos/' . $logoFile)) {
    $systemLogo = 'assets/uploads/logos/' . $logoFile;
}
$systemName = getSetting('system_name') ?: 'School Attendance System';
$divisionName = getSetting('division_name') ?: 'Division of Sipalay City';
$is_school_day = isSchoolDay(date('Y-m-d'), $conn);
$non_school_reason = getNonSchoolDayReason(date('Y-m-d'), $conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Attendance Scanner — Division of Sipalay City</title>
    <?php if ($systemLogo): ?><link rel="icon" type="image/png" href="<?= $systemLogo ?>"><?php endif; ?>
    <!-- Preconnect to CDN origins — prevents DNS+TLS delays on 800 laptops -->
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preconnect" href="https://unpkg.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; overflow: hidden; }
        body {
            font-family: 'Inter', sans-serif;
            background-color: #ffffff;
            color: #1e293b;
            /* Subtle Grid Texture */
            background-image: 
                linear-gradient(rgba(203, 213, 225, 0.3) 1px, transparent 1px),
                linear-gradient(90deg, rgba(203, 213, 225, 0.3) 1px, transparent 1px);
            background-size: 32px 32px;
        }

        .page {
            height: 100vh;
            display: flex;
            flex-direction: column;
            background: radial-gradient(circle at 50% 50%, rgba(255,255,255,0) 0%, rgba(255,255,255,0.7) 100%);
        }

        /* ═══ HEADER ═══ */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 48px;
            flex-shrink: 0;
            background: #fff;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .brand-logo {
            width: 52px; height: 52px;
            background: linear-gradient(135deg, #4338ca, #6366f1);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.25rem;
            box-shadow: 0 4px 16px rgba(99,102,241,0.25);
            overflow: hidden;
        }
        .brand-logo img {
            width: 100%; height: 100%;
            object-fit: cover;
        }
        .brand-info h1 {
            font-size: 1.15rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.03em;
        }
        .brand-info p {
            font-size: 0.78rem;
            color: #94a3b8;
            font-weight: 500;
            margin-top: 1px;
        }
        .header-right {
            display: flex;
            align-items: center;
            gap: 32px;
        }
        .header-clock {
            text-align: right;
        }
        .header-clock .time {
            font-size: 2rem;
            font-weight: 900;
            color: #0f172a;
            letter-spacing: -1.5px;
            font-variant-numeric: tabular-nums;
            line-height: 1.1;
        }
        .header-clock .date {
            font-size: 0.78rem;
            color: #94a3b8;
            font-weight: 500;
        }
        .no-classes-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #fffbeb, #fef3c7);
            border: 1px solid #fde68a;
            color: #b45309;
            padding: 8px 16px;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(217,119,6,0.15);
            white-space: nowrap;
        }
        .no-classes-badge i {
            font-size: 1rem;
            color: #d97706;
        }
        .admin-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            color: #94a3b8;
            text-decoration: none;
            font-weight: 500;
            padding: 10px 20px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
        }
        .admin-btn:hover { color: #4338ca; border-color: #c7d2fe; background: #eef2ff; }

        /* ═══ MAIN ═══ */
        .main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            padding: 0 48px 24px;
        }
        .scanner-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 28px;
            width: 100%;
            max-width: 720px;
        }

        /* Camera */
        .camera-box {
            width: 100%;
            aspect-ratio: 1;
            max-height: calc(100vh - 200px);
            max-width: calc(100vh - 200px);
            border-radius: 32px;
            overflow: hidden;
            background: #0f172a;
            position: relative;
            box-shadow:
                0 0 0 1px rgba(0,0,0,0.04),
                0 24px 80px rgba(0,0,0,0.1),
                0 8px 24px rgba(0,0,0,0.06);
        }
        #reader { width: 100%; height: 100%; }
        #reader video { width: 100% !important; height: 100% !important; object-fit: cover !important; }
        #reader img { display: none !important; }
        #reader > div:nth-child(2) { display: none !important; }
        #reader__scan_region { width: 100% !important; height: 100% !important; }
        #reader__scan_region video { border: none !important; }
        #reader__scan_region > div:last-child { display: none !important; }
        #reader__dashboard { display: none !important; }
        #qr-shaded-region { display: none !important; }

        /* Scan frame */
        .scan-frame {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 55%; height: 55%;
            max-width: 340px; max-height: 340px;
            z-index: 5;
            pointer-events: none;
        }
        .corner {
            position: absolute;
            width: 44px; height: 44px;
            border-style: solid;
            border-width: 0;
            border-color: rgba(255,255,255,0.85);
        }
        .corner.tl { top: 0; left: 0; border-top-width: 4px; border-left-width: 4px; border-radius: 12px 0 0 0; }
        .corner.tr { top: 0; right: 0; border-top-width: 4px; border-right-width: 4px; border-radius: 0 12px 0 0; }
        .corner.bl { bottom: 0; left: 0; border-bottom-width: 4px; border-left-width: 4px; border-radius: 0 0 0 12px; }
        .corner.br { bottom: 0; right: 0; border-bottom-width: 4px; border-right-width: 4px; border-radius: 0 0 12px 0; }

        .scan-beam {
            position: absolute;
            top: 0; left: 6%;
            width: 88%; height: 3px;
            background: linear-gradient(90deg, transparent 0%, rgba(99,102,241,0.9) 50%, transparent 100%);
            border-radius: 3px;
            box-shadow: 0 0 16px rgba(99,102,241,0.4);
            animation: beamSweep 2s ease-in-out infinite;
        }
        @keyframes beamSweep {
            0%, 100% { top: 4px; }
            50% { top: calc(100% - 4px); }
        }

        /* Camera top overlay */
        .cam-overlay {
            position: absolute;
            top: 0; left: 0; right: 0;
            padding: 20px 28px;
            background: linear-gradient(180deg, rgba(0,0,0,0.55) 0%, transparent 100%);
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 8;
        }
        .cam-live {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .cam-live .dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #22c55e;
            box-shadow: 0 0 8px rgba(34,197,94,0.6);
            animation: liveBlink 1.4s infinite;
        }
        @keyframes liveBlink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        .cam-badge {
            font-size: 0.7rem;
            font-weight: 600;
            color: rgba(255,255,255,0.7);
            background: rgba(255,255,255,0.12);
            padding: 5px 14px;
            border-radius: 8px;
            letter-spacing: 0.3px;
        }

        /* Bottom hint */
        .scan-hint {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 0.88rem;
            color: #94a3b8;
            font-weight: 500;
        }
        .scan-hint i { color: #6366f1; font-size: 1.1rem; }

        /* ═══ RESULT OVERLAY ═══ */
        .result-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15,23,42,0.3);
            backdrop-filter: blur(12px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 200;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
            padding: 32px;
        }
        .result-overlay.visible { opacity: 1; pointer-events: auto; }

        .result-card {
            background: #fff;
            border-radius: 28px;
            width: 100%;
            max-width: 520px;
            overflow: hidden;
            box-shadow: 0 32px 100px rgba(0,0,0,0.18), 0 0 0 1px rgba(0,0,0,0.04);
            animation: cardEnter 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes cardEnter {
            from { opacity: 0; transform: scale(0.92) translateY(16px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        /* Status banner */
        .status-banner {
            padding: 28px 32px;
            display: flex;
            align-items: center;
            gap: 18px;
        }
        .status-icon {
            width: 56px; height: 56px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        .status-text h3 {
            font-size: 1.1rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-text p {
            font-size: 0.82rem;
            margin-top: 3px;
            font-weight: 500;
        }
        .status-banner.time-in { background: linear-gradient(135deg, #f0fdf4, #dcfce7); border-bottom: 1px solid #bbf7d0; }
        .status-banner.time-in .status-icon { background: #16a34a; color: #fff; }
        .status-banner.time-in .status-text h3 { color: #15803d; }
        .status-banner.time-in .status-text p { color: #16a34a; }

        .status-banner.time-out { background: linear-gradient(135deg, #fff7ed, #ffedd5); border-bottom: 1px solid #fed7aa; }
        .status-banner.time-out .status-icon { background: #ea580c; color: #fff; }
        .status-banner.time-out .status-text h3 { color: #9a3412; }
        .status-banner.time-out .status-text p { color: #c2410c; }

        .status-banner.error { background: linear-gradient(135deg, #1e293b, #0f172a); border-bottom: 1px solid #334155; }
        .status-banner.error .status-icon { background: linear-gradient(135deg, #f43f5e, #e11d48); color: #fff; box-shadow: 0 8px 16px rgba(225,29,72,0.3); }
        .status-banner.error .status-text h3 { color: #f8fafc; letter-spacing: 1px; }
        .status-banner.error .status-text p { color: #94a3b8; font-weight: 500; line-height: 1.4; }

        /* Person */
        .person-row {
            padding: 28px 32px 20px;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .p-avatar {
            width: 72px; height: 72px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            font-weight: 900;
            color: #fff;
            flex-shrink: 0;
        }
        .p-avatar.student { background: linear-gradient(135deg, #4338ca, #818cf8); box-shadow: 0 6px 20px rgba(99,102,241,0.25); }
        .p-avatar.teacher { background: linear-gradient(135deg, #d97706, #fbbf24); box-shadow: 0 6px 20px rgba(217,119,6,0.25); }
        .p-name {
            font-size: 1.4rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.03em;
            line-height: 1.2;
        }
        .p-badge {
            display: inline-block;
            margin-top: 6px;
            padding: 3px 14px;
            border-radius: 999px;
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        .p-badge.student { background: #eef2ff; color: #4338ca; }
        .p-badge.teacher { background: #fffbeb; color: #b45309; }

        /* Detail grid */
        .detail-grid {
            padding: 0 32px 20px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .detail-cell {
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            border-radius: 14px;
            padding: 16px;
        }
        .detail-cell.full { grid-column: span 2; }
        .detail-cell .d-label {
            font-size: 0.64rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #94a3b8;
            font-weight: 600;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .detail-cell .d-label i { font-size: 0.62rem; color: #cbd5e1; }
        .detail-cell .d-value {
            font-size: 0.95rem;
            font-weight: 700;
            color: #334155;
        }

        /* Time row */
        .time-row {
            padding: 0 32px 24px;
            display: flex;
            gap: 12px;
        }
        .t-block {
            flex: 1;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
        }
        .t-block .t-time {
            font-size: 1.6rem;
            font-weight: 900;
            letter-spacing: -0.5px;
        }
        .t-block .t-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .t-block.t-in {
            background: #f0fdf4;
            border: 2px solid #bbf7d0;
        }
        .t-block.t-in .t-time { color: #16a34a; }
        .t-block.t-in .t-label { color: #22c55e; }
        .t-block.t-out {
            background: #fff7ed;
            border: 2px solid #ffedd5;
        }
        .t-block.t-out .t-time { color: #ea580c; }
        .t-block.t-out .t-label { color: #f97316; }

        /* Countdown */
        .countdown-bar {
            height: 5px;
            background: #f1f5f9;
            border-radius: 0 0 28px 28px;
            overflow: hidden;
        }
        .countdown-bar .fill {
            height: 100%;
            animation: countdownShrink 3s linear forwards;
        }
        .countdown-bar .fill.green { background: linear-gradient(90deg, #22c55e, #4ade80); }
        .countdown-bar .fill.orange { background: linear-gradient(90deg, #f97316, #fb923c); }
        .countdown-bar .fill.rose { background: linear-gradient(90deg, #e11d48, #f43f5e); }
        @keyframes countdownShrink {
            from { width: 100%; }
            to { width: 0%; }
        }

        /* ═══ RESPONSIVE ═══ */
        @media (max-width: 768px) {
            .header { padding: 16px 24px; }
            .header-clock .time { font-size: 1.4rem; }
            .main { padding: 0 24px 16px; }
            .camera-box { border-radius: 24px; }
        }
        @media (max-width: 480px) {
            .header { padding: 12px 16px; }
            .brand-logo { width: 40px; height: 40px; font-size: 1rem; border-radius: 10px; }
            .brand-info h1 { font-size: 0.9rem; }
            .header-clock { display: none; }
            .no-classes-badge { padding: 6px 12px; font-size: 0.75rem; }
            .no-classes-badge i { font-size: 0.85rem; }
            .admin-btn span { display: none; }
            .main { padding: 0 12px 12px; }
            .camera-box { border-radius: 20px; }
            .scan-frame { width: 65%; height: 65%; }
        }
    </style>
</head>
<body>
    <div class="page">
        <!-- HEADER -->
        <div class="header">
            <div class="brand">
                <div class="brand-logo" <?php if ($systemLogo): ?>style="background:none;box-shadow:none;"<?php endif; ?>>
                    <?php if ($systemLogo): ?>
                        <img src="<?= htmlspecialchars($systemLogo) ?>" alt="Logo">
                    <?php else: ?>
                        <i class="fas fa-building-columns"></i>
                    <?php endif; ?>
                </div>
                <div class="brand-info">
                    <h1><?= htmlspecialchars($systemName) ?></h1>
                    <p><?= htmlspecialchars($divisionName) ?></p>
                </div>
            </div>
            <div class="header-right">
                <?php if (!$is_school_day): ?>
                <div class="no-classes-badge">
                    <i class="fas fa-calendar-xmark"></i>
                    <span><?= htmlspecialchars($non_school_reason ?? 'No Classes Today') ?></span>
                </div>
                <?php endif; ?>
                <div class="header-clock">
                    <div class="time" id="liveClock">--:--:-- --</div>
                    <div class="date" id="liveDate"></div>
                </div>

            </div>
        </div>

        <!-- SCANNER -->
        <div class="main">
            <div class="scanner-container">
                <div class="camera-box">
                    <div id="reader"></div>
                    <div class="scan-frame">
                        <div class="corner tl"></div>
                        <div class="corner tr"></div>
                        <div class="corner bl"></div>
                        <div class="corner br"></div>
                        <div class="scan-beam"></div>
                    </div>
                    <div class="cam-overlay">
                        <div class="cam-live"><div class="dot"></div> LIVE</div>
                        <div class="cam-badge"><i class="fas fa-qrcode"></i>&nbsp; QR Scanner</div>
                    </div>
                </div>
                <div class="scan-hint">
                    <i class="fas fa-expand"></i>
                    Position QR code within the frame to record attendance
                </div>
            </div>
        </div>
    </div>

    <!-- RESULT OVERLAY -->
    <div class="result-overlay" id="resultOverlay">
        <div class="result-card">
            <div class="status-banner" id="statusBanner">
                <div class="status-icon" id="statusIcon"><i class="fas fa-check"></i></div>
                <div class="status-text">
                    <h3 id="statusLabel">TIME IN</h3>
                    <p id="statusMessage">Attendance recorded</p>
                </div>
            </div>
            <div class="person-row" id="personSection">
                <div class="p-avatar" id="personAvatar"></div>
                <div>
                    <div class="p-name" id="personName"></div>
                    <div class="p-badge" id="personBadge"></div>
                </div>
            </div>
            <div class="detail-grid" id="detailGrid"></div>
            <div class="time-row" id="timeRow"></div>
            <div class="countdown-bar"><div class="fill" id="countdownFill"></div></div>
        </div>
    </div>

    <script>
    function updateClock() {
        const now = new Date();
        document.getElementById('liveClock').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
        document.getElementById('liveDate').textContent = now.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    }
    updateClock();
    setInterval(updateClock, 1000);

    // ══════════════════════════════════════════════════════════════
    // HIGH-THROUGHPUT SCANNER — optimized for 800+ devices / 20K+ scans per day
    // ══════════════════════════════════════════════════════════════

    let cooldown = false;
    let dismissTimer = null;
    let pendingRequest = null;        // Track in-flight fetch to avoid overlapping
    const recentScans = new Map();    // Dedup: qr_code -> timestamp (prevents same QR within 5s)
    const DEDUP_WINDOW = 5000;        // 5 seconds — ignore duplicate QR within this window
    const DISPLAY_TIME = 3000;        // How long to show the result overlay
    const REQUEST_TIMEOUT = 8000;     // Abort fetch if server doesn't respond in 8s
    const MAX_RETRIES = 1;            // Retry once on network failure

    const html5QrCode = new Html5Qrcode("reader");

    html5QrCode.start(
        { facingMode: "environment" },
        { fps: 10, qrbox: { width: 400, height: 400 }, aspectRatio: 1.0, disableFlip: false },
        onScanSuccess,
        () => {}
    ).catch(err => {
        console.error("Camera error:", err);
        html5QrCode.start(
            { facingMode: "user" },
            { fps: 10, qrbox: { width: 400, height: 400 }, aspectRatio: 1.0, disableFlip: false },
            onScanSuccess,
            () => {}
        );
    });

    /**
     * Clean expired entries from dedup map every 30s to prevent memory leak.
     */
    setInterval(() => {
        const now = Date.now();
        for (const [key, ts] of recentScans) {
            if (now - ts > DEDUP_WINDOW * 2) recentScans.delete(key);
        }
    }, 30000);

    function onScanSuccess(decodedText) {
        if (cooldown) return;

        // ── Dedup: ignore same QR within 5 seconds ──
        const now = Date.now();
        const lastScan = recentScans.get(decodedText);
        if (lastScan && (now - lastScan) < DEDUP_WINDOW) return;
        recentScans.set(decodedText, now);

        cooldown = true;

        sendScan(decodedText, 0);
    }

    /**
     * Send scan to server with timeout + retry support.
     */
    function sendScan(qrCode, attempt) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), REQUEST_TIMEOUT);

        fetch('api/scan_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ qr_code: qrCode }),
            signal: controller.signal
        })
        .then(r => {
            clearTimeout(timeoutId);
            if (!r.ok) throw new Error('Server ' + r.status);
            return r.json();
        })
        .then(data => {
            showResult(data);
            setTimeout(() => { cooldown = false; }, DISPLAY_TIME);
        })
        .catch(err => {
            clearTimeout(timeoutId);
            console.error('Scan error (attempt ' + (attempt+1) + '):', err);

            // Retry once on network/timeout failure
            if (attempt < MAX_RETRIES) {
                setTimeout(() => sendScan(qrCode, attempt + 1), 500);
                return;
            }

            showResult({ success: false, error: 'Network error. Please check connection and try again.' });
            setTimeout(() => { cooldown = false; }, 2000);
        });
    }

    function showResult(data) {
        clearTimeout(dismissTimer);
        const overlay = document.getElementById('resultOverlay');
        const banner = document.getElementById('statusBanner');
        const icon = document.getElementById('statusIcon');
        const label = document.getElementById('statusLabel');
        const msg = document.getElementById('statusMessage');
        const fill = document.getElementById('countdownFill');

        const card = overlay.querySelector('.result-card');
        card.style.animation = 'none'; card.offsetHeight;
        card.style.animation = 'cardEnter 0.35s cubic-bezier(0.16, 1, 0.3, 1)';
        overlay.classList.add('visible');

        if (!data.success) {
            banner.className = 'status-banner error';
            icon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
            label.textContent = 'ERROR';
            msg.textContent = data.error;
            fill.className = 'fill rose';

            if (data.person) {
                fillPerson(data.person);
                if (data.completed) {
                    document.getElementById('timeRow').innerHTML =
                        '<div class="t-block t-in"><div class="t-time">' + data.time_in + '</div><div class="t-label"><i class="fas fa-arrow-right-to-bracket"></i> Time In</div></div>' +
                        '<div class="t-block t-out"><div class="t-time">' + data.time_out + '</div><div class="t-label"><i class="fas fa-arrow-right-from-bracket"></i> Time Out</div></div>';
                } else {
                    document.getElementById('timeRow').innerHTML = '';
                }
            } else {
                document.getElementById('personSection').style.display = 'none';
                document.getElementById('detailGrid').innerHTML = '';
                document.getElementById('timeRow').innerHTML = '';
            }

            fill.style.animation = 'none'; fill.offsetHeight;
            fill.style.animation = 'countdownShrink 3s linear forwards';
            dismissTimer = setTimeout(dismissResult, 3000);
            return;
        }

        if (data.action === 'TIME_IN') {
            banner.className = 'status-banner time-in';
            icon.innerHTML = '<i class="fas fa-arrow-right-to-bracket"></i>';
            label.textContent = 'TIME IN';
            fill.className = 'fill green';
        } else {
            banner.className = 'status-banner time-out';
            icon.innerHTML = '<i class="fas fa-arrow-right-from-bracket"></i>';
            label.textContent = 'TIME OUT';
            fill.className = 'fill orange';
        }

        msg.textContent = data.message;
        fillPerson(data.person);

        let timeHTML = '';
        if (data.action === 'TIME_IN') {
            timeHTML = '<div class="t-block t-in"><div class="t-time">' + data.time + '</div><div class="t-label"><i class="fas fa-arrow-right-to-bracket"></i> Time In</div></div>';
        } else if (data.time_in) {
            timeHTML = '<div class="t-block t-in"><div class="t-time">' + data.time_in + '</div><div class="t-label"><i class="fas fa-arrow-right-to-bracket"></i> Time In</div></div>' +
                       '<div class="t-block t-out"><div class="t-time">' + data.time_out + '</div><div class="t-label"><i class="fas fa-arrow-right-from-bracket"></i> Time Out</div></div>';
        } else {
            timeHTML = '<div class="t-block t-out"><div class="t-time">' + data.time + '</div><div class="t-label"><i class="fas fa-arrow-right-from-bracket"></i> Time Out</div></div>';
        }
        document.getElementById('timeRow').innerHTML = timeHTML;

        fill.style.animation = 'none'; fill.offsetHeight;
        fill.style.animation = 'countdownShrink 3s linear forwards';
        dismissTimer = setTimeout(dismissResult, 3000);
    }

    function fillPerson(person) {
        const avatar = document.getElementById('personAvatar');
        const parts = person.name.trim().split(/\s+/);
        avatar.textContent = parts.length >= 2 ? (parts[0][0] + parts[parts.length-1][0]).toUpperCase() : person.name.substring(0,2).toUpperCase();
        avatar.className = 'p-avatar ' + person.type;

        document.getElementById('personSection').style.display = '';
        document.getElementById('personName').textContent = person.name;

        const badge = document.getElementById('personBadge');
        badge.textContent = person.type === 'student' ? 'Student' : 'Teacher';
        badge.className = 'p-badge ' + person.type;

        let grid = '<div class="detail-cell full"><div class="d-label"><i class="fas fa-school"></i> School</div><div class="d-value">' + person.school + '</div></div>';
        if (person.type === 'student') {
            grid += '<div class="detail-cell"><div class="d-label"><i class="fas fa-id-card"></i> LRN</div><div class="d-value">' + person.lrn + '</div></div>';
            grid += '<div class="detail-cell"><div class="d-label"><i class="fas fa-graduation-cap"></i> Grade & Section</div><div class="d-value">' + person.grade + ' — ' + person.section + '</div></div>';
        } else {
            grid += '<div class="detail-cell"><div class="d-label"><i class="fas fa-id-badge"></i> Employee ID</div><div class="d-value">' + person.employee_id + '</div></div>';
        }
        document.getElementById('detailGrid').innerHTML = grid;
    }

    function dismissResult() {
        document.getElementById('resultOverlay').classList.remove('visible');
    }
    </script>
<?php include __DIR__ . '/admin/includes/mobile_nav.php'; ?>
</body>
</html>