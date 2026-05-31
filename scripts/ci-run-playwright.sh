#!/usr/bin/env bash
# Run the Playwright suite against the CI WordPress stack. Assumes:
#   - tests/ci/docker-compose.ci.yml is up
#   - ci-install-wordpress.sh has been run
#   - Node / npm and Playwright browsers are installed (CI installs them
#     in earlier steps; locally you can do `npm ci && npx playwright install --with-deps chromium`)
set -euo pipefail

COMPOSE_FILE="${COMPOSE_FILE:-tests/ci/docker-compose.ci.yml}"
PROJECT="${COMPOSE_PROJECT_NAME:-stealth-access-ci}"

# Default to the CI URL + ciadmin credentials. Override via env to point
# the suite at a different WordPress (e.g. the dev instance).
export WP_BASE_URL="${WP_BASE_URL:-http://localhost:8889}"
export WP_ADMIN_USER="${WP_ADMIN_USER:-ciadmin}"
export WP_ADMIN_PASS="${WP_ADMIN_PASS:-Ci-Admin-Pass-2026!}"

# Resolve compose file to an absolute path so tests can `cd` into
# tests/playwright/ without breaking the wp-cli call.
COMPOSE_FILE_ABS="$(cd "$(dirname "$COMPOSE_FILE")" && pwd)/$(basename "$COMPOSE_FILE")"

# Compose wp-cli invocation through the CI stack so tests can shell out to
# `wp` without hardcoding a container name.
export WP_CLI_CMD="docker compose -p ${PROJECT} -f ${COMPOSE_FILE_ABS} exec -T wpcli wp"

cd tests/playwright

echo "==> Running Playwright with"
echo "    WP_BASE_URL=$WP_BASE_URL"
echo "    WP_ADMIN_USER=$WP_ADMIN_USER"
echo "    WP_CLI_CMD=$WP_CLI_CMD"

exec npx playwright test "$@"
