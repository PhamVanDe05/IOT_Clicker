<?php
declare(strict_types=1);
// AI question generation ("Tạo đề bằng AI"). Which model answers, first match wins (all in prod-config.php):
//   quiz_ai_base_url + quiz_ai_model (+ quiz_ai_key)  a model of your own (e.g. a friend's fine-tuned model) behind any
//                                                     OpenAI-compatible server: vLLM, Ollama, LM Studio, Groq…
//   ai_provider = 'openai' + ai_base_url + ai_model    the chat assistant's OpenAI-compatible model
//   gemini_api_key (+ gemini_model)                    Google Gemini (default)
// Besides that "cloud" model the teacher can pick the model on this computer: llama.cpp serving modelv3 (Qwen3 4B) as the
// user service clicker-llm.service on local_ai_base_url (default http://127.0.0.1:8081/v1). It is offered while /health answers.
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

function local_ai_base(array $c): string { return rtrim((string)($c['local_ai_base_url'] ?? 'http://127.0.0.1:8081/v1'), '/'); }
function local_ai_alive(array $c): bool {
    static $alive = null;
    if ($alive !== null) return $alive;
    $ctx = stream_context_create(['http' => ['timeout' => 1.5, 'ignore_errors' => true]]);
    return $alive = str_contains((string)@file_get_contents(preg_replace('#/v1$#', '', local_ai_base($c)) . '/health', false, $ctx), '"ok"');
}
// Models the teacher can choose from right now: [{id, name}], the cloud one first.
function ai_providers(array $c): array {
    $list = [];
    if (($name = quiz_ai_name($c)) !== null) $list[] = ['id' => 'cloud', 'name' => $name];
    if (local_ai_alive($c)) $list[] = ['id' => 'local', 'name' => 'Model trong máy (' . ($c['local_ai_model'] ?? 'modelv3') . ')'];
    return $list;
}
function ai_pick(array $c, mixed $want): string {
    $ids = array_column(ai_providers($c), 'id');
    if (!$ids) reject('AI_NOT_CONFIGURED', 'Máy chủ chưa có mô hình AI (chạy sudo bash tools/setup_ai.sh hoặc bật clicker-llm.service).', 503);
    return in_array($want, $ids, true) ? $want : $ids[0];
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
function ai_json(array $c, string $system, string $user, string $provider = 'cloud'): array {
    $custom = !empty($c['quiz_ai_base_url']) && !empty($c['quiz_ai_model']);
    if ($provider === 'local') {
        // Small model: lower temperature keeps it on the material; thinking is off in the service (--reasoning off).
        [$status, $res] = ai_http(local_ai_base($c) . '/chat/completions', ['model' => $c['local_ai_model'] ?? 'modelv3', 'temperature' => 0.3,
            'max_tokens' => 6000, 'response_format' => ['type' => 'json_object'],
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]]], [], 300);
        $text = $res['choices'][0]['message']['content'] ?? '';
    } elseif ($custom || (($c['ai_provider'] ?? 'gemini') === 'openai' && !empty($c['ai_base_url']))) {
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

// Questions from course material. Returns rows ready for import_questions (question_text, answer_A-D, correct_answer)
// plus difficulty, topic, a short explanation, the sentence of the material it rests on (evidence), where that sentence
// is (source: "Trang 3", "Slide 5") and verified = the evidence really is in the material (checked here, not trusted).
// The local model reads at most ~9,000 characters at a time, so long material goes to it in parts spread over the text.
function ai_generate_questions(array $c, string $content, int $count, string $level, string $lang, string $notes, string $provider = 'cloud'): array {
    $language = ['vi' => 'Vietnamese', 'en' => 'English'][$lang] ?? 'Vietnamese';
    $system = <<<TXT
You write multiple-choice exam questions for a teacher. Rules:
- Use ONLY facts stated in or directly implied by the course material. Never invent facts, numbers or names.
- Exactly 4 options A, B, C, D; exactly one correct; the 4 options must all be different. Wrong options must be plausible
  (common misconceptions), similar in length and style to the right one; no "all of the above", "none of the above", or jokes.
  Spread the correct letter evenly over A-D.
- Each question is self-contained (no "according to the text above"), short and unambiguous; options have no leading letters.
- Do not repeat a question or test the same fact twice. Cover the material broadly.
- "evidence": copy, word for word in the material's own language, the one sentence of the material that proves the answer.
- Write the questions, options, topic and explanation in {$language}.
Reply with JSON only: {"questions":[{"question":"…","A":"…","B":"…","C":"…","D":"…","answer":"A|B|C|D",
"difficulty":"de|tb|kho|nangcao","topic":"short topic name","explanation":"one sentence: why the answer is right","evidence":"…"}]}
TXT;
    $parts = $provider === 'local' ? material_parts($content, 9000, min($count, 6)) : [$content];
    $rows = [];
    // One request to the model for $want questions about $part; adds the ones that pass every check to $rows.
    // $avoid = questions already written, so a top-up round does not repeat them.
    $ask = function (string $part, int $want) use (&$rows, $c, $system, $level, $notes, $provider, $content, $lang): void {
        $have = $rows ? "\nQuestions already written (do NOT repeat or rephrase them, test other facts):\n- " . implode("\n- ", array_map(fn($q) => u_cut($q['question_text'], 120), $rows)) . "\n" : '';
        $user = "Number of questions: $want (write exactly $want)\nLanguage of the questions and options: " . ($lang === 'en' ? 'English' : 'Vietnamese') . "\nDifficulty: " . QUIZ_LEVELS[$level] . "\n"
            . ($notes !== '' ? "Teacher's extra requests: $notes\n" : '') . $have . "\nCourse material:\n<<<\n$part\n>>>";
        $json = ai_json($c, $system, $user, $provider);
        $list = $json['questions'] ?? (array_is_list($json) ? $json : []);
        foreach (is_array($list) ? $list : [] as $q) {
            if (!is_array($q)) continue;
            $strip = fn($v) => trim(preg_replace('/^\s*[A-Da-d][\.\):]\s+/u', '', (string)(is_scalar($v) ? $v : '')));
            $row = ['question_text' => trim((string)(is_scalar($q['question'] ?? null) ? $q['question'] : '')), 'answer_A' => $strip($q['A'] ?? ''), 'answer_B' => $strip($q['B'] ?? ''),
                'answer_C' => $strip($q['C'] ?? ''), 'answer_D' => $strip($q['D'] ?? ''), 'correct_answer' => strtoupper(trim((string)(is_scalar($q['answer'] ?? null) ? $q['answer'] : '')))];
            $clean = clean_question($row);
            if (is_string($clean)) continue;
            if (wrong_language(implode(' ', $clean) . ' ' . (is_scalar($q['topic'] ?? null) ? $q['topic'] : '') . ' ' . (is_scalar($q['explanation'] ?? null) ? $q['explanation'] : ''), $lang)) continue;
            // Two options that say the same thing make a question with two right answers (seen in small models' output).
            $opts = array_map(fn($k) => mb_like_lower($clean["answer_$k"]), ['A', 'B', 'C', 'D']);
            if (count(array_unique($opts)) < 4) continue;
            if (similar_question($clean['question_text'], array_column($rows, 'question_text'))) continue;
            $clean['difficulty'] = isset(QUIZ_LEVELS[$q['difficulty'] ?? '']) && $q['difficulty'] !== 'tron' ? $q['difficulty'] : ($level === 'tron' ? 'tb' : $level);
            $clean['topic'] = u_cut(trim((string)(is_scalar($q['topic'] ?? null) ? $q['topic'] : '')), 80);
            $clean['explanation'] = u_cut(trim((string)(is_scalar($q['explanation'] ?? null) ? $q['explanation'] : '')), 400);
            $clean['evidence'] = u_cut(trim((string)(is_scalar($q['evidence'] ?? null) ? $q['evidence'] : '')), 600);
            [$score, $where] = evidence_check($clean['evidence'], $content);
            $clean['verified'] = $score >= 0.75; $clean['source'] = $clean['verified'] ? $where : '';
            $rows[] = $clean;
        }
    };
    set_time_limit(280);
    foreach ($parts as $n => $part) {
        $want = intdiv($count, count($parts)) + ($n < $count % count($parts) ? 1 : 0);
        if ($want > 0) $ask($part, $want);
    }
    // The model often returns fewer than asked, and the checks drop bad ones: ask again for exactly what is missing
    // (up to 4 more rounds, cycling through the parts) so the teacher gets the number of questions they set.
    for ($round = 0; $round < 4 && count($rows) < $count; $round++) {
        $before = count($rows);
        $ask($parts[$round % count($parts)], $count - count($rows));
        if (count($rows) === $before && $round >= 2) break;   // two rounds in a row with nothing new: the material is used up
    }
    if (!$rows) reject('AI_EMPTY', 'AI chưa tạo được câu hỏi hợp lệ từ nội dung này. Hãy thêm nội dung hoặc thử lại.', 502);
    return array_slice($rows, 0, $count);
}

// Small models sometimes slip into Chinese mid-answer. For Vietnamese/English output a Han character means "wrong language".
function wrong_language(string $text, string $lang): bool { return $lang !== 'zh' && preg_match('/\p{Han}/u', $text) === 1; }

function mb_like_lower(string $s): string { return preg_replace('/\s+/u', ' ', strtolower(trim($s))); }
function similar_question(string $q, array $have): bool {
    $q = mb_like_lower($q);
    foreach ($have as $h) { similar_text($q, mb_like_lower($h), $pct); if ($pct >= 92) return true; }
    return false;
}
// Up to $max pieces of at most $size characters, cut at page/slide marks or paragraphs, spread evenly over the material.
function material_parts(string $text, int $size, int $max): array {
    if (u_len($text) <= $size) return [$text];
    $blocks = preg_split('/(?=\[(?:Trang|Slide) \d+\])|\n\s*\n/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $parts = []; $cur = '';
    foreach ($blocks as $b) {
        preg_match_all('/.{1,' . $size . '}/su', $b, $pieces);
        foreach ($pieces[0] as $piece) {
            if ($cur !== '' && u_len($cur) + u_len($piece) > $size) { $parts[] = $cur; $cur = ''; }
            $cur .= ($cur === '' ? '' : "\n\n") . $piece;
        }
    }
    if (trim($cur) !== '') $parts[] = $cur;
    if (count($parts) <= $max) return $parts;
    $pick = [];
    for ($i = 0; $i < $max; $i++) $pick[] = $parts[(int)round($i * (count($parts) - 1) / max(1, $max - 1))];
    return $pick;
}
// How much of the evidence sentence is in the material (0–1: share of its 3-word runs found in one page/slide) and where.
function evidence_check(string $evidence, string $material): array {
    // Compared without accents and case (no mbstring here): "Điện Trở" and "điện trở" match.
    $words = fn(string $s) => preg_split('/[^a-z0-9]+/', strtolower((string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', str_replace(['Đ', 'đ'], 'd', $s))), -1, PREG_SPLIT_NO_EMPTY);
    $ev = $words($evidence);
    if (count($ev) < 4) return [0.0, ''];
    $grams = []; for ($i = 0; $i + 2 < count($ev); $i++) $grams[] = $ev[$i] . ' ' . $ev[$i + 1] . ' ' . $ev[$i + 2];
    $best = [0.0, ''];
    foreach (preg_split('/(?=\[(?:Trang|Slide) \d+\])/u', $material) as $page) {
        $w = $words($page); $set = [];
        for ($i = 0; $i + 2 < count($w); $i++) $set[$w[$i] . ' ' . $w[$i + 1] . ' ' . $w[$i + 2]] = true;
        $hit = count(array_filter($grams, fn($g) => isset($set[$g]))) / count($grams);
        if ($hit > $best[0]) $best = [$hit, preg_match('/^\[((?:Trang|Slide) \d+)\]/u', $page, $m) ? $m[1] : ''];
    }
    return $best;
}

// Text of an uploaded course file: PDF (pdftotext, "[Trang N]" before each page), PPTX ("[Slide N]"), DOCX, or plain text.
// Programs get an argument list (no shell) and their output is capped, so a crafted file cannot run commands or fill memory.
function document_text(string $name, string $bytes): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, ['txt', 'md', 'csv'], true)) {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
        if (!preg_match('//u', $text)) reject('AI_FILE', 'File chữ phải là UTF-8.');
        return $text;
    }
    if (!in_array($ext, ['pdf', 'docx', 'pptx'], true)) reject('AI_FILE', 'Chỉ nhận PDF, DOCX, PPTX, TXT, MD, CSV.');
    if ($ext === 'pdf' ? !str_starts_with($bytes, '%PDF') : !str_starts_with($bytes, "PK\x03\x04")) reject('AI_FILE', 'File bị hỏng hoặc sai định dạng.');
    $tmp = tempnam(sys_get_temp_dir(), 'clk'); file_put_contents($tmp, $bytes);
    try {
        if ($ext === 'pdf') {
            $pages = explode("\f", run_tool(['pdftotext', '-layout', '-enc', 'UTF-8', $tmp, '-']));
            $text = '';
            foreach ($pages as $i => $p) if (trim($p) !== '') $text .= '[Trang ' . ($i + 1) . "]\n" . preg_replace('/[ \t]{2,}/', '  ', trim($p)) . "\n\n";
        } elseif ($ext === 'docx') {
            $xml = run_tool(['unzip', '-p', $tmp, 'word/document.xml']);
            $text = xml_text(preg_replace(['#</w:p>#', '#<w:tab/>#', '#<w:br/>#'], ["\n", "\t", "\n"], $xml));
        } else {
            $files = preg_grep('#^ppt/slides/slide\d+\.xml$#', explode("\n", run_tool(['unzip', '-Z1', $tmp])));
            natsort($files); $text = ''; $n = 0;
            foreach ($files as $f) {
                $xml = run_tool(['unzip', '-p', $tmp, $f]);
                preg_match_all('#<a:p>.*?</a:p>#s', $xml, $paras);
                $lines = array_filter(array_map(fn($p) => trim(xml_text(preg_replace('#</a:t>#', '', $p))), $paras[0]), 'strlen');
                $n++; if ($lines) $text .= "[Slide $n]\n" . implode("\n", $lines) . "\n\n";
            }
        }
    } finally { @unlink($tmp); }
    $text = trim(preg_replace("/\n{3,}/", "\n\n", (string)$text));
    if (u_len($text) < 80) reject('AI_FILE', 'Không đọc được chữ trong file (file scan/ảnh?). Hãy dán nội dung vào ô.');
    return $text;
}
function xml_text(string $xml): string { return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'); }
function run_tool(array $cmd): string {
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($p)) reject('AI_FILE', 'Máy chủ không đọc được file này.', 500);
    $out = stream_get_contents($pipes[1], 20000000); fclose($pipes[1]); proc_close($p);
    return (string)$out;
}

// "Nhận xét bằng AI" on Kết quả: the numbers come from the database (here), the model only words the feedback.
// $facts is what review_facts() built; returns {summary, strengths[], weaknesses[], topics[], advice[]}.
function ai_review(array $c, array $facts, string $lang, string $provider = 'cloud'): array {
    $language = ['vi' => 'Vietnamese', 'en' => 'English', 'zh' => 'Simplified Chinese'][$lang] ?? 'Vietnamese';
    $who = isset($facts['student']) ? 'one student' : 'the whole class';
    $system = <<<TXT
You are a kind, precise teaching assistant. From a multiple-choice test result you give feedback for {$who}.
Use ONLY the data given: never invent scores, questions or topics. Scores (score10, average_score10, highest, lowest) are points
out of 10; right_pct is the percent of students who chose the right answer. Copy numbers exactly as given. Refer to questions as "Câu N" (or "Q N" in English).
Write in {$language}, short sentences, encouraging but honest.
Reply with JSON only: {"summary":"2 sentences","strengths":["…"],"weaknesses":["…"],"topics":["topic to review"],"advice":["concrete study tip"]}
(each list 1–4 items; empty list if nothing fits).
TXT;
    for ($try = 0; $try < 2; $try++) {   // a small model may answer in the wrong language: ask once more
        $json = ai_json($c, $system, "Reply in {$language}.\nTest result data:\n" . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $provider);
        if (!wrong_language(json_encode($json, JSON_UNESCAPED_UNICODE), $lang)) break;
    }
    $list = fn($k) => array_values(array_slice(array_filter(array_map(fn($v) => is_scalar($v) ? u_cut(trim((string)$v), 300) : '', is_array($json[$k] ?? null) ? $json[$k] : []), 'strlen'), 0, 4));
    return ['summary' => u_cut(trim((string)(is_scalar($json['summary'] ?? null) ? $json['summary'] : '')), 600),
        'strengths' => $list('strengths'), 'weaknesses' => $list('weaknesses'), 'topics' => $list('topics'), 'advice' => $list('advice')];
}

// "Gợi ý ôn tập cho từng bạn": one feedback per student in $facts['students'] (coded S1…). Returns [code => {summary,
// weaknesses[], topics[], advice[]}] for the codes the model answered; the caller retries the missing ones.
function ai_review_students(array $c, array $facts, string $lang, string $provider = 'cloud'): array {
    $language = ['vi' => 'Vietnamese', 'en' => 'English', 'zh' => 'Simplified Chinese'][$lang] ?? 'Vietnamese';
    $system = <<<TXT
You are a kind, precise teaching assistant. From a multiple-choice test you write PERSONAL feedback for EACH student in "students".
Use ONLY the data given. Each student has score10 (points out of 10) and answers: "right", "wrong" (with the letter "chose") or
"blank" per question number. Compare each student's wrong or blank questions with "questions" (topic, correct answer,
common_wrong) to say what that student should review. Different students must get different advice, based on their own mistakes.
A student with all answers right: praise and give one stretch idea. Refer to questions as "Câu N" (or "Q N" in English).
Write ONLY in {$language} (never Chinese unless asked). Address the student directly as "you" (Vietnamese: "bạn"). "summary": ONE sentence
that states the score (x/10) and what went well or badly. Then 1–3 concrete study tips each. Never write the ids (S1, S2…) or names in the text.
Reply with JSON only: {"students":[{"id":"S1","summary":"…","weaknesses":["…"],"topics":["…"],"advice":["…"]}]}
Return one entry for every student id, with the same id.
TXT;
    $json = ai_json($c, $system, "Reply in {$language}.\nTest result data:\n" . json_encode($facts, JSON_UNESCAPED_UNICODE) . "\n\nReminder: every text field must be in {$language}.", $provider);
    $you = ['vi' => 'bạn', 'en' => 'you', 'zh' => '你'][$lang] ?? 'bạn';
    $clean = fn(string $t) => preg_replace('/\bS\d{1,3}\b/u', $you, $t);   // the model sometimes copies the id into the sentence
    $list = fn($row, $k) => array_values(array_slice(array_filter(array_map(fn($v) => is_scalar($v) ? u_cut(trim((string)$v), 300) : '', is_array($row[$k] ?? null) ? $row[$k] : []), 'strlen'), 0, 3));
    $out = [];
    foreach ($json['students'] ?? (array_is_list($json) ? $json : []) as $row) {
        if (!is_array($row) || !is_string($row['id'] ?? null)) continue;
        $one = ['summary' => $clean(u_cut(trim((string)(is_scalar($row['summary'] ?? null) ? $row['summary'] : '')), 400)),
            'weaknesses' => array_map($clean, $list($row, 'weaknesses')), 'topics' => array_map($clean, $list($row, 'topics')), 'advice' => array_map($clean, $list($row, 'advice'))];
        // wrong language → leave this student out; the page asks again for the missing ones
        if (!wrong_language(json_encode($one, JSON_UNESCAPED_UNICODE), $lang)) $out[$row['id']] = $one;
    }
    return $out;
}
