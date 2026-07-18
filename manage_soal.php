<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] != 'admin' && $_SESSION['user_role'] != 'teacher')) {
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

$page_title = 'Manajemen Soal - SIAKAD';
$current_page = 'manage_soal'; // untuk navigasi sidebar

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Jika admin, load nav_items untuk sidebar admin
if ($user_role === 'admin') {
    require_once __DIR__ . '/includes/nav_items.php';
}

// ========== SERVER SIDE RENDERING DATA ==========
$dataDir = __DIR__ . '/data/';
$masterFile = $dataDir . 'master.json';
$adminSoalFile = $dataDir . 'soal.json';
$userSoalFile = $dataDir . 'user_soal.json';

// Baca master data (semester, jenis, tahun) dari file JSON
if (file_exists($masterFile)) {
    $masterData = json_decode(file_get_contents($masterFile), true);
} else {
    $masterData = [
        'semester' => ['Ganjil', 'Genap'],
        'jenis' => ['Pilihan Ganda', 'Essay', 'Uraian'],
        'tahun' => ['2023-2024', '2024-2025', '2025-2026']
    ];
}

// Ambil data kelas dari Supabase
$classes_raw = supabase_admin_request('GET', 'classes');
$classes = is_array($classes_raw) ? $classes_raw : [];
$daftarKelas = [];
foreach ($classes as $c) {
    $daftarKelas[] = $c['class_name'];
}
sort($daftarKelas);
$masterData['kelas'] = $daftarKelas;

// Ambil data mata pelajaran dari Supabase
$subjects_raw = supabase_admin_request('GET', 'subjects');
$subjects = is_array($subjects_raw) ? $subjects_raw : [];
$daftarPelajaran = [];
foreach ($subjects as $sub) {
    $daftarPelajaran[] = $sub['subject_name'];
}
sort($daftarPelajaran);
$masterData['pelajaran'] = $daftarPelajaran;

// Baca soal admin
$adminSoal = [];
if (file_exists($adminSoalFile)) {
    $adminSoal = json_decode(file_get_contents($adminSoalFile), true);
    if (!is_array($adminSoal)) $adminSoal = [];
}
// Baca soal user
$userSoal = [];
if (file_exists($userSoalFile)) {
    $userSoal = json_decode(file_get_contents($userSoalFile), true);
    if (!is_array($userSoal)) $userSoal = [];
}

// Gabungkan semua soal berdasarkan role
$semuaSoal = [];

if ($user_role === 'admin') {
    // Admin melihat semua soal
    foreach ($adminSoal as $soal) {
        $soal['source'] = 'admin';
        $soal['status'] = $soal['status'] ?? 'verified';
        $soal['user_name'] = '-';
        $semuaSoal[] = $soal;
    }
    foreach ($userSoal as $soal) {
        $soal['source'] = 'user';
        $soal['status'] = $soal['status'] ?? 'pending';
        if (!empty($soal['user_id'])) {
            $user = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $soal['user_id']]);
            $soal['user_name'] = (is_array($user) && !empty($user)) ? $user[0]['full_name'] : 'Unknown';
        } else {
            $soal['user_name'] = 'User';
        }
        $semuaSoal[] = $soal;
    }
} else {
    // Teacher hanya melihat soal miliknya sendiri
    foreach ($userSoal as $soal) {
        if ($soal['user_id'] == $user_id) {
            $soal['source'] = 'user';
            $soal['status'] = $soal['status'] ?? 'pending';
            $soal['user_name'] = $_SESSION['user_name'] ?? 'Saya';
            $semuaSoal[] = $soal;
        }
    }
}

// Urutkan berdasarkan created_at descending
usort($semuaSoal, function($a, $b) {
    return strtotime($b['created_at'] ?? '1970-01-01') - strtotime($a['created_at'] ?? '1970-01-01');
});

// Encode ke JSON untuk JavaScript
$masterDataJson = json_encode($masterData);
$semuaSoalJson = json_encode($semuaSoal);

if ($user_role === 'admin') {
    require_once __DIR__ . '/includes/header.php';
} else {
    require_once __DIR__ . '/includes/header_user.php';
}
?>

