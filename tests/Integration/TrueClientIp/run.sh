#!/usr/bin/env bash
set -euo pipefail

HARNESS_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd -- "$HARNESS_DIR/../../.." && pwd)"
COMPOSE_FILE="$HARNESS_DIR/docker-compose.yml"
LEGACY_COMPOSE_FILE="$HARNESS_DIR/docker-compose.legacy.yml"
COMPOSE_PROJECT_NAME="pos-api-true-client-ip"

compose() {
    docker compose --project-name "$COMPOSE_PROJECT_NAME" --file "$COMPOSE_FILE" "$@"
}

cleanup() {
    if [[ "${TRUE_CLIENT_IP_KEEP_STACK:-0}" != "1" ]]; then
        compose down --volumes --remove-orphans >/dev/null 2>&1 || true
    fi
}

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

pass() {
    printf 'PASS: %s\n' "$*"
}

json_value() {
    local key="$1"
    python3 -c 'import json, sys; value=json.load(sys.stdin)[sys.argv[1]]; print(str(value).lower() if isinstance(value, bool) else value)' "$key" <<<"$LAST_BODY"
}

assert_eq() {
    local expected="$1"
    local actual="$2"
    local label="$3"
    [[ "$actual" == "$expected" ]] || fail "$label: expected '$expected', observed '$actual'; body=$LAST_BODY"
    pass "$label = $actual"
}

assert_contains() {
    local needle="$1"
    local haystack="$2"
    local label="$3"
    [[ "$haystack" == *"$needle"* ]] || fail "$label: '$needle' absent from '$haystack'"
    pass "$label contains $needle"
}

request() {
    local service="$1"
    local url="$2"
    shift 2

    local output
    output="$(compose exec -T "$service" curl --noproxy '*' --silent --show-error \
        --max-time 10 --write-out $'\n%{http_code}' "$@" "$url")"
    LAST_STATUS="${output##*$'\n'}"
    LAST_BODY="${output%$'\n'*}"
}

clear_limiter() {
    compose exec -T test_redis redis-cli -n 1 FLUSHDB >/dev/null
}

trap cleanup EXIT

command -v docker >/dev/null || fail 'docker is required'
command -v python3 >/dev/null || fail 'python3 is required for JSON assertions'
[[ -f "$REPO_DIR/src/vendor/autoload.php" ]] || fail 'src/vendor/autoload.php is required'

# The fake edge addresses must remain covered by the implementation-time live
# Cloudflare fetch. This intentionally fails after a range refresh removes one.
grep -Fq '173.245.48.0/20 1;' "$REPO_DIR/docker/prod/nginx/cloudflare-ips.conf" \
    || fail 'fake IPv4 edge is no longer covered by cloudflare-ips.conf'
grep -Fq '2400:cb00::/32 1;' "$REPO_DIR/docker/prod/nginx/cloudflare-ips.conf" \
    || fail 'fake IPv6 edge is no longer covered by cloudflare-ips.conf'

docker compose --project-name "$COMPOSE_PROJECT_NAME" --file "$COMPOSE_FILE" config --quiet
compose up --detach --build

for attempt in $(seq 1 30); do
    if request client_v4 'http://198.51.100.2/_ops/true-client-ip' 2>/dev/null \
        && [[ "$LAST_STATUS" == "200" ]]; then
        break
    fi
    [[ "$attempt" != "30" ]] || fail 'the real chain did not become ready within 60 seconds'
    sleep 2
done

printf '%s\n' '--- IPv4 full-chain attribution and FastCGI override ---'
request client_v4 'http://198.51.100.2/_ops/true-client-ip'
assert_eq 200 "$LAST_STATUS" 'honest IPv4 response status'
assert_eq 198.51.100.10 "$(json_value request_ip)" 'Laravel request IP'
assert_eq 198.51.100.10 "$(json_value server_remote_addr)" 'Request server REMOTE_ADDR'
assert_eq 198.51.100.10 "$(json_value php_server_remote_addr)" 'PHP-FPM REMOTE_ADDR'
assert_eq true "$(json_value request_secure)" 'Laravel secure request'

