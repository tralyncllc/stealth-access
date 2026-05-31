#!/usr/bin/env bash
# Block until the CI WordPress container is serving HTTP. We don't care
# whether it's the installer screen or the public site — only that Apache
# is up and responding.
set -euo pipefail

URL="${WP_BASE_URL:-http://localhost:8889}"
MAX_TRIES="${MAX_TRIES:-60}"
SLEEP="${SLEEP:-2}"

echo "==> Waiting for $URL (up to $((MAX_TRIES * SLEEP))s)"
for i in $(seq 1 "$MAX_TRIES"); do
  if curl -sS --max-time 3 -o /dev/null -w '%{http_code}' "$URL" 2>/dev/null | grep -qE '^[23]'; then
    echo "==> $URL responded on attempt $i"
    exit 0
  fi
  sleep "$SLEEP"
done

echo "!! $URL never became reachable" >&2
exit 1
