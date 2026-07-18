<?php
session_start();

// Cek login & role admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Timeout 8 jam
if (time() - $_SESSION['login_time'] > 28800) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['login_time'] = time();

$page_title = 'Kutub Player - Admin';
$current_page = 'audio_player';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

date_default_timezone_set('Asia/Jakarta');
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===== AMBIL DATA AUDIO DARI GOOGLE DRIVE =====
define('GOOGLE_FOLDER_ID', '1iGPS_VTgXKZyHYiDq1a8yALd8UnbwiiW');
define('API_KEY', 'AIzaSyBpFoZODnobATJR49yUHJWtys06ZIf9lGc');

function fetchDriveFiles($folderId, $path = '') {
    $url = "https://www.googleapis.com/drive/v3/files?" .
           "q='" . urlencode($folderId) . "'+in+parents+and+trashed=false" .
           "&fields=files(id,name,mimeType)" .
           "&key=" . API_KEY;
    
    $response = file_get_contents($url);
    if ($response === false) {
        return [];
    }
    $data = json_decode($response, true);
    if (!isset($data['files'])) {
        return [];
    }
    
    $audioFiles = [];
    foreach ($data['files'] as $file) {
        if ($file['mimeType'] === 'application/vnd.google-apps.folder') {
            // Rekursif ke subfolder
            $subPath = $path ? $path . '/' . $file['name'] : $file['name'];
            $subFiles = fetchDriveFiles($file['id'], $subPath);
            $audioFiles = array_merge($audioFiles, $subFiles);
        } elseif (strpos($file['mimeType'], 'audio/') === 0) {
            $audioFiles[] = [
                'id'    => $file['id'],
                'judul' => pathinfo($file['name'], PATHINFO_FILENAME),
                'kitab' => $path ?: 'Root',
                'durasi' => '' // Drive API tidak menyediakan durasi
            ];
        }
    }
    return $audioFiles;
}

// Ambil semua audio dari folder root
$audio_list = fetchDriveFiles(GOOGLE_FOLDER_ID);

// Kelompokkan berdasarkan kitab (path)
$groups = [];
foreach ($audio_list as $item) {
    $key = $item['kitab'];
    if (!isset($groups[$key])) {
        $groups[$key] = [];
    }
    $groups[$key][] = $item;
}

// Proxy URL
$proxy_url = 'api/proxy_audio';

