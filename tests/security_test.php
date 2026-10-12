<?php
declare(strict_types=1);
ob_start();
// Direct HTTP authorization checks. Uses smart_clicker_test only.
$root = dirname(__DIR__);
$base = require "$root/.local/test-config.php";
if (!str_contains($base['dsn'], 'smart_clicker_test')) exit("Refusing non-test database\n");
$db = new PDO($base['dsn'], $base['db_user'], $base['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tmp = sys_get_temp_dir() . '/clicker-security-' . bin2hex(random_bytes(4));
mkdir($tmp);
symlink("$root/web", "$tmp/clicker");
$password = (string)random_int(10000000, 99999999);
$username = 'SEC_' . bin2hex(random_bytes(4));
$db->prepare('INSERT INTO teacher_account(username,password_hash) VALUES(?,?)')->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
file_put_contents("$tmp/config.php", '<?php return ' . var_export($base, true) . ';');
$port = random_int(20000, 40000);
$server = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $tmp],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null,
    ['CLICKER_CONFIG' => "$tmp/config.php", 'PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($server, $tmp, $db, $username) {
    foreach (['SEC-ESP-A', 'SEC-ESP-B', 'BRIDGE-SEC', 'SEC-TAG'] as $uid) {
        $db->prepare('DELETE FROM device WHERE device_uid=?')->execute([$uid]);
        $hash = hash('sha256', $uid);
        $db->prepare('DELETE FROM request_limit WHERE actor_hash=?')->execute([$hash]);
    }
    $db->prepare('DELETE FROM request_limit WHERE actor_hash=?')->execute([hash('sha256', '127.0.0.1')]);
    $db->prepare('DELETE FROM teacher_account WHERE username=? OR username=?')->execute([$username, $username . '_new']);
    proc_terminate($server);
    unlink("$tmp/clicker"); unlink("$tmp/config.php"); rmdir($tmp);
});
usleep(400000);

function request(string $path, string $method = 'GET', array $body = [], array $headers = []): array {
    global $port;
    $header = array_merge(['Content-Type: application/json'], $headers);
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $header),
        'content' => $method === 'POST' ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 5]]);
    $raw = file_get_contents("http://127.0.0.1:$port/clicker/$path", false, $context);
    $lines = $http_response_header;
    preg_match('/ (\d{3})/', $lines[0], $match);
    return ['status' => (int)$match[1], 'headers' => $lines, 'body' => json_decode((string)$raw, true) ?? []];
}
function cookie(array $response, string $name): string {
    foreach ($response['headers'] as $header) if (preg_match('/^Set-Cookie: (' . preg_quote($name, '/') . '=[^;]*)/i', $header, $match)) return $match[1];
    return '';
}
function check(string $label, bool $valid): void {
    global $failures;
    echo ($valid ? 'PASS ' : 'FAIL '), $label, PHP_EOL;
    if (!$valid) $failures++;
}
$failures = 0;
$teacher = request('api.php', 'POST', ['action' => 'teacher_login', 'username' => $username, 'password' => $password]);
$teacherCookie = cookie($teacher, 'clicker_teacher');
$csrf = $teacher['body']['csrf'] ?? '';
check('teacher login', $teacher['status'] === 200 && $teacherCookie !== '' && $csrf !== '');
$public = request('api.php', 'POST', ['action' => 'teacher_login', 'username' => $username, 'password' => $password], ['Host: clicker.example.ts.net']);
check('Funnel cookie Secure + HSTS', $public['status'] === 200
    && (bool)array_filter($public['headers'], fn($h) => str_starts_with($h, 'Set-Cookie: clicker_teacher=') && (bool)preg_match('/;\s*secure(?:;|$)/i', $h))
    && (bool)array_filter($public['headers'], fn($h) => stripos($h, 'Strict-Transport-Security:') === 0));
$register = request('api.php', 'POST', ['action' => 'teacher_register', 'username' => $username . '_new', 'password' => $password]);
check('teacher self-registration', $register['status'] === 200);
check('duplicate username rejected', request('api.php', 'POST', ['action' => 'teacher_register', 'username' => $username . '_new', 'password' => $password])['status'] === 409);
check('password must be eight digits', request('api.php', 'POST', ['action' => 'teacher_register', 'username' => $username . '_short', 'password' => '1234567'])['status'] === 400);
$newCookie = cookie($register, 'clicker_teacher');
$as = fn(string $cookie, string $csrf, string $action, array $body = []) => request('api.php', 'POST', ['action' => $action] + $body,
    ["Cookie: $cookie", "X-CSRF-Token: $csrf"]);
