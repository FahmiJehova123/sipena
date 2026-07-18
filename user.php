<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With');

session_start();

$data_dir = __DIR__ . '/data/';
$user_soal_file = $data_dir . 'user_soal.json';
$upload_dir = __DIR__ . '/uploads/user_soal/';

if (!is_dir($data_dir)) mkdir($data_dir, 0777, true);
if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

if (!file_exists($user_soal_file)) {
    file_put_contents($user_soal_file, json_encode([], JSON_PRETTY_PRINT));
}

function getUserSoal() {
    global $user_soal_file;
    $data = json_decode(file_get_contents($user_soal_file), true);
    return is_array($data) ? $data : [];
}

function saveUserSoal($data) {
    global $user_soal_file;
    return file_put_contents($user_soal_file, json_encode($data, JSON_PRETTY_PRINT));
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        // Ambil semua soal user (untuk admin)
        case 'get_user_soal':
            $user_id = $_GET['user_id'] ?? null;
            $soal = getUserSoal();
            if ($user_id) {
                $soal = array_filter($soal, function($s) use ($user_id) {
                    return $s['user_id'] == $user_id;
                });
                $soal = array_values($soal);
            }
            echo json_encode(['success' => true, 'data' => $soal]);
            break;

        // Teacher membuat soal baru (status pending)
        case 'create_user_soal':
            $user_id = $_POST['user_id'] ?? '';
            if (empty($user_id)) {
                echo json_encode(['success' => false, 'message' => 'User ID tidak ditemukan']);
                break;
            }
            $pelajaran = $_POST['pelajaran'] ?? '';
            $kelas = $_POST['kelas'] ?? '';
            $semester = $_POST['semester'] ?? '';
            $tahun = $_POST['tahun'] ?? '';
            $jenis = $_POST['jenis'] ?? '';
            $deskripsi = $_POST['deskripsi'] ?? '';

            if (empty($pelajaran) || empty($kelas) || empty($semester) || empty($tahun) || empty($jenis)) {
                echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
                break;
            }

            // Upload file
            $fileName = '';
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
                $fileName = time() . '_' . uniqid() . '.' . $ext;
                $targetPath = $upload_dir . $fileName;
                if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
                    echo json_encode(['success' => false, 'message' => 'Gagal upload file']);
                    break;
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'File harus diunggah']);
                break;
            }

            $soal = getUserSoal();
            $newId = 1;
            if (!empty($soal)) {
                $newId = max(array_column($soal, 'id')) + 1;
            }
            $newSoal = [
                'id' => $newId,
                'user_id' => $user_id,
                'pelajaran' => $pelajaran,
                'kelas' => $kelas,
                'semester' => $semester,
                'tahun' => $tahun,
                'jenis' => $jenis,
                'deskripsi' => $deskripsi,
                'file' => $fileName,
                'created_at' => date('Y-m-d H:i:s'),
                'status' => 'pending'
            ];
            $soal[] = $newSoal;
            if (saveUserSoal($soal)) {
                echo json_encode(['success' => true, 'message' => 'Soal berhasil ditambahkan (menunggu verifikasi)', 'id' => $newId]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal menyimpan data soal']);
            }
            break;

        // Teacher edit soal miliknya
        case 'update_user_soal':
            $id = $_POST['id'] ?? '';
            $user_id = $_POST['user_id'] ?? '';
            if (empty($id)) {
                echo json_encode(['success' => false, 'message' => 'ID tidak ditemukan']);
                break;
            }
            $soal = getUserSoal();
            $index = -1;
            foreach ($soal as $i => $s) {
                if ($s['id'] == $id) {
                    if (!empty($user_id) && $s['user_id'] != $user_id) {
                        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses edit soal ini']);
                        break 2;
                    }
                    $index = $i;
                    break;
                }
            }
            if ($index === -1) {
                echo json_encode(['success' => false, 'message' => 'Soal tidak ditemukan']);
                break;
            }
            // Update field
            $soal[$index]['pelajaran'] = $_POST['pelajaran'] ?? $soal[$index]['pelajaran'];
            $soal[$index]['kelas'] = $_POST['kelas'] ?? $soal[$index]['kelas'];
            $soal[$index]['semester'] = $_POST['semester'] ?? $soal[$index]['semester'];
            $soal[$index]['tahun'] = $_POST['tahun'] ?? $soal[$index]['tahun'];
            $soal[$index]['jenis'] = $_POST['jenis'] ?? $soal[$index]['jenis'];
            $soal[$index]['deskripsi'] = $_POST['deskripsi'] ?? $soal[$index]['deskripsi'];

            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
                $fileName = time() . '_' . uniqid() . '.' . $ext;
                $targetPath = $upload_dir . $fileName;
                if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
                    $oldFile = $upload_dir . $soal[$index]['file'];
                    if (file_exists($oldFile)) unlink($oldFile);
                    $soal[$index]['file'] = $fileName;
                }
            }

            if (saveUserSoal($soal)) {
                echo json_encode(['success' => true, 'message' => 'Soal berhasil diupdate']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal menyimpan perubahan']);
            }
            break;

        // Hapus soal user (oleh teacher pemilik atau admin)
        case 'delete_user_soal':
            $id = $_POST['id'] ?? '';
            $user_id = $_POST['user_id'] ?? '';
            if (empty($id)) {
                echo json_encode(['success' => false, 'message' => 'ID tidak ditemukan']);
                break;
            }
            $soal = getUserSoal();
            $newSoal = [];
            $deleted = false;
            foreach ($soal as $s) {
                if ($s['id'] != $id) {
                    $newSoal[] = $s;
                } else {
                    if (!empty($user_id) && $s['user_id'] != $user_id) {
                        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses hapus soal ini']);
                        break 2;
                    }
                    $deleted = true;
                    $filePath = $upload_dir . $s['file'];
                    if (file_exists($filePath)) unlink($filePath);
                }
            }
            if ($deleted && saveUserSoal($newSoal)) {
                echo json_encode(['success' => true, 'message' => 'Soal berhasil dihapus']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Soal tidak ditemukan atau gagal dihapus']);
            }
            break;

        // Admin update status verifikasi
        case 'update_status':
            $id = $_POST['id'] ?? '';
            $status = $_POST['status'] ?? '';
            if (!$id || !in_array($status, ['verified', 'pending', 'rejected'])) {
                echo json_encode(['success' => false, 'message' => 'Parameter tidak valid']);
                break;
            }
            $soal = getUserSoal();
            $updated = false;
            foreach ($soal as &$s) {
                if ($s['id'] == $id) {
                    $s['status'] = $status;
                    $updated = true;
                    break;
                }
            }
            if ($updated && saveUserSoal($soal)) {
                echo json_encode(['success' => true, 'message' => 'Status soal berhasil diperbarui']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal memperbarui status soal']);
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Aksi tidak valid: ' . $action]);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()]);
}
?>