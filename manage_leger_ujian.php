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

$page_title = 'Leger Nilai Ujian - SIAKAP';
$current_page = 'leger_ujian';
require_once __DIR__ . '/config.php';

$user_role = $_SESSION['user_role'];
$user_id = $_SESSION['user_id'];

$school_type = isset($_GET['school_type']) ? $_GET['school_type'] : 'pagi';
$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$selected_semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 1;
$selected_academic_year = isset($_GET['academic_year']) ? trim($_GET['academic_year']) : date('Y') . '/' . (date('Y')+1);

// DAFTAR TAHUN AJARAN
$current_year = (int)date('Y');
$academic_years = [];
for ($y = $current_year - 5; $y <= $current_year + 2; $y++) {
    $academic_years[] = $y . '/' . ($y + 1);
}
if (!in_array($selected_academic_year, $academic_years)) {
    $academic_years[] = $selected_academic_year;
    sort($academic_years);
}

// Ambil kelas sesuai role
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

// Ambil semua nilai untuk semester dan tahun ajaran yang dipilih
$scores = [];
if (!empty($students) && !empty($subjects)) {
    $student_ids = array_column($students, 'id');
    $subject_ids = array_column($subjects, 'id');
    $scores_raw = supabase_admin_request('GET', 'exam_scores', null, [
        'semester' => 'eq.' . $selected_semester,
        'academic_year' => 'eq.' . $selected_academic_year
    ]);
    if (is_array($scores_raw)) {
        foreach ($scores_raw as $sc) {
            $sid = $sc['student_id'];
            $subj_id = $sc['subject_id'];
            if (in_array($sid, $student_ids) && in_array($subj_id, $subject_ids)) {
                $scores[$sid][$subj_id] = (float)$sc['score'];
            }
        }
    }
}

require_once __DIR__ . '/includes/nav_items.php';
foreach ($nav_items as &$item) {
    $item['active'] = ($item['link'] == 'leger_ujian.php');
}
unset($item);

if ($_SESSION['user_role'] == 'admin') {
    require_once __DIR__ . '/includes/header.php';
} else {
    require_once __DIR__ . '/includes/header_user.php';
}

// Ambil nama sekolah dari config (misal)
$school_name = defined('SCHOOL_NAME') ? SCHOOL_NAME : 'SMA Negeri 1 Contoh';
$school_address = defined('SCHOOL_ADDRESS') ? SCHOOL_ADDRESS : 'Jl. Pendidikan No. 123, Kota Contoh';
?>

