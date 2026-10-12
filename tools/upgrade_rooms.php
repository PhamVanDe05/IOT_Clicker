<?php
declare(strict_types=1);
// Several rooms at once: add classroom_session.mode ('wifi' = phones and laptops, 'ble' = Student Tags through the USB gateway)
// so every room keeps its own mode, and an index on state for "which rooms are running". Existing rooms take the mode the
// server used until now (app_state.mode); no other column or row changes.
//   sudo bash tools/setup_db.sh                                         live database (backs up, then runs this)
//   CLICKER_CONFIG=... CLICKER_ADMIN=app php tools/upgrade_rooms.php   test database
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
$has = $db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='classroom_session' AND column_name='mode'")->fetchColumn();
$rooms = $db->query('SELECT COUNT(*) FROM classroom_session')->fetchColumn();
if (!$has) {
    $db->exec("ALTER TABLE classroom_session ADD COLUMN mode ENUM('wifi','ble') NOT NULL DEFAULT 'wifi' AFTER pacing");
    $db->exec("UPDATE classroom_session SET mode=(SELECT mode FROM app_state WHERE id=1)");
    // Until now only the room in app_state was running; rooms left open before it were dead already: end them, so the
    // new "every room not FINISHED is running" rule starts with the true list.
    $ended = $db->exec("UPDATE classroom_session SET state='FINISHED',auto_mode=0,auto_deadline=NULL WHERE state<>'FINISHED' AND id <> COALESCE((SELECT session_id FROM app_state WHERE id=1), 0)");
    echo "Phòng cũ bỏ dở đã kết thúc: $ended\n";
}
$index = $db->query("SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='classroom_session' AND index_name='session_state'")->fetchColumn();
if (!$index) $db->exec('ALTER TABLE classroom_session ADD INDEX session_state (state)');
$after = $db->query('SELECT COUNT(*) FROM classroom_session')->fetchColumn();
echo 'classroom_session.mode: ' . ($has ? 'đã có' : 'đã thêm') . ', chỉ mục state: ' . ($index ? 'đã có' : 'đã thêm') . " ({$match[1]}, $after phòng, trước $rooms)\n";
