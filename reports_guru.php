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

$page_title = 'Laporan Kehadiran - Guru';
$current_page = 'reports_guru';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';


// Fungsi prioritas status untuk multiple log dalam satu hari
function getStatusPriority($status) {
    $priorities = ['Hadir' => 5, 'Terlambat' => 4, 'Sakit' => 3, 'Izin' => 2, 'Alpha' => 1];
    return $priorities[$status] ?? 0;
}

$user_id = $_SESSION['user_id'];

// Ambil data guru
$user_data = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $user_id]);
$guru = (is_array($user_data) && !empty($user_data)) ? $user_data[0] : null;
if (!$guru) { header('Location: logout.php'); exit; }

// Ambil jadwal mengajar guru (untuk menentukan kelas yang diajar)
$jadwal_raw = supabase_admin_request('GET', 'schedules', null, ['teacher_id' => 'eq.' . $user_id]);
$jadwal = safeArray($jadwal_raw);

if (empty($jadwal)) {
    $error_message = "Anda belum memiliki jadwal mengajar. Tidak ada kelas yang dapat dilaporkan.";
    $classes = [];
    $teacher_class_ids = [];
} else {
    // Kumpulkan semua class_id unik dari jadwal
    $teacher_class_ids = array_unique(array_column($jadwal, 'class_id'));
    $teacher_class_ids = array_filter($teacher_class_ids, function($id) { return $id > 0; });
    if (empty($teacher_class_ids)) {
        $error_message = "Jadwal mengajar Anda tidak memiliki kelas yang valid.";
        $classes = [];
    } else {
        // Ambil semua kelas
        $all_classes = safeArray(supabase_admin_request('GET', 'classes'));
        // Ambil semua siswa
        $all_students = safeArray(supabase_admin_request('GET', 'users', null, ['role' => 'eq.student']));
        
        // Tentukan tipe kelas berdasarkan siswa yang ada di kelas tersebut
        $class_type_map = [];
        foreach ($teacher_class_ids as $cid) {
            $has_pagi = false;
            $has_diniyyah = false;
            foreach ($all_students as $s) {
                if (isset($s['kelas_pagi_id']) && $s['kelas_pagi_id'] == $cid) {
                    $has_pagi = true;
                }
                if (isset($s['kelas_diniyyah_id']) && $s['kelas_diniyyah_id'] == $cid) {
                    $has_diniyyah = true;
                }
                if ($has_pagi && $has_diniyyah) break;
            }
            // Jika keduanya true, prioritaskan pagi (bisa diubah)
            if ($has_pagi) {
                $class_type_map[$cid] = 'pagi';
            } elseif ($has_diniyyah) {
                $class_type_map[$cid] = 'diniyyah';
            } else {
                // Jika tidak ada siswa, anggap kelas pagi (default)
                $class_type_map[$cid] = 'pagi';
            }
        }
        
        // Filter kelas yang diajar
        $classes = array_filter($all_classes, function($c) use ($teacher_class_ids) {
            return in_array($c['id'], $teacher_class_ids);
        });
        $classes = array_values($classes);
        
        // Tambahkan tipe ke setiap kelas
        foreach ($classes as &$cls) {
            $cls['type'] = $class_type_map[$cls['id']] ?? 'pagi';
        }
        unset($cls);
    }
}

// Filter dari request
$selected_class_type = isset($_GET['class_type']) ? $_GET['class_type'] : 'pagi';
$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$selected_activity_id = isset($_GET['activity_id']) ? (int)$_GET['activity_id'] : 0;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$export = isset($_GET['export']) ? $_GET['export'] : '';

// Validasi tanggal
if ($start_date > $end_date) {
    $temp = $start_date;
    $start_date = $end_date;
    $end_date = $temp;
    $warning_date = "Tanggal mulai lebih besar dari tanggal akhir, telah ditukar.";
} else {
    $warning_date = "";
}

// Tentukan kolom kelas yang sesuai
$class_column = ($selected_class_type == 'pagi') ? 'kelas_pagi_id' : 'kelas_diniyyah_id';

// Ambil semua siswa (hanya yang diajar guru)
$all_students = safeArray(supabase_admin_request('GET', 'users', null, ['role' => 'eq.student']));

// Filter siswa berdasarkan kelas yang diajar (guru) dan tipe kelas yang dipilih
$students = array_filter($all_students, function($s) use ($teacher_class_ids, $class_column, $selected_class_id) {
    // Cek apakah siswa memiliki kelas yang diajar guru
    $student_class_id = $s[$class_column] ?? 0;
    if (!in_array($student_class_id, $teacher_class_ids)) {
        return false;
    }
    // Jika ada filter kelas tertentu
    if ($selected_class_id > 0 && $student_class_id != $selected_class_id) {
        return false;
    }
    return true;
});
$students = array_values($students);

