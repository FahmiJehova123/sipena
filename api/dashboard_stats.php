<?php
// api/dashboard_stats.php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Set zona waktu untuk perhitungan (Indonesia)
date_default_timezone_set('Asia/Jakarta');

// Status yang dianggap hadir
$hadirStatuses = ['Hadir', 'Terlambat'];

/**
 * Mengubah tanggal lokal (Asia/Jakarta) menjadi rentang UTC untuk query Supabase.
 * Contoh: '2026-06-20' -> start: '2026-06-19T17:00:00Z', end: '2026-06-20T16:59:59Z'
 */
function getDayRangeUtc($dateStr) {
    $start = new DateTime($dateStr . ' 00:00:00', new DateTimeZone('Asia/Jakarta'));
    $end   = new DateTime($dateStr . ' 23:59:59', new DateTimeZone('Asia/Jakarta'));
    $start->setTimezone(new DateTimeZone('UTC'));
    $end->setTimezone(new DateTimeZone('UTC'));
    return [
        'start' => $start->format('Y-m-d\TH:i:s\Z'),
        'end'   => $end->format('Y-m-d\TH:i:s\Z')
    ];
}

// ---- 1. Total hadir hari ini (inkl. Terlambat) ----
$today = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
$todayDate = $today->format('Y-m-d');
$rangeToday = getDayRangeUtc($todayDate);

$hadir_raw = supabase_admin_request('GET', 'attendance_logs', null, [
    'status'    => 'in.(' . implode(',', $hadirStatuses) . ')',
    'scan_time' => 'gte.' . $rangeToday['start'],
    'scan_time' => 'lte.' . $rangeToday['end']
]);
$total_hadir_hari_ini = is_array($hadir_raw) ? count($hadir_raw) : 0;

// ---- 2. Total guru & murid ----
$guru_raw = supabase_admin_request('GET', 'users', null, [
    'role'  => 'eq.teacher',
    'limit' => 9999
]);
$total_guru = is_array($guru_raw) ? count($guru_raw) : 0;

$murid_raw = supabase_admin_request('GET', 'users', null, [
    'role'  => 'eq.student',
    'limit' => 9999
]);
$total_murid = is_array($murid_raw) ? count($murid_raw) : 0;

// ---- 3. Rata-rata kehadiran bulan ini (perkiraan) ----
$firstDay = new DateTime('first day of this month', new DateTimeZone('Asia/Jakarta'));
$lastDay  = new DateTime('last day of this month', new DateTimeZone('Asia/Jakarta'));
$rangeBulanStart = getDayRangeUtc($firstDay->format('Y-m-d'));
$rangeBulanEnd   = getDayRangeUtc($lastDay->format('Y-m-d'));

$hadir_bulan_raw = supabase_admin_request('GET', 'attendance_logs', null, [
    'status'    => 'in.(' . implode(',', $hadirStatuses) . ')',
    'scan_time' => 'gte.' . $rangeBulanStart['start'],
    'scan_time' => 'lte.' . $rangeBulanEnd['end']
]);
$total_hadir_bulan = is_array($hadir_bulan_raw) ? count($hadir_bulan_raw) : 0;
$hari_sekolah = 20; // asumsi, bisa disesuaikan
$max_kehadiran = $total_murid * $hari_sekolah;
$avg_kehadiran = ($max_kehadiran > 0) ? round(($total_hadir_bulan / $max_kehadiran) * 100) : 0;

// ---- 4. Trend hadir (kemarin) ----
$kemarin = (clone $today)->modify('-1 day');
$kemarinDate = $kemarin->format('Y-m-d');
$rangeKemarin = getDayRangeUtc($kemarinDate);

$hadir_kemarin_raw = supabase_admin_request('GET', 'attendance_logs', null, [
    'status'    => 'in.(' . implode(',', $hadirStatuses) . ')',
    'scan_time' => 'gte.' . $rangeKemarin['start'],
    'scan_time' => 'lte.' . $rangeKemarin['end']
]);
$total_hadir_kemarin = is_array($hadir_kemarin_raw) ? count($hadir_kemarin_raw) : 0;

