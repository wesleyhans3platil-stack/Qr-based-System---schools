<?php
session_start();
require_once '../config/database.php';
$conn = getDBConnection();

if (!isset($_SESSION['admin_id'])) { header('Location: ../admin_login.php'); exit; }
$report_type = sanitize($_GET['report'] ?? 'daily');
$filter_school = (int)($_GET['school'] ?? 0);
$filter_date = sanitize($_GET['date'] ?? date('Y-m-d'));
$filter_from = sanitize($_GET['from'] ?? date('Y-m-01'));
$filter_to = sanitize($_GET['to'] ?? date('Y-m-d'));
$format = sanitize($_GET['format'] ?? 'csv');

$admin_role = $_SESSION['admin_role'] ?? 'super_admin';
$admin_school_id = $_SESSION['admin_school_id'] ?? null;

$school_cond = '';
if ($admin_role === 'principal' && $admin_school_id) $school_cond = " AND a.school_id = $admin_school_id";
if ($filter_school) $school_cond .= " AND a.school_id = $filter_school";

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="attendance_report_' . $report_type . '_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

switch ($report_type) {
    case 'daily':
        fputcsv($output, ['School', 'Code', 'Enrolled', 'Present', 'Late', 'Absent', 'Rate (%)', 'Teachers Present', 'Total Teachers']);
        $sql = "SELECT sch.name, sch.code,
                    (SELECT COUNT(*) FROM students st WHERE st.school_id = sch.id AND st.status='active') as enrolled,
                    COUNT(DISTINCT CASE WHEN a.person_type='student' AND a.time_in IS NOT NULL THEN a.person_id END) as present,
                    COUNT(DISTINCT CASE WHEN a.person_type='student' AND a.status='late' THEN a.person_id END) as late_count,
                    COUNT(DISTINCT CASE WHEN a.person_type='teacher' AND a.time_in IS NOT NULL THEN a.person_id END) as teachers_present,
                    (SELECT COUNT(*) FROM teachers t WHERE t.school_id = sch.id AND t.status='active') as total_teachers
                FROM schools sch LEFT JOIN attendance a ON a.school_id = sch.id AND a.date = '$filter_date'
                WHERE sch.status='active' GROUP BY sch.id ORDER BY sch.name";
        $r = $conn->query($sql);
        if ($r) while ($row = $r->fetch_assoc()) {
            $absent = $row['enrolled'] - $row['present'];
            $rate = $row['enrolled'] > 0 ? round(($row['present'] / $row['enrolled']) * 100, 1) : 0;
            fputcsv($output, [$row['name'], $row['code'], $row['enrolled'], $row['present'], $row['late_count'], $absent, $rate, $row['teachers_present'], $row['total_teachers']]);
        }
        break;

    case 'absentees':
        fputcsv($output, ['LRN', 'Name', 'School', 'Grade', 'Section']);
        $sql = "SELECT s.lrn, s.name, sch.name as school_name, gl.name as grade_name, sec.name as section_name
                FROM students s LEFT JOIN schools sch ON s.school_id = sch.id LEFT JOIN grade_levels gl ON s.grade_level_id = gl.id LEFT JOIN sections sec ON s.section_id = sec.id
                WHERE s.status='active' AND s.id NOT IN (SELECT person_id FROM attendance WHERE person_type='student' AND date='$filter_date')
                " . ($filter_school ? "AND s.school_id = $filter_school" : "") . " ORDER BY sch.name, s.name";
        $r = $conn->query($sql);
        if ($r) while ($row = $r->fetch_assoc()) fputcsv($output, array_values($row));
        break;

    case 'weekly':
    case 'monthly':
        fputcsv($output, ['Date', 'Day', 'Present', 'Late']);
        $sql = "SELECT a.date, COUNT(DISTINCT CASE WHEN a.person_type='student' AND a.time_in IS NOT NULL THEN a.person_id END) as present,
                    COUNT(DISTINCT CASE WHEN a.person_type='student' AND a.status='late' THEN a.person_id END) as late_count
                FROM attendance a WHERE a.date BETWEEN '$filter_from' AND '$filter_to' $school_cond GROUP BY a.date ORDER BY a.date";
        $r = $conn->query($sql);
        if ($r) while ($row = $r->fetch_assoc()) fputcsv($output, [$row['date'], date('l', strtotime($row['date'])), $row['present'], $row['late_count']]);
        break;

    default:
        fputcsv($output, ['No data']);
}

fclose($output);
exit;
?>
