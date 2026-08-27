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

Run from the `pos_api` repository root:

```bash
bash tests/Integration/TrueClientIp/run.sh
```

The runner checks IPv4 and IPv6 attribution, all missing/invalid header
fallbacks, forgery resistance, direct-origin attribution, a 10/min limiter
under rotating headers, IPv6 `/64` budget sharing, secure request/cookie state,
and the FastCGI `REMOTE_ADDR` override. It then recreates only `pos_nginx` with
the pre-change FastCGI fixture and requires honest attribution to fail. That
negative control prevents a synthetic or ineffective test from passing when
the production nginx change is reverted.

By default the exact Compose project is removed with its named volumes on exit.
Set `TRUE_CLIENT_IP_KEEP_STACK=1` only when interactive inspection is needed,
then clean it explicitly:

```bash
docker compose --project-name pos-api-true-client-ip \
  --file tests/Integration/TrueClientIp/docker-compose.yml \
  down --volumes --remove-orphans
```

The fake Cloudflare-to-NPM networks use tiny subnets carved from the ranges
fetched live on 2026-08-27. The runner verifies that both containing ranges are
still present in `cloudflare-ips.conf` before starting. After refreshing the
published ranges, update these test subnets only if that preflight fails.
