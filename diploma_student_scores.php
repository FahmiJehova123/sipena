<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'student') {
    header('Location: login.php');
    exit;
}
if (time() - $_SESSION['login_time'] > 28800) {
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['login_time'] = time();

$page_title = 'Nilai Ijazah Siswa - SIAKAP';
$current_page = 'student_scores';
require_once __DIR__ . '/config.php';

$student_id = $_SESSION['user_id'];

// Ambil data siswa
$student_raw = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $student_id]);
$student = (is_array($student_raw) && count($student_raw) > 0) ? $student_raw[0] : null;
if (!$student) die("Data siswa tidak ditemukan.");

// Ambil semua mata pelajaran untuk mapping nama mapel
$subjects_raw = supabase_admin_request('GET', 'subjects', null, []);
$subject_map = [];
if (is_array($subjects_raw)) {
    foreach ($subjects_raw as $subj) {
        if (isset($subj['id'])) $subject_map[$subj['id']] = $subj['subject_name'] ?? 'Mata Pelajaran';
    }
}

// Fungsi mendapatkan nama wali kelas berdasarkan kelas (pagi/diniyyah)
function getHomeroomTeacherNameForType($student_id, $class_type) {
    $student = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $student_id]);
    if (!is_array($student) || empty($student)) return '-';
    $student = $student[0];
    $class_id = null;
    if ($class_type == 'pagi') $class_id = $student['kelas_pagi_id'] ?? null;
    else $class_id = $student['kelas_diniyyah_id'] ?? null;
    if (!$class_id) return '-';
    $class = supabase_admin_request('GET', 'classes', null, ['id' => 'eq.' . $class_id]);
    if (!is_array($class) || empty($class)) return '-';
    $teacher_id = $class[0]['homeroom_teacher_id'] ?? null;
    if (!$teacher_id) return '-';
    $teacher = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $teacher_id]);
    return (is_array($teacher) && !empty($teacher)) ? $teacher[0]['full_name'] : '-';
}
$wali_kelas_pagi = getHomeroomTeacherNameForType($student_id, 'pagi');
$wali_kelas_diniyyah = getHomeroomTeacherNameForType($student_id, 'diniyyah');

// Ambil nilai ijazah kelompokkan berdasarkan class_type dan tahun
$diploma_raw = supabase_admin_request('GET', 'diploma_scores', null, ['student_id' => 'eq.' . $student_id]);
$diploma_scores = is_array($diploma_raw) ? $diploma_raw : [];

$grouped = ['pagi' => [], 'diniyyah' => []];
foreach ($diploma_scores as $dip) {
    $type = $dip['class_type'] ?? 'pagi';
    $year = $dip['graduation_year'];
    $subject_id = $dip['subject_id'];
    $score = $dip['score'];
    if (!isset($grouped[$type][$year])) $grouped[$type][$year] = [];
    $grouped[$type][$year][$subject_id] = $score;
}

// Tentukan tab aktif dari GET
$active_tab = isset($_GET['tab']) && $_GET['tab'] == 'diniyyah' ? 'diniyyah' : 'pagi';

require_once __DIR__ . '/includes/nav_items.php';
foreach ($nav_items as &$item) $item['active'] = ($item['link'] == 'student_scores');
unset($item);
require_once __DIR__ . '/includes/header_user.php';
?>

