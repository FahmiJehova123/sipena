<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] != 'admin' && $_SESSION['user_role'] != 'teacher')) {
    header('Location: login.php');
    exit;
}
if (time() - $_SESSION['login_time'] > 28800) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['login_time'] = time();

$page_title = 'Manajemen User - SIAKAD Admin';

// Ambil role dari GET (default student)
$role_filter = isset($_GET['role']) ? $_GET['role'] : 'student';
$allowed_roles = ['student', 'teacher', 'user'];
if (!in_array($role_filter, $allowed_roles)) {
    $role_filter = 'student';
}

// Tentukan judul dan current_page untuk sidebar
if ($role_filter == 'teacher') {
    $current_page = 'manage_guru';
    $title = 'Manajemen Guru';
} elseif ($role_filter == 'user') {
    $current_page = 'manage_user';
    $title = 'Manajemen User (Pending)';
} else {
    $current_page = 'manage_murid';
    $title = 'Manajemen Murid';
}

require_once __DIR__ . '/config.php';

// Load PhpSpreadsheet
require_once __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

function safeArray($data) {
    return is_array($data) ? $data : [];
}

// Pagination dan pencarian
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Validasi nilai per_page (hanya izinkan nilai tertentu)
$allowed_per_page = [10, 25, 50, 100];
if (!in_array($per_page, $allowed_per_page)) {
    $per_page = 10;
}

// ========== AMBIL USER DENGAN SELECT EKSPLISIT (SEMUA FIELD YANG DIPERLUKAN) ==========
$users_data = supabase_admin_request('GET', 'users', null, [
    'role'   => 'eq.' . $role_filter,
    'select' => 'id,full_name,role,nidn_or_nisn,photo_url,nik,phone,kelas_pagi_id,kelas_diniyyah_id,bagian,tingkat,tahun_masuk,tempat_lahir,tanggal_lahir,alamat,nama_ayah,pekerjaan_ayah,nama_ibu,pekerjaan_ibu'
]);

$all_users = safeArray($users_data);

// Filter pencarian (tidak mengubah struktur field)
if (!empty($search)) {
    $all_users = array_filter($all_users, function($user) use ($search) {
        $search_lower = strtolower($search);
        return strpos(strtolower($user['full_name'] ?? ''), $search_lower) !== false ||
               strpos(strtolower($user['nidn_or_nisn'] ?? ''), $search_lower) !== false ||
               strpos(strtolower($user['alamat'] ?? ''), $search_lower) !== false ||
               strpos(strtolower($user['nama_ayah'] ?? ''), $search_lower) !== false ||
               strpos(strtolower($user['nama_ibu'] ?? ''), $search_lower) !== false;
    });
    $all_users = array_values($all_users);
}

$total_users = count($all_users);
$total_pages = ceil($total_users / $per_page);
$offset = ($page - 1) * $per_page;
$users = array_slice($all_users, $offset, $per_page);

// Ambil daftar kelas untuk dropdown
$classes = safeArray(supabase_admin_request('GET', 'classes'));
$schedules_data = supabase_admin_request('GET', 'schedules', null, ['select' => 'id,classes(class_name),subjects(subject_name)']);
$schedules = safeArray($schedules_data);

