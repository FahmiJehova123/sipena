<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$range = $_GET['range'] ?? 'this_month';
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

// Fungsi untuk mendapatkan rentang waktu
function getDateRange($range, $start, $end) {
    $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    switch ($range) {
        case 'this_month':
            $start = (clone $now)->modify('first day of this month')->setTime(0,0,0);
            $end = (clone $now)->modify('last day of this month')->setTime(23,59,59);
            break;
        case 'last_month':
            $start = (clone $now)->modify('first day of last month')->setTime(0,0,0);
            $end = (clone $now)->modify('last day of last month')->setTime(23,59,59);
            break;
        case 'this_year':
            $start = (clone $now)->modify('first day of January')->setTime(0,0,0);
            $end = (clone $now)->modify('last day of December')->setTime(23,59,59);
            break;
        case 'custom':
            $start = new DateTime($start, new DateTimeZone('Asia/Jakarta'));
            $end = new DateTime($end, new DateTimeZone('Asia/Jakarta'));
            $end->setTime(23,59,59);
            break;
        default: return null;
    }
    return ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')];
}

$rangeDates = getDateRange($range, $start_date, $end_date);
if (!$rangeDates) {
    echo json_encode(['error' => 'Invalid range']);
    exit;
}

// Query ke tabel payments (asumsikan ada tabel payments dengan field amount, status, payment_date)
// Jika belum ada, gunakan data dummy
$payments = supabase_admin_request('GET', 'payments', null, [
    'payment_date' => 'gte.' . $rangeDates['start'],
    'payment_date' => 'lte.' . $rangeDates['end']
]);

if (!is_array($payments)) $payments = [];

// Kelompokkan per bulan (atau per hari jika range pendek)
$labels = [];
$tagihan = [];
$terkumpul = [];

// --- Jika ingin data dummy untuk sementara ---
// Data dummy berdasarkan bulan
$labels = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
$tagihan = [85000000, 88000000, 92000000, 95000000, 91000000, 89000000, 102000000, 105000000, 98000000, 97000000, 95000000, 92000000];
$terkumpul = [75000000, 78000000, 82000000, 85000000, 81000000, 79000000, 92000000, 95000000, 88000000, 87000000, 85000000, 82000000];

// Filter sesuai range jika perlu (untuk custom bisa di-slice)
if ($range == 'this_month') {
    $labels = [date('F Y')];
    $tagihan = [array_sum($tagihan) / 12];
    $terkumpul = [array_sum($terkumpul) / 12];
} elseif ($range == 'last_month') {
    $labels = [date('F Y', strtotime('-1 month'))];
    $tagihan = [array_sum($tagihan) / 12];
    $terkumpul = [array_sum($terkumpul) / 12];
}

echo json_encode([
    'labels' => $labels,
    'tagihan' => $tagihan,
    'terkumpul' => $terkumpul,
    'total_tagihan' => array_sum($tagihan),
    'total_terkumpul' => array_sum($terkumpul)
]);
?>