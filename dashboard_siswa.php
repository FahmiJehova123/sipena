<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'student') {
    header('Location: login.php');
    exit;
}
if (time() - $_SESSION['login_time'] > 28800) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['login_time'] = time();

$page_title = 'Dashboard Siswa - SIAKAD';
$current_page = 'dashboard';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Generate CSRF token jika belum ada
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'];

// Ambil data user
$user_data = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $user_id]);
$user = (is_array($user_data) && !empty($user_data)) ? $user_data[0] : null;
if (!$user) { header('Location: logout.php'); exit; }

// Kelas pagi & diniyyah
$kelas_pagi = '-';
if (!empty($user['kelas_pagi_id'])) {
    $kelas = supabase_admin_request('GET', 'classes', null, ['id' => 'eq.' . $user['kelas_pagi_id']]);
    $kelas_pagi = (is_array($kelas) && !empty($kelas)) ? $kelas[0]['class_name'] : '-';
}
$kelas_diniyyah = '-';
if (!empty($user['kelas_diniyyah_id'])) {
    $kelas = supabase_admin_request('GET', 'classes', null, ['id' => 'eq.' . $user['kelas_diniyyah_id']]);
    $kelas_diniyyah = (is_array($kelas) && !empty($kelas)) ? $kelas[0]['class_name'] : '-';
}

$full_name = htmlspecialchars($user['full_name'] ?? '-');
$nis = htmlspecialchars($user['nidn_or_nisn'] ?? '-');
$photo_url = !empty($user['photo_url']) ? $user['photo_url'] : '';

// ========== STATISTIK KEAKTIFAN ==========
// Fungsi untuk mendapatkan data absensi berdasarkan rentang tanggal
function getAttendanceStats($user_id, $start_date, $end_date) {
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
        if (isset($stats[$status])) {
            $stats[$status]++;
        }
    }
    $stats['total'] = array_sum($stats);
    $stats['persentase'] = $stats['total'] > 0 ? round(($stats['Hadir'] / $stats['total']) * 100) : 0;
    return $stats;
}

// 1. Data untuk 1 bulan terakhir (30 hari)
$end_date = date('Y-m-d');
$start_date_30 = date('Y-m-d', strtotime('-29 days'));
$stats_30hari = getAttendanceStats($user_id, $start_date_30, $end_date);

// 2. Data untuk 1 tahun pelajaran (asumsi tahun pelajaran dimulai bulan Juli)
$current_month = (int)date('m');
$current_year = (int)date('Y');
if ($current_month >= 7) {
    $tahun_ajaran_start = $current_year . '-07-01';
    $tahun_ajaran_end = ($current_year + 1) . '-06-30';
} else {
    $tahun_ajaran_start = ($current_year - 1) . '-07-01';
    $tahun_ajaran_end = $current_year . '-06-30';
}
$stats_tahunan = getAttendanceStats($user_id, $tahun_ajaran_start, $tahun_ajaran_end);

// 3. Data harian untuk 30 hari terakhir (untuk progress bar)
$daily_stats = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $daily_stats[$date] = ['Hadir' => 0, 'Terlambat' => 0, 'Izin' => 0, 'Sakit' => 0, 'Alpha' => 0, 'total' => 0];
    // Cari log pada tanggal ini
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

// --- Jadwal (dengan pengelompokan hari) ---
$schedules_raw = [];
if ($user['kelas_pagi_id']) {
    $s = supabase_admin_request('GET', 'schedules', null, ['class_id' => 'eq.' . $user['kelas_pagi_id']]);
    if (is_array($s)) $schedules_raw = array_merge($schedules_raw, $s);
}
if ($user['kelas_diniyyah_id']) {
    $s = supabase_admin_request('GET', 'schedules', null, ['class_id' => 'eq.' . $user['kelas_diniyyah_id']]);
    if (is_array($s)) $schedules_raw = array_merge($schedules_raw, $s);
}
usort($schedules_raw, function($a, $b) {
    if ($a['day_of_week'] == $b['day_of_week']) return strcmp($a['start_time'], $b['start_time']);
    return $a['day_of_week'] - $b['day_of_week'];
});

