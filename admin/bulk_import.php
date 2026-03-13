<?php
session_start();
require_once '../config/database.php';
$conn = getDBConnection();

// Auth guard
if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['admin_role'], ['super_admin', 'principal'])) {
    header('Location: ../admin_login.php');
    exit;
}

$current_page = 'bulk_import';
$page_title = 'Bulk Import';
$success = '';
$error = '';
$imported = 0;
$skipped = 0;
$errors_list = [];
$preview_data = [];
$show_preview = false;
$import_type = $_POST['import_type'] ?? $_SESSION['import_type'] ?? 'students';
$active_tab = $import_type;

// ─── Parse XLSX helper ────────────────────────────────
function parseXLSX($filepath) {
    $rows = [];
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) return false;

    // Read shared strings
    $strings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = new SimpleXMLElement($ssXml);
        foreach ($ss->si as $si) {
            $text = '';
            if (isset($si->t)) {
                $text = (string)$si->t;
            } elseif (isset($si->r)) {
                foreach ($si->r as $run) {
                    $text .= (string)$run->t;
                }
            }
            $strings[] = $text;
        }
    }

    // Read first worksheet
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetXml) { $zip->close(); return false; }

    $sheet = new SimpleXMLElement($sheetXml);
    foreach ($sheet->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            $value = '';
            if (isset($cell->v)) {
                $value = (string)$cell->v;
                $attrs = $cell->attributes();
                if (isset($attrs['t']) && (string)$attrs['t'] === 's') {
                    $value = $strings[(int)$value] ?? '';
                }
            }
            // Parse cell reference (e.g. "C2") to determine column index
            $ref = (string)($cell->attributes()['r'] ?? '');
            $colLetters = preg_replace('/[0-9]+/', '', $ref);
            $colIndex = 0;
            for ($ci = 0; $ci < strlen($colLetters); $ci++) {
                $colIndex = $colIndex * 26 + (ord(strtoupper($colLetters[$ci])) - 64);
            }
            $colIndex--; // 0-based
            // Pad with empty strings for any skipped columns
            while (count($rowData) < $colIndex) {
                $rowData[] = '';
            }
            $rowData[$colIndex] = $value;
        }
        $rows[] = $rowData;
    }

    $zip->close();
    return $rows;
}

// ─── Parse uploaded file ──────────────────────────────
function parseUploadedFile($file) {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx'])) return ['error' => 'Only CSV and Excel (.xlsx) files are accepted.'];

    $rows = [];
    if ($ext === 'csv') {
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle) { while (($data = fgetcsv($handle)) !== false) $rows[] = $data; fclose($handle); }
    } else {
        $rows = parseXLSX($file['tmp_name']);
        if ($rows === false) return ['error' => 'Failed to parse Excel file.'];
    }
    return ['rows' => $rows, 'ext' => $ext];
}

// ═══════════════════════════════════════════════════════
// STUDENT Preview
// ═══════════════════════════════════════════════════════
if (isset($_POST['preview']) && $_POST['import_type'] === 'students' && isset($_FILES['import_file'])) {
    $school_id = (int)($_POST['school_id'] ?? 0);
    $grade_level_id = (int)($_POST['grade_level_id'] ?? 0);
    $section_id = (int)($_POST['section_id'] ?? 0);

    $parsed = parseUploadedFile($_FILES['import_file']);
    if (isset($parsed['error'])) {
        $error = $parsed['error'];
    } else {
        $rows = $parsed['rows'];
        if (count($rows) > 1) {
            $tmp_path = sys_get_temp_dir() . '/qr_import_' . session_id() . '.' . $parsed['ext'];
            move_uploaded_file($_FILES['import_file']['tmp_name'], $tmp_path);
            $_SESSION['import_tmp_file'] = $tmp_path;
            $_SESSION['import_ext'] = $parsed['ext'];
            $_SESSION['import_school_id'] = $school_id;
            $_SESSION['import_grade_level_id'] = $grade_level_id;
            $_SESSION['import_section_id'] = $section_id;
                // Default import active_from to today (or system launch date if later) so newly imported students are not marked absent immediately.
                $sys_launch = null;
                $r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='launch_start_date'");
                if ($r && $row = $r->fetch_assoc()) $sys_launch = $row['setting_value'];
                $today = date('Y-m-d');
                $default_active_from = $today;
                if ($sys_launch && $sys_launch > $default_active_from) {
                    $default_active_from = $sys_launch;
                }
                $posted_af = trim($_POST['import_active_from'] ?? '');
                $_SESSION['import_active_from'] = $posted_af !== '' ? $posted_af : $default_active_from;
            $_SESSION['import_type'] = 'students';

            array_shift($rows);
            foreach ($rows as $i => $row) {
                $lrn = trim($row[0] ?? '');
                $name = trim($row[1] ?? '');
                $status = 'ready'; $note = '';

                // Support 6-col (LRN, Name, School, Grade, Section, Guardian) or 3-col (LRN, Name, Guardian)
                $file_school = ''; $file_grade = ''; $file_section = ''; $guardian = '';
                $resolved_school_id = $school_id;
                $resolved_grade_id = $grade_level_id;
                $resolved_section_id = $section_id;

                if (count($row) >= 4) {
                    $file_school = trim($row[2] ?? '');
                    $file_grade = trim($row[3] ?? '');
                    $file_section = trim($row[4] ?? '');
                    $guardian = trim($row[5] ?? '');
                } else {
                    $guardian = trim($row[2] ?? '');
                }

                // Resolve school from file
                if (!empty($file_school)) {
                    $sch_stmt = $conn->prepare("SELECT id FROM schools WHERE name = ? AND status='active'");
                    $sch_stmt->bind_param("s", $file_school); $sch_stmt->execute();
                    $sch_res = $sch_stmt->get_result();
                    if ($sch_row = $sch_res->fetch_assoc()) {
                        $resolved_school_id = $sch_row['id'];
                    } else {
                        $status = 'error'; $note = 'School not found: ' . $file_school;
                    }
                }

                // Resolve grade from file
                if (!empty($file_grade) && $status !== 'error') {
                    $gr_stmt = $conn->prepare("SELECT id FROM grade_levels WHERE name = ?");
                    $gr_stmt->bind_param("s", $file_grade); $gr_stmt->execute();
                    $gr_res = $gr_stmt->get_result();
                    if ($gr_row = $gr_res->fetch_assoc()) {
                        $resolved_grade_id = $gr_row['id'];
                    } else {
                        $status = 'error'; $note = 'Grade not found: ' . $file_grade;
                    }
                }

                // Resolve section from file
                if (!empty($file_section) && $status !== 'error' && $resolved_school_id && $resolved_grade_id) {
                    $sec_stmt = $conn->prepare("SELECT id FROM sections WHERE name = ? AND school_id = ? AND grade_level_id = ? AND status='active'");
                    $sec_stmt->bind_param("sii", $file_section, $resolved_school_id, $resolved_grade_id); $sec_stmt->execute();
                    $sec_res = $sec_stmt->get_result();
                    if ($sec_row = $sec_res->fetch_assoc()) {
                        $resolved_section_id = $sec_row['id'];
                    } else {
                        // Section will be auto-created on confirm
                        $resolved_section_id = -1; // placeholder
                        $note = 'Section "' . $file_section . '" will be auto-created';
                    }
                }

                // Validate required fields
                if (empty($lrn) || empty($name)) {
                    $status = 'error'; $note = 'Missing LRN or Name';
                } elseif (!$resolved_school_id || !$resolved_grade_id || (!$resolved_section_id && $resolved_section_id !== -1)) {
                    if ($status !== 'error') {
                        $missing = [];
                        if (!$resolved_school_id) $missing[] = 'School';
                        if (!$resolved_grade_id) $missing[] = 'Grade';
                        if (!$resolved_section_id && $resolved_section_id !== -1) $missing[] = 'Section';
                        $status = 'error'; $note = 'Missing ' . implode(', ', $missing) . ' (not in file or dropdown)';
                    }
                } elseif ($status !== 'error') {
                    $stmt = $conn->prepare("SELECT id FROM students WHERE lrn = ?");
                    $stmt->bind_param("s", $lrn); $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) { $status = 'update'; $note = 'Existing record will be updated'; }
                }

                $preview_data[] = [
                    'row' => $i + 2, 'lrn' => $lrn, 'name' => $name,
                    'school' => $file_school, 'grade' => $file_grade, 'section' => $file_section,
                    'school_id' => $resolved_school_id, 'grade_id' => $resolved_grade_id, 'section_id' => $resolved_section_id,
                    'guardian' => $guardian, 'status' => $status, 'note' => $note
                ];
            }
            $show_preview = true;
        } else {
            $error = 'File is empty or has no data rows.';
        }
    }
}

