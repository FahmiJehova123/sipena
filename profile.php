<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['login_time'])) {
    header('Location: login.php');
    exit;
}
if (time() - $_SESSION['login_time'] > 28800) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['login_time'] = time();

$user_role = $_SESSION['user_role'];
$user_id = $_SESSION['user_id'];

$page_title = 'Profil Saya - SIAKAD';
$current_page = 'profile';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Generate CSRF token jika belum ada
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Ambil data user
$user_data = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $user_id]);
$user = (is_array($user_data) && !empty($user_data)) ? $user_data[0] : null;
if (!$user) {
    header('Location: logout.php');
    exit;
}

// Ambil nama kelas untuk student
$kelas_pagi = '-';
$kelas_diniyyah = '-';
if ($user_role == 'student') {
    if (!empty($user['kelas_pagi_id'])) {
        $k = supabase_admin_request('GET', 'classes', null, ['id' => 'eq.' . $user['kelas_pagi_id']]);
        $kelas_pagi = (is_array($k) && !empty($k)) ? $k[0]['class_name'] : '-';
    }
    if (!empty($user['kelas_diniyyah_id'])) {
        $k = supabase_admin_request('GET', 'classes', null, ['id' => 'eq.' . $user['kelas_diniyyah_id']]);
        $kelas_diniyyah = (is_array($k) && !empty($k)) ? $k[0]['class_name'] : '-';
    }
}

// Folder untuk foto profil
define('PROFILE_FOTO_DIR', __DIR__ . '/assets/img/profile/profile_user/');
if (!is_dir(PROFILE_FOTO_DIR)) {
    mkdir(PROFILE_FOTO_DIR, 0755, true);
}