<style>
    /* (style sama seperti sebelumnya) */
    .status-badge { padding: 4px 8px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; display: inline-block; }
    .status-verified { background-color: #d4edda; color: #155724; }
    .status-pending { background-color: #fff3cd; color: #856404; }
    .status-rejected { background-color: #f8d7da; color: #721c24; }
    .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
    .btn-action { padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 6px; border: none; }
    .btn-view { background: #3498db; color: white; }
    .btn-edit { background: #f39c12; color: white; }
    .btn-delete { background: #e74c3c; color: white; }
    .btn-verify { background: #2ecc71; color: white; }
    .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; }
    .modal-content { background: white; border-radius: 12px; width: 90%; max-width: 700px; max-height: 90vh; overflow: auto; }
    .dark .modal-content { background: #1f2937; color: #e5e7eb; }
    .modal-header { display: flex; justify-content: space-between; padding: 16px; border-bottom: 1px solid #e5e7eb; }
    .modal-body { padding: 16px; }
    .modal-footer { padding: 16px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 8px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: white; }
    .dark .form-group input, .dark .form-group select, .dark .form-group textarea { background: #374151; border-color: #4b5563; color: white; }
    .btn { padding: 8px 16px; border-radius: 6px; cursor: pointer; border: none; }
    .btn-primary { background: #3498db; color: white; }
    .btn-secondary { background: #95a5a6; color: white; }
    .btn-success { background: #2ecc71; color: white; }
    .pagination-controls { display: flex; justify-content: space-between; align-items: center; margin-top: 16px; flex-wrap: wrap; gap: 12px; }
    #pagination { display: flex; gap: 6px; flex-wrap: wrap; }
    .page-btn { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; }
    select#page-size { background: none;}
    .page-btn.active { background: #3498db; color: white; border-color: #3498db; }
    .loading-spinner { border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 20px auto; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    .empty-state { text-align: center; padding: 40px; color: #6c757d; }
    .filter-section { margin-bottom: 16px; }
    .filter-section select { width: 100%; }
    .admin-only { display: <?= $user_role === 'admin' ? 'block' : 'none'; ?>; }
    .teacher-only { display: <?= $user_role === 'teacher' ? 'block' : 'none'; ?>; }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php if ($user_role === 'admin'): ?>
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <?php else: ?>
        <?php require_once __DIR__ . '/includes/sidebar_user.php'; ?>
    <?php endif; ?>
    <div class="main-content-container flex-1">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openMobileSidebarBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Manajemen Soal</h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                        <i class="fas fa-moon text-xl dark:hidden"></i>
                        <i class="fas fa-sun text-xl hidden dark:inline"></i>
                    </button>
                    <div class="relative group">
                        <button class="flex items-center space-x-2 focus:outline-none">
                            <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold overflow-hidden">
                                <?php
                                $user_photo = $_SESSION['user_photo'] ?? null;
                                $user_name = $_SESSION['user_name'] ?? 'Admin';
                                $initial = strtoupper(substr($user_name, 0, 1));
                                ?>
                                <?php if ($user_photo): ?>
                                    <img src="<?= htmlspecialchars($user_photo) ?>" class="w-full h-full object-cover" alt="Foto Profil">
                                <?php else: ?>
                                    <span><?= $initial ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="hidden md:inline text-gray-700 dark:text-gray-200"><?= htmlspecialchars($_SESSION['user_name']) ?></span>
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

        <main class="p-4 md:p-6 dark:bg-gray-900 dark:text-white">
            <div id="alert-message" class="fixed top-4 right-4 z-50 hidden p-4 rounded-lg shadow-lg"></div>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <!-- Sidebar Filter -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4">
                    <h2 class="text-lg font-semibold mb-4">Filter Soal</h2>
                    <div class="filter-section">
                        <label>Mata Pelajaran</label>
                        <select id="filter-pelajaran"><option value="">Semua</option></select>
                    </div>
                    <div class="filter-section">
                        <label>Kelas</label>
                        <select id="filter-kelas"><option value="">Semua</option></select>
                    </div>
                    <div class="filter-section">
                        <label>Semester</label>
                        <select id="filter-semester"><option value="">Semua</option></select>
                    </div>
                    <div class="filter-section">
                        <label>Tahun Pelajaran</label>
                        <select id="filter-tahun"><option value="">Semua</option></select>
                    </div>
                    <div class="filter-section">
                        <label>Jenis Soal</label>
                        <select id="filter-jenis"><option value="">Semua</option></select>
                    </div>
                    <div class="filter-section admin-only">
                        <label>Status</label>
                        <select id="filter-status">
                            <option value="">Semua</option>
                            <option value="verified">Terverifikasi</option>
                            <option value="pending">Menunggu Verifikasi</option>
                            <option value="rejected">Ditolak</option>
                        </select>
                    </div>
                    <div class="filter-section admin-only">
                        <label>Sumber</label>
                        <select id="filter-sumber">
                            <option value="">Semua</option>
                            <option value="admin">Admin</option>
                            <option value="user">User</option>
                        </select>
                    </div>
                    <button id="btn-reset-filter" class="btn btn-secondary w-full mt-4">Reset Filter</button>
                </div>

                <!-- Daftar Soal -->
                <div class="lg:col-span-3 bg-white dark:bg-gray-800 rounded-xl shadow-md p-4">
                    <div class="flex justify-between items-center mb-4 flex-wrap gap-2">
                        <h2 class="text-lg font-semibold">Daftar Soal</h2>
                        <div class="flex flex-wrap gap-2">
                            <input type="text" id="search-input" placeholder="Cari soal..." class="border rounded px-3 py-1">
                            <button id="btn-search" class="btn btn-primary">Cari</button>
                            <button id="btn-tambah-soal" class="btn btn-success">+ Tambah Soal</button>
                            <?php if ($user_role === 'admin'): ?>
                            <button id="btn-pengaturan-master" class="btn btn-primary" style="background:#9b59b6;"><i class="fas fa-cog"></i> Pengaturan</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th>No</th><th>Pelajaran</th><th>Kelas</th><th>Semester</th><th>Tahun</th><th>Jenis</th><th>Status</th>
                                    <?php if ($user_role === 'admin'): ?><th>Sumber</th><th>User</th><?php endif; ?>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="soal-table-body">
                                <tr><td colspan="10" class="text-center py-10"><div class="loading-spinner"></div></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-controls">
                        <div><label>Baris per halaman: <select id="page-size"><option>5</option><option selected>10</option><option>20</option><option>50</option></select></label></div>
                        <div id="pagination"></div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Modal Tambah/Edit Soal -->
<div id="soal-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modal-title">Tambah Soal Baru</h2>
            <span class="close-btn" id="close-modal">&times;</span>
        </div>
        <div class="modal-body">
            <form id="soal-form" enctype="multipart/form-data">
                <input type="hidden" id="edit-id" name="id">
                <input type="hidden" id="edit-source" name="source">
                <div class="form-group">
                    <label>Mata Pelajaran</label>
                    <select id="pelajaran" required><option value="">Pilih</option></select>
                </div>
                <div class="form-group">
                    <label>Kelas</label>
                    <select id="kelas" required><option value="">Pilih</option></select>
                </div>
                <div class="form-group">
                    <label>Semester</label>
                    <select id="semester" required><option value="">Pilih</option></select>
                </div>
                <div class="form-group">
                    <label>Tahun Pelajaran</label>
                    <select id="tahun-pelajaran" required><option value="">Pilih</option></select>
                </div>
                <div class="form-group">
                    <label>Jenis Soal</label>
                    <select id="jenis-soal" required><option value="">Pilih</option></select>
                </div>
                <div class="form-group">
                    <label>Deskripsi</label>
                    <textarea id="deskripsi" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label>File Soal</label>
                    <input type="file" id="file-soal" accept=".pdf,.doc,.docx,.jpg,.png">
                    <small class="text-gray-500">PDF, DOC, JPG, PNG (max 10MB). Kosongkan jika tidak ingin mengganti file saat edit.</small>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="cancel-soal">Batal</button>
            <button class="btn btn-primary" id="save-soal">Simpan</button>
        </div>
    </div>
</div>

<?php if ($user_role === 'admin'): ?>
<!-- Modal Pengaturan Master Data -->
<div id="master-modal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2>Pengaturan Master Data</h2>
            <span class="close-btn" id="close-master-modal">&times;</span>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Semester</label>
                <div id="semester-list" class="space-y-2"></div>
                <div class="flex gap-2 mt-2">
                    <input type="text" id="new-semester" placeholder="Semester baru" class="flex-1">
                    <button id="add-semester" class="btn btn-primary btn-sm">Tambah</button>
                </div>
            </div>
            <div class="form-group">
                <label>Jenis Soal</label>
                <div id="jenis-list" class="space-y-2"></div>
                <div class="flex gap-2 mt-2">
                    <input type="text" id="new-jenis" placeholder="Jenis baru" class="flex-1">
                    <button id="add-jenis" class="btn btn-primary btn-sm">Tambah</button>
                </div>
            </div>
            <div class="form-group">
                <label>Tahun Pelajaran</label>
                <div id="tahun-list" class="space-y-2"></div>
                <div class="flex gap-2 mt-2">
                    <input type="text" id="new-tahun" placeholder="Contoh: 2025-2026" class="flex-1">
                    <button id="add-tahun" class="btn btn-primary btn-sm">Tambah</button>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="cancel-master">Tutup</button>
            <button class="btn btn-primary" id="save-master">Simpan Perubahan</button>
        </div>
    </div>
</div>

<!-- Modal Verifikasi Soal -->
<div id="verifikasi-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Verifikasi Soal</h2>
            <span class="close-btn" id="close-verifikasi-modal">&times;</span>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Nama User</label>
                <input type="text" id="verif-user-name" readonly class="bg-gray-100">
            </div>
            <div class="form-group">
                <label>Status Saat Ini</label>
                <input type="text" id="verif-current-status" readonly>
            </div>
            <div class="form-group">
                <label>Ubah Status</label>
                <select id="verif-new-status">
                    <option value="verified">Terverifikasi</option>
                    <option value="rejected">Ditolak</option>
                    <option value="pending">Menunggu Verifikasi</option>
                </select>
            </div>
            <input type="hidden" id="verif-id">
            <input type="hidden" id="verif-source">
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="cancel-verifikasi">Batal</button>
            <button class="btn btn-primary" id="confirm-verifikasi">Simpan Perubahan</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Data dari server
const masterDataFromServer = <?= $masterDataJson ?>;
const semuaSoalFromServer = <?= $semuaSoalJson ?>;
const userRole = '<?= $user_role ?>';
const userId = '<?= $user_id ?>';

// ========== DROPDOWN PROFILE (Click handler) ==========
const profileGroup = document.querySelector('.relative.group');
if (profileGroup) {
    const profileButton = profileGroup.querySelector('button');
    const dropdownMenu = profileGroup.querySelector('.absolute');
    if (profileButton && dropdownMenu) {
        // Toggle dropdown saat klik tombol
        profileButton.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdownMenu.classList.toggle('hidden');
        });
        // Tutup dropdown jika klik di luar
        document.addEventListener('click', (e) => {
            if (!profileGroup.contains(e.target)) {
                dropdownMenu.classList.add('hidden');
            }
        });
    }
}
    
// ========== DARK MODE (sederhana) ==========
const darkModeToggle = document.getElementById('darkModeToggle');
if (darkModeToggle) {
    darkModeToggle.addEventListener('click', () => {
        const isDark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled');
        // Update ikon (opsional, karena sudah ditangani oleh event listener lain)
        const moon = darkModeToggle.querySelector('.fa-moon');
        const sun = darkModeToggle.querySelector('.fa-sun');
        if (moon && sun) {
            moon.classList.toggle('hidden', isDark);
            sun.classList.toggle('hidden', !isDark);
        }
    });
}
// Inisialisasi ikon saat halaman dimuat
const initIcons = () => {
    const isDark = document.documentElement.classList.contains('dark');
    const moon = darkModeToggle?.querySelector('.fa-moon');
    const sun = darkModeToggle?.querySelector('.fa-sun');
    if (moon && sun) {
        moon.classList.toggle('hidden', isDark);
        sun.classList.toggle('hidden', !isDark);
    }
};
initIcons();

// Global variables
let semuaSoal = [...semuaSoalFromServer];
let masterData = { ...masterDataFromServer };
let filteredSoal = [...semuaSoal];
let currentPage = 1, pageSize = 10;
let isEditMode = false;

// Fallback master data
if (!masterData.semester) masterData.semester = ['Ganjil', 'Genap'];
if (!masterData.jenis) masterData.jenis = ['Pilihan Ganda', 'Essay', 'Uraian'];
if (!masterData.tahun) masterData.tahun = ['2023-2024', '2024-2025', '2025-2026'];
if (!masterData.kelas) masterData.kelas = [];
if (!masterData.pelajaran) masterData.pelajaran = [];

function showAlert(message, type = 'success') {
    const alertDiv = document.getElementById('alert-message');
    if (!alertDiv) return;
    alertDiv.textContent = message;
    alertDiv.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg text-white ${type === 'error' ? 'bg-red-500' : 'bg-green-500'}`;
    alertDiv.style.display = 'block';
    setTimeout(() => alertDiv.style.display = 'none', 4000);
}

function updateSelectOptions(selectId, options, includeEmpty = true) {
    const select = document.getElementById(selectId);
    if (!select) return;
    const oldValue = select.value;
    select.innerHTML = '';
    if (includeEmpty) select.appendChild(new Option('Semua', ''));
    options.forEach(opt => select.appendChild(new Option(opt, opt)));
    if (oldValue && options.includes(oldValue)) select.value = oldValue;
}

function initSelects() {
    updateSelectOptions('filter-pelajaran', masterData.pelajaran, true);
    updateSelectOptions('filter-kelas', masterData.kelas, true);
    updateSelectOptions('filter-semester', masterData.semester, true);
    updateSelectOptions('filter-tahun', masterData.tahun, true);
    updateSelectOptions('filter-jenis', masterData.jenis, true);
    updateSelectOptions('pelajaran', masterData.pelajaran, false);
    updateSelectOptions('kelas', masterData.kelas, false);
    updateSelectOptions('semester', masterData.semester, false);
    updateSelectOptions('tahun-pelajaran', masterData.tahun, false);
    updateSelectOptions('jenis-soal', masterData.jenis, false);
}

function renderSoalTable() {
    const tbody = document.getElementById('soal-table-body');
    if (!tbody) return;
    const start = (currentPage - 1) * pageSize;
    const pageItems = filteredSoal.slice(start, start + pageSize);
    if (pageItems.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center py-10">Tidak ada data</td></tr>';
        renderPagination();
        return;
    }
    tbody.innerHTML = '';
    pageItems.forEach((soal, idx) => {
        const row = tbody.insertRow();
        row.insertCell().innerText = start + idx + 1;
        row.insertCell().innerText = soal.pelajaran || '-';
        row.insertCell().innerText = soal.kelas || '-';
        row.insertCell().innerText = soal.semester || '-';
        row.insertCell().innerText = soal.tahun || '-';
        row.insertCell().innerText = soal.jenis || '-';
        const statusCell = row.insertCell();
        const statusClass = soal.status === 'verified' ? 'status-verified' : (soal.status === 'pending' ? 'status-pending' : 'status-rejected');
        const statusText = soal.status === 'verified' ? 'Terverifikasi' : (soal.status === 'pending' ? 'Menunggu Verifikasi' : 'Ditolak');
        statusCell.innerHTML = `<span class="status-badge ${statusClass}">${statusText}</span>`;
        if (userRole === 'admin') {
            row.insertCell().innerText = soal.source === 'admin' ? 'Admin' : 'User';
            row.insertCell().innerText = soal.user_name || (soal.source === 'admin' ? '-' : 'User');
        }
        const actionCell = row.insertCell();
        let buttons = `
            <div class="action-buttons">
                <button class="btn-action btn-view" onclick="viewSoal(${soal.id}, '${soal.source}')"><i class="fas fa-eye"></i> Lihat</button>
                <button class="btn-action btn-edit" onclick="editSoal(${soal.id}, '${soal.source}')"><i class="fas fa-edit"></i> Edit</button>
                <button class="btn-action btn-delete" onclick="deleteSoal(${soal.id}, '${soal.source}')"><i class="fas fa-trash"></i> Hapus</button>`;
        if (userRole === 'admin' && soal.source === 'user' && soal.status !== 'verified') {
            buttons += `<button class="btn-action btn-verify" onclick="openVerifikasiModal(${soal.id}, '${soal.source}')"><i class="fas fa-check-circle"></i> Verifikasi</button>`;
        }
        buttons += `</div>`;
        actionCell.innerHTML = buttons;
    });
    renderPagination();
}

function renderPagination() {
    const totalPages = Math.ceil(filteredSoal.length / pageSize);
    const container = document.getElementById('pagination');
    if (!container) return;
    container.innerHTML = '';
    if (totalPages <= 1) return;
    for (let i = 1; i <= totalPages; i++) {
        const btn = document.createElement('button');
        btn.className = `page-btn ${i === currentPage ? 'active' : ''}`;
        btn.textContent = i;
        btn.onclick = () => { currentPage = i; renderSoalTable(); };
        container.appendChild(btn);
    }
}

function applyFilters() {
    const pelajaran = document.getElementById('filter-pelajaran').value;
    const kelas = document.getElementById('filter-kelas').value;
    const semester = document.getElementById('filter-semester').value;
    const tahun = document.getElementById('filter-tahun').value;
    const jenis = document.getElementById('filter-jenis').value;
    const status = userRole === 'admin' ? document.getElementById('filter-status')?.value : '';
    const sumber = userRole === 'admin' ? document.getElementById('filter-sumber')?.value : '';
    const search = document.getElementById('search-input').value.toLowerCase();

    filteredSoal = semuaSoal.filter(soal => {
        if (pelajaran && soal.pelajaran !== pelajaran) return false;
        if (kelas && soal.kelas !== kelas) return false;
        if (semester && soal.semester !== semester) return false;
        if (tahun && soal.tahun !== tahun) return false;
        if (jenis && soal.jenis !== jenis) return false;
        if (status && soal.status !== status) return false;
        if (sumber && soal.source !== sumber) return false;
        if (search && !`${soal.pelajaran} ${soal.kelas} ${soal.deskripsi}`.toLowerCase().includes(search)) return false;
        return true;
    });
    currentPage = 1;
    renderSoalTable();
}

// CRUD berdasarkan role
async function saveSoal() {
    const pelajaran = document.getElementById('pelajaran').value;
    const kelas = document.getElementById('kelas').value;
    const semester = document.getElementById('semester').value;
    const tahun = document.getElementById('tahun-pelajaran').value;
    const jenis = document.getElementById('jenis-soal').value;
    const deskripsi = document.getElementById('deskripsi').value;
    const fileInput = document.getElementById('file-soal');
    const id = document.getElementById('edit-id').value;
    const source = document.getElementById('edit-source').value;
    const isEdit = !!id;

    if (!pelajaran || !kelas || !semester || !tahun || !jenis) {
        showAlert('Semua field harus diisi!', 'error');
        return;
    }
    if (!fileInput.files.length && !isEdit) {
        showAlert('Harap pilih file soal', 'error');
        return;
    }
    if (fileInput.files.length) {
        const file = fileInput.files[0];
        if (file.size > 10 * 1024 * 1024) {
            showAlert('File maksimal 10MB', 'error');
            return;
        }
    }

    const formData = new FormData();
    let endpoint = '';
    let action = '';

    if (userRole === 'admin') {
        endpoint = 'soal.php';
        action = isEdit ? 'update' : 'create';
        formData.append('action', action);
        if (isEdit) {
            formData.append('id', id);
            if (source) formData.append('source', source);
        }
        formData.append('pelajaran', pelajaran);
        formData.append('kelas', kelas);
        formData.append('semester', semester);
        formData.append('tahun', tahun);
        formData.append('jenis', jenis);
        formData.append('deskripsi', deskripsi);
        if (fileInput.files.length) formData.append('file', fileInput.files[0]);
    } else {
        endpoint = 'user.php';
        action = isEdit ? 'update_user_soal' : 'create_user_soal';
        formData.append('action', action);
        if (isEdit) {
            formData.append('id', id);
        } else {
            formData.append('user_id', userId);
        }
        formData.append('pelajaran', pelajaran);
        formData.append('kelas', kelas);
        formData.append('semester', semester);
        formData.append('tahun', tahun);
        formData.append('jenis', jenis);
        formData.append('deskripsi', deskripsi);
        if (fileInput.files.length) formData.append('file', fileInput.files[0]);
    }

    console.log('Sending to:', endpoint, 'action:', action, 'isEdit:', isEdit);

    try {
        const res = await fetch(endpoint, { method: 'POST', body: formData });
        const data = await res.json();
        console.log('Response:', data);
        if (data.success) {
            showAlert(`Soal berhasil ${isEdit ? 'diupdate' : 'ditambahkan'}`);
            document.getElementById('soal-modal').style.display = 'none';
            location.reload();
        } else {
            showAlert('Gagal: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch(e) {
        showAlert('Error: ' + e.message, 'error');
    }
}

async function deleteSoal(id, source) {
    if (!confirm('Yakin hapus soal ini?')) return;
    let endpoint, action;
    if (userRole === 'admin') {
        endpoint = source === 'admin' ? 'soal.php' : 'user.php';
        action = source === 'admin' ? 'delete' : 'delete_user_soal';
    } else {
        endpoint = 'user.php';
        action = 'delete_user_soal';
    }
    const formData = new URLSearchParams();
    formData.append('action', action);
    formData.append('id', id);
    if (userRole !== 'admin') {
        formData.append('user_id', userId);
    }
    try {
        const res = await fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData });
        const data = await res.json();
        if (data.success) {
            showAlert('Soal dihapus');
            location.reload();
        } else {
            showAlert('Gagal hapus', 'error');
        }
    } catch(e) { showAlert('Error', 'error'); }
}

function editSoal(id, source) {
    const soal = semuaSoal.find(s => s.id == id && s.source === source);
    if (!soal) return;
    isEditMode = true;
    document.getElementById('modal-title').innerText = 'Edit Soal';
    document.getElementById('edit-id').value = soal.id;
    document.getElementById('edit-source').value = soal.source;
    initSelects();
    setTimeout(() => {
        document.getElementById('pelajaran').value = soal.pelajaran || '';
        document.getElementById('kelas').value = soal.kelas || '';
        document.getElementById('semester').value = soal.semester || '';
        document.getElementById('tahun-pelajaran').value = soal.tahun || '';
        document.getElementById('jenis-soal').value = soal.jenis || '';
        document.getElementById('deskripsi').value = soal.deskripsi || '';
    }, 50);
    document.getElementById('file-soal').required = false;
    document.getElementById('soal-modal').style.display = 'flex';
}

function openTambahSoalModal() {
    isEditMode = false;
    document.getElementById('modal-title').innerText = 'Tambah Soal Baru';
    document.getElementById('soal-form').reset();
    document.getElementById('edit-id').value = '';
    document.getElementById('edit-source').value = '';
    document.getElementById('file-soal').required = true;
    initSelects();
    document.getElementById('soal-modal').style.display = 'flex';
}

function viewSoal(id, source) {
    const soal = semuaSoal.find(s => s.id == id && s.source === source);
    if (!soal) return;
    const filePath = source === 'admin' ? `uploads/${soal.file}` : `uploads/user_soal/${soal.file}`;
    window.open(filePath, '_blank');
}

// Admin-only functions
<?php if ($user_role === 'admin'): ?>
let currentVerifId = null, currentVerifSource = null;
function openVerifikasiModal(id, source) {
    const soal = semuaSoal.find(s => s.id == id && s.source === source);
    if (!soal) return;
    currentVerifId = id;
    currentVerifSource = source;
    document.getElementById('verif-user-name').value = soal.user_name || 'User';
    document.getElementById('verif-current-status').value = soal.status === 'pending' ? 'Menunggu Verifikasi' : (soal.status === 'verified' ? 'Terverifikasi' : 'Ditolak');
    document.getElementById('verif-new-status').value = soal.status;
    document.getElementById('verif-id').value = id;
    document.getElementById('verif-source').value = source;
    document.getElementById('verifikasi-modal').style.display = 'flex';
}

async function confirmVerifikasi() {
    const newStatus = document.getElementById('verif-new-status').value;
    const id = currentVerifId;
    if (!id) return;
    try {
        const formData = new URLSearchParams();
        formData.append('action', 'update_status');
        formData.append('id', id);
        formData.append('status', newStatus);
        const res = await fetch('user.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData });
        const data = await res.json();
        if (data.success) {
            showAlert('Status soal berhasil diperbarui');
            document.getElementById('verifikasi-modal').style.display = 'none';
            location.reload();
        } else {
            showAlert('Gagal update status: ' + data.message, 'error');
        }
    } catch(e) {
        showAlert('Error: ' + e.message, 'error');
    }
}

// Master data management
function renderMasterLists() {
    const semesterDiv = document.getElementById('semester-list');
    const jenisDiv = document.getElementById('jenis-list');
    const tahunDiv = document.getElementById('tahun-list');
    if (!semesterDiv) return;
    
    semesterDiv.innerHTML = '';
    masterData.semester.forEach((item, idx) => {
        semesterDiv.innerHTML += `<div class="flex justify-between items-center p-2 border-b"><span>${escapeHtml(item)}</span><button onclick="deleteMasterItem('semester', ${idx})" class="text-red-500"><i class="fas fa-trash"></i></button></div>`;
    });
    jenisDiv.innerHTML = '';
    masterData.jenis.forEach((item, idx) => {
        jenisDiv.innerHTML += `<div class="flex justify-between items-center p-2 border-b"><span>${escapeHtml(item)}</span><button onclick="deleteMasterItem('jenis', ${idx})" class="text-red-500"><i class="fas fa-trash"></i></button></div>`;
    });
    tahunDiv.innerHTML = '';
    masterData.tahun.forEach((item, idx) => {
        tahunDiv.innerHTML += `<div class="flex justify-between items-center p-2 border-b"><span>${escapeHtml(item)}</span><button onclick="deleteMasterItem('tahun', ${idx})" class="text-red-500"><i class="fas fa-trash"></i></button></div>`;
    });
}

function deleteMasterItem(type, index) {
    masterData[type].splice(index, 1);
    renderMasterLists();
}

function addMasterItem(type) {
    const inputId = `new-${type}`;
    const input = document.getElementById(inputId);
    const value = input.value.trim();
    if (!value) { showAlert('Isi data terlebih dahulu', 'error'); return; }
    if (masterData[type].includes(value)) { showAlert('Data sudah ada', 'error'); return; }
    masterData[type].push(value);
    renderMasterLists();
    input.value = '';
}

async function saveMasterData() {
    try {
        const res = await fetch('soal.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update_master', data: { semester: masterData.semester, jenis: masterData.jenis, tahun: masterData.tahun } })
        });
        const data = await res.json();
        if (data.success) {
            showAlert('Master data disimpan');
            initSelects();
            document.getElementById('master-modal').style.display = 'none';
        } else {
            showAlert('Gagal simpan', 'error');
        }
    } catch(e) { showAlert('Error: ' + e.message, 'error'); }
}

document.getElementById('btn-pengaturan-master')?.addEventListener('click', () => {
    renderMasterLists();
    document.getElementById('master-modal').style.display = 'flex';
});
document.getElementById('close-master-modal')?.addEventListener('click', () => document.getElementById('master-modal').style.display = 'none');
document.getElementById('cancel-master')?.addEventListener('click', () => document.getElementById('master-modal').style.display = 'none');
document.getElementById('save-master')?.addEventListener('click', saveMasterData);
document.getElementById('add-semester')?.addEventListener('click', () => addMasterItem('semester'));
document.getElementById('add-jenis')?.addEventListener('click', () => addMasterItem('jenis'));
document.getElementById('add-tahun')?.addEventListener('click', () => addMasterItem('tahun'));

document.getElementById('close-verifikasi-modal')?.addEventListener('click', () => document.getElementById('verifikasi-modal').style.display = 'none');
document.getElementById('cancel-verifikasi')?.addEventListener('click', () => document.getElementById('verifikasi-modal').style.display = 'none');
document.getElementById('confirm-verifikasi')?.addEventListener('click', confirmVerifikasi);
<?php endif; ?>

function resetFilter() {
    document.getElementById('filter-pelajaran').value = '';
    document.getElementById('filter-kelas').value = '';
    document.getElementById('filter-semester').value = '';
    document.getElementById('filter-tahun').value = '';
    document.getElementById('filter-jenis').value = '';
    if (userRole === 'admin') {
        document.getElementById('filter-status').value = '';
        document.getElementById('filter-sumber').value = '';
    }
    document.getElementById('search-input').value = '';
    applyFilters();
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// Event listeners
document.getElementById('btn-tambah-soal')?.addEventListener('click', openTambahSoalModal);
document.getElementById('save-soal')?.addEventListener('click', saveSoal);
document.getElementById('close-modal')?.addEventListener('click', () => document.getElementById('soal-modal').style.display = 'none');
document.getElementById('cancel-soal')?.addEventListener('click', () => document.getElementById('soal-modal').style.display = 'none');
document.getElementById('btn-search')?.addEventListener('click', applyFilters);
document.getElementById('btn-reset-filter')?.addEventListener('click', resetFilter);
document.getElementById('page-size')?.addEventListener('change', (e) => { pageSize = parseInt(e.target.value); currentPage = 1; renderSoalTable(); });
document.getElementById('search-input')?.addEventListener('keypress', (e) => { if (e.key === 'Enter') applyFilters(); });
['filter-pelajaran','filter-kelas','filter-semester','filter-tahun','filter-jenis'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', applyFilters);
});
if (userRole === 'admin') {
    ['filter-status','filter-sumber'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', applyFilters);
    });
}

// Inisialisasi
initSelects();
renderSoalTable();
    
// ========== SIDEBAR MOBILE UNTUK TEACHER ==========
if (userRole === 'teacher') {
    const openBtn = document.getElementById('openMobileSidebarBtn');
    const sidebar = document.getElementById('sidebarUser');
    const overlay = document.getElementById('sidebarOverlayUser');
    const closeBtn = document.getElementById('closeSidebarUser');
    
    if (openBtn && sidebar) {
        openBtn.addEventListener('click', function(e) {
            e.preventDefault();
            sidebar.classList.remove('-translate-x-full');
            sidebar.classList.add('translate-x-0');
            if (overlay) overlay.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        });
    }
    if (closeBtn && sidebar) {
        closeBtn.addEventListener('click', function() {
            sidebar.classList.add('-translate-x-full');
            sidebar.classList.remove('translate-x-0');
            if (overlay) overlay.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        });
    }
    if (overlay) {
        overlay.addEventListener('click', function() {
            sidebar.classList.add('-translate-x-full');
            sidebar.classList.remove('translate-x-0');
            overlay.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        });
    }
}
    
</script>

    <?php if ($user_role === 'admin'): ?>
        <?php require_once __DIR__ . '/includes/footer.php'; ?>
    <?php else: ?>
        <?php require_once __DIR__ . '/includes/footer_user.php'; ?>
    <?php endif; ?>