<?php
require_once 'functions.php';
$users = supabase_admin_request('GET', 'users', null, ['role' => 'eq.student', 'limit' => 1]);
if (empty($users)) die('No student');
$user_id = $users[0]['id'];
$result = generate_qr_code($user_id);
var_dump($result);
?>