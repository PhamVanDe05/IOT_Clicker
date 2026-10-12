<?php
// Several rooms at once: two teachers each run a room (one Wi-Fi, one BLE) side by side, test DB only.
//   php tests/multi_room_test.php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = require "$root/.local/test-config.php";
require_once "$root/web/lib.php";   // room_code()
if (!str_contains($base['dsn'], 'smart_clicker_test')) exit("Refusing: test config must point at smart_clicker_test\n");

$tmp = sys_get_temp_dir() . '/clicker-multi-' . bin2hex(random_bytes(4));
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

$jars = []; $tokens = [];   // one cookie set per browser: teachers "a", "b"; phones "p1", "p2", "t1"…
function http(string $who, string $method, string $path, ?array $body = null): array {
    global $port, $jars, $tokens;
    $cookie = implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($jars[$who] ?? []), $jars[$who] ?? []));
    $headers = array_merge(['Content-Type: application/json'], $cookie ? ["Cookie: $cookie"] : [], isset($tokens[$who]) ? ['X-CSRF-Token: ' . $tokens[$who]] : []);
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body === null ? '' : json_encode($body), 'ignore_errors' => true, 'timeout' => 10]]);
    $raw = file_get_contents("http://127.0.0.1:$port/clicker/$path", false, $context);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: ([a-z_]+)=([^;]+)/i', $line, $m)) $jars[$who][$m[1]] = $m[2];
    preg_match('/ (\d{3})/', $http_response_header[0], $m);
    return ['status' => (int)$m[1]] + (json_decode((string)$raw, true) ?? ['raw' => $raw]);
}
function teacher(string $who, string $action, array $data = []): array { return http($who, 'POST', 'api.php', ['action' => $action] + $data); }
// A phone that typed the code of room $room (and, with $tag, the simulated Student Tag on the same page).
function phone(string $who, int $room, string $action, array $data = [], bool $tag = false): array {
    global $tokens, $base;
    if (!isset($tokens[$who])) $tokens[$who] = http($who, 'GET', 'phone-api.php')['csrf'];
    return http($who, 'POST', 'phone-api.php', ['action' => $action] + $data + ($room ? ['room' => room_code($base, $room)] : []) + ($tag ? ['as_tag' => true] : []));
}
$failures = 0;
function check(string $label, bool $ok, $detail = ''): void {
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : ' → ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) $failures++;
}

