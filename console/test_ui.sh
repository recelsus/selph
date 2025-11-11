#!/usr/bin/env bash
set -euo pipefail

SERVER_HOST=${SERVER_HOST:-0.0.0.0}
CLIENT_HOST=${CLIENT_HOST:-127.0.0.1}
PORT=${PORT:-9020}
BASE_URL="http://$CLIENT_HOST:$PORT"
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

php -S "$SERVER_HOST:$PORT" -t "$PROJECT_ROOT" "$PROJECT_ROOT/index.php" >/tmp/sample-ui.log 2>&1 &
SERVER_PID=$!
trap 'kill $SERVER_PID >/dev/null 2>&1 || true' EXIT
sleep 1

echo "\n### UI: root page"
curl -sS "$BASE_URL/"

echo "\n\n### API: sample notes_select\n"
curl -sS "$BASE_URL/api/sample/notes_select"
