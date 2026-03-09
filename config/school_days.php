<?php
/**
 * Helper functions for determining school days (excludes weekends and holidays).
 * Supports both division-wide holidays (school_id IS NULL) and school-specific holidays.
 */

/**
 * Check if a given date is a school day (not weekend, not holiday).
 * @param string $date Date in Y-m-d format
 * @param mysqli $conn Database connection
 * @param int|null $school_id Optional school ID for school-specific holidays
 * @return bool True if it's a school day
 */
function isSchoolDay($date, $conn, $school_id = null) {
    // Check weekend (6 = Saturday, 7 = Sunday)
    $day_of_week = date('N', strtotime($date));
    if ($day_of_week >= 6) return false;

    // Check holiday: division-wide (school_id IS NULL) + school-specific
    if ($school_id) {
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM holidays WHERE holiday_date = ? AND (school_id IS NULL OR school_id = ?)");
        $stmt->bind_param("si", $date, $school_id);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM holidays WHERE holiday_date = ? AND school_id IS NULL");
        $stmt->bind_param("s", $date);
    }
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['cnt'] == 0;
}

/**
 * Check if today is a school day.
 * @param mysqli $conn Database connection
 * @param int|null $school_id Optional school ID
 * @return bool
 */
function isTodaySchoolDay($conn, $school_id = null) {
    return isSchoolDay(date('Y-m-d'), $conn, $school_id);
}

/**
 * Get the reason why a date is not a school day.
 * @param string $date Date in Y-m-d format
 * @param mysqli $conn Database connection
 * @param int|null $school_id Optional school ID for school-specific holidays
 * @return string|null Reason string, or null if it IS a school day
 */
function getNonSchoolDayReason($date, $conn, $school_id = null) {
    $day_of_week = date('N', strtotime($date));
    if ($day_of_week == 6) return 'Saturday — No Classes';
    if ($day_of_week == 7) return 'Sunday — No Classes';

    if ($school_id) {
        $stmt = $conn->prepare("SELECT name, type, school_id FROM holidays WHERE holiday_date = ? AND (school_id IS NULL OR school_id = ?) ORDER BY school_id ASC LIMIT 1");
        $stmt->bind_param("si", $date, $school_id);
    } else {
        $stmt = $conn->prepare("SELECT name, type, school_id FROM holidays WHERE holiday_date = ? AND school_id IS NULL LIMIT 1");
        $stmt->bind_param("s", $date);
    }
    $stmt->execute();
    $holiday = $stmt->get_result()->fetch_assoc();
    if ($holiday) {
        $type_label = ucfirst($holiday['type']) . ' Holiday';
        $scope = $holiday['school_id'] ? ' (School)' : '';
        return $holiday['name'] . ' — ' . $type_label . $scope;
    }
    return null;
}

/**
 * Find the previous school day before a given date.
 * @param string $date Date in Y-m-d format
 * @param mysqli $conn Database connection
 * @param int|null $school_id Optional school ID
 * @return string Previous school day in Y-m-d
 */
function getPreviousSchoolDay($date, $conn, $school_id = null) {
    $prev = date('Y-m-d', strtotime($date . ' -1 day'));
    for ($try = 0; $try < 10; $try++) {
        if (isSchoolDay($prev, $conn, $school_id)) break;
        $prev = date('Y-m-d', strtotime($prev . ' -1 day'));
    }
    return $prev;
}

/**
 * Get list of holidays for a date range.
 * @param string $start_date Y-m-d
 * @param string $end_date Y-m-d
 * @param mysqli $conn
 * @param int|null $school_id Optional school ID
 * @return array
 */
function getHolidaysInRange($start_date, $end_date, $conn, $school_id = null) {
    $holidays = [];
    if ($school_id) {
        $stmt = $conn->prepare("SELECT holiday_date, name, type, school_id FROM holidays WHERE holiday_date BETWEEN ? AND ? AND (school_id IS NULL OR school_id = ?) ORDER BY holiday_date");
        $stmt->bind_param("ssi", $start_date, $end_date, $school_id);
    } else {
        $stmt = $conn->prepare("SELECT holiday_date, name, type, school_id FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date");
        $stmt->bind_param("ss", $start_date, $end_date);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $holidays[$row['holiday_date']] = $row;
    }
    return $holidays;
}
