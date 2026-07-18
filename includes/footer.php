<script>
    // Dark mode toggle (tombol dengan id darkModeToggle ada di header)
    const darkModeToggle = document.getElementById('darkModeToggle');
    if (darkModeToggle) {
        darkModeToggle.addEventListener('click', () => {
            const html = document.documentElement;
            html.classList.toggle('dark');
            const isDark = html.classList.contains('dark');
            document.cookie = `darkMode=${isDark ? 'enabled' : 'disabled'}; path=/; max-age=31536000`;
        });
    }
    if (typeof AOS !== 'undefined') AOS.init({ duration: 800, once: true });
</script>
<script src="/siakad/register-sw.js" defer></script>
</body>
</html>