// Filter hari (default hari ini)
$filter_day = isset($_GET['filter_day']) ? (int)$_GET['filter_day'] : date('N');
if ($filter_day == 0) $filter_day = null;
$schedules = [];
if ($filter_day) {
    foreach ($schedules_raw as $s) {
        if ($s['day_of_week'] == $filter_day) $schedules[] = $s;
    }
} else {
    $schedules = $schedules_raw;
}
$hari_map = [1=>'Senin',2=>'Selasa',3=>'Rabu',4=>'Kamis',5=>'Jumat',6=>'Sabtu',7=>'Minggu'];

// Kelompokkan jadwal per hari untuk tampilan (nama hari hanya sekali)
$schedules_by_day = [];
foreach ($schedules as $s) {
    $day = $s['day_of_week'];
    if (!isset($schedules_by_day[$day])) {
        $schedules_by_day[$day] = [];
    }
    $schedules_by_day[$day][] = $s;
}

// --- Riwayat Absensi (dengan pengelompokan per tanggal) ---
$absensi_raw = supabase_admin_request('GET', 'attendance_logs', null, [
    'user_id' => 'eq.' . $user_id,
    'order' => 'scan_time.desc',
    'limit' => 30
]);
$absensi = is_array($absensi_raw) ? $absensi_raw : [];
foreach ($absensi as &$log) {
    if (!empty($log['activity_id'])) {
        $act = supabase_admin_request('GET', 'activities', null, ['id' => 'eq.' . $log['activity_id']]);
        $log['activity_name'] = (is_array($act) && !empty($act)) ? $act[0]['name'] : '-';
    } else {
        $log['activity_name'] = '-';
    }
    $log['date_only'] = date('Y-m-d', strtotime($log['scan_time']));
    $log['day_name'] = date('l', strtotime($log['scan_time']));
}
unset($log);

// Kelompokkan berdasarkan tanggal
$absensi_by_date = [];
foreach ($absensi as $a) {
    $date = $a['date_only'];
    if (!isset($absensi_by_date[$date])) {
        $absensi_by_date[$date] = [];
    }
    $absensi_by_date[$date][] = $a;
}

// ========== NOTIFIKASI UNTUK SISWA ==========
// Ambil SEMUA pengumuman aktif untuk siswa (target_role = 'student' atau 'all')
$announcements_raw = supabase_admin_request('GET', 'announcements', null, [
    'is_active' => 'eq.true',
    'target_role' => 'in.(student,all)',
    'order' => 'created_at.desc'
]);
$announcements = safeArray($announcements_raw);

// Ambil SEMUA read untuk user ini (siswa)
$reads_raw = supabase_admin_request('GET', 'announcement_reads', null, [
    'user_id' => 'eq.' . $user_id
]);
$reads = safeArray($reads_raw);
$read_announcement_ids = array_column($reads, 'announcement_id');

// Hitung unread (belum dibaca)
$unread_count = 0;
foreach ($announcements as $ann) {
    if (!in_array($ann['id'], $read_announcement_ids)) {
        $unread_count++;
    }
}

// Untuk tampilan dropdown notifikasi, batasi 10 terbaru
$announcements_dropdown = array_slice($announcements, 0, 10);

require_once __DIR__ . '/includes/header_user.php';
?>

