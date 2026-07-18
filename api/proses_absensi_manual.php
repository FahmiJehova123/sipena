<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

session_start();

// Baca input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

// Validasi CSRF token, kecuali dari kiosk
if (!isset($input['from_kiosk']) || $input['from_kiosk'] !== true) {
    if (!isset($input['csrf_token']) || $input['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
}

$user_id = $input['user_id'] ?? '';
$schedule_id = $input['schedule_id'] ?? '';
$status = $input['status'] ?? 'Hadir';
$tanggal = $input['tanggal'] ?? date('Y-m-d'); // ambil tanggal dari request, fallback hari ini

if (empty($user_id) || empty($schedule_id)) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
    exit;
}

$allowed = ['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpha'];
if (!in_array($status, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Status tidak valid']);
    exit;
}

// ========== VALIDASI USER & SCHEDULE ==========
$userResult = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $user_id]);
if (empty($userResult)) {
    echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
    exit;
}
$user = $userResult[0];
$role = $user['role'];

$scheduleResult = supabase_admin_request('GET', 'schedules', null, ['id' => 'eq.' . $schedule_id]);
if (empty($scheduleResult)) {
    echo json_encode(['success' => false, 'message' => 'Jadwal tidak ditemukan']);
    exit;
}
$schedule = $scheduleResult[0];

// Cek hak akses
if ($role == 'student') {
    $kelas_pagi = $user['kelas_pagi_id'] ?? null;
    $kelas_diniyyah = $user['kelas_diniyyah_id'] ?? null;
    $allowed_classes = [];
    if ($kelas_pagi) $allowed_classes[] = $kelas_pagi;
    if ($kelas_diniyyah) $allowed_classes[] = $kelas_diniyyah;
    if (empty($allowed_classes) || !in_array($schedule['class_id'], $allowed_classes)) {
        echo json_encode(['success' => false, 'message' => 'Siswa tidak terdaftar di kelas jadwal ini']);
        exit;
    }
} elseif ($role == 'teacher') {
    if ($schedule['teacher_id'] != $user_id) {
        echo json_encode(['success' => false, 'message' => 'Guru tidak mengajar jadwal ini']);
        exit;
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Role tidak valid untuk absensi manual']);
    exit;
}

// ========== CEK ABSENSI GANDA BERDASARKAN TANGGAL ==========
$timezone = new DateTimeZone('Asia/Jakarta');
$start_date = new DateTime($tanggal . ' 00:00:00', $timezone);
$end_date = new DateTime($tanggal . ' 23:59:59', $timezone);
$start_utc = $start_date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
$end_utc = $end_date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

$existing = supabase_admin_request('GET', 'attendance_logs', null, [
    'user_id' => 'eq.' . $user_id,
    'schedule_id' => 'eq.' . $schedule_id,
    'scan_time' => 'gte.' . $start_utc,
    'scan_time' => 'lte.' . $end_utc
]);

if (!empty($existing)) {
    echo json_encode(['success' => false, 'message' => 'User sudah absen untuk jadwal ini pada tanggal ' . $tanggal]);
    exit;
}

// ========== BUAT SCAN_TIME DENGAN TANGGAL YANG DIPILIH ==========
$now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
$scan_datetime = new DateTime($tanggal . ' ' . $now->format('H:i:s'), new DateTimeZone('Asia/Jakarta'));
$scan_datetime->setTimezone(new DateTimeZone('UTC'));
$scan_time_utc = $scan_datetime->format('Y-m-d\TH:i:s\Z');

// ========== SIMPAN ABSENSI ==========
$data = [
    'user_id' => $user_id,
    'schedule_id' => $schedule_id,
    'status' => $status,
    'scan_time' => $scan_time_utc
];

$result = supabase_admin_request('POST', 'attendance_logs', $data);

if ($result !== null && (isset($result['id']) || $result === ['id' => 'unknown', 'message' => 'Created (empty response)'])) {
    echo json_encode(['success' => true, 'message' => 'Absensi berhasil']);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . json_encode($result)]);
}
?>