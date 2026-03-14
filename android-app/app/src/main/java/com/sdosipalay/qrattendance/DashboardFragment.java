package com.sdosipalay.qrattendance;

import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.fragment.app.Fragment;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;

/**
 * Native dashboard fragment with live polling from the dashboard API.
 */
public class DashboardFragment extends Fragment {

    private static final long POLL_INTERVAL_MS = 10_000;

    private TextView statSchools;
    private TextView statPresent;
    private TextView statAbsent;
    private TextView statFlagged;

    private final Handler pollHandler = new Handler(Looper.getMainLooper());
    private final Runnable pollRunnable = new Runnable() {
        @Override
        public void run() {
            fetchDashboardStats();
            pollHandler.postDelayed(this, POLL_INTERVAL_MS);
        }
    };

    public static DashboardFragment newInstance() {
        return new DashboardFragment();
    }

    @Nullable
    @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container,
                             @Nullable Bundle savedInstanceState) {
        View view = inflater.inflate(R.layout.fragment_dashboard, container, false);
        statSchools = view.findViewById(R.id.statSchools);
        statPresent = view.findViewById(R.id.statPresent);
        statAbsent = view.findViewById(R.id.statAbsent);
        statFlagged = view.findViewById(R.id.statFlagged);

        statSchools.setText("—");
        statPresent.setText("—");
        statAbsent.setText("—");
        statFlagged.setText("—");

        return view;
    }

    @Override
    public void onResume() {
        super.onResume();
        pollHandler.post(pollRunnable);
    }

    @Override
    public void onPause() {
        super.onPause();
        pollHandler.removeCallbacks(pollRunnable);
    }

    private void fetchDashboardStats() {
        new Thread(() -> {
            try {
                String baseUrl = BuildConfig.BASE_URL;
                URL url = new URL(baseUrl + "api/dashboard_data.php");
                HttpURLConnection conn = (HttpURLConnection) url.openConnection();
                conn.setRequestMethod("GET");
                conn.setConnectTimeout(15000);
                conn.setReadTimeout(15000);
                conn.setUseCaches(false);

                // Attach session cookie if available
                String sessionCookie = new SessionManager(requireContext()).getSessionCookie();
                if (sessionCookie != null && !sessionCookie.isEmpty()) {
                    conn.setRequestProperty("Cookie", sessionCookie);
                }

                int code = conn.getResponseCode();
                if (code != 200) {
                    conn.disconnect();
                    return;
                }

                BufferedReader reader = new BufferedReader(new InputStreamReader(conn.getInputStream()));
                StringBuilder sb = new StringBuilder();
                String line;
                while ((line = reader.readLine()) != null) {
                    sb.append(line);
                }
                reader.close();
                conn.disconnect();

                JSONObject json = new JSONObject(sb.toString());
                JSONObject stats = json.optJSONObject("stats");
                if (stats == null) return;

                final int totalSchools = stats.optInt("total_schools", 0);
                final int present = stats.optInt("timed_in_today", 0);
                final int absent = stats.optInt("absent_today", 0);
                final int flagged = stats.optInt("flag_count", 0);

                requireActivity().runOnUiThread(() -> updateStats(totalSchools, present, absent, flagged));
            } catch (Exception ignored) {
            }
        }).start();
    }

    public void updateStats(int schools, int present, int absent, int flagged) {
        if (statSchools != null) statSchools.setText(String.valueOf(schools));
        if (statPresent != null) statPresent.setText(String.valueOf(present));
        if (statAbsent != null) statAbsent.setText(String.valueOf(absent));
        if (statFlagged != null) statFlagged.setText(String.valueOf(flagged));
    }
}