// Jika tidak ada siswa, beri pesan
if (empty($students) && empty($error_message)) {
    $error_message = "Tidak ada siswa pada kelas yang Anda ajar untuk tipe kelas yang dipilih.";
}

// Filter pencarian
if (!empty($search) && !empty($students)) {
    $students = array_filter($students, function($s) use ($search) {
        $search_lower = strtolower($search);
        return strpos(strtolower($s['full_name'] ?? ''), $search_lower) !== false ||
               strpos(strtolower($s['nidn_or_nisn'] ?? ''), $search_lower) !== false;
    });
    $students = array_values($students);
}

// Urutkan siswa berdasarkan nama (abjad)
usort($students, function($a, $b) {
    return strcmp($a['full_name'], $b['full_name']);
});

// Ambil kegiatan
$activities = safeArray(supabase_admin_request('GET', 'activities', null, ['is_active' => 'eq.true', 'order' => 'name.asc']));

// Jika tidak ada siswa, lewati proses log
if (empty($students)) {
    $table_data = [];
    $date_range = [];
} else {
    // Rentang tanggal
    $start_datetime = $start_date . ' 00:00:00';
    $end_datetime = $end_date . ' 23:59:59';
    
    // Ambil log absensi
    $logs_params = [
        'scan_time' => 'gte.' . $start_datetime,
        'scan_time' => 'lte.' . $end_datetime
    ];
    if ($selected_activity_id > 0) {
        $logs_params['activity_id'] = 'eq.' . $selected_activity_id;
    }
    $logs = safeArray(supabase_admin_request('GET', 'attendance_logs', null, $logs_params));
    
    // Generate date range
    $start_dt = new DateTime($start_date);
    $end_dt = new DateTime($end_date);
    $end_dt->modify('+1 day');
    $interval = new DateInterval('P1D');
    $date_period = new DatePeriod($start_dt, $interval, $end_dt);
    $date_range = [];
    foreach ($date_period as $dt) {
        $date_range[] = $dt->format('Y-m-d');
    }
    
    // Mapping log
    $logs_by_user_date = [];
    $student_totals = [];
    foreach ($logs as $log) {
        $user_id = $log['user_id'];
        $log_date = date('Y-m-d', strtotime($log['scan_time']));
        $status = $log['status'];
        $key = $user_id . '|' . $log_date;
        
        if (!isset($logs_by_user_date[$key]) || getStatusPriority($status) > getStatusPriority($logs_by_user_date[$key])) {
            $logs_by_user_date[$key] = $status;
        }
        
        if (!isset($student_totals[$user_id])) {
            $student_totals[$user_id] = ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpha' => 0, 'Terlambat' => 0];
        }
        if (isset($student_totals[$user_id][$status])) {
            $student_totals[$user_id][$status]++;
        }
    }
    
    // Siapkan data tabel
    $table_data = [];
    $no = 1;
    foreach ($students as $student) {
        $id = $student['id'];
        $nama = $student['full_name'];
        $nisn = $student['nidn_or_nisn'] ?? '-';
        
        $daily_status = [];
        foreach ($date_range as $date) {
            $key = $id . '|' . $date;
            $daily_status[$date] = $logs_by_user_date[$key] ?? '-';
        }
        
        $totals = $student_totals[$id] ?? ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpha' => 0, 'Terlambat' => 0];
        $total_kehadiran = array_sum($totals);
        
        $table_data[] = [
            'no' => $no++,
            'nisn' => $nisn,
            'nama' => $nama,
            'daily' => $daily_status,
            'hadir' => $totals['Hadir'],
            'sakit' => $totals['Sakit'],
            'izin' => $totals['Izin'],
            'alpha' => $totals['Alpha'],
            'terlambat' => $totals['Terlambat'],
            'total' => $total_kehadiran
        ];
    }
}

