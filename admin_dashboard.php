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

$page_title = 'Dashboard - SIAKAD Admin';
$current_page = 'dashboard';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Set zona waktu Asia/Jakarta sebelum render tanggal
date_default_timezone_set('Asia/Jakarta');
$currentDate = date('l, d F Y');
$currentTime = date('H:i:s');

// Generate CSRF token jika belum ada
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ========== FUNGSI CACHE SEDERHANA (OPTIMASI) ==========
function getCached($key, $callback, $ttl = 600) {
    $cacheDir = __DIR__ . '/cache/';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
    $file = $cacheDir . md5($key) . '.json';
    if (file_exists($file) && (time() - filemtime($file) < $ttl)) {
        return json_decode(file_get_contents($file), true);
    }
    $data = $callback();
    file_put_contents($file, json_encode($data));
    return $data;
}

// ========== AMBIL DATA GURU (CACHED) ==========
$teachers_raw = getCached('teachers', function() {
    return supabase_admin_request('GET', 'users', null, [
        'role' => 'eq.teacher',
        'order' => 'full_name.asc',
        'select' => 'id, full_name, nidn_or_nisn'
    ]);
}, 600);

$teachers = is_array($teachers_raw) ? $teachers_raw : [];

// ========== AMBIL DATA KELAS (CACHED) ==========
$classes_raw = getCached('classes', function() {
    return supabase_admin_request('GET', 'classes', null, [
        'select' => 'id, class_name, grade_level, class_type, sort_order'
    ]);
}, 600);
$classes = is_array($classes_raw) ? $classes_raw : [];

// ========== AMBIL JADWAL HARI INI DENGAN EMBEDDING (OPTIMASI) ==========
$today = date('N'); // 1=Senin ... 7=Minggu
$schedules_raw = supabase_admin_request('GET', 'schedules', null, [
    'day_of_week' => 'eq.' . $today,               // hanya hari ini
    'select' => 'id, class_id, subject_id, teacher_id, day_of_week, start_time, end_time, academic_year, semester, classes!inner(class_name), subjects!inner(subject_name)',
    'order' => 'start_time.asc'
]);

// Mapping teacher_id => full_name dari $teachers
$teacher_map = [];
foreach ($teachers as $t) {
    $teacher_map[$t['id']] = $t['full_name'] ?? '-';
}

$today_schedules = [];
if (is_array($schedules_raw)) {
    foreach ($schedules_raw as $s) {
        $today_schedules[] = [
            'id' => $s['id'],
            'class_id' => $s['class_id'],
            'class_name' => $s['classes']['class_name'] ?? '?',
            'subject_name' => $s['subjects']['subject_name'] ?? '?',
            'day_of_week' => $s['day_of_week'] ?? null,
            'start_time' => $s['start_time'] ?? null,
            'end_time' => $s['end_time'] ?? null,
            'teacher_id' => $s['teacher_id'] ?? null,
            'teacher_name' => $teacher_map[$s['teacher_id']] ?? '-'  // tambahkan nama guru
        ];
    }
}

// ========== (Opsional) Data untuk dropdown absensi manual ==========
$users_raw = supabase_admin_request('GET', 'users', null, [
    'select' => 'id, full_name, role, kelas_pagi_id, kelas_diniyyah_id'
]);
$users = is_array($users_raw) ? $users_raw : [];

// ========== NOTIFIKASI ADMIN ==========
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    $unread_count = 0;
    $announcements_dropdown = [];
} else {
    $announcements_raw = supabase_admin_request('GET', 'announcements', null, [
        'is_active' => 'eq.true',
        'order' => 'created_at.desc',
        'limit' => 10
    ]);
    $announcements = safeArray($announcements_raw);
    
    $reads_raw = supabase_admin_request('GET', 'announcement_reads', null, [
        'user_id' => 'eq.' . $user_id
    ]);
    $reads = safeArray($reads_raw);
    $read_announcement_ids = array_column($reads, 'announcement_id');
    
    $unread_count = 0;
    foreach ($announcements as $ann) {
        if (!in_array($ann['id'], $read_announcement_ids)) {
            $unread_count++;
        }
    }
    $announcements_dropdown = $announcements;
}
?>
    <?php require_once __DIR__ . '/includes/header.php'; ?>
