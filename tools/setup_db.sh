#!/bin/bash
# All database upgrades for the live server, in one step: back up, then
#   1) join_request table (students register themselves, teacher approves)
#   2) classroom_session.pacing (rooms where each student works at their own pace)
#   3) app_setting, audit_log, password_request, device.label (admin tools)
#   4) teacher_account.is_admin / disabled_at, choose the admin, remove the old "Pham Van De" account
#   5) classroom_session.mode (several rooms at once, each with its own Wi-Fi / BLE mode)
# Only adds tables/columns; the only rows removed are those of "Pham Van De" (after the backup). Safe to run again.
#   sudo bash tools/setup_db.sh
set -euo pipefail
[ "$EUID" -eq 0 ] || { echo "Hãy chạy: sudo bash tools/setup_db.sh"; exit 1; }
cd "$(dirname "$0")/.."
CFG=/etc/clicker/prod-config.php
echo "== 1/7 Sao lưu database thật"
CLICKER_CONFIG="$CFG" php tools/backup.php
chown -R "${SUDO_USER:-root}": .local/backups 2>/dev/null || true
echo "== 2/7 Bảng join_request (tự đăng ký + duyệt)"
CLICKER_CONFIG="$CFG" php tools/upgrade_join.php
echo "== 3/7 Cột pacing (sinh viên tự làm)"
CLICKER_CONFIG="$CFG" php tools/upgrade_pacing.php
echo "== 4/7 Công cụ quản trị (cài đặt, nhật ký, quên mật khẩu, tên thiết bị)"
CLICKER_CONFIG="$CFG" php tools/upgrade_ops.php
echo "== 5/7 Hồ sơ giáo viên, lớp tự đăng ký"
CLICKER_CONFIG="$CFG" php tools/upgrade_profile.php
echo "== 6/7 Nhiều phòng cùng lúc (chế độ Wi-Fi / BLE theo từng phòng)"
CLICKER_CONFIG="$CFG" php tools/upgrade_rooms.php
echo "== 7/7 Quản trị viên"
read -rp "Email Google (hoặc tên đăng nhập) của quản trị viên (Enter = giữ như cũ): " ADMIN
REMOVE=()
if [ "${KEEP_OWNER:-}" != 1 ]; then REMOVE=(--remove="Pham Van De"); fi
ARGS=("${REMOVE[@]}"); [ -n "$ADMIN" ] && ARGS+=(--admin="$ADMIN")
CLICKER_CONFIG="$CFG" php tools/upgrade_admin.php "${ARGS[@]}"
echo "Xong. Đăng nhập lại web bằng tài khoản quản trị để thấy mục Quản trị."
