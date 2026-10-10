<?php
declare(strict_types=1);

final class ApiError extends RuntimeException {
    public function __construct(public string $errorCode, string $message, public int $status = 400) {
        parent::__construct($message);
    }
}

function reject(string $code, string $message, int $status = 400): never {
    throw new ApiError($code, $message, $status);
}

function field(array $data, string $key, int $max = 120): string {
    $value = $data[$key] ?? null;
    if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $max || !preg_match('//u', $value)) {
        reject('INVALID_INPUT', "Trường $key không hợp lệ.");
    }
    return trim($value);
}

function positive_id(mixed $value): int {
    if (is_bool($value) || !is_scalar($value) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$value)) {
        reject('INVALID_ID', 'ID phải là số nguyên dương.');
    }
    return (int)$value;
}

function query(PDO $db, string $sql, array $params = []): PDOStatement {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function public_host(array $config): bool {
    $host = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    return str_ends_with($host, '.ts.net') || ($host !== '' && $host === strtolower($config['internet_host'] ?? ''));
}

function secure_request(array $config): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    // Funnel terminates TLS then proxies to Apache on loopback. Never trust forwarded headers from LAN clients.
    return public_host($config) && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

// Host is client-supplied: a Funnel request can claim "Host: localhost" and still arrive from loopback.
// The headers tailscaled adds cannot be removed by the sender, so they decide first.
function local_host_request(array $config): bool {
    if (public_host($config) || remote_request()) return false;
    $host = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    if (!in_array($host, ['localhost', '127.0.0.1', '::1', 'clicker.local'], true)) return false;
    $source = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($source, ['127.0.0.1', '::1'], true)
        || ($source !== '' && $source === ($_SERVER['SERVER_ADDR'] ?? '') && filter_var($source, FILTER_VALIDATE_IP));
}

function client_source(array $config): string {
    $source = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if ((public_host($config) || remote_request()) && in_array($source, ['127.0.0.1', '::1'], true)) {
        $hops = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        $forwarded = trim(end($hops));
        if (filter_var($forwarded, FILTER_VALIDATE_IP)) $source = $forwarded;
    }
    return $source;
}

function rate_limit(PDO $db, string $bucket, string $actor, int $max, int $seconds): void {
    $hash = hash('sha256', $actor);
    $window = intdiv(time(), $seconds);
    query($db, 'INSERT INTO request_limit(bucket,actor_hash,window_id,hits) VALUES(?,?,?,1)
        ON DUPLICATE KEY UPDATE hits=IF(window_id=VALUES(window_id),hits+1,1),window_id=VALUES(window_id)', [$bucket, $hash, $window]);
    if ((int)query($db, 'SELECT hits FROM request_limit WHERE bucket=? AND actor_hash=?', [$bucket, $hash])->fetchColumn() > $max) {
        reject('RATE_LIMIT', 'Gửi quá nhiều yêu cầu. Hãy thử lại sau.', 429);
    }
}

function authorize_device(PDO $db, string $uid, string $action, array $data, string $key): void {
    $bridge = $action === 'bridge_sync' || str_starts_with($uid, 'NRF-');
    $authUid = $bridge && $action !== 'bridge_sync' ? strtoupper(field($data, 'bridge_uid', 64)) : $uid;
    $kind = $bridge ? 'bridge' : 'esp';
    if ($bridge && !str_starts_with($authUid, 'BRIDGE-')) reject('UNAUTHORIZED', 'Thiết bị chưa được cấp quyền.', 401);
    $row = query($db, 'SELECT kind,key_hash,revoked_at FROM device WHERE device_uid=?', [$authUid])->fetch();
    if (!$row || $row['kind'] !== $kind || $row['revoked_at'] !== null
        || !hash_equals($row['key_hash'], hash('sha256', $key))) {
        reject('UNAUTHORIZED', 'Thiết bị chưa được cấp quyền.', 401);
    }
}

function parse_exam(string $text): array {
    $lines = preg_split('/\R/u', preg_replace('/^\xEF\xBB\xBF/', '', $text));
    if ($lines === false) reject('INVALID_EXAM', 'File đề phải là UTF-8.');
    $section = ''; $questions = []; $keys = []; $current = null; $part = 'question_text';
    foreach ($lines as $index => $raw) {
        $line = trim($raw); $number = $index + 1;
        if ($line === '') continue;
        if ($line === '[QUESTIONS]') {
            if ($section !== '') reject('INVALID_EXAM', "Dòng $number: trùng hoặc sai thứ tự QUESTIONS.");
            $section = 'questions'; continue;
        }
        if ($line === '[ANSWER_KEY]') {
            if ($section !== 'questions' || !$questions) reject('INVALID_EXAM', "Dòng $number: ANSWER_KEY sai vị trí.");
            $section = 'keys'; continue;
        }
        if ($section === 'keys') {
            if (!preg_match('/^Q([1-9][0-9]{0,3})\s*=\s*([ABCD])$/D', $line, $m) || isset($keys[(int)$m[1]])) {
                reject('INVALID_EXAM', "Dòng $number: đáp án sai định dạng hoặc trùng.");
            }
            $keys[(int)$m[1]] = $m[2]; continue;
        }
        if ($section !== 'questions') reject('INVALID_EXAM', "Dòng $number: thiếu [QUESTIONS].");
        if (preg_match('/^Q([1-9][0-9]{0,3})$/D', $line, $m)) {
            $current = (int)$m[1];
            if (isset($questions[$current])) reject('INVALID_EXAM', "Dòng $number: Q$current bị trùng.");
            $questions[$current] = ['question_no' => $current, 'question_text' => ''];
            $part = 'question_text'; continue;
        }
        if ($current === null) reject('INVALID_EXAM', "Dòng $number: thiếu mã Q.");
        if (preg_match('/^([ABCD])\.\s*(.*)$/u', $line, $m)) {
            $part = 'answer_' . $m[1];
            if (isset($questions[$current][$part])) reject('INVALID_EXAM', "Q$current: lựa chọn {$m[1]} bị trùng.");
            $questions[$current][$part] = $m[2];
        } else {
            $questions[$current][$part] .= ($questions[$current][$part] === '' ? '' : "\n") . $line;
        }
    }
    if (!$questions || count($questions) > 200 || count($keys) !== count($questions)) reject('INVALID_EXAM', 'Đề cần 1–200 câu và đủ đáp án tương ứng.');
    foreach ($questions as $id => &$q) {
        foreach (['question_text', 'answer_A', 'answer_B', 'answer_C', 'answer_D'] as $key) {
            if (trim($q[$key] ?? '') === '' || strlen($q[$key]) > 8000) reject('INVALID_EXAM', "Q$id: thiếu hoặc quá dài $key.");
        }
        if (!isset($keys[$id])) reject('INVALID_EXAM', "Q$id: thiếu đáp án đúng.");
        $q['correct_answer'] = $keys[$id];
    }
    unset($q);
    ksort($questions);
    return array_values($questions);
}

function session_row(PDO $db, int $id): array {
    $row = query($db, 'SELECT * FROM classroom_session WHERE id=?', [$id])->fetch();
    if (!$row) reject('SESSION_NOT_FOUND', 'Không tìm thấy buổi học.', 404);
    return $row;
}

function owned_session(PDO $db, int $id, int $teacherId): array {
    $row = session_row($db, $id);
    if ((int)$row['teacher_id'] !== $teacherId) reject('SESSION_NOT_FOUND', 'Không tìm thấy buổi học.', 404);
    return $row;
}

function report(PDO $db, int $sessionId): array {
    $questions = query($db, 'SELECT q.id,q.question_no,q.correct_answer FROM classroom_session s JOIN question q ON q.exam_id=s.exam_id WHERE s.id=? ORDER BY q.question_no', [$sessionId])->fetchAll();
    $rows = query($db, 'SELECT student_no,student_id,student_name FROM session_student WHERE session_id=? ORDER BY student_no,student_id', [$sessionId])->fetchAll();
    $answers = [];
    foreach (query($db, 'SELECT student_id,question_id,selected_answer FROM student_response WHERE session_id=?', [$sessionId]) as $response) {
        $answers[$response['student_id']][$response['question_id']] = $response['selected_answer'];
    }
    foreach ($rows as &$row) {
        $row += ['answers' => [], 'correct' => 0, 'total' => count($questions), 'percent' => 0];
        foreach ($questions as $q) {
            $answer = $answers[$row['student_id']][$q['id']] ?? '';
            $row['answers']['Q' . $q['question_no']] = $answer;
            if ($answer !== '' && $answer === $q['correct_answer']) $row['correct']++;
        }
        $row['percent'] = $row['total'] ? round($row['correct'] / $row['total'] * 100, 1) : 0;
    }
    return ['questions' => $questions, 'rows' => $rows];
}

function dashboard(PDO $db, int $id): array {
    $session = session_row($db, $id);
    $questions = query($db, 'SELECT q.id,q.question_no,q.question_text,q.answer_A,q.answer_B,q.answer_C,q.answer_D,sq.opened_at,sq.closed_at,q.correct_answer FROM question q LEFT JOIN session_question sq ON sq.question_id=q.id AND sq.session_id=? WHERE q.exam_id=? ORDER BY q.question_no', [$id, $session['exam_id']])->fetchAll();
    $question = null;
    foreach ($questions as $q) if ($q['id'] === $session['current_question_id']) $question = $q;
    $roster = query($db, 'SELECT s.*,IF(d.last_seen >= NOW() - INTERVAL 10 SECOND,1,0) AS online FROM session_student s LEFT JOIN device d ON d.device_uid=s.device_uid WHERE s.session_id=? ORDER BY s.student_no,s.student_id', [$id])->fetchAll();
    $logged = count(array_filter($roster, fn($s) => $s['device_uid'] !== null));
    $analysis = [];
    foreach ($questions as $q) if ($q['opened_at'] !== null) {
        $analysis[$q['id']] = ['question_id' => $q['id'], 'question_no' => $q['question_no'], 'distribution' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0], 'answered' => 0, 'waiting' => count($roster), 'correct' => 0, 'wrong' => 0, 'accuracy' => 0, 'wrong_rate' => 0];
    }
    foreach (query($db, 'SELECT r.question_id,r.selected_answer,COUNT(*) AS n,SUM(r.selected_answer=q.correct_answer) AS correct_count FROM student_response r JOIN question q ON q.id=r.question_id WHERE r.session_id=? GROUP BY r.question_id,r.selected_answer', [$id]) as $row) {
        $qid = $row['question_id'];
        if (!isset($analysis[$qid])) continue;
        $n = (int)$row['n'];
        $analysis[$qid]['distribution'][$row['selected_answer']] = $n;
        $analysis[$qid]['answered'] += $n;
        $analysis[$qid]['correct'] += (int)$row['correct_count'];
    }
    foreach ($analysis as &$item) {
        $item['waiting'] = count($roster) - $item['answered'];
        $item['wrong'] = $item['answered'] - $item['correct'];
        $item['accuracy'] = $item['answered'] ? round($item['correct'] / $item['answered'] * 100, 1) : 0;
        $item['wrong_rate'] = $item['answered'] ? round($item['wrong'] / $item['answered'] * 100, 1) : 0;
    }
    unset($item);
    $current = $question ? ($analysis[$question['id']] ?? null) : null;
    $liveResponses = $question ? query($db, 'SELECT student_id,selected_answer FROM student_response WHERE session_id=? AND question_id=?', [$id, $question['id']])->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    $autoRemaining = $session['auto_mode'] ? (int)ceil(query($db, 'SELECT GREATEST(0, TIMESTAMPDIFF(MICROSECOND, NOW(3), auto_deadline)) FROM classroom_session WHERE id=?', [$id])->fetchColumn() / 1e6) : null;
    return ['session' => $session, 'auto_remaining' => $autoRemaining, 'questions' => $questions, 'question' => $question, 'roster' => $roster,
        'analysis' => array_values($analysis), 'live_responses' => $liveResponses,
        'distribution' => $current['distribution'] ?? ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0],
        'answered' => $current['answered'] ?? 0, 'logged_in' => $logged,
        'waiting' => $current['waiting'] ?? count($roster),
        'online_devices' => (int)query($db, "SELECT COUNT(*) FROM device WHERE last_seen >= NOW() - INTERVAL 10 SECOND AND device_uid NOT LIKE 'BRIDGE-%'")->fetchColumn(),
        'report' => report($db, $id)];
}

