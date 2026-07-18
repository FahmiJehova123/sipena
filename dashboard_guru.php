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

$page_title = 'Dashboard Guru - SIAKAD';
$current_page = 'dashboard';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'];

// Ambil data guru
$user_data = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $user_id]);
$guru = (is_array($user_data) && !empty($user_data)) ? $user_data[0] : null;
if (!$guru) { header('Location: logout.php'); exit; }

// Ambil jadwal mengajar
$jadwal_raw = supabase_admin_request('GET', 'schedules', null, ['teacher_id' => 'eq.' . $user_id]);
$jadwal = safeArray($jadwal_raw);

$today = date('N');
$jadwal_hari_ini = array_filter($jadwal, function($j) use ($today) {
    return isset($j['day_of_week']) && $j['day_of_week'] == $today;
});

$jadwal_dropdown = $jadwal;
usort($jadwal_dropdown, function($a, $b) {
    if ($a['day_of_week'] == $b['day_of_week']) return strcmp($a['start_time'], $b['start_time']);
    return $a['day_of_week'] - $b['day_of_week'];
});

$classes_raw = supabase_admin_request('GET', 'classes');
$classes = safeArray($classes_raw);
$subjects_raw = supabase_admin_request('GET', 'subjects');
$subjects = safeArray($subjects_raw);

// Ambil semua siswa yang diajar
$siswa_ids = [];
foreach ($jadwal as $j) {
    if (!is_array($j)) continue;
    $siswa_pagi = supabase_admin_request('GET', 'users', null, ['role' => 'eq.student', 'kelas_pagi_id' => 'eq.' . $j['class_id']]);
    if (is_array($siswa_pagi)) foreach ($siswa_pagi as $s) $siswa_ids[$s['id']] = $s;
    $siswa_diniyyah = supabase_admin_request('GET', 'users', null, ['role' => 'eq.student', 'kelas_diniyyah_id' => 'eq.' . $j['class_id']]);
    if (is_array($siswa_diniyyah)) foreach ($siswa_diniyyah as $s) $siswa_ids[$s['id']] = $s;
}
$siswa = array_values($siswa_ids);
$total_siswa = count($siswa);

// Urutkan siswa berdasarkan nama (A–Z)
usort($siswa, function($a, $b) {
    return strcmp($a['full_name'], $b['full_name']);
});

// Cari wali kelas
$wali_kelas = null;
foreach ($classes as $c) {
    if (is_array($c) && isset($c['wali_kelas_id']) && $c['wali_kelas_id'] == $user_id) {
        $wali_kelas = $c;
        break;
    }
}

// ========== STATISTIK ABSENSI ==========
$student_ids = array_map(function($s) { return $s['id']; }, $siswa);
$all_absensi = [];
if (!empty($student_ids)) {
    $ids_string = implode(',', $student_ids);
    $all_absensi_raw = supabase_admin_request('GET', 'attendance_logs', null, [
        'user_id' => 'in.(' . $ids_string . ')',
        'order' => 'scan_time.desc',
        'limit' => 1000
    ]);
    $all_absensi = safeArray($all_absensi_raw);
}

// Hari ini
$today_date = date('Y-m-d');
$absen_hari_ini = array_filter($all_absensi, function($log) use ($today_date) {
    return date('Y-m-d', strtotime($log['scan_time'])) == $today_date;
});
$status_count_hari_ini = ['Hadir' => 0, 'Terlambat' => 0, 'Izin' => 0, 'Sakit' => 0, 'Alpha' => 0];
foreach ($absen_hari_ini as $log) {
    $st = $log['status'] ?? 'Hadir';
    if (isset($status_count_hari_ini[$st])) $status_count_hari_ini[$st]++;
}
$total_absen_hari_ini = array_sum($status_count_hari_ini);

// 30 hari terakhir
$bulan_ago = date('Y-m-d', strtotime('-29 days'));
$absen_30hari = array_filter($all_absensi, function($log) use ($bulan_ago) {
    return date('Y-m-d', strtotime($log['scan_time'])) >= $bulan_ago;
});
$status_count_30hari = ['Hadir' => 0, 'Terlambat' => 0, 'Izin' => 0, 'Sakit' => 0, 'Alpha' => 0];
foreach ($absen_30hari as $log) {
    $st = $log['status'] ?? 'Hadir';
    if (isset($status_count_30hari[$st])) $status_count_30hari[$st]++;
}
$total_absen_30hari = array_sum($status_count_30hari);
$hadir_persen_30hari = $total_absen_30hari > 0 ? round(($status_count_30hari['Hadir'] / $total_absen_30hari) * 100) : 0;

// Grafik 30 hari
$labels_30hari = [];
$data_hadir = [];
$data_terlambat = [];
$data_izin_sakit = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $labels_30hari[] = date('d M', strtotime($date));
    $hadir = $terlambat = $izin_sakit = 0;
    foreach ($all_absensi as $log) {
        if (date('Y-m-d', strtotime($log['scan_time'])) == $date) {
            $st = $log['status'] ?? 'Hadir';
            if ($st == 'Hadir') $hadir++;
            elseif ($st == 'Terlambat') $terlambat++;
            elseif ($st == 'Izin' || $st == 'Sakit') $izin_sakit++;
        }
    }
    $data_hadir[] = $hadir;
    $data_terlambat[] = $terlambat;
    $data_izin_sakit[] = $izin_sakit;
}

$pie_labels = ['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpha'];
$pie_data = [
    $status_count_hari_ini['Hadir'],
    $status_count_hari_ini['Terlambat'],
    $status_count_hari_ini['Izin'],
    $status_count_hari_ini['Sakit'],
    $status_count_hari_ini['Alpha']
];
$pie_colors = ['#22c55e', '#eab308', '#3b82f6', '#8b5cf6', '#ef4444'];

// Urutkan jadwal
usort($jadwal, function($a, $b) {
    if ($a['day_of_week'] == $b['day_of_week']) return strcmp($a['start_time'], $b['start_time']);
    return $a['day_of_week'] - $b['day_of_week'];
});

