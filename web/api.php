<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/ai.php';

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
    if (join_ready($db)) query($db, 'DELETE FROM join_request WHERE session_id=?', [$sid]);
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
    $GLOBALS['clientIp'] = client_source($config);
    // Devices: phones and laptops (phone-api.php, which defines CLICKER_PHONE_UID) and, in a BLE room, the Student Tags
    // relayed by the teacher's USB gateway (gateway_* below). ESP32 boards and the Python bridge are no longer used.
    $deviceAction = in_array($action, ['poll', 'login', 'answer', 'register'], true);
    if (($deviceAction || $action === 'bridge_sync') && !defined('CLICKER_PHONE_UID')) reject('DEVICE_REMOVED', 'ESP32 và bridge BLE không còn được hỗ trợ: dùng điện thoại hoặc Gateway USB.', 410);
    $deviceRoom = null;
    if ($deviceAction) {
        if ($method !== 'POST') reject('METHOD', 'Thiết bị phải dùng POST.', 405);
        $uid = CLICKER_PHONE_UID;
        // Tag mô phỏng: phone.html in BLE mode answers as a Student Tag of its own (NRF-SIM-…), so a BLE class can be
        // tried without boards. Same login / answer rules as a real tag; the admin can switch it off.
        if (($data['as_tag'] ?? false) === true) {
            if (!setting($db, 'ble_sim')) reject('SIM_OFF', 'Quản trị viên đã tắt máy bấm mô phỏng.', 403);
            $uid = 'NRF-SIM-' . substr(strtoupper(hash('sha256', 'tag:' . CLICKER_PHONE_UID)), 0, 12);
        }
        if ($action === 'poll') rate_limit($db, 'poll', $uid, 12, 1);
        elseif ($action === 'login') {
            rate_limit($db, 'login', $uid, 20, 60);
            rate_limit($db, 'login_source', client_source($config), 200, 60);
        } elseif ($action === 'answer') rate_limit($db, 'answer', $uid, 90, 60);
        elseif ($action === 'register') {
            rate_limit($db, 'register', $uid, 10, 60);
            rate_limit($db, 'register_source', client_source($config), 120, 60);
        }
    } else {
        session_name('clicker_teacher');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => secure_request($config), 'path' => '/clicker/']);
        session_start();
        if ($action === 'teacher_register' && $method === 'POST') {
            if (!setting($db, 'allow_register')) reject('REGISTER_OFF', 'Đang tạm dừng đăng ký tài khoản mới. Hãy liên hệ quản trị viên.', 403);
            rate_limit($db, 'teacher_register', client_source($config), 5, 3600);
            $username = field($data, 'username', 80);
            $password = field($data, 'password', 8);
            if (!preg_match('/^[0-9]{8}$/D', $password)) reject('INVALID_PASSWORD', 'Mật khẩu cần đúng 8 chữ số.');
            try {
                query($db, 'INSERT INTO teacher_account(username,password_hash) VALUES(?,?)',
                    [$username, password_hash($password, PASSWORD_DEFAULT)]);
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? 0) === 1062) reject('USERNAME_USED', 'Tên đăng nhập đã được dùng.', 409);
                throw $error;
            }
            $newId = (int)$db->lastInsertId();
            audit($db, $newId, $username, 'register', 'Đăng ký bằng mật khẩu');
            start_teacher_session($newId, $username);
        }
        // ---- Quên mật khẩu: request → admin bấm Cấp phép (chuông) → this browser sets a new password and signs in.
        // The request is tied to this browser's session, so only the person who asked can set the password.
        // Rejected = row deleted; approved = handled_at set (valid 30 minutes).
        if ($action === 'password_request' && $method === 'POST') {
            rate_limit($db, 'password_request', client_source($config), 5, 3600);
            if (!ops_ready($db)) reject('TOOLS_OFF', 'Chức năng quên mật khẩu chưa bật trên máy chủ. Hãy liên hệ quản trị viên.', 409);
            $who = field($data, 'username', 190);
            $row = query($db, 'SELECT id,username FROM teacher_account WHERE username=? OR LOWER(email)=LOWER(?) LIMIT 1', [$who, $who])->fetch();
            // Unknown names get the same waiting screen (nobody can probe which accounts exist); it never gets approved.
            $_SESSION['pw_request'] = 0; $_SESSION['pw_name'] = $who;
            if ($row) {
                query($db, 'INSERT INTO password_request(teacher_id,ip) VALUES(?,?)', [$row['id'], client_source($config)]);
                $_SESSION['pw_request'] = (int)$db->lastInsertId();
                audit($db, (int)$row['id'], $row['username'], 'password_request', 'Yêu cầu đặt lại mật khẩu');
            }
            reply(['ok' => true]);
        }
        if ($action === 'password_status' && $method === 'GET') {
            if (!isset($_SESSION['pw_request'])) reply(['ok' => true, 'state' => 'none']);
            $req = $_SESSION['pw_request'] ? query($db, 'SELECT handled_at,handled_at >= NOW() - INTERVAL 30 MINUTE AS fresh FROM password_request WHERE id=?', [$_SESSION['pw_request']])->fetch() : ['handled_at' => null, 'fresh' => 0];
            $state = $req === false ? 'rejected' : ($req['handled_at'] === null ? 'waiting' : ($req['fresh'] ? 'approved' : 'expired'));
            if ($state === 'rejected' || $state === 'expired') unset($_SESSION['pw_request'], $_SESSION['pw_name']);
            reply(['ok' => true, 'state' => $state, 'name' => $_SESSION['pw_name'] ?? null]);
        }
        if ($action === 'password_cancel' && $method === 'POST') {
            if (!empty($_SESSION['pw_request'])) query($db, 'DELETE FROM password_request WHERE id=? AND handled_at IS NULL', [$_SESSION['pw_request']]);
            unset($_SESSION['pw_request'], $_SESSION['pw_name']);
            reply(['ok' => true]);
        }
        if ($action === 'password_set' && $method === 'POST') {
            $rid = (int)($_SESSION['pw_request'] ?? 0);
            $req = $rid ? query($db, 'SELECT p.teacher_id,t.username FROM password_request p JOIN teacher_account t ON t.id=p.teacher_id WHERE p.id=? AND p.handled_at IS NOT NULL AND p.handled_at >= NOW() - INTERVAL 30 MINUTE', [$rid])->fetch() : false;
            if (!$req) reject('NOT_APPROVED', 'Yêu cầu chưa được cấp phép hoặc đã hết hạn.', 403);
            $password = field($data, 'password', 8);
            if (!preg_match('/^[0-9]{8}$/D', $password)) reject('INVALID_PASSWORD', 'Mật khẩu cần đúng 8 chữ số.');
            if (($data['password2'] ?? null) !== $password) reject('PASSWORD_MISMATCH', 'Hai lần nhập mật khẩu không giống nhau.');
            query($db, 'UPDATE teacher_account SET password_hash=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $req['teacher_id']]);
            query($db, 'DELETE FROM password_request WHERE teacher_id=?', [$req['teacher_id']]);
            audit($db, (int)$req['teacher_id'], $req['username'], 'password_set', 'Đặt mật khẩu mới sau khi được cấp phép');
            unset($_SESSION['pw_request'], $_SESSION['pw_name']);
            if (admin_ready($db) && query($db, 'SELECT disabled_at IS NOT NULL FROM teacher_account WHERE id=?', [$req['teacher_id']])->fetchColumn()) reject('ACCOUNT_DISABLED', 'Tài khoản đã bị khoá. Hãy liên hệ quản trị viên.', 403);
            start_teacher_session((int)$req['teacher_id'], $req['username']);
        }
        if ($action === 'teacher_login' && $method === 'POST') {
            rate_limit($db, 'teacher_login', client_source($config), 8, 900);
            $username = field($data, 'username', 80);
            $password = field($data, 'password', 8);
            if (!preg_match('/^[0-9]{8}$/D', $password)) {
                reject('UNAUTHORIZED', 'Tên đăng nhập hoặc mật khẩu không đúng.', 401);
            }
            $account = query($db, 'SELECT id,username,password_hash FROM teacher_account WHERE username=?', [$username])->fetch();
            if (!$account || !password_verify($password, $account['password_hash'])) {
                audit($db, $account ? (int)$account['id'] : null, $username, 'login_fail', 'Sai mật khẩu');
                reject('UNAUTHORIZED', 'Tên đăng nhập hoặc mật khẩu không đúng.', 401);
            }
            if (admin_ready($db) && query($db, 'SELECT disabled_at IS NOT NULL FROM teacher_account WHERE id=?', [$account['id']])->fetchColumn()) reject('ACCOUNT_DISABLED', 'Tài khoản đã bị khoá. Hãy liên hệ quản trị viên.', 403);
            query($db, 'DELETE FROM request_limit WHERE bucket=? AND actor_hash=?', ['teacher_login', hash('sha256', client_source($config))]);
            audit($db, (int)$account['id'], $account['username'], 'login', 'Đăng nhập bằng mật khẩu');
            start_teacher_session((int)$account['id'], $account['username']);
        }
        if (!empty($_SESSION['teacher_id']) && (time() - ($_SESSION['last_activity'] ?? 0) > 3600
            || time() - ($_SESSION['created_at'] ?? 0) > 28800)) end_teacher_session();
        // Every request re-reads the account: a deleted or locked teacher is signed out at once (Google sign-ins too).
        $me = null;
        if (!empty($_SESSION['teacher_id'])) {
            $me = query($db, 'SELECT id' . (admin_ready($db) ? ',is_admin,disabled_at' : ',0 AS is_admin,NULL AS disabled_at') . ' FROM teacher_account WHERE id=?', [$_SESSION['teacher_id']])->fetch();
            if (!$me || $me['disabled_at'] !== null) { end_teacher_session(); reject($me ? 'ACCOUNT_DISABLED' : 'UNAUTHORIZED', $me ? 'Tài khoản đã bị khoá. Hãy liên hệ quản trị viên.' : 'Hãy đăng nhập giáo viên.', $me ? 403 : 401); }
        }
        $isAdmin = (bool)($me['is_admin'] ?? false);
        // Online presence: one write at most every 20 s per teacher.
        if ($me && presence_ready($db)) query($db, 'UPDATE teacher_account SET last_seen=NOW() WHERE id=? AND (last_seen IS NULL OR last_seen < NOW() - INTERVAL 20 SECOND)', [$me['id']]);
        if ($action === 'auth') reply(['ok' => true, 'web' => WEB_VERSION, 'authenticated' => !empty($_SESSION['teacher_id']),
            'csrf' => !empty($_SESSION['teacher_id']) ? $_SESSION['csrf'] : null,
            'username' => $_SESSION['teacher_username'] ?? null, 'is_admin' => $isAdmin, 'view_as' => $_SESSION['view_as_name'] ?? null,
            'profile' => $me ? my_profile($db, (int)$me['id']) : null, 'profile_ready' => profile_ready($db)]);
        if (empty($_SESSION['teacher_id'])) reject('UNAUTHORIZED', 'Hãy đăng nhập giáo viên.', 401);
        if ($method === 'POST' && (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) reject('CSRF', 'Phiên thao tác không hợp lệ.', 403);
        if (str_starts_with($action, 'admin_')) {
            if (!$isAdmin) reject('NOT_ADMIN', 'Chỉ quản trị viên mới dùng được mục này.', 403);
            $self = (int)$_SESSION['teacher_id'];
            if ($action === 'admin_overview' && $method === 'GET') {
                $n = fn(string $t) => (int)$db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
                $seen = presence_ready($db) ? 't.last_seen,IF(t.last_seen >= NOW() - INTERVAL ' . ONLINE_SECONDS . ' SECOND,1,0) AS online,' : 'NULL AS last_seen,0 AS online,';
                $teachers = $db->query("SELECT t.id,t.username,t.email,t.google_id IS NOT NULL AS google,t.is_admin,t.disabled_at,t.created_at,$seen
                    (SELECT COUNT(DISTINCT s.class_id) FROM student s WHERE s.teacher_id=t.id) AS classes,
                    (SELECT COUNT(*) FROM student s WHERE s.teacher_id=t.id) AS students,
                    (SELECT COUNT(*) FROM exam e WHERE e.teacher_id=t.id) AS exams,
                    (SELECT COUNT(*) FROM classroom_session c WHERE c.teacher_id=t.id) AS rooms,
                    (SELECT MAX(COALESCE(c.last_activity,c.created_at)) FROM classroom_session c WHERE c.teacher_id=t.id) AS last_used
                    FROM teacher_account t ORDER BY t.is_admin DESC, last_used IS NULL, last_used DESC, t.id")->fetchAll();
                // Per teacher: each class (students, rooms, last room) and each question set (questions, rooms).
                $classes = $db->query("SELECT s.teacher_id,s.class_id,COUNT(*) AS students,
                    (SELECT COUNT(*) FROM classroom_session c WHERE c.teacher_id=s.teacher_id AND c.class_id=s.class_id) AS rooms,
                    (SELECT MAX(c.created_at) FROM classroom_session c WHERE c.teacher_id=s.teacher_id AND c.class_id=s.class_id) AS last_room
                    FROM student s GROUP BY s.teacher_id,s.class_id ORDER BY s.class_id")->fetchAll();
                $exams = $db->query("SELECT e.teacher_id,e.id,e.title,(SELECT COUNT(*) FROM question q WHERE q.exam_id=e.id) AS questions,
                    (SELECT COUNT(*) FROM classroom_session c WHERE c.exam_id=e.id) AS rooms FROM exam e ORDER BY e.id DESC")->fetchAll();
                $profiles = profile_ready($db) ? $db->query('SELECT teacher_id,full_name,gender,school,subject,grades,phone,bio,avatar IS NOT NULL AS has_avatar,UNIX_TIMESTAMP(updated_at) AS v FROM teacher_profile')->fetchAll(PDO::FETCH_UNIQUE) : [];
                foreach ($teachers as &$t) {
                    $t['profile'] = $profiles[$t['id']] ?? null;
                    $t['class_list'] = array_values(array_filter($classes, fn($c) => (int)$c['teacher_id'] === (int)$t['id']));
                    $t['exam_list'] = array_values(array_filter($exams, fn($e) => (int)$e['teacher_id'] === (int)$t['id']));
                }
                unset($t);
                sweep_idle_rooms($db);
                $rooms = $db->query("SELECT c.id,c.title,c.class_id,c.state,c.created_at,t.username," . (rooms_ready($db) ? 'c.mode' : "'wifi' AS mode") . ",
                    (SELECT COUNT(*) FROM session_student s WHERE s.session_id=c.id AND s.device_uid IS NOT NULL) AS joined
                    FROM classroom_session c JOIN teacher_account t ON t.id=c.teacher_id WHERE c.state<>'FINISHED' ORDER BY c.id DESC")->fetchAll();
                $recent = $db->query("SELECT c.id,c.title,c.class_id,c.state,c.created_at,t.username,(SELECT COUNT(*) FROM student_response r WHERE r.session_id=c.id) AS answers
                    FROM classroom_session c JOIN teacher_account t ON t.id=c.teacher_id ORDER BY c.id DESC LIMIT 12")->fetchAll();
                $week = $db->query("SELECT DATE(saved_at) AS d, COUNT(*) AS n FROM student_response WHERE saved_at >= CURDATE() - INTERVAL 13 DAY GROUP BY DATE(saved_at) ORDER BY d")->fetchAll();
                reply(['ok' => true, 'me' => $self, 'totals' => ['teachers' => $n('teacher_account'), 'students' => $n('student'), 'exams' => $n('exam'),
                    'questions' => $n('question'), 'rooms' => $n('classroom_session'), 'answers' => $n('student_response')],
                    'teachers' => $teachers, 'active_rooms' => $rooms, 'recent' => $recent, 'answers_by_day' => $week]);
            }
            $myName = $_SESSION['teacher_username'] ?? '';
            if ($method === 'GET' && $action === 'admin_extra') {
                // Settings, server health, password requests: the admin page asks for these with the overview.
                $settings = []; foreach (SETTINGS as $k => $label) $settings[] = ['name' => $k, 'label' => $label, 'on' => setting($db, $k)];
                $sizes = $db->query("SELECT table_name,table_rows FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('audit_log','request_limit')")->fetchAll(PDO::FETCH_KEY_PAIR);
                $requests = ops_ready($db) ? $db->query('SELECT p.id,p.teacher_id,p.created_at,t.username,t.email FROM password_request p JOIN teacher_account t ON t.id=p.teacher_id WHERE p.handled_at IS NULL ORDER BY p.id')->fetchAll() : [];
                $mem = @file_get_contents('/proc/meminfo') ?: '';
                preg_match('/MemTotal:\s+(\d+)/', $mem, $mt); preg_match('/MemAvailable:\s+(\d+)/', $mem, $ma);
                $status = ['disk_free' => @disk_free_space('/') ?: 0, 'disk_total' => @disk_total_space('/') ?: 0,
                    'mem_total' => (int)($mt[1] ?? 0) * 1024, 'mem_free' => (int)($ma[1] ?? 0) * 1024,
                    'load' => (float)explode(' ', (string)@file_get_contents('/proc/loadavg'))[0], 'cpus' => (int)(trim((string)@shell_exec('nproc')) ?: 1),
                    'uptime' => (int)(float)explode(' ', (string)@file_get_contents('/proc/uptime'))[0],
                    'db_size' => (int)$db->query('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn(),
                    'db_version' => $db->query('SELECT VERSION()')->fetchColumn(), 'php' => PHP_VERSION,
                    'online' => (int)$db->query("SELECT COUNT(*) FROM device WHERE last_seen >= NOW() - INTERVAL 10 SECOND AND device_uid NOT LIKE 'BRIDGE-%'")->fetchColumn(),
                    'public_url' => ($config['internet_host'] ?? '') ? 'https://' . $config['internet_host'] . '/clicker/' : null,
                    'ai' => !empty($config['ai_provider']) && (!empty($config['gemini_key']) || !empty($config['ai_key']) || !empty($config['openai_key'])),
                    'google' => !empty($config['google_client_id']), 'tools_ready' => ops_ready($db)];
                reply(['ok' => true, 'settings' => $settings, 'requests' => $requests, 'server' => $status, 'audit_days' => setting_value($db, 'audit_days', 90), 'table_rows' => $sizes]);
            }
            if ($method === 'GET' && $action === 'admin_notifications') {
                $rows = ops_ready($db) ? $db->query('SELECT p.id,p.teacher_id,p.created_at,p.ip,t.username,t.email FROM password_request p JOIN teacher_account t ON t.id=p.teacher_id WHERE p.handled_at IS NULL ORDER BY p.id DESC')->fetchAll() : [];
                reply(['ok' => true, 'requests' => $rows]);
            }
            if ($method === 'GET' && $action === 'admin_audit') {
                if (!ops_ready($db)) reply(['ok' => true, 'rows' => [], 'ready' => false]);
                $before = (int)($_GET['before'] ?? 0); $q = trim((string)($_GET['q'] ?? ''));
                $where = []; $args = [];
                if ($before > 0) { $where[] = 'id<?'; $args[] = $before; }
                if ($q !== '') { $where[] = '(actor_name LIKE ? OR action LIKE ? OR detail LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
                $rows = query($db, 'SELECT id,at,actor_id,actor_name,action,detail,ip FROM audit_log' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 100', $args)->fetchAll();
                reply(['ok' => true, 'rows' => $rows, 'ready' => true]);
            }
            if ($method === 'GET' && $action === 'admin_devices') {
                $label = (bool)$db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='device' AND column_name='label'")->fetchColumn();
                $rows = $db->query('SELECT d.device_uid,d.kind,d.last_seen,d.revoked_at,d.registered_at,' . ($label ? 'd.label' : 'NULL AS label') . ',
                    IF(d.last_seen >= NOW() - INTERVAL 10 SECOND,1,0) AS online,
                    (SELECT CONCAT(ss.student_name, " · ", ss.student_id) FROM session_student ss WHERE ss.device_uid=d.device_uid ORDER BY ss.session_id DESC LIMIT 1) AS last_student
                    FROM device d ORDER BY d.last_seen IS NULL, d.last_seen DESC LIMIT 400')->fetchAll();
                reply(['ok' => true, 'devices' => $rows, 'labels' => $label]);
            }
            if ($method === 'GET' && $action === 'admin_report') {
                $rows = $db->query("SELECT s.id,s.teacher_id,t.username,s.class_id,s.exam_id,e.title,ss.student_id,
                    (SELECT COUNT(*) FROM question q WHERE q.exam_id=s.exam_id) AS qn,
                    (SELECT COUNT(*) FROM student_response r JOIN question q ON q.id=r.question_id WHERE r.session_id=s.id AND r.student_id=ss.student_id AND r.selected_answer=q.correct_answer) AS ok
                    FROM classroom_session s JOIN session_student ss ON ss.session_id=s.id JOIN teacher_account t ON t.id=s.teacher_id JOIN exam e ON e.id=s.exam_id
                    WHERE ss.device_uid IS NOT NULL AND s.state='FINISHED'")->fetchAll();
                $group = function (callable $key, callable $label) use ($rows): array {
                    $out = [];
                    foreach ($rows as $r) {
                        $k = $key($r); $score = $r['qn'] ? $r['ok'] / $r['qn'] * 10 : 0;
                        $out[$k] ??= ['label' => $label($r), 'n' => 0, 'sum' => 0.0, 'rooms' => []];
                        $out[$k]['n']++; $out[$k]['sum'] += $score; $out[$k]['rooms'][$r['id']] = 1;
                    }
                    $out = array_map(fn($g) => ['label' => $g['label'], 'students' => $g['n'], 'rooms' => count($g['rooms']), 'avg' => round($g['sum'] / max(1, $g['n']), 2)], array_values($out));
                    usort($out, fn($a, $b) => $b['students'] <=> $a['students']);
                    return $out;
                };
                $hard = $db->query("SELECT q.question_no,q.question_text,q.correct_answer,e.title,t.username,COUNT(*) AS n,SUM(r.selected_answer=q.correct_answer) AS ok
                    FROM student_response r JOIN question q ON q.id=r.question_id JOIN exam e ON e.id=q.exam_id JOIN teacher_account t ON t.id=e.teacher_id
                    GROUP BY q.id HAVING COUNT(*) >= 3 ORDER BY SUM(r.selected_answer=q.correct_answer)/COUNT(*), COUNT(*) DESC LIMIT 10")->fetchAll();
                reply(['ok' => true, 'by_teacher' => $group(fn($r) => $r['teacher_id'], fn($r) => $r['username']),
                    'by_class' => $group(fn($r) => $r['teacher_id'] . '|' . $r['class_id'], fn($r) => $r['class_id'] . ' · ' . $r['username']),
                    'by_exam' => $group(fn($r) => $r['exam_id'], fn($r) => $r['title'] . ' · ' . $r['username']), 'hardest' => $hard]);
            }
            if ($method === 'GET' && $action === 'admin_backup') {
                // Download a gzip of the whole database, made with mariadb-dump (nothing is stored on the server).
                preg_match('/(?:^|;)host=([^;]+)/', $config['dsn'], $h); preg_match('/(?:^|;)dbname=([A-Za-z0-9_]+)/', $config['dsn'], $n);
                $proc = proc_open(['/usr/bin/mariadb-dump', '--host=' . ($h[1] ?? 'localhost'), '--user=' . $config['db_user'], '--single-transaction', '--quick',
                    '--skip-lock-tables', '--no-tablespaces', $n[1]], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['MYSQL_PWD' => $config['db_password']]);
                if (!is_resource($proc)) reject('BACKUP_FAILED', 'Không chạy được mariadb-dump.', 500);
                $sql = stream_get_contents($pipes[1]); fclose($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
                if (proc_close($proc) !== 0 || $sql === '') { error_log('Clicker backup: ' . $err); reject('BACKUP_FAILED', 'Sao lưu không thành công.', 500); }
                audit($db, $self, $myName, 'admin_backup', 'Tải bản sao lưu database (' . round(strlen($sql) / 1024) . ' KB)');
                header_remove('Content-Type');
                header('Content-Type: application/gzip');
                header('Content-Disposition: attachment; filename="clicker-' . date('Ymd-His') . '.sql.gz"');
                echo gzencode($sql, 6); exit;
            }
            if ($method !== 'POST') reject('METHOD', 'Lệnh quản trị phải dùng POST.', 405);
            $db->beginTransaction();
            if ($action === 'admin_audit_days') {
                $days = (int)($data['days'] ?? 0);
                if (!in_array($days, AUDIT_DAYS, true)) reject('INVALID_SETTING', 'Chọn 30, 90, 180 hoặc 365 ngày.');
                if (!ops_ready($db)) reject('TOOLS_OFF', 'Chưa cài công cụ quản trị (chạy sudo bash tools/setup_db.sh).', 409);
                query($db, 'INSERT INTO app_setting(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', ['audit_days', (string)$days]);
                audit($db, $self, $myName, 'admin_setting', "Tự xoá nhật ký sau $days ngày");
                $db->commit(); $done = housekeeping($db); reply(['ok' => true, 'cleaned' => $done]);
            }
            if ($action === 'admin_cleanup') {
                $db->commit(); $done = housekeeping($db);
                audit($db, $self, $myName, 'admin_setting', "Dọn dữ liệu cũ: {$done['log']} dòng nhật ký, {$done['limits']} dòng chống spam");
                reply(['ok' => true, 'cleaned' => $done]);
            }
            if ($action === 'admin_setting') {
                $name = field($data, 'name', 40);
                if (!isset(SETTINGS[$name])) reject('INVALID_SETTING', 'Cài đặt không hợp lệ.');
                if (!ops_ready($db)) reject('TOOLS_OFF', 'Chưa cài công cụ quản trị (chạy sudo bash tools/setup_db.sh).', 409);
                $on = ($data['on'] ?? null) === true;
                query($db, 'INSERT INTO app_setting(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$name, $on ? '1' : '0']);
                audit($db, $self, $myName, 'admin_setting', SETTINGS[$name] . ': ' . ($on ? 'bật' : 'tắt'));
                $db->commit(); reply(['ok' => true]);
            }
            if ($action === 'admin_create_teacher') {
                // Password account (8 digits, generated when left empty) or a Gmail invite: the first Google sign-in with
                // that address lands in this account (oauth_account links by verified e-mail).
                $username = field($data, 'username', 80);
                $email = isset($data['email']) && trim((string)$data['email']) !== '' ? strtolower(trim((string)$data['email'])) : null;
                if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) reject('INVALID_EMAIL', 'Email không hợp lệ.');
                $password = trim((string)($data['password'] ?? '')) ?: random_password();
                if (!preg_match('/^[0-9]{8}$/D', $password)) reject('INVALID_PASSWORD', 'Mật khẩu cần đúng 8 chữ số.');
                if ($email !== null && query($db, 'SELECT 1 FROM teacher_account WHERE LOWER(email)=?', [$email])->fetchColumn()) reject('EMAIL_USED', 'Email này đã có tài khoản.', 409);
                try { query($db, 'INSERT INTO teacher_account(username,password_hash,email) VALUES(?,?,?)', [$username, password_hash($password, PASSWORD_DEFAULT), $email]); }
                catch (PDOException $error) { if (($error->errorInfo[1] ?? 0) === 1062) reject('USERNAME_USED', 'Tên đăng nhập đã được dùng.', 409); throw $error; }
                $id = (int)$db->lastInsertId();
                audit($db, $self, $myName, 'admin_create_teacher', "Tạo tài khoản \"$username\"" . ($email ? " ($email)" : ''));
                $db->commit(); reply(['ok' => true, 'teacher_id' => $id, 'username' => $username, 'password' => $password, 'email' => $email]);
            }
            if ($action === 'admin_approve_request' || $action === 'admin_reject_request') {
                $rid = positive_id($data['request_id'] ?? null);
                $req = ops_ready($db) ? query($db, 'SELECT p.id,t.username FROM password_request p JOIN teacher_account t ON t.id=p.teacher_id WHERE p.id=? AND p.handled_at IS NULL', [$rid])->fetch() : false;
                if (!$req) reject('REQUEST_NOT_FOUND', 'Yêu cầu không còn (đã xử lý hoặc người đó đã huỷ).', 404);
                if ($action === 'admin_approve_request') query($db, 'UPDATE password_request SET handled_at=NOW() WHERE id=?', [$rid]);
                else query($db, 'DELETE FROM password_request WHERE id=?', [$rid]);
                audit($db, $self, $myName, $action === 'admin_approve_request' ? 'admin_approve_pw' : 'admin_reject_pw',
                    ($action === 'admin_approve_request' ? 'Cấp phép đặt lại mật khẩu cho "' : 'Từ chối yêu cầu mật khẩu của "') . $req['username'] . '"');
                $db->commit(); reply(['ok' => true]);
            }
            if ($action === 'admin_audit_delete') {
                if (!ops_ready($db)) reject('TOOLS_OFF', 'Nhật ký chưa bật.', 409);
                $ids = $data['ids'] ?? null;
                if ($ids === 'all') $n = $db->exec('DELETE FROM audit_log');
                else {
                    if (!is_array($ids) || !$ids || count($ids) > 1000) reject('INVALID_INPUT', 'Chọn dòng cần xoá.');
                    $ids = array_values(array_unique(array_map(fn($v) => positive_id($v), $ids)));
                    $n = query($db, 'DELETE FROM audit_log WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids)->rowCount();
                }
                audit($db, $self, $myName, 'admin_audit_delete', $ids === 'all' ? "Xoá toàn bộ nhật ký ($n dòng)" : "Xoá $n dòng nhật ký");
                $db->commit(); reply(['ok' => true, 'deleted' => $n]);
            }
            if ($action === 'admin_view_stop') {
                $db->commit(); unset($_SESSION['view_as'], $_SESSION['view_as_name']); reply(['ok' => true]);
            }
            if ($action === 'admin_delete_devices') {
                // Delete to keep the list short. Kept (and reported): devices a student used in a quiz (their answers point
                // to them) and devices that still hold an old ESP32/bridge key (use Chặn for those).
                $uids = $data['uids'] ?? null;
                if ($uids === 'inactive') $uids = $db->query('SELECT device_uid FROM device WHERE last_seen IS NULL OR last_seen < NOW() - INTERVAL 30 DAY')->fetchAll(PDO::FETCH_COLUMN);
                if (!is_array($uids) || count($uids) > 2000) reject('INVALID_INPUT', 'Chọn thiết bị cần xoá.');
                $deleted = 0; $kept = 0;
                foreach (array_unique(array_map(fn($u) => strtoupper(substr((string)$u, 0, 64)), $uids)) as $uid) {
                    $row = query($db, 'SELECT key_hash FROM device WHERE device_uid=?', [$uid])->fetch();
                    if (!$row) continue;
                    $used = query($db, 'SELECT 1 FROM session_student WHERE device_uid=? UNION SELECT 1 FROM student_response WHERE device_uid=? LIMIT 1', [$uid, $uid])->fetchColumn();
                    if ($used || $row['key_hash'] !== null) { $kept++; continue; }
                    if (join_ready($db)) query($db, 'DELETE FROM join_request WHERE device_uid=?', [$uid]);
                    query($db, 'DELETE FROM device WHERE device_uid=?', [$uid]);
                    $deleted++;
                }
                audit($db, $self, $myName, 'admin_device', "Xoá $deleted thiết bị" . ($kept ? " (giữ $kept máy đã dùng trong bài kiểm tra / đã đăng ký)" : ''));
                $db->commit(); reply(['ok' => true, 'deleted' => $deleted, 'kept' => $kept]);
            }
            if ($action === 'admin_delete_teachers') {
                $ids = $data['ids'] ?? null;
                if (!is_array($ids) || !$ids || count($ids) > 500) reject('INVALID_INPUT', 'Chọn giáo viên cần xoá.');
                $ids = array_values(array_diff(array_unique(array_map(fn($v) => positive_id($v), $ids)), [$self]));
                if (($data['confirmed'] ?? null) !== 'XOA ' . count($ids)) reject('CONFIRM_REQUIRED', 'Gõ đúng XOA ' . count($ids) . ' để xác nhận.');
                $names = []; $totals = ['rooms' => 0, 'exams' => 0, 'students' => 0];
                foreach ($ids as $id) {
                    $name = query($db, 'SELECT username FROM teacher_account WHERE id=?', [$id])->fetchColumn();
                    if ($name === false) continue;
                    release_room_of($db, $id);
                    foreach (delete_teacher($db, $id) as $k => $n) $totals[$k] += $n;
                    $names[] = $name;
                }
                audit($db, $self, $myName, 'admin_delete', 'Xoá ' . count($names) . ' tài khoản: ' . implode(', ', $names));
                $db->commit(); reply(['ok' => true, 'deleted' => count($names), 'removed' => $totals]);
            }
            if ($action === 'admin_device') {
                $uid = strtoupper(field($data, 'device_uid', 64));
                if (!query($db, 'SELECT 1 FROM device WHERE device_uid=?', [$uid])->fetchColumn()) reject('DEVICE_NOT_FOUND', 'Không tìm thấy thiết bị.', 404);
                if (array_key_exists('label', $data)) {
                    $label = trim((string)$data['label']); preg_match('/^.{0,60}/su', $label, $cut);
                    query($db, 'UPDATE device SET label=? WHERE device_uid=?', [$cut[0] === '' ? null : $cut[0], $uid]);
                    audit($db, $self, $myName, 'admin_device', "Đặt tên $uid: " . ($cut[0] ?: '(trống)'));
                }
                if (array_key_exists('blocked', $data)) {
                    $blocked = $data['blocked'] === true;
                    query($db, 'UPDATE device SET revoked_at=' . ($blocked ? 'NOW()' : 'NULL') . ' WHERE device_uid=?', [$uid]);
                    audit($db, $self, $myName, 'admin_device', ($blocked ? 'Chặn ' : 'Bỏ chặn ') . $uid);
                }
                $db->commit(); reply(['ok' => true]);
            }
            if ($action === 'admin_end_room') {
                query($db, 'SELECT session_id FROM app_state WHERE id=1 FOR UPDATE');
                $sid = positive_id($data['session_id'] ?? null);
                if (session_row($db, $sid)['state'] === 'FINISHED') reject('NO_ACTIVE_ROOM', 'Phòng này đã kết thúc.', 409);
                finish_session($db, $sid);
                audit($db, $self, $myName, 'admin_end_room', "Kết thúc phòng #$sid");
                $db->commit(); reply(['ok' => true, 'ended' => $sid]);
            }
            $target = positive_id($data['teacher_id'] ?? null);
            if ($target === $self) reject('ADMIN_SELF', 'Không thao tác trên chính tài khoản quản trị của bạn.', 409);
            $row = query($db, 'SELECT id,username,is_admin FROM teacher_account WHERE id=? FOR UPDATE', [$target])->fetch();
            if (!$row) reject('TEACHER_NOT_FOUND', 'Không tìm thấy giáo viên.', 404);
            if ($action === 'admin_view_as') {
                // Read-only look at this teacher's classes, exams and results (all their POSTs are refused while it lasts).
                audit($db, $self, $myName, 'admin_view_as', "Xem dữ liệu của \"{$row['username']}\" (chỉ đọc)");
                $db->commit(); $_SESSION['view_as'] = (int)$row['id']; $_SESSION['view_as_name'] = $row['username']; reply(['ok' => true]);
            }
            if ($action === 'admin_reset_password') {
                $password = random_password();
                query($db, 'UPDATE teacher_account SET password_hash=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $target]);
                if (ops_ready($db)) query($db, 'DELETE FROM password_request WHERE teacher_id=?', [$target]);
                audit($db, $self, $myName, 'admin_reset_password', "Đặt lại mật khẩu \"{$row['username']}\"");
                $db->commit(); reply(['ok' => true, 'username' => $row['username'], 'password' => $password]);
            }
            if ($action === 'admin_set_locked') {
                $lock = ($data['locked'] ?? null) === true;
                if ($lock) release_room_of($db, $target);
                query($db, 'UPDATE teacher_account SET disabled_at=' . ($lock ? 'NOW()' : 'NULL') . ' WHERE id=?', [$target]);
                audit($db, $self, $myName, 'admin_lock', ($lock ? 'Khoá' : 'Mở khoá') . " \"{$row['username']}\"");
                $db->commit(); reply(['ok' => true, 'locked' => $lock]);
            }
            if ($action === 'admin_set_admin') {
                query($db, 'UPDATE teacher_account SET is_admin=? WHERE id=?', [($data['admin'] ?? null) === true ? 1 : 0, $target]);
                audit($db, $self, $myName, 'admin_role', (($data['admin'] ?? null) === true ? 'Cấp' : 'Bỏ') . " quyền quản trị \"{$row['username']}\"");
                $db->commit(); reply(['ok' => true]);
            }
            if ($action === 'admin_delete_teacher') {
                if (($data['confirmed'] ?? null) !== $row['username']) reject('CONFIRM_REQUIRED', 'Gõ đúng tên tài khoản để xác nhận xoá.');
                $removed = delete_teacher($db, $target);
                audit($db, $self, $myName, 'admin_delete', "Xoá tài khoản \"{$row['username']}\" ({$removed['rooms']} buổi, {$removed['exams']} đề, {$removed['students']} SV)");
                $db->commit(); reply(['ok' => true, 'removed' => $removed]);
            }
            reject('ACTION', 'Lệnh không hợp lệ.', 404);
        }
        if ($action === 'logout' && $method === 'POST') {
            audit($db, (int)$_SESSION['teacher_id'], $_SESSION['teacher_username'] ?? '', 'logout', 'Đăng xuất');
            release_teacher_room($db, (int)$_SESSION['teacher_id']);
            end_teacher_session();
            reply(['ok' => true]);
        }
        if ($method === 'POST') $_SESSION['last_activity'] = time();
        $teacherId = (int)$_SESSION['teacher_id'];
        $actorName = $_SESSION['teacher_username'] ?? '';
        if (random_int(1, 200) === 1) housekeeping($db);   // a few times a day on a normal school day
        if ($isAdmin && !empty($_SESSION['view_as'])) {
            // Admin looking at another teacher: everything reads that teacher's data, nothing can be changed.
            if ($method === 'POST') reject('VIEW_ONLY', 'Đang xem dữ liệu giáo viên khác (chỉ đọc). Bấm "Thoát chế độ xem" để thao tác.', 403);
            $teacherId = (int)$_SESSION['view_as'];
        }
        session_write_close();
        // ---- Gateway USB (web/gateway.js): an nRF52840 on the teacher's USB relays the BLE tags through this signed-in
        // tab (Web Serial). No device key: the teacher session + CSRF are the credentials, and the tags follow this
        // teacher's own room. gateway_sync is answered here; login / answer go on to the device rules below.
        if (in_array($action, ['gateway_sync', 'gateway_login', 'gateway_answer'], true)) {
            if ($method !== 'POST') reject('METHOD', 'Gateway phải dùng POST.', 405);
            $bridgeUid = strtoupper(field($data, 'bridge_uid', 64));
            if (!preg_match('/^BRIDGE-[A-F0-9]{12}$/D', $bridgeUid)) reject('INVALID_UID', 'Gateway không hợp lệ.');
            query($db, 'INSERT INTO device(device_uid,last_seen) VALUES(?,NOW(3)) ON DUPLICATE KEY UPDATE last_seen=NOW(3)', [$bridgeUid]);
            if (query($db, 'SELECT revoked_at IS NOT NULL FROM device WHERE device_uid=?', [$bridgeUid])->fetchColumn()) reject('DEVICE_BLOCKED', 'Gateway này đã bị quản trị viên chặn.', 403);
            if ($action === 'gateway_sync') {
                rate_limit($db, 'gateway_sync', $bridgeUid, 5, 1);
                $tags = $data['tags'] ?? null;
                if (!is_array($tags) || count($tags) > 200) reject('INVALID_TAGS', 'Danh sách tag tối đa 200 UID.');
                $db->beginTransaction();
                if ($room = teacher_room($db, $teacherId)) { auto_tick($db, (int)$room['id']); $room = session_row($db, (int)$room['id']); }
                $sid = $room ? (int)$room['id'] : 0;
                $question = $room && $room['state'] === 'QUESTION_ACTIVE' ? query($db, 'SELECT id AS question_id,question_no FROM question WHERE id=?', [$room['current_question_id']])->fetch() : null;
                $scores = [];
                if ($room && $room['state'] === 'FINISHED' && $tags) foreach (report($db, $sid)['rows'] as $row)
                    $scores[$row['student_id']] = (int)floor($row['score10'] * 10 + 1e-6);   // tenths, cut not rounded: 9.75 -> 97
                $status = [];
                foreach ($tags as $tag) {
                    $tag = is_string($tag) ? strtoupper(trim($tag)) : '';
                    if (!preg_match('/^NRF-[A-F0-9]{12}$/D', $tag)) reject('INVALID_UID', 'Tag không hợp lệ.');
                    query($db, 'INSERT INTO device(device_uid,last_seen) VALUES(?,NOW(3)) ON DUPLICATE KEY UPDATE last_seen=NOW(3)', [$tag]);
                    $student = $sid ? query($db, 'SELECT student_id FROM session_student WHERE session_id=? AND device_uid=?', [$sid, $tag])->fetchColumn() : false;
                    $answer = $student !== false && $question ? query($db, 'SELECT selected_answer FROM student_response WHERE session_id=? AND device_uid=? AND question_id=?', [$sid, $tag, $question['question_id']])->fetchColumn() : false;
                    $status[$tag] = ['logged' => $student !== false, 'answer' => $answer ?: null,
                        'score_tenths' => $student !== false && $room['state'] === 'FINISHED' ? $scores[$student] : null];
                }
                $db->commit();
                reply(['ok' => true, 'mode' => room_mode($db, $room), 'session_id' => $sid ?: null, 'state' => $room['state'] ?? 'WAIT_SESSION', 'question' => $question ?: null, 'tags' => (object)$status]);
            }
            $uid = strtoupper(field($data, 'device_uid', 64));
            if (!preg_match('/^NRF-[A-F0-9]{12}$/D', $uid)) reject('INVALID_UID', 'Tag không hợp lệ.');
            $deviceRoom = running_room($db, $teacherId);
            if (!$deviceRoom || (int)$deviceRoom['id'] !== (int)($data['session_id'] ?? 0)) reject('SESSION_FINISHED', 'Buổi học không còn hoạt động.', 409);
            $action = substr($action, 8);
            if ($action === 'login') rate_limit($db, 'login', $uid, 20, 60);
            else rate_limit($db, 'answer', $uid, 90, 60);
            $deviceAction = true;
        }
        // ---- Hồ sơ, ảnh đại diện, đổi mật khẩu (own account; refused while an admin only views another teacher)
        if ($action === 'avatar' && $method === 'GET') {
            $who = isset($_GET['id']) && $isAdmin ? positive_id($_GET['id']) : $teacherId;
            $img = profile_ready($db) ? query($db, 'SELECT avatar,avatar_type FROM teacher_profile WHERE teacher_id=?', [$who])->fetch() : false;
            if (!$img || !$img['avatar']) { http_response_code(404); exit; }
            header_remove('Content-Type'); header('Content-Type: ' . $img['avatar_type']); header('Cache-Control: private, max-age=300');
            echo $img['avatar']; exit;
        }
        if ($action === 'ai_status' && $method === 'GET') reply(['ok' => true, 'model' => quiz_ai_name($config)]);
        if ($action === 'ai_generate_questions' && $method === 'POST') {
            // Tạo đề bằng AI: outside the room lock (the model may take half a minute); nothing is saved here —
            // the teacher reviews the questions and saves them with import_questions like an Excel file.
            if (!empty($_SESSION['view_as'])) reject('VIEW_ONLY', 'Đang xem dữ liệu giáo viên khác (chỉ đọc).', 403);
            if (quiz_ai_name($config) === null) reject('AI_NOT_CONFIGURED', 'Máy chủ chưa cấu hình mô hình AI (chạy sudo bash tools/setup_ai.sh).', 503);
            rate_limit($db, 'ai_quiz', (string)$teacherId, 15, 3600);
            $content = trim((string)(is_string($data['content'] ?? null) ? $data['content'] : ''));
            if (!preg_match('//u', $content) || strlen($content) < 80) reject('AI_CONTENT', 'Dán nội dung môn học (ít nhất vài câu) để AI soạn câu hỏi.');
            if (strlen($content) > 120000) reject('AI_CONTENT', 'Nội dung quá dài (tối đa khoảng 40 trang). Hãy chia nhỏ theo chương.');
            $count = (int)($data['count'] ?? 10);
            if ($count < 1 || $count > 40) reject('INVALID_INPUT', 'Số câu từ 1 đến 40.');
            $level = (string)($data['level'] ?? 'tron');
            if (!isset(QUIZ_LEVELS[$level])) reject('INVALID_INPUT', 'Độ khó không hợp lệ.');
            $notes = trim((string)(is_string($data['notes'] ?? null) ? $data['notes'] : ''));
            preg_match('/^.{0,500}/su', $notes, $cut);
            $rows = ai_generate_questions($config, $content, $count, $level, ($data['lang'] ?? 'vi') === 'en' ? 'en' : 'vi', $cut[0]);
            audit($db, $teacherId, $actorName, 'ai_generate', 'AI tạo ' . count($rows) . ' câu hỏi (' . $level . ')');
            reply(['ok' => true, 'rows' => $rows, 'model' => quiz_ai_name($config)]);
        }
        if (in_array($action, ['profile_save', 'avatar_upload', 'avatar_remove', 'password_change'], true) && $method === 'POST') {
            if (!profile_ready($db) && $action !== 'password_change') reject('PROFILE_OFF', 'Chưa bật hồ sơ trên máy chủ (chạy sudo bash tools/setup_db.sh).', 409);
            if ($action === 'profile_save') {
                $name = field($data, 'full_name', 120);
                if (!preg_match('/\pL/u', $name) || preg_match('/^[\s]*[=+@\-]/u', $name)) reject('INVALID_NAME', 'Họ tên không hợp lệ.');
                $gender = in_array($data['gender'] ?? '', ['nam', 'nu', 'khac'], true) ? $data['gender'] : 'khac';
                $text = function (string $k, int $max) use ($data): string {
                    $v = trim((string)(is_scalar($data[$k] ?? null) ? $data[$k] : ''));
                    if (!preg_match('//u', $v)) reject('INVALID_INPUT', "Trường $k không hợp lệ.");
                    preg_match('/^.{0,' . $max . '}/su', $v, $m); return $m[0];
                };
                $phone = preg_replace('/[^0-9+ ]/', '', $text('phone', 20));
                query($db, 'INSERT INTO teacher_profile(teacher_id,full_name,gender,school,subject,grades,phone,bio) VALUES(?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),gender=VALUES(gender),school=VALUES(school),subject=VALUES(subject),grades=VALUES(grades),phone=VALUES(phone),bio=VALUES(bio)',
                    [$teacherId, $name, $gender, $text('school', 160), $text('subject', 120), $text('grades', 120), $phone, $text('bio', 300)]);
                audit($db, $teacherId, $actorName, 'profile_save', 'Cập nhật hồ sơ');
                reply(['ok' => true, 'profile' => my_profile($db, $teacherId)]);
            }
            if ($action === 'avatar_upload') {
                // The browser crops and shrinks the photo (256 px) before sending it as a data URL.
                if (!query($db, 'SELECT 1 FROM teacher_profile WHERE teacher_id=?', [$teacherId])->fetchColumn()) reject('PROFILE_FIRST', 'Hãy lưu hồ sơ trước khi đổi ảnh.', 409);
                if (!preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$#D', (string)($data['image'] ?? ''), $m)) reject('INVALID_IMAGE', 'Ảnh phải là JPG, PNG hoặc WEBP.');
                $bin = base64_decode($m[2], true);
                if ($bin === false || strlen($bin) > 400000) reject('IMAGE_TOO_LARGE', 'Ảnh tối đa 400 KB.');
                $info = @getimagesizefromstring($bin);
                if (!$info || $info['mime'] !== $m[1]) reject('INVALID_IMAGE', 'File không phải ảnh hợp lệ.');
                query($db, 'UPDATE teacher_profile SET avatar=?,avatar_type=? WHERE teacher_id=?', [$bin, $m[1], $teacherId]);
                reply(['ok' => true, 'profile' => my_profile($db, $teacherId)]);
            }
            if ($action === 'avatar_remove') {
                query($db, 'UPDATE teacher_profile SET avatar=NULL,avatar_type=NULL WHERE teacher_id=?', [$teacherId]);
                reply(['ok' => true, 'profile' => my_profile($db, $teacherId)]);
            }
            // password_change: the current password is needed, except for accounts that sign in with Google (they never
            // chose one); the new one must be 8 digits typed twice.
            $acc = query($db, 'SELECT password_hash,google_id IS NOT NULL AS google FROM teacher_account WHERE id=?', [$teacherId])->fetch();
            $new = field($data, 'password', 8);
            if (!preg_match('/^[0-9]{8}$/D', $new)) reject('INVALID_PASSWORD', 'Mật khẩu mới cần đúng 8 chữ số.');
            if (($data['password2'] ?? null) !== $new) reject('PASSWORD_MISMATCH', 'Hai lần nhập mật khẩu mới không giống nhau.');
            $old = (string)($data['old_password'] ?? '');
            if (!$acc['google'] || $old !== '') {
                rate_limit($db, 'password_change', (string)$teacherId, 8, 900);
                if (!password_verify($old, $acc['password_hash'])) reject('WRONG_PASSWORD', 'Mật khẩu hiện tại không đúng.', 403);
            }
            query($db, 'UPDATE teacher_account SET password_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $teacherId]);
            audit($db, $teacherId, $actorName, 'password_change', 'Đổi mật khẩu');
            reply(['ok' => true]);
        }
    }
    if ($deviceAction) {
        query($db, 'INSERT INTO device(device_uid,last_seen) VALUES(?,NOW(3)) ON DUPLICATE KEY UPDATE last_seen=NOW(3)', [$uid]);
        if (query($db, 'SELECT revoked_at IS NOT NULL FROM device WHERE device_uid=?', [$uid])->fetchColumn()) reject('DEVICE_BLOCKED', 'Thiết bị này đã bị quản trị viên chặn.', 403);
        // A phone's room is the one whose code it typed, else the one it joined (gateway tags already follow their teacher's room).
        if (defined('CLICKER_PHONE_UID')) $deviceRoom = device_room($db, $config, $uid, $data['room'] ?? null);
        if ($action !== 'poll') {
            if (!$deviceRoom || (int)$deviceRoom['id'] !== (int)($data['session_id'] ?? 0))
                reject($action === 'answer' ? 'QUESTION_CLOSED' : 'LOGIN_CLOSED', $action === 'answer' ? 'Câu hỏi chưa mở hoặc đã đóng.' : 'Chưa mở hoặc đã đóng đăng nhập.', 409);
            // Only the devices of the room's mode may join; the others see why they are paused.
            $isTag = str_starts_with($uid, 'NRF-'); $roomMode = room_mode($db, $deviceRoom);
            if ($isTag && $roomMode !== 'ble') reject('MODE_WIFI', 'Phòng đang dùng chế độ Wi-Fi (điện thoại).', 409);
            if (!$isTag && $roomMode === 'ble') reject('MODE_BLE', 'Phòng đang dùng chế độ BLE (Student Tag). Điện thoại tạm dừng.', 409);
        }
    }
    if ($action === 'poll') {
        $db->beginTransaction();
        $sid = $deviceRoom ? (int)$deviceRoom['id'] : 0;
        if ($sid) auto_tick($db, $sid);
        $session = $sid ? session_row($db, (int)$sid) : null;
        $finished = $session && $session['state'] === 'FINISHED';
        $login = $sid && !$finished ? query($db, 'SELECT student_id,student_name FROM session_student WHERE session_id=? AND device_uid=?', [$sid, $uid])->fetch() : false;
        $question = null; $selfDone = null;
        if ($login && $session['state'] === 'QUESTION_ACTIVE' && self_paced($session)) {
            // Self-paced: this student's own next question and how long is left for it.
            $walk = self_walk(self_plan($db, $session), self_answers($db, (int)$sid, $login['student_id'])[$login['student_id']] ?? []);
            if ($walk['question']) $question = ['question_id' => (int)$walk['question']['id'], 'question_no' => (int)$walk['question']['question_no'], 'accepted_answer' => null,
                'index' => $walk['index'], 'total' => $walk['total'], 'remaining' => round($walk['remaining'], 1),
                'quiz_left' => round(max(0, ($plan = self_plan($db, $session))['start'] + count($plan['qs']) * $plan['seconds'] - $plan['now']), 1)];
            elseif (defined('CLICKER_PHONE_UID')) {
                $mine = report($db, (int)$sid);
                foreach ($mine['rows'] as $r) if ($r['student_id'] === $login['student_id']) $selfDone = ['correct' => $r['correct'], 'total' => $r['total'], 'answered' => $walk['answered'], 'score10' => $r['score10']];
            }
        } elseif ($login && $session['state'] === 'QUESTION_ACTIVE') {
            $question = query($db, 'SELECT id AS question_id,question_no FROM question WHERE id=?', [$session['current_question_id']])->fetch();
            $question['accepted_answer'] = query($db, 'SELECT selected_answer FROM student_response WHERE session_id=? AND device_uid=? AND question_id=?', [$sid, $uid, $question['question_id']])->fetchColumn() ?: null;
        }
        // Phones show the question itself (never the key), like the projector.
        if ($question && defined('CLICKER_PHONE_UID')) {
            $text = query($db, 'SELECT question_text,answer_A,answer_B,answer_C,answer_D FROM question WHERE id=?', [$question['question_id']])->fetch();
            $question['text'] = $text['question_text'];
            $question['choices'] = ['A' => $text['answer_A'], 'B' => $text['answer_B'], 'C' => $text['answer_C'], 'D' => $text['answer_D']];
        }
        // Phones also learn the room's class (shown on the join form) and whether their join request still waits.
        if (defined('CLICKER_PHONE_UID') && $finished) {   // KẾT THÚC: every phone that was in the room sees its score
            $was = query($db, 'SELECT student_id FROM session_student WHERE session_id=? AND device_uid=?', [$sid, $uid])->fetchColumn();
            if ($was !== false) foreach (report($db, (int)$sid)['rows'] as $r) if ($r['student_id'] === $was)
                $selfDone = ['correct' => $r['correct'], 'total' => $r['total'], 'answered' => count(array_filter($r['answers'])), 'score10' => $r['score10']];
        }
        if ($selfDone && $finished) $phoneDone = ['self_done' => $selfDone];
        $phone = defined('CLICKER_PHONE_UID') && $sid && !$finished ? ['class_id' => $session['class_id'], 'pacing' => $session['pacing'] ?? 'teacher', 'self_done' => $selfDone, 'seconds' => (int)$session['auto_seconds'],
            'join_pending' => join_ready($db) ? (query($db, 'SELECT student_id,student_name FROM join_request WHERE session_id=? AND device_uid=?', [$sid, $uid])->fetch() ?: null) : null] : [];
        $db->commit();
        $phone += $phoneDone ?? [];
        reply(['ok' => true, 'mode' => $session ? room_mode($db, $session) : null, 'session_id' => $sid && !$finished ? (int)$sid : null, 'state' => $session['state'] ?? 'WAIT_SESSION', 'student_id' => $login['student_id'] ?? null, 'student_name' => $login['student_name'] ?? null, 'question' => $question] + $phone);
    }
    if ($method === 'GET') {
        // This teacher's running room decides the mode shown in the top bar; with no room the page keeps its own choice.
        if ($action === 'bootstrap') sweep_idle_rooms($db);
        $myRoom = running_room($db, $teacherId); $ownedActive = $myRoom ? (int)$myRoom['id'] : 0;
        $mode = $myRoom || !rooms_ready($db) ? room_mode($db, $myRoom) : null;
        $devices = $ownedActive ? query($db, 'SELECT d.device_uid,d.last_seen,IF(d.last_seen >= NOW() - INTERVAL 10 SECOND,1,0) AS online FROM device d JOIN session_student s ON s.device_uid=d.device_uid WHERE s.session_id=? ORDER BY d.last_seen DESC LIMIT 200', [$ownedActive])->fetchAll() : [];
        if ($action === 'status') reply(['ok' => true, 'mode' => $mode,
            'online' => array_column(array_filter($devices, fn($row) => (int)$row['online'] === 1), 'device_uid')]);
        if ($action === 'bootstrap') reply(['ok' => true, 'mode' => $mode, 'pacing_ready' => pacing_ready($db) && setting($db, 'self_paced'),
            'phone_url' => phone_url($config['public_url'] ?? '/clicker/'),
            'classes' => profile_ready($db)
                ? query($db, 'SELECT c.class_id, SUM(c.n) AS count, MAX(c.open) AS open FROM (SELECT class_id,COUNT(*) AS n,0 AS open FROM student WHERE teacher_id=? GROUP BY class_id
                    UNION ALL SELECT class_id,0,1 FROM teacher_class WHERE teacher_id=?) c GROUP BY c.class_id ORDER BY c.class_id', [$teacherId, $teacherId])->fetchAll()
                : query($db, 'SELECT class_id,COUNT(*) AS count,0 AS open FROM student WHERE teacher_id=? GROUP BY class_id ORDER BY class_id', [$teacherId])->fetchAll(),
            'students' => query($db, 'SELECT student_id,student_no,student_name,class_id FROM student WHERE teacher_id=? ORDER BY class_id,student_no,student_id', [$teacherId])->fetchAll(),
            'exams' => query($db, 'SELECT e.id,e.title,COUNT(q.id) AS count FROM exam e JOIN question q ON q.exam_id=e.id WHERE e.teacher_id=? GROUP BY e.id ORDER BY e.id DESC', [$teacherId])->fetchAll(),
            'sessions' => query($db, 'SELECT s.*,e.title AS exam_title FROM classroom_session s JOIN exam e ON e.id=s.exam_id WHERE s.teacher_id=? ORDER BY s.id DESC LIMIT 200', [$teacherId])->fetchAll(),
            'devices' => $devices]);
        if ($action === 'exam_questions') {
            $exam = owned_exam($db, positive_id($_GET['exam_id'] ?? null), $teacherId);
            // used = rooms that already opened the question: it can still be edited (results are re-graded) but not deleted.
            $questions = query($db, 'SELECT q.id,q.question_no,q.question_text,q.answer_A,q.answer_B,q.answer_C,q.answer_D,q.correct_answer,
                COUNT(sq.session_id) AS used FROM question q LEFT JOIN session_question sq ON sq.question_id=q.id WHERE q.exam_id=? GROUP BY q.id ORDER BY q.question_no', [$exam['id']])->fetchAll();
            $live = query($db, "SELECT id FROM classroom_session WHERE exam_id=? AND state<>'FINISHED' ORDER BY id DESC LIMIT 1", [$exam['id']])->fetchColumn();
            reply(['ok' => true, 'exam' => $exam, 'questions' => $questions, 'live_session' => $live ? (int)$live : null]);
        }
        $sid = positive_id($_GET['session_id'] ?? null);
        owned_session($db, $sid, $teacherId);
        // One consistent snapshot for statistics and responses while devices submit.
        $db->beginTransaction();
        if ($action === 'dashboard') {
            auto_tick($db, $sid); $result = dashboard($db, $sid);
            // The code is shown while the room runs.
            $live = $result['session']['state'] !== 'FINISHED';
            $db->commit();
            $progress = null;
            if (self_paced($result['session']) && in_array($result['session']['state'], ['QUESTION_ACTIVE', 'FINISHED'], true)) {
                // Self-paced: where each student is (question k of n, finished or not) for the Live tab and projector.
                $plan = self_plan($db, $result['session']); $answers = self_answers($db, $sid); $progress = [];
                // Whole quiz: number of questions x seconds per question, counted from Bắt đầu; the room ends at zero.
                $selfTotal = count($plan['qs']) * $plan['seconds']; $selfLeft = max(0, $plan['start'] + $selfTotal - $plan['now']);
                foreach ($result['roster'] as $st) {
                    if ($st['device_uid'] === null) continue;
                    $w = self_walk($plan, $answers[$st['student_id']] ?? []);
                    $progress[$st['student_id']] = ['index' => $w['index'], 'answered' => $w['answered'], 'total' => $w['total'], 'done' => $w['question'] === null || $result['session']['state'] === 'FINISHED'];
                }
            }
            $joins = join_ready($db) ? query($db, 'SELECT student_id,student_name,created_at FROM join_request WHERE session_id=? ORDER BY created_at', [$sid])->fetchAll() : [];
            reply(['ok' => true, 'mode' => room_mode($db, $result['session']), 'room_code' => $live ? room_code($config, $sid) : null, 'join_requests' => $joins, 'pacing' => $result['session']['pacing'] ?? 'teacher', 'self_progress' => $progress, 'self_total' => $selfTotal ?? null, 'self_left' => isset($selfLeft) ? round($selfLeft, 1) : null, 'pacing_ready' => pacing_ready($db)] + $result);
        }
        if ($action === 'export') {
            $report = report($db, $sid); $db->commit();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="clicker-session-' . $sid . '.csv"');
            $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(['STT', 'MSSV', 'Họ tên'], array_map(fn($q) => 'Q' . $q['question_no'], $report['questions']), ['Đúng/Tổng', 'Tỷ lệ đúng (%)', 'Điểm (thang 10)']));
            foreach ($report['rows'] as $row) {
                $name = preg_match('/^[\s]*[=+@\-]/u', $row['student_name']) ? "'" . $row['student_name'] : $row['student_name'];
                fputcsv($out, array_merge([$row['student_no'], $row['student_id'], $name], array_values($row['answers']), [$row['correct'] . '/' . $row['total'], $row['percent'], $row['score10']]));
            }
            fclose($out); exit;
        }
        reject('ACTION', 'Lệnh không hợp lệ.', 404);
    }

    // Server lock (the app_state row, shared by every room): teacher commands take it exclusively; device login/answer
    // share it, so a class pressing OK together is stored in parallel yet never races a NEXT/KẾT THÚC. An expired AUTO
    // step of the room in question is applied first in its own transaction: it needs the exclusive lock, and upgrading
    // a shared lock held by several devices would deadlock; a separate transaction also keeps the answer's snapshot.
    $shared = in_array($action, ['login', 'answer'], true);
    $tickSid = $deviceAction ? (int)($deviceRoom['id'] ?? 0) : (int)(is_scalar($data['session_id'] ?? null) ? $data['session_id'] : 0);
    if ($shared && $tickSid) { $db->beginTransaction(); auto_tick($db, $tickSid); $db->commit(); }
    $db->beginTransaction();
    query($db, 'SELECT session_id FROM app_state WHERE id=1 ' . ($shared ? 'LOCK IN SHARE MODE' : 'FOR UPDATE'));
    if (!$shared && $tickSid > 0) auto_tick($db, $tickSid);
    $result = [];
    if ($action === 'class_create') {
        // Lớp tự đăng ký: a class with no student file; students join with the room code and are approved.
        if (!profile_ready($db)) reject('PROFILE_OFF', 'Chưa bật lớp tự đăng ký trên máy chủ (chạy sudo bash tools/setup_db.sh).', 409);
        $class = field($data, 'class_id', 60);
        if (query($db, 'SELECT 1 FROM student WHERE teacher_id=? AND class_id=? LIMIT 1', [$teacherId, $class])->fetchColumn() || open_class($db, $teacherId, $class)) reject('CLASS_EXISTS', 'Lớp này đã có.', 409);
        query($db, 'INSERT INTO teacher_class(teacher_id,class_id) VALUES(?,?)', [$teacherId, $class]);
        $result['class_id'] = $class;
    } elseif ($action === 'import_students') {
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
    } elseif ($action === 'import_questions') {
        // Rows from Excel/CSV/paste, parsed in the browser. commit=false only checks them and reports every bad row.
        $rows = $data['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1 || count($rows) > 200) reject('INVALID_EXAM', 'Cần 1–200 câu hỏi.');
        $examId = isset($data['exam_id']) ? (int)owned_exam($db, positive_id($data['exam_id']), $teacherId)['id'] : 0;
        $title = $examId ? null : field($data, 'title');
        $have = $examId ? (int)query($db, 'SELECT COUNT(*) FROM question WHERE exam_id=?', [$examId])->fetchColumn() : 0;
        if ($have + count($rows) > 200) reject('TOO_MANY_QUESTIONS', 'Một bộ đề tối đa 200 câu (đang có ' . $have . ').');
        $clean = []; $errors = [];
        foreach ($rows as $i => $row) {
            $q = clean_question($row);
            if (is_string($q)) $errors[] = ['row' => $i + 1, 'error' => $q]; else $clean[] = $q;
        }
        $result = ['checked' => count($rows), 'errors' => $errors, 'imported' => 0];
        if (($data['commit'] ?? false) === true && !$errors) {
            if (!$examId) { query($db, 'INSERT INTO exam(teacher_id,title) VALUES(?,?)', [$teacherId, $title]); $examId = (int)$db->lastInsertId(); }
            foreach ($clean as $q) insert_question($db, $examId, $q);
            $result['imported'] = count($clean); $result['exam_id'] = $examId;
        }
    } elseif ($action === 'save_question') {
        // Add (no question_id) or edit one question. A new key re-grades every room that used it, because
        // results are computed from question.correct_answer when read.
        $exam = owned_exam($db, positive_id($data['exam_id'] ?? null), $teacherId);
        $q = clean_question($data);
        if (is_string($q)) reject('INVALID_QUESTION', $q);
        if (isset($data['question_id'])) {
            $qid = positive_id($data['question_id']);
            if (!query($db, 'SELECT id FROM question WHERE id=? AND exam_id=?', [$qid, $exam['id']])->fetchColumn()) reject('QUESTION_NOT_FOUND', 'Không tìm thấy câu hỏi.', 404);
            query($db, 'UPDATE question SET question_text=?,answer_A=?,answer_B=?,answer_C=?,answer_D=?,correct_answer=? WHERE id=?',
                [$q['question_text'], $q['answer_A'], $q['answer_B'], $q['answer_C'], $q['answer_D'], $q['correct_answer'], $qid]);
        } else {
            if ((int)query($db, 'SELECT COUNT(*) FROM question WHERE exam_id=?', [$exam['id']])->fetchColumn() >= 200) reject('TOO_MANY_QUESTIONS', 'Một bộ đề tối đa 200 câu.');
            $qid = insert_question($db, (int)$exam['id'], $q);
        }
        $result = ['question_id' => $qid, 'used' => (int)query($db, 'SELECT COUNT(*) FROM session_question WHERE question_id=?', [$qid])->fetchColumn()];
    } elseif ($action === 'remove_question') {
        if (($data['confirmed'] ?? null) !== true) reject('CONFIRM_REQUIRED', 'Hãy xác nhận thao tác xóa.');
        $qid = positive_id($data['question_id'] ?? null);
        $q = query($db, 'SELECT q.id,q.exam_id,q.question_no FROM question q JOIN exam e ON e.id=q.exam_id WHERE q.id=? AND e.teacher_id=?', [$qid, $teacherId])->fetch();
        if (!$q) reject('QUESTION_NOT_FOUND', 'Không tìm thấy câu hỏi.', 404);
        if (query($db, 'SELECT 1 FROM session_question WHERE question_id=? LIMIT 1', [$qid])->fetchColumn()) {
            reject('QUESTION_USED', 'Câu này đã dùng trong buổi kiểm tra nên không xóa được (vẫn sửa được).', 409);
        }
        if ((int)query($db, 'SELECT COUNT(*) FROM question WHERE exam_id=?', [$q['exam_id']])->fetchColumn() <= 1) reject('LAST_QUESTION', 'Bộ đề cần ít nhất 1 câu.', 409);
        query($db, 'DELETE FROM question WHERE id=?', [$qid]);
        // Close the gap so the set still reads Q1, Q2, Q3…, unless a later question was already asked:
        // its number is on old reports and must not change.
        $laterUsed = query($db, 'SELECT 1 FROM question q JOIN session_question sq ON sq.question_id=q.id WHERE q.exam_id=? AND q.question_no>? LIMIT 1', [$q['exam_id'], $q['question_no']])->fetchColumn();
        if (!$laterUsed) query($db, 'UPDATE question SET question_no=question_no-1 WHERE exam_id=? AND question_no>? ORDER BY question_no', [$q['exam_id'], $q['question_no']]);
        $result = ['removed' => $qid];
    } elseif ($action === 'rename_exam') {
        $exam = owned_exam($db, positive_id($data['exam_id'] ?? null), $teacherId);
        query($db, 'UPDATE exam SET title=? WHERE id=?', [field($data, 'title'), $exam['id']]);
    } elseif ($action === 'create_session') {
        $class = field($data, 'class_id', 60); $examId = positive_id($data['exam_id'] ?? null);
        $title = isset($data['title']) ? field($data, 'title') : "Kiểm tra lớp $class";
        $seconds = $data['auto_seconds'] ?? 30;
        if (is_bool($seconds) || !is_scalar($seconds) || !preg_match('/^[1-9][0-9]{0,3}$/D', (string)$seconds) || (int)$seconds > 3600) reject('INVALID_SECONDS', 'Thời gian mỗi câu phải từ 1 đến 3600 giây.');
        $pacing = $data['pacing'] ?? 'teacher';
        if (!in_array($pacing, ['teacher', 'self'], true)) reject('INVALID_PACING', 'Hình thức phải là teacher hoặc self.');
        if ($pacing === 'self' && !setting($db, 'self_paced')) reject('PACING_OFF', 'Quản trị viên đang tắt hình thức "Sinh viên tự làm".', 409);
        if ($pacing === 'self' && !pacing_ready($db)) reject('PACING_OFF', 'Chưa bật "Sinh viên tự làm" trên máy chủ (chạy tools/setup_db.sh).', 409);
        // The mode chosen in the top bar comes with the new room (one server-wide mode until tools/upgrade_rooms.php).
        $mode = rooms_ready($db) ? (($data['mode'] ?? 'wifi') === 'ble' ? 'ble' : 'wifi') : room_mode($db, null);
        if ($pacing === 'self' && $mode === 'ble') reject('PACING_BLE', 'Chế độ BLE chỉ dùng "Giáo viên điều khiển". Chuyển sang Wi-Fi để sinh viên tự làm.', 409);
        $result['session_id'] = create_room($db, $title, $class, $examId, $teacherId, (int)$seconds, $mode);
        if ($pacing === 'self') query($db, "UPDATE classroom_session SET pacing='self' WHERE id=?", [$result['session_id']]);
    } elseif ($action === 'retest_session') {
        // Kiểm tra lại a finished room: a new round with the same class and exam; the old results stay as they were.
        $old = owned_session($db, positive_id($data['session_id'] ?? null), $teacherId);
        if ($old['state'] !== 'FINISHED') reject('SESSION_NOT_FINISHED', 'Phòng này chưa kết thúc.', 409);
        $round = 1 + (int)query($db, 'SELECT COUNT(*) FROM classroom_session WHERE teacher_id=? AND class_id=? AND exam_id=?', [$teacherId, $old['class_id'], $old['exam_id']])->fetchColumn();
        $base = preg_replace('/ · lần \d+$/u', '', $old['title'] ?: "Kiểm tra lớp {$old['class_id']}");
        preg_match('/^.{0,108}/su', $base, $cut);  // title column is 120 characters; no mbstring on this PHP
        $sid = create_room($db, $cut[0] . " · lần $round", $old['class_id'], (int)$old['exam_id'], $teacherId, (int)$old['auto_seconds'], room_mode($db, $old));
        query($db, "UPDATE classroom_session SET state='LOGIN_OPEN' WHERE id=?", [$sid]);
        if (self_paced($old)) query($db, "UPDATE classroom_session SET pacing='self' WHERE id=?", [$sid]);
        $result['session_id'] = $sid;
    } elseif ($action === 'set_mode') {
        $newMode = field($data, 'mode', 4);
        if (!in_array($newMode, ['wifi', 'ble'], true)) reject('INVALID_MODE', 'Chế độ phải là wifi hoặc ble.');
        // The teacher's own running room switches (and the next room starts in this mode: the page sends it with
        // create_session). Before tools/upgrade_rooms.php there is one mode for the whole server.
        $myRoom = running_room($db, $teacherId);
        if ($myRoom && $newMode !== room_mode($db, $myRoom) && in_array($myRoom['state'], ['LOGIN_OPEN', 'QUESTION_ACTIVE'], true)) reject('MODE_BUSY', 'Hãy đóng đăng nhập và câu hỏi đang mở trước khi đổi chế độ.', 409);
        if ($myRoom && $newMode === 'ble' && self_paced($myRoom)) reject('PACING_BLE', 'Phòng "Sinh viên tự làm" chỉ chạy ở chế độ Wi-Fi.', 409);
        if (rooms_ready($db)) { if ($myRoom) query($db, 'UPDATE classroom_session SET mode=? WHERE id=?', [$newMode, $myRoom['id']]); }
        else {
            if (query($db, "SELECT 1 FROM classroom_session WHERE teacher_id<>? AND state<>'FINISHED' LIMIT 1", [$teacherId])->fetchColumn() && $newMode !== room_mode($db, null)) reject('ROOM_BUSY', 'Giáo viên khác đang có phòng chạy: chạy sudo bash tools/setup_db.sh để mỗi phòng có chế độ riêng.', 409);
            query($db, 'UPDATE app_state SET mode=? WHERE id=1', [$newMode]);
        }
        $result['mode'] = $newMode;
    } elseif (in_array($action, ['remove_class', 'remove_student', 'remove_exam', 'remove_session'], true)) {
        if (($data['confirmed'] ?? null) !== true) reject('CONFIRM_REQUIRED', 'Hãy xác nhận thao tác xóa.');
        if ($action === 'remove_class') {
            $class = field($data, 'class_id', 60);
            if (!query($db, 'SELECT student_id FROM student WHERE teacher_id=? AND class_id=? LIMIT 1', [$teacherId, $class])->fetchColumn() && !open_class($db, $teacherId, $class)) reject('CLASS_NOT_FOUND', 'Không tìm thấy lớp.', 404);
            if (profile_ready($db)) query($db, 'DELETE FROM teacher_class WHERE teacher_id=? AND class_id=?', [$teacherId, $class]);
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
                if ($session['state'] !== 'LOGIN_OPEN') reject('LOGIN_CLOSED', 'Chưa mở hoặc đã đóng đăng nhập.', 409);
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
        } elseif ($action === 'register') {
            // A phone whose MSSV is not on the room's list asks to join; the teacher approves it in the Phòng tab.
            if (!defined('CLICKER_PHONE_UID')) reject('PHONE_ONLY', 'Chỉ đăng ký được trên trang điện thoại.', 403);
            if (!join_ready($db) || !setting($db, 'self_join')) reject('JOIN_OFF', 'Lớp chưa bật tự đăng ký. Hãy báo giảng viên.', 409);
            if ($session['state'] !== 'LOGIN_OPEN') reject('LOGIN_CLOSED', 'Chưa mở hoặc đã đóng đăng nhập.', 409);
            $studentId = field($data, 'student_id', 20); $name = field($data, 'student_name', 120);
            if (!preg_match('/^[0-9]{1,20}$/D', $studentId)) reject('INVALID_STUDENT', 'MSSV chỉ gồm chữ số.');
            if (preg_match('/^[\s]*[=+@\-]/u', $name) || !preg_match('/\pL/u', $name)) reject('INVALID_NAME', 'Họ tên không hợp lệ.');
            if (query($db, 'SELECT 1 FROM session_student WHERE session_id=? AND device_uid=?', [$sid, $uid])->fetchColumn()) reject('DEVICE_IN_USE', 'Thiết bị đã đăng nhập trong buổi này.', 409);
            if (query($db, 'SELECT 1 FROM session_student WHERE session_id=? AND student_id=?', [$sid, $studentId])->fetchColumn()) reject('STUDENT_EXISTS', 'MSSV đã có trong lớp, hãy đăng nhập bằng MSSV.', 409);
            $other = query($db, 'SELECT class_id FROM student WHERE teacher_id=? AND student_id=?', [$session['teacher_id'], $studentId])->fetchColumn();
            if ($other !== false) reject('STUDENT_OTHER_CLASS', "MSSV đang thuộc lớp $other. Hãy báo giảng viên.", 409);
            if ((int)query($db, 'SELECT COUNT(*) FROM join_request WHERE session_id=?', [$sid])->fetchColumn() >= 300) reject('JOIN_FULL', 'Quá nhiều yêu cầu đang chờ.', 429);
            // The first phone to ask for an MSSV keeps it (a plain upsert would let a second phone overwrite that request).
            $owner = query($db, 'SELECT device_uid FROM join_request WHERE session_id=? AND student_id=? FOR UPDATE', [$sid, $studentId])->fetchColumn();
            if ($owner !== false && $owner !== $uid) reject('STUDENT_PENDING', 'MSSV này đang chờ duyệt trên thiết bị khác.', 409);
            try {
                query($db, 'DELETE FROM join_request WHERE session_id=? AND device_uid=?', [$sid, $uid]);   // a phone may correct its own request
                query($db, 'INSERT INTO join_request(session_id,device_uid,student_id,student_name) VALUES(?,?,?,?)', [$sid, $uid, $studentId, $name]);
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? 0) === 1062) reject('STUDENT_PENDING', 'MSSV này đang chờ duyệt trên thiết bị khác.', 409);
                throw $error;
            }
            $result = ['type' => 'JOIN_PENDING', 'session_id' => $sid, 'student_id' => $studentId];
        } elseif ($action === 'approve_join' || $action === 'reject_join') {
            // ✓ adds the student to the class list and logs the phone in; ✗ drops the request. student_ids: list, or "all".
            if (!join_ready($db)) reject('JOIN_OFF', 'Chưa bật tự đăng ký (chạy tools/setup_db.sh).', 409);
            $ids = $data['student_ids'] ?? null;
            $rows = query($db, 'SELECT device_uid,student_id,student_name FROM join_request WHERE session_id=? ORDER BY created_at FOR UPDATE', [$sid])->fetchAll();
            if ($ids !== 'all') {
                if (!is_array($ids) || !$ids || count($ids) > 300) reject('INVALID_INPUT', 'Danh sách MSSV không hợp lệ.');
                $want = array_flip(array_map('strval', $ids));
                $rows = array_values(array_filter($rows, fn($r) => isset($want[$r['student_id']])));
            }
            $done = 0; $skipped = [];
            foreach ($rows as $r) {
                query($db, 'DELETE FROM join_request WHERE session_id=? AND device_uid=?', [$sid, $r['device_uid']]);
                if ($action === 'reject_join') { $done++; continue; }
                if ($session['state'] === 'FINISHED') { $skipped[] = $r['student_id']; continue; }
                $taken = query($db, 'SELECT 1 FROM session_student WHERE session_id=? AND (student_id=? OR device_uid=?)', [$sid, $r['student_id'], $r['device_uid']])->fetchColumn()
                    || query($db, 'SELECT 1 FROM student WHERE teacher_id=? AND student_id=?', [$teacherId, $r['student_id']])->fetchColumn();
                if ($taken) { $skipped[] = $r['student_id']; continue; }
                $no = 1 + (int)query($db, 'SELECT COALESCE(MAX(student_no),0) FROM student WHERE teacher_id=? AND class_id=?', [$teacherId, $session['class_id']])->fetchColumn();
                $no = max($no, 1 + (int)query($db, 'SELECT COALESCE(MAX(student_no),0) FROM session_student WHERE session_id=?', [$sid])->fetchColumn());
                query($db, 'INSERT INTO student(teacher_id,student_id,student_no,student_name,class_id) VALUES(?,?,?,?,?)', [$teacherId, $r['student_id'], $no, $r['student_name'], $session['class_id']]);
                query($db, 'INSERT INTO session_student(session_id,student_id,student_no,student_name,device_uid) VALUES(?,?,?,?,?)', [$sid, $r['student_id'], $no, $r['student_name'], $r['device_uid']]);
                $done++;
            }
            if ($action === 'approve_join' && $done && open_class($db, $teacherId, $session['class_id'])) renumber_by_mssv($db, $teacherId, $session['class_id'], $sid);
            touch_room($db, $sid);
            $result = ['done' => $done, 'skipped' => $skipped];
        } elseif ($action === 'answer') {
            $qid = positive_id($data['question_id'] ?? null); $answer = field($data, 'answer', 1);
            if (!in_array($answer, ['A', 'B', 'C', 'D'], true)) reject('INVALID_ANSWER', 'Đáp án phải thuộc A/B/C/D.');
            $studentId = query($db, 'SELECT student_id FROM session_student WHERE session_id=? AND device_uid=?', [$sid, $uid])->fetchColumn();
            if ($studentId === false) reject('LOGIN_REQUIRED', 'Thiết bị chưa đăng nhập.', 403);
            $existing = query($db, 'SELECT selected_answer FROM student_response WHERE session_id=? AND student_id=? AND question_id=?', [$sid, $studentId, $qid])->fetchColumn();
            // Retry an already committed response even after the question/session closes.
            if ($existing !== false && $existing !== $answer) reject('ANSWER_LOCKED', 'Đáp án đã lưu, không thể đổi.', 409);
            if ($existing === false) {
                if (self_paced($session)) {
                    // Self-paced: only this student's current question, or one that timed out a moment ago.
                    $walk = $session['state'] === 'QUESTION_ACTIVE'
                        ? self_walk($plan = self_plan($db, $session), self_answers($db, $sid, $studentId)[$studentId] ?? []) : null;
                    $open = $walk && $walk['question'] && (int)$walk['question']['id'] === $qid;
                    $grace = !$open && $walk && isset($walk['late'][$qid]) && $plan['now'] <= $walk['late'][$qid] + ANSWER_GRACE_MS / 1000;
                } else {
                    $open = $session['state'] === 'QUESTION_ACTIVE' && (int)$session['current_question_id'] === $qid;
                    // Grace window: a press sent just before NEXT/AUTO/KẾT THÚC closed the question still counts.
                    $grace = !$open && query($db, 'SELECT 1 FROM session_question WHERE session_id=? AND question_id=? AND closed_at >= NOW(3) - INTERVAL ' . ANSWER_GRACE_MS . '000 MICROSECOND', [$sid, $qid])->fetchColumn();
                }
                if (!$open && !$grace) reject('QUESTION_CLOSED', 'Câu hỏi chưa mở hoặc đã đóng.', 409);
                if (!insert_once($db, 'INSERT INTO student_response(session_id,student_id,question_id,selected_answer,device_uid) VALUES(?,?,?,?,?)', [$sid, $studentId, $qid, $answer, $uid])) {
                    $existing = query($db, 'SELECT selected_answer FROM student_response WHERE session_id=? AND student_id=? AND question_id=?', [$sid, $studentId, $qid])->fetchColumn();
                    if ($existing !== $answer) reject('ANSWER_LOCKED', 'Đáp án đã lưu, không thể đổi.', 409);
                }
            }
            $result = ['type' => 'ANSWER_ACK', 'session_id' => $sid, 'question_id' => $qid, 'answer' => $answer, 'duplicate' => $existing !== false];
        } else {
            // KẾT THÚC on a room that just ended by itself (self-paced: everyone finished) is simply done, so the
            // automatic finish committed by auto_tick above is kept instead of being rolled back with an error.
            $session = session_row($db, $sid);
            if ($session['state'] === 'FINISHED' && in_array($action, ['close_question', 'finish_session'], true)) { $db->commit(); reply(['ok' => true, 'already' => true]); }
            if ($session['state'] === 'FINISHED') reject('SESSION_FINISHED', 'Buổi học đã kết thúc.', 409);
            if ($session['auto_mode'] && in_array($action, ['open_login', 'close_login', 'start_question', 'next_question', 'auto_start'], true)) reject('AUTO_RUNNING', 'Đang chạy AUTO. Bấm AUTO lần nữa để dừng trước.', 409);
            if ($action === 'open_login' || $action === 'close_login') {
                if ($session['state'] === 'QUESTION_ACTIVE') reject('QUESTION_ACTIVE', 'Hãy đóng câu đang mở trước.', 409);
                query($db, 'UPDATE classroom_session SET state=? WHERE id=?', [$action === 'open_login' ? 'LOGIN_OPEN' : 'READY', $sid]);
            } elseif (self_paced($session) && in_array($action, ['next_question', 'auto_start', 'auto_stop'], true)) {
                reject('SELF_PACED', 'Phòng "Sinh viên tự làm": mỗi người tự sang câu, không dùng NEXT/AUTO.', 409);
            } elseif ($action === 'start_question' && self_paced($session)) {
                // Self-paced Bắt đầu: login closes and every question opens now; each student walks them at their pace.
                if ($session['state'] === 'QUESTION_ACTIVE') reject('QUESTION_ACTIVE', 'Bài đã bắt đầu.', 409);
                if (room_mode($db, $session) === 'ble') reject('PACING_BLE', 'Chế độ BLE chỉ dùng "Giáo viên điều khiển". Chuyển sang Wi-Fi trước.', 409);
                $ids = query($db, 'SELECT q.id FROM question q LEFT JOIN session_question sq ON sq.session_id=? AND sq.question_id=q.id WHERE q.exam_id=? AND sq.question_id IS NULL ORDER BY q.question_no', [$sid, $session['exam_id']])->fetchAll(PDO::FETCH_COLUMN);
                if (!$ids) reject('NO_NEXT_QUESTION', 'Đã dùng hết câu hỏi của bộ đề.', 409);
                foreach ($ids as $id) query($db, 'INSERT INTO session_question(session_id,question_id,opened_at) VALUES(?,?,NOW(3))', [$sid, $id]);
                query($db, "UPDATE classroom_session SET state='QUESTION_ACTIVE',current_question_id=? WHERE id=?", [$ids[0], $sid]);
                $result['question_id'] = (int)$ids[0];
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
    $logged = ['import_students' => fn() => 'Nhập ' . ($result['imported'] ?? 0) . ' sinh viên', 'import_exam' => fn() => 'Nhập bộ đề "' . ($data['title'] ?? '') . '"',
        'import_questions' => fn() => 'Nhập ' . ($result['imported'] ?? 0) . ' câu hỏi', 'create_session' => fn() => 'Tạo phòng "' . ($data['title'] ?? '') . '" lớp ' . ($data['class_id'] ?? ''),
        'remove_class' => fn() => 'Xoá lớp ' . ($data['class_id'] ?? ''), 'remove_student' => fn() => 'Xoá sinh viên ' . ($data['student_id'] ?? ''),
        'remove_exam' => fn() => 'Xoá bộ đề #' . ($data['exam_id'] ?? ''), 'remove_session' => fn() => 'Xoá buổi #' . ($data['session_id'] ?? ''),
        'set_mode' => fn() => 'Chuyển chế độ ' . ($result['mode'] ?? ''), 'class_create' => fn() => 'Tạo lớp tự đăng ký ' . ($data['class_id'] ?? ''), 'approve_join' => fn() => 'Duyệt ' . ($result['done'] ?? 0) . ' sinh viên vào lớp'];
    if (!$deviceAction && isset($logged[$action]) && $result !== null) audit($db, $teacherId, $actorName, $action, $logged[$action]());
    reply(['ok' => true] + $result);
} catch (ApiError $error) {
    if ($db?->inTransaction()) $db->rollBack();
    reply(['ok' => false, 'code' => $error->errorCode, 'error' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    if ($db?->inTransaction()) $db->rollBack();
    error_log('Clicker: ' . $error->getMessage());
    reply(['ok' => false, 'code' => 'SERVER_ERROR', 'error' => 'Server chưa xử lý được yêu cầu. Hãy thử lại.'], 500);
}
