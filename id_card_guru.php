<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'teacher') {
    header('Location: login.php');
    exit;
}
if (time() - $_SESSION['login_time'] > 28800) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['login_time'] = time();

$page_title = 'ID Card Guru - SIAKAD';
$current_page = 'idcard';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$user_id = $_SESSION['user_id'];

$user_data = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $user_id]);
$user = (is_array($user_data) && !empty($user_data)) ? $user_data[0] : null;
if (!$user) { header('Location: logout.php'); exit; }

// Ambil daftar kelas yang diajar oleh guru ini
$jadwal = supabase_admin_request('GET', 'schedules', null, ['teacher_id' => 'eq.' . $user_id]);
$class_ids = [];
if (is_array($jadwal)) {
    foreach ($jadwal as $j) {
        if (!empty($j['class_id']) && !in_array($j['class_id'], $class_ids)) {
            $class_ids[] = $j['class_id'];
        }
    }
}
$kelas_diajar = [];
foreach ($class_ids as $cid) {
    $class = supabase_admin_request('GET', 'classes', null, ['id' => 'eq.' . $cid]);
    if (is_array($class) && !empty($class)) {
        $kelas_diajar[] = $class[0]['class_name'];
    }
}
$kelas_diajar_str = !empty($kelas_diajar) ? implode(', ', $kelas_diajar) : '-';

$full_name = htmlspecialchars($user['full_name'] ?? '-');
$nidn = htmlspecialchars($user['nidn_or_nisn'] ?? '-');
$tempat_lahir = htmlspecialchars($user['tempat_lahir'] ?? '-');
$tanggal_lahir_raw = $user['tanggal_lahir'] ?? '';
if (!empty($tanggal_lahir_raw) && $tanggal_lahir_raw != '-') {
    $tanggal_lahir = date('d-m-Y', strtotime($tanggal_lahir_raw));
} else {
    $tanggal_lahir = '-';
}
$alamat = htmlspecialchars($user['alamat'] ?? '-');
$pendidikan = htmlspecialchars($user['bagian'] ?? '-'); // misal menggunakan kolom bagian untuk gelar
$phone = htmlspecialchars($user['phone'] ?? '-');
$photo_url = !empty($user['photo_url']) ? $user['photo_url'] : '';

require_once __DIR__ . '/includes/header_user.php';
?>