// Ekspor CSV
if ($export == 'csv' && !empty($table_data)) {
    $class_name = ($selected_class_id > 0) ? (array_column($classes, 'class_name', 'id')[$selected_class_id] ?? 'Kelas') : 'Semua Kelas';
    $activity_name = ($selected_activity_id > 0) ? (array_column($activities, 'name', 'id')[$selected_activity_id] ?? 'Kegiatan') : 'Semua Kegiatan';
    $filename = "laporan_kehadiran_guru_{$class_name}_{$activity_name}_{$start_date}_to_{$end_date}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    
    $headers = ['No', 'NISN', 'Nama'];
    foreach ($date_range as $date) {
        $headers[] = date('d/m', strtotime($date));
    }
    $headers = array_merge($headers, ['Hadir', 'Sakit', 'Izin', 'Alpha', 'Terlambat', 'Total']);
    fputcsv($output, $headers);
    
    foreach ($table_data as $row) {
        $csv_row = [$row['no'], $row['nisn'], $row['nama']];
        foreach ($date_range as $date) {
            $csv_row[] = $row['daily'][$date];
        }
        $csv_row[] = $row['hadir'];
        $csv_row[] = $row['sakit'];
        $csv_row[] = $row['izin'];
        $csv_row[] = $row['alpha'];
        $csv_row[] = $row['terlambat'];
        $csv_row[] = $row['total'];
        fputcsv($output, $csv_row);
    }
    fclose($output);
    exit;
}

// Navigasi sidebar (sesuaikan dengan menu guru)
require_once __DIR__ . '/includes/nav_items.php';
foreach ($nav_items as &$item) {
    $item['active'] = ($item['link'] == 'reports_guru.php');
}
unset($item);

require_once __DIR__ . '/includes/header_user.php';
?>

<style>
/* Perbaikan scroll dan sticky header */
.main-content-container {
    overflow-x: auto;
    position: relative;
}
/* ======= PERBAIKAN SCROLL MOBILE ======= */
.table-wrapper {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch; /* smooth scrolling di iOS */
    max-width: 100%;
    position: relative;
}

/* ======= STICKY KOLOM UNTUK PC ======= */
/* Default: sticky berlaku untuk semua layar */
.sticky-col-no,
.sticky-col-nisn,
.sticky-col-nama {
    position: sticky;
    z-index: 15;
    background-color: #ffffff; /* solid agar tidak tembus */
}
.dark .sticky-col-no,
.dark .sticky-col-nisn,
.dark .sticky-col-nama {
    background-color: #1f2937;
}
.sticky-col-no {
    left: 0;
}
.sticky-col-nisn {
    left: 60px;
}
.sticky-col-nama {
    left: 180px;
}

