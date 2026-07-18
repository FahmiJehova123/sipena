<?php
// sidebar.php - Admin dengan submenu (independen, tanpa variabel global)
if (session_status() === PHP_SESSION_NONE) session_start();

// Definisikan menu dengan submenu (gunakan variabel lokal unik)
$sidebarMenus = [
    ['icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'link' => 'admin_dashboard.php'],

    // Submenu Manajemen User
    ['icon' => 'fas fa-users', 'label' => 'Manajemen User', 'link' => '#', 'children' => [
        ['icon' => 'fas fa-chalkboard-teacher', 'label' => 'Manajemen Guru', 'link' => 'manage_users.php?role=teacher'],
        ['icon' => 'fas fa-user-graduate', 'label' => 'Manajemen Murid', 'link' => 'manage_users.php?role=student'],
        ['icon' => 'fa-solid fa-user-lock', 'label' => 'Manajemen User', 'link' => 'manage_users.php?role=user'],
        ['icon' => 'fas fa-user-shield', 'label' => 'Manajemen Admin', 'link' => 'manage_admins.php']
    ]],

    // Submenu Akademik
    ['icon' => 'fas fa-building', 'label' => 'Akademik', 'link' => '#', 'children' => [
        ['icon' => 'fa-solid fa-user-graduate', 'label' => 'Detail Siswa', 'link' => 'student_detail.php'],
        ['icon' => 'fas fa-building', 'label' => 'Manajemen Kelas', 'link' => 'manage_classes.php'],
        ['icon' => 'fas fa-people-arrows', 'label' => 'Manajemen Rombel', 'link' => 'manage_rombel.php'],
        ['icon' => 'fas fa-book', 'label' => 'Mata Pelajaran', 'link' => 'manage_subjects.php'],
        ['icon' => 'fas fa-file-alt', 'label' => 'Manajemen Soal', 'link' => 'manage_soal.php'],
        ['icon' => 'fas fa-calendar-alt', 'label' => 'Manajemen Jadwal', 'link' => 'manage_schedules.php'],
        ['icon' => 'fas fa-calendar-check', 'label' => 'Manajemen Kegiatan', 'link' => 'manage_activities.php'],
        ['icon' => 'fas fa-chart-line', 'label' => 'Laporan', 'link' => 'reports.php'],
        ['icon' => 'fa-solid fa-table-list', 'label' => 'Manajemen Nilai', 'link' => 'manage_exam_scores.php'],
        ['icon' => 'fa-solid fa-file-lines', 'label' => 'Leger Ujian', 'link' => 'manage_leger_ujian.php'],
        ['icon' => 'fa-solid fa-file-contract', 'label' => 'Manajemen Ijazah', 'link' => 'manage_diploma_scores.php'],
        ['icon' => 'fa-solid fa-wave-square', 'label' => 'Monitoring Nilai', 'link' => 'monitoring_scores.php']
    ]],

    // Menu tanpa submenu
    ['icon' => 'fa-solid fa-headphones', 'label' => 'Kitab Audio', 'link' => 'audio_player'],
    ['icon' => 'fa-solid fa-bell', 'label' => 'Manajemen Pengumuman', 'link' => 'manajemen_pengumuman'],
    ['icon' => 'fa-solid fa-bell', 'label' => 'Lokasi Kelas', 'link' => 'class_map.php'],
    ['icon' => 'fa-solid fa-toggle-on', 'label' => 'Sidebar Menu', 'link' => 'manage_sidebar.php'],
    ['icon' => 'fas fa-cog', 'label' => 'Pengaturan', 'link' => 'profile.php']
];

// Tentukan menu aktif berdasarkan URL
$current_uri = $_SERVER['REQUEST_URI'];
$base_path = '/siakad/';
if ($base_path && strpos($current_uri, $base_path) === 0) {
    $current_uri = substr($current_uri, strlen($base_path));
}
$current_path = strtok($current_uri, '?');

function setActiveMenu(&$items, $current_path, $current_uri) {
    $anyActive = false;
    foreach ($items as &$item) {
        if (isset($item['children'])) {
            $childActive = setActiveMenu($item['children'], $current_path, $current_uri);
            $item['active'] = $childActive;
            if ($childActive) $anyActive = true;
        } else {
            $link = $item['link'];
            if (strpos($link, '?') !== false) {
                $active = ($current_uri == $link);
            } else {
                $active = ($current_path == $link);
            }
            $item['active'] = $active;
            if ($active) $anyActive = true;
        }
    }
    return $anyActive;
}
setActiveMenu($sidebarMenus, $current_path, $current_uri);

$sidebarCollapsed = isset($_COOKIE['sidebarCollapsed']) && $_COOKIE['sidebarCollapsed'] == 'true';
?>
<style>
    /* ========== CSS MODERN & FUTURISTIK UNTUK SIDEBAR ========== */
    /* Transisi halus untuk semua elemen */
    #sidebarPc a, #sidebarPc .has-submenu > a, .submenu a {
        position: relative;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        overflow: hidden;
    }
    /* Efek hover: scale icon, glow border, transform X */
    #sidebarPc a:hover, #sidebarPc .has-submenu > a:hover {
        background: linear-gradient(135deg, rgba(59,130,246,0.2) 0%, rgba(139,92,246,0.2) 100%);
        transform: translateX(4px);
        box-shadow: 0 0 8px rgba(59,130,246,0.4);
    }
    #sidebarPc a:hover i, #sidebarPc .has-submenu > a:hover i {
        transform: scale(1.1);
        filter: drop-shadow(0 0 4px rgba(59,130,246,0.6));
        transition: transform 0.2s ease;
    }
    /* Menu active dengan efek gradien dan border neon */
    #sidebarPc a.active, #sidebarPc .has-submenu > a.active {
        background: linear-gradient(135deg, rgba(59,130,246,0.3), rgba(139,92,246,0.3));
        border-left: 3px solid #3b82f6;
        box-shadow: 0 0 12px rgba(59,130,246,0.5);
        transform: translateX(2px);
    }
    #sidebarPc a.active i, #sidebarPc .has-submenu > a.active i {
        color: #60a5fa;
        text-shadow: 0 0 6px #3b82f6;
    }
    /* Submenu items */
    .submenu a {
        transition: all 0.2s ease;
    }
    .submenu a:hover {
        background: rgba(59,130,246,0.2);
        transform: translateX(4px);
        padding-left: 0.75rem !important;
        border-left: 2px solid #3b82f6;
    }
    .submenu a.active {
        background: rgba(59,130,246,0.25);
        border-left: 2px solid #60a5fa;
        color: white;
    }
    /* Animasi submenu slide */
    .submenu {
        overflow: hidden;
        transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .submenu.show {
        display: block;
        animation: slideDown 0.3s ease-out forwards;
    }
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    /* Efek saat sidebar collapsed */
    .sidebar-collapsed a:hover i {
        transform: scale(1.2);
    }
    /* Tambahan efek glassmorphism (opsional) */
    #sidebarPc {
        backdrop-filter: blur(4px);
        background: rgba(17,24,39,0.95);
    }
    .dark #sidebarPc {
        background: rgba(0,0,0,0.85);
        backdrop-filter: blur(8px);
    }
    /* Menonaktifkan backdrop filter di mobile untuk performa */
    @media (max-width: 768px) {
        #sidebarPc, .dark #sidebarPc {
            backdrop-filter: none;
        }
    }
    /* Scrollbar custom */
    #sidebarPc ::-webkit-scrollbar {
        width: 4px;
    }
    #sidebarPc ::-webkit-scrollbar-track {
        background: #1f2937;
    }
    #sidebarPc ::-webkit-scrollbar-thumb {
        background: #3b82f6;
        border-radius: 4px;
    }
    /* Menu parent (has-submenu) ketika active */
    .has-submenu > a.active + .submenu {
        animation: slideDown 0.3s ease-out;
    }
    /* Fallback untuk transition opacity */
    .submenu {
        transition: opacity 0.2s ease, transform 0.2s ease;
    }
    .submenu.show {
        opacity: 1;
        transform: translateY(0);
    }
    .submenu:not(.show) {
        opacity: 0;
        transform: translateY(-10px);
        display: none;
    }
    /* Efek hover pada ikon panah */
    .has-submenu > a i:last-child {
        transition: transform 0.3s ease, color 0.2s;
    }
    .has-submenu > a:hover i:last-child {
        color: #60a5fa;
    }
    /* Klasik */
    .submenu { list-style: none; padding-left: 2rem; margin-top: 0.5rem; margin-bottom: 0.5rem; }
    .has-submenu > a { cursor: pointer; }
    .has-submenu.open > a i:last-child { transform: rotate(90deg); }
    .sidebar-collapsed .submenu { display: none !important; }
    .sidebar-collapsed .has-submenu > a span:not(.icon-only) { display: none; }
    .sidebar-collapsed .has-submenu > a i:last-child { display: none; }
