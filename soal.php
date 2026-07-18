<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With');

$data_dir = __DIR__ . '/data/';
$master_file = $data_dir . 'master.json';
$soal_file = $data_dir . 'soal.json';

if (!is_dir($data_dir)) mkdir($data_dir, 0777, true);

if (!file_exists($master_file)) {
    $default_master = [
        'semester' => ['Ganjil', 'Genap'],
        'jenis' => ['Pilihan Ganda', 'Essay', 'Uraian'],
        'tahun' => ['2023-2024', '2024-2025', '2025-2026']
    ];
    file_put_contents($master_file, json_encode($default_master, JSON_PRETTY_PRINT));
}

if (!file_exists($soal_file)) {
    file_put_contents($soal_file, json_encode([], JSON_PRETTY_PRINT));
}

function getMaster() {
    global $master_file;
    $data = json_decode(file_get_contents($master_file), true);
    return $data ?: [];
}

function saveMaster($data) {
    global $master_file;
    return file_put_contents($master_file, json_encode($data, JSON_PRETTY_PRINT));
}

function getAdminSoal() {
    global $soal_file;
    $data = json_decode(file_get_contents($soal_file), true);
    return is_array($data) ? $data : [];
}

function saveAdminSoal($data) {
    global $soal_file;
    return file_put_contents($soal_file, json_encode($data, JSON_PRETTY_PRINT));
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_master':
            echo json_encode(['success' => true, 'data' => getMaster()]);
            break;

        case 'update_master':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) $input = $_POST;
            $newMaster = $input['data'] ?? $input;
            if (saveMaster($newMaster)) {
                echo json_encode(['success' => true, 'message' => 'Master data disimpan']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal simpan master']);
            }
            break;

        case 'get_all':
            echo json_encode(['success' => true, 'data' => getAdminSoal()]);
            break;

        case 'create':
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

            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $fileName = '';
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
                $fileName = time() . '_' . uniqid() . '.' . $ext;
                $targetPath = $uploadDir . $fileName;
                if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
                    echo json_encode(['success' => false, 'message' => 'Gagal upload file']);
                    break;
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'File harus diunggah']);
                break;
            }

            $soal = getAdminSoal();
            $newId = 1;
            if (!empty($soal)) {
                $newId = max(array_column($soal, 'id')) + 1;
            }
            $newSoal = [
                'id' => $newId,
                'pelajaran' => $pelajaran,
                'kelas' => $kelas,
                'semester' => $semester,
                'tahun' => $tahun,
                'jenis' => $jenis,
                'deskripsi' => $deskripsi,
                'file' => $fileName,
                'created_at' => date('Y-m-d H:i:s'),
                'status' => 'verified'
            ];
            $soal[] = $newSoal;
            if (saveAdminSoal($soal)) {
                echo json_encode(['success' => true, 'message' => 'Soal berhasil ditambahkan', 'id' => $newId]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal menyimpan data soal']);
            }
            break;

        case 'update':
            $id = $_POST['id'] ?? '';
            $source = $_POST['source'] ?? 'admin';
            if ($source !== 'admin') {
                echo json_encode(['success' => false, 'message' => 'Update soal user tidak didukung di sini']);
                break;
            }
            $soal = getAdminSoal();
            $index = -1;
            foreach ($soal as $i => $s) {
                if ($s['id'] == $id) {
                    $index = $i;
                    break;
                }
            }
            if ($index === -1) {
                echo json_encode(['success' => false, 'message' => 'Soal tidak ditemukan']);
                break;
            }
            $soal[$index]['pelajaran'] = $_POST['pelajaran'] ?? $soal[$index]['pelajaran'];
            $soal[$index]['kelas'] = $_POST['kelas'] ?? $soal[$index]['kelas'];
            $soal[$index]['semester'] = $_POST['semester'] ?? $soal[$index]['semester'];
            $soal[$index]['tahun'] = $_POST['tahun'] ?? $soal[$index]['tahun'];
            $soal[$index]['jenis'] = $_POST['jenis'] ?? $soal[$index]['jenis'];
            $soal[$index]['deskripsi'] = $_POST['deskripsi'] ?? $soal[$index]['deskripsi'];

            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
                $fileName = time() . '_' . uniqid() . '.' . $ext;
                $targetPath = __DIR__ . '/uploads/' . $fileName;
                if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
                    $oldFile = __DIR__ . '/uploads/' . $soal[$index]['file'];
                    if (file_exists($oldFile)) unlink($oldFile);
                    $soal[$index]['file'] = $fileName;
                }
            }

            if (saveAdminSoal($soal)) {
                echo json_encode(['success' => true, 'message' => 'Soal berhasil diupdate']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal menyimpan perubahan']);
            }
            break;

        case 'delete':
            $id = $_POST['id'] ?? '';
            $source = $_POST['source'] ?? 'admin';
            if ($source !== 'admin') {
                echo json_encode(['success' => false, 'message' => 'Hapus soal user tidak didukung di sini']);
                break;
            }
            $soal = getAdminSoal();
            $newSoal = [];
            $deleted = false;
            foreach ($soal as $s) {
                if ($s['id'] != $id) {
                    $newSoal[] = $s;
                } else {
                    $deleted = true;
                    $filePath = __DIR__ . '/uploads/' . $s['file'];
                    if (file_exists($filePath)) unlink($filePath);
                }
            }
            if ($deleted && saveAdminSoal($newSoal)) {
                echo json_encode(['success' => true, 'message' => 'Soal berhasil dihapus']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Soal tidak ditemukan atau gagal dihapus']);
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