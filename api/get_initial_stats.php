<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

// Helper function untuk menghitung jumlah response (karena bisa null)
function countResult($data) {
    return is_array($data) ? count($data) : 0;
}

// === Total Guru ===
$guruRaw = supabase_admin_request('GET', 'users', null, ['role' => 'eq.teacher']);
$totalGuru = countResult($guruRaw);

// === Total Murid ===
$muridRaw = supabase_admin_request('GET', 'users', null, ['role' => 'eq.student']);
$totalMurid = countResult($muridRaw);

// === Total Hadir Hari Ini ===
$todayStart = date('Y-m-d') . 'T00:00:00';
$todayEnd = date('Y-m-d') . 'T23:59:59';
$hadirTodayRaw = supabase_admin_request('GET', 'attendance_logs', null, [
    'status' => 'eq.Hadir',
    'scan_time' => 'gte.' . $todayStart,
    'scan_time' => 'lte.' . $todayEnd
]);
$totalHadirHariIni = countResult($hadirTodayRaw);

// === Total Hadir Kemarin (untuk trend) ===
$yesterdayStart = date('Y-m-d', strtotime('-1 day')) . 'T00:00:00';
$yesterdayEnd = date('Y-m-d', strtotime('-1 day')) . 'T23:59:59';
$hadirYesterdayRaw = supabase_admin_request('GET', 'attendance_logs', null, [
    'status' => 'eq.Hadir',
    'scan_time' => 'gte.' . $yesterdayStart,
    'scan_time' => 'lte.' . $yesterdayEnd
]);
$totalHadirKemarin = countResult($hadirYesterdayRaw);

// Hitung trend
if ($totalHadirKemarin > 0) {
    $trend = round((($totalHadirHariIni - $totalHadirKemarin) / $totalHadirKemarin) * 100);
    $trendHadir = ($trend >= 0) ? "+$trend%" : "$trend%";
} else {
    $trendHadir = ($totalHadirHariIni > 0) ? "+100%" : "0%";
}

// === Rata-rata Kehadiran Bulan Ini ===
$bulanStart = date('Y-m-01') . 'T00:00:00';
$bulanEnd = date('Y-m-t') . 'T23:59:59';
$hadirBulanRaw = supabase_admin_request('GET', 'attendance_logs', null, [
    'status' => 'eq.Hadir',
    'scan_time' => 'gte.' . $bulanStart,
    'scan_time' => 'lte.' . $bulanEnd
]);
$totalHadirBulan = countResult($hadirBulanRaw);

// Total siswa
$siswaRaw = supabase_admin_request('GET', 'users', null, ['role' => 'eq.student']);
$totalSiswa = countResult($siswaRaw);

// Hitung jumlah hari sekolah (Senin-Jumat) dalam bulan ini
$startDate = new DateTime(date('Y-m-01'));
$endDate = new DateTime(date('Y-m-t'));
$endDate->modify('+1 day'); // include end date
$interval = new DateInterval('P1D');
$period = new DatePeriod($startDate, $interval, $endDate);
$hariSekolah = 0;
foreach ($period as $day) {
    $dayOfWeek = (int)$day->format('N'); // 1=Senin, 7=Minggu
    if ($dayOfWeek >= 1 && $dayOfWeek <= 5) {
        $hariSekolah++;
    }
}

// Hitung rata-rata kehadiran
if ($totalSiswa > 0 && $hariSekolah > 0) {
    $maxKehadiran = $totalSiswa * $hariSekolah;
    $avgKehadiran = round(($totalHadirBulan / $maxKehadiran) * 100);
    if ($avgKehadiran > 100) $avgKehadiran = 100;
} else {
    $avgKehadiran = 0;
}

// === Output JSON ===
echo json_encode([
    'total_guru' => $totalGuru,
    'total_murid' => $totalMurid,
    'total_hadir_hari_ini' => $totalHadirHariIni,
    'avg_kehadiran' => $avgKehadiran,
    'trend_hadir' => $trendHadir
]);
?>