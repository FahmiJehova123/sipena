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

$page_title = 'Manajemen Kelas - SIAKAD Admin';
$current_page = 'manage_classes';
require_once __DIR__ . '/config.php';

// Ambil daftar guru untuk dropdown wali kelas
$teachers_raw = supabase_admin_request('GET', 'users', null, ['role' => 'eq.teacher', 'order' => 'full_name.asc']);
$teachers = [];
if (is_array($teachers_raw)) {
    foreach ($teachers_raw as $t) {
        if (isset($t['id'])) $teachers[] = $t;
    }
}

// Proses form (tambah, edit, hapus) dan update order
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ---- TANGANI UPDATE ORDER (dari fetch JSON) ----
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if ($input && isset($input['action']) && $input['action'] === 'update_order' && isset($input['items'])) {
        $items = $input['items']; // array id dalam urutan baru
        $success = true;
        foreach ($items as $index => $id) {
            $result = supabase_admin_request('PATCH', 'classes', ['sort_order' => $index], ['id' => 'eq.' . $id]);
            // Jika result null, berarti gagal
            if ($result === null) {
                $success = false;
                break;
            }
            // Jika result berupa array dan ada kunci 'error', gagal
            if (is_array($result) && isset($result['error'])) {
                $success = false;
                break;
            }
            // Selain itu (termasuk array kosong atau berisi id), dianggap sukses
        }
        echo json_encode(['success' => $success]);
        exit;
    }

    // ---- TANGANI FORM BIASA (add, edit, delete) ----
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $data = [
            'class_name' => $_POST['class_name'],
            'grade_level' => (int)$_POST['grade_level'],
            'class_type' => $_POST['class_type'] ?? 'pagi',
            'homeroom_teacher_id' => !empty($_POST['homeroom_teacher_id']) ? $_POST['homeroom_teacher_id'] : null
        ];
        $result = supabase_admin_request('POST', 'classes', $data);
        if (isset($result['id'])) {
            $message = 'Kelas berhasil ditambahkan';
        } else {
            $message = 'Gagal menambah kelas: ' . json_encode($result);
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $data = [
            'class_name' => $_POST['class_name'],
            'grade_level' => (int)$_POST['grade_level'],
            'class_type' => $_POST['class_type'] ?? 'pagi',
            'homeroom_teacher_id' => !empty($_POST['homeroom_teacher_id']) ? $_POST['homeroom_teacher_id'] : null
        ];
        $result = supabase_admin_request('PATCH', 'classes', $data, ['id' => 'eq.' . $id]);
        if (isset($result['id']) || (is_array($result) && empty($result))) {
            $message = 'Kelas berhasil diupdate';
        } else {
            $message = 'Gagal update kelas: ' . json_encode($result);
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        supabase_admin_request('DELETE', 'classes', null, ['id' => 'eq.' . $id]);
        $message = 'Kelas berhasil dihapus';
    }
    header('Location: manage_classes.php');
    exit;
}

// Ambil data kelas dengan urutan berdasarkan sort_order, fallback ke grade_level
$classes_raw = supabase_admin_request('GET', 'classes', null, [
    'order' => 'class_type.asc, sort_order.asc, grade_level.asc, class_name.asc'
]);
$classes = [];
if (is_array($classes_raw)) {
    foreach ($classes_raw as $c) {
        if (isset($c['id'])) $classes[] = $c;
    }
}

// Mapping id guru => nama
$teacher_map = [];
foreach ($teachers as $t) {
    $teacher_map[$t['id']] = $t['full_name'] ?? '-';
}
foreach ($classes as &$c) {
    $c['homeroom_teacher_name'] = isset($c['homeroom_teacher_id']) && isset($teacher_map[$c['homeroom_teacher_id']]) 
        ? $teacher_map[$c['homeroom_teacher_id']] 
        : '-';
}
unset($c);

// Pisahkan kelas berdasarkan tipe
$classes_pagi = array_filter($classes, fn($c) => ($c['class_type'] ?? 'pagi') == 'pagi');
$classes_diniyyah = array_filter($classes, fn($c) => ($c['class_type'] ?? 'pagi') == 'diniyyah');

