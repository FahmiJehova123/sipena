<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'user') {
    header('Location: login.php');
    exit;
}
if (time() - $_SESSION['login_time'] > 28800) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['login_time'] = time();

$page_title = 'Dashboard User - SIPENA';
$current_page = 'dashboard';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success = '';

// Ambil data user
$user_data = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $user_id]);
$user = (is_array($user_data) && !empty($user_data)) ? $user_data[0] : null;
if (!$user) {
    header('Location: logout.php');
    exit;
}

// Proses update profil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Token keamanan tidak valid. Silakan refresh halaman.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_profile') {
            $full_name = trim($_POST['full_name'] ?? '');
            $tempat_lahir = trim($_POST['tempat_lahir'] ?? '');
            $tanggal_lahir = trim($_POST['tanggal_lahir'] ?? '');
            $alamat = trim($_POST['alamat'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            
            $update_data = [];
            if (!empty($full_name)) $update_data['full_name'] = $full_name;
            if (!empty($tempat_lahir)) $update_data['tempat_lahir'] = $tempat_lahir;
            if (!empty($tanggal_lahir)) $update_data['tanggal_lahir'] = $tanggal_lahir;
            if (!empty($alamat)) $update_data['alamat'] = $alamat;
            if (!empty($phone)) $update_data['phone'] = $phone;
            if (!empty($email)) $update_data['email'] = $email;
            
            // Upload foto baru jika ada
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['jpg', 'jpeg', 'png'];
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed)) {
                    $errors[] = 'Format foto harus JPG, JPEG atau PNG.';
                } else {
                    $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9]/', '', $user['nik'] ?? $user_id) . '.' . $ext;
                    $target_dir = __DIR__ . '/assets/img/foto_user/';
                    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
                    $target_file = $target_dir . $filename;
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
                        $domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
                        $update_data['photo_url'] = $domain . '/assets/img/foto_user/' . $filename;
                        // Hapus foto lama jika ada
                        if (!empty($user['photo_url'])) {
                            $old_file = __DIR__ . str_replace($domain, '', $user['photo_url']);
                            if (file_exists($old_file)) unlink($old_file);
                        }
                    } else {
                        $errors[] = 'Gagal mengupload foto.';
                    }
                }
            }
            
            if (!empty($update_data)) {
                $update_data['updated_at'] = date('Y-m-d H:i:s');
                $result = supabase_admin_request('PATCH', 'users', $update_data, ['id' => 'eq.' . $user_id]);
                if ($result && is_array($result) && !isset($result['error'])) {
                    $success = 'Data profil berhasil diperbarui.';
                    // Refresh data user
                    $user_data = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $user_id]);
                    $user = (is_array($user_data) && !empty($user_data)) ? $user_data[0] : $user;
                    // Update session name jika berubah
                    $_SESSION['user_name'] = $user['full_name'];
                    $_SESSION['user_photo'] = $user['photo_url'] ?? null;
                } else {
                    $errors[] = 'Gagal menyimpan perubahan. ' . ($result['error'] ?? '');
                }
            } else {
                $errors[] = 'Tidak ada data yang diubah.';
            }
        } 
        elseif ($action === 'change_password') {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $errors[] = 'Semua field password harus diisi.';
            } elseif ($new_password !== $confirm_password) {
                $errors[] = 'Password baru dan konfirmasi tidak cocok.';
            } elseif (strlen($new_password) < 6) {
                $errors[] = 'Password baru minimal 6 karakter.';
            } else {
                // Verifikasi password lama
                if (password_verify($current_password, $user['password_hash'] ?? '')) {
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $result = supabase_admin_request('PATCH', 'users', ['password_hash' => $new_hash, 'updated_at' => date('Y-m-d H:i:s')], ['id' => 'eq.' . $user_id]);
                    if ($result && is_array($result) && !isset($result['error'])) {
                        $success = 'Password berhasil diubah. Silakan login kembali.';
                        session_destroy();
                        echo "<script>setTimeout(function(){ window.location.href='login.php'; }, 2000);</script>";
                    } else {
                        $errors[] = 'Gagal mengubah password. ' . ($result['error'] ?? '');
                    }
                } else {
                    $errors[] = 'Password saat ini salah.';
                }
            }
        }
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$full_name = htmlspecialchars($user['full_name'] ?? '-');
$nisn = htmlspecialchars($user['nidn_or_nisn'] ?? '-');
$email = htmlspecialchars($user['email'] ?? '');
$phone = htmlspecialchars($user['phone'] ?? '');
$tempat_lahir = htmlspecialchars($user['tempat_lahir'] ?? '');
$tanggal_lahir = $user['tanggal_lahir'] ?? '';
$alamat = htmlspecialchars($user['alamat'] ?? '');
$photo_url = $user['photo_url'] ?? '';
$role = $user['role'] ?? 'user';
$tahun_masuk = $user['tahun_masuk'] ?? '-';

