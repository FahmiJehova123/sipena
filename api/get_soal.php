<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
session_start();

// Hanya admin yang boleh mengakses
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$id = $_GET['id'] ?? '';
$source = $_GET['source'] ?? 'admin'; // 'admin' atau 'user'

if (empty($id)) {
    echo json_encode(['error' => 'ID tidak ditemukan']);
    exit;
}

$dataDir = __DIR__ . '/../data/';
$adminFile = $dataDir . 'soal.json';
$userFile = $dataDir . 'user_soal.json';

$result = null;

if ($source === 'admin') {
    if (file_exists($adminFile)) {
        $json = file_get_contents($adminFile);
        $soal = json_decode($json, true);
        if (is_array($soal)) {
            foreach ($soal as $item) {
                if (isset($item['id']) && $item['id'] == $id) {
                    $result = $item;
                    break;
                }
            }
        }
    }
} elseif ($source === 'user') {
    if (file_exists($userFile)) {
        $json = file_get_contents($userFile);
        $soal = json_decode($json, true);
        if (is_array($soal)) {
            foreach ($soal as $item) {
                if (isset($item['id']) && $item['id'] == $id) {
                    $result = $item;
                    // Ambil nama user jika ada user_id
                    if (!empty($result['user_id'])) {
                        $user = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $result['user_id']]);
                        if (is_array($user) && !empty($user)) {
                            $result['user_name'] = $user[0]['full_name'] ?? 'Unknown';
                        } else {
                            $result['user_name'] = 'Unknown';
                        }
                    }
                    break;
                }
            }
        }
    }
}

if ($result) {
    echo json_encode(['success' => true, 'data' => $result]);
} else {
    echo json_encode(['success' => false, 'message' => 'Soal tidak ditemukan']);
}
?>