// Notifikasi admin (sama seperti dashboard)
$user_id = $_SESSION['user_id'] ?? null;
$unread_count = 0;
$announcements_dropdown = [];
if ($user_id) {
    $announcements_raw = supabase_admin_request('GET', 'announcements', null, [
        'is_active' => 'eq.true',
        'order' => 'created_at.desc',
        'limit' => 10
    ]);
    $announcements = safeArray($announcements_raw);
    $reads_raw = supabase_admin_request('GET', 'announcement_reads', null, [
        'user_id' => 'eq.' . $user_id
    ]);
    $reads = safeArray($reads_raw);
    $read_ids = array_column($reads, 'announcement_id');
    $unread_count = 0;
    foreach ($announcements as $ann) {
        if (!in_array($ann['id'], $read_ids)) $unread_count++;
    }
    $announcements_dropdown = $announcements;
}
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<style>
    /* ===== GAYA UTAMA ===== */
    .audio-player-container { max-width: 900px; margin: 0 auto; }
    .audio-item {
        cursor: pointer;
        padding: 8px 12px;
        border-radius: 6px;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid #e5e7eb;
    }
    .audio-item:hover { background: #f1f5f9; }
    .dark .audio-item:hover { background: #1e293b; }
    .audio-item.active { background: #dbeafe; border-left: 4px solid #3b82f6; }
    .dark .audio-item.active { background: #1e3a5f; border-left-color: #60a5fa; }
    .audio-item .durasi { color: #6b7280; font-size: 0.8rem; }
    .dark .audio-item .durasi { color: #9ca3af; }
    .kitab-group {
        margin-bottom: 16px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        overflow: hidden;
    }
    .dark .kitab-group { border-color: #374151; }
    .kitab-header {
        background: #f8fafc;
        padding: 10px 16px;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-weight: 600;
        color: #1f2937;
        transition: background 0.2s;
    }
    .dark .kitab-header { background: #1e293b; color: #e5e7eb; }
    .kitab-header:hover { background: #e2e8f0; }
    .dark .kitab-header:hover { background: #2d3a4f; }
    .kitab-header .play-folder-btn {
        background: #3b82f6;
        color: white;
        border: none;
        border-radius: 20px;
        padding: 4px 14px;
        font-size: 0.8rem;
        cursor: pointer;
        transition: background 0.2s;
    }
    .kitab-header .play-folder-btn:hover { background: #2563eb; }
    .kitab-body { padding: 0 8px; }
    .kitab-body.hidden { display: none; }
    .audio-list-container { max-height: 500px; overflow-y: auto; }

    /* Now Playing Box */
    .now-playing-box {
        background: #f8fafc;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .dark .now-playing-box { background: #1e293b; }
    .progress-container {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-top: 12px;
    }
    .progress-container input[type="range"] {
        flex: 1;
        height: 6px;
        -webkit-appearance: none;
        background: #d1d5db;
        border-radius: 3px;
        outline: none;
    }
    .dark .progress-container input[type="range"] { background: #4b5563; }
    .progress-container input[type="range"]::-webkit-slider-thumb {
        -webkit-appearance: none;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: #3b82f6;
        cursor: pointer;
    }
    .control-buttons {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 24px;
        margin: 16px 0 12px 0;
    }
    .control-buttons button {
        background: none;
        border: none;
        font-size: 2rem;
        color: #3b82f6;
        transition: transform 0.15s;
        cursor: pointer;
    }
    .control-buttons .jump-btn {
        font-size: 1.2rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        line-height: 1;
        color: #6b7280;
        padding: 0 4px;
    }
    .control-buttons .jump-btn i {
        font-size: 1.2rem;
    }
    .control-buttons .jump-btn span {
        font-size: 0.5rem;
        font-weight: 700;
        margin-top: 2px;
    }
    .dark .control-buttons .jump-btn {
        color: #9ca3af;
    }
    .control-buttons button:hover { transform: scale(1.1); }
    .control-buttons button:disabled { color: #9ca3af; cursor: not-allowed; transform: none; }
    .volume-container {
        display: flex;
        align-items: center;
        gap: 12px;
        justify-content: center;
        margin-top: 8px;
    }
    .volume-container input[type="range"] {
        width: 120px;
        height: 4px;
        -webkit-appearance: none;
        background: #d1d5db;
        border-radius: 2px;
        outline: none;
    }
    .dark .volume-container input[type="range"] { background: #4b5563; }
    .volume-container input[type="range"]::-webkit-slider-thumb {
        -webkit-appearance: none;
        width: 14px;
        height: 14px;
        border-radius: 50%;
        background: #3b82f6;
        cursor: pointer;
    }
    .time-label { font-size: 0.9rem; color: #4b5563; min-width: 45px; font-variant-numeric: tabular-nums; }
    .dark .time-label { color: #d1d5db; }
    .error-msg { color: #dc2626; font-size: 0.9rem; text-align: center; margin-top: 8px; display: none; }
    .extra-controls {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 16px;
        margin-top: 6px;
        flex-wrap: wrap;
    }
    .extra-controls label {
        font-size: 0.85rem;
        color: #6b7280;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .dark .extra-controls label { color: #9ca3af; }
    .extra-controls select, .extra-controls a {
        background: #e5e7eb;
        border: none;
        border-radius: 6px;
        padding: 4px 10px;
        font-size: 0.85rem;
        color: #1f2937;
        cursor: pointer;
    }
    .dark .extra-controls select, .dark .extra-controls a {
        background: #374151;
        color: #e5e7eb;
    }
    .extra-controls a { text-decoration: none; display: inline-block; }
    .extra-controls a:hover { background: #d1d5db; }
    .dark .extra-controls a:hover { background: #4b5563; }

    /* Mini Player */
    #mini-player {
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: #1f2937;
        color: white;
        padding: 12px 18px;
        border-radius: 40px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        display: flex;
        align-items: center;
        gap: 14px;
        z-index: 9999;
        cursor: grab;
        user-select: none;
        transition: none;
        min-width: 220px;
        backdrop-filter: blur(8px);
        border: 1px solid rgba(255,255,255,0.1);
    }
    #mini-player:active { cursor: grabbing; }
    #mini-player .mini-title {
        flex: 1;
        font-size: 0.9rem;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 150px;
        cursor: pointer;
    }
    #mini-player .mini-title:hover { color: #93c5fd; }
    #mini-player button {
        background: none;
        border: none;
        color: #e5e7eb;
        font-size: 1.2rem;
        cursor: pointer;
        padding: 0 4px;
        transition: color 0.2s;
    }
    #mini-player button:hover { color: #60a5fa; }
    #mini-player .mini-close { color: #9ca3af; font-size: 1rem; }
    #mini-player .mini-close:hover { color: #ef4444; }
    #mini-player.hidden { display: none !important; }

    @media (max-width: 640px) {
        .control-buttons button { font-size: 1.6rem; gap: 16px; }
        .now-playing-box { padding: 16px; }
        #mini-player { bottom: 10px; right: 10px; padding: 10px 14px; min-width: 180px; }
        #mini-player .mini-title { max-width: 100px; font-size: 0.8rem; }
    }
    
    /* ===== PENCARIAN ===== */
    #searchKitab {
        transition: all 0.2s;
    }
    #searchKitab:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
    }
    .audio-item.hidden-item { display: none; }
    .kitab-group.hidden-group { display: none; }
    #noSearchResult {
        animation: fadeIn 0.3s ease;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    
    <div class="main-content-container flex-1 transition-all duration-300 ease-in-out">
        <!-- HEADER (sama seperti dashboard) -->
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openMobileSidebarBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Kutub Player</h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                        <i class="fas fa-moon text-xl dark:hidden"></i>
                        <i class="fas fa-sun text-xl hidden dark:inline"></i>
                    </button>
                    
                    <!-- Notifikasi -->
                    <div class="relative" id="notificationDropdown">
                        <button id="notificationBtn" class="relative text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white focus:outline-none">
                            <i class="fas fa-bell text-xl"></i>
                            <?php if ($unread_count > 0): ?>
                                <span id="unreadBadge" class="absolute -top-1 -right-2 bg-red-500 text-white text-xs rounded-full px-1.5 py-0.5 min-w-[18px] text-center"><?= $unread_count ?></span>
                            <?php else: ?>
                                <span id="unreadBadge" class="hidden"></span>
                            <?php endif; ?>
                        </button>
                        <div id="notificationPanel" class="absolute right-0 mt-2 w-80 bg-white dark:bg-gray-800 rounded-lg shadow-lg overflow-hidden z-30 hidden">
                            <div class="p-3 border-b dark:border-gray-700 font-semibold text-gray-700 dark:text-gray-200 flex justify-between items-center">
                                <span><i class="fas fa-bell mr-2"></i> Notifikasi</span>
                                <button id="markAllReadBtn" class="text-xs text-blue-500 hover:underline">Tandai semua dibaca</button>
                            </div>
                            <div id="notificationList" class="max-h-96 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-700">
                                <?php if (empty($announcements_dropdown)): ?>
                                    <div class="p-4 text-center text-gray-500 dark:text-gray-400 text-sm">Tidak ada pengumuman</div>
                                <?php else: ?>
                                    <?php foreach ($announcements_dropdown as $ann): 
                                        $is_read = in_array($ann['id'], $read_ids);
                                    ?>
                                        <div class="notification-item p-3 hover:bg-gray-50 dark:hover:bg-gray-700 transition <?= !$is_read ? 'bg-blue-50 dark:bg-blue-900/20' : '' ?>" data-id="<?= $ann['id'] ?>">
                                            <div class="flex justify-between items-start">
                                                <div class="flex-1">
                                                    <p class="font-medium text-gray-800 dark:text-white text-sm"><?= htmlspecialchars($ann['title']) ?></p>
                                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 line-clamp-2"><?= htmlspecialchars(strip_tags($ann['content'] ?? '')) ?></p>
                                                    <span class="text-xs text-gray-400 mt-1 block"><?= date('d M Y H:i', strtotime($ann['created_at'])) ?></span>
                                                </div>
                                                <?php if (!$is_read): ?>
                                                    <span class="w-2 h-2 bg-blue-500 rounded-full mt-1"></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div class="p-2 text-center border-t dark:border-gray-700">
                                <a href="admin_announcements.php" class="text-xs text-blue-600 dark:text-blue-400">Lihat semua pengumuman</a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Profile -->
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
                            <span class="hidden md:inline text-gray-700 dark:text-gray-200"><?= htmlspecialchars($user_name) ?></span>
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
            <div class="audio-player-container">
                <!-- Now Playing Box -->
                <div class="now-playing-box">
                    <div class="flex justify-between items-center">
                        <div>
                            <small class="text-gray-500 dark:text-gray-400"><i class="fas fa-play-circle mr-1"></i> Sedang diputar</small>
                            <h5 id="currentTitle" class="text-xl font-semibold text-gray-800 dark:text-white mt-1">Belum ada audio</h5>
                        </div>
                        <span id="currentDuration" class="bg-gray-200 dark:bg-gray-700 px-3 py-1 rounded-full text-sm font-medium text-gray-700 dark:text-gray-300">00:00</span>
                    </div>

                    <div class="progress-container">
                        <span class="time-label" id="currentTime">00:00</span>
                        <input type="range" id="progressSlider" value="0" min="0" max="100" step="0.1">
                        <span class="time-label" id="totalTime">00:00</span>
                    </div>

                    <div class="control-buttons">
                        <button id="prevBtn" title="Sebelumnya"><i class="fas fa-step-backward"></i></button>

                        <!-- Tombol Mundur 10 detik -->
                        <button id="rewind10Btn" class="jump-btn" title="Mundur 10 detik">
                            <i class="fas fa-backward"></i>
                        </button>

                        <button id="playBtn" title="Putar / Jeda"><i class="fas fa-play"></i></button>

                        <!-- Tombol Maju 10 detik -->
                        <button id="forward10Btn" class="jump-btn" title="Maju 10 detik">
                            <i class="fas fa-forward"></i>
                        </button>

                        <button id="nextBtn" title="Selanjutnya"><i class="fas fa-step-forward"></i></button>
                    </div>

                    <div class="volume-container">
                        <i class="fas fa-volume-down text-gray-500 dark:text-gray-400"></i>
                        <input type="range" id="volumeSlider" min="0" max="1" step="0.01" value="0.8">
                        <i class="fas fa-volume-up text-gray-500 dark:text-gray-400"></i>
                    </div>

                    <div class="extra-controls">
                        <label>
                            <i class="fas fa-tachometer-alt"></i> Kecepatan
                            <select id="playbackRate">
                                <option value="0.5">0.5x</option>
                                <option value="0.75">0.75x</option>
                                <option value="1" selected>1x</option>
                                <option value="1.25">1.25x</option>
                                <option value="1.5">1.5x</option>
                                <option value="2">2x</option>
                            </select>
                        </label>
                        <a id="downloadBtn" href="#" download target="_blank" title="Download audio">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </div>

                    <div id="errorMessage" class="error-msg"></div>
                </div>

                <!-- Daftar Audio Kelompok (Kitab) -->
                <div class="flex justify-between items-center mb-3">
                    <h4 class="text-lg font-semibold text-gray-800 dark:text-white"><i class="fas fa-list-ul mr-2 text-blue-500"></i> Daftar Kitab</h4>
                    <span class="text-sm text-gray-500 dark:text-gray-400"><?= count($audio_list) ?> audio</span>
                </div>

                <!-- KOLOM PENCARIAN -->
                <div class="mb-3">
                    <input type="text" id="searchKitab" 
                           placeholder="Cari kitab atau judul audio..." 
                           class="w-full border rounded-lg px-4 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <div class="audio-list-container">
                    <?php if (empty($groups)): ?>
                        <div class="p-6 text-center text-gray-500 dark:text-gray-400">Tidak ada audio ditemukan di Google Drive.</div>
                    <?php else: ?>
                        <?php foreach ($groups as $kitab => $items): ?>
                            <div class="kitab-group" data-kitab="<?= htmlspecialchars($kitab) ?>">
                                <div class="kitab-header" onclick="toggleGroup(this)">
                                    <span><i class="fas fa-folder-open mr-2"></i> <?= htmlspecialchars($kitab) ?> (<?= count($items) ?>)</span>
                                    <button class="play-folder-btn" onclick="event.stopPropagation(); playFolder('<?= htmlspecialchars($kitab) ?>')">
                                        <i class="fas fa-play"></i> Play All
                                    </button>
                                </div>
                                <div class="kitab-body">
                                    <?php foreach ($items as $index => $audio): ?>
                                        <div class="audio-item" 
                                             data-index="<?= $index ?>" 
                                             data-id="<?= $audio['id'] ?>"
                                             data-file-id="<?= htmlspecialchars($audio['id']) ?>"
                                             data-title="<?= htmlspecialchars($audio['judul']) ?>"
                                             data-kitab="<?= htmlspecialchars($kitab) ?>"
                                             data-durasi="">
                                            <div class="flex items-center gap-3">
                                                <i class="fas fa-music text-blue-500"></i>
                                                <span class="font-medium text-gray-800 dark:text-white"><?= htmlspecialchars($audio['judul']) ?></span>
                                            </div>
                                            <span class="durasi"><?= htmlspecialchars($audio['durasi'] ?: '--:--') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <p class="text-center text-xs text-gray-400 dark:text-gray-500 mt-4">
                    <i class="fas fa-info-circle mr-1"></i> Klik judul audio untuk memutar, klik header kitab untuk toggle daftar.
                </p>
            </div>
        </main>
    </div>
</div>

<!-- MINI PLAYER -->
<div id="mini-player" class="hidden">
    <span class="mini-title" id="miniTitle">Judul Audio</span>
    <button id="miniPlayBtn" title="Putar / Jeda"><i class="fas fa-play"></i></button>
    <button id="miniNextBtn" title="Selanjutnya"><i class="fas fa-step-forward"></i></button>
    <button id="miniCloseBtn" class="mini-close" title="Tutup player"><i class="fas fa-times"></i></button>
</div>

<!-- Modal Detail Pengumuman -->
<div id="announcementDetailModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-2xl mx-4 max-h-[85vh] flex flex-col">
        <div class="flex justify-between items-center p-4 border-b dark:border-gray-700">
            <h3 id="announcementModalTitle" class="text-lg font-bold text-gray-800 dark:text-white">Detail Pengumuman</h3>
            <button onclick="closeAnnouncementModal()" class="text-gray-500 hover:text-gray-700 dark:text-gray-400">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="p-4 overflow-y-auto flex-1">
            <div class="mb-3">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Tanggal</label>
                <p id="announcementModalDate" class="text-gray-700 dark:text-gray-300 text-sm"></p>
            </div>
            <div class="mb-3">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Isi Pengumuman</label>
                <div id="announcementModalContent" class="text-gray-800 dark:text-gray-200 text-sm whitespace-pre-line"></div>
            </div>
        </div>
        <div class="flex justify-end p-4 border-t dark:border-gray-700">
            <button onclick="closeAnnouncementModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Tutup</button>
        </div>
    </div>
</div>

<audio id="audioPlayer" style="display:none;"></audio>

<script>
    // ===== DARK MODE =====
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
        darkModeToggle.addEventListener('click', () => {
            setDarkMode(!document.documentElement.classList.contains('dark'));
        });
    }

    // ===== NOTIFIKASI =====
    const notifBtn = document.getElementById('notificationBtn');
    const notifPanel = document.getElementById('notificationPanel');
    const unreadBadge = document.getElementById('unreadBadge');
    const markAllBtn = document.getElementById('markAllReadBtn');

    if (notifBtn) {
        notifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            notifPanel.classList.toggle('hidden');
        });
        document.addEventListener('click', function(event) {
            if (!notifBtn.contains(event.target) && !notifPanel.contains(event.target)) {
                notifPanel.classList.add('hidden');
            }
        });
    }

    async function markAsRead(announcementId, itemElement) {
        let csrfToken = document.querySelector('input[name="csrf_token"]')?.value || 
                        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) {
            console.error('CSRF token tidak ditemukan');
            return;
        }
        try {
            const res = await fetch('api/mark_announcement_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ announcement_id: announcementId, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                itemElement.classList.remove('bg-blue-50', 'dark:bg-blue-900/20');
                const dot = itemElement.querySelector('.w-2.h-2');
                if (dot) dot.remove();
                let currentCount = parseInt(unreadBadge.innerText) || 0;
                if (currentCount > 0) {
                    currentCount--;
                    if (currentCount === 0) {
                        unreadBadge.classList.add('hidden');
                        unreadBadge.innerText = '';
                    } else {
                        unreadBadge.innerText = currentCount;
                    }
                }
            }
        } catch (err) { console.error(err); }
    }

    document.getElementById('notificationList')?.addEventListener('click', async (e) => {
        const item = e.target.closest('.notification-item');
        if (!item) return;
        const id = item.getAttribute('data-id');
        if (!id) return;
        e.stopPropagation();
        await markAsRead(id, item);
        const title = item.querySelector('.font-medium')?.innerText || '';
        const content = item.querySelector('.text-xs.text-gray-500')?.innerText || '';
        const dateText = item.querySelector('span.text-xs.text-gray-400')?.innerText || '';
        document.getElementById('announcementModalTitle').innerText = title;
        document.getElementById('announcementModalContent').innerHTML = content.replace(/\n/g, '<br>');
        document.getElementById('announcementModalDate').innerHTML = dateText;
        const modal = document.getElementById('announcementDetailModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        notifPanel.classList.add('hidden');
    });

    document.getElementById('announcementDetailModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeAnnouncementModal();
    });

    function closeAnnouncementModal() {
        const modal = document.getElementById('announcementDetailModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    if (markAllBtn) {
        markAllBtn.addEventListener('click', async () => {
            const unreadItems = document.querySelectorAll('.notification-item.bg-blue-50, .notification-item.dark\\:bg-blue-900\\/20');
            if (unreadItems.length === 0) return;
            let csrfToken = document.querySelector('input[name="csrf_token"]')?.value || 
                            document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            try {
                const res = await fetch('api/mark_all_announcements_read.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    unreadItems.forEach(item => {
                        item.classList.remove('bg-blue-50', 'dark:bg-blue-900/20');
                        const dot = item.querySelector('.w-2.h-2');
                        if (dot) dot.remove();
                    });
                    unreadBadge.classList.add('hidden');
                    unreadBadge.innerText = '';
                }
            } catch (err) { console.error(err); }
        });
    }

// ===== KUTUB PLAYER ENGINE (DENGAN REAL-TIME PROGRESS) =====
(function() {
    const audio = document.getElementById('audioPlayer');
    const playBtn = document.getElementById('playBtn');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const progressSlider = document.getElementById('progressSlider');
    const volumeSlider = document.getElementById('volumeSlider');
    const currentTimeLabel = document.getElementById('currentTime');
    const totalTimeLabel = document.getElementById('totalTime');
    const currentTitle = document.getElementById('currentTitle');
    const currentDuration = document.getElementById('currentDuration');
    const errorMsg = document.getElementById('errorMessage');
    const downloadBtn = document.getElementById('downloadBtn');
    const playbackRateSelect = document.getElementById('playbackRate');
    const audioItems = document.querySelectorAll('.audio-item');
    const proxyUrl = '<?= $proxy_url ?>';

    // Mini Player
    const miniPlayer = document.getElementById('mini-player');
    const miniTitle = document.getElementById('miniTitle');
    const miniPlayBtn = document.getElementById('miniPlayBtn');
    const miniNextBtn = document.getElementById('miniNextBtn');
    const miniCloseBtn = document.getElementById('miniCloseBtn');

    // Data playlist
    let allAudioData = [];
    audioItems.forEach(el => {
        allAudioData.push({
            id: el.dataset.fileId,
            judul: el.dataset.title,
            kitab: el.dataset.kitab
        });
    });

    let currentIndex = 0;
    let isPlaying = false;
    let isDragging = false;
    let hasError = false;
    let currentItem = null;
    let currentPlaylist = allAudioData;
    let progressInterval = null;
    let metadataCheckInterval = null;

    // ===== FUNGSI FORMAT WAKTU =====
    function formatTime(seconds) {
        if (isNaN(seconds) || !isFinite(seconds) || seconds < 0) return '00:00';
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }

    // ===== UPDATE PROGRESS =====
    function updateProgress() {
        if (!audio || !audio.duration || isNaN(audio.duration) || !isFinite(audio.duration) || audio.duration <= 0) {
            return;
        }
        if (!isDragging) {
            const percent = (audio.currentTime / audio.duration) * 100;
            progressSlider.value = Math.min(percent, 100);
            currentTimeLabel.textContent = formatTime(audio.currentTime);
            // Tampilkan sisa waktu (countdown)
            const remaining = Math.max(0, audio.duration - audio.currentTime);
            totalTimeLabel.textContent = formatTime(remaining);
        }
    }

    // ===== UPDATE MINI PLAYER =====
    function updateMiniPlayer() {
        if (!currentItem) {
            miniPlayer.classList.add('hidden');
            return;
        }
        miniPlayer.classList.remove('hidden');
        miniTitle.textContent = currentItem.judul || 'Audio';
        miniPlayBtn.innerHTML = isPlaying ? '<i class="fas fa-pause"></i>' : '<i class="fas fa-play"></i>';
    }

    // ===== METADATA LOADED =====
    function onMetadataLoaded() {
        console.log('Metadata loaded, duration:', audio.duration);
        if (audio.duration && isFinite(audio.duration) && audio.duration > 0) {
            totalTimeLabel.textContent = formatTime(audio.duration);
            currentDuration.textContent = formatTime(audio.duration);
            if (metadataCheckInterval) {
                clearInterval(metadataCheckInterval);
                metadataCheckInterval = null;
            }
            if (progressInterval) clearInterval(progressInterval);
            progressInterval = setInterval(updateProgress, 250);
        } else {
            totalTimeLabel.textContent = '00:00';
            currentDuration.textContent = '00:00';
        }
    }

    // ===== HANDLE PLAY ERROR =====
    function handlePlayError(err) {
        console.warn('Gagal memutar audio:', err);
        hasError = true;
        errorMsg.textContent = 'Gagal memutar audio. Periksa file ID atau koneksi.';
        errorMsg.style.display = 'block';
        isPlaying = false;
        updatePlayButton();
        updateMiniPlayer();
    }

    // ===== UPDATE TOMBOL PLAY/PAUSE =====
    function updatePlayButton() {
        const icon = isPlaying ? 'pause' : 'play';
        playBtn.innerHTML = `<i class="fas fa-${icon}"></i>`;
        miniPlayBtn.innerHTML = `<i class="fas fa-${icon}"></i>`;
    }

    // ===== LOAD AUDIO =====
    function loadAudio(index, playlist) {
        if (!playlist) playlist = currentPlaylist;
        if (index < 0 || index >= playlist.length) return;
        const item = playlist[index];
        const fileId = item.id;
        const title = item.judul;

        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }
        if (metadataCheckInterval) {
            clearInterval(metadataCheckInterval);
            metadataCheckInterval = null;
        }

        const audioUrl = `${proxyUrl}?fileId=${encodeURIComponent(fileId)}`;
        audio.src = audioUrl;
        audio.load();

        currentTitle.textContent = title;
        currentDuration.textContent = '00:00';
        progressSlider.value = 0;
        currentTimeLabel.textContent = '00:00';
        totalTimeLabel.textContent = '00:00';
        errorMsg.style.display = 'none';
        hasError = false;
        downloadBtn.href = audioUrl;

        currentItem = item;
        audioItems.forEach(el => el.classList.remove('active'));
        audioItems.forEach(el => {
            if (el.dataset.fileId === fileId) el.classList.add('active');
        });

        currentIndex = index;

        audio.removeEventListener('loadedmetadata', onMetadataLoaded);
        audio.addEventListener('loadedmetadata', onMetadataLoaded);

        // Fallback polling
        metadataCheckInterval = setInterval(function() {
            if (audio.duration && isFinite(audio.duration) && audio.duration > 0) {
                console.log('Metadata detected by polling, duration:', audio.duration);
                onMetadataLoaded();
                clearInterval(metadataCheckInterval);
                metadataCheckInterval = null;
            }
        }, 500);

        setTimeout(function() {
            if (metadataCheckInterval) {
                clearInterval(metadataCheckInterval);
                metadataCheckInterval = null;
                if (!audio.duration || !isFinite(audio.duration) || audio.duration <= 0) {
                    console.warn('Gagal memuat metadata audio setelah 10 detik');
                    errorMsg.textContent = 'Gagal memuat durasi audio. File mungkin tidak valid.';
                    errorMsg.style.display = 'block';
                }
            }
        }, 10000);

        if (isPlaying) {
            audio.play().catch(handlePlayError);
        }
        updatePlayButton();
        updateMiniPlayer();
    }

    // ===== TOGGLE PLAY =====
    function togglePlay() {
        if (audio.src === '' || audio.src === window.location.href) {
            if (currentPlaylist.length > 0) {
                loadAudio(0, currentPlaylist);
                audio.play().catch(handlePlayError);
                isPlaying = true;
                updatePlayButton();
                updateMiniPlayer();
            }
            return;
        }
        if (hasError) {
            audio.load();
            audio.play().catch(handlePlayError);
            isPlaying = true;
            updatePlayButton();
            updateMiniPlayer();
            return;
        }
        if (isPlaying) {
            audio.pause();
        } else {
            audio.play().catch(handlePlayError);
        }
        isPlaying = !isPlaying;
        updatePlayButton();
        updateMiniPlayer();
    }

    // ===== NAVIGASI =====
    function prevAudio() {
        if (currentPlaylist.length === 0) return;
        let newIndex = currentIndex - 1;
        if (newIndex < 0) newIndex = currentPlaylist.length - 1;
        loadAudio(newIndex, currentPlaylist);
        if (isPlaying) {
            audio.play().catch(handlePlayError);
        }
    }

    function nextAudio() {
        if (currentPlaylist.length === 0) return;
        let newIndex = currentIndex + 1;
        if (newIndex >= currentPlaylist.length) newIndex = 0;
        loadAudio(newIndex, currentPlaylist);
        if (isPlaying) {
            audio.play().catch(handlePlayError);
        }
    }

    // ===== PLAY FOLDER (KITAB) =====
    window.playFolder = function(kitab) {
        const playlist = allAudioData.filter(item => item.kitab === kitab);
        if (playlist.length === 0) return;
        currentPlaylist = playlist;
        loadAudio(0, playlist);
        audio.play().catch(handlePlayError);
        isPlaying = true;
        updatePlayButton();
        updateMiniPlayer();
        const groups = document.querySelectorAll('.kitab-group');
        groups.forEach(g => {
            const header = g.querySelector('.kitab-header');
            const body = g.querySelector('.kitab-body');
            if (header && header.textContent.includes(kitab)) {
                body.classList.remove('hidden');
            }
        });
    };

    // ===== TOGGLE GROUP =====
    window.toggleGroup = function(header) {
        const body = header.nextElementSibling;
        if (body) body.classList.toggle('hidden');
    };

    // ===== EVENT KLIK ITEM AUDIO =====
    audioItems.forEach((el) => {
        el.addEventListener('click', function() {
            const kitab = this.dataset.kitab;
            const playlist = allAudioData.filter(item => item.kitab === kitab);
            const idx = playlist.findIndex(item => item.id === this.dataset.fileId);
            if (idx !== -1) {
                currentPlaylist = playlist;
                loadAudio(idx, playlist);
                audio.play().catch(handlePlayError);
                isPlaying = true;
                updatePlayButton();
                updateMiniPlayer();
            }
        });
    });

    // ===== EVENT PLAYER =====
    audio.addEventListener('timeupdate', function() {
        if (!isDragging && audio.duration && isFinite(audio.duration) && audio.duration > 0) {
            const percent = (audio.currentTime / audio.duration) * 100;
            progressSlider.value = Math.min(percent, 100);
            currentTimeLabel.textContent = formatTime(audio.currentTime);
        }
    });

    audio.addEventListener('ended', function() {
        nextAudio();
    });

    audio.addEventListener('error', function(e) {
        console.error('Audio error:', e);
        hasError = true;
        errorMsg.textContent = 'Terjadi kesalahan saat memuat audio. Coba lagi.';
        errorMsg.style.display = 'block';
        isPlaying = false;
        updatePlayButton();
        updateMiniPlayer();
    });

    // ===== PROGRESS SLIDER =====
    progressSlider.addEventListener('input', function() {
        isDragging = true;
        if (audio.duration && isFinite(audio.duration) && audio.duration > 0) {
            const time = (this.value / 100) * audio.duration;
            currentTimeLabel.textContent = formatTime(time);
        }
    });

    progressSlider.addEventListener('change', function() {
        isDragging = false;
        if (audio.duration && isFinite(audio.duration) && audio.duration > 0) {
            const time = (this.value / 100) * audio.duration;
            audio.currentTime = time;
            currentTimeLabel.textContent = formatTime(time);
        }
    });
    // ===== LOMPAT 10 DETIK =====
    const rewind10Btn = document.getElementById('rewind10Btn');
    const forward10Btn = document.getElementById('forward10Btn');

    rewind10Btn.addEventListener('click', function() {
        if (audio.duration && isFinite(audio.duration)) {
            const newTime = Math.max(0, audio.currentTime - 10);
            audio.currentTime = newTime;
            currentTimeLabel.textContent = formatTime(newTime);
        }
    });

    forward10Btn.addEventListener('click', function() {
        if (audio.duration && isFinite(audio.duration)) {
            const newTime = Math.min(audio.duration, audio.currentTime + 10);
            audio.currentTime = newTime;
            currentTimeLabel.textContent = formatTime(newTime);
        }
    });
    // ===== VOLUME =====
    volumeSlider.addEventListener('input', function() {
        audio.volume = this.value;
    });
    audio.volume = volumeSlider.value;

    // ===== PLAYBACK RATE =====
    playbackRateSelect.addEventListener('change', function() {
        audio.playbackRate = parseFloat(this.value);
    });

    // ===== TOMBOL UTAMA =====
    playBtn.addEventListener('click', togglePlay);
    prevBtn.addEventListener('click', prevAudio);
    nextBtn.addEventListener('click', nextAudio);

    // ===== MINI PLAYER =====
    miniPlayBtn.addEventListener('click', togglePlay);
    miniNextBtn.addEventListener('click', nextAudio);
    miniCloseBtn.addEventListener('click', function() {
        closePlayer();
    });
    miniTitle.addEventListener('click', function() {
        const mainPlayer = document.querySelector('.now-playing-box');
        if (mainPlayer) {
            mainPlayer.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    // ===== CLOSE PLAYER =====
    function closePlayer() {
        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }
        if (metadataCheckInterval) {
            clearInterval(metadataCheckInterval);
            metadataCheckInterval = null;
        }
        audio.pause();
        audio.src = '';
        isPlaying = false;
        currentItem = null;
        updatePlayButton();
        updateMiniPlayer();
        miniPlayer.classList.add('hidden');
        currentTitle.textContent = 'Belum ada audio';
        currentDuration.textContent = '00:00';
        progressSlider.value = 0;
        currentTimeLabel.textContent = '00:00';
        totalTimeLabel.textContent = '00:00';
        downloadBtn.href = '#';
        audioItems.forEach(el => el.classList.remove('active'));
    }
    window.closePlayer = closePlayer;

    // ===== SHORTCUT SPASI =====
    document.addEventListener('keydown', function(e) {
        if (e.target.tagName === 'INPUT') return;
        if (e.code === 'Space') {
            e.preventDefault();
            togglePlay();
        }
    });

    // ===== DRAG MINI PLAYER =====
    let dragData = { dragging: false, startX: 0, startY: 0, origX: 0, origY: 0 };
    miniPlayer.addEventListener('mousedown', function(e) {
        if (e.target.closest('button')) return;
        dragData.dragging = true;
        dragData.startX = e.clientX;
        dragData.startY = e.clientY;
        const rect = miniPlayer.getBoundingClientRect();
        dragData.origX = rect.left;
        dragData.origY = rect.top;
        miniPlayer.style.transition = 'none';
        miniPlayer.style.cursor = 'grabbing';
    });
    document.addEventListener('mousemove', function(e) {
        if (!dragData.dragging) return;
        const dx = e.clientX - dragData.startX;
        const dy = e.clientY - dragData.startY;
        miniPlayer.style.left = (dragData.origX + dx) + 'px';
        miniPlayer.style.top = (dragData.origY + dy) + 'px';
        miniPlayer.style.right = 'auto';
        miniPlayer.style.bottom = 'auto';
    });
    document.addEventListener('mouseup', function() {
        if (dragData.dragging) {
            dragData.dragging = false;
            miniPlayer.style.cursor = 'grab';
            miniPlayer.style.transition = '';
        }
    });

    // ===== SIDEBAR MOBILE =====
    document.getElementById('openSidebarUserBtn')?.addEventListener('click', function() {
        const sidebar = document.getElementById('sidebarUser');
        if (sidebar) {
            sidebar.classList.remove('-translate-x-full');
            sidebar.classList.add('translate-x-0');
            document.getElementById('sidebarOverlayUser')?.classList.remove('hidden');
            document.body.classList.add('sidebar-open');
        }
    });

    // ===== INISIALISASI =====
    if (audioItems.length > 0) {
        currentPlaylist = allAudioData;
        loadAudio(0, currentPlaylist);
    }
    miniPlayer.classList.add('hidden'); 
})();
// ===== FITUR PENCARIAN KITAB =====
(function() {
    const searchInput = document.getElementById('searchKitab');
    if (!searchInput) return;

    searchInput.addEventListener('input', function() {
        const keyword = this.value.toLowerCase().trim();
        const groups = document.querySelectorAll('.kitab-group');
        let visibleCount = 0;

        groups.forEach(group => {
            const kitabName = group.dataset.kitab ? group.dataset.kitab.toLowerCase() : '';
            const items = group.querySelectorAll('.audio-item');
            let groupHasVisible = false;

            items.forEach(item => {
                const title = item.dataset.title ? item.dataset.title.toLowerCase() : '';
                const match = !keyword || title.includes(keyword) || kitabName.includes(keyword);
                if (match) {
                    item.classList.remove('hidden-item');
                    groupHasVisible = true;
                } else {
                    item.classList.add('hidden-item');
                }
            });

            if (groupHasVisible) {
                group.classList.remove('hidden-group');
                // Buka body kitab jika ada hasil
                const body = group.querySelector('.kitab-body');
                if (body) body.classList.remove('hidden');
                visibleCount++;
            } else {
                group.classList.add('hidden-group');
            }
        });

        // Tampilkan pesan jika tidak ada hasil
        let noResult = document.getElementById('noSearchResult');
        const container = document.querySelector('.audio-list-container');
        if (visibleCount === 0 && keyword) {
            if (!noResult) {
                noResult = document.createElement('div');
                noResult.id = 'noSearchResult';
                noResult.className = 'p-6 text-center text-gray-500 dark:text-gray-400';
                noResult.innerHTML = `<i class="fas fa-search text-2xl mb-2"></i><p>Tidak ada hasil untuk "<strong>${keyword}</strong>"</p>`;
                if (container) container.appendChild(noResult);
            } else {
                noResult.innerHTML = `<i class="fas fa-search text-2xl mb-2"></i><p>Tidak ada hasil untuk "<strong>${keyword}</strong>"</p>`;
            }
        } else {
            if (noResult) noResult.remove();
        }
    });
})();
    
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>