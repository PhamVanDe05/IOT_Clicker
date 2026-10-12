<?php
// Gateway USB (web/gateway.js → api.php gateway_sync / gateway_login / gateway_answer) over real HTTP, test DB only.
//   php tests/gateway_test.php
// The browser side is played here: what the signed-in teacher tab sends after the USB gateway hears a tag.
declare(strict_types=1);

$root = dirname(__DIR__);
$base = require "$root/.local/test-config.php";
if (!str_contains($base['dsn'], 'smart_clicker_test')) exit("Refusing: test config must point at smart_clicker_test\n");

$tmp = sys_get_temp_dir() . '/clicker-gw-' . bin2hex(random_bytes(4));
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

$jar = [];   // who => [cookie, csrf]
function http(string $who, string $method, string $query, ?array $body = null): array {
    global $port, $jar;
    [$cookie, $csrf] = $jar[$who] ?? ['', ''];
    $headers = array_merge(['Content-Type: application/json'], $cookie ? ["Cookie: $cookie"] : [], $csrf ? ["X-CSRF-Token: $csrf"] : []);
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body === null ? '' : json_encode($body), 'ignore_errors' => true, 'timeout' => 10]]);
    $raw = file_get_contents("http://127.0.0.1:$port/clicker/api.php$query", false, $context);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: (clicker_teacher=[^;]+)/i', $line, $m)) $jar[$who][0] = $m[1];
    preg_match('/ (\d{3})/', $http_response_header[0], $m);
    return ['status' => (int)$m[1]] + (json_decode((string)$raw, true) ?? ['raw' => $raw]);
}
function teacher(string $who, string $action, array $data = []): array { return http($who, 'POST', '', ['action' => $action] + $data); }

$failures = 0;
function check(string $label, bool $ok, $detail = ''): void {
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : ' → ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) $failures++;
}