<style>
    /* Dark mode untuk kartu */
    .dark .ktp-card {
        background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
        border: 1px solid #374151;
    }
    .dark .ktp-card .card-header,
    .dark .ktp-card .footer {
        background: #0f172a;
        color: #e2e8f0;
    }
    .dark .ktp-card .photo {
        background: #374151;
        border-color: #4b5563;
    }
    .dark .ktp-card .info-table td {
        color: #f3f4f6;
    }
    .dark .ktp-card .info-label {
        color: #9ca3af;
    }
    
    .ktp-card {
        width: 85.6mm;
        height: 53.98mm;
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        border-radius: 6px;
        box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        font-family: 'Courier New', monospace;
        position: relative;
        overflow: hidden;
        transition: transform 0.2s;
    }
    .ktp-card:hover { transform: scale(1.02); }
    .ktp-card .card-header {
        background: #2c3e50;
        color: white;
        padding: 5px 0;
        text-align: center;
        font-size: 11px;
        font-weight: bold;
        letter-spacing: 1px;
    }
    .ktp-card .card-body {
        padding: 8px 10px;
        display: flex;
        gap: 10px;
    }
    .ktp-card .photo {
        width: 30mm;
        height: 34mm;
        background: #e9ecef;
        border: 1px solid #adb5bd;
        border-radius: 3px;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
    }
    .ktp-card .photo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    /* Tabel informasi */
    .ktp-card .info-table {
        font-family: system-ui;
        font-size: 7pt;
        width: 100%;
        border-collapse: collapse;
        line-height: 1.4;
    }
    .ktp-card .info-table td {
        padding: 2px 0;
        border: none;
        vertical-align: top;
    }
    .ktp-card .info-label {
        font-weight: 700;
        white-space: nowrap;
        width: 1%;
        color: #374151;
    }
    .ktp-card .info-colon {
        width: 1%;
        text-align: center;
        padding: 0 4px;
        color: #374151;
    }
    .ktp-card .info-value {
        word-break: break-word;
        color: #1f2937;
    }
    .ktp-card .footer {
        background: #2c3e50;
        color: white;
        font-size: 7px;
        text-align: center;
        padding: 3px;
        position: absolute;
        bottom: 0;
        width: 100%;
    }
    @media print {
        .ktp-card {
            box-shadow: none;
            page-break-after: avoid;
            break-inside: avoid;
            background: white !important;
            color: black !important;
            border: 1px solid #ccc;
        }
        .ktp-card .card-header,
        .ktp-card .footer {
            background: #2c3e50 !important;
            color: white !important;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .ktp-card .photo {
            background: #f0f0f0 !important;
            border: 1px solid #aaa;
        }
        .ktp-card .info-label,
        .ktp-card .info-colon,
        .ktp-card .info-value {
            color: black !important;
        }
    }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar_user.php'; ?>
    
    <div class="main-content-container flex-1 transition-all duration-300 ease-in-out">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openSidebarUserBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden" onclick="document.getElementById('sidebarUser').classList.remove('-translate-x-full'); document.getElementById('sidebarUser').classList.add('translate-x-0'); document.getElementById('sidebarOverlayUser').classList.remove('hidden'); document.body.classList.add('sidebar-open'); return false;">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">ID Card Guru</h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                        <i class="fas fa-moon text-xl dark:hidden"></i>
                        <i class="fas fa-sun text-xl hidden dark:inline"></i>
                    </button>
                    <div class="relative group">
                        <button class="flex items-center space-x-2 focus:outline-none">
                            <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold overflow-hidden">
                                <?php
                                $user_photo = $_SESSION['user_photo'] ?? null;
                                $user_name = $_SESSION['user_name'] ?? 'Admin';
                                $initial = strtoupper(substr($user_name, 0, 1));
                                ?>
                                <?php if ($user_photo): ?>
                                    <img src="<?= htmlspecialchars($user_photo) ?>" class="w-full h-full object-cover" alt="Foto Profil">
                                <?php else: ?>
                                    <span><?= $initial ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="hidden md:inline text-gray-700 dark:text-gray-200"><?= htmlspecialchars($_SESSION['user_name']) ?></span>
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
            <div class="flex flex-wrap justify-center gap-6 mb-8">
                <!-- Card Depan -->
                <div id="ktp-card-front" class="ktp-card">
                    <div class="card-header">KARTU TANDA GURU</div>
                    <div class="card-body">
                        <div class="photo">
                            <?php if ($photo_url): ?>
                                <img src="<?= $photo_url ?>" alt="foto">
                            <?php else: ?>
                                <i class="fas fa-user-circle fa-3x text-gray-400"></i>
                            <?php endif; ?>
                        </div>
                        <table class="info-table">
                            <tr><td class="info-label">Nama</td><td class="info-colon">:</td><td class="info-value"><?= $full_name ?></td></tr>
                            <tr><td class="info-label">NIDN</td><td class="info-colon">:</td><td class="info-value"><?= $nidn ?></td></tr>
                            <tr><td class="info-label">Tempat/Tgl Lahir</td><td class="info-colon">:</td><td class="info-value"><?= $tempat_lahir ?>, <?= $tanggal_lahir ?></td></tr>
                            <tr><td class="info-label">Kelas Diajar</td><td class="info-colon">:</td><td class="info-value"><?= $kelas_diajar_str ?></td></tr>
                            <tr><td class="info-label">Pendidikan/Wewenang</td><td class="info-colon">:</td><td class="info-value"><?= $pendidikan ?></td></tr>
                            <tr><td class="info-label">Alamat</td><td class="info-colon">:</td><td class="info-value"><?= $alamat ?></td></tr>
                            <tr><td class="info-label">No. HP</td><td class="info-colon">:</td><td class="info-value"><?= $phone ?></td></tr>
                        </table>
                    </div>
                    <div class="footer">Berlaku selama masa tugas</div>
                </div>

                <!-- Card Belakang (QR) -->
                <div id="ktp-card-back" class="ktp-card">
                    <div class="card-header">KARTU TANDA GURU (Belakang)</div>
                    <div class="card-body" style="justify-content: center; align-items: center; flex-direction: column; display: flex; gap: 5px;">
                        <canvas id="qr-canvas" style="width: 35mm; height: 35mm;"></canvas>
                        <p style="font-size: 7px;">Scan QR untuk absensi</p>
                    </div>
                    <div class="footer">Simpan kartu ini dengan baik</div>
                </div>
            </div>

            <div class="flex justify-center">
                <button id="viewCardBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg shadow transition duration-200 flex items-center gap-2">
                    <i class="fas fa-eye"></i> View & Cetak Kartu
                </button>
            </div>
        </main>
    </div>
</div>

<!-- Modal Preview -->
<div id="printModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 w-auto max-w-full max-h-screen overflow-auto">
        <div class="flex justify-end mb-2"><button id="closeModalBtn" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button></div>
        <div id="modalCardsContainer" class="flex flex-col items-center gap-6"></div>
        <div class="flex justify-center mt-4"><button id="modalPrintBtn" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg shadow flex items-center gap-2"><i class="fas fa-print"></i> Cetak</button></div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.0/qrious.min.js"></script>
<script>
    const canvas = document.getElementById('qr-canvas');
    let qrDataUrl = '';
    if (canvas) {
        const qr = new QRious({ element: canvas, value: '<?= $user['id'] ?>', size: 300, background: 'white' });
        setTimeout(() => { qrDataUrl = canvas.toDataURL(); }, 200);
    }

    const frontCard = document.getElementById('ktp-card-front').cloneNode(true);
    const backCard = document.getElementById('ktp-card-back').cloneNode(true);
    frontCard.classList.remove('hover:shadow-xl', 'shadow-2xl');
    backCard.classList.remove('hover:shadow-xl', 'shadow-2xl');

    const modal = document.getElementById('printModal');
    const modalContainer = document.getElementById('modalCardsContainer');
    const viewBtn = document.getElementById('viewCardBtn');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const modalPrintBtn = document.getElementById('modalPrintBtn');

    viewBtn.addEventListener('click', () => {
        if (qrDataUrl) {
            const oldCanvas = backCard.querySelector('#qr-canvas');
            if (oldCanvas) {
                const img = document.createElement('img');
                img.src = qrDataUrl;
                img.style.width = oldCanvas.style.width;
                img.style.height = oldCanvas.style.height;
                oldCanvas.parentNode.replaceChild(img, oldCanvas);
            }
        }
        modalContainer.innerHTML = '';
        const frontClone = frontCard.cloneNode(true);
        const backClone = backCard.cloneNode(true);
        modalContainer.appendChild(frontClone);
        modalContainer.appendChild(backClone);
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    });

    closeModalBtn.addEventListener('click', () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    });

    modalPrintBtn.addEventListener('click', () => {
        const printWindow = window.open('', '_blank');
        const frontHtml = frontCard.outerHTML;
        const backHtml = backCard.outerHTML;
        printWindow.document.write(`<!DOCTYPE html><html><head><title>Cetak Kartu Guru</title><style>
            .ktp-card {
                width: 85.6mm;
                height: 53.98mm;
                background: white;
                border-radius: 6px;
                border: 1px solid #ccc;
                font-family: 'Courier New', monospace;
                position: relative;
                overflow: hidden;
                page-break-after: avoid;
                margin: 0 auto;
            }
            .ktp-card .card-header {
                background: #2c3e50;
                color: white;
                padding: 5px 0;
                text-align: center;
                font-size: 11px;
                font-weight: bold;
            }
            .ktp-card .card-body {
                padding: 8px 10px;
                display: flex;
                gap: 10px;
            }
            .ktp-card .photo {
                width: 30mm;
                height: 34mm;
                background: #f0f0f0;
                border: 1px solid #aaa;
                border-radius: 3px;
                display: flex;
                align-items: center;
                justify-content: center;
                overflow: hidden;
                flex-shrink: 0;
            }
            .ktp-card .photo img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            .ktp-card .info-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 8px;
                line-height: 1.4;
            }
            .ktp-card .info-table td {
                padding: 2px 0;
                border: none;
                vertical-align: top;
            }
            .ktp-card .info-label {
                font-weight: bold;
                white-space: nowrap;
                width: 1%;
            }
            .ktp-card .info-colon {
                width: 1%;
                text-align: center;
                padding: 0 4px;
            }
            .ktp-card .info-value {
                word-break: break-word;
            }
            .ktp-card .footer {
                background: #2c3e50;
                color: white;
                font-size: 7px;
                text-align: center;
                padding: 3px;
                position: absolute;
                bottom: 0;
                width: 100%;
            }
            .print-container {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 10mm;
                padding: 5mm;
            }
            @media print {
                body { margin: 0; padding: 0; }
                .print-container { gap: 10mm; }
            }
            @page { size: auto; margin: 5mm; }
        </style></head><body><div class="print-container"><div class="ktp-card">${frontHtml}</div><div class="ktp-card">${backHtml}</div></div><script>window.onload=()=>{window.print();setTimeout(()=>window.close(),500);};<\/script></body></html>`);
        printWindow.document.close();
    });
</script>
<script>
    const darkModeToggle = document.getElementById('darkModeToggle');
    function setDarkMode(isDark) {
        if (isDark) {
            document.documentElement.classList.add('dark');
            localStorage.setItem('darkMode', 'enabled');
        } else {
            document.documentElement.classList.remove('dark');
            localStorage.setItem('darkMode', 'disabled');
        }
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
</script>
<script>
    document.getElementById('closeSidebarUser')?.addEventListener('click', function() {
        document.getElementById('sidebarUser').classList.add('-translate-x-full');
        document.getElementById('sidebarUser').classList.remove('translate-x-0');
        document.getElementById('sidebarOverlayUser').classList.add('hidden');
        document.body.classList.remove('sidebar-open');
    });
    document.getElementById('sidebarOverlayUser')?.addEventListener('click', function() {
        document.getElementById('sidebarUser').classList.add('-translate-x-full');
        document.getElementById('sidebarUser').classList.remove('translate-x-0');
        document.getElementById('sidebarOverlayUser').classList.add('hidden');
        document.body.classList.remove('sidebar-open');
    });
</script>

<?php require_once __DIR__ . '/includes/footer_user.php'; ?>