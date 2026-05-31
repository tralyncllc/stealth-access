#!/usr/bin/env bash
# Bring up the CI WordPress + MySQL stack. Run from the plugin repo root.
set -euo pipefail

COMPOSE_FILE="${COMPOSE_FILE:-tests/ci/docker-compose.ci.yml}"
PROJECT="${COMPOSE_PROJECT_NAME:-stealth-access-ci}"

echo "==> Starting CI stack ($PROJECT) from $COMPOSE_FILE"
docker compose -p "$PROJECT" -f "$COMPOSE_FILE" up -d
echo "==> Stack started. Services:"
docker compose -p "$PROJECT" -f "$COMPOSE_FILE" ps
