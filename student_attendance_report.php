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

$page_title = 'Laporan Absensi - SIAKAP';
$current_page = 'student_attendance_report';
require_once __DIR__ . '/config.php';

function safeArray($data) { return is_array($data) ? $data : []; }

$student_id = $_SESSION['user_id'];

// Ambil data siswa
$student_raw = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $student_id]);
$student = (is_array($student_raw) && count($student_raw) > 0) ? $student_raw[0] : null;
if (!$student) {
    die("Data siswa tidak ditemukan.");
}

// Ambil filter tanggal
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$export = isset($_GET['export']) ? $_GET['export'] : '';

// Validasi tanggal
if ($start_date > $end_date) {
    $temp = $start_date;
    $start_date = $end_date;
    $end_date = $temp;
}

// Ambil semua log absensi siswa dalam rentang tanggal
$logs_params = [
    'user_id' => 'eq.' . $student_id,
    'scan_time' => 'gte.' . $start_date . ' 00:00:00',
    'scan_time' => 'lte.' . $end_date . ' 23:59:59',
    'order' => 'scan_time.desc'
];
$logs_raw = supabase_admin_request('GET', 'attendance_logs', null, $logs_params);
$logs = safeArray($logs_raw);

// Ambil semua schedule_id yang ada di logs
$schedule_ids = array_unique(array_column($logs, 'schedule_id'));
$schedule_ids = array_filter($schedule_ids, function($id) { return $id > 0; });

// Ambil data schedules dan subjects untuk mapping
$schedule_map = [];
if (!empty($schedule_ids)) {
    $ids_string = implode(',', $schedule_ids);
    $schedules_raw = supabase_admin_request('GET', 'schedules', null, ['id' => 'in.(' . $ids_string . ')']);
    $schedules = safeArray($schedules_raw);
    // Ambil semua subject_id dari schedules
    $subject_ids = array_unique(array_column($schedules, 'subject_id'));
    $subject_ids = array_filter($subject_ids, function($id) { return $id > 0; });
    $subjects = [];
    if (!empty($subject_ids)) {
        $subj_ids_string = implode(',', $subject_ids);
        $subjects_raw = supabase_admin_request('GET', 'subjects', null, ['id' => 'in.(' . $subj_ids_string . ')']);
        $subjects = safeArray($subjects_raw);
        $subjects = array_column($subjects, 'subject_name', 'id');
    }
    // Ambil kelas
    $class_ids = array_unique(array_column($schedules, 'class_id'));
    $class_ids = array_filter($class_ids, function($id) { return $id > 0; });
    $classes = [];
    if (!empty($class_ids)) {
        $class_ids_string = implode(',', $class_ids);
        $classes_raw = supabase_admin_request('GET', 'classes', null, ['id' => 'in.(' . $class_ids_string . ')']);
        $classes = safeArray($classes_raw);
        $classes = array_column($classes, 'class_name', 'id');
    }
    // Buat mapping schedule_id -> subject_name, class_name
    foreach ($schedules as $sch) {
        $schedule_map[$sch['id']] = [
            'subject' => $subjects[$sch['subject_id']] ?? 'Mata Pelajaran',
            'class' => $classes[$sch['class_id']] ?? 'Kelas'
        ];
    }
}

// Hitung statistik
$stats = ['Hadir' => 0, 'Terlambat' => 0, 'Izin' => 0, 'Sakit' => 0, 'Alpha' => 0];
foreach ($logs as $log) {
    $status = $log['status'] ?? 'Hadir';
    if (isset($stats[$status])) {
        $stats[$status]++;
    }
}
$total_kehadiran = array_sum($stats);

// Ekspor CSV
if ($export == 'csv' && !empty($logs)) {
    $filename = "laporan_absensi_{$student['full_name']}_{$start_date}_to_{$end_date}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['No', 'Tanggal', 'Jam', 'Status', 'Keterangan', 'Mata Pelajaran', 'Kelas']);
    $no = 1;
    foreach ($logs as $log) {
        $schedule_info = $schedule_map[$log['schedule_id']] ?? ['subject' => '-', 'class' => '-'];
        fputcsv($output, [
            $no++,
            date('d/m/Y', strtotime($log['scan_time'])),
            date('H:i', strtotime($log['scan_time'])),
            $log['status'] ?? 'Hadir',
            $log['note'] ?? '',
            $schedule_info['subject'],
            $schedule_info['class']
        ]);
    }
    fclose($output);
    exit;
}

