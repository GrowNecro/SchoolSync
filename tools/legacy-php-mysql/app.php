<?php
declare(strict_types=1);

const SCHOOLSYNC_STORAGE = __DIR__ . '/storage';
const SCHOOLSYNC_UPLOADS = __DIR__ . '/uploads';
const SCHOOLSYNC_CONFIG_DIR = __DIR__ . '/config';
const SCHOOLSYNC_DATABASE_CONFIG = SCHOOLSYNC_CONFIG_DIR . '/database.php';
const SCHOOLSYNC_LEGACY_SETTINGS = SCHOOLSYNC_STORAGE . '/settings.json';
const SCHOOLSYNC_LEGACY_CONFIG = SCHOOLSYNC_STORAGE . '/config.json';

function bootstrap_app(): void
{
    foreach ([SCHOOLSYNC_STORAGE, SCHOOLSYNC_UPLOADS, SCHOOLSYNC_CONFIG_DIR] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Folder aplikasi tidak dapat dibuat: ' . $directory);
        }
    }
}

function default_config(): array
{
    return [
        'schedule' => ['day' => 'Friday', 'start' => '14:00', 'end' => '16:00'],
        'project' => '',
        'browser' => ['https://classroom.google.com'],
        'launcher' => ['edge', 'roblox'],
        'shutdown' => ['enabled' => false, 'warning' => 10],
        'updated_at' => gmdate('c'),
    ];
}

function database_configured(): bool
{
    return is_file(SCHOOLSYNC_DATABASE_CONFIG);
}

function database_options(): array
{
    if (!database_configured()) {
        throw new RuntimeException('Database MySQL belum dikonfigurasi.');
    }
    $options = require SCHOOLSYNC_DATABASE_CONFIG;
    if (!is_array($options)) {
        throw new RuntimeException('Konfigurasi database tidak valid.');
    }
    return $options;
}

function pdo_from_options(array $options): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $options['host'],
        $options['port'],
        $options['database']
    );
    return new PDO($dsn, (string) $options['username'], (string) $options['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function database(): PDO
{
    static $pdo = null;
    if (!$pdo instanceof PDO) {
        $pdo = pdo_from_options(database_options());
        initialize_database($pdo);
    }
    return $pdo;
}

function initialize_database(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS schoolsync_admins (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS schoolsync_settings (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        schedule_day VARCHAR(16) NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        project VARCHAR(255) NULL,
        browser_json LONGTEXT NOT NULL,
        launcher_json LONGTEXT NOT NULL,
        shutdown_enabled TINYINT(1) NOT NULL DEFAULT 0,
        shutdown_warning SMALLINT UNSIGNED NOT NULL DEFAULT 10,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS schoolsync_projects (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL UNIQUE,
        size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    migrate_legacy_json($pdo);
    if ((int) $pdo->query('SELECT COUNT(*) FROM schoolsync_settings')->fetchColumn() === 0) {
        save_config(default_config(), $pdo);
    }
    $initialized = true;
}

function configure_database(array $input): void
{
    $host = trim((string) ($input['db_host'] ?? 'localhost'));
    $port = filter_var($input['db_port'] ?? 3306, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
    $name = trim((string) ($input['db_name'] ?? ''));
    $username = trim((string) ($input['db_username'] ?? ''));
    $password = (string) ($input['db_password'] ?? '');

    if (!preg_match('/^[A-Za-z0-9.-]+$/', $host) || $port === false) {
        throw new RuntimeException('Host atau port MySQL tidak valid.');
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name) || $name === '') {
        throw new RuntimeException('Nama database MySQL tidak valid.');
    }
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $username) || $username === '') {
        throw new RuntimeException('Nama pengguna MySQL tidak valid.');
    }

    $options = [
        'host' => $host,
        'port' => (int) $port,
        'database' => $name,
        'username' => $username,
        'password' => $password,
    ];

    try {
        $pdo = pdo_from_options($options);
        initialize_database($pdo);
    } catch (PDOException $exception) {
        throw new RuntimeException('Koneksi MySQL gagal. Periksa database, pengguna, kata sandi, dan hak aksesnya.');
    }

    $contents = "<?php\nreturn " . var_export($options, true) . ";\n";
    $temporary = SCHOOLSYNC_DATABASE_CONFIG . '.tmp';
    if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, SCHOOLSYNC_DATABASE_CONFIG)) {
        @unlink($temporary);
        throw new RuntimeException('Konfigurasi database tidak dapat disimpan. Periksa izin folder config.');
    }
}

function read_legacy_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function migrate_legacy_json(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM schoolsync_admins')->fetchColumn() === 0) {
        $legacyAdmin = read_legacy_json(SCHOOLSYNC_LEGACY_SETTINGS);
        if (isset($legacyAdmin['username'], $legacyAdmin['password_hash'])) {
            $statement = $pdo->prepare('INSERT INTO schoolsync_admins (username, password_hash) VALUES (:username, :password_hash)');
            $statement->execute([
                'username' => (string) $legacyAdmin['username'],
                'password_hash' => (string) $legacyAdmin['password_hash'],
            ]);
        }
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM schoolsync_settings')->fetchColumn() === 0) {
        $legacyConfig = read_legacy_json(SCHOOLSYNC_LEGACY_CONFIG);
        if ($legacyConfig) {
            save_config(array_replace(default_config(), $legacyConfig), $pdo);
        }
    }
}

