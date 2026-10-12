<?php
declare(strict_types=1);
// Chat assistant for the public site. The browser never sees an API key: it posts the conversation here and this
// file forwards it to the configured model.
//   GET  assistant.php?status=1          {"enabled": bool}
//   POST assistant.php  {"messages":[{"role":"user"|"assistant","text":"…"}], "lang":"vi|en|zh"}  → {"ok":true,"reply":"…"}
// Config (prod-config.php):
//   ai_provider   'gemini' (default) or 'openai' — any OpenAI-compatible server: Ollama, vLLM, llama.cpp, LM Studio
//   gemini_api_key, gemini_model (default gemini-flash-latest, which Google moves to its newest Flash model)
//   ai_base_url (e.g. http://127.0.0.1:11434/v1), ai_model, ai_api_key (optional)   ← a self-trained model plugs in here
require_once dirname(__DIR__) . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const MAX_TURNS = 12, MAX_CHARS = 1000, MAX_TOTAL = 6000;

// php-mbstring is not installed here; count and cut UTF-8 characters with PCRE instead.
function ulen(string $s): int { return preg_match_all('/./su', $s); }
function ucut(string $s, int $n): string { preg_match('/^.{0,' . $n . '}/su', $s, $m); return $m[0]; }

function out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function ai_ready(array $c): bool {
    return ($c['ai_provider'] ?? 'gemini') === 'openai' ? !empty($c['ai_base_url']) && !empty($c['ai_model']) : !empty($c['gemini_api_key']);
}
// Same stream-wrapper HTTP as oauth.php (no php-curl here). Returns [status, decoded body].
function post_json(string $url, array $body, array $headers): array {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 45, 'ignore_errors' => true,
        'header' => implode("\r\n", array_merge(['Content-Type: application/json'], $headers)) . "\r\n",
        'content' => json_encode($body, JSON_UNESCAPED_UNICODE)]]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
    return [$status, $raw === false ? null : json_decode($raw, true)];
}

function system_prompt(string $lang): string {
    $language = ['vi' => 'Vietnamese', 'en' => 'English', 'zh' => 'Simplified Chinese'][$lang] ?? 'Vietnamese';
    return <<<TXT
You are "Trợ lý Clicker", the assistant on the website of Clicker, a classroom quiz system built as a university IoT project.
Answer in {$language} unless the user writes in another language, then use theirs. Be warm, short and concrete: 1–4 short
paragraphs or a short list. Never invent features, prices, numbers or policies that are not listed below; if you don't know,
say so and suggest the teacher dashboard or the FAQ on the pricing page.

What Clicker is:
- Teachers run live multiple-choice quizzes (answers A–D). Every student answers with a handheld clicker; results appear on
  the classroom screen within about a second and are graded automatically. Slogan: "Click See Teach".
- Devices: nRF52840 Student Tag (12 keys, BLE advertising, runs on battery) through a USB gateway plugged into the teacher's
  computer, or any phone / laptop browser (Wi-Fi mode, room code). Several teachers can run rooms at the same time.
- Students have no accounts: they enter their student ID (MSSV) on the clicker when the teacher opens sign-in. Only teachers
  have accounts: username + 8-digit password, or Google sign-in.
- The server (Apache, PHP, MariaDB) runs on the teacher's laptop in the classroom; no Internet is needed during class. Data
  stays on that server. Tested with 40 clickers at once (60 in a load test), under a second from click to screen.
- Teacher flow: import the class list and a question bank from Excel/CSV/text → create a room → open sign-in → start
  (or AUTO with seconds per question) → NEXT → END. Live dashboard, per-question analysis, results table with privacy mode,
  CSV/Excel export.
- AI quiz authoring (create a quiz from a description, from course material, or convert old Word/Excel quizzes) is COMING SOON,
  not available yet.
- Plans. Every price is VND PER ACCOUNT PER MONTH. "Yearly" means the same monthly price but paid 12 months at once.
  | Plan         | paid monthly | paid yearly (per month) | total per year |
  | Free         | 0            | 0                       | 0              |
  | Essentials   | 99,000       | 79,000                  | 948,000        |
  | Pro          | 179,000      | 149,000                 | 1,788,000      |
  | Pro, 2+ accounts (10% off) | 161,000 | 134,000         | 1,608,000 each |
  | Organization | from 129,000 | from 119,000            | quote on request |
  Students are always free. Essentials and Pro have a 14-day free trial. Never say a monthly price is a yearly price.
- Payment: online payment is NOT open yet. Do not invent a payment method, contact person or process; just say payment
  will open later and point to pricing.html.
- Pages: sign in / create an account at login.html, pricing at pricing.html, privacy at privacy.html, phone clicker at ../phone.html.

You cannot see or change anyone's classes, quizzes or scores, and you have no access to the database. Do not ask for passwords.
If asked something unrelated to teaching, quizzes or Clicker, answer briefly and steer back.
TXT;
}

