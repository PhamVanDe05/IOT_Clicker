<?php
// Teacher profile (required after sign-up), avatar, password change, classes without a file (numbered by MSSV),
// whole-quiz countdown of self-paced rooms.
//   php tests/profile_test.php
// Needs .local/test-config.php (smart_clicker_test) upgraded with tools/upgrade_profile.php.
declare(strict_types=1);

$root = dirname(__DIR__);
$base = require "$root/.local/test-config.php";
if (!str_contains($base['dsn'], 'smart_clicker_test')) exit("Refusing: test config must point at smart_clicker_test\n");

$tmp = sys_get_temp_dir() . '/clicker-prof-' . bin2hex(random_bytes(4));
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
if (!$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='teacher_profile'")->fetchColumn()) exit("Run: CLICKER_CONFIG=.local/test-config.php CLICKER_ADMIN=app php tools/upgrade_profile.php\n");
$db->exec("DELETE FROM request_limit");
$name = 'PROF_' . bin2hex(random_bytes(3));
$reg = http('t', 'POST', 'api.php', ['action' => 'teacher_register', 'username' => $name, 'password' => '12345678']);
$tokens['t'] = $reg['csrf'] ?? ''; $tid = (int)$db->query("SELECT id FROM teacher_account WHERE username='$name'")->fetchColumn();
$auth = http('t', 'GET', 'api.php?action=auth');
check('tài khoản mới: chưa có hồ sơ → web yêu cầu điền', $auth['profile'] === null && $auth['profile_ready'] === true, $auth);
check('họ tên trống bị từ chối', teacher('t', 'profile_save', ['full_name' => ''])['code'] === 'INVALID_INPUT');
$p = teacher('t', 'profile_save', ['full_name' => 'Nguyễn Thị Lan', 'gender' => 'nu', 'school' => 'THPT Nguyễn Du', 'subject' => 'Vật lý', 'grades' => 'Khối 10, 11', 'phone' => '0901 234 567 abc']);
check('lưu hồ sơ: đủ trường, giới tính, số điện thoại được lọc', ($p['profile']['full_name'] ?? '') === 'Nguyễn Thị Lan' && $p['profile']['gender'] === 'nu' && $p['profile']['phone'] === '0901 234 567 ' && !$p['profile']['has_avatar'], $p);
check('auth trả hồ sơ', (http('t', 'GET', 'api.php?action=auth')['profile']['school'] ?? '') === 'THPT Nguyễn Du');
$png = 'data:image/png;base64,' . base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$up = teacher('t', 'avatar_upload', ['image' => $png]);
check('tải ảnh đại diện', ($up['profile']['has_avatar'] ?? false) === true, $up);
$img = http('t', 'GET', 'api.php?action=avatar');
check('xem ảnh đại diện', $img['status'] === 200 && str_starts_with($img['raw'] ?? '', "\x89PNG"));
check('file giả ảnh bị từ chối', teacher('t', 'avatar_upload', ['image' => 'data:image/png;base64,' . base64_encode('<?php echo 1;')])['code'] === 'INVALID_IMAGE');
$db->exec("UPDATE teacher_account SET is_admin=1 WHERE id=$tid");
$ov = http('t', 'GET', 'api.php?action=admin_overview');
$mine = array_values(array_filter($ov['teachers'] ?? [], fn($x) => (int)$x['id'] === $tid))[0] ?? [];
check('quản trị viên thấy hồ sơ + ảnh của giáo viên', ($mine['profile']['school'] ?? '') === 'THPT Nguyễn Du' && $mine['profile']['has_avatar'] == 1, $mine['profile'] ?? $ov);
check('quản trị viên xem được ảnh của người khác', http('t', 'GET', "api.php?action=avatar&id=$tid")['status'] === 200);
$db->exec("UPDATE teacher_account SET is_admin=0 WHERE id=$tid");
teacher('t', 'avatar_remove');
check('bỏ ảnh → về ảnh mặc định', http('t', 'GET', 'api.php?action=auth')['profile']['has_avatar'] === false);
check('đổi mật khẩu: sai mật khẩu cũ', teacher('t', 'password_change', ['old_password' => '00000000', 'password' => '22223333', 'password2' => '22223333'])['code'] === 'WRONG_PASSWORD');
check('đổi mật khẩu: nhập lại không khớp', teacher('t', 'password_change', ['old_password' => '12345678', 'password' => '22223333', 'password2' => '22223334'])['code'] === 'PASSWORD_MISMATCH');
check('đổi mật khẩu thành công', (teacher('t', 'password_change', ['old_password' => '12345678', 'password' => '22223333', 'password2' => '22223333'])['ok'] ?? false) === true);
$jars['t2'] = [];
check('đăng nhập bằng mật khẩu mới', (http('t2', 'POST', 'api.php', ['action' => 'teacher_login', 'username' => $name, 'password' => '22223333'])['ok'] ?? false) === true);
// class without a file
$cls = 'OPEN' . random_int(1000, 9999);
check('tạo lớp tự đăng ký', (teacher('t', 'class_create', ['class_id' => $cls])['ok'] ?? false) === true);
check('tạo trùng bị từ chối', teacher('t', 'class_create', ['class_id' => $cls])['code'] === 'CLASS_EXISTS');
$boot = http('t', 'GET', 'api.php?action=bootstrap');
$row = array_values(array_filter($boot['classes'], fn($c) => $c['class_id'] === $cls))[0] ?? [];
check('lớp hiện trong danh sách, 0 sinh viên, đánh dấu tự đăng ký', (int)($row['count'] ?? -1) === 0 && (int)($row['open'] ?? 0) === 1, $row);
teacher('t', 'set_mode', ['mode' => 'wifi']);
$examId = teacher('t', 'import_exam', ['title' => "Open $cls", 'text' => "[QUESTIONS]\n\nQ1\nC1?\nA. a\nB. b\nC. c\nD. d\n\nQ2\nC2?\nA. a\nB. b\nC. c\nD. d\n\n[ANSWER_KEY]\nQ1=A\nQ2=B\n"])['exam_id'] ?? 0;
$sid = (int)(teacher('t', 'create_session', ['title' => "Open $cls", 'class_id' => $cls, 'exam_id' => $examId, 'auto_seconds' => 20, 'pacing' => 'self'])['session_id'] ?? 0);
check('mở phòng cho lớp chưa có sinh viên', $sid > 0);
teacher('t', 'open_login', ['session_id' => $sid]);
foreach ([['p1', '2300009', 'Cường'], ['p2', '2300001', 'An'], ['p3', '230005', 'Bình']] as [$who, $mssv, $nm]) {
    $tokens[$who] = http($who, 'GET', 'phone-api.php')['csrf'];
    http($who, 'POST', 'phone-api.php', ['action' => 'register', 'session_id' => $sid, 'student_id' => $mssv, 'student_name' => $nm, 'room' => room_code($base, $sid)]);
}
teacher('t', 'approve_join', ['session_id' => $sid, 'student_ids' => 'all']);
$roster = http('t', 'GET', "api.php?action=dashboard&session_id=$sid")['roster'];
check('duyệt xong: danh sách xếp theo MSSV tăng dần', array_column($roster, 'student_id') === ['230005', '2300001', '2300009'] && array_column($roster, 'student_no') == [1, 2, 3], array_map(fn($r) => [$r['student_no'], $r['student_id']], $roster));
$stud = $db->query("SELECT student_id,student_no FROM student WHERE teacher_id=$tid AND class_id='$cls' ORDER BY student_no")->fetchAll(PDO::FETCH_KEY_PAIR);
check('danh sách lớp đã lưu cũng theo thứ tự đó', array_keys($stud) == ['230005', '2300001', '2300009'], $stud);
teacher('t', 'start_question', ['session_id' => $sid]);
$d = http('t', 'GET', "api.php?action=dashboard&session_id=$sid");
check('đồng hồ cả bài: 2 câu × 20 giây = 40 giây, đang đếm', $d['self_total'] === 40 && $d['self_left'] > 35 && $d['self_left'] <= 40, [$d['self_total'], $d['self_left']]);
$q = http('p1', 'POST', 'phone-api.php', ['action' => 'poll', 'room' => room_code($base, $sid)])['question'] ?? [];
check('màn sinh viên cũng có thời gian còn lại của cả bài', isset($q['quiz_left']) && $q['quiz_left'] > 35, $q);
teacher('t', 'close_question', ['session_id' => $sid]);
check('xoá lớp tự đăng ký', (teacher('t', 'remove_class', ['class_id' => $cls, 'confirmed' => true])['ok'] ?? false) === true
    && !$db->query("SELECT 1 FROM teacher_class WHERE teacher_id=$tid")->fetchColumn());

teacher('t', 'remove_exam', ['exam_id' => $examId, 'confirmed' => true]);
$db->beginTransaction(); delete_teacher($db, $tid); $db->commit();
echo $failures ? "\n$failures FAIL\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
