<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$id = $_GET['id'] ?? '';
if (empty($id)) {
    echo json_encode([]);
    exit;
}

// Ambil data jadwal
$result = supabase_admin_request('GET', 'schedules', null, ['id' => 'eq.' . $id]);
if (!is_array($result) || empty($result)) {
    echo json_encode([]);
    exit;
}
$schedule = $result[0];

// Ambil nama kelas
$class_name = '-';
$class_data = supabase_admin_request('GET', 'classes', null, ['id' => 'eq.' . $schedule['class_id']]);
if (is_array($class_data) && !empty($class_data)) {
    $class_name = $class_data[0]['class_name'];
}

// Ambil nama mata pelajaran
$subject_name = '-';
$subject_data = supabase_admin_request('GET', 'subjects', null, ['id' => 'eq.' . $schedule['subject_id']]);
if (is_array($subject_data) && !empty($subject_data)) {
    $subject_name = $subject_data[0]['subject_name'];
}

// Ambil nama guru
$teacher_name = '-';
$teacher_data = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $schedule['teacher_id']]);
if (is_array($teacher_data) && !empty($teacher_data)) {
    $teacher_name = $teacher_data[0]['full_name'];
}

// Gabungkan data
$schedule['class_name'] = $class_name;
$schedule['subject_name'] = $subject_name;
$schedule['teacher_name'] = $teacher_name;

echo json_encode($schedule);
?>