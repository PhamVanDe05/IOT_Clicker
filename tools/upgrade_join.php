<?php
declare(strict_types=1);
// Add the join_request table (students who register themselves on the phone page and wait for the teacher's ✓).
// Only creates a new table; existing tables and rows are not touched. Back up first: php tools/backup.php
//   sudo bash tools/setup_db.sh                                       live database (backs up, then runs this)
//   CLICKER_CONFIG=... CLICKER_ADMIN=app php tools/upgrade_join.php   a database whose app account may CREATE (test)
// Safe to run again.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$config = require (getenv('CLICKER_CONFIG') ?: "$root/.local/prod-config.php");
if (!preg_match('/(?:^|;)dbname=([A-Za-z0-9_]+)/', $config['dsn'], $match)) exit("Database name not found\n");
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
if (getenv('CLICKER_ADMIN') === 'app') {
    $db = new PDO($config['dsn'], $config['db_user'], $config['db_password'], $options);
} elseif (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    $db = new PDO("mysql:unix_socket=/run/mysqld/mysqld.sock;dbname={$match[1]};charset=utf8mb4", 'root', '', $options);
} else exit("Run with sudo (live) or CLICKER_ADMIN=app (test)\n");
$db->exec(file_get_contents("$root/tools/join_request.sql"));
$cols = $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='join_request'")->fetchColumn();
echo "join_request: OK ($cols cột) trên {$match[1]}\n";
