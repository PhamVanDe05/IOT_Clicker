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


// Six-digit code students type on the public page. Derived from the room id with the server key, so it needs
// no database column and cannot be guessed from the id.
function room_code(array $config, int $sid): string {
    $n = hexdec(substr(hash_hmac('sha256', "room:$sid", $config['phone_signing_key']), 0, 12)) % 1000000;
    return str_pad((string)$n, 6, '0', STR_PAD_LEFT);
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


// One question typed or imported on the web (text, A–D and the key). Returns the cleaned row, or why it is refused.
function clean_question(mixed $row): array|string {
    if (!is_array($row)) return 'Dòng không hợp lệ.';
    $q = [];
    foreach (['question_text' => 'câu hỏi', 'answer_A' => 'lựa chọn A', 'answer_B' => 'lựa chọn B', 'answer_C' => 'lựa chọn C', 'answer_D' => 'lựa chọn D'] as $key => $label) {
        $value = $row[$key] ?? '';
        if (!is_string($value) && !is_int($value) && !is_float($value)) return "Thiếu $label.";
        $value = trim(str_replace("\r\n", "\n", (string)$value));
        if ($value === '') return "Thiếu $label.";
        if (strlen($value) > 8000 || !preg_match('//u', $value)) return "Nội dung $label quá dài hoặc sai mã chữ.";
        $q[$key] = $value;
    }
    $key = strtoupper(trim((string)(is_scalar($row['correct_answer'] ?? null) ? $row['correct_answer'] : '')));
    if (!in_array($key, ['A', 'B', 'C', 'D'], true)) return 'Đáp án đúng phải là A, B, C hoặc D.';
    $q['correct_answer'] = $key;
    return $q;
}

// Self-registration needs the join_request table (tools/setup_db.sh). Until it exists the feature is simply off.
// Admin tools (tools/upgrade_ops.php): switches, activity log, password requests. Missing tables = defaults, no log.
function ops_ready(PDO $db): bool {
    static $ready = null;
    return $ready ??= (bool)$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='audit_log'")->fetchColumn();
}

// Feature switches the admin turns on/off on the web. All on unless an admin switched one off.
// Version of the web pages. Apache sends no cache headers for .html (and none can be added without root), so a browser may keep
// an old page after a deploy: the pages compare this with their own copy and reload once when it differs. Bump on every deploy.
const WEB_VERSION = '20261013-2';

const SETTINGS = ['allow_register' => 'Cho đăng ký tài khoản giáo viên mới', 'assistant' => 'Trợ lý AI', 'self_paced' => 'Hình thức "Sinh viên tự làm"', 'self_join' => 'Sinh viên tự đăng ký vào lớp', 'ble_sim' => 'Máy bấm mô phỏng trên điện thoại (BLE)'];
function setting(PDO $db, string $name): bool {
    static $cache = null;
    if ($cache === null) $cache = ops_ready($db) ? $db->query('SELECT name,value FROM app_setting')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    return ($cache[$name] ?? '1') === '1';
}

// One line in the activity log (who, what, from where). Never stores passwords or keys.
function audit(PDO $db, ?int $actorId, ?string $actorName, string $action, string $detail = ''): void {
    if (!ops_ready($db)) return;
    preg_match('/^.{0,500}/su', $detail, $cut);
    query($db, 'INSERT INTO audit_log(actor_id,actor_name,action,detail,ip) VALUES(?,?,?,?,?)',
        [$actorId, $actorName, $action, $cut[0], substr((string)($GLOBALS['clientIp'] ?? ''), 0, 64)]);
}

// Free-form admin settings (numbers): app_setting rows that are not on/off switches.
function setting_value(PDO $db, string $name, int $default): int {
    if (!ops_ready($db)) return $default;
    $v = query($db, 'SELECT value FROM app_setting WHERE name=?', [$name])->fetchColumn();
    return $v === false ? $default : (int)$v;
}
const AUDIT_DAYS = [30, 90, 180, 365];
// Housekeeping so the database stays small: activity log older than the admin's choice (default 90 days) and
// rate-limit counters older than one day. request_limit has no date, only a window number per bucket length.
const RATE_WINDOWS = ['ai_chat' => 600, 'answer' => 60, 'gateway_sync' => 1, 'login' => 60, 'login_source' => 60, 'oauth_callback' => 900,
    'oauth_start' => 900, 'password_change' => 900, 'password_request' => 3600, 'poll' => 1, 'register' => 60, 'register_source' => 60,
    'room_code' => 60, 'teacher_login' => 900, 'teacher_register' => 3600];
function housekeeping(PDO $db): array {
    $done = ['limits' => 0, 'log' => 0];
    foreach (RATE_WINDOWS as $bucket => $seconds)
        $done['limits'] += query($db, 'DELETE FROM request_limit WHERE bucket=? AND window_id < ?', [$bucket, intdiv(time() - 86400, $seconds)])->rowCount();
    if (ops_ready($db)) {
        $days = setting_value($db, 'audit_days', 90);
        if (in_array($days, AUDIT_DAYS, true)) $done['log'] = query($db, 'DELETE FROM audit_log WHERE at < NOW() - INTERVAL ' . $days . ' DAY')->rowCount();
        query($db, 'DELETE FROM password_request WHERE created_at < NOW() - INTERVAL 7 DAY');
    }
    $done['rooms'] = sweep_idle_rooms($db);
    return $done;
}

function random_password(): string { return str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT); }