$config = require (getenv('CLICKER_CONFIG') ?: '/home/pham-van-de/Desktop/IOT/clicker/.local/prod-config.php');
if (public_host($config) && !secure_request($config)) out(['ok' => false, 'code' => 'HTTPS_REQUIRED'], 403);
// The admin can switch the assistant off on the web (app_setting "assistant").
$switchOn = function () use ($config): bool {
    try { $db = new PDO($config['dsn'], $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); return setting($db, 'assistant'); }
    catch (Throwable) { return true; }
};
if (isset($_GET['status'])) out(['enabled' => ai_ready($config) && $switchOn()]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'code' => 'METHOD'], 405);
if (!ai_ready($config) || !$switchOn()) out(['ok' => false, 'code' => 'AI_NOT_CONFIGURED'], 503);

try {
    $db = new PDO($config['dsn'], $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    // The model costs money per call: at most 20 messages per visitor every 10 minutes.
    rate_limit($db, 'ai_chat', client_source($config), 20, 600);

    $raw = file_get_contents('php://input', false, null, 0, 65537);
    $in = json_decode((string)$raw, true);
    $lang = in_array($in['lang'] ?? '', ['vi', 'en', 'zh'], true) ? $in['lang'] : 'vi';
    $turns = [];
    $total = 0;
    foreach (array_slice(is_array($in['messages'] ?? null) ? $in['messages'] : [], -MAX_TURNS) as $m) {
        $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $text = trim((string)($m['text'] ?? ''));
        if ($text === '' || !preg_match('//u', $text)) continue;
        if (ulen($text) > MAX_CHARS) $text = ucut($text, MAX_CHARS);
        $total += ulen($text);
        $turns[] = [$role, $text];
    }
    if (!$turns || end($turns)[0] !== 'user') out(['ok' => false, 'code' => 'EMPTY'], 400);
    if ($total > MAX_TOTAL) $turns = array_slice($turns, -4);

    $provider = $config['ai_provider'] ?? 'gemini';
    if ($provider === 'openai') {
        $messages = [['role' => 'system', 'content' => system_prompt($lang)]];
        foreach ($turns as [$r, $t]) $messages[] = ['role' => $r, 'content' => $t];
        [$status, $res] = post_json(rtrim($config['ai_base_url'], '/') . '/chat/completions',
            ['model' => $config['ai_model'], 'messages' => $messages, 'temperature' => 0.5, 'max_tokens' => 700],
            !empty($config['ai_api_key']) ? ['Authorization: Bearer ' . $config['ai_api_key']] : []);
        $reply = $res['choices'][0]['message']['content'] ?? '';
    } else {
        $model = preg_replace('/[^A-Za-z0-9._-]/', '', $config['gemini_model'] ?? 'gemini-flash-latest');
        $contents = array_map(fn($t) => ['role' => $t[0] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $t[1]]]], $turns);
        $body = ['systemInstruction' => ['parts' => [['text' => system_prompt($lang)]]], 'contents' => $contents,
                 'generationConfig' => ['temperature' => 0.5, 'maxOutputTokens' => 700]];
        // The free tier often answers 503 "high demand" or 429 for a moment: retry once, then try the lighter model.
        foreach ([$model, $model, 'gemini-flash-lite-latest'] as $i => $try) {
            if ($i > 0) usleep(1200000);
            [$status, $res] = post_json("https://generativelanguage.googleapis.com/v1beta/models/$try:generateContent",
                $body, ['x-goog-api-key: ' . $config['gemini_api_key']]);
            if (!in_array($status, [429, 500, 503], true)) break;
        }
        $reply = implode('', array_map(fn($p) => $p['text'] ?? '', $res['candidates'][0]['content']['parts'] ?? []));
    }
    $reply = trim($reply);
    if ($status !== 200 || $reply === '') {
        // Provider's own error message only; the key is never logged.
        error_log("Clicker assistant: $provider HTTP $status " . substr((string)($res['error']['message'] ?? json_encode($res['error'] ?? '')), 0, 200));
        out(['ok' => false, 'code' => in_array($status, [429, 503], true) ? 'AI_BUSY' : 'AI_UPSTREAM'], 502);
    }
    out(['ok' => true, 'reply' => $reply]);
} catch (ApiError $e) {
    out(['ok' => false, 'code' => $e->errorCode], $e->status);
} catch (Throwable $e) {
    error_log('Clicker assistant: ' . $e->getMessage());
    out(['ok' => false, 'code' => 'SERVER_ERROR'], 500);
}
