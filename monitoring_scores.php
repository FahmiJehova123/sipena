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

$page_title = 'Monitoring Input Nilai - SIAKAP';
$current_page = 'monitoring';
require_once __DIR__ . '/config.php';

$user_role = $_SESSION['user_role'];
$user_id = $_SESSION['user_id'];

// ========== Filter Sekolah ==========
$school_type = isset($_GET['school_type']) ? $_GET['school_type'] : 'pagi';

// Tab aktif
$active_tab = isset($_GET['tab']) && $_GET['tab'] == 'ijazah' ? 'ijazah' : 'semester';

// Filter untuk tab semester
$selected_semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 1;
$selected_academic_year = isset($_GET['academic_year']) ? trim($_GET['academic_year']) : date('Y') . '/' . (date('Y')+1);

// Filter untuk tab ijazah
$selected_graduation_year = isset($_GET['graduation_year']) ? (int)$_GET['graduation_year'] : date('Y');
$filter_grade_level = isset($_GET['grade_level']) ? (int)$_GET['grade_level'] : 0; // 0 = semua tingkat

// ========== Ambil semua kelas sesuai role dan school_type ==========
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

// Daftar tingkat kelas unik untuk dropdown filter (dari kelas yang ada)
$unique_grade_levels = [];
foreach ($classes as $c) {
    $unique_grade_levels[$c['grade_level']] = $c['grade_level'];
}
ksort($unique_grade_levels);

// Fungsi helper untuk mengambil siswa berdasarkan kelas
function getStudentsByClass($class_id, $school_type) {
    global $supabase_admin_request;
    $field_class_id = ($school_type == 'pagi') ? 'kelas_pagi_id' : 'kelas_diniyyah_id';
    $students_raw = supabase_admin_request('GET', 'users', null, [
        'role' => 'eq.student',
        $field_class_id => 'eq.' . $class_id,
        'order' => 'full_name.asc'
    ]);
    $students = [];
    if (is_array($students_raw)) {
        foreach ($students_raw as $s) if (isset($s['id'])) $students[] = $s;
    }
    return $students;
}

function getSubjectsByGrade($grade_level) {
    global $supabase_admin_request;
    $subjects_raw = supabase_admin_request('GET', 'subjects', null, [
        'grade_level' => 'eq.' . $grade_level,
        'order' => 'subject_name.asc'
    ]);
    $subjects = [];
    if (is_array($subjects_raw)) {
        foreach ($subjects_raw as $subj) if (isset($subj['id'])) $subjects[] = $subj;
    }
    return $subjects;
}

// Opsi dropdown untuk tahun ajaran dan tahun lulus
$academic_years = [];
$current_year = (int)date('Y');
for ($y = $current_year - 2; $y <= $current_year + 2; $y++) {
    $academic_years[] = $y . '/' . ($y + 1);
}
$graduation_years = [];
for ($y = $current_year - 2; $y <= $current_year + 3; $y++) {
    $graduation_years[] = $y;
}