<style>
    /* Gaya tambahan untuk dark mode yang benar */
    .dark .bg-white { background-color: #1f2937 !important; }
    .dark .text-gray-800 { color: #f3f4f6 !important; }
    .dark .text-gray-600 { color: #d1d5db !important; }
    .dark .border-gray-200 { border-color: #374151 !important; }
    .dark .bg-gray-50 { background-color: #111827 !important; }
    .dark .bg-gray-100 { background-color: #111827 !important; }
    
    /* Gaya timeline untuk jadwal (penomoran jam ke dengan garis) */
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
    .schedule-item:last-child {
        margin-bottom: 0;
    }
    .schedule-time-badge {
        position: relative;
        display: inline-block;
        font-weight: 600;
        color: #1f2937;
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
    
    .absensi-date-header {
        background-color: #f3f4f6;
        font-weight: 600;
        padding: 8px 12px;
        border-radius: 8px;
        margin-top: 12px;
    }
    .dark .absensi-date-header {
        background-color: #2d3748;
        color: #f3f4f6;
    }
    .timeline-dot {
        width: 10px;
        height: 10px;
        background-color: #3b82f6;
        border-radius: 50%;
        display: inline-block;
        margin-right: 12px;
    }
    .schedule-day-header {
        background-color: #e5e7eb;
        font-weight: bold;
        padding: 8px;
        margin-top: 16px;
        margin-bottom: 8px;
        border-radius: 8px;
    }
    .dark .schedule-day-header {
        background-color: #374151;
        color: #f3f4f6;
    }
    .schedule-day-header:first-of-type {
        margin-top: 0;
    }

    /* ===== GAYA STATISTIK KEAKTIFAN ===== */
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

    /* Progress bar */
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

    .metric-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
        gap: 8px;
    }
    .metric-item {
        text-align: center;
        padding: 6px 4px;
        border-radius: 8px;
        background: #f9fafb;
    }
    .dark .metric-item { background: #374151; }
    .metric-value { font-size: 1.1rem; font-weight: 700; }
    .metric-label { font-size: 0.6rem; color: #6b7280; text-transform: uppercase; }
    .dark .metric-label { color: #9ca3af; }

    .legend-dot {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        margin-right: 4px;
    }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar_user.php'; ?>
    
    <div class="main-content-container flex-1 transition-all duration-300 ease-in-out">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openSidebarUserBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden" onclick="document.getElementById('sidebarUser').classList.remove('-translate-x-full'); document.getElementById('sidebarUser').classList.add('translate-x-0'); document.getElementById('sidebarOverlayUser').classList.remove('hidden'); document.body.classList.add('sidebar-open'); return false;">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Dashboard Siswa</h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                        <i class="fas fa-moon text-xl dark:hidden"></i>
                        <i class="fas fa-sun text-xl hidden dark:inline"></i>
                    </button>
                    
                    <!-- Notifikasi Pengumuman -->
                    <div class="relative" id="notificationDropdown">
                        <button id="notificationBtn" class="relative text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white focus:outline-none">
                            <i class="fas fa-bell text-xl"></i>
                            <?php if ($unread_count > 0): ?>
                                <span id="unreadBadge" class="absolute -top-1 -right-2 bg-red-500 text-white text-xs rounded-full px-1.5 py-0.5 min-w-[18px] text-center"><?= $unread_count ?></span>
                            <?php else: ?>
                                <span id="unreadBadge" class="hidden"></span>
                            <?php endif; ?>
                        </button>
                        <!-- Dropdown notifikasi -->
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
                    
                    <!-- Profile User -->
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
            <!-- Profil singkat dengan link ke ID Card -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 mb-8 flex flex-wrap items-center gap-4 transition-all">
                <div class="w-20 h-20 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center overflow-hidden shadow-lg">
                    <?php if ($photo_url): ?>
                        <img src="<?= $photo_url ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                        <i class="fas fa-user-graduate text-4xl text-white"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <h2 class="text-2xl font-bold text-gray-800 dark:text-white"><?= $full_name ?></h2>
                    <p class="text-gray-600 dark:text-gray-400"><i class="fas fa-id-card mr-1"></i> NIS: <?= $nis ?> | <i class="fas fa-chalkboard-teacher mr-1"></i> Kelas Pagi: <?= $kelas_pagi ?> | <i class="fas fa-mosque mr-1"></i> Diniyyah: <?= $kelas_diniyyah ?></p>
                    <a href="id_card.php" class="inline-block mt-2 text-blue-600 dark:text-blue-400 hover:underline"><i class="fas fa-id-card mr-1"></i> Lihat ID Card →</a>
                </div>
            </div>

            <!-- ========== STATISTIK KEAKTIFAN ========== -->
            <div class="mb-8">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">
                    <i class="fas fa-chart-line mr-2 text-green-500"></i> Statistik Keaktifan
                </h2>

                <!-- Progress Bar Keaktifan 30 Hari -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 mb-4">
                    <div class="flex flex-wrap items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                            <i class="fas fa-calendar-week mr-2 text-blue-500"></i> Keaktifan 30 Hari Terakhir
                        </h3>
                        <span class="text-sm font-bold <?= $stats_30hari['persentase'] >= 80 ? 'text-green-600 dark:text-green-400' : ($stats_30hari['persentase'] >= 60 ? 'text-yellow-600 dark:text-yellow-400' : 'text-red-600 dark:text-red-400') ?>">
                            <?= $stats_30hari['persentase'] ?>% Kehadiran
                        </span>
                    </div>
                    <div class="progress-container">
                        <div class="progress-bar <?= $stats_30hari['persentase'] >= 80 ? 'high' : ($stats_30hari['persentase'] >= 60 ? 'medium' : 'low') ?>" style="width: <?= $stats_30hari['persentase'] ?>%;">
                            <?= $stats_30hari['persentase'] ?>%
                        </div>
                    </div>
                    <div class="flex flex-wrap justify-between text-xs text-gray-500 dark:text-gray-400 mt-2">
                        <span>Hadir: <?= $stats_30hari['Hadir'] ?></span>
                        <span>Terlambat: <?= $stats_30hari['Terlambat'] ?></span>
                        <span>Izin: <?= $stats_30hari['Izin'] ?></span>
                        <span>Sakit: <?= $stats_30hari['Sakit'] ?></span>
                        <span>Alpha: <?= $stats_30hari['Alpha'] ?></span>
                    </div>
                </div>

                <!-- Diagram Donat + Metrik Tahunan -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <!-- Donut Chart 30 Hari -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 text-center">
                            <i class="fas fa-chart-pie mr-2 text-purple-500"></i> Komposisi Kehadiran (30 Hari)
                        </h3>
                        <div class="donut-container">
                            <canvas id="donutChart"></canvas>
                        </div>
                        <div class="flex flex-wrap justify-center gap-3 mt-2 text-xs">
                            <span><span class="legend-dot" style="background:#22c55e;"></span>Hadir <?= $stats_30hari['Hadir'] ?></span>
                            <span><span class="legend-dot" style="background:#eab308;"></span>Terlambat <?= $stats_30hari['Terlambat'] ?></span>
                            <span><span class="legend-dot" style="background:#3b82f6;"></span>Izin <?= $stats_30hari['Izin'] ?></span>
                            <span><span class="legend-dot" style="background:#8b5cf6;"></span>Sakit <?= $stats_30hari['Sakit'] ?></span>
                            <span><span class="legend-dot" style="background:#ef4444;"></span>Alpha <?= $stats_30hari['Alpha'] ?></span>
                        </div>
                    </div>

                    <!-- Metrik Keaktifan 1 Tahun Pelajaran -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 text-center">
                            <i class="fas fa-calendar-alt mr-2 text-indigo-500"></i> Keaktifan <?= date('Y', strtotime($tahun_ajaran_start)) ?> - <?= date('Y', strtotime($tahun_ajaran_end)) ?>
                        </h3>
                        <div class="grid grid-cols-3 gap-2 mb-3">
                            <div class="stat-card-activity p-2 text-center">
                                <div class="stat-value-activity text-green-600 dark:text-green-400 text-xl"><?= $stats_tahunan['Hadir'] ?></div>
                                <div class="stat-label-activity text-xs">Hadir</div>
                            </div>
                            <div class="stat-card-activity p-2 text-center">
                                <div class="stat-value-activity text-yellow-600 dark:text-yellow-400 text-xl"><?= $stats_tahunan['Terlambat'] ?></div>
                                <div class="stat-label-activity text-xs">Terlambat</div>
                            </div>
                            <div class="stat-card-activity p-2 text-center">
                                <div class="stat-value-activity text-blue-600 dark:text-blue-400 text-xl"><?= $stats_tahunan['Izin'] ?></div>
                                <div class="stat-label-activity text-xs">Izin</div>
                            </div>
                            <div class="stat-card-activity p-2 text-center">
                                <div class="stat-value-activity text-purple-600 dark:text-purple-400 text-xl"><?= $stats_tahunan['Sakit'] ?></div>
                                <div class="stat-label-activity text-xs">Sakit</div>
                            </div>
                            <div class="stat-card-activity p-2 text-center">
                                <div class="stat-value-activity text-red-600 dark:text-red-400 text-xl"><?= $stats_tahunan['Alpha'] ?></div>
                                <div class="stat-label-activity text-xs">Alpha</div>
                            </div>
                            <div class="stat-card-activity p-2 text-center">
                                <div class="stat-value-activity text-indigo-600 dark:text-indigo-400 text-xl"><?= $stats_tahunan['total'] ?></div>
                                <div class="stat-label-activity text-xs">Total</div>
                            </div>
                        </div>
                        <div class="text-center">
                            <span class="inline-block px-4 py-1 rounded-full text-sm font-bold <?= $stats_tahunan['persentase'] >= 80 ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : ($stats_tahunan['persentase'] >= 60 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200') ?>">
                                Persentase Kehadiran: <?= $stats_tahunan['persentase'] ?>%
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Jadwal Pelajaran dengan filter hari (menggunakan timeline) -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 mb-8">
                <div class="flex flex-wrap justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white"><i class="fas fa-calendar-alt mr-2 text-blue-500"></i> Jadwal Pelajaran</h2>
                    <form method="GET" class="flex gap-2">
                        <select name="filter_day" class="border rounded-lg px-3 py-1 text-sm dark:bg-gray-700 dark:text-white">
                            <option value="0">Semua Hari</option>
                            <?php for ($i=1; $i<=7; $i++): ?>
                                <option value="<?= $i ?>" <?= ($filter_day == $i) ? 'selected' : '' ?>><?= $hari_map[$i] ?></option>
                            <?php endfor; ?>
                        </select>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-lg text-sm transition"><i class="fas fa-filter mr-1"></i> Filter</button>
                    </form>
                </div>

                <?php if (empty($schedules_by_day) && empty($schedules)): ?>
                    <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                        <i class="fas fa-calendar-times text-4xl mb-2"></i>
                        <p>Tidak ada jadwal untuk filter ini</p>
                    </div>
                <?php else: ?>
                    <div class="schedule-timeline dark:text-white">
                        <?php foreach ($schedules_by_day as $day => $day_schedules): ?>
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
                                            <div class="text-xs text-gray-800 dark:text-white"><?= $j['start_time'] ?> - <?= $j['end_time'] ?> • Guru: <?= htmlspecialchars($teacher) ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Riwayat Absensi dengan pengelompokan per tanggal (timeline tetap) -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4"><i class="fas fa-history mr-2 text-purple-500"></i> Riwayat Absensi</h2>
                <?php if (empty($absensi_by_date)): ?>
                    <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                        <i class="fas fa-hourglass-half text-4xl mb-2"></i>
                        <p>Belum ada riwayat absensi</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($absensi_by_date as $date => $logs): 
                            $day_name = date('l', strtotime($date));
                            $formatted_date = date('d M Y', strtotime($date));
                        ?>
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                                <div class="absensi-date-header flex items-center gap-2 bg-gray-100 dark:bg-gray-700 p-3">
                                    <i class="fas fa-calendar-day text-blue-500"></i>
                                    <span class="font-semibold"><?= $day_name ?>, <?= $formatted_date ?></span>
                                </div>
                                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php $no_abs = 1; foreach ($logs as $log): ?>
                                        <div class="p-3 flex flex-wrap items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-800 dark:hover:text-black transition">
                                            <div class="w-6 text-gray-500"><?= $no_abs++ ?>.</div>
                                            <div class="flex-1 text-gray-800 dark:text-white">
                                                <div class="flex items-center gap-2">
                                                    <span class="timeline-dot"></span>
                                                    <span class="font-medium"><?= htmlspecialchars($log['activity_name']) ?></span>
                                                </div>
                                                <div class="text-xs text-green-800 dark:text-white mt-1">
                                                    <i class="far fa-clock mr-1"></i> <?= date('H:i', strtotime($log['scan_time'])) ?>
                                                    <span class="mx-2">•</span>
                                                    <i class="fas fa-tag mr-1"></i> Status: 
                                                    <span class="px-2 py-0.5 rounded-full text-xs <?= ($log['status'] == 'Hadir') ? 'bg-green-100 text-green-800' : (($log['status'] == 'Terlambat') ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') ?>">
                                                        <?= $log['status'] ?>
                                                    </span>
                                                </div>
                                                <?php if (!empty($log['note'])): ?>
                                                    <div class="text-xs text-gray-400 mt-1"><i class="fas fa-pencil-alt mr-1"></i> Catatan: <?= htmlspecialchars($log['note']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- ===== CHART.JS CDN ===== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
// ========== DONUT CHART ==========
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('donutChart').getContext('2d');
    const stats = <?= json_encode([
        'Hadir' => $stats_30hari['Hadir'],
        'Terlambat' => $stats_30hari['Terlambat'],
        'Izin' => $stats_30hari['Izin'],
        'Sakit' => $stats_30hari['Sakit'],
        'Alpha' => $stats_30hari['Alpha']
    ]) ?>;
    
    const labels = ['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpha'];
    const data = [stats.Hadir, stats.Terlambat, stats.Izin, stats.Sakit, stats.Alpha];
    const colors = ['#22c55e', '#eab308', '#3b82f6', '#8b5cf6', '#ef4444'];
    
    // Filter data yang nilainya > 0
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
    
    // Jika semua data 0, tampilkan pesan
    if (filteredData.length === 0) {
        document.getElementById('donutChart').parentElement.innerHTML = '<p class="text-center text-gray-500 text-sm">Belum ada data absensi 30 hari</p>';
        return;
    }
    
    new Chart(ctx, {
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
            plugins: {
                legend: {
                    display: false
                }
            },
            cutout: '70%'
        }
    });
});

// ========== DARK MODE (Perbaikan total) ==========
const darkModeToggle = document.getElementById('darkModeToggle');
function setDarkMode(isDark) {
    if (isDark) {
        document.documentElement.classList.add('dark');
        localStorage.setItem('darkMode', 'enabled');
    } else {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('darkMode', 'disabled');
    }
    // Update ikon tombol
    if (darkModeToggle) {
        const moonIcon = darkModeToggle.querySelector('.fa-moon');
        const sunIcon = darkModeToggle.querySelector('.fa-sun');
        if (moonIcon && sunIcon) {
            if (isDark) {
                moonIcon.classList.add('hidden');
                sunIcon.classList.remove('hidden');
            } else {
                moonIcon.classList.remove('hidden');
                sunIcon.classList.add('hidden');
            }
        }
    }
}
// Baca dari localStorage
const savedMode = localStorage.getItem('darkMode');
if (savedMode === 'enabled') {
    setDarkMode(true);
} else if (savedMode === 'disabled') {
    setDarkMode(false);
} else {
    // Deteksi preferensi sistem
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    setDarkMode(prefersDark);
}
// Event listener
if (darkModeToggle) {
    darkModeToggle.addEventListener('click', () => {
        const isDark = document.documentElement.classList.contains('dark');
        setDarkMode(!isDark);
    });
}
</script>

<script>
    // Sidebar close handlers
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
</script>
<script src="assets/js/notifications_info.js"></script>

<?php require_once __DIR__ . '/includes/footer_user.php'; ?>