</style>

<!-- SIDEBAR PC (HTML sama persis seperti sebelumnya, hanya CSS yang diperbarui) -->
<aside id="sidebarPc" class="hidden md:flex md:flex-col bg-gray-900 text-white transition-all duration-300 <?= $sidebarCollapsed ? 'w-20 sidebar-collapsed' : 'w-64' ?> h-screen sticky top-0">
    <div class="p-4 border-b border-gray-800 flex justify-between items-center">
        <div class="flex items-center space-x-2">
            <i class="fas fa-school text-xl logo-icon <?= $sidebarCollapsed ? '' : 'hidden' ?>"></i>
            <h2 class="text-xl font-bold logo-text <?= $sidebarCollapsed ? 'hidden' : '' ?>">SIPENA Admin</h2>
        </div>
        <button id="toggleSidebarPc" class="text-gray-400 hover:text-white focus:outline-none">
            <i class="fas fa-bars text-xl"></i>
        </button>
    </div>
    <nav class="flex-1 overflow-y-auto py-4">
        <ul class="space-y-2 px-3">
            <?php foreach ($sidebarMenus as $item): ?>
                <?php if (isset($item['children'])): ?>
                    <li class="has-submenu <?= ($item['active'] ?? false) ? 'open' : '' ?>">
                        <a href="#" class="flex items-center p-3 rounded-lg transition duration-200 justify-between <?= ($item['active'] ?? false) ? 'active' : '' ?>">
                            <div class="flex items-center">
                                <i class="<?= $item['icon'] ?> w-6 text-center"></i>
                                <span class="ml-3 sidebar-text <?= $sidebarCollapsed ? 'hidden' : '' ?>"><?= htmlspecialchars($item['label']) ?></span>
                            </div>
                            <i class="fas fa-chevron-right text-xs sidebar-text <?= $sidebarCollapsed ? 'hidden' : '' ?>"></i>
                        </a>
                        <ul class="submenu <?= ($item['active'] ?? false) ? 'show' : '' ?>">
                            <?php foreach ($item['children'] as $child): ?>
                                <li>
                                    <a href="<?= htmlspecialchars($child['link']) ?>" class="flex items-center p-2 rounded-lg transition duration-200 <?= ($child['active'] ?? false) ? 'active' : '' ?>">
                                        <i class="<?= $child['icon'] ?> w-6 text-center text-sm"></i>
                                        <span class="ml-3 sidebar-text <?= $sidebarCollapsed ? 'hidden' : '' ?>"><?= htmlspecialchars($child['label']) ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php else: ?>
                    <li>
                        <a href="<?= htmlspecialchars($item['link']) ?>" class="flex items-center p-3 rounded-lg transition duration-200 <?= ($item['active'] ?? false) ? 'active' : '' ?>">
                            <i class="<?= $item['icon'] ?> w-6 text-center"></i>
                            <span class="ml-3 sidebar-text <?= $sidebarCollapsed ? 'hidden' : '' ?>"><?= htmlspecialchars($item['label']) ?></span>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </nav>
    <div class="p-4 border-t border-gray-800 text-sm text-gray-500">
        <i class="fas fa-sign-out-alt mr-2"></i>
        <a href="logout.php" class="hover:text-white sidebar-text <?= $sidebarCollapsed ? 'hidden' : '' ?>">Logout</a>
    </div>
