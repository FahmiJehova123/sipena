<?php
session_start();
header('Content-Type: application/json');
require_once '../config.php';
require_once '../functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'teacher') {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}
$input = json_decode(file_get_contents('php://input'), true);
$announcement_id = $input['announcement_id'] ?? '';
$action = $input['action'] ?? 'mark'; // mark atau unmark
$csrf = $input['csrf_token'] ?? '';
if ($csrf !== $_SESSION['csrf_token']) {
    echo json_encode(['success'=>false,'message'=>'Invalid CSRF']); exit;
}
$user_id = $_SESSION['user_id'];

if ($action === 'mark') {
    // Cek sudah ada belum
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
        echo json_encode(['success' => isset($result['id'])]);
    } else {
        echo json_encode(['success' => true]);
    }
} else { // unmark
    $result = supabase_admin_request('DELETE', 'announcement_reads', null, [
        'announcement_id' => "eq.$announcement_id",
        'user_id' => "eq.$user_id"
    ]);
    echo json_encode(['success' => true]);
}
?>