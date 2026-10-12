#!/bin/bash
# Local server on the TEST database for screenshots: http://127.0.0.1:8099/clicker/   (stop: bash tools/dev/serve_test.sh stop)
cd "$(dirname "$0")/../.."
pid=$(ss -ltnp 2>/dev/null | grep ':8099 ' | grep -o 'pid=[0-9]*' | cut -d= -f2)
[ "${1:-}" = stop ] && { [ -n "$pid" ] && kill $pid; exit 0; }
[ -n "$pid" ] && exit 0
R=.local/dev-root; mkdir -p $R && ln -sfn "$PWD/web" $R/clicker
CLICKER_CONFIG=$PWD/.local/test-config.php nohup php -S 127.0.0.1:8099 -t $R > .local/dev-server.log 2>&1 &
sleep 1
