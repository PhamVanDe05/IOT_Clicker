<?php
// Self-registration on the phone page and teacher approval (✓ / ✗ / duyệt tất cả).
//   php tests/self_join_test.php
// Needs .local/test-config.php (smart_clicker_test) with the join_request table (tools/upgrade_join.php).
declare(strict_types=1);

$root = dirname(__DIR__);
$base = require "$root/.local/test-config.php";
require_once "$root/web/lib.php";   // room_code()
if (!str_contains($base['dsn'], 'smart_clicker_test')) exit("Refusing: test config must point at smart_clicker_test\n");

$tmp = sys_get_temp_dir() . '/clicker-join-' . bin2hex(random_bytes(4));
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

$db = new PDO($base['dsn'], $base['db_user'], $base['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (!$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='join_request'")->fetchColumn()) exit("Run: CLICKER_CONFIG=.local/test-config.php CLICKER_ADMIN=app php tools/upgrade_join.php\n");
$db->exec("DELETE FROM request_limit WHERE bucket IN ('register','register_source','login','login_source')");
$teachers = [];
foreach (['main', 'other'] as $who) {
    $teachers[$who] = ['username' => 'JOIN_' . bin2hex(random_bytes(4)), 'password' => (string)random_int(10000000, 99999999)];
    $db->prepare('INSERT INTO teacher_account(username,password_hash) VALUES(?,?)')->execute([$teachers[$who]['username'], password_hash($teachers[$who]['password'], PASSWORD_DEFAULT)]);
    $teachers[$who]['id'] = (int)$db->lastInsertId();
    $tokens[$who] = teacher($who, 'teacher_login', ['username' => $teachers[$who]['username'], 'password' => $teachers[$who]['password']])['csrf'] ?? '';
}
$esp = 'JOIN-ESP-1';
$db->prepare('INSERT INTO device(device_uid,kind,key_hash) VALUES(?,?,?) ON DUPLICATE KEY UPDATE kind=VALUES(kind),key_hash=VALUES(key_hash),revoked_at=NULL')->execute([$esp, 'esp', hash('sha256', device_key($esp))]);

$class = 'JOIN' . random_int(1000, 9999);
$known = '95' . random_int(1000000, 9999999);
teacher('main', 'import_students', ['students' => [['student_no' => '1', 'student_id' => $known, 'student_name' => 'Có sẵn', 'class_id' => $class]]]);
$examId = teacher('main', 'import_exam', ['title' => "Join $class", 'text' => "[QUESTIONS]\n\nQ1\nCâu 1?\nA. a\nB. b\nC. c\nD. d\n\n[ANSWER_KEY]\nQ1=A\n"])['exam_id'] ?? 0;
$sid = (int)(teacher('main', 'create_session', ['title' => "Join $class", 'class_id' => $class, 'exam_id' => $examId, 'auto_seconds' => 30])['session_id'] ?? 0);
check('tạo phòng', $sid > 0);
$new1 = '96' . random_int(1000000, 9999999); $new2 = '97' . random_int(1000000, 9999999); $new3 = '98' . random_int(1000000, 9999999);

check('chưa mở đăng nhập → không đăng ký được', phone('p1', 'register', ['session_id' => $sid, 'student_id' => $new1, 'student_name' => 'Nguyễn Mới'])['code'] === 'LOGIN_CLOSED');
teacher('main', 'open_login', ['session_id' => $sid]);
$poll = phone('p1', 'poll');
check('điện thoại thấy lớp của phòng để tự điền', ($poll['class_id'] ?? '') === $class && array_key_exists('join_pending', $poll), $poll);
check('MSSV lạ đăng nhập → STUDENT_NOT_FOUND (mở form đăng ký)', phone('p1', 'login', ['session_id' => $sid, 'student_id' => $new1])['code'] === 'STUDENT_NOT_FOUND');
$reg = phone('p1', 'register', ['session_id' => $sid, 'student_id' => $new1, 'student_name' => 'Nguyễn Mới']);
check('gửi yêu cầu vào lớp → JOIN_PENDING', ($reg['type'] ?? '') === 'JOIN_PENDING', $reg);
check('điện thoại thấy đang chờ duyệt', (phone('p1', 'poll')['join_pending']['student_id'] ?? '') === $new1);
$dash = http('main', 'GET', "api.php?action=dashboard&session_id=$sid");
check('giáo viên thấy 1 người chờ duyệt', array_column($dash['join_requests'] ?? [], 'student_id') === [$new1], $dash['join_requests'] ?? $dash);
check('máy khác xin cùng MSSV → STUDENT_PENDING', phone('p2', 'register', ['session_id' => $sid, 'student_id' => $new1, 'student_name' => 'Giả mạo'])['code'] === 'STUDENT_PENDING');
check('MSSV đã có trong lớp → STUDENT_EXISTS', phone('p2', 'register', ['session_id' => $sid, 'student_id' => $known, 'student_name' => 'X'])['code'] === 'STUDENT_EXISTS');
check('họ tên rỗng/ký tự lạ bị từ chối', phone('p2', 'register', ['session_id' => $sid, 'student_id' => $new2, 'student_name' => '=1+1'])['code'] === 'INVALID_NAME');
check('ESP32 không còn được dùng: yêu cầu bị từ chối (DEVICE_REMOVED)', http('esp', 'POST', 'api.php', ['action' => 'register', 'device_uid' => $esp, 'session_id' => $sid, 'student_id' => $new2, 'student_name' => 'ESP'])['code'] === 'DEVICE_REMOVED');
check('giáo viên khác không duyệt được', teacher('other', 'approve_join', ['session_id' => $sid, 'student_ids' => [$new1]])['code'] === 'SESSION_NOT_FOUND');

$rej = teacher('main', 'reject_join', ['session_id' => $sid, 'student_ids' => [$new1]]);
$poll = phone('p1', 'poll');
check('✗ từ chối → hết chờ, chưa đăng nhập', ($rej['done'] ?? 0) === 1 && $poll['join_pending'] === null && $poll['student_id'] === null, [$rej, $poll]);
phone('p1', 'register', ['session_id' => $sid, 'student_id' => $new1, 'student_name' => 'Nguyễn Mới']);
$ok = teacher('main', 'approve_join', ['session_id' => $sid, 'student_ids' => [$new1]]);
$poll = phone('p1', 'poll');
check('✓ duyệt → điện thoại đăng nhập luôn', ($ok['done'] ?? 0) === 1 && $poll['student_id'] === $new1, [$ok, $poll]);
$row = $db->query("SELECT class_id,student_name,student_no FROM student WHERE teacher_id={$teachers['main']['id']} AND student_id='$new1'")->fetch(PDO::FETCH_ASSOC);
check('được thêm vào danh sách lớp của phòng, STT nối tiếp', $row && $row['class_id'] === $class && $row['student_name'] === 'Nguyễn Mới' && (int)$row['student_no'] === 2, $row);

phone('p2', 'register', ['session_id' => $sid, 'student_id' => $new2, 'student_name' => 'Trần Hai']);
phone('p3', 'register', ['session_id' => $sid, 'student_id' => $new3, 'student_name' => 'Lê Ba']);
$all = teacher('main', 'approve_join', ['session_id' => $sid, 'student_ids' => 'all']);
check('Duyệt tất cả → 2 người vào', ($all['done'] ?? 0) === 2 && phone('p2', 'poll')['student_id'] === $new2 && phone('p3', 'poll')['student_id'] === $new3, $all);
$dash = http('main', 'GET', "api.php?action=dashboard&session_id=$sid");
check('phòng có 4 sinh viên, hàng chờ trống', count($dash['roster']) === 4 && $dash['join_requests'] === [], [count($dash['roster']), $dash['join_requests']]);

$qid = teacher('main', 'start_question', ['session_id' => $sid])['question_id'] ?? 0;
check('người vừa duyệt trả lời được', phone('p1', 'answer', ['session_id' => $sid, 'question_id' => $qid, 'answer' => 'A'])['ok'] ?? false);
check('đã bắt đầu → không đăng ký mới được', phone('p4', 'register', ['session_id' => $sid, 'student_id' => '99' . random_int(1000000, 9999999), 'student_name' => 'Trễ'])['code'] === 'LOGIN_CLOSED');
teacher('main', 'close_question', ['session_id' => $sid]);

// --- cleanup
teacher('main', 'remove_exam', ['exam_id' => $examId, 'confirmed' => true]);
teacher('main', 'remove_class', ['class_id' => $class, 'confirmed' => true]);
$db->prepare('DELETE FROM device WHERE device_uid=?')->execute([$esp]);
foreach ($teachers as $t) $db->prepare('DELETE FROM teacher_account WHERE id=?')->execute([$t['id']]);
$db->exec("DELETE FROM request_limit WHERE bucket IN ('register','register_source')");

echo $failures ? "\n$failures FAIL\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
