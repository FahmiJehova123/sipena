<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher'])) {
    header('Location: login.php');
    exit;
}
if (time() - $_SESSION['login_time'] > 28800) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['login_time'] = time();

$page_title = 'Input Nilai Ujian Semester - SIAKAP';
$current_page = 'exam_scores';
require_once __DIR__ . '/config.php';

$user_role = $_SESSION['user_role'];
$user_id = $_SESSION['user_id'];

$school_type = isset($_GET['school_type']) ? $_GET['school_type'] : 'pagi';
$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$selected_subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$selected_semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 1;
$selected_academic_year = isset($_GET['academic_year']) ? trim($_GET['academic_year']) : date('Y') . '/' . (date('Y')+1);

// ========== DAFTAR TAHUN AJARAN UNTUK DROPDOWN ==========
$current_year = (int)date('Y');
$academic_years = [];
for ($y = $current_year - 5; $y <= $current_year + 2; $y++) {
    $academic_years[] = $y . '/' . ($y + 1);
}
// Jika tahun ajaran yang dipilih tidak ada dalam daftar (misal dari URL), tambahkan agar tetap tampil
if (!in_array($selected_academic_year, $academic_years)) {
    $academic_years[] = $selected_academic_year;
    sort($academic_years);
}
// ======================================================

// Ambil daftar kelas sesuai role
if ($user_role == 'admin') {
    $classes_raw = supabase_admin_request('GET', 'classes', null, [
        'class_type' => 'eq.' . $school_type,
        'order' => 'grade_level.asc, class_name.asc'
    ]);
} else {
    $classes_raw = supabase_admin_request('GET', 'classes', null, [
        'homeroom_teacher_id' => 'eq.' . $user_id,
        'class_type' => 'eq.' . $school_type,
        'order' => 'grade_level.asc, class_name.asc'
    ]);
}

$classes = [];
if (is_array($classes_raw)) {
    foreach ($classes_raw as $c) {
        if (isset($c['id'])) $classes[] = $c;
    }
}

// Peringatan (warning) bukan error fatal
$warning_message = '';
if ($user_role != 'admin' && empty($classes)) {
    $warning_message = "Anda belum ditugaskan sebagai wali kelas untuk sekolah " . ($school_type == 'pagi' ? 'Pagi' : 'Diniyyah') . ". Silakan pilih sekolah lain jika ada, atau hubungi administrator.";
}

if (empty($classes)) {
    $selected_class_id = 0;
    $class_data = null;
} else {
    if ($selected_class_id == 0) {
        $selected_class_id = $classes[0]['id'];
    }
    $class_data = null;
    foreach ($classes as $c) {
        if ($c['id'] == $selected_class_id) {
            $class_data = $c;
            break;
        }
    }
    if (!$class_data) {
        $selected_class_id = 0;
        $class_data = null;
    }
}

// Ambil siswa
$students = [];
if ($class_data && $selected_class_id > 0) {
    $field_class_id = ($school_type == 'pagi') ? 'kelas_pagi_id' : 'kelas_diniyyah_id';
    $students_raw = supabase_admin_request('GET', 'users', null, [
        'role' => 'eq.student',
        $field_class_id => 'eq.' . $selected_class_id,
        'order' => 'full_name.asc'
    ]);
    if (is_array($students_raw)) {
        foreach ($students_raw as $s) {
            if (isset($s['id'])) $students[] = $s;
        }
    }
}

// Mata pelajaran berdasarkan grade_level kelas
$subjects = [];
if ($class_data) {
    $grade_level = $class_data['grade_level'];
    $subjects_raw = supabase_admin_request('GET', 'subjects', null, [
        'grade_level' => 'eq.' . $grade_level,
        'order' => 'subject_name.asc'
    ]);
    if (is_array($subjects_raw)) {
        foreach ($subjects_raw as $subj) {
            if (isset($subj['id'])) $subjects[] = $subj;
        }
    }
}

