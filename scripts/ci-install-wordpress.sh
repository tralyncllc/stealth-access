#!/usr/bin/env bash
# Install WordPress core inside the CI stack, activate the Stealth Access
# plugin, set permalinks, and create the ciadmin user the Playwright suite
# logs in as. Idempotent: re-runnable without resetting the DB.
set -euo pipefail

COMPOSE_FILE="${COMPOSE_FILE:-tests/ci/docker-compose.ci.yml}"
PROJECT="${COMPOSE_PROJECT_NAME:-stealth-access-ci}"

WP_URL="${WP_BASE_URL:-http://localhost:8889}"
WP_TITLE="${WP_TITLE:-Stealth Access CI}"
WP_ADMIN_USER="${WP_ADMIN_USER:-ciadmin}"
WP_ADMIN_PASS="${WP_ADMIN_PASS:-Ci-Admin-Pass-2026!}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-ciadmin@example.test}"

wpcli() {
  docker compose -p "$PROJECT" -f "$COMPOSE_FILE" exec -T wpcli wp "$@"
}

echo "==> Installing WordPress core (url=$WP_URL)"
if wpcli core is-installed 2>/dev/null; then
  echo "    Already installed, skipping core install"
else
  wpcli core install \
    --url="$WP_URL" \
    --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASS" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --skip-email
fi

echo "==> Setting permalink structure"
wpcli rewrite structure '/%postname%/' --hard

echo "==> Activating Stealth Access plugin"
wpcli plugin activate stealth-access

echo "==> Plugin status:"
wpcli plugin get stealth-access --fields=name,status,version

echo "==> Done. Site: $WP_URL  Admin: $WP_ADMIN_USER / $WP_ADMIN_PASS"
