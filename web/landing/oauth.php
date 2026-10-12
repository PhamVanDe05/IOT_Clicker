<?php
declare(strict_types=1);
// Google / Facebook sign-in for teachers (authorization code + PKCE).
// Lives in landing/ with the rest of the public site; the session cookie is scoped to /clicker/ so it still works.
//   oauth.php?p=google|facebook   start: send the browser to the provider
//   oauth.php?code=…&state=…      provider callback: verify, find or create the teacher, open the session
//   oauth.php?status=1            which providers are configured (JSON, for the login page)
// Config keys (prod-config.php): google_client_id, google_client_secret, facebook_app_id, facebook_app_secret.
require_once dirname(__DIR__) . '/lib.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const OAUTH_COOKIE = 'clicker_oauth';
const LOGIN_PAGE = 'login.html';

function back_to_login(string $code, string $provider = ''): never {
    header('Location: ' . LOGIN_PAGE . '?err=' . rawurlencode($code) . ($provider ? '&p=' . rawurlencode($provider) : ''), true, 303);
    exit;
}
function b64url(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }
function provider_ready(array $config, string $p): bool {
    return $p === 'google' ? !empty($config['google_client_id']) && !empty($config['google_client_secret'])
        : ($p === 'facebook' && !empty($config['facebook_app_id']) && !empty($config['facebook_app_secret']));
}
// Providers only accept redirect URIs registered in advance: localhost, or the public Tailscale name over HTTPS.
function redirect_uri(array $config): string {
    $host = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    if (public_host($config) && secure_request($config)) return 'https://' . $config['internet_host'] . '/clicker/landing/oauth.php';
    if (in_array($host, ['localhost', '127.0.0.1'], true)) return 'http://localhost/clicker/landing/oauth.php';
    back_to_login('OAUTH_HOST');
}
// The state cookie is SameSite=Lax: the teacher session cookie is Strict and is not sent on the provider's redirect back.
function state_key(array $config): string { return hash('sha256', 'clicker-oauth|' . ($config['phone_signing_key'] ?? $config['db_password']), true); }
function put_state(array $config, array $state): void {
    $body = b64url(json_encode($state, JSON_THROW_ON_ERROR));
    setcookie(OAUTH_COOKIE, $body . '.' . b64url(hash_hmac('sha256', $body, state_key($config), true)), ['expires' => time() + 600,
        'path' => '/clicker/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => secure_request($config)]);
}
function take_state(array $config): ?array {
    $raw = $_COOKIE[OAUTH_COOKIE] ?? '';
    setcookie(OAUTH_COOKIE, '', ['expires' => time() - 3600, 'path' => '/clicker/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => secure_request($config)]);
    [$body, $mac] = array_pad(explode('.', $raw, 2), 2, '');
    if ($body === '' || !hash_equals(b64url(hash_hmac('sha256', $body, state_key($config), true)), $mac)) return null;
    $state = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    return is_array($state) && ($state['exp'] ?? 0) > time() ? $state : null;
}
// HTTPS call to the provider; no php-curl on this machine, so use the stream wrapper (peer verification is on by default).
function http_json(string $url, ?array $post = null): array {
    $options = ['method' => $post ? 'POST' : 'GET', 'timeout' => 10, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"];
    if ($post) { $options['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n"; $options['content'] = http_build_query($post); }
    $body = @file_get_contents($url, false, stream_context_create(['http' => $options]));
    if ($body === false) back_to_login('OAUTH_NETWORK');
    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
}

$config = require (getenv('CLICKER_CONFIG') ?: '/home/pham-van-de/Desktop/IOT/clicker/.local/prod-config.php');
if (public_host($config) && !secure_request($config)) back_to_login('HTTPS_REQUIRED');

if (isset($_GET['status'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['google' => provider_ready($config, 'google'), 'facebook' => provider_ready($config, 'facebook')]);
    exit;
}

$db = null;
try {
    $db = new PDO($config['dsn'], $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);

    // ---- start ----
    if (isset($_GET['p'])) {
        $p = (string)$_GET['p'];
        if (!in_array($p, ['google', 'facebook'], true)) back_to_login('OAUTH_PROVIDER');
        if (!provider_ready($config, $p)) back_to_login('OAUTH_NOT_CONFIGURED', $p);
        // Opened through the LAN address (e.g. http://192.168.x.x): providers refuse private IPs as redirect URIs,
        // so restart the sign-in on the public HTTPS name instead of failing.
        $host = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        if (!public_host($config) && !in_array($host, ['localhost', '127.0.0.1'], true) && !empty($config['internet_host'])) {
            header('Location: https://' . $config['internet_host'] . '/clicker/landing/oauth.php?p=' . rawurlencode($p), true, 303);
            exit;
        }
        rate_limit($db, 'oauth_start', client_source($config), 20, 900);
        $uri = redirect_uri($config);
        $state = ['p' => $p, 'state' => b64url(random_bytes(24)), 'verifier' => b64url(random_bytes(48)),
            'nonce' => b64url(random_bytes(16)), 'uri' => $uri, 'exp' => time() + 600];
        put_state($config, $state);
        $challenge = b64url(hash('sha256', $state['verifier'], true));
        $url = $p === 'google'
            ? 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(['client_id' => $config['google_client_id'],
                'redirect_uri' => $uri, 'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $state['state'],
                'nonce' => $state['nonce'], 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'prompt' => 'select_account'])
            : 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query(['client_id' => $config['facebook_app_id'],
                'redirect_uri' => $uri, 'response_type' => 'code', 'scope' => 'public_profile,email', 'state' => $state['state'],
                'code_challenge' => $challenge, 'code_challenge_method' => 'S256']);
        header('Location: ' . $url, true, 303);
        exit;
    }

    // ---- callback ----
    $state = take_state($config);
    if (!$state || !is_string($_GET['state'] ?? null) || !hash_equals($state['state'], $_GET['state'])) back_to_login('OAUTH_STATE');
    $p = $state['p'];
    if (isset($_GET['error']) || !is_string($_GET['code'] ?? null) || $_GET['code'] === '') back_to_login('OAUTH_CANCELLED', $p);
    rate_limit($db, 'oauth_callback', client_source($config), 20, 900);
    $code = $_GET['code'];

    if ($p === 'google') {
        $token = http_json('https://oauth2.googleapis.com/token', ['code' => $code, 'client_id' => $config['google_client_id'],
            'client_secret' => $config['google_client_secret'], 'redirect_uri' => $state['uri'], 'grant_type' => 'authorization_code',
            'code_verifier' => $state['verifier']]);
        // The ID token came straight from Google's token endpoint over TLS with our secret, so its claims are
        // trusted without a signature check (OpenID Connect Core 3.1.3.7); audience, issuer, expiry and nonce still are.
        $parts = explode('.', (string)($token['id_token'] ?? ''));
        $claims = count($parts) === 3 ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
        // Log why the exchange failed (Google's error code, or which claim check did not pass); never the secret or tokens.
        $why = !is_array($claims) ? 'token endpoint: ' . ($token['error'] ?? 'no id_token') . ' ' . ($token['error_description'] ?? '')
            : (($claims['aud'] ?? '') !== $config['google_client_id'] ? 'aud mismatch'
            : (!in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true) ? 'iss ' . ($claims['iss'] ?? '')
            : (($claims['exp'] ?? 0) < time() ? 'expired' : (!hash_equals($state['nonce'], (string)($claims['nonce'] ?? '')) ? 'nonce mismatch' : ''))));
        if ($why !== '') { error_log('Clicker OAuth google: ' . $why . ' | redirect_uri=' . $state['uri']); back_to_login('OAUTH_TOKEN', $p); }
        $profile = ['id' => (string)($claims['sub'] ?? ''), 'name' => $claims['name'] ?? '',
            'email' => !empty($claims['email_verified']) ? ($claims['email'] ?? null) : null];
    } else {
        $token = http_json('https://graph.facebook.com/v19.0/oauth/access_token?' . http_build_query(['client_id' => $config['facebook_app_id'],
            'client_secret' => $config['facebook_app_secret'], 'redirect_uri' => $state['uri'], 'code' => $code, 'code_verifier' => $state['verifier']]));
        $access = (string)($token['access_token'] ?? '');
        if ($access === '') back_to_login('OAUTH_TOKEN', $p);
        $me = http_json('https://graph.facebook.com/v19.0/me?' . http_build_query(['fields' => 'id,name,email', 'access_token' => $access,
            'appsecret_proof' => hash_hmac('sha256', $access, $config['facebook_app_secret'])]));
        // Facebook only returns an e-mail it has confirmed.
        $profile = ['id' => (string)($me['id'] ?? ''), 'name' => $me['name'] ?? '', 'email' => $me['email'] ?? null];
    }

    $account = oauth_account($db, $p, $profile);
    if (admin_ready($db) && query($db, 'SELECT disabled_at IS NOT NULL FROM teacher_account WHERE id=?', [$account['id']])->fetchColumn()) back_to_login('ACCOUNT_DISABLED');

    $GLOBALS['clientIp'] = client_source($config);
    audit($db, (int)$account['id'], $account['username'], 'login', 'Đăng nhập bằng ' . ucfirst($p));
    // Same session the password login opens (api.php start_teacher_session).
    session_name('clicker_teacher');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => secure_request($config), 'path' => '/clicker/']);
    session_start();
    session_regenerate_id(true);
    $_SESSION = ['teacher_id' => (int)$account['id'], 'teacher_username' => $account['username'],
        'csrf' => bin2hex(random_bytes(24)), 'created_at' => time(), 'last_activity' => time()];
    session_write_close();
} catch (ApiError $error) {
    back_to_login($error->errorCode);
} catch (Throwable $error) {
    error_log('Clicker OAuth: ' . $error->getMessage());
    back_to_login('SERVER_ERROR');
}
// Leave through a page of our own: a Strict cookie is not sent on a redirect that started at the provider.
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=<?= LOGIN_PAGE ?>#welcome">
<title>Đang đăng nhập…</title><script>location.replace('<?= LOGIN_PAGE ?>#welcome')</script>
