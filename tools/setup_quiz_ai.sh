#!/bin/bash
# Plug your own model into "Tạo đề bằng AI" (web/ai.php). The chat assistant keeps its own model.
#   sudo bash tools/setup_quiz_ai.sh
# The model must sit behind an OpenAI-compatible server (vLLM, Ollama, llama.cpp server, LM Studio, Groq, …) that
# answers POST <url>/chat/completions. Leave the address empty to remove it: question generation then uses Gemini.
set -euo pipefail
[ "$EUID" -eq 0 ] || { echo "Hãy chạy: sudo bash tools/setup_quiz_ai.sh"; exit 1; }
CFG=/etc/clicker/prod-config.php
[ -r "$CFG" ] || { echo "Không thấy $CFG"; exit 1; }

echo "Model riêng cho \"Tạo đề bằng AI\" (bỏ trống địa chỉ = quay về Gemini)"
read -rp  "Địa chỉ API (vd http://192.168.1.20:8000/v1): " URL
MODEL=""; KEY=""
if [ -n "$URL" ]; then
  URL=${URL%/}
  read -rp  "Tên model (vd clicker-quiz-7b): " MODEL
  read -rsp "API key (Enter nếu không có): " KEY; echo " (đã ẩn)"
  echo -n "Kiểm tra máy chủ model… "
  AUTH=(); [ -n "$KEY" ] && AUTH=(-H "Authorization: Bearer $KEY")
  if curl -s --max-time 15 "${AUTH[@]}" "$URL/models" | grep -q "\"$MODEL\""; then echo "thấy model $MODEL."
  else echo "chưa thấy model trong $URL/models (vẫn lưu; kiểm tra lại địa chỉ / tên model nếu tạo đề báo lỗi)."; fi
fi

cp -a "$CFG" "$CFG.bak-$(date +%Y%m%d-%H%M%S)"
CFG="$CFG" URL="$URL" MODEL="$MODEL" KEY="$KEY" php -r '
$path = getenv("CFG"); $c = require $path;
if (getenv("URL") === "") unset($c["quiz_ai_base_url"], $c["quiz_ai_model"], $c["quiz_ai_key"]);
else { $c["quiz_ai_base_url"] = getenv("URL"); $c["quiz_ai_model"] = getenv("MODEL"); $c["quiz_ai_key"] = getenv("KEY"); }
$guard = "if (realpath(\$_SERVER[\"SCRIPT_FILENAME\"] ?? \"\") === __FILE__) { http_response_code(404); exit; }\n";
file_put_contents($path, "<?php\n" . $guard . "return " . var_export($c, true) . ";\n");
echo "Đã lưu vào $path\n";'
php -l "$CFG" >/dev/null && echo "File cấu hình hợp lệ."
echo "Xong. Mở Thư viện → \"Tạo đề bằng AI\": góc phải thẻ hiện tên model đang dùng."