printf '%s\n' '--- forged full-chain headers are inert ---'
request client_v4 'http://198.51.100.2/_ops/true-client-ip' \
    -H 'X-Forwarded-For: 1.2.3.4' \
    -H 'X-Real-IP: 5.6.7.8' \
    -H 'CF-Connecting-IP: 9.10.11.12'
assert_eq 200 "$LAST_STATUS" 'forged-header response status'
assert_eq 198.51.100.10 "$(json_value request_ip)" 'forged-header attribution'
assert_contains 1.2.3.4 "$(json_value received_x_forwarded_for)" 'forwarded forged XFF evidence'

printf '%s\n' '--- direct-to-origin path ignores forged client headers ---'
request direct_client 'http://203.0.113.2/_ops/true-client-ip' \
    -H 'X-Forwarded-For: 1.2.3.4' \
    -H 'X-Real-IP: 5.6.7.8' \
    -H 'CF-Connecting-IP: 9.10.11.12'
assert_eq 200 "$LAST_STATUS" 'direct-path response status'
assert_eq 203.0.113.10 "$(json_value request_ip)" 'direct caller attribution'
assert_eq 203.0.113.10 "$(json_value php_server_remote_addr)" 'direct caller PHP-FPM REMOTE_ADDR'

printf '%s\n' '--- direct-to-origin caller consumes only its own limiter bucket ---'
clear_limiter
for request_number in $(seq 1 10); do
    request direct_client "http://203.0.113.2/_ops/true-client-ip/limited?kiosk_id=direct-$request_number" \
        -H "X-Forwarded-For: 10.10.0.$request_number" \
        -H "X-Real-IP: 192.0.2.$request_number" \
        -H "CF-Connecting-IP: 198.51.100.$request_number"
    assert_eq 200 "$LAST_STATUS" "direct caller request $request_number status"
    assert_eq 203.0.113.10 "$(json_value request_ip)" "direct caller request $request_number attribution"
done
request direct_client 'http://203.0.113.2/_ops/true-client-ip/limited?kiosk_id=direct-11'
assert_eq 429 "$LAST_STATUS" 'direct caller request 11 status'

# The Cloudflare-path customer has a different real address and must not share
# the direct caller's exhausted bucket.
request client_v4 'http://198.51.100.2/_ops/true-client-ip/limited?kiosk_id=direct-independent'
assert_eq 200 "$LAST_STATUS" 'Cloudflare customer remains independent of direct caller'
assert_eq 198.51.100.10 "$(json_value request_ip)" 'independent Cloudflare customer attribution'

printf '%s\n' '--- malformed and missing-header fallbacks ---'
request client_v4 'http://198.51.100.2/_ops/true-client-ip' -H 'X-Harness-CF-Mode: absent'
assert_eq 200 "$LAST_STATUS" 'missing CF-Connecting-IP response status'
assert_eq 173.245.63.251 "$(json_value request_ip)" 'missing CF-Connecting-IP fallback'

request client_v4 'http://198.51.100.2/_ops/true-client-ip' -H 'X-Harness-CF-Mode: invalid'
assert_eq 200 "$LAST_STATUS" 'invalid CF-Connecting-IP response status'
assert_eq 173.245.63.251 "$(json_value request_ip)" 'invalid CF-Connecting-IP fallback'

request client_v4 'http://198.51.100.2/_ops/true-client-ip' -H 'X-Harness-NPM-Mode: absent'
assert_eq 200 "$LAST_STATUS" 'missing X-Real-IP response status'
assert_eq 10.254.252.2 "$(json_value request_ip)" 'missing X-Real-IP fallback'

request client_v4 'http://198.51.100.2/_ops/true-client-ip' -H 'X-Harness-NPM-Mode: invalid'
assert_eq 200 "$LAST_STATUS" 'invalid X-Real-IP response status'
assert_eq 10.254.252.2 "$(json_value request_ip)" 'invalid X-Real-IP fallback'

