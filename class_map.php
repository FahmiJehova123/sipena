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

$page_title = 'Peta Kelas - SIAKAD';
$current_page = 'class_map';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

date_default_timezone_set('Asia/Jakarta');

$user_role = $_SESSION['user_role'];
$user_id = $_SESSION['user_id'];

// Ambil semua kelas (tanpa filter role, karena semua bisa melihat peta)
$classes_raw = supabase_admin_request('GET', 'classes');
$classes = is_array($classes_raw) ? $classes_raw : [];

// Ambil data guru (untuk referensi)
$teachers_raw = supabase_admin_request('GET', 'users', null, ['role' => 'eq.teacher']);
$teachers = is_array($teachers_raw) ? $teachers_raw : [];

// Tentukan apakah admin
$is_admin = ($user_role == 'admin');

// Pilih header dan sidebar berdasarkan role
if ($is_admin) {
    require_once __DIR__ . '/includes/header.php';
} else {
    require_once __DIR__ . '/includes/header_user.php';
}
?>
<style>
    .map-container {
        background: #f0f4f8;
        border-radius: 16px;
        padding: 20px;
        box-shadow: inset 0 2px 8px rgba(0,0,0,0.05);
        min-height: 500px;
        position: relative;
    }
    #map {
        width: 100%;
        height: 500px;
        border-radius: 12px;
        background: #e2e8f0;
    }
    .legend {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        padding: 12px 0;
        justify-content: center;
    }
    .legend-item {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.85rem;
        color: #334155;
    }
    .legend-color {
        width: 20px;
        height: 20px;
        border-radius: 6px;
    }
    .filter-bar {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        align-items: center;
        margin-bottom: 20px;
        padding: 12px 16px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .filter-bar select, .filter-bar button {
        padding: 8px 14px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: white;
        font-size: 0.9rem;
        outline: none;
    }
    .filter-bar button {
        background: #3b82f6;
        color: white;
        border: none;
        cursor: pointer;
        transition: 0.15s;
        font-weight: 500;
    }
    .filter-bar button:hover {
        background: #2563eb;
    }
    .dark .map-container { background: #1e293b; }
    .dark .filter-bar { background: #2d3748; border-color: #475569; }
    .dark .filter-bar select { background: #1e293b; color: #e2e8f0; border-color: #475569; }
    .dark .filter-bar select option { background: #1e293b; }

    /* Modal edit lokasi (hanya admin) */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.6);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }
    .modal-overlay.active {
        display: flex;
    }
    .modal-box {
        background: white;
        border-radius: 16px;
        padding: 24px;
        max-width: 500px;
        width: 90%;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    }
    .dark .modal-box {
        background: #1e293b;
        color: #e2e8f0;
    }
    .modal-box h3 {
        font-size: 1.25rem;
        font-weight: 700;
        margin-bottom: 16px;
        color: #1e293b;
    }
    .dark .modal-box h3 {
        color: #f1f5f9;
    }
    .modal-box label {
        display: block;
        font-weight: 500;
        margin-top: 12px;
        margin-bottom: 4px;
        color: #475569;
    }
    .dark .modal-box label {
        color: #94a3b8;
    }
    .modal-box input, .modal-box textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #f8fafc;
        transition: 0.15s;
        font-size: 0.95rem;
    }
    .dark .modal-box input, .dark .modal-box textarea {
        background: #0f172a;
        border-color: #475569;
        color: #e2e8f0;
    }
    .modal-box input:focus, .modal-box textarea:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59,130,246,0.2);
        outline: none;
    }
    .modal-box .btn-row {
        display: flex;
        gap: 12px;
        margin-top: 20px;
        justify-content: flex-end;
    }
    .modal-box .btn-row button {
        padding: 10px 24px;
        border-radius: 8px;
        border: none;
        font-weight: 500;
        cursor: pointer;
        transition: 0.15s;
    }
    .modal-box .btn-row .btn-primary {
        background: #3b82f6;
        color: white;
    }
    .modal-box .btn-row .btn-primary:hover {
        background: #2563eb;
    }
    .modal-box .btn-row .btn-secondary {
        background: #e2e8f0;
        color: #334155;
    }
    .dark .modal-box .btn-row .btn-secondary {
        background: #475569;
        color: #e2e8f0;
    }
    .modal-box .btn-row .btn-secondary:hover {
        background: #cbd5e1;
    }
    .dark .modal-box .btn-row .btn-secondary:hover {
        background: #64748b;
    }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php if ($is_admin): ?>
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <?php else: ?>
        <?php require_once __DIR__ . '/includes/sidebar_user.php'; ?>
    <?php endif; ?>
    
    <div class="main-content-container flex-1 transition-all duration-300 ease-in-out">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openMobileSidebarBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">🗺️ Peta Kelas</h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                        <i class="fas fa-moon text-xl dark:hidden"></i>
                        <i class="fas fa-sun text-xl hidden dark:inline"></i>
                    </button>
                    <div class="relative group">
                        <button class="flex items-center space-x-2 focus:outline-none">
                            <?php
                            $user_photo = $_SESSION['user_photo'] ?? null;
                            $user_name = $_SESSION['user_name'] ?? 'User';
                            $initial = strtoupper(substr($user_name, 0, 1));
                            ?>
                            <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold overflow-hidden">
                                <?php if ($user_photo): ?>
                                    <img src="<?= htmlspecialchars($user_photo) ?>" class="w-full h-full object-cover" alt="Foto">
                                <?php else: ?>
                                    <span><?= $initial ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="hidden md:inline text-gray-700 dark:text-gray-200"><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></span>
                        </button>
                        <div class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-md shadow-lg py-1 hidden group-hover:block z-20">
                            <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-user mr-2"></i>Profil</a>
                            <a href="logout.php" class="block px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-gray-100 dark:hover:bg-gray-700"><i class="fas fa-sign-out-alt mr-2"></i>Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-4 md:p-6 dark:bg-gray-900 transition-colors min-h-screen">
            <!-- Filter -->
            <div class="filter-bar">
                <div class="flex items-center gap-2 flex-wrap w-full md:w-auto">
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Tipe Kelas:</label>
                    <select id="filterType" class="dark:bg-gray-700 dark:text-white">
                        <option value="all">Semua</option>
                        <option value="pagi">Pagi</option>
                        <option value="diniyyah">Diniyyah</option>
                    </select>
                </div>
                <div class="flex items-center gap-2 flex-wrap w-full md:w-auto">
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Tingkat:</label>
                    <select id="filterLevel" class="dark:bg-gray-700 dark:text-white">
                        <option value="all">Semua</option>
                        <?php
                        $levels = array_unique(array_column($classes, 'grade_level'));
                        sort($levels);
                        foreach ($levels as $lv): ?>
                            <option value="<?= $lv ?>">Tingkat <?= $lv ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button id="resetFilterBtn" class="ml-auto"><i class="fas fa-undo-alt mr-1"></i> Reset</button>
                <span id="resultCount" class="text-sm text-gray-500 dark:text-gray-400 ml-2">Menampilkan <strong id="countDisplay">0</strong> ruang</span>
            </div>

            <!-- Peta -->
            <div class="map-container">
                <div id="map"></div>
            </div>

            <!-- Legenda -->
            <div class="mt-6 bg-white dark:bg-gray-800 rounded-xl p-4 shadow-md">
                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2"><i class="fas fa-palette mr-2"></i>Legenda Warna (Tingkat)</h4>
                <div class="legend">
                    <?php
                    $levelColors = [
                        1 => '#ef4444', 2 => '#f59e0b', 3 => '#10b981', 4 => '#3b82f6',
                        5 => '#8b5cf6', 6 => '#ec4899', 7 => '#14b8a6', 8 => '#f97316',
                        9 => '#6366f1', 10 => '#84cc16'
                    ];
                    foreach ($levelColors as $lv => $color):
                    ?>
                        <div class="legend-item">
                            <span class="legend-color" style="background:<?= $color ?>"></span>
                            Tingkat <?= $lv ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="legend-item">
                        <span class="legend-color" style="background:#94a3b8"></span>
                        Lainnya
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Modal Edit Lokasi (hanya untuk admin) -->
<?php if ($is_admin): ?>
<div id="locationModal" class="modal-overlay">
    <div class="modal-box">
        <h3><i class="fas fa-map-pin mr-2"></i> Atur Lokasi Kelas</h3>
        <form id="locationForm">
            <input type="hidden" id="editClassId" name="class_id">
            <label for="editAddress">Alamat / Deskripsi Lokasi</label>
            <textarea id="editAddress" name="address" rows="2" placeholder="Contoh: Gedung Utama Lantai 2, Jl. Pendidikan No.5"></textarea>

            <label for="editLat">Latitude</label>
            <input type="text" id="editLat" name="latitude" placeholder="-6.2088">

            <label for="editLng">Longitude</label>
            <input type="text" id="editLng" name="longitude" placeholder="106.8456">

            <div style="margin-top: 12px; font-size:0.9rem; color:#64748b;">
                <i class="fas fa-info-circle"></i> Anda bisa menggeser marker di peta untuk mendapatkan koordinat yang tepat.
            </div>

            <div class="btn-row">
                <button type="button" class="btn-secondary" onclick="closeLocationModal()">Batal</button>
                <button type="submit" class="btn-primary">Simpan Lokasi</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Leaflet CSS dan JS (gratis, tanpa API Key) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// ========== DARK MODE ==========
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
if (darkModeToggle) {
    darkModeToggle.addEventListener('click', () => setDarkMode(!document.documentElement.classList.contains('dark')));
}

