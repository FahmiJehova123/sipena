<?php
// middleware/sidebar_middleware.php
header('Content-Type: application/json');
header('Cache-Control: no-cache, private');
session_start();

// Perbaiki path config (karena middleware di folder /middleware, config di folder root)
require_once __DIR__ . '/../config.php';

// Pastikan fungsi supabase_admin_request tersedia
if (!function_exists('supabase_admin_request')) {
    // Jika tidak ada, fallback ke array kosong
    function supabase_admin_request($method, $table, $data = null, $params = []) {
        error_log("WARNING: supabase_admin_request tidak tersedia di " . __FILE__);
        return [];
    }
}

$configFile = __DIR__ . '/sidebar_config.json';
if (!file_exists($configFile)) {
    echo json_encode(['error' => 'Konfigurasi sidebar tidak ditemukan']);
    exit;
}
$raw = file_get_contents($configFile);
$config = json_decode($raw, true);
if (!is_array($config)) $config = [];

$userRole = $_SESSION['user_role'] ?? 'guest';
$userId = $_SESSION['user_id'] ?? null;

// Fungsi ambil kondisi user (class_type & grade_level) dari database
function getUserConditions($userId, $userRole) {
    if (!$userId) return [];
    $conditions = [];
    if ($userRole === 'teacher') {
        $classes = supabase_admin_request('GET', 'classes', null, ['homeroom_teacher_id' => 'eq.' . $userId]);
        if (is_array($classes)) {
            foreach ($classes as $c) {
                $type = $c['class_type'] ?? 'pagi';
                $grade = $c['grade_level'] ?? null;
                if ($grade !== null) {
                    $conditions[] = ['class_type' => $type, 'grade_level' => (int)$grade];
                }
            }
        } else {
            error_log("Gagal mengambil kelas untuk teacher $userId: " . print_r($classes, true));
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
        } else {
            error_log("Gagal mengambil data student $userId");
        }
    }
    return $conditions;
}

// Evaluasi apakah menu condition cocok dengan user conditions
function evaluateCondition($menuCond, $userConds) {
    // Jika menu tidak punya condition, tampilkan
    if (empty($menuCond) || !is_array($menuCond)) return true;
    $reqType = $menuCond['class_type'] ?? null;
    $reqGrades = $menuCond['grade_level'] ?? [];
    // Jika condition tidak valid, tampilkan
    if (empty($reqType) || empty($reqGrades)) return true;
    // Jika user tidak punya kondisi, jangan tampilkan menu dengan condition
    if (empty($userConds)) return false;
    foreach ($userConds as $uc) {
        if ($uc['class_type'] === $reqType && in_array($uc['grade_level'], $reqGrades)) {
            return true;
        }
    }
    return false;
}

// Filter menu rekursif
function filterMenu($items, $userRole, $userConds) {
    $result = [];
    foreach ($items as $item) {
        // Filter role
        if (!isset($item['roles']) || !in_array($userRole, $item['roles'])) continue;
        // Filter kondisi
        if (!evaluateCondition($item['condition'] ?? null, $userConds)) continue;
        // Filter children
        if (!empty($item['children'])) {
            $item['children'] = filterMenu($item['children'], $userRole, $userConds);
        }
        $result[] = $item;
    }
    return $result;
}

$userConditions = getUserConditions($userId, $userRole);
// Log untuk debugging (di error log server)
error_log("User role: $userRole, User ID: $userId, Conditions: " . print_r($userConditions, true));

$filteredMenu = filterMenu($config, $userRole, $userConditions);
echo json_encode($filteredMenu, JSON_PRETTY_PRINT);