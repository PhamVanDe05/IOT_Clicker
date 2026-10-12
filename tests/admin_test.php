<?php
// Admin page: only admins see it; lock signs a teacher out and blocks login; delete removes everything they own.
//   php tests/admin_test.php
// Needs .local/test-config.php (smart_clicker_test) with teacher_account.is_admin (tools/upgrade_admin.php).
declare(strict_types=1);

$root = dirname(__DIR__);
$base = require "$root/.local/test-config.php";
if (!str_contains($base['dsn'], 'smart_clicker_test')) exit("Refusing: test config must point at smart_clicker_test\n");

$tmp = sys_get_temp_dir() . '/clicker-admin-' . bin2hex(random_bytes(4));
mkdir($tmp);
symlink("$root/web", "$tmp/clicker");
file_put_contents("$tmp/config.php", '<?php return ' . var_export($base, true) . ';');
$port = random_int(20000, 40000);
$server = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $tmp], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['CLICKER_CONFIG' => "$tmp/config.php", 'PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($server, $tmp) {
    proc_terminate($server);
    unlink("$tmp/clicker"); unlink("$tmp/config.php"); rmdir($tmp);
});
usleep(400000);

// $jars: one cookie set per browser (teacher "main", teacher "other", phones "p1", "p2"…)
$jars = []; $tokens = [];
function http(string $who, string $method, string $path, ?array $body = null, array $headers = []): array {
    global $port, $jars, $tokens;
    $cookie = implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($jars[$who] ?? []), $jars[$who] ?? []));
    $headers = array_merge(['Content-Type: application/json'], $cookie ? ["Cookie: $cookie"] : [], isset($tokens[$who]) ? ['X-CSRF-Token: ' . $tokens[$who]] : [], $headers);
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body === null ? '' : json_encode($body), 'ignore_errors' => true, 'timeout' => 10]]);
    $raw = file_get_contents("http://127.0.0.1:$port/clicker/$path", false, $context);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ([a-z_]+)=([^;]+)/i', $line, $m)) $jars[$who][$m[1]] = $m[2];
    preg_match('/ (\d{3})/', $http_response_header[0], $m);
    return ['status' => (int)$m[1]] + (json_decode((string)$raw, true) ?? ['raw' => $raw]);
}
function teacher(string $who, string $action, array $data = []): array { return http($who, 'POST', 'api.php', ['action' => $action] + $data); }
function phone(string $who, string $action, array $data = []): array {
    global $tokens, $base;
    if (!isset($tokens[$who])) $tokens[$who] = http($who, 'GET', 'phone-api.php')['csrf'];
    // the phone typed the code of the room it talks about (several rooms can run at once)
    $room = (int)($data['session_id'] ?? ($GLOBALS['sid'] ?? 0));
    return http($who, 'POST', 'phone-api.php', ['action' => $action] + $data + ($room ? ['room' => room_code($base, $room)] : []));
}
function device_key(string $uid): string { global $base; return hash_hmac('sha256', $uid, $base['device_key']); }

$failures = 0;
function check(string $label, bool $ok, $detail = ''): void {
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : ' → ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) $failures++;
}

