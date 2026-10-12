<?php
declare(strict_types=1);
// php tools/backup.php | php tools/backup.php --verify .local/backups/<file>.sql
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$config = require (getenv('CLICKER_CONFIG') ?: "$root/.local/prod-config.php");
preg_match('/(?:^|;)host=([^;]+)/', $config['dsn'], $hostMatch);
preg_match('/(?:^|;)dbname=([^;]+)/', $config['dsn'], $nameMatch);
$host = $hostMatch[1] ?? 'localhost';
$name = $nameMatch[1] ?? '';
if (!preg_match('/^[A-Za-z0-9_]+$/D', $name)) throw new RuntimeException('Invalid database name');
$dir = "$root/.local/backups";
if (!is_dir($dir)) mkdir($dir, 0700);
chmod($dir, 0700);

function run_db(array $command, string $password, array $descriptors): void {
    $process = proc_open($command, $descriptors, $pipes, null, ['MYSQL_PWD' => $password]);
    if (!is_resource($process)) throw new RuntimeException('Could not start MariaDB command');
    if (isset($pipes[1])) { stream_get_contents($pipes[1]); fclose($pipes[1]); }
    $error = isset($pipes[2]) ? stream_get_contents($pipes[2]) : '';
    if (isset($pipes[2])) fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('MariaDB command failed: ' . trim($error));
}

if (($argv[1] ?? '') === '--verify') {
    $path = realpath($argv[2] ?? '');
    if (!$path || !str_starts_with($path, realpath($dir) . '/') || !str_ends_with($path, '.sql')
        || !is_file($path) || !is_file($path . '.sha256')
        || !hash_equals(trim((string)file_get_contents($path . '.sha256')), hash_file('sha256', $path))) {
        throw new RuntimeException('Backup file or checksum is invalid');
    }
    // The app account cannot create databases: restore into a scratch database as root over the local socket.
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) exit("Run: sudo php tools/backup.php --verify <file>\n");
    $conn = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $scratch = 'clicker_verify_' . bin2hex(random_bytes(5));
    $conn->exec("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        run_db(['/usr/bin/mariadb', '--user=root', $scratch], '', [0 => ['file', $path, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]);
        $names = $conn->query("SELECT table_name FROM information_schema.tables WHERE table_schema='$scratch'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['student', 'classroom_session', 'student_response'] as $table) {
            if (!in_array($table, $names, true)) throw new RuntimeException("Restore is missing table $table");
        }
        echo 'RESTORE OK: ', count($names), " tables verified in temporary database; source unchanged.\n";
    } finally {
        $conn->exec("DROP DATABASE `$scratch`");
    }
    exit;
}
if (count($argv) !== 1) exit("Usage: php tools/backup.php [--verify .local/backups/<file>.sql]\n");
$path = $dir . '/' . $name . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
try {
    run_db(['/usr/bin/mariadb-dump', '--host=' . $host, '--user=' . $config['db_user'],
        '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--result-file=' . $path, $name],
        $config['db_password'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']]);
    if (!is_file($path) || filesize($path) === 0) throw new RuntimeException('Backup is empty');
    chmod($path, 0600);
    file_put_contents($path . '.sha256', hash_file('sha256', $path) . "\n");
    chmod($path . '.sha256', 0600);
    echo "BACKUP OK: $path\n";
} catch (Throwable $error) {
    if (is_file($path)) unlink($path);
    throw $error;
}