require_once __DIR__ . '/includes/nav_items.php';
foreach ($nav_items as &$item) {
    $item['active'] = ($item['link'] == 'monitoring_scores.php');
}
unset($item);
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .filter-bar { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 20px; }
    .progress-bar { background: #e5e7eb; border-radius: 9999px; height: 10px; overflow: hidden; }
    .progress-fill { height: 100%; border-radius: 9999px; transition: width 0.3s; }
    .dark .progress-bar { background: #374151; }
    .class-card { transition: transform 0.2s; height: 100%; }
    .class-card:hover { transform: translateY(-2px); }
    .tab-button { padding: 8px 16px; border-bottom: 2px solid transparent; cursor: pointer; }
    .tab-button.active { border-bottom-color: #3b82f6; color: #3b82f6; font-weight: bold; }
    .dark .tab-button.active { border-bottom-color: #60a5fa; color: #60a5fa; }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main-content-container flex-1">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openMobileSidebarBtn" class="md:hidden text-gray-600"><i class="fas fa-bars text-2xl"></i></button>
                <h1 class="text-xl font-semibold hidden md:block dark:text-white">Monitoring Input Nilai</h1>
                <div class="flex items-center space-x-4">
                    <button id="darkModeToggle"><i class="fas fa-moon text-xl dark:hidden"></i><i class="fas fa-sun text-xl hidden dark:inline"></i></button>
                    <div class="relative group">
                        <button class="flex items-center space-x-2"><div class="w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center">A</div><span class="hidden md:inline dark:text-gray-200"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'User') ?></span></button>
                        <div class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-md shadow-lg hidden group-hover:block"><a href="logout.php" class="block px-4 py-2 text-red-600">Logout</a></div>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4 md:p-6 dark:bg-gray-900">
            <!-- Filter Sekolah (umum) -->
            <form method="GET" class="filter-bar" id="mainForm">
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400">Sekolah</label>
                    <select name="school_type" class="border rounded px-3 py-2 dark:bg-gray-700" onchange="updateUrlAndSubmit()">
                        <option value="pagi" <?= $school_type == 'pagi' ? 'selected' : '' ?>>Pagi</option>
                        <option value="diniyyah" <?= $school_type == 'diniyyah' ? 'selected' : '' ?>>Diniyyah</option>
                    </select>
                </div>
                <!-- Input hidden untuk semua filter -->
                <input type="hidden" name="tab" id="tabInput" value="<?= $active_tab ?>">
                <input type="hidden" name="semester" id="semesterInput" value="<?= $selected_semester ?>">
                <input type="hidden" name="academic_year" id="academicYearInput" value="<?= htmlspecialchars($selected_academic_year) ?>">
                <input type="hidden" name="graduation_year" id="graduationYearInput" value="<?= $selected_graduation_year ?>">
                <input type="hidden" name="grade_level" id="gradeLevelInput" value="<?= $filter_grade_level ?>">
                <noscript>
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Terapkan</button>
                </noscript>
            </form>

            <!-- Tab navigasi -->
            <div class="border-b border-gray-200 dark:border-gray-700 mb-4">
                <div class="flex gap-4">
                    <div class="tab-button <?= $active_tab == 'semester' ? 'active' : '' ?>" data-tab="semester">Nilai Ujian Semester</div>
                    <div class="tab-button <?= $active_tab == 'ijazah' ? 'active' : '' ?>" data-tab="ijazah">Nilai Ijazah</div>
                </div>
            </div>

            <!-- Konten Tab Semester -->
            <div id="tab-semester" class="tab-content <?= $active_tab == 'semester' ? 'active' : '' ?>">
                <!-- Filter khusus semester -->
                <div class="filter-bar mb-4">
                    <div>
                        <label class="block text-xs text-gray-600 dark:text-gray-400">Semester</label>
                        <select id="semesterSelect" class="border rounded px-3 py-2 dark:bg-gray-700" onchange="updateSemesterFilter()">
                            <option value="1" <?= $selected_semester == 1 ? 'selected' : '' ?>>Ganjil</option>
                            <option value="2" <?= $selected_semester == 2 ? 'selected' : '' ?>>Genap</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 dark:text-gray-400">Tahun Ajaran</label>
                        <select id="academicYearSelect" class="border rounded px-3 py-2 dark:bg-gray-700" onchange="updateSemesterFilter()">
                            <?php foreach ($academic_years as $year): ?>
                                <option value="<?= $year ?>" <?= $selected_academic_year == $year ? 'selected' : '' ?>><?= $year ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php if (empty($classes)): ?>
                    <div class="bg-yellow-100 p-4 rounded">Tidak ada kelas untuk sekolah <?= $school_type == 'pagi' ? 'Pagi' : 'Diniyyah' ?> yang dapat diakses.</div>
                <?php else: ?>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <?php foreach ($classes as $class): 
                            $grade_level = $class['grade_level'];
                            $students = getStudentsByClass($class['id'], $school_type);
                            $subjects = getSubjectsByGrade($grade_level);
                            $total_pairs = count($students) * count($subjects);
                            
                            $exam_filled = 0;
                            if ($total_pairs > 0 && !empty($students) && !empty($subjects)) {
                                $student_ids = array_column($students, 'id');
                                $subject_ids = array_column($subjects, 'id');
                                $exam_scores_raw = supabase_admin_request('GET', 'exam_scores', null, [
                                    'semester' => 'eq.' . $selected_semester,
                                    'academic_year' => 'eq.' . $selected_academic_year
                                ]);
                                if (is_array($exam_scores_raw)) {
                                    foreach ($exam_scores_raw as $ex) {
                                        if (in_array($ex['student_id'], $student_ids) && in_array($ex['subject_id'], $subject_ids)) {
                                            $exam_filled++;
                                        }
                                    }
                                }
                            }
                            $exam_percent = $total_pairs > 0 ? round(($exam_filled / $total_pairs) * 100, 1) : 0;
                        ?>
                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 class-card">
                                <h2 class="text-xl font-bold border-b pb-2 dark:text-white">
                                    <?= htmlspecialchars($class['class_name']) ?> (Kelas <?= $grade_level ?>)
                                </h2>
                                <div class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                    <span class="mr-4"><i class="fas fa-users"></i> <?= count($students) ?> siswa</span>
                                    <span><i class="fas fa-book"></i> <?= count($subjects) ?> mapel</span>
                                </div>
                                <div class="mt-4">
                                    <p class="font-medium text-blue-600 dark:text-blue-400">Nilai Ujian Semester</p>
                                    <p class="text-xs">Target: <?= $total_pairs ?> nilai | Terisi: <?= $exam_filled ?></p>
                                    <div class="progress-bar mt-1"><div class="progress-fill bg-blue-600" style="width: <?= $exam_percent ?>%"></div></div>
                                    <p class="text-right text-sm font-semibold"><?= $exam_percent ?>%</p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Konten Tab Ijazah (dengan filter tingkat kelas) -->
            <div id="tab-ijazah" class="tab-content <?= $active_tab == 'ijazah' ? 'active' : '' ?>">
                <!-- Filter khusus ijazah: tahun lulus dan tingkat kelas -->
                <div class="filter-bar mb-4">
                    <div>
                        <label class="block text-xs text-gray-600 dark:text-gray-400">Tahun Lulus</label>
                        <select id="graduationYearSelect" class="border rounded px-3 py-2 dark:bg-gray-700" onchange="updateIjazahFilter()">
                            <?php foreach ($graduation_years as $year): ?>
                                <option value="<?= $year ?>" <?= $selected_graduation_year == $year ? 'selected' : '' ?>><?= $year ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 dark:text-gray-400">Tingkat Kelas</label>
                        <select id="gradeLevelSelect" class="border rounded px-3 py-2 dark:bg-gray-700" onchange="updateIjazahFilter()">
                            <option value="0">Semua Tingkat</option>
                            <?php foreach ($unique_grade_levels as $gl): ?>
                                <option value="<?= $gl ?>" <?= $filter_grade_level == $gl ? 'selected' : '' ?>>Kelas <?= $gl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php
                // Filter kelas berdasarkan tingkat jika dipilih
                $filtered_classes = $classes;
                if ($filter_grade_level > 0) {
                    $filtered_classes = array_filter($filtered_classes, function($c) use ($filter_grade_level) {
                        return $c['grade_level'] == $filter_grade_level;
                    });
                    $filtered_classes = array_values($filtered_classes);
                }
                ?>
                <?php if (empty($filtered_classes)): ?>
                    <div class="bg-yellow-100 p-4 rounded">Tidak ada kelas untuk sekolah <?= $school_type == 'pagi' ? 'Pagi' : 'Diniyyah' ?> <?= $filter_grade_level > 0 ? 'dengan tingkat ' . $filter_grade_level : '' ?> yang dapat diakses.</div>
                <?php else: ?>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <?php foreach ($filtered_classes as $class): 
                            $grade_level = $class['grade_level'];
                            $students = getStudentsByClass($class['id'], $school_type);
                            $subjects = getSubjectsByGrade($grade_level);
                            $total_pairs = count($students) * count($subjects);
                            
                            $diploma_filled = 0;
                            if ($total_pairs > 0 && !empty($students) && !empty($subjects)) {
                                $student_ids = array_column($students, 'id');
                                $subject_ids = array_column($subjects, 'id');
                                $diploma_scores_raw = supabase_admin_request('GET', 'diploma_scores', null, [
                                    'graduation_year' => 'eq.' . $selected_graduation_year
                                ]);
                                if (is_array($diploma_scores_raw)) {
                                    foreach ($diploma_scores_raw as $dip) {
                                        if (in_array($dip['student_id'], $student_ids) && in_array($dip['subject_id'], $subject_ids)) {
                                            $diploma_filled++;
                                        }
                                    }
                                }
                            }
                            $diploma_percent = $total_pairs > 0 ? round(($diploma_filled / $total_pairs) * 100, 1) : 0;
                        ?>
                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 class-card">
                                <h2 class="text-xl font-bold border-b pb-2 dark:text-white">
                                    <?= htmlspecialchars($class['class_name']) ?> (Kelas <?= $grade_level ?>)
                                </h2>
                                <div class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                    <span class="mr-4"><i class="fas fa-users"></i> <?= count($students) ?> siswa</span>
                                    <span><i class="fas fa-book"></i> <?= count($subjects) ?> mapel</span>
                                </div>
                                <div class="mt-4">
                                    <p class="font-medium text-green-600 dark:text-green-400">Nilai Ijazah</p>
                                    <p class="text-xs">Target: <?= $total_pairs ?> nilai | Terisi: <?= $diploma_filled ?></p>
                                    <div class="progress-bar mt-1"><div class="progress-fill bg-green-600" style="width: <?= $diploma_percent ?>%"></div></div>
                                    <p class="text-right text-sm font-semibold"><?= $diploma_percent ?>%</p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<script>
function updateUrlAndSubmit() {
    document.getElementById('mainForm').submit();
}

function updateSemesterFilter() {
    document.getElementById('semesterInput').value = document.getElementById('semesterSelect').value;
    document.getElementById('academicYearInput').value = document.getElementById('academicYearSelect').value;
    document.getElementById('tabInput').value = 'semester';
    document.getElementById('mainForm').submit();
}

function updateIjazahFilter() {
    document.getElementById('graduationYearInput').value = document.getElementById('graduationYearSelect').value;
    document.getElementById('gradeLevelInput').value = document.getElementById('gradeLevelSelect').value;
    document.getElementById('tabInput').value = 'ijazah';
    document.getElementById('mainForm').submit();
}

// Tab switching
document.querySelectorAll('.tab-button').forEach(btn => {
    btn.addEventListener('click', function(e) {
        document.getElementById('tabInput').value = this.getAttribute('data-tab');
        document.getElementById('mainForm').submit();
    });
});

// Dark mode
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