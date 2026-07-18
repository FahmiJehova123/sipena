<?php
// includes/sidebar_user.php - Baca langsung dari sidebar_config.json + filter kondisi
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_role = $_SESSION['user_role'] ?? 'guest';
$user_id = $_SESSION['user_id'] ?? null;

// ================== FUNGSI UNTUK MENDAPATKAN KONDISI USER ==================
function getUserConditions($userId, $userRole) {
    if (!$userId) return [];
    $conditions = [];
    if ($userRole === 'teacher') {
        // Ambil semua kelas yang menjadi wali (homeroom_teacher_id)
        $classes = supabase_admin_request('GET', 'classes', null, ['homeroom_teacher_id' => 'eq.' . $userId]);
        if (is_array($classes)) {
            foreach ($classes as $c) {
                $type = $c['class_type'] ?? 'pagi';
                $grade = $c['grade_level'] ?? null;
                if ($grade !== null) {
                    $conditions[] = ['class_type' => $type, 'grade_level' => (int)$grade];
                }
            }
        }
    } elseif ($userRole === 'student') {
        $user = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $userId]);
        if (is_array($user) && count($user) > 0) {
            $u = $user[0];
            $pagiId = $u['kelas_pagi_id'] ?? null;
            $diniyyahId = $u['kelas_diniyyah_id'] ?? null;
            if ($pagiId) {
                $c = supabase_admin_request('GET', 'classes', null, ['id' => 'eq.' . $pagiId]);
                if (is_array($c) && count($c) > 0) {
                    $conditions[] = ['class_type' => 'pagi', 'grade_level' => (int)$c[0]['grade_level']];
                }
            }
            if ($diniyyahId) {
                $c = supabase_admin_request('GET', 'classes', null, ['id' => 'eq.' . $diniyyahId]);
                if (is_array($c) && count($c) > 0) {
                    $conditions[] = ['class_type' => 'diniyyah', 'grade_level' => (int)$c[0]['grade_level']];
                }
            }
        }
    }
    return $conditions;
}

// ================== FUNGSI EVALUASI KONDISI ==================
function evaluateCondition($menuCond, $userConds) {
    // Jika menu tidak punya condition, tampilkan
    if (empty($menuCond) || !is_array($menuCond)) return true;
    $reqType = $menuCond['class_type'] ?? null;
    $reqGrades = $menuCond['grade_level'] ?? [];
    // Jika condition tidak valid (tidak ada tipe atau grade), tampilkan
    if (empty($reqType) || empty($reqGrades)) return true;
    // Jika user tidak punya kondisi, jangan tampilkan menu dengan kondisi
    if (empty($userConds)) return false;
    foreach ($userConds as $uc) {
        if ($uc['class_type'] === $reqType && in_array($uc['grade_level'], $reqGrades)) {
            return true;
        }
    }
    return false;
}

// ================== BACA MENU DARI JSON ==================
$jsonFile = __DIR__ . '/../middleware/sidebar_config.json';
$nav_items = [];
if (file_exists($jsonFile)) {
    $json = file_get_contents($jsonFile);
    $all_menus = json_decode($json, true);
    if (is_array($all_menus)) {
        // Ambil kondisi user (class_type & grade_level)
        $userConditions = getUserConditions($user_id, $user_role);
        // Filter menu berdasarkan role dan kondisi
        foreach ($all_menus as $menu) {
            // Filter role
            if (!isset($menu['roles']) || !in_array($user_role, $menu['roles'])) continue;
            // Filter kondisi
            if (!evaluateCondition($menu['condition'] ?? null, $userConditions)) continue;
            // Filter children (rekursif sederhana)
            if (!empty($menu['children'])) {
                $filteredChildren = [];
                foreach ($menu['children'] as $child) {
                    if (!isset($child['roles']) || in_array($user_role, $child['roles'])) {
                        if (evaluateCondition($child['condition'] ?? null, $userConditions)) {
                            $filteredChildren[] = $child;
                        }
                    }
                }
                $menu['children'] = $filteredChildren;
            }
            $nav_items[] = $menu;
        }
    }
}

// Fallback jika tidak ada menu (hanya untuk keamanan)
if (empty($nav_items)) {
    // Bisa diisi dengan menu statis atau biarkan kosong
}

