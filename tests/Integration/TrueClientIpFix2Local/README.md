# OPS-QR1b real multi-hop verification

This harness proves the production nginx behavior with actual TCP hops. It does
not fabricate `REMOTE_ADDR`, inject an `Illuminate\\Http\\Request`, publish a
host port, join `charity_net`, define a database service, or run a migration.

The positive path is:

```text
client container
  -> fake Cloudflare nginx (real client source address)
  -> fake NPM nginx (Cloudflare-range source address)
  -> pos_nginx (the repository's real docker/prod/nginx/default.conf)
  -> dedicated pos_api PHP-FPM / Laravel
```

The direct-origin path starts at `direct_client` and enters fake NPM without the
Cloudflare hop. Dedicated Redis persists the production named limiter across
PHP-FPM requests. The PHP-FPM container uses a fixture `routes/web.php` mounted
only inside this stack; it touches Laravel's real web session and reports both
Laravel's IP and PHP's `$_SERVER['REMOTE_ADDR']`.

The BFF-consumer path starts at `bff_client` and calls `pos_nginx` directly.
With the configured test-only auth secret, ten requests from one forwarded
customer IP exhaust that customer's real `qr-checkout` IP bucket while a second
forwarded customer remains independent. Missing and wrong auth rotate forwarded
addresses but still exhaust the single BFF socket-peer bucket, proving the
fail-safe fallback to pre-W2 behavior. Every request varies `X-QR-Session` so the
limiter's independent session axis cannot manufacture either result.

Every invocation creates a unique Compose project. Ordinary IPv4 networks use
Docker's dynamic IPAM; the IPv6 client network and both Cloudflare-range edge
networks are atomically reserved from random, non-overlapping subnets before
Compose starts. The runner discovers each container address from Docker rather
than assuming fixed values. Containers, Redis, volumes, the built application
image, networks, and teardown are therefore scoped to one invocation, so two
runs can execute concurrently without sharing counters or deleting each other.

Run from the `pos_api` repository root:

```bash
bash tests/Integration/TrueClientIp/run.sh
```

The runner checks IPv4 and IPv6 attribution, all missing/invalid header
fallbacks, forgery resistance, direct-origin attribution, a 10/min limiter
under rotating headers, IPv6 `/64` budget sharing, secure request/cookie state,
the authenticated BFF consumer and its no-auth/wrong-auth fallbacks, and the
FastCGI `REMOTE_ADDR` override. It then recreates only `pos_nginx` with the
pre-change FastCGI fixture and requires honest attribution to fail. That negative
control prevents a synthetic or ineffective test from passing when the
production nginx change is reverted. A complete successful run emits exactly
**114** lines beginning with `PASS:` and no lines beginning with `FAIL:`.

By default the exact Compose project, its named volumes, its locally built
image, and its three pre-reserved external networks are removed on exit. Set
`TRUE_CLIENT_IP_KEEP_STACK=1` only when interactive inspection is needed. The
runner prints that invocation's exact project name, subnets, and cleanup
commands; use those printed commands when finished.

```bash
TRUE_CLIENT_IP_KEEP_STACK=1 bash tests/Integration/TrueClientIp/run.sh
```

The fake Cloudflare-to-NPM networks use random subnets carved from the ranges
fetched live on 2026-08-27. The runner verifies that both containing ranges are
still present in `cloudflare-ips.conf` before reserving them. After refreshing
the published ranges, update the two parent ranges in `run.sh` only if that
preflight fails.
