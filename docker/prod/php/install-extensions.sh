#!/bin/sh
# Same extension versions as the passing 2026-10-08 production-image CI run.
# Use official maintainer releases when the PECL REST/download service is down.
set -eu
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fetch() {
    url="$1"; output="$2"; checksum="$3"
    curl --fail --show-error --location --retry 4 --connect-timeout 15 --max-time 180 "$url" -o "$output"
    printf '%s  %s\n' "$checksum" "$output" | sha256sum --check --strict -
}
case "${1:?extension group required}" in
    sqlsrv)
        fetch https://github.com/microsoft/msphpsql/releases/download/v5.13.3/pdo_sqlsrv-5.13.3.tgz "$work/pdo_sqlsrv.tgz" 198a7b37da0658d36a93d158a0ec179b137b3a4d241c90a6650ae9ee8f91ec4a
        fetch https://github.com/microsoft/msphpsql/releases/download/v5.13.3/sqlsrv-5.13.3.tgz "$work/sqlsrv.tgz" 1c3092ca793bb67002ca022c412aacabb79a3297ee7005e3b7cc91b1e7166d22
        pecl install "$work/pdo_sqlsrv.tgz" "$work/sqlsrv.tgz"
        docker-php-ext-enable pdo_sqlsrv sqlsrv
        ;;
    redis)
        fetch https://github.com/phpredis/phpredis/archive/refs/tags/6.3.0.tar.gz "$work/redis.tar.gz" cb8f81df1a275599e4f8ddcfec7e1f65ed1953e6f5673649149fd680ebff4cad
        mkdir "$work/redis"
        tar -xzf "$work/redis.tar.gz" --strip-components=1 -C "$work/redis"
        (cd "$work/redis" && phpize && ./configure && make -j2 && make install)
        docker-php-ext-enable redis
        ;;
    *) printf 'Unknown extension group\n' >&2; exit 2 ;;
esac
