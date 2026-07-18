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

$page_title = 'Manajemen Jadwal - SIAKAD Admin';
$current_page = 'schedules';
require_once __DIR__ . '/config.php';

// Proses form (tambah, edit, hapus)
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $data = [
            'class_id' => (int)$_POST['class_id'],
            'subject_id' => (int)$_POST['subject_id'],
            'teacher_id' => $_POST['teacher_id'],
            'day_of_week' => (int)$_POST['day_of_week'],
            'start_time' => $_POST['start_time'],
            'end_time' => $_POST['end_time'],
            'academic_year' => $_POST['academic_year'],
            'semester' => (int)$_POST['semester']
        ];
        $result = supabase_admin_request('POST', 'schedules', $data);
        if (isset($result['id'])) {
            $message = 'Jadwal berhasil ditambahkan';
        } else {
            $message = 'Gagal menambah jadwal: ' . json_encode($result);
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $data = [
            'class_id' => (int)$_POST['class_id'],
            'subject_id' => (int)$_POST['subject_id'],
            'teacher_id' => $_POST['teacher_id'],
            'day_of_week' => (int)$_POST['day_of_week'],
            'start_time' => $_POST['start_time'],
            'end_time' => $_POST['end_time'],
            'academic_year' => $_POST['academic_year'],
            'semester' => (int)$_POST['semester']
        ];
        $result = supabase_admin_request('PATCH', 'schedules', $data, ['id' => 'eq.' . $id]);
        if (isset($result['id']) || (is_array($result) && empty($result))) {
            $message = 'Jadwal berhasil diupdate';
        } else {
            $message = 'Gagal update jadwal: ' . json_encode($result);
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        supabase_admin_request('DELETE', 'schedules', null, ['id' => 'eq.' . $id]);
        $message = 'Jadwal berhasil dihapus';
    }
}

// Ambil data referensi
$classes_raw = supabase_admin_request('GET', 'classes');
$classes = is_array($classes_raw) ? $classes_raw : [];

$subjects_raw = supabase_admin_request('GET', 'subjects');
$subjects = is_array($subjects_raw) ? $subjects_raw : [];

$teachers_raw = supabase_admin_request('GET', 'users', null, ['role' => 'eq.teacher']);
$teachers = is_array($teachers_raw) ? $teachers_raw : [];

// Ambil data jadwal
$schedules_raw = supabase_admin_request('GET', 'schedules', null, ['order' => 'day_of_week.asc, start_time.asc']);
$schedules_temp = is_array($schedules_raw) ? $schedules_raw : [];

// Proses relasi untuk tampilan tabel
$schedules = [];
foreach ($schedules_temp as $s) {
    // Cari nama kelas
    $class_name = '-';
    foreach ($classes as $c) {
        if ($c['id'] == $s['class_id']) {
            $class_name = $c['class_name'];
            break;
        }
    }
    // Cari nama mata pelajaran
    $subject_name = '-';
    foreach ($subjects as $sub) {
        if ($sub['id'] == $s['subject_id']) {
            $subject_name = $sub['subject_name'];
            break;
        }
    }
    // Cari nama guru
    $teacher_name = '-';
    foreach ($teachers as $t) {
        if ($t['id'] == $s['teacher_id']) {
            $teacher_name = $t['full_name'];
            break;
        }
    }
    $schedules[] = [
        'id' => $s['id'],
        'class_name' => $class_name,
        'subject_name' => $subject_name,
        'teacher_name' => $teacher_name,
        'day_of_week' => $s['day_of_week'],
        'start_time' => $s['start_time'],
        'end_time' => $s['end_time'],
        'academic_year' => $s['academic_year'],
        'semester' => $s['semester']
    ];
}

// Navigasi sidebar
require_once __DIR__ . '/includes/nav_items.php';