// ═══════════════════════════════════════════════════════
// SHS STUDENT Preview (7-column: LRN, Name, School, Grade, Track, Section, Guardian)
// ═══════════════════════════════════════════════════════
if (isset($_POST['preview']) && $_POST['import_type'] === 'shs_students' && isset($_FILES['import_file'])) {
    $parsed = parseUploadedFile($_FILES['import_file']);
    if (isset($parsed['error'])) {
        $error = $parsed['error'];
    } else {
        $rows = $parsed['rows'];
        if (count($rows) > 1) {
            $tmp_path = sys_get_temp_dir() . '/qr_import_shs_' . session_id() . '.' . $parsed['ext'];
            move_uploaded_file($_FILES['import_file']['tmp_name'], $tmp_path);
            $_SESSION['import_tmp_file'] = $tmp_path;
            $_SESSION['import_ext'] = $parsed['ext'];
            $_SESSION['import_type'] = 'shs_students';

            array_shift($rows);
            foreach ($rows as $i => $row) {
                $lrn = trim($row[0] ?? '');
                $name = trim($row[1] ?? '');
                $file_school = trim($row[2] ?? '');
                $file_grade = trim($row[3] ?? '');
                $file_track = trim($row[4] ?? '');
                $file_section = trim($row[5] ?? '');
                $guardian = trim($row[6] ?? '');
                $status = 'ready'; $note = '';
                $resolved_school_id = 0; $resolved_grade_id = 0; $resolved_section_id = 0;

                // Resolve school
                if (!empty($file_school)) {
                    $sch_stmt = $conn->prepare("SELECT id FROM schools WHERE name = ? AND status='active'");
                    $sch_stmt->bind_param("s", $file_school); $sch_stmt->execute();
                    $sch_res = $sch_stmt->get_result();
                    if ($sch_row = $sch_res->fetch_assoc()) { $resolved_school_id = $sch_row['id']; }
                    else { $status = 'error'; $note = 'School not found: ' . $file_school; }
                }

                // Resolve grade
                if (!empty($file_grade) && $status !== 'error') {
                    $gr_stmt = $conn->prepare("SELECT id FROM grade_levels WHERE name = ?");
                    $gr_stmt->bind_param("s", $file_grade); $gr_stmt->execute();
                    $gr_res = $gr_stmt->get_result();
                    if ($gr_row = $gr_res->fetch_assoc()) { $resolved_grade_id = $gr_row['id']; }
                    else { $status = 'error'; $note = 'Grade not found: ' . $file_grade; }
                }

                // Validate track
                if (empty($file_track) && $status !== 'error') {
                    $status = 'error'; $note = 'Missing Track/Strand';
                }

                // Resolve section (match on school + grade + name + track)
                if (!empty($file_section) && $status !== 'error' && $resolved_school_id && $resolved_grade_id) {
                    $sec_stmt = $conn->prepare("SELECT id FROM sections WHERE name = ? AND school_id = ? AND grade_level_id = ? AND (track = ? OR track IS NULL) AND status='active' ORDER BY track DESC LIMIT 1");
                    $sec_stmt->bind_param("siis", $file_section, $resolved_school_id, $resolved_grade_id, $file_track); $sec_stmt->execute();
                    $sec_res = $sec_stmt->get_result();
                    if ($sec_row = $sec_res->fetch_assoc()) { $resolved_section_id = $sec_row['id']; }
                    else { $resolved_section_id = -1; $note = 'Section "' . $file_section . '" (' . $file_track . ') will be auto-created'; }
                }

                // Validate required
                if (empty($lrn) || empty($name)) {
                    $status = 'error'; $note = 'Missing LRN or Name';
                } elseif ($status !== 'error' && (!$resolved_school_id || !$resolved_grade_id)) {
                    $missing = [];
                    if (!$resolved_school_id) $missing[] = 'School';
                    if (!$resolved_grade_id) $missing[] = 'Grade';
                    $status = 'error'; $note = 'Missing ' . implode(', ', $missing);
                } elseif ($status !== 'error') {
                    $stmt = $conn->prepare("SELECT id FROM students WHERE lrn = ?");
                    $stmt->bind_param("s", $lrn); $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) { $status = 'update'; $note = 'Existing record will be updated'; }
                }

                $preview_data[] = [
                    'row' => $i + 2, 'lrn' => $lrn, 'name' => $name,
                    'school' => $file_school, 'grade' => $file_grade, 'track' => $file_track,
                    'section' => $file_section, 'school_id' => $resolved_school_id,
                    'grade_id' => $resolved_grade_id, 'section_id' => $resolved_section_id,
                    'guardian' => $guardian, 'status' => $status, 'note' => $note
                ];
            }
            $show_preview = true;
            $active_tab = 'shs_students';
        } else {
            $error = 'File is empty or has no data rows.';
        }
    }
}

