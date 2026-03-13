<?php
/**
 * Seed sample/demo students into each school.
 * POST only, super_admin only.
 * Creates sections, assigns adviser, and generates QR codes.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
$conn = getDBConnection();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}
if (!isset($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Handle clear action
$action = $_POST['action'] ?? 'seed';
if ($action === 'clear') {
    $conn->begin_transaction();
    try {
        // Delete attendance records for seeded students (QR starts with STU-)
        $conn->query("DELETE a FROM attendance a JOIN students s ON a.person_id = s.id AND a.person_type='student' WHERE s.qr_code LIKE 'STU-%'");
        // Delete seeded students
        $conn->query("DELETE FROM students WHERE qr_code LIKE 'STU-%'");
        $deleted = $conn->affected_rows;
        // Remove sections that now have zero students
        $conn->query("DELETE sec FROM sections sec LEFT JOIN students s ON s.section_id = sec.id WHERE s.id IS NULL");
        $sections_removed = $conn->affected_rows;
        $conn->commit();
        echo json_encode(['success' => true, 'deleted' => $deleted, 'sections_removed' => $sections_removed]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$count_per_school = max(1, min(50, (int)($_POST['count'] ?? 10)));

// Filipino first/last names for realistic sample data
$first_names = [
    'Juan', 'Maria', 'Jose', 'Ana', 'Pedro', 'Rosa', 'Carlos', 'Elena',
    'Miguel', 'Sofia', 'Antonio', 'Isabella', 'Rafael', 'Gabriela', 'Fernando',
    'Andrea', 'Ricardo', 'Patricia', 'Eduardo', 'Catalina', 'Roberto', 'Lucia',
    'Daniel', 'Carmen', 'Francisco', 'Teresa', 'Manuel', 'Beatriz', 'Alejandro',
    'Victoria', 'Joaquin', 'Margarita', 'Santiago', 'Esperanza', 'Lorenzo',
    'Cristina', 'Gabriel', 'Dolores', 'Ramon', 'Mercedes', 'Enrique', 'Pilar',
    'Marco', 'Rosario', 'Andres', 'Concepcion', 'Emilio', 'Felicidad',
    'Angelo', 'Jasmine', 'Renz', 'Althea', 'Kyle', 'Jhane', 'Mark', 'Princess',
    'John', 'April', 'James', 'Cherry', 'Bryan', 'Nicole', 'Kevin', 'Arianne'
];
$last_names = [
    'Santos', 'Reyes', 'Cruz', 'Bautista', 'Del Rosario', 'Gonzales', 'Lopez',
    'Garcia', 'Mendoza', 'Torres', 'Rivera', 'Flores', 'Ramos', 'Villanueva',
    'De Leon', 'Morales', 'Aquino', 'Castro', 'Dela Cruz', 'Pascual',
    'Soriano', 'Fernandez', 'Salvador', 'Manalo', 'Tolentino', 'Navarro',
    'Mercado', 'Aguilar', 'Valdez', 'Hernandez', 'Corpuz', 'Dizon',
    'Magno', 'Pangilinan', 'Galang', 'Ocampo', 'Magsaysay', 'Rosal',
    'Dimaculangan', 'Bustamante', 'Ilagan', 'Cordova', 'Estrella', 'Librado'
];

// Load schools
$schools = [];
$r = $conn->query("SELECT id, name FROM schools WHERE status='active' ORDER BY name");
if ($r) while ($row = $r->fetch_assoc()) $schools[] = $row;

if (empty($schools)) {
    echo json_encode(['success' => false, 'error' => 'No active schools found']);
    exit;
}

// Load grade levels
$grades = [];
$r = $conn->query("SELECT id, name FROM grade_levels ORDER BY id");
if ($r) while ($row = $r->fetch_assoc()) $grades[] = $row;

$section_names = ['Apple', 'Mango', 'Sampaguita', 'Orchid', 'Rose', 'Narra', 'Molave', 'Acacia'];

$total_added = 0;
$total_skipped = 0;
$sections_created = 0;

$conn->begin_transaction();

try {
    foreach ($schools as $school) {
        // Determine grade range based on school name
        if (stripos($school['name'], 'Primary') !== false) {
            $grade_range = array_slice($grades, 0, 3); // K to Grade 2
        } elseif (stripos($school['name'], 'Elementary') !== false) {
            $grade_range = array_slice($grades, 0, 7); // K to Grade 6
        } elseif (stripos($school['name'], 'Integrated') !== false) {
            $grade_range = array_slice($grades, 0, 10); // K to Grade 9
        } elseif (stripos($school['name'], 'High School') !== false || stripos($school['name'], 'Farm School') !== false) {
            $grade_range = array_slice($grades, 6, 4); // Grade 7 to 10
        } else {
            $grade_range = array_slice($grades, 0, 7); // default elementary
        }

        if (empty($grade_range)) continue;

        $students_added_this_school = 0;

        for ($i = 0; $i < $count_per_school; $i++) {
            // Pick random grade
            $grade = $grade_range[array_rand($grade_range)];

            // Get or create a section for this grade in this school
            $sec_name = $section_names[$i % count($section_names)];
            $sec_r = $conn->query("SELECT id FROM sections WHERE school_id = {$school['id']} AND grade_level_id = {$grade['id']} AND name = '" . $conn->real_escape_string($sec_name) . "' LIMIT 1");
            if ($sec_r && $sec_row = $sec_r->fetch_assoc()) {
                $section_id = $sec_row['id'];
            } else {
                $stmt = $conn->prepare("INSERT INTO sections (name, school_id, grade_level_id, status) VALUES (?, ?, ?, 'active')");
                $stmt->bind_param("sii", $sec_name, $school['id'], $grade['id']);
                $stmt->execute();
                $section_id = $conn->insert_id;
                $sections_created++;
            }

            // Generate unique LRN (12 digits)
            $lrn = str_pad(mt_rand(100000000000, 999999999999), 12, '0', STR_PAD_LEFT);
            // Check LRN uniqueness
            $lr = $conn->query("SELECT id FROM students WHERE lrn = '$lrn'");
            if ($lr && $lr->num_rows > 0) {
                $lrn = str_pad(mt_rand(100000000000, 999999999999), 12, '0', STR_PAD_LEFT);
            }

            // Generate name
            $fname = $first_names[array_rand($first_names)];
            $lname = $last_names[array_rand($last_names)];
            $full_name = "$lname, $fname";

            // Generate guardian contact
            $guardian = '09' . str_pad(mt_rand(100000000, 999999999), 9, '0', STR_PAD_LEFT);

            // Generate QR code
            $qr_code = 'STU-' . $school['id'] . '-' . $lrn . '-' . bin2hex(random_bytes(4));

            // Backdate created_at so students appear in 2-day absence alerts
            $days_ago = mt_rand(3, 30);
            $created_at = date('Y-m-d H:i:s', strtotime("-{$days_ago} days"));

            // Seeded students should start inactive until they scan in
            $stmt = $conn->prepare("INSERT INTO students (lrn, name, school_id, grade_level_id, section_id, guardian_contact, qr_code, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'inactive', ?)");
            $stmt->bind_param("ssiiisss", $lrn, $full_name, $school['id'], $grade['id'], $section_id, $guardian, $qr_code, $created_at);

            if ($stmt->execute()) {
                $total_added++;
                $students_added_this_school++;
            } else {
                $total_skipped++;
            }
        }
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'students_added' => $total_added,
        'students_skipped' => $total_skipped,
        'sections_created' => $sections_created,
        'schools_count' => count($schools),
        'per_school' => $count_per_school
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