function settings(): array
{
    $row = database()->query('SELECT id, username, password_hash, created_at, updated_at FROM schoolsync_admins ORDER BY id LIMIT 1')->fetch();
    return is_array($row) ? $row : [];
}

function create_admin(string $username, string $passwordHash): void
{
    $statement = database()->prepare('INSERT INTO schoolsync_admins (username, password_hash) VALUES (:username, :password_hash)');
    $statement->execute(['username' => $username, 'password_hash' => $passwordHash]);
}

function update_admin_password(int $id, string $passwordHash): void
{
    $statement = database()->prepare('UPDATE schoolsync_admins SET password_hash = :password_hash WHERE id = :id');
    $statement->execute(['id' => $id, 'password_hash' => $passwordHash]);
}

function current_config(): array
{
    $row = database()->query('SELECT * FROM schoolsync_settings WHERE id = 1')->fetch();
    if (!is_array($row)) {
        return default_config();
    }
    return [
        'schedule' => [
            'day' => (string) $row['schedule_day'],
            'start' => substr((string) $row['start_time'], 0, 5),
            'end' => substr((string) $row['end_time'], 0, 5),
        ],
        'project' => (string) ($row['project'] ?? ''),
        'browser' => json_decode((string) $row['browser_json'], true) ?: [],
        'launcher' => json_decode((string) $row['launcher_json'], true) ?: [],
        'shutdown' => [
            'enabled' => (bool) $row['shutdown_enabled'],
            'warning' => (int) $row['shutdown_warning'],
        ],
        'updated_at' => (string) $row['updated_at'],
    ];
}

