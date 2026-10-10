<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function end_teacher_session(): void {
    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $cookie['path'],
        'secure' => $cookie['secure'], 'httponly' => $cookie['httponly'], 'samesite' => $cookie['samesite']]);
    session_destroy();
}

function start_teacher_session(int $id, string $username): never {
    session_regenerate_id(true);
    $_SESSION = ['teacher_id' => $id, 'teacher_username' => $username,
        'csrf' => bin2hex(random_bytes(24)), 'created_at' => time(), 'last_activity' => time()];
    reply(['ok' => true, 'csrf' => $_SESSION['csrf'], 'username' => $username]);
}

function delete_session(PDO $db, int $sid): void {
    query($db, 'UPDATE app_state SET session_id=NULL WHERE session_id=?', [$sid]);
    foreach (['student_response', 'session_question', 'session_student'] as $table) {
        query($db, "DELETE FROM $table WHERE session_id=?", [$sid]);
    }
    query($db, 'DELETE FROM classroom_session WHERE id=?', [$sid]);
}

function delete_student(PDO $db, int $teacherId, string $studentId): void {
    $sessions = query($db, 'SELECT id FROM classroom_session WHERE teacher_id=?', [$teacherId])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($sessions as $sid) {
        foreach (['student_response', 'session_student'] as $table) {
            query($db, "DELETE FROM $table WHERE session_id=? AND student_id=?", [$sid, $studentId]);
        }
    }
    query($db, 'DELETE FROM student WHERE teacher_id=? AND student_id=?', [$teacherId, $studentId]);
}

