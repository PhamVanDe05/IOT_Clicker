<?php
// Question import from Excel/paste and the question editor (Đợt 3): check-before-save, append to a set, add while a
// room is running, re-grading after a key change, delete rules, ownership.
//   php tests/question_edit_test.php
// Needs .local/test-config.php (smart_clicker_test). Starts its own PHP server with throwaway teachers.
declare(strict_types=1);

$root = dirname(__DIR__);
$base = require "$root/.local/test-config.php";
require_once "$root/web/lib.php";   // room_code()
if (!str_contains($base['dsn'], 'smart_clicker_test')) exit("Refusing: test config must point at smart_clicker_test\n");

$tmp = sys_get_temp_dir() . '/clicker-qedit-' . bin2hex(random_bytes(4));
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
// The answering device is a phone (phone-api.php) that typed the room's code.
$phoneId = null;
function device(string $unused, string $action, array $data = []): array {
    global $port, $base, $phoneId;
    if (!$phoneId) {
        $raw = file_get_contents("http://127.0.0.1:$port/clicker/phone-api.php");
        preg_match('/^Set-Cookie: (clicker_device=[^;]+)/mi', implode("\n", $http_response_header), $m);
        $phoneId = [$m[1], json_decode($raw, true)['csrf']];
    }
    $body = ['action' => $action] + $data + (isset($data['session_id']) ? ['room' => room_code($base, (int)$data['session_id'])] : []);
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nCookie: {$phoneId[0]}\r\nX-CSRF-Token: {$phoneId[1]}", 'content' => json_encode($body), 'ignore_errors' => true, 'timeout' => 10]]);
    $raw = file_get_contents("http://127.0.0.1:$port/clicker/phone-api.php", false, $context);
    return json_decode((string)$raw, true) ?? ['raw' => $raw];
}
function questions(int $examId): array { return http('GET', "?action=exam_questions&exam_id=$examId"); }
function q(string $text, string $key, string $prefix = ''): array {
    return ['question_text' => $text, 'answer_A' => "{$prefix}a", 'answer_B' => "{$prefix}b", 'answer_C' => "{$prefix}c", 'answer_D' => "{$prefix}d", 'correct_answer' => $key];
}

$failures = 0;
function check(string $label, bool $ok, $detail = ''): void {
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : ' → ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) $failures++;
}