require_once __DIR__ . '/includes/nav_items.php';
foreach ($nav_items as &$item) {
    $item['active'] = ($item['link'] == 'student_attendance_report.php');
}
unset($item);
require_once __DIR__ . '/includes/header_user.php';
?>

<style>
    .stat-card {
        background: white;
        border-radius: 10px;
        padding: 1rem;
        text-align: center;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        border-left: 4px solid #3b82f6;
    }
    .dark .stat-card { background: #1f2937; }
    .stat-value { font-size: 1.8rem; font-weight: 700; }
    .stat-label { font-size: 0.8rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; }
    .dark .stat-label { color: #9ca3af; }
    .stat-hadir { border-left-color: #22c55e; }
    .stat-terlambat { border-left-color: #eab308; }
    .stat-izin { border-left-color: #3b82f6; }
    .stat-sakit { border-left-color: #8b5cf6; }
    .stat-alpha { border-left-color: #ef4444; }

    .filter-form input, .filter-form select {
        border: 1px solid #d1d5db;
        border-radius: 6px;
        padding: 0.4rem 0.75rem;
        width: 100%;
    }
    .dark .filter-form input, .dark .filter-form select {
        background: #374151;
        color: white;
        border-color: #4b5563;
    }
    .btn-export {
        background: #10b981;
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: background 0.2s;
    }
    .btn-export:hover { background: #059669; }

    /* Tabel */
    .attendance-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .attendance-table th, .attendance-table td {
        padding: 0.5rem 0.75rem;
        border-bottom: 1px solid #e5e7eb;
        text-align: left;
    }
    .dark .attendance-table th, .dark .attendance-table td {
        border-bottom-color: #4b5563;
    }
    .attendance-table th {
        background: #f3f4f6;
        font-weight: 600;
    }
    .dark .attendance-table th { background: #374151; }
    .attendance-table tr:hover td { background: #f9fafb; }
    .dark .attendance-table tr:hover td { background: #2d3748; }

    .badge-status {
        display: inline-block;
        padding: 0.2rem 0.6rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        min-width: 65px;
        text-align: center;
    }
    .badge-hadir { background: #dcfce7; color: #166534; }
    .badge-terlambat { background: #fef9c3; color: #854d0e; }
    .badge-izin { background: #dbeafe; color: #1e40af; }
    .badge-sakit { background: #ede9fe; color: #5b21b6; }
    .badge-alpha { background: #fee2e2; color: #991b1b; }
    .badge-default { background: #f3f4f6; color: #4b5563; }
    .dark .badge-hadir { background: #166534; color: #86efac; }
    .dark .badge-terlambat { background: #854d0e; color: #fde047; }
    .dark .badge-izin { background: #1e40af; color: #93c5fd; }
    .dark .badge-sakit { background: #5b21b6; color: #c4b5fd; }
    .dark .badge-alpha { background: #991b1b; color: #fca5a5; }
    .dark .badge-default { background: #4b5563; color: #9ca3af; }

    @media (max-width: 640px) {
        .table-wrapper { overflow-x: auto; }
        .attendance-table { font-size: 0.75rem; }
        .attendance-table th, .attendance-table td { padding: 0.3rem 0.4rem; }
        .stat-value { font-size: 1.4rem; }
    }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar_user.php'; ?>
    <div class="main-content-container flex-1">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openMobileSidebarBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Laporan Absensi</h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                        <i class="fas fa-moon text-xl dark:hidden"></i>
                        <i class="fas fa-sun text-xl hidden dark:inline"></i>
                    </button>
                    <div class="relative group">
                        <button class="flex items-center space-x-2 focus:outline-none">
                            <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold overflow-hidden">
                                <?php 
                                $user_photo = $student['photo_url'] ?? '';
                                if ($user_photo): ?>
                                    <img src="<?= htmlspecialchars($user_photo) ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <span><?= strtoupper(substr($student['full_name'] ?? 'A', 0, 1)) ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="hidden md:inline text-gray-700 dark:text-gray-200"><?= htmlspecialchars($student['full_name'] ?? 'Siswa') ?></span>
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
            <!-- Informasi siswa -->
            <div class="mb-6">
                <h2 class="text-2xl font-bold dark:text-white"><?= htmlspecialchars($student['full_name'] ?? 'Siswa') ?></h2>
                <p class="text-gray-600 dark:text-gray-300">NIS / NISN: <?= htmlspecialchars($student['nidn_or_nisn'] ?? '-') ?></p>
            </div>

            <!-- Filter -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 mb-6 filter-form">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
                    <div>
                        <label class="block text-gray-700 dark:text-gray-300 text-sm mb-1">Tanggal Mulai</label>
                        <input type="date" name="start_date" value="<?= $start_date ?>">
                    </div>
                    <div>
                        <label class="block text-gray-700 dark:text-gray-300 text-sm mb-1">Tanggal Berakhir</label>
                        <input type="date" name="end_date" value="<?= $end_date ?>">
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm"><i class="fas fa-search mr-1"></i> Tampilkan</button>
                        <a href="<?= $_SERVER['PHP_SELF'] ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm"><i class="fas fa-undo-alt mr-1"></i> Reset</a>
                    </div>
                    <?php if (!empty($logs)): ?>
                    <div class="flex justify-end">
                        <a href="?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&export=csv" class="btn-export">
                            <i class="fas fa-download"></i> Ekspor CSV
                        </a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Statistik -->
            <?php if (!empty($logs)): ?>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3 mb-6">
                <div class="stat-card stat-hadir">
                    <div class="stat-value text-green-600"><?= $stats['Hadir'] ?></div>
                    <div class="stat-label">Hadir</div>
                </div>
                <div class="stat-card stat-terlambat">
                    <div class="stat-value text-yellow-600"><?= $stats['Terlambat'] ?></div>
                    <div class="stat-label">Terlambat</div>
                </div>
                <div class="stat-card stat-izin">
                    <div class="stat-value text-blue-600"><?= $stats['Izin'] ?></div>
                    <div class="stat-label">Izin</div>
                </div>
                <div class="stat-card stat-sakit">
                    <div class="stat-value text-purple-600"><?= $stats['Sakit'] ?></div>
                    <div class="stat-label">Sakit</div>
                </div>
                <div class="stat-card stat-alpha">
                    <div class="stat-value text-red-600"><?= $stats['Alpha'] ?></div>
                    <div class="stat-label">Alpha</div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tabel -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-3">
                    <i class="fas fa-list mr-2 text-blue-500"></i> Riwayat Absensi
                    <?php if (!empty($logs)): ?>
                    <span class="text-sm font-normal text-gray-500 dark:text-gray-400">(<?= count($logs) ?> catatan)</span>
                    <?php endif; ?>
                </h3>

                <?php if (empty($logs)): ?>
                    <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                        <i class="fas fa-inbox text-4xl mb-2"></i>
                        <p>Tidak ada data absensi pada periode yang dipilih.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper overflow-x-auto">
                        <table class="attendance-table">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal</th>
                                    <th>Jam</th>
                                    <th>Status</th>
                                    <th>Keterangan</th>
                                    <th>Mata Pelajaran</th>
                                    <th>Kelas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($logs as $log): 
                                    $schedule_info = $schedule_map[$log['schedule_id']] ?? ['subject' => '-', 'class' => '-'];
                                    $status = $log['status'] ?? 'Hadir';
                                    $badge_class = match($status) {
                                        'Hadir' => 'badge-hadir',
                                        'Terlambat' => 'badge-terlambat',
                                        'Izin' => 'badge-izin',
                                        'Sakit' => 'badge-sakit',
                                        'Alpha' => 'badge-alpha',
                                        default => 'badge-default'
                                    };
                                ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= date('d/m/Y', strtotime($log['scan_time'])) ?></td>
                                    <td><?= date('H:i', strtotime($log['scan_time'])) ?></td>
                                    <td><span class="badge-status <?= $badge_class ?>"><?= $status ?></span></td>
                                    <td><?= htmlspecialchars($log['note'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($schedule_info['subject']) ?></td>
                                    <td><?= htmlspecialchars($schedule_info['class']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<script>
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