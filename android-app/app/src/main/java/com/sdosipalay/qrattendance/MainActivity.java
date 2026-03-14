package com.sdosipalay.qrattendance;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.AlertDialog;
import android.app.DownloadManager;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.net.ConnectivityManager;
import android.net.NetworkInfo;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.os.Message;
import android.provider.MediaStore;
import android.util.Log;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.DownloadListener;
import android.webkit.GeolocationPermissions;
import android.webkit.JsResult;
import android.webkit.JsPromptResult;
import android.webkit.PermissionRequest;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
// progressBar removed per request
import android.widget.TextView;
import android.widget.Toast;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.ActivityCompat;
import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.content.ContextCompat;
import androidx.core.content.FileProvider;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;
import com.google.android.material.bottomnavigation.BottomNavigationView;
import androidx.work.Constraints;
import androidx.work.ExistingPeriodicWorkPolicy;
import androidx.work.NetworkType;
import androidx.work.PeriodicWorkRequest;
import androidx.work.WorkManager;
import androidx.work.OneTimeWorkRequest;

import java.io.File;
import java.io.IOException;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;
import java.util.concurrent.TimeUnit;

/**
 * ══════════════════════════════════════════════════════════════════
 * MAIN ACTIVITY — Full-featured WebView wrapper
 * ══════════════════════════════════════════════════════════════════
 * Loads the dashboard inside a WebView with session cookie from
 * the native LoginActivity.
 *
 * Features:
 *   - Pull-to-refresh
 *   - Offline detection with retry
 *   - Camera permission for QR scanning
 *   - File upload (<input type="file">, camera capture)
 *   - File download (CSV exports, reports, etc.)
 *   - JavaScript alert / confirm / prompt dialogs
 *   - window.open support
 *   - Geolocation permission
 *   - Background absence-check notifications via WorkManager
 */
public class MainActivity extends AppCompatActivity {

    private static final String TAG = "MainActivity";
    private static final int CAMERA_PERMISSION_REQUEST = 100;
    private static final int FILE_CHOOSER_REQUEST = 200;
    private static final String PREFS_NAME = "QRAttendancePrefs";
    private static final String WELCOME_CHANNEL_ID = "welcome_channel";
    private static final int WELCOME_NOTIFICATION_ID = 2000;

    private DashboardFragment dashboardFragment;
    private WebView webViewAttendance;
    private WebView webViewSchools;
    private WebView webViewReports;
    private WebView currentWebView;
    private BottomNavigationView bottomNav;
    private SwipeRefreshLayout swipeRefresh;
    private View offlineView;
    private Button retryButton;
    private TextView logoutOfflineBtn;

    private final Handler jsPollHandler = new Handler(Looper.getMainLooper());
    private final Runnable jsPollRunnable = new Runnable() {
        @Override
        public void run() {
            if (currentWebView != null) {
                currentWebView.post(() -> currentWebView.evaluateJavascript("if(typeof pollData=='function'){pollData();}", null));
            }
            // Poll less often to avoid UI jank on slower devices
            jsPollHandler.postDelayed(this, 10000);
        }
    };

    private void startJsPolling() {
        jsPollHandler.removeCallbacks(jsPollRunnable);
        jsPollHandler.postDelayed(jsPollRunnable, 10000);
    }

    private void stopJsPolling() {
        jsPollHandler.removeCallbacks(jsPollRunnable);
    }

    // File upload
    private ValueCallback<Uri[]> fileUploadCallback;
    private String cameraPhotoPath;

