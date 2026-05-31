#!/usr/bin/env bash
# Tear down the CI stack. Removes containers and their volumes.
set -euo pipefail

COMPOSE_FILE="${COMPOSE_FILE:-tests/ci/docker-compose.ci.yml}"
PROJECT="${COMPOSE_PROJECT_NAME:-stealth-access-ci}"

docker compose -p "$PROJECT" -f "$COMPOSE_FILE" down -v
