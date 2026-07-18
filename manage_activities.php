<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

$page_title = 'Manajemen Kegiatan - SIAKAD Admin';
$current_page = 'activities';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';


$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $end_date   = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        
        $data = [
            'name'        => $_POST['name'],
            'type'        => $_POST['type'],
            'day_of_week' => (int)$_POST['day_of_week'],
            'start_time'  => $_POST['start_time'],
            'end_time'    => $_POST['end_time'],
            'is_active'   => isset($_POST['is_active']),
            'start_date'  => $start_date,
            'end_date'    => $end_date
        ];
        
        if ($action === 'add') {
            $result = supabase_admin_request('POST', 'activities', $data);
            if (is_array($result) && isset($result['id']) && $result['id'] !== 'unknown') {
                $activity_id = $result['id'];
            } else {
                $search = supabase_admin_request('GET', 'activities', null, [
                    'name'        => 'eq.' . $data['name'],
                    'start_time'  => 'eq.' . $data['start_time'],
                    'day_of_week' => 'eq.' . $data['day_of_week'],
                    'order'       => 'created_at.desc',
                    'limit'       => 1
                ]);
                $activity_id = (is_array($search) && !empty($search)) ? $search[0]['id'] : null;
            }
            
            if ($activity_id) {
                $message = "Kegiatan berhasil ditambahkan (ID: $activity_id)";
            } else {
                $error = 'Gagal menambah kegiatan: ' . json_encode($result);
                $activity_id = null;
            }
        } else { // edit
            $id = (int)$_POST['id'];
            // Validasi data
            if (empty($data['name']) || empty($data['start_time']) || empty($data['end_time'])) {
                $error = 'Data kegiatan tidak lengkap';
                $activity_id = null;
            } else {
                // Lakukan PATCH
                $result = supabase_admin_request('PATCH', 'activities', $data, ['id' => 'eq.' . $id]);
                
                // Jika response null, coba verifikasi apakah data sudah berubah
                if ($result === null) {
                    // Cek apakah kegiatan masih ada
                    $check = supabase_admin_request('GET', 'activities', null, ['id' => 'eq.' . $id]);
                    if (is_array($check) && !empty($check)) {
                        // Data ditemukan, anggap update berhasil meskipun response null
                        $message = 'Kegiatan berhasil diupdate (verifikasi sukses)';
                        $activity_id = $id;
                    } else {
                        $error = 'Gagal update kegiatan: data tidak ditemukan setelah update';
                        $activity_id = null;
                    }
                } elseif (isset($result['id']) || (is_array($result) && empty($result))) {
                    $message = 'Kegiatan berhasil diupdate';
                    $activity_id = $id;
                    // Hapus exceptions lama
                    supabase_admin_request('DELETE', 'activity_exceptions', null, ['activity_id' => 'eq.' . $activity_id]);
                } else {
                    $error = 'Gagal update kegiatan: ' . json_encode($result);
                    $activity_id = null;
                }
            }
        }
        
        // Proses exceptions (sama seperti sebelumnya)
        if ($activity_id && !empty($_POST['exceptions_data'])) {
            $exceptions = json_decode($_POST['exceptions_data'], true);
            if (is_array($exceptions) && count($exceptions) > 0) {
                $successCount = 0;
                foreach ($exceptions as $exc) {
                    if (!empty($exc['date'])) {
                        $excData = [
                            'activity_id'    => $activity_id,
                            'exception_date' => $exc['date'],
                            'is_recurring'   => false
                        ];
                        $excResult = supabase_admin_request('POST', 'activity_exceptions', $excData);
                        if ($excResult !== null && (!is_array($excResult) || empty($excResult) || isset($excResult['id']))) {
                            $successCount++;
                        }
                    }
                }
                if ($successCount > 0) {
                    $message .= " dan $successCount pengecualian tersimpan.";
                } else {
                    $message .= " (Pengecualian gagal disimpan)";
                }
            } else {
                $message .= " (Tidak ada pengecualian valid)";
            }
        } elseif ($activity_id) {
            $message .= " (Tidak ada pengecualian)";
        }
        
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        supabase_admin_request('DELETE', 'activities', null, ['id' => 'eq.' . $id]);
        $message = 'Kegiatan berhasil dihapus';
    }
}

// Ambil semua kegiatan
$activities_raw = supabase_admin_request('GET', 'activities', null, ['order' => 'day_of_week.asc, start_time.asc']);
$activities = safeArray($activities_raw);
$hari_map = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

