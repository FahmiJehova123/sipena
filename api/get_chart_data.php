<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$period = $_GET['period'] ?? 'month';
$labels = [];
$data = [];

if ($period == 'month') {
    // Hitung kehadiran per minggu dalam bulan ini
    $year = date('Y');
    $month = date('m');
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
    
    // Ambil semua log kehadiran dengan status 'Hadir' dalam bulan ini
    $logs = supabase_admin_request('GET', 'attendance_logs', null, [
        'scan_time' => 'gte.' . $start_date . 'T00:00:00',
        'scan_time' => 'lte.' . $end_date . 'T23:59:59',
        'status' => 'eq.Hadir'
    ]);
    
    if (!is_array($logs)) $logs = [];
    
    // Kelompokkan per minggu (nomor minggu)
    $weeks = [];
    foreach ($logs as $log) {
        $week = date('W', strtotime($log['scan_time']));
        if (!isset($weeks[$week])) $weeks[$week] = 0;
        $weeks[$week]++;
    }
    
    // Urutkan minggu
    ksort($weeks);
    $labels = array_keys($weeks);
    $data = array_values($weeks);
    
    // Jika tidak ada data, beri default 4 minggu dengan nilai 0
    if (empty($labels)) {
        $labels = ['Minggu 1', 'Minggu 2', 'Minggu 3', 'Minggu 4'];
        $data = [0, 0, 0, 0];
    } else {
        // Ubah label minggu menjadi format yang lebih rapi
        $new_labels = [];
        foreach ($labels as $i => $week) {
            $new_labels[] = 'Minggu ' . ($i+1);
        }
        $labels = $new_labels;
    }
} else {
    // Minggu ini: kehadiran per hari (Senin - Jumat)
    $today = new DateTime();
    $start_of_week = clone $today;
    $start_of_week->modify('monday this week');
    $end_of_week = clone $start_of_week;
    $end_of_week->modify('friday this week');
    
    $logs = supabase_admin_request('GET', 'attendance_logs', null, [
        'scan_time' => 'gte.' . $start_of_week->format('Y-m-d') . 'T00:00:00',
        'scan_time' => 'lte.' . $end_of_week->format('Y-m-d') . 'T23:59:59',
        'status' => 'eq.Hadir'
    ]);
    
    if (!is_array($logs)) $logs = [];
    
    $days = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum'];
    $daily_counts = array_fill_keys($days, 0);
    
    foreach ($logs as $log) {
        $day_name = date('D', strtotime($log['scan_time']));
        $map = ['Mon' => 'Sen', 'Tue' => 'Sel', 'Wed' => 'Rab', 'Thu' => 'Kam', 'Fri' => 'Jum'];
        if (isset($map[$day_name])) {
            $daily_counts[$map[$day_name]]++;
        }
    }
    $labels = $days;
    $data = array_values($daily_counts);
}

echo json_encode(['labels' => $labels, 'data' => $data]);
?>