// IPv4 the laptop uses on its current network (the Wi-Fi card on a hotspot), independent of the URL the page was opened with.
function laptop_ip(): string {
    if (!function_exists('shell_exec')) return '';
    $route = (string)shell_exec('/usr/sbin/ip -4 route get 1.1.1.1 2>/dev/null || /usr/bin/ip -4 route get 1.1.1.1 2>/dev/null');
    return preg_match('/\bsrc (\d+\.\d+\.\d+\.\d+)/', $route, $m) ? $m[1] : '';
}

// Opened through the internet link (Tailscale Funnel or any reverse proxy) rather than on the laptop's Wi-Fi.
// ESPTouch cannot reach a board from there, so the Wi-Fi setup tab is hidden and its API refused.
function remote_request(): bool {
    return isset($_SERVER['HTTP_TAILSCALE_FUNNEL_REQUEST']) || isset($_SERVER['HTTP_X_FORWARDED_FOR'])
        || preg_match('/\.ts\.net(:\d+)?$/i', $_SERVER['HTTP_HOST'] ?? '');
}

// Student link follows the laptop's current IP when setup saved a bare IPv4 URL; custom hostnames are kept.
// Through the internet link it is that link, so phones anywhere can open it.
function phone_url(string $publicUrl): string {
    if (remote_request() && preg_match('/^[a-z0-9.-]+$/i', $_SERVER['HTTP_HOST'] ?? '')) return "https://{$_SERVER['HTTP_HOST']}/clicker/phone.html";
    $ip = laptop_ip();
    if ($ip && preg_match('#^http://\d+\.\d+\.\d+\.\d+/#', $publicUrl)) $publicUrl = preg_replace('#^http://[^/]+/#', "http://$ip/", $publicUrl);
    return $publicUrl . 'phone.html';
}