$db = new PDO($base['dsn'], $base['db_user'], $base['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$accounts = [];
foreach (['main', 'other'] as $who) {
    $name = 'GW_' . $who . '_' . bin2hex(random_bytes(3)); $pass = (string)random_int(10000000, 99999999);
    $db->prepare('INSERT INTO teacher_account(username,password_hash) VALUES(?,?)')->execute([$name, password_hash($pass, PASSWORD_DEFAULT)]);
    $accounts[$who] = (int)$db->lastInsertId();
    $login = teacher($who, 'teacher_login', ['username' => $name, 'password' => $pass]);
    $jar[$who][1] = $login['csrf'] ?? '';
}
$bridge = 'BRIDGE-' . strtoupper(bin2hex(random_bytes(6)));
$tagA = 'NRF-' . strtoupper(bin2hex(random_bytes(6)));
$tagB = 'NRF-' . strtoupper(bin2hex(random_bytes(6)));

$class = 'GW' . random_int(1000, 9999);
$ids = ['98' . random_int(1000000, 9999999), '98' . random_int(1000000, 9999999)];
teacher('main', 'import_students', ['students' => [['student_no' => '1', 'student_id' => $ids[0], 'student_name' => 'Gateway A', 'class_id' => $class], ['student_no' => '2', 'student_id' => $ids[1], 'student_name' => 'Gateway B', 'class_id' => $class]]]);
$exam = "[QUESTIONS]\n\nQ1\nCâu 1?\nA. a\nB. b\nC. c\nD. d\n\nQ2\nCâu 2?\nA. a\nB. b\nC. c\nD. d\n\n[ANSWER_KEY]\nQ1=B\nQ2=C\n";
$examId = teacher('main', 'import_exam', ['title' => "GW $class", 'text' => $exam])['exam_id'] ?? 0;
$sid = (int)(teacher('main', 'create_session', ['title' => "GW $class", 'class_id' => $class, 'exam_id' => $examId, 'auto_seconds' => 30])['session_id'] ?? 0);
check('tạo phòng thử', $sid > 0);

$sync = fn(string $who = 'main', array $tags = []) => teacher($who, 'gateway_sync', ['bridge_uid' => $bridge, 'tags' => $tags]);
$gwLogin = fn(string $tag, string $student, string $who = 'main') => teacher($who, 'gateway_login', ['bridge_uid' => $bridge, 'device_uid' => $tag, 'session_id' => $sid, 'student_id' => $student]);
$gwAnswer = fn(string $tag, int $qid, string $answer) => teacher('main', 'gateway_answer', ['bridge_uid' => $bridge, 'device_uid' => $tag, 'session_id' => $sid, 'question_id' => $qid, 'answer' => $answer]);

// --- who may use it
$saved = $jar['main'][1]; $jar['main'][1] = '';
check('thiếu CSRF bị từ chối', $sync()['status'] === 403);
$jar['main'][1] = $saved;
$savedJar = $jar['main']; unset($jar['main']);
check('chưa đăng nhập bị từ chối', $sync()['status'] === 401);
$jar['main'] = $savedJar;
check('gateway_uid sai dạng bị từ chối', teacher('main', 'gateway_sync', ['bridge_uid' => 'BRIDGE-XYZ', 'tags' => []])['code'] === 'INVALID_UID');
check('GET không dùng được', http('main', 'GET', '?action=gateway_sync')['status'] === 405);
check('gateway của giáo viên khác chỉ thấy phòng của người đó (không thấy phòng này)', ($o = $sync('other'))['ok'] === true && $o['session_id'] === null, $o);

// --- Wi-Fi mode: the tags are told to pause
check('ở chế độ Wi-Fi: sync báo mode wifi', ($sync()['mode'] ?? '') === 'wifi');
teacher('main', 'open_login', ['session_id' => $sid]);
check('ở chế độ Wi-Fi: tag bị từ chối', $gwLogin($tagA, $ids[0])['code'] === 'MODE_WIFI');
teacher('main', 'close_login', ['session_id' => $sid]);
check('chuyển sang BLE', (teacher('main', 'set_mode', ['mode' => 'ble'])['mode'] ?? '') === 'ble');

// --- one class in BLE through the gateway
teacher('main', 'open_login', ['session_id' => $sid]);
$s = $sync('main', [$tagA, $tagB]);
check('sync: phòng, trạng thái LOGIN_OPEN, tag chưa đăng nhập', ($s['session_id'] ?? 0) === $sid && $s['state'] === 'LOGIN_OPEN' && $s['tags'][$tagA]['logged'] === false, $s);
check('gateway hiện là online', (bool)$db->query("SELECT last_seen >= NOW() - INTERVAL 5 SECOND FROM device WHERE device_uid='$bridge'")->fetchColumn());
$l = $gwLogin($tagA, $ids[0]);
check('tag A đăng nhập MSSV', ($l['type'] ?? '') === 'LOGIN_OK', $l);
check('gửi lại cùng gói đăng nhập vẫn OK (không lỗi)', ($gwLogin($tagA, $ids[0])['type'] ?? '') === 'LOGIN_OK');
check('MSSV đã dùng trên tag khác → STUDENT_IN_USE', $gwLogin($tagB, $ids[0])['code'] === 'STUDENT_IN_USE');
check('MSSV lạ → STUDENT_NOT_FOUND', $gwLogin($tagB, '123')['code'] === 'STUDENT_NOT_FOUND');
check('giáo viên khác không gửi đăng nhập vào phòng này được', $gwLogin($tagB, $ids[1], 'other')['code'] === 'SESSION_FINISHED');
check('tag UID sai dạng bị từ chối', $gwLogin('NRF-12', $ids[1])['code'] === 'INVALID_UID');
teacher('main', 'start_question', ['session_id' => $sid]);
$s = $sync('main', [$tagA]);
$qid = (int)($s['question']['question_id'] ?? 0);
check('sync: câu 1 đang mở, tag A đã đăng nhập', $s['state'] === 'QUESTION_ACTIVE' && $qid > 0 && $s['tags'][$tagA]['logged'] === true, $s);
check('sync không lộ đáp án đúng', !preg_match('/correct|points/', json_encode($s)));
$a = $gwAnswer($tagA, $qid, 'B');
check('tag A trả lời B → ANSWER_ACK', ($a['type'] ?? '') === 'ANSWER_ACK' && $a['duplicate'] === false, $a);
check('gửi lại → ACK trùng, không ghi thêm', ($gwAnswer($tagA, $qid, 'B')['duplicate'] ?? null) === true
    && (int)$db->query("SELECT COUNT(*) FROM student_response WHERE session_id=$sid")->fetchColumn() === 1);
check('đổi đáp án đã lưu → ANSWER_LOCKED', $gwAnswer($tagA, $qid, 'C')['code'] === 'ANSWER_LOCKED');
check('tag chưa đăng nhập → LOGIN_REQUIRED', $gwAnswer($tagB, $qid, 'A')['code'] === 'LOGIN_REQUIRED');
check('sync báo đáp án đã lưu của tag A', ($sync('main', [$tagA])['tags'][$tagA]['answer'] ?? '') === 'B');
check('phòng cũ (session_id sai) → SESSION_FINISHED', teacher('main', 'gateway_answer', ['bridge_uid' => $bridge, 'device_uid' => $tagA, 'session_id' => $sid + 1000, 'question_id' => $qid, 'answer' => 'B'])['code'] === 'SESSION_FINISHED');
$row = array_values(array_filter(http('main', 'GET', "?action=dashboard&session_id=$sid")['report']['rows'] ?? [], fn($r) => $r['student_id'] === $ids[0]))[0] ?? [];
check('bảng kết quả: tag A đúng câu 1', ($row['correct'] ?? -1) === 1, $row);
$db->prepare('UPDATE device SET revoked_at=NOW() WHERE device_uid=?')->execute([$bridge]);
check('gateway bị admin chặn thì không dùng được', $sync()['code'] === 'DEVICE_BLOCKED');
$db->prepare('UPDATE device SET revoked_at=NULL WHERE device_uid=?')->execute([$bridge]);
teacher('main', 'close_question', ['session_id' => $sid]);
usleep(1100000);  // stay below the gateway_sync rate limit while testing the finished room
$finished = $sync('main', [$tagA, $tagB]);
check('KẾT THÚC: chỉ tag đã đăng nhập nhận điểm 5.0/10', $finished['state'] === 'FINISHED'
    && ($finished['tags'][$tagA]['score_tenths'] ?? null) === 50
    && ($finished['tags'][$tagB]['score_tenths'] ?? null) === null, $finished);

// --- cleanup: back to Wi-Fi, remove this run's data
check('trả về chế độ Wi-Fi', (teacher('main', 'set_mode', ['mode' => 'wifi'])['mode'] ?? '') === 'wifi');
teacher('main', 'remove_session', ['session_id' => $sid, 'confirmed' => true]);
teacher('main', 'remove_exam', ['exam_id' => $examId, 'confirmed' => true]);
teacher('main', 'remove_class', ['class_id' => $class, 'confirmed' => true]);
$db->prepare('DELETE FROM device WHERE device_uid IN (?,?,?)')->execute([$bridge, $tagA, $tagB]);
foreach ($accounts as $id) $db->prepare('DELETE FROM teacher_account WHERE id=?')->execute([$id]);

echo $failures ? "\n$failures FAIL\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
