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
deploy_restart_since=$(date -u +%Y-%m-%dT%H:%M:%SZ)
timeout 300 docker compose -f "$C" --profile deploy run --rm deploy
docker compose -f "$C" up -d
# schedule:work starts a fresh schedule:run child each minute, so it picks up
# the rebuilt shared config without a signal. Restarting it could kill an
# active recovery sweep; only PHP-FPM needs an explicit bind-mount reload.
docker compose -f "$C" restart pos_api nginx

# The API has no public page; any non-5xx proves nginx -> php -> router. Also
# prove the recovery scheduler survived the deploy instead of assuming it did.
sleep 6

check_running() {
    local service="$1"
    local container_id
    local state
    local health
    local restart_count

    container_id=$(docker compose -f "$C" ps -q "$service")
    [ -n "$container_id" ] || { echo "FAIL: $service has no container"; exit 1; }

    state=$(docker inspect --format '{{.State.Status}}' "$container_id")
    echo "$service: $state"
    [ "$state" = "running" ] || { echo "FAIL: $service is not running"; exit 1; }

    health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$container_id")
    [ -z "$health" ] || [ "$health" = "healthy" ] || { echo "FAIL: $service is not healthy"; exit 1; }

    restart_count=$(docker inspect --format '{{.RestartCount}}' "$container_id")
    [ "$restart_count" -eq 0 ] || { echo "FAIL: $service has restarted"; exit 1; }
}

for service in pos_api scheduler; do
    check_running "$service"
done

if ! code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 https://posapi.mithqal.net/api/v1/device/config); then
    echo "FAIL: health request could not connect"
    exit 1
fi
echo "health: HTTP $code"
[[ "$code" =~ ^[1-4][0-9]{2}$ ]] || { echo "FAIL: health check"; exit 1; }

echo "fresh logs (pos_api, scheduler):"
fresh_logs=$(docker compose -f "$C" logs --since "$deploy_restart_since" --no-color pos_api scheduler 2>&1)
printf '%s\n' "$fresh_logs"
errs=$(printf '%s\n' "$fresh_logs" | grep -ciE "fatal error|exception" || true)
echo "fresh log errors: $errs"
[ "$errs" -eq 0 ] || { echo "FAIL: errors right after deploy"; exit 1; }
echo "deploy OK"
