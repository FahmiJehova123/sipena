<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'student') {
    exit('Unauthorized');
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$student_id = $_SESSION['user_id'];
$school_type = isset($_GET['school_type']) ? $_GET['school_type'] : 'pagi'; // 'pagi' atau 'diniyyah'

// Ambil data siswa
$student_raw = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $student_id]);
if (!is_array($student_raw) || count($student_raw) == 0) exit('Siswa tidak ditemukan.');
$student = $student_raw[0];

// Ambil kelas siswa berdasarkan school_type
$field = ($school_type == 'pagi') ? 'kelas_pagi_id' : 'kelas_diniyyah_id';
$class_id = $student[$field] ?? null;
if (!$class_id) exit('Anda tidak terdaftar di kelas ' . $school_type);

// Ambil grade_level kelas
$class_raw = supabase_admin_request('GET', 'classes', null, ['id' => 'eq.' . $class_id, 'select' => 'grade_level, class_name']);
if (!is_array($class_raw) || count($class_raw) == 0) exit('Kelas tidak ditemukan.');
$class = $class_raw[0];
$grade_level = $class['grade_level'];
$class_name = $class['class_name'];

// Ambil semua mata pelajaran berdasarkan grade_level
$subjects_raw = supabase_admin_request('GET', 'subjects', null, ['grade_level' => 'eq.' . $grade_level, 'order' => 'subject_name.asc']);
$subjects = [];
if (is_array($subjects_raw)) {
    foreach ($subjects_raw as $s) if (isset($s['id'])) $subjects[$s['id']] = $s['subject_name'];
}
if (empty($subjects)) exit('Tidak ada mata pelajaran untuk tingkat ini.');

// Ambil semua nilai exam_scores siswa
$exam_scores_raw = supabase_admin_request('GET', 'exam_scores', null, ['student_id' => 'eq.' . $student_id]);
$exam_scores = is_array($exam_scores_raw) ? $exam_scores_raw : [];

// Kelompokkan berdasarkan tahun ajaran, semester, dan mapel
$scores_by_year_sem = [];
foreach ($exam_scores as $es) {
    $year = $es['academic_year'];
    $sem = $es['semester'];
    $subj_id = $es['subject_id'];
    if (!isset($subjects[$subj_id])) continue; // hanya mapel yang sesuai grade_level
    if (!isset($scores_by_year_sem[$year][$sem])) $scores_by_year_sem[$year][$sem] = [];
    $scores_by_year_sem[$year][$sem][$subj_id] = $es['score'];
}

// Urutkan tahun ajaran dan semester
krsort($scores_by_year_sem); // tahun terbaru dulu
foreach ($scores_by_year_sem as &$sem_arr) {
    ksort($sem_arr); // semester 1 dulu
}
unset($sem_arr);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Leger Nilai');

// Header
$sheet->setCellValue('A1', 'Nama Siswa');
$sheet->setCellValue('B1', 'NIS/NISN');
$sheet->setCellValue('C1', 'Kelas');
$sheet->setCellValue('D1', 'Tipe Sekolah');
$sheet->setCellValue('E1', 'Tahun Ajaran');
$sheet->setCellValue('F1', 'Semester');
$col_start = 7;
$colIdx = $col_start;
foreach ($subjects as $subj_name) {
    $colLetter = Coordinate::stringFromColumnIndex($colIdx);
    $sheet->setCellValue($colLetter . '1', $subj_name);
    $colIdx++;
}
$sheet->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . '1', 'Rata-rata');
$sheet->getStyle('1')->getFont()->setBold(true);

$row = 2;
foreach ($scores_by_year_sem as $year => $semesters) {
    foreach ($semesters as $sem => $subject_scores) {
        $sheet->setCellValue('A' . $row, $student['full_name']);
        $sheet->setCellValue('B' . $row, $student['nidn_or_nisn']);
        $sheet->setCellValue('C' . $row, $class_name);
        $sheet->setCellValue('D' . $row, ucfirst($school_type));
        $sheet->setCellValue('E' . $row, $year);
        $sheet->setCellValue('F' . $row, ($sem == 1 ? 'Ganjil' : 'Genap'));
        
        $total = 0;
        $count = 0;
        $colIdx = $col_start;
        foreach ($subjects as $subj_id => $subj_name) {
            $score = isset($subject_scores[$subj_id]) ? $subject_scores[$subj_id] : '';
            $colLetter = Coordinate::stringFromColumnIndex($colIdx);
            $sheet->setCellValue($colLetter . $row, (is_numeric($score) ? number_format($score, 2) : '-'));
            if (is_numeric($score)) {
                $total += $score;
                $count++;
            }
            $colIdx++;
        }
        $avg = ($count > 0) ? round($total / $count, 2) : '-';
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $row, $avg);
        $row++;
    }
}

// Auto size kolom
foreach (range('A', Coordinate::stringFromColumnIndex($colIdx + 1)) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$filename = 'leger_nilai_' . $student['full_name'] . '_' . $school_type . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>