<?php
session_start();
require_once 'config.php';

if (!defined('LOTTIE_URL')) {
    define('LOTTIE_URL', 'https://lottie.host/22912782-3d7f-4039-972e-1064d8fa33ee/SiYBAklTWx.json');
}

$domain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
define('APP_DOMAIN', $domain);
define('FOTO_USER_DIR', __DIR__ . '/assets/img/foto_user/');
if (!is_dir(FOTO_USER_DIR)) {
    mkdir(FOTO_USER_DIR, 0755, true);
}

function generate_uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function generate_nisn($tahun_masuk) {
    return $tahun_masuk . str_pad(mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
}

function insert_user_return($data) {
    $url = SUPABASE_URL . '/rest/v1/users';
    $ch = curl_init($url);
    $headers = [
        'apikey: ' . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        $result = json_decode($response, true);
        if (is_array($result) && count($result) > 0 && isset($result[0]['id'])) {
            return $result[0];
        }
        return ['error' => 'Response tidak mengandung id: ' . $response];
    } else {
        return ['error' => "HTTP $httpCode: " . substr($response, 0, 300)];
    }
}

$error = '';
$success = '';
$generated_nisn = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_submit'])) {
    $full_name      = trim($_POST['full_name'] ?? '');
    $tahun_masuk    = trim($_POST['tahun_masuk'] ?? '');
    $nik            = trim($_POST['nik'] ?? '');
    $tempat_lahir   = trim($_POST['tempat_lahir'] ?? '');
    $tanggal_lahir  = trim($_POST['tanggal_lahir'] ?? '');
    $alamat         = trim($_POST['alamat'] ?? '');
    $phone          = trim($_POST['phone'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $password       = $_POST['password'] ?? '';
    $confirm_pass   = $_POST['confirm_password'] ?? '';

    $errors = [];
    if (empty($full_name)) $errors[] = 'Nama lengkap wajib diisi.';
    if (empty($tahun_masuk) || !is_numeric($tahun_masuk) || $tahun_masuk < 1900 || $tahun_masuk > date('Y')+1)
        $errors[] = 'Tahun masuk tidak valid.';
    if (empty($nik) || !preg_match('/^\d{16}$/', $nik)) $errors[] = 'NIK harus 16 digit angka.';
    if (empty($tempat_lahir)) $errors[] = 'Tempat lahir wajib diisi.';
    if (empty($tanggal_lahir)) $errors[] = 'Tanggal lahir wajib diisi.';
    if (empty($alamat)) $errors[] = 'Alamat wajib diisi.';
    if (empty($phone) || !preg_match('/^[0-9]{10,15}$/', $phone)) $errors[] = 'No telepon tidak valid (10-15 digit).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email tidak valid.';
    if (strlen($password) < 6) $errors[] = 'Password minimal 6 karakter.';
    if ($password !== $confirm_pass) $errors[] = 'Password dan konfirmasi tidak cocok.';

    $photo_url = null;
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $errors[] = 'Format foto harus JPG, JPEG, atau PNG.';
        } else {
            $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9]/', '', $nik) . '.' . $ext;
            $target = FOTO_USER_DIR . $filename;
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $target)) {
                $photo_url = APP_DOMAIN . '/siakad/assets/img/foto_user/' . $filename;
            } else {
                $errors[] = 'Gagal menyimpan foto.';
            }
        }
    } else {
        $errors[] = 'Wajib mengambil/upload foto.';
    }

    $nisn = generate_nisn($tahun_masuk);
    if (empty($errors)) {
        $existing_nik = supabase_admin_request('GET', 'users', null, ['nik' => 'eq.' . $nik]);
        if (!empty($existing_nik) && count($existing_nik) > 0) $errors[] = 'NIK sudah terdaftar.';
        $existing_email = supabase_admin_request('GET', 'users', null, ['email' => 'eq.' . $email]);
        if (!empty($existing_email) && count($existing_email) > 0) $errors[] = 'Email sudah terdaftar.';
        $existing_nisn = supabase_admin_request('GET', 'users', null, ['nidn_or_nisn' => 'eq.' . $nisn]);
        if (!empty($existing_nisn) && count($existing_nisn) > 0) {
            $nisn = generate_nisn($tahun_masuk);
        }
    }

    if (empty($errors)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $userData = [
            'id'             => generate_uuid(),
            'full_name'      => $full_name,
            'role'           => 'user',
            'nidn_or_nisn'   => $nisn,
            'tahun_masuk'    => (int)$tahun_masuk,
            'nik'            => $nik,
            'tempat_lahir'   => $tempat_lahir,
            'tanggal_lahir'  => $tanggal_lahir,
            'alamat'         => $alamat,
            'phone'          => $phone,
            'email'          => $email,
            'password_hash'  => $password_hash,
            'photo_url'      => $photo_url,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s')
        ];
        $userData = array_filter($userData, fn($v) => !is_null($v));

        $result = insert_user_return($userData);
        
        if (isset($result['error'])) {
            $errors[] = 'Registrasi gagal: ' . $result['error'];
            if ($photo_url && file_exists(FOTO_USER_DIR . basename($photo_url))) unlink(FOTO_USER_DIR . basename($photo_url));
        } else if (isset($result['id'])) {
            $success = 'Registrasi berhasil!';
            $generated_nisn = $nisn;
            $_POST = [];
        } else {
            $errors[] = 'Registrasi gagal: Response tidak valid.';
            if ($photo_url && file_exists(FOTO_USER_DIR . basename($photo_url))) unlink(FOTO_USER_DIR . basename($photo_url));
        }
    }
    
    if (!empty($errors)) $error = implode('<br>', $errors);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Registrasi Santri - SIPENA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.7.1/dist/dotlottie-wc.js" type="module"></script>
    <style>
        .bg-carousel {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
            overflow: hidden;
        }
        .carousel-slides {
            position: absolute;
            width: 100%;
            height: 100%;
            display: flex;
            transition: transform 0.7s ease-in-out;
        }
        .slide {
            min-width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }
        .overlay-dark {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            z-index: -1;
        }
        .register-container {
            max-width: 750px;
            width: 90%;
            margin: 1rem auto;
            position: relative;
            z-index: 10;
            max-height: 95vh;
            overflow-y: auto;
        }
        .glass-form {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(16px);
            border-radius: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 25px 45px rgba(0, 0, 0, 0.3);
            padding: 1.2rem;
        }
        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            position: relative;
        }
        .progress-steps::before {
            content: '';
            position: absolute;
            top: 16px;
            left: 0;
            width: 100%;
            height: 4px;
            background: rgba(255,255,255,0.3);
            border-radius: 4px;
            z-index: 0;
        }
        .step {
            position: relative;
            z-index: 1;
            text-align: center;
            flex: 1;
        }
        .step-circle {
            width: 32px;
            height: 32px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 6px;
            font-weight: bold;
            color: white;
            border: 2px solid rgba(255,255,255,0.5);
            transition: all 0.3s;
            font-size: 0.85rem;
        }
        .step.active .step-circle {
            background: linear-gradient(135deg, #8b5cf6, #6366f1);
            border-color: white;
            box-shadow: 0 0 0 3px rgba(139,92,246,0.5);
        }
        .step.completed .step-circle {
            background: #10b981;
            border-color: white;
        }
        .step-label {
            font-size: 0.7rem;
            color: rgba(255,255,255,0.8);
            font-weight: 500;
        }
        .step.active .step-label {
            color: white;
            font-weight: 600;
        }
        .progress-fill {
            position: absolute;
            top: 16px;
            left: 0;
            height: 4px;
            background: linear-gradient(90deg, #8b5cf6, #c084fc);
            border-radius: 4px;
            transition: width 0.3s ease;
            z-index: 1;
        }
        .step-content { display: none; animation: fadeIn 0.4s ease; }
        .step-content.active-step { display: block; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .input-floating {
            position: relative;
            margin-bottom: 1.2rem;
        }
        .input-floating input, .input-floating textarea, .input-floating select {
            width: 100%;
            padding: 0.9rem 0.8rem 0.4rem 0.8rem;
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(255,255,255,0.4);
            border-radius: 1rem;
            font-size: 0.9rem;
            transition: 0.2s;
            color: #1f2937;
        }
        .input-floating textarea { resize: vertical; min-height: 70px; }
        .input-floating input:focus, .input-floating textarea:focus, .input-floating select:focus {
            outline: none;
            border-color: #8b5cf6;
            background: white;
            box-shadow: 0 0 0 3px rgba(139,92,246,0.2);
        }
        .input-floating label {
            position: absolute;
            left: 0.8rem;
            top: 50%;
            transform: translateY(-50%);
            color: #4b5563;
            pointer-events: none;
            transition: 0.2s;
            background: transparent;
            font-size: 0.85rem;
        }
        .input-floating textarea ~ label {
            top: 0.8rem;
            transform: none;
        }
        .input-floating input:focus ~ label,
        .input-floating input:not(:placeholder-shown) ~ label,
        .input-floating textarea:focus ~ label,
        .input-floating textarea:not(:placeholder-shown) ~ label,
        .input-floating select:focus ~ label,
        .input-floating select:not([value=""]) ~ label {
            top: 0.1rem;
            left: 0.6rem;
            font-size: 0.65rem;
            color: #8b5cf6;
            background: rgba(255,255,255,0.8);
            padding: 0 4px;
            border-radius: 12px;
        }
        .photo-preview {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.6rem;
            margin: 0.8rem 0;
        }
        .preview-img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            background: #e2e8f0;
        }
        .photo-btn {
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            border-radius: 2rem;
            padding: 0.4rem 1rem;
            font-size: 0.85rem;
            cursor: pointer;
            transition: 0.2s;
            color: white;
            display: inline-block;
        }
        .photo-btn:hover { background: rgba(0,0,0,0.7); }
        .btn-nav {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.4);
            border-radius: 2rem;
            padding: 0.4rem 1rem;
            font-weight: 500;
            transition: 0.2s;
            font-size: 0.85rem;
        }
        .btn-nav:hover { background: rgba(255,255,255,0.4); }
        .btn-submit {
            background: linear-gradient(135deg, #8b5cf6, #6366f1);
            border: none;
            border-radius: 2rem;
            padding: 0.5rem 1.5rem;
            font-weight: 600;
            font-size: 0.9rem;
            transition: 0.3s;
        }
        .btn-submit:hover { transform: scale(1.02); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .success-box {
            background: rgba(16, 185, 129, 0.2);
            border: 1px solid #10b981;
            border-radius: 1rem;
            padding: 0.8rem;
            text-align: center;
        }
        .register-container::-webkit-scrollbar { width: 5px; }
        .register-container::-webkit-scrollbar-track { background: rgba(255,255,255,0.2); border-radius: 5px; }
        .register-container::-webkit-scrollbar-thumb { background: #8b5cf6; border-radius: 5px; }
        @media (min-width: 768px) {
            .register-container { margin: 1.5rem auto; }
            .glass-form { padding: 1.8rem; }
            .step-circle { width: 40px; height: 40px; font-size: 1rem; }
            .step-label { font-size: 0.75rem; }
            .progress-steps::before, .progress-fill { top: 20px; }
            .preview-img { width: 100px; height: 100px; }
            .input-floating input, .input-floating textarea, .input-floating select {
                padding: 1rem 1rem 0.5rem 1rem;
                font-size: 1rem;
            }
            .input-floating label { font-size: 0.95rem; left: 1rem; }
            .input-floating input:focus ~ label,
            .input-floating input:not(:placeholder-shown) ~ label {
                top: 0.2rem;
                left: 0.8rem;
                font-size: 0.7rem;
            }
        }
    </style>
</head>
<body>

<div class="bg-carousel">
    <div class="carousel-slides" id="carouselSlides">
        <div class="slide" style="background-image: url('https://lh3.googleusercontent.com/rd-d/ALs6j_GJMeeuiC4rVYS5c9kU9Jmv1vi0P01AuE3UFGcGj6ixeAsWvB1lEGuwYw7N1JlFElROrUaprG8seIRz5OJWsMZQUkQ30RdnxcRyR066pNy2BIWigB6EFLmr2RWXxgpwLQiDUL47jfHBji7NYzvpRUEhFSacHS7gQJ6S_lJMaL1Gt3qG1WJFUQYvgkiTjBZXZboZUK4VDUMlrJ00MqWNFn-gn_dLFbGpFwhLRspfDnPeprZGWCYxucml6j3g4AnS1rInFjvpLbqRwdXpyXfT5UPm_dmnz9ZdCFL17cYbZtFZVp0xTdgRleMbBC9NWqOJxPfKXgF82-pyPwShNl0YMeBWKEKrpNa-56XxcQ3ML14gv4fPS_yOjp8hUGDagvYG7W6-yrBNasnNdHEUlOl1O81cMpupEgqOMtkpp8e1OBFsQWUyuHxh7d3DkegU2KbrrIbGvs0L-9gJpX2w-Vu52q3PwV--gAV-zy8j1RZpdZLVOdPl-y6gl6x5adpTiXLqvu8ZmXkyHxwjTe4E6oYg0a5IA1VHuIwik-p9dHxqwKdUCOWmDSW-5InXBwn2szutv53AbqFvehxPXyTQSrPA9hoPjezts8dQfxTb5vek21Zu-Xb79k1o7NZ3qyRdFnq9qGDDlzJij9lU4ilyHLYnjJ_k8cyh7ZF27fCHiayhkSNKgH3JQZlNnzotmwuCMzgD391RTxDn8H0P7pEg9_e68qNNnaXDk5-ecY9MwyneDlKqC5D0r3TUFEBKeGzjBt3rOCUlVcrIiG_8dNwfGJkaeOexikkBaX5rEJCkGG4BO4csi4f4aYpNBkTJmqYXgJFxVgfZvjsnQaO69cNkOSdBOddBnA8TQT951op36CKkElbXrPrZE-ZB-CG6aT3O_feoH1KZOSC4Wl9pyalXLFQBhKm8K-MiWbQRVzXs7U8Lwa9XE7rHNmw1p8GIXWKgCi8b1L1RNOOiXOJWvEJV30bbmfwNWnhMvyzDf-pWC6nm7gWIOJQxXRtq1JzvZ3CBGHLW9Ec5cPnJ-qHBO8YIX50sc1jHpOrK6OZin9Ltt7qVpJlRxv2UiXpGZGndCyselqql41kOWORPh65aAKxjthL_WC-0TqLJ-cJHtykcG5OtWXJHFcj9IRhaZOADFNmApLz1nGvhQCZ2E1o_Jjghk342WW5kzqFaph6gd_1YjVKp5DvUE6-U2iG_CqQHQGqwYxGWQwpwaSn3nCI7VuRPI24p5J6uF6Uv1PB8Y7sI90afmaeIZEHUercAAFBRiEX--UWuIsvorxqXCTcDw5PlgpwHhnpNF0WkeCXPx112QZzQQfQ');"></div>
        <div class="slide" style="background-image: url('https://lh3.googleusercontent.com/rd-d/ALs6j_EpMtyze9An_EJHSEcBZBRhST1zpJh1N16PFWVfVKPIn06Spe2KJUTvKsQvxKl7jqO7-r09tuqBjvEpwawyyj5TM7zaeEhtuuFgf-v8kxytJNCf3CrrgbNmr_Cdp3Guu2-DbiDLTGD9EBw8S5SL2gK-IhaWqAoRpLYMUrp5n1fTwigN1oHooxh6Q2jNjpRzgJHMOjendpNxu1p5BErdyMbSC3HqTiwDnQMWrocACADLfX4nCqhNWzuX_6JNQTofAuHCHCoLqNQ80T1DVruV0Y84kcbq6fYsHQOXhdT76JhpXkwetHiRmN-F9RIFQFPIeirW0CgM0RfbRF-dR1mmjghPwi0kvC_jIafqXjWL2Dfd-mIw42iqI3accJfj-IeUf2JNzwgBkKHh3HApcPtrY71P3GROqgzXX5NAmGtJZew4CHFOm8PZN9HmdNIPuC2WjU3YFd7eyN1QCpu9iKhcXiGycvlBn7MX6VQTQCnwEZV2RS5uJpDx2bTGWeFO2eRctJqO_tj0RPQxO1NxwWAzYpezT5aEtL8OLTlKNe884BQlQUruM6dmj4Ia9uB1aMU9G6Lj2dwGGEjYtttrCLh9gwvXpiDwcHdRKhQh6SQRThaol7pL3QhA5vNWrx_okjnT9UkmholJdF4_glJAxrvZu9kzHIosJ9WrbIY3TjXj_go7nj7EIviLxSL58Hyp8NxybQuIFl5EtCbDQtzs5JD-lxS1Pj9JKEa-CHUjLp7Omwq8yRI9egVIj0aMy_V_Wj710OpT32DfdJDs6RoS3zfiJ5iMj5FSh8Zt7QXdJy3RTrBNhxzZKJ3mpSoG-GNV5aLOG1O4E9qq7cm6kiL6n9NpHDXV7TSSbv85iGkw31px_II-VlugA5-CmFDBo-dV78ScXQQALQoYhIMOkWJO3FfMTr1_TsWsrXWyd9W8GBlFPCCjxH7-Q2cBeX3pFiJHTXcbkakdlws3B0Jp7dPmtfDI5g2mPQNjgRXxBGmEH9Ez5C8IpU5K-GimHJkPlXU3kvvCqDf2kmAwnWpAMF45t2L3-gtTtlwnmNxvEjSucg3m7O8nebSWc6TabuAoLHb7iPFypy1G3U87Mt5aI1ckUyNrhvjuYcqDNYGKDGz3CO1QLgFIgKjOTLgtiMQJ30-Imr_vAQJMffrxra-kMM9p-3-tKENUrV0llM7TKXax6VpXEXzF-KYjMuytE5ACiF6VgtKuHaK-JUdfHIYlemfeNxp2iohH_i69PQolovVPfa82IFE8MzcldqiZgdsWJ3329ZPpxQju1HhyGor_J_BatwI_8OPwet6NfJAUJz31kWgXAMA');"></div>
        <div class="slide" style="background-image: url('https://lh3.googleusercontent.com/rd-d/ALs6j_GJ_kkcuahopM7bbVtzOzrA_8WqIAtxsPGKx2ynC47Q1HEn1sqpNbQqEslvXbzeDYnxuoa3GnvpimBt9Uz2ForVrbWLVbCpkiBJnWymgTars93yfB63kcNm2xbsI6f9XqoGLq1BHJXFXuWialF8qQfXIzijuwyt6wDQgtzMFnV0NjJRT0aD1vkKmUKJZ6yEFc7faqTYf3ftYUPQMSniFvyohZ67SyJpkunfhAzqMLwOWa_fs2qq5hD55F_VOoQV8mQCCN_aJy7OUrs8Oqb3sfFTrvC2bFBBkjzJZfpLmr5EQfk-BFc0BMPdJPmLNGs6yfWiBNaqZFmd55f1cALqx4xkNeqxcvcBmKbx2XEe4CwrF8ayz_le6JbtzSjxRf-S4aDN46zo76yLPzylLHQ_IcPpajjUoHNIIGVpyHL209zKF5Y9AHUz_rfCZvK6BPAiLaxFHxUc-5smDOKtf5WR01ZjZAp0-DIUnZcYKziFR1-9hyyOwB2yghhEYSCmJzF6sNjURoCwSPjtcsnlgoHlOh0SpxX6zxSYWR4bPdRIv69lrcNoUK3wX13h9DNkiXmf4v41RQBlUDVFOTysHEfv6v2J1QwDwnfchIL57AB0J3Jy4yRw7c15tmJEAcLrg1Vl-RtYqkLKZ8ru7vdplus3OguMZ_jlYiKEn0MwrghIFKSLeyhU_i1t1zMYa-sTCVx4AJqR2W-DWDYkEBxelpihzyTO5CDIptJu_MdE5SFrudyCFNxqMOrxNexShIaXQ2qCkMPdnyBGdOMjj2qaBhqqj7e95_vayEimEbloQqhCIi1aGKg7VJM96PGKenKB8flLQ5RhsBzH75FhaQ33Z03-a06_qtdgF-QXsMG_qEKCdn00ovaKFjsAu39MvNy_RzYvCPLsxI2GZWBA3e1vRIY8p0AVe6SwQAj1VRli_UfgQvUBnSLehYmmdIt-v26c3Y2Fovhi7upE-IU5S5ixkqekOg0YIofb6y36M3c0K9cEkY0IDQ05-gYYmFKgDSbmZ8sMtAGM4YCNA1fL8zIDEmXu9hwkX99nSkN0WHpoTKpVRZHnoIdktS7nc-ZiP9qUdEfFsaINhIeiAF9a3UVa4kLnozTPdd-oj5nPIfQF0fd8MbHTP3BmR-smXDV5C6Y-n0vH1JVu8nkBdqA1t1wB7RiSPVJMGfTeO084gpo-8SpRU1tiV9mZZ8gaJJ-CVB1JKG-IX4TdJOWGW3kCboJfFSJI-IENBnJ5knbxqIUAB-pR2eCoGxNeJPpEVstaxEwKSIf3Xf7vBtLN_3sGyUBrKS9mu2rDWvChh8a7gVG5qgjAg6A');"></div>
        <div class="slide" style="background-image: url('https://images.unsplash.com/photo-1580582932707-520aed937b7b?w=1600');"></div>
    </div>
</div>
<div class="overlay-dark"></div>

<div class="register-container">
    <div class="glass-form">
        <div class="flex justify-center mb-1">
            <dotlottie-wc src="<?= LOTTIE_URL ?>" speed="0.6" style="width: 70px; height: 70px" loop autoplay></dotlottie-wc>
        </div>
        <h1 class="text-xl md:text-2xl font-bold text-center text-white">Daftar Akun User</h1>
        <p class="text-center text-gray-200 mb-3 text-sm">Isi data diri dengan lengkap</p>

        <?php if ($error): ?>
            <div class="bg-red-500/30 backdrop-blur border-l-4 border-red-500 text-white p-3 rounded-xl mb-4 text-sm"><?= $error ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-box text-white mb-4">
                <i class="fas fa-check-circle text-3xl mb-2 text-green-400"></i>
                <p class="font-bold"><?= htmlspecialchars($success) ?></p>
                <p class="mt-2">Gunakan NIS/NIDN berikut untuk login:</p>
                <p class="text-xl font-mono bg-black/30 inline-block px-4 py-2 rounded-lg mt-1"><?= htmlspecialchars($generated_nisn) ?></p>
                <div class="mt-4"><a href="login.php" class="bg-white/20 hover:bg-white/30 px-4 py-2 rounded-full inline-block">← Kembali ke Login</a></div>
            </div>
        <?php else: ?>
            <form method="POST" enctype="multipart/form-data" id="multiStepForm">
                <input type="hidden" name="register_submit" value="1">
                
                <div class="progress-steps mb-5">
                    <div class="progress-fill" id="progressFill" style="width: 0%;"></div>
                    <div class="step active" data-step="1"><div class="step-circle">1</div><div class="step-label">Data Diri</div></div>
                    <div class="step" data-step="2"><div class="step-circle">2</div><div class="step-label">Lahir & Alamat</div></div>
                    <div class="step" data-step="3"><div class="step-circle">3</div><div class="step-label">Kontak & Foto</div></div>
                    <div class="step" data-step="4"><div class="step-circle">4</div><div class="step-label">Password</div></div>
                </div>

                <div class="step-content active-step" id="step1">
                    <div class="input-floating">
                        <input type="text" id="full_name" name="full_name" placeholder=" " required value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                        <label>Nama Lengkap <span class="text-red-400">*</span></label>
                    </div>
                    <div class="input-floating">
                        <input type="number" id="tahun_masuk" name="tahun_masuk" placeholder=" " required min="1900" max="<?= date('Y')+1 ?>" value="<?= htmlspecialchars($_POST['tahun_masuk'] ?? date('Y')) ?>">
                        <label>Tahun Masuk <span class="text-red-400">*</span></label>
                    </div>
                    <div class="input-floating">
                        <input type="text" id="nik" name="nik" placeholder=" " required maxlength="16" pattern="\d{16}" value="<?= htmlspecialchars($_POST['nik'] ?? '') ?>">
                        <label>NIK (16 digit) <span class="text-red-400">*</span></label>
                    </div>
                </div>

                <div class="step-content" id="step2">
                    <div class="input-floating">
                        <input type="text" id="tempat_lahir" name="tempat_lahir" placeholder=" " required value="<?= htmlspecialchars($_POST['tempat_lahir'] ?? '') ?>">
                        <label>Tempat Lahir <span class="text-red-400">*</span></label>
                    </div>
                    <div class="input-floating">
                        <input type="date" id="tanggal_lahir" name="tanggal_lahir" placeholder=" " required value="<?= htmlspecialchars($_POST['tanggal_lahir'] ?? '') ?>">
                        <label>Tanggal Lahir <span class="text-red-400">*</span></label>
                    </div>
                    <div class="input-floating">
                        <textarea id="alamat" name="alamat" placeholder=" " required><?= htmlspecialchars($_POST['alamat'] ?? '') ?></textarea>
                        <label>Alamat Lengkap <span class="text-red-400">*</span></label>
                    </div>
                </div>

                <div class="step-content" id="step3">
                    <div class="input-floating">
                        <input type="tel" id="phone" name="phone" placeholder=" " required pattern="[0-9]{10,15}" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                        <label>No Telepon <span class="text-red-400">*</span></label>
                    </div>
                    <div class="input-floating">
                        <input type="email" id="email" name="email" placeholder=" " required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        <label>Email <span class="text-red-400">*</span></label>
                    </div>
                    <div class="photo-preview">
                        <img id="preview" class="preview-img" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='%23cccccc' viewBox='0 0 24 24'%3E%3Cpath d='M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z'/%3E%3C/svg%3E" alt="Preview">
                        <label for="foto" class="photo-btn"><i class="fas fa-camera mr-2"></i>Ambil / Upload Foto</label>
                        <input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/jpg" capture="environment" style="display: none;" required>
                        <p class="text-xs text-gray-200">Klik tombol untuk mengambil foto</p>
                    </div>
                </div>

                <div class="step-content" id="step4">
                    <div class="input-floating">
                        <input type="password" id="password" name="password" placeholder=" " required>
                        <label>Password (min 6 karakter) <span class="text-red-400">*</span></label>
                    </div>
                    <div class="input-floating">
                        <input type="password" id="confirm_password" name="confirm_password" placeholder=" " required>
                        <label>Konfirmasi Password <span class="text-red-400">*</span></label>
                    </div>
                </div>

                <div class="flex justify-between mt-4 gap-2">
                    <button type="button" id="prevBtn" class="btn-nav text-white rounded-full flex items-center gap-1"><i class="fas fa-arrow-left"></i> <span class="hidden sm:inline">Sebelumnya</span></button>
                    <button type="button" id="nextBtn" class="btn-nav text-white rounded-full flex items-center gap-1"><span class="hidden sm:inline">Selanjutnya</span> <i class="fas fa-arrow-right"></i></button>
                    <button type="submit" id="submitBtn" class="btn-submit text-white rounded-full flex items-center gap-1" style="display: none;"><i class="fas fa-check-circle"></i> <span>Daftar</span></button>
                </div>
            </form>
            <div class="text-center mt-4">
                <a href="login.php" class="text-gray-200 hover:text-white text-xs"><i class="fas fa-sign-in-alt mr-1"></i> Sudah punya akun? Login</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const slidesContainer = document.getElementById('carouselSlides');
    const slides = document.querySelectorAll('.slide');
    let currentBgIndex = 0;
    if (slidesContainer && slides.length) {
        setInterval(() => {
            currentBgIndex = (currentBgIndex + 1) % slides.length;
            slidesContainer.style.transform = `translateX(-${currentBgIndex * 100}%)`;
        }, 5000);
    }

    const steps = document.querySelectorAll('.step');
    const stepContents = document.querySelectorAll('.step-content');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const submitBtn = document.getElementById('submitBtn');
    const progressFill = document.getElementById('progressFill');
    let currentStep = 1;
    const totalSteps = 4;

    function updateStepUI() {
        steps.forEach((step, idx) => {
            const stepNum = idx + 1;
            if (stepNum < currentStep) {
                step.classList.add('completed');
                step.classList.remove('active');
            } else if (stepNum === currentStep) {
                step.classList.add('active');
                step.classList.remove('completed');
            } else {
                step.classList.remove('active', 'completed');
            }
        });
        stepContents.forEach((content, idx) => {
            if (idx + 1 === currentStep) content.classList.add('active-step');
            else content.classList.remove('active-step');
        });
        const percent = ((currentStep - 1) / (totalSteps - 1)) * 100;
        progressFill.style.width = percent + '%';
        if (currentStep === totalSteps) {
            nextBtn.style.display = 'none';
            submitBtn.style.display = 'flex';
        } else {
            nextBtn.style.display = 'flex';
            submitBtn.style.display = 'none';
        }
        prevBtn.style.visibility = currentStep === 1 ? 'hidden' : 'visible';
    }

    function validateStep(step) {
        if (step === 1) {
            const nama = document.getElementById('full_name').value.trim();
            const tahun = document.getElementById('tahun_masuk').value.trim();
            const nik = document.getElementById('nik').value.trim();
            if (!nama) { alert('Nama lengkap wajib diisi'); return false; }
            if (!tahun || isNaN(tahun) || tahun < 1900 || tahun > new Date().getFullYear()+1) { alert('Tahun masuk tidak valid'); return false; }
            if (!nik || !/^\d{16}$/.test(nik)) { alert('NIK harus 16 digit angka'); return false; }
            return true;
        }
        if (step === 2) {
            const tempat = document.getElementById('tempat_lahir').value.trim();
            const tgl = document.getElementById('tanggal_lahir').value.trim();
            const alamat = document.getElementById('alamat').value.trim();
            if (!tempat) { alert('Tempat lahir wajib diisi'); return false; }
            if (!tgl) { alert('Tanggal lahir wajib diisi'); return false; }
            if (!alamat) { alert('Alamat wajib diisi'); return false; }
            return true;
        }
        if (step === 3) {
            const phone = document.getElementById('phone').value.trim();
            const email = document.getElementById('email').value.trim();
            const foto = document.getElementById('foto').files.length;
            if (!phone || !/^[0-9]{10,15}$/.test(phone)) { alert('No telepon harus 10-15 digit angka'); return false; }
            if (!email || !/^\S+@\S+\.\S+$/.test(email)) { alert('Email tidak valid'); return false; }
            if (!foto) { alert('Wajib mengambil/upload foto'); return false; }
            return true;
        }
        if (step === 4) {
            const pass = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            if (pass.length < 6) { alert('Password minimal 6 karakter'); return false; }
            if (pass !== confirm) { alert('Password dan konfirmasi tidak cocok'); return false; }
            return true;
        }
        return true;
    }

    nextBtn.addEventListener('click', () => {
        if (validateStep(currentStep)) {
            if (currentStep < totalSteps) {
                currentStep++;
                updateStepUI();
            }
        }
    });
    prevBtn.addEventListener('click', () => {
        if (currentStep > 1) {
            currentStep--;
            updateStepUI();
        }
    });
    updateStepUI();

    const fotoInput = document.getElementById('foto');
    const previewImg = document.getElementById('preview');
    fotoInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(ev) { previewImg.src = ev.target.result; };
            reader.readAsDataURL(file);
        } else {
            previewImg.src = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='%23cccccc' viewBox='0 0 24 24'%3E%3Cpath d='M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z'/%3E%3C/svg%3E";
        }
    });

    const form = document.getElementById('multiStepForm');
    form.addEventListener('submit', () => {
        const btn = document.querySelector('.btn-submit');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
        btn.disabled = true;
    });
</script>
</body>
</html>