$class = 'SEC' . random_int(100000, 999999);
$sameId = (string)random_int(100000000, 999999999);
$onlyA = (string)random_int(100000000, 999999999);
$row = fn(string $id, string $name) => ['student_no' => 1, 'student_id' => $id, 'student_name' => $name, 'class_id' => $class];
$as($teacherCookie, $csrf, 'import_students', ['students' => [$row($sameId, 'Teacher A'), $row($onlyA, 'Only A')]]);
$as($newCookie, $register['body']['csrf'], 'import_students', ['students' => [$row($sameId, 'Teacher B')]]);
$bootA = request('api.php?action=bootstrap', 'GET', [], ["Cookie: $teacherCookie"]);
$bootB = request('api.php?action=bootstrap', 'GET', [], ["Cookie: $newCookie"]);
check('same MSSV and class remain separate', count($bootA['body']['students'] ?? []) === 2
    && count($bootB['body']['students'] ?? []) === 1
    && $bootA['body']['students'][0]['student_name'] !== $bootB['body']['students'][0]['student_name']);
$examText = "[QUESTIONS]\nQ1\nTest?\nA. A\nB. B\nC. C\nD. D\n[ANSWER_KEY]\nQ1=A\n";
$examA = $as($teacherCookie, $csrf, 'import_exam', ['title' => 'Exam A', 'text' => $examText])['body']['exam_id'] ?? 0;
$examB = $as($newCookie, $register['body']['csrf'], 'import_exam', ['title' => 'Exam B', 'text' => $examText])['body']['exam_id'] ?? 0;
check('exam lists remain separate', count(request('api.php?action=bootstrap', 'GET', [], ["Cookie: $newCookie"])['body']['exams'] ?? []) === 1);
$roomA = $as($teacherCookie, $csrf, 'create_session', ['class_id' => $class, 'exam_id' => $examA])['body']['session_id'] ?? 0;
check('other teacher cannot see dashboard or export',
    request('api.php?action=dashboard&session_id=' . $roomA, 'GET', [], ["Cookie: $newCookie"])['status'] === 404
    && request('api.php?action=export&session_id=' . $roomA, 'GET', [], ["Cookie: $newCookie"])['status'] === 404);
check('other teacher cannot delete or end room',
    $as($newCookie, $register['body']['csrf'], 'remove_session', ['session_id' => $roomA, 'confirmed' => true])['status'] === 404
    && $as($newCookie, $register['body']['csrf'], 'close_question', ['session_id' => $roomA])['status'] === 404);
check('other teacher cannot delete exam or unique student',
    $as($newCookie, $register['body']['csrf'], 'remove_exam', ['exam_id' => $examA, 'confirmed' => true])['status'] === 404
    && $as($newCookie, $register['body']['csrf'], 'remove_student', ['student_id' => $onlyA, 'confirmed' => true])['status'] === 404);
// Several rooms at once: the second teacher's room runs beside the first one and does not end it.
$roomB = $as($newCookie, $register['body']['csrf'], 'create_session', ['class_id' => $class, 'exam_id' => $examB])['body']['session_id'] ?? 0;
check('second teacher runs a room at the same time', $roomB > 0
    && $db->query("SELECT state FROM classroom_session WHERE id=" . (int)$roomA)->fetchColumn() !== 'FINISHED');
$as($teacherCookie, $csrf, 'close_question', ['session_id' => $roomA]);
$as($newCookie, $register['body']['csrf'], 'remove_session', ['session_id' => $roomB, 'confirmed' => true]);
$as($teacherCookie, $csrf, 'remove_session', ['session_id' => $roomA, 'confirmed' => true]);
$as($newCookie, $register['body']['csrf'], 'remove_exam', ['exam_id' => $examB, 'confirmed' => true]);
$as($teacherCookie, $csrf, 'remove_exam', ['exam_id' => $examA, 'confirmed' => true]);
$as($newCookie, $register['body']['csrf'], 'remove_class', ['class_id' => $class, 'confirmed' => true]);
$as($teacherCookie, $csrf, 'remove_class', ['class_id' => $class, 'confirmed' => true]);