$filter_day = isset($_GET['filter_day']) ? (int)$_GET['filter_day'] : date('N');
if ($filter_day == 0) $filter_day = null;
$jadwal_filtered = [];
if ($filter_day) {
    foreach ($jadwal as $j) if ($j['day_of_week'] == $filter_day) $jadwal_filtered[] = $j;
} else {
    $jadwal_filtered = $jadwal;
}
$hari_map = [1=>'Senin',2=>'Selasa',3=>'Rabu',4=>'Kamis',5=>'Jumat',6=>'Sabtu',7=>'Minggu'];

$jadwal_by_day = [];
foreach ($jadwal_filtered as $j) {
    $day = $j['day_of_week'];
    if (!isset($jadwal_by_day[$day])) $jadwal_by_day[$day] = [];
    $jadwal_by_day[$day][] = $j;
}

// Pengumuman
$announcements_raw = supabase_admin_request('GET', 'announcements', null, [
    'is_active' => 'eq.true',
    'target_role' => 'in.(teacher,all)',
    'order' => 'created_at.desc'
]);
$announcements = safeArray($announcements_raw);
$reads_raw = supabase_admin_request('GET', 'announcement_reads', null, ['user_id' => 'eq.' . $user_id]);
$reads = safeArray($reads_raw);
$read_announcement_ids = array_column($reads, 'announcement_id');
$unread_count = 0;
foreach ($announcements as $ann) {
    if (!in_array($ann['id'], $read_announcement_ids)) $unread_count++;
}
$announcements_dropdown = array_slice($announcements, 0, 10);

