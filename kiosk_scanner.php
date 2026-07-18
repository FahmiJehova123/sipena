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

$page_title = 'Kiosk Absensi - QR & NFC';
$current_page = 'dashboard';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$user_role = $_SESSION['user_role'];
$user_id = $_SESSION['user_id'];

// Ambil kegiatan untuk hari ini
$today = date('N');
$activities_raw = supabase_admin_request('GET', 'activities', null, [
    'day_of_week' => 'eq.' . $today,
    'is_active' => 'eq.true',
    'order' => 'start_time.asc'
]);
$activities = is_array($activities_raw) ? $activities_raw : [];

// Ambil jadwal untuk hari ini
$schedules_raw = supabase_admin_request('GET', 'schedules', null, ['day_of_week' => 'eq.' . $today]);
$schedules = is_array($schedules_raw) ? $schedules_raw : [];
if ($user_role == 'teacher') {
    $schedules = array_filter($schedules, function($s) use ($user_id) {
        return isset($s['teacher_id']) && $s['teacher_id'] == $user_id;
    });
}
usort($schedules, function($a, $b) { return strcmp($a['start_time'], $b['start_time']); });

$classes_raw = supabase_admin_request('GET', 'classes');
$classes = is_array($classes_raw) ? $classes_raw : [];
$subjects_raw = supabase_admin_request('GET', 'subjects');
$subjects = is_array($subjects_raw) ? $subjects_raw : [];
$classMap = [];
foreach ($classes as $c) { $classMap[$c['id']] = $c['class_name']; }
$subjectMap = [];
foreach ($subjects as $sub) { $subjectMap[$sub['id']] = $sub['subject_name']; }

