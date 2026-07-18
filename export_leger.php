<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] != 'admin' && $_SESSION['user_role'] != 'teacher')) {
    exit('Unauthorized');
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/scores_functions.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$type = $_GET['type'] ?? 'exam'; // exam atau report_card
$class_id = (int)$_GET['class_id'];
$academic_year = $_GET['academic_year'] ?? getCurrentAcademicYear();
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 1;
$exam_type_id = isset($_GET['exam_type_id']) ? (int)$_GET['exam_type_id'] : 0;

// Cek data kelas
$classes = getAllClasses();
$class_type = 'pagi';
$grade_level = 1;
foreach ($classes as $c) {
    if ($c['id'] == $class_id) {
        $class_type = $c['class_type'] ?? 'pagi';
        $grade_level = $c['grade_level'] ?? 1;
        break;
    }
}

// Validasi untuk teacher: hanya boleh export kelas yang diajar
if ($_SESSION['user_role'] == 'teacher') {
    $teacher_id = $_SESSION['user_id'];
    $allowed = false;
    $teacher_classes = getTeacherClassesAll($teacher_id); // fungsi ini harus ada di scores_functions
    foreach ($teacher_classes as $tc) {
        if ($tc['id'] == $class_id) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        exit('Akses ditolak: Anda tidak mengajar kelas ini.');
    }
}

$students = getStudentsByClass($class_id, $class_type);
if (empty($students)) exit('Tidak ada siswa di kelas ini.');

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

if ($type == 'exam') {
    if ($exam_type_id == 0) exit('Jenis ujian tidak dipilih.');
    $subjects = getSubjectsByGradeLevel($grade_level); // semua mata pelajaran berdasarkan tingkat
    if (empty($subjects)) exit('Tidak ada mata pelajaran untuk tingkat kelas ini.');
    
    // Header
    $sheet->setCellValue('A1', 'No');
    $sheet->setCellValue('B1', 'Nama Siswa');
    $colIdx = 3;
    foreach ($subjects as $subj) {
        $colLetter = Coordinate::stringFromColumnIndex($colIdx);
        $sheet->setCellValue($colLetter . '1', $subj['subject_name']);
        $colIdx++;
    }
    $colLetter = Coordinate::stringFromColumnIndex($colIdx);
    $sheet->setCellValue($colLetter . '1', 'Rata-rata');
    $sheet->getStyle('1')->getFont()->setBold(true);
    
    $row = 2;
    $no = 1;
    foreach ($students as $student) {
        $sheet->setCellValue('A' . $row, $no++);
        $sheet->setCellValue('B' . $row, $student['full_name']);
        $total = 0; $cnt = 0;
        $colIdx = 3;
        foreach ($subjects as $subj) {
            $score = getScore($student['id'], $subj['id'], $exam_type_id, $academic_year, $semester);
            $val = $score !== null ? $score : '';
            $colLetter = Coordinate::stringFromColumnIndex($colIdx);
            $sheet->setCellValue($colLetter . $row, $val);
            if (is_numeric($val)) { $total += $val; $cnt++; }
            $colIdx++;
        }
        $colLetter = Coordinate::stringFromColumnIndex($colIdx);
        $avg = ($cnt > 0) ? round($total / $cnt, 2) : '';
        $sheet->setCellValue($colLetter . $row, $avg);
        $row++;
    }
    $filename = 'leger_nilai_ujian_kelas_' . $class_id . '_' . $academic_year . '.xlsx';
} else {
    // Export Ijazah / Report Card
    $subjects_report = getSubjectsByGradeLevel($grade_level);
    if (empty($subjects_report)) exit('Tidak ada mata pelajaran untuk tingkat kelas ini.');
    
    // Header
    $sheet->setCellValue('A1', 'No');
    $sheet->setCellValue('B1', 'Nama Siswa');
    $sheet->setCellValue('C1', 'Nomor Ijazah');
    $colIdx = 4;
    foreach ($subjects_report as $subj) {
        $colLetter = Coordinate::stringFromColumnIndex($colIdx);
        $sheet->setCellValue($colLetter . '1', $subj['subject_name']);
        $colIdx++;
    }
    $colLetter = Coordinate::stringFromColumnIndex($colIdx);
    $sheet->setCellValue($colLetter . '1', 'Rata-rata');
    $colIdx++;
    $colLetter = Coordinate::stringFromColumnIndex($colIdx);
    $sheet->setCellValue($colLetter . '1', 'Status');
    $sheet->getStyle('1')->getFont()->setBold(true);
    
    $row = 2;
    $no = 1;
    foreach ($students as $student) {
        $rc = getReportCard($student['id'], $academic_year);
        $cert_number = $rc['certificate_number'] ?? '';
        $status = $rc['status'] ?? 'lulus';
        $avg = $rc['average_score'] ?? 0;
        $scores_map = $rc ? getReportCardScores($rc['id']) : [];
        
        $sheet->setCellValue('A' . $row, $no++);
        $sheet->setCellValue('B' . $row, $student['full_name']);
        $sheet->setCellValue('C' . $row, $cert_number);
        $total = 0; $cnt = 0;
        $colIdx = 4;
        foreach ($subjects_report as $subj) {
            $val = $scores_map[$subj['id']] ?? '';
            $colLetter = Coordinate::stringFromColumnIndex($colIdx);
            $sheet->setCellValue($colLetter . $row, $val);
            if (is_numeric($val)) { $total += $val; $cnt++; }
            $colIdx++;
        }
        $colLetter = Coordinate::stringFromColumnIndex($colIdx);
        $avg_calc = ($cnt > 0) ? round($total / $cnt, 2) : $avg;
        $sheet->setCellValue($colLetter . $row, $avg_calc);
        $colIdx++;
        $colLetter = Coordinate::stringFromColumnIndex($colIdx);
        $sheet->setCellValue($colLetter . $row, ucfirst($status));
        $row++;
    }
    $filename = 'leger_ijazah_kelas_' . $class_id . '_' . $academic_year . '.xlsx';
}

// Auto size kolom
foreach (range('A', $sheet->getHighestColumn()) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>