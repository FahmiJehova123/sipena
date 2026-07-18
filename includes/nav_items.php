<?php
// includes/nav_items.php - Menu sidebar admin dengan submenu
$nav_items = [
    ['icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard', 'link' => 'admin_dashboard.php'],

    // Submenu Manajemen User
    ['icon' => 'fas fa-users', 'label' => 'Manajemen User', 'link' => '#', 'children' => [
        ['icon' => 'fas fa-chalkboard-teacher', 'label' => 'Manajemen Guru', 'link' => 'manage_users.php?role=teacher'],
        ['icon' => 'fas fa-user-graduate', 'label' => 'Manajemen Murid', 'link' => 'manage_users.php?role=student'],
        ['icon' => 'fas fa-user-shield', 'label' => 'Manajemen Admin', 'link' => 'manage_admins.php']
    ]],

    // Submenu Akademik
    ['icon' => 'fas fa-building', 'label' => 'Akademik', 'link' => '#', 'children' => [
        ['icon' => 'fas fa-building', 'label' => 'Manajemen Kelas', 'link' => 'manage_classes.php'],
        ['icon' => 'fas fa-people-arrows', 'label' => 'Rombel', 'link' => 'manage_rombel.php'],
        ['icon' => 'fas fa-book', 'label' => 'Mata Pelajaran', 'link' => 'manage_subjects.php'],
        ['icon' => 'fas fa-file-alt', 'label' => 'Manajemen Soal', 'link' => 'manage_soal.php'],
        ['icon' => 'fas fa-calendar-alt', 'label' => 'Jadwal', 'link' => 'manage_schedules.php'],
        ['icon' => 'fas fa-calendar-check', 'label' => 'Kegiatan', 'link' => 'manage_activities.php'],
        ['icon' => 'fas fa-chart-line', 'label' => 'Laporan', 'link' => 'reports.php'],
        ['icon' => 'fa-solid fa-table-list', 'label' => 'Manajemen Nilai', 'link' => 'manage_exam_scores.php'],
        ['icon' => 'fa-solid fa-file-contract', 'label' => 'Manajemen Ijazah', 'link' => 'manage_diploma_scores.php'],
        ['icon' => 'fa-solid fa-wave-square', 'label' => 'Monitoring Nilai', 'link' => 'monitoring_scores.php']
    ]],

    // Menu tanpa submenu
    ['icon' => 'fa-solid fa-toggle-on', 'label' => 'Sidebar Menu', 'link' => 'manage_sidebar.php'],
    ['icon' => 'fas fa-cog', 'label' => 'Pengaturan', 'link' => 'profile.php']
];
?>