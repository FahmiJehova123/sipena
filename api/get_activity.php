<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
$id = $_GET['id'] ?? '';
if (empty($id)) {
    echo json_encode([]);
    exit;
}
$result = supabase_admin_request('GET', 'activities', null, ['id' => 'eq.' . $id]);
if (is_array($result) && !empty($result)) {
    echo json_encode($result[0]);
} else {
    echo json_encode([]);
}
?>