</aside>

<!-- SIDEBAR MOBILE (sama seperti sebelumnya, dengan class active ditambahkan) -->
<div id="sidebarMobile" class="fixed top-0 left-0 h-full w-64 bg-gray-900 text-white z-50 transform -translate-x-full transition-transform duration-300 ease-in-out md:hidden">
    <div class="p-4 border-b border-gray-800 flex justify-between items-center">
        <h2 class="text-xl font-bold"><i class="fas fa-school mr-2"></i>SIAKAD Admin</h2>
        <button id="closeMobileSidebar" class="text-gray-400 hover:text-white">
            <i class="fas fa-times text-xl"></i>
        </button>
    </div>
    <nav class="overflow-y-auto py-4 h-full">
        <ul class="space-y-2 px-3">
            <?php foreach ($sidebarMenus as $item): ?>
                <?php if (isset($item['children'])): ?>
                    <li class="has-submenu <?= ($item['active'] ?? false) ? 'open' : '' ?>">
                        <a href="#" class="flex items-center p-3 rounded-lg transition duration-200 justify-between <?= ($item['active'] ?? false) ? 'active' : '' ?>">
                            <div class="flex items-center">
                                <i class="<?= $item['icon'] ?> w-6 text-center"></i>
                                <span class="ml-3"><?= htmlspecialchars($item['label']) ?></span>
                            </div>
                            <i class="fas fa-chevron-right text-xs"></i>
                        </a>
                        <ul class="submenu <?= ($item['active'] ?? false) ? 'show' : '' ?>">
                            <?php foreach ($item['children'] as $child): ?>
                                <li>
                                    <a href="<?= htmlspecialchars($child['link']) ?>" class="flex items-center p-2 rounded-lg transition duration-200 <?= ($child['active'] ?? false) ? 'active' : '' ?>">
                                        <i class="<?= $child['icon'] ?> w-6 text-center text-sm"></i>
                                        <span class="ml-3"><?= htmlspecialchars($child['label']) ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php else: ?>
                    <li>
                        <a href="<?= htmlspecialchars($item['link']) ?>" class="flex items-center p-3 rounded-lg transition duration-200 <?= ($item['active'] ?? false) ? 'active' : '' ?>">
                            <i class="<?= $item['icon'] ?> w-6 text-center"></i>
                            <span class="ml-3"><?= htmlspecialchars($item['label']) ?></span>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
            <li class="mt-8 pt-4 border-t border-gray-800">
                <a href="logout.php" class="flex items-center p-3 text-gray-300 hover:bg-gray-800 rounded-lg">
                    <i class="fas fa-sign-out-alt w-6"></i><span class="ml-3">Logout</span>
                </a>
            </li>
        </ul>
    </nav>
