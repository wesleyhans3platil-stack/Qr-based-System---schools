package com.sdosipalay.qrattendance;

import android.animation.AnimatorSet;
import android.animation.ObjectAnimator;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.View;
import android.view.animation.DecelerateInterpolator;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.CookieHandler;
import java.net.CookieManager;
import java.net.CookiePolicy;
import java.net.HttpCookie;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.util.List;
import java.util.Map;

/**
 * ══════════════════════════════════════════════════════════════════
 * NATIVE LOGIN ACTIVITY — Bold modern design
 * ══════════════════════════════════════════════════════════════════
 * Beautiful native login form that authenticates against the server
 * and passes the session cookie to the WebView in MainActivity.
 */
public class LoginActivity extends AppCompatActivity {

    private EditText usernameInput, passwordInput;
    private Button signInBtn;
    private ProgressBar loginProgress;
    private LinearLayout errorContainer;
    private TextView errorText;

    private static final String PREFS_NAME = "QRAttendancePrefs";
    private static final String KEY_SESSION_COOKIE = "session_cookie";
    private static final String KEY_LOGGED_IN = "logged_in";
    private static final String KEY_USER_NAME = "user_full_name";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // If already logged in with saved session, skip to main
        SharedPreferences prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
        if (prefs.getBoolean(KEY_LOGGED_IN, false)) {
            goToMain(prefs.getString(KEY_SESSION_COOKIE, ""), null);
            return;
        }

        setContentView(R.layout.activity_login);

        // Find views
        usernameInput = findViewById(R.id.usernameInput);
        passwordInput = findViewById(R.id.passwordInput);
        signInBtn = findViewById(R.id.signInBtn);
        loginProgress = findViewById(R.id.loginProgress);
        errorContainer = findViewById(R.id.errorContainer);
        errorText = findViewById(R.id.errorText);

        // Entrance animation
        animateEntrance();

        // Sign in click
        signInBtn.setOnClickListener(v -> attemptLogin());

        // Handle IME done action on password field
        passwordInput.setOnEditorActionListener((v, actionId, event) -> {
            attemptLogin();
            return true;
        });

