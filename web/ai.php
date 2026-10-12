<?php
declare(strict_types=1);
// AI question generation ("Tạo đề bằng AI"). Which model answers, first match wins (all in prod-config.php):
//   quiz_ai_base_url + quiz_ai_model (+ quiz_ai_key)  a model of your own (e.g. a friend's fine-tuned model) behind any
//                                                     OpenAI-compatible server: vLLM, Ollama, LM Studio, Groq…
//   ai_provider = 'openai' + ai_base_url + ai_model    the chat assistant's OpenAI-compatible model
//   gemini_api_key (+ gemini_model)                    Google Gemini (default)
// The server keeps every key; the browser only sends the course text and receives checked questions.

const QUIZ_LEVELS = ['de' => 'easy (recall of definitions and facts)', 'tb' => 'medium (understanding and simple application)',
    'kho' => 'hard (analysis, multi-step reasoning, calculations)', 'nangcao' => 'advanced (tricky cases, synthesis across topics)',
    'tron' => 'a balanced mix: about 30% easy, 40% medium, 20% hard, 10% advanced'];

function quiz_ai_name(array $c): ?string {
    if (!empty($c['quiz_ai_base_url']) && !empty($c['quiz_ai_model'])) return (string)$c['quiz_ai_model'];
    if (($c['ai_provider'] ?? 'gemini') === 'openai' && !empty($c['ai_base_url']) && !empty($c['ai_model'])) return (string)$c['ai_model'];
    if (!empty($c['gemini_api_key'])) return (string)($c['gemini_model'] ?? 'gemini-flash-latest');
    return null;
}

function ai_http(string $url, array $body, array $headers, int $timeout = 120): array {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => $timeout, 'ignore_errors' => true,
        'header' => implode("\r\n", array_merge(['Content-Type: application/json'], $headers)) . "\r\n",
        'content' => json_encode($body, JSON_UNESCAPED_UNICODE)]]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
    return [$status, $raw === false ? null : json_decode($raw, true)];
}

// One JSON answer from the configured model. Returns the decoded JSON or rejects with a readable error.
function ai_json(array $c, string $system, string $user): array {
    $custom = !empty($c['quiz_ai_base_url']) && !empty($c['quiz_ai_model']);
    if ($custom || (($c['ai_provider'] ?? 'gemini') === 'openai' && !empty($c['ai_base_url']))) {
        $base = $custom ? $c['quiz_ai_base_url'] : $c['ai_base_url'];
        $key = $custom ? ($c['quiz_ai_key'] ?? '') : ($c['ai_api_key'] ?? '');
        [$status, $res] = ai_http(rtrim((string)$base, '/') . '/chat/completions',
            ['model' => $custom ? $c['quiz_ai_model'] : $c['ai_model'], 'temperature' => 0.6, 'max_tokens' => 8000,
             'response_format' => ['type' => 'json_object'],
             'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]]],
            $key ? ['Authorization: Bearer ' . $key] : []);
        $text = $res['choices'][0]['message']['content'] ?? '';
        $provider = $custom ? 'quiz model' : 'openai';
    } else {
        $model = preg_replace('/[^A-Za-z0-9._-]/', '', $c['gemini_model'] ?? 'gemini-flash-latest');
        $body = ['systemInstruction' => ['parts' => [['text' => $system]]], 'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                 'generationConfig' => ['temperature' => 0.6, 'maxOutputTokens' => 16000, 'responseMimeType' => 'application/json']];
        foreach ([$model, $model, 'gemini-flash-lite-latest'] as $i => $try) {
            if ($i > 0) usleep(1500000);
            [$status, $res] = ai_http("https://generativelanguage.googleapis.com/v1beta/models/$try:generateContent", $body, ['x-goog-api-key: ' . $c['gemini_api_key']]);
            if (!in_array($status, [429, 500, 503], true)) break;
        }
        $text = implode('', array_map(fn($p) => $p['text'] ?? '', $res['candidates'][0]['content']['parts'] ?? []));
        $provider = 'gemini';
    }
    if ($status !== 200 || trim((string)$text) === '') {
        error_log("Clicker quiz AI: $provider HTTP $status " . substr((string)($res['error']['message'] ?? json_encode($res['error'] ?? '')), 0, 200));
        reject(in_array($status, [429, 503], true) ? 'AI_BUSY' : 'AI_UPSTREAM', in_array($status, [429, 503], true) ? 'AI đang bận, thử lại sau ít phút.' : 'Không gọi được mô hình AI.', 502);
    }
    // Some models wrap JSON in ```json fences or add a sentence; keep the outermost JSON value.
    $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)));
    $json = json_decode($text, true);
    if (!is_array($json) && preg_match('/[\[{].*[\]}]/s', $text, $m)) $json = json_decode($m[0], true);
    if (!is_array($json)) reject('AI_FORMAT', 'AI trả về sai định dạng, hãy thử lại.', 502);
    return $json;
}