function save_config(array $config, ?PDO $pdo = null): void
{
    $pdo ??= database();
    $statement = $pdo->prepare('INSERT INTO schoolsync_settings
        (id, schedule_day, start_time, end_time, project, browser_json, launcher_json, shutdown_enabled, shutdown_warning)
        VALUES (1, :day, :start_time, :end_time, :project, :browser_json, :launcher_json, :shutdown_enabled, :shutdown_warning)
        ON DUPLICATE KEY UPDATE schedule_day = VALUES(schedule_day), start_time = VALUES(start_time),
        end_time = VALUES(end_time), project = VALUES(project), browser_json = VALUES(browser_json),
        launcher_json = VALUES(launcher_json), shutdown_enabled = VALUES(shutdown_enabled),
        shutdown_warning = VALUES(shutdown_warning)');
    $statement->execute([
        'day' => (string) $config['schedule']['day'],
        'start_time' => (string) $config['schedule']['start'],
        'end_time' => (string) $config['schedule']['end'],
        'project' => ($config['project'] ?? '') !== '' ? (string) $config['project'] : null,
        'browser_json' => json_encode(array_values((array) ($config['browser'] ?? [])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'launcher_json' => json_encode(array_values((array) ($config['launcher'] ?? [])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'shutdown_enabled' => !empty($config['shutdown']['enabled']) ? 1 : 0,
        'shutdown_warning' => (int) ($config['shutdown']['warning'] ?? 10),
    ]);
}

function is_installed(): bool
{
    return (int) database()->query('SELECT COUNT(*) FROM schoolsync_admins')->fetchColumn() > 0;
}

function is_logged_in(): bool
{
    return isset($_SESSION['schoolsync_admin']) && $_SESSION['schoolsync_admin'] === true;
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $provided = $_POST['csrf'] ?? '';
    if (!is_string($provided) || !hash_equals(csrf_token(), $provided)) {
        throw new RuntimeException('Sesi formulir sudah tidak valid. Muat ulang halaman lalu coba lagi.');
    }
}

function clean_lines(string $value): array
{
    $lines = preg_split('/\R/u', $value) ?: [];
    $lines = array_map(static fn(string $line): string => trim($line), $lines);
    return array_values(array_unique(array_filter($lines, static fn(string $line): bool => $line !== '')));
}

function validate_time(string $value, string $label): string
{
    $date = DateTimeImmutable::createFromFormat('!H:i', $value);
    if (!$date || $date->format('H:i') !== $value) {
        throw new RuntimeException($label . ' tidak valid.');
    }
    return $value;
}

function safe_project_name(string $name): string
{
    $name = basename(trim($name));
    if ($name === '' || !preg_match('/^[A-Za-z0-9._ -]+\.(rbxl|rbxlx)$/i', $name)) {
        throw new RuntimeException('Nama proyek hanya boleh berisi huruf, angka, spasi, titik, strip, dan harus berformat .rbxl atau .rbxlx.');
    }
    return $name;
}

function save_project_record(string $name, int $size): void
{
    $statement = database()->prepare('INSERT INTO schoolsync_projects (filename, size) VALUES (:filename, :size)
        ON DUPLICATE KEY UPDATE size = VALUES(size), updated_at = CURRENT_TIMESTAMP');
    $statement->execute(['filename' => $name, 'size' => $size]);
}

function delete_project_record(string $name): void
{
    $statement = database()->prepare('DELETE FROM schoolsync_projects WHERE filename = :filename');
    $statement->execute(['filename' => $name]);
}

function project_exists(string $name): bool
{
    $statement = database()->prepare('SELECT COUNT(*) FROM schoolsync_projects WHERE filename = :filename');
    $statement->execute(['filename' => $name]);
    return (int) $statement->fetchColumn() > 0;
}

function project_files(): array
{
    foreach (glob(SCHOOLSYNC_UPLOADS . '/*') ?: [] as $path) {
        if (is_file($path) && preg_match('/\.(rbxl|rbxlx)$/i', basename($path))) {
            save_project_record(basename($path), (int) (filesize($path) ?: 0));
        }
    }
    $rows = database()->query('SELECT filename, size, updated_at FROM schoolsync_projects ORDER BY updated_at DESC')->fetchAll();
    $files = [];
    foreach ($rows as $row) {
        $path = SCHOOLSYNC_UPLOADS . '/' . $row['filename'];
        if (!is_file($path)) {
            delete_project_record((string) $row['filename']);
            continue;
        }
        $files[] = [
            'name' => (string) $row['filename'],
            'size' => (int) $row['size'],
            'modified' => strtotime((string) $row['updated_at']) ?: time(),
        ];
    }
    return $files;
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

function base_url(): string
{
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $directory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $directory = preg_replace('#/api$#', '', $directory) ?: '';
    return $scheme . '://' . $host . rtrim($directory, '/');
}

bootstrap_app();
