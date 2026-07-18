<?php
session_start();
header('Content-Type: application/json');
require_once '../config.php';
require_once '../functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 
    http_response_code(405); 
    echo json_encode(['success'=>false,'message'=>'Method not allowed']);
    exit; 
}

// Izinkan admin dan teacher
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher', 'teacher'])) { 
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); 
    exit; 
}

$input = json_decode(file_get_contents('php://input'), true);
$csrf = $input['csrf_token'] ?? '';
if ($csrf !== $_SESSION['csrf_token']) { 
    echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']); 
    exit; 
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

// Tentukan filter pengumuman berdasarkan role
if ($role == 'admin') {
    // Admin: semua pengumuman aktif (tanpa filter target_role)
    $anns = supabase_admin_request('GET', 'announcements', null, [
        'is_active' => 'eq.true'
    ]);
} else {
    // Teacher: hanya pengumuman untuk teacher atau all
    $anns = supabase_admin_request('GET', 'announcements', null, [
        'is_active' => 'eq.true',
        'target_role' => 'in.(teacher,all)'
    ]);
}

if (is_array($anns)) {
    foreach ($anns as $ann) {
        // Cek apakah sudah dibaca
        $exists = supabase_admin_request('GET', 'announcement_reads', null, [
            'announcement_id' => "eq.{$ann['id']}",
            'user_id' => "eq.$user_id"
        ]);
        if (empty($exists)) {
            supabase_admin_request('POST', 'announcement_reads', [
                'announcement_id' => $ann['id'],
                'user_id' => $user_id,
                'read_at' => date('Y-m-d H:i:s')
            ]);
        }
    }
}

echo json_encode(['success'=>true]);
?>