// Proses simpan
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_scores') {
    $subject_id = (int)$_POST['subject_id'];
    $semester = (int)$_POST['semester'];
    $academic_year = $_POST['academic_year'];
    $scores = $_POST['score'] ?? [];
    foreach ($scores as $student_id => $score) {
        if ($score === '') continue;
        $score = (float)$score;
        $existing = supabase_admin_request('GET', 'exam_scores', null, [
            'student_id' => 'eq.' . $student_id,
            'subject_id' => 'eq.' . $subject_id,
            'semester' => 'eq.' . $semester,
            'academic_year' => 'eq.' . $academic_year
        ]);
        if (is_array($existing) && count($existing) > 0) {
            $id = $existing[0]['id'];
            supabase_admin_request('PATCH', 'exam_scores', ['score' => $score], ['id' => 'eq.' . $id]);
        } else {
            supabase_admin_request('POST', 'exam_scores', [
                'student_id' => $student_id,
                'subject_id' => $subject_id,
                'semester' => $semester,
                'academic_year' => $academic_year,
                'score' => $score
            ]);
        }
    }
    $message = 'Nilai berhasil disimpan.';
    header("Location: manage_exam_scores.php?school_type=$school_type&class_id=$selected_class_id&subject_id=$subject_id&semester=$semester&academic_year=" . urlencode($academic_year));
    exit;
}

// Ambil nilai existing
$existing_scores = [];
if ($selected_subject_id && $selected_class_id && !empty($students)) {
    $student_ids = array_column($students, 'id');
    $scores_raw = supabase_admin_request('GET', 'exam_scores', null, [
        'subject_id' => 'eq.' . $selected_subject_id,
        'semester' => 'eq.' . $selected_semester,
        'academic_year' => 'eq.' . $selected_academic_year
    ]);
    if (is_array($scores_raw)) {
        foreach ($scores_raw as $sc) {
            if (in_array($sc['student_id'], $student_ids)) {
                $existing_scores[$sc['student_id']] = $sc['score'];
            }
        }
    }
}

require_once __DIR__ . '/includes/nav_items.php';
foreach ($nav_items as &$item) {
    $item['active'] = ($item['link'] == 'manage_exam_scores.php'); // atau 'manage_diploma_scores.php'
}
unset($item);

// Pilih header berdasarkan role
if ($_SESSION['user_role'] == 'admin') {
    require_once __DIR__ . '/includes/header.php';
} else {
    require_once __DIR__ . '/includes/header_user.php';
}
?>

