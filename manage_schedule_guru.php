<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'teacher') {
    header('Location: login.php');
    exit;
}
if (time() - $_SESSION['login_time'] > 28800) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['login_time'] = time();

$page_title = 'Manajemen Jadwal Saya - SIAKAD';
$current_page = 'manage_schedule_guru';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Ambil data guru
$guru_data = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $user_id]);
$guru = (is_array($guru_data) && !empty($guru_data)) ? $guru_data[0] : null;
if (!$guru) { header('Location: logout.php'); exit; }

// Ambil semua kelas dan mata pelajaran (termasuk grade_level)
$all_classes = safeArray(supabase_admin_request('GET', 'classes'));
$subjects = safeArray(supabase_admin_request('GET', 'subjects'));

// Kirim data kelas dan mata pelajaran ke JavaScript untuk filter
$classes_json = json_encode($all_classes);
$subjects_json = json_encode($subjects);

// Ambil total jadwal milik guru ini (untuk pagination)
$total_schedules_raw = supabase_admin_request('GET', 'schedules', null, [
    'teacher_id' => 'eq.' . $user_id,
    'select' => 'id'
]);
$total_schedules = is_array($total_schedules_raw) ? count($total_schedules_raw) : 0;
$total_pages = ceil($total_schedules / $per_page);

// Ambil jadwal milik guru ini dengan pagination
$schedules_raw = supabase_admin_request('GET', 'schedules', null, [
    'teacher_id' => 'eq.' . $user_id,
    'order' => 'day_of_week.asc, start_time.asc',
    'limit' => $per_page,
    'offset' => $offset
]);
$schedules = safeArray($schedules_raw);

// Dapatkan daftar class_id unik yang diajar oleh guru ini (untuk dropdown)
$teacher_class_ids = [];
foreach ($schedules as $sch) {
    $teacher_class_ids[] = $sch['class_id'];
}
$teacher_class_ids = array_unique($teacher_class_ids);

// Filter kelas hanya yang diajar (untuk dropdown)
$classes_for_dropdown = [];
if (!empty($teacher_class_ids)) {
    $classes_for_dropdown = array_filter($all_classes, function($c) use ($teacher_class_ids) {
        return in_array($c['id'], $teacher_class_ids);
    });
    $classes_for_dropdown = array_values($classes_for_dropdown);
} else {
    // Jika belum ada jadwal, guru bisa memilih semua kelas (atau kosong)
    $classes_for_dropdown = $all_classes;
}