// Profiles and open classes (tools/upgrade_profile.php). Missing tables = no profile step, file-only classes.
function profile_ready(PDO $db): bool {
    static $ready = null;
    return $ready ??= (bool)$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='teacher_profile'")->fetchColumn();
}
function my_profile(PDO $db, int $teacherId): ?array {
    if (!profile_ready($db)) return null;
    $p = query($db, 'SELECT full_name,gender,school,subject,grades,phone,bio,avatar IS NOT NULL AS has_avatar,UNIX_TIMESTAMP(updated_at) AS v FROM teacher_profile WHERE teacher_id=?', [$teacherId])->fetch();
    if (!$p) return null;
    $p['has_avatar'] = (bool)$p['has_avatar']; $p['google'] = (bool)query($db, 'SELECT google_id IS NOT NULL FROM teacher_account WHERE id=?', [$teacherId])->fetchColumn();
    return $p;
}
function open_class(PDO $db, int $teacherId, string $class): bool {
    return profile_ready($db) && (bool)query($db, 'SELECT 1 FROM teacher_class WHERE teacher_id=? AND class_id=?', [$teacherId, $class])->fetchColumn();
}
// Open classes are numbered by MSSV (ascending, shorter numbers first) every time someone is approved.
function renumber_by_mssv(PDO $db, int $teacherId, string $class, ?int $sid): void {
    $order = 'ORDER BY LENGTH(student_id), student_id';
    foreach (query($db, "SELECT student_id FROM student WHERE teacher_id=? AND class_id=? $order", [$teacherId, $class])->fetchAll(PDO::FETCH_COLUMN) as $i => $id)
        query($db, 'UPDATE student SET student_no=? WHERE teacher_id=? AND student_id=?', [$i + 1, $teacherId, $id]);
    if ($sid) foreach (query($db, "SELECT student_id FROM session_student WHERE session_id=? $order", [$sid])->fetchAll(PDO::FETCH_COLUMN) as $i => $id)
        query($db, 'UPDATE session_student SET student_no=? WHERE session_id=? AND student_id=?', [$i + 1, $sid, $id]);
}

// Admin page needs teacher_account.is_admin / disabled_at (tools/setup_db.sh); until then nobody is admin.
function admin_ready(PDO $db): bool {
    static $ready = null;
    return $ready ??= (bool)$db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='teacher_account' AND column_name='is_admin'")->fetchColumn();
}

// teacher_account.last_seen (tools/upgrade_admin.php): green "online" dot on the admin page.
function presence_ready(PDO $db): bool {
    static $ready = null;
    return $ready ??= (bool)$db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='teacher_account' AND column_name='last_seen'")->fetchColumn();
}
const ONLINE_SECONDS = 60;   // the dashboard asks the server every 1-2 s, so an open tab stays well inside this