// ================== SET ACTIVE MENU (HIGHLIGHT) ==================
$currentUri = $_SERVER['REQUEST_URI'];
$basePath = '/siakad/';
if ($basePath && strpos($currentUri, $basePath) === 0) {
    $currentUri = substr($currentUri, strlen($basePath));
}
$currentPath = strtok($currentUri, '?');

function setActive(&$items, $currentPath) {
    foreach ($items as &$item) {
        if (!empty($item['children'])) {
            setActive($item['children'], $currentPath);
            $hasActive = false;
            foreach ($item['children'] as $child) {
                if (!empty($child['active'])) { $hasActive = true; break; }
            }
            $item['active'] = $hasActive;
        } else {
            $link = $item['link'];
            $compare = strtok($link, '?#');
            $item['active'] = ($currentPath == $compare);
        }
    }
}
setActive($nav_items, $currentPath);

$sidebarCollapsed = isset($_COOKIE['sidebarCollapsed']) && $_COOKIE['sidebarCollapsed'] == 'true';
?>
<style>
    /* (CSS sidebar tetap sama, tidak perlu diubah) */
    .submenu { list-style: none; padding-left: 2rem; margin-top: 0.5rem; margin-bottom: 0.5rem; display: none; }
    .submenu.show { display: block; }
    .has-submenu > a { cursor: pointer; }
    .has-submenu > a i:last-child { transition: transform 0.2s; }
    .has-submenu.open > a i:last-child { transform: rotate(90deg); }
    .sidebar-collapsed .submenu { display: none !important; }
    .sidebar-collapsed .has-submenu > a span:not(.icon-only) { display: none; }
    .sidebar-collapsed .has-submenu > a i:last-child { display: none; }
</style>

<!-- SIDEBAR PC (salin dari kode Anda sebelumnya, gunakan $nav_items) -->
<aside id="sidebarPc" class="hidden md:flex md:flex-col bg-gray-900 text-white transition-all duration-300 <?= $sidebarCollapsed ? 'w-20 sidebar-collapsed' : 'w-64' ?> h-screen sticky top-0">
    <div class="p-4 border-b border-gray-800 flex justify-between items-center">
        <div class="flex items-center space-x-2">
            <i class="fas fa-school text-xl logo-icon <?= $sidebarCollapsed ? '' : 'hidden' ?>"></i>
            <h2 class="text-xl font-bold logo-text <?= $sidebarCollapsed ? 'hidden' : '' ?>">SIPENA</h2>
        </div>
        <button id="toggleSidebarPc" class="text-gray-400 hover:text-white focus:outline-none">
            <i class="fas fa-bars text-xl"></i>
        </button>
    </div>
    <nav class="flex-1 overflow-y-auto py-4">
        <ul class="space-y-2 px-3">
            <?php if (empty($nav_items)): ?>
                <li class="text-gray-500 p-3">Tidak ada menu.</li>
            <?php else: ?>
                <?php foreach ($nav_items as $item): ?>
                    <?php if (!empty($item['children'])): ?>
                        <li class="has-submenu <?= ($item['active'] ?? false) ? 'open' : '' ?>">
                            <a href="#" class="flex items-center p-3 rounded-lg transition duration-200 text-gray-300 hover:bg-gray-800 hover:text-white justify-between">
                                <div class="flex items-center">
                                    <i class="<?= htmlspecialchars($item['icon']) ?> w-6 text-center"></i>
                                    <span class="ml-3 sidebar-text <?= $sidebarCollapsed ? 'hidden' : '' ?>"><?= htmlspecialchars($item['label']) ?></span>
                                </div>
                                <i class="fas fa-chevron-right text-xs sidebar-text <?= $sidebarCollapsed ? 'hidden' : '' ?>"></i>
                            </a>
                            <ul class="submenu <?= ($item['active'] ?? false) ? 'show' : '' ?>">
                                <?php foreach ($item['children'] as $child): ?>
                                    <li>
                                        <a href="<?= htmlspecialchars($child['link']) ?>" class="flex items-center p-2 rounded-lg transition duration-200 <?= ($child['active'] ?? false) ? 'bg-gray-800 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white' ?>">
                                            <i class="<?= htmlspecialchars($child['icon']) ?> w-6 text-center text-sm"></i>
                                            <span class="ml-3 sidebar-text <?= $sidebarCollapsed ? 'hidden' : '' ?>"><?= htmlspecialchars($child['label']) ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li>
                            <a href="<?= htmlspecialchars($item['link']) ?>" class="flex items-center p-3 rounded-lg transition duration-200 <?= ($item['active'] ?? false) ? 'bg-gray-800 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white' ?>">
                                <i class="<?= htmlspecialchars($item['icon']) ?> w-6 text-center"></i>
                                <span class="ml-3 sidebar-text <?= $sidebarCollapsed ? 'hidden' : '' ?>"><?= htmlspecialchars($item['label']) ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </nav>
    <div class="p-4 border-t border-gray-800 text-sm text-gray-500">
        <i class="fas fa-sign-out-alt mr-2"></i>
        <a href="logout.php" class="hover:text-white sidebar-text <?= $sidebarCollapsed ? 'hidden' : '' ?>">Logout</a>
    </div>
