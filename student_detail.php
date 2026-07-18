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

$page_title = 'Detail Siswa - SIAKAD Admin';
$current_page = 'student_detail';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Fungsi untuk mendapatkan statistik absensi
function getStudentAttendanceStats($user_id, $start_date, $end_date) {
    global $supabase_admin_request;
    $logs_params = [
        'user_id' => 'eq.' . $user_id,
        'scan_time' => 'gte.' . $start_date . ' 00:00:00',
        'scan_time' => 'lte.' . $end_date . ' 23:59:59'
    ];
    $logs_raw = supabase_admin_request('GET', 'attendance_logs', null, $logs_params);
    $logs = is_array($logs_raw) ? $logs_raw : [];
    
    $stats = ['Hadir' => 0, 'Terlambat' => 0, 'Izin' => 0, 'Sakit' => 0, 'Alpha' => 0];
    foreach ($logs as $log) {
        $status = $log['status'] ?? 'Hadir';
        if (isset($stats[$status])) $stats[$status]++;
    }
    $stats['total'] = array_sum($stats);
    $stats['persentase'] = $stats['total'] > 0 ? round(($stats['Hadir'] / $stats['total']) * 100) : 0;
    return $stats;
}

// Fungsi untuk mendapatkan data absensi harian 30 hari terakhir
function getStudentDailyStats($user_id) {
    global $supabase_admin_request;
    $daily_stats = [];
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $daily_stats[$date] = ['Hadir' => 0, 'Terlambat' => 0, 'Izin' => 0, 'Sakit' => 0, 'Alpha' => 0, 'total' => 0];
        $logs_params = [
            'user_id' => 'eq.' . $user_id,
            'scan_time' => 'gte.' . $date . ' 00:00:00',
            'scan_time' => 'lte.' . $date . ' 23:59:59'
        ];
        $logs_raw = supabase_admin_request('GET', 'attendance_logs', null, $logs_params);
        $logs = is_array($logs_raw) ? $logs_raw : [];
        foreach ($logs as $log) {
            $status = $log['status'] ?? 'Hadir';
            if (isset($daily_stats[$date][$status])) {
                $daily_stats[$date][$status]++;
                $daily_stats[$date]['total']++;
            }
        }
    }
    return $daily_stats;
}

// Ambil daftar semua siswa
$students_raw = supabase_admin_request('GET', 'users', null, ['role' => 'eq.student', 'order' => 'full_name.asc']);
$all_students = is_array($students_raw) ? $students_raw : [];

// Ambil daftar kelas
$classes_raw = supabase_admin_request('GET', 'classes');
$classes = is_array($classes_raw) ? $classes_raw : [];
$class_map = array_column($classes, 'class_name', 'id');

// Filter dan pencarian
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$class_filter = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;

// Filter siswa
$filtered_students = $all_students;
if (!empty($search)) {
    $search_lower = strtolower($search);
    $filtered_students = array_filter($filtered_students, function($s) use ($search_lower) {
        return strpos(strtolower($s['full_name'] ?? ''), $search_lower) !== false ||
               strpos(strtolower($s['nidn_or_nisn'] ?? ''), $search_lower) !== false;
    });
}
if ($class_filter > 0) {
    $filtered_students = array_filter($filtered_students, function($s) use ($class_filter) {
        return ($s['kelas_pagi_id'] ?? 0) == $class_filter || ($s['kelas_diniyyah_id'] ?? 0) == $class_filter;
    });
}
$filtered_students = array_values($filtered_students);
$total_students = count($filtered_students);
$total_pages = ceil($total_students / $per_page);
$offset = ($page - 1) * $per_page;
$students_page = array_slice($filtered_students, $offset, $per_page);

// Jika ada parameter student_id, tampilkan detail
$selected_student_id = isset($_GET['student_id']) ? $_GET['student_id'] : null;
$selected_student = null;
$student_stats = null;
$student_daily_stats = null;
$student_absensi = null;
$student_schedules = null;