// Questions from course material. Returns rows ready for import_questions (question_text, answer_A-D,
// correct_answer) plus difficulty, topic and a short explanation for the teacher's review.
function ai_generate_questions(array $c, string $content, int $count, string $level, string $lang, string $notes): array {
    $language = ['vi' => 'Vietnamese', 'en' => 'English'][$lang] ?? 'Vietnamese';
    $system = <<<TXT
You write multiple-choice exam questions for a teacher. Rules:
- Use ONLY facts stated in or directly implied by the course material. Never invent facts, numbers or names.
- Exactly 4 options A, B, C, D; exactly one correct. Wrong options must be plausible (common misconceptions), similar in length
  and style to the right one; no "all of the above", "none of the above", or jokes. Spread the correct letter evenly over A-D.
- Each question is self-contained (no "according to the text above"), short and unambiguous; options have no leading letters.
- Do not repeat a question or test the same fact twice. Cover the material broadly.
- Write everything in {$language}.
Reply with JSON only: {"questions":[{"question":"…","A":"…","B":"…","C":"…","D":"…","answer":"A|B|C|D",
"difficulty":"de|tb|kho|nangcao","topic":"short topic name","explanation":"one sentence: why the answer is right"}]}
TXT;
    $user = "Number of questions: $count\nDifficulty: " . QUIZ_LEVELS[$level] . "\n"
        . ($notes !== '' ? "Teacher's extra requests: $notes\n" : '') . "\nCourse material:\n<<<\n$content\n>>>";
    $json = ai_json($c, $system, $user);
    $list = $json['questions'] ?? (array_is_list($json) ? $json : []);
    $rows = [];
    foreach (is_array($list) ? $list : [] as $q) {
        if (!is_array($q)) continue;
        $strip = fn($v) => trim(preg_replace('/^\s*[A-Da-d][\.\):]\s+/u', '', (string)(is_scalar($v) ? $v : '')));
        $row = ['question_text' => trim((string)($q['question'] ?? '')), 'answer_A' => $strip($q['A'] ?? ''), 'answer_B' => $strip($q['B'] ?? ''),
            'answer_C' => $strip($q['C'] ?? ''), 'answer_D' => $strip($q['D'] ?? ''), 'correct_answer' => strtoupper(trim((string)($q['answer'] ?? '')))];
        $clean = clean_question($row);
        if (is_string($clean)) continue;
        $key = mb_like_lower($clean['question_text']);
        if (isset($rows[$key])) continue;
        $clean['difficulty'] = isset(QUIZ_LEVELS[$q['difficulty'] ?? '']) ? $q['difficulty'] : ($level === 'tron' ? 'tb' : $level);
        $clean['topic'] = substr(trim((string)($q['topic'] ?? '')), 0, 80);
        $clean['explanation'] = substr(trim((string)($q['explanation'] ?? '')), 0, 400);
        $rows[$key] = $clean;
        if (count($rows) >= $count) break;
    }
    if (!$rows) reject('AI_EMPTY', 'AI chưa tạo được câu hỏi hợp lệ từ nội dung này. Hãy thêm nội dung hoặc thử lại.', 502);
    return array_values($rows);
}

function mb_like_lower(string $s): string { return preg_replace('/\s+/u', ' ', strtolower($s)); }