// Proses CRUD (sama seperti sebelumnya)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $selected_class_id = (int)$_POST['class_id'];
        if (!empty($teacher_class_ids) && !in_array($selected_class_id, $teacher_class_ids)) {
            $error = 'Anda hanya dapat menambah jadwal untuk kelas yang sudah pernah diajar.';
        } else {
            $data = [
                'class_id' => $selected_class_id,
                'subject_id' => (int)$_POST['subject_id'],
                'teacher_id' => $user_id,
                'day_of_week' => (int)$_POST['day_of_week'],
                'start_time' => $_POST['start_time'],
                'end_time' => $_POST['end_time'],
                'academic_year' => $_POST['academic_year'],
                'semester' => (int)$_POST['semester']
            ];
            $result = supabase_admin_request('POST', 'schedules', $data);
            if (isset($result['id'])) {
                $message = 'Jadwal berhasil ditambahkan';
                header('Location: manage_schedule_guru.php?page=1');
                exit;
            } else {
                $error = 'Gagal menambah jadwal: ' . json_encode($result);
            }
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $check = supabase_admin_request('GET', 'schedules', null, ['id' => 'eq.' . $id, 'teacher_id' => 'eq.' . $user_id]);
        if (empty($check)) {
            $error = 'Anda tidak memiliki akses ke jadwal ini.';
        } else {
            $data = [
                'class_id' => (int)$_POST['class_id'],
                'subject_id' => (int)$_POST['subject_id'],
                'day_of_week' => (int)$_POST['day_of_week'],
                'start_time' => $_POST['start_time'],
                'end_time' => $_POST['end_time'],
                'academic_year' => $_POST['academic_year'],
                'semester' => (int)$_POST['semester']
            ];
            $result = supabase_admin_request('PATCH', 'schedules', $data, ['id' => 'eq.' . $id]);
            if (isset($result['id']) || (is_array($result) && empty($result))) {
                $message = 'Jadwal berhasil diupdate';
                header('Location: manage_schedule_guru.php?page=' . $page);
                exit;
            } else {
                $error = 'Gagal update jadwal: ' . json_encode($result);
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $check = supabase_admin_request('GET', 'schedules', null, ['id' => 'eq.' . $id, 'teacher_id' => 'eq.' . $user_id]);
        if (empty($check)) {
            $error = 'Anda tidak memiliki akses ke jadwal ini.';
        } else {
            supabase_admin_request('DELETE', 'schedules', null, ['id' => 'eq.' . $id]);
            $message = 'Jadwal berhasil dihapus';
            header('Location: manage_schedule_guru.php?page=' . $page);
            exit;
        }
    }
}

// Tambahkan nama kelas dan mapel ke setiap jadwal
foreach ($schedules as &$s) {
    $class_name = '-';
    foreach ($all_classes as $c) if ($c['id'] == $s['class_id']) { $class_name = $c['class_name']; break; }
    $subject_name = '-';
    foreach ($subjects as $sub) if ($sub['id'] == $s['subject_id']) { $subject_name = $sub['subject_name']; break; }
    $s['class_name'] = $class_name;
    $s['subject_name'] = $subject_name;
}
unset($s);

$hari_map = [1=>'Senin',2=>'Selasa',3=>'Rabu',4=>'Kamis',5=>'Jumat',6=>'Sabtu',7=>'Minggu'];

require_once __DIR__ . '/includes/header_user.php';
?>

<style>
    .schedule-table th, .schedule-table td { padding: 12px 8px; vertical-align: middle; }
    @media (max-width: 768px) {
        .schedule-table th, .schedule-table td { padding: 8px 4px; font-size: 0.8rem; }
    }
    .pagination { display: flex; justify-content: center; gap: 0.5rem; margin-top: 1.5rem; }
    .pagination a, .pagination span { padding: 0.5rem 0.75rem; border-radius: 0.375rem; background-color: #e5e7eb; color: #1f2937; text-decoration: none; }
    .pagination .active { background-color: #3b82f6; color: white; }
    .pagination a:hover { background-color: #9ca3af; }
    .dark .pagination a, .dark .pagination span { background-color: #374151; color: #e5e7eb; }
    .dark .pagination .active { background-color: #3b82f6; }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar_user.php'; ?>
    
    <div class="main-content-container flex-1 transition-all duration-300 ease-in-out">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openSidebarUserBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden" onclick="document.getElementById('sidebarUser').classList.remove('-translate-x-full'); document.getElementById('sidebarUser').classList.add('translate-x-0'); document.getElementById('sidebarOverlayUser').classList.remove('hidden'); document.body.classList.add('sidebar-open'); return false;">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Manajemen Jadwal Saya</h1>
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

        <main class="p-4 md:p-6 dark:bg-gray-900 transition-colors">
            <?php if ($message): ?>
                <div class="bg-green-100 dark:bg-green-800 text-green-700 dark:text-green-200 p-3 rounded mb-4"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="bg-red-100 dark:bg-red-800 text-red-700 dark:text-red-200 p-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="flex justify-end mb-4">
                <button onclick="openModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded"><i class="fas fa-plus mr-1"></i> Tambah Jadwal</button>
            </div>

            <div class="bg-white dark:bg-gray-800 dark:text-white rounded-xl shadow overflow-x-auto">
                <table class="min-w-full schedule-table">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Kelas</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Mata Pelajaran</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Hari</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Jam Mulai</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Jam Selesai</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Tahun Ajaran</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Semester</th>
                            <th class="px-4 py-3 text-center text-xs font-medium uppercase">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php if (empty($schedules)): ?>
                            <tr><td colspan="8" class="text-center py-4 text-gray-500">Belum ada jadwal. Silakan tambah jadwal.</td></tr>
                        <?php else: ?>
                            <?php foreach ($schedules as $s): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-4 py-2"><?= htmlspecialchars($s['class_name']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($s['subject_name']) ?></td>
                                <td class="px-4 py-2"><?= $hari_map[$s['day_of_week']] ?> (<?= $s['day_of_week'] ?>)</td>
                                <td class="px-4 py-2"><?= $s['start_time'] ?></td>
                                <td class="px-4 py-2"><?= $s['end_time'] ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($s['academic_year']) ?></td>
                                <td class="px-4 py-2"><?= $s['semester'] ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-center space-x-1">
                                    <button onclick="editSchedule(<?= $s['id'] ?>)" class="bg-yellow-500 hover:bg-yellow-600 text-white px-2 py-1 rounded text-sm"><i class="fas fa-edit"></i> Edit</button>
                                    <button onclick="confirmDelete(<?= $s['id'] ?>)" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-sm"><i class="fas fa-trash"></i> Hapus</button>
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
                    <a href="?page=<?= $page-1 ?>">« Prev</a>
                <?php else: ?>
                    <span class="opacity-50">« Prev</span>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?page=<?= $i ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page+1 ?>">Next »</a>
                <?php else: ?>
                    <span class="opacity-50">Next »</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Modal Tambah/Edit Jadwal -->
<div id="scheduleModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md max-h-screen overflow-y-auto">
        <h3 id="modalTitle" class="text-lg font-bold text-gray-800 dark:text-white mb-4">Tambah Jadwal</h3>
        <form method="POST" id="scheduleForm">
            <input type="hidden" name="action" id="formAction">
            <input type="hidden" name="id" id="scheduleId">
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Kelas</label>
                <select name="class_id" id="classId" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                    <option value="">Pilih Kelas</option>
                    <?php if (empty($classes_for_dropdown)): ?>
                        <option value="" disabled>Belum ada kelas yang diajar</option>
                    <?php else: ?>
                        <?php foreach ($classes_for_dropdown as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <?php if (empty($classes_for_dropdown)): ?>
                    <small class="text-amber-600 text-xs">Anda belum memiliki jadwal. Hubungi admin untuk menambahkan kelas pertama kali.</small>
                <?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Mata Pelajaran</label>
                <select name="subject_id" id="subjectId" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                    <option value="">-- Pilih Mata Pelajaran --</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Hari</label>
                <select name="day_of_week" id="dayOfWeek" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                    <option value="">Pilih Hari</option>
                    <option value="1">Senin</option><option value="2">Selasa</option><option value="3">Rabu</option><option value="4">Kamis</option><option value="5">Jumat</option><option value="6">Sabtu</option><option value="7">Minggu</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Jam Mulai</label>
                <input type="time" name="start_time" id="startTime" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Jam Selesai</label>
                <input type="time" name="end_time" id="endTime" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Tahun Ajaran</label>
                <input type="text" name="academic_year" id="academicYear" placeholder="2024/2025" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Semester</label>
                <select name="semester" id="semester" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                    <option value="1">Semester 1</option><option value="2">Semester 2</option>
                </select>
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
        if (moon && sun) { moon.classList.toggle('hidden', isDark); sun.classList.toggle('hidden', !isDark); }
    }
}
const saved = localStorage.getItem('darkMode');
if (saved === 'enabled') setDarkMode(true);
else if (saved === 'disabled') setDarkMode(false);
else setDarkMode(window.matchMedia('(prefers-color-scheme: dark)').matches);
darkModeToggle?.addEventListener('click', () => setDarkMode(!document.documentElement.classList.contains('dark')));

// Data kelas dan mata pelajaran dari server
const allClasses = <?= $classes_json ?>;
const allSubjects = <?= $subjects_json ?>;

// Fungsi untuk mendapatkan grade_level dari suatu kelas
function getGradeLevelByClassId(classId) {
    const found = allClasses.find(c => c.id == classId);
    return found ? found.grade_level : null;
}

// Fungsi untuk memfilter mata pelajaran berdasarkan grade_level
function filterSubjectsByGrade(gradeLevel) {
    if (!gradeLevel) return [];
    return allSubjects.filter(sub => sub.grade_level === gradeLevel);
}

// Fungsi untuk mengisi dropdown mata pelajaran
function populateSubjectsDropdown(gradeLevel) {
    const subjectSelect = document.getElementById('subjectId');
    if (!subjectSelect) return;
    // Kosongkan dropdown, tambahkan opsi default
    subjectSelect.innerHTML = '<option value="">-- Pilih Mata Pelajaran --</option>';
    if (!gradeLevel) {
        subjectSelect.disabled = true;
        subjectSelect.innerHTML = '<option value="">Pilih kelas terlebih dahulu</option>';
        return;
    }
    const filtered = filterSubjectsByGrade(gradeLevel);
    if (filtered.length === 0) {
        subjectSelect.disabled = true;
        subjectSelect.innerHTML = '<option value="" disabled>Tidak ada mata pelajaran untuk tingkat ini</option>';
        return;
    }
    filtered.forEach(sub => {
        const option = document.createElement('option');
        option.value = sub.id;
        option.textContent = `${sub.subject_name} (${sub.subject_code}) - Tingkat ${sub.grade_level}`;
        subjectSelect.appendChild(option);
    });
    subjectSelect.disabled = false;
}

// Event listener untuk dropdown kelas
const classSelect = document.getElementById('classId');
if (classSelect) {
    classSelect.addEventListener('change', function() {
        const classId = this.value;
        if (!classId) {
            populateSubjectsDropdown(null);
            return;
        }
        const gradeLevel = getGradeLevelByClassId(classId);
        populateSubjectsDropdown(gradeLevel);
    });
}

// Modal handler
const modal = document.getElementById('scheduleModal');
function openModal() {
    document.getElementById('formAction').value = 'add';
    document.getElementById('modalTitle').innerText = 'Tambah Jadwal';
    document.getElementById('scheduleId').value = '';
    document.getElementById('classId').value = '';
    document.getElementById('dayOfWeek').value = '';
    document.getElementById('startTime').value = '';
    document.getElementById('endTime').value = '';
    document.getElementById('academicYear').value = '';
    document.getElementById('semester').value = '1';
    // Reset dropdown mata pelajaran
    populateSubjectsDropdown(null);
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closeModal() {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function editSchedule(id) {
    fetch(`api/get_schedule.php?id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data && data.id && data.teacher_id === '<?= $user_id ?>') {
                document.getElementById('formAction').value = 'edit';
                document.getElementById('modalTitle').innerText = 'Edit Jadwal';
                document.getElementById('scheduleId').value = data.id;
                document.getElementById('classId').value = data.class_id;
                // Setelah mengisi kelas, panggil filter mata pelajaran
                const gradeLevel = getGradeLevelByClassId(data.class_id);
                populateSubjectsDropdown(gradeLevel);
                // Set nilai subject_id setelah dropdown terisi
                setTimeout(() => {
                    document.getElementById('subjectId').value = data.subject_id;
                }, 50);
                document.getElementById('dayOfWeek').value = data.day_of_week;
                document.getElementById('startTime').value = data.start_time;
                document.getElementById('endTime').value = data.end_time;
                document.getElementById('academicYear').value = data.academic_year;
                document.getElementById('semester').value = data.semester;
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            } else {
                alert('Data jadwal tidak ditemukan atau bukan milik Anda.');
            }
        })
        .catch(err => console.error(err));
}

function confirmDelete(id) {
    if (confirm('Yakin hapus jadwal ini?')) {
        const f = document.createElement('form');
        f.method = 'POST';
        f.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(f);
        f.submit();
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer_user.php'; ?>