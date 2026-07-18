<?php
// profile_content.php
global $user, $user_role, $kelas_pagi, $kelas_diniyyah, $password_message;
?>
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden max-w-4xl mx-auto">
    <div class="bg-gradient-to-r from-blue-600 to-indigo-700 px-6 py-4">
        <h2 class="text-2xl font-bold text-white">Profil Pengguna</h2>
        <p class="text-blue-100">Informasi lengkap akun Anda</p>
    </div>
    <div class="p-6">
        <div class="flex flex-col md:flex-row gap-6">
            <!-- Foto -->
            <div class="flex-shrink-0">
                <div class="w-32 h-32 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center overflow-hidden">
                    <?php if (!empty($user['photo_url'])): ?>
                        <img src="<?= htmlspecialchars($user['photo_url']) ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                        <i class="fas fa-user-circle text-6xl text-gray-500"></i>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Data diri -->
            <div class="flex-1 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><span class="font-semibold">Nama Lengkap:</span> <?= htmlspecialchars($user['full_name'] ?? '-') ?></div>
                <div><span class="font-semibold">Role:</span> <?= ucfirst($user_role) ?></div>
                <div><span class="font-semibold"><?= $user_role == 'teacher' ? 'NIDN' : 'NIS' ?>:</span> <?= htmlspecialchars($user['nidn_or_nisn'] ?? '-') ?></div>
                <div><span class="font-semibold">Email/NIK:</span> <?= htmlspecialchars($user['nik'] ?? '-') ?></div>
                <div><span class="font-semibold">Nomor HP:</span> <?= htmlspecialchars($user['phone'] ?? '-') ?></div>
                <div><span class="font-semibold">Tahun Masuk:</span> <?= htmlspecialchars($user['tahun_masuk'] ?? '-') ?></div>
                <?php if ($user_role == 'student'): ?>
                <div><span class="font-semibold">Kelas Pagi:</span> <?= $kelas_pagi ?></div>
                <div><span class="font-semibold">Kelas Diniyyah:</span> <?= $kelas_diniyyah ?></div>
                <div><span class="font-semibold">Bagian Diniyyah:</span> <?= htmlspecialchars($user['bagian'] ?? '-') ?></div>
                <div><span class="font-semibold">Tingkat Diniyyah:</span> <?= htmlspecialchars($user['tingkat'] ?? '-') ?></div>
                <?php endif; ?>
                <div><span class="font-semibold">Tempat Lahir:</span> <?= htmlspecialchars($user['tempat_lahir'] ?? '-') ?></div>
                <div><span class="font-semibold">Tanggal Lahir:</span> <?= htmlspecialchars($user['tanggal_lahir'] ?? '-') ?></div>
                <div class="md:col-span-2"><span class="font-semibold">Alamat:</span> <?= nl2br(htmlspecialchars($user['alamat'] ?? '-')) ?></div>
                <div><span class="font-semibold">Nama Ayah:</span> <?= htmlspecialchars($user['nama_ayah'] ?? '-') ?></div>
                <div><span class="font-semibold">Pekerjaan Ayah:</span> <?= htmlspecialchars($user['pekerjaan_ayah'] ?? '-') ?></div>
                <div><span class="font-semibold">Nama Ibu:</span> <?= htmlspecialchars($user['nama_ibu'] ?? '-') ?></div>
                <div><span class="font-semibold">Pekerjaan Ibu:</span> <?= htmlspecialchars($user['pekerjaan_ibu'] ?? '-') ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Form Ganti Password -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden max-w-4xl mx-auto mt-6">
    <div class="bg-gray-100 dark:bg-gray-700 px-6 py-3">
        <h3 class="text-lg font-semibold">Ganti Password</h3>
    </div>
    <div class="p-6">
        <?= $password_message ?>
        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Password Lama</label>
                <input type="password" name="old_password" required class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white">
            </div>
            <div>
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Password Baru (min 6 karakter)</label>
                <input type="password" name="new_password" required class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white">
            </div>
            <div>
                <label class="block text-gray-700 dark:text-gray-300 mb-1">Konfirmasi Password Baru</label>
                <input type="password" name="confirm_password" required class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white">
            </div>
            <button type="submit" name="change_password" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Ubah Password</button>
        </form>
    </div>
</div>

<script>
// Dark mode (sama seperti sebelumnya)
const darkModeToggle = document.getElementById('darkModeToggle');
function setDarkMode(isDark) {
    if (isDark) document.documentElement.classList.add('dark');
    else document.documentElement.classList.remove('dark');
    localStorage.setItem('darkMode', isDark ? 'enabled' : 'disabled');
    if (darkModeToggle) {
        const moon = darkModeToggle.querySelector('.fa-moon');
        const sun = darkModeToggle.querySelector('.fa-sun');
        if (moon && sun) {
            moon.classList.toggle('hidden', isDark);
            sun.classList.toggle('hidden', !isDark);
        }
    }
}
const saved = localStorage.getItem('darkMode');
if (saved === 'enabled') setDarkMode(true);
else if (saved === 'disabled') setDarkMode(false);
else setDarkMode(window.matchMedia('(prefers-color-scheme: dark)').matches);
darkModeToggle?.addEventListener('click', () => setDarkMode(!document.documentElement.classList.contains('dark')));
</script>