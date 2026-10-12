<?php
declare(strict_types=1);
// Checks the six-digit room code typed on the public page (landing/index.html). It only answers whether the code
// matches a running room; it never lists rooms or reveals a code.
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function answer(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $config = require (getenv('CLICKER_CONFIG') ?: '/home/pham-van-de/Desktop/IOT/clicker/.local/prod-config.php');
    if (public_host($config) && !secure_request($config)) reject('HTTPS_REQUIRED', 'Trang công khai chỉ hỗ trợ HTTPS.', 403);
    $code = (string)($_GET['code'] ?? '');
    if (!preg_match('/^[0-9]{6}$/D', $code)) reject('ROOM_CODE', 'Mã phòng gồm 6 chữ số.');
    $db = new PDO($config['dsn'], $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $room = null;   // any running room (several teachers can run one each); the code is derived from the room id
    foreach (query($db, "SELECT id,state FROM classroom_session WHERE state<>'FINISHED' ORDER BY id DESC")->fetchAll() as $r)
        if (hash_equals(room_code($config, (int)$r['id']), $code)) { $room = $r; break; }
    if (!$room) {
        // Only wrong codes count: a whole class behind one school IP may check the right code at once.
        // 30 wrong tries a minute per visitor still makes guessing one of a million codes impractical.
        rate_limit($db, 'room_code', client_source($config), 30, 60);
        reject('ROOM_NOT_FOUND', 'Mã phòng không đúng hoặc phòng chưa mở.', 404);
    }
    answer(['ok' => true, 'login_open' => $room['state'] === 'LOGIN_OPEN']);
} catch (ApiError $error) {
    answer(['ok' => false, 'code' => $error->errorCode, 'error' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    error_log('Clicker room code: ' . $error->getMessage());
    answer(['ok' => false, 'code' => 'SERVER_ERROR', 'error' => 'Server chưa xử lý được yêu cầu. Hãy thử lại.'], 500);
}
