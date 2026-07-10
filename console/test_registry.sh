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

cat <<PAYLOAD > /tmp/registry_table_payload.json
{
    "database": "$DB_NAME",
    "table": "notes",
    "columns": [
        { "name": "id", "type": "INTEGER", "primary_key": true, "auto_increment": true },
        { "name": "title", "type": "TEXT", "not_null": true },
        { "name": "content", "type": "TEXT", "not_null": true },
        { "name": "created_at", "type": "TEXT", "not_null": true, "default_expression": "CURRENT_TIMESTAMP" }
    ]
}
PAYLOAD

echo "\n\n### registry: create_table (notes)"
curl -sS -X POST \
    -H 'Content-Type: application/json' \
    -d @/tmp/registry_table_payload.json \
    "$BASE_URL/registry/create_table"

cat <<PAYLOAD > /tmp/registry_endpoint_select_payload.json
{
    "database": "$DB_NAME",
    "endpoint": "notes_select",
    "kind": "select",
    "table": "notes"
}
PAYLOAD

echo "\n\n### registry: create_endpoint (notes_select)"
curl -sS -X POST \
    -H 'Content-Type: application/json' \
    -d @/tmp/registry_endpoint_select_payload.json \
    "$BASE_URL/registry/create_endpoint"

cat <<PAYLOAD > /tmp/registry_endpoint_insert_payload.json
{
    "database": "$DB_NAME",
    "endpoint": "notes_insert",
    "kind": "insert",
    "table": "notes"
}
PAYLOAD

echo "\n\n### registry: create_endpoint (notes_insert)"
curl -sS -X POST \
    -H 'Content-Type: application/json' \
    -d @/tmp/registry_endpoint_insert_payload.json \
    "$BASE_URL/registry/create_endpoint"

cat <<PAYLOAD > /tmp/registry_endpoint_update_payload.json
{
    "database": "$DB_NAME",
    "endpoint": "notes_update",
    "kind": "update",
    "table": "notes"
}
PAYLOAD

echo "\n\n### registry: create_endpoint (notes_update)"
curl -sS -X POST \
    -H 'Content-Type: application/json' \
    -d @/tmp/registry_endpoint_update_payload.json \
    "$BASE_URL/registry/create_endpoint"

cat <<PAYLOAD > /tmp/registry_endpoint_delete_payload.json
{
    "database": "$DB_NAME",
    "endpoint": "notes_delete",
    "kind": "delete",
    "table": "notes"
}
PAYLOAD

echo "\n\n### registry: create_endpoint (notes_delete)"
curl -sS -X POST \
    -H 'Content-Type: application/json' \
    -d @/tmp/registry_endpoint_delete_payload.json \
    "$BASE_URL/registry/create_endpoint"

echo "\n\n### generated endpoint: notes_insert"
curl -sS -X POST \
    -d 'title=Generated&content=Hello' \
    "$BASE_URL/api/$DB_NAME/notes_insert"

echo "\n\n### generated endpoint: notes_update"
curl -sS -X POST \
    -d 'id=1&title=Updated&content=Changed' \
    "$BASE_URL/api/$DB_NAME/notes_update"

echo "\n\n### generated endpoint: notes_select"
curl -sS "$BASE_URL/api/$DB_NAME/notes_select"

echo "\n\n### generated endpoint: notes_delete"
curl -sS -X POST \
    -d 'id=1' \
    "$BASE_URL/api/$DB_NAME/notes_delete"

echo "\n\n### generated endpoint: notes_select after delete"
curl -sS "$BASE_URL/api/$DB_NAME/notes_select"

cat <<PAYLOAD > /tmp/registry_drop_table_payload.json
{
    "database": "$DB_NAME",
    "table": "notes"
}
PAYLOAD

echo "\n\n### registry: drop_table (notes)"
curl -sS -X POST \
    -H 'Content-Type: application/json' \
    -d @/tmp/registry_drop_table_payload.json \
    "$BASE_URL/registry/drop_table"

echo "\n\n### check database directory"
ls -1 "$PROJECT_ROOT/database/$DB_NAME"
