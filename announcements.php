<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Izinkan hanya teacher dan student
$allowed_roles = ['teacher', 'student'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], $allowed_roles)) {
    header('Location: login.php');
    exit;
}
if (time() - $_SESSION['login_time'] > 28800) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['login_time'] = time();

$page_title = 'Semua Pengumuman - SIAKAD';
$current_page = 'announcements';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Definisi safeArray jika belum ada
if (!function_exists('safeArray')) {
    function safeArray($data) {
        if (!is_array($data)) return [];
        return array_filter($data, 'is_array');
    }
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

// Ambil data user
$user_data = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $user_id]);
$user_info = (is_array($user_data) && !empty($user_data)) ? $user_data[0] : null;
if (!$user_info) { header('Location: logout.php'); exit; }

// Parameter filter dan paginasi
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Tentukan filter target_role berdasarkan role user
if ($user_role == 'teacher') {
    $target_filter = 'in.(teacher,all)';
} else { // student
    $target_filter = 'in.(student,all)';
}

$filters = [
    'is_active' => 'eq.true',
    'target_role' => $target_filter,
    'order' => 'created_at.desc',
    'limit' => $per_page,
    'offset' => $offset
];
if ($search !== '') {
    $filters['title'] = 'ilike.%' . $search . '%';
}

$announcements_raw = supabase_admin_request('GET', 'announcements', null, $filters);
$announcements = safeArray($announcements_raw);

// Hitung total data untuk paginasi
$count_filters = [
    'is_active' => 'eq.true',
    'target_role' => $target_filter
];
if ($search !== '') {
    $count_filters['title'] = 'ilike.%' . $search . '%';
}
$count_raw = supabase_admin_request('GET', 'announcements', null, array_merge($count_filters, ['select' => 'count']));
$total_records = is_array($count_raw) && isset($count_raw[0]['count']) ? (int)$count_raw[0]['count'] : 0;
$total_pages = ceil($total_records / $per_page);
$has_next = $page < $total_pages;
$has_prev = $page > 1;

// Ambil ID pengumuman yang sudah dibaca
$announcement_ids = array_column($announcements, 'id');
$read_ids = [];
if (!empty($announcement_ids)) {
    $reads_raw = supabase_admin_request('GET', 'announcement_reads', null, [
        'announcement_id' => 'in.(' . implode(',', array_map(function($id) { return "'$id'"; }, $announcement_ids)) . ')',
        'user_id' => 'eq.' . $user_id
    ]);
    $reads = safeArray($reads_raw);
    foreach ($reads as $r) {
        $read_ids[] = $r['announcement_id'];
    }
}

// Gunakan sidebar_user untuk teacher dan student
require_once __DIR__ . '/includes/header_user.php';
?>

