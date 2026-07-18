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

$page_title = 'Input Nilai Ijazah - SIPENA';
$current_page = 'diploma_scores';
require_once __DIR__ . '/config.php';

$user_role = $_SESSION['user_role'];
$user_id = $_SESSION['user_id'];

$school_type = isset($_GET['school_type']) ? $_GET['school_type'] : 'pagi';
$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$selected_subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$selected_graduation_year = isset($_GET['graduation_year']) ? (int)$_GET['graduation_year'] : date('Y');

// Ambil daftar kelas
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

$warning_message = '';
if ($user_role != 'admin' && empty($classes)) {
    $warning_message = "Anda belum ditugaskan sebagai wali kelas untuk sekolah " . ($school_type == 'pagi' ? 'Pagi' : 'Diniyyah') . ". Silakan pilih sekolah lain jika ada, atau hubungi administrator.";
}

if (empty($classes)) {
    $selected_class_id = 0;
    $class_data = null;
} else {
    if ($selected_class_id == 0) $selected_class_id = $classes[0]['id'];
    $class_data = null;
    foreach ($classes as $c) {
        if ($c['id'] == $selected_class_id) { $class_data = $c; break; }
    }
    if (!$class_data) { $selected_class_id = 0; $class_data = null; }
}

// Siswa
$students = [];
if ($class_data && $selected_class_id > 0) {
    $field_class_id = ($school_type == 'pagi') ? 'kelas_pagi_id' : 'kelas_diniyyah_id';
    $students_raw = supabase_admin_request('GET', 'users', null, [
        'role' => 'eq.student',
        $field_class_id => 'eq.' . $selected_class_id,
        'order' => 'full_name.asc'
    ]);
    if (is_array($students_raw)) {
        foreach ($students_raw as $s) if (isset($s['id'])) $students[] = $s;
    }
}

// Mata pelajaran
$subjects = [];
if ($class_data) {
    $grade_level = $class_data['grade_level'];
    $subjects_raw = supabase_admin_request('GET', 'subjects', null, [
        'grade_level' => 'eq.' . $grade_level,
        'order' => 'subject_name.asc'
    ]);
    if (is_array($subjects_raw)) {
        foreach ($subjects_raw as $subj) if (isset($subj['id'])) $subjects[] = $subj;
    }
}

// Proses simpan (dengan class_type otomatis dari school_type)
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_diploma') {
    $subject_id = (int)$_POST['subject_id'];
    $graduation_year = (int)$_POST['graduation_year'];
    $class_type = $school_type; // <-- otomatis dari pilihan sekolah
    $scores = $_POST['score'] ?? [];
    foreach ($scores as $student_id => $score) {
        if ($score === '') continue;
        $score = (float)$score;
        // Cek existing berdasarkan student, subject, year, dan class_type
        $existing = supabase_admin_request('GET', 'diploma_scores', null, [
            'student_id' => 'eq.' . $student_id,
            'subject_id' => 'eq.' . $subject_id,
            'graduation_year' => 'eq.' . $graduation_year,
            'class_type' => 'eq.' . $class_type
        ]);
        if (is_array($existing) && count($existing) > 0) {
            // Update nilai dan pastikan class_type tetap (meskipun sudah sama)
            supabase_admin_request('PATCH', 'diploma_scores', [
                'score' => $score,
                'class_type' => $class_type
            ], ['id' => 'eq.' . $existing[0]['id']]);
        } else {
            // Insert data baru
            supabase_admin_request('POST', 'diploma_scores', [
                'student_id' => $student_id,
                'subject_id' => $subject_id,
                'score' => $score,
                'graduation_year' => $graduation_year,
                'class_type' => $class_type
            ]);
        }
    }
    $message = 'Nilai ijazah disimpan.';
    header("Location: manage_diploma_scores.php?school_type=$school_type&class_id=$selected_class_id&subject_id=$subject_id&graduation_year=$graduation_year");
    exit;
}

// Ambil nilai existing (juga filter berdasarkan class_type)
$existing_scores = [];
if ($selected_subject_id && $selected_class_id && !empty($students)) {
    $student_ids = array_column($students, 'id');
    $scores_raw = supabase_admin_request('GET', 'diploma_scores', null, [
        'subject_id' => 'eq.' . $selected_subject_id,
        'graduation_year' => 'eq.' . $selected_graduation_year,
        'class_type' => 'eq.' . $school_type // filter class_type!
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
    $item['active'] = ($item['link'] == 'manage_diploma_scores.php');
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
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Menejemen Nilai Ijazah</h1>
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
            <?php if ($warning_message): ?>
                <div class="bg-yellow-100 dark:bg-yellow-800 text-yellow-700 p-3 rounded mb-4"><?= htmlspecialchars($warning_message) ?></div>
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
                            <option value="<?= $subj['id'] ?>" <?= $selected_subject_id == $subj['id'] ? 'selected' : '' ?>><?= htmlspecialchars($subj['subject_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Tahun Lulus</label>
                    <input type="number" name="graduation_year" value="<?= $selected_graduation_year ?>" class="border rounded px-3 py-2 dark:bg-gray-700 w-32" onchange="this.form.submit()">
                </div>
                <div>
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded"><i class="fas fa-eye"></i> Tampilkan</button>
                </div>
            </form>

            <?php if ($selected_subject_id && !empty($students)): ?>
                <form method="POST" class="mt-4">
                    <input type="hidden" name="action" value="save_diploma">
                    <input type="hidden" name="subject_id" value="<?= $selected_subject_id ?>">
                    <input type="hidden" name="graduation_year" value="<?= $selected_graduation_year ?>">
                    <!-- class_type akan diambil dari $school_type saat POST -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 score-table">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-4 py-3 text-left">No</th>
                                    <th class="px-4 py-3 text-left">Nama Siswa</th>
                                    <th class="px-4 py-3 text-left">NIS</th>
                                    <th class="px-4 py-3 text-center">Nilai (0-100)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($students as $student): ?>
                                    <tr>
                                        <td class="px-4 py-2"><?= $no++ ?></td>
                                        <td class="px-4 py-2 font-medium"><?= htmlspecialchars($student['full_name']) ?></td>
                                        <td class="px-4 py-2"><?= htmlspecialchars($student['nidn_or_nisn']) ?></td>
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
                <div class="bg-yellow-100 p-4 rounded">Tidak ada siswa di kelas ini. Pastikan siswa memiliki <?= $school_type == 'pagi' ? 'kelas_pagi_id' : 'kelas_diniyyah_id' ?> yang sesuai.</div>
            <?php elseif (!$selected_subject_id && $class_data): ?>
                <div class="bg-blue-100 p-4 rounded">Silakan pilih mata pelajaran terlebih dahulu.</div>
            <?php elseif (empty($classes)): ?>
                <div class="bg-gray-100 p-4 rounded text-center">Anda belum ditugaskan sebagai wali kelas untuk sekolah ini. Silakan pilih sekolah lain atau hubungi administrator.</div>
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