// Tampilkan pesan bahwa user masih menunggu verifikasi
$waiting_message = "Akun Anda sedang dalam proses verifikasi oleh admin. Setelah disetujui, Anda akan mendapatkan akses sebagai santri/guru. Silakan lengkapi data diri Anda di bawah ini.";
if ($role === 'student') {
    $waiting_message = "Status: Santri aktif. Selamat belajar!";
} elseif ($role === 'teacher') {
    $waiting_message = "Status: Guru aktif. Selamat mengajar!";
}

require_once __DIR__ . '/includes/header_user.php';
?>

<style>
    /* Dark mode overrides sama seperti dashboard_siswa.php */
    .dark .bg-white { background-color: #1f2937 !important; }
    .dark .text-gray-800 { color: #f3f4f6 !important; }
    .dark .text-gray-600 { color: #d1d5db !important; }
    .dark .border-gray-200 { border-color: #374151 !important; }
    .dark .bg-gray-50 { background-color: #111827 !important; }
    .dark .bg-gray-100 { background-color: #111827 !important; }
    .profile-preview {
        width: 120px;
        height: 120px;
        object-fit: cover;
        border-radius: 50%;
        border: 3px solid #8b5cf6;
        background: #e2e8f0;
    }
    @media (max-width: 640px) {
        .profile-preview { width: 80px; height: 80px; }
    }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar_user.php'; ?>
    
    <div class="main-content-container flex-1 transition-all duration-300 ease-in-out">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openSidebarUserBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden" onclick="document.getElementById('sidebarUser').classList.remove('-translate-x-full'); document.getElementById('sidebarUser').classList.add('translate-x-0'); document.getElementById('sidebarOverlayUser').classList.remove('hidden'); document.body.classList.add('sidebar-open'); return false;">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Manajemen Akun</h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                        <i class="fas fa-moon text-xl dark:hidden"></i>
                        <i class="fas fa-sun text-xl hidden dark:inline"></i>
                    </button>
                    <div class="relative group">
                        <button class="flex items-center space-x-2 focus:outline-none">
                            <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold overflow-hidden">
                                <?php if ($photo_url): ?>
                                    <img src="<?= $photo_url ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <span><?= strtoupper(substr($full_name, 0, 1)) ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="hidden md:inline text-gray-700 dark:text-gray-200"><?= $full_name ?></span>
                            <i class="fas fa-chevron-down hidden md:inline text-gray-500 dark:text-gray-400 text-xs"></i>
                        </button>
                        <div class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-md shadow-lg py-1 hidden group-hover:block z-20">
                            <a href="logout.php" class="block px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-sign-out-alt mr-2"></i>Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4 md:p-6 dark:bg-gray-900 transition-colors">
            <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded">
                    <?php foreach ($errors as $err): ?>
                        <p><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($err) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded">
                    <p><i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success) ?></p>
                </div>
            <?php endif; ?>

            <!-- Info verifikasi -->
            <div class="bg-yellow-50 dark:bg-yellow-900/20 border-l-4 border-yellow-500 p-4 mb-6 rounded">
                <div class="flex items-start gap-3">
                    <i class="fas fa-clock text-yellow-600 text-xl"></i>
                    <div>
                        <p class="font-medium text-yellow-800 dark:text-yellow-200">Status Akun: <strong><?= ucfirst($role) ?></strong></p>
                        <p class="text-yellow-700 dark:text-yellow-300 text-sm"><?= $waiting_message ?></p>
                        <?php if ($role === 'user'): ?>
                            <p class="text-xs text-yellow-600 dark:text-yellow-400 mt-1">Silakan isi data diri selengkap mungkin agar admin dapat memverifikasi akun Anda.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Kartu Profil Ringkas -->
                <div class="lg:col-span-1">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 text-left">
                        <div class="flex justify-center mb-4">
                            <img id="previewPhoto" class="profile-preview" src="<?= $photo_url ?: 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'%238b5cf6\' viewBox=\'0 0 24 24\'%3E%3Cpath d=\'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\'/%3E%3C/svg%3E' ?>" alt="Foto Profil">
                        </div>
                        <h3 class="text-xl font-bold text-gray-800 dark:text-white"><?= $full_name ?></h3>
                        <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">
                            <i class="fas fa-id-card mr-1"></i> NIS/NIDN: <?= $nisn ?>
                        </p>
                        <p class="text-gray-500 dark:text-gray-400 text-sm">
                            <i class="fas fa-calendar-alt mr-1"></i> Tahun Masuk: <?= $tahun_masuk ?>
                        </p>
                        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                <i class="fas fa-envelope mr-1"></i> <?= $email ?: 'Email belum diisi' ?>
                            </p>
                            <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">
                                <i class="fas fa-phone mr-1"></i> <?= $phone ?: 'Telepon belum diisi' ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Form Edit Data Diri -->
                <div class="lg:col-span-2">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 mb-6">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4"><i class="fas fa-user-edit mr-2 text-blue-500"></i> Edit Data Diri</h2>
                        <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Yakin ingin menyimpan perubahan?')">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nama Lengkap</label>
                                    <input type="text" name="full_name" value="<?= $full_name ?>" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                                    <input type="email" name="email" value="<?= $email ?>" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">No Telepon</label>
                                    <input type="tel" name="phone" value="<?= $phone ?>" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tempat Lahir</label>
                                    <input type="text" name="tempat_lahir" value="<?= $tempat_lahir ?>" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tanggal Lahir</label>
                                    <input type="date" name="tanggal_lahir" value="<?= $tanggal_lahir ?>" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" required>
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Alamat Lengkap</label>
                                    <textarea name="alamat" rows="2" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" required><?= $alamat ?></textarea>
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Foto Profil</label>
                                    <input type="file" name="photo" accept="image/jpeg,image/png,image/jpg" id="photoInput" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                    <p class="text-xs text-gray-500 mt-1">Kosongkan jika tidak ingin mengganti foto. Format JPG/PNG.</p>
                                </div>
                            </div>
                            <div class="mt-6">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg transition"><i class="fas fa-save mr-2"></i> Simpan Perubahan</button>
                            </div>
                        </form>
                    </div>

                    <!-- Form Ganti Password -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4"><i class="fas fa-key mr-2 text-red-500"></i> Ganti Password</h2>
                        <form method="POST" onsubmit="return confirm('Yakin ingin mengganti password? Anda akan logout dan harus login kembali.')">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="change_password">
                            <div class="grid grid-cols-1 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Password Saat Ini</label>
                                    <input type="password" name="current_password" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Password Baru (min 6 karakter)</label>
                                    <input type="password" name="new_password" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Konfirmasi Password Baru</label>
                                    <input type="password" name="confirm_password" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" required>
                                </div>
                            </div>
                            <div class="mt-6">
                                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-5 py-2 rounded-lg transition"><i class="fas fa-exchange-alt mr-2"></i> Ganti Password</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
// Dark mode toggle (sama seperti dashboard_siswa)
const darkModeToggle = document.getElementById('darkModeToggle');
function setDarkMode(isDark) {
    if (isDark) {
        document.documentElement.classList.add('dark');
        localStorage.setItem('darkMode', 'enabled');
    } else {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('darkMode', 'disabled');
    }
    if (darkModeToggle) {
        const moonIcon = darkModeToggle.querySelector('.fa-moon');
        const sunIcon = darkModeToggle.querySelector('.fa-sun');
        if (moonIcon && sunIcon) {
            if (isDark) { moonIcon.classList.add('hidden'); sunIcon.classList.remove('hidden'); }
            else { moonIcon.classList.remove('hidden'); sunIcon.classList.add('hidden'); }
        }
    }
}
const savedMode = localStorage.getItem('darkMode');
if (savedMode === 'enabled') setDarkMode(true);
else if (savedMode === 'disabled') setDarkMode(false);
else setDarkMode(window.matchMedia('(prefers-color-scheme: dark)').matches);
if (darkModeToggle) darkModeToggle.addEventListener('click', () => setDarkMode(!document.documentElement.classList.contains('dark')));

// Preview foto sebelum upload
const photoInput = document.getElementById('photoInput');
const previewPhoto = document.getElementById('previewPhoto');
if (photoInput) {
    photoInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(ev) {
                previewPhoto.src = ev.target.result;
            };
            reader.readAsDataURL(file);
        } else {
            previewPhoto.src = "<?= $photo_url ?: 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'%238b5cf6\' viewBox=\'0 0 24 24\'%3E%3Cpath d=\'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\'/%3E%3C/svg%3E' ?>";
        }
    });
}

// Sidebar handlers (sama seperti sebelumnya)
document.getElementById('closeSidebarUser')?.addEventListener('click', function() {
    document.getElementById('sidebarUser').classList.add('-translate-x-full');
    document.getElementById('sidebarUser').classList.remove('translate-x-0');
    document.getElementById('sidebarOverlayUser').classList.add('hidden');
    document.body.classList.remove('sidebar-open');
});
document.getElementById('sidebarOverlayUser')?.addEventListener('click', function() {
    document.getElementById('sidebarUser').classList.add('-translate-x-full');
    document.getElementById('sidebarUser').classList.remove('translate-x-0');
    document.getElementById('sidebarOverlayUser').classList.add('hidden');
    document.body.classList.remove('sidebar-open');
});
</script>

<?php require_once __DIR__ . '/includes/footer_user.php'; ?>