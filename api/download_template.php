<?php
/**
 * Generate and download XLSX template files for bulk import.
 * Includes dropdown data validation for School and Grade columns.
 * Usage: download_template.php?type=students  or  download_template.php?type=teachers
 */

require_once __DIR__ . '/../config/database.php';

$type = $_GET['type'] ?? 'students';

// ─── Fetch dropdown data from database ─────────────
$schoolNames = [];
$r = $conn->query("SELECT name FROM schools WHERE status='active' ORDER BY name");
if ($r) while ($row = $r->fetch_assoc()) $schoolNames[] = $row['name'];

$gradeNames = [];
$r = $conn->query("SELECT name FROM grade_levels ORDER BY id");
if ($r) while ($row = $r->fetch_assoc()) $gradeNames[] = $row['name'];

// SHS Track options
$shsTracks = ['STEM','ABM','HUMSS','GAS','TVL-HE','TVL-ICT','TVL-IA','TVL-AFA','Sports','Arts & Design'];
// SHS grades only (Grade 11, Grade 12)
$shsGradeNames = array_filter($gradeNames, function($g) { return preg_match('/Grade 1[12]/', $g); });
$shsGradeNames = array_values($shsGradeNames);

// ─── Template configuration ─────────────
if ($type === 'teachers') {
    $filename = 'teacher_import_template.xlsx';
    $headers = ['Employee ID', 'Name', 'School', 'Grade', 'Section', 'Contact Number'];
    $hints = ['e.g. EMP-001', 'Last Name, First Name, Middle Name', '(Select from dropdown)', '(Select from dropdown)', 'Capital first letter (e.g. Banana)', '09XXXXXXXXX'];
    $rows = [];
    $dropdowns = [
        2 => $schoolNames, // Column C = School
        3 => $gradeNames,  // Column D = Grade
    ];
    $maxDataRows = 500;
} elseif ($type === 'shs_students') {
    $filename = 'shs_student_import_template.xlsx';
    $headers = ['LRN', 'Student Name', 'School', 'Grade', 'Track/Strand', 'Section', 'Guardian Contact'];
    $hints = ['e.g. 123456789012', 'Last Name, First Name, Middle Name', '(Select from dropdown)', '(Select from dropdown)', '(Select from dropdown)', 'Capital first letter (e.g. Banana)', '09XXXXXXXXX'];
    $rows = [];
    $dropdowns = [
        2 => $schoolNames,     // Column C = School
        3 => $shsGradeNames,   // Column D = Grade (only Grade 11, 12)
        4 => $shsTracks,       // Column E = Track/Strand
    ];
    $maxDataRows = 1000;
} else {
    $filename = 'student_import_template.xlsx';
    $headers = ['LRN', 'Student Name', 'School', 'Grade', 'Section', 'Guardian Contact'];
    $hints = ['e.g. 123456789012', 'Last Name, First Name, Middle Name', '(Select from dropdown)', '(Select from dropdown)', 'Capital first letter (e.g. Banana)', '09XXXXXXXXX'];
    $rows = [];
    $dropdowns = [
        2 => $schoolNames,  // Column C = School
        3 => $gradeNames,   // Column D = Grade
    ];
    $maxDataRows = 1000;
}

// ─── Build XLSX (Office Open XML) ─────────────
function colLetter($i) {
    if ($i < 26) return chr(65 + $i);
    return chr(64 + intdiv($i, 26)) . chr(65 + ($i % 26));
}

