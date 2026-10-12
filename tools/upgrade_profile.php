<?php
declare(strict_types=1);
// Teacher profiles (name, gender, school, subject, grades, avatar) and classes without a student file. Only adds tables.
//   sudo bash tools/setup_db.sh                                           live database (backs up first)
//   CLICKER_CONFIG=... CLICKER_ADMIN=app php tools/upgrade_profile.php    test database
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
$db->exec(file_get_contents("$root/tools/profile.sql"));
echo "teacher_profile, teacher_class: OK ({$match[1]})\n";