<style>
    .announcement-card { transition: all 0.2s; }
    .announcement-card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
    .line-clamp-3 { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar_user.php'; ?>
    
    <div class="main-content-container flex-1">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openSidebarUserBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden" onclick="document.getElementById('sidebarUser').classList.remove('-translate-x-full'); document.getElementById('sidebarUser').classList.add('translate-x-0'); document.getElementById('sidebarOverlayUser').classList.remove('hidden'); document.body.classList.add('sidebar-open'); return false;">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Semua Pengumuman</h1>
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
                                $user_name = $_SESSION['user_name'] ?? 'User';
                                $initial = strtoupper(substr($user_name, 0, 1));
                                ?>
                                <?php if ($user_photo): ?>
                                    <img src="<?= htmlspecialchars($user_photo) ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <span><?= $initial ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="hidden md:inline text-gray-700 dark:text-gray-200"><?= htmlspecialchars($_SESSION['user_name']) ?></span>
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4 md:p-6 dark:bg-gray-900">
            <!-- Filter -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 mb-6">
                <form method="GET" class="flex flex-wrap gap-3 items-end">
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Cari pengumuman</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Judul..." class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700">
                    </div>
                    <div>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm"><i class="fas fa-search mr-1"></i> Cari</button>
                        <?php if ($search): ?>
                            <a href="announcements.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm ml-2">Reset</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Daftar Pengumuman -->
            <div class="space-y-4">
                <?php if (empty($announcements)): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-8 text-center">
                        <i class="fas fa-bell-slash text-5xl text-gray-400 mb-3"></i>
                        <p class="text-gray-500 dark:text-gray-400">Belum ada pengumuman.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($announcements as $ann): 
                        $is_read = in_array($ann['id'], $read_ids);
                    ?>
                        <div class="announcement-card bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border-l-4 <?= $is_read ? 'border-gray-300 dark:border-gray-600' : 'border-blue-500' ?>">
                            <div class="p-5">
                                <div class="flex justify-between items-start gap-3">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 flex-wrap mb-2">
                                            <h3 class="text-lg font-bold text-gray-800 dark:text-white"><?= htmlspecialchars($ann['title']) ?></h3>
                                            <?php if (!$is_read): ?>
                                                <span class="bg-blue-100 text-blue-800 text-xs px-2 py-0.5 rounded-full">Baru</span>
                                            <?php endif; ?>
                                            <span class="text-xs text-gray-500"><i class="far fa-calendar-alt mr-1"></i> <?= date('d M Y H:i', strtotime($ann['created_at'])) ?></span>
                                        </div>
                                        <div class="text-gray-700 dark:text-gray-300 text-sm mb-3 line-clamp-3">
                                            <?= nl2br(htmlspecialchars($ann['content'] ?? '')) ?>
                                        </div>
                                        <div class="flex justify-between items-center mt-2">
                                            <button onclick="toggleRead('<?= $ann['id'] ?>', this)" class="text-xs text-blue-600 dark:text-blue-400 hover:underline">
                                                <?= $is_read ? 'Tandai belum dibaca' : 'Tandai sudah dibaca' ?>
                                            </button>
                                            <button onclick="showDetailModal(<?= htmlspecialchars(json_encode($ann['title'])) ?>, <?= htmlspecialchars(json_encode($ann['content'] ?? '')) ?>, '<?= date('d M Y H:i', strtotime($ann['created_at'])) ?>')" class="text-xs text-gray-500 hover:text-gray-700">
                                                <i class="fas fa-expand-alt mr-1"></i> Baca lengkap
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Paginasi -->
                    <div class="flex justify-center gap-2 mt-6">
                        <?php if ($has_prev): ?>
                            <a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>" class="px-3 py-1 rounded bg-gray-200 dark:bg-gray-700 text-gray-700">« Sebelumnya</a>
                        <?php endif; ?>
                        <span class="px-3 py-1">Halaman <?= $page ?> dari <?= $total_pages ?></span>
                        <?php if ($has_next): ?>
                            <a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>" class="px-3 py-1 rounded bg-gray-200 dark:bg-gray-700 text-gray-700">Selanjutnya »</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Modal Detail -->
<div id="detailModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-2xl mx-4 max-h-[85vh] flex flex-col">
        <div class="flex justify-between items-center p-4 border-b dark:border-gray-700">
            <h3 id="modalTitle" class="text-lg font-bold text-gray-800 dark:text-white">Detail Pengumuman</h3>
            <button onclick="closeModal()" class="text-gray-500 hover:text-gray-700">&times;</button>
        </div>
        <div class="p-4 overflow-y-auto flex-1">
            <p id="modalDate" class="text-xs text-gray-500 mb-3"></p>
            <div id="modalContent" class="text-gray-800 dark:text-gray-200 text-sm"></div>
        </div>
        <div class="flex justify-end p-4 border-t">
            <button onclick="closeModal()" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Tutup</button>
        </div>
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

// Toggle baca
async function toggleRead(id, btn) {
    const csrf = '<?= $_SESSION['csrf_token'] ?>';
    const isCurrentlyMarked = btn.innerText.includes('Tandai sudah dibaca') ? false : true;
    const action = isCurrentlyMarked ? 'unmark' : 'mark';
    try {
        const res = await fetch('api/toggle_announcement_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ announcement_id: id, action, csrf_token: csrf })
        });
        const data = await res.json();
        if (data.success) {
            const card = btn.closest('.announcement-card');
            const badge = card.querySelector('.bg-blue-100');
            if (action === 'mark') {
                card.classList.remove('border-gray-300', 'dark:border-gray-600');
                card.classList.add('border-blue-500');
                if (!badge) {
                    const titleDiv = card.querySelector('.flex.items-center.gap-2');
                    const newBadge = document.createElement('span');
                    newBadge.className = 'bg-blue-100 text-blue-800 text-xs px-2 py-0.5 rounded-full';
                    newBadge.innerText = 'Baru';
                    titleDiv.appendChild(newBadge);
                }
                btn.innerText = 'Tandai sudah dibaca';
            } else {
                card.classList.remove('border-blue-500');
                card.classList.add('border-gray-300', 'dark:border-gray-600');
                if (badge) badge.remove();
                btn.innerText = 'Tandai belum dibaca';
            }
        } else alert('Gagal: ' + data.message);
    } catch(e) { alert('Terjadi kesalahan'); }
}

function showDetailModal(title, content, date) {
    document.getElementById('modalTitle').innerText = title;
    document.getElementById('modalContent').innerHTML = content.replace(/\n/g, '<br>');
    document.getElementById('modalDate').innerText = date;
    document.getElementById('detailModal').classList.remove('hidden');
    document.getElementById('detailModal').classList.add('flex');
}
function closeModal() {
    const m = document.getElementById('detailModal');
    m.classList.add('hidden');
    m.classList.remove('flex');
}
</script>

<?php require_once __DIR__ . '/includes/footer_user.php'; ?>