// ========== DATA KELAS ==========
const classesData = <?= json_encode($classes) ?>;
const isAdmin = <?= json_encode($is_admin) ?>;

// ========== LEAFLET MAP ==========
let map;
let markers = [];

function initMap() {
    // Inisialisasi peta dengan pusat di Indonesia
    map = L.map('map').setView([-6.2088, 106.8456], 12);

    // Tambahkan tile layer dari OpenStreetMap (GRATIS, tanpa API Key)
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    // Tambahkan marker untuk setiap kelas
    classesData.forEach(cls => {
        let lat = cls.latitude ? parseFloat(cls.latitude) : null;
        let lng = cls.longitude ? parseFloat(cls.longitude) : null;
        
        // Jika tidak ada koordinat, beri posisi default dengan offset acak
        if (!lat || !lng) {
            const offset = (Math.random() - 0.5) * 0.02;
            lat = -6.2088 + offset;
            lng = 106.8456 + offset;
        }

        // Buat marker dengan warna sesuai tingkat (gunakan ikon custom)
        const marker = L.marker([lat, lng], {
            title: cls.class_name,
            icon: getMarkerIcon(cls.grade_level)
        }).addTo(map);

        // Konten popup
        let popupContent = `
            <div style="padding:4px;max-width:220px;">
                <strong>${cls.class_name}</strong><br>
                <span style="font-size:0.9rem;">${cls.class_type || ''} • Tingkat ${cls.grade_level || '-'}</span><br>
                <span style="font-size:0.85rem;color:#475569;">${cls.address || 'Alamat belum diatur'}</span>
                <br>
                <button onclick="navigateToClass('${cls.id}')" style="margin-top:6px;background:#3b82f6;color:white;border:none;padding:4px 12px;border-radius:6px;cursor:pointer;">
                    🧭 Navigasi
                </button>
        `;
        // Tombol edit hanya untuk admin
        if (isAdmin) {
            popupContent += `
                <button onclick="openLocationModal('${cls.id}')" style="margin-top:6px;margin-left:6px;background:#f59e0b;color:white;border:none;padding:4px 12px;border-radius:6px;cursor:pointer;">
                    ✏️ Edit Lokasi
                </button>
            `;
        }
        popupContent += `</div>`;
        marker.bindPopup(popupContent);

        // Simpan referensi marker
        markers.push(marker);
    });

    // Sesuaikan zoom agar semua marker terlihat
    if (markers.length > 0) {
        const group = L.featureGroup(markers);
        map.fitBounds(group.getBounds());
    }
}

