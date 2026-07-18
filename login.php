<?php
session_start();
require_once 'config.php';

//lottie
if (!defined('LOTTIE_URL')) {
    define('LOTTIE_URL', 'https://lottie.host/22912782-3d7f-4039-972e-1064d8fa33ee/SiYBAklTWx.json');
}

//carousel
$carouselData = [];
$carouselFile = __DIR__ . '/data/carousel.json';
if (file_exists($carouselFile)) {
    $json = file_get_contents($carouselFile);
    $carouselData = json_decode($json, true);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $user_data = supabase_admin_request('GET', 'users', null, ['nidn_or_nisn' => 'eq.' . $username]);
    
    if (!empty($user_data) && isset($user_data[0])) {
        $user = $user_data[0];
        if (password_verify($password, $user['password_hash'] ?? '')) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_photo'] = $user['photo_url'] ?? null;
            $_SESSION['login_time'] = time();
            
            // Redirect berdasarkan role
            if ($user['role'] == 'admin') {
                header('Location: admin_dashboard.php');
            } elseif ($user['role'] == 'teacher') {
                header('Location: dashboard_guru.php');
            } elseif ($user['role'] == 'user') {
                header('Location: dashboard_user.php');
            } else {
                // Default untuk student
                header('Location: dashboard_siswa.php');
            }
            exit;
        } else {
            $error = 'Password salah!';
        }
    } else {
        $error = 'Username tidak ditemukan!';
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Login - SIAKAD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.7.1/dist/dotlottie-wc.js" type="module"></script>
    <style>
        body {
            background: linear-gradient(-45deg, #1a1a2e, #16213e, #0f3460, #533483);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            font-family: 'Inter', system-ui, sans-serif;
            margin: 0;
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .login-container {
            max-width: 1300px;
            width: 100%;
            margin: 0 auto;
            display: flex;
            flex-direction: row;
            gap: 2rem;
            align-items: center;
        }
        .form-col {
            flex: 1;
            max-width: 380px;
            margin: 0 auto;
        }
        .carousel-col {
            flex: 1;
        }
        .glass-form {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(12px);
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 25px 45px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease;
            width: 100%;
        }
        .input-floating {
            position: relative;
            margin-bottom: 1.5rem;
        }
        .input-floating input {
            width: 100%;
            padding: 1rem 1rem 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 1rem;
            font-size: 1rem;
            transition: all 0.2s;
            color: #1f2937;
        }
        .input-floating input:focus {
            outline: none;
            border-color: #8b5cf6;
            background: white;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.3);
        }
        .input-floating label {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            pointer-events: none;
            transition: 0.2s ease all;
            background: transparent;
            font-size: 1rem;
        }
        .input-floating input:focus ~ label,
        .input-floating input:not(:placeholder-shown) ~ label {
            top: 0.2rem;
            left: 0.8rem;
            font-size: 0.7rem;
            color: #8b5cf6;
            background: rgba(255,255,255,0.8);
            border-radius: 20px;
            backdrop-filter: blur(4px);
        }
        .dark .input-floating input {
            background: rgba(31, 41, 55, 0.8);
            color: #f3f4f6;
            border-color: #4b5563;
        }
        .dark .input-floating input:focus {
            background: #1f2937;
        }
        .dark .input-floating label {
            color: #9ca3af;
        }
        .dark .input-floating input:focus ~ label,
        .dark .input-floating input:not(:placeholder-shown) ~ label {
            color: #a78bfa;
            background: rgba(0,0,0,0.6);
        }
        .btn-login {
            background: linear-gradient(135deg, #8b5cf6, #6366f1);
            border: none;
            border-radius: 1rem;
            font-weight: 600;
            font-size: 1rem;
            padding: 0.85rem;
            transition: all 0.3s;
            width: 100%;
        }
        .btn-login:hover {
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            transform: scale(1.02);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }
        .carousel-card {
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(8px);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 20px 35px rgba(0, 0, 0, 0.2);
            position: relative;
            width: auto;
            aspect-ratio: 4 / 2.5;
            max-height: 100%;
            min-height: 400px;
        }
        .carousel-container {
            position: relative;
            width: 100%;
            height: 100%;
            overflow: hidden;
            cursor: grab;
        }
        .carousel-container:active {
            cursor: grabbing;
        }
        .carousel-track {
            display: flex;
            transition: transform 0.5s ease;
            height: 100%;
        }
        .carousel-slide {
            flex: 0 0 100%;
            background-size: cover;
            background-position: center;
            height: 100%;
        }
        .carousel-indicators {
            position: absolute;
            bottom: 1rem;
            left: 0;
            right: 0;
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            z-index: 20;
        }
        .dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 9999px;
            background-color: rgba(255,255,255,0.3);
            transition: all 0.2s;
            cursor: pointer;
        }
        .dot.active {
            background-color: rgba(255,255,255,0.8);
            width: 1rem;
        }
        /* Responsif untuk tablet */
        @media (max-width: 1024px) and (min-width: 769px) {
            .login-container {
                gap: 1.5rem;
            }
            .form-col {
                max-width: 380px;
            }
            .carousel-card {
                max-height: 500px;
                min-height: 350px;
            }
            .lottie-container dotlottie-wc {
                width: 160px !important;
                height: 160px !important;
            }
        }
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }
            .login-container {
                flex-direction: column;
                gap: 2rem;
            }
            .carousel-col {
                display: none;
            }
            .form-col {
                max-width: 100%;
            }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }
        .lottie-container {
            text-align: center;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="form-col">
            <div class="lottie-container">
                <dotlottie-wc 
                    src="<?= LOTTIE_URL ?>" 
                    speed="0.5" 
                    style="justify-self: anchor-center; width: 200px; height: 200px" 
                    mode="forward" 
                    loop 
                    autoplay>
                </dotlottie-wc>
            </div>
            <div class="glass-form p-6 md:p-8 flex flex-col animate-fade-in-up">
                <h1 class="text-3xl font-bold text-center text-white mb-2">SIPENA</h1>
                <p class="text-center text-gray-200 mb-6">Sistem Informasi Pendidikan Santri</p>

                <?php if ($error): ?>
                    <div class="bg-red-500/20 backdrop-blur-sm border-l-4 border-red-500 text-white p-3 rounded-lg mb-6 text-sm flex items-center gap-2">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?= $error ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-4">
                    <div class="input-floating">
                        <input type="text" id="username" name="username" placeholder=" " required autocomplete="off" autofocus>
                        <label for="username">NIS / NIDN</label>
                    </div>
                    <div class="input-floating">
                        <input type="password" id="password" name="password" placeholder=" " required>
                        <label for="password">Password</label>
                    </div>
                    <button type="submit" class="btn-login text-white py-3 rounded-xl text-lg flex items-center justify-center gap-2">
                        <i class="fas fa-sign-in-alt"></i> Masuk
                    </button>
                </form>
                <!-- Tambahkan baris berikut untuk link registrasi -->
                <div class="mt-4 text-center">
                    <a href="regristrasi.php" class="text-gray-200 hover:text-white text-sm transition flex items-center justify-center gap-1">
                        <i class="fas fa-user-plus"></i> Belum punya akun? Daftar di sini
                    </a>
                </div>

                <div class="mt-6 text-center text-xs text-gray-300">
                    <p>© <?= date('Y') ?> SIPENA - Pondok Pesantren</p>
                </div>
            </div>
        </div>

        <div class="carousel-col hidden md:block">
            <div class="carousel-card">
                <div class="carousel-container" id="carouselContainer">
                    <div class="carousel-track" id="carouselTrack">
                        <?php foreach ($carouselData as $slide): ?>
                            <div class="carousel-slide" style="background-image: url('<?= htmlspecialchars($slide['image']) ?>');"></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="carousel-indicators" id="carouselIndicators"></div>
                <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent pointer-events-none"></div>
                <div class="absolute bottom-6 left-6 right-6 text-white z-10 pointer-events-none">
                    <p class="text-lg font-semibold drop-shadow-md" id="carouselCaption"><?= htmlspecialchars($carouselData[0]['caption'] ?? 'Belajar untuk Masa Depan') ?></p>
                    <p class="text-sm drop-shadow-md" id="carouselSubCaption"><?= htmlspecialchars($carouselData[0]['sub_caption'] ?? 'Pondok Pesantren Modern') ?></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Data caption dari PHP untuk update dinamis
        const captions = <?= json_encode(array_column($carouselData, 'caption')) ?>;
        const subCaptions = <?= json_encode(array_column($carouselData, 'sub_caption')) ?>;
        const captionEl = document.getElementById('carouselCaption');
        const subCaptionEl = document.getElementById('carouselSubCaption');

        // Carousel dengan swipe (touch & mouse)
        (function() {
            const track = document.getElementById('carouselTrack');
            const container = document.getElementById('carouselContainer');
            const indicatorsContainer = document.getElementById('carouselIndicators');
            if (!track || !container) return;

            const slides = Array.from(track.children);
            const slideCount = slides.length;
            let currentIndex = 0;
            let startX = 0;
            let currentTranslate = 0;
            let isDragging = false;
            let startTransform = 0;

            function updateCaption(index) {
                if (captionEl && captions[index]) captionEl.innerText = captions[index];
                if (subCaptionEl && subCaptions[index]) subCaptionEl.innerText = subCaptions[index];
            }

            function createIndicators() {
                indicatorsContainer.innerHTML = '';
                for (let i = 0; i < slideCount; i++) {
                    const dot = document.createElement('div');
                    dot.classList.add('dot');
                    if (i === currentIndex) dot.classList.add('active');
                    dot.addEventListener('click', () => goToSlide(i));
                    indicatorsContainer.appendChild(dot);
                }
            }

            function updateIndicators() {
                const dots = indicatorsContainer.querySelectorAll('.dot');
                dots.forEach((dot, i) => {
                    if (i === currentIndex) {
                        dot.classList.add('active');
                    } else {
                        dot.classList.remove('active');
                    }
                });
            }

            function goToSlide(index) {
                if (index < 0) index = 0;
                if (index >= slideCount) index = slideCount - 1;
                currentIndex = index;
                const newTransform = -currentIndex * 100;
                track.style.transform = `translateX(${newTransform}%)`;
                currentTranslate = newTransform;
                updateIndicators();
                updateCaption(currentIndex);
            }

            function nextSlide() {
                if (currentIndex < slideCount - 1) {
                    goToSlide(currentIndex + 1);
                } else {
                    goToSlide(0);
                }
            }

            function onDragStart(e) {
                e.preventDefault();
                isDragging = true;
                startX = e.type.includes('mouse') ? e.pageX : e.touches[0].clientX;
                startTransform = currentTranslate;
                track.style.transition = 'none';
            }

            function onDragMove(e) {
                if (!isDragging) return;
                const currentX = e.type.includes('mouse') ? e.pageX : e.touches[0].clientX;
                const diff = currentX - startX;
                const percentMove = (diff / container.clientWidth) * 100;
                let newTranslate = startTransform + percentMove;
                if (newTranslate > 0) newTranslate = 0;
                if (newTranslate < -(slideCount - 1) * 100) newTranslate = -(slideCount - 1) * 100;
                track.style.transform = `translateX(${newTranslate}%)`;
            }

            function onDragEnd(e) {
                if (!isDragging) return;
                isDragging = false;
                track.style.transition = 'transform 0.5s ease';
                const finalTranslate = parseFloat(track.style.transform.replace('translateX(', '').replace('%)', '')) || 0;
                const threshold = 15;
                const expectedIndex = Math.round(-finalTranslate / 100);
                if (Math.abs(finalTranslate + currentIndex * 100) > threshold) {
                    goToSlide(expectedIndex);
                } else {
                    goToSlide(currentIndex);
                }
            }

            container.addEventListener('mousedown', onDragStart);
            window.addEventListener('mousemove', onDragMove);
            window.addEventListener('mouseup', onDragEnd);
            container.addEventListener('touchstart', onDragStart);
            window.addEventListener('touchmove', onDragMove);
            window.addEventListener('touchend', onDragEnd);

            let autoSlideInterval;
            function startAutoSlide() {
                if (autoSlideInterval) clearInterval(autoSlideInterval);
                autoSlideInterval = setInterval(() => {
                    if (!isDragging) nextSlide();
                }, 5000);
            }
            startAutoSlide();

            window.addEventListener('mouseup', () => setTimeout(startAutoSlide, 100));
            window.addEventListener('touchend', () => setTimeout(startAutoSlide, 100));

            createIndicators();
            goToSlide(0);
        })();

        // Form loading
        const form = document.querySelector('form');
        const submitBtn = form?.querySelector('button[type="submit"]');
        if (form && submitBtn) {
            form.addEventListener('submit', () => {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
                submitBtn.disabled = true;
            });
        }
    </script>
</body>
</html>