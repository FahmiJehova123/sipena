<!DOCTYPE html>
<html lang="id" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title><?= $page_title ?? 'SIPENA' ?></title>

    <!-- Tailwind & AOS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class', theme: { extend: {} } }</script>
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

    <!-- FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/id.js"></script>

    <!-- Toast Notifikasi -->
    <link rel="stylesheet" href="/siakad/assets/css/toast_style.css">
	<script src="/siakad/assets/js/audio-notif.js"></script>
    <script src="/siakad/assets/js/toast_notif.js"></script>

    <!-- Supabase (opsional, jika diperlukan) -->
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <!-- Dark mode inisialisasi (menggunakan localStorage) -->
    <script>
        (function() {
            const saved = localStorage.getItem('darkMode');
            const html = document.documentElement;
            if (saved === 'enabled') {
                html.classList.add('dark');
            } else if (saved === 'disabled') {
                html.classList.remove('dark');
            } else if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                html.classList.add('dark');
                localStorage.setItem('darkMode', 'enabled');
            }
        })();
    </script>

    <style>
        .sidebar-transition { transition: transform 0.3s ease-in-out; }
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        body.sidebar-open { overflow: hidden; }
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 font-sans antialiased">