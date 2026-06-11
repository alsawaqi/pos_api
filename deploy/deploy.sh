#!/bin/bash
# Production deploy for pos_api — executed ON THE VPS by the GitHub Actions
# `deploy` job after the device-contract tests pass (or by hand). Assumes
# the repo was just `git pull`ed. NO migrate (pos_admin owns the shared
# schema) and NO node-build (JSON-only device API).
set -euo pipefail
cd "$(dirname "$0")/.."
C="docker-compose.prod.yml"

docker compose -f "$C" build
docker compose -f "$C" --profile build run --rm composer
docker compose -f "$C" up -d
timeout 300 docker compose -f "$C" --profile deploy run --rm deploy
docker restart pos_api-pos_api-1

# The API has no public page; any non-5xx proves nginx -> php -> router.
sleep 6
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 https://posapi.mithqal.net/api/v1/device/config)
echo "health: HTTP $code"
[ "$code" -lt 500 ] || { echo "FAIL: health check"; exit 1; }
errs=$(docker logs --since 1m pos_api-pos_api-1 2>&1 | grep -ciE "fatal error|exception" || true)
echo "fresh log errors: $errs"
[ "$errs" -eq 0 ] || { echo "FAIL: errors right after deploy"; exit 1; }
echo "deploy OK"
