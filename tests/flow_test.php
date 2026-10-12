<?php
// End-to-end classroom flow over real HTTP against the test database (never the live one).
//   php tests/flow_test.php
// Needs .local/test-config.php (smart_clicker_test). Starts its own PHP server with a throwaway teacher password.
declare(strict_types=1);

$root = dirname(__DIR__);
$base = require "$root/.local/test-config.php";
if (!str_contains($base['dsn'], 'smart_clicker_test')) exit("Refusing: test config must point at smart_clicker_test\n");

$tmp = sys_get_temp_dir() . '/clicker-flow-' . bin2hex(random_bytes(4));
mkdir($tmp);
symlink("$root/web", "$tmp/clicker");
$password = (string)random_int(10000000, 99999999);
$username = 'FLOW_' . bin2hex(random_bytes(4));
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
function http(string $method, string $query, ?array $body = null, array $headers = []): array {
    global $port, $cookie, $csrf;
    $headers = array_merge(['Content-Type: application/json'], $cookie ? ["Cookie: $cookie"] : [], $csrf ? ["X-CSRF-Token: $csrf"] : [], $headers);
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body === null ? '' : json_encode($body), 'ignore_errors' => true, 'timeout' => 10]]);
    $raw = file_get_contents("http://127.0.0.1:$port/clicker/api.php$query", false, $context);
    foreach ($http_response_header as $line) if (preg_match('/^Set-Cookie: (clicker_teacher=[^;]+)/i', $line, $m)) $cookie = $m[1];
    preg_match('/ (\d{3})/', $http_response_header[0], $m);
    return ['status' => (int)$m[1]] + (json_decode((string)$raw, true) ?? ['raw' => $raw]);
}
function teacher(string $action, array $data = []): array { return http('POST', '', ['action' => $action] + $data); }
// Devices are phones (phone-api.php, one signed cookie each) that send the room code; "NRF-…" names are simulated
// Student Tags (the same phone page in a BLE room). $roomSid: the room the phones typed the code of.
require_once "$root/web/lib.php";   // room_code()
$phones = []; $roomSid = 0;
function phone_id(string $name): array {
    global $port, $phones;
    if (!isset($phones[$name])) {
        $raw = file_get_contents("http://127.0.0.1:$port/clicker/phone-api.php");
        preg_match('/^Set-Cookie: (clicker_device=[^;]+)/mi', implode("\n", $http_response_header), $m);
        $phones[$name] = [$m[1], json_decode($raw, true)['csrf']];
    }
    return $phones[$name];
}
function device_body(string $name, string $action, array $data): array {
    global $base, $roomSid;
    $sid = (int)($data['session_id'] ?? $roomSid);
    return ['action' => $action] + $data + ($sid ? ['room' => room_code($base, $sid)] : []) + (str_starts_with($name, 'NRF-') ? ['as_tag' => true] : []);
}
function device(string $name, string $action, array $data = []): array {
    global $port;
    [$cookie, $token] = phone_id($name);
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nCookie: $cookie\r\nX-CSRF-Token: $token",
        'content' => json_encode(device_body($name, $action, $data)), 'ignore_errors' => true, 'timeout' => 10]]);
    $raw = file_get_contents("http://127.0.0.1:$port/clicker/phone-api.php", false, $context);
    preg_match('/ (\d{3})/', $http_response_header[0], $m);
    return ['status' => (int)$m[1]] + (json_decode((string)$raw, true) ?? ['raw' => $raw]);
}
function dash(int $sid): array { return http('GET', "?action=dashboard&session_id=$sid"); }
function current_no(array $d): ?int { return isset($d['question']) ? (int)$d['question']['question_no'] : null; }

$failures = 0;
function check(string $label, bool $ok, $detail = ''): void {
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : ' → ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) $failures++;
}