require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* Toast fallback (jika toast_notif.js tidak tersedia) */
    .toast-notification {
        position: fixed;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: #1f2937;
        color: white;
        padding: 12px 24px;
        border-radius: 12px;
        z-index: 1000;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 8px;
        white-space: nowrap;
    }
    @media (max-width: 640px) {
        .toast-notification { white-space: normal; max-width: 90%; text-align: center; font-size: 0.875rem; padding: 10px 16px; }
    }
    .toast-notification.success { background: #10b981; }
    .toast-notification.error { background: #ef4444; }
    .toast-notification.info { background: #3b82f6; }
    
    #qr-reader { width: 100%; margin: 0 auto; }
    #qr-reader video { border-radius: 16px; width: 100%; height: auto; }
    
    /* Layout dua kolom */
    @media (min-width: 1024px) {
        .two-columns { display: flex; gap: 2rem; align-items: flex-start; }
        .left-panel { flex: 1; position: sticky; top: 20px; }
        .right-panel { flex: 1; }
    }
    @media (max-width: 768px) {
        .container-custom { padding-left: 1rem; padding-right: 1rem; }
        .card-padding { padding: 1rem; }
    }
</style>

<div class="min-h-screen bg-gray-100 dark:bg-gray-900 py-6 md:py-10">
    <div class="container-custom mx-auto px-4 max-w-7xl">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl p-4 md:p-6">
            <h1 class="text-2xl md:text-3xl font-bold text-center mb-2">
                <i class="fas fa-qrcode text-blue-500 mr-2"></i> Kiosk Absensi
            </h1>
            <p class="text-center text-gray-500 dark:text-gray-400 mb-8">Scan QR Code atau tap NFC untuk absensi</p>

            <div class="two-columns">
                <!-- Panel Kiri -->
                <div class="left-panel space-y-5">
                    <div>
                        <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">🔘 Pilih Mode Absensi</label>
                        <div class="flex flex-wrap gap-4">
                            <label class="inline-flex items-center cursor-pointer">
                                <input type="radio" name="absensiMode" value="activity" checked class="mr-2"> Absensi Kegiatan
                            </label>
                            <label class="inline-flex items-center cursor-pointer">
                                <input type="radio" name="absensiMode" value="schedule" class="mr-2"> Absensi Jadwal (Mengajar)
                            </label>
                        </div>
                    </div>

                    <div id="activitySection">
                        <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">📌 Pilih Kegiatan</label>
                        <select id="activitySelect" class="w-full border rounded-lg px-4 py-2.5 dark:bg-gray-700 dark:text-white">
                            <option value="">-- Pilih Kegiatan --</option>
                            <?php foreach ($activities as $a): ?>
                                <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?> (<?= $a['type'] ?>) — <?= substr($a['start_time'],0,5) ?> s/d <?= substr($a['end_time'],0,5) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($activities)): ?>
                            <p class="text-amber-600 text-sm mt-2">⚠️ Tidak ada kegiatan aktif hari ini.</p>
                        <?php endif; ?>
                    </div>

                    <div id="scheduleSection" class="hidden">
                        <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">📅 Pilih Jadwal (Hari Ini)</label>
                        <select id="scheduleSelect" class="w-full border rounded-lg px-4 py-2.5 dark:bg-gray-700 dark:text-white">
                            <option value="">-- Pilih Jadwal --</option>
                            <?php foreach ($schedules as $s): 
                                $class_name = $classMap[$s['class_id']] ?? '?';
                                $subject_name = $subjectMap[$s['subject_id']] ?? '?';
                            ?>
                                <option value="<?= $s['id'] ?>"><?= "$subject_name - $class_name (".$s['start_time']." - ".$s['end_time'].")" ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($schedules)): ?>
                            <p class="text-amber-600 text-sm mt-2">⚠️ Tidak ada jadwal mengajar hari ini.</p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">📷 Pilih Kamera</label>
                        <select id="cameraSelect" class="w-full border rounded-lg px-4 py-2.5 dark:bg-gray-700 dark:text-white">
                            <option value="">-- Memuat daftar kamera --</option>
                        </select>
                        <p id="cameraStatus" class="text-xs text-gray-500 mt-1"></p>
                    </div>

                    <div class="flex flex-wrap gap-3 mt-4">
                        <button id="startNfcBtn" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition flex items-center gap-2 shadow-md">
                            <i class="fas fa-sim-card"></i> Scan NFC
                        </button>
                        <button onclick="location.reload()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition flex items-center gap-2 shadow-md">
                            <i class="fas fa-sync-alt"></i> Reset Kamera
                        </button>
                    </div>
                </div>

                <!-- Panel Kanan -->
                <div class="right-panel mt-6 lg:mt-0">
                    <div id="qr-reader" class="w-full mb-4"></div>
                    <div id="scan-result" class="text-center text-lg font-semibold mt-4 min-h-[3rem]"></div>
                </div>
            </div>

            <div class="flex justify-center mt-8">
                <a href="<?= $_SESSION['user_role'] == 'admin' ? 'admin_dashboard.php' : 'dashboard_guru.php' ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition flex items-center gap-2 shadow-md">
                    <i class="fas fa-arrow-left"></i> Kembali ke Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
    // ========== AUDIO NOTIFICATION (Web Audio API) ==========
    function playBeep(type) {
        try {
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();
            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);
            oscillator.type = 'sine';
            oscillator.frequency.value = type === 'success' ? 880 : 440;
            gainNode.gain.value = 0.3;
            oscillator.start();
            gainNode.gain.exponentialRampToValueAtTime(0.00001, audioCtx.currentTime + 0.5);
            oscillator.stop(audioCtx.currentTime + 0.5);
        } catch(e) { console.log('Audio not supported'); }
    }

    // ========== TOAST (gunakan showToast jika ada, fallback) ==========
    if (typeof showToast === 'undefined') {
        window.showToast = function(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast-notification ${type}`;
            let icon = type === 'success' ? '<i class="fas fa-check-circle"></i>' : (type === 'error' ? '<i class="fas fa-exclamation-circle"></i>' : '<i class="fas fa-info-circle"></i>');
            toast.innerHTML = `${icon} ${message}`;
            document.body.appendChild(toast);
            setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3000);
        };
    }

    // ========== QR SCANNER ==========
    let html5QrCode = null;
    let currentCameraId = null;
    let isScanning = false;
    let scannerActive = false;

    const modeRadios = document.querySelectorAll('input[name="absensiMode"]');
    const activitySection = document.getElementById('activitySection');
    const scheduleSection = document.getElementById('scheduleSection');
    modeRadios.forEach(radio => {
        radio.addEventListener('change', (e) => {
            if (e.target.value === 'activity') {
                activitySection.classList.remove('hidden');
                scheduleSection.classList.add('hidden');
            } else {
                activitySection.classList.add('hidden');
                scheduleSection.classList.remove('hidden');
            }
        });
    });

    async function loadCameras() {
        try {
            const devices = await Html5Qrcode.getCameras();
            const cameraSelect = document.getElementById('cameraSelect');
            if (devices && devices.length) {
                cameraSelect.innerHTML = '<option value="">-- Pilih Kamera --</option>';
                devices.forEach(device => {
                    const option = document.createElement('option');
                    option.value = device.id;
                    option.text = device.label || `Kamera ${device.id.slice(0,5)}`;
                    cameraSelect.appendChild(option);
                });
                document.getElementById('cameraStatus').innerHTML = `${devices.length} kamera ditemukan. Pilih salah satu.`;
                cameraSelect.value = devices[0].id;
                currentCameraId = devices[0].id;
                await startScanner(currentCameraId);
            } else {
                cameraSelect.innerHTML = '<option value="">Tidak ada kamera</option>';
                document.getElementById('cameraStatus').innerHTML = 'Tidak ada kamera ditemukan.';
                showToast('Tidak ada kamera', 'error');
                playBeep('error');
            }
        } catch (err) {
            console.error(err);
            document.getElementById('cameraStatus').innerHTML = 'Gagal mengakses kamera. Pastikan izin diberikan dan gunakan HTTPS.';
            showToast('Gagal mengakses kamera: ' + err.message, 'error');
            playBeep('error');
        }
    }

    async function startScanner(cameraId) {
        if (!cameraId) return;
        if (scannerActive) {
            try { await html5QrCode.stop(); } catch(e) {}
            scannerActive = false;
        }
        if (html5QrCode) {
            try { await html5QrCode.clear(); } catch(e) {}
        }
        html5QrCode = new Html5Qrcode("qr-reader");
        const config = { fps: 10, qrbox: { width: 250, height: 250 } };
        try {
            await html5QrCode.start(cameraId, config, qrCodeSuccessCallback);
            scannerActive = true;
            isScanning = true;
            showToast('Kamera berhasil diaktifkan', 'info');
            playBeep('success');
        } catch (err) {
            console.error(err);
            showToast('Gagal memulai kamera: ' + err.message, 'error');
            playBeep('error');
        }
    }

    const qrCodeSuccessCallback = async (decodedText, decodedResult) => {
        if (!isScanning) return;
        const mode = document.querySelector('input[name="absensiMode"]:checked').value;
        let id = null;
        if (mode === 'activity') {
            id = document.getElementById('activitySelect').value;
            if (!id) {
                showToast("Pilih kegiatan terlebih dahulu", "error");
                playBeep('error');
                return;
            }
        } else {
            id = document.getElementById('scheduleSelect').value;
            if (!id) {
                showToast("Pilih jadwal terlebih dahulu", "error");
                playBeep('error');
                return;
            }
        }
        isScanning = false;
        if (scannerActive) {
            try { await html5QrCode.stop(); } catch(e) {}
            scannerActive = false;
        }
        document.getElementById('scan-result').innerHTML = '<span class="text-yellow-600">⏳ Memproses...</span>';
        try {
            let res;
            if (mode === 'activity') {
                res = await fetch('api/proses_absensi_activity.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ qr_data: decodedText, activity_id: id })
                });
            } else {
                res = await fetch('api/proses_absensi_manual.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: decodedText, schedule_id: id, from_kiosk: true })
                });
            }
            const data = await res.json();
            if (data.success) {
                document.getElementById('scan-result').innerHTML = `<span class="text-green-600 text-xl">✅ ${data.message}</span>`;
                showToast(data.message, "success");
                playBeep('success');
            } else {
                document.getElementById('scan-result').innerHTML = `<span class="text-red-600 text-xl">❌ ${data.message}</span>`;
                showToast(data.message, "error");
                playBeep('error');
            }
        } catch (err) {
            console.error(err);
            document.getElementById('scan-result').innerHTML = '<span class="text-red-600">Error koneksi</span>';
            showToast("Error koneksi", "error");
            playBeep('error');
        }
        setTimeout(async () => {
            document.getElementById('scan-result').innerHTML = '';
            if (currentCameraId) await startScanner(currentCameraId);
            isScanning = true;
        }, 1000);
    };

    document.getElementById('cameraSelect').addEventListener('change', async (e) => {
        currentCameraId = e.target.value;
        if (currentCameraId) await startScanner(currentCameraId);
    });

    loadCameras();

    // ========== NFC SCANNER ==========
    const startNfcBtn = document.getElementById('startNfcBtn');
    if ('NDEFReader' in window) {
        startNfcBtn.addEventListener('click', async () => {
            const mode = document.querySelector('input[name="absensiMode"]:checked').value;
            let id = null;
            if (mode === 'activity') {
                id = document.getElementById('activitySelect').value;
                if (!id) { showToast("Pilih kegiatan", "error"); playBeep('error'); return; }
            } else {
                id = document.getElementById('scheduleSelect').value;
                if (!id) { showToast("Pilih jadwal", "error"); playBeep('error'); return; }
            }
            try {
                const ndef = new NDEFReader();
                await ndef.scan();
                showToast("Tap kartu NFC sekarang...", "info");
                ndef.addEventListener("readingerror", () => {
                    showToast("Gagal membaca NFC", "error");
                    playBeep('error');
                });
                ndef.addEventListener("reading", async ({ message }) => {
                    let nfcData = '';
                    for (let record of message.records) {
                        if (record.recordType === "text") {
                            const textDecoder = new TextDecoder(record.encoding);
                            nfcData = textDecoder.decode(record.data);
                            break;
                        }
                    }
                    if (nfcData) {
                        try {
                            let res;
                            if (mode === 'activity') {
                                res = await fetch('api/proses_absensi_activity.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ qr_data: nfcData, activity_id: id })
                                });
                            } else {
                                res = await fetch('api/proses_absensi_manual.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ user_id: nfcData, schedule_id: id, from_kiosk: true })
                                });
                            }
                            const data = await res.json();
                            if (data.success) {
                                showToast(data.message, "success");
                                playBeep('success');
                            } else {
                                showToast(data.message, "error");
                                playBeep('error');
                            }
                        } catch (err) {
                            showToast("Error koneksi", "error");
                            playBeep('error');
                        }
                    }
                });
            } catch (err) {
                console.error(err);
                showToast("NFC tidak didukung atau gagal scan", "error");
                playBeep('error');
            }
        });
    } else {
        startNfcBtn.title = "Web NFC tidak didukung browser ini";
        startNfcBtn.classList.add('opacity-75');
        startNfcBtn.addEventListener('click', () => {
            showToast("Browser tidak mendukung NFC", "error");
            playBeep('error');
        });
    }
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>