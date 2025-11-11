#!/usr/bin/env bash
set -euo pipefail

SERVER_HOST=${SERVER_HOST:-0.0.0.0}
CLIENT_HOST=${CLIENT_HOST:-127.0.0.1}
PORT=${PORT:-9010}
BASE_URL="http://$CLIENT_HOST:$PORT"
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SAMPLE_SRC="$PROJECT_ROOT/sample/database/sample"
SAMPLE_DST="$PROJECT_ROOT/database/sample"

cleanup_sample() {
    rm -rf "$SAMPLE_DST"
}

cleanup_sample
mkdir -p "$PROJECT_ROOT/database"
cp -R "$SAMPLE_SRC" "$PROJECT_ROOT/database/"
trap 'cleanup_sample; kill $SERVER_PID >/dev/null 2>&1 || true' EXIT

php -S "$SERVER_HOST:$PORT" -t "$PROJECT_ROOT" "$PROJECT_ROOT/index.php" >/tmp/sample-api.log 2>&1 &
SERVER_PID=$!
sleep 1

printf '\n### sample: notes_select (GET)\n'
curl -sS "$BASE_URL/api/sample/notes_select"

printf '\n### sample: notes_insert (POST)\n'
curl -sS -X POST -d 'title=Test&content=Hello' "$BASE_URL/api/sample/notes_insert"

printf '\n### sample: notes_select (GET)\n'
curl -sS "$BASE_URL/api/sample/notes_select"
