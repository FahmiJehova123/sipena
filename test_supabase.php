<?php
session_start();
require_once 'config.php';
$userRole = $_SESSION['user_role'] ?? 'guest';
$userId = $_SESSION['user_id'] ?? null;
echo "Role: $userRole, ID: $userId<br>";
$classes = supabase_admin_request('GET', 'classes', null, ['homeroom_teacher_id' => 'eq.' . $userId]);
echo '<pre>'; print_r($classes); echo '</pre>';