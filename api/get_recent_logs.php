<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

// Ambil 10 log terbaru (descending)
$logs = supabase_admin_request('GET', 'attendance_logs', null, [
    'order' => 'scan_time.desc',
    'limit' => 10
]);

$result = [];
if (is_array($logs)) {
    foreach ($logs as $log) {
        // Ambil data user terkait
        $user = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $log['user_id']]);
        $full_name = (is_array($user) && !empty($user)) ? $user[0]['full_name'] : 'Unknown';
        $role = (is_array($user) && !empty($user)) ? $user[0]['role'] : 'Murid';
        $result[] = [
            'scan_time' => $log['scan_time'],
            'user_name' => $full_name,
            'role' => $role,
            'status' => $log['status']
        ];
    }
}
echo json_encode($result);
?>