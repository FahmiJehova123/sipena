<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$id = $_GET['id'] ?? '';
if (empty($id)) {
    echo json_encode(['full_name' => 'Unknown', 'role' => '']);
    exit;
}

$result = supabase_select('users', 'full_name, role', ['id' => 'eq.' . $id]);
if ($result['code'] == 200 && !empty($result['data'])) {
    echo json_encode($result['data'][0]);
} else {
    echo json_encode(['full_name' => 'Unknown', 'role' => '']);
}
?>