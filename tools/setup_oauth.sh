#!/bin/bash
# One-step Google sign-in setup on this laptop (Facebook support stays in oauth.php but is not offered). Back up first: php tools/backup.php
#   sudo bash tools/setup_oauth.sh
# 1) adds teacher_account.email/google_id/facebook_id (idempotent), 2) asks for the provider keys (secrets are
# typed hidden and never echoed), 3) merges them into /etc/clicker/prod-config.php (a dated copy is kept), 4) checks.
set -euo pipefail
[ "$EUID" -eq 0 ] || { echo "Hãy chạy: sudo bash tools/setup_oauth.sh"; exit 1; }
cd "$(dirname "$0")/.."
CFG=/etc/clicker/prod-config.php
[ -r "$CFG" ] || { echo "Không thấy $CFG"; exit 1; }

echo "== 1/3 Nâng cấp database (thêm cột email, google_id, facebook_id)"
CLICKER_CONFIG="$CFG" php tools/upgrade_oauth.php

echo
echo "== 2/3 Nhập khoá (bấm Enter để bỏ qua, giữ giá trị cũ)"
read -rp  "Google Client ID      : " GID
read -rsp "Google Client Secret  : " GSEC; echo " (đã ẩn)"
FID=""; FSEC=""
if [ -n "$GID" ] && [[ "$GID" != *.apps.googleusercontent.com ]]; then echo "Cảnh báo: Google Client ID thường kết thúc bằng .apps.googleusercontent.com"; fi

# Public HTTPS name from this user's Tailscale Funnel (used for the Google redirect URI).
HOST=""
if [ -n "${SUDO_USER:-}" ]; then
  HOST=$(sudo -u "$SUDO_USER" "/home/$SUDO_USER/.local/bin/tailscale" status --json 2>/dev/null \
    | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo rtrim($j["Self"]["DNSName"] ?? "", ".");' || true)
fi
[ -n "$HOST" ] && echo "Link công khai: https://$HOST/clicker/"

cp -a "$CFG" "$CFG.bak-$(date +%Y%m%d-%H%M%S)"
# Values travel by environment, not argv, so they do not show up in the process list.
CFG="$CFG" HOST="$HOST" GID="$GID" GSEC="$GSEC" FID="$FID" FSEC="$FSEC" php -r '
$path = getenv("CFG");
$c = require $path;
foreach (["google_client_id" => "GID", "google_client_secret" => "GSEC", "facebook_app_id" => "FID", "facebook_app_secret" => "FSEC"] as $key => $env) {
    $value = trim((string)getenv($env));
    if ($value !== "") $c[$key] = $value;
}
$host = trim((string)getenv("HOST"));
if ($host !== "") { $c["internet_host"] = $host; $c["public_url"] = "https://$host/clicker/"; }
$guard = "if (realpath(\$_SERVER[\"SCRIPT_FILENAME\"] ?? \"\") === __FILE__) { http_response_code(404); exit; }\n";
file_put_contents($path, "<?php\n" . $guard . "return " . var_export($c, true) . ";\n");
echo "Đã lưu khoá vào $path\n";'

echo
echo "== 3/3 Kiểm tra"
php -l "$CFG" >/dev/null && echo "File cấu hình hợp lệ."
echo -n "Trạng thái: "; curl -s http://localhost/clicker/landing/oauth.php?status=1; echo
echo "Mở http://localhost/clicker/landing/login.html và bấm nút Google để thử."
[ -n "$HOST" ] && echo "Địa chỉ trả về cần khai báo với Google: https://$HOST/clicker/landing/oauth.php"