$is_teacher = (isset($_GET['role']) && $_GET['role'] == 'teacher');
foreach ($nav_items as &$item) {
    if ($is_teacher && $item['link'] == 'manage_users.php?role=teacher') {
        $item['active'] = true;
    } elseif (!$is_teacher && $item['link'] == 'manage_users.php?role=student') {
        $item['active'] = true;
    } else {
        $item['active'] = false;
    }
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
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Manajemen Jadwal</h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                        <i class="fas fa-moon text-xl dark:hidden"></i>
                        <i class="fas fa-sun text-xl hidden dark:inline"></i>
                    </button>
                    <div class="relative group">
                        <button class="flex items-center space-x-2 focus:outline-none">
                            <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold">A</div>
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

            <div class="flex justify-end mb-4">
                <button onclick="openAddModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded"><i class="fas fa-plus mr-1"></i>Tambah Jadwal</button>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Kelas</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Mata Pelajaran</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Guru</th>
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
                            <tr><td colspan="9" class="text-center py-4 text-gray-500">Belum ada jadwal</td></tr>
                        <?php else: ?>
                            <?php foreach ($schedules as $s): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="px-4 py-3"><?= htmlspecialchars($s['class_name']) ?></td>
                                    <td class="px-4 py-3"><?= htmlspecialchars($s['subject_name']) ?></td>
                                    <td class="px-4 py-3"><?= htmlspecialchars($s['teacher_name']) ?></td>
                                    <td class="px-4 py-3">
                                        <?php 
                                            $hariMap = [1=>'Senin',2=>'Selasa',3=>'Rabu',4=>'Kamis',5=>'Jumat',6=>'Sabtu',7=>'Minggu'];
                                            echo $hariMap[$s['day_of_week']] ?? $s['day_of_week'];
                                        ?>
                                    </td>
                                    <td class="px-4 py-3"><?= $s['start_time'] ?></td>
                                    <td class="px-4 py-3"><?= $s['end_time'] ?></td>
                                    <td class="px-4 py-3"><?= htmlspecialchars($s['academic_year']) ?></td>
                                    <td class="px-4 py-3"><?= $s['semester'] ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap text-center space-x-1">
                                        <button onclick="viewSchedule(<?= $s['id'] ?>)" class="bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded text-xs" title="Lihat"><i class="fas fa-eye"></i></button>
                                        <button onclick="editSchedule(<?= $s['id'] ?>)" class="bg-yellow-500 hover:bg-yellow-600 text-white px-2 py-1 rounded text-xs" title="Edit"><i class="fas fa-edit"></i></button>
                                        <button onclick="confirmDelete(<?= $s['id'] ?>)" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs" title="Hapus"><i class="fas fa-trash"></i></button>
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
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Mata Pelajaran</label>
                <select name="subject_id" id="subjectId" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                    <option value="">Pilih Mata Pelajaran</option>
                    <?php foreach ($subjects as $sub): ?>
                        <option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['subject_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Guru</label>
                <select name="teacher_id" id="teacherId" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                    <option value="">Pilih Guru</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Hari</label>
                <select name="day_of_week" id="dayOfWeek" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                    <option value="">Pilih Hari</option>
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
                    <option value="1">Semester 1</option>
                    <option value="2">Semester 2</option>
                </select>
            </div>
            <div class="flex justify-end space-x-2 mt-4">
                <button type="button" onclick="closeModal()" class="bg-gray-400 hover:bg-gray-500 text-white px-4 py-2 rounded">Batal</button>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Detail (Lihat Jadwal) -->
<div id="viewModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Detail Jadwal</h3>
        <div class="space-y-2 text-gray-700 dark:text-gray-300">
            <p><strong>Kelas:</strong> <span id="viewClass"></span></p>
            <p><strong>Mata Pelajaran:</strong> <span id="viewSubject"></span></p>
            <p><strong>Guru:</strong> <span id="viewTeacher"></span></p>
            <p><strong>Hari:</strong> <span id="viewDay"></span></p>
            <p><strong>Jam Mulai:</strong> <span id="viewStartTime"></span></p>
            <p><strong>Jam Selesai:</strong> <span id="viewEndTime"></span></p>
            <p><strong>Tahun Ajaran:</strong> <span id="viewAcademicYear"></span></p>
            <p><strong>Semester:</strong> <span id="viewSemester"></span></p>
        </div>
        <div class="flex justify-end mt-4">
            <button type="button" onclick="closeViewModal()" class="bg-gray-400 hover:bg-gray-500 text-white px-4 py-2 rounded">Tutup</button>
        </div>
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

// Modal
const modal = document.getElementById('scheduleModal');
function openAddModal() {
    document.getElementById('formAction').value = 'add';
    document.getElementById('modalTitle').innerText = 'Tambah Jadwal';
    document.getElementById('scheduleId').value = '';
    document.getElementById('classId').value = '';
    document.getElementById('subjectId').value = '';
    document.getElementById('teacherId').value = '';
    document.getElementById('dayOfWeek').value = '';
    document.getElementById('startTime').value = '';
    document.getElementById('endTime').value = '';
    document.getElementById('academicYear').value = '';
    document.getElementById('semester').value = '1';
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
            if (data && data.id) {
                document.getElementById('formAction').value = 'edit';
                document.getElementById('modalTitle').innerText = 'Edit Jadwal';
                document.getElementById('scheduleId').value = data.id;
                document.getElementById('classId').value = data.class_id;
                document.getElementById('subjectId').value = data.subject_id;
                document.getElementById('teacherId').value = data.teacher_id;
                document.getElementById('dayOfWeek').value = data.day_of_week;
                document.getElementById('startTime').value = data.start_time;
                document.getElementById('endTime').value = data.end_time;
                document.getElementById('academicYear').value = data.academic_year;
                document.getElementById('semester').value = data.semester;
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            } else {
                alert('Data jadwal tidak ditemukan');
            }
        })
        .catch(err => console.error(err));
}

function viewSchedule(id) {
    fetch(`api/get_schedule.php?id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data && data.id) {
                const hariMap = {1:'Senin',2:'Selasa',3:'Rabu',4:'Kamis',5:'Jumat',6:'Sabtu',7:'Minggu'};
                document.getElementById('viewClass').innerText = data.class_name || '-';
                document.getElementById('viewSubject').innerText = data.subject_name || '-';
                document.getElementById('viewTeacher').innerText = data.teacher_name || '-';
                document.getElementById('viewDay').innerText = hariMap[data.day_of_week] || data.day_of_week;
                document.getElementById('viewStartTime').innerText = data.start_time;
                document.getElementById('viewEndTime').innerText = data.end_time;
                document.getElementById('viewAcademicYear').innerText = data.academic_year;
                document.getElementById('viewSemester').innerText = data.semester;
                document.getElementById('viewModal').classList.remove('hidden');
                document.getElementById('viewModal').classList.add('flex');
            } else {
                alert('Data jadwal tidak ditemukan');
            }
        })
        .catch(err => console.error(err));
}
function closeViewModal() {
    document.getElementById('viewModal').classList.add('hidden');
    document.getElementById('viewModal').classList.remove('flex');
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>