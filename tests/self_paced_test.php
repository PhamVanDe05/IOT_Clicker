<?php
// "Sinh viên tự làm" rooms: every student walks the exam at their own pace, auto_seconds per question.
//   php tests/self_paced_test.php
// Needs .local/test-config.php (smart_clicker_test) with classroom_session.pacing (tools/upgrade_pacing.php).
declare(strict_types=1);

$root = dirname(__DIR__);
$base = require "$root/.local/test-config.php";
require_once "$root/web/lib.php";   // room_code()
if (!str_contains($base['dsn'], 'smart_clicker_test')) exit("Refusing: test config must point at smart_clicker_test\n");

$tmp = sys_get_temp_dir() . '/clicker-pace-' . bin2hex(random_bytes(4));
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
if (!$db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='classroom_session' AND column_name='pacing'")->fetchColumn()) exit("Run: CLICKER_CONFIG=.local/test-config.php CLICKER_ADMIN=app php tools/upgrade_pacing.php\n");
$db->exec("DELETE FROM request_limit WHERE bucket IN ('login','login_source','answer','poll')");
$teachers = [];
foreach (['main'] as $who) {
    $teachers[$who] = ['username' => 'PACE_' . bin2hex(random_bytes(4)), 'password' => (string)random_int(10000000, 99999999)];
    $db->prepare('INSERT INTO teacher_account(username,password_hash) VALUES(?,?)')->execute([$teachers[$who]['username'], password_hash($teachers[$who]['password'], PASSWORD_DEFAULT)]);
    $teachers[$who]['id'] = (int)$db->lastInsertId();
    $tokens[$who] = teacher($who, 'teacher_login', ['username' => $teachers[$who]['username'], 'password' => $teachers[$who]['password']])['csrf'] ?? '';
}
teacher('main', 'set_mode', ['mode' => 'wifi']);
$class = 'PACE' . random_int(1000, 9999);
$ids = []; for ($i = 1; $i <= 3; $i++) $ids[] = '94' . random_int(1000000, 9999999);
teacher('main', 'import_students', ['students' => array_map(fn($id, $i) => ['student_no' => (string)($i + 1), 'student_id' => $id, 'student_name' => "Pace $i", 'class_id' => $class], $ids, array_keys($ids))]);
$exam = "[QUESTIONS]\n"; for ($q = 1; $q <= 3; $q++) $exam .= "\nQ$q\nCâu số $q?\nA. a$q\nB. b$q\nC. c$q\nD. d$q\n";
$exam .= "\n[ANSWER_KEY]\nQ1=A\nQ2=B\nQ3=C\n";
$examId = teacher('main', 'import_exam', ['title' => "Pace $class", 'text' => $exam])['exam_id'] ?? 0;
check('giá trị hình thức sai bị từ chối', teacher('main', 'create_session', ['class_id' => $class, 'exam_id' => $examId, 'auto_seconds' => 3, 'pacing' => 'x'])['code'] === 'INVALID_PACING');
$sid = (int)(teacher('main', 'create_session', ['title' => "Pace $class", 'class_id' => $class, 'exam_id' => $examId, 'auto_seconds' => 3, 'pacing' => 'self'])['session_id'] ?? 0);
check('tạo phòng "Sinh viên tự làm" 3 giây/câu', $sid > 0 && http('main', 'GET', "api.php?action=dashboard&session_id=$sid")['pacing'] === 'self');
teacher('main', 'open_login', ['session_id' => $sid]);
foreach ([0, 1, 2] as $i) phone("p$i", 'login', ['session_id' => $sid, 'student_id' => $ids[$i]]);
$start = teacher('main', 'start_question', ['session_id' => $sid]);
check('Bắt đầu mở tất cả câu', ($start['ok'] ?? false) && (int)$db->query("SELECT COUNT(*) FROM session_question WHERE session_id=$sid")->fetchColumn() === 3, $start);
check('NEXT/AUTO bị chặn trong phòng tự làm', teacher('main', 'next_question', ['session_id' => $sid])['code'] === 'SELF_PACED' && teacher('main', 'auto_start', ['session_id' => $sid])['code'] === 'SELF_PACED');
$p = phone('p0', 'poll');
check('điện thoại thấy Q1 kèm nội dung + 4 lựa chọn, không có đáp án đúng', ($p['question']['question_no'] ?? 0) === 1 && $p['question']['text'] === 'Câu số 1?' && $p['question']['choices']['B'] === 'b1'
    && !str_contains(json_encode($p), 'correct') && $p['question']['total'] === 3 && $p['question']['remaining'] > 0, $p['question'] ?? $p);
$q1 = $p['question']['question_id'];
check('trả lời Q1', phone('p0', 'answer', ['session_id' => $sid, 'question_id' => $q1, 'answer' => 'A'])['ok'] ?? false);
$p = phone('p0', 'poll');
check('trả lời xong → sang ngay Q2 (không đợi)', ($p['question']['question_no'] ?? 0) === 2, $p['question'] ?? $p);
check('máy khác vẫn ở Q1', (phone('p1', 'poll')['question']['question_no'] ?? 0) === 1);
$q2 = $p['question']['question_id'];
check('không trả lời trước câu chưa tới (Q3)', phone('p0', 'answer', ['session_id' => $sid, 'question_id' => $q2 + 1, 'answer' => 'C'])['code'] === 'QUESTION_CLOSED');
check('không trả lời lại câu đã qua bằng đáp án khác', phone('p0', 'answer', ['session_id' => $sid, 'question_id' => $q1, 'answer' => 'B'])['code'] === 'ANSWER_LOCKED');
phone('p0', 'answer', ['session_id' => $sid, 'question_id' => $q2, 'answer' => 'C']);
$dash = http('main', 'GET', "api.php?action=dashboard&session_id=$sid");
$row0 = array_values(array_filter($dash['report']['rows'], fn($r) => $r['student_id'] === $ids[0]))[0];
check('kết quả cập nhật ngay: Q1=A, Q2=C khi chưa hết bài', $row0['answers']['Q1'] === 'A' && $row0['answers']['Q2'] === 'C', $row0['answers']);
check('bảng tiến độ: máy 1 đang ở câu 3', ($dash['self_progress'][$ids[0]]['index'] ?? 0) === 3 && ($dash['self_progress'][$ids[1]]['index'] ?? 0) === 1, $dash['self_progress']);
phone('p0', 'answer', ['session_id' => $sid, 'question_id' => $q2 + 1, 'answer' => 'C']);
sleep(5);   // > 3 s + 1.5 s grace: student p1 never answered Q1
$p = phone('p1', 'poll');
check('hết giờ không chọn → tự sang Q2', ($p['question']['question_no'] ?? 0) === 2, $p['question'] ?? $p);
check('câu đã hết giờ không trả lời được nữa', phone('p1', 'answer', ['session_id' => $sid, 'question_id' => $q1, 'answer' => 'A'])['code'] === 'QUESTION_CLOSED');
$p = phone('p0', 'poll');
check('làm xong → không còn câu, thấy điểm của mình (2/3, 6.67 điểm)', $p['question'] === null && ($p['self_done']['correct'] ?? -1) === 2 && abs(($p['self_done']['score10'] ?? 0) - 6.67) < 0.01, $p);
$row0 = array_values(array_filter(http('main', 'GET', "api.php?action=dashboard&session_id=$sid")['report']['rows'], fn($r) => $r['student_id'] === $ids[0]))[0];
check('bảng điểm có điểm thang 10', abs($row0['score10'] - 6.67) < 0.01, $row0);
sleep(6);   // every walk is over (3 questions x 3 s)
$d = http('main', 'GET', "api.php?action=dashboard&session_id=$sid");
check('hết thời gian của cả bài → phòng tự kết thúc', $d['session']['state'] === 'FINISHED', $d['session']['state']);
$csv = http('main', 'GET', "api.php?action=export&session_id=$sid");
check('file CSV có cột Điểm (thang 10)', str_contains($csv['raw'] ?? '', 'Điểm (thang 10)') && str_contains($csv['raw'] ?? '', '6.67'));

$sid3 = (int)(teacher('main', 'create_session', ['title' => "Pace3 $class", 'class_id' => $class, 'exam_id' => $examId, 'auto_seconds' => 30, 'pacing' => 'self'])['session_id'] ?? 0);
teacher('main', 'open_login', ['session_id' => $sid3]);
phone('p0', 'login', ['session_id' => $sid3, 'student_id' => $ids[0]]);
teacher('main', 'start_question', ['session_id' => $sid3]);
for ($i = 0; $i < 3; $i++) { $q = phone('p0', 'poll', ['session_id' => $sid3])['question']; phone('p0', 'answer', ['session_id' => $sid3, 'question_id' => $q['question_id'], 'answer' => 'A']); }
$close = teacher('main', 'close_question', ['session_id' => $sid3]);
check('mọi người làm xong rồi bấm KẾT THÚC: không báo lỗi, phòng ở trạng thái kết thúc', ($close['ok'] ?? false) && (int)$db->query("SELECT state='FINISHED' FROM classroom_session WHERE id=$sid3")->fetchColumn() === 1, $close);

// teacher-paced room still works as before
$sid2 = (int)(teacher('main', 'create_session', ['title' => "Pace2 $class", 'class_id' => $class, 'exam_id' => $examId, 'auto_seconds' => 30])['session_id'] ?? 0);
check('phòng mặc định vẫn là Giáo viên điều khiển', http('main', 'GET', "api.php?action=dashboard&session_id=$sid2")['pacing'] === 'teacher');
teacher('main', 'open_login', ['session_id' => $sid2]);
phone('p0', 'login', ['session_id' => $sid2, 'student_id' => $ids[0]]);
teacher('main', 'start_question', ['session_id' => $sid2]);
$p = phone('p0', 'poll', ['session_id' => $sid2]);
check('giáo viên điều khiển: điện thoại cũng thấy nội dung câu hỏi', ($p['question']['text'] ?? '') === 'Câu số 1?' && !isset($p['question']['total']), $p['question'] ?? $p);
phone('p0', 'answer', ['session_id' => $sid2, 'question_id' => $p['question']['question_id'], 'answer' => 'A']);
teacher('main', 'close_question', ['session_id' => $sid2]);
$end = phone('p0', 'poll', ['session_id' => $sid2]);
check('KẾT THÚC giữa chừng: điện thoại thấy điểm hiện tại (1/3 đúng → 3.33/10)', ($end['self_done']['correct'] ?? -1) === 1 && ($end['self_done']['total'] ?? 0) === 3 && abs(($end['self_done']['score10'] ?? 0) - 3.33) < 0.01, $end);
check('máy không vào phòng thì không thấy điểm', !isset(phone('p9', 'poll', ['session_id' => $sid2])['self_done']));
check('chế độ BLE không tạo được phòng tự làm', teacher('main', 'create_session', ['class_id' => $class, 'exam_id' => $examId, 'auto_seconds' => 3, 'pacing' => 'self', 'mode' => 'ble'])['code'] === 'PACING_BLE');

// --- cleanup
teacher('main', 'remove_exam', ['exam_id' => $examId, 'confirmed' => true]);
teacher('main', 'remove_class', ['class_id' => $class, 'confirmed' => true]);
foreach ($teachers as $t) $db->prepare('DELETE FROM teacher_account WHERE id=?')->execute([$t['id']]);

echo $failures ? "\n$failures FAIL\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
