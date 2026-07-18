<?php
session_start();
header('Content-Type: application/json');
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true);
if ($input['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']);
    exit;
}
$data = [
    'title' => $input['title'],
    'content' => $input['content'],
    'target_role' => $input['target_role'],
    'is_active' => $input['is_active'],
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s')
];
$result = supabase_admin_request('POST', 'announcements', $data);
echo json_encode(['success' => isset($result['id'])]);
?>