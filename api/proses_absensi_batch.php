<?php
// api/proses_absensi_batch.php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
session_start();

// Hanya teacher dan admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] != 'admin' && $_SESSION['user_role'] != 'teacher')) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

if (!isset($input['csrf_token']) || $input['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$schedule_id = $input['schedule_id'] ?? '';
$attendances = $input['attendances'] ?? [];
$tanggal = $input['tanggal'] ?? date('Y-m-d');

if (empty($schedule_id) || empty($attendances)) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
    exit;
}

$schedule_id = intval($schedule_id);

// Validasi kepemilikan jadwal
$teacher_id = $_SESSION['user_id'];
$schedule_check = supabase_admin_request('GET', 'schedules', null, [
    'id' => 'eq.' . $schedule_id,
    'teacher_id' => 'eq.' . $teacher_id
]);
if (!is_array($schedule_check) || empty($schedule_check)) {
    echo json_encode(['success' => false, 'message' => 'Jadwal tidak valid atau bukan milik Anda']);
    exit;
}

$allowed_status = ['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpha'];
$filtered_attendances = [];
foreach ($attendances as $user_id => $status) {
    if (in_array($status, $allowed_status)) {
        $filtered_attendances[$user_id] = $status;
    }
}
if (empty($filtered_attendances)) {
    echo json_encode(['success' => false, 'message' => 'Tidak ada status yang valid']);
    exit;
}

// Ambil SEMUA log untuk schedule_id ini (tanpa filter tanggal)
$all_logs = supabase_admin_request('GET', 'attendance_logs', null, ['schedule_id' => 'eq.' . $schedule_id]);
$existing_logs = [];
if (is_array($all_logs)) {
    foreach ($all_logs as $log) {
        // Bandingkan tanggal (tanpa waktu)
        $log_date = date('Y-m-d', strtotime($log['scan_time']));
        if ($log_date === $tanggal) {
            $existing_logs[] = $log;
        }
    }
}

$existing_map = [];
foreach ($existing_logs as $log) {
    $existing_map[$log['user_id']] = $log['id'];
}

$to_insert = [];
$to_update = [];
foreach ($filtered_attendances as $user_id => $status) {
    if (isset($existing_map[$user_id])) {
        $to_update[] = ['id' => $existing_map[$user_id], 'status' => $status];
    } else {
        $to_insert[] = ['user_id' => $user_id, 'status' => $status];
    }
}

// Waktu scan (gunakan tanggal yang diminta + jam sekarang)
$now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
$scan_datetime = new DateTime($tanggal . ' ' . $now->format('H:i:s'), new DateTimeZone('Asia/Jakarta'));
$scan_datetime->setTimezone(new DateTimeZone('UTC'));
$scan_time_utc = $scan_datetime->format('Y-m-d\TH:i:s\Z');

$success_count = 0;
$errors = [];
$error_details = [];

// Insert baru
foreach ($to_insert as $item) {
    $data = [
        'user_id' => $item['user_id'],
        'schedule_id' => $schedule_id,
        'status' => $item['status'],
        'scan_time' => $scan_time_utc
    ];
    $result = supabase_admin_request('POST', 'attendance_logs', $data);
    if (is_array($result) && isset($result['id'])) {
        $success_count++;
    } else {
        $errors[] = $item['user_id'];
        $error_details[$item['user_id']] = is_array($result) ? json_encode($result) : 'null';
    }
}

// Update yang sudah ada
foreach ($to_update as $item) {
    $result = supabase_admin_request('PATCH', 'attendance_logs', ['status' => $item['status']], ['id' => 'eq.' . $item['id']]);
    if (is_array($result) && isset($result['id'])) {
        $success_count++;
    } else {
        $errors[] = $item['id'];
        $error_details[$item['id']] = is_array($result) ? json_encode($result) : 'null';
    }
}

$total = count($filtered_attendances);
if ($success_count === $total) {
    echo json_encode(['success' => true, 'message' => "✅ Semua absensi berhasil disimpan ($success_count siswa)"]);
} elseif ($success_count > 0) {
    echo json_encode(['success' => true, 'message' => "⚠️ $success_count dari $total absensi berhasil disimpan. " . (count($errors) ? "Gagal untuk ID: " . implode(',', $errors) : "")]);
} else {
    $detail = [];
    foreach ($error_details as $id => $msg) {
        $detail[] = "$id: $msg";
    }
    echo json_encode([
        'success' => false,
        'message' => 'Tidak ada absensi yang tersimpan. Gagal untuk ID: ' . implode(',', $errors),
        'details' => $detail
    ]);
}
?>