const ROOM_IDLE_MINUTES = 30;
const ANSWER_GRACE_MS = 1500;

// INSERT that reports a duplicate key (a concurrent twin already wrote the row) as false instead of failing.
function insert_once(PDO $db, string $sql, array $params): bool {
    try { query($db, $sql, $params); return true; }
    catch (PDOException $error) { if (($error->errorInfo[1] ?? 0) === 1062) return false; throw $error; }
}

// Next unused question in exam order: after the current one first, then any earlier one still unused,
// so a room that started mid-exam never gets stuck. False when every question has been used.
function next_question_id(PDO $db, array $session): int|false {
    $currentNo = $session['current_question_id'] ? (int)query($db, 'SELECT question_no FROM question WHERE id=?', [$session['current_question_id']])->fetchColumn() : 0;
    $next = query($db, 'SELECT q.id FROM question q LEFT JOIN session_question sq ON sq.session_id=? AND sq.question_id=q.id WHERE q.exam_id=? AND sq.question_id IS NULL ORDER BY q.question_no<=?, q.question_no LIMIT 1', [$session['id'], $session['exam_id'], $currentNo])->fetchColumn();
    return $next ? (int)$next : false;
}

// New room with a snapshot of the class roster; it becomes the active room and ends the previous one,
// unless AUTO is still running it. Returns the new session id.
function create_room(PDO $db, $activeSid, string $title, string $class, int $examId, int $teacherId, int $autoSeconds): int {
    if ($activeSid) {
        $old = session_row($db, (int)$activeSid);
        if ((int)$old['teacher_id'] !== $teacherId && $old['state'] !== 'FINISHED') {
            reject('ROOM_BUSY', 'Giáo viên khác đang dùng thiết bị. Hãy đợi buổi học kết thúc.', 409);
        }
        if ($old['auto_mode']) reject('AUTO_RUNNING', 'Phòng hiện tại đang chạy AUTO. Bấm AUTO để dừng trước khi tạo phòng mới.', 409);
        if ($old['state'] !== 'FINISHED') finish_session($db, (int)$activeSid);
    }
    if (!query($db, 'SELECT id FROM exam WHERE id=? AND teacher_id=?', [$examId, $teacherId])->fetchColumn()) reject('EXAM_NOT_FOUND', 'Không tìm thấy bộ đề.', 404);
    if (!query($db, 'SELECT COUNT(*) FROM student WHERE class_id=? AND teacher_id=?', [$class, $teacherId])->fetchColumn()) reject('EMPTY_CLASS', 'Lớp chưa có sinh viên.');
    query($db, 'INSERT INTO classroom_session(teacher_id,title,class_id,exam_id,auto_seconds) VALUES(?,?,?,?,?)', [$teacherId, $title, $class, $examId, $autoSeconds]);
    $sid = (int)$db->lastInsertId();
    query($db, 'INSERT INTO session_student(session_id,student_id,student_no,student_name) SELECT ?,student_id,student_no,student_name FROM student WHERE class_id=? AND teacher_id=?', [$sid, $class, $teacherId]);
    query($db, 'UPDATE app_state SET session_id=? WHERE id=1', [$sid]);
    return $sid;
}

