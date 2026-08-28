#!/usr/bin/env bash
set -euo pipefail

HARNESS_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd -- "$HARNESS_DIR/../../.." && pwd)"
COMPOSE_FILE="$HARNESS_DIR/docker-compose.yml"
LEGACY_COMPOSE_FILE="$HARNESS_DIR/docker-compose.legacy.yml"
RUN_TOKEN="$(date +%s%N)-$$-$RANDOM"
COMPOSE_PROJECT_NAME="pos-api-tcip-$RUN_TOKEN"

TRUE_CLIENT_IP_APP_NETWORK="$COMPOSE_PROJECT_NAME-app"
TRUE_CLIENT_IP_NPM_APP_NETWORK="$COMPOSE_PROJECT_NAME-npm-app"
TRUE_CLIENT_IP_CLIENT_V4_NETWORK="$COMPOSE_PROJECT_NAME-client-v4"
TRUE_CLIENT_IP_CLIENT_V6_NETWORK="$COMPOSE_PROJECT_NAME-client-v6"
TRUE_CLIENT_IP_CF_V4_NETWORK="$COMPOSE_PROJECT_NAME-cf-v4"
TRUE_CLIENT_IP_CF_V6_NETWORK="$COMPOSE_PROJECT_NAME-cf-v6"
TRUE_CLIENT_IP_DIRECT_NETWORK="$COMPOSE_PROJECT_NAME-direct"
export COMPOSE_PROJECT_NAME TRUE_CLIENT_IP_APP_NETWORK
export TRUE_CLIENT_IP_NPM_APP_NETWORK TRUE_CLIENT_IP_CLIENT_V4_NETWORK
export TRUE_CLIENT_IP_CLIENT_V6_NETWORK TRUE_CLIENT_IP_CF_V4_NETWORK
export TRUE_CLIENT_IP_CF_V6_NETWORK TRUE_CLIENT_IP_DIRECT_NETWORK

CREATED_NETWORKS=()

compose() {
    docker compose --project-name "$COMPOSE_PROJECT_NAME" --file "$COMPOSE_FILE" "$@"
}