// Removes a teacher and everything they own (rooms with their answers, question sets, class lists). Call inside a transaction.
function delete_teacher(PDO $db, int $teacherId): array {
    $count = fn(string $sql) => (int)query($db, $sql, [$teacherId])->fetchColumn();
    $done = ['rooms' => $count('SELECT COUNT(*) FROM classroom_session WHERE teacher_id=?'), 'exams' => $count('SELECT COUNT(*) FROM exam WHERE teacher_id=?'),
        'students' => $count('SELECT COUNT(*) FROM student WHERE teacher_id=?')];
    foreach (query($db, 'SELECT id FROM classroom_session WHERE teacher_id=?', [$teacherId])->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        if (join_ready($db)) query($db, 'DELETE FROM join_request WHERE session_id=?', [$sid]);
        foreach (['student_response', 'session_question', 'session_student'] as $table) query($db, "DELETE FROM $table WHERE session_id=?", [$sid]);
        query($db, 'DELETE FROM classroom_session WHERE id=?', [$sid]);
    }
    query($db, 'DELETE q FROM question q JOIN exam e ON e.id=q.exam_id WHERE e.teacher_id=?', [$teacherId]);
    query($db, 'DELETE FROM exam WHERE teacher_id=?', [$teacherId]);
    query($db, 'DELETE FROM student WHERE teacher_id=?', [$teacherId]);
    query($db, 'DELETE FROM teacher_account WHERE id=?', [$teacherId]);
    return $done;
}

// "Sinh viên tự làm" needs classroom_session.pacing (tools/setup_db.sh); without it every room is teacher-paced.
function pacing_ready(PDO $db): bool {
    static $ready = null;
    return $ready ??= (bool)$db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='classroom_session' AND column_name='pacing'")->fetchColumn();
}

function join_ready(PDO $db): bool {
    static $ready = null;
    return $ready ??= (bool)$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='join_request'")->fetchColumn();
}

function owned_exam(PDO $db, int $examId, int $teacherId): array {
    $exam = query($db, 'SELECT id,title FROM exam WHERE id=? AND teacher_id=?', [$examId, $teacherId])->fetch();
    if (!$exam) reject('EXAM_NOT_FOUND', 'Không tìm thấy bộ đề.', 404);
    return $exam;
}

