<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    header('Location: login.php');
    exit;
}
if (time() - $_SESSION['login_time'] > 28800) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['login_time'] = time();

$page_title = 'Manajemen Rombel - SIAKAD Admin';
$current_page = 'rombel';
require_once __DIR__ . '/config.php';

function safeArray($data) {
    return is_array($data) ? $data : [];
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'bulk_pindah_pagi') {
        $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
        $siswa_ids = isset($_POST['siswa_ids']) ? $_POST['siswa_ids'] : [];
        if ($class_id && is_array($siswa_ids) && count($siswa_ids) > 0) {
            $success = 0;
            foreach ($siswa_ids as $sid) {
                $data = ['kelas_pagi_id' => $class_id];
                $result = supabase_admin_request('PATCH', 'users', $data, ['id' => 'eq.' . $sid]);
                if (isset($result['id']) || (is_array($result) && empty($result))) $success++;
            }
            $message = "$success siswa berhasil dipindahkan ke kelas pagi.";
        } else {
            $message = "Pilih kelas dan minimal satu siswa.";
        }
    }
    elseif ($action === 'bulk_pindah_diniyyah') {
        $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
        $siswa_ids = isset($_POST['siswa_ids']) ? $_POST['siswa_ids'] : [];
        if ($class_id && is_array($siswa_ids) && count($siswa_ids) > 0) {
            $success = 0;
            foreach ($siswa_ids as $sid) {
                $data = ['kelas_diniyyah_id' => $class_id];
                $result = supabase_admin_request('PATCH', 'users', $data, ['id' => 'eq.' . $sid]);
                if (isset($result['id']) || (is_array($result) && empty($result))) $success++;
            }
            $message = "$success siswa berhasil dipindahkan ke kelas diniyyah.";
        } else {
            $message = "Pilih kelas dan minimal satu siswa.";
        }
    }
    elseif ($action === 'bulk_keluarkan_pagi') {
        $siswa_ids = isset($_POST['siswa_ids']) ? $_POST['siswa_ids'] : [];
        if (is_array($siswa_ids) && count($siswa_ids) > 0) {
            $success = 0;
            foreach ($siswa_ids as $sid) {
                $data = ['kelas_pagi_id' => null];
                $result = supabase_admin_request('PATCH', 'users', $data, ['id' => 'eq.' . $sid]);
                if (isset($result['id']) || (is_array($result) && empty($result))) $success++;
            }
            $message = "$success siswa berhasil dikeluarkan dari kelas pagi.";
        } else {
            $message = "Tidak ada siswa yang dipilih.";
        }
    }
    elseif ($action === 'bulk_keluarkan_diniyyah') {
        $siswa_ids = isset($_POST['siswa_ids']) ? $_POST['siswa_ids'] : [];
        if (is_array($siswa_ids) && count($siswa_ids) > 0) {
            $success = 0;
            foreach ($siswa_ids as $sid) {
                $data = ['kelas_diniyyah_id' => null];
                $result = supabase_admin_request('PATCH', 'users', $data, ['id' => 'eq.' . $sid]);
                if (isset($result['id']) || (is_array($result) && empty($result))) $success++;
            }
            $message = "$success siswa berhasil dikeluarkan dari kelas diniyyah.";
        } else {
            $message = "Tidak ada siswa yang dipilih.";
        }
    }
    elseif ($action === 'hapus_kelas') {
        $class_id = (int)$_POST['class_id'];
        supabase_admin_request('DELETE', 'classes', null, ['id' => 'eq.' . $class_id]);
        $message = 'Kelas berhasil dihapus';
    }
    elseif ($action === 'bulk_assign_pagi') {
        $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
        $siswa_ids = isset($_POST['siswa_ids']) ? $_POST['siswa_ids'] : [];
        if ($class_id && is_array($siswa_ids) && count($siswa_ids) > 0) {
            $success = 0;
            foreach ($siswa_ids as $sid) {
                $data = ['kelas_pagi_id' => $class_id];
                $result = supabase_admin_request('PATCH', 'users', $data, ['id' => 'eq.' . $sid]);
                if (isset($result['id']) || (is_array($result) && empty($result))) $success++;
            }
            $message = "$success siswa berhasil ditambahkan ke kelas pagi.";
        } else {
            $message = "Pilih kelas dan minimal satu siswa.";
        }
    }
    elseif ($action === 'bulk_assign_diniyyah') {
        $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
        $siswa_ids = isset($_POST['siswa_ids']) ? $_POST['siswa_ids'] : [];
        if ($class_id && is_array($siswa_ids) && count($siswa_ids) > 0) {
            $success = 0;
            foreach ($siswa_ids as $sid) {
                $data = ['kelas_diniyyah_id' => $class_id];
                $result = supabase_admin_request('PATCH', 'users', $data, ['id' => 'eq.' . $sid]);
                if (isset($result['id']) || (is_array($result) && empty($result))) $success++;
            }
            $message = "$success siswa berhasil ditambahkan ke kelas diniyyah.";
        } else {
            $message = "Pilih kelas dan minimal satu siswa.";
        }
    }
}