<style>
    /* Gaya umum */
    .filter-bar { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 20px; }
    .leger-table { font-size: 14px; width: min-content; border-collapse: collapse; margin: 0 auto; }
    .leger-table th, .leger-table td { padding: 6px 8px; text-align: center; border: 1px solid #d1d5db; white-space: nowrap; }
    .leger-table th { background-color: #f3f4f6; font-weight: 600; min-inline-size: max-content; text-align: start;}
    .dark .leger-table th { background-color: #374151; color: white;}
    .leger-table .student-name { text-align: left; font-weight: 500; }
    .leger-table .nis { text-align: left; }
    .leger-table .total-row td { font-weight: bold; background-color: #e5e7eb; }
    .dark .leger-table .total-row td { background-color: #1e293b; }

    /* Rotasi teks untuk header mapel, total, rata-rata */
    .rotate-text {
        writing-mode: vertical-lr;
        transform: rotate(-180deg);
        white-space: nowrap;
        height: 80px;
        vertical-align: middle;
        text-align: center;
        font-size: 12px;
        letter-spacing: 1px;
        width: 1%; /* memastikan kolom selebar konten */
    }
    .rotate-text-small {
        writing-mode: vertical-lr;
        transform: rotate(-180deg);
        white-space: nowrap;
        height: 60px;
        font-size: 11px;
        width: 1%;
    }

    /* Modal */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.6);
        z-index: 9999;
        justify-content: center;
        align-items: center;
        padding: 20px;
    }
    .modal-overlay.active {
        display: flex;
    }
    .modal-content {
        background: white;
        border-radius: 12px;
        max-width: 95%;
        max-height: 90vh;
        overflow: auto;
        padding: 30px 20px 20px 20px;
        position: relative;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    .modal-close {
        position: absolute;
        top: 12px;
        right: 18px;
        font-size: 28px;
        cursor: pointer;
        color: #333;
        background: none;
        border: none;
        line-height: 1;
    }
    .modal-actions {
        margin-top: 20px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        justify-content: center;
    }
    .modal-actions button {
        padding: 8px 20px;
        border-radius: 6px;
        border: none;
        font-weight: 600;
        cursor: pointer;
    }
    .btn-print {
        background: #2563eb;
        color: white;
    }
    .btn-close-modal {
        background: #e5e7eb;
        color: #1f2937;
    }

    /* Responsif */
    @media (max-width: 640px) {
        .leger-table { font-size: 12px; }
        .leger-table th, .leger-table td { padding: 4px 4px; }
        .rotate-text { height: 60px; font-size: 10px; }
        .rotate-text-small { height: 50px; font-size: 10px; }
        .modal-content { padding: 20px 10px; }
    }

    /* Cetak (hanya dari modal) */
    @media print {
        /* Sembunyikan semua kecuali modal-content */
        body * { visibility: hidden; }
        .modal-content, .modal-content * { visibility: visible; }
        .modal-content {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            max-height: none;
            border-radius: 0;
            padding: 20px;
            box-shadow: none;
            background: white;
        }
        .modal-overlay {
            background: white !important;
            padding: 0;
            display: block !important;
        }
        .modal-close, .modal-actions, .no-print { display: none !important; }
        .leger-table th { background-color: #e2e8f0 !important; color: #000; }
        .leger-table td { border: 1px solid #000; }
        .leger-table .total-row td { background-color: #d1d5db !important; }

        @page {
            size: F4;
            margin: 1.5cm;
        }
        .leger-table { font-size: 11px; }
        .leger-table th, .leger-table td { padding: 4px 6px; }
        .rotate-text { height: 70px; font-size: 10px; }
        .rotate-text-small { height: 50px; font-size: 10px; }
        /* pastikan konten tidak terpotong */
        .modal-content { overflow: visible; }
    }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php if ($user_role === 'admin'): ?>
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <?php else: ?>
        <?php require_once __DIR__ . '/includes/sidebar_user.php'; ?>
    <?php endif; ?>
    <div class="main-content-container flex-1">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10 no-print">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openMobileSidebarBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Leger Nilai Ujian</h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white no-print">
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
            <?php if ($warning_message): ?>
                <div class="bg-yellow-100 dark:bg-yellow-200 text-yellow-700 p-3 rounded mb-4 no-print"><?= htmlspecialchars($warning_message) ?></div>
            <?php endif; ?>

            <!-- Filter Bar -->
            <form method="GET" class="filter-bar no-print">
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Sekolah</label>
                    <select name="school_type" class="border rounded px-3 py-2 dark:bg-gray-700" onchange="this.form.submit()">
                        <option value="pagi" <?= $school_type == 'pagi' ? 'selected' : '' ?>>Pagi</option>
                        <option value="diniyyah" <?= $school_type == 'diniyyah' ? 'selected' : '' ?>>Diniyyah</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Kelas</label>
                    <select name="class_id" class="border rounded px-3 py-2 dark:bg-gray-700" onchange="this.form.submit()">
                        <?php if (empty($classes)): ?>
                            <option value="">-- Tidak ada kelas --</option>
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
                <div>
                    <button type="button" id="viewLegerBtn" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded flex items-center gap-2">
                        <i class="fas fa-search"></i> View
                    </button>
                </div>
            </form>

            <!-- Tabel Leger (tampilan biasa) -->
            <?php if (!empty($students) && !empty($subjects)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-x-auto">
                    <table class="leger-table" style="margin:0 auto;">
                        <thead>
                            <tr>
                                <th rowspan="2" style="width:1%;">No</th>
                                <th rowspan="2" style="text-align:center; width:1%;">Nama Siswa</th>
                                <th rowspan="2" style="text-align:center; width:1%;">NIS</th>
                                <?php foreach ($subjects as $subj): ?>
                                    <th class="rotate-text"><?= htmlspecialchars($subj['subject_name']) ?></th>
                                <?php endforeach; ?>
                                <th class="rotate-text-small">Total</th>
                                <th class="rotate-text-small">Rata-rata</th>
                            </tr>
                            <tr>
                                <?php foreach ($subjects as $subj): ?>
                                    <th style="text-align:center; font-size:10px; font-weight:normal; background:#838383; color: white;">(100)</th>
                                <?php endforeach; ?>
                                <th style="font-size:10px; font-weight:normal; background:#f9fafb;">&nbsp;</th>
                                <th style="font-size:10px; font-weight:normal; background:#f9fafb;">&nbsp;</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = 1;
                            foreach ($students as $student):
                                $sid = $student['id'];
                                $total = 0;
                                $valid_count = 0;
                                $row_scores = [];
                                foreach ($subjects as $subj) {
                                    $score = isset($scores[$sid][$subj['id']]) ? $scores[$sid][$subj['id']] : null;
                                    $row_scores[$subj['id']] = $score;
                                    if ($score !== null) {
                                        $total += $score;
                                        $valid_count++;
                                    }
                                }
                                $average = ($valid_count > 0) ? $total / $valid_count : 0;
                            ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td class="student-name"><?= htmlspecialchars($student['full_name']) ?></td>
                                    <td class="nis"><?= htmlspecialchars($student['nidn_or_nisn']) ?></td>
                                    <?php foreach ($subjects as $subj): ?>
                                        <td>
                                            <?php 
                                                $score = $row_scores[$subj['id']];
                                                echo ($score !== null) ? number_format($score, 2) : '-';
                                            ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td><strong><?= number_format($total, 2) ?></strong></td>
                                    <td><strong><?= number_format($average, 2) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="total-row">
                                <td colspan="3" style="text-align:right; font-weight:bold;">Rata-rata Kelas</td>
                                <?php 
                                    $class_averages = [];
                                    foreach ($subjects as $subj) {
                                        $sum = 0; $cnt = 0;
                                        foreach ($students as $student) {
                                            $sid = $student['id'];
                                            $score = isset($scores[$sid][$subj['id']]) ? $scores[$sid][$subj['id']] : null;
                                            if ($score !== null) {
                                                $sum += $score;
                                                $cnt++;
                                            }
                                        }
                                        $class_averages[$subj['id']] = ($cnt > 0) ? $sum / $cnt : 0;
                                    }
                                    foreach ($subjects as $subj): 
                                ?>
                                    <td><strong><?= number_format($class_averages[$subj['id']], 2) ?></strong></td>
                                <?php endforeach; ?>
                                <td>-</td>
                                <td>-</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php elseif (!empty($students) && empty($subjects)): ?>
                <div class="bg-yellow-100 dark:bg-yellow-800 text-yellow-700 p-4 rounded">Belum ada mata pelajaran yang terdaftar untuk kelas ini.</div>
            <?php elseif (empty($students) && !empty($subjects)): ?>
                <div class="bg-yellow-100 dark:bg-yellow-800 text-yellow-700 p-4 rounded">Tidak ada siswa di kelas ini.</div>
            <?php else: ?>
                <div class="bg-gray-100 dark:bg-gray-700 p-4 rounded text-center">Silakan pilih kelas dan semester untuk menampilkan leger nilai.</div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Modal View / Cetak -->
<div class="modal-overlay" id="legerModal">
    <div class="modal-content">
        <button class="modal-close" id="closeModalBtn">&times;</button>
        <h3 style="margin-top:0; margin-bottom:5px; text-align:center;">Leger Nilai Ujian Semester</h3>
        <?php if ($class_data): ?>
            <p style="margin:0 0 15px 0; text-align:center; font-size:14px;">
                Kelas : <?= htmlspecialchars($class_data['class_name']) ?> (Kelas <?= $class_data['grade_level'] ?>) &nbsp;|&nbsp;
                Semester : <?= $selected_semester == 1 ? 'Ganjil' : 'Genap' ?> &nbsp;|&nbsp;
                Tahun Ajaran : <?= htmlspecialchars($selected_academic_year) ?>
            </p>
        <?php endif; ?>

        <!-- Tabel yang akan dicetak (tanpa kop) -->
        <?php if (!empty($students) && !empty($subjects)): ?>
            <table class="leger-table" id="printableTable">
                <thead>
                    <tr>
                        <th rowspan="2" style="width:1%;">No</th>
                        <th rowspan="2" style="text-align:center; width:1%;">Nama Siswa</th>
                        <th rowspan="2" style="text-align:center; width:1%;">NIS</th>
                        <?php foreach ($subjects as $subj): ?>
                            <th class="rotate-text"><?= htmlspecialchars($subj['subject_name']) ?></th>
                        <?php endforeach; ?>
                        <th class="rotate-text-small">Total</th>
                        <th class="rotate-text-small">Rata-rata</th>
                    </tr>
                    <tr>
                        <?php foreach ($subjects as $subj): ?>
                            <th style="font-size:10px; font-weight:normal; background:#838383; color: white;">(100)</th>
                        <?php endforeach; ?>
                        <th style="font-size:10px; font-weight:normal; background:#f9fafb;">&nbsp;</th>
                        <th style="font-size:10px; font-weight:normal; background:#f9fafb;">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1;
                    foreach ($students as $student):
                        $sid = $student['id'];
                        $total = 0;
                        $valid_count = 0;
                        $row_scores = [];
                        foreach ($subjects as $subj) {
                            $score = isset($scores[$sid][$subj['id']]) ? $scores[$sid][$subj['id']] : null;
                            $row_scores[$subj['id']] = $score;
                            if ($score !== null) {
                                $total += $score;
                                $valid_count++;
                            }
                        }
                        $average = ($valid_count > 0) ? $total / $valid_count : 0;
                    ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td class="student-name"><?= htmlspecialchars($student['full_name']) ?></td>
                            <td class="nis"><?= htmlspecialchars($student['nidn_or_nisn']) ?></td>
                            <?php foreach ($subjects as $subj): ?>
                                <td>
                                    <?php 
                                        $score = $row_scores[$subj['id']];
                                        echo ($score !== null) ? number_format($score, 2) : '-';
                                    ?>
                                </td>
                            <?php endforeach; ?>
                            <td><strong><?= number_format($total, 2) ?></strong></td>
                            <td><strong><?= number_format($average, 2) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="3" style="text-align:right; font-weight:bold;">Rata-rata Kelas</td>
                        <?php 
                            $class_averages = [];
                            foreach ($subjects as $subj) {
                                $sum = 0; $cnt = 0;
                                foreach ($students as $student) {
                                    $sid = $student['id'];
                                    $score = isset($scores[$sid][$subj['id']]) ? $scores[$sid][$subj['id']] : null;
                                    if ($score !== null) {
                                        $sum += $score;
                                        $cnt++;
                                    }
                                }
                                $class_averages[$subj['id']] = ($cnt > 0) ? $sum / $cnt : 0;
                            }
                            foreach ($subjects as $subj): 
                        ?>
                            <td><strong><?= number_format($class_averages[$subj['id']], 2) ?></strong></td>
                        <?php endforeach; ?>
                        <td>-</td>
                        <td>-</td>
                    </tr>
                </tfoot>
            </table>
        <?php else: ?>
            <p>Tidak ada data untuk ditampilkan.</p>
        <?php endif; ?>

        <div class="modal-actions">
            <button class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Cetak</button>
            <button class="btn-close-modal" id="closeModalBtn2">Tutup</button>
        </div>
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

// Modal handling
const modal = document.getElementById('legerModal');
const viewBtn = document.getElementById('viewLegerBtn');
const closeBtns = document.querySelectorAll('#closeModalBtn, #closeModalBtn2');

function openModal() {
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

viewBtn?.addEventListener('click', openModal);
closeBtns.forEach(btn => btn?.addEventListener('click', closeModal));
modal?.addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
// Tutup dengan ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
});

// Saat print, pastikan modal tetap terbuka dan konten tercetak
window.addEventListener('beforeprint', function() {
    // Jika modal tidak aktif, buka dulu? Biarkan user membuka manual.
    // Tapi jika modal terbuka, kita biarkan.
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>