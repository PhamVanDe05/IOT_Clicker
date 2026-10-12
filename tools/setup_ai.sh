#!/bin/bash
# Turn on the chat assistant (web/landing/assistant.php).
#   sudo bash tools/setup_ai.sh
# Gemini: paste an API key from https://aistudio.google.com/apikey (typed hidden, never echoed).
# Own model later: choose "2" and give the OpenAI-compatible URL of your server (Ollama, vLLM, llama.cpp, LM Studio).
set -euo pipefail
[ "$EUID" -eq 0 ] || { echo "Hãy chạy: sudo bash tools/setup_ai.sh"; exit 1; }
CFG=/etc/clicker/prod-config.php
[ -r "$CFG" ] || { echo "Không thấy $CFG"; exit 1; }

echo "Chọn model cho trợ lý:"
echo "  1) Google Gemini (khuyên dùng, cần Internet)"
echo "  2) Model riêng / tự train (máy chủ tương thích OpenAI: Ollama, vLLM...)"
read -rp "Chọn [1]: " CH; CH=${CH:-1}
# Hidden input makes a failed paste invisible, so report what arrived (length + first/last characters only).
ask_key() {
  local prompt=$1 k
  read -rsp "$prompt" k; echo
  k=$(printf '%s' "$k" | tr -d '[:space:]')   # drop stray spaces/newlines from the paste
  if [ -z "$k" ]; then echo "   → không nhận được ký tự nào"; else echo "   → đã nhận ${#k} ký tự: ${k:0:4}…${k: -3}"; fi
  KEY=$k
}
# Ask the provider whether the key works before saving anything (HTTP 200 = ok).
check_key() {
  local code
  if [ "$PROVIDER" = "gemini" ]; then
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 -H "x-goog-api-key: $KEY" \
      "https://generativelanguage.googleapis.com/v1beta/models/$MODEL")
  else
    code=$(curl -s -o /tmp/clicker-models.$$ -w '%{http_code}' --max-time 15 ${KEY:+-H "Authorization: Bearer $KEY"} "${URL%/}/models")
  fi
  echo "   → kiểm tra với nhà cung cấp: HTTP $code"
  [ "$code" = "200" ] || { rm -f /tmp/clicker-models.$$; return 1; }
  # OpenAI-compatible servers list the models this key may use: make sure the chosen one is among them.
  if [ "$PROVIDER" = "openai" ] && [ -s /tmp/clicker-models.$$ ]; then
    local ids
    ids=$(php -r '$j=json_decode(file_get_contents($argv[1]),true); foreach ($j["data"] ?? [] as $m) echo $m["id"], "\n";' /tmp/clicker-models.$$ \
      | grep -viE 'whisper|guard|orpheus|tts|embed' || true)
    if [ -n "$ids" ] && ! printf '%s\n' "$ids" | grep -qxF "$MODEL"; then
      echo "   → tài khoản này KHÔNG dùng được model \"$MODEL\". Các model dùng được:"
      printf '%s\n' "$ids" | sed 's/^/        /'
      read -rp "   Gõ tên model muốn dùng: " MODEL
    fi
  fi
  rm -f /tmp/clicker-models.$$
  return 0
}

if [ "$CH" = "2" ]; then
  read -rp  "Địa chỉ API (vd http://127.0.0.1:11434/v1): " URL
  read -rp  "Tên model (vd my-clicker-model): " MODEL
  PROVIDER=openai
  for try in 1 2 3; do
    ask_key "API key (dán rồi Enter; Enter trống nếu máy chủ không cần key): "
    check_key && break
    [ "$try" = 3 ] && { echo "Key vẫn không được nhận, không đổi gì."; exit 1; }
    echo "   Key chưa đúng, dán lại (Terminal: Ctrl+Shift+V hoặc chuột phải → Paste)."
  done
else
  read -rp  "Model [gemini-flash-latest]: " MODEL; MODEL=${MODEL:-gemini-flash-latest}
  URL=""; PROVIDER=gemini
  for try in 1 2 3; do
    ask_key "Gemini API key (dán rồi Enter): "
    [ -n "$KEY" ] && check_key && break
    [ "$try" = 3 ] && { echo "Key vẫn không được nhận, không đổi gì."; exit 1; }
    echo "   Key chưa đúng, dán lại (Terminal: Ctrl+Shift+V hoặc chuột phải → Paste)."
  done
fi

cp -a "$CFG" "$CFG.bak-$(date +%Y%m%d-%H%M%S)"
CFG="$CFG" PROVIDER="$PROVIDER" URL="$URL" MODEL="$MODEL" KEY="$KEY" php -r '
$path = getenv("CFG"); $c = require $path;
$c["ai_provider"] = getenv("PROVIDER");
if (getenv("PROVIDER") === "gemini") { $c["gemini_api_key"] = getenv("KEY"); $c["gemini_model"] = getenv("MODEL"); }
else { $c["ai_base_url"] = getenv("URL"); $c["ai_model"] = getenv("MODEL"); $c["ai_api_key"] = getenv("KEY"); }
$guard = "if (realpath(\$_SERVER[\"SCRIPT_FILENAME\"] ?? \"\") === __FILE__) { http_response_code(404); exit; }\n";
file_put_contents($path, "<?php\n" . $guard . "return " . var_export($c, true) . ";\n");
echo "Đã lưu vào $path\n";'
php -l "$CFG" >/dev/null && echo "File cấu hình hợp lệ."

# PHP opcache re-reads the config file only every 2 s; wait so the check sees the new key.
sleep 3
echo -n "Trạng thái: "; curl -s http://localhost/clicker/landing/assistant.php?status=1; echo
echo -n "Hỏi thử: "
curl -s -X POST -H 'Content-Type: application/json' -d '{"lang":"vi","messages":[{"role":"user","text":"Clicker là gì? Trả lời 1 câu."}]}' \
  http://localhost/clicker/landing/assistant.php | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo ($d["ok"] ?? false) ? "OK: ".$d["reply"] : "LỖI: ".($d["code"] ?? "?"); echo "\n";'
