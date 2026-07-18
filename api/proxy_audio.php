<?php
// proxy_audio.php – Proxy untuk Google Drive dengan dukungan Range & Content-Length

// Izinkan semua origin (CORS)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, Content-Type');

// Tangani preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Pastikan ada fileId
if (!isset($_GET['fileId'])) {
    http_response_code(400);
    echo 'Missing fileId parameter.';
    exit;
}
$fileId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['fileId']);

// API Key
$apiKey = 'AIzaSyBpFoZODnobATJR49yUHJWtys06ZIf9lGc';

// Cek apakah request memiliki header Range (untuk seeking)
$rangeHeader = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null;

// Build URL Google Drive
$url = "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media&key={$apiKey}";

// Inisialisasi cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_HEADER, true); // ambil header dan body

// Jika ada Range request, teruskan ke Google
if ($rangeHeader) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Range: ' . $rangeHeader]);
}

$response = curl_exec($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Pisahkan header dan body
$headers = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);

// Jika Google gagal
if ($httpCode !== 200 && $httpCode !== 206) { // 206 = Partial Content (untuk Range)
    http_response_code($httpCode);
    echo "Error fetching file: HTTP {$httpCode}";
    exit;
}

// Parse header Google untuk mendapatkan Content-Type dan Content-Length
$contentType = '';
$contentLength = 0;
$contentRange = '';
$lines = explode("\r\n", $headers);
foreach ($lines as $line) {
    if (stripos($line, 'Content-Type:') === 0) {
        $contentType = trim(substr($line, 13));
    }
    if (stripos($line, 'Content-Length:') === 0) {
        $contentLength = (int) trim(substr($line, 15));
    }
    if (stripos($line, 'Content-Range:') === 0) {
        $contentRange = trim(substr($line, 14));
    }
}

// Kirim header ke browser
if ($contentType) {
    header("Content-Type: {$contentType}");
}
if ($contentLength > 0) {
    header("Content-Length: {$contentLength}");
}
header('Accept-Ranges: bytes');

// Jika ada Range response dari Google, kirim status 206 dan header Content-Range
if ($httpCode === 206 && $contentRange) {
    http_response_code(206);
    header("Content-Range: {$contentRange}");
} else {
    http_response_code(200);
}

// Kirim body audio
echo $body;