<?php
declare(strict_types=1);
// Admin tools: app_setting, audit_log, password_request tables and device.label. Only adds; safe to run again.
//   sudo bash tools/setup_db.sh                                       live database (backs up first)
//   CLICKER_CONFIG=... CLICKER_ADMIN=app php tools/upgrade_ops.php    test database
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
$db->exec(file_get_contents("$root/tools/ops.sql"));
if (!$db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='device' AND column_name='label'")->fetchColumn())
    $db->exec('ALTER TABLE device ADD COLUMN label VARCHAR(60) NULL');
echo "app_setting, audit_log, password_request, device.label: OK ({$match[1]})\n";
