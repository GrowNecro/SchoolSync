<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);
session_start();

require __DIR__ . '/app.php';

$error = '';
$success = (string) ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_success']);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'setup_database' && !database_configured()) {
            verify_csrf();
            configure_database($_POST);
            $_SESSION['flash_success'] = 'Database MySQL terhubung dan tabel SchoolSync berhasil dibuat.';
            header('Location: index.php');
            exit;
        }

        if ($action === 'setup' && !is_installed()) {
            verify_csrf();
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if (strlen($username) < 3) {
                throw new RuntimeException('Nama admin minimal 3 karakter.');
            }
            if (strlen($password) < 8) {
                throw new RuntimeException('Kata sandi minimal 8 karakter.');
            }
            if (!hash_equals($password, (string) ($_POST['password_confirm'] ?? ''))) {
                throw new RuntimeException('Konfirmasi kata sandi tidak sama.');
            }
            create_admin($username, password_hash($password, PASSWORD_DEFAULT));
            session_regenerate_id(true);
            $_SESSION['schoolsync_admin'] = true;
            $_SESSION['flash_success'] = 'Panel SchoolSync berhasil disiapkan.';
            header('Location: index.php');
            exit;
        }

        if ($action === 'login' && is_installed()) {
            verify_csrf();
            $data = settings();
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if (!hash_equals((string) ($data['username'] ?? ''), $username)
                || !password_verify($password, (string) ($data['password_hash'] ?? ''))) {
                usleep(350000);
                throw new RuntimeException('Nama admin atau kata sandi salah.');
            }
            session_regenerate_id(true);
            $_SESSION['schoolsync_admin'] = true;
            header('Location: index.php');
            exit;
        }

        require_login();
        verify_csrf();

        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            header('Location: index.php');
            exit;
        }

        if ($action === 'save_config') {
            $allowedDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            $day = (string) ($_POST['day'] ?? '');
            if (!in_array($day, $allowedDays, true)) {
                throw new RuntimeException('Hari pelaksanaan tidak valid.');
            }
            $start = validate_time((string) ($_POST['start'] ?? ''), 'Jam mulai');
            $end = validate_time((string) ($_POST['end'] ?? ''), 'Jam selesai');
            if ($end <= $start) {
                throw new RuntimeException('Jam selesai harus setelah jam mulai pada hari yang sama.');
            }

            $browsers = clean_lines((string) ($_POST['browser'] ?? ''));
            foreach ($browsers as $url) {
                if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
                    throw new RuntimeException('Alamat website tidak valid: ' . $url);
                }
            }

            $allowedLaunchers = ['edge', 'roblox', 'vscode', 'scratch', 'construct', 'python'];
            $launchers = array_values(array_intersect($allowedLaunchers, (array) ($_POST['launcher'] ?? [])));
            $project = trim((string) ($_POST['project'] ?? ''));
            if ($project !== '') {
                $project = safe_project_name($project);
                if (!is_file(SCHOOLSYNC_UPLOADS . '/' . $project)) {
                    throw new RuntimeException('Proyek yang dipilih tidak ditemukan. Unggah proyek terlebih dahulu.');
                }
            }

            $warning = filter_var($_POST['warning'] ?? 10, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 120],
            ]);
            if ($warning === false) {
                throw new RuntimeException('Peringatan shutdown harus antara 1 sampai 120 menit.');
            }

            save_config([
                'schedule' => ['day' => $day, 'start' => $start, 'end' => $end],
                'project' => $project,
                'browser' => $browsers,
                'launcher' => $launchers,
                'shutdown' => [
                    'enabled' => isset($_POST['shutdown_enabled']),
                    'warning' => $warning,
                ],
                'updated_at' => gmdate('c'),
            ]);
            $_SESSION['flash_success'] = 'Konfigurasi berhasil disimpan dan siap dibaca komputer lab.';
            header('Location: index.php');
            exit;
        }

        if ($action === 'upload_project') {
            $upload = $_FILES['project_file'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $code = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
                $message = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'Ukuran proyek melebihi batas upload server.'
                    : 'Pilih file proyek yang akan diunggah.';
                throw new RuntimeException($message);
            }
            $name = safe_project_name((string) $upload['name']);
            if (!is_uploaded_file((string) $upload['tmp_name'])) {
                throw new RuntimeException('File upload tidak dikenali oleh server.');
            }
            if (!move_uploaded_file((string) $upload['tmp_name'], SCHOOLSYNC_UPLOADS . '/' . $name)) {
                throw new RuntimeException('Proyek gagal disimpan. Periksa izin folder uploads.');
            }
            save_project_record($name, (int) (filesize(SCHOOLSYNC_UPLOADS . '/' . $name) ?: 0));
            $_SESSION['flash_success'] = 'Proyek ' . $name . ' berhasil diunggah.';
            header('Location: index.php#projects');
            exit;
        }

        if ($action === 'delete_project') {
            $name = safe_project_name((string) ($_POST['name'] ?? ''));
            $path = SCHOOLSYNC_UPLOADS . '/' . $name;
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Proyek gagal dihapus.');
            }
            $config = current_config();
            if (($config['project'] ?? '') === $name) {
                $config['project'] = '';
                save_config($config);
            }
            delete_project_record($name);
            $_SESSION['flash_success'] = 'Proyek ' . $name . ' sudah dihapus.';
            header('Location: index.php#projects');
            exit;
        }

        if ($action === 'change_password') {
            $data = settings();
            $current = (string) ($_POST['current_password'] ?? '');
            $password = (string) ($_POST['new_password'] ?? '');
            if (!password_verify($current, (string) ($data['password_hash'] ?? ''))) {
                throw new RuntimeException('Kata sandi saat ini salah.');
            }
            if (strlen($password) < 8) {
                throw new RuntimeException('Kata sandi baru minimal 8 karakter.');
            }
            if (!hash_equals($password, (string) ($_POST['new_password_confirm'] ?? ''))) {
                throw new RuntimeException('Konfirmasi kata sandi baru tidak sama.');
            }
            update_admin_password((int) $data['id'], password_hash($password, PASSWORD_DEFAULT));
            $_SESSION['flash_success'] = 'Kata sandi admin berhasil diperbarui.';
            header('Location: index.php#security');
            exit;
        }
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$databaseConfigured = database_configured();
$databaseError = '';
$installed = false;
$loggedIn = false;
$config = default_config();
$projects = [];
if ($databaseConfigured) {
    try {
        $installed = is_installed();
        $loggedIn = is_logged_in();
        $config = current_config();
        $projects = $loggedIn ? project_files() : [];
    } catch (Throwable $exception) {
        $databaseError = 'Koneksi MySQL gagal. Periksa file config/database.php dan hak akses database.';
    }
}
$days = [
    'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
    'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Minggu',
];
$launchers = [
    'edge' => 'Microsoft Edge', 'roblox' => 'Roblox Studio', 'vscode' => 'Visual Studio Code',
    'scratch' => 'Scratch Desktop', 'construct' => 'Construct 3', 'python' => 'Python IDLE',
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>SchoolSync Control</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="<?= $loggedIn ? 'app-view' : 'auth-view' ?>">
<?php if (!$installed || !$loggedIn): ?>
    <main class="auth-shell">
        <section class="auth-brand">
            <a class="brand" href="index.php"><span class="brand-mark">S</span><span>SchoolSync</span></a>
            <div class="auth-copy">
                <span class="eyebrow">Kontrol laboratorium</span>
                <h1>Siapkan kelas dari satu tempat.</h1>
                <p>Atur jadwal, proyek, website, dan aplikasi yang dibuka di seluruh komputer laboratorium.</p>
            </div>
            <div class="signal-card"><span class="signal-dot"></span><span>Panel siap dihubungkan ke komputer lab</span></div>
        </section>
        <section class="auth-panel">
            <div class="auth-form-wrap">
                <span class="mobile-brand">SchoolSync Control</span>
                <?php if (!$databaseConfigured): ?>
                    <h2>Hubungkan MySQL</h2>
                    <p>Masukkan database yang sudah dibuat melalui cPanel Rumahweb. Tabel SchoolSync akan dibuat otomatis.</p>
                <?php elseif ($databaseError): ?>
                    <h2>Database tidak terhubung</h2>
                    <p>Periksa kredensial pada <code>config/database.php</code>, lalu muat ulang halaman ini.</p>
                <?php else: ?>
                    <h2><?= $installed ? 'Selamat datang' : 'Siapkan panel admin' ?></h2>
                    <p><?= $installed ? 'Masuk untuk mengelola sesi pembelajaran.' : 'Buat akun admin pertama untuk mengamankan panel.' ?></p>
                <?php endif; ?>
                <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <?php if (!$databaseConfigured): ?>
                    <form method="post" class="stack-form">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="setup_database">
                        <div class="form-grid two compact-grid">
                            <label>Host MySQL<input name="db_host" value="localhost" required autofocus></label>
                            <label>Port<input type="number" name="db_port" value="3306" min="1" max="65535" required></label>
                        </div>
                        <label>Nama database<input name="db_name" placeholder="akun_schoolsync" required></label>
                        <label>Pengguna database<input name="db_username" autocomplete="username" placeholder="akun_schoolsync" required></label>
                        <label>Kata sandi database<input type="password" name="db_password" autocomplete="current-password"></label>
                        <button class="button primary wide" type="submit">Hubungkan dan buat tabel</button>
                    </form>
                    <small>Database dan pengguna harus dibuat terlebih dahulu melalui cPanel.</small>
                <?php elseif ($databaseError): ?>
                    <div class="alert error"><?= htmlspecialchars($databaseError) ?></div>
                    <small>File konfigurasi database tidak dapat diubah dari halaman ini setelah dibuat.</small>
                <?php else: ?>
                    <form method="post" class="stack-form">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="<?= $installed ? 'login' : 'setup' ?>">
                        <label>Nama admin<input name="username" autocomplete="username" required minlength="3" autofocus></label>
                        <label>Kata sandi<input type="password" name="password" autocomplete="<?= $installed ? 'current-password' : 'new-password' ?>" required minlength="8"></label>
                        <?php if (!$installed): ?>
                            <label>Ulangi kata sandi<input type="password" name="password_confirm" autocomplete="new-password" required minlength="8"></label>
                        <?php endif; ?>
                        <button class="button primary wide" type="submit"><?= $installed ? 'Masuk ke panel' : 'Buat panel admin' ?></button>
                    </form>
                    <small>Gunakan HTTPS saat panel sudah berada di hosting.</small>
                <?php endif; ?>
            </div>
        </section>
    </main>
<?php else: ?>
    <div class="layout">
        <aside class="sidebar">
            <a class="brand" href="#top"><span class="brand-mark">S</span><span>SchoolSync</span></a>
            <nav>
                <a class="active" href="#overview">Ringkasan</a>
                <a href="#schedule">Sesi kelas</a>
                <a href="#projects">Proyek</a>
                <a href="#automation">Otomatisasi</a>
                <a href="#connection">Koneksi</a>
                <a href="#security">Keamanan</a>
            </nav>
            <form method="post" class="logout-form">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit">Keluar</button>
            </form>
        </aside>

        <main class="main" id="top">
            <header class="topbar">
                <button class="menu-button" type="button" aria-label="Buka menu" data-menu>☰</button>
                <div><span class="breadcrumb">LAB CONTROL /</span> DASHBOARD</div>
                <div class="admin-chip"><span><?= strtoupper(substr((string) (settings()['username'] ?? 'A'), 0, 1)) ?></span><?= htmlspecialchars((string) (settings()['username'] ?? 'Admin')) ?></div>
            </header>

            <div class="content">
                <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
                <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

                <section class="hero" id="overview">
                    <div>
                        <span class="eyebrow">SchoolSync Control</span>
                        <h1>Ruang kelas siap,<br>sebelum siswa masuk.</h1>
                        <p>Semua perubahan di halaman ini otomatis tersedia untuk komputer laboratorium saat SchoolSync dijalankan.</p>
                    </div>
                    <div class="hero-status">
                        <span>Status konfigurasi</span>
                        <strong><i></i> Aktif</strong>
                        <small>Terakhir disimpan <?= htmlspecialchars(date('d M Y, H:i', strtotime((string) ($config['updated_at'] ?? 'now')))) ?> WIB</small>
                    </div>
                </section>

                <section class="metrics">
                    <article><span>Jadwal aktif</span><strong><?= htmlspecialchars($days[$config['schedule']['day']] ?? '-') ?></strong><small><?= htmlspecialchars($config['schedule']['start']) ?>—<?= htmlspecialchars($config['schedule']['end']) ?> WIB</small></article>
                    <article><span>Proyek dipilih</span><strong><?= $config['project'] ? '1 proyek' : 'Belum ada' ?></strong><small><?= htmlspecialchars($config['project'] ?: 'Unggah dan pilih proyek') ?></small></article>
                    <article><span>Otomatisasi</span><strong><?= count((array) $config['launcher']) ?> aplikasi</strong><small><?= count((array) $config['browser']) ?> website akan dibuka</small></article>
                </section>

                <form method="post" class="config-form">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_config">

                    <section class="panel" id="schedule">
                        <div class="panel-heading"><div><span class="step">01</span><h2>Sesi kelas</h2><p>Tentukan kapan SchoolSync menjalankan persiapan kelas.</p></div></div>
                        <div class="form-grid three">
                            <label>Hari pelaksanaan<select name="day" required><?php foreach ($days as $value => $label): ?><option value="<?= $value ?>" <?= $config['schedule']['day'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                            <label>Jam mulai<input type="time" name="start" value="<?= htmlspecialchars($config['schedule']['start']) ?>" required></label>
                            <label>Jam selesai<input type="time" name="end" value="<?= htmlspecialchars($config['schedule']['end']) ?>" required></label>
                        </div>
                    </section>

                    <section class="panel" id="projects">
                        <div class="panel-heading"><div><span class="step">02</span><h2>Proyek aktif</h2><p>Pilih file yang akan disalin dan dibuka di komputer lab.</p></div><a class="text-link" href="#project-upload">Unggah proyek baru</a></div>
                        <label>Pilih proyek<select name="project"><option value="">Tidak menggunakan proyek</option><?php foreach ($projects as $project): ?><option value="<?= htmlspecialchars($project['name']) ?>" <?= $config['project'] === $project['name'] ? 'selected' : '' ?>><?= htmlspecialchars($project['name']) ?></option><?php endforeach; ?></select></label>
                    </section>

                    <section class="panel" id="automation">
                        <div class="panel-heading"><div><span class="step">03</span><h2>Otomatisasi</h2><p>Atur halaman dan aplikasi yang dibuka saat sesi dimulai.</p></div></div>
                        <div class="form-grid two">
                            <label>Website yang dibuka <span class="hint">Satu alamat per baris</span><textarea name="browser" rows="6" placeholder="https://classroom.google.com"><?= htmlspecialchars(implode("\n", (array) $config['browser'])) ?></textarea></label>
                            <fieldset><legend>Aplikasi yang dijalankan</legend><div class="check-grid"><?php foreach ($launchers as $value => $label): ?><label class="check"><input type="checkbox" name="launcher[]" value="<?= $value ?>" <?= in_array($value, (array) $config['launcher'], true) ? 'checked' : '' ?>><span><b><?= htmlspecialchars($label) ?></b><small><?= htmlspecialchars($value) ?></small></span></label><?php endforeach; ?></div></fieldset>
                        </div>
                        <div class="shutdown-row">
                            <label class="switch-label"><input type="checkbox" name="shutdown_enabled" <?= $config['shutdown']['enabled'] ? 'checked' : '' ?>><span class="switch"></span><span><b>Matikan komputer otomatis</b><small>Komputer dimatikan ketika sesi berakhir.</small></span></label>
                            <label class="warning-field">Peringatan sebelum selesai <span><input type="number" name="warning" min="1" max="120" value="<?= (int) $config['shutdown']['warning'] ?>"> menit</span></label>
                        </div>
                    </section>

                    <div class="save-bar"><span>Perubahan baru diterapkan setelah disimpan.</span><button class="button primary" type="submit">Simpan konfigurasi</button></div>
                </form>

                <section class="panel" id="project-upload">
                    <div class="panel-heading"><div><span class="step">04</span><h2>Perpustakaan proyek</h2><p>Kelola file Roblox yang tersedia untuk sesi pembelajaran.</p></div></div>
                    <form method="post" enctype="multipart/form-data" class="upload-box">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="upload_project">
                        <label><b>Taruh proyek Roblox di sini</b><span>Format .rbxl atau .rbxlx, mengikuti batas upload hosting.</span><input type="file" name="project_file" accept=".rbxl,.rbxlx" required></label>
                        <button class="button secondary" type="submit">Unggah proyek</button>
                    </form>
                    <div class="file-list">
                        <?php if (!$projects): ?><div class="empty">Belum ada proyek yang diunggah.</div><?php endif; ?>
                        <?php foreach ($projects as $project): ?><article><div class="file-icon">RB</div><div><strong><?= htmlspecialchars($project['name']) ?></strong><small><?= format_bytes((int) $project['size']) ?> · <?= date('d M Y, H:i', (int) $project['modified']) ?></small></div><span class="<?= $config['project'] === $project['name'] ? 'active-badge' : 'muted-badge' ?>"><?= $config['project'] === $project['name'] ? 'Aktif' : 'Tersimpan' ?></span><form method="post" onsubmit="return confirm('Hapus proyek ini?')"><input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete_project"><input type="hidden" name="name" value="<?= htmlspecialchars($project['name']) ?>"><button class="delete-button" type="submit" aria-label="Hapus <?= htmlspecialchars($project['name']) ?>">Hapus</button></form></article><?php endforeach; ?>
                    </div>
                </section>

                <section class="panel connection" id="connection">
                    <div class="panel-heading"><div><span class="step">05</span><h2>Hubungkan komputer lab</h2><p>Unduh installer dari panel, lalu jalankan sebagai administrator pada setiap komputer.</p></div><a class="button download-button" href="api/installer.php">Unduh installer</a></div>
                    <div class="endpoint"><code>Install.bat "<?= htmlspecialchars(base_url()) ?>"</code><button type="button" data-copy>Salin</button></div>
                    <small>Installer unduhan sudah berisi alamat panel ini. Perintah di atas tersedia sebagai cara alternatif. Seluruh aplikasi, konfigurasi, dan proyek berasal dari panel—tanpa GitHub.</small>
                </section>

                <section class="panel" id="security">
                    <div class="panel-heading"><div><span class="step">06</span><h2>Keamanan admin</h2><p>Perbarui kata sandi panel secara berkala.</p></div></div>
                    <form method="post" class="form-grid three password-form">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="change_password">
                        <label>Kata sandi saat ini<input type="password" name="current_password" required></label>
                        <label>Kata sandi baru<input type="password" name="new_password" minlength="8" required></label>
                        <label>Ulangi kata sandi baru<input type="password" name="new_password_confirm" minlength="8" required></label>
                        <button class="button secondary" type="submit">Perbarui kata sandi</button>
                    </form>
                </section>
            </div>
            <footer>SchoolSync Control · <?= date('Y') ?></footer>
        </main>
    </div>
<?php endif; ?>
<script>
const menu = document.querySelector('[data-menu]');
if (menu) menu.addEventListener('click', () => document.body.classList.toggle('menu-open'));
document.querySelectorAll('.sidebar a').forEach(link => link.addEventListener('click', () => document.body.classList.remove('menu-open')));
const copy = document.querySelector('[data-copy]');
if (copy) copy.addEventListener('click', async () => {
  const value = copy.previousElementSibling.textContent;
  await navigator.clipboard.writeText(value);
  copy.textContent = 'Tersalin';
  setTimeout(() => copy.textContent = 'Salin', 1600);
});
</script>
</body>
</html>