// ===== PERUBAHAN UTAMA: tambahkan parameter order =====
// Ambil semua kelas, urutkan berdasarkan class_type, sort_order, grade_level, class_name
$classes_raw = supabase_admin_request('GET', 'classes', null, [
    'order' => 'class_type.asc, sort_order.asc, grade_level.asc, class_name.asc'
]);
$classes = safeArray($classes_raw);
$classes_pagi = array_filter($classes, fn($c) => ($c['class_type'] ?? 'pagi') == 'pagi');
$classes_diniyyah = array_filter($classes, fn($c) => ($c['class_type'] ?? 'pagi') == 'diniyyah');

// Ambil semua siswa
$students_raw = supabase_admin_request('GET', 'users', null, ['role' => 'eq.student']);
$students = safeArray($students_raw);

// Kelompokkan siswa berdasarkan kelas pagi
$students_by_class_pagi = [];
$students_without_class_pagi = [];
foreach ($students as $student) {
    if (!empty($student['kelas_pagi_id'])) {
        $students_by_class_pagi[$student['kelas_pagi_id']][] = $student;
    } else {
        $students_without_class_pagi[] = $student;
    }
}
// Kelompokkan siswa berdasarkan kelas diniyyah
$students_by_class_diniyyah = [];
$students_without_class_diniyyah = [];
foreach ($students as $student) {
    if (!empty($student['kelas_diniyyah_id'])) {
        $students_by_class_diniyyah[$student['kelas_diniyyah_id']][] = $student;
    } else {
        $students_without_class_diniyyah[] = $student;
    }
}

require_once __DIR__ . '/includes/nav_items.php';
foreach ($nav_items as &$item) {
    $item['active'] = ($item['link'] == 'manage_rombel.php');
}
unset($item);

