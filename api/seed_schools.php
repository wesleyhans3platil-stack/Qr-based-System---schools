<?php
/**
 * One-time script to seed all Sipalay City schools.
 * Run once, then delete. Skips schools that already exist by name.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
$conn = getDBConnection();

$schools = [
    'Agripino Alvarez Elementary School',
    'Banag Elementary School',
    'Barangay V Elementary School',
    'Barasbarasan Elementary School',
    'Bawog Elementary School',
    'Binotusan Elementary School',
    'Binulig Elementary School',
    'Bungabunga Elementary School',
    'Cabadiangan Elementary School',
    'Calangcang Elementary School',
    'Calat-an Elementary School',
    'Cambogui-ot Elementary School',
    'Camindangan Elementary School',
    'Cansauro Elementary School',
    'Cantaca Elementary School',
    'Canturay Elementary School',
    'Cartagena Elementary School',
    'Cayhagan Elementary School',
    'Crossing Tanduay Elementary School',
    'Genaro P. Alvarez Elementary School',
    'Genaro P. Alvarez Elementary School II',
    'Gil M. Montilla Elementary School',
    'Hda. Maricalum Elementary School',
    'Manlucahoc Elementary School',
    'Maricalum Elementary School',
    'Nabulao Elementary School',
    'Nauhang Primary School',
    'Patag Magbanua Elementary School',
    'Dungga Integrated School',
    'Dung-i Integrated School',
    'Macarandan Integrated School',
    'Mauboy Integrated School',
    'Omas Integrated School',
    'Tugas Integrated School',
    'Vista Alegre Integrated School',
    'Cambogui-ot National High School',
    'Camindangan National High School',
    'Cayhagan National High School',
    'Gil Montilla National High School',
    'Jacinto Montilla Memorial National High School',
    'Leodegario Ponce Gonzales National High School',
    'Mariano Gemora National High School',
    'Maricalum Farm School',
    'Nabulao National High School',
    'Sipalay City National High School',
];

// Get next code number
$code_r = $conn->query("SELECT MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) as max_num FROM schools WHERE code LIKE 'SCH-%'");
$next_num = ($code_r && $row = $code_r->fetch_assoc()) ? (int)$row['max_num'] + 1 : 1;

$stmt_check = $conn->prepare("SELECT id FROM schools WHERE name = ?");
$stmt_insert = $conn->prepare("INSERT INTO schools (name, code, status) VALUES (?, ?, 'active')");

$added = 0;
$skipped = 0;

foreach ($schools as $name) {
    $stmt_check->bind_param("s", $name);
    $stmt_check->execute();
    $stmt_check->store_result();

    if ($stmt_check->num_rows > 0) {
        $skipped++;
        continue;
    }

    $code = 'SCH-' . str_pad($next_num, 3, '0', STR_PAD_LEFT);
    $stmt_insert->bind_param("ss", $name, $code);
    if ($stmt_insert->execute()) {
        $added++;
        $next_num++;
    }
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'added' => $added,
    'skipped' => $skipped,
    'total' => count($schools)
]);
