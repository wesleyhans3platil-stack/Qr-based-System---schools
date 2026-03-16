# QR Attendance System — Functional Logic Scenarios

This document describes how the system behaves and the expected flow of actions for each user role and feature. It does **not** reference any code or implementation details; it focuses on behavior and business logic.

---

## 1) User Login Flow

### Scenario: Admin logs into the system
- User enters username and password into the login form.
- The system validates credentials.
- If credentials are valid:
  - The user is granted access.
  - The system remembers the user’s role (e.g., super admin, principal, superintendent).
  - The user is redirected to the appropriate dashboard view.
- If credentials are invalid:
  - The user is shown a clear error message.
  - The user can retry.

---

## 2) Dashboard (Real-time Attendance Monitoring)

### Scenario: A user opens the dashboard
- The dashboard shows key attendance metrics (e.g., present, absent, flagged) for the selected day.
- The dashboard updates in near real time by periodically fetching fresh numbers.
- The user can select a date or school to filter the displayed information.

### Scenario: Attendance data changes while dashboard is open
- The system automatically detects updates and refreshes the dashboard.
- The user does not need to manually refresh the page.

---

## 3) Attendance Scanning

### Scenario: A student or teacher scans their QR code
- The QR code is read and submitted to the system.
- The system verifies the scanned code against known users.
- If the code is valid:
  - The system records the attendance (time in / time out) for that person.
  - If the person is already marked present, the system may update their time out.
- If the code is invalid:
  - The system shows an error message explaining the failure.

### Scenario: Kiosk-style scanner (continuous scan mode)
- A dedicated kiosk web page runs continuously to scan QR codes without requiring user login.
- Each scan is immediately submitted and the result (success/failure) is displayed on screen.
- The kiosk keeps scanning until stopped, allowing many students to pass through quickly.
- The system ensures each scanned ID is only recorded once per session (to prevent duplicates) and provides clear feedback if the code was already used.

---

## 4) Reporting & Exporting

### Scenario: User generates a report
- The user selects a report type and date range.
- The system generates the requested data set.
- The user can download the report as a file (e.g., CSV).
- The system ensures the download is authenticated and authorized.

---

## 5) Role-Based Access

The system enforces role-based rules at every entry point.

### Super Admin
- Full access to all schools and all reports.
- Can manage users, schools, and system settings.

### Principal
- Access limited to the principal's assigned school.
- Any data query (attendance, reports, student list) is filtered to the principal's school.

### Superintendent / Asst Superintendent
- Views are similar to super admin but may be scoped by organizational policy.
- They can access dashboards and reports for multiple schools.

---

## 6) API Endpoints & Functional Logic

### 6.1 `api/scan_attendance` (Attendance scan)
**Purpose:** Record a scanned QR code scan (student/teacher attendance).

**Logic:**
1. Validate that user is logged in (session exists).
2. Read the scanned code from the request.
3. Determine if scan is for a student or teacher.
4. Verify the scanned ID exists and is active.
5. Insert or update an attendance record for the current date:
   - If the person has not yet timed in today → record time-in.
   - If already timed in but not timed out → record time-out.
6. Return a success or error message.

### 6.2 `api/dashboard_data` (Dashboard stats)
**Purpose:** Provide full dashboard statistics in a single JSON response.

**Logic:**
1. Ensure the user is authenticated.
2. Apply role-based filters (e.g., principal sees only their school).
3. Apply optional query parameters: `date` and `school`.
4. Collect metrics including:
   - Total schools (active)
   - Total students (active + inactive)
   - Total teachers (active)
   - Students present today (timed in)
   - Students absent today (active - present)
   - Teacher attendance (present vs total)
   - School-by-school breakdown (enrollment, present, absent, rate)
   - Optional lists (inactive students, flagged students)
5. Cache the response briefly (3 seconds) to reduce DB load under heavy polling.
6. Return JSON with all computed stats.

### 6.3 `api/realtime_poll` (Lightweight polling)
**Purpose:** Provide a small “hash+counts” response so clients can detect changes quickly without fetching full data.

**Logic:**
1. Verify user is authenticated.
2. Build a short-lived hash based on:
   - Today’s attendance count
   - Most recent scan timestamp
   - Total active student count
