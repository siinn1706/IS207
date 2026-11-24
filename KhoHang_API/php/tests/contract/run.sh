#!/usr/bin/env bash
# Boots FastAPI (baseline) and the PHP port on two copies of one synthetic fixture,
# runs compare_backends.py, then stops everything. Never touches KhoHang_API/data.
#   PY=/path/to/venv/bin/python ./run.sh [workdir]
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PHPDIR="$(cd "$HERE/../.." && pwd)"
API="$(cd "$PHPDIR/.." && pwd)"
PY="${PY:-python3}"
WORK="${1:-$(mktemp -d)}"
export JWT_SECRET="contract-test-secret"
unset SMTP_USER SMTP_PASS GEMINI_API_KEY || true

rm -rf "$WORK/py" "$WORK/php"; mkdir -p "$WORK/py/data" "$WORK/php"
"$PY" "$HERE/seed_fixture.py" "$WORK/py/data/data.db" >/dev/null
cp "$WORK/py/data/data.db" "$WORK/php/data.db"
cp -r "$API/app" "$API/templates" "$WORK/py/"   # FastAPI resolves data/ next to its package

pids=()
cleanup() { for p in "${pids[@]}"; do
  # Git Bash: kill only stops the MSYS wrapper, so stop the whole Windows process tree.
  if [ -r "/proc/$p/winpid" ]; then taskkill //PID "$(cat "/proc/$p/winpid")" //T //F >/dev/null 2>&1 || true; fi
  kill "$p" 2>/dev/null || true; done; }
trap cleanup EXIT

(cd "$WORK/py" && exec "$PY" -m uvicorn app.main:app --port 9100 --log-level warning) >"$WORK/py.log" 2>&1 & pids+=($!)

export DB_DATABASE="$WORK/php/data.db" KHOHANG_DATA_DIR="$WORK/php" KHOHANG_TEMPLATES_DIR="$API/templates"
export RT_WS_PORT=9201 RT_INTERNAL_PORT=9202 APP_DEBUG=false CACHE_STORE=array
(cd "$PHPDIR" && php artisan migrate --force >/dev/null)
(cd "$PHPDIR" && exec php artisan rt:serve) >"$WORK/rt.log" 2>&1 & pids+=($!)
(cd "$PHPDIR/public" && PHP_CLI_SERVER_WORKERS=4 exec php -S 127.0.0.1:9200 \
    ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) >"$WORK/php.log" 2>&1 & pids+=($!)

for url in http://127.0.0.1:9100/ http://127.0.0.1:9200/; do
  for _ in $(seq 60); do curl -sf "$url" >/dev/null && break; sleep 0.5; done
done
sleep 1
"$PY" "$HERE/compare_backends.py" --py http://127.0.0.1:9100 --php http://127.0.0.1:9200 \
  --py-ws ws://127.0.0.1:9100/ws/rt --php-ws ws://127.0.0.1:9201/ws/rt --dump "$WORK/results.json"

echo "--- database state after the run ---"
"$PY" "$HERE/compare_databases.py" "$WORK/py/data/data.db" "$WORK/php/data.db"

echo "--- cross-compatibility (rollback safety) ---"
TOKEN=$(curl -sf -XPOST http://127.0.0.1:9100/auth/login -H 'content-type: application/json' \
  -d '{"username_or_email":"admin","password":"admin123"}' | "$PY" -c 'import json,sys;print(json.load(sys.stdin)["access_token"])')
code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:9200/auth/me -H "Authorization: Bearer $TOKEN")
echo "FastAPI-issued JWT accepted by PHP: HTTP $code"; [ "$code" = 200 ]
HASH=$(cd "$PHPDIR" && php -r 'require "vendor/autoload.php"; echo App\Support\Passwords::hash("rollback-pass");')
"$PY" -c "import sys; from passlib.context import CryptContext; ok=CryptContext(schemes=['bcrypt']).verify('rollback-pass', sys.argv[1]); print('PHP bcrypt hash verified by passlib:', ok); sys.exit(0 if ok else 1)" "$HASH"