require_once __DIR__ . '/includes/nav_items.php';
foreach ($nav_items as &$item) {
    $item['active'] = ($item['link'] == 'manage_classes.php');
}
unset($item);
require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* Tambahan untuk rapi tombol aksi */
    .action-buttons {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .btn-edit, .btn-delete {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.25rem 0.75rem;
        font-size: 0.875rem;
        border-radius: 0.375rem;
        transition: all 0.2s;
    }
    .btn-edit {
        background-color: #eab308;
        color: white;
    }
    .btn-edit:hover {
        background-color: #ca8a04;
    }
    .btn-delete {
        background-color: #ef4444;
        color: white;
    }
    .btn-delete:hover {
        background-color: #dc2626;
    }
    .dark .btn-edit {
        background-color: #b45309;
    }
    .dark .btn-edit:hover {
        background-color: #9a3412;
    }
    .dark .btn-delete {
        background-color: #b91c1c;
    }
    .dark .btn-delete:hover {
        background-color: #991b1b;
    }
    @media (max-width: 640px) {
        .action-buttons {
            flex-direction: column;
            gap: 0.25rem;
        }
        .btn-edit, .btn-delete {
            justify-content: center;
        }
    }

    /* Drag handle */
    .draggable-row td:first-child {
        cursor: grab;
    }
    .draggable-row td:first-child:active {
        cursor: grabbing;
    }
    .sortable-chosen {
        background-color: #fbbf24 !important;
    }
    .sortable-ghost {
        opacity: 0.4;
    }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main-content-container flex-1">
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

        <main class="p-4 md:p-6 dark:bg-gray-900">
            <?php if ($message): ?>
                <div class="bg-green-100 dark:bg-green-800 text-green-700 p-3 rounded mb-4"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="flex justify-end mb-4">
                <button onclick="openModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded"><i class="fas fa-plus mr-1"></i>Tambah Kelas</button>
            </div>

            <!-- Tab navigasi -->
            <div class="mb-4 border-b border-gray-200 dark:border-gray-700">
                <ul class="flex flex-wrap -mb-px text-sm font-medium text-center">
                    <li class="mr-2"><button class="inline-block p-4 border-b-2 rounded-t-lg active" id="pagi-tab" data-tab="pagi">Kelas Pagi</button></li>
                    <li class="mr-2"><button class="inline-block p-4 border-b-2 rounded-t-lg" id="diniyyah-tab" data-tab="diniyyah">Kelas Diniyyah</button></li>
                </ul>
            </div>

            <!-- Tab Kelas Pagi -->
            <div id="tab-pagi" class="tab-content">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Urut</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">No</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Nama Kelas</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Tingkat</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Wali Kelas</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-pagi">
                            <?php if (empty($classes_pagi)): ?>
                                <tr><td colspan="6" class="text-center py-4 text-gray-500">Tidak ada kelas pagi</td></tr>
                            <?php else: 
                                $counter = 1;
                                foreach ($classes_pagi as $class): ?>
                                <tr data-id="<?= $class['id'] ?>" class="draggable-row hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="px-2 py-3 text-center cursor-move">
                                        <i class="fas fa-grip-vertical text-gray-400"></i>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap"><?= $counter++ ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap font-medium"><?= htmlspecialchars($class['class_name']) ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap"><?= $class['grade_level'] ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap"><?= htmlspecialchars($class['homeroom_teacher_name']) ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="action-buttons">
                                            <button onclick="editClass(<?= $class['id'] ?>, '<?= htmlspecialchars($class['class_name']) ?>', <?= $class['grade_level'] ?>, '<?= $class['class_type'] ?>', '<?= $class['homeroom_teacher_id'] ?? '' ?>')" class="btn-edit">
                                                <i class="fas fa-edit text-xs"></i> Edit
                                            </button>
                                            <button onclick="confirmDelete(<?= $class['id'] ?>)" class="btn-delete">
                                                <i class="fas fa-trash-alt text-xs"></i> Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab Kelas Diniyyah -->
            <div id="tab-diniyyah" class="tab-content hidden">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Urut</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">No</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Nama Kelas</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Tingkat</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Wali Kelas</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-diniyyah">
                            <?php if (empty($classes_diniyyah)): ?>
                                <tr><td colspan="6" class="text-center py-4 text-gray-500">Tidak ada kelas diniyyah</td></tr>
                            <?php else: 
                                $counter = 1;
                                foreach ($classes_diniyyah as $class): ?>
                                <tr data-id="<?= $class['id'] ?>" class="draggable-row hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="px-2 py-3 text-center cursor-move">
                                        <i class="fas fa-grip-vertical text-gray-400"></i>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap"><?= $counter++ ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap font-medium"><?= htmlspecialchars($class['class_name']) ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap"><?= $class['grade_level'] ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap"><?= htmlspecialchars($class['homeroom_teacher_name']) ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="action-buttons">
                                            <button onclick="editClass(<?= $class['id'] ?>, '<?= htmlspecialchars($class['class_name']) ?>', <?= $class['grade_level'] ?>, '<?= $class['class_type'] ?>', '<?= $class['homeroom_teacher_id'] ?? '' ?>')" class="btn-edit">
                                                <i class="fas fa-edit text-xs"></i> Edit
                                            </button>
                                            <button onclick="confirmDelete(<?= $class['id'] ?>)" class="btn-delete">
                                                <i class="fas fa-trash-alt text-xs"></i> Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Modal Tambah/Edit -->
<div id="classModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-96 max-w-full">
        <h3 id="modalTitle" class="text-lg font-bold mb-4 dark:text-white">Tambah Kelas</h3>
        <form method="POST" id="classForm">
            <input type="hidden" name="action" id="formAction">
            <input type="hidden" name="id" id="classId">
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Nama Kelas</label>
                <input type="text" name="class_name" id="className" required class="w-full border rounded px-2 py-1 dark:bg-gray-700">
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Tingkat (Angka)</label>
                <input type="number" name="grade_level" id="gradeLevel" required min="1" max="12" class="w-full border rounded px-2 py-1 dark:bg-gray-700">
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Tipe Kelas</label>
                <select name="class_type" id="classType" required class="w-full border rounded px-2 py-1 dark:bg-gray-700">
                    <option value="pagi">Kelas Pagi</option>
                    <option value="diniyyah">Kelas Diniyyah</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Wali Kelas</label>
                <select name="homeroom_teacher_id" id="homeroomTeacherId" class="w-full border rounded px-2 py-1 dark:bg-gray-700">
                    <option value="">-- Pilih Wali Kelas (Opsional) --</option>
                    <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= $teacher['id'] ?>"><?= htmlspecialchars($teacher['full_name']) ?> (<?= htmlspecialchars($teacher['nidn_or_nisn'] ?? '-') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="closeModal()" class="bg-gray-400 text-white px-4 py-2 rounded">Batal</button>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- CDN SortableJS -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<script>
// Dark mode toggle
const darkModeToggle = document.getElementById('darkModeToggle');
function setDarkMode(isDark) { if (isDark) document.documentElement.classList.add('dark'); else document.documentElement.classList.remove('dark'); localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled'); }
const saved = localStorage.getItem('darkMode'); if (saved === 'enabled') setDarkMode(true); else if (saved !== 'disabled') setDarkMode(window.matchMedia('(prefers-color-scheme: dark)').matches);
darkModeToggle?.addEventListener('click', () => setDarkMode(!document.documentElement.classList.contains('dark')));

// Tab switching
const tabs = document.querySelectorAll('[data-tab]');
const contents = { pagi: document.getElementById('tab-pagi'), diniyyah: document.getElementById('tab-diniyyah') };
tabs.forEach(tab => {
    tab.addEventListener('click', () => {
        const target = tab.getAttribute('data-tab');
        tabs.forEach(t => t.classList.remove('active', 'border-blue-600', 'text-blue-600'));
        tab.classList.add('active', 'border-blue-600', 'text-blue-600');
        if (contents[target]) {
            Object.values(contents).forEach(c => c.classList.add('hidden'));
            contents[target].classList.remove('hidden');
        }
        // Inisialisasi Sortable untuk tab yang baru muncul (jika belum)
        setTimeout(() => {
            const tbody = document.getElementById('tbody-' + target);
            if (tbody && !tbody.sortableInstance) {
                initSortable(tbody, target);
            }
        }, 100);
    });
});
document.querySelector('[data-tab="pagi"]').click();

// Fungsi untuk menampilkan toast (contoh sederhana)
function showToast(msg, type = 'success') {
    const div = document.createElement('div');
    div.className = 'fixed top-4 right-4 z-50 px-4 py-2 rounded shadow-lg text-white ' + (type === 'success' ? 'bg-green-600' : 'bg-red-600');
    div.innerText = msg;
    document.body.appendChild(div);
    setTimeout(() => div.remove(), 3000);
}

// Inisialisasi Sortable pada tbody
function initSortable(tbody, type) {
    if (!tbody) return;
    if (tbody.sortableInstance) return; // sudah ada

    Sortable.create(tbody, {
        handle: '.draggable-row td:first-child', // kolom ikon grip
        animation: 150,
        onEnd: function(evt) {
            // Ambil urutan id terbaru
            const items = [];
            tbody.querySelectorAll('.draggable-row').forEach(row => {
                items.push(row.dataset.id);
            });

            // Kirim ke server (ke manage_classes.php)
            fetch('manage_classes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update_order',
                    type: type,
                    items: items
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('Urutan berhasil diperbarui', 'success');
                    // Perbarui nomor urut tampilan (opsional)
                    updateRowNumbers(tbody);
                } else {
                    showToast('Gagal menyimpan urutan', 'error');
                }
            })
            .catch(err => {
                console.error(err);
                showToast('Terjadi kesalahan', 'error');
            });
        }
    });
    // Simpan instance untuk mencegah inisialisasi ulang
    tbody.sortableInstance = true;
}