require "$root/web/lib.php";
$db = new PDO($base['dsn'], $base['db_user'], $base['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (!$db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='teacher_account' AND column_name='is_admin'")->fetchColumn()) exit("Run: CLICKER_CONFIG=.local/test-config.php CLICKER_ADMIN=app php tools/upgrade_admin.php\n");
$db->exec("DELETE FROM request_limit WHERE bucket='teacher_login'");
$teachers = [];
foreach (['admin', 'bob', 'eve'] as $who) {
    $teachers[$who] = ['username' => 'ADM_' . $who . '_' . bin2hex(random_bytes(3)), 'password' => (string)random_int(10000000, 99999999)];
    $db->prepare('INSERT INTO teacher_account(username,password_hash,is_admin) VALUES(?,?,?)')->execute([$teachers[$who]['username'], password_hash($teachers[$who]['password'], PASSWORD_DEFAULT), $who === 'admin' ? 1 : 0]);
    $teachers[$who]['id'] = (int)$db->lastInsertId();
}
$login = function (string $who) use (&$teachers, &$tokens, &$jars) { $jars[$who] = []; unset($tokens[$who]);
    $r = teacher($who, 'teacher_login', ['username' => $teachers[$who]['username'], 'password' => $teachers[$who]['password']]); $tokens[$who] = $r['csrf'] ?? ''; return $r; };
$login('admin'); $login('bob');
check('auth báo is_admin cho quản trị viên', http('admin', 'GET', 'api.php?action=auth')['is_admin'] === true && http('bob', 'GET', 'api.php?action=auth')['is_admin'] === false);
check('giáo viên thường không xem được trang quản trị', http('bob', 'GET', 'api.php?action=admin_overview')['code'] === 'NOT_ADMIN');
check('giáo viên thường không khoá được ai', teacher('bob', 'admin_set_locked', ['teacher_id' => $teachers['eve']['id'], 'locked' => true])['code'] === 'NOT_ADMIN');
// bob owns a class, an exam and a room
$class = 'ADM' . random_int(1000, 9999);
teacher('bob', 'import_students', ['students' => [['student_no' => '1', 'student_id' => '93' . random_int(1000000, 9999999), 'student_name' => 'A', 'class_id' => $class]]]);
$examId = teacher('bob', 'import_exam', ['title' => "Adm $class", 'text' => "[QUESTIONS]\n\nQ1\nC?\nA. a\nB. b\nC. c\nD. d\n\n[ANSWER_KEY]\nQ1=A\n"])['exam_id'] ?? 0;
$sid = (int)(teacher('bob', 'create_session', ['title' => "Adm", 'class_id' => $class, 'exam_id' => $examId, 'auto_seconds' => 30])['session_id'] ?? 0);
teacher('bob', 'open_login', ['session_id' => $sid]);
$o = http('admin', 'GET', 'api.php?action=admin_overview');
$bob = array_values(array_filter($o['teachers'] ?? [], fn($t) => (int)$t['id'] === $teachers['bob']['id']))[0] ?? [];
check('quản trị viên thấy mọi giáo viên + số lớp/đề/buổi', ($bob['exams'] ?? 0) == 1 && ($bob['rooms'] ?? 0) == 1 && ($bob['classes'] ?? 0) == 1 && isset($o['totals']['answers']), $bob);
check('thấy phòng đang chạy trong danh sách', in_array($sid, array_map('intval', array_column($o['active_rooms'] ?? [], 'id')), true), $o['active_rooms'] ?? null);
check('không tự khoá / tự xoá chính mình', teacher('admin', 'admin_set_locked', ['teacher_id' => $teachers['admin']['id'], 'locked' => true])['code'] === 'ADMIN_SELF');
$lock = teacher('admin', 'admin_set_locked', ['teacher_id' => $teachers['bob']['id'], 'locked' => true]);
check('khoá bob: phòng của bob kết thúc để trả thiết bị', ($lock['ok'] ?? false) && !in_array($sid, array_map('intval', array_column(http('admin', 'GET', 'api.php?action=admin_overview')['active_rooms'], 'id')), true), $lock);
check('bob đang đăng nhập bị đăng xuất ngay', http('bob', 'GET', 'api.php?action=bootstrap')['code'] === 'ACCOUNT_DISABLED');
check('bob đăng nhập lại bị từ chối', $login('bob')['code'] === 'ACCOUNT_DISABLED');
teacher('admin', 'admin_set_locked', ['teacher_id' => $teachers['bob']['id'], 'locked' => false]);
check('mở khoá: bob đăng nhập lại được', ($login('bob')['ok'] ?? false) === true);
teacher('admin', 'admin_set_admin', ['teacher_id' => $teachers['eve']['id'], 'admin' => true]);
$login('eve');
check('cho eve làm quản trị → eve thấy trang quản trị', (http('eve', 'GET', 'api.php?action=admin_overview')['ok'] ?? false) === true);
check('xoá cần gõ đúng tên', teacher('admin', 'admin_delete_teacher', ['teacher_id' => $teachers['bob']['id'], 'confirmed' => 'sai'])['code'] === 'CONFIRM_REQUIRED');
$del = teacher('admin', 'admin_delete_teacher', ['teacher_id' => $teachers['bob']['id'], 'confirmed' => $teachers['bob']['username']]);
$left = (int)$db->query("SELECT (SELECT COUNT(*) FROM teacher_account WHERE id={$teachers['bob']['id']}) + (SELECT COUNT(*) FROM exam WHERE teacher_id={$teachers['bob']['id']}) + (SELECT COUNT(*) FROM classroom_session WHERE teacher_id={$teachers['bob']['id']}) + (SELECT COUNT(*) FROM student WHERE teacher_id={$teachers['bob']['id']})")->fetchColumn();
check('xoá bob: mất tài khoản, lớp, đề, buổi học', ($del['removed']['rooms'] ?? 0) === 1 && $left === 0, [$del, $left]);
check('bob đang đăng nhập bị đá ra', http('bob', 'GET', 'api.php?action=bootstrap')['code'] === 'UNAUTHORIZED');

// --- admin tools (tools/upgrade_ops.php)
if ($db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='audit_log'")->fetchColumn()) {
    $db->exec("DELETE FROM request_limit WHERE bucket IN ('password_request','teacher_register','teacher_login')");
    $new = teacher('admin', 'admin_create_teacher', ['username' => 'ADM_new_' . bin2hex(random_bytes(3)), 'email' => '']);
    check('tạo tài khoản: tự sinh mật khẩu 8 số', ($new['ok'] ?? false) && preg_match('/^[0-9]{8}$/', $new['password'] ?? ''), $new);
    $teachers['new'] = ['username' => $new['username'], 'password' => $new['password'], 'id' => $new['teacher_id']];
    check('tài khoản vừa tạo đăng nhập được', ($login('new')['ok'] ?? false) === true);
    check('tên trùng bị từ chối', teacher('admin', 'admin_create_teacher', ['username' => $new['username']])['code'] === 'USERNAME_USED');
    $mail = 'adm' . bin2hex(random_bytes(3)) . '@example.com';
    $inv = teacher('admin', 'admin_create_teacher', ['username' => 'ADM_inv_' . bin2hex(random_bytes(3)), 'email' => $mail]);
    $teachers['inv'] = ['id' => $inv['teacher_id'] ?? 0];
    $acc = oauth_account($db, 'google', ['id' => 'gadm' . bin2hex(random_bytes(4)), 'email' => $mail, 'name' => 'Ai Do']);
    check('mời bằng Gmail: đăng nhập Google vào đúng tài khoản đã tạo', (int)$acc['id'] === (int)($inv['teacher_id'] ?? -1), [$acc, $inv]);
    // forgotten password: request → admin approves from the bell → the same browser sets a new password and is signed in
    $jars['anon'] = []; $jars['thief'] = [];
    check('quên mật khẩu: tên không có vẫn vào màn chờ (không lộ tài khoản)', (http('anon', 'POST', 'api.php', ['action' => 'password_request', 'username' => 'khong_ton_tai_xyz'])['ok'] ?? false) === true
        && http('anon', 'GET', 'api.php?action=password_status')['state'] === 'waiting');
    $jars['anon'] = [];
    http('anon', 'POST', 'api.php', ['action' => 'password_request', 'username' => $teachers['new']['username']]);
    check('người quên thấy đang chờ cấp phép', http('anon', 'GET', 'api.php?action=password_status')['state'] === 'waiting');
    $notes = http('admin', 'GET', 'api.php?action=admin_notifications')['requests'] ?? [];
    $req = array_values(array_filter($notes, fn($r) => (int)$r['teacher_id'] === (int)$teachers['new']['id']))[0] ?? null;
    check('chuông của quản trị viên có yêu cầu', $req !== null, $notes);
    check('chưa cấp phép thì không đặt mật khẩu được', http('anon', 'POST', 'api.php', ['action' => 'password_set', 'password' => '11112222', 'password2' => '11112222'])['code'] === 'NOT_APPROVED');
    check('giáo viên thường không cấp phép được', teacher('new', 'admin_approve_request', ['request_id' => (int)$req['id']])['code'] === 'NOT_ADMIN');
    teacher('admin', 'admin_approve_request', ['request_id' => (int)$req['id']]);
    check('cấp phép → màn chờ chuyển sang đặt mật khẩu', http('anon', 'GET', 'api.php?action=password_status')['state'] === 'approved');
    check('trình duyệt khác không đặt được mật khẩu thay', http('thief', 'POST', 'api.php', ['action' => 'password_set', 'password' => '99998888', 'password2' => '99998888'])['code'] === 'NOT_APPROVED');
    check('nhập lại không khớp bị từ chối', http('anon', 'POST', 'api.php', ['action' => 'password_set', 'password' => '11112222', 'password2' => '11112223'])['code'] === 'PASSWORD_MISMATCH');
    $set = http('anon', 'POST', 'api.php', ['action' => 'password_set', 'password' => '11112222', 'password2' => '11112222']);
    check('đặt mật khẩu mới → đăng nhập luôn', ($set['ok'] ?? false) && $set['username'] === $teachers['new']['username'] && http('anon', 'GET', 'api.php?action=auth')['authenticated'] === true, $set);
    $teachers['new']['password'] = '11112222';
    check('mật khẩu mới dùng được, chuông hết yêu cầu', ($login('new')['ok'] ?? false) === true
        && !array_filter(http('admin', 'GET', 'api.php?action=admin_notifications')['requests'], fn($r) => (int)$r['teacher_id'] === (int)$teachers['new']['id']));
    $jars['anon'] = [];
    $db->exec("DELETE FROM request_limit WHERE bucket='password_request'");
    http('anon', 'POST', 'api.php', ['action' => 'password_request', 'username' => $teachers['new']['username']]);
    $req = array_values(array_filter(http('admin', 'GET', 'api.php?action=admin_notifications')['requests'], fn($r) => (int)$r['teacher_id'] === (int)$teachers['new']['id']))[0];
    teacher('admin', 'admin_reject_request', ['request_id' => (int)$req['id']]);
    check('từ chối → màn chờ báo không được chấp nhận', http('anon', 'GET', 'api.php?action=password_status')['state'] === 'rejected');
    $reset = teacher('admin', 'admin_reset_password', ['teacher_id' => $teachers['new']['id']]);
    $teachers['new']['password'] = $reset['password'] ?? '';
    check('đặt lại mật khẩu từ menu vẫn dùng được', ($login('new')['ok'] ?? false) === true);
    // read-only view
    teacher('new', 'import_students', ['students' => [['student_no' => '1', 'student_id' => '92' . random_int(1000000, 9999999), 'student_name' => 'V', 'class_id' => 'VIEW1']]]);
    teacher('admin', 'admin_view_as', ['teacher_id' => $teachers['new']['id']]);
    $boot = http('admin', 'GET', 'api.php?action=bootstrap');
    check('xem như: thấy lớp của giáo viên đó', in_array('VIEW1', array_column($boot['classes'] ?? [], 'class_id'), true), $boot['classes'] ?? $boot);
    check('xem như: không sửa được gì', teacher('admin', 'import_students', ['students' => [['student_no' => '1', 'student_id' => '1', 'student_name' => 'X', 'class_id' => 'X']]])['code'] === 'VIEW_ONLY');
    check('auth báo đang xem', http('admin', 'GET', 'api.php?action=auth')['view_as'] === $teachers['new']['username']);
    teacher('admin', 'admin_view_stop');
    check('thoát xem: trở lại dữ liệu của mình', !in_array('VIEW1', array_column(http('admin', 'GET', 'api.php?action=bootstrap')['classes'] ?? [], 'class_id'), true));
    // switches
    teacher('admin', 'admin_setting', ['name' => 'allow_register', 'on' => false]);
    check('tắt đăng ký: đăng ký mới bị chặn', http('anon', 'POST', 'api.php', ['action' => 'teacher_register', 'username' => 'ADM_x' . bin2hex(random_bytes(3)), 'password' => '12345678'])['code'] === 'REGISTER_OFF');
    teacher('admin', 'admin_setting', ['name' => 'allow_register', 'on' => true]);
    check('cài đặt sai tên bị từ chối', teacher('admin', 'admin_setting', ['name' => 'xyz', 'on' => true])['code'] === 'INVALID_SETTING');
    // devices
    $tokens['ph'] = http('ph', 'GET', 'phone-api.php')['csrf'];
    $uid = $db->query("SELECT device_uid FROM device WHERE device_uid LIKE 'PHONE-%' ORDER BY registered_at IS NULL, last_seen DESC LIMIT 1")->fetchColumn();
    http('ph', 'POST', 'phone-api.php', ['action' => 'poll']);
    $uid = $db->query("SELECT device_uid FROM device WHERE device_uid LIKE 'PHONE-%' ORDER BY last_seen DESC LIMIT 1")->fetchColumn();
    teacher('admin', 'admin_device', ['device_uid' => $uid, 'label' => 'Máy thử 1', 'blocked' => true]);
    $devs = http('admin', 'GET', 'api.php?action=admin_devices')['devices'] ?? [];
    $mine = array_values(array_filter($devs, fn($d) => $d['device_uid'] === $uid))[0] ?? [];
    check('đặt tên + chặn thiết bị', ($mine['label'] ?? '') === 'Máy thử 1' && $mine['revoked_at'] !== null, $mine);
    check('máy bị chặn không dùng được', http('ph', 'POST', 'phone-api.php', ['action' => 'poll'])['code'] === 'DEVICE_BLOCKED');
    teacher('admin', 'admin_device', ['device_uid' => $uid, 'blocked' => false]);
    check('bỏ chặn: dùng lại được', (http('ph', 'POST', 'phone-api.php', ['action' => 'poll'])['ok'] ?? false) === true);
    $db->prepare('DELETE FROM device WHERE device_uid=?')->execute([$uid]);
    // backup, report, log
    $bk = http('admin', 'GET', 'api.php?action=admin_backup');
    $sql = @gzdecode($bk['raw'] ?? '');
    check('sao lưu: tải được file .sql.gz có bảng teacher_account', $bk['status'] === 200 && is_string($sql) && str_contains($sql, 'CREATE TABLE `teacher_account`'), substr((string)($bk['raw'] ?? ''), 0, 80));
    check('giáo viên thường không tải được sao lưu', http('new', 'GET', 'api.php?action=admin_backup')['code'] === 'NOT_ADMIN');
    $rep = http('admin', 'GET', 'api.php?action=admin_report');
    check('báo cáo trả về 4 phần', isset($rep['by_teacher'], $rep['by_class'], $rep['by_exam'], $rep['hardest']));
    $log = http('admin', 'GET', 'api.php?action=admin_audit&q=' . rawurlencode($teachers['new']['username']));
    $acts = array_column($log['rows'] ?? [], 'action');
    check('nhật ký ghi: tạo, quên mật khẩu, cấp phép, đặt mật khẩu, xem như, đăng nhập', !array_diff(['admin_create_teacher', 'password_request', 'password_set', 'admin_reset_password', 'admin_view_as', 'login'], $acts), $acts);
    $ids = array_slice(array_column($log['rows'] ?? [], 'id'), 0, 2);
    $del = teacher('admin', 'admin_audit_delete', ['ids' => $ids]);
    $left = http('admin', 'GET', 'api.php?action=admin_audit&q=' . rawurlencode($teachers['new']['username']))['rows'];
    check('xoá vài dòng nhật ký đã chọn', ($del['deleted'] ?? 0) === 2 && !array_intersect($ids, array_column($left, 'id')), $del);
    check('giáo viên thường không xoá được nhật ký', teacher('new', 'admin_audit_delete', ['ids' => 'all'])['code'] === 'NOT_ADMIN');
    // automatic clean-up
    $db->exec("INSERT INTO audit_log(at,actor_name,action,detail) VALUES(NOW() - INTERVAL 100 DAY,'old','login','cũ'),(NOW() - INTERVAL 10 DAY,'new','login','mới')");
    $db->prepare('INSERT INTO request_limit(bucket,actor_hash,window_id,hits) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE window_id=VALUES(window_id)')->execute(['room_code', hash('sha256', 'cleanup-test'), intdiv(time() - 3 * 86400, 60)]);
    $clean = teacher('admin', 'admin_audit_days', ['days' => 90]);
    $old = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE actor_name='old'")->fetchColumn(); $new = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE actor_name='new'")->fetchColumn();
    check('tự dọn: xoá nhật ký cũ hơn 90 ngày, giữ cái mới', ($clean['ok'] ?? false) && $old === 0 && $new === 1, [$clean, $old, $new]);
    check('tự dọn: xoá bộ đếm chống spam cũ hơn 1 ngày', !$db->query("SELECT 1 FROM request_limit WHERE actor_hash='" . hash('sha256', 'cleanup-test') . "'")->fetchColumn());
    check('số ngày ngoài 30/90/180/365 bị từ chối', teacher('admin', 'admin_audit_days', ['days' => 7])['code'] === 'INVALID_SETTING');
    $db->exec("DELETE FROM audit_log WHERE actor_name='new'");
    // online dot, bulk delete
    $ov = http('admin', 'GET', 'api.php?action=admin_overview');
    $meRow = array_values(array_filter($ov['teachers'], fn($x) => (int)$x['id'] === $teachers['admin']['id']))[0] ?? [];
    check('quản trị viên đang dùng web hiện online', (int)($meRow['online'] ?? 0) === 1, $meRow);
    $db->exec("UPDATE teacher_account SET last_seen=NOW() - INTERVAL 10 MINUTE WHERE id=" . (int)$teachers['new']['id']);
    $newRow = array_values(array_filter(http('admin', 'GET', 'api.php?action=admin_overview')['teachers'], fn($x) => (int)$x['id'] === (int)$teachers['new']['id']))[0] ?? [];
    check('không hoạt động 10 phút → offline', (int)($newRow['online'] ?? 1) === 0);
    $db->exec("INSERT INTO device(device_uid,last_seen) VALUES('PHONE-OLDDEL',NOW() - INTERVAL 40 DAY),('PHONE-NEWDEL',NOW())");
    $dd = teacher('admin', 'admin_delete_devices', ['uids' => 'inactive']);
    check('xoá máy không dùng hơn 30 ngày', ($dd['ok'] ?? false) && !$db->query("SELECT 1 FROM device WHERE device_uid='PHONE-OLDDEL'")->fetchColumn() && $db->query("SELECT 1 FROM device WHERE device_uid='PHONE-NEWDEL'")->fetchColumn(), $dd);
    teacher('admin', 'admin_delete_devices', ['uids' => ['PHONE-NEWDEL']]);
    check('xoá thiết bị đã chọn', !$db->query("SELECT 1 FROM device WHERE device_uid='PHONE-NEWDEL'")->fetchColumn());
    $sys = http('admin', 'GET', 'api.php?action=admin_extra')['server'] ?? [];
    check('tình trạng máy chủ: dung lượng ổ đĩa, RAM, database', ($sys['disk_total'] ?? 0) > 0 && ($sys['mem_total'] ?? 0) > 0 && ($sys['db_size'] ?? 0) > 0, $sys);
    foreach (['new', 'inv'] as $who) { $db->beginTransaction(); delete_teacher($db, (int)$teachers[$who]['id']); $db->commit(); unset($teachers[$who]); }
}

$extra = [];
foreach (['x1', 'x2'] as $w) { $db->prepare('INSERT INTO teacher_account(username,password_hash) VALUES(?,?)')->execute(['ADM_' . $w . bin2hex(random_bytes(3)), 'x']); $extra[] = (int)$db->lastInsertId(); }
check('xoá nhiều: phải gõ đúng XOA n', teacher('admin', 'admin_delete_teachers', ['ids' => $extra, 'confirmed' => 'XOA 1'])['code'] === 'CONFIRM_REQUIRED');
$bulk = teacher('admin', 'admin_delete_teachers', ['ids' => array_merge($extra, [$teachers['admin']['id']]), 'confirmed' => 'XOA 2']);
check('xoá nhiều tài khoản cùng lúc (bỏ qua chính mình)', ($bulk['deleted'] ?? 0) === 2 && !$db->query('SELECT COUNT(*) FROM teacher_account WHERE id IN (' . implode(',', $extra) . ')')->fetchColumn()
    && $db->query('SELECT 1 FROM teacher_account WHERE id=' . $teachers['admin']['id'])->fetchColumn(), $bulk);
foreach ($teachers as $t) $db->prepare('DELETE FROM teacher_account WHERE id=?')->execute([$t['id']]);
echo $failures ? "\n$failures FAIL\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