require_once __DIR__ . '/includes/header.php';
?>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main-content-container flex-1 transition-all duration-300 ease-in-out">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openMobileSidebarBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Menejemen Rombel</h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <a href="kiosk_scanner.php" class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg text-sm flex items-center gap-2">
                        <i class="fas fa-qrcode"></i> <span class="hidden sm:inline">Absensi QR/NFC</span>
                    </a>
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                        <i class="fas fa-moon text-xl dark:hidden"></i>
                        <i class="fas fa-sun text-xl hidden dark:inline"></i>
                    </button>
                    <div class="relative group">
                        <button class="flex items-center space-x-2 focus:outline-none">
                            <?php
                            $user_photo = $_SESSION['user_photo'] ?? null;
                            $user_name = $_SESSION['user_name'] ?? 'Admin';
                            $initial = strtoupper(substr($user_name, 0, 1));
                            ?>
                            <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold overflow-hidden">
                                <?php if ($user_photo): ?>
                                    <img src="<?= htmlspecialchars($user_photo) ?>" class="w-full h-full object-cover" alt="Foto Profil">
                                <?php else: ?>
                                    <span><?= $initial ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="hidden md:inline text-gray-700 dark:text-gray-200"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></span>
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

        <main class="p-4 md:p-6 dark:bg-gray-900 transition-colors">
            <?php if ($message): ?>
                <div class="bg-green-100 dark:bg-green-800 text-green-700 dark:text-green-200 p-3 rounded mb-4"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <!-- Tab navigasi -->
            <div class="mb-4 border-b border-gray-200 dark:border-gray-700">
                <ul class="flex flex-wrap -mb-px text-sm font-medium text-center">
                    <li class="mr-2"><button class="tab-btn inline-block p-4 border-b-2 rounded-t-lg active" data-tab="pagi">Kelas Pagi</button></li>
                    <li class="mr-2"><button class="tab-btn inline-block p-4 border-b-2 rounded-t-lg" data-tab="diniyyah">Kelas Diniyyah</button></li>
                </ul>
            </div>

            <!-- Tab Kelas Pagi -->
            <div id="tab-pagi" class="tab-content">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 mb-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Daftar Kelas Pagi</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr><th class="px-4 py-3 text-left text-xs font-medium uppercase">Nama Kelas</th><th class="px-4 py-3 text-left text-xs font-medium uppercase">Tingkat</th><th class="px-4 py-3 text-left text-xs font-medium uppercase">Jumlah Siswa</th><th class="px-4 py-3 text-left text-xs font-medium uppercase">Aksi</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes_pagi as $class): $count = count($students_by_class_pagi[$class['id']] ?? []); ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="px-4 py-2"><?= htmlspecialchars($class['class_name']) ?></td>
                                    <td class="px-4 py-2"><?= $class['grade_level'] ?></td>
                                    <td class="px-4 py-2"><?= $count ?> siswa</td>
                                    <td class="px-4 py-2 space-x-2">
                                        <button onclick="showClassDetail('pagi', <?= $class['id'] ?>, '<?= htmlspecialchars($class['class_name']) ?>')" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-sm">Lihat Siswa</button>
                                        <button onclick="confirmDeleteClass(<?= $class['id'] ?>)" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-sm">Hapus Kelas</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Siswa Belum Memiliki Kelas Pagi</h2>
                    <form method="POST" id="formBulkPagi" class="mb-4">
                        <input type="hidden" name="action" value="bulk_assign_pagi">
                        <div class="flex flex-wrap gap-4 items-end">
                            <div><label class="block text-sm">Pilih Kelas Pagi</label><select name="class_id" id="bulkClassPagi" class="border rounded px-3 py-2 dark:bg-gray-700" required><?php foreach ($classes_pagi as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option><?php endforeach; ?></select></div>
                            <div><label class="block text-sm">Cari Siswa</label><input type="text" id="searchPagi" placeholder="Nama atau NISN..." class="border rounded px-3 py-2 dark:bg-gray-700 w-64"></div>
                            <div><button type="button" id="selectAllPagi" class="bg-gray-500 text-white px-3 py-2 rounded">Pilih Semua</button><button type="button" id="deselectAllPagi" class="bg-gray-500 text-white px-3 py-2 rounded ml-2">Batal Pilih</button></div>
                        </div>
                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700"><tr><th class="px-4 py-2"><input type="checkbox" id="checkAllPagi"></th><th class="px-4 py-2 text-left">NISN</th><th class="px-4 py-2 text-left">Nama</th></tr></thead>
                                <tbody id="tableBodyPagi">
                                    <?php foreach ($students_without_class_pagi as $s): ?>
                                    <tr class="student-row-pagi" data-name="<?= strtolower(htmlspecialchars($s['full_name'])) ?>" data-nisn="<?= strtolower(htmlspecialchars($s['nidn_or_nisn'] ?? '')) ?>">
                                        <td class="px-4 py-2"><input type="checkbox" name="siswa_ids[]" value="<?= $s['id'] ?>" class="siswa-checkbox-pagi"></td>
                                        <td class="px-4 py-2"><?= htmlspecialchars($s['nidn_or_nisn'] ?? '-') ?></td>
                                        <td class="px-4 py-2"><?= htmlspecialchars($s['full_name']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-4"><button type="submit" class="bg-green-600 text-white px-4 py-2 rounded">Tambahkan ke Kelas</button></div>
                    </form>
                    <?php if (empty($students_without_class_pagi)) echo '<div class="text-center py-4 text-gray-500">Semua siswa sudah memiliki kelas pagi</div>'; ?>
                </div>
            </div>

            <!-- Tab Kelas Diniyyah (mirip) -->
            <div id="tab-diniyyah" class="tab-content hidden">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 mb-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Daftar Kelas Diniyyah</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr><th class="px-4 py-3 text-left text-xs font-medium uppercase">Nama Kelas</th><th class="px-4 py-3 text-left text-xs font-medium uppercase">Tingkat</th><th class="px-4 py-3 text-left text-xs font-medium uppercase">Jumlah Siswa</th><th class="px-4 py-3 text-left text-xs font-medium uppercase">Aksi</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes_diniyyah as $class): $count = count($students_by_class_diniyyah[$class['id']] ?? []); ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="px-4 py-2"><?= htmlspecialchars($class['class_name']) ?></td>
                                    <td class="px-4 py-2"><?= $class['grade_level'] ?></td>
                                    <td class="px-4 py-2"><?= $count ?> siswa</td>
                                    <td class="px-4 py-2 space-x-2">
                                        <button onclick="showClassDetail('diniyyah', <?= $class['id'] ?>, '<?= htmlspecialchars($class['class_name']) ?>')" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-sm">Lihat Siswa</button>
                                        <button onclick="confirmDeleteClass(<?= $class['id'] ?>)" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-sm">Hapus Kelas</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Siswa Belum Memiliki Kelas Diniyyah</h2>
                    <form method="POST" id="formBulkDiniyyah" class="mb-4">
                        <input type="hidden" name="action" value="bulk_assign_diniyyah">
                        <div class="flex flex-wrap gap-4 items-end">
                            <div><label class="block text-sm">Pilih Kelas Diniyyah</label><select name="class_id" id="bulkClassDiniyyah" class="border rounded px-3 py-2 dark:bg-gray-700" required><?php foreach ($classes_diniyyah as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option><?php endforeach; ?></select></div>
                            <div><label class="block text-sm">Cari Siswa</label><input type="text" id="searchDiniyyah" placeholder="Nama atau NISN..." class="border rounded px-3 py-2 dark:bg-gray-700 w-64"></div>
                            <div><button type="button" id="selectAllDiniyyah" class="bg-gray-500 text-white px-3 py-2 rounded">Pilih Semua</button><button type="button" id="deselectAllDiniyyah" class="bg-gray-500 text-white px-3 py-2 rounded ml-2">Batal Pilih</button></div>
                        </div>
                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700"><tr><th class="px-4 py-2"><input type="checkbox" id="checkAllDiniyyah"></th><th class="px-4 py-2 text-left">NISN</th><th class="px-4 py-2 text-left">Nama</th></tr></thead>
                                <tbody id="tableBodyDiniyyah">
                                    <?php foreach ($students_without_class_diniyyah as $s): ?>
                                    <tr class="student-row-diniyyah" data-name="<?= strtolower(htmlspecialchars($s['full_name'])) ?>" data-nisn="<?= strtolower(htmlspecialchars($s['nidn_or_nisn'] ?? '')) ?>">
                                        <td class="px-4 py-2"><input type="checkbox" name="siswa_ids[]" value="<?= $s['id'] ?>" class="siswa-checkbox-diniyyah"></td>
                                        <td class="px-4 py-2"><?= htmlspecialchars($s['nidn_or_nisn'] ?? '-') ?></td>
                                        <td class="px-4 py-2"><?= htmlspecialchars($s['full_name']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-4"><button type="submit" class="bg-green-600 text-white px-4 py-2 rounded">Tambahkan ke Kelas</button></div>
                    </form>
                    <?php if (empty($students_without_class_diniyyah)) echo '<div class="text-center py-4 text-gray-500">Semua siswa sudah memiliki kelas diniyyah</div>'; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Modal Detail Siswa -->
<div id="modalSiswa" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-3xl max-h-screen overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold" id="modalTitle">Siswa Kelas</h3>
            <button onclick="closeModalSiswa()" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
        </div>
        <div id="modalContent" class="overflow-x-auto"></div>
        <div class="flex flex-wrap justify-end gap-2 mt-4">
            <select id="modalTargetClass" class="border rounded px-3 py-2 dark:bg-gray-700"><option value="">-- Pilih Kelas Tujuan --</option></select>
            <button id="modalMoveBtn" class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded">Pindahkan yang Dipilih</button>
            <button id="modalRemoveBtn" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">Keluarkan yang Dipilih</button>
            <button onclick="closeModalSiswa()" class="bg-gray-400 hover:bg-gray-500 text-white px-4 py-2 rounded">Tutup</button>
        </div>
    </div>
</div>

<script>
// ========== DARK MODE ==========
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
darkModeToggle?.addEventListener('click', () => setDarkMode(!document.documentElement.classList.contains('dark')));


// ========== GLOBAL FUNCTIONS ==========
var studentsByClassPagi = <?= json_encode($students_by_class_pagi) ?>;
var studentsByClassDiniyyah = <?= json_encode($students_by_class_diniyyah) ?>;
var classesPagi = <?= json_encode(array_values($classes_pagi)) ?>;
var classesDiniyyah = <?= json_encode(array_values($classes_diniyyah)) ?>;

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function getSelectedSiswaIds() {
    var checkboxes = document.querySelectorAll('.siswa-checkbox-modal:checked');
    return Array.from(checkboxes).map(function(cb) { return cb.value; });
}

function closeModalSiswa() {
    document.getElementById('modalSiswa').classList.add('hidden');
    document.getElementById('modalSiswa').classList.remove('flex');
}

function confirmDeleteClass(classId) {
    if (confirm('Yakin hapus kelas ini? Siswa akan kehilangan referensi kelas.')) {
        var formData = new URLSearchParams();
        formData.append('action', 'hapus_kelas');
        formData.append('class_id', classId);
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        }).then(function() { location.reload(); });
    }
}

function showClassDetail(type, classId, className) {
    var siswa = (type === 'pagi' ? studentsByClassPagi[classId] : studentsByClassDiniyyah[classId]) || [];
    var html = '<table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"><thead class="bg-gray-50 dark:bg-gray-700"><tr><th class="px-4 py-2"><input type="checkbox" id="selectAllModal"></th><th class="px-4 py-2 text-left">NISN</th><th class="px-4 py-2 text-left">Nama</th></tr></thead><tbody>';
    if (siswa.length === 0) {
        html += '<tr><td colspan="3" class="text-center py-4">Tidak ada siswa di kelas ini</td></tr>';
    } else {
        for (var i = 0; i < siswa.length; i++) {
            var s = siswa[i];
            html += '<tr><td class="px-4 py-2"><input type="checkbox" class="siswa-checkbox-modal" value="' + s.id + '"></td><td class="px-4 py-2">' + escapeHtml(s.nidn_or_nisn || '-') + '</td><td class="px-4 py-2">' + escapeHtml(s.full_name) + '</td></tr>';
        }
    }
    html += '</tbody></table>';
    document.getElementById('modalContent').innerHTML = html;
    document.getElementById('modalTitle').innerHTML = 'Siswa Kelas ' + escapeHtml(className) + ' (' + (type === 'pagi' ? 'Pagi' : 'Diniyyah') + ')';

    // Isi dropdown kelas tujuan
    var targetSelect = document.getElementById('modalTargetClass');
    targetSelect.innerHTML = '<option value="">-- Pilih Kelas Tujuan --</option>';
    var targetClasses = (type === 'pagi') ? classesPagi : classesDiniyyah;
    for (var j = 0; j < targetClasses.length; j++) {
        var c = targetClasses[j];
        if (c.id != classId) {
            targetSelect.innerHTML += '<option value="' + c.id + '">' + escapeHtml(c.class_name) + '</option>';
        }
    }

    // Event "Pilih Semua"
    var selectAll = document.getElementById('selectAllModal');
    if (selectAll) {
        selectAll.onclick = function(e) {
            var cbs = document.querySelectorAll('.siswa-checkbox-modal');
            for (var k = 0; k < cbs.length; k++) cbs[k].checked = e.target.checked;
        };
    }

    // Tombol Pindahkan
    var moveBtn = document.getElementById('modalMoveBtn');
    moveBtn.onclick = function() {
        var targetClassId = targetSelect.value;
        if (!targetClassId) { alert('Pilih kelas tujuan'); return; }
        var selected = getSelectedSiswaIds();
        if (selected.length === 0) { alert('Pilih minimal satu siswa'); return; }
        if (confirm('Pindahkan ' + selected.length + ' siswa ke kelas lain?')) {
            var action = (type === 'pagi') ? 'bulk_pindah_pagi' : 'bulk_pindah_diniyyah';
            var formData = new URLSearchParams();
            formData.append('action', action);
            formData.append('class_id', targetClassId);
            for (var m = 0; m < selected.length; m++) formData.append('siswa_ids[]', selected[m]);
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            }).then(function() { location.reload(); });
        }
    };

    // Tombol Keluarkan
    var removeBtn = document.getElementById('modalRemoveBtn');
    removeBtn.onclick = function() {
        var selected = getSelectedSiswaIds();
        if (selected.length === 0) { alert('Pilih minimal satu siswa'); return; }
        if (confirm('Keluarkan ' + selected.length + ' siswa dari kelas ini?')) {
            var action = (type === 'pagi') ? 'bulk_keluarkan_pagi' : 'bulk_keluarkan_diniyyah';
            var formData = new URLSearchParams();
            formData.append('action', action);
            for (var n = 0; n < selected.length; n++) formData.append('siswa_ids[]', selected[n]);
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            }).then(function() { location.reload(); });
        }
    };

    document.getElementById('modalSiswa').classList.remove('hidden');
    document.getElementById('modalSiswa').classList.add('flex');
}