</div>

<script>
// (JavaScript untuk collapse, toggle submenu, mobile)
document.addEventListener('DOMContentLoaded', function() {
    // PC Collapse
    const sidebarPc = document.getElementById('sidebarPc');
    const togglePc = document.getElementById('toggleSidebarPc');
    if (togglePc && sidebarPc) {
        togglePc.addEventListener('click', () => {
            const isCollapsed = sidebarPc.classList.contains('w-20');
            if (isCollapsed) {
                sidebarPc.classList.replace('w-20', 'w-64');
                sidebarPc.classList.remove('sidebar-collapsed');
                document.cookie = "sidebarCollapsed=false; path=/";
            } else {
                sidebarPc.classList.replace('w-64', 'w-20');
                sidebarPc.classList.add('sidebar-collapsed');
                document.cookie = "sidebarCollapsed=true; path=/";
            }
            document.querySelectorAll('#sidebarPc .sidebar-text, #sidebarPc .logo-text').forEach(el => el.classList.toggle('hidden', !isCollapsed));
            document.querySelectorAll('#sidebarPc .logo-icon').forEach(el => el.classList.toggle('hidden', isCollapsed));
        });
    }

    // Submenu toggle (PC dan Mobile)
    function initSubmenuToggle(container) {
        if (!container) return;
        container.querySelectorAll('.has-submenu > a').forEach(parentLink => {
            parentLink.addEventListener('click', function(e) {
                e.preventDefault();
                const li = this.closest('.has-submenu');
                const submenu = li.querySelector('.submenu');
                if (submenu) {
                    submenu.classList.toggle('show');
                    li.classList.toggle('open');
                }
            });
        });
    }
    initSubmenuToggle(document.getElementById('sidebarPc'));
    initSubmenuToggle(document.getElementById('sidebarMobile'));

    // Mobile sidebar
    const sidebarMobile = document.getElementById('sidebarMobile');
    const mainContainer = document.querySelector('.main-content-container');
    window.openMobileSidebar = function() {
        if (sidebarMobile) {
            sidebarMobile.classList.remove('-translate-x-full');
            sidebarMobile.classList.add('translate-x-0');
            if (mainContainer) mainContainer.style.marginLeft = '16rem';
            document.body.classList.add('overflow-hidden');
        }
    };
    window.closeMobileSidebar = function() {
        if (sidebarMobile) {
            sidebarMobile.classList.add('-translate-x-full');
            sidebarMobile.classList.remove('translate-x-0');
            if (mainContainer) mainContainer.style.marginLeft = '';
            document.body.classList.remove('overflow-hidden');
        }
    };
    const openBtn = document.getElementById('openMobileSidebarBtn');
    const closeBtn = document.getElementById('closeMobileSidebar');
    if (openBtn) openBtn.addEventListener('click', window.openMobileSidebar);
    if (closeBtn) closeBtn.addEventListener('click', window.closeMobileSidebar);
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) {
            if (mainContainer) mainContainer.style.marginLeft = '';
            if (sidebarMobile) sidebarMobile.classList.add('-translate-x-full');
        }
    });
});
</script>