if ($total_hadir_kemarin > 0) {
    $trend = round((($total_hadir_hari_ini - $total_hadir_kemarin) / $total_hadir_kemarin) * 100);
    $trend_hadir = ($trend >= 0) ? "+$trend%" : "$trend%";
} else {
    $trend_hadir = ($total_hadir_hari_ini > 0) ? "+100%" : "0%";
}

// ---- 5. Data grafik kehadiran per hari dalam bulan ini ----
$daysInMonth = (int) $lastDay->format('d');
$month = $firstDay->format('m');
$year  = $firstDay->format('Y');

// Inisialisasi array per tanggal
$chart_labels = [];
$hadir_per_day = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $chart_labels[] = $date;
    $hadir_per_day[$date] = 0;
}

// Ambil semua log bulan ini sekaligus (sudah difilter status hadir)
$allLogsBulan = supabase_admin_request('GET', 'attendance_logs', null, [
    'status'    => 'in.(' . implode(',', $hadirStatuses) . ')',
    'scan_time' => 'gte.' . $rangeBulanStart['start'],
    'scan_time' => 'lte.' . $rangeBulanEnd['end']
]);

if (is_array($allLogsBulan)) {
    foreach ($allLogsBulan as $log) {
        // Konversi scan_time (UTC) ke waktu lokal (Asia/Jakarta)
        // Asumsi: scan_time adalah string dengan zona '+00' (UTC)
        $dt = new DateTime($log['scan_time']); // otomatis baca zona dari string
        $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
        $logDate = $dt->format('Y-m-d');
        
        // Jika data Anda sudah dalam WIB, hilangkan baris setTimezone() di atas
        // dan langsung gunakan $logDate = date('Y-m-d', strtotime($log['scan_time']));
        
        if (isset($hadir_per_day[$logDate])) {
            $hadir_per_day[$logDate]++;
        }
    }
}
// Susun chart_data sesuai urutan label
$chart_data = [];
foreach ($chart_labels as $label) {
    $chart_data[] = $hadir_per_day[$label];
}

// ---- 6. Data jumlah kelas per tingkat ----
$classes_raw = supabase_admin_request('GET', 'classes');
$classes = is_array($classes_raw) ? $classes_raw : [];
$class_levels = [];
foreach ($classes as $c) {
    $level = $c['grade_level'];
    if (!isset($class_levels[$level])) $class_levels[$level] = 0;
    $class_levels[$level]++;
}
ksort($class_levels);

// ---- 7. Log absensi terbaru (10 data) dengan nama user ----
$recent_logs_raw = supabase_admin_request('GET', 'attendance_logs', null, ['order' => 'scan_time.desc', 'limit' => 10]);
$recent_logs = [];
if (is_array($recent_logs_raw)) {
    foreach ($recent_logs_raw as $log) {
        $user_raw = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $log['user_id']]);
        $full_name = (is_array($user_raw) && !empty($user_raw)) ? $user_raw[0]['full_name'] : 'Unknown';
        $role = (is_array($user_raw) && !empty($user_raw)) ? $user_raw[0]['role'] : 'Murid';
        $recent_logs[] = [
            'scan_time' => $log['scan_time'],
            'user_name' => $full_name,
            'role'      => $role,
            'status'    => $log['status']
        ];
    }
}

// ---- Kirim semua data sebagai JSON ----
echo json_encode([
    'total_hadir_hari_ini' => $total_hadir_hari_ini,
    'total_guru'           => $total_guru,
    'total_murid'          => $total_murid,
    'avg_kehadiran'        => $avg_kehadiran,
    'trend_hadir'          => $trend_hadir,
    'chart_labels'         => $chart_labels, // array tanggal YYYY-MM-DD
    'chart_data'           => $chart_data,
    'kelas_labels'         => array_keys($class_levels),
    'kelas_counts'         => array_values($class_levels),
    'recent_logs'          => $recent_logs
]);