#!/usr/bin/env bash
set -euo pipefail

IP_ADDRESS=${1:-127.0.0.1}
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_DIR="$PROJECT_ROOT/config/security"
DB_PATH="$DB_DIR/security.sqlite"

mkdir -p "$DB_DIR"

sqlite3 "$DB_PATH" <<SQL
CREATE TABLE IF NOT EXISTS api_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    api_key TEXT NOT NULL UNIQUE,
    label TEXT
);
CREATE TABLE IF NOT EXISTS ip_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cidr TEXT NOT NULL,
    action TEXT NOT NULL CHECK(action IN ('allow','deny'))
);
SQL

sqlite3 "$DB_PATH" <<SQL
INSERT OR IGNORE INTO ip_rules (cidr, action) VALUES ('$IP_ADDRESS', 'allow');
SQL

echo "Initialized security DB at $DB_PATH"
echo "Allow rule registered for $IP_ADDRESS"