    private String pendingUrl;

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        // Edge-to-edge: transparent status bar that does not overlap WebView
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            getWindow().setStatusBarColor(android.graphics.Color.TRANSPARENT);
            getWindow().getDecorView().setSystemUiVisibility(
                View.SYSTEM_UI_FLAG_LAYOUT_STABLE
                | View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR);
        }

        // Request notification permission (Android 13+)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
                    != PackageManager.PERMISSION_GRANTED) {
                ActivityCompat.requestPermissions(this,
                    new String[]{Manifest.permission.POST_NOTIFICATIONS}, 999);
            }
        }

        // Find views
        dashboardFragment = DashboardFragment.newInstance();
        getSupportFragmentManager().beginTransaction()
            .replace(R.id.fragmentContainer, dashboardFragment)
            .commitNowAllowingStateLoss();

        webViewAttendance = findViewById(R.id.webViewAttendance);
        webViewSchools = findViewById(R.id.webViewSchools);
        webViewReports = findViewById(R.id.webViewReports);
        bottomNav = findViewById(R.id.bottomNav);
        swipeRefresh = findViewById(R.id.swipeRefresh);
        offlineView = findViewById(R.id.offlineView);
        retryButton = findViewById(R.id.retryButton);
        logoutOfflineBtn = findViewById(R.id.logoutOfflineBtn);

        currentWebView = null;
        setupWebView(webViewAttendance);
        setupWebView(webViewSchools);
        setupWebView(webViewReports);

        setupBottomNav();
        setupSwipeRefresh();
        setupButtons();
        loadApp();
        scheduleAbsenceCheck();
        showWelcomeNotification();
        // Run an immediate check once at login so admins get absence alerts like the welcome
        triggerImmediateAbsenceCheck();
        // Start periodic JS polling (will be paused when activity is not visible)
        startJsPolling();
    }

    // ═══════════════════════════════════════════════════════════
    //  WELCOME NOTIFICATION
    // ═══════════════════════════════════════════════════════════

    private void showWelcomeNotification() {
        String fullName = getIntent().getStringExtra("user_full_name");
        if (fullName == null || fullName.isEmpty()) return;

        // Create notification channel (Android 8+)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel(
                WELCOME_CHANNEL_ID, "Welcome", NotificationManager.IMPORTANCE_DEFAULT);
            channel.setDescription("Welcome notifications on login");
            NotificationManager nm = getSystemService(NotificationManager.class);
            if (nm != null) nm.createNotificationChannel(channel);
        }

        // Build the notification
        Intent intent = new Intent(this, MainActivity.class);
        intent.setFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        PendingIntent pendingIntent = PendingIntent.getActivity(this, 0, intent,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);

        NotificationCompat.Builder builder = new NotificationCompat.Builder(this, WELCOME_CHANNEL_ID)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle("Welcome, " + fullName + "!")
            .setContentText("You are now logged in to EduTrack. Have a great day!")
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .setAutoCancel(true)
            .setContentIntent(pendingIntent);

        if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
                == PackageManager.PERMISSION_GRANTED || Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) {
            NotificationManagerCompat.from(this).notify(WELCOME_NOTIFICATION_ID, builder.build());
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  SCHEDULE BACKGROUND ABSENCE NOTIFICATIONS
    // ═══════════════════════════════════════════════════════════

    private void scheduleAbsenceCheck() {
        Constraints constraints = new Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build();

        // Check every 30 minutes (minimum WorkManager interval is 15 min)
        PeriodicWorkRequest absenceWork = new PeriodicWorkRequest.Builder(
                AbsenceCheckWorker.class, 30, TimeUnit.MINUTES)
            .setConstraints(constraints)
            .addTag("absence_check")
            .build();

        WorkManager.getInstance(this).enqueueUniquePeriodicWork(
            "absence_check_periodic",
            ExistingPeriodicWorkPolicy.KEEP,
            absenceWork
        );

        Log.d(TAG, "Absence check worker scheduled (every 30 min)");
    }

    private void triggerImmediateAbsenceCheck() {
        try {
            // mark worker to force notify once on next check (useful after login)
            SharedPreferences prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
            prefs.edit().putBoolean("force_absence_notify", true).apply();

            Constraints constraints = new Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.CONNECTED)
                    .build();
            OneTimeWorkRequest req = new OneTimeWorkRequest.Builder(AbsenceCheckWorker.class)
                    .setConstraints(constraints)
                    .build();
            WorkManager.getInstance(this).enqueue(req);
            Log.d(TAG, "Immediate absence check enqueued");
        } catch (Exception e) {
            Log.w(TAG, "Failed to enqueue immediate absence check", e);
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  WEBVIEW SETUP
    // ═══════════════════════════════════════════════════════════

    @SuppressLint("SetJavaScriptEnabled")
    private void setupWebView(WebView webView) {
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setAllowFileAccess(true);
        settings.setAllowContentAccess(true);
        settings.setCacheMode(WebSettings.LOAD_DEFAULT);
        settings.setAppCacheEnabled(true); // allow caching of static assets for faster loads
        settings.setLoadWithOverviewMode(true);
        settings.setUseWideViewPort(true);
        settings.setSupportZoom(false);
        settings.setBuiltInZoomControls(false);
        settings.setDisplayZoomControls(false);
        settings.setMediaPlaybackRequiresUserGesture(false);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);
        settings.setJavaScriptCanOpenWindowsAutomatically(true);
        settings.setSupportMultipleWindows(true);
        settings.setUserAgentString(settings.getUserAgentString() + " QRAttendanceApp/1.0");

        // Cookies
        CookieManager cookieManager = CookieManager.getInstance();
        cookieManager.setAcceptCookie(true);
        cookieManager.setAcceptThirdPartyCookies(webView, true);

        // Hide visible scrollbars — keep scrolling functional
        webView.setVerticalScrollBarEnabled(false);
        webView.setHorizontalScrollBarEnabled(false);
        webView.setScrollBarSize(0);

        // ── WebViewClient ──
        webView.setWebViewClient(new WebViewClient() {
            @Override
            public void onPageStarted(WebView view, String url, Bitmap favicon) {
                // loading indicator removed — no action
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                swipeRefresh.setRefreshing(false);
                hideOffline();
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) {
                    swipeRefresh.setRefreshing(false);
                    showOffline();
                }
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                String url = request.getUrl().toString();

                // Detect logout
                if (url.contains("logout.php") || url.contains("app_login.php")) {
                    logout();
                    return true;
                }

                // Handle tel: mailto: sms:
                if (url.startsWith("tel:") || url.startsWith("mailto:") || url.startsWith("sms:")) {
                    try {
                        startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
                    } catch (ActivityNotFoundException e) {
                        Log.w(TAG, "No handler for: " + url);
                    }
                    return true;
                }

                // Keep server URLs in WebView
                String baseHost = Uri.parse(BuildConfig.BASE_URL).getHost();
                if (url.contains(baseHost) ||
                    url.contains("192.168.") || url.contains("localhost") ||
                    url.contains("Qr based System") || url.contains("Qr%20based")) {
                    return false;
                }

                // External links → browser
                try {
                    startActivity(new Intent(Intent.ACTION_VIEW, request.getUrl()));
                } catch (ActivityNotFoundException e) {
                    Log.w(TAG, "No browser for: " + url);
                }
                return true;
            }
        });

        // ── WebChromeClient (full-featured) ──
        webView.setWebChromeClient(new WebChromeClient() {

            @Override
            public void onProgressChanged(WebView view, int newProgress) {
                // progress updates removed
            }

            // ── FILE UPLOAD (<input type="file">) ──
            @Override
            public boolean onShowFileChooser(WebView webView, ValueCallback<Uri[]> filePathCallback,
                                             FileChooserParams fileChooserParams) {
                if (fileUploadCallback != null) {
                    fileUploadCallback.onReceiveValue(null);
                }
                fileUploadCallback = filePathCallback;

                String[] acceptTypes = fileChooserParams.getAcceptTypes();
                boolean acceptsImage = false;
                boolean acceptsAny = (acceptTypes == null || acceptTypes.length == 0 ||
                    (acceptTypes.length == 1 && acceptTypes[0].isEmpty()));

                if (!acceptsAny) {
                    for (String type : acceptTypes) {
                        if (type.startsWith("image/")) { acceptsImage = true; break; }
                    }
                }

                // Build file picker intent
                Intent fileIntent = new Intent(Intent.ACTION_GET_CONTENT);
                fileIntent.addCategory(Intent.CATEGORY_OPENABLE);
                if (acceptsAny) {
                    fileIntent.setType("*/*");
                } else {
                    fileIntent.setType(acceptTypes[0]);
                    if (acceptTypes.length > 1) fileIntent.putExtra(Intent.EXTRA_MIME_TYPES, acceptTypes);
                }
                if (fileChooserParams.getMode() == FileChooserParams.MODE_OPEN_MULTIPLE) {
                    fileIntent.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true);
                }

                Intent chooserIntent = Intent.createChooser(fileIntent, "Choose File");

                // Add camera option for image inputs
                if (acceptsImage || acceptsAny) {
                    try {
                        Intent cameraIntent = new Intent(MediaStore.ACTION_IMAGE_CAPTURE);
                        if (cameraIntent.resolveActivity(getPackageManager()) != null) {
                            File photoFile = createImageFile();
                            cameraPhotoPath = photoFile.getAbsolutePath();
                            Uri photoUri = FileProvider.getUriForFile(
                                MainActivity.this,
                                getApplicationContext().getPackageName() + ".fileprovider",
                                photoFile);
                            cameraIntent.putExtra(MediaStore.EXTRA_OUTPUT, photoUri);
                            chooserIntent.putExtra(Intent.EXTRA_INITIAL_INTENTS, new Intent[]{cameraIntent});
                        }
                    } catch (IOException e) {
                        Log.e(TAG, "Create image file failed", e);
                    }
                }

                try {
                    startActivityForResult(chooserIntent, FILE_CHOOSER_REQUEST);
                } catch (ActivityNotFoundException e) {
                    fileUploadCallback = null;
                    Toast.makeText(MainActivity.this, "No file manager found", Toast.LENGTH_SHORT).show();
                    return false;
                }
                return true;
            }

            // ── CAMERA / MEDIA PERMISSIONS ──
            @Override
            public void onPermissionRequest(final PermissionRequest request) {
                runOnUiThread(() -> {
                    String[] resources = request.getResources();
                    for (String resource : resources) {
                        if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(resource)) {
                            if (checkCameraPermission()) {
                                request.grant(new String[]{resource});
                            } else {
                                requestCameraPermission();
                            }
                            return;
                        }
                        if (PermissionRequest.RESOURCE_AUDIO_CAPTURE.equals(resource)) {
                            request.grant(new String[]{resource});
                            return;
                        }
                    }
                    request.grant(resources);
                });
            }

            // ── GEOLOCATION ──
            @Override
            public void onGeolocationPermissionsShowPrompt(String origin,
                    GeolocationPermissions.Callback callback) {
                callback.invoke(origin, true, false);
            }

            // ── JAVASCRIPT ALERT ──
            @Override
            public boolean onJsAlert(WebView view, String url, String message, final JsResult result) {
                new AlertDialog.Builder(MainActivity.this)
                    .setTitle("Alert")
                    .setMessage(message)
                    .setPositiveButton("OK", (d, w) -> result.confirm())
                    .setCancelable(false)
                    .show();
                return true;
            }

            // ── JAVASCRIPT CONFIRM ──
            @Override
            public boolean onJsConfirm(WebView view, String url, String message, final JsResult result) {
                new AlertDialog.Builder(MainActivity.this)
                    .setTitle("Confirm")
                    .setMessage(message)
                    .setPositiveButton("OK", (d, w) -> result.confirm())
                    .setNegativeButton("Cancel", (d, w) -> result.cancel())
                    .setCancelable(false)
                    .show();
                return true;
            }

            // ── JAVASCRIPT PROMPT ──
            @Override
            public boolean onJsPrompt(WebView view, String url, String message,
                                       String defaultValue, final JsPromptResult result) {
                final EditText input = new EditText(MainActivity.this);
                input.setText(defaultValue);
                input.setPadding(50, 30, 50, 30);
                new AlertDialog.Builder(MainActivity.this)
                    .setTitle("Input")
                    .setMessage(message)
                    .setView(input)
                    .setPositiveButton("OK", (d, w) -> result.confirm(input.getText().toString()))
                    .setNegativeButton("Cancel", (d, w) -> result.cancel())
                    .setCancelable(false)
                    .show();
                return true;
            }

            // ── WINDOW.OPEN ──
            @Override
            public boolean onCreateWindow(WebView view, boolean isDialog,
                                          boolean isUserGesture, Message resultMsg) {
                WebView.HitTestResult hitResult = view.getHitTestResult();
                String url = hitResult.getExtra();
                if (url != null) {
                    view.loadUrl(url);
                } else {
                    WebView tempWebView = new WebView(MainActivity.this);
                    tempWebView.setWebViewClient(new WebViewClient() {
                        @Override
                        public boolean shouldOverrideUrlLoading(WebView v, WebResourceRequest request) {
                            webView.loadUrl(request.getUrl().toString());
                            return true;
                        }
                    });
                    WebView.WebViewTransport transport = (WebView.WebViewTransport) resultMsg.obj;
                    transport.setWebView(tempWebView);
                    resultMsg.sendToTarget();
                    return true;
                }
                return false;
            }

            @Override
            public void onCloseWindow(WebView window) { }
        });

        // ── DOWNLOAD LISTENER (CSV exports, reports, etc.) ──
        webView.setDownloadListener((url, userAgent, contentDisposition, mimeType, contentLength) -> {
            try {
                DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));

                // Pass cookies so authenticated downloads work
                String cookies = CookieManager.getInstance().getCookie(url);
                if (cookies != null) request.addRequestHeader("Cookie", cookies);
                request.addRequestHeader("User-Agent", userAgent);

                String filename = URLUtil.guessFileName(url, contentDisposition, mimeType);
                request.setTitle(filename);
                request.setDescription("Downloading...");
                request.setMimeType(mimeType);
                request.allowScanningByMediaScanner();
                request.setNotificationVisibility(
                    DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, filename);

                DownloadManager dm = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
                if (dm != null) {
                    dm.enqueue(request);
                    Toast.makeText(MainActivity.this, "Downloading: " + filename, Toast.LENGTH_SHORT).show();
                }
            } catch (Exception e) {
                Log.e(TAG, "Download failed", e);
                try {
                    startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
                } catch (ActivityNotFoundException ex) {
                    Toast.makeText(MainActivity.this, "Cannot download file", Toast.LENGTH_SHORT).show();
                }
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    //  SWIPE TO REFRESH
    // ═══════════════════════════════════════════════════════════

    private void setupSwipeRefresh() {
        swipeRefresh.setColorSchemeColors(ContextCompat.getColor(this, R.color.orange_500));
        swipeRefresh.setOnRefreshListener(() -> {
            if (isNetworkAvailable()) {
                if (currentWebView != null) {
                    currentWebView.reload();
                }
            } else {
                swipeRefresh.setRefreshing(false);
                showOffline();
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    //  BUTTONS
    // ═══════════════════════════════════════════════════════════

    private void setupButtons() {
        retryButton.setOnClickListener(v -> {
            if (isNetworkAvailable()) { hideOffline(); if (currentWebView != null) currentWebView.reload(); }
        });
        if (logoutOfflineBtn != null) {
            logoutOfflineBtn.setOnClickListener(v -> logout());
        }
    }

    private void setupBottomNav() {
        bottomNav.setOnItemSelectedListener(item -> {
            selectTab(item.getItemId());
            return true;
        });
        // Default to dashboard
        bottomNav.setSelectedItemId(R.id.nav_dashboard);
    }

    private void selectTab(int itemId) {
        if (itemId == R.id.nav_dashboard) {
            // show native dashboard fragment
            showFragment();
            return;
        }

        // show the appropriate webview for other tabs
        hideFragment();
        WebView target = getWebViewForTab(itemId);
        if (target == null) return;

        if (currentWebView != null && currentWebView != target) {
            currentWebView.setVisibility(View.GONE);
        }
        target.setVisibility(View.VISIBLE);
        currentWebView = target;

        String url = getUrlForTab(itemId);
        if (url != null) {
            ensureUrlLoaded(target, url);
        }
    }

    private void showFragment() {
        hideAllWebViews();
        findViewById(R.id.fragmentContainer).setVisibility(View.VISIBLE);
        currentWebView = null;
    }

    private void hideFragment() {
        findViewById(R.id.fragmentContainer).setVisibility(View.GONE);
    }

    private void hideAllWebViews() {
        if (webViewAttendance != null) webViewAttendance.setVisibility(View.GONE);
        if (webViewSchools != null) webViewSchools.setVisibility(View.GONE);
        if (webViewReports != null) webViewReports.setVisibility(View.GONE);
    }

    private WebView getWebViewForTab(int itemId) {
        switch (itemId) {
            case R.id.nav_attendance:
                return webViewAttendance;
            case R.id.nav_schools:
                return webViewSchools;
            case R.id.nav_reports:
                return webViewReports;
            case R.id.nav_dashboard:
            default:
                return webViewDashboard;
        }
    }

    private String getUrlForTab(int itemId) {
        String base = BuildConfig.BASE_URL;
        switch (itemId) {
            case R.id.nav_attendance:
                return base + "Qrscanattendance.php";
            case R.id.nav_schools:
                return base + "admin/schools.php";
            case R.id.nav_reports:
                return base + "admin/reports.php";
            case R.id.nav_dashboard:
            default:
                return base + "app_dashboard.php";
        }
    }

    private void ensureUrlLoaded(WebView webView, String url) {
        if (webView == null || url == null) return;
        String current = webView.getUrl();
        if (current == null || !current.startsWith(url)) {
            webView.clearCache(true);
            webView.clearHistory();
            webView.loadUrl(addVersionParam(url));
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  LOAD APP WITH SESSION COOKIE
    // ═══════════════════════════════════════════════════════════

    private void loadApp() {
        String baseUrl = BuildConfig.BASE_URL;
        // Allow notifications or external intents to override target URL
        String target = getIntent().getStringExtra("target_url");
        if (target != null && !target.isEmpty()) {
            pendingUrl = addVersionParam(target);
            // If the app is opened with a specific section, navigate to that tab
            if (target.contains("Qrscanattendance")) {
                bottomNav.setSelectedItemId(R.id.nav_attendance);
            } else if (target.contains("/admin/schools")) {
                bottomNav.setSelectedItemId(R.id.nav_schools);
            } else if (target.contains("/admin/reports")) {
                bottomNav.setSelectedItemId(R.id.nav_reports);
            } else {
                bottomNav.setSelectedItemId(R.id.nav_dashboard);
            }
        } else {
            pendingUrl = addVersionParam(baseUrl + "app_dashboard.php");
            bottomNav.setSelectedItemId(R.id.nav_dashboard);
        }

        String sessionCookie = getIntent().getStringExtra("session_cookie");
        if (sessionCookie != null && !sessionCookie.isEmpty()) {
            CookieManager cm = CookieManager.getInstance();
            cm.setCookie(baseUrl, sessionCookie);
            cm.flush();
        }

        if (isNetworkAvailable()) {
            if (currentWebView != null) {
                currentWebView.clearCache(true);
                currentWebView.clearHistory();
                currentWebView.loadUrl(pendingUrl);
            }
        } else {
            showOffline();
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        // Ensure JS polling continues when returning to the app
        jsPollHandler.postDelayed(jsPollRunnable, 1000);
    }

    @Override
    protected void onPause() {
        super.onPause();
        jsPollHandler.removeCallbacks(jsPollRunnable);
    }

    private String addVersionParam(String url) {
        // Append version code (or timestamp) to force the WebView to reload fresh HTML.
        String param = "v=" + BuildConfig.VERSION_CODE;
        if (url.contains("?")) {
            return url + "&" + param;
        }
        return url + "?" + param;
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        String target = intent.getStringExtra("target_url");
        if (target != null && !target.isEmpty()) {
            String versioned = addVersionParam(target);
            if (isNetworkAvailable()) {
                if (currentWebView != null) {
                    currentWebView.loadUrl(versioned);
                }
                hideOffline();
            } else {
                // store for later
                pendingUrl = versioned;
            }
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  FILE UPLOAD RESULT
    // ═══════════════════════════════════════════════════════════

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);

        if (requestCode == FILE_CHOOSER_REQUEST) {
            if (fileUploadCallback == null) return;
            Uri[] results = null;

            if (resultCode == RESULT_OK) {
                if (data != null) {
                    if (data.getClipData() != null) {
                        int count = data.getClipData().getItemCount();
                        results = new Uri[count];
                        for (int i = 0; i < count; i++) {
                            results[i] = data.getClipData().getItemAt(i).getUri();
                        }
                    } else if (data.getDataString() != null) {
                        results = new Uri[]{Uri.parse(data.getDataString())};
                    }
                }
                // Camera capture fallback
                if (results == null && cameraPhotoPath != null) {
                    File photoFile = new File(cameraPhotoPath);
                    if (photoFile.exists() && photoFile.length() > 0) {
                        results = new Uri[]{Uri.fromFile(photoFile)};
                    }
                }
            }

            fileUploadCallback.onReceiveValue(results);
            fileUploadCallback = null;
            cameraPhotoPath = null;
        }
    }

    // ── Create temp image file for camera capture ──
    private File createImageFile() throws IOException {
        String timeStamp = new SimpleDateFormat("yyyyMMdd_HHmmss", Locale.US).format(new Date());
        File storageDir = getExternalFilesDir(Environment.DIRECTORY_PICTURES);
        return File.createTempFile("QR_" + timeStamp + "_", ".jpg", storageDir);
    }

    // ═══════════════════════════════════════════════════════════
    //  CAMERA PERMISSION
    // ═══════════════════════════════════════════════════════════

    private boolean checkCameraPermission() {
        return ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA)
                == PackageManager.PERMISSION_GRANTED;
    }

    private void requestCameraPermission() {
        ActivityCompat.requestPermissions(this,
            new String[]{Manifest.permission.CAMERA}, CAMERA_PERMISSION_REQUEST);
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, @NonNull String[] permissions,
            @NonNull int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == CAMERA_PERMISSION_REQUEST) {
            if (grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                webView.reload();
            }
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════

    private boolean isNetworkAvailable() {
        ConnectivityManager cm = (ConnectivityManager) getSystemService(CONNECTIVITY_SERVICE);
        if (cm != null) {
            NetworkInfo activeNetwork = cm.getActiveNetworkInfo();
            return activeNetwork != null && activeNetwork.isConnected();
        }
        return false;
    }

    private void showOffline() {
        offlineView.setVisibility(View.VISIBLE);
        if (currentWebView != null) currentWebView.setVisibility(View.GONE);
    }

    private void hideOffline() {
        offlineView.setVisibility(View.GONE);
        if (currentWebView != null) currentWebView.setVisibility(View.VISIBLE);
    }

    private void logout() {
        SharedPreferences prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
        prefs.edit().clear().apply();
        CookieManager.getInstance().removeAllCookies(null);
        CookieManager.getInstance().flush();
        Intent intent = new Intent(this, LoginActivity.class);
        intent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
        startActivity(intent);
        finish();
        overridePendingTransition(android.R.anim.fade_in, android.R.anim.fade_out);
    }

    // ═══════════════════════════════════════════════════════════
    //  BACK BUTTON
    // ═══════════════════════════════════════════════════════════

    @Override
    public void onBackPressed() {
        if (currentWebView != null && currentWebView.canGoBack()) {
            currentWebView.goBack();
        } else {
            new AlertDialog.Builder(this)
                .setTitle("Exit App")
                .setMessage("Are you sure you want to exit?")
                .setPositiveButton("Exit", (d, w) -> finish())
                .setNegativeButton("Cancel", null)
                .show();
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  LIFECYCLE
    // ═══════════════════════════════════════════════════════════

    @Override
    protected void onResume() {
        super.onResume();
        if (currentWebView != null) currentWebView.onResume();
        CookieManager.getInstance().flush();
        startJsPolling();
    }

    @Override
    protected void onPause() {
        super.onPause();
        if (currentWebView != null) currentWebView.onPause();
        CookieManager.getInstance().flush();
        stopJsPolling();
    }

    @Override
    protected void onDestroy() {
        if (webViewDashboard != null) webViewDashboard.destroy();
        if (webViewAttendance != null) webViewAttendance.destroy();
        if (webViewSchools != null) webViewSchools.destroy();
        if (webViewReports != null) webViewReports.destroy();
        super.onDestroy();
    }
}