$db = new PDO($base['dsn'], $base['db_user'], $base['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->prepare('INSERT INTO teacher_account(username,password_hash) VALUES(?,?)')->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
$teacherId = (int)$db->lastInsertId();
// Device requests sent at the same moment from separate processes; returns [HTTP status, reply] for each.
function race(array $requests): array {
    global $port;
    $procs = [];
    foreach ($requests as [$name, $body]) {
        [$cookie, $token] = phone_id($name);
        $action = $body['action']; unset($body['action']);
        $procs[] = popen('php -r ' . escapeshellarg('$c=stream_context_create(["http"=>["method"=>"POST","header"=>"Content-Type: application/json\r\nCookie: ' . $cookie . '\r\nX-CSRF-Token: ' . $token . '","content"=>' . var_export(json_encode(device_body($name, $action, $body)), true) . ',"ignore_errors"=>true]]); $r=file_get_contents("http://127.0.0.1:' . $port . '/clicker/phone-api.php",false,$c); preg_match("/ (\\d{3})/",$http_response_header[0],$m); echo $m[1]," ",$r;'), 'r');
    }
    return array_map(function ($proc) { $out = stream_get_contents($proc); pclose($proc); return [(int)substr($out, 0, 3), json_decode(substr($out, 4), true)]; }, $procs);
}
function expire(int $sid): void { global $db; $db->prepare('UPDATE classroom_session SET auto_deadline=NOW(3) - INTERVAL 1 SECOND WHERE id=?')->execute([$sid]); }

// --- setup: own class and a 4-question exam
$login = teacher('teacher_login', ['username' => $username, 'password' => $password]);
$csrf = $login['csrf'] ?? '';
check('giáo viên đăng nhập', $login['ok'] ?? false, $login);
$class = 'FLOW' . random_int(1000, 9999);
$ids = [];
for ($i = 1; $i <= 5; $i++) $ids[] = '99' . random_int(1000000, 9999999);
teacher('import_students', ['students' => array_map(fn($id, $i) => ['student_no' => (string)($i + 1), 'student_id' => $id, 'student_name' => "Flow $i", 'class_id' => $class], $ids, array_keys($ids))]);
$exam = "[QUESTIONS]\n";
for ($q = 1; $q <= 4; $q++) $exam .= "\nQ$q\nCâu $q?\nA. a\nB. b\nC. c\nD. d\n";
$exam .= "\n[ANSWER_KEY]\nQ1=A\nQ2=B\nQ3=C\nQ4=D\n";
$examId = teacher('import_exam', ['title' => "Flow $class", 'text' => $exam])['exam_id'] ?? 0;
check('nhập lớp và đề 4 câu', $examId > 0);
$newRoom = function (int $seconds = 30) use (&$class, &$examId): int {
    global $roomSid;
    return $roomSid = (int)(teacher('create_session', ['title' => "Flow $class", 'class_id' => $class, 'exam_id' => $examId, 'auto_seconds' => $seconds])['session_id'] ?? 0);
};
check('từ chối số giây ngoài khoảng', teacher('create_session', ['class_id' => $class, 'exam_id' => $examId, 'auto_seconds' => 0])['code'] === 'INVALID_SECONDS'
    && teacher('create_session', ['class_id' => $class, 'exam_id' => $examId, 'auto_seconds' => 3601])['code'] === 'INVALID_SECONDS'
    && teacher('create_session', ['class_id' => $class, 'exam_id' => $examId, 'auto_seconds' => true])['code'] === 'INVALID_SECONDS');

// --- 1. manual flow: login → Bắt đầu → NEXT → KẾT THÚC
$sid = $newRoom(17);
check('tạo phòng lưu thời gian 17 giây', $sid > 0 && (int)dash($sid)['session']['auto_seconds'] === 17);
check('mở đăng nhập', (teacher('open_login', ['session_id' => $sid])['ok'] ?? false));
check('ESP32 đăng nhập', device('FLOW-ESP-1', 'login', ['session_id' => $sid, 'student_id' => $ids[0]])['ok'] ?? false);
check('nRF bị từ chối khi lớp ở chế độ Wi-Fi', device('NRF-FLOW-2', 'login', ['session_id' => $sid, 'student_id' => $ids[1]])['code'] === 'MODE_WIFI');
$start = teacher('start_question', ['session_id' => $sid, 'question_id' => 999999]);
$d = dash($sid);
check('Bắt đầu mở Q1 (bỏ qua ô chọn câu) và đóng đăng nhập', ($start['ok'] ?? false) && current_no($d) === 1 && $d['session']['state'] === 'QUESTION_ACTIVE', [$start, current_no($d)]);
check('đăng nhập muộn bị từ chối sau khi bắt đầu', device('FLOW-ESP-3', 'login', ['session_id' => $sid, 'student_id' => $ids[2]])['status'] === 409);
$qid = (int)$d['question']['id'];
$polled = json_encode([device('FLOW-ESP-1', 'poll'), teacher('gateway_sync', ['bridge_uid' => 'BRIDGE-0000000F10A1', 'tags' => ['NRF-0000000F10A2']])]);
check('thiết bị không bao giờ nhận đáp án đúng hay điểm của câu', !preg_match('/correct|points/', $polled), $polled);
check('trả lời Q1', device('FLOW-ESP-1', 'answer', ['session_id' => $sid, 'question_id' => $qid, 'answer' => 'A'])['ok'] ?? false);
check('bấm Bắt đầu lần 2 bị từ chối rõ ràng', teacher('start_question', ['session_id' => $sid])['code'] === 'QUESTION_ACTIVE');
teacher('next_question', ['session_id' => $sid]);
check('NEXT sang Q2', current_no(dash($sid)) === 2);
check('mở đăng nhập bị chặn khi đang làm', teacher('open_login', ['session_id' => $sid])['status'] === 409);
$end = teacher('close_question', ['session_id' => $sid]);
$d = dash($sid);
check('KẾT THÚC đóng câu và kết thúc buổi', ($end['ok'] ?? false) && $d['session']['state'] === 'FINISHED' && !array_filter($d['questions'], fn($q) => $q['opened_at'] !== null && $q['closed_at'] === null));
$afterEnd = device('FLOW-ESP-1', 'poll');
check('điện thoại về WAIT sau khi kết thúc, không giữ MSSV/câu cũ', ($afterEnd['ok'] ?? false)
    && $afterEnd['state'] === 'FINISHED' && $afterEnd['session_id'] === null
    && $afterEnd['student_id'] === null && $afterEnd['question'] === null);
check('không NEXT được sau khi kết thúc', teacher('next_question', ['session_id' => $sid])['code'] === 'SESSION_FINISHED');
$resultRow = array_values(array_filter(dash($sid)['report']['rows'], fn($r) => $r['student_id'] === $ids[0]))[0] ?? [];
check('đúng 1/4 = 25%, câu sai hoặc chưa mở đều không được tính đúng',
    ($resultRow['correct'] ?? -1) === 1 && ($resultRow['total'] ?? -1) === 4 && ($resultRow['percent'] ?? -1) === 25
    && count($resultRow['answers'] ?? []) === 4, $resultRow);
$csv = http('GET', "?action=export&session_id=$sid");
check('CSV xuất đúng/tổng và tỷ lệ', $csv['status'] === 200 && str_contains($csv['raw'] ?? '', 'Đúng/Tổng') && str_contains($csv['raw'] ?? '', '1/4,25'));

// --- 2. AUTO from the room with login open, through to the end
$sid = $newRoom(17);
teacher('open_login', ['session_id' => $sid]);
$auto = teacher('auto_start', ['session_id' => $sid]);
$d = dash($sid);
check('AUTO: đóng đăng nhập, mở Q1, đếm 17 giây', ($auto['ok'] ?? false) && current_no($d) === 1 && $d['auto_remaining'] >= 16 && $d['auto_remaining'] <= 17, [$auto, current_no($d), $d['auto_remaining']]);
check('AUTO chặn NEXT', teacher('next_question', ['session_id' => $sid])['code'] === 'AUTO_RUNNING');
check('AUTO chặn Bắt đầu', teacher('start_question', ['session_id' => $sid])['code'] === 'AUTO_RUNNING');
check('bấm AUTO lần 2 (bật) bị chặn', teacher('auto_start', ['session_id' => $sid])['code'] === 'AUTO_RUNNING');
check('tạo phòng mới bị chặn khi AUTO chạy', ($x = teacher('create_session', ['class_id' => $class, 'exam_id' => $examId]))['code'] === 'AUTO_RUNNING', $x);
$seen = [1];
for ($i = 0; $i < 6; $i++) {
    expire($sid);
    // a device poll and the dashboard hit the expired deadline together: still exactly one step
    device('FLOW-ESP-1', 'poll'); $d = dash($sid);
    if ($d['session']['state'] === 'FINISHED') break;
    if ($i === 0) check('AUTO sang Q2 vẫn đếm 17 giây', $d['auto_remaining'] >= 16 && $d['auto_remaining'] <= 17, $d['auto_remaining']);
    $seen[] = current_no($d);
}
check('AUTO đi đúng Q1→Q2→Q3→Q4 không nhảy câu', $seen === [1, 2, 3, 4], $seen);
check('hết câu cuối: tự kết thúc buổi và tắt AUTO', $d['session']['state'] === 'FINISHED' && !(int)$d['session']['auto_mode']);

// --- 3. stop AUTO keeps the open question; KẾT THÚC works during AUTO
$sid = $newRoom();
teacher('auto_start', ['session_id' => $sid]);
teacher('auto_stop', ['session_id' => $sid]);
$d = dash($sid);
check('dừng AUTO: câu vẫn mở, điều khiển tay', current_no($d) === 1 && $d['session']['state'] === 'QUESTION_ACTIVE' && !(int)$d['session']['auto_mode']);
teacher('next_question', ['session_id' => $sid]);
check('NEXT tay sau khi dừng AUTO', current_no(dash($sid)) === 2);
teacher('auto_start', ['session_id' => $sid]);
check('KẾT THÚC khi AUTO đang chạy', (teacher('close_question', ['session_id' => $sid])['ok'] ?? false) && dash($sid)['session']['state'] === 'FINISHED');

// --- 4. room started mid-exam (old bug: Q20 then stuck) keeps going through the unused questions
$sid = $newRoom();
$q4 = (int)$db->query("SELECT id FROM question WHERE exam_id=$examId AND question_no=4")->fetchColumn();
$db->prepare("INSERT INTO session_question(session_id,question_id) VALUES(?,?)")->execute([$sid, $q4]);
$db->prepare("UPDATE classroom_session SET state='QUESTION_ACTIVE',current_question_id=? WHERE id=?")->execute([$q4, $sid]);
$order = [4];
for ($i = 0; $i < 3; $i++) { teacher('next_question', ['session_id' => $sid]); $order[] = current_no(dash($sid)); }
check('đang ở câu cuối vẫn NEXT được về câu chưa làm (Q4→Q1→Q2→Q3)', $order === [4, 1, 2, 3], $order);
check('hết câu thì NEXT báo rõ', teacher('next_question', ['session_id' => $sid])['code'] === 'NO_NEXT_QUESTION');

// --- 5. creating a room ends the previous one; concurrent double-click is serialized
$sid = $newRoom();
teacher('start_question', ['session_id' => $sid]);
$sid2 = $newRoom();
check('tạo phòng mới tự kết thúc phòng cũ', $sid2 > 0 && dash($sid)['session']['state'] === 'FINISHED');
teacher('start_question', ['session_id' => $sid2]);
$parallel = [];
$procs = [];
for ($i = 0; $i < 2; $i++) {
    $procs[] = popen('php -r ' . escapeshellarg('$c=stream_context_create(["http"=>["method"=>"POST","header"=>"Content-Type: application/json\r\nCookie: ' . $cookie . '\r\nX-CSRF-Token: ' . $csrf . '","content"=>json_encode(["action"=>"next_question","session_id"=>' . $sid2 . ']),"ignore_errors"=>true]]); echo file_get_contents("http://127.0.0.1:' . $port . '/clicker/api.php",false,$c);'), 'r');
}
foreach ($procs as $p) { $parallel[] = json_decode(stream_get_contents($p), true); pclose($p); }
$d = dash($sid2);
$opened = count(array_filter($d['questions'], fn($q) => $q['opened_at'] !== null));
check('bấm NEXT 2 lần cùng lúc: đi đúng 2 câu, không lỗi DB', current_no($d) === 3 && $opened === 3 && count(array_filter($parallel, fn($r) => $r['ok'] ?? false)) === 2, $parallel);
teacher('close_question', ['session_id' => $sid2]);

// --- 6. login races, then the grace window after a question closes
$sid = $newRoom();
teacher('open_login', ['session_id' => $sid]);
$login = fn(string $studentId) => ['action' => 'login', 'session_id' => $sid, 'student_id' => $studentId];
$codes = array_column(race([['FLOW-RACE-A', $login($ids[2])], ['FLOW-RACE-B', $login($ids[2])]]), 0); sort($codes);
check('2 thiết bị cùng đăng nhập 1 MSSV: 1 thành công, 1 bị từ chối 409 (không lỗi 500)', $codes === [200, 409], $codes);
$twin = race([['FLOW-ESP-3', $login($ids[1])], ['FLOW-ESP-3', $login($ids[1])]]);
check('1 thiết bị gửi trùng đăng nhập cùng lúc: cả 2 lần đều LOGIN_OK', array_column($twin, 0) === [200, 200], $twin);
$split = race([['FLOW-ESP-1', $login($ids[3])], ['FLOW-ESP-1', $login($ids[4])]]);
$codes = array_column($split, 0); sort($codes);
check('1 thiết bị đăng nhập 2 MSSV cùng lúc: chỉ giữ 1 MSSV', $codes === [200, 409] && in_array('DEVICE_IN_USE', array_column(array_column($split, 1), 'code'), true), $split);
check('thiết bị đã đăng nhập đổi sang MSSV khác bị từ chối', device('FLOW-ESP-3', 'login', ['session_id' => $sid, 'student_id' => $ids[0]])['code'] === 'DEVICE_IN_USE');
check('gửi lại đăng nhập cũ vẫn LOGIN_OK', (device('FLOW-ESP-3', 'login', ['session_id' => $sid, 'student_id' => $ids[1]])['type'] ?? '') === 'LOGIN_OK');
check('danh sách phòng thấy đúng 3 thiết bị đã đăng nhập', dash($sid)['logged_in'] === 3);
teacher('start_question', ['session_id' => $sid]);
$q1 = (int)dash($sid)['question']['id'];
teacher('next_question', ['session_id' => $sid]);
$late = device('FLOW-ESP-1', 'answer', ['session_id' => $sid, 'question_id' => $q1, 'answer' => 'B']);
check('gia hạn: đáp án Q1 tới ngay sau NEXT vẫn được lưu', ($late['type'] ?? '') === 'ANSWER_ACK', $late);
$q2 = (int)dash($sid)['question']['id'];
teacher('next_question', ['session_id' => $sid]);
$db->prepare('UPDATE session_question SET closed_at=NOW(3) - INTERVAL 2 SECOND WHERE session_id=? AND question_id=?')->execute([$sid, $q2]);
check('quá 1,5 giây sau khi đóng thì từ chối', device('FLOW-ESP-1', 'answer', ['session_id' => $sid, 'question_id' => $q2, 'answer' => 'C'])['code'] === 'QUESTION_CLOSED');
teacher('close_question', ['session_id' => $sid]);

// --- 7. Kiểm tra lại a finished room: new round, old results untouched
$first = $newRoom(23);
teacher('open_login', ['session_id' => $first]);
device('FLOW-ESP-1', 'login', ['session_id' => $first, 'student_id' => $ids[0]]);
teacher('start_question', ['session_id' => $first]);
device('FLOW-ESP-1', 'answer', ['session_id' => $first, 'question_id' => (int)dash($first)['question']['id'], 'answer' => 'A']);
teacher('close_question', ['session_id' => $first]);
check('kiểm tra lại bị từ chối khi phòng chưa kết thúc', teacher('retest_session', ['session_id' => $newRoom()])['code'] === 'SESSION_NOT_FINISHED');
$again = teacher('retest_session', ['session_id' => $first]);
$d = dash((int)($again['session_id'] ?? 0));
check('kiểm tra lại: giữ 23 giây, mở đăng nhập, tên có "lần N"', ($again['ok'] ?? false) && $d['session']['state'] === 'LOGIN_OPEN' && (int)$d['session']['auto_seconds'] === 23 && preg_match('/ · lần \d+$/u', $d['session']['title']) && count($d['roster']) === count($ids), [$again, $d['session']['title'] ?? null]);
check('lượt mới làm lại từ Q1', (teacher('start_question', ['session_id' => $again['session_id']])['ok'] ?? false) && current_no(dash($again['session_id'])) === 1);
$oldRow = array_values(array_filter(dash($first)['report']['rows'], fn($r) => $r['student_id'] === $ids[0]))[0] ?? [];
check('kết quả lượt cũ giữ nguyên', ($oldRow['answers']['Q1'] ?? '') === 'A' && dash($first)['session']['state'] === 'FINISHED');
teacher('close_question', ['session_id' => $again['session_id']]);

// --- 8. Freeing the devices: idle rooms expire, answers keep a room alive, KẾT THÚC on a new room, logout ends the room
$state = fn(int $room) => $db->query("SELECT state FROM classroom_session WHERE id=$room")->fetchColumn();
$age = fn(int $room) => $db->exec("UPDATE classroom_session SET created_at=NOW() - INTERVAL 31 MINUTE, last_activity=NOW(3) - INTERVAL 31 MINUTE WHERE id=$room");
$idleRoom = $newRoom();
$age($idleRoom);
device('FLOW-ESP-1', 'poll');
check('phòng không hoạt động 30 phút tự kết thúc (thiết bị được giải phóng)', $state($idleRoom) === 'FINISHED');
$busyRoom = $newRoom();
teacher('open_login', ['session_id' => $busyRoom]);
device('FLOW-ESP-1', 'login', ['session_id' => $busyRoom, 'student_id' => $ids[0]]);
teacher('start_question', ['session_id' => $busyRoom]);
device('FLOW-ESP-1', 'answer', ['session_id' => $busyRoom, 'question_id' => (int)dash($busyRoom)['question']['id'], 'answer' => 'A']);
$age($busyRoom);
device('FLOW-ESP-1', 'poll');
check('lệnh giáo viên cũ nhưng vừa có đáp án: phòng không bị kết thúc', $state($busyRoom) === 'QUESTION_ACTIVE');
teacher('next_question', ['session_id' => $busyRoom]);
check('lệnh giáo viên cập nhật lần hoạt động cuối', (int)$db->query("SELECT last_activity > NOW(3) - INTERVAL 1 MINUTE FROM classroom_session WHERE id=$busyRoom")->fetchColumn() === 1);
teacher('close_question', ['session_id' => $busyRoom]);
$newOnly = $newRoom();
check('KẾT THÚC được ngay khi phòng mới tạo', (teacher('close_question', ['session_id' => $newOnly])['ok'] ?? false) && $state($newOnly) === 'FINISHED');
$leftRoom = $newRoom();
teacher('open_login', ['session_id' => $leftRoom]);
check('đăng xuất', teacher('logout')['ok'] ?? false);
check('đăng xuất thì phòng của mình tự kết thúc', $state($leftRoom) === 'FINISHED');
$cookie = '';
$login = teacher('teacher_login', ['username' => $username, 'password' => $password]);
$csrf = $login['csrf'] ?? '';

// --- 9. password guessing from the internet is rate limited per source
$savedCookie = $cookie; $cookie = '';
$outsider = '203.0.113.' . random_int(1, 254);
$tunnel = ['Host: localhost', 'Tailscale-Funnel-Request: ?1', "X-Forwarded-For: $outsider"];
$guess = fn(array $headers) => http('POST', '', ['action' => 'teacher_login', 'username' => $username, 'password' => '00000000'], $headers);
for ($i = 0, $blocked = false; $i < 10 && !$blocked; $i++) $blocked = $guess($tunnel)['status'] === 429;
check('người ngoài gõ sai nhiều lần thì bị chặn', $blocked);
check('nhưng không khóa đăng nhập trên laptop', $guess(['Host: localhost'])['status'] !== 429);
$db->prepare('DELETE FROM request_limit WHERE bucket=? AND actor_hash=?')->execute(['teacher_login', hash('sha256', $outsider)]);
$cookie = $savedCookie;

// --- cleanup: remove this run's rooms, exam and class from the test database
foreach ($db->query("SELECT id FROM classroom_session WHERE exam_id=$examId")->fetchAll(PDO::FETCH_COLUMN) as $room) teacher('remove_session', ['session_id' => (int)$room, 'confirmed' => true]);
teacher('remove_exam', ['exam_id' => $examId, 'confirmed' => true]);
teacher('remove_class', ['class_id' => $class, 'confirmed' => true]);
$db->exec("DELETE FROM device WHERE device_uid IN ('BRIDGE-0000000F10A1','NRF-0000000F10A2')");
$db->prepare('DELETE FROM teacher_account WHERE id=?')->execute([$teacherId]);

echo $failures ? "\n$failures FAIL\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
