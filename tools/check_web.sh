#!/bin/bash
# Quick health check of everything the Clicker web needs on this laptop. No sudo, changes nothing.
#   bash ~/clicker/tools/check_web.sh
ok()   { printf '  \e[32m✔\e[0m %s\n' "$1"; }
bad()  { printf '  \e[31m✘\e[0m %s\n     → %s\n' "$1" "$2"; FAIL=1; }
FAIL=0
PUBLIC=$("$HOME/.local/bin/tailscale" status --json 2>/dev/null | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo rtrim($j["Self"]["DNSName"] ?? "", ".");')
LAN=$(ip -4 -o addr show scope global 2>/dev/null | awk '!/docker|br-|tailscale/{split($4,a,"/"); print a[1]; exit}')

echo "Dịch vụ"
systemctl is-active --quiet apache2 && ok "Apache (web) đang chạy" || bad "Apache chưa chạy" "sudo systemctl start apache2"
systemctl is-active --quiet mariadb && ok "MariaDB (database) đang chạy" || bad "MariaDB chưa chạy" "sudo systemctl start mariadb"
systemctl --user is-active --quiet tailscaled && ok "Tailscale đang chạy" || bad "Tailscale chưa chạy" "systemctl --user start tailscaled"

echo "Trang web"
code() { curl -s -o /dev/null -w '%{http_code}' --max-time "${2:-8}" "$1"; }
[ "$(code http://localhost/clicker/landing/)" = 200 ] && ok "Trên máy này: http://localhost/clicker/landing/" \
  || bad "Trang không mở được trên máy này" "xem: sudo tail -20 /var/log/apache2/error.log"
auth=$(curl -s --max-time 8 "http://localhost/clicker/api.php?action=auth")
[[ "$auth" == *'"ok":true'* ]] && ok "API + database trả lời bình thường" || bad "API lỗi (thường do database)" "sudo systemctl restart mariadb apache2"
if [ -n "$LAN" ]; then
  [ "$(code "http://$LAN/clicker/landing/")" = 200 ] && ok "Cùng Wi-Fi: http://$LAN/clicker/landing/" || bad "Không mở được bằng IP Wi-Fi $LAN" "sudo ufw allow 80/tcp"
else bad "Máy chưa vào Wi-Fi/mạng" "kết nối Wi-Fi rồi chạy lại"; fi
if [ -n "$PUBLIC" ]; then
  [ "$(code "https://$PUBLIC/clicker/landing/" 20)" = 200 ] && ok "Công khai: https://$PUBLIC/clicker/landing/" \
    || bad "Link công khai chưa mở được" "$HOME/.local/bin/tailscale funnel --bg --set-path=/clicker http://127.0.0.1:80/clicker"
else bad "Tailscale chưa đăng nhập" "$HOME/.local/bin/tailscale up --hostname=clicker"; fi

echo "Đăng nhập Google và trợ lý AI"
[[ "$(curl -s --max-time 8 http://localhost/clicker/landing/oauth.php?status=1)" == *'"google":true'* ]] && ok "Nút Google đã bật" \
  || bad "Nút Google chưa bật" "cd ~/clicker && sudo bash tools/setup_oauth.sh"
[[ "$(curl -s --max-time 8 http://localhost/clicker/landing/assistant.php?status=1)" == *'"enabled":true'* ]] && ok "Trợ lý AI đã bật" \
  || bad "Trợ lý AI chưa bật" "cd ~/clicker && sudo bash tools/setup_ai.sh"

echo "Máy"
free=$(df -h / | awk 'NR==2{print $4}'); pct=$(df / | awk 'NR==2{gsub("%","",$5); print $5}')
[ "$pct" -lt 95 ] && ok "Ổ đĩa còn trống $free" || bad "Ổ đĩa gần đầy (còn $free) — web sẽ lỗi ngẫu nhiên" "dọn bớt file, xem mục Ổ đĩa trong CHAY_WEB.md"

echo
[ $FAIL = 0 ] && echo "Mọi thứ ổn. Gửi link công khai ở trên cho máy khác." || echo "Có mục ✘ ở trên: chạy lệnh sau dấu → của mục đó."