// Close every open question and end the room; AUTO stops with it.
function finish_session(PDO $db, int $sid): void {
    query($db, 'UPDATE session_question SET closed_at=NOW(3) WHERE session_id=? AND closed_at IS NULL', [$sid]);
    query($db, "UPDATE classroom_session SET state='FINISHED',auto_mode=0,auto_deadline=NULL WHERE id=?", [$sid]);
}

function open_question(PDO $db, int $sid, int $qid): void {
    touch_room($db, $sid);
    query($db, 'UPDATE session_question SET closed_at=NOW(3) WHERE session_id=? AND closed_at IS NULL', [$sid]);
    query($db, 'INSERT INTO session_question(session_id,question_id) VALUES(?,?)', [$sid, $qid]);
    query($db, "UPDATE classroom_session SET state='QUESTION_ACTIVE',current_question_id=? WHERE id=?", [$qid, $sid]);
}

// AUTO mode has no timer process: whichever request (device poll, dashboard, command) first sees an expired
// deadline advances the room, serialized by the app_state lock. Call inside a transaction.
// A forgotten room must not hold the devices: with no teacher command and no answer for ROOM_IDLE_MINUTES
// it ends like KẾT THÚC. AUTO rooms are skipped (they advance and end by themselves); keeping the dashboard
// open is not activity. Answers are read from student_response instead of touching the room row, so a class
// answering together never queues on one row lock. Called wherever auto_tick runs, inside a transaction.
function expire_idle_room(PDO $db, int $sid): bool {
    $stale = "state<>'FINISHED' AND auto_mode=0 AND COALESCE(last_activity, created_at) < NOW(3) - INTERVAL " . ROOM_IDLE_MINUTES . ' MINUTE';
    // Cheap check first (runs on every poll); the answer lookup only when the room already looks idle.
    if (!query($db, "SELECT $stale FROM classroom_session WHERE id=?", [$sid])->fetchColumn()) return false;
    $idle = "$stale AND NOT EXISTS (SELECT 1 FROM student_response r WHERE r.session_id=classroom_session.id AND r.saved_at >= NOW(3) - INTERVAL " . ROOM_IDLE_MINUTES . ' MINUTE)';
    if (!query($db, "SELECT $idle FROM classroom_session WHERE id=?", [$sid])->fetchColumn()) return false;
    query($db, 'SELECT session_id FROM app_state WHERE id=1 FOR UPDATE');
    if (!query($db, "SELECT 1 FROM classroom_session WHERE id=? AND $idle FOR UPDATE", [$sid])->fetchColumn()) return false;
    finish_session($db, $sid);
    return true;
}

