<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
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

$page_title = 'Manajemen Pengumuman - Admin';
$current_page = 'admin_announcements';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!function_exists('safeArray')) {
    function safeArray($data) { return is_array($data) ? array_filter($data, 'is_array') : []; }
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Ambil semua pengumuman (tanpa batasan untuk admin)
$announcements_raw = supabase_admin_request('GET', 'announcements', null, ['order' => 'created_at.desc']);
$announcements = safeArray($announcements_raw);

// Ambil semua user (guru + siswa)
$users_raw = supabase_admin_request('GET', 'users', null, ['role' => 'in.(teacher,student)']);
$users = safeArray($users_raw);

// Ambil semua data pembacaan
$reads_raw = supabase_admin_request('GET', 'announcement_reads');
$reads = safeArray($reads_raw);
$reads_by_announcement = [];
foreach ($reads as $r) {
    $reads_by_announcement[$r['announcement_id']][] = $r['user_id'];
}

// Jika ada aksi POST (tambah/edit/hapus), proses di sini
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';
    if ($csrf !== $_SESSION['csrf_token']) {
        $error = 'Token CSRF tidak valid.';
    } else {
        if ($action === 'add') {
            $title = trim($_POST['title']);
            $content = trim($_POST['content']);
            $target_role = $_POST['target_role'];
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            if (empty($title)) {
                $error = 'Judul harus diisi.';
            } else {
                $data = [
                    'title' => $title,
                    'content' => $content,
                    'target_role' => $target_role,
                    'is_active' => $is_active,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                $result = supabase_admin_request('POST', 'announcements', $data);
                if (isset($result['id'])) {
                    $message = 'Pengumuman berhasil ditambahkan.';
                    // Refresh data
                    header('Location: admin_announcements.php?msg=' . urlencode($message));
                    exit;
                } else {
                    $error = 'Gagal menambahkan: ' . json_encode($result);
                }
            }
        } elseif ($action === 'edit') {
            $id = $_POST['id'];
            $title = trim($_POST['title']);
            $content = trim($_POST['content']);
            $target_role = $_POST['target_role'];
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            if (empty($title) || empty($id)) {
                $error = 'Data tidak lengkap.';
            } else {
                $data = [
                    'title' => $title,
                    'content' => $content,
                    'target_role' => $target_role,
                    'is_active' => $is_active,
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                $result = supabase_admin_request('PATCH', 'announcements', $data, ['id' => 'eq.' . $id]);
                // Response biasanya array kosong atau error, asumsikan success jika tidak error
                if (!isset($result['error'])) {
                    $message = 'Pengumuman berhasil diupdate.';
                    header('Location: admin_announcements.php?msg=' . urlencode($message));
                    exit;
                } else {
                    $error = 'Gagal update: ' . $result['error'];
                }
            }
        } elseif ($action === 'delete') {
            $id = $_POST['id'];
            if (empty($id)) {
                $error = 'ID tidak valid.';
            } else {
                // Hapus juga announcement_reads yang terkait (cascade otomatis jika foreign key, tapi manual saja)
                supabase_admin_request('DELETE', 'announcement_reads', null, ['announcement_id' => 'eq.' . $id]);
                $result = supabase_admin_request('DELETE', 'announcements', null, ['id' => 'eq.' . $id]);
                if (!isset($result['error'])) {
                    $message = 'Pengumuman berhasil dihapus.';
                    header('Location: admin_announcements.php?msg=' . urlencode($message));
                    exit;
                } else {
                    $error = 'Gagal hapus: ' . $result['error'];
                }
            }
        } elseif ($action === 'mark_all_read' && isset($_POST['announcement_id'])) {
            // Admin menandai semua user untuk pengumuman tertentu sebagai sudah baca (force)
            $ann_id = $_POST['announcement_id'];
            $target_role = $_POST['target_role'] ?? 'all';
            // Filter user berdasarkan target
            $target_users = [];
            foreach ($users as $u) {
                if ($target_role == 'all') $target_users[] = $u;
                elseif ($target_role == 'teacher' && $u['role'] == 'teacher') $target_users[] = $u;
                elseif ($target_role == 'student' && $u['role'] == 'student') $target_users[] = $u;
            }
            $current_reads = $reads_by_announcement[$ann_id] ?? [];
            $inserted = 0;
            foreach ($target_users as $u) {
                if (!in_array($u['id'], $current_reads)) {
                    supabase_admin_request('POST', 'announcement_reads', [
                        'announcement_id' => $ann_id,
                        'user_id' => $u['id'],
                        'read_at' => date('Y-m-d H:i:s')
                    ]);
                    $inserted++;
                }
            }
            $message = "$inserted user ditandai sudah membaca.";
            header('Location: admin_announcements.php?msg=' . urlencode($message));
            exit;
        }
    }
}

// Pesan dari redirect
$msg = $_GET['msg'] ?? '';
if ($msg) $message = $msg;

// Data untuk detail pengumuman (jika ada parameter detail)
$selected_id = isset($_GET['detail']) ? $_GET['detail'] : null;
$selected_announcement = null;
if ($selected_id) {
    foreach ($announcements as $a) {
        if ($a['id'] == $selected_id) {
            $selected_announcement = $a;
            break;
        }
    }
}

require_once __DIR__ . '/includes/nav_items.php';
foreach ($nav_items as &$item) {
    $item['active'] = ($item['link'] == 'admin_announcements.php');
}
unset($item);
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .announcement-card { transition: all 0.2s; cursor: pointer; }
    .announcement-card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
    .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .status-badge { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; }
    .status-read { background-color: #10b981; }
    .status-unread { background-color: #ef4444; }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content-container flex-1 transition-all duration-300">
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
            <?php if ($message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Tombol Tambah Pengumuman -->
            <div class="mb-6 text-right">
                <button onclick="openAddModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg"><i class="fas fa-plus mr-1"></i> Tambah Pengumuman</button>
            </div>

            <!-- Daftar Pengumuman dalam Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                <?php if (empty($announcements)): ?>
                    <div class="col-span-full text-center py-10 text-gray-500">Belum ada pengumuman. Klik "Tambah Pengumuman" untuk membuat.</div>
                <?php else: ?>
                    <?php foreach ($announcements as $ann): 
                        $total_target = 0;
                        $total_read = count($reads_by_announcement[$ann['id']] ?? []);
                        if ($ann['target_role'] == 'all') $total_target = count($users);
                        elseif ($ann['target_role'] == 'teacher') $total_target = count(array_filter($users, fn($u)=>$u['role']=='teacher'));
                        else $total_target = count(array_filter($users, fn($u)=>$u['role']=='student'));
                        $percent = $total_target > 0 ? round(($total_read / $total_target) * 100) : 0;
                    ?>
                        <div class="announcement-card bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border-l-4 <?= $ann['is_active'] ? 'border-blue-500' : 'border-gray-400' ?>" onclick="showDetail('<?= $ann['id'] ?>')">
                            <div class="p-4">
                                <div class="flex justify-between items-start">
                                    <h3 class="font-bold text-gray-800 dark:text-white text-lg"><?= htmlspecialchars($ann['title']) ?></h3>
                                    <div class="flex gap-1">
                                        <button onclick="event.stopPropagation(); openEditModal('<?= $ann['id'] ?>', '<?= addslashes($ann['title']) ?>', `<?= addslashes($ann['content'] ?? '') ?>`, '<?= $ann['target_role'] ?>', <?= $ann['is_active'] ? 'true' : 'false' ?>)" class="text-blue-500 hover:text-blue-700"><i class="fas fa-edit"></i></button>
                                        <button onclick="event.stopPropagation(); deleteConfirm('<?= $ann['id'] ?>', '<?= addslashes($ann['title']) ?>')" class="text-red-500 hover:text-red-700 ml-2"><i class="fas fa-trash-alt"></i></button>
                                    </div>
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    <span class="inline-block px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 mr-2">
                                        <?= $ann['target_role'] == 'teacher' ? '👨‍🏫 Guru' : ($ann['target_role'] == 'student' ? '🧑‍🎓 Siswa' : '🌐 Semua') ?>
                                    </span>
                                    <?= date('d/m/Y H:i', strtotime($ann['created_at'])) ?>
                                    <?php if (!$ann['is_active']): ?> • <span class="text-red-500">Nonaktif</span><?php endif; ?>
                                </div>
                                <p class="text-gray-600 dark:text-gray-300 text-sm mt-2 line-clamp-2"><?= htmlspecialchars(strip_tags($ann['content'] ?? '')) ?></p>
                                <div class="mt-3 flex items-center justify-between text-xs">
                                    <div><span class="status-badge status-read"></span> <?= $total_read ?> / <?= $total_target ?> sudah baca</div>
                                    <div class="w-24 bg-gray-200 rounded-full h-1.5">
                                        <div class="bg-green-500 h-1.5 rounded-full" style="width: <?= $percent ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Detail Pengumuman (jika dipilih) -->
            <?php if ($selected_announcement): 
                $ann = $selected_announcement;
                $target_role = $ann['target_role'];
                $target_users = [];
                foreach ($users as $u) {
                    if ($target_role == 'all') $target_users[] = $u;
                    elseif ($target_role == 'teacher' && $u['role'] == 'teacher') $target_users[] = $u;
                    elseif ($target_role == 'student' && $u['role'] == 'student') $target_users[] = $u;
                }
                $read_ids = $reads_by_announcement[$ann['id']] ?? [];
            ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 mt-6">
                    <div class="flex justify-between items-center border-b pb-3 mb-4">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white">Detail Pengumuman</h2>
                        <button onclick="window.location.href='admin_announcements.php'" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times"></i></button>
                    </div>
                    <h3 class="text-lg font-semibold"><?= htmlspecialchars($ann['title']) ?></h3>
                    <div class="text-sm text-gray-500 mb-3"><?= date('d F Y H:i', strtotime($ann['created_at'])) ?> | Target: <?= $target_role == 'teacher' ? 'Guru' : ($target_role == 'student' ? 'Siswa' : 'Semua') ?> | Status: <?= $ann['is_active'] ? 'Aktif' : 'Nonaktif' ?></div>
                    <div class="bg-gray-50 dark:bg-gray-700 p-3 rounded mb-4 whitespace-pre-line"><?= nl2br(htmlspecialchars($ann['content'] ?? '')) ?></div>
                    
                    <div class="flex justify-between items-center mb-3">
                        <h4 class="font-semibold">Daftar Pembaca</h4>
                        <button onclick="markAllRead('<?= $ann['id'] ?>', '<?= $target_role ?>')" class="bg-blue-600 text-white px-3 py-1 rounded text-sm"><i class="fas fa-check-double mr-1"></i> Tandai semua sudah baca</button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50 dark:bg-gray-700"><tr><th class="px-4 py-2">Nama</th><th class="px-4 py-2">Role</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Waktu Baca</th></tr></thead>
                            <tbody>
                                <?php foreach ($target_users as $u): 
                                    $is_read = in_array($u['id'], $read_ids);
                                    $read_time = '';
                                    if ($is_read) {
                                        foreach ($reads as $r) {
                                            if ($r['announcement_id'] == $ann['id'] && $r['user_id'] == $u['id']) { $read_time = $r['read_at']; break; }
                                        }
                                    }
                                ?>
                                    <tr class="border-t">
                                        <td class="px-4 py-2"><?= htmlspecialchars($u['full_name']) ?></td>
                                        <td class="px-4 py-2"><?= $u['role'] == 'teacher' ? 'Guru' : 'Siswa' ?></td>
                                        <td class="px-4 py-2"><?= $is_read ? '<span class="text-green-600">Sudah baca</span>' : '<span class="text-red-500">Belum baca</span>' ?></td>
                                        <td class="px-4 py-2"><?= $read_time ? date('d/m/Y H:i', strtotime($read_time)) : '-' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Modal Tambah/Edit -->
<div id="announcementModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-2xl mx-4 max-h-[90vh] flex flex-col">
        <div class="flex justify-between items-center p-4 border-b dark:border-gray-700">
            <h3 id="modalTitle" class="text-lg font-bold">Tambah Pengumuman</h3>
            <button onclick="closeModal()" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times text-xl"></i></button>
        </div>
        <form id="announcementForm" method="POST" class="flex-1 overflow-y-auto p-4">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" id="formAction">
            <input type="hidden" name="id" id="announcementId">
            <div class="mb-4">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Judul <span class="text-red-500">*</span></label>
                <input type="text" name="title" id="title" required class="w-full border rounded px-3 py-2 dark:bg-gray-700">
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Konten</label>
                <textarea name="content" id="content" rows="6" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></textarea>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Target Role</label>
                <select name="target_role" id="target_role" class="w-full border rounded px-3 py-2 dark:bg-gray-700">
                    <option value="teacher">👨‍🏫 Guru</option>
                    <option value="student">🧑‍🎓 Siswa</option>
                    <option value="all">🌐 Semua (Guru + Siswa)</option>
                </select>
            </div>
            <div class="mb-4 flex items-center">
                <input type="checkbox" name="is_active" id="is_active" value="1" class="mr-2">
                <label class="text-gray-700 dark:text-gray-300">Aktif (ditampilkan)</label>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeModal()" class="bg-gray-400 text-white px-4 py-2 rounded">Batal</button>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Simpan</button>
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
    document.getElementById('formAction').value = 'add';
    document.getElementById('announcementId').value = '';
    document.getElementById('title').value = '';
    document.getElementById('content').value = '';
    document.getElementById('target_role').value = 'all';
    document.getElementById('is_active').checked = true;
    modalTitle.innerText = 'Tambah Pengumuman';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function openEditModal(id, title, content, targetRole, isActive) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('announcementId').value = id;
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

function deleteConfirm(id, title) {
    if (confirm(`Hapus pengumuman "${title}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function showDetail(id) {
    window.location.href = 'admin_announcements.php?detail=' + id;
}

function markAllRead(announcementId, targetRole) {
    if (confirm('Tandai semua user (sesuai target) sebagai sudah membaca pengumuman ini?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                          <input type="hidden" name="action" value="mark_all_read">
                          <input type="hidden" name="announcement_id" value="${announcementId}">
                          <input type="hidden" name="target_role" value="${targetRole}">`;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>