<style>
    .tab-button { padding: 8px 16px; border-bottom: 2px solid transparent; cursor: pointer; transition: all 0.2s; }
    .tab-button.active { border-bottom-color: #10b981; color: #10b981; font-weight: bold; }
    .dark .tab-button.active { border-bottom-color: #34d399; color: #34d399; }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    .score-card { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 1.5rem; overflow: hidden; transition: transform 0.2s; }
    .score-card:hover { transform: translateY(-2px); }
    .dark .score-card { background: #1f2937; }
    .card-header { background: #f3f4f6; padding: 0.75rem 1rem; font-weight: 600; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; flex-wrap: wrap; align-items: center; gap: 10px; }
    .dark .card-header { background: #374151; border-bottom-color: #4b5563; }
    .score-table { width: 100%; border-collapse: collapse; }
    .score-table th, .score-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #e5e7eb; }
    .dark .score-table th, .dark .score-table td { border-bottom-color: #4b5563; }
    .status-lulus { display: inline-block; background-color: #d1fae5; color: #065f46; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
    .average-badge { background-color: #f3f4f6; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
    .dark .average-badge { background-color: #374151; color: #e5e7eb; }
    /* Modal Responsif F4 */
    .modal-ijazah { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; overflow-y: auto; padding: 16px; }
    .modal-content { background: white; width: 100%; max-width: 21.6cm; margin: auto; padding: 1.5cm; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); position: relative; min-height: 33cm; box-sizing: border-box; }
    .dark .modal-content { background: #1f2937; color: #f3f4f6; }
    @media print { body * { visibility: hidden; } .modal-ijazah, .modal-ijazah * { visibility: visible; } .modal-ijazah { position: absolute; top: 0; left: 0; width: 100%; background: white; padding: 0; } .modal-content { margin: 0; padding: 1cm; box-shadow: none; } .btn-close-modal, .btn-print-ijazah { display: none; } }
    @media (max-width: 768px) { .modal-content { padding: 0.8cm; min-height: auto; } .school-name { font-size: 1rem; } .ijazah-title { font-size: 1.2rem; } .score-table-ijazah th, .score-table-ijazah td { padding: 4px; font-size: 0.7rem; } }
    .signature-line { margin-top: 2rem; text-align: right; }
    .signature-item { margin-bottom: 1rem; }
    .signature-name { margin-top: 0.5rem; font-weight: bold; }
    .school-header { text-align: center; margin-bottom: 1.5rem; }
    .school-name { font-size: 1.4rem; font-weight: bold; text-transform: uppercase; }
    .ijazah-title { font-size: 1.8rem; font-weight: bold; text-align: center; margin: 1rem 0; }
    .score-table-ijazah { width: 100%; border-collapse: collapse; margin: 1rem 0; }
    .score-table-ijazah th, .score-table-ijazah td { border: 1px solid #ccc; padding: 0.5rem; text-align: left; }
    .dark .score-table-ijazah th, .dark .score-table-ijazah td { border-color: #4b5563; }
    .preview-btn, .print-card-btn { transition: all 0.2s; }
    .print-card-btn { background-color: #6b7280; }
    .print-card-btn:hover { background-color: #4b5563; }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar_user.php'; ?>
    <div class="main-content-container flex-1">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <?php if ($user_role !== 'student'): ?>
                <button id="openSidebarUserBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden" onclick="document.getElementById('sidebarUser').classList.remove('-translate-x-full'); document.getElementById('sidebarUser').classList.add('translate-x-0'); document.getElementById('sidebarOverlayUser').classList.remove('hidden'); document.body.classList.add('sidebar-open'); return false;">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <?php else: ?>
                <button id="openMobileSidebarBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <?php endif; ?>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Nilai Ijazah</h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                        <i class="fas fa-moon text-xl dark:hidden"></i>
                        <i class="fas fa-sun text-xl hidden dark:inline"></i>
                    </button>
                    <div class="relative group">
                        <button class="flex items-center space-x-2 focus:outline-none">
                            <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold overflow-hidden">
                                <?php 
                                $user_photo = $user['photo_url'] ?? '';
                                if ($user_photo): ?>
                                    <img src="<?= htmlspecialchars($user_photo) ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <span><?= strtoupper(substr($user['full_name'] ?? 'A', 0, 1)) ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="hidden md:inline text-gray-700 dark:text-gray-200"><?= htmlspecialchars($_SESSION['user_name'] ?? ($user_role == 'teacher' ? 'Guru' : ($user_role == 'student' ? 'Siswa' : 'User'))) ?></span>
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
        <main class="p-4 md:p-6 dark:bg-gray-900">
            <div class="mb-6">
                <h2 class="text-2xl font-bold dark:text-white"><?= htmlspecialchars($student['full_name']) ?></h2>
                <p class="text-gray-600 dark:text-gray-300">NIS / NISN: <?= htmlspecialchars($student['nidn_or_nisn'] ?? '-') ?></p>
            </div>
            <!-- Tab navigasi -->
            <div class="border-b border-gray-200 dark:border-gray-700 mb-4">
                <div class="flex gap-4">
                    <div class="tab-button <?= $active_tab == 'pagi' ? 'active' : '' ?>" data-tab="pagi">Ijazah Pagi</div>
                    <div class="tab-button <?= $active_tab == 'diniyyah' ? 'active' : '' ?>" data-tab="diniyyah">Ijazah Diniyyah</div>
                </div>
            </div>

            <!-- Konten Tab Pagi -->
            <div id="tab-pagi" class="tab-content <?= $active_tab == 'pagi' ? 'active' : '' ?>">
                <?php
                $data_pagi = $grouped['pagi'];
                if (empty($data_pagi)):
                ?>
                    <div class="bg-yellow-100 dark:bg-yellow-800 p-4 rounded">Belum ada data nilai ijazah untuk kelas Pagi.</div>
                <?php else: ?>
                    <?php foreach ($data_pagi as $year => $subject_scores):
                        $total = 0; $count = 0;
                        foreach ($subject_scores as $score) { $total += $score; $count++; }
                        $average = $count > 0 ? round($total / $count, 2) : 0;
                        $subjects = [];
                        foreach ($subject_scores as $subj_id => $score) {
                            $subjects[] = ['subject_name' => $subject_map[$subj_id] ?? 'Mata Pelajaran', 'score' => $score];
                        }
                    ?>
                        <div class="score-card" data-year="<?= $year ?>" data-subjects='<?= json_encode($subjects, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>' data-type="pagi">
                            <div class="card-header">
                                <span><i class="fas fa-graduation-cap mr-2"></i> Tahun Lulus: <?= htmlspecialchars($year) ?></span>
                                <div class="flex gap-2">
                                    <button class="print-card-btn text-white px-3 py-1 rounded text-sm"><i class="fas fa-print"></i> Cetak</button>
                                    <button class="preview-btn bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm"><i class="fas fa-eye"></i> Preview</button>
                                </div>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="score-table min-w-full"><thead><tr><th>Mata Pelajaran</th><th>Nilai</th></tr></thead><tbody>
                                <?php foreach ($subjects as $s): ?>
                                    <tr><td><?= htmlspecialchars($s['subject_name']) ?></td><td><?= number_format($s['score'], 2) ?></td></tr>
                                <?php endforeach; ?>
                                </tbody></table>
                            </div>
                            <div class="p-3 bg-gray-50 dark:bg-gray-800/50 text-right border-t">
                                <span class="status-lulus"><?= ($average >= 75) ? 'Lulus' : 'Tidak Lulus' ?></span>
                                <span class="average-badge ml-2">Rata-rata: <?= $average ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Konten Tab Diniyyah -->
            <div id="tab-diniyyah" class="tab-content <?= $active_tab == 'diniyyah' ? 'active' : '' ?>" style="display:<?= $active_tab == 'diniyyah' ? 'block' : 'none' ?>">
                <?php
                $data_diniyyah = $grouped['diniyyah'];
                if (empty($data_diniyyah)):
                ?>
                    <div class="bg-yellow-100 dark:bg-yellow-800 p-4 rounded">Belum ada data nilai ijazah untuk kelas Diniyyah.</div>
                <?php else: ?>
                    <?php foreach ($data_diniyyah as $year => $subject_scores):
                        $total = 0; $count = 0;
                        foreach ($subject_scores as $score) { $total += $score; $count++; }
                        $average = $count > 0 ? round($total / $count, 2) : 0;
                        $subjects = [];
                        foreach ($subject_scores as $subj_id => $score) {
                            $subjects[] = ['subject_name' => $subject_map[$subj_id] ?? 'Mata Pelajaran', 'score' => $score];
                        }
                    ?>
                        <div class="score-card" data-year="<?= $year ?>" data-subjects='<?= json_encode($subjects, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>' data-type="diniyyah">
                            <div class="card-header">
                                <span><i class="fas fa-graduation-cap mr-2"></i> Tahun Lulus: <?= htmlspecialchars($year) ?></span>
                                <div class="flex gap-2">
                                    <button class="print-card-btn text-white px-3 py-1 rounded text-sm"><i class="fas fa-print"></i> Cetak</button>
                                    <button class="preview-btn bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm"><i class="fas fa-eye"></i> Preview</button>
                                </div>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="score-table min-w-full"><thead><tr><th>Mata Pelajaran</th><th>Nilai</th></tr></thead><tbody>
                                <?php foreach ($subjects as $s): ?>
                                    <tr><td><?= htmlspecialchars($s['subject_name']) ?></td><td><?= number_format($s['score'], 2) ?></td></tr>
                                <?php endforeach; ?>
                                </tbody></table>
                            </div>
                            <div class="p-3 bg-gray-50 dark:bg-gray-800/50 text-right border-t">
                                <span class="status-lulus"><?= ($average >= 75) ? 'Lulus' : 'Tidak Lulus' ?></span>
                                <span class="average-badge ml-2">Rata-rata: <?= $average ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Modal Preview Ijazah -->
<div id="modalIjazah" class="modal-ijazah">
    <div class="modal-content">
        <div class="flex justify-end gap-2 mb-4 btn-close-print">
            <button onclick="printIjazah()" class="bg-green-600 text-white px-4 py-2 rounded btn-print-ijazah"><i class="fas fa-print"></i> Cetak</button>
            <button onclick="closeModal()" class="bg-red-600 text-white px-4 py-2 rounded btn-close-modal"><i class="fas fa-times"></i> Tutup</button>
        </div>
        <div id="ijazahContent"></div>
    </div>
</div>

<script>
function previewIjazah(year, subjects, student, waliKelas, schoolTypeLabel) {
    // Tentukan kop surat berdasarkan tipe sekolah
    let kopSurat = '';
    if (schoolTypeLabel === 'pagi') {
        kopSurat = `<div class="school-header">
                        <div class="school-name">YAYASAN PENDIDIKAN ISLAM</div>
                        <div class="school-sub">SEKOLAH (PAGI)</div>
                        <div class="school-address">Mojosari, Indonesia</div>
                    </div>`;
    } else {
        kopSurat = `<div class="school-header">
                        <div class="school-name">YAYASAN PENDIDIKAN ISLAM</div>
                        <div class="school-sub">MADRASAH DINIYYAH</div>
                        <div class="school-address">Mojosari, Indonesia</div>
                    </div>`;
    }

    let total = 0, count = 0, rows = '';
    subjects.forEach(subj => {
        rows += `<tr><td>${escapeHtml(subj.subject_name)}</td><td>${parseFloat(subj.score).toFixed(2)}</td></tr>`;
        total += subj.score;
        count++;
    });
    let average = count > 0 ? (total / count).toFixed(2) : 0;
    let html = `
        ${kopSurat}
        <div class="ijazah-title">IJAZAH</div>
        <div class="student-info"><table style="width:100%"></td><td style="width:30%">Nama Siswa</td><td>: ${escapeHtml(student.full_name)}</td></tr>
        <tr><td>Tempat, Tanggal Lahir</td><td>: ${escapeHtml(student.tempat_lahir || '-')}, ${escapeHtml(student.tanggal_lahir || '-')}</td></tr>
        <tr><td>NIS / NISN</td><td>: ${escapeHtml(student.nidn_or_nisn || '-')}</td></tr>
        <tr><td>Tahun Lulus</td><td>: ${year}</td></tr></table></div>
        <table class="score-table-ijazah"><thead><tr><th>Mata Pelajaran</th><th>Nilai</th></tr></thead><tbody>${rows}</tbody>
        <tfoot><tr><th>Rata-rata</th><th>${average}</th></tr></tfoot></table>
        <div class="signature-line"><div class="signature-item">Mojosari, _______________<br>Wali Kelas,<br><br><br><div class="signature-name">(${escapeHtml(waliKelas)})</div><hr style="width: 200px; margin-left: auto;"></div></div>
    `;
    document.getElementById('ijazahContent').innerHTML = html;
    document.getElementById('modalIjazah').style.display = 'flex';
}

function closeModal() { document.getElementById('modalIjazah').style.display = 'none'; }
function printIjazah() { window.print(); }
function escapeHtml(str) { if (!str) return ''; return str.replace(/[&<>]/g, function(m){ if(m==='&') return '&amp;'; if(m==='<') return '&lt;'; if(m==='>') return '&gt;'; return m;}); }

// Tab switching
document.querySelectorAll('.tab-button').forEach(btn => {
    btn.addEventListener('click', function() {
        let tab = this.getAttribute('data-tab');
        document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.getElementById(`tab-${tab}`).classList.add('active');
        let url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.pushState({}, '', url);
    });
});

// Preview and Print handlers
document.querySelectorAll('.preview-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        let card = this.closest('.score-card');
        let year = card.getAttribute('data-year');
        let subjects = JSON.parse(card.getAttribute('data-subjects'));
        let type = card.getAttribute('data-type');
        let student = <?= json_encode($student, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        let waliKelas = (type === 'pagi') ? '<?= addslashes($wali_kelas_pagi) ?>' : '<?= addslashes($wali_kelas_diniyyah) ?>';
        previewIjazah(year, subjects, student, waliKelas, type);
    });
});
document.querySelectorAll('.print-card-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        let card = this.closest('.score-card');
        let year = card.getAttribute('data-year');
        let subjects = JSON.parse(card.getAttribute('data-subjects'));
        let type = card.getAttribute('data-type');
        let student = <?= json_encode($student, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        let waliKelas = (type === 'pagi') ? '<?= addslashes($wali_kelas_pagi) ?>' : '<?= addslashes($wali_kelas_diniyyah) ?>';
        previewIjazah(year, subjects, student, waliKelas, type);
        setTimeout(() => window.print(), 500);
    });
});

// Dark mode toggle
const darkModeToggle = document.getElementById('darkModeToggle');
function setDarkMode(isDark) { if (isDark) document.documentElement.classList.add('dark'); else document.documentElement.classList.remove('dark'); localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled'); }
const saved = localStorage.getItem('darkMode');
if (saved === 'enabled') setDarkMode(true);
else if (saved !== 'disabled') setDarkMode(window.matchMedia('(prefers-color-scheme: dark)').matches);
darkModeToggle?.addEventListener('click', () => setDarkMode(!document.documentElement.classList.contains('dark')));

// Mobile sidebar
if (typeof window.openMobileSidebar !== 'undefined') {
    const openBtn = document.getElementById('openSidebarUserBtn');
    if (openBtn) openBtn.addEventListener('click', window.openMobileSidebar);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>