require_once __DIR__ . '/includes/header_user.php';
?>
<style>
    .stat-card { transition: all 0.2s ease; border-radius: 1rem; padding: 1.25rem 1rem; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border: 1px solid #f1f5f9; }
    .dark .stat-card { background: #1f2937; border-color: #374151; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.07); }
    .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
    .stat-value { font-size: 2rem; font-weight: 700; line-height: 1.2; }
    .stat-label { font-size: 0.8rem; color: #6b7280; font-weight: 500; text-transform: uppercase; letter-spacing: 0.02em; }
    .dark .stat-label { color: #9ca3af; }
    .chart-container { position: relative; height: 200px; max-width: 100%; }
    .chart-container-pie { position: relative; height: 180px; max-width: 200px; margin: 0 auto; }
    .schedule-timeline { position: relative; padding-left: 24px; }
    .schedule-timeline::before { content: ''; position: absolute; left: 8px; top: 0; bottom: 0; width: 2px; background: linear-gradient(to bottom, #3b82f6, #8b5cf6); }
    .schedule-item { position: relative; margin-bottom: 12px; }
    .schedule-time-badge { display: inline-block; font-weight: 600; background: #e0e7ff; padding: 2px 8px; border-radius: 20px; font-size: 0.7rem; margin-right: 12px; }
    .dark .schedule-time-badge { background: #374151; color: #e5e7eb; }
    .schedule-day-header { background: #e5e7eb; font-weight: bold; padding: 8px; margin-top: 16px; margin-bottom: 8px; border-radius: 8px; }
    .dark .schedule-day-header { background: #374151; color: #f3f4f6; }
    #toggleCalendarBtn { pointer-events: auto !important; z-index: 9999 !important; position: relative; }
    .dark .fc { color: #e5e7eb; }
    .dark .fc .fc-button-primary { background-color: #374151; border-color: #4b5563; }
    .dark .fc .fc-button-primary:hover { background-color: #4b5563; }
    .dark .fc .fc-daygrid-day { background-color: #1f2937; border-color: #374151; }
    .dark .fc .fc-daygrid-day-number { color: #e5e7eb; }
    .dark .fc .fc-col-header-cell { background-color: #111827; color: #f3f4f6; }
    .dark .fc .fc-list-day-cushion { background-color: #1f2937; }
    .dark .fc .fc-list-event { background-color: #374151; }
    .fc .fc-toolbar.fc-header-toolbar { flex-wrap: wrap; }
    .fc .fc-toolbar-title { font-size: 1.2rem; font-weight: 600; }
    .fc .fc-button { background-color: #3b82f6; border-color: #3b82f6; color: white; text-transform: capitalize; transition: all 0.2s; }
    .fc .fc-button:hover { background-color: #2563eb; transform: translateY(-1px); }
    .fc .fc-button-primary:not(:disabled).fc-button-active,
    .fc .fc-button-primary:not(:disabled):active { background-color: #1d4ed8; }
    @media (max-width: 768px) {
        .fc .fc-toolbar { flex-direction: column; align-items: stretch; gap: 8px; }
        .fc .fc-toolbar > * { justify-content: center; }
        .fc .fc-button { padding: 0.4rem 0.6rem; font-size: 0.8rem; }
        .fc .fc-toolbar-title { font-size: 1rem; text-align: center; }
        .fc .fc-daygrid-day-number { font-size: 0.8rem; }
        .fc .fc-col-header-cell-cushion { font-size: 0.75rem; }
    }
    .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar_user.php'; ?>
    
    <div class="main-content-container flex-1 transition-all duration-300 ease-in-out">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openSidebarUserBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden" onclick="document.getElementById('sidebarUser').classList.remove('-translate-x-full'); document.getElementById('sidebarUser').classList.add('translate-x-0'); document.getElementById('sidebarOverlayUser').classList.remove('hidden'); document.body.classList.add('sidebar-open'); return false;">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Dashboard Guru</h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                        <i class="fas fa-moon text-xl dark:hidden"></i>
                        <i class="fas fa-sun text-xl hidden dark:inline"></i>
                    </button>
                    <div class="relative" id="notificationDropdown">
                        <button id="notificationBtn" class="relative text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white focus:outline-none">
                            <i class="fas fa-bell text-xl"></i>
                            <?php if ($unread_count > 0): ?>
                                <span id="unreadBadge" class="absolute -top-1 -right-2 bg-red-500 text-white text-xs rounded-full px-1.5 py-0.5 min-w-[18px] text-center"><?= $unread_count ?></span>
                            <?php else: ?>
                                <span id="unreadBadge" class="hidden"></span>
                            <?php endif; ?>
                        </button>
                        <div id="notificationPanel" class="absolute right-0 mt-2 w-80 bg-white dark:bg-gray-800 rounded-lg shadow-lg overflow-hidden z-30 hidden transition-all">
                            <div class="p-3 border-b dark:border-gray-700 font-semibold text-gray-700 dark:text-gray-200">
                                <i class="fas fa-bell mr-2"></i> Notifikasi
                                <button id="markAllReadBtn" class="text-xs text-blue-500 float-right hover:underline">Tandai semua dibaca</button>
                            </div>
                            <div id="notificationList" class="max-h-96 overflow-y-auto divide-y">
                                <?php if (empty($announcements_dropdown)): ?>
                                    <div class="p-4 text-center text-gray-500">Tidak ada pengumuman</div>
                                <?php else: ?>
                                    <?php foreach ($announcements_dropdown as $ann): 
                                        $is_read = in_array($ann['id'], $read_announcement_ids);
                                    ?>
                                        <div class="notification-item p-3 hover:bg-gray-50 dark:hover:bg-gray-700 transition <?= !$is_read ? 'bg-blue-50 dark:bg-blue-900/20' : '' ?>" data-id="<?= $ann['id'] ?>">
                                            <div class="flex justify-between items-start">
                                                <div class="flex-1">
                                                    <p class="font-medium text-gray-800 dark:text-white text-sm"><?= htmlspecialchars($ann['title']) ?></p>
                                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 line-clamp-2"><?= htmlspecialchars(strip_tags($ann['content'] ?? '')) ?></p>
                                                    <span class="text-xs text-gray-400 mt-1 block"><?= date('d M Y H:i', strtotime($ann['created_at'])) ?></span>
                                                </div>
                                                <?php if (!$is_read): ?>
                                                    <span class="w-2 h-2 bg-blue-500 rounded-full mt-1"></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div class="p-2 text-center border-t dark:border-gray-700">
                                <a href="announcements.php" class="text-xs text-blue-600 dark:text-blue-400">Lihat semua pengumuman</a>
                            </div>
                        </div>
                    </div>
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
            <!-- Card Jam & Tanggal -->
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 rounded-xl shadow-lg p-4 mb-6 text-white">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm opacity-90">Hari & Tanggal</p>
                        <h2 class="text-2xl font-bold" id="currentDate"></h2>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-right">
                            <p class="text-sm opacity-90">Waktu</p>
                            <h2 class="text-2xl font-bold" id="currentTime"></h2>
                        </div>
                        <button id="toggleCalendarBtn" class="bg-white/20 hover:bg-white/30 rounded-full p-2 transition">
                            <i id="calendarIcon" class="fas fa-chevron-down text-xl"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Kalender -->
            <div id="calendarArea" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 mb-6 calendar-collapsed" style="display:none;">
                <div class="flex flex-wrap justify-between items-center mb-4 gap-2">
                    <h3 class="text-md font-semibold text-gray-700 dark:text-gray-200">
                        <i class="fas fa-calendar-alt mr-2 text-blue-500"></i> Kalender Akademik
                    </h3>
                    <div class="flex gap-2">
                        <button data-stack="schedule" class="stack-filter px-3 py-1 text-xs rounded-full bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300 active">Jadwal</button>
                        <button data-stack="activity" class="stack-filter px-3 py-1 text-xs rounded-full bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-300">Kegiatan</button>
                        <button data-stack="holiday" class="stack-filter px-3 py-1 text-xs rounded-full bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300">Libur</button>
                    </div>
                </div>
                <div id="fullCalendar" class="dark:text-gray-200"></div>
            </div>

            <!-- Statistik Ringkas -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6 dark:text-white">
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $total_siswa ?></div>
                            <div class="stat-label">Total Siswa</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $total_absen_hari_ini ?></div>
                            <div class="stat-label">Absen Hari Ini</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon bg-yellow-100 text-yellow-600 dark:bg-yellow-900/30 dark:text-yellow-400">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $status_count_hari_ini['Terlambat'] ?></div>
                            <div class="stat-label">Terlambat</div>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="flex items-center gap-3">
                        <div class="stat-icon bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $hadir_persen_30hari ?>%</div>
                            <div class="stat-label">Kehadiran (30 Hari)</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Grafik -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl shadow-md p-4">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">
                        <i class="fas fa-chart-bar mr-2 text-blue-500"></i> Statistik Kehadiran 30 Hari Terakhir
                    </h3>
                    <div class="chart-container">
                        <canvas id="weeklyChart"></canvas>
                    </div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 flex flex-col items-center">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2 w-full">
                        <i class="fas fa-chart-pie mr-2 text-green-500"></i> Status Hari Ini
                    </h3>
                    <div class="chart-container-pie">
                        <canvas id="pieChart"></canvas>
                    </div>
                    <div class="flex flex-wrap justify-center gap-2 mt-2 text-xs">
                        <?php foreach ($pie_labels as $i => $label): ?>
                            <span class="inline-flex items-center">
                                <span class="w-3 h-3 rounded-full mr-1" style="background:<?= $pie_colors[$i] ?>"></span>
                                <?= $label ?> (<?= $pie_data[$i] ?>)
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Wali Kelas -->
            <?php if ($wali_kelas): ?>
                <div class="bg-gradient-to-r from-indigo-50 to-blue-50 dark:from-indigo-900/30 dark:to-blue-900/30 rounded-xl shadow-md p-5 mb-6 border border-indigo-100 dark:border-indigo-800">
                    <div class="flex items-center gap-4 flex-wrap">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-chalkboard-teacher text-indigo-600 dark:text-indigo-400 text-2xl"></i>
                            <span class="font-bold text-gray-800 dark:text-white">Wali Kelas:</span>
                        </div>
                        <span class="text-lg font-semibold text-indigo-700 dark:text-indigo-300"><?= htmlspecialchars($wali_kelas['class_name']) ?></span>
                        <span class="text-sm text-gray-600 dark:text-gray-400">| Jumlah Siswa: <?= $total_siswa ?></span>
                        <?php if (!empty($wali_kelas['room'])): ?>
                            <span class="text-sm text-gray-600 dark:text-gray-400">| Ruang: <?= htmlspecialchars($wali_kelas['room']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Jadwal Mengajar -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 mb-6">
                <div class="flex flex-wrap justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white"><i class="fas fa-calendar-alt mr-2 text-blue-500"></i> Jadwal Mengajar</h2>
                    <form method="GET" class="flex gap-2">
                        <select name="filter_day" class="border rounded-lg px-3 py-1 text-sm dark:bg-gray-700 dark:text-white">
                            <option value="0">Semua Hari</option>
                            <?php for ($i=1; $i<=7; $i++): ?>
                                <option value="<?= $i ?>" <?= ($filter_day == $i) ? 'selected' : '' ?>><?= $hari_map[$i] ?></option>
                            <?php endfor; ?>
                        </select>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-lg text-sm"><i class="fas fa-filter mr-1"></i> Filter</button>
                    </form>
                </div>
                <?php if (empty($jadwal_by_day)): ?>
                    <div class="text-center py-8 text-gray-500 dark:text-gray-400"><i class="fas fa-calendar-times text-4xl mb-2"></i><p>Tidak ada jadwal untuk filter ini</p></div>
                <?php else: ?>
                    <div class="schedule-timeline">
                        <?php foreach ($jadwal_by_day as $day => $day_schedules): ?>
                            <div class="schedule-day-header bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-white"><?= $hari_map[$day] ?></div>
                            <?php $jam_ke = 1; foreach ($day_schedules as $j):
                                $class_name = '-';
                                foreach ($classes as $c) if (is_array($c) && $c['id'] == $j['class_id']) { $class_name = $c['class_name']; break; }
                                $subject_name = '-';
                                foreach ($subjects as $sub) if (is_array($sub) && $sub['id'] == $j['subject_id']) { $subject_name = $sub['subject_name']; break; }
                            ?>
                                <div class="schedule-item">
                                    <div class="flex flex-wrap items-start gap-2 py-2">
                                        <div class="schedule-time-badge bg-indigo-100 dark:bg-indigo-900 text-indigo-800 dark:text-indigo-200">Jam ke-<?= $jam_ke++ ?></div>
                                        <div class="flex-1">
                                            <div class="font-semibold text-gray-800 dark:text-white"><?= htmlspecialchars($subject_name) ?></div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">Kelas: <?= htmlspecialchars($class_name) ?> • <?= $j['start_time'] ?> - <?= $j['end_time'] ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Daftar Siswa -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white"><i class="fas fa-users mr-2 text-green-500"></i> Daftar Siswa yang Diajar</h2>
                    <button onclick="openBatchAbsensiModal()" class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-1 rounded text-sm"><i class="fas fa-check-double mr-1"></i> Absensi Batch</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-2 text-left text-gray-700 dark:text-gray-300">No</th>
                                <th class="px-4 py-2 text-left text-gray-700 dark:text-gray-300">Nama</th>
                                <th class="px-4 py-2 text-left text-gray-700 dark:text-gray-300">NIS</th>
                                <th class="px-4 py-2 text-left text-gray-700 dark:text-gray-300">Kelas</th>
                                <th class="px-4 py-2 text-left text-gray-700 dark:text-gray-300">Aksi</th>
                            </td>
                        </thead>
                        <tbody>
                            <?php $no=1; foreach ($siswa as $s):
                                $kelas = '-';
                                if (!empty($s['kelas_pagi_id'])) {
                                    foreach ($classes as $c) if (is_array($c) && $c['id'] == $s['kelas_pagi_id']) { $kelas = $c['class_name']; break; }
                                }
                            ?>
                                <tr class="border-t border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="px-4 py-2 text-gray-800 dark:text-gray-200"><?= $no++ ?></td>
                                    <td class="px-4 py-2 font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($s['full_name']) ?></td>
                                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300"><?= htmlspecialchars($s['nidn_or_nisn'] ?? '-') ?></td>
                                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300"><?= $kelas ?></td>
                                    <td class="px-4 py-2">
                                        <button onclick="openAbsensiModal('<?= $s['id'] ?>', '<?= htmlspecialchars($s['full_name']) ?>')" class="bg-green-600 hover:bg-green-700 text-white px-2 py-1 rounded text-sm">
                                            <i class="fas fa-check-circle mr-1"></i> Absensi
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Riwayat Absensi -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">
                    <i class="fas fa-history mr-2 text-purple-500"></i> Riwayat Absensi Siswa (Terbaru)
                </h2>
                <?php
                if (empty($siswa)) {
                    echo '<div class="text-center py-8 text-gray-500 dark:text-gray-400"><i class="fas fa-users-slash text-4xl mb-2"></i><p>Belum ada siswa yang diajar.</p></div>';
                } else {
                    $student_names = [];
                    foreach ($siswa as $s) { $student_names[$s['id']] = $s['full_name']; }
                    $all_absensi_raw = supabase_admin_request('GET', 'attendance_logs', null, ['order' => 'scan_time.desc', 'limit' => 50]);
                    $all_absensi = is_array($all_absensi_raw) ? $all_absensi_raw : [];
                    $student_ids = array_map(function($s) { return $s['id']; }, $siswa);
                    $absensi = [];
                    foreach ($all_absensi as $log) {
                        if (is_array($log) && isset($log['user_id']) && in_array($log['user_id'], $student_ids)) {
                            $absensi[] = $log;
                        }
                    }
                    if (empty($absensi)): ?>
                        <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                            <i class="fas fa-hourglass-half text-4xl mb-2"></i>
                            <p>Belum ada riwayat absensi untuk siswa yang diajar.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm" id="riwayatTable">
                                <thead class="bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                    <tr><th class="px-3 py-2 text-left">Tanggal</th><th class="px-3 py-2 text-left">Jam</th><th class="px-3 py-2 text-left">Nama Siswa</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Catatan</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($absensi as $log):
                                        $siswa_name = $student_names[$log['user_id']] ?? 'Unknown';
                                        $waktu = date('H:i', strtotime($log['scan_time']));
                                        $tanggal = date('d M Y', strtotime($log['scan_time']));
                                        $status = $log['status'] ?? 'Hadir';
                                        $catatan = $log['note'] ?? '';
                                        $status_class = match($status) {
                                            'Hadir' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                                            'Terlambat' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
                                            'Izin', 'Sakit' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
                                            default => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                                        };
                                    ?>
                                        <tr class="border-t border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700">
                                            <td class="px-3 py-2 whitespace-nowrap text-gray-800 dark:text-gray-200"><?= $tanggal ?></td>
                                            <td class="px-3 py-2 whitespace-nowrap text-gray-600 dark:text-gray-400"><?= $waktu ?></td>
                                            <td class="px-3 py-2 font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($siswa_name) ?></td>
                                            <td class="px-3 py-2"><span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold <?= $status_class ?>"><?= $status ?></span></td>
                                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400 text-xs max-w-xs truncate"><?= htmlspecialchars($catatan) ?: '-' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif;
                } ?>
                <div class="flex flex-wrap gap-3 mb-4 items-end mt-4">
                    <div class="flex-1 min-w-[150px]">
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Cari Nama / Catatan</label>
                        <input type="text" id="searchAbsensi" placeholder="Ketik nama atau catatan..." class="w-full border rounded-lg px-3 py-1.5 text-sm dark:bg-gray-700 dark:border-gray-600">
                    </div>
                    <div class="w-36">
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Filter Status</label>
                        <select id="filterStatus" class="w-full border rounded-lg px-3 py-1.5 text-sm dark:bg-gray-700 dark:border-gray-600">
                            <option value="">Semua</option>
                            <option value="Hadir">Hadir</option>
                            <option value="Terlambat">Terlambat</option>
                            <option value="Izin">Izin</option>
                            <option value="Sakit">Sakit</option>
                            <option value="Alpha">Alpha</option>
                        </select>
                    </div>
                    <div>
                        <button id="resetFilterBtn" class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-1.5 rounded-lg text-sm"><i class="fas fa-undo-alt mr-1"></i> Reset</button>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- ========== MODAL-MODAL ========== -->
<!-- Modal Absensi Manual -->
<div id="absensiModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-96 max-w-full">
        <h3 class="text-lg font-bold mb-4">Absensi Siswa</h3>
        <form id="absensiForm">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" id="siswaId">
            <input type="hidden" id="tanggalAbsen" name="tanggal" value="">
            <div class="mb-3"><label>Nama Siswa</label><input type="text" id="siswaName" readonly class="w-full border rounded px-2 py-1 bg-gray-100 dark:bg-gray-700"></div>
            <div class="mb-3"><label>Pilih Jadwal</label>
                <select id="jadwalId" class="w-full border rounded px-2 py-1" required>
                    <option value="">-- Pilih Jadwal --</option>
                    <?php foreach ($jadwal_dropdown as $j): 
                        $class_name = '-'; foreach ($classes as $c) if (is_array($c) && $c['id'] == $j['class_id']) { $class_name = $c['class_name']; break; }
                        $subject_name = '-'; foreach ($subjects as $sub) if (is_array($sub) && $sub['id'] == $j['subject_id']) { $subject_name = $sub['subject_name']; break; }
                        $hari_teks = $hari_map[$j['day_of_week']] ?? 'Hari ?';
                    ?>
                        <option value="<?= $j['id'] ?>" data-day="<?= $j['day_of_week'] ?>">
                            <?= "$hari_teks - $class_name - $subject_name (".$j['start_time']." - ".$j['end_time'].")" ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3"><label>Status</label>
                <select id="statusAbsen" class="w-full border rounded px-2 py-1">
                    <option value="Hadir">Hadir</option>
                    <option value="Terlambat">Terlambat</option>
                    <option value="Izin">Izin</option>
                    <option value="Sakit">Sakit</option>
                    <option value="Alpha">Alpha</option>
                </select>
            </div>
            <div class="flex justify-end space-x-2 mt-4">
                <button type="button" onclick="closeAbsensiModal()" class="bg-gray-400 text-white px-4 py-2 rounded">Batal</button>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Batch Absensi -->
<div id="batchAbsensiModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-800 dark:text-white">Absensi Batch (Satu Kelas)</h3>
            <button onclick="closeBatchAbsensiModal()" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
        </div>
        <form id="batchAbsensiForm">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" id="batchTanggalAbsen" name="tanggal" value="">
            <div class="mb-3 flex items-center gap-2">
                <span class="font-medium text-gray-700 dark:text-gray-300">Tanggal:</span>
                <span id="batchTanggalDisplay" class="text-gray-800 dark:text-white font-semibold"></span>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Pilih Jadwal</label>
                <select id="batchJadwalId" class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white" required>
                    <option value="">-- Pilih Jadwal --</option>
                    <?php foreach ($jadwal_dropdown as $j): 
                        $class_name = '-'; foreach ($classes as $c) if (is_array($c) && $c['id'] == $j['class_id']) { $class_name = $c['class_name']; break; }
                        $subject_name = '-'; foreach ($subjects as $sub) if (is_array($sub) && $sub['id'] == $j['subject_id']) { $subject_name = $sub['subject_name']; break; }
                        $hari_teks = $hari_map[$j['day_of_week']] ?? 'Hari ?';
                    ?>
                        <option value="<?= $j['id'] ?>" data-day="<?= $j['day_of_week'] ?>" data-class-id="<?= $j['class_id'] ?>">
                            <?= "$hari_teks - $class_name - $subject_name (".$j['start_time']." - ".$j['end_time'].")" ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="batchSiswaList" class="space-y-2 max-h-96 overflow-y-auto">
                <!-- akan diisi JS -->
            </div>
            <div class="flex justify-end space-x-2 mt-4">
                <button type="button" onclick="closeBatchAbsensiModal()" class="bg-gray-400 text-white px-4 py-2 rounded">Batal</button>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Simpan Semua</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Detail Event -->
<div id="eventDetailModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-md mx-4 event-modal">
        <div class="flex justify-between items-center p-4 border-b dark:border-gray-700">
            <h3 class="text-lg font-bold text-gray-800 dark:text-white">Detail Event</h3>
            <button onclick="closeEventModal()" class="text-gray-500 hover:text-gray-700 dark:text-gray-400"><i class="fas fa-times text-xl"></i></button>
        </div>
        <div class="p-4 modal-content">
            <div class="mb-3"><label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Judul</label><p class="text-gray-800 dark:text-white font-semibold" id="eventModalJudul"></p></div>
            <div class="mb-3"><label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Mulai</label><p class="text-gray-700 dark:text-gray-300" id="eventModalMulai"></p></div>
            <div class="mb-3"><label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Selesai</label><p class="text-gray-700 dark:text-gray-300" id="eventModalSelesai"></p></div>
            <div class="mb-3"><label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Tipe</label><p class="text-gray-700 dark:text-gray-300" id="eventModalTipe"></p></div>
        </div>
        <div class="flex justify-end p-4 border-t dark:border-gray-700">
            <button onclick="closeEventModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Tutup</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ========== REAL TIME CLOCK ==========
function updateDateTime() {
    const now = new Date();
    document.getElementById('currentDate').innerText = now.toLocaleDateString('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    document.getElementById('currentTime').innerText = now.toLocaleTimeString('id-ID');
}
updateDateTime();
setInterval(updateDateTime, 1000);

// ========== KALENDER FULLCALENDAR ==========
let calendar = null;
let currentEvents = [];
let activeStacks = { schedule: true, activity: true, holiday: true };

async function loadCalendarEvents(start, end) {
    try {
        const cleanStart = start.split('T')[0];
        const cleanEnd = end.split('T')[0];
        const res = await fetch(`api/calendar_events.php?start=${cleanStart}&end=${cleanEnd}`);
        const events = await res.json();
        currentEvents = events;
        return filterEventsByStack(events, activeStacks);
    } catch(e) {
        console.error('Gagal memuat event kalender:', e);
        return [];
    }
}

function filterEventsByStack(events, stacks) {
    return events.filter(event => {
        const type = event.extendedProps?.type;
        if (type === 'schedule') return stacks.schedule;
        if (type === 'activity') return stacks.activity;
        if (type === 'holiday') return stacks.holiday;
        return true;
    });
}

async function initCalendar() {
    const calendarEl = document.getElementById('fullCalendar');
    if (!calendarEl) return;
    calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        locale: 'id',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,listWeek'
        },
        events: async (fetchInfo, successCallback) => {
            const events = await loadCalendarEvents(fetchInfo.startStr, fetchInfo.endStr);
            successCallback(events);
        },
        eventClick: (info) => {
            info.jsEvent.preventDefault();
            showEventDetail(info.event);
        },
        height: 'auto',
        firstDay: 1,
        buttonText: {
            today: 'Hari Ini',
            month: 'Bulan',
            week: 'Minggu',
            list: 'Daftar'
        },
        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
        windowResize: function() {
            if (calendar) calendar.updateSize();
        }
    });
    calendar.render();
}

async function refreshCalendar() {
    if (calendar) {
        const filteredEvents = filterEventsByStack(currentEvents, activeStacks);
        calendar.removeAllEvents();
        calendar.addEventSource(filteredEvents);
    } else {
        await initCalendar();
    }
}

function showEventDetail(event) {
    const modal = document.getElementById('eventDetailModal');
    document.getElementById('eventModalJudul').innerText = event.title;
    document.getElementById('eventModalMulai').innerText = event.startStr || '-';
    document.getElementById('eventModalSelesai').innerText = event.endStr || '-';
    const type = event.extendedProps.type;
    let typeLabel = '', badgeClass = '';
    if (type === 'schedule') { typeLabel = '📚 Jadwal Mengajar'; badgeClass = 'bg-blue-100 text-blue-800'; }
    else if (type === 'activity') { typeLabel = '🎉 Kegiatan'; badgeClass = 'bg-orange-100 text-orange-800'; }
    else if (type === 'holiday') { typeLabel = '🌴 Libur'; badgeClass = 'bg-red-100 text-red-800'; }
    else { typeLabel = type; badgeClass = 'bg-gray-100 text-gray-800'; }
    document.getElementById('eventModalTipe').innerHTML = `<span class="inline-block px-2 py-1 rounded-full text-xs font-semibold ${badgeClass}">${typeLabel}</span>`;
    modal.classList.remove('hidden'); modal.classList.add('flex');
}

function closeEventModal() {
    document.getElementById('eventDetailModal').classList.add('hidden'); document.getElementById('eventDetailModal').classList.remove('flex');
}

document.querySelectorAll('.stack-filter').forEach(btn => {
    btn.addEventListener('click', () => {
        const stack = btn.getAttribute('data-stack');
        if (stack === 'schedule') activeStacks.schedule = !activeStacks.schedule;
        else if (stack === 'activity') activeStacks.activity = !activeStacks.activity;
        else if (stack === 'holiday') activeStacks.holiday = !activeStacks.holiday;
        btn.classList.toggle('opacity-50'); btn.classList.toggle('bg-opacity-50');
        refreshCalendar();
    });
});

document.addEventListener('DOMContentLoaded', () => { initCalendar(); });

document.getElementById('closeSidebarUser')?.addEventListener('click', function() { 
    document.getElementById('sidebarUser').classList.add('-translate-x-full'); 
    document.getElementById('sidebarUser').classList.remove('translate-x-0'); 
    document.getElementById('sidebarOverlayUser').classList.add('hidden'); 
    document.body.classList.remove('sidebar-open'); 
});
document.getElementById('sidebarOverlayUser')?.addEventListener('click', function() { 
    document.getElementById('sidebarUser').classList.add('-translate-x-full'); 
    document.getElementById('sidebarUser').classList.remove('translate-x-0'); 
    document.getElementById('sidebarOverlayUser').classList.add('hidden'); 
    document.body.classList.remove('sidebar-open'); 
});

// ========== TOGGLE KALENDER ==========
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('toggleCalendarBtn');
    const calendarArea = document.getElementById('calendarArea');
    const calendarIcon = document.getElementById('calendarIcon');
    if (!toggleBtn) return;
    calendarArea.style.display = 'none';
    calendarIcon.classList.remove('fa-chevron-up'); calendarIcon.classList.add('fa-chevron-down');
    let isExpanded = false;
    toggleBtn.addEventListener('click', function(e) {
        e.preventDefault();
        isExpanded = !isExpanded;
        if (isExpanded) {
            calendarArea.style.display = 'block';
            calendarIcon.classList.remove('fa-chevron-down'); calendarIcon.classList.add('fa-chevron-up');
            if (typeof calendar !== 'undefined' && calendar) setTimeout(() => calendar.updateSize(), 100);
        } else {
            calendarArea.style.display = 'none';
            calendarIcon.classList.remove('fa-chevron-up'); calendarIcon.classList.add('fa-chevron-down');
        }
    });
});

// ========== TANGGAL OTOMATIS ==========
function getDateForDay(dayOfWeek) {
    const now = new Date();
    let targetDay = dayOfWeek;
    if (targetDay === 7) targetDay = 0;
    let diff = targetDay - now.getDay();
    if (diff < 0) diff += 7;
    const date = new Date(now);
    date.setDate(now.getDate() + diff);
    return date.toISOString().split('T')[0];
}

function getTodayDayOfWeek() {
    const d = new Date().getDay();
    return d === 0 ? 7 : d;
}

// ========== ABSENSI MANUAL ==========
const modal = document.getElementById('absensiModal');
const jadwalSelect = document.getElementById('jadwalId');
const tanggalInput = document.getElementById('tanggalAbsen');

jadwalSelect.addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const day = selectedOption.getAttribute('data-day');
    if (day) { tanggalInput.value = getDateForDay(parseInt(day)); }
    else { tanggalInput.value = ''; }
});

function openAbsensiModal(id, name) {
    document.getElementById('siswaId').value = id;
    document.getElementById('siswaName').value = name;
    if (jadwalSelect.options.length > 1) {
        jadwalSelect.selectedIndex = 1;
        jadwalSelect.dispatchEvent(new Event('change'));
    }
    modal.classList.remove('hidden'); modal.classList.add('flex');
}

function closeAbsensiModal() {
    modal.classList.add('hidden'); modal.classList.remove('flex');
}

document.getElementById('absensiForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const userId = document.getElementById('siswaId').value;
    const jadwalId = jadwalSelect.value;
    const status = document.getElementById('statusAbsen').value;
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;
    const tanggal = tanggalInput.value;
    if (!jadwalId) { showToast('Pilih jadwal terlebih dahulu', 'warning'); return; }
    if (!tanggal) { showToast('Tanggal tidak valid', 'warning'); return; }
    const submitBtn = e.submitter;
    const originalText = submitBtn.innerText;
    submitBtn.innerText = 'Menyimpan...'; submitBtn.disabled = true;
    try {
        const res = await fetch('api/proses_absensi_manual.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId, schedule_id: jadwalId, status: status, tanggal: tanggal, csrf_token: csrfToken })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Absensi berhasil', 'success');
            playBeep('success');
            closeAbsensiModal();
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('Gagal: ' + data.message, 'error');
            playBeep('error');
            submitBtn.innerText = originalText; submitBtn.disabled = false;
        }
    } catch(e) {
        showToast('Terjadi kesalahan: ' + e.message, 'error');
        playBeep('error');
        submitBtn.innerText = originalText; submitBtn.disabled = false;
    }
});

// ========== BATCH ABSENSI ==========
const studentsData = <?= json_encode($siswa) ?>;
const batchModal = document.getElementById('batchAbsensiModal');
const batchJadwalSelect = document.getElementById('batchJadwalId');
const batchTanggalInput = document.getElementById('batchTanggalAbsen');
const batchTanggalDisplay = document.getElementById('batchTanggalDisplay');
const batchSiswaList = document.getElementById('batchSiswaList');

batchJadwalSelect.addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const day = selectedOption.getAttribute('data-day');
    let tanggal = '';
    if (day) {
        tanggal = getDateForDay(parseInt(day));
        batchTanggalInput.value = tanggal;
        const dateObj = new Date(tanggal + 'T00:00:00');
        batchTanggalDisplay.innerText = dateObj.toLocaleDateString('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    } else {
        batchTanggalInput.value = '';
        batchTanggalDisplay.innerText = '';
    }
    let classId = selectedOption.getAttribute('data-class-id');
    if (!classId) { batchSiswaList.innerHTML = '<div class="text-center text-gray-500">Pilih jadwal terlebih dahulu</div>'; return; }
    classId = parseInt(classId);
    const students = getStudentsByClassId(classId);
    if (students.length === 0) {
        batchSiswaList.innerHTML = '<div class="text-center text-gray-500">Tidak ada siswa yang terdaftar di kelas ini</div>';
    } else {
        renderBatchStudentList(students);
    }
});

function closeBatchAbsensiModal() {
    batchModal.classList.add('hidden'); batchModal.classList.remove('flex');
}

function openBatchAbsensiModal() {
    batchModal.classList.remove('hidden'); batchModal.classList.add('flex');
    const todayDay = getTodayDayOfWeek();
    let selectedIndex = 0;
    for (let i = 0; i < batchJadwalSelect.options.length; i++) {
        const opt = batchJadwalSelect.options[i];
        if (opt.getAttribute('data-day') == todayDay) {
            selectedIndex = i;
            break;
        }
    }
    batchJadwalSelect.selectedIndex = selectedIndex;
    batchJadwalSelect.dispatchEvent(new Event('change'));
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function getStudentsByClassId(classId) {
    const targetClassId = parseInt(classId);
    return studentsData.filter(s => {
        const pagiId = s.kelas_pagi_id ? parseInt(s.kelas_pagi_id) : null;
        const diniyyahId = s.kelas_diniyyah_id ? parseInt(s.kelas_diniyyah_id) : null;
        return pagiId === targetClassId || diniyyahId === targetClassId;
    });
}

function renderBatchStudentList(students) {
    if (!students.length) {
        batchSiswaList.innerHTML = '<div class="text-center text-gray-500">Tidak ada siswa yang terdaftar di kelas ini</div>';
        return;
    }
    let html = `<table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
        <thead class="bg-gray-50 dark:bg-gray-700"><tr><th class="px-4 py-2 text-left">Nama Siswa</th><th class="px-4 py-2 text-left">NIS</th><th class="px-4 py-2 text-left">Status</th></tr></thead><tbody>`;
    students.forEach(s => {
        html += `<tr><td class="px-4 py-2">${escapeHtml(s.full_name)}</td>
            <td class="px-4 py-2">${escapeHtml(s.nidn_or_nisn || '-')}</td>
            <td class="px-4 py-2"><select data-user-id="${s.id}" class="batch-status w-32 border rounded px-2 py-1 dark:bg-gray-700">
                <option value="Hadir" selected>Hadir</option><option value="Terlambat">Terlambat</option>
                <option value="Izin">Izin</option><option value="Sakit">Sakit</option><option value="Alpha">Alpha</option>
            </select></td></tr>`;
    });
    html += '</tbody></table>';
    batchSiswaList.innerHTML = html;
}

document.getElementById('batchAbsensiForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const scheduleId = batchJadwalSelect.value;
    if (!scheduleId) { showToast('Pilih jadwal terlebih dahulu', 'warning'); return; }
    const csrfToken = this.querySelector('input[name="csrf_token"]').value;
    const tanggal = batchTanggalInput.value;
    if (!tanggal) { showToast('Tanggal tidak valid', 'warning'); return; }
    const statusSelects = document.querySelectorAll('#batchSiswaList .batch-status');
    if (statusSelects.length === 0) { showToast('Tidak ada siswa untuk diabsensi', 'warning'); return; }
    const attendances = {};
    for (let select of statusSelects) {
        const userId = select.getAttribute('data-user-id');
        const status = select.value;
        if (userId && status) attendances[userId] = status;
    }
    if (Object.keys(attendances).length === 0) { showToast('Tidak ada data absensi', 'warning'); return; }
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerText;
    submitBtn.innerText = 'Menyimpan...'; submitBtn.disabled = true;
    try {
        const res = await fetch('api/proses_absensi_batch.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ schedule_id: scheduleId, attendances: attendances, tanggal: tanggal, csrf_token: csrfToken })
        });
        const data = await res.json();
        if (data.success) {
            showToast(`✅ ${data.message}`, 'success');
            playBeep('success');
            closeBatchAbsensiModal();
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('❌ Gagal: ' + data.message, 'error');
            playBeep('error');
            submitBtn.innerText = originalText; submitBtn.disabled = false;
        }
    } catch (err) {
        showToast('Terjadi kesalahan: ' + err.message, 'error');
        playBeep('error');
        submitBtn.innerText = originalText; submitBtn.disabled = false;
    }
});

/// ========== CHARTS ==========
document.addEventListener('DOMContentLoaded', function() {
    const ctxBar = document.getElementById('weeklyChart').getContext('2d');
    new Chart(ctxBar, {
        type: 'line',
        data: {
            labels: <?= json_encode($labels_30hari) ?>,
            datasets: [
                {
                    label: 'Hadir',
                    data: <?= json_encode($data_hadir) ?>,
                    borderColor: '#22c55e',
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: false,
                    pointBackgroundColor: '#22c55e',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                },
                {
                    label: 'Terlambat',
                    data: <?= json_encode($data_terlambat) ?>,
                    borderColor: '#eab308',
                    backgroundColor: 'rgba(234, 179, 8, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: false,
                    pointBackgroundColor: '#eab308',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                },
                {
                    label: 'Izin / Sakit',
                    data: <?= json_encode($data_izin_sakit) ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: false,
                    pointBackgroundColor: '#3b82f6',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        boxWidth: 12,
                        font: { size: 10 },
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        font: { size: 10 }
                    },
                    grid: {
                        color: 'rgba(0,0,0,0.05)'
                    }
                },
                x: {
                    ticks: {
                        font: { size: 8 },
                        maxRotation: 45,
                        minRotation: 0,
                        autoSkip: true,
                        maxTicksLimit: 30
                    },
                    grid: {
                        display: false
                    }
                }
            },
            elements: {
                line: {
                    borderJoinStyle: 'round'
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            }
        }
    });

    const ctxPie = document.getElementById('pieChart').getContext('2d');
    new Chart(ctxPie, {
        type: 'pie',
        data: {
            labels: <?= json_encode($pie_labels) ?>,
            datasets: [{
                data: <?= json_encode($pie_data) ?>,
                backgroundColor: <?= json_encode($pie_colors) ?>,
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            cutout: '60%'
        }
    });
});

// ========== DARK MODE ==========
const darkModeToggle = document.getElementById('darkModeToggle');
function setDarkMode(isDark) {
    if (isDark) document.documentElement.classList.add('dark');
    else document.documentElement.classList.remove('dark');
    localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled');
    if (darkModeToggle) {
        const moonIcon = darkModeToggle.querySelector('.fa-moon');
        const sunIcon = darkModeToggle.querySelector('.fa-sun');
        if (moonIcon && sunIcon) {
            moonIcon.classList.toggle('hidden', isDark);
            sunIcon.classList.toggle('hidden', !isDark);
        }
    }
}
const savedDarkMode = localStorage.getItem('darkMode');
if (savedDarkMode === 'enabled') setDarkMode(true);
else if (savedDarkMode === 'disabled') setDarkMode(false);
else setDarkMode(window.matchMedia('(prefers-color-scheme: dark)').matches);
if (darkModeToggle) {
    darkModeToggle.addEventListener('click', () => {
        setDarkMode(!document.documentElement.classList.contains('dark'));
    });
}

// ========== FILTER RIWAYAT ==========
const searchInput = document.getElementById('searchAbsensi');
const statusFilter = document.getElementById('filterStatus');
const resetBtn = document.getElementById('resetFilterBtn');
const absensiRows = document.querySelectorAll('#riwayatTable tbody tr');

function filterTable() {
    const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
    const statusVal = statusFilter ? statusFilter.value : '';
    let visibleCount = 0;
    absensiRows.forEach(row => {
        const nama = row.querySelector('td:nth-child(3)')?.innerText.toLowerCase() || '';
        const catatan = row.querySelector('td:nth-child(5)')?.innerText.toLowerCase() || '';
        const statusSpan = row.querySelector('td:nth-child(4) span');
        const status = statusSpan ? statusSpan.innerText : '';
        let show = true;
        if (searchTerm && !nama.includes(searchTerm) && !catatan.includes(searchTerm)) show = false;
        if (statusVal && status !== statusVal) show = false;
        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });
    let noDataRow = document.getElementById('noDataRow');
    if (visibleCount === 0) {
        if (!noDataRow) {
            const tbody = document.querySelector('#riwayatTable tbody');
            const tr = document.createElement('tr');
            tr.id = 'noDataRow';
            tr.innerHTML = `<td colspan="5" class="text-center py-4 text-gray-500">Tidak ada data sesuai filter</td>`;
            tbody.appendChild(tr);
        }
    } else {
        if (noDataRow) noDataRow.remove();
    }
}
if (searchInput) searchInput.addEventListener('keyup', filterTable);
if (statusFilter) statusFilter.addEventListener('change', filterTable);
if (resetBtn) {
    resetBtn.addEventListener('click', () => {
        if (searchInput) searchInput.value = '';
        if (statusFilter) statusFilter.value = '';
        filterTable();
    });
}
</script>
<script src="assets/js/notifications_info.js"></script>

<?php require_once __DIR__ . '/includes/footer_user.php'; ?>