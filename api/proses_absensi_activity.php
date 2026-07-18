<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$input = json_decode(file_get_contents('php://input'), true);
$qr_data = $input['qr_data'] ?? '';
$activity_id = $input['activity_id'] ?? '';

if (empty($qr_data) || empty($activity_id)) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
    exit;
}

$verification = verify_qr_code($qr_data);
if (!$verification['valid']) {
    echo json_encode(['success' => false, 'message' => 'QR Code tidak valid']);
    exit;
}

$user_id = $verification['user_id'];
$status = 'Hadir'; // status default

// Cek apakah sudah absen untuk kegiatan ini hari ini
$todayStart = date('Y-m-d') . 'T00:00:00';
$todayEnd = date('Y-m-d') . 'T23:59:59';
$existing = supabase_admin_request('GET', 'attendance_logs', null, [
    'user_id' => 'eq.' . $user_id,
    'activity_id' => 'eq.' . (int)$activity_id,
    'scan_time' => 'gte.' . $todayStart,
    'scan_time' => 'lte.' . $todayEnd
]);

if (!empty($existing)) {
    echo json_encode(['success' => false, 'message' => 'Anda sudah absen untuk kegiatan ini hari ini']);
    exit;
}

$data = [
    'user_id' => $user_id,
    'activity_id' => (int)$activity_id,
    'status' => $status,
    'scan_time' => date('Y-m-d H:i:s')
];

$result = supabase_admin_request('POST', 'attendance_logs', $data);
error_log("Insert result: " . json_encode($result)); // log ke file error_log
if (isset($result['id'])) {
    echo json_encode(['success' => true, 'message' => 'Absensi berhasil']);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . json_encode($result)]);
}

?>