// ========== HANDLE GET ACTIONS (Export & Download Template) ==========
if (isset($_GET['action'])) {
    $get_action = $_GET['action'];
    
    // Export Excel (GET)
    if ($get_action === 'export') {
        $export_users = $all_users; // data sudah difilter
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Header kolom
        $headers = [
            'A1' => 'Nama Lengkap',
            'B1' => 'NIS/NIDN',
            'C1' => 'Foto URL',
            'D1' => 'NIK',
            'E1' => 'Nomor HP',
            'F1' => 'Tahun Masuk',
            'G1' => 'Tempat Lahir',
            'H1' => 'Tanggal Lahir',
            'I1' => 'Alamat',
            'J1' => 'Kelas Pagi',
            'K1' => 'Kelas Diniyyah',
            'L1' => 'Bagian Diniyyah',
            'M1' => 'Tingkat Diniyyah',
            'N1' => 'Nama Ayah',
            'O1' => 'Pekerjaan Ayah',
            'P1' => 'Nama Ibu',
            'Q1' => 'Pekerjaan Ibu'
        ];
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        // Data
        $row = 2;
        foreach ($export_users as $user) {
            // Mapping nama kelas
            $kelas_pagi_nama = '';
            foreach ($classes as $c) {
                if ($c['id'] == ($user['kelas_pagi_id'] ?? null)) {
                    $kelas_pagi_nama = $c['class_name'];
                    break;
                }
            }
            $kelas_diniyyah_nama = '';
            foreach ($classes as $c) {
                if ($c['id'] == ($user['kelas_diniyyah_id'] ?? null)) {
                    $kelas_diniyyah_nama = $c['class_name'];
                    break;
                }
            }
            
            $sheet->setCellValue("A{$row}", $user['full_name'] ?? '');
            $sheet->setCellValue("B{$row}", $user['nidn_or_nisn'] ?? '');
            $sheet->setCellValue("C{$row}", $user['photo_url'] ?? '');
            $sheet->setCellValue("D{$row}", $user['nik'] ?? '');
            $sheet->setCellValue("E{$row}", $user['phone'] ?? '');
            $sheet->setCellValue("F{$row}", $user['tahun_masuk'] ?? '');
            $sheet->setCellValue("G{$row}", $user['tempat_lahir'] ?? '');
            $sheet->setCellValue("H{$row}", $user['tanggal_lahir'] ?? '');
            $sheet->setCellValue("I{$row}", $user['alamat'] ?? '');
            $sheet->setCellValue("J{$row}", $kelas_pagi_nama);
            $sheet->setCellValue("K{$row}", $kelas_diniyyah_nama);
            $sheet->setCellValue("L{$row}", $user['bagian'] ?? '');
            $sheet->setCellValue("M{$row}", $user['tingkat'] ?? '');
            $sheet->setCellValue("N{$row}", $user['nama_ayah'] ?? '');
            $sheet->setCellValue("O{$row}", $user['pekerjaan_ayah'] ?? '');
            $sheet->setCellValue("P{$row}", $user['nama_ibu'] ?? '');
            $sheet->setCellValue("Q{$row}", $user['pekerjaan_ibu'] ?? '');
            $row++;
        }
        
        foreach (range('A', 'Q') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        $fileName = ($role_filter == 'teacher' ? 'guru' : ($role_filter == 'user' ? 'user' : 'murid')) . '_' . date('Y-m-d') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
    
    // Download Template (GET)
    if ($get_action === 'download_template') {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $headers = [
            'A1' => 'Nama Lengkap',
            'B1' => 'NIS/NIDN',
            'C1' => 'Foto URL',
            'D1' => 'NIK',
            'E1' => 'Nomor HP',
            'F1' => 'Tahun Masuk',
            'G1' => 'Tempat Lahir',
            'H1' => 'Tanggal Lahir',
            'I1' => 'Alamat',
            'J1' => 'Kelas Pagi',
            'K1' => 'Kelas Diniyyah',
            'L1' => 'Bagian Diniyyah',
            'M1' => 'Tingkat Diniyyah',
            'N1' => 'Nama Ayah',
            'O1' => 'Pekerjaan Ayah',
            'P1' => 'Nama Ibu',
            'Q1' => 'Pekerjaan Ibu'
        ];
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        $sheet->setCellValue('A2', 'Contoh Nama');
        $sheet->setCellValue('B2', 'NIS/NIDN123');
        $sheet->setCellValue('J2', 'Kelas 10 IPA');
        $sheet->setCellValue('K2', 'Diniyyah 1');
        
        foreach (range('A', 'Q') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        $fileName = 'template_' . ($role_filter == 'teacher' ? 'guru' : ($role_filter == 'user' ? 'user' : 'murid')) . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}

// ========== PROSES POST (CRUD, Import, Change Role) ==========
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // IMPORT EXCEL (POST)
    if ($action === 'import' && isset($_FILES['import_file']) && $_FILES['import_file']['error'] == 0) {
        $fileName = $_FILES['import_file']['tmp_name'];
        try {
            $spreadsheet = IOFactory::load($fileName);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();
            array_shift($rows); // hapus header
            
            $imported = 0;
            $errors = [];
            
            foreach ($rows as $rowIndex => $row) {
                $full_name      = trim($row[0] ?? '');
                $nidn_or_nisn   = trim($row[1] ?? '');
                $photo_url      = trim($row[2] ?? '');
                $nik            = trim($row[3] ?? '');
                $phone          = trim($row[4] ?? '');
                $tahun_masuk    = !empty($row[5]) ? (int)$row[5] : null;
                $tempat_lahir   = trim($row[6] ?? '');
                $tanggal_lahir  = !empty($row[7]) ? date('Y-m-d', strtotime($row[7])) : null;
                $alamat         = trim($row[8] ?? '');
                $kelas_pagi     = trim($row[9] ?? '');
                $kelas_diniyyah = trim($row[10] ?? '');
                $bagian         = trim($row[11] ?? '');
                $tingkat        = trim($row[12] ?? '');
                $nama_ayah      = trim($row[13] ?? '');
                $pekerjaan_ayah = trim($row[14] ?? '');
                $nama_ibu       = trim($row[15] ?? '');
                $pekerjaan_ibu  = trim($row[16] ?? '');
                
                if (empty($full_name)) {
                    $errors[] = "Baris " . ($rowIndex+2) . ": Nama lengkap tidak boleh kosong";
                    continue;
                }
                
                // Cari ID kelas pagi
                $kelas_pagi_id = null;
                if (!empty($kelas_pagi)) {
                    foreach ($classes as $c) {
                        if (strcasecmp($c['class_name'], $kelas_pagi) == 0) {
                            $kelas_pagi_id = $c['id'];
                            break;
                        }
                    }
                }
                
                // Cari ID kelas diniyyah
                $kelas_diniyyah_id = null;
                if (!empty($kelas_diniyyah)) {
                    foreach ($classes as $c) {
                        if (strcasecmp($c['class_name'], $kelas_diniyyah) == 0) {
                            $kelas_diniyyah_id = $c['id'];
                            break;
                        }
                    }
                }
                
                // Generate UUID
                $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000,
                    mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
                
                $data = [
                    'id' => $id,
                    'full_name' => $full_name,
                    'role' => $role_filter,
                    'nidn_or_nisn' => $nidn_or_nisn,
                    'photo_url' => $photo_url ?: null,
                    'nik' => $nik ?: null,
                    'phone' => $phone ?: null,
                    'kelas_pagi_id' => $kelas_pagi_id,
                    'kelas_diniyyah_id' => $kelas_diniyyah_id,
                    'bagian' => $bagian ?: null,
                    'tingkat' => $tingkat ?: null,
                    'tahun_masuk' => $tahun_masuk,
                    'tempat_lahir' => $tempat_lahir ?: null,
                    'tanggal_lahir' => $tanggal_lahir,
                    'alamat' => $alamat ?: null,
                    'nama_ayah' => $nama_ayah ?: null,
                    'pekerjaan_ayah' => $pekerjaan_ayah ?: null,
                    'nama_ibu' => $nama_ibu ?: null,
                    'pekerjaan_ibu' => $pekerjaan_ibu ?: null
                ];
                
                $result = supabase_admin_request('POST', 'users', $data);
                if (isset($result['id'])) {
                    $imported++;
                } else {
                    $errors[] = "Baris " . ($rowIndex+2) . ": Gagal import - " . json_encode($result);
                }
            }
            
            if ($imported > 0) {
                $message = "<div class='bg-green-100 text-green-700 p-2 rounded'>Import berhasil: $imported data ditambahkan.</div>";
                if (!empty($errors)) {
                    $message .= "<div class='bg-yellow-100 text-yellow-700 p-2 rounded mt-2'>" . implode('<br>', $errors) . "</div>";
                }
            } else {
                $message = "<div class='bg-red-100 text-red-700 p-2 rounded'>Import gagal: " . implode('<br>', $errors) . "</div>";
            }
        } catch (Exception $e) {
            $message = "<div class='bg-red-100 text-red-700 p-2 rounded'>Error membaca file: " . $e->getMessage() . "</div>";
        }
    }
    
    // CHANGE ROLE
    elseif ($action === 'change_role') {
        $id = $_POST['id'] ?? '';
        $new_role = $_POST['new_role'] ?? '';
        if (!empty($id) && in_array($new_role, ['student', 'teacher', 'user', 'admin'])) {
            // Hapus semua role user saat ini
            supabase_admin_request('DELETE', 'user_roles', null, ['user_id' => 'eq.' . $id]);
            // Dapatkan ID role baru
            $roles_data = supabase_admin_request('GET', 'roles', null, ['name' => 'eq.' . $new_role]);
            if (!empty($roles_data) && isset($roles_data[0]['id'])) {
                $role_id = $roles_data[0]['id'];
                $result = supabase_admin_request('POST', 'user_roles', ['user_id' => $id, 'role_id' => $role_id]);
                if (isset($result['user_id'])) {
                    $message = "<div class='bg-green-100 text-green-700 p-2 rounded'>Role berhasil diubah menjadi $new_role.</div>";
                } else {
                    $message = "<div class='bg-red-100 text-red-700 p-2 rounded'>Gagal mengubah role.</div>";
                }
            } else {
                $message = "<div class='bg-red-100 text-red-700 p-2 rounded'>Role tidak ditemukan.</div>";
            }
        } else {
            $message = "<div class='bg-red-100 text-red-700 p-2 rounded'>Data tidak valid.</div>";
        }
    }
    
    // CRUD: add, edit, delete, generate_qr, reset_password
    elseif ($action === 'add') {
        $id = $_POST['id'] ?? '';
        if (empty($id)) {
            $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
        }
        $data = [
            'id' => $id,
            'full_name' => $_POST['full_name'],
            'role' => $role_filter,
            'nidn_or_nisn' => $_POST['nidn_or_nisn'],
            'photo_url' => $_POST['photo_url'] ?? null,
            'nik' => $_POST['nik'] ?? null,
            'phone' => $_POST['phone'] ?? null,
            'kelas_pagi_id' => !empty($_POST['kelas_pagi_id']) ? (int)$_POST['kelas_pagi_id'] : null,
            'kelas_diniyyah_id' => !empty($_POST['kelas_diniyyah_id']) ? (int)$_POST['kelas_diniyyah_id'] : null,
            'bagian' => $_POST['bagian'] ?? null,
            'tingkat' => $_POST['tingkat'] ?? null,
            'tahun_masuk' => !empty($_POST['tahun_masuk']) ? (int)$_POST['tahun_masuk'] : null,
            'tempat_lahir' => $_POST['tempat_lahir'] ?? null,
            'tanggal_lahir' => !empty($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : null,
            'alamat' => $_POST['alamat'] ?? null,
            'nama_ayah' => $_POST['nama_ayah'] ?? null,
            'pekerjaan_ayah' => $_POST['pekerjaan_ayah'] ?? null,
            'nama_ibu' => $_POST['nama_ibu'] ?? null,
            'pekerjaan_ibu' => $_POST['pekerjaan_ibu'] ?? null
        ];
        $result = supabase_admin_request('POST', 'users', $data);
        if (isset($result['id'])) {
            $message = 'User berhasil ditambahkan';
        } else {
            $message = 'Gagal menambah user: ' . json_encode($result);
        }
    } elseif ($action === 'edit') {
        $id = $_POST['id'];
        $data = [
            'full_name' => $_POST['full_name'],
            'nidn_or_nisn' => $_POST['nidn_or_nisn'],
            'photo_url' => $_POST['photo_url'] ?? null,
            'nik' => $_POST['nik'] ?? null,
            'phone' => $_POST['phone'] ?? null,
            'kelas_pagi_id' => !empty($_POST['kelas_pagi_id']) ? (int)$_POST['kelas_pagi_id'] : null,
            'kelas_diniyyah_id' => !empty($_POST['kelas_diniyyah_id']) ? (int)$_POST['kelas_diniyyah_id'] : null,
            'bagian' => $_POST['bagian'] ?? null,
            'tingkat' => $_POST['tingkat'] ?? null,
            'tahun_masuk' => !empty($_POST['tahun_masuk']) ? (int)$_POST['tahun_masuk'] : null,
            'tempat_lahir' => $_POST['tempat_lahir'] ?? null,
            'tanggal_lahir' => !empty($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : null,
            'alamat' => $_POST['alamat'] ?? null,
            'nama_ayah' => $_POST['nama_ayah'] ?? null,
            'pekerjaan_ayah' => $_POST['pekerjaan_ayah'] ?? null,
            'nama_ibu' => $_POST['nama_ibu'] ?? null,
            'pekerjaan_ibu' => $_POST['pekerjaan_ibu'] ?? null
        ];
        $result = supabase_admin_request('PATCH', 'users', $data, ['id' => 'eq.' . $id]);
        if (isset($result['id']) || (is_array($result) && empty($result))) {
            $message = 'User berhasil diupdate';
        } else {
            $message = 'Gagal update user: ' . json_encode($result);
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'];
        supabase_admin_request('DELETE', 'users', null, ['id' => 'eq.' . $id]);
        $message = 'User berhasil dihapus';
    } elseif ($action === 'generate_qr') {
        $user_id = $_POST['user_id'];
        require_once __DIR__ . '/functions.php';
        $qr_base64 = generate_qr_code($user_id);
        if ($qr_base64) {
            $message = "QR Code berhasil dibuat. <img src='$qr_base64' class='w-32 h-32 border rounded mt-2'>";
        } else {
            $message = "Gagal generate QR Code. Periksa koneksi ke Supabase.";
        }
    } elseif ($action === 'reset_password') {
        $id = $_POST['id'];
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($new_password) || $new_password !== $confirm_password) {
            $message = '<div class="bg-red-100 text-red-700 p-2 rounded">Password baru tidak cocok atau kosong.</div>';
        } elseif (strlen($new_password) < 6) {
            $message = '<div class="bg-red-100 text-red-700 p-2 rounded">Password minimal 6 karakter.</div>';
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $update = supabase_admin_request('PATCH', 'users', ['password_hash' => $hashed], ['id' => 'eq.' . $id]);
            if (isset($update['id']) || (is_array($update) && empty($update))) {
                $message = '<div class="bg-green-100 text-green-700 p-2 rounded">Password berhasil direset.</div>';
            } else {
                $message = '<div class="bg-red-100 text-red-700 p-2 rounded">Gagal mereset password.</div>';
            }
        }
    }
}

// Navigasi sidebar untuk menandai active menu
require_once __DIR__ . '/includes/nav_items.php';
foreach ($nav_items as &$item) {
    if ($role_filter == 'teacher' && $item['link'] == 'manage_users.php?role=teacher') {
        $item['active'] = true;
    } elseif ($role_filter == 'student' && $item['link'] == 'manage_users.php?role=student') {
        $item['active'] = true;
    } elseif ($role_filter == 'user' && $item['link'] == 'manage_users.php?role=user') {
        $item['active'] = true;
    } else {
        $item['active'] = false;
    }
}
unset($item);

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* Modal detail styling */
#detailModal { backdrop-filter: blur(4px); }
#detailModal > div { max-width: 90vw; max-height: 90vh; border-radius: 1.25rem; overflow-y: auto; }
@media (min-width: 768px) { #detailModal > div { max-width: 80vw; } }
@media (min-width: 1024px) { #detailModal > div { max-width: 70vw; } }
#detailContent .grid > div { padding: 0.75rem; background-color: #f9fafb; border-radius: 0.75rem; }
.dark #detailContent .grid > div { background-color: #1f2937; }
#detailContent strong { font-weight: 600; color: #111827; display: inline-block; min-width: 120px; margin-right: 0.5rem; }
.dark #detailContent strong { color: #f3f4f6; }
#detailContent img { max-height: 200px; border-radius: 0.75rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content-container flex-1 transition-all duration-300 ease-in-out overflow-x-auto">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openMobileSidebarBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block"><?= $title ?></h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <a href="kiosk_scanner.php" class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg text-sm flex items-center gap-2">
                        <i class="fas fa-qrcode"></i> <span class="hidden sm:inline">Absensi QR/NFC</span>
                    </a>
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                        <i class="fas fa-moon text-xl dark:hidden"></i>
                        <i class="fas fa-sun text-xl hidden dark:inline"></i>
                    </button>
                    <div class="relative group">
                        <button class="flex items-center space-x-2 focus:outline-none">
                            <?php
                            $user_photo = $_SESSION['user_photo'] ?? null;
                            $user_name = $_SESSION['user_name'] ?? 'Admin';
                            $initial = strtoupper(substr($user_name, 0, 1));
                            ?>
                            <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold overflow-hidden">
                                <?php if ($user_photo): ?>
                                    <img src="<?= htmlspecialchars($user_photo) ?>" class="w-full h-full object-cover" alt="Foto Profil">
                                <?php else: ?>
                                    <span><?= $initial ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="hidden md:inline text-gray-700 dark:text-gray-200"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></span>
                            <i class="fas fa-chevron-down hidden md:inline text-gray-500 dark:text-gray-400 text-xs"></i>
                        </button>
                        <div class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-md shadow-lg py-1 hidden group-hover:block z-20">
                            <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-user mr-2"></i>Profil</a>
                            <a href="logout.php" class="block px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-sign-out-alt mr-2"></i>Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4 md:p-6 dark:bg-gray-900 transition-colors">
            <?php if ($message): ?>
                <div class="mb-4"><?= $message ?></div>
            <?php endif; ?>

            <div class="flex flex-wrap justify-between items-center mb-4 gap-2">
                <div class="space-x-2">
                    <a href="?role=student&page=1&search=<?= urlencode($search) ?>" class="px-4 py-2 rounded <?= $role_filter == 'student' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700 dark:text-gray-300' ?>">Murid</a>
                    <a href="?role=teacher&page=1&search=<?= urlencode($search) ?>" class="px-4 py-2 rounded <?= $role_filter == 'teacher' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700 dark:text-gray-300' ?>">Guru</a>
                    <a href="?role=user&page=1&search=<?= urlencode($search) ?>" class="px-4 py-2 rounded <?= $role_filter == 'user' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700 dark:text-gray-300' ?>">User (Pending)</a>
                </div>
                <div class="flex flex-wrap gap-2">
                    <form method="GET" class="flex gap-2">
                        <input type="hidden" name="role" value="<?= $role_filter ?>">
                        <input type="text" name="search" placeholder="Cari nama, NIS, alamat..." value="<?= htmlspecialchars($search) ?>" class="border rounded px-3 py-1 dark:bg-gray-700 dark:text-white">
                        <button type="submit" class="bg-blue-600 text-white px-3 py-1 rounded">Cari</button>
                        <?php if ($search): ?>
                            <a href="?role=<?= $role_filter ?>&page=1" class="bg-gray-500 text-white px-3 py-1 rounded">Reset</a>
                        <?php endif; ?>
                    </form>
                    <button onclick="openAddModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded"><i class="fas fa-plus mr-1"></i>Tambah</button>
                    <button onclick="openImportModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded"><i class="fas fa-upload mr-1"></i>Import Excel</button>
                    <a href="?role=<?= $role_filter ?>&action=export&search=<?= urlencode($search) ?>" class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded"><i class="fas fa-download mr-1"></i>Export Excel</a>
                    <a href="?role=<?= $role_filter ?>&action=download_template" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded"><i class="fas fa-file-import mr-1"></i>Download Template</a>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">No</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Nama</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Role</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">NIS/NIDN</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Tahun Masuk</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Tempat Lahir</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Tanggal Lahir</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Alamat</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Nomor HP</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php if (empty($users)): ?>
                            <tr><td colspan="10" class="text-center py-4 text-gray-500">Belum ada数据</td></tr>
                        <?php else: ?>
                            <?php $no = $offset + 1; foreach ($users as $user): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-4 py-2"><?= $no++ ?></td>
                                <td class="px-4 py-2 whitespace-nowrap font-medium"><?= htmlspecialchars($user['full_name']) ?></td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs rounded-full <?= 
                                        $user['role'] == 'admin' ? 'bg-red-100 text-red-800' : 
                                        ($user['role'] == 'teacher' ? 'bg-purple-100 text-purple-800' : 
                                        ($user['role'] == 'student' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800')) ?>">
                                        <?= ucfirst($user['role']) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap"><?= htmlspecialchars($user['nidn_or_nisn'] ?? '-') ?></td>
                                <td class="px-4 py-2 whitespace-nowrap"><?= htmlspecialchars($user['tahun_masuk'] ?? '-') ?></td>
                                <td class="px-4 py-2 whitespace-nowrap"><?= htmlspecialchars($user['tempat_lahir'] ?? '-') ?></td>
                                <td class="px-4 py-2 whitespace-nowrap"><?= htmlspecialchars($user['tanggal_lahir'] ?? '-') ?></td>
                                <td class="px-4 py-2 whitespace-nowrap"><?= htmlspecialchars($user['alamat'] ?? '-') ?></td>
                                <td class="px-4 py-2 whitespace-nowrap"><?= htmlspecialchars($user['phone'] ?? '-') ?></td>
                                <td class="px-4 py-2 whitespace-nowrap space-x-1">
                                    <button onclick="openDetailModal('<?= $user['id'] ?>')" class="bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded text-sm">Detail</button>
                                    <button onclick="openEditModal('<?= $user['id'] ?>')" class="bg-yellow-500 hover:bg-yellow-600 text-white px-2 py-1 rounded text-sm">Edit</button>
                                    <button onclick="openResetPasswordModal('<?= $user['id'] ?>', '<?= htmlspecialchars($user['full_name']) ?>')" class="bg-purple-600 hover:bg-purple-700 text-white px-2 py-1 rounded text-sm">Reset Pass</button>
                                    <button onclick="openChangeRoleModal('<?= $user['id'] ?>', '<?= htmlspecialchars($user['full_name']) ?>', '<?= $user['role'] ?>')" class="bg-orange-600 hover:bg-orange-700 text-white px-2 py-1 rounded text-sm">Ubah Role</button>
                                    <button onclick="confirmDelete('<?= $user['id'] ?>')" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-sm">Hapus</button>
                                    <?php if ($role_filter == 'student'): ?>
                                    <button onclick="openQRModal('<?= $user['id'] ?>', '<?= htmlspecialchars($user['full_name']) ?>')" class="bg-blue-600 hover:bg-blue-700 text-white px-2 py-1 rounded text-sm">QR</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

<!-- Pagination Responsif -->
<?php if ($total_pages > 1): ?>
<div class="flex flex-col sm:flex-row justify-center items-center gap-4 mt-6">
    <!-- Informasi halaman -->
    <div class="text-sm text-gray-600 dark:text-gray-400 order-2 sm:order-1">
        Menampilkan halaman <span class="font-semibold"><?= $page ?></span> dari 
        <span class="font-semibold"><?= $total_pages ?></span> halaman
        <span class="hidden xs:inline"> | Total <?= $total_users ?> data</span>
    </div>
    
    <!-- Tombol navigasi -->
    <div class="flex flex-wrap justify-center gap-1 order-1 sm:order-2">
        <!-- Tombol First -->
        <?php if ($page > 1): ?>
        <a href="?role=<?= $role_filter ?>&page=1&search=<?= urlencode($search) ?>" 
           class="px-2 sm:px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors <?= $page == 1 ? 'opacity-50 cursor-not-allowed' : '' ?>"
           title="Halaman pertama">
            <i class="fas fa-angle-double-left text-xs sm:text-sm"></i>
            <span class="hidden sm:inline"> Pertama</span>
        </a>
        <?php endif; ?>
        
        <!-- Tombol Prev -->
        <?php if ($page > 1): ?>
        <a href="?role=<?= $role_filter ?>&page=<?= $page-1 ?>&search=<?= urlencode($search) ?>" 
           class="px-2 sm:px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
            <i class="fas fa-chevron-left text-xs sm:text-sm"></i>
            <span class="hidden sm:inline"> Sebelumnya</span>
        </a>
        <?php endif; ?>
        
        <!-- Nomor halaman dengan ellipsis untuk halaman yang banyak -->
        <div class="flex flex-wrap justify-center gap-1">
            <?php
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            
            // Tampilkan nomor halaman pertama jika perlu
            if ($start_page > 1): ?>
                <a href="?role=<?= $role_filter ?>&page=1&search=<?= urlencode($search) ?>" 
                   class="hidden sm:flex px-2 sm:px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">1</a>
                <?php if ($start_page > 2): ?>
                    <span class="hidden sm:flex px-2 py-1 text-gray-500">...</span>
                <?php endif;
            endif;
            
            // Tampilkan halaman di sekitar halaman aktif
            for ($i = $start_page; $i <= $end_page; $i++): ?>
                <a href="?role=<?= $role_filter ?>&page=<?= $i ?>&search=<?= urlencode($search) ?>" 
                   class="flex px-2 sm:px-3 py-1 rounded-lg transition-colors <?= $i == $page ? 'bg-blue-600 text-white shadow-md' : 'bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600' ?>">
                    <?= $i ?>
                </a>
            <?php endfor;
            
            // Tampilkan nomor halaman terakhir jika perlu
            if ($end_page < $total_pages): 
                if ($end_page < $total_pages - 1): ?>
                    <span class="hidden sm:flex px-2 py-1 text-gray-500">...</span>
                <?php endif; ?>
                <a href="?role=<?= $role_filter ?>&page=<?= $total_pages ?>&search=<?= urlencode($search) ?>" 
                   class="hidden sm:flex px-2 sm:px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors"><?= $total_pages ?></a>
            <?php endif; ?>
        </div>
        
        <!-- Tombol Next -->
        <?php if ($page < $total_pages): ?>
        <a href="?role=<?= $role_filter ?>&page=<?= $page+1 ?>&search=<?= urlencode($search) ?>" 
           class="px-2 sm:px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
            <span class="hidden sm:inline">Selanjutnya </span>
            <i class="fas fa-chevron-right text-xs sm:text-sm"></i>
        </a>
        <?php endif; ?>
        
        <!-- Tombol Last -->
        <?php if ($page < $total_pages): ?>
        <a href="?role=<?= $role_filter ?>&page=<?= $total_pages ?>&search=<?= urlencode($search) ?>" 
           class="px-2 sm:px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors"
           title="Halaman terakhir">
            <span class="hidden sm:inline">Terakhir </span>
            <i class="fas fa-angle-double-right text-xs sm:text-sm"></i>
        </a>
        <?php endif; ?>
    </div>
    
    <!-- Pilihan jumlah data per halaman (optional) -->
    <div class="order-3 mt-2 sm:mt-0">
        <select id="perPageSelect" class="text-sm border rounded-lg px-2 py-1 bg-white dark:bg-gray-700 dark:text-white dark:border-gray-600">
            <option value="10" <?= $per_page == 10 ? 'selected' : '' ?>>10 per halaman</option>
            <option value="25" <?= $per_page == 25 ? 'selected' : '' ?>>25 per halaman</option>
            <option value="50" <?= $per_page == 50 ? 'selected' : '' ?>>50 per halaman</option>
            <option value="100" <?= $per_page == 100 ? 'selected' : '' ?>>100 per halaman</option>
        </select>
    </div>
</div>

            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Modal Tambah/Edit -->
<div id="userModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-4xl max-h-screen overflow-y-auto">
        <h3 id="modalTitle" class="text-lg font-bold text-gray-800 dark:text-white mb-4">Tambah User</h3>
        <form method="POST" id="userForm">
            <input type="hidden" name="action" id="formAction">
            <input type="hidden" name="id" id="userId">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label>Nama Lengkap</label><input type="text" name="full_name" id="fullName" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white"></div>
                <div><label><?= $role_filter == 'teacher' ? 'NIDN' : 'NIS' ?></label><input type="text" name="nidn_or_nisn" id="nidn" class="w-full border rounded px-2 py-1"></div>
                <div><label>Foto (URL)</label><input type="url" name="photo_url" id="photoUrl" class="w-full border rounded px-2 py-1" placeholder="https://..."></div>
                <div><label>NIK</label><input type="text" name="nik" id="nik" class="w-full border rounded px-2 py-1"></div>
                <div><label>Nomor HP</label><input type="text" name="phone" id="phone" class="w-full border rounded px-2 py-1"></div>
                <div><label>Tahun Masuk</label><input type="number" name="tahun_masuk" id="tahunMasuk" class="w-full border rounded px-2 py-1"></div>
                <div><label>Tempat Lahir</label><input type="text" name="tempat_lahir" id="tempatLahir" class="w-full border rounded px-2 py-1"></div>
                <div><label>Tanggal Lahir</label><input type="date" name="tanggal_lahir" id="tanggalLahir" class="w-full border rounded px-2 py-1"></div>
                <div class="md:col-span-2"><label>Alamat</label><textarea name="alamat" id="alamat" rows="2" class="w-full border rounded px-2 py-1"></textarea></div>
                <div><label>Kelas Pagi</label><select name="kelas_pagi_id" id="kelasPagiId" class="w-full border rounded px-2 py-1"><option value="">-- Tidak Dikelas --</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option><?php endforeach; ?></select></div>
                <div><label>Kelas Diniyyah</label><select name="kelas_diniyyah_id" id="kelasDiniyyahId" class="w-full border rounded px-2 py-1"><option value="">-- Tidak Dikelas --</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option><?php endforeach; ?></select></div>
                <div><label>Bagian (Kelas Diniyyah)</label><input type="text" name="bagian" id="bagian" class="w-full border rounded px-2 py-1"></div>
                <div><label>Tingkat (Kelas Diniyyah)</label><input type="text" name="tingkat" id="tingkat" class="w-full border rounded px-2 py-1"></div>
                <div><label>Nama Ayah</label><input type="text" name="nama_ayah" id="namaAyah" class="w-full border rounded px-2 py-1"></div>
                <div><label>Pekerjaan Ayah</label><input type="text" name="pekerjaan_ayah" id="pekerjaanAyah" class="w-full border rounded px-2 py-1"></div>
                <div><label>Nama Ibu</label><input type="text" name="nama_ibu" id="namaIbu" class="w-full border rounded px-2 py-1"></div>
                <div><label>Pekerjaan Ibu</label><input type="text" name="pekerjaan_ibu" id="pekerjaanIbu" class="w-full border rounded px-2 py-1"></div>
            </div>
            <div class="flex justify-end space-x-2 mt-4">
                <button type="button" onclick="closeModal()" class="bg-gray-400 text-white px-4 py-2 rounded">Batal</button>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Import Excel -->
<div id="importModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Import Data User (Excel)</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import">
            <div class="mb-4">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Pilih file Excel (.xlsx)</label>
                <input type="file" name="import_file" accept=".xlsx" required class="w-full border rounded px-2 py-1 dark:bg-gray-700">
            </div>
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="closeImportModal()" class="bg-gray-400 text-white px-4 py-2 rounded">Batal</button>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Import</button>
            </div>
        </form>
        <div class="mt-3 text-xs text-gray-500">* Pastikan format sesuai dengan template yang disediakan.</div>
    </div>
</div>

<!-- Modal Reset Password -->
<div id="resetPasswordModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md max-h-screen overflow-y-auto">
        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Reset Password</h3>
        <form method="POST" id="resetPasswordForm">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id" id="resetUserId">
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">User</label>
                <input type="text" id="resetUserName" readonly class="w-full border rounded px-2 py-1 bg-gray-100 dark:bg-gray-700">
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Password Baru</label>
                <input type="password" name="new_password" id="newPassword" required minlength="6" class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Konfirmasi Password Baru</label>
                <input type="password" name="confirm_password" id="confirmPassword" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
            </div>
            <div class="flex justify-end space-x-2 mt-4">
                <button type="button" onclick="closeResetPasswordModal()" class="bg-gray-400 text-white px-4 py-2 rounded">Batal</button>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Ubah Role -->
<div id="changeRoleModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Ubah Role User</h3>
        <form method="POST">
            <input type="hidden" name="action" value="change_role">
            <input type="hidden" name="id" id="changeRoleUserId">
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">User</label>
                <input type="text" id="changeRoleUserName" readonly class="w-full border rounded px-2 py-1 bg-gray-100 dark:bg-gray-700">
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Role Saat Ini</label>
                <input type="text" id="changeRoleCurrentRole" readonly class="w-full border rounded px-2 py-1 bg-gray-100 dark:bg-gray-700">
            </div>
            <div class="mb-3">
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Role Baru</label>
                <select name="new_role" id="changeRoleNewRole" required class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:text-white">
                    <option value="student">Murid (Student)</option>
                    <option value="teacher">Guru (Teacher)</option>
                    <option value="user">User (Pending)</option>
                </select>
            </div>
            <div class="flex justify-end space-x-2 mt-4">
                <button type="button" onclick="closeChangeRoleModal()" class="bg-gray-400 text-white px-4 py-2 rounded">Batal</button>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Ubah Role</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Detail User -->
<div id="detailModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-3xl max-h-screen overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-800 dark:text-white">Detail User</h3>
            <button onclick="closeDetailModal()" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
        </div>
        <div id="detailContent" class="space-y-3"></div>
        <div class="flex justify-end mt-4">
            <button onclick="closeDetailModal()" class="bg-gray-400 hover:bg-gray-500 text-white px-4 py-2 rounded">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal Generate QR -->
<div id="qrModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-96 max-w-full">
        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Generate QR Code</h3>
        <form method="POST">
            <input type="hidden" name="action" value="generate_qr">
            <input type="hidden" name="user_id" id="qrUserId">
            <div class="flex justify-end space-x-2">
                <button type="button" onclick="closeQRModal()" class="bg-gray-400 text-white px-4 py-2 rounded">Batal</button>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Generate</button>
            </div>
        </form>
    </div>
</div>

<script>
// Dark mode
const darkModeToggle = document.getElementById('darkModeToggle');
function setDarkMode(isDark) {
    if (isDark) document.documentElement.classList.add('dark');
    else document.documentElement.classList.remove('dark');
    localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled');
    if (darkModeToggle) {
        const moon = darkModeToggle.querySelector('.fa-moon');
        const sun = darkModeToggle.querySelector('.fa-sun');
        if (moon && sun) {
            moon.classList.toggle('hidden', isDark);
            sun.classList.toggle('hidden', !isDark);
        }
    }
}
const saved = localStorage.getItem('darkMode');
if (saved === 'enabled') setDarkMode(true);
else if (saved === 'disabled') setDarkMode(false);
else setDarkMode(window.matchMedia('(prefers-color-scheme: dark)').matches);
darkModeToggle?.addEventListener('click', () => setDarkMode(!document.documentElement.classList.contains('dark')));

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// Detail Modal
function openDetailModal(userId) {
    fetch(`api/get_user_detail.php?id=${userId}`)
        .then(res => res.json())
        .then(data => {
            if (data && data.id) {
                let html = `
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div><strong>Nama Lengkap:</strong> ${escapeHtml(data.full_name)}</div>
                        <div><strong>Role:</strong> ${escapeHtml(data.role)}</div>
                        <div><strong>NIS/NIDN:</strong> ${escapeHtml(data.nidn_or_nisn || '-')}</div>
                        <div><strong>NIK:</strong> ${escapeHtml(data.nik || '-')}</div>
                        <div><strong>Nomor HP:</strong> ${escapeHtml(data.phone || '-')}</div>
                        <div><strong>Tahun Masuk:</strong> ${escapeHtml(data.tahun_masuk || '-')}</div>
                        <div><strong>Tempat Lahir:</strong> ${escapeHtml(data.tempat_lahir || '-')}</div>
                        <div><strong>Tanggal Lahir:</strong> ${escapeHtml(data.tanggal_lahir || '-')}</div>
                        <div><strong>Alamat:</strong> ${escapeHtml(data.alamat || '-')}</div>
                        <div><strong>Kelas Pagi:</strong> ${escapeHtml(data.kelas_pagi_nama || '-')}</div>
                        <div><strong>Kelas Diniyyah:</strong> ${escapeHtml(data.kelas_diniyyah_nama || '-')}</div>
                        <div><strong>Bagian Diniyyah:</strong> ${escapeHtml(data.bagian || '-')}</div>
                        <div><strong>Tingkat Diniyyah:</strong> ${escapeHtml(data.tingkat || '-')}</div>
                        <div><strong>Nama Ayah:</strong> ${escapeHtml(data.nama_ayah || '-')}</div>
                        <div><strong>Pekerjaan Ayah:</strong> ${escapeHtml(data.pekerjaan_ayah || '-')}</div>
                        <div><strong>Nama Ibu:</strong> ${escapeHtml(data.nama_ibu || '-')}</div>
                        <div><strong>Pekerjaan Ibu:</strong> ${escapeHtml(data.pekerjaan_ibu || '-')}</div>
                        <div class="md:col-span-2"><strong>Foto:</strong> ${data.photo_url ? `<img src="${escapeHtml(data.photo_url)}" class="mt-1 max-w-full max-h-40 rounded shadow">` : '-'}</div>
                    </div>
                `;
                document.getElementById('detailContent').innerHTML = html;
                document.getElementById('detailModal').classList.remove('hidden');
                document.getElementById('detailModal').classList.add('flex');
            } else {
                alert('Gagal mengambil data user');
            }
        })
        .catch(err => console.error(err));
}
function closeDetailModal() {
    document.getElementById('detailModal').classList.add('hidden');
    document.getElementById('detailModal').classList.remove('flex');
}

// Modal Reset Password
let resetPasswordModal = document.getElementById('resetPasswordModal');
function openResetPasswordModal(userId, userName) {
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUserName').value = userName;
    document.getElementById('newPassword').value = '';
    document.getElementById('confirmPassword').value = '';
    resetPasswordModal.classList.remove('hidden');
    resetPasswordModal.classList.add('flex');
}
function closeResetPasswordModal() {
    resetPasswordModal.classList.add('hidden');
    resetPasswordModal.classList.remove('flex');
}

// Modal Ubah Role
let changeRoleModal = document.getElementById('changeRoleModal');
function openChangeRoleModal(userId, userName, currentRole) {
    document.getElementById('changeRoleUserId').value = userId;
    document.getElementById('changeRoleUserName').value = userName;
    document.getElementById('changeRoleCurrentRole').value = currentRole;
    let select = document.getElementById('changeRoleNewRole');
    select.value = '';
    changeRoleModal.classList.remove('hidden');
    changeRoleModal.classList.add('flex');
}
function closeChangeRoleModal() {
    changeRoleModal.classList.add('hidden');
    changeRoleModal.classList.remove('flex');
}

// Modal Tambah/Edit
const modal = document.getElementById('userModal');
function openAddModal() {
    document.getElementById('modalTitle').innerText = 'Tambah User';
    document.getElementById('formAction').value = 'add';
    document.getElementById('userId').value = '';
    document.getElementById('fullName').value = '';
    document.getElementById('nidn').value = '';
    document.getElementById('photoUrl').value = '';
    document.getElementById('nik').value = '';
    document.getElementById('phone').value = '';
    document.getElementById('tahunMasuk').value = '';
    document.getElementById('tempatLahir').value = '';
    document.getElementById('tanggalLahir').value = '';
    document.getElementById('alamat').value = '';
    document.getElementById('kelasPagiId').value = '';
    document.getElementById('kelasDiniyyahId').value = '';
    document.getElementById('bagian').value = '';
    document.getElementById('tingkat').value = '';
    document.getElementById('namaAyah').value = '';
    document.getElementById('pekerjaanAyah').value = '';
    document.getElementById('namaIbu').value = '';
    document.getElementById('pekerjaanIbu').value = '';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function openEditModal(userId) {
    fetch(`api/get_user_detail.php?id=${userId}`)
        .then(res => res.json())
        .then(data => {
            if (data && data.id) {
                document.getElementById('modalTitle').innerText = 'Edit User';
                document.getElementById('formAction').value = 'edit';
                document.getElementById('userId').value = data.id;
                document.getElementById('fullName').value = data.full_name || '';
                document.getElementById('nidn').value = data.nidn_or_nisn || '';
                document.getElementById('photoUrl').value = data.photo_url || '';
                document.getElementById('nik').value = data.nik || '';
                document.getElementById('phone').value = data.phone || '';
                document.getElementById('tahunMasuk').value = data.tahun_masuk || '';
                document.getElementById('tempatLahir').value = data.tempat_lahir || '';
                document.getElementById('tanggalLahir').value = data.tanggal_lahir || '';
                document.getElementById('alamat').value = data.alamat || '';
                document.getElementById('kelasPagiId').value = data.kelas_pagi_id || '';
                document.getElementById('kelasDiniyyahId').value = data.kelas_diniyyah_id || '';
                document.getElementById('bagian').value = data.bagian || '';
                document.getElementById('tingkat').value = data.tingkat || '';
                document.getElementById('namaAyah').value = data.nama_ayah || '';
                document.getElementById('pekerjaanAyah').value = data.pekerjaan_ayah || '';
                document.getElementById('namaIbu').value = data.nama_ibu || '';
                document.getElementById('pekerjaanIbu').value = data.pekerjaan_ibu || '';
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            } else {
                alert('Gagal mengambil data user');
            }
        })
        .catch(err => console.error(err));
}
function closeModal() {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
function confirmDelete(id) {
    if (confirm('Yakin hapus user ini?')) {
        const f = document.createElement('form');
        f.method = 'POST';
        f.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(f);
        f.submit();
    }
}
function openQRModal(userId, userName) {
    document.getElementById('qrUserId').value = userId;
    document.getElementById('qrModal').classList.remove('hidden');
    document.getElementById('qrModal').classList.add('flex');
}
function closeQRModal() {
    document.getElementById('qrModal').classList.add('hidden');
    document.getElementById('qrModal').classList.remove('flex');
}
function openImportModal() {
    document.getElementById('importModal').classList.remove('hidden');
    document.getElementById('importModal').classList.add('flex');
}
function closeImportModal() {
    document.getElementById('importModal').classList.add('hidden');
    document.getElementById('importModal').classList.remove('flex');
}
</script>
<script>
document.getElementById('perPageSelect')?.addEventListener('change', function() {
    var url = new URL(window.location.href);
    url.searchParams.set('per_page', this.value);
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>