<?php
declare(strict_types=1);
// Add classroom_session.pacing: 'teacher' (NEXT/AUTO for the whole class, as before) or 'self' (each student moves
// on after answering or after auto_seconds). Existing rooms become 'teacher'; no other column or row changes.
//   sudo bash tools/setup_db.sh                                         live database (backs up, then runs this)
//   CLICKER_CONFIG=... CLICKER_ADMIN=app php tools/upgrade_pacing.php   test database
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
$has = $db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='classroom_session' AND column_name='pacing'")->fetchColumn();
$rooms = $db->query('SELECT COUNT(*) FROM classroom_session')->fetchColumn();
if (!$has) $db->exec("ALTER TABLE classroom_session ADD COLUMN pacing ENUM('teacher','self') NOT NULL DEFAULT 'teacher' AFTER auto_seconds");
$after = $db->query('SELECT COUNT(*) FROM classroom_session')->fetchColumn();
echo 'classroom_session.pacing: ' . ($has ? 'đã có' : 'đã thêm') . " ({$match[1]}, $after phòng, trước $rooms)\n";
