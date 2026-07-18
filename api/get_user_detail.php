<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$id = $_GET['id'] ?? '';
if (empty($id)) {
    echo json_encode(['error' => 'ID tidak ditemukan']);
    exit;
}

// Ambil data user dari tabel users (termasuk kolom role)
$user_data = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $id]);
$user = is_array($user_data) && !empty($user_data) ? $user_data[0] : null;

if (!$user) {
    echo json_encode(['error' => 'User tidak ditemukan']);
    exit;
}

// Ambil nama kelas pagi
if (!empty($user['kelas_pagi_id'])) {
    $kelas_pagi = supabase_admin_request('GET', 'classes', null, ['id' => 'eq.' . $user['kelas_pagi_id']]);
    $user['kelas_pagi_nama'] = (is_array($kelas_pagi) && !empty($kelas_pagi)) ? $kelas_pagi[0]['class_name'] : '-';
} else {
    $user['kelas_pagi_nama'] = '-';
}

// Ambil nama kelas diniyyah
if (!empty($user['kelas_diniyyah_id'])) {
    $kelas_diniyyah = supabase_admin_request('GET', 'classes', null, ['id' => 'eq.' . $user['kelas_diniyyah_id']]);
    $user['kelas_diniyyah_nama'] = (is_array($kelas_diniyyah) && !empty($kelas_diniyyah)) ? $kelas_diniyyah[0]['class_name'] : '-';
} else {
    $user['kelas_diniyyah_nama'] = '-';
}

// Tambahkan field 'roles' sebagai array (untuk kompatibilitas dengan manage_users.js)
// Kolom 'role' mungkin masih ada di tabel users (berdasarkan skema)
$user['roles'] = [$user['role'] ?? 'user'];
$user['primary_role'] = $user['role'] ?? 'user';

echo json_encode($user);
?>