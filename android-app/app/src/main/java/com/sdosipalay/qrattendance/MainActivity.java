package com.sdosipalay.qrattendance;

import android.Manifest;
import android.app.AlertDialog;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.os.Build;
import android.os.Bundle;
import android.util.Log;
import android.view.View;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.ActivityCompat;
import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.content.ContextCompat;
import androidx.fragment.app.Fragment;
import androidx.work.Constraints;
import androidx.work.ExistingPeriodicWorkPolicy;
import androidx.work.NetworkType;
import androidx.work.PeriodicWorkRequest;
import androidx.work.WorkManager;

import com.google.android.material.bottomnavigation.BottomNavigationView;

import java.util.concurrent.TimeUnit;

public class MainActivity extends AppCompatActivity {

    private static final String TAG = "MainActivity";
    private static final String WELCOME_CHANNEL_ID = "welcome_channel";
    private static final int WELCOME_NOTIFICATION_ID = 2000;

    private BottomNavigationView bottomNav;
    private SessionManager sessionManager;
    private ApiClient apiClient;

    private final DashboardFragment dashboardFragment = new DashboardFragment();
    private final AttendanceFragment attendanceFragment = new AttendanceFragment();
    // Removed ScannerFragment (camera scanner)
    private final SchoolsFragment schoolsFragment = new SchoolsFragment();
    private final ReportsFragment reportsFragment = new ReportsFragment();
    private Fragment activeFragment;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        // Status bar
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            getWindow().setStatusBarColor(ContextCompat.getColor(this, R.color.bg_primary));
            getWindow().getDecorView().setSystemUiVisibility(
                View.SYSTEM_UI_FLAG_LAYOUT_STABLE | View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR);
        }

        // Notification permission (Android 13+)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
                    != PackageManager.PERMISSION_GRANTED) {
                ActivityCompat.requestPermissions(this,
                    new String[]{Manifest.permission.POST_NOTIFICATIONS}, 999);
            }
        }

        sessionManager = new SessionManager(this);
        apiClient = new ApiClient(BuildConfig.BASE_URL, sessionManager.getSessionCookie());

        bottomNav = findViewById(R.id.bottomNav);
        setupFragments();
        setupBottomNav();
        scheduleAbsenceCheck();
        showWelcomeNotification();
    }

    public ApiClient getApiClient() {
        return apiClient;
    }

    public SessionManager getSessionManager() {
        return sessionManager;
    }

    private void setupFragments() {
        // Add all fragments, hide all except dashboard
        getSupportFragmentManager().beginTransaction()
            .add(R.id.fragmentContainer, reportsFragment, "reports").hide(reportsFragment)
            .add(R.id.fragmentContainer, schoolsFragment, "schools").hide(schoolsFragment)
            .add(R.id.fragmentContainer, attendanceFragment, "attendance").hide(attendanceFragment)
            .add(R.id.fragmentContainer, dashboardFragment, "dashboard")
            .commit();
        activeFragment = dashboardFragment;
    }

    private void setupBottomNav() {
        bottomNav.setOnItemSelectedListener(item -> {
            Fragment selected = null;
            int id = item.getItemId();

            if (id == R.id.nav_dashboard) selected = dashboardFragment;
            else if (id == R.id.nav_attendance) selected = attendanceFragment;
            // Removed scanner tab
            else if (id == R.id.nav_schools) selected = schoolsFragment;
            else if (id == R.id.nav_reports) selected = reportsFragment;

            if (selected != null && selected != activeFragment) {
                getSupportFragmentManager().beginTransaction()
                    .hide(activeFragment)
                    .show(selected)
                    .commit();
                activeFragment = selected;
            }
            return true;
        });
    }

    public void logout() {
        sessionManager.logout();
        Intent intent = new Intent(this, LoginActivity.class);
        intent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
        startActivity(intent);
        finish();
        overridePendingTransition(android.R.anim.fade_in, android.R.anim.fade_out);
    }

    private void scheduleAbsenceCheck() {
        Constraints constraints = new Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build();

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
    }

    private void showWelcomeNotification() {
        String fullName = getIntent().getStringExtra("user_full_name");
        if (fullName == null || fullName.isEmpty()) {
            fullName = sessionManager.getFullName();
        }
        if (fullName == null || fullName.isEmpty()) return;

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel(
                WELCOME_CHANNEL_ID, "Welcome", NotificationManager.IMPORTANCE_DEFAULT);
            channel.setDescription("Welcome notifications on login");
            NotificationManager nm = getSystemService(NotificationManager.class);
            if (nm != null) nm.createNotificationChannel(channel);
        }

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

    @Override
    public void onBackPressed() {
        if (bottomNav.getSelectedItemId() != R.id.nav_dashboard) {
            bottomNav.setSelectedItemId(R.id.nav_dashboard);
        } else {
            new AlertDialog.Builder(this)
                .setTitle("Exit App")
                .setMessage("Are you sure you want to exit?")
                .setPositiveButton("Exit", (d, w) -> finish())
                .setNegativeButton("Cancel", null)
                .show();
        }
    }
}