$db = null;
try {
    $config = require (getenv('CLICKER_CONFIG') ?: '/home/pham-van-de/Desktop/IOT/clicker/.local/prod-config.php');
    if (public_host($config)) {
        if (!secure_request($config)) reject('HTTPS_REQUIRED', 'Trang công khai chỉ hỗ trợ HTTPS.', 403);
        header('Strict-Transport-Security: max-age=31536000');
    }
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['GET', 'POST'], true)) reject('METHOD', 'Chỉ hỗ trợ GET/POST.', 405);
    $data = [];
    if ($method === 'POST') {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) reject('TOO_LARGE', 'Dữ liệu tối đa 1 MB.', 413);
        $raw = file_get_contents('php://input', false, null, 0, 1048577);
        if (strlen($raw) > 1048576) reject('TOO_LARGE', 'Dữ liệu tối đa 1 MB.', 413);
        $data = json_decode($raw, true);
        if (!is_array($data) || !str_starts_with(ltrim($raw), '{')) reject('JSON', 'JSON không hợp lệ.');
    }
    $action = $method === 'POST' ? field($data, 'action', 40) : ($_GET['action'] ?? 'auth');
    $db = new PDO($config['dsn'], $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $deviceAction = in_array($action, ['poll', 'login', 'answer', 'bridge_sync'], true);
    $gatewayAction = in_array($action, ['gateway_sync', 'gateway_login', 'gateway_answer'], true);
    if ($deviceAction) {
        if ($method !== 'POST') reject('METHOD', 'Thiết bị phải dùng POST.', 405);
        $uid = defined('CLICKER_PHONE_UID') ? CLICKER_PHONE_UID : strtoupper(field($data, 'device_uid', 64));
        if (!preg_match('/^[A-Z0-9:_-]{3,64}$/D', $uid)) reject('INVALID_UID', 'Device UID không hợp lệ.');
        if (!defined('CLICKER_PHONE_UID')) authorize_device($db, $uid, $action, $data, $_SERVER['HTTP_X_DEVICE_KEY'] ?? '');
        if ($action === 'poll') rate_limit($db, 'poll', $uid, 12, 1);
        elseif ($action === 'bridge_sync') rate_limit($db, 'bridge_sync', $uid, 5, 1);
        elseif ($action === 'login') {
            rate_limit($db, 'login', $uid, 20, 60);
            rate_limit($db, 'login_source', client_source($config), 200, 60);
        } elseif ($action === 'answer') rate_limit($db, 'answer', $uid, 90, 60);
    } else {
        session_name('clicker_teacher');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => secure_request($config), 'path' => '/clicker/']);
        session_start();
        if ($action === 'teacher_register' && $method === 'POST') {
            rate_limit($db, 'teacher_register', client_source($config), 5, 3600);
            $username = field($data, 'username', 80);
            $password = field($data, 'password', 8);
            if (strcasecmp($username, 'Pham Van De') === 0) reject('USERNAME_USED', 'Tên đăng nhập đã được dùng.', 409);
            if (!preg_match('/^[0-9]{8}$/D', $password)) reject('INVALID_PASSWORD', 'Mật khẩu cần đúng 8 chữ số.');
            try {
                query($db, 'INSERT INTO teacher_account(username,password_hash) VALUES(?,?)',
                    [$username, password_hash($password, PASSWORD_DEFAULT)]);
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? 0) === 1062) reject('USERNAME_USED', 'Tên đăng nhập đã được dùng.', 409);
                throw $error;
            }
            start_teacher_session((int)$db->lastInsertId(), $username);
        }
        if ($action === 'teacher_login' && $method === 'POST') {
            rate_limit($db, 'teacher_login', client_source($config), 8, 900);
            $username = field($data, 'username', 80);
            $password = field($data, 'password', 8);
            if (!preg_match('/^[0-9]{8}$/D', $password)) {
                reject('UNAUTHORIZED', 'Tên đăng nhập hoặc mật khẩu không đúng.', 401);
            }
            if (strcasecmp($username, 'Pham Van De') === 0 && !local_host_request($config)) {
                reject('LOCAL_ONLY', 'Tài khoản chủ chỉ dùng trên laptop qua clicker.local hoặc localhost.', 403);
            }
            $account = query($db, 'SELECT id,username,password_hash FROM teacher_account WHERE username=?', [$username])->fetch();
            if (!$account || !password_verify($password, $account['password_hash'])) reject('UNAUTHORIZED', 'Tên đăng nhập hoặc mật khẩu không đúng.', 401);
            query($db, 'DELETE FROM request_limit WHERE bucket=? AND actor_hash=?', ['teacher_login', hash('sha256', client_source($config))]);
            start_teacher_session((int)$account['id'], $account['username']);
        }
        if (!empty($_SESSION['teacher_id']) && (time() - ($_SESSION['last_activity'] ?? 0) > 3600
            || time() - ($_SESSION['created_at'] ?? 0) > 28800)) end_teacher_session();
        if (!empty($_SESSION['teacher_id']) && strcasecmp($_SESSION['teacher_username'] ?? '', 'Pham Van De') === 0
            && !local_host_request($config)) {
            end_teacher_session();
            reject('LOCAL_ONLY', 'Tài khoản chủ chỉ dùng trên laptop.', 403);
        }
        if ($action === 'auth') reply(['ok' => true, 'authenticated' => !empty($_SESSION['teacher_id']),
            'csrf' => !empty($_SESSION['teacher_id']) ? $_SESSION['csrf'] : null,
            'username' => $_SESSION['teacher_username'] ?? null]);
        if (empty($_SESSION['teacher_id'])) reject('UNAUTHORIZED', 'Hãy đăng nhập giáo viên.', 401);
        if ($method === 'POST' && (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) reject('CSRF', 'Phiên thao tác không hợp lệ.', 403);
        if ($action === 'logout' && $method === 'POST') {
            release_teacher_room($db, (int)$_SESSION['teacher_id']);
            end_teacher_session();
            reply(['ok' => true]);
        }
        if ($method === 'POST') $_SESSION['last_activity'] = time();
        $teacherId = (int)$_SESSION['teacher_id'];
        session_write_close();
    }
    if ($gatewayAction) {
        if ($method !== 'POST') reject('METHOD', 'Gateway phải dùng POST.', 405);
        $bridgeUid = strtoupper(field($data, 'bridge_uid', 64));
        if (!preg_match('/^BRIDGE-[A-F0-9]{12}$/D', $bridgeUid)) reject('INVALID_UID', 'Gateway không hợp lệ.');
        $owner = query($db, 'SELECT s.teacher_id FROM app_state a JOIN classroom_session s ON s.id=a.session_id WHERE a.id=1')->fetchColumn();
        if ($owner !== false && (int)$owner !== $teacherId) reject('ROOM_BUSY', 'Giáo viên khác đang dùng thiết bị.', 409);
        if ($action !== 'gateway_sync') {
            $sid = positive_id($data['session_id'] ?? null);
            $active = query($db, 'SELECT session_id FROM app_state WHERE id=1')->fetchColumn();
            if ((int)$active !== $sid) reject('SESSION_FINISHED', 'Buổi học không còn hoạt động.', 409);
            $uid = strtoupper(field($data, 'device_uid', 64));
            if (!preg_match('/^NRF-[A-F0-9]{12}$/D', $uid)) reject('INVALID_UID', 'Tag không hợp lệ.');
        } else {
            $uid = $bridgeUid;
        }
        $action = substr($action, 8);
        $action = $action === 'sync' ? 'bridge_sync' : $action;
        $deviceAction = true;
        if ($action === 'bridge_sync') rate_limit($db, 'bridge_sync', $uid, 5, 1);
        elseif ($action === 'login') {
            rate_limit($db, 'login', $uid, 20, 60);
            rate_limit($db, 'login_source', client_source($config), 200, 60);
        } else rate_limit($db, 'answer', $uid, 90, 60);
    }
    if (in_array($action, ['wifi_info', 'wifi_provision'], true) && remote_request()) {
        reject('REMOTE_SETUP', 'Cấp Wi-Fi qua web chỉ dùng được khi máy chủ ở cùng Wi-Fi với ESP32. Ở xa: giữ BACK 5 giây trên ESP32.', 403);
    }
    if ($action === 'wifi_info' && $method === 'GET') {
        $ssid = function_exists('shell_exec') ? trim((string)shell_exec('/usr/sbin/iwgetid -r 2>/dev/null')) : '';
        $bssid = function_exists('shell_exec') ? trim((string)shell_exec('/usr/sbin/iwgetid -a -r 2>/dev/null')) : '';
        reply(['ok' => true, 'ssid' => $ssid, 'bssid' => $bssid, 'server_ip' => laptop_ip()]);
    }
    if ($action === 'wifi_provision' && $method === 'POST') {
        $ssid = $data['ssid'] ?? null;
        $password = $data['password'] ?? null;
        $bssid = field($data, 'bssid', 17);
        // The laptop's own Wi-Fi address wins; the form value is only a fallback when detection fails.
        $serverIp = laptop_ip() ?: trim(is_string($data['server_ip'] ?? null) ? $data['server_ip'] : '');
        if (!is_string($ssid) || strlen($ssid) < 1 || strlen($ssid) > 32 || !preg_match('//u', $ssid)
            || !is_string($password) || strlen($password) < 8 || strlen($password) > 63
            || preg_match('/[\x00-\x1f\x7f]/', $ssid . $password)) reject('INVALID_WIFI', 'SSID hoặc mật khẩu Wi-Fi không hợp lệ.');
        if (!preg_match('/^(?:[a-f0-9]{2}:){5}[a-f0-9]{2}$/iD', $bssid)) reject('INVALID_BSSID', 'BSSID phải dạng AA:BB:CC:DD:EE:FF.');
        if (!filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || str_starts_with($serverIp, '127.') || str_starts_with($serverIp, '169.254.') || $serverIp === '0.0.0.0') {
            reject('INVALID_IP', 'Không tìm được IP Wi-Fi của laptop. Kiểm tra laptop đã vào Wi-Fi, hoặc nhập IP trong mục Nâng cao.');
        }
        require_once __DIR__ . '/esptouch.php';
        set_time_limit(45);
        try { $found = esptouch_send($ssid, $password, $bssid, $serverIp); }
        catch (RuntimeException $error) { reject('WIFI_SEND', $error->getMessage(), 503); }
        reply(['ok' => true, 'devices' => $found, 'count' => count($found)]);
    }
    if ($deviceAction) query($db, 'INSERT INTO device(device_uid,last_seen) VALUES(?,NOW(3)) ON DUPLICATE KEY UPDATE last_seen=NOW(3)', [$uid]);
    $mode = query($db, 'SELECT mode FROM app_state WHERE id=1')->fetchColumn() ?: 'wifi';
    if ($deviceAction && $action !== 'bridge_sync') {
        // Only the devices of the selected mode may join; the others see why they are paused.
        $isTag = str_starts_with($uid, 'NRF-');
        if ($isTag && $mode !== 'ble') reject('MODE_WIFI', 'Lớp đang dùng chế độ Wi-Fi (ESP32 thử nghiệm).', 409);
        if (!$isTag && $mode === 'ble') reject('MODE_BLE', 'Lớp đang dùng chế độ BLE (nRF52840). Thiết bị Wi-Fi tạm dừng.', 409);
    }
    if ($action === 'bridge_sync' && !defined('CLICKER_PHONE_UID')) {
        // BLE bridge on this server: one request refreshes every tag it hears and returns their login/answer state.
        $tags = $data['tags'] ?? null;
        if (!is_array($tags) || count($tags) > 200) reject('INVALID_TAGS', 'Danh sách tag tối đa 200 UID.');
        $db->beginTransaction();
        $sid = query($db, 'SELECT session_id FROM app_state WHERE id=1')->fetchColumn();
        if ($sid) auto_tick($db, (int)$sid);
        $session = $sid ? session_row($db, (int)$sid) : null;
        if ($gatewayAction && $session && (int)$session['teacher_id'] !== $teacherId) reject('ROOM_BUSY', 'Giáo viên khác đang dùng thiết bị.', 409);
        $question = null;
        if ($session && $session['state'] === 'QUESTION_ACTIVE') $question = query($db, 'SELECT id AS question_id,question_no FROM question WHERE id=?', [$session['current_question_id']])->fetch();
        $status = [];
        foreach ($tags as $tag) {
            $tag = is_string($tag) ? strtoupper(trim($tag)) : '';
            if (!preg_match('/^[A-Z0-9:_-]{3,64}$/D', $tag)) reject('INVALID_UID', 'Device UID không hợp lệ.');
            query($db, 'INSERT INTO device(device_uid,last_seen) VALUES(?,NOW(3)) ON DUPLICATE KEY UPDATE last_seen=NOW(3)', [$tag]);
            $student = $sid ? query($db, 'SELECT student_id FROM session_student WHERE session_id=? AND device_uid=?', [$sid, $tag])->fetchColumn() : false;
            $answer = $student !== false && $question ? query($db, 'SELECT selected_answer FROM student_response WHERE session_id=? AND device_uid=? AND question_id=?', [$sid, $tag, $question['question_id']])->fetchColumn() : false;
            $status[$tag] = ['logged' => $student !== false, 'answer' => $answer ?: null];
        }
        $db->commit();
        reply(['ok' => true, 'mode' => $mode, 'session_id' => $sid ? (int)$sid : null, 'state' => $session['state'] ?? 'WAIT_SESSION', 'question' => $question ?: null, 'tags' => (object)$status]);
    }
    if ($action === 'poll') {
        $db->beginTransaction();
        $sid = query($db, 'SELECT session_id FROM app_state WHERE id=1')->fetchColumn();
        if ($sid) auto_tick($db, (int)$sid);
        $session = $sid ? session_row($db, (int)$sid) : null;
        $finished = $session && $session['state'] === 'FINISHED';
        $login = $sid && !$finished ? query($db, 'SELECT student_id,student_name FROM session_student WHERE session_id=? AND device_uid=?', [$sid, $uid])->fetch() : false;
        $question = null;
        if ($login && $session['state'] === 'QUESTION_ACTIVE') {
            $question = query($db, 'SELECT id AS question_id,question_no FROM question WHERE id=?', [$session['current_question_id']])->fetch();
            $question['accepted_answer'] = query($db, 'SELECT selected_answer FROM student_response WHERE session_id=? AND device_uid=? AND question_id=?', [$sid, $uid, $question['question_id']])->fetchColumn() ?: null;
        }
        $db->commit();
        reply(['ok' => true, 'session_id' => $sid && !$finished ? (int)$sid : null, 'state' => $session['state'] ?? 'WAIT_SESSION', 'student_id' => $login['student_id'] ?? null, 'student_name' => $login['student_name'] ?? null, 'question' => $question]);
    }
    if ($method === 'GET') {
        $ownedActive = query($db, 'SELECT s.id FROM app_state a JOIN classroom_session s ON s.id=a.session_id WHERE a.id=1 AND s.teacher_id=?', [$teacherId])->fetchColumn();
        $bridge = $ownedActive ? query($db, "SELECT device_uid,last_seen,IF(last_seen >= NOW() - INTERVAL 5 SECOND,1,0) AS online FROM device WHERE device_uid LIKE 'BRIDGE-%' AND last_seen IS NOT NULL ORDER BY last_seen DESC LIMIT 1")->fetch() ?: null : null;
        $devices = $ownedActive ? query($db, 'SELECT d.device_uid,d.last_seen,IF(d.last_seen >= NOW() - INTERVAL 10 SECOND,1,0) AS online FROM device d JOIN session_student s ON s.device_uid=d.device_uid WHERE s.session_id=? ORDER BY d.last_seen DESC LIMIT 200', [$ownedActive])->fetchAll() : [];
        if ($action === 'status') reply(['ok' => true, 'mode' => $mode, 'bridge' => $bridge,
            'online' => array_column(array_filter($devices, fn($row) => (int)$row['online'] === 1), 'device_uid')]);
        if ($action === 'bootstrap') reply(['ok' => true, 'mode' => $mode, 'remote' => remote_request(),
            'bridge' => $bridge,
            'phone_url' => phone_url($config['public_url'] ?? '/clicker/'),
            'classes' => query($db, 'SELECT class_id,COUNT(*) AS count FROM student WHERE teacher_id=? GROUP BY class_id ORDER BY class_id', [$teacherId])->fetchAll(),
            'students' => query($db, 'SELECT student_id,student_no,student_name,class_id FROM student WHERE teacher_id=? ORDER BY class_id,student_no,student_id', [$teacherId])->fetchAll(),
            'exams' => query($db, 'SELECT e.id,e.title,COUNT(q.id) AS count FROM exam e JOIN question q ON q.exam_id=e.id WHERE e.teacher_id=? GROUP BY e.id ORDER BY e.id DESC', [$teacherId])->fetchAll(),
            'sessions' => query($db, 'SELECT s.*,e.title AS exam_title FROM classroom_session s JOIN exam e ON e.id=s.exam_id WHERE s.teacher_id=? ORDER BY s.id DESC LIMIT 200', [$teacherId])->fetchAll(),
            'devices' => $devices]);
        $sid = positive_id($_GET['session_id'] ?? null);
        owned_session($db, $sid, $teacherId);
        // One consistent snapshot for statistics and responses while devices submit.
        $db->beginTransaction();
        if ($action === 'dashboard') { auto_tick($db, $sid); $result = dashboard($db, $sid); $db->commit(); reply(['ok' => true, 'mode' => $mode] + $result); }
        if ($action === 'export') {
            $report = report($db, $sid); $db->commit();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="clicker-session-' . $sid . '.csv"');
            $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(['STT', 'MSSV', 'Họ tên'], array_map(fn($q) => 'Q' . $q['question_no'], $report['questions']), ['Đúng/Tổng', 'Tỷ lệ đúng (%)']));
            foreach ($report['rows'] as $row) {
                $name = preg_match('/^[\s]*[=+@\-]/u', $row['student_name']) ? "'" . $row['student_name'] : $row['student_name'];
                fputcsv($out, array_merge([$row['student_no'], $row['student_id'], $name], array_values($row['answers']), [$row['correct'] . '/' . $row['total'], $row['percent']]));
            }
            fclose($out); exit;
        }
        reject('ACTION', 'Lệnh không hợp lệ.', 404);
    }

    // Limit: one room. Teacher commands take the room lock exclusively; device login/answer share it, so a
    // class pressing OK together is stored in parallel yet never races a NEXT/KẾT THÚC. An expired AUTO step is
    // applied first in its own transaction: it needs the exclusive lock, and upgrading a shared lock held by
    // several devices would deadlock; a separate transaction also keeps the answer's snapshot after the lock.
    $shared = in_array($action, ['login', 'answer'], true);
    if ($shared && ($tickSid = query($db, 'SELECT session_id FROM app_state WHERE id=1')->fetchColumn())) {
        $db->beginTransaction(); auto_tick($db, (int)$tickSid); $db->commit();
    }
    $db->beginTransaction();
    $activeSid = query($db, 'SELECT session_id FROM app_state WHERE id=1 ' . ($shared ? 'LOCK IN SHARE MODE' : 'FOR UPDATE'))->fetchColumn();
    if (!$shared && $activeSid) auto_tick($db, (int)$activeSid);
    $result = [];
    if ($action === 'import_students') {
        $rows = $data['students'] ?? null;
        if (!is_array($rows) || count($rows) < 1 || count($rows) > 1000) reject('INVALID_ROSTER', 'Danh sách cần 1–1000 sinh viên.');
        $seen = []; $clean = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) reject('INVALID_ROSTER', 'Dòng sinh viên không hợp lệ.');
            $id = field($row, 'student_id', 20);
            if (!preg_match('/^[0-9]{1,20}$/D', $id) || isset($seen[$id])) reject('INVALID_ROSTER', 'MSSV sai hoặc trùng ở dòng ' . ($i + 2));
            $seen[$id] = true;
            $clean[] = [$id, positive_id($row['student_no'] ?? ($i + 1)), field($row, 'student_name', 120), field($row, 'class_id', 60)];
        }
        foreach ($clean as $row) query($db, 'INSERT INTO student(teacher_id,student_id,student_no,student_name,class_id) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE student_no=VALUES(student_no),student_name=VALUES(student_name),class_id=VALUES(class_id)', [$teacherId, ...$row]);
        $result['imported'] = count($clean);
    } elseif ($action === 'import_exam') {
        $questions = parse_exam(field($data, 'text', 500000));
        query($db, 'INSERT INTO exam(teacher_id,title) VALUES(?,?)', [$teacherId, field($data, 'title')]);
        $examId = (int)$db->lastInsertId();
        foreach ($questions as $q) {
            query($db, 'INSERT INTO question(exam_id,question_no,question_text,answer_A,answer_B,answer_C,answer_D,correct_answer) VALUES(?,?,?,?,?,?,?,?)', [$examId, $q['question_no'], $q['question_text'], $q['answer_A'], $q['answer_B'], $q['answer_C'], $q['answer_D'], $q['correct_answer']]);
        }
        $result = ['exam_id' => $examId, 'imported' => count($questions)];
    } elseif ($action === 'create_session') {
        $class = field($data, 'class_id', 60); $examId = positive_id($data['exam_id'] ?? null);
        $title = isset($data['title']) ? field($data, 'title') : "Kiểm tra lớp $class";
        $seconds = $data['auto_seconds'] ?? 30;
        if (is_bool($seconds) || !is_scalar($seconds) || !preg_match('/^[1-9][0-9]{0,3}$/D', (string)$seconds) || (int)$seconds > 3600) reject('INVALID_SECONDS', 'Thời gian mỗi câu phải từ 1 đến 3600 giây.');
        $result['session_id'] = create_room($db, $activeSid, $title, $class, $examId, $teacherId, (int)$seconds);
    } elseif ($action === 'retest_session') {
        // Kiểm tra lại a finished room: a new round with the same class and exam; the old results stay as they were.
        $old = owned_session($db, positive_id($data['session_id'] ?? null), $teacherId);
        if ($old['state'] !== 'FINISHED') reject('SESSION_NOT_FINISHED', 'Phòng này chưa kết thúc.', 409);
        $round = 1 + (int)query($db, 'SELECT COUNT(*) FROM classroom_session WHERE teacher_id=? AND class_id=? AND exam_id=?', [$teacherId, $old['class_id'], $old['exam_id']])->fetchColumn();
        $base = preg_replace('/ · lần \d+$/u', '', $old['title'] ?: "Kiểm tra lớp {$old['class_id']}");
        preg_match('/^.{0,108}/su', $base, $cut);  // title column is 120 characters; no mbstring on this PHP
        $sid = create_room($db, $activeSid, $cut[0] . " · lần $round", $old['class_id'], (int)$old['exam_id'], $teacherId, (int)$old['auto_seconds']);
        query($db, "UPDATE classroom_session SET state='LOGIN_OPEN' WHERE id=?", [$sid]);
        $result['session_id'] = $sid;
    } elseif ($action === 'set_mode') {
        $newMode = field($data, 'mode', 4);
        if (!in_array($newMode, ['wifi', 'ble'], true)) reject('INVALID_MODE', 'Chế độ phải là wifi hoặc ble.');
        $activeRoom = $activeSid ? session_row($db, (int)$activeSid) : null;
        $state = $activeRoom['state'] ?? null;
        if ($activeRoom && (int)$activeRoom['teacher_id'] !== $teacherId && $state !== 'FINISHED') {
            reject('ROOM_BUSY', 'Giáo viên khác đang dùng thiết bị.', 409);
        }
        if ($newMode !== $mode && in_array($state, ['LOGIN_OPEN', 'QUESTION_ACTIVE'], true)) reject('MODE_BUSY', 'Hãy đóng đăng nhập và câu hỏi đang mở trước khi đổi chế độ.', 409);
        query($db, 'UPDATE app_state SET mode=? WHERE id=1', [$newMode]);
        $result['mode'] = $newMode;
    } elseif (in_array($action, ['remove_class', 'remove_student', 'remove_exam', 'remove_session'], true)) {
        if (($data['confirmed'] ?? null) !== true) reject('CONFIRM_REQUIRED', 'Hãy xác nhận thao tác xóa.');
        if ($action === 'remove_class') {
            $class = field($data, 'class_id', 60);
            if (!query($db, 'SELECT student_id FROM student WHERE teacher_id=? AND class_id=? LIMIT 1', [$teacherId, $class])->fetchColumn()) reject('CLASS_NOT_FOUND', 'Không tìm thấy lớp.', 404);
            foreach (query($db, 'SELECT id FROM classroom_session WHERE teacher_id=? AND class_id=?', [$teacherId, $class])->fetchAll(PDO::FETCH_COLUMN) as $sid) delete_session($db, (int)$sid);
            foreach (query($db, 'SELECT student_id FROM student WHERE teacher_id=? AND class_id=?', [$teacherId, $class])->fetchAll(PDO::FETCH_COLUMN) as $studentId) delete_student($db, $teacherId, $studentId);
        } elseif ($action === 'remove_student') {
            $studentId = field($data, 'student_id', 20);
            if (!query($db, 'SELECT student_id FROM student WHERE teacher_id=? AND student_id=?', [$teacherId, $studentId])->fetchColumn()) reject('STUDENT_NOT_FOUND', 'Không tìm thấy sinh viên.', 404);
            delete_student($db, $teacherId, $studentId);
        } elseif ($action === 'remove_exam') {
            $examId = positive_id($data['exam_id'] ?? null);
            if (!query($db, 'SELECT id FROM exam WHERE id=? AND teacher_id=?', [$examId, $teacherId])->fetchColumn()) reject('EXAM_NOT_FOUND', 'Không tìm thấy bộ đề.', 404);
            foreach (query($db, 'SELECT id FROM classroom_session WHERE exam_id=? AND teacher_id=?', [$examId, $teacherId])->fetchAll(PDO::FETCH_COLUMN) as $sid) delete_session($db, (int)$sid);
            query($db, 'DELETE FROM question WHERE exam_id=?', [$examId]);
            query($db, 'DELETE FROM exam WHERE id=?', [$examId]);
        } else {
            $sid = positive_id($data['session_id'] ?? null);
            owned_session($db, $sid, $teacherId);
            delete_session($db, $sid);
        }
        $result = ['mode' => 'deleted'];
    } else {
        $sid = positive_id($data['session_id'] ?? null);
        $session = $deviceAction ? session_row($db, $sid) : owned_session($db, $sid, $teacherId);
        if ($action === 'login') {
            $studentId = field($data, 'student_id', 20);
            $taken = fn(string $code): never => reject($code, $code === 'DEVICE_IN_USE' ? 'Thiết bị đã gắn với MSSV khác trong buổi này.' : 'MSSV đã đăng nhập trên thiết bị khác.', 409);
            $mine = query($db, 'SELECT student_id FROM session_student WHERE session_id=? AND device_uid=?', [$sid, $uid])->fetchColumn();
            if ($mine !== false && $mine !== $studentId) $taken('DEVICE_IN_USE');
            if ($mine === false) {
                if ((int)$activeSid !== $sid || $session['state'] !== 'LOGIN_OPEN') reject('LOGIN_CLOSED', 'Chưa mở hoặc đã đóng đăng nhập.', 409);
                $seat = query($db, 'SELECT device_uid FROM session_student WHERE session_id=? AND student_id=?', [$sid, $studentId])->fetch();
                if (!$seat) reject('STUDENT_NOT_FOUND', 'MSSV không thuộc lớp trong buổi này.', 404);
                if ($seat['device_uid'] !== null) $taken('STUDENT_IN_USE');
                // Claim the free seat. Racing requests are settled by the row lock (one device per MSSV) and the unique
                // (session_id, device_uid) key (one MSSV per device); the loser reads the committed owner with a locking read.
                try { $claimed = query($db, 'UPDATE session_student SET device_uid=? WHERE session_id=? AND student_id=? AND device_uid IS NULL', [$uid, $sid, $studentId])->rowCount() === 1; }
                catch (PDOException $error) { if (($error->errorInfo[1] ?? 0) !== 1062) throw $error; $claimed = false; }
                if (!$claimed) {
                    $owner = query($db, 'SELECT student_id FROM session_student WHERE session_id=? AND device_uid=? FOR UPDATE', [$sid, $uid])->fetchColumn();
                    if ($owner !== $studentId) $taken($owner === false ? 'STUDENT_IN_USE' : 'DEVICE_IN_USE');
                }
            }
            $result = ['type' => 'LOGIN_OK', 'session_id' => $sid, 'student_id' => $studentId];
        } elseif ($action === 'answer') {
            $qid = positive_id($data['question_id'] ?? null); $answer = field($data, 'answer', 1);
            if (!in_array($answer, ['A', 'B', 'C', 'D'], true)) reject('INVALID_ANSWER', 'Đáp án phải thuộc A/B/C/D.');
            $studentId = query($db, 'SELECT student_id FROM session_student WHERE session_id=? AND device_uid=?', [$sid, $uid])->fetchColumn();
            if ($studentId === false) reject('LOGIN_REQUIRED', 'Thiết bị chưa đăng nhập.', 403);
            $existing = query($db, 'SELECT selected_answer FROM student_response WHERE session_id=? AND student_id=? AND question_id=?', [$sid, $studentId, $qid])->fetchColumn();
            // Retry an already committed response even after the question/session closes.
            if ($existing !== false && $existing !== $answer) reject('ANSWER_LOCKED', 'Đáp án đã lưu, không thể đổi.', 409);
            if ($existing === false) {
                $open = (int)$activeSid === $sid && $session['state'] === 'QUESTION_ACTIVE' && (int)$session['current_question_id'] === $qid;
                // Grace window: a press sent just before NEXT/AUTO/KẾT THÚC closed the question still counts.
                $grace = !$open && query($db, 'SELECT 1 FROM session_question WHERE session_id=? AND question_id=? AND closed_at >= NOW(3) - INTERVAL ' . ANSWER_GRACE_MS . '000 MICROSECOND', [$sid, $qid])->fetchColumn();
                if (!$open && !$grace) reject('QUESTION_CLOSED', 'Câu hỏi chưa mở hoặc đã đóng.', 409);
                if (!insert_once($db, 'INSERT INTO student_response(session_id,student_id,question_id,selected_answer,device_uid) VALUES(?,?,?,?,?)', [$sid, $studentId, $qid, $answer, $uid])) {
                    $existing = query($db, 'SELECT selected_answer FROM student_response WHERE session_id=? AND student_id=? AND question_id=?', [$sid, $studentId, $qid])->fetchColumn();
                    if ($existing !== $answer) reject('ANSWER_LOCKED', 'Đáp án đã lưu, không thể đổi.', 409);
                }
            }
            $result = ['type' => 'ANSWER_ACK', 'session_id' => $sid, 'question_id' => $qid, 'answer' => $answer, 'duplicate' => $existing !== false];
        } else {
            if ($session['state'] === 'FINISHED' || (int)$activeSid !== $sid) reject('SESSION_FINISHED', 'Buổi học đã kết thúc.', 409);
            if ($session['auto_mode'] && in_array($action, ['open_login', 'close_login', 'start_question', 'next_question', 'auto_start'], true)) reject('AUTO_RUNNING', 'Đang chạy AUTO. Bấm AUTO lần nữa để dừng trước.', 409);
            if ($action === 'open_login' || $action === 'close_login') {
                if ($session['state'] === 'QUESTION_ACTIVE') reject('QUESTION_ACTIVE', 'Hãy đóng câu đang mở trước.', 409);
                query($db, 'UPDATE classroom_session SET state=? WHERE id=?', [$action === 'open_login' ? 'LOGIN_OPEN' : 'READY', $sid]);
            } elseif ($action === 'start_question') {
                // Bắt đầu: login closes and the first unused question opens, in exam order (never the preview dropdown).
                if ($session['state'] === 'QUESTION_ACTIVE') reject('QUESTION_ACTIVE', 'Câu hỏi đã đang mở.', 409);
                $next = next_question_id($db, $session);
                if (!$next) reject('NO_NEXT_QUESTION', 'Đã dùng hết câu hỏi của bộ đề.', 409);
                open_question($db, $sid, $next);
                $result['question_id'] = $next;
            } elseif ($action === 'next_question') {
                if ($session['state'] !== 'QUESTION_ACTIVE') reject('NO_ACTIVE_QUESTION', 'Hãy bấm Bắt đầu trước.', 409);
                $next = next_question_id($db, $session);
                if (!$next) reject('NO_NEXT_QUESTION', 'Không còn câu tiếp theo chưa sử dụng.', 409);
                open_question($db, $sid, $next);
                $result['question_id'] = $next;
            } elseif ($action === 'auto_start') {
                // Keep an open question and give it a full slot; otherwise open the next unused one.
                if ($session['state'] !== 'QUESTION_ACTIVE') {
                    $next = next_question_id($db, $session);
                    if (!$next) reject('NO_NEXT_QUESTION', 'Không còn câu tiếp theo chưa sử dụng.', 409);
                    open_question($db, $sid, $next);
                }
                query($db, 'UPDATE classroom_session SET auto_mode=1,auto_deadline=NOW(3) + INTERVAL ? SECOND WHERE id=?', [(int)$session['auto_seconds'], $sid]);
            } elseif ($action === 'auto_stop') {
                // The open question stays open for manual control.
                query($db, 'UPDATE classroom_session SET auto_mode=0,auto_deadline=NULL WHERE id=?', [$sid]);
            } elseif (in_array($action, ['close_question', 'finish_session'], true)) {
                // KẾT THÚC: close the open question and end the room.
                finish_session($db, $sid);
            } else reject('ACTION', 'Lệnh không hợp lệ.', 404);
            // Teacher commands on the room reset its idle timer (answers are counted from student_response).
            touch_room($db, $sid);
        }
    }
    $db->commit();
    reply(['ok' => true] + $result);
} catch (ApiError $error) {
    if ($db?->inTransaction()) $db->rollBack();
    reply(['ok' => false, 'code' => $error->errorCode, 'error' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    if ($db?->inTransaction()) $db->rollBack();
    error_log('Clicker: ' . $error->getMessage());
    reply(['ok' => false, 'code' => 'SERVER_ERROR', 'error' => 'Server chưa xử lý được yêu cầu. Hãy thử lại.'], 500);
}
