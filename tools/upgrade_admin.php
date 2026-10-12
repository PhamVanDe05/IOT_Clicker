<?php
declare(strict_types=1);
// Admin page: add teacher_account.is_admin and disabled_at, make one account admin, optionally remove an old account.
//   php tools/upgrade_admin.php                         add the columns only (safe to run again)
//   php tools/upgrade_admin.php --admin=EMAIL_OR_NAME   also make that account the admin
//   php tools/upgrade_admin.php --remove="Pham Van De"  also delete that account and everything it owns
// Live database: run through sudo bash tools/setup_db.sh (backs up first). Test: CLICKER_CONFIG=... CLICKER_ADMIN=app
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require "$root/web/lib.php";
$config = require (getenv('CLICKER_CONFIG') ?: "$root/.local/prod-config.php");
if (!preg_match('/(?:^|;)dbname=([A-Za-z0-9_]+)/', $config['dsn'], $match)) exit("Database name not found\n");
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
if (getenv('CLICKER_ADMIN') === 'app') {
    $db = new PDO($config['dsn'], $config['db_user'], $config['db_password'], $options);
} elseif (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    $db = new PDO("mysql:unix_socket=/run/mysqld/mysqld.sock;dbname={$match[1]};charset=utf8mb4", 'root', '', $options);
} else exit("Run with sudo (live) or CLICKER_ADMIN=app (test)\n");
$opt = getopt('', ['admin:', 'remove:']);
$has = fn(string $c) => (bool)$db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='teacher_account' AND column_name='$c'")->fetchColumn();
if (!$has('is_admin')) $db->exec('ALTER TABLE teacher_account ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0');
if (!$has('disabled_at')) $db->exec('ALTER TABLE teacher_account ADD COLUMN disabled_at DATETIME NULL');
if (!$has('last_seen')) $db->exec('ALTER TABLE teacher_account ADD COLUMN last_seen DATETIME NULL');   // online dot on the admin page
echo "teacher_account.is_admin / disabled_at / last_seen: OK ({$match[1]})\n";

if (isset($opt['remove'])) {
    $row = query($db, 'SELECT id,username FROM teacher_account WHERE username=?', [$opt['remove']])->fetch();
    if (!$row) echo "Không có tài khoản \"{$opt['remove']}\" (đã xoá trước đó?)\n";
    else {
        $db->beginTransaction();
        $done = delete_teacher($db, (int)$row['id']);
        $db->commit();
        echo "Đã xoá tài khoản \"{$row['username']}\": {$done['rooms']} phòng, {$done['exams']} bộ đề, {$done['students']} sinh viên\n";
    }
}
if (isset($opt['admin'])) {
    $key = trim($opt['admin']);
    $rows = query($db, 'SELECT id,username,email FROM teacher_account WHERE LOWER(email)=LOWER(?) OR username=?', [$key, $key])->fetchAll();
    if (count($rows) !== 1) {
        echo count($rows) ? "Có nhiều tài khoản khớp \"$key\", hãy gõ tên đăng nhập.\n" : "Không tìm thấy tài khoản \"$key\". Hãy đăng nhập web bằng tài khoản đó một lần rồi chạy lại.\n";
        exit(2);
    }
    query($db, 'UPDATE teacher_account SET is_admin=1, disabled_at=NULL WHERE id=?', [$rows[0]['id']]);
    echo "Quản trị viên: {$rows[0]['username']}\n";
}
echo 'Hiện có ' . $db->query('SELECT COUNT(*) FROM teacher_account')->fetchColumn() . ' tài khoản giáo viên, ' . $db->query('SELECT COUNT(*) FROM teacher_account WHERE is_admin=1')->fetchColumn() . " quản trị viên.\n";