<style>
    .filter-bar { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 20px; }
    .score-table input { width: 80px; padding: 4px; text-align: center; }
    @media (max-width: 640px) { .score-table input { width: 60px; } }
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
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Menejemen Nilai</h1>
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
        <main class="p-4 md:p-6 dark:bg-gray-900 dark:text-white">
            <?php if ($message): ?>
                <div class="bg-green-100 dark:bg-green-800 text-green-700 p-3 rounded mb-4"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($warning_message): ?>
                <div class="bg-yellow-100 dark:bg-yellow-200 text-yellow-700 p-3 rounded mb-4"><?= htmlspecialchars($warning_message) ?></div>
            <?php endif; ?>

            <form method="GET" class="filter-bar">
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Sekolah</label>
                    <select name="school_type" class="border rounded px-3 py-2 dark:bg-gray-700" onchange="this.form.submit()">
                        <option value="pagi" <?= $school_type == 'pagi' ? 'selected' : '' ?>>Pagi</option>
                        <option value="diniyyah" <?= $school_type == 'diniyyah' ? 'selected' : '' ?>>Diniyyah</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Kelas (Wali Kelas)</label>
                    <select name="class_id" class="border rounded px-3 py-2 dark:bg-gray-700" onchange="this.form.submit()">
                        <?php if (empty($classes)): ?>
                            <option value="">-- Tidak ada kelas yang ditugaskan --</option>
                        <?php else: ?>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $selected_class_id == $c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['class_name']) ?> (Kelas <?= $c['grade_level'] ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Mata Pelajaran</label>
                    <select name="subject_id" class="border rounded px-3 py-2 dark:bg-gray-700" onchange="this.form.submit()">
                        <option value="">-- Pilih Mapel --</option>
                        <?php foreach ($subjects as $subj): ?>
                            <option value="<?= $subj['id'] ?>" <?= $selected_subject_id == $subj['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($subj['subject_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Semester</label>
                    <select name="semester" class="border rounded px-3 py-2 dark:bg-gray-700" onchange="this.form.submit()">
                        <option value="1" <?= $selected_semester == 1 ? 'selected' : '' ?>>Ganjil</option>
                        <option value="2" <?= $selected_semester == 2 ? 'selected' : '' ?>>Genap</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Tahun Ajaran</label>
                    <select name="academic_year" class="border rounded px-3 py-2 dark:bg-gray-700" onchange="this.form.submit()">
                        <?php foreach ($academic_years as $year): ?>
                            <option value="<?= $year ?>" <?= $selected_academic_year == $year ? 'selected' : '' ?>><?= $year ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded"><i class="fas fa-eye"></i> Tampilkan</button>
                </div>
            </form>

            <?php if ($selected_subject_id && !empty($students)): ?>
                <form method="POST" class="mt-4">
                    <input type="hidden" name="action" value="save_scores">
                    <input type="hidden" name="subject_id" value="<?= $selected_subject_id ?>">
                    <input type="hidden" name="semester" value="<?= $selected_semester ?>">
                    <input type="hidden" name="academic_year" value="<?= htmlspecialchars($selected_academic_year) ?>">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 score-table">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">No</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Nama Siswa</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">NIS</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Nilai (0-100)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($students as $student): ?>
                                    <tr class="border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <td class="px-4 py-2 whitespace-nowrap"><?= $no++ ?></td>
                                        <td class="px-4 py-2 whitespace-nowrap font-medium"><?= htmlspecialchars($student['full_name']) ?></td>
                                        <td class="px-4 py-2 whitespace-nowrap"><?= htmlspecialchars($student['nidn_or_nisn']) ?></td>
                                        <td class="px-4 py-2 text-center">
                                            <input type="number" step="0.01" min="0" max="100" name="score[<?= $student['id'] ?>]" value="<?= isset($existing_scores[$student['id']]) ? number_format($existing_scores[$student['id']], 2) : '' ?>" class="border rounded px-2 py-1 dark:bg-gray-700 dark:text-white w-24">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4 flex justify-end">
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded shadow"><i class="fas fa-save mr-1"></i> Simpan Semua Nilai</button>
                    </div>
                </form>
            <?php elseif ($selected_subject_id && empty($students) && $class_data): ?>
                <div class="bg-yellow-100 dark:bg-yellow-800 text-yellow-700 p-4 rounded">Tidak ada siswa di kelas ini. Pastikan siswa memiliki <?= $school_type == 'pagi' ? 'kelas_pagi_id' : 'kelas_diniyyah_id' ?> yang sesuai.</div>
            <?php elseif (!$selected_subject_id && $class_data): ?>
                <div class="bg-blue-100 dark:bg-blue-800 text-blue-700 p-4 rounded">Silakan pilih mata pelajaran terlebih dahulu.</div>
            <?php elseif (empty($classes)): ?>
                <div class="bg-gray-100 dark:bg-gray-700 p-4 rounded text-center">Anda belum ditugaskan sebagai wali kelas untuk sekolah ini. Silakan pilih sekolah lain atau hubungi administrator.</div>
            <?php endif; ?>
        </main>
    </div>
</div>

<script>
const darkModeToggle = document.getElementById('darkModeToggle');
function setDarkMode(isDark) {
    if (isDark) document.documentElement.classList.add('dark');
    else document.documentElement.classList.remove('dark');
    localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled');
}
const saved = localStorage.getItem('darkMode');
if (saved === 'enabled') setDarkMode(true);
else if (saved !== 'disabled') setDarkMode(window.matchMedia('(prefers-color-scheme: dark)').matches);
darkModeToggle?.addEventListener('click', () => setDarkMode(!document.documentElement.classList.contains('dark')));
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>