</aside>

<!-- SIDEBAR MOBILE (salin dari kode Anda, dengan $nav_items) -->
<div id="sidebarMobile" class="fixed top-0 left-0 h-full w-64 bg-gray-900 text-white z-50 transform -translate-x-full transition-transform duration-300 ease-in-out md:hidden">
    <div class="p-4 border-b border-gray-800 flex justify-between items-center">
        <h2 class="text-xl font-bold"><i class="fas fa-school mr-2"></i>SIPENA</h2>
        <button id="closeMobileSidebar" class="text-gray-400 hover:text-white">
            <i class="fas fa-times text-xl"></i>
        </button>
    </div>
    <nav class="overflow-y-auto py-4 h-full">
        <ul class="space-y-2 px-3">
            <?php if (empty($nav_items)): ?>
                <li class="text-gray-500 p-3">Tidak ada menu</li>
            <?php else: ?>
                <?php foreach ($nav_items as $item): ?>
                    <?php if (!empty($item['children'])): ?>
                        <li class="has-submenu <?= ($item['active'] ?? false) ? 'open' : '' ?>">
                            <a href="#" class="flex items-center p-3 rounded-lg transition duration-200 text-gray-300 hover:bg-gray-800 hover:text-white justify-between">
                                <div class="flex items-center">
                                    <i class="<?= htmlspecialchars($item['icon']) ?> w-6 text-center"></i>
                                    <span class="ml-3"><?= htmlspecialchars($item['label']) ?></span>
                                </div>
                                <i class="fas fa-chevron-right text-xs"></i>
                            </a>
                            <ul class="submenu <?= ($item['active'] ?? false) ? 'show' : '' ?>">
                                <?php foreach ($item['children'] as $child): ?>
                                    <li>
                                        <a href="<?= htmlspecialchars($child['link']) ?>" class="flex items-center p-2 rounded-lg transition duration-200 <?= ($child['active'] ?? false) ? 'bg-gray-800 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white' ?>">
                                            <i class="<?= htmlspecialchars($child['icon']) ?> w-6 text-center text-sm"></i>
                                            <span class="ml-3"><?= htmlspecialchars($child['label']) ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li>
                            <a href="<?= htmlspecialchars($item['link']) ?>" class="flex items-center p-3 rounded-lg transition duration-200 <?= ($item['active'] ?? false) ? 'bg-gray-800 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white' ?>">
                                <i class="<?= htmlspecialchars($item['icon']) ?> w-6 text-center"></i>
                                <span class="ml-3"><?= htmlspecialchars($item['label']) ?></span>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            <li class="mt-8 pt-4 border-t border-gray-800">
                <a href="logout.php" class="flex items-center p-3 text-gray-300 hover:bg-gray-800 rounded-lg">
                    <i class="fas fa-sign-out-alt w-6"></i><span class="ml-3">Logout</span>
                </a>
            </li>
        </ul>
    </nav>
</div>

<script>
    // (JavaScript untuk toggle sidebar, sama seperti sebelumnya)
    document.addEventListener('DOMContentLoaded', function() {
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

        function initSubmenuToggle(container) {
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
        const openBtn = document.getElementById('openMobileSidebarBtn') || document.getElementById('openSidebarUserBtn');
        const closeBtn = document.getElementById('closeMobileSidebar');
        if (openBtn) {
            openBtn.removeAttribute('onclick');
            openBtn.addEventListener('click', window.openMobileSidebar);
        }
        if (closeBtn) closeBtn.addEventListener('click', window.closeMobileSidebar);
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 768) {
                if (mainContainer) mainContainer.style.marginLeft = '';
                if (sidebarMobile) sidebarMobile.classList.add('-translate-x-full');
            }
        });
    });
</script>