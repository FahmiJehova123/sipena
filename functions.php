<?php
// functions.php
require_once 'config.php';
require_once 'vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

function safeArray($data) {
    if (!is_array($data)) return [];
    return array_filter($data, 'is_array');
}

if (!function_exists('supabase_admin_request')) {
    function supabase_admin_request($method, $table, $data = null, $params = []) {
        $url = SUPABASE_URL . '/rest/v1/' . $table;
        if (!empty($params)) $url .= '?' . http_build_query($params);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data && ($method == 'POST' || $method == 'PATCH')) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true);
    }
}

function generate_qr_code($user_id) {
    $qr_data = $user_id;
    $qr_url = "https://api.qrmint.com/v1/qr?text=" . urlencode($qr_data) . "&format=svg";
    $ch = curl_init($qr_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $svg_content = curl_exec($ch);
    curl_close($ch);
    if (!$svg_content) return false;
    return 'data:image/svg+xml;base64,' . base64_encode($svg_content);
}

function verify_qr_code($qr_data) {
    $user_id = $qr_data;
    $user = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $user_id]);
    if (!empty($user)) return ['valid' => true, 'user_id' => $user_id];
    return ['valid' => false];
}

function get_user_name($user_id) {
    $result = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $user_id]);
    if (!empty($result)) return $result[0]['full_name'];
    return 'Unknown';
}

function get_schedule_info($schedule_id) {
    $result = supabase_admin_request('GET', 'schedules', null, [
        'select' => '*, subjects(subject_name), classes(class_name)',
        'id' => 'eq.' . $schedule_id
    ]);
    if (!empty($result)) return $result[0];
    return null;
}


?>