// Fungsi untuk mendapatkan ikon marker berdasarkan tingkat
function getMarkerIcon(grade) {
    const colors = {
        1: 'red', 2: 'orange', 3: 'green', 4: 'blue',
        5: 'purple', 6: 'pink', 7: 'teal', 8: 'deeporange',
        9: 'indigo', 10: 'lime'
    };
    const color = colors[grade] || 'grey';
    // Gunakan marker default dengan warna yang berbeda
    const svg = `
        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32">
            <circle cx="16" cy="16" r="14" fill="${color}" stroke="#fff" stroke-width="2"/>
            <text x="16" y="20" font-size="12" text-anchor="middle" fill="#fff" font-weight="bold">${grade || '?'}</text>
        </svg>
    `;
    return L.icon({
        iconUrl: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg),
        iconSize: [32, 32],
        iconAnchor: [16, 32],
        popupAnchor: [0, -28]
    });
}

function navigateToClass(classId) {
    const cls = classesData.find(c => c.id == classId);
    if (!cls) return;
    if (cls.latitude && cls.longitude) {
        const url = `https://www.google.com/maps/dir/?api=1&destination=${cls.latitude},${cls.longitude}`;
        window.open(url, '_blank');
    } else if (cls.address) {
        const url = `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(cls.address)}`;
        window.open(url, '_blank');
    } else {
        alert('Lokasi kelas belum diatur. Silakan admin mengatur lokasi terlebih dahulu.');
    }
}

