<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    header('Location: login.php');
    exit;
}
$page_title = 'Manajemen Sidebar Menu';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/header.php';

$jsonFile = __DIR__ . '/middleware/sidebar_config.json';

function readConfig($file) {
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);
    return json_decode($content, true) ?? [];
}
function writeConfig($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Proses form CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $config = readConfig($jsonFile);
    
    if ($action === 'add_main') {
        $new = [
            'icon' => $_POST['icon'],
            'label' => $_POST['label'],
            'link' => $_POST['link'],
            'roles' => array_map('trim', explode(',', $_POST['roles'])),
            'children' => []
        ];
        if (!empty($_POST['class_type']) && !empty($_POST['grade_level'])) {
            $new['condition'] = [
                'class_type' => $_POST['class_type'],
                'grade_level' => array_map('intval', explode(',', $_POST['grade_level']))
            ];
        }
        $config[] = $new;
        writeConfig($jsonFile, $config);
    }
    elseif ($action === 'edit_main') {
        $idx = (int)$_POST['index'];
        if (isset($config[$idx])) {
            $config[$idx]['icon'] = $_POST['icon'];
            $config[$idx]['label'] = $_POST['label'];
            $config[$idx]['link'] = $_POST['link'];
            $config[$idx]['roles'] = array_map('trim', explode(',', $_POST['roles']));
            if (!empty($_POST['class_type']) && !empty($_POST['grade_level'])) {
                $config[$idx]['condition'] = [
                    'class_type' => $_POST['class_type'],
                    'grade_level' => array_map('intval', explode(',', $_POST['grade_level']))
                ];
            } else {
                unset($config[$idx]['condition']);
            }
            writeConfig($jsonFile, $config);
        }
    }
    elseif ($action === 'delete_main') {
        $idx = (int)$_POST['index'];
        if (isset($config[$idx])) {
            array_splice($config, $idx, 1);
            writeConfig($jsonFile, $config);
        }
    }
    elseif ($action === 'add_sub') {
        $parent = (int)$_POST['parent_index'];
        if (isset($config[$parent])) {
            $new = [
                'icon' => $_POST['icon'],
                'label' => $_POST['label'],
                'link' => $_POST['link'],
                'roles' => array_map('trim', explode(',', $_POST['roles']))
            ];
            if (!empty($_POST['class_type']) && !empty($_POST['grade_level'])) {
                $new['condition'] = [
                    'class_type' => $_POST['class_type'],
                    'grade_level' => array_map('intval', explode(',', $_POST['grade_level']))
                ];
            }
            $config[$parent]['children'][] = $new;
            writeConfig($jsonFile, $config);
        }
    }
    elseif ($action === 'edit_sub') {
        $parent = (int)$_POST['parent_index'];
        $child = (int)$_POST['child_index'];
        if (isset($config[$parent]['children'][$child])) {
            $config[$parent]['children'][$child]['icon'] = $_POST['icon'];
            $config[$parent]['children'][$child]['label'] = $_POST['label'];
            $config[$parent]['children'][$child]['link'] = $_POST['link'];
            $config[$parent]['children'][$child]['roles'] = array_map('trim', explode(',', $_POST['roles']));
            if (!empty($_POST['class_type']) && !empty($_POST['grade_level'])) {
                $config[$parent]['children'][$child]['condition'] = [
                    'class_type' => $_POST['class_type'],
                    'grade_level' => array_map('intval', explode(',', $_POST['grade_level']))
                ];
            } else {
                unset($config[$parent]['children'][$child]['condition']);
            }
            writeConfig($jsonFile, $config);
        }
    }
    elseif ($action === 'delete_sub') {
        $parent = (int)$_POST['parent_index'];
        $child = (int)$_POST['child_index'];
        if (isset($config[$parent]['children'][$child])) {
            array_splice($config[$parent]['children'], $child, 1);
            writeConfig($jsonFile, $config);
        }
    }
    header('Location: manage_sidebar.php');
    exit;
}

$config = readConfig($jsonFile);

// Navigasi sidebar
require_once __DIR__ . '/includes/nav_items.php';
foreach ($nav_items as &$item) { $item['active'] = false; }
unset($item);
$nav_items[0]['active'] = true;


require_once __DIR__ . '/includes/header.php';
?>
<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    
    <div class="main-content-container flex-1 transition-all duration-300 ease-in-out">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openMobileSidebarBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Dashboard</h1>
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
        