// Helper: generate UUID untuk nama file
function generate_uuid_filename() {
    return sprintf('%04x%04x_%04x_%04x_%04x_%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}

// Fungsi kompres gambar ke ukuran maksimal (KB)
function compressImage($sourcePath, $destPath, $maxSizeKB = 500) {
    $maxSizeBytes = $maxSizeKB * 1024;
    
    // Cek ukuran file asli
    if (filesize($sourcePath) <= $maxSizeBytes) {
        // Jika sudah di bawah batas, copy saja tanpa kompresi
        copy($sourcePath, $destPath);
        return true;
    }
    
    // Dapatkan tipe gambar
    $imageInfo = getimagesize($sourcePath);
    if (!$imageInfo) return false;
    
    $mime = $imageInfo['mime'];
    $quality = 85; // kualitas awal
    $minQuality = 30;
    $maxDimension = 1500; // resize jika dimensi terlalu besar (opsional)
    
    // Buka gambar sesuai tipe
    switch ($mime) {
        case 'image/jpeg':
            $src = imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $src = imagecreatefrompng($sourcePath);
            // PNG perlu preserved transparency, kita konversi ke JPEG? Tidak, tetap PNG tapi kompres
            break;
        case 'image/gif':
            $src = imagecreatefromgif($sourcePath);
            break;
        case 'image/webp':
            $src = imagecreatefromwebp($sourcePath);
            break;
        default:
            return false;
    }
    
    if (!$src) return false;
    
    // Resize jika dimensi terlalu besar (opsional, untuk memperkecil ukuran)
    $width = imagesx($src);
    $height = imagesy($src);
    if ($width > $maxDimension || $height > $maxDimension) {
        $ratio = min($maxDimension / $width, $maxDimension / $height);
        $newWidth = round($width * $ratio);
        $newHeight = round($height * $ratio);
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($src);
        $src = $resized;
    }
    
    // Coba kompres dengan kualitas menurun hingga ukuran memenuhi
    $success = false;
    for ($q = $quality; $q >= $minQuality; $q -= 5) {
        // Tentukan fungsi simpan sesuai tipe
        switch ($mime) {
            case 'image/jpeg':
                $success = imagejpeg($src, $destPath, $q);
                break;
            case 'image/png':
                // Untuk PNG, quality 0-9 (0 = no compression, 9 = max compression)
                $pngQuality = 9 - round(($q / 100) * 9);
                $pngQuality = max(0, min(9, $pngQuality));
                $success = imagepng($src, $destPath, $pngQuality);
                break;
            case 'image/gif':
                $success = imagegif($src, $destPath);
                break;
            case 'image/webp':
                $success = imagewebp($src, $destPath, $q);
                break;
        }
        if (!$success) break;
        
        if (filesize($destPath) <= $maxSizeBytes) {
            $success = true;
            break;
        }
    }
    
    imagedestroy($src);
    
    // Jika setelah kompresi minimal masih > max size, maka resize lebih kecil lagi
    if (filesize($destPath) > $maxSizeBytes) {
        // Kompres ulang dengan resize lebih agresif (turunkan dimensi)
        $src2 = imagecreatefromstring(file_get_contents($destPath));
        if ($src2) {
            $width2 = imagesx($src2);
            $height2 = imagesy($src2);
            $newWidth2 = round($width2 * 0.7);
            $newHeight2 = round($height2 * 0.7);
            $resized2 = imagecreatetruecolor($newWidth2, $newHeight2);
            imagecopyresampled($resized2, $src2, 0, 0, 0, 0, $newWidth2, $newHeight2, $width2, $height2);
            // Simpan dengan kualitas terendah
            switch ($mime) {
                case 'image/jpeg': imagejpeg($resized2, $destPath, $minQuality); break;
                case 'image/png': imagepng($resized2, $destPath, 9); break;
                case 'image/webp': imagewebp($resized2, $destPath, $minQuality); break;
                default: imagejpeg($resized2, $destPath, $minQuality);
            }
            imagedestroy($resized2);
            imagedestroy($src2);
        }
    }
    
    return file_exists($destPath);
}

// Proses update profil (via modal)
$profile_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $profile_message = '<div class="bg-red-100 text-red-700 p-2 rounded">Token keamanan tidak valid.</div>';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $tempat_lahir = trim($_POST['tempat_lahir'] ?? '');
        $tanggal_lahir = trim($_POST['tanggal_lahir'] ?? '');
        $alamat = trim($_POST['alamat'] ?? '');
        
        $update_data = [];
        if (!empty($full_name)) $update_data['full_name'] = $full_name;
        if (!empty($email)) $update_data['email'] = $email;
        if (!empty($phone)) $update_data['phone'] = $phone;
        if (!empty($tempat_lahir)) $update_data['tempat_lahir'] = $tempat_lahir;
        if (!empty($tanggal_lahir)) $update_data['tanggal_lahir'] = $tanggal_lahir;
        if (!empty($alamat)) $update_data['alamat'] = $alamat;
        
        // Proses upload foto dengan kompresi
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                $profile_message = '<div class="bg-red-100 text-red-700 p-2 rounded">Format foto tidak didukung (JPG,PNG,GIF,WEBP).</div>';
            } else {
                $filename = generate_uuid_filename() . '.' . $ext;
                $temp_file = $_FILES['profile_photo']['tmp_name'];
                $target = PROFILE_FOTO_DIR . $filename;

                // Kompres gambar ke maksimal 500 KB
                if (compressImage($temp_file, $target, 500)) {
                    $domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
                    $photo_url = $domain . '/siakad/assets/img/profile/profile_user/' . $filename;
                    $update_data['photo_url'] = $photo_url;
                    // Hapus foto lama jika ada dan berasal dari lokal
                    if (!empty($user['photo_url']) && strpos($user['photo_url'], $domain) !== false) {
                        $old_file = PROFILE_FOTO_DIR . basename($user['photo_url']);
                        if (file_exists($old_file)) unlink($old_file);
                    }
                } else {
                    $profile_message = '<div class="bg-red-100 text-red-700 p-2 rounded">Gagal mengompres atau menyimpan foto.</div>';
                }
            }
        }
        
        if (!empty($update_data) && empty($profile_message)) {
            $update_data['updated_at'] = date('Y-m-d H:i:s');
            $result = supabase_admin_request('PATCH', 'users', $update_data, ['id' => 'eq.' . $user_id]);
            if (isset($result['id']) || (is_array($result) && empty($result))) {
                $profile_message = '<div class="bg-green-100 text-green-700 p-2 rounded">Data profil berhasil diperbarui.</div>';
                // Refresh data user
                $user_data = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $user_id]);
                $user = (is_array($user_data) && !empty($user_data)) ? $user_data[0] : $user;
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_photo'] = $user['photo_url'] ?? null;
            } else {
                $profile_message = '<div class="bg-red-100 text-red-700 p-2 rounded">Gagal menyimpan perubahan.</div>';
            }
        } elseif (empty($update_data)) {
            $profile_message = '<div class="bg-yellow-100 text-yellow-700 p-2 rounded">Tidak ada data yang diubah.</div>';
        }
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Proses ganti password (reset password) dengan perbaikan pesan
$password_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $password_message = '<div class="bg-red-100 text-red-700 p-2 rounded">Token keamanan tidak valid.</div>';
    } else {
        $old_password = $_POST['old_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($old_password) || empty($new_password) || empty($confirm_password)) {
            $password_message = '<div class="bg-red-100 text-red-700 p-2 rounded">Semua field password harus diisi.</div>';
        } elseif (strlen($new_password) < 6) {
            $password_message = '<div class="bg-red-100 text-red-700 p-2 rounded">Password baru minimal 6 karakter.</div>';
        } elseif ($new_password !== $confirm_password) {
            $password_message = '<div class="bg-red-100 text-red-700 p-2 rounded">Password baru dan konfirmasi tidak cocok.</div>';
        } else {
            if (password_verify($old_password, $user['password_hash'] ?? '')) {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update = supabase_admin_request('PATCH', 'users', ['password_hash' => $new_hash], ['id' => 'eq.' . $user_id]);
                if (isset($update['id']) || (is_array($update) && empty($update))) {
                    $password_message = '<div class="bg-green-100 text-green-700 p-2 rounded">Password berhasil diubah. Silakan login kembali.</div>';
                    // Optional: logout user after password change
                    // session_destroy();
                    // echo "<script>setTimeout(function(){ window.location.href='login.php'; }, 2000);</script>";
                } else {
                    $password_message = '<div class="bg-red-100 text-red-700 p-2 rounded">Gagal mengubah password.</div>';
                }
            } else {
                $password_message = '<div class="bg-red-100 text-red-700 p-2 rounded">Password lama salah.</div>';
            }
        }
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Pilih header berdasarkan role
if ($user_role === 'admin') {
    require_once __DIR__ . '/includes/header.php';
} else {
    require_once __DIR__ . '/includes/header_user.php';
}
?>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php if ($user_role === 'admin'): ?>
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <?php else: ?>
        <?php require_once __DIR__ . '/includes/sidebar_user.php'; ?>
    <?php endif; ?>
    
    <div class="main-content-container flex-1 transition-all duration-300 ease-in-out">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <?php if ($user_role !== 'admin'): ?>
                <button id="openSidebarUserBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden" onclick="document.getElementById('sidebarUser').classList.remove('-translate-x-full'); document.getElementById('sidebarUser').classList.add('translate-x-0'); document.getElementById('sidebarOverlayUser').classList.remove('hidden'); document.body.classList.add('sidebar-open'); return false;">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <?php else: ?>
                <button id="openMobileSidebarBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <?php endif; ?>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Profil Saya</h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                        <i class="fas fa-moon text-xl dark:hidden"></i>
                        <i class="fas fa-sun text-xl hidden dark:inline"></i>
                    </button>
                    <div class="relative group">
                        <button class="flex items-center space-x-2 focus:outline-none">
                            <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold overflow-hidden">
                                <?php 
                                $user_photo = $user['photo_url'] ?? '';
                                if ($user_photo): ?>
                                    <img src="<?= htmlspecialchars($user_photo) ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <span><?= strtoupper(substr($user['full_name'] ?? 'A', 0, 1)) ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="hidden md:inline text-gray-700 dark:text-gray-200"><?= htmlspecialchars($_SESSION['user_name'] ?? ($user_role == 'teacher' ? 'Guru' : ($user_role == 'student' ? 'Siswa' : 'User'))) ?></span>
                            <i class="fas fa-chevron-down hidden md:inline text-gray-500 dark:text-gray-400 text-xs"></i>
                        </button>
                        <div class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-md shadow-lg py-1 hidden group-hover:block z-20">
                            <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-user mr-2"></i>Profil</a>
                            <a href="logout.php" class="block px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-sign-out-alt mr-2"></i>Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        <main class="p-4 md:p-6 dark:bg-gray-900 transition-colors min-h-screen">
            <!-- Menampilkan pesan -->
            <?php if ($profile_message): ?>
                <div class="mb-4"><?= $profile_message ?></div>
            <?php endif; ?>
            <?php if ($password_message): ?>
                <div class="mb-4"><?= $password_message ?></div>
            <?php endif; ?>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 max-w-4xl mx-auto">
                <div class="flex flex-col md:flex-row gap-6">
                    <!-- Side kiri: Foto Profil -->
                    <div class="flex flex-col items-center space-y-3">
                        <div class="w-32 h-32 rounded-full overflow-hidden bg-gray-200 dark:bg-gray-700 border-4 border-blue-500">
                            <?php if ($user_photo): ?>
                                <img src="<?= htmlspecialchars($user_photo) ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center text-4xl text-gray-500">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <button onclick="openEditModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">
                            <i class="fas fa-edit mr-1"></i> Edit Profil
                        </button>
                    </div>

                    <!-- Side kanan: Informasi Profil -->
                    <div class="flex-1 space-y-3">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div><strong>Nama Lengkap:</strong> <?= htmlspecialchars($user['full_name'] ?? '-') ?></div>
                            <div><strong>Role:</strong> <?= ucfirst($user_role) ?></div>
                            <div><strong>Email:</strong> <?= htmlspecialchars($user['email'] ?? '-') ?></div>
                            <div><strong>Nomor HP:</strong> <?= htmlspecialchars($user['phone'] ?? '-') ?></div>
                            <div><strong>Tempat Lahir:</strong> <?= htmlspecialchars($user['tempat_lahir'] ?? '-') ?></div>
                            <div><strong>Tanggal Lahir:</strong> <?= htmlspecialchars($user['tanggal_lahir'] ?? '-') ?></div>
                            <div class="md:col-span-2"><strong>Alamat:</strong> <?= nl2br(htmlspecialchars($user['alamat'] ?? '-')) ?></div>
                            <?php if ($user_role == 'student'): ?>
                            <div><strong>Kelas Pagi:</strong> <?= $kelas_pagi ?></div>
                            <div><strong>Kelas Diniyyah:</strong> <?= $kelas_diniyyah ?></div>
                            <div><strong>Tahun Masuk:</strong> <?= htmlspecialchars($user['tahun_masuk'] ?? '-') ?></div>
                            <?php endif; ?>
                            <?php if ($user_role == 'teacher'): ?>
                            <div><strong>NIDN:</strong> <?= htmlspecialchars($user['nidn_or_nisn'] ?? '-') ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Bagian Ganti Password -->
                <div class="mt-8 pt-6 border-t border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Ganti Password</h3>
                    <form method="POST" class="space-y-3 max-w-md">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="change_password" value="1">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Password Lama</label>
                            <input type="password" name="old_password" required class="mt-1 w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Password Baru (min 6 karakter)</label>
                            <input type="password" name="new_password" required class="mt-1 w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Konfirmasi Password Baru</label>
                            <input type="password" name="confirm_password" required class="mt-1 w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:text-white">
                        </div>
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">Update Password</button>
                    </form>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Modal Edit Profil -->
<div id="editProfileModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl max-h-screen overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-800 dark:text-white">Edit Profil</h3>
            <button onclick="closeEditModal()" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="update_profile" value="1">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Foto Profil</label>
                    <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp" class="mt-1 w-full">
                    <p class="text-xs text-gray-500">Kosongkan jika tidak ingin mengganti foto.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nama Lengkap</label>
                    <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nomor HP</label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tempat Lahir</label>
                    <input type="text" name="tempat_lahir" value="<?= htmlspecialchars($user['tempat_lahir'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tanggal Lahir</label>
                    <input type="date" name="tanggal_lahir" value="<?= htmlspecialchars($user['tanggal_lahir'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Alamat Lengkap</label>
                    <textarea name="alamat" rows="3" class="mt-1 w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white"><?= htmlspecialchars($user['alamat'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="flex justify-end space-x-2 mt-4">
                <button type="button" onclick="closeEditModal()" class="bg-gray-400 text-white px-4 py-2 rounded">Batal</button>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
// Dark mode toggle (sama seperti sebelumnya)
const darkModeToggle = document.getElementById('darkModeToggle');
function setDarkMode(isDark) {
    if (isDark) document.documentElement.classList.add('dark');
    else document.documentElement.classList.remove('dark');
    localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled');
    if (darkModeToggle) {
        const moon = darkModeToggle.querySelector('.fa-moon');
        const sun = darkModeToggle.querySelector('.fa-sun');
        if (moon && sun) {
            moon.classList.toggle('hidden', isDark);
            sun.classList.toggle('hidden', !isDark);
        }
    }
}
const saved = localStorage.getItem('darkMode');
if (saved === 'enabled') setDarkMode(true);
else if (saved === 'disabled') setDarkMode(false);
else setDarkMode(window.matchMedia('(prefers-color-scheme: dark)').matches);
if (darkModeToggle) {
    darkModeToggle.addEventListener('click', () => setDarkMode(!document.documentElement.classList.contains('dark')));
}
// Modal edit profil
const editModal = document.getElementById('editProfileModal');
function openEditModal() {
    editModal.classList.remove('hidden');
    editModal.classList.add('flex');
}
function closeEditModal() {
    editModal.classList.add('hidden');
    editModal.classList.remove('flex');
}
// Sidebar close handlers
const closeSidebarBtn = document.getElementById('closeSidebarUser');
if (closeSidebarBtn) {
    closeSidebarBtn.addEventListener('click', function() {
        document.getElementById('sidebarUser').classList.add('-translate-x-full');
        document.getElementById('sidebarUser').classList.remove('translate-x-0');
        document.getElementById('sidebarOverlayUser').classList.add('hidden');
        document.body.classList.remove('sidebar-open');
    });
}
const overlayUser = document.getElementById('sidebarOverlayUser');
if (overlayUser) {
    overlayUser.addEventListener('click', function() {
        document.getElementById('sidebarUser').classList.add('-translate-x-full');
        document.getElementById('sidebarUser').classList.remove('translate-x-0');
        overlayUser.classList.add('hidden');
        document.body.classList.remove('sidebar-open');
    });
}
</script>

<?php
if ($user_role === 'admin') {
    require_once __DIR__ . '/includes/footer.php';
} else {
    require_once __DIR__ . '/includes/footer_user.php';
}
?>