<?php
set_time_limit(120); // Perpanjang waktu eksekusi menjadi 120 detik
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
session_start();

// Izinkan admin dan teacher
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] != 'admin' && $_SESSION['user_role'] != 'teacher')) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_role = $_SESSION['user_role'];
$user_id = $_SESSION['user_id'];

// Fungsi untuk membersihkan parameter tanggal (mengambil YYYY-MM-DD)
function cleanDate($dateStr) {
    if (strpos($dateStr, 'T') !== false) {
        $dateStr = substr($dateStr, 0, strpos($dateStr, 'T'));
    }
    return substr($dateStr, 0, 10);
}

$startRaw = $_GET['start'] ?? '';
$endRaw   = $_GET['end'] ?? '';

$start = cleanDate($startRaw);
$end   = cleanDate($endRaw);

if (!$start || !$end) {
    echo json_encode([]);
    exit;
}

// Batasi rentang maksimal 90 hari
$startDate = new DateTime($start);
$endDate   = new DateTime($end);
$diffDays  = $startDate->diff($endDate)->days;
if ($diffDays > 90) {
    $endDate = (clone $startDate)->modify('+90 days');
    $end = $endDate->format('Y-m-d');
}

// Cache sederhana untuk request
$cache = [];
function cachedRequest($table, $params = []) {
    global $cache;
    $key = $table . '_' . json_encode($params);
    if (!isset($cache[$key])) {
        $result = supabase_admin_request('GET', $table, null, $params);
        $cache[$key] = is_array($result) ? $result : [];
    }
    return $cache[$key];
}

$events = [];

try {
    // ========== JADWAL MENGAJAR ==========
    // Jika admin, ambil semua jadwal. Jika teacher, hanya jadwal dengan teacher_id = user_id
    if ($user_role === 'admin') {
        $schedules = cachedRequest('schedules');
    } else {
        $schedules = cachedRequest('schedules', ['teacher_id' => 'eq.' . $user_id]);
    }
    
    $classes   = cachedRequest('classes');
    $subjects  = cachedRequest('subjects');
    
    $classMap = [];
    foreach ($classes as $c) { $classMap[$c['id']] = $c['class_name']; }
    $subjectMap = [];
    foreach ($subjects as $s) { $subjectMap[$s['id']] = $s['subject_name']; }
    
    $current = clone $startDate;
    while ($current <= $endDate) {
        $dayOfWeek = (int)$current->format('N');
        $dateStr = $current->format('Y-m-d');
        foreach ($schedules as $schedule) {
            if ($schedule['day_of_week'] == $dayOfWeek) {
                $className = $classMap[$schedule['class_id']] ?? '?';
                $subjectName = $subjectMap[$schedule['subject_id']] ?? '?';
                $startTime = $schedule['start_time'];
                $endTime   = $schedule['end_time'];
                
                $startDateTime = DateTime::createFromFormat('Y-m-d H:i:s', "$dateStr $startTime");
                $endDateTime   = DateTime::createFromFormat('Y-m-d H:i:s', "$dateStr $endTime");
                
                if ($startDateTime && $endDateTime) {
                    $events[] = [
                        'id'     => "schedule_{$schedule['id']}_{$current->format('Ymd')}",
                        'title'  => "$subjectName - $className",
                        'start'  => $startDateTime->format('Y-m-d\TH:i:s'),
                        'end'    => $endDateTime->format('Y-m-d\TH:i:s'),
                        'color'  => '#3b82f6',
                        'textColor' => '#ffffff',
                        'extendedProps' => ['type' => 'schedule']
                    ];
                }
            }
        }
        $current->modify('+1 day');
    }
    
    // ========== KEGIATAN (tetap untuk semua role) ==========
    $activities = cachedRequest('activities', ['is_active' => 'eq.true']);
    $allExceptions = cachedRequest('activity_exceptions');
    
    $exceptionsByActivity = [];
    foreach ($allExceptions as $exc) {
        $exceptionsByActivity[$exc['activity_id']][] = $exc['exception_date'];
    }
    
    $current = clone $startDate;
    while ($current <= $endDate) {
        $dateStr = $current->format('Y-m-d');
        $dayOfWeek = (int)$current->format('N');
        foreach ($activities as $act) {
            if ($act['day_of_week'] != $dayOfWeek) continue;
            if (!empty($act['start_date']) && $dateStr < $act['start_date']) continue;
            if (!empty($act['end_date'])   && $dateStr > $act['end_date']) continue;
            if (isset($exceptionsByActivity[$act['id']]) && in_array($dateStr, $exceptionsByActivity[$act['id']])) continue;
            
            $startDateTime = DateTime::createFromFormat('Y-m-d H:i:s', "$dateStr {$act['start_time']}");
            $endDateTime   = DateTime::createFromFormat('Y-m-d H:i:s', "$dateStr {$act['end_time']}");
            if ($startDateTime && $endDateTime) {
                $events[] = [
                    'id'     => "activity_{$act['id']}_{$dateStr}",
                    'title'  => $act['name'] . ' (' . $act['type'] . ')',
                    'start'  => $startDateTime->format('Y-m-d\TH:i:s'),
                    'end'    => $endDateTime->format('Y-m-d\TH:i:s'),
                    'color'  => '#f59e0b',
                    'textColor' => '#ffffff',
                    'extendedProps' => ['type' => 'activity']
                ];
            }
        }
        $current->modify('+1 day');
    }
    
    echo json_encode($events);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>