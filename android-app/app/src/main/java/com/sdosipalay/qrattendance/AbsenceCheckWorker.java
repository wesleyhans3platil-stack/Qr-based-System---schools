package com.sdosipalay.qrattendance;

import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Build;
import android.util.Log;

import androidx.annotation.NonNull;
import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.work.Worker;
import androidx.work.WorkerParameters;

import org.json.JSONObject;
import org.json.JSONArray;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.Calendar;

/**
 * ══════════════════════════════════════════════════════════════════
 * ABSENCE CHECK WORKER — Background notification service
 * ══════════════════════════════════════════════════════════════════
 * Runs periodically via WorkManager to check the server for
 * students/teachers absent 2+ consecutive school days.
 * Shows native Android notifications when absences are found.
 *
 * - Only checks during school hours (Mon–Fri, 9:30 AM – 5:00 PM)
 * - Won't re-notify for the same absence count on the same day
 * - Uses the session cookie to authenticate API requests
 */
public class AbsenceCheckWorker extends Worker {

    private static final String TAG = "AbsenceCheckWorker";
    private static final String CHANNEL_ID = "absence_alerts";
    private static final String CHANNEL_NAME = "Absence Alerts";
    private static final String PREFS_NAME = "QRAttendancePrefs";
    private static final int NOTIFICATION_ID_STUDENTS = 1001;
    private static final int NOTIFICATION_ID_TEACHERS = 1002;

    public AbsenceCheckWorker(@NonNull Context context, @NonNull WorkerParameters params) {
        super(context, params);
    }

    @NonNull
    @Override
    public Result doWork() {
        Log.d(TAG, "Absence check started");

        // Only check during school hours (Mon-Fri, 9:30 AM - 5:00 PM)
        Calendar now = Calendar.getInstance();
        int dayOfWeek = now.get(Calendar.DAY_OF_WEEK);
        int hour = now.get(Calendar.HOUR_OF_DAY);
        int minute = now.get(Calendar.MINUTE);

        // Skip weekends
        if (dayOfWeek == Calendar.SATURDAY || dayOfWeek == Calendar.SUNDAY) {
            Log.d(TAG, "Weekend — skipping");
            return Result.success();
        }

        // Skip outside school hours (before 9:30 AM or after 5 PM)
        if (hour < 9 || (hour == 9 && minute < 30) || hour >= 17) {
            Log.d(TAG, "Outside school hours — skipping");
            return Result.success();
        }

        // Get session cookie
        SharedPreferences prefs = getApplicationContext()
            .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE);
        String sessionCookie = prefs.getString("session_cookie", "");
        boolean loggedIn = prefs.getBoolean("logged_in", false);

        if (!loggedIn || sessionCookie.isEmpty()) {
            Log.d(TAG, "Not logged in — skipping");
            return Result.success();
        }