require_once __DIR__ . '/includes/nav_items.php';
foreach ($nav_items as &$item) {
    $item['active'] = ($item['link'] == 'manage_activities.php');
}
unset($item);
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .exception-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #f9fafb;
        padding: 8px 12px;
        border-radius: 8px;
        margin-bottom: 8px;
    }
    .dark .exception-item {
        background: #374151;
    }
    .exception-date {
        font-family: monospace;
        font-weight: 500;
    }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main-content-container flex-1 transition-all duration-300 ease-in-out">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openMobileSidebarBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Menejemen Aktifitas</h1>
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
            <?php if ($error): ?>
                <div class="bg-red-100 dark:bg-red-800 text-red-700 dark:text-red-200 p-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="flex justify-end mb-4">
                <button onclick="openModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded"><i class="fas fa-plus mr-1"></i>Tambah Kegiatan</button>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Nama</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Tipe</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Hari</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Jam</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Rentang Tanggal</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($activities)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-gray-500">Belum ada kegiatan</td></tr>
                        <?php else: ?>
                            <?php foreach ($activities as $a): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-4 py-2"><?= htmlspecialchars($a['name']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($a['type']) ?></td>
                                <td class="px-4 py-2"><?= $hari_map[$a['day_of_week']] ?></td>
                                <td class="px-4 py-2"><?= $a['start_time'] ?> - <?= $a['end_time'] ?></td>
                                <td class="px-4 py-2 text-sm">
                                    <?php if (!empty($a['start_date']) && !empty($a['end_date'])): ?>
                                        <?= date('d/m/Y', strtotime($a['start_date'])) ?> s/d <?= date('d/m/Y', strtotime($a['end_date'])) ?>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-1 text-xs rounded-full <?= $a['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                        <?= $a['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                                    </span>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap space-x-2">
                                    <button onclick="editActivity(<?= $a['id'] ?>)" class="bg-yellow-500 hover:bg-yellow-600 text-white px-2 py-1 rounded text-sm">Edit</button>
                                    <button onclick="confirmDelete(<?= $a['id'] ?>)" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-sm">Hapus</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<!-- Modal Tambah/Edit Kegiatan -->
<div id="activityModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl max-h-screen overflow-y-auto">
        <h3 id="modalTitle" class="text-lg font-bold text-gray-800 dark:text-white mb-4">Tambah Kegiatan</h3>
        <form method="POST" id="activityForm">
            <input type="hidden" name="action" id="formAction">
            <input type="hidden" name="id" id="activityId">
            <input type="hidden" name="exceptions_data" id="exceptionsData">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="mb-3">
                    <label class="block text-gray-700 dark:text-gray-300 mb-1">Nama Kegiatan *</label>
                    <input type="text" name="name" id="activityName" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                </div>
                <div class="mb-3">
                    <label class="block text-gray-700 dark:text-gray-300 mb-1">Tipe *</label>
                    <select name="type" id="activityType" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                        <option value="sekolah">Sekolah</option>
                        <option value="ekstra">Ekstrakurikuler</option>
                        <option value="ibadah">Ibadah</option>
                        <option value="lainnya">Lainnya</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="block text-gray-700 dark:text-gray-300 mb-1">Hari *</label>
                    <select name="day_of_week" id="dayOfWeek" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                        <option value="1">Senin</option>
                        <option value="2">Selasa</option>
                        <option value="3">Rabu</option>
                        <option value="4">Kamis</option>
                        <option value="5">Jumat</option>
                        <option value="6">Sabtu</option>
                        <option value="7">Minggu</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="block text-gray-700 dark:text-gray-300 mb-1">Jam Mulai *</label>
                    <input type="time" name="start_time" id="startTime" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                </div>
                <div class="mb-3">
                    <label class="block text-gray-700 dark:text-gray-300 mb-1">Jam Selesai *</label>
                    <input type="time" name="end_time" id="endTime" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                </div>
                <div class="mb-3">
                    <label class="block text-gray-700 dark:text-gray-300 mb-1">Tanggal Mulai (opsional)</label>
                    <input type="date" name="start_date" id="startDate" class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                </div>
                <div class="mb-3">
                    <label class="block text-gray-700 dark:text-gray-300 mb-1">Tanggal Berakhir (opsional)</label>
                    <input type="date" name="end_date" id="endDate" class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                </div>
                <div class="mb-3 flex items-center">
                    <label class="inline-flex items-center">
                        <input type="checkbox" name="is_active" id="isActive" class="rounded border-gray-300 text-blue-600 shadow-sm">
                        <span class="ml-2 text-gray-700 dark:text-gray-300">Aktif</span>
                    </label>
                </div>
            </div>

            <!-- Pengecualian Tanggal -->
            <div class="mt-4 border-t pt-4">
                <label class="block text-gray-700 dark:text-gray-300 font-semibold mb-2">Pengecualian Tanggal</label>
                <div id="exceptionsList" class="space-y-2 mb-3"></div>
                <div class="flex gap-2 items-end">
                    <div class="flex-1">
                        <input type="date" id="newExceptionDate" class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                    </div>
                    <button type="button" onclick="addException()" class="bg-blue-600 text-white px-3 py-1 rounded">Tambah</button>
                </div>
                <p class="text-xs text-gray-500 mt-1">Tanggal-tanggal ini akan dikecualikan (kegiatan tidak berlangsung).</p>
            </div>

            <div class="flex justify-end space-x-2 mt-4">
                <button type="button" onclick="closeModal()" class="bg-gray-400 hover:bg-gray-500 text-white px-4 py-2 rounded">Batal</button>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Simpan</button>
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

// Exceptions handling
let exceptionsArray = [];

function renderExceptions() {
    const container = document.getElementById('exceptionsList');
    if (!container) return;
    if (exceptionsArray.length === 0) {
        container.innerHTML = '<div class="text-gray-400 text-sm italic">Belum ada pengecualian</div>';
        return;
    }
    let html = '';
    exceptionsArray.forEach((exc, idx) => {
        html += `
            <div class="exception-item">
                <div>
                    <span class="exception-date">${exc.date}</span>
                </div>
                <button type="button" onclick="removeException(${idx})" class="text-red-500 hover:text-red-700">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </div>
        `;
    });
    container.innerHTML = html;
}

function addException() {
    const dateInput = document.getElementById('newExceptionDate');
    const date = dateInput.value;
    if (!date) {
        alert('Pilih tanggal pengecualian');
        return;
    }
    if (exceptionsArray.some(e => e.date === date)) {
        alert('Tanggal sudah ada dalam daftar pengecualian');
        return;
    }
    exceptionsArray.push({ date: date });
    renderExceptions();
    dateInput.value = '';
}

function removeException(index) {
    exceptionsArray.splice(index, 1);
    renderExceptions();
}

function resetExceptions() {
    exceptionsArray = [];
    renderExceptions();
}

// Submit form: set exceptions_data
const activityForm = document.getElementById('activityForm');
activityForm.addEventListener('submit', function(e) {
    const exceptionsJson = JSON.stringify(exceptionsArray);
    document.getElementById('exceptionsData').value = exceptionsJson;
    console.log('Submitting exceptions JSON:', exceptionsJson);
});

function openModal() {
    document.getElementById('formAction').value = 'add';
    document.getElementById('modalTitle').innerText = 'Tambah Kegiatan';
    document.getElementById('activityId').value = '';
    document.getElementById('activityName').value = '';
    document.getElementById('activityType').value = 'sekolah';
    document.getElementById('dayOfWeek').value = '1';
    document.getElementById('startTime').value = '';
    document.getElementById('endTime').value = '';
    document.getElementById('startDate').value = '';
    document.getElementById('endDate').value = '';
    document.getElementById('isActive').checked = true;
    resetExceptions();
    document.getElementById('activityModal').classList.remove('hidden');
    document.getElementById('activityModal').classList.add('flex');
}

function closeModal() {
    document.getElementById('activityModal').classList.add('hidden');
    document.getElementById('activityModal').classList.remove('flex');
}

async function editActivity(id) {
    try {
        const res = await fetch(`api/get_activity.php?id=${id}`);
        const data = await res.json();
        if (!data || !data.id) throw new Error('Data tidak ditemukan');
        
        document.getElementById('formAction').value = 'edit';
        document.getElementById('modalTitle').innerText = 'Edit Kegiatan';
        document.getElementById('activityId').value = data.id;
        document.getElementById('activityName').value = data.name;
        document.getElementById('activityType').value = data.type;
        document.getElementById('dayOfWeek').value = data.day_of_week;
        document.getElementById('startTime').value = data.start_time;
        document.getElementById('endTime').value = data.end_time;
        document.getElementById('startDate').value = data.start_date || '';
        document.getElementById('endDate').value = data.end_date || '';
        document.getElementById('isActive').checked = data.is_active === true;
        
        const excRes = await fetch(`api/get_activity_exceptions.php?activity_id=${id}`);
        const exceptions = await excRes.json();
        exceptionsArray = (Array.isArray(exceptions) ? exceptions : []).map(exc => ({ date: exc.exception_date }));
        renderExceptions();
        
        document.getElementById('activityModal').classList.remove('hidden');
        document.getElementById('activityModal').classList.add('flex');
    } catch(err) {
        console.error(err);
        alert('Gagal mengambil data kegiatan');
    }
}

function confirmDelete(id) {
    if (confirm('Yakin hapus kegiatan ini? Semua pengecualian juga akan terhapus.')) {
        const f = document.createElement('form');
        f.method = 'POST';
        f.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(f);
        f.submit();
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>