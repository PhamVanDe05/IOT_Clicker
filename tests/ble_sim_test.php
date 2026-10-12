<?php
// Tag mô phỏng: phone.html in BLE mode logs in and answers as a Student Tag of its own (NRF-SIM-…).
//   php tests/ble_sim_test.php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = require "$root/.local/test-config.php";
require_once "$root/web/lib.php";   // room_code()
if (!str_contains($base['dsn'], 'smart_clicker_test')) exit("Refusing: test config must point at smart_clicker_test\n");

$tmp = sys_get_temp_dir() . '/clicker-sim-' . bin2hex(random_bytes(4));
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
$db->exec("DELETE FROM request_limit WHERE bucket IN ('login','login_source','answer','poll')");
$name = 'SIM_' . bin2hex(random_bytes(4)); $pass = (string)random_int(10000000, 99999999);
$db->prepare('INSERT INTO teacher_account(username,password_hash) VALUES(?,?)')->execute([$name, password_hash($pass, PASSWORD_DEFAULT)]);
$tid = (int)$db->lastInsertId();
$tokens['main'] = teacher('main', 'teacher_login', ['username' => $name, 'password' => $pass])['csrf'] ?? '';
$class = 'SIM' . random_int(1000, 9999);
$ids = ['91' . random_int(1000000, 9999999), '92' . random_int(1000000, 9999999)];
teacher('main', 'import_students', ['students' => [['student_no' => '1', 'student_id' => $ids[0], 'student_name' => 'Tag Một', 'class_id' => $class], ['student_no' => '2', 'student_id' => $ids[1], 'student_name' => 'Tag Hai', 'class_id' => $class]]]);
$examId = teacher('main', 'import_exam', ['title' => "Sim $class", 'text' => "[QUESTIONS]\n\nQ1\nCâu 1?\nA. a\nB. b\nC. c\nD. d\n\n[ANSWER_KEY]\nQ1=B\n"])['exam_id'] ?? 0;
$sid = (int)(teacher('main', 'create_session', ['title' => "Sim $class", 'class_id' => $class, 'exam_id' => $examId, 'auto_seconds' => 30])['session_id'] ?? 0);
check('tạo phòng', $sid > 0);
$hadSetting = $db->query("SELECT value FROM app_setting WHERE name='ble_sim'")->fetchColumn();

teacher('main', 'open_login', ['session_id' => $sid]);
check('phòng Wi-Fi: tag mô phỏng biết chế độ, đăng nhập bị từ chối (MODE_WIFI)', (phone('p1', 'poll', ['as_tag' => true])['mode'] ?? '') === 'wifi'
    && phone('p1', 'login', ['session_id' => $sid, 'student_id' => $ids[0], 'as_tag' => true])['code'] === 'MODE_WIFI');
teacher('main', 'close_login', ['session_id' => $sid]);
teacher('main', 'set_mode', ['mode' => 'ble']);
check('phòng BLE: điện thoại thấy chế độ BLE, đăng nhập thường bị từ chối (MODE_BLE)', (phone('p1', 'poll')['mode'] ?? '') === 'ble'
    && phone('p2', 'login', ['session_id' => $sid, 'student_id' => $ids[1]])['code'] === 'MODE_BLE');
$p = phone('p1', 'poll', ['as_tag' => true]);
check('chế độ BLE: tag mô phỏng thấy phòng', ($p['ok'] ?? false) && ($p['session_id'] ?? 0) === $sid, $p);
teacher('main', 'open_login', ['session_id' => $sid]);
$l = phone('p1', 'login', ['session_id' => $sid, 'student_id' => $ids[0], 'as_tag' => true]);
check('nhập MSSV → LOGIN_OK', ($l['type'] ?? '') === 'LOGIN_OK', $l);
$uid = $db->query("SELECT device_uid FROM session_student WHERE session_id=$sid AND student_id='{$ids[0]}'")->fetchColumn();
check('gắn với thiết bị NRF-SIM-… (khác mã điện thoại)', (bool)preg_match('/^NRF-SIM-[A-F0-9]{12}$/', (string)$uid), $uid);
check('máy khác nhập cùng MSSV → STUDENT_IN_USE (E3)', phone('p2', 'login', ['session_id' => $sid, 'student_id' => $ids[0], 'as_tag' => true])['code'] === 'STUDENT_IN_USE');
check('MSSV không có trong lớp → STUDENT_NOT_FOUND (E2)', phone('p2', 'login', ['session_id' => $sid, 'student_id' => '123', 'as_tag' => true])['code'] === 'STUDENT_NOT_FOUND');
$poll = phone('p1', 'poll', ['as_tag' => true]);
check('poll trả tên sinh viên cho tag mô phỏng', ($poll['student_name'] ?? '') === 'Tag Một', $poll);
teacher('main', 'start_question', ['session_id' => $sid]);
$q = phone('p1', 'poll', ['as_tag' => true])['question'] ?? null;
check('câu 1 mở trên tag', ($q['question_no'] ?? 0) === 1, $q);
$a = phone('p1', 'answer', ['session_id' => $sid, 'question_id' => $q['question_id'], 'answer' => 'B', 'as_tag' => true]);
check('chọn 2 + OK → ANSWER_ACK B', ($a['type'] ?? '') === 'ANSWER_ACK' && $a['answer'] === 'B', $a);
check('tag thấy đáp án đã lưu (hiện B=)', (phone('p1', 'poll', ['as_tag' => true])['question']['accepted_answer'] ?? '') === 'B');
check('đổi đáp án → ANSWER_LOCKED (E7)', phone('p1', 'answer', ['session_id' => $sid, 'question_id' => $q['question_id'], 'answer' => 'C', 'as_tag' => true])['code'] === 'ANSWER_LOCKED');
$row = array_values(array_filter(http('main', 'GET', "api.php?action=dashboard&session_id=$sid")['report']['rows'] ?? [], fn($r) => $r['student_id'] === $ids[0]))[0] ?? [];
check('bảng kết quả: Tag Một đúng 1 câu', ($row['correct'] ?? -1) === 1, $row);
$db->exec("INSERT INTO app_setting(name,value) VALUES('ble_sim','0') ON DUPLICATE KEY UPDATE value='0'");
check('quản trị tắt tag mô phỏng → SIM_OFF', phone('p1', 'poll', ['as_tag' => true])['code'] === 'SIM_OFF');
if ($hadSetting === false) $db->exec("DELETE FROM app_setting WHERE name='ble_sim'"); else $db->prepare("UPDATE app_setting SET value=? WHERE name='ble_sim'")->execute([$hadSetting]);
teacher('main', 'close_question', ['session_id' => $sid]);

// --- cleanup
teacher('main', 'set_mode', ['mode' => 'wifi']);
teacher('main', 'remove_session', ['session_id' => $sid, 'confirmed' => true]);
teacher('main', 'remove_exam', ['exam_id' => $examId, 'confirmed' => true]);
teacher('main', 'remove_class', ['class_id' => $class, 'confirmed' => true]);
$db->exec("DELETE FROM device WHERE device_uid LIKE 'NRF-SIM-%' OR (device_uid LIKE 'PHONE-%' AND last_seen >= NOW() - INTERVAL 5 MINUTE AND device_uid NOT IN (SELECT device_uid FROM session_student WHERE device_uid IS NOT NULL))");
$db->prepare('DELETE FROM teacher_account WHERE id=?')->execute([$tid]);
echo $failures ? "\n$failures FAIL\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
