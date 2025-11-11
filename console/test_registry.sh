#!/usr/bin/env bash
set -euo pipefail

SERVER_HOST=${SERVER_HOST:-0.0.0.0}
CLIENT_HOST=${CLIENT_HOST:-127.0.0.1}
PORT=${PORT:-9030}
BASE_URL="http://$CLIENT_HOST:$PORT"
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

php -S "$SERVER_HOST:$PORT" -t "$PROJECT_ROOT" "$PROJECT_ROOT/index.php" >/tmp/registry-api.log 2>&1 &
SERVER_PID=$!
trap 'kill $SERVER_PID >/dev/null 2>&1 || true' EXIT
sleep 1

DB_NAME="registry_demo_$RANDOM"

cat <<PAYLOAD > /tmp/registry_payload.json
{
    "name": "$DB_NAME",
    "meta": {
        "schema_version": 1,
        "validation": { "enabled": true },
        "tables": {}
    }
}
PAYLOAD

echo "\n### registry: create_db ($DB_NAME)"
curl -sS -X POST \
    -H 'Content-Type: application/json' \
    -d @/tmp/registry_payload.json \
    "$BASE_URL/registry/create_db"

echo "\n\n### check database directory"
ls -1 "$PROJECT_ROOT/database/$DB_NAME"