3. Compare the hash to the client-supplied `hash` param.
4. If the hash differs, return `changed:true` plus the new hash and counts.
5. If the hash is the same, keep the request open (long polling) for up to ~25 seconds, retrying every 0.5 seconds.
6. If timeout occurs without changes, return `changed:false` and the current hash.

### 6.4 Export & Backup Endpoints
- `api/backup_manage`: Manage export and restore of backups.
- `api/backup_database`: Triggers database backup.
- `api/download_template`: Provides template downloads (e.g., CSV structure for bulk import).

These endpoints ensure only authenticated, authorized users can generate downloads.

---

## 7) Client-Side Polling / Refresh Logic

### Web UI (browser) / WebView
- The dashboard page polls `api/dashboard_data` every few seconds.
- The mobile app also uses `api/realtime_poll` for an even lighter signal.
- When new data is detected, the UI updates the visible counts and charts.

### Polling details (how the system avoids unnecessary reloads)
- The system uses a hash of key metrics.
- When nothing changes, the poll returns `changed:false`, so the UI can keep displaying the same values.
- When the data changes, the UI fetches the full dashboard JSON and updates the display.

---

## 8) Offline & Connectivity Handling

- If the client detects no network, it shows an offline overlay.
- The user can retry once the network returns.
- Attendance scans made while offline are rejected (because they cannot reach the server).

---

## 9) How New Changes Appear Without Rebuild

Because the mobile app is a WebView wrapper:
- Any change to server-side pages (HTML/CSS/JS) is immediately reflected on the device the next time the page reloads.
- Native app rebuild is only required when you change Android app logic (navigation, permissions, offline handling, etc.).

---

*This document focuses on the functional behavior and logic of the system and its endpoints.*

---

## 10) Pages & Screens (What each page is for)

### Root / Public Pages
- `admin_login` — Login page for admin users.
- `admin_register` — (If enabled) register new admin users.
- `app_login` — Mobile app login endpoint (used by the Android app for authentication).
- `app_dashboard` — Mobile dashboard view (web layout tailored for phones).
- `Qrscanattendance` — Mobile scan page used by the app for QR scanning.
- `change_password` — Change password form.

### Admin Panel Pages (Admin/ folder)
- `dashboard` — Main admin dashboard with real-time attendance stats.
- `principal_dashboard` — Dashboard view for principals (restricted to their school).
- `sds_dashboard` — Superintendent dashboard.
- `asds_dashboard` — Assistant superintendent dashboard.
- `attendance` — Attendance management page.
- `students` — Student management page (add/edit students).
- `teachers` — Teacher management page.
- `schools` — School management.
- `sections` — Sections management.
- `users` — Admin user management.
- `register_user` — Add new admin user.
- `settings` — System settings.
- `notifications` — Manage notifications.
- `user_logs` — Audit log of user actions.
- `sms_logs` — SMS send log.
- `backups` — Backup/restore interface.
- `bulk_import` — Import students, teachers, or other data via CSV.
  - Template files are provided in `templates/` (e.g., `student_import_template.csv`, `teacher_import_template.csv`, `shs_student_import_template.csv`).
  - CSV templates include required columns and a **category field** (e.g., student, teacher, SHS) so the system can place each row in the correct group.
  - The category also determines which fields are required; for example, students may require grade/section while teachers may require subject/department.
  - During import, the system detects existing records (by LRN, employee ID, or other unique identifier) and updates them instead of creating duplicates.
  - Invalid or conflicting rows are reported so the admin can correct and retry.
- `export_report`, `export_users`, `export_outside_report`, `export_not_scanned_today` — Data export endpoints.
- `print_qr` — Print student QR codes.

### API Endpoints (api/ folder)
- `scan_attendance` — Record attendance from scan.
- `dashboard_data` — Full dashboard JSON for polling.
- `realtime_poll` — Lightweight change-detection poll.
- `check_absences_notify` — Background absence notification logic.
- `sms_absence_check` — SMS alerts for absences.
- `backup_database`, `backup_manage` — Backup-related endpoints.
- `import_user` — Bulk user import.
- `push_subscribe`, `vapid_public_key` — Push notification subscription.
- `download_template`, `download_word` — Download templates and documents.
- `google_login` — Google OAuth login support.

---

*This list is intended to help you understand the purpose of each user-facing page and key API endpoint.*

---

## 6) Offline & Connectivity Handling

