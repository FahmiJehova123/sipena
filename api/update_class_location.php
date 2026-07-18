<?php
// api/update_class_location.php
// Aktifkan error reporting untuk debugging (hapus setelah selesai)
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

// Mulai session untuk cek login
session_start();

// Log untuk debugging (bisa dicek di error_log)
error_log("=== update_class_location.php dipanggil ===");
error_log("SESSION: " . print_r($_SESSION, true));

// Cek otorisasi admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    error_log("Unauthorized access attempt");
    exit;
}

// Ambil data dari request
$raw_input = file_get_contents('php://input');
error_log("Raw input: " . $raw_input);

$data = json_decode($raw_input, true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input']);
    error_log("Invalid JSON input");
    exit;
}

// Validasi data
if (!isset($data['class_id']) || !isset($data['address']) || !isset($data['latitude']) || !isset($data['longitude'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Data tidak lengkap']);
    error_log("Data tidak lengkap: " . print_r($data, true));
    exit;
}

$class_id = (int) $data['class_id'];
$address = trim($data['address']);
$lat = (float) $data['latitude'];
$lng = (float) $data['longitude'];

if ($class_id <= 0 || empty($address) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    http_response_code(400);
    echo json_encode(['error' => 'Data tidak valid']);
    error_log("Data tidak valid: class_id=$class_id, address=$address, lat=$lat, lng=$lng");
    exit;
}

error_log("Menyimpan lokasi untuk kelas ID $class_id: address=$address, lat=$lat, lng=$lng");

// Update ke Supabase
$payload = [
    'address'   => $address,
    'latitude'  => $lat,
    'longitude' => $lng
];

// Cek apakah fungsi supabase_admin_request tersedia
if (!function_exists('supabase_admin_request')) {
    http_response_code(500);
    echo json_encode(['error' => 'Fungsi supabase_admin_request tidak ditemukan']);
    error_log("Fungsi supabase_admin_request tidak ditemukan");
    exit;
}

$result = supabase_admin_request('PATCH', 'classes', $payload, ['id' => 'eq.' . $class_id]);

error_log("Hasil dari Supabase: " . print_r($result, true));

// Cek hasil
if ($result !== false && is_array($result) && isset($result[0])) {
    echo json_encode(['success' => true, 'message' => 'Lokasi berhasil diperbarui']);
    error_log("Update berhasil untuk kelas ID $class_id");
} else {
    http_response_code(500);
    $error_msg = is_array($result) ? json_encode($result) : 'Gagal menyimpan data';
    echo json_encode(['error' => $error_msg]);
    error_log("Gagal menyimpan: " . $error_msg);
}