printf '%s\n' '--- rotating forged headers cannot rotate limiter buckets ---'
clear_limiter
for request_number in $(seq 1 10); do
    request client_v4 "http://198.51.100.2/_ops/true-client-ip/limited?kiosk_id=rotated-$request_number" \
        -H "X-Forwarded-For: 10.0.0.$request_number" \
        -H "X-Real-IP: 192.0.2.$request_number" \
        -H "CF-Connecting-IP: 203.0.113.$request_number"
    assert_eq 200 "$LAST_STATUS" "rotated-header request $request_number status"
    assert_eq 198.51.100.10 "$(json_value request_ip)" \
        "rotated-header request $request_number attribution"
done
request client_v4 'http://198.51.100.2/_ops/true-client-ip/limited?kiosk_id=rotated-11' \
    -H 'X-Forwarded-For: 10.0.0.11' \
    -H 'X-Real-IP: 192.0.2.11' \
    -H 'CF-Connecting-IP: 203.0.113.11'
assert_eq 429 "$LAST_STATUS" 'rotated-header request 11 status'

printf '%s\n' '--- IPv6 full-chain attribution and /64 limiter key ---'
request client_v6_a 'http://[2001:db8:100::2]/_ops/true-client-ip'
assert_eq 200 "$LAST_STATUS" 'honest IPv6 response status'
assert_eq 2001:db8:100::10 "$(json_value request_ip)" 'Laravel IPv6 request IP'
assert_eq 2001:db8:100::10 "$(json_value php_server_remote_addr)" 'IPv6 PHP-FPM REMOTE_ADDR'

clear_limiter
for request_number in $(seq 1 10); do
    request client_v6_a "http://[2001:db8:100::2]/_ops/true-client-ip/limited?kiosk_id=v6-$request_number"
    assert_eq 200 "$LAST_STATUS" "IPv6 /64 request $request_number status"
done
request client_v6_b 'http://[2001:db8:100::2]/_ops/true-client-ip/limited?kiosk_id=v6-11'
assert_eq 429 "$LAST_STATUS" 'second IPv6 address in the same /64 shares budget'

printf '%s\n' '--- real Laravel session cookie is Secure ---'
COOKIE_HEADERS="$(compose exec -T client_v4 curl --noproxy '*' --silent --show-error \
    --max-time 10 --dump-header - --output /dev/null \
    'http://198.51.100.2/_ops/true-client-ip')"
printf '%s' "$COOKIE_HEADERS" | grep -Eiq \
    '^set-cookie:[[:space:]]*pos_api_true_client_ip_session=[^;]*;.*;[[:space:]]*secure([;[:space:]]|$)' \
    || fail "Laravel session cookie lacks Secure: $COOKIE_HEADERS"
pass 'Laravel pos_api_true_client_ip_session cookie carries Secure'

printf '%s\n' '--- reverted-config negative control ---'
docker compose --project-name "$COMPOSE_PROJECT_NAME" \
    --file "$COMPOSE_FILE" --file "$LEGACY_COMPOSE_FILE" \
    up --detach --force-recreate --no-deps pos_nginx >/dev/null

for attempt in $(seq 1 15); do
    if request client_v4 'http://198.51.100.2/_ops/true-client-ip' 2>/dev/null \
        && [[ "$LAST_STATUS" == "200" ]]; then
        break
    fi
    [[ "$attempt" != "15" ]] || fail 'negative-control nginx did not become ready within 30 seconds'
    sleep 2
done

LEGACY_IP="$(json_value request_ip)"
[[ "$LEGACY_IP" != "198.51.100.10" ]] \
    || fail 'negative control unexpectedly retained honest client attribution'
assert_eq 10.254.252.2 "$LEGACY_IP" 'reverted config exposes NPM as Laravel client'
pass 'positive attribution evidence fails when the nginx change is reverted'

printf '%s\n' 'All real multi-hop true-client-IP checks passed.'
