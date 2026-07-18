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
$id = $input['announcement_id'];
$result = supabase_admin_request('DELETE', 'announcements', null, ['id' => 'eq.' . $id]);
echo json_encode(['success' => true]);
?>