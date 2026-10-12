<?php
// AI question generation against a fake OpenAI-compatible model (no real AI is called), and the review → save flow.
//   php tests/ai_quiz_test.php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = require "$root/.local/test-config.php";
if (!str_contains($base['dsn'], 'smart_clicker_test')) exit("Refusing: test config must point at smart_clicker_test\n");

$tmp = sys_get_temp_dir() . '/clicker-ai-' . bin2hex(random_bytes(4));
mkdir($tmp);
symlink("$root/web", "$tmp/clicker");
// Fake model: answers /chat/completions with 3 questions (one duplicate, one with 3 options) after checking the prompt.
$mockPort = random_int(41000, 50000);
file_put_contents("$tmp/mock.php", <<<'MOCK'
<?php
$in = json_decode(file_get_contents('php://input'), true);
$prompt = $in['messages'][1]['content'] ?? '';
file_put_contents(__DIR__ . '/mock-prompt.txt', $prompt);
$q = fn($t, $k) => ['question' => $t, 'A' => 'A. Volt', 'B' => 'Ampe', 'C' => 'Ôm', 'D' => 'Oát', 'answer' => $k, 'difficulty' => 'de', 'topic' => 'Đơn vị', 'explanation' => 'Vì vậy.'];
$out = ['questions' => [$q('Đơn vị điện áp?', 'A'), $q('Đơn vị điện áp?', 'A'), ['question' => 'Thiếu D?', 'A' => 'a', 'B' => 'b', 'C' => 'c', 'answer' => 'A'], $q('Đơn vị điện trở?', 'c')]];
header('Content-Type: application/json');
echo json_encode(['choices' => [['message' => ['content' => "```json\n" . json_encode($out, JSON_UNESCAPED_UNICODE) . "\n```"]]]]);
MOCK);
$mock = proc_open(['php', '-S', "127.0.0.1:$mockPort", "$tmp/mock.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $mp, null, ['PATH' => getenv('PATH')]);
file_put_contents("$tmp/config.php", '<?php return ' . var_export($base + ['quiz_ai_base_url' => "http://127.0.0.1:$mockPort/v1", 'quiz_ai_model' => 'mock-quiz'], true) . ';');
$port = random_int(20000, 40000);
$server = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $tmp], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['CLICKER_CONFIG' => "$tmp/config.php", 'PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($server, $tmp, &$mock) {
    proc_terminate($server); proc_terminate($mock);
    foreach (glob("$tmp/*") as $f) is_link($f) ? unlink($f) : @unlink($f); @rmdir($tmp);
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
$db->exec("DELETE FROM request_limit WHERE bucket IN ('ai_quiz','teacher_register')");
$name = 'AIQ_' . bin2hex(random_bytes(3));
$tokens['t'] = http('t', 'POST', 'api.php', ['action' => 'teacher_register', 'username' => $name, 'password' => '12345678'])['csrf'] ?? '';
$tid = (int)$db->query("SELECT id FROM teacher_account WHERE username='$name'")->fetchColumn();
check('ai_status báo đúng model riêng (quiz_ai_model)', (http('t', 'GET', 'api.php?action=ai_status')['model'] ?? '') === 'mock-quiz');
check('nội dung quá ngắn bị từ chối', teacher('t', 'ai_generate_questions', ['content' => 'ngắn', 'count' => 5])['code'] === 'AI_CONTENT');
check('số câu ngoài 1–40 bị từ chối', teacher('t', 'ai_generate_questions', ['content' => str_repeat('Điện áp đo bằng Volt. ', 10), 'count' => 99])['code'] === 'INVALID_INPUT');
$content = str_repeat('Điện áp có đơn vị Volt, điện trở có đơn vị Ôm, cường độ dòng điện có đơn vị Ampe. ', 4);
$r = teacher('t', 'ai_generate_questions', ['content' => $content, 'count' => 10, 'level' => 'kho', 'notes' => 'tập trung đơn vị']);
check('AI trả về: bỏ câu trùng và câu thiếu lựa chọn, bỏ "A." thừa, đáp án chữ thường được nhận', ($r['ok'] ?? false) && count($r['rows']) === 2
    && $r['rows'][0]['answer_A'] === 'Volt' && $r['rows'][1]['correct_answer'] === 'C' && $r['rows'][0]['explanation'] === 'Vì vậy.', $r);
$sent = (string)@file_get_contents(glob(sys_get_temp_dir() . '/clicker-ai-*/mock-prompt.txt')[0] ?? '');
check('gửi cho model: nội dung môn học, số câu, độ khó, yêu cầu thêm', str_contains($sent, 'Number of questions: 10') && str_contains($sent, 'hard') && str_contains($sent, 'tập trung đơn vị') && str_contains($sent, 'Volt'), substr($sent, 0, 200));
check('chưa lưu gì vào database', !(int)$db->query("SELECT COUNT(*) FROM exam WHERE teacher_id=$tid")->fetchColumn());
$save = teacher('t', 'import_questions', ['title' => 'Đề AI', 'rows' => array_map(fn($q) => array_intersect_key($q, array_flip(['question_text', 'answer_A', 'answer_B', 'answer_C', 'answer_D', 'correct_answer'])), $r['rows']), 'commit' => true]);
check('lưu câu đã chọn thành bộ đề', ($save['imported'] ?? 0) === 2);
$db->beginTransaction(); delete_teacher($db, $tid); $db->commit();
echo $failures ? "\n$failures FAIL\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
