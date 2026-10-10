<?php
declare(strict_types=1);
// Browser identity uses a signed HttpOnly cookie; the ESP32 device key stays on the server.
require_once __DIR__ . '/lib.php';
$config = require (getenv('CLICKER_CONFIG') ?: '/home/pham-van-de/Desktop/IOT/clicker/.local/prod-config.php');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (public_host($config)) {
    if (!secure_request($config)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Trang công khai chỉ hỗ trợ HTTPS.']); exit; }
    header('Strict-Transport-Security: max-age=31536000');
}
$signingKey = $config['phone_signing_key'];
$cookie = explode('.', $_COOKIE['clicker_device'] ?? '');
$uid = $cookie[0] ?? '';
$valid = preg_match('/^PHONE-[A-F0-9]{32}$/D', $uid) && count($cookie) === 2
    && hash_equals(hash_hmac('sha256', $uid, $signingKey), $cookie[1]);
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$valid) $uid = 'PHONE-' . strtoupper(bin2hex(random_bytes(16)));
    setcookie('clicker_device', $uid . '.' . hash_hmac('sha256', $uid, $signingKey), [
        'expires' => time() + 2592000, 'path' => '/clicker/', 'httponly' => true, 'samesite' => 'Strict',
        'secure' => secure_request($config),
    ]);
    echo json_encode(['ok' => true, 'device_uid' => $uid, 'csrf' => hash_hmac('sha256', 'csrf:' . $uid, $signingKey)]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$valid || !hash_equals(hash_hmac('sha256', 'csrf:' . $uid, $signingKey), $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403); echo json_encode(['ok' => false, 'code' => 'PHONE_AUTH', 'error' => 'Hãy tải lại trang để kết nối thiết bị.']); exit;
}
$raw = file_get_contents('php://input', false, null, 0, 1048577);
$data = json_decode($raw, true);
if (strlen($raw) > 1048576 || !is_array($data) || !in_array($data['action'] ?? '', ['poll', 'login', 'answer'], true)) {
    http_response_code(400); echo json_encode(['ok' => false, 'code' => 'ACTION', 'error' => 'Lệnh thiết bị không hợp lệ.']); exit;
}
define('CLICKER_PHONE_UID', $uid);
require __DIR__ . '/api.php';
