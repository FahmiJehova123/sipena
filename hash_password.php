<?php
// hash_password.php - Jalankan sekali, lalu hapus
require_once 'config.php';
$plain_password = 'admin123';
$hashed = password_hash($plain_password, PASSWORD_DEFAULT);
echo "Hashed password: " . $hashed;
// Contoh: $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
?>