function touch_room(PDO $db, int $sid): void {
    query($db, 'UPDATE classroom_session SET last_activity=NOW(3) WHERE id=?', [$sid]);
}

// Logging out ends the teacher's own active room so the devices are free for the next class.
function release_teacher_room(PDO $db, int $teacherId): void {
    $db->beginTransaction();
    $sid = query($db, 'SELECT session_id FROM app_state WHERE id=1 FOR UPDATE')->fetchColumn();
    if ($sid && query($db, "SELECT 1 FROM classroom_session WHERE id=? AND teacher_id=? AND state<>'FINISHED'", [$sid, $teacherId])->fetchColumn()) {
        finish_session($db, (int)$sid);
    }
    $db->commit();
}

function auto_tick(PDO $db, int $sid): void {
    if (expire_idle_room($db, $sid)) return;
    if (!query($db, 'SELECT auto_mode=1 AND auto_deadline<=NOW(3) FROM classroom_session WHERE id=?', [$sid])->fetchColumn()) return;
    query($db, 'SELECT session_id FROM app_state WHERE id=1 FOR UPDATE');
    $session = query($db, 'SELECT * FROM classroom_session WHERE id=? AND auto_mode=1 AND auto_deadline<=NOW(3) FOR UPDATE', [$sid])->fetch();
    if (!$session) return;
    $next = $session['state'] === 'FINISHED' ? false : next_question_id($db, $session);
    if ($next) {
        open_question($db, $sid, $next);
        query($db, 'UPDATE classroom_session SET auto_deadline=NOW(3) + INTERVAL ? SECOND WHERE id=?', [(int)$session['auto_seconds'], $sid]);
        return;
    }
    // Last question done: the room ends, same as pressing KẾT THÚC.
    finish_session($db, $sid);
}