function escapeXml($str) {
    return htmlspecialchars((string)$str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// ─── Collect all strings for shared strings table ─────────────
$allStrings = [];
$stringMap = [];

function addString($str) {
    global $allStrings, $stringMap;
    if (!isset($stringMap[$str])) {
        $stringMap[$str] = count($allStrings);
        $allStrings[] = $str;
    }
    return $stringMap[$str];
}

// Rich text header+hint entries stored separately
$headerRichIndices = [];
foreach ($headers as $i => $h) {
    // We'll build rich text entries directly in shared strings XML
    $headerRichIndices[$i] = count($allStrings);
    $allStrings[] = ['rich' => true, 'header' => $h, 'hint' => $hints[$i]];
}
foreach ($rows as $row) { foreach ($row as $cell) addString($cell); }
foreach ($dropdowns as $colIdx => $values) {
    foreach ($values as $v) addString($v);
}

// ─── Shared Strings XML ─────────────
$ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($allStrings) . '" uniqueCount="' . count($allStrings) . '">';
foreach ($allStrings as $s) {
    if (is_array($s) && !empty($s['rich'])) {
        // Rich text: bold header + line break + italic green hint
        $ssXml .= '<si>'
            . '<r><rPr><b/><sz val="11"/><color rgb="FFFFFFFF"/><rFont val="Calibri"/></rPr><t>' . escapeXml($s['header']) . '</t></r>'
            . '<r><rPr><sz val="9"/><color rgb="FF90EE90"/><rFont val="Calibri"/></rPr><t xml:space="preserve">' . "\n" . '(' . escapeXml($s['hint']) . ')' . '</t></r>'
            . '</si>';
    } else {
        $ssXml .= '<si><t>' . escapeXml($s) . '</t></si>';
    }
}
$ssXml .= '</sst>';

// ─── Stylesheet XML ─────────────
$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<fonts count="3">'
    . '<font><sz val="11"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
    . '<font><i/><sz val="10"/><color rgb="FF888888"/><name val="Calibri"/></font>'
    . '</fonts>'
    . '<fills count="3">'
    . '<fill><patternFill patternType="none"/></fill>'
    . '<fill><patternFill patternType="gray125"/></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FF4338CA"/></patternFill></fill>'
    . '</fills>'
    . '<borders count="1"><border/></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="3">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    . '<xf numFmtId="0" fontId="0" fillId="2" borderId="0" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    . '</cellXfs>'
    . '</styleSheet>';

// ─── Sheet 1 (Template) ─────────────
$sheet1Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<cols>';
foreach ($headers as $i => $h) {
    $width = max(strlen($h), 20) + 8;
    $sheet1Xml .= '<col min="' . ($i+1) . '" max="' . ($i+1) . '" width="' . $width . '" bestFit="1" customWidth="1"/>';
}
$sheet1Xml .= '</cols><sheetData>';

// Header row — rich text bold white + italic hint, on purple (style=1), taller row
$sheet1Xml .= '<row r="1" ht="38" customHeight="1">';
foreach ($headers as $i => $h) {
    $ref = colLetter($i) . '1';
    $sheet1Xml .= '<c r="' . $ref . '" t="s" s="1"><v>' . $headerRichIndices[$i] . '</v></c>';
}
$sheet1Xml .= '</row>';

// Sample data rows (style=2 = text format)
foreach ($rows as $ri => $row) {
    $rowNum = $ri + 2;
    $sheet1Xml .= '<row r="' . $rowNum . '">';
    foreach ($row as $ci => $cell) {
        $ref = colLetter($ci) . $rowNum;
        $sheet1Xml .= '<c r="' . $ref . '" t="s" s="2"><v>' . $stringMap[$cell] . '</v></c>';
    }
    $sheet1Xml .= '</row>';
}

$sheet1Xml .= '</sheetData>';

// ─── Data Validations (dropdown lists from hidden Lists sheet) ─────────────
if (!empty($dropdowns)) {
    $sheet1Xml .= '<dataValidations count="' . count($dropdowns) . '">';
    $listColOffset = 0;
    foreach ($dropdowns as $colIdx => $values) {
        if (empty($values)) { $listColOffset++; continue; }
        $col = colLetter($colIdx);
        $listCol = colLetter($listColOffset);
        $sqref = $col . '2:' . $col . $maxDataRows;
        $listRef = 'Lists!' . '$' . $listCol . '$1:$' . $listCol . '$' . count($values);
        $sheet1Xml .= '<dataValidation type="list" allowBlank="1" showDropDown="0" showInputMessage="1" showErrorMessage="1" sqref="' . $sqref . '"'
            . ' errorTitle="Invalid Value" error="Please select a value from the dropdown list." promptTitle="Select" prompt="Choose from the list">'
            . '<formula1>' . $listRef . '</formula1>'
            . '</dataValidation>';
        $listColOffset++;
    }
    $sheet1Xml .= '</dataValidations>';
}

$sheet1Xml .= '</worksheet>';

// ─── Sheet 2 (Lists — hidden, stores dropdown values) ─────────────
$sheet2Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetData>';

$maxRows = 0;
foreach ($dropdowns as $values) { $maxRows = max($maxRows, count($values)); }

for ($r = 0; $r < $maxRows; $r++) {
    $rowNum = $r + 1;
    $sheet2Xml .= '<row r="' . $rowNum . '">';
    $cIdx = 0;
    foreach ($dropdowns as $colIdx => $values) {
        if (isset($values[$r])) {
            $ref = colLetter($cIdx) . $rowNum;
            $sheet2Xml .= '<c r="' . $ref . '" t="s"><v>' . $stringMap[$values[$r]] . '</v></c>';
        }
        $cIdx++;
    }
    $sheet2Xml .= '</row>';
}

$sheet2Xml .= '</sheetData></worksheet>';

// ─── Workbook XML (2 sheets, Lists is hidden) ─────────────
$wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets>'
    . '<sheet name="Template" sheetId="1" r:id="rId1"/>'
    . '<sheet name="Lists" sheetId="2" state="hidden" r:id="rId2"/>'
    . '</sheets></workbook>';

// ─── Workbook relationships ─────────────
$wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
    . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
    . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>';

// ─── Root relationships ─────────────
$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>';

// ─── Content types ─────────────
$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '</Types>';

// ─── Create ZIP (XLSX is a ZIP) ─────────────
$tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
$zip = new ZipArchive();
$zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rootRels);
$zip->addFromString('xl/workbook.xml', $wbXml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Xml);
$zip->addFromString('xl/worksheets/sheet2.xml', $sheet2Xml);
$zip->addFromString('xl/sharedStrings.xml', $ssXml);
$zip->addFromString('xl/styles.xml', $stylesXml);
$zip->close();

// ─── Send file ─────────────
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-cache, no-store, must-revalidate');
readfile($tmpFile);
@unlink($tmpFile);
exit;