if ($selected_student_id) {
    // Cari siswa
    foreach ($all_students as $s) {
        if ($s['id'] == $selected_student_id) {
            $selected_student = $s;
            break;
        }
    }
    if ($selected_student) {
        // Statistik
        $end_date = date('Y-m-d');
        $start_date_30 = date('Y-m-d', strtotime('-29 days'));
        $student_stats = getStudentAttendanceStats($selected_student_id, $start_date_30, $end_date);
        
        // Data tahunan
        $current_month = (int)date('m');
        $current_year = (int)date('Y');
        if ($current_month >= 7) {
            $tahun_start = $current_year . '-07-01';
            $tahun_end = ($current_year + 1) . '-06-30';
        } else {
            $tahun_start = ($current_year - 1) . '-07-01';
            $tahun_end = $current_year . '-06-30';
        }
        $student_stats_tahunan = getStudentAttendanceStats($selected_student_id, $tahun_start, $tahun_end);
        $student_daily_stats = getStudentDailyStats($selected_student_id);
        
        // Riwayat absensi (30 terakhir)
        $absensi_raw = supabase_admin_request('GET', 'attendance_logs', null, [
            'user_id' => 'eq.' . $selected_student_id,
            'order' => 'scan_time.desc',
            'limit' => 30
        ]);
        $student_absensi = is_array($absensi_raw) ? $absensi_raw : [];
        foreach ($student_absensi as &$log) {
            if (!empty($log['activity_id'])) {
                $act = supabase_admin_request('GET', 'activities', null, ['id' => 'eq.' . $log['activity_id']]);
                $log['activity_name'] = (is_array($act) && !empty($act)) ? $act[0]['name'] : '-';
            } else {
                $log['activity_name'] = '-';
            }
        }
        unset($log);
        
        // Jadwal siswa (dari kelas pagi dan diniyyah)
        $student_schedules = [];
        if (!empty($selected_student['kelas_pagi_id'])) {
            $s = supabase_admin_request('GET', 'schedules', null, ['class_id' => 'eq.' . $selected_student['kelas_pagi_id']]);
            if (is_array($s)) $student_schedules = array_merge($student_schedules, $s);
        }
        if (!empty($selected_student['kelas_diniyyah_id'])) {
            $s = supabase_admin_request('GET', 'schedules', null, ['class_id' => 'eq.' . $selected_student['kelas_diniyyah_id']]);
            if (is_array($s)) $student_schedules = array_merge($student_schedules, $s);
        }
        usort($student_schedules, function($a, $b) {
            if ($a['day_of_week'] == $b['day_of_week']) return strcmp($a['start_time'], $b['start_time']);
            return $a['day_of_week'] - $b['day_of_week'];
        });
    }
}

$hari_map = [1=>'Senin',2=>'Selasa',3=>'Rabu',4=>'Kamis',5=>'Jumat',6=>'Sabtu',7=>'Minggu'];

