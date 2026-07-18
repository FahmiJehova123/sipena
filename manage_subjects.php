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

$page_title = 'Manajemen Mata Pelajaran - SIAKAD Admin';
$current_page = 'subjects';
require_once __DIR__ . '/config.php';

// ========== PAGINATION & SEARCH ==========
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Ambil semua data subjects (urutkan dari Supabase)
$all_subjects_raw = supabase_admin_request('GET', 'subjects', null, ['order' => 'grade_level.asc, subject_name.asc']);
$all_subjects = [];
if (is_array($all_subjects_raw)) {
    foreach ($all_subjects_raw as $item) {
        if (is_array($item) && isset($item['id'])) {
            $all_subjects[] = $item;
        }
    }
}

// Filter berdasarkan pencarian (di PHP)
if (!empty($search)) {
    $search_lower = strtolower($search);
    $all_subjects = array_filter($all_subjects, function($subject) use ($search_lower) {
        return strpos(strtolower($subject['subject_code'] ?? ''), $search_lower) !== false ||
               strpos(strtolower($subject['subject_name'] ?? ''), $search_lower) !== false ||
               strpos(strtolower((string)($subject['grade_level'] ?? '')), $search_lower) !== false;
    });
    $all_subjects = array_values($all_subjects); // reindex array
}

$total = count($all_subjects);
$total_pages = ceil($total / $limit);
$subjects = array_slice($all_subjects, $offset, $limit);

// ========== PROSES CRUD ==========
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $data = [
            'subject_name' => $_POST['subject_name'],
            'subject_code' => $_POST['subject_code'],
            'grade_level'  => (int)$_POST['grade_level']
        ];
        $result = supabase_admin_request('POST', 'subjects', $data);
        if (isset($result['id'])) {
            $message = 'Mata pelajaran berhasil ditambahkan';
            header('Location: manage_subjects.php?page=1' . (!empty($search) ? '&search=' . urlencode($search) : ''));
            exit;
        } else {
            $message = 'Gagal menambah: ' . json_encode($result);
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $data = [
            'subject_name' => $_POST['subject_name'],
            'subject_code' => $_POST['subject_code'],
            'grade_level'  => (int)$_POST['grade_level']
        ];
        $result = supabase_admin_request('PATCH', 'subjects', $data, ['id' => 'eq.' . $id]);
        if (isset($result['id']) || (is_array($result) && empty($result))) {
            $message = 'Mata pelajaran berhasil diupdate';
            header('Location: manage_subjects.php?page=' . $page . (!empty($search) ? '&search=' . urlencode($search) : ''));
            exit;
        } else {
            $message = 'Gagal update: ' . json_encode($result);
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        supabase_admin_request('DELETE', 'subjects', null, ['id' => 'eq.' . $id]);
        $message = 'Mata pelajaran berhasil dihapus';
        header('Location: manage_subjects.php?page=' . $page . (!empty($search) ? '&search=' . urlencode($search) : ''));
        exit;
    }
}

