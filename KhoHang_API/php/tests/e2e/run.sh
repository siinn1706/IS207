#!/usr/bin/env bash
# UI smoke test: PHP backend (8000/8001) on a synthetic DB + Vite (5173), then
# Playwright logs in, visits every page and runs the key flows (smoke.js).
# Never touches KhoHang_API/data.   PY=/path/to/python ./run.sh [workdir]
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; PHPDIR="$(cd "$HERE/../.." && pwd)"; ROOT="$(cd "$PHPDIR/../.." && pwd)"
PY="${PY:-python3}"; WORK="${1:-$(mktemp -d)}"
rm -rf "$WORK/data" "$WORK/shots"; mkdir -p "$WORK/data" "$WORK/shots"
"$PY" "$PHPDIR/tests/contract/seed_fixture.py" "$WORK/data/data.db" >/dev/null
export KHOHANG_DATA_DIR="$WORK/data" JWT_SECRET=e2e-secret CACHE_STORE=array APP_DEBUG=false
pids=(); cleanup() { for p in "${pids[@]}"; do
  # Git Bash: kill only stops the MSYS wrapper, so stop the whole Windows process tree (npx -> node vite).
  if [ -r "/proc/$p/winpid" ]; then taskkill //PID "$(cat "/proc/$p/winpid")" //T //F >/dev/null 2>&1 || true; fi
  kill "$p" 2>/dev/null || true; done; }; trap cleanup EXIT
(cd "$PHPDIR" && php artisan khohang:upgrade >/dev/null)
(cd "$PHPDIR" && exec php artisan rt:serve) >"$WORK/rt.log" 2>&1 & pids+=($!)
(cd "$PHPDIR/public" && PHP_CLI_SERVER_WORKERS=4 exec php -S 127.0.0.1:8000 \
   ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) >"$WORK/http.log" 2>&1 & pids+=($!)
(cd "$ROOT/UI_Desktop" && exec npx vite --port 5173 --host 127.0.0.1 --strictPort) >"$WORK/vite.log" 2>&1 & pids+=($!)
for u in http://127.0.0.1:8000/ http://127.0.0.1:5173/; do for _ in $(seq 120); do curl -sf "$u" >/dev/null && break; sleep 0.5; done; done
"$PY" "$HERE/seed_api.py" >/dev/null
node "$HERE/smoke.js" "$WORK/shots"