<style>
    .timeline-item { position: relative; padding-left: 30px; margin-bottom: 20px; }
    .timeline-item::before { content: ''; position: absolute; left: 10px; top: 5px; width: 12px; height: 12px; border-radius: 50%; background: #3b82f6; border: 2px solid white; box-shadow: 0 0 0 2px #3b82f6; z-index: 1; }
    .timeline-item::after { content: ''; position: absolute; left: 15px; top: 17px; bottom: -20px; width: 2px; background: #e5e7eb; }
    .timeline-item:last-child::after { display: none; }
    .dark .timeline-item::after { background: #374151; }
    .toast-notification { position: fixed; bottom: 20px; right: 20px; z-index: 9999; animation: slideIn 0.3s ease-out; }
    @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
    .calendar-placeholder { transition: all 0.3s ease; }
    .calendar-collapsed { display: none; }
    .calendar-expanded { display: block; }
    /* FullCalendar dark mode adjustment */
.dark .fc {
    color: #e5e7eb;
}
.dark .fc .fc-button-primary {
    background-color: #374151;
    border-color: #4b5563;
}
.dark .fc .fc-button-primary:hover {
    background-color: #4b5563;
}
.dark .fc .fc-daygrid-day {
    background-color: #1f2937;
    border-color: #374151;
}
.dark .fc .fc-daygrid-day-number {
    color: #e5e7eb;
}
.dark .fc .fc-col-header-cell {
    background-color: #111827;
    color: #f3f4f6;
}
.dark .fc .fc-list-day-cushion {
    background-color: #1f2937;
}
.dark .fc .fc-list-event {
    background-color: #374151;
}
.fc .fc-toolbar.fc-header-toolbar {
    flex-wrap: wrap;
}
.fc .fc-toolbar-title {
    font-size: 1.2rem;
    font-weight: 600;
}
.fc .fc-button {
    background-color: #3b82f6;
    border-color: #3b82f6;
    color: white;
    text-transform: capitalize;
    transition: all 0.2s;
}
.fc .fc-button:hover {
    background-color: #2563eb;
    transform: translateY(-1px);
}
.fc .fc-button-primary:not(:disabled).fc-button-active,
.fc .fc-button-primary:not(:disabled):active {
    background-color: #1d4ed8;
}
@media (max-width: 768px) {
    .fc .fc-toolbar {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
    }
    .fc .fc-toolbar > * {
        justify-content: center;
    }
    .fc .fc-button {
        padding: 0.4rem 0.6rem;
        font-size: 0.8rem;
    }
    .fc .fc-toolbar-title {
        font-size: 1rem;
        text-align: center;
    }
    .fc .fc-daygrid-day-number {
        font-size: 0.8rem;
    }
    .fc .fc-col-header-cell-cushion {
        font-size: 0.75rem;
    }
}
@media (max-width: 640px) {
    .fc .fc-toolbar-chunk:last-child {
        display: flex;
        flex-wrap: wrap;
    }
    .fc .fc-toolbar {
        flex-direction: column;
        align-items: center;
        gap: 8px;
    }
    .fc .fc-toolbar-chunk {
        display: flex;
        justify-content: center;
    }
    .fc .fc-toolbar-title {
        font-size: 1rem;
    }
}
/* Modal Event */
.event-modal {
    transition: all 0.2s ease;
}
.event-modal .modal-content {
    max-height: 70vh;
    overflow-y: auto;
}
/* Perbaikan tampilan event */
.fc-event {
    cursor: pointer;
    font-size: 0.8rem;
    padding: 2px 4px;
    border-radius: 4px;
    transition: transform 0.1s;
}
.fc-event:hover {
    transform: scale(1.02);
}
/* Dark mode adjustment untuk button */
.dark .fc .fc-button {
    background-color: #4b5563;
    border-color: #6b7280;
}
.dark .fc .fc-button:hover {
    background-color: #6b7280;
}
/* Tambahan untuk modal absensi */
.user-search-input {
    margin-bottom: 10px;
}
.user-list-container {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 5px;
}
.user-item {
    padding: 8px;
    cursor: pointer;
    border-bottom: 1px solid #eee;
}
.user-item:hover {
    background-color: #f0f0f0;
}
.user-item.selected {
    background-color: #d0e0ff;
}
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
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
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Dashboard</h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <a href="kiosk_scanner.php" class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg text-sm flex items-center gap-2">
                        <i class="fas fa-qrcode"></i> <span class="hidden sm:inline">Absensi QR/NFC</span>
                    </a>
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                        <i class="fas fa-moon text-xl dark:hidden"></i>
                        <i class="fas fa-sun text-xl hidden dark:inline"></i>
                    </button>
                    
                    <!-- Notifikasi Admin -->
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
                            <div class="p-3 border-b dark:border-gray-700 font-semibold text-gray-700 dark:text-gray-200 flex justify-between items-center">
                                <span><i class="fas fa-bell mr-2"></i> Notifikasi</span>
                                <button id="markAllReadBtn" class="text-xs text-blue-500 hover:underline">Tandai semua dibaca</button>
                            </div>
                            <div id="notificationList" class="max-h-96 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-700">
                                <?php if (empty($announcements_dropdown)): ?>
                                    <div class="p-4 text-center text-gray-500 dark:text-gray-400 text-sm">Tidak ada pengumuman</div>
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
                                <a href="admin_announcements.php" class="text-xs text-blue-600 dark:text-blue-400">Lihat semua pengumuman</a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Profile User-->
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
            <!-- Card Jam dan Tanggal Real Time + Tombol Toggle Kalender -->
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 rounded-xl shadow-lg p-4 mb-4 text-white">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm opacity-90">Hari & Tanggal</p>
                        <h2 class="text-2xl font-bold" id="currentDate"><?= $currentDate ?></h2>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-right">
                            <p class="text-sm opacity-90">Waktu</p>
                            <h2 class="text-2xl font-bold" id="currentTime"><?= $currentTime ?></h2>
                        </div>
                        <button id="toggleCalendarBtn" class="bg-white/20 hover:bg-white/30 rounded-full p-2 transition">
                            <i id="calendarIcon" class="fas fa-chevron-down text-xl"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Area Kalender (Collapsible) -->
            <div id="calendarArea" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 mb-8 calendar-collapsed">
                <div class="flex flex-wrap justify-between items-center mb-4 gap-2">
                    <h3 class="text-md font-semibold text-gray-700 dark:text-gray-200">
                        <i class="fas fa-calendar-alt mr-2 text-blue-500"></i> Kalender Akademik
                    </h3>
                    <div class="flex gap-2">
                        <!-- Filter stack (multi stack) -->
                        <button data-stack="schedule" class="stack-filter px-3 py-1 text-xs rounded-full bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300 active">Jadwal</button>
                        <button data-stack="activity" class="stack-filter px-3 py-1 text-xs rounded-full bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-300">Kegiatan</button>
                        <button data-stack="holiday" class="stack-filter px-3 py-1 text-xs rounded-full bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300">Libur</button>
                    </div>
                </div>
                <div id="fullCalendar" class="dark:text-gray-200"></div>
            </div>

            <!-- Widget Cards KPI -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6 mb-8">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 border-l-4 border-blue-500">
                    <div class="flex justify-between items-start">
                        <div><p class="text-gray-500 dark:text-gray-400 text-sm">Total Hadir Hari Ini</p><p class="text-3xl font-bold text-gray-800 dark:text-white" id="total-hadir">0</p></div>
                        <i class="fas fa-user-check text-blue-500 text-3xl opacity-70"></i>
                    </div>
                    <div class="mt-2 text-xs text-green-600"><i class="fas fa-arrow-up"></i> <span id="trend-hadir">0%</span> dari kemarin</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 border-l-4 border-green-500">
                    <div class="flex justify-between items-start"><div><p class="text-gray-500 dark:text-gray-400 text-sm">Total Guru</p><p class="text-3xl font-bold text-gray-800 dark:text-white" id="total-guru">0</p></div><i class="fas fa-chalkboard-teacher text-green-500 text-3xl opacity-70"></i></div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 border-l-4 border-yellow-500">
                    <div class="flex justify-between items-start"><div><p class="text-gray-500 dark:text-gray-400 text-sm">Total Murid</p><p class="text-3xl font-bold text-gray-800 dark:text-white" id="total-murid">0</p></div><i class="fas fa-user-graduate text-yellow-500 text-3xl opacity-70"></i></div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 border-l-4 border-purple-500">
                    <div class="flex justify-between items-start"><div><p class="text-gray-500 dark:text-gray-400 text-sm">Rata-rata Kehadiran</p><p class="text-3xl font-bold text-gray-800 dark:text-white" id="avg-kehadiran">0%</p></div><i class="fas fa-chart-line text-purple-500 text-3xl opacity-70"></i></div>
                </div>
            </div>

            <!-- Grafik Kehadiran & Kelas -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white"><i class="fas fa-chart-line text-blue-500 mr-2"></i> Statistik Kehadiran Bulan Ini</h2>
                    </div>
                    <canvas id="attendanceChart" height="200"></canvas>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4"><i class="fas fa-building text-green-500 mr-2"></i> Jumlah Kelas per Tingkat</h2>
                    <canvas id="classesChart" height="200"></canvas>
                </div>
            </div>

            <!-- Grafik Pembayaran dengan Filter Rentang Waktu -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 mb-8">
                <div class="flex flex-wrap justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white"><i class="fas fa-chart-pie text-purple-500 mr-2"></i> Grafik Pembayaran</h2>
                    <div class="flex flex-wrap gap-2">
                        <select id="paymentRange" class="border rounded-lg px-3 py-1 text-sm dark:bg-gray-700 dark:text-white">
                            <option value="this_month">Bulan Ini</option>
                            <option value="last_month">Bulan Lalu</option>
                            <option value="this_year">Tahun Ini</option>
                            <option value="custom">Custom Range</option>
                        </select>
                        <div id="customDateRange" class="hidden flex gap-2">
                            <input type="date" id="startDate" class="border rounded px-2 py-1 text-sm dark:bg-gray-700">
                            <input type="date" id="endDate" class="border rounded px-2 py-1 text-sm dark:bg-gray-700">
                            <button id="applyCustomRange" class="bg-blue-600 text-white px-3 py-1 rounded text-sm">Terapkan</button>
                        </div>
                    </div>
                </div>
                <canvas id="paymentChart" height="250"></canvas>
                <div class="grid grid-cols-2 gap-4 mt-4">
                    <div class="bg-blue-50 dark:bg-gray-700 p-3 rounded-lg text-center">
                        <p class="text-xs text-gray-500">Total Tagihan</p>
                        <p class="text-xl font-bold text-blue-600" id="totalTagihan">0</p>
                    </div>
                    <div class="bg-green-50 dark:bg-gray-700 p-3 rounded-lg text-center">
                        <p class="text-xs text-gray-500">Total Diterima</p>
                        <p class="text-xl font-bold text-green-600" id="totalDiterima">0</p>
                    </div>
                </div>
            </div>

            <!-- Live Feed Absensi & Log Pembayaran -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white"><i class="fas fa-sync-alt text-blue-500 mr-2 animate-pulse"></i> Live Feed Absensi</h2>
                        <button id="manualAbsensiBtn" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-sm"><i class="fas fa-plus mr-1"></i> Absensi Manual</button>
                    </div>
                    <div id="live-feed" class="space-y-3 h-96 overflow-y-auto pr-2">
                        <div class="text-center text-gray-400 dark:text-gray-500 py-10"><i class="fas fa-hourglass-half text-4xl mb-2"></i><p>Memuat data...</p></div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white"><i class="fas fa-money-bill-wave text-green-500 mr-2"></i> Log Pembayaran (10 Terakhir)</h2>
                        <button id="togglePaymentLog" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                            <i class="fas fa-chevron-up"></i>
                        </button>
                    </div>
                    <div id="paymentLogContent" class="payment-log-collapse">
                        <?php
                        // Data dummy pembayaran (sementara)
                        $payment_logs = [
                            ['id' => 1, 'nama' => 'Ahmad Fauzi', 'amount' => 500000, 'status' => 'Lunas', 'tanggal' => date('Y-m-d H:i:s', strtotime('-5 minutes')), 'metode' => 'Transfer'],
                            ['id' => 2, 'nama' => 'Siti Aminah', 'amount' => 450000, 'status' => 'Lunas', 'tanggal' => date('Y-m-d H:i:s', strtotime('-2 hours')), 'metode' => 'Tunai'],
                            ['id' => 3, 'nama' => 'Budi Santoso', 'amount' => 500000, 'status' => 'Lunas', 'tanggal' => date('Y-m-d H:i:s', strtotime('-1 day')), 'metode' => 'Transfer'],
                            ['id' => 4, 'nama' => 'Dewi Lestari', 'amount' => 450000, 'status' => 'Lunas', 'tanggal' => date('Y-m-d H:i:s', strtotime('-2 days')), 'metode' => 'Tunai'],
                            ['id' => 5, 'nama' => 'Eko Prasetyo', 'amount' => 500000, 'status' => 'Lunas', 'tanggal' => date('Y-m-d H:i:s', strtotime('-3 days')), 'metode' => 'Transfer'],
                            ['id' => 6, 'nama' => 'Fatimah Zahra', 'amount' => 450000, 'status' => 'Lunas', 'tanggal' => date('Y-m-d H:i:s', strtotime('-4 days')), 'metode' => 'Tunai'],
                            ['id' => 7, 'nama' => 'Gilang Ramadhan', 'amount' => 500000, 'status' => 'Lunas', 'tanggal' => date('Y-m-d H:i:s', strtotime('-5 days')), 'metode' => 'Transfer'],
                            ['id' => 8, 'nama' => 'Haniyah Putri', 'amount' => 450000, 'status' => 'Lunas', 'tanggal' => date('Y-m-d H:i:s', strtotime('-6 days')), 'metode' => 'Tunai'],
                            ['id' => 9, 'nama' => 'Iskandar Zulkarnain', 'amount' => 500000, 'status' => 'Lunas', 'tanggal' => date('Y-m-d H:i:s', strtotime('-1 week')), 'metode' => 'Transfer'],
                            ['id' => 10, 'nama' => 'Jihan Fakhira', 'amount' => 450000, 'status' => 'Lunas', 'tanggal' => date('Y-m-d H:i:s', strtotime('-1 week +1 day')), 'metode' => 'Tunai'],
                        ];
                        ?>
                        <?php if (empty($payment_logs)): ?>
                            <div class="text-center py-4">Belum ada pembayaran</div>
                        <?php else: ?>
                            <div class="space-y-3 max-h-96 overflow-y-auto pr-2">
                                <?php foreach ($payment_logs as $log): ?>
                                <div class="timeline-item relative pl-6 pb-3">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <p class="font-semibold text-gray-800 dark:text-white"><?= htmlspecialchars($log['nama']) ?></p>
                                            <p class="text-xs text-gray-500"><?= date('d/m/Y H:i', strtotime($log['tanggal'])) ?> • <?= $log['metode'] ?></p>
                                        </div>
                                        <div class="text-right">
                                            <span class="text-sm font-bold text-green-600">Rp <?= number_format($log['amount'], 0, ',', '.') ?></span>
                                            <span class="ml-2 text-xs bg-green-100 text-green-800 px-2 py-0.5 rounded-full"><?= $log['status'] ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ========== BATCH ABSENSI SISWA & GURU ========== -->
            <div class="mt-8">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5">
                    <div class="flex border-b border-gray-200 dark:border-gray-700 mb-4">
                        <button id="tabSiswaBtn" class="px-4 py-2 text-sm font-medium text-blue-600 border-b-2 border-blue-600 dark:text-blue-400 dark:border-blue-400">Absensi Batch Siswa</button>
                        <button id="tabGuruBtn" class="px-4 py-2 text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">Absensi Batch Guru</button>
                    </div>

                    <!-- Panel Siswa -->
                    <div id="batchSiswaPanel" class="batch-panel">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                            <div>
                                <label class="block text-gray-700 dark:text-gray-300 mb-1">Tipe Kelas</label>
                                <select id="batchSiswaKelasTipe" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white">
                                    <option value="pagi">Kelas Pagi</option>
                                    <option value="diniyyah">Kelas Diniyyah</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-gray-700 dark:text-gray-300 mb-1">Pilih Tingkat Kelas</label>
                                <select id="batchSiswaGradeLevel" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white">
                                    <option value="">-- Pilih Tingkat --</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-gray-700 dark:text-gray-300 mb-1">Pilih Kelas</label>
                                <select id="batchSiswaKelas" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white" disabled>
                                    <option value="">Pilih tingkat terlebih dahulu</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-gray-700 dark:text-gray-300 mb-1">Pilih Jadwal (Hari Ini)</label>
                                <select id="batchSiswaJadwal" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white" disabled>
                                    <option value="">Pilih kelas terlebih dahulu</option>
                                </select>
                            </div>
                        </div>
                        <div id="batchSiswaList" class="overflow-x-auto mb-4">
                            <div class="text-center text-gray-500 py-8">Pilih kelas dan jadwal untuk menampilkan daftar siswa</div>
                        </div>
                        <div class="flex justify-end">
                            <button id="batchSiswaSubmit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Simpan Semua</button>
                        </div>
                    </div>

                    <!-- Panel Guru -->
                    <div id="batchGuruPanel" class="batch-panel hidden">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <div>
                                <label class="block text-gray-700 dark:text-gray-300 mb-1">Tipe Kelas</label>
                                <select id="batchGuruKelasTipe" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white">
                                    <option value="pagi">Kelas Pagi (Guru Pengajar)</option>
                                    <option value="diniyyah">Kelas Diniyyah (Guru Pengajar)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-gray-700 dark:text-gray-300 mb-1">Pilih Tingkat Kelas</label>
                                <select id="batchGuruGradeLevel" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white">
                                    <option value="">-- Pilih Tingkat --</option>
                                    <!-- diisi JS -->
                                </select>
                            </div>
                            <div>
                                <label class="block text-gray-700 dark:text-gray-300 mb-1">Pilih Jadwal (Hari Ini)</label>
                                <select id="batchGuruJadwal" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white" disabled>
                                    <option value="">Pilih tingkat terlebih dahulu</option>
                                </select>
                            </div>
                        </div>
                        <div id="batchGuruList" class="overflow-x-auto mb-4">
                            <div class="text-center text-gray-500 py-8">Pilih tingkat dan jadwal untuk menampilkan daftar guru</div>
                        </div>
                        <div class="flex justify-end">
                            <button id="batchGuruSubmit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Simpan Semua</button>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Tabel Aktivitas Terkini (Absensi) -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white">Aktivitas Terkini (10 Terakhir)</h2>
                    <button id="clearLogsBtn" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-sm"><i class="fas fa-trash-alt mr-1"></i> Hapus Semua Log</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase">Waktu</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase">Nama</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase">Role</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody id="aktivitas-table" class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            <tr><td colspan="4" class="text-center py-4">Memuat data...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Modal Absensi Manual (Baru) -->
<div id="manualAbsensiModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Absensi Manual Siswa</h3>
        <form id="manualAbsensiForm">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <!-- Pencarian Siswa -->
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Cari Siswa</label>
                <input type="text" id="searchStudent" class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white" placeholder="Ketik nama siswa...">
                <div id="studentList" class="user-list-container mt-1 hidden"></div>
            </div>
            
            <!-- Siswa yang dipilih (hidden) -->
            <input type="hidden" id="selectedUserId" name="user_id">
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Siswa Terpilih</label>
                <input type="text" id="selectedUserName" readonly class="w-full border rounded px-2 py-1 bg-gray-100 dark:bg-gray-700">
            </div>
            
            <!-- Jadwal Hari Ini (hanya untuk siswa yang dipilih) -->
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Pilih Jadwal (Hari Ini)</label>
                <select id="scheduleId" name="schedule_id" class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white" required disabled>
                    <option value="">-- Pilih Siswa Terlebih Dahulu --</option>
                </select>
            </div>
            
            <!-- Status -->
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Status</label>
                <select id="manualStatus" name="status" class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white" required>
                    <option value="Hadir">Hadir</option>
                    <option value="Terlambat">Terlambat</option>
                    <option value="Izin">Izin</option>
                    <option value="Sakit">Sakit</option>
                    <option value="Alpha">Alpha</option>
                </select>
            </div>
            
            <div class="flex justify-end space-x-2 mt-4">
                <button type="button" onclick="closeManualModal()" class="bg-gray-400 text-white px-4 py-2 rounded">Batal</button>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Simpan Absensi</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Detail Event Kalender -->
<div id="eventDetailModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-md mx-4 event-modal">
        <div class="flex justify-between items-center p-4 border-b dark:border-gray-700">
            <h3 class="text-lg font-bold text-gray-800 dark:text-white">Detail Event</h3>
            <button onclick="closeEventModal()" class="text-gray-500 hover:text-gray-700 dark:text-gray-400">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="p-4 modal-content">
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Judul</label>
                <p class="text-gray-800 dark:text-white font-semibold" id="eventModalJudul"></p>
            </div>
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Mulai</label>
                <p class="text-gray-700 dark:text-gray-300" id="eventModalMulai"></p>
            </div>
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Selesai</label>
                <p class="text-gray-700 dark:text-gray-300" id="eventModalSelesai"></p>
            </div>
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Tipe</label>
                <p class="text-gray-700 dark:text-gray-300" id="eventModalTipe"></p>
            </div>
        </div>
        <div class="flex justify-end p-4 border-t dark:border-gray-700">
            <button onclick="closeEventModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal Detail Pengumuman -->
<div id="announcementDetailModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-2xl mx-4 max-h-[85vh] flex flex-col">
        <div class="flex justify-between items-center p-4 border-b dark:border-gray-700">
            <h3 id="announcementModalTitle" class="text-lg font-bold text-gray-800 dark:text-white">Detail Pengumuman</h3>
            <button onclick="closeAnnouncementModal()" class="text-gray-500 hover:text-gray-700 dark:text-gray-400">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="p-4 overflow-y-auto flex-1">
            <div class="mb-3">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Tanggal</label>
                <p id="announcementModalDate" class="text-gray-700 dark:text-gray-300 text-sm"></p>
            </div>
            <div class="mb-3">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Isi Pengumuman</label>
                <div id="announcementModalContent" class="text-gray-800 dark:text-gray-200 text-sm whitespace-pre-line"></div>
            </div>
        </div>
        <div class="flex justify-end p-4 border-t dark:border-gray-700">
            <button onclick="closeAnnouncementModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Tutup</button>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>

<script>
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

// ========== REAL TIME CLOCK ==========
function updateDateTime() {
    const now = new Date();
    document.getElementById('currentDate').innerText = now.toLocaleDateString('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    document.getElementById('currentTime').innerText = now.toLocaleTimeString('id-ID');
}
updateDateTime();
setInterval(updateDateTime, 1000);

// ========== TOGGLE KALENDER ==========
const toggleCalendarBtn = document.getElementById('toggleCalendarBtn');
const calendarArea = document.getElementById('calendarArea');
const calendarIcon = document.getElementById('calendarIcon');
let calendarExpanded = false;
toggleCalendarBtn?.addEventListener('click', () => {
    calendarExpanded = !calendarExpanded;
    if (calendarExpanded) {
        calendarArea.classList.remove('calendar-collapsed');
        calendarArea.classList.add('calendar-expanded');
        calendarIcon.classList.remove('fa-chevron-down');
        calendarIcon.classList.add('fa-chevron-up');
        if (calendar) calendar.updateSize();
    } else {
        calendarArea.classList.remove('calendar-expanded');
        calendarArea.classList.add('calendar-collapsed');
        calendarIcon.classList.remove('fa-chevron-up');
        calendarIcon.classList.add('fa-chevron-down');
    }
});

// ========== CHART INIT ==========
let attendanceChart, kelasChart, paymentChart;

// ========== LOAD DASHBOARD STATS (ABSENSI) ==========
async function loadDashboardStats() {
    try {
        const res = await fetch('api/dashboard_stats.php');
        const data = await res.json();
        
        document.getElementById('total-hadir').innerText = data.total_hadir_hari_ini ?? 0;
        document.getElementById('total-guru').innerText = data.total_guru ?? 0;
        document.getElementById('total-murid').innerText = data.total_murid ?? 0;
        document.getElementById('avg-kehadiran').innerText = (data.avg_kehadiran ?? 0) + '%';
        document.getElementById('trend-hadir').innerHTML = data.trend_hadir ?? '0%';
        
        if (attendanceChart) {
            attendanceChart.data.labels = data.chart_labels;
            attendanceChart.data.datasets[0].data = data.chart_data;
            attendanceChart.update();
        } else {
            const ctx = document.getElementById('attendanceChart').getContext('2d');
            attendanceChart = new Chart(ctx, {
                type: 'line',
                data: { labels: data.chart_labels, datasets: [{ label: 'Jumlah Kehadiran', data: data.chart_data, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', tension: 0.3, fill: true }] },
                options: { responsive: true, maintainAspectRatio: true }
            });
        }
        
        if (kelasChart) {
            kelasChart.data.labels = data.kelas_labels;
            kelasChart.data.datasets[0].data = data.kelas_counts;
            kelasChart.update();
        } else {
            const kelasCtx = document.getElementById('classesChart').getContext('2d');
            kelasChart = new Chart(kelasCtx, {
                type: 'bar',
                data: { labels: data.kelas_labels, datasets: [{ label: 'Jumlah Kelas', data: data.kelas_counts, backgroundColor: '#10b981', borderRadius: 8 }] },
                options: { responsive: true, maintainAspectRatio: true }
            });
        }
        
        renderAktivitasTable(data.recent_logs);
        const liveFeedDiv = document.getElementById('live-feed');
        liveFeedDiv.innerHTML = '';
        if (data.recent_logs && data.recent_logs.length) {
            [...data.recent_logs].reverse().forEach(log => addLiveFeedItem(log, false));
        } else {
            liveFeedDiv.innerHTML = '<div class="text-center text-gray-400 dark:text-gray-500 py-10"><i class="fas fa-hourglass-half text-4xl mb-2"></i><p>Belum ada aktivitas absensi</p></div>';
        }
        window.latestLogs = data.recent_logs || [];
    } catch(e) {
        console.error('Gagal memuat statistik dashboard:', e);
        showToast('Gagal memuat data dashboard', 'error');
    }
}

// ========== RENDER TABEL AKTIVITAS ==========
function renderAktivitasTable(logs) {
    const tbody = document.getElementById('aktivitas-table');
    if (!tbody) return;
    if (!logs || logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-gray-400">Belum ada data</td></tr>';
        return;
    }
    let html = '';
    logs.forEach(log => {
        html += `<tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td class="px-6 py-3 text-sm dark:text-gray-300">${new Date(log.scan_time).toLocaleString()}  </td>
                    <td class="px-6 py-3 font-medium dark:text-white">${escapeHtml(log.user_name)}</td>
                    <td class="px-6 py-3 text-sm dark:text-gray-300">${escapeHtml(log.role)}</td>
                    <td class="px-6 py-3"><span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">${escapeHtml(log.status)}</span></td>
                  </tr>`;
    });
    tbody.innerHTML = html;
}

// ========== LIVE FEED ==========
function escapeHtml(str) { if(!str) return ''; return str.replace(/[&<>]/g, function(m){ if(m==='&') return '&amp;'; if(m==='<') return '&lt;'; if(m==='>') return '&gt;'; return m;}); }

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast-notification bg-${type === 'success' ? 'green' : 'red'}-500 text-white px-4 py-2 rounded-lg shadow-lg flex items-center gap-2`;
    toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

function addLiveFeedItem(log, updateGlobal = true) {
    const liveFeedDiv = document.getElementById('live-feed');
    if (liveFeedDiv.children.length === 1 && liveFeedDiv.children[0].innerText.includes('Belum ada aktivitas')) {
        liveFeedDiv.innerHTML = '';
    }
    const item = document.createElement('div');
    item.className = 'bg-gray-50 dark:bg-gray-700 p-3 rounded-lg border-l-4 border-blue-500 shadow-sm';
    item.innerHTML = `<div class="flex justify-between"><div><p class="font-semibold dark:text-white">${escapeHtml(log.user_name)}</p><p class="text-xs text-gray-500 dark:text-gray-400">${escapeHtml(log.role)} - ${new Date(log.scan_time).toLocaleString()}</p></div><span class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded-full">${escapeHtml(log.status)}</span></div>`;
    liveFeedDiv.prepend(item);
    liveFeedDiv.scrollTop = 0;
    if (liveFeedDiv.children.length > 20) liveFeedDiv.removeChild(liveFeedDiv.lastChild);
    
    if (updateGlobal) {
        if (!window.latestLogs) window.latestLogs = [];
        window.latestLogs.unshift(log);
        if (window.latestLogs.length > 10) window.latestLogs.pop();
        renderAktivitasTable(window.latestLogs);
    }
}

// ========== SUPABASE REALTIME ==========
const supabaseClient = window.supabase.createClient('<?= SUPABASE_URL ?>', '<?= SUPABASE_ANON_KEY ?>');
let usePolling = false;
let pollingInterval = null;

async function fetchLatestLogs() {
    try {
        const res = await fetch('api/dashboard_stats.php');
        const data = await res.json();
        const newLogs = data.recent_logs || [];
        if (newLogs.length) {
            let existingIds = new Set((window.latestLogs || []).map(l => l.scan_time + l.user_name));
            let added = false;
            for (let log of newLogs) {
                if (!existingIds.has(log.scan_time + log.user_name)) {
                    addLiveFeedItem(log, true);
                    added = true;
                }
            }
            if (added) loadDashboardStats();
            else {
                window.latestLogs = newLogs;
                renderAktivitasTable(window.latestLogs);
            }
        }
    } catch(e) { console.error('Polling error:', e); }
}

const channel = supabaseClient.channel('attendance-channel')
    .on('postgres_changes', { event: 'INSERT', schema: 'public', table: 'attendance_logs' }, async (payload) => {
        if (usePolling) return;
        const newLog = payload.new;
        try {
            const res = await fetch(`api/get_user.php?id=${newLog.user_id}`);
            const userData = await res.json();
            newLog.user_name = userData.full_name || 'Tidak dikenal';
            newLog.role = userData.role || 'Murid';
            addLiveFeedItem(newLog, true);
            loadDashboardStats();
        } catch(e) { console.error(e); }
    })
    .subscribe((status, err) => {
        console.log('Realtime status:', status, err);
        if (status === 'CHANNEL_ERROR' || (err && err.message === 'WebSocket connection failed')) {
            console.warn('WebSocket gagal, beralih ke polling 5 detik');
            usePolling = true;
            if (pollingInterval) clearInterval(pollingInterval);
            pollingInterval = setInterval(fetchLatestLogs, 5000);
            fetchLatestLogs();
        }
    });

setTimeout(() => {
    if (!usePolling && !channel) {
        console.warn('Realtime tidak terhubung, fallback polling');
        usePolling = true;
        if (pollingInterval) clearInterval(pollingInterval);
        pollingInterval = setInterval(fetchLatestLogs, 5000);
        fetchLatestLogs();
    }
}, 5000);

// ========== HAPUS LOG ==========
document.getElementById('clearLogsBtn')?.addEventListener('click', async () => {
    if (confirm('⚠️ Yakin akan menghapus SEMUA data log absensi?')) {
        try {
            const res = await fetch('api/clear_logs.php', { method: 'POST' });
            const data = await res.json();
            if (data.success) {
                alert('✅ Semua log berhasil dihapus');
                playBeep('success');
                window.latestLogs = [];
                renderAktivitasTable([]);
                document.getElementById('live-feed').innerHTML = '<div class="text-center text-gray-400 dark:text-gray-500 py-10"><i class="fas fa-hourglass-half text-4xl mb-2"></i><p>Belum ada aktivitas absensi</p></div>';
                document.getElementById('total-hadir').innerText = '0';
                loadDashboardStats();
            } else alert('❌ Gagal menghapus log: ' + data.message);
        } catch(e){ alert('Error: ' + e.message); }
    }
});

// ========== COLLAPSE PAYMENT LOG ==========
const toggleBtn = document.getElementById('togglePaymentLog');
const paymentContent = document.getElementById('paymentLogContent');
let collapsed = false;
toggleBtn?.addEventListener('click', () => {
    if (collapsed) {
        paymentContent.style.display = 'block';
        toggleBtn.innerHTML = '<i class="fas fa-chevron-up"></i>';
    } else {
        paymentContent.style.display = 'none';
        toggleBtn.innerHTML = '<i class="fas fa-chevron-down"></i>';
    }
    collapsed = !collapsed;
});

// ========== GRAFIK PEMBAYARAN DENGAN FILTER RENTANG ==========
let paymentChartInstance = null;

async function loadPaymentData(range, startDate = null, endDate = null) {
    try {
        let url = 'api/payment_stats.php?range=' + range;
        if (range === 'custom' && startDate && endDate) {
            url += `&start_date=${startDate}&end_date=${endDate}`;
        }
        const res = await fetch(url);
        const data = await res.json();
        
        if (paymentChartInstance) {
            paymentChartInstance.data.labels = data.labels;
            paymentChartInstance.data.datasets[0].data = data.tagihan;
            paymentChartInstance.data.datasets[1].data = data.terkumpul;
            paymentChartInstance.update();
        } else {
            const ctx = document.getElementById('paymentChart').getContext('2d');
            paymentChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [
                        { label: 'Tagihan (Rp)', data: data.tagihan, backgroundColor: 'rgba(245,158,11,0.7)', borderColor: '#f59e0b', borderWidth: 1 },
                        { label: 'Terkumpul (Rp)', data: data.terkumpul, backgroundColor: 'rgba(16,185,129,0.7)', borderColor: '#10b981', borderWidth: 1 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: { y: { beginAtZero: true, ticks: { callback: (val) => 'Rp ' + val.toLocaleString() } } }
                }
            });
        }
        document.getElementById('totalTagihan').innerText = 'Rp ' + (data.total_tagihan?.toLocaleString() ?? 0);
        document.getElementById('totalDiterima').innerText = 'Rp ' + (data.total_terkumpul?.toLocaleString() ?? 0);
    } catch(e) {
        console.error('Gagal memuat data pembayaran:', e);
        generateDummyPaymentData();
    }
}

function generateDummyPaymentData() {
    const labels = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    const tagihan = [85000000, 88000000, 92000000, 95000000, 91000000, 89000000, 102000000, 105000000, 98000000, 97000000, 95000000, 92000000];
    const terkumpul = [75000000, 78000000, 82000000, 85000000, 81000000, 79000000, 92000000, 95000000, 88000000, 87000000, 85000000, 82000000];
    if (paymentChartInstance) {
        paymentChartInstance.data.labels = labels;
        paymentChartInstance.data.datasets[0].data = tagihan;
        paymentChartInstance.data.datasets[1].data = terkumpul;
        paymentChartInstance.update();
    } else {
        const ctx = document.getElementById('paymentChart').getContext('2d');
        paymentChartInstance = new Chart(ctx, {
            type: 'line',
            data: { labels, datasets: [{ label: 'Tagihan (Rp)', data: tagihan, backgroundColor: 'rgba(245,158,11,0.7)' }, { label: 'Terkumpul (Rp)', data: terkumpul, backgroundColor: 'rgba(16,185,129,0.7)' }] },
            options: { responsive: true, maintainAspectRatio: true, scales: { y: { beginAtZero: true, ticks: { callback: (val) => 'Rp ' + val.toLocaleString() } } } }
        });
    }
    const totalTag = tagihan.reduce((a,b)=>a+b,0);
    const totalTer = terkumpul.reduce((a,b)=>a+b,0);
    document.getElementById('totalTagihan').innerText = 'Rp ' + totalTag.toLocaleString();
    document.getElementById('totalDiterima').innerText = 'Rp ' + totalTer.toLocaleString();
}

const paymentRangeSelect = document.getElementById('paymentRange');
const customDateDiv = document.getElementById('customDateRange');
const startDateInput = document.getElementById('startDate');
const endDateInput = document.getElementById('endDate');
const applyCustomBtn = document.getElementById('applyCustomRange');

paymentRangeSelect?.addEventListener('change', (e) => {
    if (e.target.value === 'custom') {
        customDateDiv.classList.remove('hidden');
        const today = new Date().toISOString().split('T')[0];
        const firstDay = new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0];
        startDateInput.value = firstDay;
        endDateInput.value = today;
    } else {
        customDateDiv.classList.add('hidden');
        loadPaymentData(e.target.value);
    }
});

applyCustomBtn?.addEventListener('click', () => {
    const start = startDateInput.value;
    const end = endDateInput.value;
    if (start && end) {
        loadPaymentData('custom', start, end);
    } else alert('Pilih tanggal mulai dan akhir');
});

loadPaymentData('this_month');

// ========== KALENDER FULLCALENDAR ==========
let calendar = null;
let currentEvents = [];
let activeStacks = {
    schedule: true,
    activity: true,
    holiday: true
};

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
        windowResize: function() { if (calendar) calendar.updateSize(); }
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
    let typeLabel = '';
    let badgeClass = '';
    if (type === 'schedule') {
        typeLabel = '📚 Jadwal Mengajar';
        badgeClass = 'bg-blue-100 text-blue-800';
    } else if (type === 'activity') {
        typeLabel = '🎉 Kegiatan';
        badgeClass = 'bg-orange-100 text-orange-800';
    } else if (type === 'holiday') {
        typeLabel = '🌴 Libur';
        badgeClass = 'bg-red-100 text-red-800';
    } else {
        typeLabel = type;
        badgeClass = 'bg-gray-100 text-gray-800';
    }
    document.getElementById('eventModalTipe').innerHTML = `<span class="inline-block px-2 py-1 rounded-full text-xs font-semibold ${badgeClass}">${typeLabel}</span>`;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeEventModal() {
    const modal = document.getElementById('eventDetailModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

document.querySelectorAll('.stack-filter').forEach(btn => {
    btn.addEventListener('click', () => {
        const stack = btn.getAttribute('data-stack');
        if (stack === 'schedule') activeStacks.schedule = !activeStacks.schedule;
        else if (stack === 'activity') activeStacks.activity = !activeStacks.activity;
        else if (stack === 'holiday') activeStacks.holiday = !activeStacks.holiday;
        btn.classList.toggle('opacity-50');
        btn.classList.toggle('bg-opacity-50');
        refreshCalendar();
    });
});

document.addEventListener('DOMContentLoaded', () => {
    initCalendar();
});

// ========== DATA UNTUK ABSENSI MANUAL & BATCH ==========
const allUsers = <?= json_encode($users) ?>;
const todaySchedules = <?= json_encode($today_schedules) ?>;
const classes = <?= json_encode($classes) ?>;

// ========== ABSENSI MANUAL (Satu Siswa) ==========
const students = allUsers.filter(u => u.role === 'student');
let selectedStudent = null;

function renderStudentList(searchTerm = '') {
    const container = document.getElementById('studentList');
    if (!container) return;
    const filtered = students.filter(s => s.full_name.toLowerCase().includes(searchTerm.toLowerCase()));
    if (filtered.length === 0) {
        container.classList.add('hidden');
        return;
    }
    container.classList.remove('hidden');
    container.innerHTML = '';
    filtered.forEach(student => {
        const div = document.createElement('div');
        div.className = 'user-item';
        div.textContent = student.full_name;
        div.onclick = () => selectStudent(student);
        container.appendChild(div);
    });
}

function selectStudent(student) {
    selectedStudent = student;
    document.getElementById('selectedUserId').value = student.id;
    document.getElementById('selectedUserName').value = student.full_name;
    const studentList = document.getElementById('studentList');
    if (studentList) studentList.classList.add('hidden');
    document.getElementById('searchStudent').value = student.full_name;
    
    const kelasPagiId = student.kelas_pagi_id ? parseInt(student.kelas_pagi_id) : null;
    const kelasDiniyyahId = student.kelas_diniyyah_id ? parseInt(student.kelas_diniyyah_id) : null;
    const scheduleSelect = document.getElementById('scheduleId');
    const classIds = [];
    if (kelasPagiId && !isNaN(kelasPagiId)) classIds.push(kelasPagiId);
    if (kelasDiniyyahId && !isNaN(kelasDiniyyahId)) classIds.push(kelasDiniyyahId);
    
    if (classIds.length === 0) {
        scheduleSelect.innerHTML = '<option value="">Siswa tidak memiliki kelas (pagi/diniyyah)</option>';
        scheduleSelect.disabled = true;
        return;
    }
    const availableSchedules = todaySchedules.filter(s => classIds.includes(parseInt(s.class_id)));
    scheduleSelect.innerHTML = '<option value="">-- Pilih Jadwal --</option>';
    if (availableSchedules.length === 0) {
        scheduleSelect.innerHTML = '<option value="">Tidak ada jadwal untuk kelas siswa ini hari ini</option>';
        scheduleSelect.disabled = true;
    } else {
        availableSchedules.forEach(s => {
            const option = document.createElement('option');
            option.value = s.id;
            option.textContent = `${s.subject_name} - ${s.class_name} (${s.start_time} - ${s.end_time})`;
            scheduleSelect.appendChild(option);
        });
        scheduleSelect.disabled = false;
    }
}

const searchInput = document.getElementById('searchStudent');
if (searchInput) {
    searchInput.addEventListener('input', (e) => renderStudentList(e.target.value));
    document.addEventListener('click', (e) => {
        const list = document.getElementById('studentList');
        if (list && !list.contains(e.target) && e.target !== searchInput) {
            list.classList.add('hidden');
        }
    });
}

function openManualModal() {
    const modalDiv = document.getElementById('manualAbsensiModal');
    if (!modalDiv) return;
    document.getElementById('manualAbsensiForm').reset();
    document.getElementById('selectedUserId').value = '';
    document.getElementById('selectedUserName').value = '';
    document.getElementById('searchStudent').value = '';
    const scheduleSelect = document.getElementById('scheduleId');
    if (scheduleSelect) {
        scheduleSelect.innerHTML = '<option value="">-- Pilih Siswa Terlebih Dahulu --</option>';
        scheduleSelect.disabled = true;
    }
    selectedStudent = null;
    modalDiv.classList.remove('hidden');
    modalDiv.classList.add('flex');
}

function closeManualModal() {
    const modalDiv = document.getElementById('manualAbsensiModal');
    if (modalDiv) {
        modalDiv.classList.add('hidden');
        modalDiv.classList.remove('flex');
    }
}

document.getElementById('manualAbsensiForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const userId = document.getElementById('selectedUserId').value;
    const scheduleId = document.getElementById('scheduleId').value;
    const status = document.getElementById('manualStatus').value;
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;
    if (!userId || !scheduleId) {
        alert('Pilih siswa dan jadwal terlebih dahulu');
        return;
    }
    try {
        const res = await fetch('api/proses_absensi_manual.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId, schedule_id: scheduleId, status: status, csrf_token: csrfToken })
        });
        const data = await res.json();
        if (data.success) {
            alert('Absensi berhasil');
            closeManualModal();
            loadDashboardStats();
        } else {
            alert('Gagal: ' + data.message);
        }
    } catch(e) { alert('Error: ' + e.message); }
});

const manualBtn = document.getElementById('manualAbsensiBtn');
if (manualBtn) manualBtn.addEventListener('click', openManualModal);

// ========== BATCH ABSENSI (SISWA & GURU) ==========
// Helper: dropdown status
function createStatusSelect(userId, defaultValue = 'Hadir') {
    const select = document.createElement('select');
    select.className = 'status-select w-32 border rounded px-2 py-1 text-sm';
    select.setAttribute('data-user-id', userId);
    const options = ['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpha'];
    options.forEach(opt => {
        const option = document.createElement('option');
        option.value = opt;
        option.textContent = opt;
        if (opt === defaultValue) option.selected = true;
        select.appendChild(option);
    });
    return select;
}

// ========== BATCH SISWA (dengan filter tingkat) ==========
const batchSiswaKelasTipe = document.getElementById('batchSiswaKelasTipe');
const batchSiswaGradeLevel = document.getElementById('batchSiswaGradeLevel');
const batchSiswaKelas = document.getElementById('batchSiswaKelas');
const batchSiswaJadwal = document.getElementById('batchSiswaJadwal');
const batchSiswaListDiv = document.getElementById('batchSiswaList');
const batchSiswaSubmit = document.getElementById('batchSiswaSubmit');

let currentAvailableClasses = [];
let currentAvailableSchedules = [];

// Pastikan data classes tersedia
if (!classes || !classes.length) {
    console.warn('Data classes kosong, pastikan PHP mengirim data classes');
}

function populateSiswaGradeLevels() {
    const tipe = batchSiswaKelasTipe.value;
    const filteredClasses = classes.filter(c => c.class_type === tipe);
    const gradeLevels = [...new Set(filteredClasses.map(c => c.grade_level))].sort((a,b)=>a-b);
    batchSiswaGradeLevel.innerHTML = '<option value="">-- Pilih Tingkat --</option>';
    gradeLevels.forEach(level => {
        const option = document.createElement('option');
        option.value = level;
        option.textContent = `Tingkat ${level}`;
        batchSiswaGradeLevel.appendChild(option);
    });
    // Reset
    batchSiswaKelas.innerHTML = '<option value="">Pilih tingkat terlebih dahulu</option>';
    batchSiswaKelas.disabled = true;
    batchSiswaJadwal.disabled = true;
    batchSiswaJadwal.innerHTML = '<option value="">Pilih kelas terlebih dahulu</option>';
    batchSiswaListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Pilih kelas dan jadwal untuk menampilkan daftar siswa</div>';
    currentAvailableClasses = [];
    currentAvailableSchedules = [];
}

function populateSiswaKelas() {
    const tipe = batchSiswaKelasTipe.value;
    const gradeLevel = batchSiswaGradeLevel.value;
    if (!gradeLevel) {
        batchSiswaKelas.innerHTML = '<option value="">Pilih tingkat terlebih dahulu</option>';
        batchSiswaKelas.disabled = true;
        batchSiswaJadwal.disabled = true;
        batchSiswaListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Pilih kelas dan jadwal untuk menampilkan daftar siswa</div>';
        return;
    }
    currentAvailableClasses = classes.filter(c => c.class_type === tipe && c.grade_level == gradeLevel);
    batchSiswaKelas.innerHTML = '<option value="">-- Pilih Kelas --</option>';
    if (currentAvailableClasses.length === 0) {
        batchSiswaKelas.disabled = true;
        batchSiswaKelas.innerHTML = '<option value="">Tidak ada kelas</option>';
        batchSiswaJadwal.disabled = true;
        batchSiswaListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Tidak ada kelas dengan kriteria tersebut</div>';
        return;
    }
    currentAvailableClasses.forEach(kls => {
        const option = document.createElement('option');
        option.value = kls.id;
        option.textContent = `${kls.class_name} (Tingkat ${kls.grade_level})`;
        batchSiswaKelas.appendChild(option);
    });
    batchSiswaKelas.disabled = false;
    batchSiswaJadwal.disabled = true;
    batchSiswaJadwal.innerHTML = '<option value="">Pilih kelas terlebih dahulu</option>';
    batchSiswaListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Pilih jadwal untuk menampilkan daftar siswa</div>';
}

function populateSiswaJadwal() {
    const classId = batchSiswaKelas.value;
    if (!classId) {
        batchSiswaJadwal.innerHTML = '<option value="">Pilih kelas terlebih dahulu</option>';
        batchSiswaJadwal.disabled = true;
        batchSiswaListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Pilih kelas dan jadwal untuk menampilkan daftar siswa</div>';
        return;
    }
    currentAvailableSchedules = todaySchedules.filter(s => s.class_id == classId);
    batchSiswaJadwal.innerHTML = '<option value="">-- Pilih Jadwal --</option>';
    if (currentAvailableSchedules.length === 0) {
        batchSiswaJadwal.disabled = true;
        batchSiswaJadwal.innerHTML = '<option value="">Tidak ada jadwal hari ini</option>';
        batchSiswaListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Tidak ada jadwal untuk kelas ini hari ini</div>';
        return;
    }
    currentAvailableSchedules.forEach(s => {
        const option = document.createElement('option');
        option.value = s.id;
        option.textContent = `${s.subject_name} - ${s.class_name} (${s.start_time} - ${s.end_time})`;
        batchSiswaJadwal.appendChild(option);
    });
    batchSiswaJadwal.disabled = false;
    batchSiswaListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Pilih jadwal untuk menampilkan daftar siswa</div>';
}

function loadSiswaListByJadwal() {
    const jadwalId = batchSiswaJadwal.value;
    const classId = batchSiswaKelas.value;
    const tipe = batchSiswaKelasTipe.value;
    if (!jadwalId || !classId) {
        batchSiswaListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Pilih jadwal terlebih dahulu</div>';
        return;
    }
    const siswaFilter = allUsers.filter(u => u.role === 'student' && 
        ((tipe === 'pagi' && u.kelas_pagi_id == classId) || (tipe === 'diniyyah' && u.kelas_diniyyah_id == classId)));
    if (siswaFilter.length === 0) {
        batchSiswaListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Tidak ada siswa di kelas ini</div>';
        return;
    }
    let html = '<table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"><thead class="bg-gray-50 dark:bg-gray-700"><tr><th class="px-4 py-2 text-left">Nama</th><th class="px-4 py-2 text-left">NIS</th><th class="px-4 py-2 text-left">Status</th></tr></thead><tbody>';
    siswaFilter.forEach(s => {
        html += `<tr><td class="px-4 py-2">${escapeHtml(s.full_name)}</td><td class="px-4 py-2">${escapeHtml(s.nidn_or_nisn || '-')}</td><td class="px-4 py-2">${createStatusSelect(s.id).outerHTML}</td></tr>`;
    });
    html += '</tbody></tr>';
    batchSiswaListDiv.innerHTML = html;
    window.selectedSiswaScheduleId = jadwalId;
}

// Event listeners untuk batch siswa
if (batchSiswaKelasTipe) batchSiswaKelasTipe.addEventListener('change', populateSiswaGradeLevels);
if (batchSiswaGradeLevel) batchSiswaGradeLevel.addEventListener('change', populateSiswaKelas);
if (batchSiswaKelas) batchSiswaKelas.addEventListener('change', populateSiswaJadwal);
if (batchSiswaJadwal) batchSiswaJadwal.addEventListener('change', loadSiswaListByJadwal);
if (batchSiswaSubmit) {
    batchSiswaSubmit.addEventListener('click', async () => {
        const jadwalId = window.selectedSiswaScheduleId;
        if (!jadwalId) { showToast('Pilih jadwal terlebih dahulu', 'error'); return; }
        const statusSelects = document.querySelectorAll('#batchSiswaList .status-select');
        if (statusSelects.length === 0) { showToast('Tidak ada siswa', 'error'); return; }
        const attendances = {};
        statusSelects.forEach(sel => attendances[sel.getAttribute('data-user-id')] = sel.value);
        try {
            const res = await fetch('api/proses_absensi_batch.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ schedule_id: jadwalId, attendances: attendances, csrf_token: '<?= $_SESSION['csrf_token'] ?>' })
            });
            const data = await res.json();
            if (data.success) {
                showToast(`✅ ${data.message}`, 'success');
                setTimeout(() => location.reload(), 1500);
            } else showToast('❌ Gagal: ' + data.message, 'error');
        } catch(e) { showToast('Error: ' + e.message, 'error'); }
    });
}

// ========== BATCH GURU (tidak diubah strukturnya, tapi tambahkan pengecekan) ==========
const batchGuruKelasTipe = document.getElementById('batchGuruKelasTipe');
const batchGuruGradeLevel = document.getElementById('batchGuruGradeLevel');
const batchGuruJadwal = document.getElementById('batchGuruJadwal');
const batchGuruListDiv = document.getElementById('batchGuruList');
const batchGuruSubmit = document.getElementById('batchGuruSubmit');
let currentGuruSchedules = [];

function populateGradeLevels() {
    const tipe = batchGuruKelasTipe.value;
    const filteredClasses = classes.filter(c => c.class_type === tipe);
    const gradeLevels = [...new Set(filteredClasses.map(c => c.grade_level))].sort((a,b)=>a-b);
    batchGuruGradeLevel.innerHTML = '<option value="">-- Pilih Tingkat --</option>';
    gradeLevels.forEach(level => {
        const option = document.createElement('option');
        option.value = level;
        option.textContent = `Tingkat ${level}`;
        batchGuruGradeLevel.appendChild(option);
    });
    batchGuruJadwal.disabled = true;
    batchGuruJadwal.innerHTML = '<option value="">Pilih tingkat terlebih dahulu</option>';
    batchGuruListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Pilih tingkat dan jadwal untuk menampilkan daftar guru</div>';
    currentGuruSchedules = [];
}

function loadJadwalByGrade() {
    const tipe = batchGuruKelasTipe.value;
    const gradeLevel = batchGuruGradeLevel.value;
    if (!gradeLevel) {
        batchGuruJadwal.disabled = true;
        batchGuruJadwal.innerHTML = '<option value="">Pilih tingkat terlebih dahulu</option>';
        batchGuruListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Pilih tingkat dan jadwal untuk menampilkan daftar guru</div>';
        return;
    }
    const classIds = classes.filter(c => c.class_type === tipe && c.grade_level == gradeLevel).map(c => c.id);
    if (classIds.length === 0) {
        batchGuruJadwal.disabled = true;
        batchGuruJadwal.innerHTML = '<option value="">Tidak ada kelas</option>';
        batchGuruListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Tidak ada kelas dengan kriteria tersebut</div>';
        return;
    }
    currentGuruSchedules = todaySchedules.filter(s => classIds.includes(s.class_id));
    if (currentGuruSchedules.length === 0) {
        batchGuruJadwal.disabled = true;
        batchGuruJadwal.innerHTML = '<option value="">Tidak ada jadwal hari ini</option>';
        batchGuruListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Tidak ada jadwal mengajar untuk tingkat ini hari ini</div>';
        return;
    }
    batchGuruJadwal.innerHTML = '<option value="">-- Pilih Jadwal --</option>';
    currentGuruSchedules.forEach(s => {
        const option = document.createElement('option');
        option.value = s.id;
        option.textContent = `${s.subject_name} - ${s.class_name} (${s.start_time} - ${s.end_time})`;
        batchGuruJadwal.appendChild(option);
    });
    batchGuruJadwal.disabled = false;
    batchGuruListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Pilih jadwal untuk menampilkan daftar guru</div>';
}

function loadGuruListByJadwal() {
    const scheduleId = batchGuruJadwal.value;
    if (!scheduleId) {
        batchGuruListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Pilih jadwal untuk menampilkan daftar guru</div>';
        return;
    }
    const selected = currentGuruSchedules.find(s => s.id == scheduleId);
    if (!selected) {
        batchGuruListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Jadwal tidak valid</div>';
        return;
    }
    const teacherId = selected.teacher_id;
    if (!teacherId) {
        batchGuruListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Jadwal tidak memiliki guru pengajar</div>';
        return;
    }
    const guru = allUsers.find(u => u.role === 'teacher' && u.id === teacherId);
    if (!guru) {
        batchGuruListDiv.innerHTML = '<div class="text-center text-gray-500 py-8">Data guru tidak ditemukan</div>';
        return;
    }
    let html = '<table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"><thead class="bg-gray-50 dark:bg-gray-700"><tr><th class="px-4 py-2 text-left">Nama Guru</th><th class="px-4 py-2 text-left">NIDN</th><th class="px-4 py-2 text-left">Status</th></tr></thead><tbody>';
    html += `<tr><td class="px-4 py-2">${escapeHtml(guru.full_name)}</td><td class="px-4 py-2">${escapeHtml(guru.nidn_or_nisn || '-')}</td><td class="px-4 py-2">${createStatusSelect(guru.id).outerHTML}</td></tr>`;
    html += '</tbody></table>';
    batchGuruListDiv.innerHTML = html;
    window.selectedGuruScheduleId = scheduleId;
}

if (batchGuruKelasTipe) batchGuruKelasTipe.addEventListener('change', populateGradeLevels);
if (batchGuruGradeLevel) batchGuruGradeLevel.addEventListener('change', loadJadwalByGrade);
if (batchGuruJadwal) batchGuruJadwal.addEventListener('change', loadGuruListByJadwal);
if (batchGuruSubmit) {
    batchGuruSubmit.addEventListener('click', async () => {
        const scheduleId = window.selectedGuruScheduleId;
        if (!scheduleId) { showToast('Pilih jadwal terlebih dahulu', 'error'); return; }
        const statusSelects = document.querySelectorAll('#batchGuruList .status-select');
        if (statusSelects.length === 0) { showToast('Tidak ada guru', 'error'); return; }
        const attendances = {};
        statusSelects.forEach(sel => attendances[sel.getAttribute('data-user-id')] = sel.value);
        try {
            const res = await fetch('api/proses_absensi_batch.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ schedule_id: scheduleId, attendances: attendances, csrf_token: '<?= $_SESSION['csrf_token'] ?>' })
            });
            const data = await res.json();
            if (data.success) {
                showToast(`✅ ${data.message}`, 'success');
                setTimeout(() => location.reload(), 1500);
            } else showToast('❌ Gagal: ' + data.message, 'error');
        } catch(e) { showToast('Error: ' + e.message, 'error'); }
    });
}

// ========== TAB SWITCHING ==========
const tabSiswa = document.getElementById('tabSiswaBtn');
const tabGuru = document.getElementById('tabGuruBtn');
const panelSiswa = document.getElementById('batchSiswaPanel');
const panelGuru = document.getElementById('batchGuruPanel');
if (tabSiswa && tabGuru && panelSiswa && panelGuru) {
    tabSiswa.addEventListener('click', () => {
        tabSiswa.classList.add('text-blue-600', 'border-blue-600');
        tabGuru.classList.remove('text-blue-600', 'border-blue-600');
        panelSiswa.classList.remove('hidden');
        panelGuru.classList.add('hidden');
    });
    tabGuru.addEventListener('click', () => {
        tabGuru.classList.add('text-blue-600', 'border-blue-600');
        tabSiswa.classList.remove('text-blue-600', 'border-blue-600');
        panelGuru.classList.remove('hidden');
        panelSiswa.classList.add('hidden');
    });
}

// Inisialisasi (panggil setelah DOMContentLoaded, tapi kita panggil langsung juga)
setTimeout(() => {
    if (batchSiswaKelasTipe) populateSiswaGradeLevels();
    if (batchGuruKelasTipe) populateGradeLevels();
}, 100); // sedikit delay memastikan elemen siap

// ========== INITIAL LOAD ==========
loadDashboardStats();
</script>
<script>
// ========== NOTIFIKASI ADMIN DENGAN MODAL ==========
const notifBtn = document.getElementById('notificationBtn');
const notifPanel = document.getElementById('notificationPanel');
const unreadBadge = document.getElementById('unreadBadge');
const markAllBtn = document.getElementById('markAllReadBtn');

if (notifBtn) {
    notifBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        notifPanel.classList.toggle('hidden');
    });
    document.addEventListener('click', function(event) {
        if (!notifBtn.contains(event.target) && !notifPanel.contains(event.target)) {
            notifPanel.classList.add('hidden');
        }
    });
}

