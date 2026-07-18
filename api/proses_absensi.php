<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php'; // untuk verify_qr_code

$input = json_decode(file_get_contents('php://input'), true);
$qr_data = $input['qr_data'] ?? '';

if (empty($qr_data)) {
    echo json_encode(['success' => false, 'message' => 'Data QR kosong']);
    exit;
}

$verification = verify_qr_code($qr_data);
if (!$verification['valid']) {
    echo json_encode(['success' => false, 'message' => 'QR Code tidak valid atau sudah digunakan']);
    exit;
}

$user_id = $verification['user_id'];
$schedule_id = $verification['schedule_id'];

// Cek apakah sudah absen hari ini untuk jadwal yang sama
$today_start = date('Y-m-d') . 'T00:00:00';
$today_end = date('Y-m-d') . 'T23:59:59';
$existing = supabase_admin_request('GET', 'attendance_logs', null, [
    'user_id' => 'eq.' . $user_id,
    'schedule_id' => 'eq.' . $schedule_id,
    'scan_time' => 'gte.' . $today_start,
    'scan_time' => 'lte.' . $today_end
]);

if (!empty($existing)) {
    echo json_encode(['success' => false, 'message' => 'User sudah absen untuk jadwal ini hari ini']);
    exit;
}

// Simpan absensi
$data = [
    'user_id' => $user_id,
    'schedule_id' => $schedule_id,
    'status' => 'Hadir',
    'scan_time' => date('Y-m-d H:i:s')
];

$result = supabase_admin_request('POST', 'attendance_logs', $data);

if (isset($result['id'])) {
    echo json_encode(['success' => true, 'message' => 'Absensi berhasil']);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . json_encode($result)]);
}
?>