// ========== DOM READY ==========
document.addEventListener('DOMContentLoaded', function() {
    // Dark mode toggle event
    var darkModeToggle = document.getElementById('darkModeToggle');
    if (darkModeToggle) {
        darkModeToggle.addEventListener('click', function() {
            var isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled');
        });
    }

    // Tab switching (sama seperti sebelumnya)
    var tabBtns = document.querySelectorAll('.tab-btn');
    var tabPagi = document.getElementById('tab-pagi');
    var tabDiniyyah = document.getElementById('tab-diniyyah');

    function switchTab(tabId) {
        if (tabPagi) tabPagi.classList.add('hidden');
        if (tabDiniyyah) tabDiniyyah.classList.add('hidden');
        if (tabId === 'pagi' && tabPagi) tabPagi.classList.remove('hidden');
        if (tabId === 'diniyyah' && tabDiniyyah) tabDiniyyah.classList.remove('hidden');
        tabBtns.forEach(function(btn) {
            btn.classList.remove('border-blue-600', 'text-blue-600');
            if (btn.getAttribute('data-tab') === tabId) {
                btn.classList.add('border-blue-600', 'text-blue-600');
            }
        });
    }

    tabBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var tabId = btn.getAttribute('data-tab');
            if (tabId) switchTab(tabId);
        });
    });
    switchTab('pagi');

    // Bulk assign helpers (pencarian, select all) - tetap sama
    var searchPagi = document.getElementById('searchPagi');
    if (searchPagi) {
        searchPagi.addEventListener('input', function() {
            var keyword = this.value.toLowerCase();
            var rows = document.querySelectorAll('#tableBodyPagi .student-row-pagi');
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                var name = row.getAttribute('data-name');
                var nisn = row.getAttribute('data-nisn');
                row.style.display = (name.includes(keyword) || nisn.includes(keyword)) ? '' : 'none';
            }
        });
    }
    var checkAllPagi = document.getElementById('checkAllPagi');
    if (checkAllPagi) {
        checkAllPagi.addEventListener('change', function(e) {
            var cbs = document.querySelectorAll('#tableBodyPagi .siswa-checkbox-pagi');
            for (var i = 0; i < cbs.length; i++) cbs[i].checked = e.target.checked;
        });
    }
    var selectAllPagi = document.getElementById('selectAllPagi');
    if (selectAllPagi) {
        selectAllPagi.addEventListener('click', function() {
            var cbs = document.querySelectorAll('#tableBodyPagi .siswa-checkbox-pagi');
            for (var i = 0; i < cbs.length; i++) cbs[i].checked = true;
            if (checkAllPagi) checkAllPagi.checked = true;
        });
    }
    var deselectAllPagi = document.getElementById('deselectAllPagi');
    if (deselectAllPagi) {
        deselectAllPagi.addEventListener('click', function() {
            var cbs = document.querySelectorAll('#tableBodyPagi .siswa-checkbox-pagi');
            for (var i = 0; i < cbs.length; i++) cbs[i].checked = false;
            if (checkAllPagi) checkAllPagi.checked = false;
        });
    }

    // Sama untuk diniyyah
    var searchDiniyyah = document.getElementById('searchDiniyyah');
    if (searchDiniyyah) {
        searchDiniyyah.addEventListener('input', function() {
            var keyword = this.value.toLowerCase();
            var rows = document.querySelectorAll('#tableBodyDiniyyah .student-row-diniyyah');
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                var name = row.getAttribute('data-name');
                var nisn = row.getAttribute('data-nisn');
                row.style.display = (name.includes(keyword) || nisn.includes(keyword)) ? '' : 'none';
            }
        });
    }
    var checkAllDiniyyah = document.getElementById('checkAllDiniyyah');
    if (checkAllDiniyyah) {
        checkAllDiniyyah.addEventListener('change', function(e) {
            var cbs = document.querySelectorAll('#tableBodyDiniyyah .siswa-checkbox-diniyyah');
            for (var i = 0; i < cbs.length; i++) cbs[i].checked = e.target.checked;
        });
    }
    var selectAllDiniyyah = document.getElementById('selectAllDiniyyah');
    if (selectAllDiniyyah) {
        selectAllDiniyyah.addEventListener('click', function() {
            var cbs = document.querySelectorAll('#tableBodyDiniyyah .siswa-checkbox-diniyyah');
            for (var i = 0; i < cbs.length; i++) cbs[i].checked = true;
            if (checkAllDiniyyah) checkAllDiniyyah.checked = true;
        });
    }
    var deselectAllDiniyyah = document.getElementById('deselectAllDiniyyah');
    if (deselectAllDiniyyah) {
        deselectAllDiniyyah.addEventListener('click', function() {
            var cbs = document.querySelectorAll('#tableBodyDiniyyah .siswa-checkbox-diniyyah');
            for (var i = 0; i < cbs.length; i++) cbs[i].checked = false;
            if (checkAllDiniyyah) checkAllDiniyyah.checked = false;
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>