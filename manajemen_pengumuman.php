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

$page_title = 'Manajemen Pengumuman - SIAKAD Admin';
$current_page = 'announcements';
require_once __DIR__ . '/config.php';

function safeArray($data) {
    return is_array($data) ? $data : [];
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Pagination & filter
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$target_filter = isset($_GET['target']) ? $_GET['target'] : '';

$filters = [
    'order' => 'created_at.desc',
    'limit' => $per_page,
    'offset' => $offset
];
if ($search !== '') {
    $filters['title'] = 'ilike.%' . $search . '%';
}
if ($target_filter !== '') {
    $filters['target_role'] = 'eq.' . $target_filter;
}

// Ambil data pengumuman
$announcements_raw = supabase_admin_request('GET', 'annotations', null, $filters); // HATI-HATI: nama tabel sebenarnya 'announcements', bukan 'annotations'. Ganti dengan 'announcements' jika perlu. Saya akan gunakan 'announcements'
// Jika tabel Anda bernama 'announcements', gunakan:
$announcements_raw = supabase_admin_request('GET', 'announcements', null, $filters);
$announcements = safeArray($announcements_raw);

// Hitung total data untuk paginasi (menggunakan endpoint terpisah atau query count)
$count_filters = [];
if ($search !== '') $count_filters['title'] = 'ilike.%' . $search . '%';
if ($target_filter !== '') $count_filters['target_role'] = 'eq.' . $target_filter;
$count_raw = supabase_admin_request('GET', 'announcements', null, array_merge($count_filters, ['select' => 'count']));
$total_records = is_array($count_raw) && isset($count_raw[0]['count']) ? (int)$count_raw[0]['count'] : 0;
$total_pages = ceil($total_records / $per_page);

require_once __DIR__ . '/includes/nav_items.php';
foreach ($nav_items as &$item) {
    $item['active'] = ($item['link'] == 'manajemen_pengumuman.php');
}
unset($item);
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .modal { transition: opacity 0.2s ease; }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content-container flex-1 transition-all duration-300 ease-in-out">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openMobileSidebarBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Manajemen Pengumuman</h1>
                <div class="flex items-center space-x-4 ml-auto">
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
                                    <img src="<?= htmlspecialchars($user_photo) ?>" class="w-full h-full object-cover">
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
            <!-- Filter & Tambah -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 mb-6">
                <div class="flex flex-wrap justify-between gap-4">
                    <form method="GET" class="flex flex-wrap gap-3 items-end">
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Cari Judul</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari..." class="border rounded px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Target Role</label>
                            <select name="target" class="border rounded px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
                                <option value="">Semua</option>
                                <option value="teacher" <?= $target_filter == 'teacher' ? 'selected' : '' ?>>Guru</option>
                                <option value="student" <?= $target_filter == 'student' ? 'selected' : '' ?>>Siswa</option>
                                <option value="all" <?= $target_filter == 'all' ? 'selected' : '' ?>>Semua (Guru+Siswa)</option>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm"><i class="fas fa-search mr-1"></i> Filter</button>
                            <?php if ($search || $target_filter): ?>
                                <a href="manajemen_pengumuman.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm ml-2">Reset</a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <button onclick="openAddModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm"><i class="fas fa-plus mr-1"></i> Tambah Pengumuman</button>
                </div>
            </div>

            <!-- Daftar Pengumuman -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase">Judul</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase">Target</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase">Dibuat</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php if (empty($announcements)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-6 text-gray-500">Belum ada pengumuman. Klik "Tambah Pengumuman" untuk membuat.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($announcements as $ann): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <td class="px-4 py-3">
                                            <div class="font-medium text-gray-800 dark:text-white"><?= htmlspecialchars($ann['title']) ?></div>
                                            <div class="text-xs text-gray-500 line-clamp-1"><?= htmlspecialchars(strip_tags($ann['content'] ?? '')) ?></div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?php
                                                $target_label = [
                                                    'teacher' => '👨‍🏫 Guru',
                                                    'student' => '🧑‍🎓 Siswa',
                                                    'all' => '🌐 Semua'
                                                ][$ann['target_role']] ?? $ann['target_role'];
                                            ?>
                                            <span class="px-2 py-1 rounded-full text-xs bg-gray-100 dark:bg-gray-600"><?= $target_label ?></span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?php if ($ann['is_active']): ?>
                                                <span class="px-2 py-1 rounded-full text-xs bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Aktif</span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 rounded-full text-xs bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">Nonaktif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400"><?= date('d M Y H:i', strtotime($ann['created_at'])) ?></td>
                                        <td class="px-4 py-3">
                                            <button onclick="openEditModal('<?= $ann['id'] ?>', '<?= addslashes($ann['title']) ?>', `<?= addslashes($ann['content'] ?? '') ?>`, '<?= $ann['target_role'] ?>', <?= $ann['is_active'] ? 'true' : 'false' ?>)" class="text-blue-600 hover:text-blue-800 mr-2"><i class="fas fa-edit"></i></button>
                                            <button onclick="deleteAnnouncement('<?= $ann['id'] ?>', '<?= addslashes($ann['title']) ?>')" class="text-red-600 hover:text-red-800"><i class="fas fa-trash-alt"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Paginasi -->
                <?php if ($total_pages > 1): ?>
                <div class="flex justify-center mt-6 gap-1">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&target=<?= urlencode($target_filter) ?>" class="px-3 py-1 rounded bg-gray-200 dark:bg-gray-700">«</a>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&target=<?= urlencode($target_filter) ?>" class="px-3 py-1 rounded <?= $i == $page ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&target=<?= urlencode($target_filter) ?>" class="px-3 py-1 rounded bg-gray-200 dark:bg-gray-700">»</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Modal Tambah/Edit -->
<div id="announcementModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-2xl mx-4 max-h-[90vh] flex flex-col">
        <div class="flex justify-between items-center p-4 border-b dark:border-gray-700">
            <h3 id="modalTitle" class="text-lg font-bold text-gray-800 dark:text-white">Tambah Pengumuman</h3>
            <button onclick="closeModal()" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times text-xl"></i></button>
        </div>
        <form id="announcementForm" class="flex-1 overflow-y-auto p-4">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="announcement_id" id="announcement_id">
            <div class="mb-4">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Judul <span class="text-red-500">*</span></label>
                <input type="text" name="title" id="title" required class="w-full border rounded-lg px-3 py-2 dark:bg-gray-700 dark:border-gray-600">
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Konten</label>
                <textarea name="content" id="content" rows="6" class="w-full border rounded-lg px-3 py-2 dark:bg-gray-700 dark:border-gray-600"></textarea>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Target Role <span class="text-red-500">*</span></label>
                <select name="target_role" id="target_role" required class="w-full border rounded-lg px-3 py-2 dark:bg-gray-700">
                    <option value="teacher">👨‍🏫 Guru</option>
                    <option value="student">🧑‍🎓 Siswa</option>
                    <option value="all">🌐 Semua (Guru + Siswa)</option>
                </select>
            </div>
            <div class="mb-4 flex items-center">
                <input type="checkbox" name="is_active" id="is_active" value="1" class="mr-2">
                <label class="text-gray-700 dark:text-gray-300">Aktif (ditampilkan)</label>
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <button type="button" onclick="closeModal()" class="bg-gray-400 hover:bg-gray-500 text-white px-4 py-2 rounded-lg">Batal</button>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
// Dark mode
const darkModeToggle = document.getElementById('darkModeToggle');
function setDarkMode(isDark) {
    if (isDark) document.documentElement.classList.add('dark');
    else document.documentElement.classList.remove('dark');
    localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled');
}
const saved = localStorage.getItem('darkMode');
if (saved === 'enabled') setDarkMode(true);
else if (saved !== 'disabled' && window.matchMedia('(prefers-color-scheme: dark)').matches) setDarkMode(true);
darkModeToggle?.addEventListener('click', () => setDarkMode(!document.documentElement.classList.contains('dark')));

// Modal handling
const modal = document.getElementById('announcementModal');
const form = document.getElementById('announcementForm');
const modalTitle = document.getElementById('modalTitle');

function openAddModal() {
    document.getElementById('announcement_id').value = '';
    document.getElementById('title').value = '';
    document.getElementById('content').value = '';
    document.getElementById('target_role').value = 'all';
    document.getElementById('is_active').checked = true;
    modalTitle.innerText = 'Tambah Pengumuman';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function openEditModal(id, title, content, targetRole, isActive) {
    document.getElementById('announcement_id').value = id;
    document.getElementById('title').value = title;
    document.getElementById('content').value = content;
    document.getElementById('target_role').value = targetRole;
    document.getElementById('is_active').checked = isActive;
    modalTitle.innerText = 'Edit Pengumuman';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeModal() {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Submit form via AJAX
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(form);
    const data = {
        csrf_token: formData.get('csrf_token'),
        announcement_id: formData.get('announcement_id') || null,
        title: formData.get('title'),
        content: formData.get('content'),
        target_role: formData.get('target_role'),
        is_active: formData.get('is_active') === '1'
    };
    const method = data.announcement_id ? 'PUT' : 'POST';
    const url = data.announcement_id ? 'api/update_announcement.php' : 'api/create_announcement.php';
    
    try {
        const res = await fetch(url, {
            method: 'POST', // both endpoints accept POST with method override
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await res.json();
        if (result.success) {
            alert('Berhasil disimpan');
            location.reload();
        } else {
            alert('Gagal: ' + (result.message || 'Terjadi kesalahan'));
        }
    } catch(err) {
        alert('Error: ' + err.message);
    }
});

async function deleteAnnouncement(id, title) {
    if (!confirm(`Hapus pengumuman "${title}"?`)) return;
    try {
        const res = await fetch('api/delete_announcement.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ announcement_id: id, csrf_token: '<?= $_SESSION['csrf_token'] ?>' })
        });
        const result = await res.json();
        if (result.success) {
            alert('Pengumuman dihapus');
            location.reload();
        } else {
            alert('Gagal: ' + result.message);
        }
    } catch(err) {
        alert('Error: ' + err.message);
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>