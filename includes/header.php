<!DOCTYPE html>
<html lang="id" class="<?= isset($_COOKIE['darkMode']) && $_COOKIE['darkMode'] == 'enabled' ? 'dark' : '' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= $page_title ?? 'SIAKAD Admin' ?></title>
	<!-- Service Workers -->
	<link rel="manifest" href="/siakad/manifest.json">
    <meta name="theme-color" content="#1e3a8a">
    <meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="SIAKAD">
    <link rel="apple-touch-icon" href="/siakad/assets/icons/icon-192x192.png">
	
	<!-- CDN Tailwind & AOS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class', theme: { extend: {} } }</script>
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
	<!-- CDN Kalender -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/id.js"></script>
	<!-- Toast Notifikasi -->
	<link rel="stylesheet" href="/siakad/assets/css/toast_style.css">
	<script src="/siakad/assets/js/audio-notif.js"></script>
	<script src="/siakad/assets/js/toast_notif.js"></script>
	<!-- CDN Chart & Supabase -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
	<!-- CDN Icon Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Sidebar transition untuk mobile (slide & geser konten) */
        .mobile-sidebar-transition {
            transition: transform 0.3s ease-in-out, margin-left 0.3s ease-in-out;
        }
        .sidebar-collapsed { width: 80px; }
        .sidebar-collapsed .sidebar-text { display: none; }
        .sidebar-collapsed .sidebar-icon { margin-right: 0; }
        .sidebar-collapsed .logo-text { display: none; }
        .sidebar-collapsed .logo-icon { display: inline; }
        .sidebar-collapsed nav ul li a { justify-content: center; }
        /* Sembunyikan scrollbar */
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 font-sans antialiased">