$db = new PDO($base['dsn'], $base['db_user'], $base['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (!rooms_ready($db)) exit("Run: CLICKER_CONFIG=.local/test-config.php CLICKER_ADMIN=app php tools/upgrade_rooms.php\n");
$db->exec("DELETE FROM request_limit WHERE bucket IN ('login','login_source','answer','poll','room_code')");
$t = []; $class = []; $exam = []; $ids = [];
foreach (['a', 'b'] as $who) {
    $name = 'MULTI_' . $who . '_' . bin2hex(random_bytes(3)); $pass = (string)random_int(10000000, 99999999);
    $db->prepare('INSERT INTO teacher_account(username,password_hash) VALUES(?,?)')->execute([$name, password_hash($pass, PASSWORD_DEFAULT)]);
    $t[$who] = (int)$db->lastInsertId();
    $tokens[$who] = teacher($who, 'teacher_login', ['username' => $name, 'password' => $pass])['csrf'] ?? '';
    $class[$who] = 'M' . strtoupper($who) . random_int(1000, 9999);
    $ids[$who] = '8' . random_int(1000000, 9999999);
    teacher($who, 'import_students', ['students' => [['student_no' => '1', 'student_id' => $ids[$who], 'student_name' => "SV $who", 'class_id' => $class[$who]]]]);
    $exam[$who] = teacher($who, 'import_exam', ['title' => "Multi $who", 'text' => "[QUESTIONS]\n\nQ1\nCâu 1?\nA. a\nB. b\nC. c\nD. d\n\nQ2\nCâu 2?\nA. a\nB. b\nC. c\nD. d\n\n[ANSWER_KEY]\nQ1=A\nQ2=B\n"])['exam_id'] ?? 0;
}

// --- two rooms at the same time, each in its own mode
$ra = (int)(teacher('a', 'create_session', ['title' => 'Phòng A', 'class_id' => $class['a'], 'exam_id' => $exam['a'], 'auto_seconds' => 30, 'mode' => 'wifi'])['session_id'] ?? 0);
$rb = (int)(teacher('b', 'create_session', ['title' => 'Phòng B', 'class_id' => $class['b'], 'exam_id' => $exam['b'], 'auto_seconds' => 30, 'mode' => 'ble'])['session_id'] ?? 0);
$state = fn(int $room) => $db->query("SELECT state FROM classroom_session WHERE id=$room")->fetchColumn();
$modeOf = fn(int $room) => $db->query("SELECT mode FROM classroom_session WHERE id=$room")->fetchColumn();
check('2 giáo viên tạo 2 phòng cùng lúc, không ai bị chặn', $ra > 0 && $rb > 0 && $state($ra) !== 'FINISHED' && $state($rb) !== 'FINISHED');
check('mỗi phòng giữ chế độ riêng (A Wi-Fi, B BLE)', $modeOf($ra) === 'wifi' && $modeOf($rb) === 'ble');
check('2 phòng có mã khác nhau', room_code($base, $ra) !== room_code($base, $rb));
$ok = fn(int $room) => http('x', 'GET', 'room.php?code=' . room_code($base, $room))['ok'] ?? false;
check('trang ngoài nhận cả 2 mã phòng', $ok($ra) && $ok($rb));
$status = fn(string $who) => http($who, 'GET', 'api.php?action=status');
check('thanh trên của mỗi giáo viên theo chế độ phòng mình', ($status('a')['mode'] ?? '') === 'wifi' && ($status('b')['mode'] ?? '') === 'ble');

teacher('a', 'open_login', ['session_id' => $ra]);
teacher('b', 'open_login', ['session_id' => $rb]);
check('mở đăng nhập phòng A không đụng phòng B', $state($ra) === 'LOGIN_OPEN' && $state($rb) === 'LOGIN_OPEN');

// --- phones and tags find their room by its code
$p = phone('p1', $ra, 'poll');
check('điện thoại gõ mã A thấy phòng A (Wi-Fi)', ($p['session_id'] ?? 0) === $ra && ($p['mode'] ?? '') === 'wifi', $p);
check('đăng nhập vào phòng A', (phone('p1', $ra, 'login', ['session_id' => $ra, 'student_id' => $ids['a']])['type'] ?? '') === 'LOGIN_OK');
$p = phone('t1', $rb, 'poll', [], true);
check('tag mô phỏng gõ mã B thấy phòng B (BLE)', ($p['session_id'] ?? 0) === $rb && ($p['mode'] ?? '') === 'ble', $p);
check('tag đăng nhập vào phòng B', (phone('t1', $rb, 'login', ['session_id' => $rb, 'student_id' => $ids['b']], true)['type'] ?? '') === 'LOGIN_OK');
check('MSSV lớp A không vào được phòng B', phone('t2', $rb, 'login', ['session_id' => $rb, 'student_id' => $ids['a']], true)['code'] === 'STUDENT_NOT_FOUND');
check('không có mã B thì không đăng nhập được phòng B', phone('p3', $ra, 'login', ['session_id' => $rb, 'student_id' => $ids['b']])['code'] === 'LOGIN_CLOSED');
check('điện thoại đã vào A, không gửi mã, vẫn về đúng phòng A', (phone('p1', 0, 'poll')['session_id'] ?? 0) === $ra);

// --- each room moves on its own
teacher('a', 'start_question', ['session_id' => $ra]);
check('A bắt đầu câu 1, B vẫn chờ', $state($ra) === 'QUESTION_ACTIVE' && $state($rb) === 'LOGIN_OPEN');
$qa = phone('p1', $ra, 'poll')['question'] ?? [];
check('điện thoại phòng A thấy câu 1 của A', ($qa['question_no'] ?? 0) === 1 && ($qa['text'] ?? '') === 'Câu 1?', $qa);
check('tag phòng B chưa có câu nào', (phone('t1', $rb, 'poll', [], true)['question'] ?? null) === null);
check('trả lời phòng A', (phone('p1', $ra, 'answer', ['session_id' => $ra, 'question_id' => $qa['question_id'], 'answer' => 'A'])['type'] ?? '') === 'ANSWER_ACK');
teacher('b', 'start_question', ['session_id' => $rb]);
$qb = phone('t1', $rb, 'poll', [], true)['question'] ?? [];
check('tag trả lời phòng B', (phone('t1', $rb, 'answer', ['session_id' => $rb, 'question_id' => $qb['question_id'], 'answer' => 'B'], true)['type'] ?? '') === 'ANSWER_ACK');
check('trả lời câu của phòng A vào phòng B bị từ chối', phone('t1', $rb, 'answer', ['session_id' => $rb, 'question_id' => $qa['question_id'], 'answer' => 'A'], true)['code'] === 'QUESTION_CLOSED');
check('đổi chế độ phòng A không đổi phòng B', teacher('a', 'set_mode', ['mode' => 'ble'])['code'] === 'MODE_BUSY' && $modeOf($rb) === 'ble');
check('giáo viên khác không điều khiển được phòng A', teacher('b', 'next_question', ['session_id' => $ra])['status'] === 404);

// --- USB gateways follow their own teacher's room
$sync = fn(string $who, string $bridge) => teacher($who, 'gateway_sync', ['bridge_uid' => $bridge, 'tags' => []]);
check('gateway của B theo phòng B, của A theo phòng A', ($sync('b', 'BRIDGE-00000000B00B')['session_id'] ?? 0) === $rb && ($sync('a', 'BRIDGE-00000000A00A')['session_id'] ?? 0) === $ra);

// --- ending one room leaves the other running
teacher('a', 'close_question', ['session_id' => $ra]);
check('A kết thúc, B vẫn chạy', $state($ra) === 'FINISHED' && $state($rb) === 'QUESTION_ACTIVE');
$end = phone('p1', $ra, 'poll');
check('điện thoại phòng A thấy điểm của mình', ($end['state'] ?? '') === 'FINISHED' && ($end['self_done']['correct'] ?? -1) === 1, $end);
$rows = fn(string $who, int $room) => http($who, 'GET', "api.php?action=dashboard&session_id=$room")['report']['rows'] ?? [];
check('bảng điểm A và B riêng biệt (A đúng 1, B chọn B sai 0)', ($rows('a', $ra)[0]['correct'] ?? -1) === 1 && ($rows('b', $rb)[0]['correct'] ?? -1) === 0 && count($rows('b', $rb)) === 1);
check('giáo viên A tạo phòng mới không kết thúc phòng B', ($ra2 = (int)(teacher('a', 'create_session', ['title' => 'A2', 'class_id' => $class['a'], 'exam_id' => $exam['a'], 'auto_seconds' => 30])['session_id'] ?? 0)) > 0 && $state($rb) === 'QUESTION_ACTIVE');
teacher('b', 'logout');
check('B đăng xuất: chỉ phòng B kết thúc', $state($rb) === 'FINISHED' && $state($ra2) !== 'FINISHED');

// --- cleanup
foreach ($t as $id) { $db->beginTransaction(); delete_teacher($db, $id); $db->commit(); }
$db->exec("DELETE FROM device WHERE device_uid IN ('BRIDGE-00000000B00B','BRIDGE-00000000A00A')");
echo $failures ? "\n$failures FAIL\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
