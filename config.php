<?php
// config.php
define('SUPABASE_URL', 'https://mksnmsxorljdqubuywrg.supabase.co');
date_default_timezone_set('Asia/Jakarta');

// GANTI DENGAN ANON KEY ASLI (dari dashboard Supabase -> Settings -> API -> anon public)
define('SUPABASE_ANON_KEY', '');

// SERVICE ROLE KEY (RAHASIA, hanya untuk backend)
define('SUPABASE_SERVICE_KEY', '');

// ========== FUNGSI REQUEST DENGAN ANON KEY (untuk frontend / realtime) ==========
function supabase_request($method, $table, $data = null, $params = []) {
    $url = SUPABASE_URL . '/rest/v1/' . $table;
    if (!empty($params)) $url .= '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Authorization: Bearer ' . SUPABASE_ANON_KEY,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($data && ($method == 'POST' || $method == 'PATCH')) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $http_code, 'data' => json_decode($response, true)];
}

// ========== FUNGSI REQUEST DENGAN SERVICE KEY (bypass RLS, untuk backend admin) ==========
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Jika respons kosong tapi HTTP 2xx (sukses), anggap sukses
    if (empty($response) && $http_code >= 200 && $http_code < 300) {
        return []; // array kosong menandakan sukses tanpa data
    }
    if (empty($response)) {
        return null; // error atau tidak ada data
    }
    $decoded = json_decode($response, true);
    return $decoded ?: null;
}

// ========== FUNGSI HELPER (KOMPATIBILITAS DENGAN KODE LAMA) ==========
function supabase_select($table, $columns = '*', $filters = []) {
    $params = ['select' => $columns];
    foreach ($filters as $key => $value) $params[$key] = $value;
    return supabase_request('GET', $table, null, $params);
}
function supabase_insert($table, $data) {
    return supabase_request('POST', $table, $data);
}
function supabase_update($table, $data, $id_field, $id_value) {
    return supabase_request('PATCH', $table . '?' . $id_field . '=eq.' . $id_value, $data);
}


// ========== FUNGSI UNTUK BCRYPT (autentikasi) ==========
function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

// ========== FUNGSI VALIDASI INPUT ==========
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// ========== FUNGSI UNTUK PAGINATION ==========
function paginate($total_items, $current_page, $per_page = 10, $url_pattern = '?page=%d') {
    // akan diimplementasikan nanti
}

// Konfigurasi asset yang akan dimuat
// config.php (bagian asset)
$allowed_domains = [
    'cdn.tailwindcss.com',
    'cdn.jsdelivr.net',
    'unpkg.com',
    'cdnjs.cloudflare.com',
    'fonts.googleapis.com',
    'fonts.gstatic.com',
    'mdrspondokmojosari.ct.ws' // jika ada asset lokal, ganti dengan domain Anda
];

$assets = [
    'css' => [
        'https://cdn.tailwindcss.com',
        'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css',
        '/siakad/assets/css/toast_style.css',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css',
        'https://unpkg.com/aos@2.3.1/dist/aos.css'
    ],
    'js' => [
        'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js',
        'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/id.js',
        '/siakad/assets/js/toast_notif.js',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
        'https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2'
    ]
];
?>