async function markAsRead(announcementId, itemElement) {
    let csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
    if (!csrfToken) {
        csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    }
    if (!csrfToken) {
        console.error('CSRF token tidak ditemukan');
        return;
    }
    try {
        const res = await fetch('api/mark_announcement_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ announcement_id: announcementId, csrf_token: csrfToken })
        });
        const data = await res.json();
        if (data.success) {
            itemElement.classList.remove('bg-blue-50', 'dark:bg-blue-900/20');
            const dot = itemElement.querySelector('.w-2.h-2');
            if (dot) dot.remove();
            let currentCount = parseInt(unreadBadge.innerText) || 0;
            if (currentCount > 0) {
                currentCount--;
                if (currentCount === 0) {
                    unreadBadge.classList.add('hidden');
                    unreadBadge.innerText = '';
                } else {
                    unreadBadge.innerText = currentCount;
                }
            }
        } else {
            console.error('Gagal:', data.message);
        }
    } catch (err) {
        console.error(err);
    }
}

// Event listener untuk klik notifikasi: tandai baca + buka modal
document.getElementById('notificationList')?.addEventListener('click', async (e) => {
    const item = e.target.closest('.notification-item');
    if (!item) return;
    const id = item.getAttribute('data-id');
    if (!id) return;
    e.stopPropagation();
    // Tandai sebagai dibaca
    await markAsRead(id, item);
    // Ambil data dari elemen notifikasi
    const title = item.querySelector('.font-medium')?.innerText || '';
    const content = item.querySelector('.text-xs.text-gray-500')?.innerText || '';
    const dateText = item.querySelector('span.text-xs.text-gray-400')?.innerText || '';
    // Isi modal
    document.getElementById('announcementModalTitle').innerText = title;
    document.getElementById('announcementModalContent').innerHTML = content.replace(/\n/g, '<br>');
    document.getElementById('announcementModalDate').innerHTML = dateText;
    // Tampilkan modal
    const modal = document.getElementById('announcementDetailModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    // Tutup dropdown notifikasi
    notifPanel.classList.add('hidden');
});

// Tutup modal jika klik di luar
document.getElementById('announcementDetailModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeAnnouncementModal();
    }
});

function closeAnnouncementModal() {
    const modal = document.getElementById('announcementDetailModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Tandai semua dibaca
if (markAllBtn) {
    markAllBtn.addEventListener('click', async () => {
        const unreadItems = document.querySelectorAll('.notification-item.bg-blue-50, .notification-item.dark\\:bg-blue-900\\/20');
        if (unreadItems.length === 0) return;
        let csrfToken = document.querySelector('input[name="csrf_token"]')?.value || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        try {
            const res = await fetch('api/mark_all_announcements_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                unreadItems.forEach(item => {
                    item.classList.remove('bg-blue-50', 'dark:bg-blue-900/20');
                    const dot = item.querySelector('.w-2.h-2');
                    if (dot) dot.remove();
                });
                unreadBadge.classList.add('hidden');
                unreadBadge.innerText = '';
            }
        } catch (err) {
            console.error(err);
        }
    });
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>