<div class="container mx-auto p-4">
    <h1 class="text-2xl font-bold mb-4">Manajemen Sidebar Menu</h1>
    <button onclick="openMainModal()" class="bg-green-600 text-white px-4 py-2 rounded mb-4">Tambah Menu Utama</button>
    <div class="space-y-3">
        <?php foreach ($config as $idx => $menu): ?>
            <div class="bg-white dark:bg-gray-800 rounded shadow p-3">
                <div class="flex justify-between items-center">
                    <div>
                        <i class="<?= htmlspecialchars($menu['icon']) ?> mr-2"></i>
                        <strong><?= htmlspecialchars($menu['label']) ?></strong>
                        <span class="text-sm text-gray-500">(<?= htmlspecialchars($menu['link']) ?>)</span>
                        <span class="text-xs bg-gray-200 px-1 rounded">Roles: <?= implode(',', $menu['roles']) ?></span>
                        <?php if (isset($menu['condition'])): ?>
                            <span class="text-xs bg-blue-100 px-1 rounded">Kondisi: <?= $menu['condition']['class_type'] ?> / <?= implode(',', $menu['condition']['grade_level']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <button onclick="editMain(<?= $idx ?>)" class="bg-yellow-500 text-white px-2 py-1 rounded text-sm">Edit</button>
                        <button onclick="deleteMain(<?= $idx ?>)" class="bg-red-600 text-white px-2 py-1 rounded text-sm">Hapus</button>
                        <button onclick="openSubModal(<?= $idx ?>)" class="bg-blue-600 text-white px-2 py-1 rounded text-sm">Tambah Submenu</button>
                    </div>
                </div>
                <?php if (!empty($menu['children'])): ?>
                    <div class="ml-6 mt-2 border-l-2 pl-2">
                        <?php foreach ($menu['children'] as $cidx => $child): ?>
                            <div class="flex justify-between items-center py-1">
                                <div>
                                    <i class="<?= htmlspecialchars($child['icon']) ?> mr-1"></i>
                                    <?= htmlspecialchars($child['label']) ?>
                                    <span class="text-xs text-gray-500">(<?= htmlspecialchars($child['link']) ?>)</span>
                                    <span class="text-xs bg-gray-200 px-1 rounded">Roles: <?= implode(',', $child['roles']) ?></span>
                                    <?php if (isset($child['condition'])): ?>
                                        <span class="text-xs bg-blue-100 px-1 rounded">Kondisi: <?= $child['condition']['class_type'] ?> / <?= implode(',', $child['condition']['grade_level']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <button onclick="editSub(<?= $idx ?>, <?= $cidx ?>)" class="bg-yellow-500 text-white px-1 py-0.5 rounded text-xs">Edit</button>
                                    <button onclick="deleteSub(<?= $idx ?>, <?= $cidx ?>)" class="bg-red-600 text-white px-1 py-0.5 rounded text-xs">Hapus</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal Menu Utama -->
<div id="mainModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-96 max-w-full">
        <h3 id="mainModalTitle" class="text-lg font-bold mb-4">Tambah Menu Utama</h3>
        <form method="POST">
            <input type="hidden" name="action" id="mainAction">
            <input type="hidden" name="index" id="mainIndex">
            <div class="mb-2"><label>Icon</label><input type="text" name="icon" id="mainIcon" class="w-full border rounded p-1" required></div>
            <div class="mb-2"><label>Label</label><input type="text" name="label" id="mainLabel" class="w-full border rounded p-1" required></div>
            <div class="mb-2"><label>Link</label><input type="text" name="link" id="mainLink" class="w-full border rounded p-1" required></div>
            <div class="mb-2"><label>Roles (pisah koma)</label><input type="text" name="roles" id="mainRoles" class="w-full border rounded p-1" required></div>
            <div class="mb-2"><label>Class Type (opsional)</label><select name="class_type" id="mainClassType" class="w-full border rounded p-1"><option value="">-</option><option value="pagi">Pagi</option><option value="diniyyah">Diniyyah</option></select></div>
            <div class="mb-2"><label>Grade Level (opsional, pisah koma)</label><input type="text" name="grade_level" id="mainGradeLevel" class="w-full border rounded p-1" placeholder="contoh: 12,13"></div>
            <div class="flex justify-end gap-2"><button type="button" onclick="closeMainModal()" class="bg-gray-400 text-white px-3 py-1 rounded">Batal</button><button type="submit" class="bg-blue-600 text-white px-3 py-1 rounded">Simpan</button></div>
        </form>
    </div>
</div>

<!-- Modal Submenu -->
<div id="subModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-96 max-w-full">
        <h3 id="subModalTitle" class="text-lg font-bold mb-4">Tambah Submenu</h3>
        <form method="POST">
            <input type="hidden" name="action" id="subAction">
            <input type="hidden" name="parent_index" id="subParentIndex">
            <input type="hidden" name="child_index" id="subChildIndex">
            <div class="mb-2"><label>Icon</label><input type="text" name="icon" id="subIcon" class="w-full border rounded p-1" required></div>
            <div class="mb-2"><label>Label</label><input type="text" name="label" id="subLabel" class="w-full border rounded p-1" required></div>
            <div class="mb-2"><label>Link</label><input type="text" name="link" id="subLink" class="w-full border rounded p-1" required></div>
            <div class="mb-2"><label>Roles (pisah koma)</label><input type="text" name="roles" id="subRoles" class="w-full border rounded p-1" required></div>
            <div class="mb-2"><label>Class Type (opsional)</label><select name="class_type" id="subClassType" class="w-full border rounded p-1"><option value="">-</option><option value="pagi">Pagi</option><option value="diniyyah">Diniyyah</option></select></div>
            <div class="mb-2"><label>Grade Level (opsional, pisah koma)</label><input type="text" name="grade_level" id="subGradeLevel" class="w-full border rounded p-1" placeholder="contoh: 12,13"></div>
            <div class="flex justify-end gap-2"><button type="button" onclick="closeSubModal()" class="bg-gray-400 text-white px-3 py-1 rounded">Batal</button><button type="submit" class="bg-blue-600 text-white px-3 py-1 rounded">Simpan</button></div>
        </form>
    </div>
</div>
</div>
<script>
// ========== DARK MODE ==========
const darkModeToggle = document.getElementById('darkModeToggle');
function setDarkMode(isDark) {
    if (isDark) document.documentElement.classList.add('dark');
    else document.documentElement.classList.remove('dark');
    localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled');
    if (darkModeToggle) {
        const moonIcon = darkModeToggle.querySelector('.fa-moon');
        const sunIcon = darkModeToggle.querySelector('.fa-sun');
        if (moonIcon && sunIcon) {
            moonIcon.classList.toggle('hidden', isDark);
            sunIcon.classList.toggle('hidden', !isDark);
        }
    }
}
const savedDarkMode = localStorage.getItem('darkMode');
if (savedDarkMode === 'enabled') setDarkMode(true);
else if (savedDarkMode === 'disabled') setDarkMode(false);
else setDarkMode(window.matchMedia('(prefers-color-scheme: dark)').matches);
if (darkModeToggle) {
    darkModeToggle.addEventListener('click', () => {
        setDarkMode(!document.documentElement.classList.contains('dark'));
    });
}
    
    
const menus = <?= json_encode($config) ?>;

function openMainModal(idx = -1) {
    const modal = document.getElementById('mainModal');
    document.getElementById('mainAction').value = idx === -1 ? 'add_main' : 'edit_main';
    document.getElementById('mainIndex').value = idx;
    if (idx === -1) {
        document.getElementById('mainModalTitle').innerText = 'Tambah Menu Utama';
        document.getElementById('mainIcon').value = 'fas fa-circle';
        document.getElementById('mainLabel').value = '';
        document.getElementById('mainLink').value = '#';
        document.getElementById('mainRoles').value = 'teacher,student';
        document.getElementById('mainClassType').value = '';
        document.getElementById('mainGradeLevel').value = '';
    } else {
        const m = menus[idx];
        document.getElementById('mainModalTitle').innerText = 'Edit Menu Utama';
        document.getElementById('mainIcon').value = m.icon;
        document.getElementById('mainLabel').value = m.label;
        document.getElementById('mainLink').value = m.link;
        document.getElementById('mainRoles').value = m.roles.join(',');
        document.getElementById('mainClassType').value = m.condition?.class_type || '';
        document.getElementById('mainGradeLevel').value = (m.condition?.grade_level || []).join(',');
    }
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closeMainModal() { document.getElementById('mainModal').classList.add('hidden'); }
function deleteMain(idx) { if(confirm('Hapus menu utama?')) { let f=document.createElement('form');f.method='POST';f.innerHTML=`<input name="action" value="delete_main"><input name="index" value="${idx}">`;document.body.appendChild(f);f.submit(); } }
function openSubModal(parent, child=-1) {
    const modal = document.getElementById('subModal');
    document.getElementById('subParentIndex').value = parent;
    document.getElementById('subChildIndex').value = child;
    if(child === -1) {
        document.getElementById('subModalTitle').innerText = 'Tambah Submenu';
        document.getElementById('subAction').value = 'add_sub';
        document.getElementById('subIcon').value = 'fas fa-chevron-right';
        document.getElementById('subLabel').value = '';
        document.getElementById('subLink').value = '#';
        document.getElementById('subRoles').value = 'teacher,student';
        document.getElementById('subClassType').value = '';
        document.getElementById('subGradeLevel').value = '';
    } else {
        const childMenu = menus[parent].children[child];
        document.getElementById('subModalTitle').innerText = 'Edit Submenu';
        document.getElementById('subAction').value = 'edit_sub';
        document.getElementById('subIcon').value = childMenu.icon;
        document.getElementById('subLabel').value = childMenu.label;
        document.getElementById('subLink').value = childMenu.link;
        document.getElementById('subRoles').value = childMenu.roles.join(',');
        document.getElementById('subClassType').value = childMenu.condition?.class_type || '';
        document.getElementById('subGradeLevel').value = (childMenu.condition?.grade_level || []).join(',');
    }
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closeSubModal() { document.getElementById('subModal').classList.add('hidden'); }
function deleteSub(parent, child) { if(confirm('Hapus submenu?')) { let f=document.createElement('form');f.method='POST';f.innerHTML=`<input name="action" value="delete_sub"><input name="parent_index" value="${parent}"><input name="child_index" value="${child}">`;document.body.appendChild(f);f.submit(); } }
window.editMain = openMainModal;
window.deleteMain = deleteMain;
window.editSub = openSubModal;
window.deleteSub = deleteSub;
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>