cleanup() {
    if [[ "${TRUE_CLIENT_IP_KEEP_STACK:-0}" != "1" ]]; then
        compose down --volumes --remove-orphans --rmi local >/dev/null 2>&1 || true
        if ((${#CREATED_NETWORKS[@]})); then
            docker network rm "${CREATED_NETWORKS[@]}" >/dev/null 2>&1 || true
        fi
    fi
}

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

random_subnet() {
    local parent_network="$1"
    local prefix_length="$2"

    python3 -c '
import ipaddress
import secrets
import sys

parent = ipaddress.ip_network(sys.argv[1])
prefix = int(sys.argv[2])
subnet_count = 1 << (prefix - parent.prefixlen)
offset = secrets.randbelow(subnet_count) << (parent.max_prefixlen - prefix)
print(ipaddress.ip_network((int(parent.network_address) + offset, prefix)))
' "$parent_network" "$prefix_length"
}

create_isolated_network() {
    local network_name="$1"
    local parent_network="$2"
    local prefix_length="$3"
    local address_family="$4"
    local output_variable="$5"
    local attempt subnet error
    local -a family_options=()

    if [[ "$address_family" == "6" ]]; then
        family_options=(--ipv4=false --ipv6)
    fi

    for attempt in $(seq 1 64); do
        subnet="$(random_subnet "$parent_network" "$prefix_length")"
        if error="$(docker network create --driver bridge --internal \
            "${family_options[@]}" --subnet "$subnet" \
            --label org.charity.pos.true-client-ip-harness=true \
            --label "org.charity.pos.true-client-ip-run=$RUN_TOKEN" \
            "$network_name" 2>&1)"; then
            CREATED_NETWORKS+=("$network_name")
            printf -v "$output_variable" '%s' "$subnet"
            return
        fi
    done

    fail "could not reserve a non-overlapping $address_family-bit subnet for $network_name: $error"
}

pass() {
    printf 'PASS: %s\n' "$*"
}

json_value() {
    local key="$1"
    python3 -c 'import json, sys; value=json.load(sys.stdin)[sys.argv[1]]; print(str(value).lower() if isinstance(value, bool) else value)' "$key" <<<"$LAST_BODY"
}

service_network_ip() {
    local service="$1"
    local network_name="$2"
    local address_key="$3"
    local container_id value

    container_id="$(compose ps -q "$service")"
    [[ -n "$container_id" ]] || fail "container for service $service is not running"

    value="$(docker inspect "$container_id" | python3 -c '
import json
import sys

networks = json.load(sys.stdin)[0]["NetworkSettings"]["Networks"]
network = networks.get(sys.argv[1])
if network is None:
    raise SystemExit(f"container is not attached to {sys.argv[1]}")
value = network.get(sys.argv[2], "")
if not value:
    raise SystemExit(f"{sys.argv[2]} is empty on {sys.argv[1]}")
print(value)
' "$network_name" "$address_key")" || fail "could not inspect $service on $network_name"

    printf '%s\n' "$value"
}

require_shared_ipv6_64() {
    local first_address="$1"
    local second_address="$2"

    [[ "$first_address" != "$second_address" ]] \
        || fail 'the two IPv6 clients unexpectedly received the same address'
    python3 -c '
import ipaddress
import sys

first = ipaddress.ip_network(f"{sys.argv[1]}/64", strict=False)
second = ipaddress.ip_network(f"{sys.argv[2]}/64", strict=False)
raise SystemExit(0 if first == second else 1)
' "$first_address" "$second_address" \
        || fail "IPv6 clients are not in one /64: $first_address and $second_address"
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

request_ipv6_limiter_batch() {
    local base_url="$1"
    local output

    if ! output="$(compose exec -T client_v6_a sh -c '
set -eu
base_url="$1"
request_number=1
while [ "$request_number" -le 10 ]; do
    curl --noproxy "*" --silent --show-error --max-time 10 \
        --output /dev/null --write-out "%{http_code}\n" \
        "$base_url/_ops/true-client-ip/limited?kiosk_id=v6-$request_number"
    request_number=$((request_number + 1))
done
' sh "$base_url")"; then
        fail 'IPv6 limiter request batch failed'
    fi

    mapfile -t IPV6_BATCH_STATUSES <<<"$output"
    [[ "${#IPV6_BATCH_STATUSES[@]}" == "10" ]] \
        || fail "IPv6 limiter batch returned ${#IPV6_BATCH_STATUSES[@]} statuses instead of 10: $output"
}

trap cleanup EXIT

command -v docker >/dev/null || fail 'docker is required'
command -v python3 >/dev/null || fail 'python3 is required for JSON assertions'
[[ -f "$REPO_DIR/src/vendor/autoload.php" ]] || fail 'src/vendor/autoload.php is required'

# The randomized fake-edge subnets must remain covered by the implementation-
# time live Cloudflare fetch. This fails after a range refresh removes a parent.
grep -Fq '173.245.48.0/20 1;' "$REPO_DIR/docker/prod/nginx/cloudflare-ips.conf" \
    || fail 'fake IPv4 edge is no longer covered by cloudflare-ips.conf'
grep -Fq '2400:cb00::/32 1;' "$REPO_DIR/docker/prod/nginx/cloudflare-ips.conf" \
    || fail 'fake IPv6 edge is no longer covered by cloudflare-ips.conf'

create_isolated_network "$TRUE_CLIENT_IP_CLIENT_V6_NETWORK" '2001:db8::/32' 64 6 \
    TRUE_CLIENT_IP_CLIENT_V6_SUBNET
create_isolated_network "$TRUE_CLIENT_IP_CF_V4_NETWORK" '173.245.48.0/20' 29 4 \
    TRUE_CLIENT_IP_CF_V4_SUBNET
create_isolated_network "$TRUE_CLIENT_IP_CF_V6_NETWORK" '2400:cb00::/32' 64 6 \
    TRUE_CLIENT_IP_CF_V6_SUBNET

printf 'Harness Compose project: %s\n' "$COMPOSE_PROJECT_NAME"
printf 'Reserved run-only subnets: client-v6=%s cf-v4=%s cf-v6=%s\n' \
    "$TRUE_CLIENT_IP_CLIENT_V6_SUBNET" \
    "$TRUE_CLIENT_IP_CF_V4_SUBNET" \
    "$TRUE_CLIENT_IP_CF_V6_SUBNET"
if [[ "${TRUE_CLIENT_IP_KEEP_STACK:-0}" == "1" ]]; then
    printf 'Manual cleanup: docker compose --project-name %q --file %q down --volumes --remove-orphans --rmi local\n' \
        "$COMPOSE_PROJECT_NAME" "$COMPOSE_FILE"
    printf 'Then remove run-only networks: docker network rm %q %q %q\n' \
        "$TRUE_CLIENT_IP_CLIENT_V6_NETWORK" \
        "$TRUE_CLIENT_IP_CF_V4_NETWORK" \
        "$TRUE_CLIENT_IP_CF_V6_NETWORK"
fi

docker compose --project-name "$COMPOSE_PROJECT_NAME" --file "$COMPOSE_FILE" config --quiet
compose up --detach --build

V4_CHAIN_URL='http://fake_cloudflare_v4'
V6_CHAIN_URL='http://fake_cloudflare_v6'
DIRECT_CHAIN_URL='http://fake_npm'

for attempt in $(seq 1 30); do
    if request client_v4 "$V4_CHAIN_URL/_ops/true-client-ip" 2>/dev/null \
        && [[ "$LAST_STATUS" == "200" ]]; then
        break
    fi
    [[ "$attempt" != "30" ]] || fail 'the real chain did not become ready within 60 seconds'
    sleep 2
done

CLIENT_V4_IP="$(service_network_ip client_v4 "$TRUE_CLIENT_IP_CLIENT_V4_NETWORK" IPAddress)"
CLIENT_V6_A_IP="$(service_network_ip client_v6_a "$TRUE_CLIENT_IP_CLIENT_V6_NETWORK" GlobalIPv6Address)"
CLIENT_V6_B_IP="$(service_network_ip client_v6_b "$TRUE_CLIENT_IP_CLIENT_V6_NETWORK" GlobalIPv6Address)"
DIRECT_CLIENT_IP="$(service_network_ip direct_client "$TRUE_CLIENT_IP_DIRECT_NETWORK" IPAddress)"
CF_EDGE_V4_IP="$(service_network_ip fake_cloudflare_v4 "$TRUE_CLIENT_IP_CF_V4_NETWORK" IPAddress)"
NPM_APP_IP="$(service_network_ip fake_npm "$TRUE_CLIENT_IP_NPM_APP_NETWORK" IPAddress)"
require_shared_ipv6_64 "$CLIENT_V6_A_IP" "$CLIENT_V6_B_IP"

printf '%s\n' '--- IPv4 full-chain attribution and FastCGI override ---'
request client_v4 "$V4_CHAIN_URL/_ops/true-client-ip"
assert_eq 200 "$LAST_STATUS" 'honest IPv4 response status'
assert_eq "$CLIENT_V4_IP" "$(json_value request_ip)" 'Laravel request IP'
assert_eq "$CLIENT_V4_IP" "$(json_value server_remote_addr)" 'Request server REMOTE_ADDR'
assert_eq "$CLIENT_V4_IP" "$(json_value php_server_remote_addr)" 'PHP-FPM REMOTE_ADDR'
assert_eq true "$(json_value request_secure)" 'Laravel secure request'

printf '%s\n' '--- forged full-chain headers are inert ---'
request client_v4 "$V4_CHAIN_URL/_ops/true-client-ip" \
    -H 'X-Forwarded-For: 1.2.3.4' \
    -H 'X-Real-IP: 5.6.7.8' \
    -H 'CF-Connecting-IP: 9.10.11.12'
assert_eq 200 "$LAST_STATUS" 'forged-header response status'
assert_eq "$CLIENT_V4_IP" "$(json_value request_ip)" 'forged-header attribution'
assert_contains 1.2.3.4 "$(json_value received_x_forwarded_for)" 'forwarded forged XFF evidence'

printf '%s\n' '--- direct-to-origin path ignores forged client headers ---'
request direct_client "$DIRECT_CHAIN_URL/_ops/true-client-ip" \
    -H 'X-Forwarded-For: 1.2.3.4' \
    -H 'X-Real-IP: 5.6.7.8' \
    -H 'CF-Connecting-IP: 9.10.11.12'
assert_eq 200 "$LAST_STATUS" 'direct-path response status'
assert_eq "$DIRECT_CLIENT_IP" "$(json_value request_ip)" 'direct caller attribution'
assert_eq "$DIRECT_CLIENT_IP" "$(json_value php_server_remote_addr)" 'direct caller PHP-FPM REMOTE_ADDR'

printf '%s\n' '--- direct-to-origin caller consumes only its own limiter bucket ---'
clear_limiter
for request_number in $(seq 1 10); do
    request direct_client "$DIRECT_CHAIN_URL/_ops/true-client-ip/limited?kiosk_id=direct-$request_number" \
        -H "X-Forwarded-For: 10.10.0.$request_number" \
        -H "X-Real-IP: 192.0.2.$request_number" \
        -H "CF-Connecting-IP: 198.51.100.$request_number"
    assert_eq 200 "$LAST_STATUS" "direct caller request $request_number status"
    assert_eq "$DIRECT_CLIENT_IP" "$(json_value request_ip)" \
        "direct caller request $request_number attribution"
done
request direct_client "$DIRECT_CHAIN_URL/_ops/true-client-ip/limited?kiosk_id=direct-11"
assert_eq 429 "$LAST_STATUS" 'direct caller request 11 status'

# The Cloudflare-path customer has a different real address and must not share
# the direct caller's exhausted bucket.
request client_v4 "$V4_CHAIN_URL/_ops/true-client-ip/limited?kiosk_id=direct-independent"
assert_eq 200 "$LAST_STATUS" 'Cloudflare customer remains independent of direct caller'
assert_eq "$CLIENT_V4_IP" "$(json_value request_ip)" 'independent Cloudflare customer attribution'

printf '%s\n' '--- malformed and missing-header fallbacks ---'
request client_v4 "$V4_CHAIN_URL/_ops/true-client-ip" -H 'X-Harness-CF-Mode: absent'
assert_eq 200 "$LAST_STATUS" 'missing CF-Connecting-IP response status'
assert_eq "$CF_EDGE_V4_IP" "$(json_value request_ip)" 'missing CF-Connecting-IP fallback'

request client_v4 "$V4_CHAIN_URL/_ops/true-client-ip" -H 'X-Harness-CF-Mode: invalid'
assert_eq 200 "$LAST_STATUS" 'invalid CF-Connecting-IP response status'
assert_eq "$CF_EDGE_V4_IP" "$(json_value request_ip)" 'invalid CF-Connecting-IP fallback'

request client_v4 "$V4_CHAIN_URL/_ops/true-client-ip" -H 'X-Harness-NPM-Mode: absent'
assert_eq 200 "$LAST_STATUS" 'missing X-Real-IP response status'
assert_eq "$NPM_APP_IP" "$(json_value request_ip)" 'missing X-Real-IP fallback'

request client_v4 "$V4_CHAIN_URL/_ops/true-client-ip" -H 'X-Harness-NPM-Mode: invalid'
assert_eq 200 "$LAST_STATUS" 'invalid X-Real-IP response status'
assert_eq "$NPM_APP_IP" "$(json_value request_ip)" 'invalid X-Real-IP fallback'

printf '%s\n' '--- rotating forged headers cannot rotate limiter buckets ---'
clear_limiter
for request_number in $(seq 1 10); do
    request client_v4 "$V4_CHAIN_URL/_ops/true-client-ip/limited?kiosk_id=rotated-$request_number" \
        -H "X-Forwarded-For: 10.0.0.$request_number" \
        -H "X-Real-IP: 192.0.2.$request_number" \
        -H "CF-Connecting-IP: 203.0.113.$request_number"
    assert_eq 200 "$LAST_STATUS" "rotated-header request $request_number status"
    assert_eq "$CLIENT_V4_IP" "$(json_value request_ip)" \
        "rotated-header request $request_number attribution"
done
request client_v4 "$V4_CHAIN_URL/_ops/true-client-ip/limited?kiosk_id=rotated-11" \
    -H 'X-Forwarded-For: 10.0.0.11' \
    -H 'X-Real-IP: 192.0.2.11' \
    -H 'CF-Connecting-IP: 203.0.113.11'
assert_eq 429 "$LAST_STATUS" 'rotated-header request 11 status'

printf '%s\n' '--- IPv6 full-chain attribution and /64 limiter key ---'
request client_v6_a "$V6_CHAIN_URL/_ops/true-client-ip"
assert_eq 200 "$LAST_STATUS" 'honest IPv6 response status'
assert_eq "$CLIENT_V6_A_IP" "$(json_value request_ip)" 'Laravel IPv6 request IP'
assert_eq "$CLIENT_V6_A_IP" "$(json_value php_server_remote_addr)" 'IPv6 PHP-FPM REMOTE_ADDR'

clear_limiter
request_ipv6_limiter_batch "$V6_CHAIN_URL"
for request_number in $(seq 1 10); do
    assert_eq 200 "${IPV6_BATCH_STATUSES[$((request_number - 1))]}" \
        "IPv6 /64 request $request_number status"
done
request client_v6_b "$V6_CHAIN_URL/_ops/true-client-ip/limited?kiosk_id=v6-11"
assert_eq 429 "$LAST_STATUS" 'second IPv6 address in the same /64 shares budget'

printf '%s\n' '--- real Laravel session cookie is Secure ---'
COOKIE_HEADERS="$(compose exec -T client_v4 curl --noproxy '*' --silent --show-error \
    --max-time 10 --dump-header - --output /dev/null \
    "$V4_CHAIN_URL/_ops/true-client-ip")"
printf '%s' "$COOKIE_HEADERS" | grep -Eiq \
    '^set-cookie:[[:space:]]*pos_api_true_client_ip_session=[^;]*;.*;[[:space:]]*secure([;[:space:]]|$)' \
    || fail "Laravel session cookie lacks Secure: $COOKIE_HEADERS"
pass 'Laravel pos_api_true_client_ip_session cookie carries Secure'

printf '%s\n' '--- reverted-config negative control ---'
docker compose --project-name "$COMPOSE_PROJECT_NAME" \
    --file "$COMPOSE_FILE" --file "$LEGACY_COMPOSE_FILE" \
    up --detach --force-recreate --no-deps pos_nginx >/dev/null

for attempt in $(seq 1 15); do
    if request client_v4 "$V4_CHAIN_URL/_ops/true-client-ip" 2>/dev/null \
        && [[ "$LAST_STATUS" == "200" ]]; then
        break
    fi
    [[ "$attempt" != "15" ]] || fail 'negative-control nginx did not become ready within 30 seconds'
    sleep 2
done

LEGACY_IP="$(json_value request_ip)"
[[ "$LEGACY_IP" != "$CLIENT_V4_IP" ]] \
    || fail 'negative control unexpectedly retained honest client attribution'
assert_eq "$NPM_APP_IP" "$LEGACY_IP" 'reverted config exposes NPM as Laravel client'
pass 'positive attribution evidence fails when the nginx change is reverted'

printf '%s\n' 'All real multi-hop true-client-IP checks passed.'
