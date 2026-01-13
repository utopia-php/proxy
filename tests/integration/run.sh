#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE_FILES=(-f "$ROOT_DIR/docker-compose.yml" -f "$ROOT_DIR/docker-compose.integration.yml")

cleanup() {
  docker compose "${COMPOSE_FILES[@]}" down -v --remove-orphans
}

trap cleanup EXIT

MARIADB_PORT="${MARIADB_PORT:-3307}" \
REDIS_PORT="${REDIS_PORT:-6380}" \
HTTP_PROXY_PORT="${HTTP_PROXY_PORT:-18080}" \
TCP_POSTGRES_PORT="${TCP_POSTGRES_PORT:-15432}" \
TCP_MYSQL_PORT="${TCP_MYSQL_PORT:-13306}" \
SMTP_PROXY_PORT="${SMTP_PROXY_PORT:-1025}" \
docker compose "${COMPOSE_FILES[@]}" up -d --build

HTTP_PROXY_URL="${HTTP_PROXY_URL:-http://127.0.0.1:18080/}" \
HTTP_PROXY_HOST="${HTTP_PROXY_HOST:-api.example.com}" \
HTTP_EXPECTED_BODY="${HTTP_EXPECTED_BODY:-ok}" \
TCP_PROXY_HOST="${TCP_PROXY_HOST:-127.0.0.1}" \
TCP_PROXY_PORT="${TCP_PROXY_PORT:-15432}" \
SMTP_PROXY_HOST="${SMTP_PROXY_HOST:-127.0.0.1}" \
SMTP_PROXY_PORT="${SMTP_PROXY_PORT:-1025}" \
php "$ROOT_DIR/tests/integration/run.php"
