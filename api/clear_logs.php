<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

// Hapus semua data dengan kondisi id >= 0 (selalu benar)
$result = supabase_admin_request('DELETE', 'attendance_logs', null, ['id' => 'gte.0']);

// Jika response adalah array kosong atau null, dianggap sukses
if ($result === null || (is_array($result) && empty($result))) {
    echo json_encode(['success' => true, 'message' => 'Semua log berhasil dihapus']);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal menghapus log: ' . json_encode($result)]);
}
?>