// ═══════════════════════════════════════════════════════
// TEACHER Preview
// ═══════════════════════════════════════════════════════
if (isset($_POST['preview']) && $_POST['import_type'] === 'teachers' && isset($_FILES['import_file'])) {
    $school_id = (int)($_POST['school_id'] ?? 0);

    $parsed = parseUploadedFile($_FILES['import_file']);
    if (isset($parsed['error'])) {
        $error = $parsed['error'];
    } else {
        $rows = $parsed['rows'];
        if (count($rows) > 1) {
            $tmp_path = sys_get_temp_dir() . '/qr_import_tch_' . session_id() . '.' . $parsed['ext'];
            move_uploaded_file($_FILES['import_file']['tmp_name'], $tmp_path);
            $_SESSION['import_tmp_file'] = $tmp_path;
            $_SESSION['import_ext'] = $parsed['ext'];
            $_SESSION['import_school_id'] = $school_id;
            $_SESSION['import_type'] = 'teachers';

            array_shift($rows);
            foreach ($rows as $i => $row) {
                $emp_id = trim($row[0] ?? '');
                $name = trim($row[1] ?? '');
                $status = 'ready'; $note = '';

                // Support 6-col (EmpID, Name, School, Grade, Section, Contact) or 4-col or 3-col
                $school_name_file = ''; $file_grade = ''; $file_section = ''; $contact = '';
                $resolved_school_id = $school_id;
                $resolved_grade_id = 0;
                $resolved_section_id = 0;

                if (count($row) >= 4) {
                    $school_name_file = trim($row[2] ?? '');
                    $file_grade = trim($row[3] ?? '');
                    $file_section = trim($row[4] ?? '');
                    $contact = trim($row[5] ?? '');
                } else {
                    $contact = trim($row[2] ?? '');
                }

                // Resolve school
                if (!empty($school_name_file)) {
                    $sch_stmt = $conn->prepare("SELECT id FROM schools WHERE name = ? AND status='active'");
                    $sch_stmt->bind_param("s", $school_name_file); $sch_stmt->execute();
                    $sch_res = $sch_stmt->get_result();
                    if ($sch_row = $sch_res->fetch_assoc()) {
                        $resolved_school_id = $sch_row['id'];
                    } else {
                        $status = 'error'; $note = 'School not found: ' . $school_name_file;
                    }
                }

                // Resolve grade
                if (!empty($file_grade) && $status !== 'error') {
                    $gr_stmt = $conn->prepare("SELECT id FROM grade_levels WHERE name = ?");
                    $gr_stmt->bind_param("s", $file_grade); $gr_stmt->execute();
                    $gr_res = $gr_stmt->get_result();
                    if ($gr_row = $gr_res->fetch_assoc()) {
                        $resolved_grade_id = $gr_row['id'];
                    } else {
                        $status = 'error'; $note = 'Grade not found: ' . $file_grade;
                    }
                }

                // Resolve section (will auto-create on confirm)
                if (!empty($file_section) && $status !== 'error' && $resolved_school_id && $resolved_grade_id) {
                    $sec_stmt = $conn->prepare("SELECT id FROM sections WHERE name = ? AND school_id = ? AND grade_level_id = ? AND status='active'");
                    $sec_stmt->bind_param("sii", $file_section, $resolved_school_id, $resolved_grade_id); $sec_stmt->execute();
                    $sec_res = $sec_stmt->get_result();
                    if ($sec_row = $sec_res->fetch_assoc()) {
                        $resolved_section_id = $sec_row['id'];
                    } else {
                        $resolved_section_id = -1; // will be auto-created
                        if (empty($note)) $note = 'Section "' . $file_section . '" will be auto-created';
                    }
                }

                if (empty($emp_id) || empty($name)) {
                    $status = 'error'; $note = 'Missing Employee ID or Name';
                } elseif (!$resolved_school_id && $status !== 'error') {
                    $status = 'error'; $note = 'Missing School (not in file or dropdown)';
                } elseif ($status !== 'error') {
                    $stmt = $conn->prepare("SELECT id FROM teachers WHERE employee_id = ?");
                    $stmt->bind_param("s", $emp_id); $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) { $status = 'update'; $note = 'Existing record will be updated'; }
                }

                $preview_data[] = [
                    'row' => $i + 2, 'employee_id' => $emp_id, 'name' => $name,
                    'school' => $school_name_file, 'school_id' => $resolved_school_id,
                    'grade' => $file_grade, 'grade_id' => $resolved_grade_id,
                    'section' => $file_section, 'section_id' => $resolved_section_id,
                    'contact' => $contact, 'status' => $status, 'note' => $note
                ];
            }
            $show_preview = true;
            $active_tab = 'teachers';
        } else {
            $error = 'File is empty or has no data rows.';
        }
    }
}