$guest = request('api.php', 'POST', ['action' => 'create_session', 'class_id' => 'X', 'exam_id' => 1]);
check('guest cannot create session', $guest['status'] === 401);
check('guest cannot export', request('api.php?action=export&session_id=1')['status'] === 401);
$noCsrf = request('api.php', 'POST', ['action' => 'remove_session', 'session_id' => 1, 'confirmed' => true], ["Cookie: $teacherCookie"]);
check('teacher POST without CSRF denied', $noCsrf['status'] === 403);
$phone = request('phone-api.php');
$phoneCookie = cookie($phone, 'clicker_device');
check('phone cannot call teacher endpoint', request('api.php', 'POST', ['action' => 'close_question', 'session_id' => 1], ["Cookie: $phoneCookie"])['status'] === 401);
check('phone endpoint rejects teacher action', request('phone-api.php', 'POST', ['action' => 'create_session'],
    ["Cookie: $phoneCookie", 'X-CSRF-Token: ' . ($phone['body']['csrf'] ?? '')])['status'] === 400);

// ESP32 boards and the Python bridge are gone: their requests are refused whatever key they carry.
check('ESP32 / bridge requests refused (DEVICE_REMOVED)', request('api.php', 'POST', ['action' => 'poll', 'device_uid' => 'SEC-ESP-A'], ['X-Device-Key: ' . bin2hex(random_bytes(32))])['status'] === 410
    && request('api.php', 'POST', ['action' => 'bridge_sync', 'device_uid' => 'BRIDGE-SEC', 'tags' => []])['status'] === 410);
$phoneHeaders = ["Cookie: $phoneCookie", 'X-CSRF-Token: ' . ($phone['body']['csrf'] ?? '')];
check('phone without a room code joins no room', ($p = request('phone-api.php', 'POST', ['action' => 'poll'], $phoneHeaders))['status'] === 200 && $p['body']['session_id'] === null);
check('phone cannot log into a room it has no code for', request('phone-api.php', 'POST', ['action' => 'login', 'session_id' => 1, 'student_id' => '1'], $phoneHeaders)['status'] === 409);
check('phone with a wrong CSRF token denied', request('phone-api.php', 'POST', ['action' => 'poll'], ["Cookie: $phoneCookie", 'X-CSRF-Token: x'])['status'] === 403);
// The limit is 12 per one-second window; a burst can straddle two windows, so keep sending until one is refused.
for ($i = 0, $limited = ['status' => 0]; $i < 40 && $limited['status'] !== 429; $i++) $limited = request('phone-api.php', 'POST', ['action' => 'poll'], $phoneHeaders);
check('phone poll flood receives 429', $limited['status'] === 429);
$phoneUid = explode('.', explode('=', $phoneCookie, 2)[1])[0];
$db->prepare('DELETE FROM request_limit WHERE actor_hash=?')->execute([hash('sha256', $phoneUid)]);
$db->prepare('UPDATE device SET revoked_at=NOW() WHERE device_uid=?')->execute([$phoneUid]);
check('blocked phone denied', request('phone-api.php', 'POST', ['action' => 'poll'], $phoneHeaders)['status'] === 403);
$db->prepare('DELETE FROM request_limit WHERE actor_hash=?')->execute([hash('sha256', $phoneUid)]);
$db->prepare('DELETE FROM device WHERE device_uid=?')->execute([$phoneUid]);

check('teacher cookie authenticates', ($auth = request('api.php?action=auth', 'GET', [], ["Cookie: $teacherCookie"]))['body']['authenticated'] ?? false);
parse_str(str_replace(';', '&', $teacherCookie), $parts);
session_name('clicker_teacher'); session_id($parts['clicker_teacher'] ?? ''); session_start();
$_SESSION['last_activity'] = time() - 3601; session_write_close();
check('idle teacher session expires', !(request('api.php?action=auth', 'GET', [], ["Cookie: $teacherCookie"])['body']['authenticated'] ?? true));
$fresh = request('api.php', 'POST', ['action' => 'teacher_login', 'username' => $username, 'password' => $password]);
$freshCookie = cookie($fresh, 'clicker_teacher');
$logout = request('api.php', 'POST', ['action' => 'logout'], ["Cookie: $freshCookie", 'X-CSRF-Token: ' . $fresh['body']['csrf']]);
check('logout expires cookie', $logout['status'] === 200
    && (bool)array_filter($logout['headers'], fn($h) => str_starts_with($h, 'Set-Cookie: clicker_teacher=') && str_contains($h, 'expires=')));
check('old teacher cookie rejected after logout', !(request('api.php?action=auth', 'GET', [], ["Cookie: $freshCookie"])['body']['authenticated'] ?? true));
for ($i = 0; $i < 9; $i++) $bad = request('api.php', 'POST', ['action' => 'teacher_login', 'username' => $username, 'password' => '00000000']);
check('wrong teacher passwords throttled', $bad['status'] === 429);
echo $failures ? "$failures FAIL\n" : "ALL PASS\n";
exit($failures ? 1 : 0);