/* ======= NONAKTIFKAN STICKY DI MOBILE ======= */
@media (max-width: 640px) {
    .sticky-col-no,
    .sticky-col-nisn,
    .sticky-col-nama {
        position: static !important;
        left: auto !important;
        z-index: auto !important;
        background-color: transparent !important; /* biar tidak solid */
    }
    /* Lebar kolom otomatis sesuai konten */
    .sticky-table th.sticky-col-no,
    .sticky-table td.sticky-col-no,
    .sticky-table th.sticky-col-nisn,
    .sticky-table td.sticky-col-nisn,
    .sticky-table th.sticky-col-nama,
    .sticky-table td.sticky-col-nama {
        min-width: auto !important;
        width: auto !important;
        white-space: nowrap;
    }
    /* Ukuran badge lebih kecil */
    .badge-status {
        min-width: 35px;
        font-size: 0.6rem;
        padding: 0.1rem 0.2rem;
    }
    /* Perbaiki wrapper agar scroll horizontal smooth */
    .table-wrapper {
        -webkit-overflow-scrolling: touch;
        overflow-x: auto;
    }
}
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar_user.php'; ?>

    <div class="main-content-container flex-1 transition-all duration-300 ease-in-out">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openMobileSidebarBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Laporan Kehadiran</h1>
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
                            <span class="hidden md:inline text-gray-700 dark:text-gray-200"><?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></span>
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
            <?php if (!empty($error_message)): ?>
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-4 mb-6 text-red-700 dark:text-red-400">
                    <i class="fas fa-exclamation-circle mr-2"></i> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($classes) || !empty($error_message) === false): ?>
            <!-- Form Filter -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 mb-6 filter-form">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4 items-end">
                    <div class="form-group">
                        <label class="block text-gray-700 dark:text-gray-300 mb-1 text-sm">Tipe Kelas</label>
                        <select name="class_type" class="border rounded px-3 py-2 dark:bg-gray-700 dark:text-white w-full text-sm">
                            <option value="pagi" <?= $selected_class_type == 'pagi' ? 'selected' : '' ?>>Kelas Pagi</option>
                            <option value="diniyyah" <?= $selected_class_type == 'diniyyah' ? 'selected' : '' ?>>Kelas Diniyyah</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="block text-gray-700 dark:text-gray-300 mb-1 text-sm">Kelas</label>
                        <select name="class_id" class="border rounded px-3 py-2 dark:bg-gray-700 dark:text-white w-full text-sm">
                            <option value="0">-- Semua Kelas --</option>
                            <?php foreach ($classes as $c): 
                                // Hanya tampilkan kelas yang sesuai dengan tipe yang dipilih
                                if ($c['type'] == $selected_class_type):
                            ?>
                                <option value="<?= $c['id'] ?>" <?= $selected_class_id == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['class_name']) ?></option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="block text-gray-700 dark:text-gray-300 mb-1 text-sm">Kegiatan</label>
                        <select name="activity_id" class="border rounded px-3 py-2 dark:bg-gray-700 dark:text-white w-full text-sm">
                            <option value="0">-- Semua Kegiatan --</option>
                            <?php foreach ($activities as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= $selected_activity_id == $a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="block text-gray-700 dark:text-gray-300 mb-1 text-sm">Tgl Mulai</label>
                        <input type="date" name="start_date" value="<?= $start_date ?>" class="border rounded px-3 py-2 dark:bg-gray-700 dark:text-white w-full text-sm">
                    </div>
                    <div class="form-group">
                        <label class="block text-gray-700 dark:text-gray-300 mb-1 text-sm">Tgl Berakhir</label>
                        <input type="date" name="end_date" value="<?= $end_date ?>" class="border rounded px-3 py-2 dark:bg-gray-700 dark:text-white w-full text-sm">
                    </div>
                    <div class="form-group">
                        <label class="block text-gray-700 dark:text-gray-300 mb-1 text-sm">Cari Nama/NISN</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Nama atau NISN..." class="border rounded px-3 py-2 dark:bg-gray-700 dark:text-white w-full text-sm">
                    </div>
                    <div class="flex flex-wrap gap-2 col-span-full sm:col-span-2 lg:col-span-6">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm"><i class="fas fa-search mr-1"></i> Tampilkan</button>
                        <?php if (!empty($table_data)): ?>
                        <a href="?class_type=<?= $selected_class_type ?>&class_id=<?= $selected_class_id ?>&activity_id=<?= $selected_activity_id ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&export=csv" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm inline-flex items-center"><i class="fas fa-download mr-1"></i> Ekspor CSV</a>
                        <?php endif; ?>
                        <?php if ($search): ?>
                        <a href="?class_type=<?= $selected_class_type ?>&class_id=<?= $selected_class_id ?>&activity_id=<?= $selected_activity_id ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm">Reset Filter</a>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if (!empty($warning_date)): ?>
                    <div class="mt-3 text-yellow-600 text-sm"><?= htmlspecialchars($warning_date) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Tabel Kehadiran -->
            <?php if (!empty($table_data)): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">
                    Laporan Kehadiran Harian
                    <?php if ($selected_class_id > 0): ?>
                        - <?= htmlspecialchars(array_column($classes, 'class_name', 'id')[$selected_class_id] ?? 'Kelas') ?>
                    <?php else: ?>
                        - Semua Kelas (<?= $selected_class_type == 'pagi' ? 'Pagi' : 'Diniyyah' ?>)
                    <?php endif; ?>
                    <?php if ($selected_activity_id > 0): ?>
                        - Kegiatan: <?= htmlspecialchars(array_column($activities, 'name', 'id')[$selected_activity_id] ?? '') ?>
                    <?php endif; ?>
                    <br><span class="text-sm font-normal">Periode: <?= date('d/m/Y', strtotime($start_date)) ?> s.d. <?= date('d/m/Y', strtotime($end_date)) ?></span>
                </h2>
                
                <div class="table-wrapper">
                    <table class="sticky-table min-w-full divide-y divide-gray-200 dark:divide-gray-700 dark:text-white text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-2 py-3 text-center text-xs font-medium uppercase sticky-col-no" style="z-index: 25;">No</th>
                                <th class="px-3 py-3 text-left text-xs font-medium uppercase sticky-col-nisn" style="z-index: 25;">NISN</th>
                                <th class="px-3 py-3 text-left text-xs font-medium uppercase sticky-col-nama" style="z-index: 25;">Nama</th>
                                <?php foreach ($date_range as $date): ?>
                                    <th class="px-2 py-3 text-center text-xs font-medium uppercase min-w-[70px]">
                                        <?= date('d/m', strtotime($date)) ?>
                                    </th>
                                <?php endforeach; ?>
                                <th class="px-3 py-3 text-center text-xs font-medium uppercase sticky-col-right" style="background-color: #f3f4f6; z-index: 20;">Hadir</th>
                                <th class="px-3 py-3 text-center text-xs font-medium uppercase sticky-col-right" style="background-color: #f3f4f6; z-index: 20;">Sakit</th>
                                <th class="px-3 py-3 text-center text-xs font-medium uppercase sticky-col-right" style="background-color: #f3f4f6; z-index: 20;">Izin</th>
                                <th class="px-3 py-3 text-center text-xs font-medium uppercase sticky-col-right" style="background-color: #f3f4f6; z-index: 20;">Alpha</th>
                                <th class="px-3 py-3 text-center text-xs font-medium uppercase sticky-col-right" style="background-color: #f3f4f6; z-index: 20;">Terlambat</th>
                                <th class="px-3 py-3 text-center text-xs font-medium uppercase sticky-col-right" style="background-color: #e5e7eb; z-index: 20;">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($table_data as $row): ?>
                                <tr class="hover:bg-gray-200 dark:hover:bg-gray-700">
                                    <td class="px-2 py-2 text-center whitespace-nowrap sticky-col-no bg-white dark:bg-gray-800 shadow-sm"><?= $row['no'] ?></td>
                                    <td class="px-3 py-2 whitespace-nowrap sticky-col-nisn bg-white dark:bg-gray-800 shadow-sm"><?= htmlspecialchars($row['nisn']) ?></td>
                                    <td class="px-3 py-2 font-medium whitespace-nowrap sticky-col-nama bg-white dark:bg-gray-800 shadow-sm"><?= htmlspecialchars($row['nama']) ?></td>
                                    <?php foreach ($date_range as $date): ?>
                                        <td class="px-2 py-2 text-center whitespace-nowrap">
                                            <?php 
                                                $status = $row['daily'][$date];
                                                $badge_class = match($status) {
                                                    'Hadir' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                                                    'Sakit' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
                                                    'Izin' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
                                                    'Alpha' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                                    'Terlambat' => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
                                                    default => 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'
                                                };
                                            ?>
                                            <span class="px-2 py-1 rounded-full text-xs font-medium <?= $badge_class ?> badge-status">
                                                <?= $status ?>
                                            </span>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="px-3 py-2 text-center font-semibold sticky-col-right bg-gray-50 dark:bg-gray-700 shadow-sm"><?= $row['hadir'] ?></td>
                                    <td class="px-3 py-2 text-center sticky-col-right bg-gray-50 dark:bg-gray-700 shadow-sm"><?= $row['sakit'] ?></td>
                                    <td class="px-3 py-2 text-center sticky-col-right bg-gray-50 dark:bg-gray-700 shadow-sm"><?= $row['izin'] ?></td>
                                    <td class="px-3 py-2 text-center sticky-col-right bg-gray-50 dark:bg-gray-700 shadow-sm"><?= $row['alpha'] ?></td>
                                    <td class="px-3 py-2 text-center sticky-col-right bg-gray-50 dark:bg-gray-700 shadow-sm"><?= $row['terlambat'] ?></td>
                                    <td class="px-3 py-2 text-center font-bold sticky-col-right bg-gray-100 dark:bg-gray-600 shadow-sm"><?= $row['total'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-4 text-xs text-gray-500 dark:text-gray-400 flex flex-wrap gap-2">
                    <i class="fas fa-info-circle mr-1"></i> Keterangan:
                    <span class="inline-block px-2 py-1 rounded-full text-xs bg-green-100 text-green-800">Hadir</span>
                    <span class="inline-block px-2 py-1 rounded-full text-xs bg-yellow-100 text-yellow-800">Sakit</span>
                    <span class="inline-block px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">Izin</span>
                    <span class="inline-block px-2 py-1 rounded-full text-xs bg-red-100 text-red-800">Alpha</span>
                    <span class="inline-block px-2 py-1 rounded-full text-xs bg-orange-100 text-orange-800">Terlambat</span>
                    <span class="inline-block px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-500">- (Tidak ada data)</span>
                </div>
            </div>
            <?php elseif (empty($error_message) && !empty($classes)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 text-center text-gray-500 dark:text-gray-400">
                    <i class="fas fa-inbox text-4xl mb-2"></i>
                    <p>Tidak ada data kehadiran untuk periode dan filter yang dipilih.</p>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<script>
// Dark mode toggle
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
</script>

<?php require_once __DIR__ . '/includes/footer_user.php'; ?>