// ========== FILTER (untuk marker) ==========
function applyFilters() {
    const type = document.getElementById('filterType').value;
    const level = document.getElementById('filterLevel').value;
    let visible = 0;

    markers.forEach((marker, idx) => {
        const cls = classesData[idx];
        let show = true;
        if (type !== 'all' && cls.class_type !== type) show = false;
        if (level !== 'all' && cls.grade_level != level) show = false;
        
        if (show) {
            if (!map.hasLayer(marker)) marker.addTo(map);
            visible++;
        } else {
            if (map.hasLayer(marker)) map.removeLayer(marker);
        }
    });
    
    document.getElementById('countDisplay').textContent = visible;
    
    // Sesuaikan zoom setelah filter
    const visibleMarkers = markers.filter(m => map.hasLayer(m));
    if (visibleMarkers.length > 0) {
        const group = L.featureGroup(visibleMarkers);
        map.fitBounds(group.getBounds());
    }
}

document.getElementById('filterType').addEventListener('change', applyFilters);
document.getElementById('filterLevel').addEventListener('change', applyFilters);
document.getElementById('resetFilterBtn').addEventListener('click', function() {
    document.getElementById('filterType').value = 'all';
    document.getElementById('filterLevel').value = 'all';
    applyFilters();
});

// ========== MODAL EDIT LOKASI (Admin only) ==========
function openLocationModal(classId) {
    if (!isAdmin) return;
    const cls = classesData.find(c => c.id == classId);
    if (!cls) return;
    document.getElementById('editClassId').value = classId;
    document.getElementById('editAddress').value = cls.address || '';
    document.getElementById('editLat').value = cls.latitude || '';
    document.getElementById('editLng').value = cls.longitude || '';
    document.getElementById('locationModal').classList.add('active');
    // Jika ada marker, pindahkan peta ke marker tersebut
    const marker = markers.find(m => m.options && m.options.title == cls.class_name); // tidak efisien, lebih baik simpan mapping
    if (marker) {
        map.panTo(marker.getLatLng());
        map.setZoom(16);
    }
}

function closeLocationModal() {
    document.getElementById('locationModal').classList.remove('active');
}

// Submit form update lokasi
document.getElementById('locationForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const classId = document.getElementById('editClassId').value;
    const address = document.getElementById('editAddress').value.trim();
    const lat = parseFloat(document.getElementById('editLat').value);
    const lng = parseFloat(document.getElementById('editLng').value);

    if (!classId || !address || isNaN(lat) || isNaN(lng)) {
        alert('Harap isi semua field dengan benar.');
        return;
    }

    try {
        const res = await fetch('api/update_class_location.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ class_id: classId, address, latitude: lat, longitude: lng })
        });
        const data = await res.json();
        if (data.success) {
            alert('Lokasi berhasil diperbarui!');
            closeLocationModal();
            // Reload halaman untuk memperbarui marker dan data
            location.reload();
        } else {
            alert('Gagal: ' + (data.error || 'Terjadi kesalahan'));
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
});

// Tutup modal jika klik di luar
document.getElementById('locationModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeLocationModal();
});

// ========== INIT ==========
document.addEventListener('DOMContentLoaded', function() {
    initMap();
    setTimeout(applyFilters, 500);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>