// Navigasi sidebar
require_once __DIR__ . '/includes/nav_items.php';
foreach ($nav_items as &$item) {
    $item['active'] = ($item['link'] == 'manage_subjects.php');
}
unset($item);
require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* Responsif tabel */
    @media (max-width: 768px) {
        .table-container { overflow-x: auto; }
        table { min-width: 500px; }
        th, td { padding: 10px 8px; font-size: 0.85rem; }
    }
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
    .pagination a, .pagination span { padding: 6px 12px; border-radius: 6px; background: #e5e7eb; color: #1f2937; text-decoration: none; }
    .pagination .active { background: #3b82f6; color: white; }
    .pagination a:hover { background: #9ca3af; }
    .dark .pagination a, .dark .pagination span { background: #374151; color: #e5e7eb; }
    .dark .pagination .active { background: #3b82f6; }
    .search-bar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    @media (max-width: 640px) { .search-bar { flex-direction: column; align-items: stretch; } }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main-content-container flex-1 transition-all duration-300 ease-in-out">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openMobileSidebarBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Manajemen Mata Pelajaran</h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                        <i class="fas fa-moon text-xl dark:hidden"></i>
                        <i class="fas fa-sun text-xl hidden dark:inline"></i>
                    </button>
                    <div class="relative group">
                        <button class="flex items-center space-x-2 focus:outline-none">
                            <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold">A</div>
                            <span class="hidden md:inline text-gray-700 dark:text-gray-200"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></span>
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

            <div class="flex flex-wrap justify-between items-center gap-3 mb-4">
                <div class="search-bar">
                    <form method="GET" class="flex flex-wrap gap-2">
                        <input type="text" name="search" placeholder="Cari kode, nama, atau tingkat..." value="<?= htmlspecialchars($search) ?>" class="border rounded px-3 py-2 w-full dark:bg-gray-700 dark:text-white w-64">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded"><i class="fas fa-search"></i> Cari</button>
                        <?php if ($search): ?>
                            <a href="manage_subjects.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded"><i class="fas fa-times"></i> Reset</a>
                        <?php endif; ?>
                    </form>
                </div>
                <button onclick="openModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded"><i class="fas fa-plus mr-1"></i>Tambah Mata Pelajaran</button>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-x-auto table-container">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Tingkat</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Kode</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Nama Mata Pelajaran</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php if (empty($subjects)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-4 text-gray-500">
                                    <?= $search ? "Tidak ada mata pelajaran yang cocok dengan pencarian '".htmlspecialchars($search)."'" : "Belum ada mata pelajaran" ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($subjects as $subject): ?>
                                <?php if (!is_array($subject)) continue; ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="px-6 py-4 whitespace-nowrap"><?= (int)$subject['grade_level'] ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($subject['subject_code']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($subject['subject_name']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap space-x-2">
                                        <button onclick="editSubject(<?= $subject['id'] ?>, '<?= htmlspecialchars($subject['subject_name']) ?>', '<?= htmlspecialchars($subject['subject_code']) ?>', <?= (int)$subject['grade_level'] ?>)" class="bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1 rounded text-sm">Edit</button>
                                        <button onclick="confirmDelete(<?= $subject['id'] ?>)" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-sm">Hapus</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page-1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>">« Prev</a>
                <?php else: ?>
                    <span class="opacity-50">« Prev</span>
                <?php endif; ?>

                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $start_page + 4);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page+1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>">Next »</a>
                <?php else: ?>
                    <span class="opacity-50">Next »</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Modal Tambah/Edit -->
<div id="subjectModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-96 max-w-full">
        <h3 id="modalTitle" class="text-lg font-bold text-gray-800 dark:text-white mb-4">Tambah Mata Pelajaran</h3>
        <form method="POST" id="subjectForm">
            <input type="hidden" name="action" id="formAction">
            <input type="hidden" name="id" id="subjectId">
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Tingkat Kelas</label>
                <input type="number" name="grade_level" id="gradeLevel" required min="1" max="20" step="1" value="1" class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                <p class="text-xs text-gray-500 mt-1">Masukkan angka tingkat (1, 2, 3, ...)</p>
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Kode Mata Pelajaran</label>
                <input type="text" name="subject_code" id="subjectCode" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Nama Mata Pelajaran</label>
                <input type="text" name="subject_name" id="subjectName" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
            </div>
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="closeModal()" class="bg-gray-400 hover:bg-gray-500 text-white px-4 py-2 rounded">Batal</button>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
// Dark mode (sama seperti sebelumnya)
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

// Modal handlers
const modal = document.getElementById('subjectModal');
function openModal() {
    document.getElementById('formAction').value = 'add';
    document.getElementById('subjectId').value = '';
    document.getElementById('gradeLevel').value = '1';
    document.getElementById('subjectCode').value = '';
    document.getElementById('subjectName').value = '';
    document.getElementById('modalTitle').innerText = 'Tambah Mata Pelajaran';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closeModal() {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
function editSubject(id, name, code, gradeLevel) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('subjectId').value = id;
    document.getElementById('gradeLevel').value = gradeLevel;
    document.getElementById('subjectCode').value = code;
    document.getElementById('subjectName').value = name;
    document.getElementById('modalTitle').innerText = 'Edit Mata Pelajaran';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function confirmDelete(id) {
    if (confirm('Yakin hapus mata pelajaran ini? Data jadwal yang terkait juga akan terhapus jika ada cascade.')) {
        const f = document.createElement('form');
        f.method = 'POST';
        f.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(f);
        f.submit();
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>