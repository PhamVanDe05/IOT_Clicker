<?php
// Exercises oauth_account() on the test database; removes everything it creates.
// php tests/oauth_account_test.php [config]   (default .local/test-config.php)
declare(strict_types=1);
require dirname(__DIR__) . '/web/lib.php';
$c = require ($argv[1] ?? dirname(__DIR__) . '/.local/test-config.php');
if (!str_contains($c['dsn'], 'smart_clicker_test')) exit("refusing: not the test database\n");
$db = new PDO($c['dsn'], $c['db_user'], $c['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$tag = 'zzoauth' . getmypid();
$fail = 0;
$check = function (string $what, bool $ok) use (&$fail) { echo ($ok ? '  PASS ' : '  FAIL ') . $what . "\n"; if (!$ok) $fail++; };
$cleanup = fn() => query($db, "DELETE FROM teacher_account WHERE username LIKE ? OR email LIKE ?", ["$tag%", "$tag%"]);
try {
    $a = oauth_account($db, 'google', ['id' => "g$tag", 'email' => "$tag@gmail.com", 'name' => "$tag An"]);
    $check('Google lan dau: tao tai khoan moi, ten = ten Google', $a['username'] === "$tag An");
    $b = oauth_account($db, 'google', ['id' => "g$tag", 'email' => "$tag@gmail.com", 'name' => 'Ten Khac']);
    $check('Google lan hai: dung tai khoan cu, khong tao them', $b['id'] == $a['id']);
    $f = oauth_account($db, 'facebook', ['id' => "f$tag", 'email' => "$tag@gmail.com", 'name' => 'FB']);
    $check('Facebook cung email da xac minh: gan vao tai khoan cu', $f['id'] == $a['id']);
    $row = query($db, 'SELECT google_id,facebook_id,password_hash FROM teacher_account WHERE id=?', [$a['id']])->fetch();
    $check('Tai khoan co ca google_id va facebook_id', $row['google_id'] === "g$tag" && $row['facebook_id'] === "f$tag");
    $check('Mat khau ngau nhien: khong dang nhap duoc bang 8 so', !password_verify('12345678', $row['password_hash']));
    query($db, 'INSERT INTO teacher_account(username,password_hash) VALUES(?,?)', ["$tag Binh", password_hash('12345678', PASSWORD_DEFAULT)]);
    $d = oauth_account($db, 'google', ['id' => "g2$tag", 'email' => null, 'name' => "$tag Binh"]);
    $check('Trung ten voi tai khoan mat khau: tao ten moi "... 2"', $d['username'] === "$tag Binh 2");
    $e = oauth_account($db, 'facebook', ['id' => "f2$tag", 'email' => null, 'name' => "$tag Chi"]);
    $check('Facebook khong co email: van tao duoc tai khoan', $e['username'] === "$tag Chi");
    try { oauth_account($db, 'google', ['id' => "bad id!", 'name' => 'x']); $check('ID khong hop le bi tu choi', false); }
    catch (ApiError $err) { $check('ID khong hop le bi tu choi', $err->errorCode === 'OAUTH_PROFILE'); }
    try { oauth_account($db, 'twitter', ['id' => 'x', 'name' => 'x']); $check('Nha cung cap la bi tu choi', false); }
    catch (ApiError $err) { $check('Nha cung cap la bi tu choi', $err->errorCode === 'OAUTH_PROVIDER'); }
} finally { $cleanup(); }
$left = (int)query($db, "SELECT COUNT(*) c FROM teacher_account WHERE username LIKE ? OR email LIKE ?", ["$tag%", "$tag%"])->fetch()['c'];
$check('Da don sach du lieu thu', $left === 0);
echo $fail ? "FAIL: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
