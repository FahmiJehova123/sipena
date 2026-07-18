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

$page_title = 'Leger Nilai Siswa - SIAKAP';
$current_page = 'student_scores';
require_once __DIR__ . '/config.php';

// ========== KONFIGURASI TANDA TANGAN ==========
// Ubah nilai di bawah ini sesuai kebutuhan (bisa dipindahkan ke config.php)
$school_city = 'Nganjuk'; // Nama kota
$signature_date = '_________________'; // Bisa diisi '_________________' atau date('d F Y') untuk otomatis
// =============================================

function safeArray($data) { return is_array($data) ? $data : []; }

$student_id = $_SESSION['user_id'];

// Ambil data siswa
$student_raw = supabase_admin_request('GET', 'users', null, ['id' => 'eq.' . $student_id]);
$student = (is_array($student_raw) && count($student_raw) > 0) ? $student_raw[0] : null;
if (!$student) {
    die("Data siswa tidak ditemukan.");
}

// Ambil semua kelas
$classes = safeArray(supabase_admin_request('GET', 'classes'));

// Ambil kelas pagi dan diniyyah
$class_pagi_id = $student['kelas_pagi_id'] ?? null;
$class_diniyyah_id = $student['kelas_diniyyah_id'] ?? null;

// Fungsi untuk mendapatkan grade_level dari kelas
function getClassGradeLevel($class_id) {
    global $supabase_admin_request;
    if (!$class_id) return null;
    $class_raw = supabase_admin_request('GET', 'classes', null, ['id' => 'eq.' . $class_id, 'select' => 'grade_level']);
    if (is_array($class_raw) && count($class_raw) > 0) {
        return $class_raw[0]['grade_level'];
    }
    return null;
}

$grade_pagi = getClassGradeLevel($class_pagi_id);
$grade_diniyyah = getClassGradeLevel($class_diniyyah_id);

// Ambil semua mata pelajaran berdasarkan grade_level
$subjects_pagi = [];
if ($grade_pagi) {
    $subjects_raw = supabase_admin_request('GET', 'subjects', null, ['grade_level' => 'eq.' . $grade_pagi, 'order' => 'subject_name.asc']);
    if (is_array($subjects_raw)) {
        foreach ($subjects_raw as $s) if (isset($s['id'])) $subjects_pagi[$s['id']] = $s['subject_name'];
    }
}
$subjects_diniyyah = [];
if ($grade_diniyyah) {
    $subjects_raw = supabase_admin_request('GET', 'subjects', null, ['grade_level' => 'eq.' . $grade_diniyyah, 'order' => 'subject_name.asc']);
    if (is_array($subjects_raw)) {
        foreach ($subjects_raw as $s) if (isset($s['id'])) $subjects_diniyyah[$s['id']] = $s['subject_name'];
    }
}

// Ambil semua nilai exam_scores untuk siswa
$exam_scores_raw = supabase_admin_request('GET', 'exam_scores', null, ['student_id' => 'eq.' . $student_id, 'order' => 'academic_year.desc, semester.asc']);
$exam_scores = is_array($exam_scores_raw) ? $exam_scores_raw : [];

// Kelompokkan nilai
$grouped_scores = [];
foreach ($exam_scores as $es) {
    $year = $es['academic_year'];
    $sem = $es['semester'];
    $subj_id = $es['subject_id'];
    if (!isset($grouped_scores[$year][$sem])) $grouped_scores[$year][$sem] = [];
    $grouped_scores[$year][$sem][$subj_id] = $es['score'];
}

