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

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'teacher', 'student'])) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); 
    exit; 
}

$input = json_decode(file_get_contents('php://input'), true);
$announcement_id = trim($input['announcement_id'] ?? '');
$csrf = $input['csrf_token'] ?? '';

if ($csrf !== $_SESSION['csrf_token']) { 
    echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']); 
    exit; 
}

if (empty($announcement_id)) {
    echo json_encode(['success'=>false,'message'=>'Announcement ID is required']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Cek apakah sudah pernah dibaca
$exists = supabase_admin_request('GET', 'announcement_reads', null, [
    'announcement_id' => "eq.$announcement_id",
    'user_id' => "eq.$user_id"
]);

if (empty($exists)) {
    $result = supabase_admin_request('POST', 'announcement_reads', [
        'announcement_id' => $announcement_id,
        'user_id' => $user_id,
        'read_at' => date('Y-m-d H:i:s')
    ]);
    
    // Cek apakah berhasil disimpan (biasanya response memiliki 'id' jika sukses)
    if (isset($result['id']) || (is_array($result) && !isset($result['error']))) {
        echo json_encode(['success'=>true]);
    } else {
        $errorMsg = is_array($result) && isset($result['message']) ? $result['message'] : 'Gagal menyimpan data';
        echo json_encode(['success'=>false,'message'=>$errorMsg]);
    }
} else {
    // Sudah ada, tetap sukses
    echo json_encode(['success'=>true]);
}
?>