// Fungsi untuk memperbarui nomor urut (kolom ke-2)
function updateRowNumbers(tbody) {
    const rows = tbody.querySelectorAll('.draggable-row');
    rows.forEach((row, index) => {
        const td = row.querySelector('td:nth-child(2)'); // kolom No
        if (td) td.innerText = index + 1;
    });
}

// Modal handlers
const modal = document.getElementById('classModal');
function openModal() {
    document.getElementById('formAction').value = 'add';
    document.getElementById('classId').value = '';
    document.getElementById('className').value = '';
    document.getElementById('gradeLevel').value = '';
    document.getElementById('classType').value = 'pagi';
    document.getElementById('homeroomTeacherId').value = '';
    document.getElementById('modalTitle').innerText = 'Tambah Kelas';
    modal.classList.remove('hidden'); modal.classList.add('flex');
}
function closeModal() { modal.classList.add('hidden'); modal.classList.remove('flex'); }
function editClass(id, name, level, type, teacherId) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('classId').value = id;
    document.getElementById('className').value = name;
    document.getElementById('gradeLevel').value = level;
    document.getElementById('classType').value = type;
    document.getElementById('homeroomTeacherId').value = teacherId || '';
    document.getElementById('modalTitle').innerText = 'Edit Kelas';
    modal.classList.remove('hidden'); modal.classList.add('flex');
}
function confirmDelete(id) {
    if (confirm('Yakin hapus kelas ini? Data siswa dan jadwal yang terkait akan terpengaruh.')) {
        const f = document.createElement('form'); f.method = 'POST';
        f.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(f); f.submit();
    }
}

// Inisialisasi Sortable untuk tab yang aktif pertama kali (pagi)
document.addEventListener('DOMContentLoaded', function() {
    const activeTab = document.querySelector('[data-tab].active');
    if (activeTab) {
        const target = activeTab.getAttribute('data-tab');
        const tbody = document.getElementById('tbody-' + target);
        if (tbody) initSortable(tbody, target);
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>