<?php
// setup_admin.php - Jalankan sekali untuk membuat akun admin pertama
require_once 'config.php';

$email = 'admin@siakad.com';
$password_hash = password_hash('admin123', PASSWORD_DEFAULT);
$full_name = 'Super Admin';
$role = 'admin';
$nidn_or_nisn = 'ADMIN001';

// Cek apakah admin sudah ada
$existing = supabase_admin_request('GET', 'users', null, ['role' => 'eq.admin', 'nidn_or_nisn' => 'eq.ADMIN001']);
if (empty($existing)) {
    $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    $data = [
        'id' => $id,
        'email' => $email,
        'full_name' => $full_name,
        'role' => $role,
        'nidn_or_nisn' => $nidn_or_nisn,
        'password_hash' => $password_hash
    ];
    $result = supabase_admin_request('POST', 'users', $data);
    if (isset($result['id'])) {
        echo "Admin berhasil dibuat. Email: $email, Password: admin123\n";
    } else {
        echo "Gagal membuat admin: " . json_encode($result);
    }
} else {
    echo "Admin sudah ada. Update password jika perlu.\n";
    // Update password jika diperlukan
    $update = supabase_admin_request('PATCH', 'users', ['password_hash' => $password_hash], ['id' => 'eq.' . $existing[0]['id']]);
    echo "Password diupdate.\n";
}
?>