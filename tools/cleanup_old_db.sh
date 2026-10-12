#!/bin/bash
# Remove the old databases that Clicker does not use: clicker_db (v5), IOT (handover web), Health_DB (health web).
# Each one is dumped to .local/backups/old-db-<name>-<date>.sql.gz first; smart_clicker and smart_clicker_test stay.
#   sudo bash tools/cleanup_old_db.sh
set -euo pipefail
[ "$EUID" -eq 0 ] || { echo "Hãy chạy: sudo bash tools/cleanup_old_db.sh"; exit 1; }
cd "$(dirname "$0")/.."
OWNER="${SUDO_USER:-root}"
mkdir -p .local/backups
for DB in clicker_db IOT Health_DB; do
  if ! mariadb -N -e "SHOW DATABASES LIKE '$DB'" | grep -qx "$DB"; then echo "· $DB: không có, bỏ qua"; continue; fi
  OUT=".local/backups/old-db-$DB-$(date +%Y%m%d-%H%M%S).sql.gz"
  mariadb-dump --single-transaction --routines --triggers --databases "$DB" | gzip > "$OUT"
  [ -s "$OUT" ] && gzip -t "$OUT" || { echo "✘ Sao lưu $DB lỗi, không xoá"; exit 1; }
  chown "$OWNER": "$OUT"; chmod 600 "$OUT"
  mariadb -e "DROP DATABASE \`$DB\`"
  echo "✔ $DB: đã sao lưu vào $OUT rồi xoá"
done
echo "Còn lại:"; mariadb -N -e "SHOW DATABASES" | grep -v -e information_schema -e performance_schema -e '^mysql$' -e '^sys$'
