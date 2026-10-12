<?php
declare(strict_types=1);
// Add Google/Facebook sign-in columns to teacher_account. Back up first: php tools/backup.php
//   sudo php tools/upgrade_oauth.php                                   live database, root over the local socket
//   CLICKER_CONFIG=... CLICKER_ADMIN=app php tools/upgrade_oauth.php   a database whose app account may ALTER (test)
// Safe to run again. Existing accounts keep their passwords; every teacher row is fingerprinted before and after.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$config = require (getenv('CLICKER_CONFIG') ?: "$root/.local/prod-config.php");
if (!preg_match('/(?:^|;)dbname=([A-Za-z0-9_]+)/', $config['dsn'], $match)) exit("Database name not found\n");
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
if (getenv('CLICKER_ADMIN') === 'app') {
    $db = new PDO($config['dsn'], $config['db_user'], $config['db_password'], $options);
} elseif (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    // The app account cannot ALTER; root authenticates by socket, so no password is read or stored here.
    $db = new PDO("mysql:unix_socket=/run/mysqld/mysqld.sock;dbname={$match[1]};charset=utf8mb4", 'root', '', $options);
} else exit("Run with sudo (live) or CLICKER_ADMIN=app (test)\n");
$hasColumn = function (string $column) use ($db): bool {
    $check = $db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='teacher_account' AND column_name=?");
    $check->execute([$column]);
    return (bool)$check->fetchColumn();
};
$fingerprint = fn() => hash('sha256', json_encode($db->query('SELECT id,username,password_hash,created_at FROM teacher_account ORDER BY id')->fetchAll(PDO::FETCH_NUM)));
$before = $fingerprint();
$added = [];
if (!$hasColumn('email')) { $db->exec('ALTER TABLE teacher_account ADD COLUMN email VARCHAR(190) NULL AFTER password_hash'); $added[] = 'email'; }
if (!$hasColumn('google_id')) { $db->exec('ALTER TABLE teacher_account ADD COLUMN google_id VARCHAR(64) NULL UNIQUE AFTER email'); $added[] = 'google_id'; }
if (!$hasColumn('facebook_id')) { $db->exec('ALTER TABLE teacher_account ADD COLUMN facebook_id VARCHAR(64) NULL UNIQUE AFTER google_id'); $added[] = 'facebook_id'; }
if ($fingerprint() !== $before) { fwrite(STDERR, "Teacher accounts changed unexpectedly.\n"); exit(1); }
echo $added ? 'Added teacher_account.' . implode(', teacher_account.', $added) . "\n" : "Already upgraded.\n";
echo "UPGRADE OK ({$match[1]}): teacher accounts unchanged.\n";