### Scenario: Network disconnects during use
- The system detects loss of connectivity.
- The user sees an offline notice.
- The user can retry once the connection is restored.
- Ongoing scans or actions may be queued or rejected depending on status.

---

## 7) Automatic Background Updates (Polling)

### Scenario: System keeps data fresh automatically
- The system periodically requests the latest attendance and flag counts.
- These updates occur without user action (e.g., in the background or via scheduled polling).
- The system only updates the display when new data differs from what is shown.

---

## 8) Session Management

### Scenario: User remains logged in
- The system stores session information securely.
- When the user returns to the app, their session is reused.
- If the session expires, the user is prompted to log in again.

---

## 9) Export and Backup

### Scenario: Admin triggers a backup or export
- The system prepares the requested export (database snapshot, attendance data, etc.).
- The export is made available for download.
- The system ensures the export is only accessible to authorized users.

---

### Notes
- This document describes functional behavior and high-level scenarios.
- It is intended for stakeholders who need to understand how the system behaves, without needing implementation specifics.

---

## 11) Deployment (Railway)

### Environment Setup
- Railway runs the PHP app using the project files in the repository.
- The project requires a MySQL database service, which Railway can provision or connect to an external database.
- Configuration is typically done via environment variables (Railway `Variables`):
  - `DB_HOST` (database hostname)
  - `DB_NAME` (database name)
  - `DB_USER` (database username)
  - `DB_PASS` (database password)
  - `DB_PORT` (typically 3306)
  - `BASE_URL` (public URL of the deployed app)

### Deployment Flow
1. Push commits to the repository.
2. Railway detects the push and runs the deployment pipeline.
3. Railway installs dependencies, sets up the environment, then starts the web server.
4. The app becomes available at the Railway-generated URL.

### Common Issues
- **Database connection errors**: usually caused by incorrect env vars or database not provisioned.
- **Cache/stale HTML**: the app uses browser caching; clearing cache or adding a version parameter (e.g., `v=123`) ensures users see updates.

---

## 12) Android Mobile App (WebView Wrapper)

### Purpose
- Provides a native Android launcher that wraps the web application.
- Gives a mobile-friendly entry point with offline detection, navigation, and push notification support.

### User Flow
1. User launches the app.
2. The app displays a login screen (native UI) and authenticates against the backend.
3. Once logged in, the app opens a WebView showing the dashboard or the assigned default screen.
4. Users can switch between dashboard, attendance scan, schools, and reports via bottom navigation.
5. If the device goes offline, the app shows a native offline overlay and allows retry when connectivity returns.

### Key Features
- **Session management**: Stores session cookie and reuses it for WebView requests.
- **Pull-to-refresh**: Allows manual refresh of the current screen.
- **Background polling**: Keeps dashboard data fresh by periodically calling backend endpoints.
- **File upload/download**: Supports file upload (camera + file picker) and download (reports, exports).
- **Offline overlay**: Shows an explicit offline state and retry action.

### Component Structure (High-level)
- **Login screen** (native UI)
- **Main screen** with BottomNavigation and multiple cached WebViews:
  - Dashboard view
  - Attendance scan view
  - Schools view
  - Reports view
- **Background worker** for absence notifications (runs even when app is not in foreground)

---

## 13) Database (MySQL)

### Schema & Data
- The system stores attendance data, users, schools, grades, sections, and configuration in MySQL.
- Key tables include:
  - `users` / `admins` (admin accounts and roles)
  - `students` (student details, status, school assignment)
  - `teachers` (teacher details)
  - `schools` (school information)
  - `attendance` (scan records with timestamps and attendance type)
  - `grade_levels`, `sections` (organizational data)

### Setup & Maintenance
- Initial schema is provided in the repository under `database/` (SQL dump files).
- To reset or restore the system, import the SQL file into MySQL using `mysql` CLI or a GUI.
- Backups can be generated using the backup endpoints (`backup_database`) and restored from within the admin UI.

### Connection Logic (Behavior)
- On each request, the system connects to MySQL using configured credentials.
- Queries are filtered according to user role (e.g., principal sees only their school) and date parameters.
- The system caches some endpoints (like dashboard stats) for a few seconds to reduce load.

---

*This document is intended to provide a complete functional overview of the system’s behavior, including deployment and database considerations.*