function insert_question(PDO $db, int $examId, array $q): int {
    $no = 1 + (int)query($db, 'SELECT COALESCE(MAX(question_no),0) FROM question WHERE exam_id=?', [$examId])->fetchColumn();
    query($db, 'INSERT INTO question(exam_id,question_no,question_text,answer_A,answer_B,answer_C,answer_D,correct_answer) VALUES(?,?,?,?,?,?,?,?)',
        [$examId, $no, $q['question_text'], $q['answer_A'], $q['answer_B'], $q['answer_C'], $q['answer_D'], $q['correct_answer']]);
    return (int)$db->lastInsertId();
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
        $row['score10'] = $row['total'] ? round($row['correct'] / $row['total'] * 10, 2) : 0;   // thang điểm 10
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

// New room with a snapshot of the class roster. A teacher runs one room at a time (their previous running room ends,
// unless AUTO is still running it); other teachers' rooms are not touched. Returns the new session id.
function create_room(PDO $db, string $title, string $class, int $examId, int $teacherId, int $autoSeconds, string $mode): int {
    $old = query($db, "SELECT id,auto_mode FROM classroom_session WHERE teacher_id=? AND state<>'FINISHED'", [$teacherId])->fetchAll();
    if (array_filter($old, fn($r) => (int)$r['auto_mode'])) reject('AUTO_RUNNING', 'Phòng hiện tại đang chạy AUTO. Bấm AUTO để dừng trước khi tạo phòng mới.', 409);
    foreach ($old as $r) finish_session($db, (int)$r['id']);
    if (!query($db, 'SELECT id FROM exam WHERE id=? AND teacher_id=?', [$examId, $teacherId])->fetchColumn()) reject('EXAM_NOT_FOUND', 'Không tìm thấy bộ đề.', 404);
    if (!query($db, 'SELECT COUNT(*) FROM student WHERE class_id=? AND teacher_id=?', [$class, $teacherId])->fetchColumn() && !open_class($db, $teacherId, $class)) reject('EMPTY_CLASS', 'Lớp chưa có sinh viên.');
    query($db, 'INSERT INTO classroom_session(teacher_id,title,class_id,exam_id,auto_seconds) VALUES(?,?,?,?,?)', [$teacherId, $title, $class, $examId, $autoSeconds]);
    $sid = (int)$db->lastInsertId();
    if (rooms_ready($db)) query($db, 'UPDATE classroom_session SET mode=? WHERE id=?', [$mode, $sid]);
    query($db, 'INSERT INTO session_student(session_id,student_id,student_no,student_name) SELECT ?,student_id,student_no,student_name FROM student WHERE class_id=? AND teacher_id=?', [$sid, $class, $teacherId]);
    return $sid;
}

// Close every open question and end the room; AUTO stops with it.
function finish_session(PDO $db, int $sid): void {
    query($db, 'UPDATE session_question SET closed_at=NOW(3) WHERE session_id=? AND closed_at IS NULL', [$sid]);
    query($db, "UPDATE classroom_session SET state='FINISHED',auto_mode=0,auto_deadline=NULL,last_activity=NOW(3) WHERE id=?", [$sid]);
}

function open_question(PDO $db, int $sid, int $qid): void {
    touch_room($db, $sid);
    query($db, 'UPDATE session_question SET closed_at=NOW(3) WHERE session_id=? AND closed_at IS NULL', [$sid]);
    query($db, 'INSERT INTO session_question(session_id,question_id) VALUES(?,?)', [$sid, $qid]);
    query($db, "UPDATE classroom_session SET state='QUESTION_ACTIVE',current_question_id=? WHERE id=?", [$qid, $sid]);
}

// AUTO mode has no timer process: whichever request (device poll, dashboard, command) first sees an expired
// deadline advances the room, serialized by the server lock (the app_state row). Call inside a transaction.
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

// Locking or deleting a teacher ends their running room. Call inside a transaction.
function release_room_of(PDO $db, int $teacherId): void {
    query($db, 'SELECT session_id FROM app_state WHERE id=1 FOR UPDATE');
    foreach (query($db, "SELECT id FROM classroom_session WHERE teacher_id=? AND state<>'FINISHED'", [$teacherId])->fetchAll(PDO::FETCH_COLUMN) as $sid) finish_session($db, (int)$sid);
}

// Logging out ends the teacher's own running room.
function release_teacher_room(PDO $db, int $teacherId): void {
    $db->beginTransaction(); release_room_of($db, $teacherId); $db->commit();
}

/* ---------- several rooms at once ----------
   A room runs until FINISHED; each teacher has at most one running room, any number of teachers at a time. The
   app_state row is only the server-wide lock that keeps commands and answers in order (and the mode of the rooms
   while classroom_session.mode is missing: tools/upgrade_rooms.php). */
function rooms_ready(PDO $db): bool {
    static $ready = null;
    return $ready ??= (bool)$db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='classroom_session' AND column_name='mode'")->fetchColumn();
}
function room_mode(PDO $db, ?array $room): string {
    if ($room && rooms_ready($db) && isset($room['mode'])) return $room['mode'];
    return query($db, 'SELECT mode FROM app_state WHERE id=1')->fetchColumn() ?: 'wifi';
}
// Rooms nobody uses any more end by themselves (the expire_idle_room rule), so "running" stays true for every list.
function sweep_idle_rooms(PDO $db): int {
    $n = 0;
    $idle = $db->query("SELECT id FROM classroom_session WHERE state<>'FINISHED' AND auto_mode=0 AND COALESCE(last_activity, created_at) < NOW(3) - INTERVAL " . ROOM_IDLE_MINUTES . ' MINUTE')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($idle as $sid) {
        $own = !$db->inTransaction(); if ($own) $db->beginTransaction();
        if (expire_idle_room($db, (int)$sid)) $n++;
        if ($own) $db->commit();
    }
    return $n;
}
// The teacher's running room, or null.
function running_room(PDO $db, int $teacherId): ?array {
    return query($db, "SELECT * FROM classroom_session WHERE teacher_id=? AND state<>'FINISHED' ORDER BY id DESC LIMIT 1", [$teacherId])->fetch() ?: null;
}
// The room the teacher's devices follow (USB gateway, the Thiết bị tab): the running one, else the one that ended last
// in the past hour so the tags still show "En".
function teacher_room(PDO $db, int $teacherId): ?array {
    return running_room($db, $teacherId) ?: (query($db, "SELECT * FROM classroom_session WHERE teacher_id=? AND COALESCE(last_activity, created_at) >= NOW() - INTERVAL 1 HOUR ORDER BY id DESC LIMIT 1", [$teacherId])->fetch() ?: null);
}
// Which room a phone or simulated tag is in: the one whose 6-digit code it typed (sent with every request), else the
// room it already joined. Rooms that ended in the past few hours still answer, so the phone can show the score.
function device_room(PDO $db, array $config, string $uid, mixed $code): ?array {
    $recent = "(c.state<>'FINISHED' OR COALESCE(c.last_activity, c.created_at) >= NOW() - INTERVAL 6 HOUR)";
    if (is_string($code) && preg_match('/^[0-9]{6}$/D', $code)) {
        foreach (query($db, "SELECT c.* FROM classroom_session c WHERE $recent ORDER BY c.state='FINISHED', c.id DESC")->fetchAll() as $room)
            if (hash_equals(room_code($config, (int)$room['id']), $code)) return $room;
    }
    return query($db, "SELECT c.* FROM session_student s JOIN classroom_session c ON c.id=s.session_id WHERE s.device_uid=? AND $recent ORDER BY c.state='FINISHED', c.id DESC LIMIT 1", [$uid])->fetch() ?: null;
}

// ---- Self-paced rooms (classroom_session.pacing = 'self') ----
// Bắt đầu opens every question at once. Each student then walks the exam in order: a question lasts auto_seconds
// from the moment the previous one was answered (or timed out); no answer in time = skipped. Nothing per student is
// stored: the walk is replayed from student_response.saved_at, so the server, phone and teacher agree on it.
function self_paced(array $session): bool { return ($session['pacing'] ?? 'teacher') === 'self'; }

function self_plan(PDO $db, array $session): array {
    $qs = query($db, 'SELECT q.id,q.question_no,UNIX_TIMESTAMP(sq.opened_at) AS opened FROM question q JOIN session_question sq ON sq.question_id=q.id AND sq.session_id=?
        WHERE q.exam_id=? ORDER BY q.question_no', [$session['id'], $session['exam_id']])->fetchAll();
    return ['qs' => $qs, 'start' => $qs ? min(array_map(fn($q) => (float)$q['opened'], $qs)) : 0.0,
        'now' => (float)query($db, 'SELECT UNIX_TIMESTAMP(NOW(3))')->fetchColumn(), 'seconds' => max(1, (int)$session['auto_seconds'])];
}

// $answers: question_id => [answer, saved_at]. Returns the current question (null when finished) and its deadline.
function self_walk(array $plan, array $answers): array {
    $t = $plan['start']; $now = $plan['now']; $done = 0; $late = [];
    foreach ($plan['qs'] as $i => $q) {
        if (isset($answers[$q['id']])) { $t = max($t, (float)$answers[$q['id']][1]); $done++; continue; }
        $deadline = $t + $plan['seconds'];
        if ($now < $deadline) return ['question' => $q, 'index' => $i + 1, 'deadline' => $deadline, 'remaining' => $deadline - $now, 'answered' => $done, 'total' => count($plan['qs']), 'late' => $late];
        $late[(int)$q['id']] = $deadline;   // timed out; a press sent just before the deadline still counts (grace)
        $t = $deadline;
    }
    return ['question' => null, 'index' => count($plan['qs']), 'deadline' => $t, 'remaining' => 0.0, 'answered' => $done, 'total' => count($plan['qs']), 'late' => $late];
}

function self_answers(PDO $db, int $sid, ?string $studentId = null): array {
    $rows = query($db, 'SELECT student_id,question_id,selected_answer,UNIX_TIMESTAMP(saved_at) AS t FROM student_response WHERE session_id=?' . ($studentId === null ? '' : ' AND student_id=?'),
        $studentId === null ? [$sid] : [$sid, $studentId])->fetchAll();
    $by = [];
    foreach ($rows as $r) $by[$r['student_id']][(int)$r['question_id']] = [$r['selected_answer'], (float)$r['t']];
    return $by;
}

// Ends a self-paced room when every student who joined has finished, or when even the slowest walk is over.
function self_tick(PDO $db, array $session): void {
    if (!self_paced($session) || $session['state'] !== 'QUESTION_ACTIVE') return;
    $plan = self_plan($db, $session);
    if (!$plan['qs']) return;
    $over = $plan['now'] > $plan['start'] + count($plan['qs']) * $plan['seconds'] + ANSWER_GRACE_MS / 1000 + 1;
    if (!$over) {
        $joined = query($db, 'SELECT student_id FROM session_student WHERE session_id=? AND device_uid IS NOT NULL', [$session['id']])->fetchAll(PDO::FETCH_COLUMN);
        if (!$joined) return;
        $answers = self_answers($db, (int)$session['id']);
        foreach ($joined as $student) if (self_walk($plan, $answers[$student] ?? [])['question'] !== null) return;
    }
    query($db, 'SELECT session_id FROM app_state WHERE id=1 FOR UPDATE');
    finish_session($db, (int)$session['id']);
}

function auto_tick(PDO $db, int $sid): void {
    if (expire_idle_room($db, $sid)) return;
    $row = query($db, 'SELECT * FROM classroom_session WHERE id=?', [$sid])->fetch();
    if ($row && self_paced($row)) { self_tick($db, $row); return; }
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

// Google/Facebook sign-in: find the teacher for a verified provider profile, link by verified e-mail, or create one.
// $profile = ['id' => provider user id, 'email' => ?string (verified only), 'name' => display name].
function oauth_account(PDO $db, string $provider, array $profile): array {
    $column = ['google' => 'google_id', 'facebook' => 'facebook_id'][$provider] ?? reject('OAUTH_PROVIDER', 'Nhà cung cấp không hợp lệ.');
    $id = (string)($profile['id'] ?? '');
    if (!preg_match('/^[0-9A-Za-z_-]{1,64}$/D', $id)) reject('OAUTH_PROFILE', 'Không đọc được tài khoản từ nhà cung cấp.');
    $email = isset($profile['email']) && filter_var($profile['email'], FILTER_VALIDATE_EMAIL) ? strtolower($profile['email']) : null;
    $account = query($db, "SELECT id,username FROM teacher_account WHERE $column=?", [$id])->fetch();
    if ($account) return $account;
    if ($email) {
        $account = query($db, "SELECT id,username FROM teacher_account WHERE email=? AND $column IS NULL", [$email])->fetch();
        if ($account) { query($db, "UPDATE teacher_account SET $column=? WHERE id=?", [$id, $account['id']]); return $account; }
    }
    if (!setting($db, 'allow_register')) reject('REGISTER_OFF', 'Đang tạm dừng đăng ký tài khoản mới. Hãy liên hệ quản trị viên.', 403);
    // New teacher. Names are usernames here, so make the name unique.
    $base = trim(preg_replace('/\s+/u', ' ', (string)($profile['name'] ?? ''))) ?: ($email ? strstr($email, '@', true) : 'Giáo viên');
    preg_match('/^.{0,70}/su', $base, $cut);
    $base = $cut[0];
    for ($n = 1; $n < 100; $n++) {
        $name = $n === 1 ? $base : "$base $n";
        try {
            query($db, "INSERT INTO teacher_account(username,password_hash,email,$column) VALUES(?,?,?,?)",
                [$name, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $email, $id]);
            return ['id' => (int)$db->lastInsertId(), 'username' => $name];
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? 0) !== 1062) throw $error;
            $taken = query($db, "SELECT id,username FROM teacher_account WHERE $column=?", [$id])->fetch();
            if ($taken) return $taken;  // the same person signed in twice at once
        }
    }
    reject('OAUTH_NAME', 'Không tạo được tên đăng nhập, hãy đăng ký bằng mật khẩu.', 409);
}