// Navigasi sidebar
require_once __DIR__ . '/includes/nav_items.php';
foreach ($nav_items as &$item) {
    $item['active'] = ($item['link'] == 'student_detail.php');
}
unset($item);
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .stat-card-activity {
        background: white;
        border-radius: 12px;
        padding: 1.25rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        border: 1px solid #f1f5f9;
        transition: all 0.2s ease;
    }
    .dark .stat-card-activity {
        background: #1f2937;
        border-color: #374151;
    }
    .stat-card-activity:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.07);
    }
    .stat-value-activity {
        font-size: 2rem;
        font-weight: 700;
        line-height: 1.2;
    }
    .stat-label-activity {
        font-size: 0.75rem;
        color: #6b7280;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .dark .stat-label-activity { color: #9ca3af; }

    .progress-container {
        width: 100%;
        background-color: #e5e7eb;
        border-radius: 10px;
        overflow: hidden;
        height: 20px;
        margin-top: 8px;
    }
    .dark .progress-container {
        background-color: #374151;
    }
    .progress-bar {
        height: 100%;
        border-radius: 10px;
        background: linear-gradient(90deg, #3b82f6, #8b5cf6);
        transition: width 0.8s ease;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        padding-right: 6px;
        font-size: 0.65rem;
        font-weight: 600;
        color: white;
        min-width: 30px;
    }
    .progress-bar.high { background: linear-gradient(90deg, #22c55e, #16a34a); }
    .progress-bar.medium { background: linear-gradient(90deg, #eab308, #ca8a04); }
    .progress-bar.low { background: linear-gradient(90deg, #ef4444, #dc2626); }

    .donut-container {
        position: relative;
        height: 160px;
        max-width: 200px;
        margin: 0 auto;
    }
    .legend-dot {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        margin-right: 4px;
    }

    .schedule-timeline {
        position: relative;
        padding-left: 24px;
    }
    .schedule-timeline::before {
        content: '';
        position: absolute;
        left: 8px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: linear-gradient(to bottom, #3b82f6, #8b5cf6);
        border-radius: 2px;
    }
    .schedule-item {
        position: relative;
        margin-bottom: 12px;
    }
    .schedule-time-badge {
        display: inline-block;
        font-weight: 600;
        background: #e0e7ff;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 0.7rem;
        margin-right: 12px;
    }
    .dark .schedule-time-badge {
        background: #374151;
        color: #e5e7eb;
    }
    .schedule-day-header {
        background: #e5e7eb;
        font-weight: bold;
        padding: 8px;
        margin-top: 16px;
        margin-bottom: 8px;
        border-radius: 8px;
    }
    .dark .schedule-day-header {
        background: #374151;
        color: #f3f4f6;
    }

    .student-list-item {
        transition: all 0.2s ease;
        cursor: pointer;
    }
    .student-list-item:hover {
        background: #f3f4f6;
        transform: translateX(4px);
    }
    .dark .student-list-item:hover {
        background: #374151;
    }
    .student-list-item.active {
        background: #dbeafe;
        border-left: 4px solid #3b82f6;
    }
    .dark .student-list-item.active {
        background: #1e3a5f;
        border-left-color: #60a5fa;
    }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    
    <div class="main-content-container flex-1 transition-all duration-300 ease-in-out">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openSidebarBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden" onclick="document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('sidebarOverlay').classList.remove('hidden');">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Detail Siswa</h1>
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
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <!-- SIDEBAR KIRI: Daftar Siswa -->
                <div class="lg:col-span-1">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-3">
                            <i class="fas fa-users mr-2 text-blue-500"></i> Daftar Siswa
                            <span class="text-sm font-normal text-gray-500 dark:text-gray-400">(<?= $total_students ?>)</span>
                        </h2>
                        <form method="GET" class="mb-3">
                            <div class="flex gap-1">
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari nama/NISN..." class="flex-1 border rounded-lg px-3 py-1.5 text-sm dark:bg-gray-700 dark:border-gray-600">
                                <button type="submit" class="bg-blue-600 text-white px-3 py-1.5 rounded-lg text-sm"><i class="fas fa-search"></i></button>
                            </div>
                            <?php if ($selected_student_id): ?>
                                <input type="hidden" name="student_id" value="<?= $selected_student_id ?>">
                            <?php endif; ?>
                        </form>
                        <div class="mb-3">
                            <select name="class_id" form="filterForm" class="w-full border rounded-lg px-3 py-1.5 text-sm dark:bg-gray-700 dark:border-gray-600">
                                <option value="0">-- Semua Kelas --</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= $class_filter == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['class_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <form id="filterForm" method="GET" class="mb-3">
                            <?php if (!empty($search)): ?>
                                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                            <?php endif; ?>
                            <?php if ($selected_student_id): ?>
                                <input type="hidden" name="student_id" value="<?= $selected_student_id ?>">
                            <?php endif; ?>
                            <button type="submit" class="w-full bg-gray-500 hover:bg-gray-600 text-white px-3 py-1.5 rounded-lg text-sm"><i class="fas fa-filter mr-1"></i> Filter Kelas</button>
                        </form>
                        <div class="overflow-y-auto max-h-[70vh]">
                            <?php if (empty($students_page)): ?>
                                <p class="text-center text-gray-500 dark:text-gray-400 py-4">Tidak ada siswa ditemukan</p>
                            <?php else: ?>
                                <?php foreach ($students_page as $s): 
                                    $is_active = ($selected_student_id == $s['id']);
                                ?>
                                    <a href="?student_id=<?= $s['id'] ?>&search=<?= urlencode($search) ?>&class_id=<?= $class_filter ?>&page=<?= $page ?>" 
                                       class="student-list-item <?= $is_active ? 'active' : '' ?> block px-3 py-2 rounded-lg mb-1 text-gray-800 dark:text-white">
                                        <div class="font-medium"><?= htmlspecialchars($s['full_name']) ?></div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            NIS: <?= htmlspecialchars($s['nidn_or_nisn'] ?? '-') ?>
                                            <?php if (!empty($s['kelas_pagi_id'])): ?>
                                                • <?= htmlspecialchars($class_map[$s['kelas_pagi_id']] ?? 'Kelas Pagi') ?>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($total_pages > 1): ?>
                            <div class="flex justify-between items-center mt-3 text-sm">
                                <span class="text-gray-500 dark:text-gray-400">Halaman <?= $page ?> dari <?= $total_pages ?></span>
                                <div class="flex gap-1">
                                    <?php if ($page > 1): ?>
                                        <a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&class_id=<?= $class_filter ?><?= $selected_student_id ? '&student_id='.$selected_student_id : '' ?>" class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded hover:bg-gray-300">«</a>
                                    <?php endif; ?>
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&class_id=<?= $class_filter ?><?= $selected_student_id ? '&student_id='.$selected_student_id : '' ?>" class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded hover:bg-gray-300">»</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- DETAIL SISWA -->
                <div class="lg:col-span-3">
                    <?php if ($selected_student && $selected_student_id): ?>
                        <?php 
                            $kelas_pagi = !empty($selected_student['kelas_pagi_id']) ? ($class_map[$selected_student['kelas_pagi_id']] ?? '-') : '-';
                            $kelas_diniyyah = !empty($selected_student['kelas_diniyyah_id']) ? ($class_map[$selected_student['kelas_diniyyah_id']] ?? '-') : '-';
                        ?>
                        <!-- Profil -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 mb-6 flex flex-wrap items-center gap-4">
                            <div class="w-20 h-20 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center overflow-hidden shadow-lg">
                                <?php if (!empty($selected_student['photo_url'])): ?>
                                    <img src="<?= htmlspecialchars($selected_student['photo_url']) ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <i class="fas fa-user-graduate text-4xl text-white"></i>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h2 class="text-2xl font-bold text-gray-800 dark:text-white"><?= htmlspecialchars($selected_student['full_name']) ?></h2>
                                <p class="text-gray-600 dark:text-gray-400">
                                    <i class="fas fa-id-card mr-1"></i> NIS: <?= htmlspecialchars($selected_student['nidn_or_nisn'] ?? '-') ?>
                                    | <i class="fas fa-chalkboard-teacher mr-1"></i> Kelas Pagi: <?= $kelas_pagi ?>
                                    | <i class="fas fa-mosque mr-1"></i> Diniyyah: <?= $kelas_diniyyah ?>
                                </p>
                            </div>
                        </div>

                        <!-- Statistik Keaktifan -->
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-3">
                                <i class="fas fa-chart-line mr-2 text-green-500"></i> Statistik Keaktifan
                            </h3>

                            <!-- Progress Bar 30 Hari -->
                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 mb-4">
                                <div class="flex flex-wrap items-center justify-between mb-2">
                                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                        <i class="fas fa-calendar-week mr-2 text-blue-500"></i> Keaktifan 30 Hari Terakhir
                                    </h4>
                                    <span class="text-sm font-bold <?= $student_stats['persentase'] >= 80 ? 'text-green-600 dark:text-green-400' : ($student_stats['persentase'] >= 60 ? 'text-yellow-600 dark:text-yellow-400' : 'text-red-600 dark:text-red-400') ?>">
                                        <?= $student_stats['persentase'] ?>% Kehadiran
                                    </span>
                                </div>
                                <div class="progress-container">
                                    <div class="progress-bar <?= $student_stats['persentase'] >= 80 ? 'high' : ($student_stats['persentase'] >= 60 ? 'medium' : 'low') ?>" style="width: <?= $student_stats['persentase'] ?>%;">
                                        <?= $student_stats['persentase'] ?>%
                                    </div>
                                </div>
                                <div class="flex flex-wrap justify-between text-xs text-gray-500 dark:text-gray-400 mt-2">
                                    <span>Hadir: <?= $student_stats['Hadir'] ?></span>
                                    <span>Terlambat: <?= $student_stats['Terlambat'] ?></span>
                                    <span>Izin: <?= $student_stats['Izin'] ?></span>
                                    <span>Sakit: <?= $student_stats['Sakit'] ?></span>
                                    <span>Alpha: <?= $student_stats['Alpha'] ?></span>
                                </div>
                            </div>

                            <!-- Donut + Tahunan -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Donut -->
                                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4">
                                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 text-center">
                                        <i class="fas fa-chart-pie mr-2 text-purple-500"></i> Komposisi Kehadiran (30 Hari)
                                    </h4>
                                    <div class="donut-container">
                                        <canvas id="detailDonutChart"></canvas>
                                    </div>
                                    <div class="flex flex-wrap justify-center gap-3 mt-2 text-xs">
                                        <span><span class="legend-dot" style="background:#22c55e;"></span>Hadir <?= $student_stats['Hadir'] ?></span>
                                        <span><span class="legend-dot" style="background:#eab308;"></span>Terlambat <?= $student_stats['Terlambat'] ?></span>
                                        <span><span class="legend-dot" style="background:#3b82f6;"></span>Izin <?= $student_stats['Izin'] ?></span>
                                        <span><span class="legend-dot" style="background:#8b5cf6;"></span>Sakit <?= $student_stats['Sakit'] ?></span>
                                        <span><span class="legend-dot" style="background:#ef4444;"></span>Alpha <?= $student_stats['Alpha'] ?></span>
                                    </div>
                                </div>

                                <!-- Tahunan -->
                                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4">
                                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 text-center">
                                        <i class="fas fa-calendar-alt mr-2 text-indigo-500"></i> Keaktifan <?= date('Y', strtotime($tahun_start)) ?> - <?= date('Y', strtotime($tahun_end)) ?>
                                    </h4>
                                    <div class="grid grid-cols-3 gap-2 mb-3">
                                        <div class="stat-card-activity p-2 text-center">
                                            <div class="stat-value-activity text-green-600 dark:text-green-400 text-xl"><?= $student_stats_tahunan['Hadir'] ?></div>
                                            <div class="stat-label-activity text-xs">Hadir</div>
                                        </div>
                                        <div class="stat-card-activity p-2 text-center">
                                            <div class="stat-value-activity text-yellow-600 dark:text-yellow-400 text-xl"><?= $student_stats_tahunan['Terlambat'] ?></div>
                                            <div class="stat-label-activity text-xs">Terlambat</div>
                                        </div>
                                        <div class="stat-card-activity p-2 text-center">
                                            <div class="stat-value-activity text-blue-600 dark:text-blue-400 text-xl"><?= $student_stats_tahunan['Izin'] ?></div>
                                            <div class="stat-label-activity text-xs">Izin</div>
                                        </div>
                                        <div class="stat-card-activity p-2 text-center">
                                            <div class="stat-value-activity text-purple-600 dark:text-purple-400 text-xl"><?= $student_stats_tahunan['Sakit'] ?></div>
                                            <div class="stat-label-activity text-xs">Sakit</div>
                                        </div>
                                        <div class="stat-card-activity p-2 text-center">
                                            <div class="stat-value-activity text-red-600 dark:text-red-400 text-xl"><?= $student_stats_tahunan['Alpha'] ?></div>
                                            <div class="stat-label-activity text-xs">Alpha</div>
                                        </div>
                                        <div class="stat-card-activity p-2 text-center">
                                            <div class="stat-value-activity text-indigo-600 dark:text-indigo-400 text-xl"><?= $student_stats_tahunan['total'] ?></div>
                                            <div class="stat-label-activity text-xs">Total</div>
                                        </div>
                                    </div>
                                    <div class="text-center">
                                        <span class="inline-block px-4 py-1 rounded-full text-sm font-bold <?= $student_stats_tahunan['persentase'] >= 80 ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : ($student_stats_tahunan['persentase'] >= 60 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200') ?>">
                                            Persentase Kehadiran: <?= $student_stats_tahunan['persentase'] ?>%
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Jadwal -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 mb-6">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-3">
                                <i class="fas fa-calendar-alt mr-2 text-blue-500"></i> Jadwal Pelajaran
                            </h3>
                            <?php if (empty($student_schedules)): ?>
                                <div class="text-center py-4 text-gray-500 dark:text-gray-400">Tidak ada jadwal</div>
                            <?php else: ?>
                                <div class="schedule-timeline">
                                    <?php 
                                    $schedules_by_day = [];
                                    foreach ($student_schedules as $s) {
                                        $day = $s['day_of_week'];
                                        if (!isset($schedules_by_day[$day])) $schedules_by_day[$day] = [];
                                        $schedules_by_day[$day][] = $s;
                                    }
                                    foreach ($schedules_by_day as $day => $day_schedules):
                                    ?>
                                        <div class="schedule-day-header"><?= $hari_map[$day] ?></div>
                                        <?php $jam_ke = 1; foreach ($day_schedules as $j):
                                            $subj = supabase_admin_request('GET', 'subjects', null, ['id' => 'eq.' . $j['subject_id']]);
                                            $subject = (is_array($subj) && !empty($subj)) ? $subj[0]['subject_name'] : '-';
                                            $tch = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $j['teacher_id']]);
                                            $teacher = (is_array($tch) && !empty($tch)) ? $tch[0]['full_name'] : '-';
                                        ?>
                                            <div class="schedule-item">
                                                <div class="flex flex-wrap items-start gap-2 py-2">
                                                    <div class="schedule-time-badge">Jam ke-<?= $jam_ke++ ?></div>
                                                    <div class="flex-1 text-gray-800 dark:text-white">
                                                        <div class="font-semibold"><?= htmlspecialchars($subject) ?></div>
                                                        <div class="text-xs text-gray-500 dark:text-gray-400"><?= $j['start_time'] ?> - <?= $j['end_time'] ?> • Guru: <?= htmlspecialchars($teacher) ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Riwayat Absensi -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-3">
                                <i class="fas fa-history mr-2 text-purple-500"></i> Riwayat Absensi (Terbaru)
                            </h3>
                            <?php if (empty($student_absensi)): ?>
                                <div class="text-center py-4 text-gray-500 dark:text-gray-400">Belum ada riwayat absensi</div>
                            <?php else: ?>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full text-sm">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th class="px-3 py-2 text-left">Tanggal</th>
                                                <th class="px-3 py-2 text-left">Jam</th>
                                                <th class="px-3 py-2 text-left">Kegiatan</th>
                                                <th class="px-3 py-2 text-left">Status</th>
                                                <th class="px-3 py-2 text-left">Catatan</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($student_absensi as $log): ?>
                                                <tr class="border-t border-gray-200 dark:border-gray-700">
                                                    <td class="px-3 py-2"><?= date('d M Y', strtotime($log['scan_time'])) ?></td>
                                                    <td class="px-3 py-2"><?= date('H:i', strtotime($log['scan_time'])) ?></td>
                                                    <td class="px-3 py-2"><?= htmlspecialchars($log['activity_name']) ?></td>
                                                    <td class="px-3 py-2">
                                                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold <?= $log['status'] == 'Hadir' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : ($log['status'] == 'Terlambat' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200') ?>">
                                                            <?= $log['status'] ?? 'Hadir' ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-3 py-2 text-gray-500 dark:text-gray-400"><?= htmlspecialchars($log['note'] ?? '-') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                    <?php elseif ($selected_student_id): ?>
                        <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-xl p-4 text-yellow-700 dark:text-yellow-400">
                            <i class="fas fa-exclamation-triangle mr-2"></i> Siswa tidak ditemukan.
                        </div>
                    <?php else: ?>
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-8 text-center text-gray-500 dark:text-gray-400">
                            <i class="fas fa-user-graduate text-6xl mb-4"></i>
                            <h3 class="text-xl font-semibold">Pilih siswa dari daftar di samping</h3>
                            <p class="text-sm mt-2">Klik nama siswa untuk melihat detail lengkap</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
// ========== DONUT CHART (Detail) ==========
<?php if ($selected_student && $selected_student_id): ?>
document.addEventListener('DOMContentLoaded', function() {
    const stats = <?= json_encode([
        'Hadir' => $student_stats['Hadir'] ?? 0,
        'Terlambat' => $student_stats['Terlambat'] ?? 0,
        'Izin' => $student_stats['Izin'] ?? 0,
        'Sakit' => $student_stats['Sakit'] ?? 0,
        'Alpha' => $student_stats['Alpha'] ?? 0
    ]) ?>;
    
    const labels = ['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpha'];
    const data = [stats.Hadir, stats.Terlambat, stats.Izin, stats.Sakit, stats.Alpha];
    const colors = ['#22c55e', '#eab308', '#3b82f6', '#8b5cf6', '#ef4444'];
    
    const filteredLabels = [];
    const filteredData = [];
    const filteredColors = [];
    for (let i = 0; i < labels.length; i++) {
        if (data[i] > 0) {
            filteredLabels.push(labels[i]);
            filteredData.push(data[i]);
            filteredColors.push(colors[i]);
        }
    }
    
    const canvas = document.getElementById('detailDonutChart');
    if (!canvas) return;
    
    if (filteredData.length === 0) {
        canvas.parentElement.innerHTML = '<p class="text-center text-gray-500 text-sm">Belum ada data</p>';
        return;
    }
    
    new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: filteredLabels,
            datasets: [{
                data: filteredData,
                backgroundColor: filteredColors,
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            cutout: '70%'
        }
    });
});
<?php endif; ?>

// ========== DARK MODE ==========
const darkModeToggle = document.getElementById('darkModeToggle');
function setDarkMode(isDark) {
    if (isDark) {
        document.documentElement.classList.add('dark');
        localStorage.setItem('darkMode', 'enabled');
    } else {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('darkMode', 'disabled');
    }
    if (darkModeToggle) {
        const moon = darkModeToggle.querySelector('.fa-moon');
        const sun = darkModeToggle.querySelector('.fa-sun');
        if (moon && sun) {
            moon.classList.toggle('hidden', isDark);
            sun.classList.toggle('hidden', !isDark);
        }
    }
}
const savedMode = localStorage.getItem('darkMode');
if (savedMode === 'enabled') setDarkMode(true);
else if (savedMode === 'disabled') setDarkMode(false);
else setDarkMode(window.matchMedia('(prefers-color-scheme: dark)').matches);
darkModeToggle?.addEventListener('click', () => {
    setDarkMode(!document.documentElement.classList.contains('dark'));
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>