        try {
            // Call the absence check API
            String baseUrl = BuildConfig.BASE_URL;
            String apiUrl = baseUrl + "api/app_absence_check.php";

            URL url = new URL(apiUrl);
            HttpURLConnection conn = (HttpURLConnection) url.openConnection();
            conn.setRequestMethod("GET");
            conn.setConnectTimeout(15000);
            conn.setReadTimeout(15000);
            conn.setRequestProperty("Cookie", sessionCookie);
            conn.setRequestProperty("User-Agent", "QRAttendanceApp/1.0");

            int responseCode = conn.getResponseCode();
            if (responseCode == 200) {
                BufferedReader reader = new BufferedReader(
                    new InputStreamReader(conn.getInputStream()));
                StringBuilder response = new StringBuilder();
                String line;
                while ((line = reader.readLine()) != null) {
                    response.append(line);
                }
                reader.close();

                // Persist raw API response for verification/debugging
                try {
                    prefs.edit().putString("last_absence_response", response.toString()).apply();
                    Log.d(TAG, "Saved last_absence_response");
                } catch (Exception e) {
                    Log.w(TAG, "Could not save last_absence_response", e);
                }

                Log.d(TAG, "Absence API response: " + response.toString());

                JSONObject json = new JSONObject(response.toString());

                if (json.optBoolean("success", false)) {
                    int absentStudents = json.optInt("absent_students", 0);
                    int absentTeachers = json.optInt("absent_teachers", 0);
                    String todayDate = json.optString("date", "");

                    // Check if we already notified today with same count
                    String lastNotifKey = prefs.getString("last_notif_key", "");
                    String currentKey = todayDate + "_s" + absentStudents + "_t" + absentTeachers;
                    boolean forceNotify = prefs.getBoolean("force_absence_notify", false);

                    if (!forceNotify && currentKey.equals(lastNotifKey)) {
                        Log.d(TAG, "Already notified for: " + currentKey);
                        return Result.success();
                    }

                    // Create notification channel
                    createNotificationChannel();

                    // Show student absence notification (tap opens flagged list)
                    if (absentStudents > 0) {
                        String studentSummary = json.optString("student_summary", "");
                        String target = baseUrl + "app_dashboard.php#flagList";

                        // Build a richer bigText from student_details if available
                        String bigText = studentSummary.isEmpty()
                                ? absentStudents + " students have been absent for 2 consecutive school days."
                                : studentSummary;
                        try {
                            JSONArray details = json.optJSONArray("student_details");
                            if (details != null && details.length() > 0) {
                                StringBuilder sb = new StringBuilder();
                                sb.append("Flagged students:\n");
                                int limit = Math.min(details.length(), 6);
                                for (int i = 0; i < limit; i++) {
                                    JSONObject s = details.optJSONObject(i);
                                    if (s != null) {
                                        String name = s.optString("name", "Unknown");
                                        String code = s.optString("school_code", "");
                                        sb.append("- ").append(name);
                                        if (!code.isEmpty()) sb.append(" (" + code + ")");
                                        if (i < limit - 1) sb.append("\n");
                                    }
                                }
                                if (details.length() > limit) sb.append("\n...and " + (details.length() - limit) + " more");
                                bigText = sb.toString();
                            }
                        } catch (Exception ignored) { }

                        showNotification(
                            NOTIFICATION_ID_STUDENTS,
                            "⚠️ " + absentStudents + " Students Absent 2+ Days",
                            absentStudents + " students flagged",
                            bigText,
                            target
                        );
                    }

                    // Show teacher absence notification
                    if (absentTeachers > 0) {
                        String teacherSummary = json.optString("teacher_summary", "");
                        String targetT = baseUrl + "app_dashboard.php#teacherList";

                        String bigTextT = teacherSummary.isEmpty()
                                ? absentTeachers + " teachers have been absent for 2 consecutive school days."
                                : teacherSummary;
                        try {
                            JSONArray tdetails = json.optJSONArray("teacher_details");
                            if (tdetails != null && tdetails.length() > 0) {
                                StringBuilder sb = new StringBuilder();
                                sb.append("Flagged teachers:\n");
                                int limit = Math.min(tdetails.length(), 6);
                                for (int i = 0; i < limit; i++) {
                                    JSONObject t = tdetails.optJSONObject(i);
                                    if (t != null) {
                                        String name = t.optString("name", "Unknown");
                                        String code = t.optString("school_code", "");
                                        sb.append("- ").append(name);
                                        if (!code.isEmpty()) sb.append(" (" + code + ")");
                                        if (i < limit - 1) sb.append("\n");
                                    }
                                }
                                if (tdetails.length() > limit) sb.append("\n...and " + (tdetails.length() - limit) + " more");
                                bigTextT = sb.toString();
                            }
                        } catch (Exception ignored) { }

                        showNotification(
                            NOTIFICATION_ID_TEACHERS,
                            "📋 " + absentTeachers + " Teachers Absent 2+ Days",
                            absentTeachers + " teachers flagged",
                            bigTextT,
                            targetT
                        );
                    }

                    // Save last notification key to avoid duplicates
                    if (absentStudents > 0 || absentTeachers > 0) {
                        prefs.edit().putString("last_notif_key", currentKey).apply();
                    }

                    // If this run was forced by login, clear the force flag so we don't re-notify repeatedly
                    if (forceNotify) {
                        prefs.edit().putBoolean("force_absence_notify", false).apply();
                        Log.d(TAG, "Cleared force_absence_notify flag after sending notifications");
                    }

                    // If there are still flagged students/teachers, schedule a reminder in 5 minutes
                    if (absentStudents > 0 || absentTeachers > 0) {
                        scheduleReminder();
                    }

                    Log.d(TAG, "Checked: " + absentStudents + " students, " + absentTeachers + " teachers absent");
                } else {
                    Log.d(TAG, "API skipped: " + json.optString("message", ""));
                }
            } else {
                Log.w(TAG, "API returned: " + responseCode);
            }

            conn.disconnect();
        } catch (Exception e) {
            Log.e(TAG, "Absence check failed", e);
            return Result.retry();
        }

        return Result.success();
    }

    private void scheduleReminder() {
        // Schedule a follow-up check in 5 minutes if any students/teachers are still flagged.
        // Using a unique work name ensures we don't enqueue multiple reminders at the same time.
        try {
            Constraints constraints = new Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build();

            OneTimeWorkRequest reminder = new OneTimeWorkRequest.Builder(AbsenceCheckWorker.class)
                .setInitialDelay(5, java.util.concurrent.TimeUnit.MINUTES)
                .setConstraints(constraints)
                .addTag("absence_reminder")
                .build();

            androidx.work.WorkManager.getInstance(getApplicationContext()).enqueueUniqueWork(
                "absence_reminder",
                androidx.work.ExistingWorkPolicy.REPLACE,
                reminder
            );

            Log.d(TAG, "Scheduled absence reminder in 5 minutes");
        } catch (Exception e) {
            Log.w(TAG, "Failed to schedule absence reminder", e);
        }
    }

    private void createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel(
                CHANNEL_ID, CHANNEL_NAME, NotificationManager.IMPORTANCE_HIGH);
            channel.setDescription("Alerts for students and teachers absent 2+ consecutive days");
            channel.enableVibration(true);
            channel.setVibrationPattern(new long[]{0, 300, 200, 300});

            NotificationManager manager = getApplicationContext()
                .getSystemService(NotificationManager.class);
            if (manager != null) {
                manager.createNotificationChannel(channel);
            }
        }
    }

    private void showNotification(int notificationId, String title, String text, String bigText, String targetUrl) {
        Context context = getApplicationContext();

        // Tap opens the app and navigates to targetUrl inside WebView
        Intent intent = new Intent(context, MainActivity.class);
        intent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        if (targetUrl != null && !targetUrl.isEmpty()) {
            intent.putExtra("target_url", targetUrl);
        }
        PendingIntent pendingIntent = PendingIntent.getActivity(
            context, notificationId, intent,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);

        NotificationCompat.Builder builder = new NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle(title)
            .setContentText(text)
            .setStyle(new NotificationCompat.BigTextStyle().bigText(bigText))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setVibrate(new long[]{0, 300, 200, 300})
            .setContentIntent(pendingIntent);

        try {
            NotificationManagerCompat notifManager = NotificationManagerCompat.from(context);
            notifManager.notify(notificationId, builder.build());
        } catch (SecurityException e) {
            Log.w(TAG, "Notification permission not granted", e);
        }
    }
}