$db = new PDO($base['dsn'], $base['db_user'], $base['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$teachers = [];
foreach (['main', 'other'] as $who) {
    $teachers[$who] = ['username' => 'QED_' . bin2hex(random_bytes(4)), 'password' => (string)random_int(10000000, 99999999)];
    $db->prepare('INSERT INTO teacher_account(username,password_hash) VALUES(?,?)')->execute([$teachers[$who]['username'], password_hash($teachers[$who]['password'], PASSWORD_DEFAULT)]);
    $teachers[$who]['id'] = (int)$db->lastInsertId();
}
$uid = 'phone';
function login_as(string $who): void {
    global $teachers, $csrf, $cookie;
    $cookie = ''; $csrf = '';
    $csrf = teacher('teacher_login', ['username' => $teachers[$who]['username'], 'password' => $teachers[$who]['password']])['csrf'] ?? '';
}
login_as('main');
check('giáo viên đăng nhập', $csrf !== '');

// --- import: check first, then save
$check = teacher('import_questions', ['title' => 'QEDIT set', 'rows' => [q('Câu 1?', 'a'), q('Câu 2?', 'E'), ['question_text' => 'Câu 3?', 'answer_A' => 'x']], 'commit' => false]);
check('kiểm tra: báo đúng dòng lỗi, chưa lưu gì', ($check['ok'] ?? false) && $check['imported'] === 0 && array_column($check['errors'], 'row') === [2, 3], $check);
$commitBad = teacher('import_questions', ['title' => 'QEDIT set', 'rows' => [q('Câu 1?', 'A'), q('Câu 2?', 'E')], 'commit' => true]);
check('còn lỗi thì không lưu', $commitBad['imported'] === 0 && !isset($commitBad['exam_id']));
$saved = teacher('import_questions', ['title' => 'QEDIT set', 'rows' => [q('Câu 1?', 'a'), q('Câu 2?', 'B')], 'commit' => true]);
$examId = (int)($saved['exam_id'] ?? 0);
check('lưu bộ đề mới 2 câu, đáp án chữ thường được nhận', $examId > 0 && $saved['imported'] === 2, $saved);
$more = teacher('import_questions', ['exam_id' => $examId, 'rows' => [q('Câu 3?', 'C')], 'commit' => true]);
$list = questions($examId);
check('nhập thêm vào bộ đề có sẵn → Q3 nối cuối', ($more['imported'] ?? 0) === 1 && array_column($list['questions'], 'question_no') === [1, 2, 3], $list);
check('danh sách có đáp án đúng cho giáo viên', array_column($list['questions'], 'correct_answer') === ['A', 'B', 'C']);
check('tối đa 200 câu mỗi bộ đề', teacher('import_questions', ['exam_id' => $examId, 'rows' => array_fill(0, 198, q('x', 'A')), 'commit' => false])['code'] === 'TOO_MANY_QUESTIONS');

// --- editor
$qids = array_column($list['questions'], 'id');
check('sửa đáp án sai định dạng bị từ chối', teacher('save_question', ['exam_id' => $examId, 'question_id' => (int)$qids[1]] + q('Câu 2?', 'E'))['code'] === 'INVALID_QUESTION');
$edit = teacher('save_question', ['exam_id' => $examId, 'question_id' => (int)$qids[1]] + q('Câu 2 (sửa)?', 'D', 'm'));
$list = questions($examId);
check('sửa nội dung và đáp án Q2', ($edit['ok'] ?? false) && $list['questions'][1]['question_text'] === 'Câu 2 (sửa)?' && $list['questions'][1]['correct_answer'] === 'D' && $list['questions'][1]['answer_A'] === 'ma');
check('đổi tên bộ đề', (teacher('rename_exam', ['exam_id' => $examId, 'title' => 'QEDIT renamed'])['ok'] ?? false) && questions($examId)['exam']['title'] === 'QEDIT renamed');

// --- live room: add a question while it runs, re-grade after a key change
$class = 'QED' . random_int(1000, 9999);
$sidNo = '97' . random_int(1000000, 9999999);
teacher('import_students', ['students' => [['student_no' => '1', 'student_id' => $sidNo, 'student_name' => 'Q Edit', 'class_id' => $class]]]);
$sid = (int)(teacher('create_session', ['title' => "QEDIT $class", 'class_id' => $class, 'exam_id' => $examId, 'auto_seconds' => 30])['session_id'] ?? 0);
teacher('open_login', ['session_id' => $sid]);
device($uid, 'login', ['session_id' => $sid, 'student_id' => $sidNo]);
teacher('start_question', ['session_id' => $sid]);
check('bộ đề đang dùng trong phòng chạy → báo live_session', (questions($examId)['live_session'] ?? null) === $sid);
check('trả lời Q1 = A (đúng)', device($uid, 'answer', ['session_id' => $sid, 'question_id' => (int)$qids[0], 'answer' => 'A'])['ok'] ?? false);
$added = teacher('save_question', ['exam_id' => $examId] + q('Câu 4 thêm lúc đang dạy?', 'A'));
$dash = http('GET', "?action=dashboard&session_id=$sid");
check('thêm câu lúc phòng đang chạy → phòng thấy ngay 4 câu', ($added['ok'] ?? false) && count($dash['questions'] ?? []) === 4, $dash['questions'] ?? $dash);
teacher('close_question', ['session_id' => $sid]);
$before = http('GET', "?action=dashboard&session_id=$sid")['report']['rows'][0]['correct'] ?? null;
teacher('save_question', ['exam_id' => $examId, 'question_id' => (int)$qids[0]] + q('Câu 1?', 'B'));
$after = http('GET', "?action=dashboard&session_id=$sid")['report']['rows'][0]['correct'] ?? null;
check('đổi đáp án Q1 A→B: điểm tự chấm lại 1 → 0', $before === 1 && $after === 0, [$before, $after]);

// --- delete rules
$del = teacher('remove_question', ['question_id' => (int)$qids[0], 'confirmed' => true]);
check('câu đã dùng trong buổi kiểm tra không xóa được', $del['code'] === 'QUESTION_USED', $del);
check('xóa cần xác nhận', teacher('remove_question', ['question_id' => (int)$qids[2]])['code'] === 'CONFIRM_REQUIRED');
$del = teacher('remove_question', ['question_id' => (int)$qids[2], 'confirmed' => true]);
$nos = array_column(questions($examId)['questions'], 'question_no');
check('xóa Q3 chưa dùng → còn Q1, Q2, Q4 cũ thành Q3', ($del['ok'] ?? false) && $nos === [1, 2, 3], $nos);

// --- another teacher cannot see or change this set
login_as('other');
check('giáo viên khác không xem được bộ đề', questions($examId)['code'] === 'EXAM_NOT_FOUND');
check('giáo viên khác không sửa được', teacher('save_question', ['exam_id' => $examId, 'question_id' => (int)$qids[1]] + q('hack', 'A'))['code'] === 'EXAM_NOT_FOUND');
check('giáo viên khác không xóa được', teacher('remove_question', ['question_id' => (int)$qids[1], 'confirmed' => true])['code'] === 'QUESTION_NOT_FOUND');
check('giáo viên khác không nhập thêm được', teacher('import_questions', ['exam_id' => $examId, 'rows' => [q('x', 'A')], 'commit' => true])['code'] === 'EXAM_NOT_FOUND');

// --- cleanup
login_as('main');
teacher('remove_exam', ['exam_id' => $examId, 'confirmed' => true]);
teacher('remove_class', ['class_id' => $class, 'confirmed' => true]);

foreach ($teachers as $t) $db->prepare('DELETE FROM teacher_account WHERE id=?')->execute([$t['id']]);

echo $failures ? "\n$failures FAIL\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