        // Focus change animation for inputs
        View.OnFocusChangeListener focusAnim = (v, hasFocus) -> {
            if (hasFocus) {
                v.setBackgroundResource(R.drawable.bg_input_focused);
                v.animate().scaleX(1.01f).scaleY(1.01f).setDuration(200).start();
            } else {
                v.setBackgroundResource(R.drawable.bg_input);
                v.animate().scaleX(1f).scaleY(1f).setDuration(200).start();
            }
        };
        usernameInput.setOnFocusChangeListener(focusAnim);
        passwordInput.setOnFocusChangeListener(focusAnim);
    }

    private void animateEntrance() {
        View logo = findViewById(R.id.loginLogo);
        // Subtle scale animation on logo
        logo.setScaleX(0.8f);
        logo.setScaleY(0.8f);
        logo.setAlpha(0f);

        ObjectAnimator scaleX = ObjectAnimator.ofFloat(logo, "scaleX", 0.8f, 1f);
        ObjectAnimator scaleY = ObjectAnimator.ofFloat(logo, "scaleY", 0.8f, 1f);
        ObjectAnimator alpha = ObjectAnimator.ofFloat(logo, "alpha", 0f, 1f);

        AnimatorSet set = new AnimatorSet();
        set.playTogether(scaleX, scaleY, alpha);
        set.setDuration(600);
        set.setInterpolator(new DecelerateInterpolator(2f));
        set.setStartDelay(200);
        set.start();
    }

    private void attemptLogin() {
        String username = usernameInput.getText().toString().trim();
        String password = passwordInput.getText().toString();

        // Validate
        if (username.isEmpty()) {
            showError("Please enter your username.");
            usernameInput.requestFocus();
            shakeView(usernameInput);
            return;
        }
        if (password.isEmpty()) {
            showError("Please enter your password.");
            passwordInput.requestFocus();
            shakeView(passwordInput);
            return;
        }

        // Show loading state
        setLoading(true);
        hideError();

        // Perform login in background
        new Thread(() -> {
            try {
                String baseUrl = BuildConfig.BASE_URL;
                URL url = new URL(baseUrl + "app_login.php");

                // Set up cookie handling
                CookieManager cookieManager = new CookieManager();
                cookieManager.setCookiePolicy(CookiePolicy.ACCEPT_ALL);
                CookieHandler.setDefault(cookieManager);

                HttpURLConnection conn = (HttpURLConnection) url.openConnection();
                conn.setRequestMethod("POST");
                conn.setDoOutput(true);
                conn.setInstanceFollowRedirects(false);
                conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded");
                conn.setRequestProperty("User-Agent", "QRAttendanceApp/1.0 Android");
                conn.setConnectTimeout(15000);
                conn.setReadTimeout(15000);

                // POST data
                String postData = "username=" + URLEncoder.encode(username, "UTF-8") +
                                  "&password=" + URLEncoder.encode(password, "UTF-8");

                OutputStream os = conn.getOutputStream();
                os.write(postData.getBytes("UTF-8"));
                os.close();

                int responseCode = conn.getResponseCode();

                // Extract session cookie
                String sessionCookie = "";
                Map<String, List<String>> headers = conn.getHeaderFields();
                List<String> cookies = headers.get("Set-Cookie");
                if (cookies != null) {
                    for (String cookie : cookies) {
                        if (cookie.contains("PHPSESSID")) {
                            sessionCookie = cookie.split(";")[0];
                            break;
                        }
                    }
                }

                // Check if login succeeded
                // Android app gets JSON 200 response with {success: true, full_name: ...}
                if (responseCode == 200) {
                    BufferedReader reader = new BufferedReader(new InputStreamReader(conn.getInputStream()));
                    StringBuilder sb = new StringBuilder();
                    String line;
                    while ((line = reader.readLine()) != null) sb.append(line);
                    reader.close();
                    String body = sb.toString();

                    // Try to parse as JSON success response
                    try {
                        JSONObject json = new JSONObject(body);
                        if (json.optBoolean("success", false)) {
                            final String finalCookie = sessionCookie;
                            final String fullName = json.optString("full_name", "");
                            runOnUiThread(() -> {
                                SharedPreferences prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
                                prefs.edit()
                                    .putBoolean(KEY_LOGGED_IN, true)
                                    .putString(KEY_SESSION_COOKIE, finalCookie)
                                    .putString(KEY_USER_NAME, fullName)
                                    .apply();
                                goToMain(finalCookie, fullName);
                            });
                            return;
                        }
                    } catch (Exception ignored) {
                        // Not JSON — fall through to HTML error parsing
                    }

                    // HTML error response — login page returned with error
                    String errorMsg = "Invalid credentials. Please try again.";
                    if (body.contains("Account not found")) {
                        errorMsg = "Account not found.";
                    } else if (body.contains("Invalid password")) {
                        errorMsg = "Incorrect password.";
                    } else if (body.contains("Please enter both")) {
                        errorMsg = "Please enter both username and password.";
                    }

                    final String finalError = errorMsg;
                    runOnUiThread(() -> {
                        showError(finalError);
                        setLoading(false);
                        shakeView(signInBtn);
                    });
                    return;
                }

                // Also handle 302 redirect (fallback)
                if (responseCode == 302 || responseCode == 301) {
                    String location = conn.getHeaderField("Location");
                    if (location != null && location.contains("dashboard")) {
                        final String finalCookie2 = sessionCookie;
                        runOnUiThread(() -> {
                            SharedPreferences prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
                            prefs.edit()
                                .putBoolean(KEY_LOGGED_IN, true)
                                .putString(KEY_SESSION_COOKIE, finalCookie2)
                                .apply();
                            goToMain(finalCookie2, null);
                        });
                        return;
                    }
                }

                runOnUiThread(() -> {
                    showError("Server error (" + responseCode + "). Please try again.");
                    setLoading(false);
                });

            } catch (Exception e) {
                runOnUiThread(() -> {
                    showError("Connection failed. Check your network.");
                    setLoading(false);
                    shakeView(signInBtn);
                });
            }
        }).start();
    }

    private void goToMain(String sessionCookie, String fullName) {
        Intent intent = new Intent(this, MainActivity.class);
        intent.putExtra("session_cookie", sessionCookie);
        if (fullName != null && !fullName.isEmpty()) {
            intent.putExtra("user_full_name", fullName);
        }
        startActivity(intent);
        finish();
        overridePendingTransition(android.R.anim.fade_in, android.R.anim.fade_out);
    }

    private void setLoading(boolean loading) {
        signInBtn.setEnabled(!loading);
        signInBtn.setAlpha(loading ? 0.6f : 1f);
        signInBtn.setText(loading ? "Signing in..." : "Sign In");
        loginProgress.setVisibility(loading ? View.VISIBLE : View.GONE);
        usernameInput.setEnabled(!loading);
        passwordInput.setEnabled(!loading);
    }

    private void showError(String msg) {
        errorContainer.setVisibility(View.VISIBLE);
        errorText.setText(msg);
        // Slide in animation
        errorContainer.setAlpha(0f);
        errorContainer.setTranslationY(-10f);
        errorContainer.animate().alpha(1f).translationY(0f).setDuration(300).start();
    }

    private void hideError() {
        errorContainer.setVisibility(View.GONE);
    }

    private void shakeView(View v) {
        ObjectAnimator shake = ObjectAnimator.ofFloat(v, "translationX",
            0, -12, 12, -8, 8, -4, 4, 0);
        shake.setDuration(400);
        shake.start();
    }
}
