<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$activity_id = $_GET['activity_id'] ?? 0;
if (!$activity_id) {
    echo json_encode([]);
    exit;
}

$result = supabase_admin_request('GET', 'activity_exceptions', null, ['activity_id' => 'eq.' . $activity_id]);
echo json_encode(is_array($result) ? $result : []);
?>