// ═══════════════════════════════════════════════════════
// STUDENT Confirm Import
// ═══════════════════════════════════════════════════════
if (isset($_POST['confirm_import']) && ($_SESSION['import_type'] ?? '') === 'students') {
    $tmp_file = $_SESSION['import_tmp_file'] ?? '';
    $ext = $_SESSION['import_ext'] ?? 'csv';
    $default_school_id = $_SESSION['import_school_id'] ?? 0;
    $default_grade_id = $_SESSION['import_grade_level_id'] ?? 0;
    $default_section_id = $_SESSION['import_section_id'] ?? 0;

    if (!file_exists($tmp_file)) {
        $error = 'Import session expired. Please upload the file again.';
    } else {
        $rows = [];
        if ($ext === 'csv') {
            $handle = fopen($tmp_file, 'r');
            if ($handle) { while (($data = fgetcsv($handle)) !== false) $rows[] = $data; fclose($handle); }
        } else { $rows = parseXLSX($tmp_file); }

        if ($rows && count($rows) > 1) {
            array_shift($rows);
            $import_active_from = $_SESSION['import_active_from'] ?? null;
            if (!$import_active_from) {
                $r2 = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='launch_start_date'");
                if ($r2 && $rw = $r2->fetch_assoc()) $import_active_from = $rw['setting_value'] ?: null;
            }
            if ($import_active_from) {
                $stmt = $conn->prepare("INSERT INTO students (lrn, name, school_id, grade_level_id, section_id, guardian_contact, qr_code, status, active_from)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)
                                    ON DUPLICATE KEY UPDATE name = VALUES(name), school_id = VALUES(school_id),
                                    grade_level_id = VALUES(grade_level_id), section_id = VALUES(section_id), guardian_contact = VALUES(guardian_contact), active_from = VALUES(active_from)");
            } else {
                $stmt = $conn->prepare("INSERT INTO students (lrn, name, school_id, grade_level_id, section_id, guardian_contact, qr_code, status)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
                                    ON DUPLICATE KEY UPDATE name = VALUES(name), school_id = VALUES(school_id),
                                    grade_level_id = VALUES(grade_level_id), section_id = VALUES(section_id), guardian_contact = VALUES(guardian_contact)");
            }

            foreach ($rows as $i => $row) {
                $lrn = trim($row[0] ?? '');
                $name = trim($row[1] ?? '');
                $file_school = ''; $file_grade = ''; $file_section = ''; $guardian = '';
                $resolved_school_id = $default_school_id;
                $resolved_grade_id = $default_grade_id;
                $resolved_section_id = $default_section_id;

                if (count($row) >= 4) {
                    $file_school = trim($row[2] ?? '');
                    $file_grade = trim($row[3] ?? '');
                    $file_section = trim($row[4] ?? '');
                    $guardian = trim($row[5] ?? '');
                } else {
                    $guardian = trim($row[2] ?? '');
                }

                if (empty($lrn) || empty($name)) {
                    $skipped++;
                    $errors_list[] = "Row " . ($i + 2) . ": Missing LRN or Name — skipped.";
                    continue;
                }

                // Resolve school from file
                if (!empty($file_school)) {
                    $sch_stmt = $conn->prepare("SELECT id FROM schools WHERE name = ? AND status='active'");
                    $sch_stmt->bind_param("s", $file_school); $sch_stmt->execute();
                    $sch_res = $sch_stmt->get_result();
                    if ($sch_row = $sch_res->fetch_assoc()) { $resolved_school_id = $sch_row['id']; }
                    else { $skipped++; $errors_list[] = "Row " . ($i + 2) . ": School not found: $file_school — skipped."; continue; }
                }
                // Resolve grade from file
                if (!empty($file_grade)) {
                    $gr_stmt = $conn->prepare("SELECT id FROM grade_levels WHERE name = ?");
                    $gr_stmt->bind_param("s", $file_grade); $gr_stmt->execute();
                    $gr_res = $gr_stmt->get_result();
                    if ($gr_row = $gr_res->fetch_assoc()) { $resolved_grade_id = $gr_row['id']; }
                    else { $skipped++; $errors_list[] = "Row " . ($i + 2) . ": Grade not found: $file_grade — skipped."; continue; }
                }
                // Resolve section from file (auto-create if not found)
                if (!empty($file_section) && $resolved_school_id && $resolved_grade_id) {
                    $sec_stmt = $conn->prepare("SELECT id FROM sections WHERE name = ? AND school_id = ? AND grade_level_id = ? AND status='active'");
                    $sec_stmt->bind_param("sii", $file_section, $resolved_school_id, $resolved_grade_id); $sec_stmt->execute();
                    $sec_res = $sec_stmt->get_result();
                    if ($sec_row = $sec_res->fetch_assoc()) { $resolved_section_id = $sec_row['id']; }
                    else {
                        // Auto-create section
                        $ins_sec = $conn->prepare("INSERT INTO sections (name, school_id, grade_level_id, status) VALUES (?, ?, ?, 'active')");
                        $ins_sec->bind_param("sii", $file_section, $resolved_school_id, $resolved_grade_id);
                        if ($ins_sec->execute()) { $resolved_section_id = $conn->insert_id; }
                        else { $skipped++; $errors_list[] = "Row " . ($i + 2) . ": Failed to create section: $file_section — skipped."; continue; }
                    }
                }

                if (!$resolved_school_id || !$resolved_grade_id || !$resolved_section_id) {
                    $skipped++;
                    $missing = [];
                    if (!$resolved_school_id) $missing[] = 'School';
                    if (!$resolved_grade_id) $missing[] = 'Grade';
                    if (!$resolved_section_id) $missing[] = 'Section';
                    $errors_list[] = "Row " . ($i + 2) . ": Missing " . implode(', ', $missing) . " — skipped.";
                    continue;
                }

                $qr_code = 'STU-' . $lrn;
                if ($import_active_from) {
                    $stmt->bind_param("ssiiisss", $lrn, $name, $resolved_school_id, $resolved_grade_id, $resolved_section_id, $guardian, $qr_code, $import_active_from);
                } else {
                    $stmt->bind_param("ssiiiss", $lrn, $name, $resolved_school_id, $resolved_grade_id, $resolved_section_id, $guardian, $qr_code);
                }
                if ($stmt->execute()) { $imported++; }
                else { $skipped++; $errors_list[] = "Row " . ($i + 2) . ": " . $stmt->error; }
            }

            $success = "$imported student(s) imported successfully!";
            if ($skipped > 0) $success .= " ($skipped skipped)";
        }

        @unlink($tmp_file);
        unset($_SESSION['import_tmp_file'], $_SESSION['import_ext'], $_SESSION['import_school_id'], $_SESSION['import_grade_level_id'], $_SESSION['import_section_id'], $_SESSION['import_active_from'], $_SESSION['import_type']);
    }
}

// ═══════════════════════════════════════════════════════
// SHS STUDENT Confirm Import
// ═══════════════════════════════════════════════════════
if (isset($_POST['confirm_import']) && ($_SESSION['import_type'] ?? '') === 'shs_students') {
    $tmp_file = $_SESSION['import_tmp_file'] ?? '';
    $ext = $_SESSION['import_ext'] ?? 'csv';

    if (!file_exists($tmp_file)) {
        $error = 'Import session expired. Please upload the file again.';
    } else {
        $rows = [];
        if ($ext === 'csv') {
            $handle = fopen($tmp_file, 'r');
            if ($handle) { while (($data = fgetcsv($handle)) !== false) $rows[] = $data; fclose($handle); }
        } else { $rows = parseXLSX($tmp_file); }

        if ($rows && count($rows) > 1) {
            array_shift($rows);
            $stmt = $conn->prepare("INSERT INTO students (lrn, name, school_id, grade_level_id, section_id, guardian_contact, qr_code, status)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
                                    ON DUPLICATE KEY UPDATE name = VALUES(name), school_id = VALUES(school_id),
                                    grade_level_id = VALUES(grade_level_id), section_id = VALUES(section_id), guardian_contact = VALUES(guardian_contact)");

            foreach ($rows as $i => $row) {
                $lrn = trim($row[0] ?? '');
                $name = trim($row[1] ?? '');
                $file_school = trim($row[2] ?? '');
                $file_grade = trim($row[3] ?? '');
                $file_track = trim($row[4] ?? '');
                $file_section = trim($row[5] ?? '');
                $guardian = trim($row[6] ?? '');

                if (empty($lrn) || empty($name)) {
                    $skipped++; $errors_list[] = "Row " . ($i + 2) . ": Missing LRN or Name — skipped."; continue;
                }
                if (empty($file_track)) {
                    $skipped++; $errors_list[] = "Row " . ($i + 2) . ": Missing Track/Strand — skipped."; continue;
                }

                // Resolve school
                $resolved_school_id = 0;
                if (!empty($file_school)) {
                    $sch_stmt = $conn->prepare("SELECT id FROM schools WHERE name = ? AND status='active'");
                    $sch_stmt->bind_param("s", $file_school); $sch_stmt->execute();
                    $sch_res = $sch_stmt->get_result();
                    if ($sch_row = $sch_res->fetch_assoc()) { $resolved_school_id = $sch_row['id']; }
                    else { $skipped++; $errors_list[] = "Row " . ($i + 2) . ": School not found: $file_school — skipped."; continue; }
                }
                if (!$resolved_school_id) { $skipped++; $errors_list[] = "Row " . ($i + 2) . ": Missing School — skipped."; continue; }

                // Resolve grade
                $resolved_grade_id = 0;
                if (!empty($file_grade)) {
                    $gr_stmt = $conn->prepare("SELECT id FROM grade_levels WHERE name = ?");
                    $gr_stmt->bind_param("s", $file_grade); $gr_stmt->execute();
                    $gr_res = $gr_stmt->get_result();
                    if ($gr_row = $gr_res->fetch_assoc()) { $resolved_grade_id = $gr_row['id']; }
                    else { $skipped++; $errors_list[] = "Row " . ($i + 2) . ": Grade not found: $file_grade — skipped."; continue; }
                }
                if (!$resolved_grade_id) { $skipped++; $errors_list[] = "Row " . ($i + 2) . ": Missing Grade — skipped."; continue; }

                // Resolve section with track (auto-create with track if not found)
                $resolved_section_id = 0;
                if (!empty($file_section)) {
                    $sec_stmt = $conn->prepare("SELECT id FROM sections WHERE name = ? AND school_id = ? AND grade_level_id = ? AND track = ? AND status='active'");
                    $sec_stmt->bind_param("siis", $file_section, $resolved_school_id, $resolved_grade_id, $file_track); $sec_stmt->execute();
                    $sec_res = $sec_stmt->get_result();
                    if ($sec_row = $sec_res->fetch_assoc()) { $resolved_section_id = $sec_row['id']; }
                    else {
                        // Auto-create section WITH track
                        $ins_sec = $conn->prepare("INSERT INTO sections (name, school_id, grade_level_id, track, status) VALUES (?, ?, ?, ?, 'active')");
                        $ins_sec->bind_param("siis", $file_section, $resolved_school_id, $resolved_grade_id, $file_track);
                        if ($ins_sec->execute()) { $resolved_section_id = $conn->insert_id; }
                        else { $skipped++; $errors_list[] = "Row " . ($i + 2) . ": Failed to create section — skipped."; continue; }
                    }
                }
                if (!$resolved_section_id) { $skipped++; $errors_list[] = "Row " . ($i + 2) . ": Missing Section — skipped."; continue; }

                $qr_code = 'STU-' . $lrn;
                $stmt->bind_param("ssiiiss", $lrn, $name, $resolved_school_id, $resolved_grade_id, $resolved_section_id, $guardian, $qr_code);
                if ($stmt->execute()) { $imported++; }
                else { $skipped++; $errors_list[] = "Row " . ($i + 2) . ": " . $stmt->error; }
            }

            $success = "$imported SHS student(s) imported successfully!";
            if ($skipped > 0) $success .= " ($skipped skipped)";
            $active_tab = 'shs_students';
        }

        @unlink($tmp_file);
        unset($_SESSION['import_tmp_file'], $_SESSION['import_ext'], $_SESSION['import_active_from'], $_SESSION['import_type']);
    }
}

// ═══════════════════════════════════════════════════════
// TEACHER Confirm Import
// ═══════════════════════════════════════════════════════
if (isset($_POST['confirm_import']) && ($_SESSION['import_type'] ?? '') === 'teachers') {
    $tmp_file = $_SESSION['import_tmp_file'] ?? '';
    $ext = $_SESSION['import_ext'] ?? 'csv';
    $school_id = $_SESSION['import_school_id'] ?? 0;

    if (!file_exists($tmp_file)) {
        $error = 'Import session expired. Please upload the file again.';
    } else {
        $rows = [];
        if ($ext === 'csv') {
            $handle = fopen($tmp_file, 'r');
            if ($handle) { while (($data = fgetcsv($handle)) !== false) $rows[] = $data; fclose($handle); }
        } else { $rows = parseXLSX($tmp_file); }

        if ($rows && count($rows) > 1) {
            array_shift($rows);
            $stmt = $conn->prepare("INSERT INTO teachers (employee_id, name, school_id, contact_number, qr_code, status)
                                    VALUES (?, ?, ?, ?, ?, 'active')
                                    ON DUPLICATE KEY UPDATE name = VALUES(name), school_id = VALUES(school_id), contact_number = VALUES(contact_number)");

            foreach ($rows as $i => $row) {
                $emp_id = trim($row[0] ?? '');
                $name = trim($row[1] ?? '');
                $school_name_file = ''; $file_grade = ''; $file_section = ''; $contact = '';
                $resolved_school_id = $school_id;

                if (count($row) >= 4) {
                    $school_name_file = trim($row[2] ?? '');
                    $file_grade = trim($row[3] ?? '');
                    $file_section = trim($row[4] ?? '');
                    $contact = trim($row[5] ?? '');
                } else {
                    $contact = trim($row[2] ?? '');
                }

                if (empty($emp_id) || empty($name)) {
                    $skipped++;
                    $errors_list[] = "Row " . ($i + 2) . ": Missing Employee ID or Name — skipped.";
                    continue;
                }

                // Resolve school
                if (!empty($school_name_file)) {
                    $sch_stmt = $conn->prepare("SELECT id FROM schools WHERE name = ? AND status='active'");
                    $sch_stmt->bind_param("s", $school_name_file); $sch_stmt->execute();
                    $sch_res = $sch_stmt->get_result();
                    if ($sch_row = $sch_res->fetch_assoc()) {
                        $resolved_school_id = $sch_row['id'];
                    } else {
                        $skipped++;
                        $errors_list[] = "Row " . ($i + 2) . ": School not found: $school_name_file — skipped.";
                        continue;
                    }
                }
                if (!$resolved_school_id) {
                    $skipped++;
                    $errors_list[] = "Row " . ($i + 2) . ": Missing School — skipped.";
                    continue;
                }

                $qr_code = 'TCH-' . $emp_id;
                $stmt->bind_param("ssiss", $emp_id, $name, $resolved_school_id, $contact, $qr_code);
                if ($stmt->execute()) {
                    $imported++;
                    $teacher_id = $conn->insert_id ?: null;

                    // If grade + section provided, resolve/create section and assign teacher as adviser
                    if (!empty($file_grade) && !empty($file_section) && $resolved_school_id) {
                        // Get teacher ID (may be existing if ON DUPLICATE KEY UPDATE)
                        if (!$teacher_id) {
                            $tid_stmt = $conn->prepare("SELECT id FROM teachers WHERE employee_id = ?");
                            $tid_stmt->bind_param("s", $emp_id); $tid_stmt->execute();
                            $tid_res = $tid_stmt->get_result();
                            if ($tid_row = $tid_res->fetch_assoc()) $teacher_id = $tid_row['id'];
                        }

                        // Resolve grade
                        $resolved_grade_id = 0;
                        $gr_stmt = $conn->prepare("SELECT id FROM grade_levels WHERE name = ?");
                        $gr_stmt->bind_param("s", $file_grade); $gr_stmt->execute();
                        $gr_res = $gr_stmt->get_result();
                        if ($gr_row = $gr_res->fetch_assoc()) $resolved_grade_id = $gr_row['id'];

                        if ($resolved_grade_id && $teacher_id) {
                            // Find or create section
                            $sec_stmt = $conn->prepare("SELECT id FROM sections WHERE name = ? AND school_id = ? AND grade_level_id = ? AND status='active'");
                            $sec_stmt->bind_param("sii", $file_section, $resolved_school_id, $resolved_grade_id); $sec_stmt->execute();
                            $sec_res = $sec_stmt->get_result();
                            if ($sec_row = $sec_res->fetch_assoc()) {
                                $section_id_resolved = $sec_row['id'];
                                // Update adviser
                                $upd = $conn->prepare("UPDATE sections SET adviser_id = ? WHERE id = ?");
                                $upd->bind_param("ii", $teacher_id, $section_id_resolved); $upd->execute();
                            } else {
                                // Create section with adviser
                                $ins_sec = $conn->prepare("INSERT INTO sections (name, school_id, grade_level_id, adviser_id, status) VALUES (?, ?, ?, ?, 'active')");
                                $ins_sec->bind_param("siii", $file_section, $resolved_school_id, $resolved_grade_id, $teacher_id); $ins_sec->execute();
                            }
                        }
                    }
                }
                else { $skipped++; $errors_list[] = "Row " . ($i + 2) . ": " . $stmt->error; }
            }

            $success = "$imported teacher(s) imported successfully!";
            if ($skipped > 0) $success .= " ($skipped skipped)";
            $active_tab = 'teachers';
        }

        @unlink($tmp_file);
        unset($_SESSION['import_tmp_file'], $_SESSION['import_ext'], $_SESSION['import_school_id'], $_SESSION['import_active_from'], $_SESSION['import_type']);
    }
}

$schools = []; $r = $conn->query("SELECT id, name FROM schools WHERE status='active' ORDER BY name"); if ($r) while ($row = $r->fetch_assoc()) $schools[] = $row;
$grades = []; $r = $conn->query("SELECT id, name FROM grade_levels ORDER BY id"); if ($r) while ($row = $r->fetch_assoc()) $grades[] = $row;
$sections = []; $r = $conn->query("SELECT sec.id, sec.name, sec.school_id, sec.grade_level_id FROM sections sec WHERE sec.status='active' ORDER BY sec.name"); if ($r) while ($row = $r->fetch_assoc()) $sections[] = $row;

$preview_school = $preview_grade = $preview_section = '';
if ($show_preview) {
    foreach ($schools as $s) { if ($s['id'] == $_SESSION['import_school_id']) $preview_school = $s['name']; }
    if ($active_tab === 'students') {
        foreach ($grades as $g) { if ($g['id'] == ($_SESSION['import_grade_level_id'] ?? 0)) $preview_grade = $g['name']; }
        foreach ($sections as $s) { if ($s['id'] == ($_SESSION['import_section_id'] ?? 0)) $preview_section = $s['name']; }
    }

    // Determine preview active_from (session or system default)
    $preview_active_from = $_SESSION['import_active_from'] ?? null;
    if (empty($preview_active_from)) {
        $r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='launch_start_date'");
        if ($r && $row = $r->fetch_assoc()) $preview_active_from = $row['setting_value'] ?: null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'includes/header.php'; ?>
<style>
    .import-tabs { display: flex; gap: 4px; background: var(--bg); border: 1px solid var(--border); border-radius: 12px; padding: 4px; margin-bottom: 24px; }
    .import-tab { flex: 1; padding: 12px 24px; border-radius: 10px; font-size: 0.88rem; font-weight: 600; color: var(--text-muted); cursor: pointer; text-align: center; transition: all 0.2s; border: none; background: none; display: flex; align-items: center; justify-content: center; gap: 8px; }
    .import-tab:hover { color: var(--text); }
    .import-tab.active { background: #fff; color: var(--primary); box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    .drop-zone { border: 2px dashed var(--border); border-radius: 12px; padding: 30px; text-align: center; background: var(--bg); cursor: pointer; transition: border-color 0.2s; }
    .drop-zone:hover { border-color: var(--primary); }
</style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-file-import" style="color:var(--primary);margin-right:8px;"></i> Bulk Import</h1>
            <p>Import students or teachers from Excel (.xlsx) or CSV file</p>
        </div>

        <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-times-circle"></i> <?= $error ?></div>
        <?php endif; ?>
        <?php if (!empty($errors_list)): ?>
        <div class="card" style="margin-bottom:20px;">
            <div class="card-title" style="color:var(--danger);"><i class="fas fa-exclamation-triangle"></i> Import Errors</div>
            <div style="max-height:200px;overflow-y:auto;font-size:0.82rem;">
                <?php foreach ($errors_list as $err): ?>
                <div style="padding:6px 0;border-bottom:1px solid var(--border);color:var(--text-muted);"><?= htmlspecialchars($err) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($show_preview): ?>
        <!-- ═══ PREVIEW ═══ -->
        <div class="card" style="margin-bottom:20px;">
            <div class="card-title"><i class="fas fa-eye"></i> Preview Import Data — <?= $active_tab === 'teachers' ? 'Teachers' : ($active_tab === 'shs_students' ? 'SHS Students' : 'Students') ?></div>
            <div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap;">
                <?php if ($preview_school): ?>
                <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:10px 16px;font-size:0.82rem;">
                    <span style="color:var(--text-muted);">Default School:</span> <strong><?= htmlspecialchars($preview_school) ?></strong>
                </div>
                <?php else: ?>
                <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:10px 16px;font-size:0.82rem;">
                    <span style="color:var(--text-muted);">School:</span> <strong style="color:var(--primary);">From file</strong>
                </div>
                <?php endif; ?>
                <?php if ($active_tab === 'students'): ?>
                    <?php if ($preview_grade): ?>
                    <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:10px 16px;font-size:0.82rem;">
                        <span style="color:var(--text-muted);">Default Grade:</span> <strong><?= htmlspecialchars($preview_grade) ?></strong>
                    </div>
                    <?php else: ?>
                    <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:10px 16px;font-size:0.82rem;">
                        <span style="color:var(--text-muted);">Grade:</span> <strong style="color:var(--primary);">From file</strong>
                    </div>
                    <?php endif; ?>
                    <?php if ($preview_section): ?>
                    <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:10px 16px;font-size:0.82rem;">
                        <span style="color:var(--text-muted);">Default Section:</span> <strong><?= htmlspecialchars($preview_section) ?></strong>
                    </div>
                    <?php else: ?>
                    <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:10px 16px;font-size:0.82rem;">
                        <span style="color:var(--text-muted);">Section:</span> <strong style="color:var(--primary);">From file</strong>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
                <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:10px 16px;font-size:0.82rem;">
                    <span style="color:var(--text-muted);">Total Rows:</span> <strong><?= count($preview_data) ?></strong>
                </div>
                <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:10px 16px;font-size:0.82rem;">
                    <span style="color:var(--text-muted);">Active From:</span> <strong><?= $preview_active_from ? htmlspecialchars($preview_active_from) : '<span style="color:var(--text-muted);">Not set</span>' ?></strong>
                </div>
            </div>

            <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Row</th>
                        <?php if ($active_tab === 'students'): ?>
                            <th>LRN</th><th>Name</th><th>School</th><th>Grade</th><th>Section</th><th>Guardian</th><th>QR Code</th>
                        <?php elseif ($active_tab === 'shs_students'): ?>
                            <th>LRN</th><th>Name</th><th>School</th><th>Grade</th><th>Track/Strand</th><th>Section</th><th>Guardian</th><th>QR Code</th>
                        <?php else: ?>
                            <th>Employee ID</th><th>Name</th><th>School</th><th>Grade</th><th>Section</th><th>Contact Number</th><th>QR Code</th>
                        <?php endif; ?>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview_data as $row): ?>
                    <tr>
                        <td><?= $row['row'] ?></td>
                        <?php if ($active_tab === 'students'): ?>
                            <td><code style="font-size:0.8rem;"><?= htmlspecialchars($row['lrn']) ?></code></td>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td style="font-size:0.82rem;"><?= htmlspecialchars($row['school'] ?? '') ?></td>
                            <td style="font-size:0.82rem;"><?= htmlspecialchars($row['grade'] ?? '') ?></td>
                            <td style="font-size:0.82rem;"><?= htmlspecialchars($row['section'] ?? '') ?></td>
                            <td style="font-size:0.82rem;color:var(--text-muted);"><?= htmlspecialchars($row['guardian'] ?? '') ?></td>
                            <td><code style="font-size:0.8rem;color:var(--primary);">STU-<?= htmlspecialchars($row['lrn']) ?></code></td>
                        <?php elseif ($active_tab === 'shs_students'): ?>
                            <td><code style="font-size:0.8rem;"><?= htmlspecialchars($row['lrn']) ?></code></td>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td style="font-size:0.82rem;"><?= htmlspecialchars($row['school'] ?? '') ?></td>
                            <td style="font-size:0.82rem;"><?= htmlspecialchars($row['grade'] ?? '') ?></td>
                            <td style="font-size:0.82rem;"><span class="badge badge-info"><?= htmlspecialchars($row['track'] ?? '') ?></span></td>
                            <td style="font-size:0.82rem;"><?= htmlspecialchars($row['section'] ?? '') ?></td>
                            <td style="font-size:0.82rem;color:var(--text-muted);"><?= htmlspecialchars($row['guardian'] ?? '') ?></td>
                            <td><code style="font-size:0.8rem;color:var(--primary);">STU-<?= htmlspecialchars($row['lrn']) ?></code></td>
                        <?php else: ?>
                            <td><code style="font-size:0.8rem;"><?= htmlspecialchars($row['employee_id']) ?></code></td>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td style="font-size:0.82rem;"><?= htmlspecialchars($row['school'] ?? '') ?></td>
                            <td style="font-size:0.82rem;"><?= htmlspecialchars($row['grade'] ?? '') ?></td>
                            <td style="font-size:0.82rem;"><?= htmlspecialchars($row['section'] ?? '') ?></td>
                            <td style="font-size:0.82rem;color:var(--text-muted);"><?= htmlspecialchars($row['contact'] ?? '') ?></td>
                            <td><code style="font-size:0.8rem;color:var(--warning);">TCH-<?= htmlspecialchars($row['employee_id']) ?></code></td>
                        <?php endif; ?>
                        <td>
                            <?php if ($row['status'] === 'ready' && !empty($row['note'])): ?>
                                <span class="badge" style="background:rgba(22,163,74,0.1);color:#16a34a;"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($row['note']) ?></span>
                            <?php elseif ($row['status'] === 'ready'): ?>
                                <span class="badge" style="background:rgba(22,163,74,0.1);color:#16a34a;"><i class="fas fa-check-circle"></i> Ready</span>
                            <?php elseif ($row['status'] === 'update'): ?>
                                <span class="badge" style="background:rgba(217,119,6,0.1);color:#d97706;"><i class="fas fa-sync-alt"></i> Update</span>
                            <?php else: ?>
                                <span class="badge" style="background:rgba(220,38,38,0.1);color:#dc2626;"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($row['note']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div style="display:flex;gap:12px;margin-top:20px;">
                <form method="POST" style="flex:1;">
                    <input type="hidden" name="confirm_import" value="1">
                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        <i class="fas fa-check"></i> Confirm & Import <?= count(array_filter($preview_data, fn($r) => $r['status'] !== 'error')) ?>
                        <?= $active_tab === 'teachers' ? 'Teacher(s)' : ($active_tab === 'shs_students' ? 'SHS Student(s)' : 'Student(s)') ?>
                    </button>
                </form>
                <a href="bulk_import.php" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;text-decoration:none;"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </div>

        <?php else: ?>
        <!-- ═══ TABS ═══ -->
        <div class="import-tabs">
            <button class="import-tab <?= $active_tab === 'students' ? 'active' : '' ?>" onclick="switchTab('students')">
                <i class="fas fa-user-graduate"></i> Students
            </button>
            <button class="import-tab <?= $active_tab === 'shs_students' ? 'active' : '' ?>" onclick="switchTab('shs_students')">
                <i class="fas fa-graduation-cap"></i> SHS Students
            </button>
            <button class="import-tab <?= $active_tab === 'teachers' ? 'active' : '' ?>" onclick="switchTab('teachers')">
                <i class="fas fa-chalkboard-teacher"></i> Teachers
            </button>
        </div>

        <!-- ═══ STUDENTS TAB ═══ -->
        <div class="tab-content <?= $active_tab === 'students' ? 'active' : '' ?>" id="tab-students">
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">
            <div class="card">
                <div class="card-title"><i class="fas fa-upload"></i> Upload Student File</div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="import_type" value="students">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Default School <span style="font-size:0.75rem;color:var(--text-muted);">(if not in file)</span></label>
                            <select name="school_id" class="form-control" id="imp_school" onchange="filterImpSections()">
                                <option value="">— From file —</option>
                                <?php foreach ($schools as $sch): ?><option value="<?= $sch['id'] ?>"><?= htmlspecialchars($sch['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Default Grade <span style="font-size:0.75rem;color:var(--text-muted);">(if not in file)</span></label>
                            <select name="grade_level_id" class="form-control" id="imp_grade" onchange="filterImpSections()">
                                <option value="">— From file —</option>
                                <?php foreach ($grades as $g): ?><option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Default Section <span style="font-size:0.75rem;color:var(--text-muted);">(if not in file)</span></label>
                        <select name="section_id" class="form-control" id="imp_section">
                            <option value="">— From file —</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Excel / CSV File *</label>
                        <div class="drop-zone" onclick="document.getElementById('stu_file_input').click()" id="stu_drop_zone">
                            <i class="fas fa-cloud-upload-alt" style="font-size:2rem;color:var(--primary);margin-bottom:8px;"></i>
                            <p style="font-size:0.88rem;font-weight:600;margin-bottom:4px;" id="stu_file_label">Click to select or drag & drop</p>
                            <p style="font-size:0.78rem;color:var(--text-muted);">Supports .xlsx and .csv files</p>
                        </div>
                        <input type="file" name="import_file" id="stu_file_input" accept=".csv,.xlsx" required style="display:none;" onchange="document.getElementById('stu_file_label').textContent = this.files[0]?.name || 'Click to select or drag & drop'">
                    </div>
                    <div class="form-group">
                        <label>Active from (optional) <span style="font-size:0.75rem;color:var(--text-muted);">(date when imported students should start counting as enrolled)</span></label>
                        <input type="date" name="import_active_from" class="form-control" />
                    </div>
                    <button type="submit" name="preview" class="btn btn-primary" style="width:100%;"><i class="fas fa-eye"></i> Preview Data</button>
                </form>
            </div>

            <div>
                <div class="card" style="margin-bottom:20px;">
                    <div class="card-title"><i class="fas fa-info-circle"></i> Student File Format</div>
                    <p style="font-size:0.83rem;color:var(--text-muted);margin-bottom:14px;">Your file should have these columns:</p>
                    <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:16px;font-family:monospace;font-size:0.75rem;line-height:2;">
                        <span style="color:var(--primary);font-weight:700;">LRN</span>, <span style="color:var(--success);font-weight:700;">Student Name</span>, <span style="color:#6366f1;font-weight:700;">School</span>, <span style="color:#0ea5e9;font-weight:700;">Grade</span>, <span style="color:#8b5cf6;font-weight:700;">Section</span>, <span style="color:#d97706;font-weight:700;">Guardian Contact</span>
                    </div>
                    <div style="font-size:0.78rem;color:var(--text-muted);margin-top:12px;display:flex;flex-direction:column;gap:6px;">
                        <div><i class="fas fa-lightbulb" style="color:var(--warning);"></i> First row is treated as header (skipped)</div>
                        <div><i class="fas fa-sync-alt" style="color:var(--primary);"></i> Duplicate LRNs update existing records</div>
                        <div><i class="fas fa-qrcode" style="color:var(--success);"></i> QR codes auto-generated as STU-{LRN}</div>
                        <div><i class="fas fa-school" style="color:#6366f1;"></i> School &amp; Grade are selected from dropdown in template</div>
                        <div><i class="fas fa-chalkboard" style="color:#8b5cf6;"></i> Section is auto-created if not found</div>
                        <div><i class="fas fa-phone" style="color:#d97706;"></i> Guardian Contact is optional</div>
                        <div><i class="fas fa-info-circle" style="color:var(--primary);"></i> Dropdowns are used as fallback if file columns are empty</div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title"><i class="fas fa-download"></i> Download Template</div>
                    <p style="font-size:0.83rem;color:var(--text-muted);margin-bottom:14px;">Get a ready-to-fill template file:</p>
                    <a href="../api/download_template.php?type=students" class="btn btn-secondary" style="width:100%;display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;"><i class="fas fa-file-excel"></i> Download Student Template (.xlsx)</a>
                </div>
            </div>
        </div>
        </div>

        <!-- ═══ SHS STUDENTS TAB ═══ -->
        <div class="tab-content <?= $active_tab === 'shs_students' ? 'active' : '' ?>" id="tab-shs_students">
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">
            <div class="card">
                <div class="card-title"><i class="fas fa-upload"></i> Upload SHS Student File</div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="import_type" value="shs_students">
                    <div class="form-group">
                        <label>Excel / CSV File *</label>
                        <div class="drop-zone" onclick="document.getElementById('shs_file_input').click()" id="shs_drop_zone">
                            <i class="fas fa-cloud-upload-alt" style="font-size:2rem;color:var(--primary);margin-bottom:8px;"></i>
                            <p style="font-size:0.88rem;font-weight:600;margin-bottom:4px;" id="shs_file_label">Click to select or drag & drop</p>
                            <p style="font-size:0.78rem;color:var(--text-muted);">Supports .xlsx and .csv files</p>
                        </div>
                        <input type="file" name="import_file" id="shs_file_input" accept=".csv,.xlsx" required style="display:none;" onchange="document.getElementById('shs_file_label').textContent = this.files[0]?.name || 'Click to select or drag & drop'">
                    </div>
                    <button type="submit" name="preview" class="btn btn-primary" style="width:100%;"><i class="fas fa-eye"></i> Preview Data</button>
                </form>
            </div>

            <div>
                <div class="card" style="margin-bottom:20px;">
                    <div class="card-title"><i class="fas fa-info-circle"></i> SHS Student File Format</div>
                    <p style="font-size:0.83rem;color:var(--text-muted);margin-bottom:14px;">For Senior High School (Grade 11 & 12) students with Track/Strand:</p>
                    <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:16px;font-family:monospace;font-size:0.73rem;line-height:2;">
                        <span style="color:var(--primary);font-weight:700;">LRN</span>, <span style="color:var(--success);font-weight:700;">Student Name</span>, <span style="color:#6366f1;font-weight:700;">School</span>, <span style="color:#0ea5e9;font-weight:700;">Grade</span>, <span style="color:#e11d48;font-weight:700;">Track/Strand</span>, <span style="color:#8b5cf6;font-weight:700;">Section</span>, <span style="color:#d97706;font-weight:700;">Guardian Contact</span>
                    </div>
                    <div style="font-size:0.78rem;color:var(--text-muted);margin-top:12px;display:flex;flex-direction:column;gap:6px;">
                        <div><i class="fas fa-lightbulb" style="color:var(--warning);"></i> First row is treated as header (skipped)</div>
                        <div><i class="fas fa-graduation-cap" style="color:#e11d48;"></i> Track/Strand is <strong>required</strong> for SHS</div>
                        <div><i class="fas fa-list" style="color:#e11d48;"></i> Tracks: STEM, ABM, HUMSS, GAS, TVL-HE, TVL-ICT, TVL-IA, TVL-AFA, Sports, Arts & Design</div>
                        <div><i class="fas fa-sync-alt" style="color:var(--primary);"></i> Duplicate LRNs update existing records</div>
                        <div><i class="fas fa-chalkboard" style="color:#8b5cf6;"></i> Section is auto-created with track if not found</div>
                        <div><i class="fas fa-qrcode" style="color:var(--success);"></i> QR codes auto-generated as STU-{LRN}</div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title"><i class="fas fa-download"></i> Download SHS Template</div>
                    <p style="font-size:0.83rem;color:var(--text-muted);margin-bottom:14px;">Get a template with Track/Strand dropdown:</p>
                    <a href="../api/download_template.php?type=shs_students" class="btn btn-secondary" style="width:100%;display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;"><i class="fas fa-file-excel"></i> Download SHS Template (.xlsx)</a>
                </div>
            </div>
        </div>
        </div>

        <!-- ═══ TEACHERS TAB ═══ -->
        <div class="tab-content <?= $active_tab === 'teachers' ? 'active' : '' ?>" id="tab-teachers">
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">
            <div class="card">
                <div class="card-title"><i class="fas fa-upload"></i> Upload Teacher File</div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="import_type" value="teachers">
                    <div class="form-group">
                        <label>Default School <span style="font-size:0.75rem;color:var(--text-muted);">(used if School column is empty)</span></label>
                        <select name="school_id" class="form-control">
                            <option value="">— From file —</option>
                            <?php foreach ($schools as $sch): ?><option value="<?= $sch['id'] ?>"><?= htmlspecialchars($sch['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Excel / CSV File *</label>
                        <div class="drop-zone" onclick="document.getElementById('tch_file_input').click()" id="tch_drop_zone">
                            <i class="fas fa-cloud-upload-alt" style="font-size:2rem;color:var(--primary);margin-bottom:8px;"></i>
                            <p style="font-size:0.88rem;font-weight:600;margin-bottom:4px;" id="tch_file_label">Click to select or drag & drop</p>
                            <p style="font-size:0.78rem;color:var(--text-muted);">Supports .xlsx and .csv files</p>
                        </div>
                        <input type="file" name="import_file" id="tch_file_input" accept=".csv,.xlsx" required style="display:none;" onchange="document.getElementById('tch_file_label').textContent = this.files[0]?.name || 'Click to select or drag & drop'">
                    </div>
                    <button type="submit" name="preview" class="btn btn-primary" style="width:100%;"><i class="fas fa-eye"></i> Preview Data</button>
                </form>
            </div>

            <div>
                <div class="card" style="margin-bottom:20px;">
                    <div class="card-title"><i class="fas fa-info-circle"></i> Teacher File Format</div>
                    <p style="font-size:0.83rem;color:var(--text-muted);margin-bottom:14px;">Your file should have these columns:</p>
                    <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:16px;font-family:monospace;font-size:0.78rem;line-height:2;">
                        <span style="color:var(--primary);font-weight:700;">Employee ID</span>, <span style="color:var(--success);font-weight:700;">Name</span>, <span style="color:#8b5cf6;font-weight:700;">School</span>, <span style="color:#d97706;font-weight:700;">Grade</span>, <span style="color:#0ea5e9;font-weight:700;">Section</span>, <span style="color:#64748b;font-weight:700;">Contact Number</span><br>
                        <span style="color:var(--text-muted);">EMP-001, Dela Cruz Juan A., Barangay V Elementary School, Grade 5, Banana, 09171234567</span><br>
                        <span style="color:var(--text-muted);">EMP-002, Santos Maria B., Gil Montilla National High School, Grade 7, Apple, 09181234567</span>
                    </div>
                    <div style="font-size:0.78rem;color:var(--text-muted);margin-top:12px;display:flex;flex-direction:column;gap:6px;">
                        <div><i class="fas fa-lightbulb" style="color:var(--warning);"></i> First row is treated as header (skipped)</div>
                        <div><i class="fas fa-sync-alt" style="color:var(--primary);"></i> Duplicate Employee IDs update existing records</div>
                        <div><i class="fas fa-qrcode" style="color:var(--success);"></i> QR codes auto-generated as TCH-{Employee ID}</div>
                        <div><i class="fas fa-school" style="color:#8b5cf6;"></i> School &amp; Grade are selected from dropdown in template</div>
                        <div><i class="fas fa-chalkboard" style="color:#0ea5e9;"></i> Section is auto-created if not found, teacher assigned as adviser</div>
                        <div><i class="fas fa-phone" style="color:#64748b;"></i> Contact Number is optional (6th column)</div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title"><i class="fas fa-download"></i> Download Template</div>
                    <p style="font-size:0.83rem;color:var(--text-muted);margin-bottom:14px;">Get a ready-to-fill template file:</p>
                    <a href="../api/download_template.php?type=teachers" class="btn btn-secondary" style="width:100%;display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;"><i class="fas fa-file-excel"></i> Download Teacher Template (.xlsx)</a>
                </div>
            </div>
        </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    const allSections = <?= json_encode($sections) ?>;
    function filterImpSections() {
        const schoolId = document.getElementById('imp_school').value;
        const gradeId = document.getElementById('imp_grade').value;
        const sel = document.getElementById('imp_section');
        sel.innerHTML = '<option value="">Select Section</option>';
        allSections.forEach(s => {
            if ((!schoolId || s.school_id == schoolId) && (!gradeId || s.grade_level_id == gradeId)) {
                sel.innerHTML += `<option value="${s.id}">${s.name}</option>`;
            }
        });
    }

    function switchTab(tab) {
        document.querySelectorAll('.import-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.getElementById('tab-' + tab).classList.add('active');
        event.target.closest('.import-tab').classList.add('active');
    }

    // Drag & drop for all drop zones
    document.querySelectorAll('.drop-zone').forEach(dropZone => {
        ['dragenter', 'dragover'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.style.borderColor = 'var(--primary)'; }));
        ['dragleave', 'drop'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.style.borderColor = 'var(--border)'; }));
        dropZone.addEventListener('drop', ev => {
            const file = ev.dataTransfer.files[0];
            if (file) {
                const input = dropZone.parentElement.querySelector('input[type="file"]');
                const label = dropZone.querySelector('p:first-of-type');
                const dt = new DataTransfer();
                dt.items.add(file);
                input.files = dt.files;
                label.textContent = file.name;
            }
        });
    });
    </script>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>
</body>
</html>