// Filter nilai untuk pagi dan diniyyah
$filtered_scores_pagi = [];
foreach ($grouped_scores as $year => $semesters) {
    foreach ($semesters as $sem => $subj_scores) {
        foreach ($subj_scores as $subj_id => $score) {
            if (isset($subjects_pagi[$subj_id])) {
                $filtered_scores_pagi[$year][$sem][$subj_id] = $score;
            }
        }
    }
}
$filtered_scores_diniyyah = [];
foreach ($grouped_scores as $year => $semesters) {
    foreach ($semesters as $sem => $subj_scores) {
        foreach ($subj_scores as $subj_id => $score) {
            if (isset($subjects_diniyyah[$subj_id])) {
                $filtered_scores_diniyyah[$year][$sem][$subj_id] = $score;
            }
        }
    }
}

// Tentukan tab aktif
$active_tab = isset($_GET['tab']) && $_GET['tab'] == 'diniyyah' ? 'diniyyah' : 'pagi';

require_once __DIR__ . '/includes/nav_items.php';
foreach ($nav_items as &$item) {
    $item['active'] = ($item['link'] == 'student_scores.php');
}
unset($item);
require_once __DIR__ . '/includes/header_user.php';
?>

<style>
    .tab-button { padding: 8px 16px; border-bottom: 2px solid transparent; cursor: pointer; transition: all 0.2s; }
    .tab-button.active { border-bottom-color: #3b82f6; color: #3b82f6; font-weight: bold; }
    .dark .tab-button.active { border-bottom-color: #60a5fa; color: #60a5fa; }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    .score-card { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 1.5rem; overflow: hidden; }
    .dark .score-card { background: #1f2937; }
    .card-header { background: #f3f4f6; padding: 0.75rem 1rem; font-weight: 600; border-bottom: 1px solid #e5e7eb; }
    .dark .card-header { background: #374151; border-bottom-color: #4b5563; }
    .score-table { width: 100%; border-collapse: collapse; }
    .score-table th, .score-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #e5e7eb; }
    .dark .score-table th, .dark .score-table td { border-bottom-color: #4b5563; }
    .print-btn { background-color: #10b981; color: white; padding: 8px 16px; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; }
    .print-btn:hover { background-color: #059669; }
    .preview-btn { background-color: #3b82f6; color: white; padding: 8px 16px; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; }
    .preview-btn:hover { background-color: #2563eb; }

    /* Modal Preview */
    .preview-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
        overflow-y: auto;
        padding: 20px;
    }
    .preview-modal-content {
        background: white;
        width: 100%;
        max-width: 210mm;
        margin: 20px auto;
        padding: 1.5rem 2rem;
        min-height: 297mm;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        position: relative;
    }
    .preview-controls {
        position: sticky;
        bottom: 20px;
        z-index: 100;
        display: flex;
        justify-content: center;
        gap: 16px;
        background: white;
        padding: 12px 24px;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        margin-top: 20px;
    }
    .preview-controls button {
        padding: 10px 24px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        border: none;
    }
    .preview-close { background: #6b7280; color: white; }
    .preview-print { background: #10b981; color: white; }

    /* Print style */
    @media print {
        body * { visibility: hidden; }
        .preview-modal, .preview-modal-content, .preview-modal-content * { visibility: visible; }
        .preview-modal { position: fixed; left: 0; top: 0; width: 100%; height: 100%; background: white; padding: 0; }
        .preview-modal-content { margin: 0; box-shadow: none; max-width: 100%; min-height: 100%; padding: 1.5cm 2cm; }
        .preview-controls { display: none !important; }
        .no-print { display: none !important; }
    }

    /* Kop surat */
    .letter-head {
        text-align: center;
        border-bottom: 3px double #000;
        padding-bottom: 10px;
        margin-bottom: 20px;
    }
    .letter-head h1 { font-size: 24px; font-weight: bold; margin: 0; }
    .letter-head p { margin: 4px 0; font-size: 14px; text-align: left;}
    .letter-head .sub { font-size: 12px; color: #555; }

    /* Tanda tangan */
    .signature-area {
        margin-top: 50px;
        display: flex;
        justify-content: flex-end;
        text-align: center;
    }
    .signature-box {
        width: 250px;
    }
    .signature-line {
        margin-top: 100px;
        padding-top: 5px;
    }
    .signature-label { font-weight: bold; }

    .preview-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
        font-size: 13px;
    }
    .preview-table th, .preview-table td {
        border: 1px solid #d1d5db;
        padding: 6px 10px;
        text-align: left;
    }
    .preview-table th {
        background: #f3f4f6;
        font-weight: 600;
    }
    .preview-table td.center { text-align: center; }
</style>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php require_once __DIR__ . '/includes/sidebar_user.php'; ?>
    <div class="main-content-container flex-1">
        <header class="bg-white dark:bg-gray-800 shadow-md sticky top-0 z-10">
            <div class="flex items-center justify-between px-4 py-3 md:px-6">
                <button id="openMobileSidebarBtn" class="text-gray-600 dark:text-gray-300 focus:outline-none md:hidden">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white hidden md:block">Leger Nilai Siswa</h1>
                <div class="flex items-center space-x-4 ml-auto">
                    <button id="darkModeToggle" class="text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white">
                        <i class="fas fa-moon text-xl dark:hidden"></i>
                        <i class="fas fa-sun text-xl hidden dark:inline"></i>
                    </button>
                    <!-- Profile User -->
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

        <main class="p-4 md:p-6 dark:bg-gray-900 dark:text-white">
            <!-- Informasi siswa -->
            <div class="mb-6">
                <h2 class="text-2xl font-bold dark:text-white"><?= htmlspecialchars($student['full_name'] ?? 'Siswa') ?></h2>
                <p class="text-gray-600 dark:text-gray-300">NIS / NISN: <?= htmlspecialchars($student['nidn_or_nisn'] ?? '-') ?></p>
            </div>

            <!-- Tab navigasi -->
            <div class="border-b border-gray-200 dark:border-gray-700 mb-4">
                <div class="flex gap-4">
                    <div class="tab-button <?= $active_tab == 'pagi' ? 'active' : '' ?>" data-tab="pagi">Kelas Pagi</div>
                    <div class="tab-button <?= $active_tab == 'diniyyah' ? 'active' : '' ?>" data-tab="diniyyah">Kelas Diniyyah</div>
                </div>
            </div>

            <!-- Konten Tab Pagi -->
            <div id="tab-pagi" class="tab-content <?= $active_tab == 'pagi' ? 'active' : '' ?>">
                <?php if (!$class_pagi_id || !$grade_pagi): ?>
                    <div class="bg-yellow-100 p-4 rounded">Anda tidak terdaftar di kelas pagi.</div>
                <?php elseif (empty($subjects_pagi)): ?>
                    <div class="bg-yellow-100 p-4 rounded">Belum ada mata pelajaran untuk tingkat kelas pagi.</div>
                <?php else: ?>
                    <div class="mb-4 flex flex-wrap gap-2">
                        <button onclick="openPreview('pagi')" class="preview-btn">
                            <i class="fas fa-eye"></i> View / Pratinjau
                        </button>
                        <a href="export_student_ledger.php?school_type=pagi" class="print-btn" target="_blank">
                            <i class="fas fa-print"></i> Cetak Leger (Excel)
                        </a>
                    </div>
                    <?php if (empty($filtered_scores_pagi)): ?>
                        <div class="bg-yellow-100 p-4 rounded">Belum ada nilai ujian untuk kelas pagi.</div>
                    <?php else: ?>
                        <?php foreach ($filtered_scores_pagi as $year => $semesters): ?>
                            <?php foreach ($semesters as $sem => $scores): ?>
                                <div class="score-card">
                                    <div class="card-header">Tahun Ajaran: <?= htmlspecialchars($year) ?> | Semester: <?= $sem == 1 ? 'Ganjil' : 'Genap' ?></div>
                                    <div class="overflow-x-auto">
                                        <table class="score-table">
                                            <thead><tr><th>Mata Pelajaran</th><th>Nilai</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($scores as $subj_id => $score): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($subjects_pagi[$subj_id]) ?></td>
                                                    <td class="font-semibold"><?= number_format($score, 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Konten Tab Diniyyah -->
            <div id="tab-diniyyah" class="tab-content <?= $active_tab == 'diniyyah' ? 'active' : '' ?>">
                <?php if (!$class_diniyyah_id || !$grade_diniyyah): ?>
                    <div class="bg-yellow-100 p-4 rounded">Anda tidak terdaftar di kelas diniyyah.</div>
                <?php elseif (empty($subjects_diniyyah)): ?>
                    <div class="bg-yellow-100 p-4 rounded">Belum ada mata pelajaran untuk tingkat kelas diniyyah.</div>
                <?php else: ?>
                    <div class="mb-4 flex flex-wrap gap-2">
                        <button onclick="openPreview('diniyyah')" class="preview-btn">
                            <i class="fas fa-eye"></i> View / Pratinjau
                        </button>
                        <a href="export_student_ledger.php?school_type=diniyyah" class="print-btn" target="_blank">
                            <i class="fas fa-print"></i> Cetak Leger (Excel)
                        </a>
                    </div>
                    <?php if (empty($filtered_scores_diniyyah)): ?>
                        <div class="bg-yellow-100 p-4 rounded">Belum ada nilai ujian untuk kelas diniyyah.</div>
                    <?php else: ?>
                        <?php foreach ($filtered_scores_diniyyah as $year => $semesters): ?>
                            <?php foreach ($semesters as $sem => $scores): ?>
                                <div class="score-card">
                                    <div class="card-header">Tahun Ajaran: <?= htmlspecialchars($year) ?> | Semester: <?= $sem == 1 ? 'Ganjil' : 'Genap' ?></div>
                                    <div class="overflow-x-auto">
                                        <table class="score-table">
                                            <thead><tr><th>Mata Pelajaran</th><th>Nilai</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($scores as $subj_id => $score): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($subjects_diniyyah[$subj_id]) ?></td>
                                                    <td class="font-semibold"><?= number_format($score, 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Modal Preview -->
<div id="previewModal" class="preview-modal">
    <div class="preview-modal-content" id="previewContent">
        <!-- Akan diisi oleh JS -->
    </div>
    <div class="preview-controls no-print">
        <button class="preview-close" onclick="closePreview()"><i class="fas fa-times mr-2"></i>Tutup</button>
        <button class="preview-print" onclick="window.print()"><i class="fas fa-print mr-2"></i>Cetak</button>
    </div>
</div>

<script>
// Dark mode
const darkModeToggle = document.getElementById('darkModeToggle');
function setDarkMode(isDark) {
    if (isDark) document.documentElement.classList.add('dark');
    else document.documentElement.classList.remove('dark');
    localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled');
}
const saved = localStorage.getItem('darkMode');
if (saved === 'enabled') setDarkMode(true);
else if (saved !== 'disabled') setDarkMode(window.matchMedia('(prefers-color-scheme: dark)').matches);
darkModeToggle?.addEventListener('click', () => setDarkMode(!document.documentElement.classList.contains('dark')));

// Tab switching
const tabs = document.querySelectorAll('.tab-button');
tabs.forEach(btn => {
    btn.addEventListener('click', () => {
        const tab = btn.getAttribute('data-tab');
        tabs.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.getElementById(`tab-${tab}`).classList.add('active');
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.pushState({}, '', url);
    });
});

// Data nilai untuk preview (diisi dari PHP)
const previewData = {
    pagi: {
        subjects: <?= json_encode($subjects_pagi) ?>,
        scores: <?= json_encode($filtered_scores_pagi) ?>,
        className: '<?= htmlspecialchars(array_column($classes, 'class_name', 'id')[$class_pagi_id] ?? 'Kelas Pagi') ?>',
        schoolCity: '<?= $school_city ?>',
        signatureDate: '<?= $signature_date ?>'
    },
    diniyyah: {
        subjects: <?= json_encode($subjects_diniyyah) ?>,
        scores: <?= json_encode($filtered_scores_diniyyah) ?>,
        className: '<?= htmlspecialchars(array_column($classes, 'class_name', 'id')[$class_diniyyah_id] ?? 'Kelas Diniyyah') ?>',
        schoolCity: '<?= $school_city ?>',
        signatureDate: '<?= $signature_date ?>'
    }
};

function openPreview(type) {
    const modal = document.getElementById('previewModal');
    const content = document.getElementById('previewContent');
    const data = previewData[type];
    if (!data) return;

    const { subjects, scores, className, schoolCity, signatureDate } = data;
    const studentName = '<?= htmlspecialchars($student['full_name']) ?>';
    const studentNisn = '<?= htmlspecialchars($student['nidn_or_nisn']) ?>';
    const now = new Date().toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });

    let html = `
    <div class="letter-head">
        <h1>LEGER NILAI SISWA</h1>
        <p><strong>Nama Siswa:</strong> ${studentName}</p>
        <p><strong>NIS / NISN:</strong> ${studentNisn}</p>
        <p><strong>Kelas:</strong> ${className}</p>
        <p class="sub">Dicetak: ${now}</p>
    </div>
    `;

    const years = Object.keys(scores);
    if (years.length === 0) {
        html += `<p class="text-center text-gray-500">Belum ada nilai untuk ditampilkan.</p>`;
    } else {
        for (let year of years) {
            for (let sem in scores[year]) {
                const semLabel = sem == 1 ? 'Ganjil' : 'Genap';
                html += `
                <div style="margin-bottom:20px;">
                    <h3 style="font-weight:600; font-size:16px; border-bottom:1px solid #ccc; padding-bottom:4px;">Tahun Ajaran: ${year} - Semester ${semLabel}</h3>
                    <table class="preview-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">No</th>
                                <th>Mata Pelajaran</th>
                                <th style="width:100px; text-align:center;">Nilai</th>
                            </tr>
                        </thead>
                        <tbody>
                `;
                let no = 1;
                for (let subjId in scores[year][sem]) {
                    const subjectName = subjects[subjId] || 'Mata Pelajaran';
                    const score = Number(scores[year][sem][subjId]).toFixed(2);
                    html += `
                        <tr>
                            <td style="text-align:center;">${no++}</td>
                            <td>${subjectName}</td>
                            <td style="text-align:center; font-weight:600;">${score}</td>
                        </tr>
                    `;
                }
                html += `
                        </tbody>
                    </table>
                </div>
                `;
            }
        }
    }

    // Tanda tangan Kepala Sekolah dengan tempat dan tanggal dari konfigurasi PHP
    html += `
    <div class="signature-area">
        <div class="signature-box">
            <p style="margin:0;">${schoolCity}, ${signatureDate}</p>
            <p style="margin:0;">Kepala Sekolah,</p>
            <div class="signature-line" style="width:200px; margin:30px auto 0;"></div>
            <p style="margin-top:5px; font-weight:bold;">(.........................................)</p>
        </div>
    </div>
    `;

    content.innerHTML = html;
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closePreview() {
    document.getElementById('previewModal').style.display = 'none';
    document.body.style.overflow = '';
}

// Tutup modal jika klik di luar konten
document.getElementById('previewModal').addEventListener('click', function(e) {
    if (e.target === this) closePreview();
});

// Mobile sidebar
const openBtn = document.getElementById('openSidebarUserBtn');
if (openBtn) openBtn.addEventListener('click', function() {
    const sidebar = document.getElementById('sidebarUser');
    if (sidebar) {
        sidebar.classList.remove('-translate-x-full');
        sidebar.classList.add('translate-x-0');
        document.getElementById('sidebarOverlayUser')?.classList.remove('hidden');
        document.body.classList.add('sidebar-open');
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>