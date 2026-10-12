<?php
// Room code: the teacher sees a six-digit code while the room holds the devices; room.php accepts only that code.
//   php tests/room_code_test.php
// Needs .local/test-config.php (smart_clicker_test). Starts its own PHP server with a throwaway teacher.
declare(strict_types=1);

$root = dirname(__DIR__);
$base = require "$root/.local/test-config.php";
if (!str_contains($base['dsn'], 'smart_clicker_test')) exit("Refusing: test config must point at smart_clicker_test\n");
require "$root/web/lib.php";

$tmp = sys_get_temp_dir() . '/clicker-room-' . bin2hex(random_bytes(4));
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

$cookie = '';
$csrf = '';
function http(string $method, string $path, ?array $body = null): array {
    global $port, $cookie, $csrf;
    $headers = array_merge(['Content-Type: application/json'], $cookie ? ["Cookie: $cookie"] : [], $csrf ? ["X-CSRF-Token: $csrf"] : []);
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body === null ? '' : json_encode($body), 'ignore_errors' => true, 'timeout' => 10]]);
    $raw = file_get_contents("http://127.0.0.1:$port/clicker/$path", false, $context);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: (clicker_teacher=[^;]+)/i', $line, $m)) $cookie = $m[1];
    preg_match('/ (\d{3})/', $http_response_header[0], $m);
    return ['status' => (int)$m[1]] + (json_decode((string)$raw, true) ?? ['raw' => $raw]);
}
function teacher(string $action, array $data = []): array { return http('POST', 'api.php', ['action' => $action] + $data); }
function dash(int $sid): array { return http('GET', "api.php?action=dashboard&session_id=$sid"); }
function room(string $code): array { return http('GET', 'room.php?code=' . rawurlencode($code)); }

$failures = 0;
function check(string $label, bool $ok, $detail = ''): void {
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : ' → ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) $failures++;
}

$db = new PDO($base['dsn'], $base['db_user'], $base['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$clearLimit = fn() => $db->prepare('DELETE FROM request_limit WHERE bucket=? AND actor_hash=?')->execute(['room_code', hash('sha256', '127.0.0.1')]);
$clearLimit();
$password = (string)random_int(10000000, 99999999);
$username = 'ROOM_' . bin2hex(random_bytes(4));
$db->prepare('INSERT INTO teacher_account(username,password_hash) VALUES(?,?)')->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
$teacherId = (int)$db->lastInsertId();

$login = teacher('teacher_login', ['username' => $username, 'password' => $password]);
$csrf = $login['csrf'] ?? '';
check('giáo viên đăng nhập', $login['ok'] ?? false, $login);
$class = 'ROOM' . random_int(1000, 9999);
teacher('import_students', ['students' => [['student_no' => '1', 'student_id' => '98' . random_int(1000000, 9999999), 'student_name' => 'Room 1', 'class_id' => $class]]]);
$examId = teacher('import_exam', ['title' => "Room $class", 'text' => "[QUESTIONS]\n\nQ1\nCâu 1?\nA. a\nB. b\nC. c\nD. d\n\n[ANSWER_KEY]\nQ1=A\n"])['exam_id'] ?? 0;
$sid = (int)(teacher('create_session', ['title' => "Room $class", 'class_id' => $class, 'exam_id' => $examId, 'auto_seconds' => 30])['session_id'] ?? 0);
check('tạo phòng', $sid > 0);

$code = dash($sid)['room_code'] ?? null;
check('giáo viên thấy mã 6 số khi phòng giữ thiết bị', is_string($code) && preg_match('/^[0-9]{6}$/D', $code) === 1, $code);
check('mã khác nhau giữa các phòng', room_code($base, $sid) !== room_code($base, $sid + 1));
teacher('open_login', ['session_id' => $sid]);
$ok = room((string)$code);
check('đúng mã → vào được, đang mở đăng nhập', ($ok['ok'] ?? false) && ($ok['login_open'] ?? null) === true, $ok);
$right = []; for ($i = 0; $i < 40; $i++) $right[] = room((string)$code)['status'];
check('cả lớp chung 1 IP nhập đúng mã 40 lần không bị chặn', count(array_unique($right)) === 1 && $right[0] === 200, array_count_values($right));
check('máy chủ chỉ nhận đúng 6 chữ số (trang ngoài tự bỏ dấu cách)', room('123 456')['code'] === 'ROOM_CODE');
check('sai định dạng → ROOM_CODE', room('12ab56')['status'] === 400);
$wrong = str_pad((string)(((int)$code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
$bad = room($wrong);
check('sai mã → 404, không lộ phòng nào', $bad['status'] === 404 && $bad['code'] === 'ROOM_NOT_FOUND' && !isset($bad['session_id']), $bad);

teacher('start_question', ['session_id' => $sid]);
$running = room((string)$code);
check('đang làm bài: mã vẫn đúng nhưng hết giờ đăng nhập', ($running['ok'] ?? false) && ($running['login_open'] ?? null) === false, $running);
teacher('close_question', ['session_id' => $sid]);
check('phòng kết thúc: giáo viên không còn thấy mã', array_key_exists('room_code', dash($sid)) && dash($sid)['room_code'] === null);
check('phòng kết thúc: mã cũ bị từ chối', room((string)$code)['status'] === 404);

$clearLimit();
$statuses = [];
for ($i = 0; $i < 31; $i++) $statuses[] = room($wrong)['status'];
check('đoán mã: lần sai thứ 31 trong 1 phút bị chặn', $statuses[29] === 404 && $statuses[30] === 429, array_slice($statuses, 28));

$clearLimit();

// --- cleanup
teacher('remove_exam', ['exam_id' => $examId, 'confirmed' => true]);
teacher('remove_class', ['class_id' => $class, 'confirmed' => true]);
$db->prepare('DELETE FROM teacher_account WHERE id=?')->execute([$teacherId]);

echo $failures ? "\n$failures FAIL\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
