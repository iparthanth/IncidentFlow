#!/bin/sh
#
# Container entrypoint.
#
# Deliberately does NOT run migrations. A migration executed by every replica
# on start is a race at best and a partially-migrated schema at worst; schema
# changes belong in a release job that runs exactly once. See docs/runbook.md.
set -eu

echo "{\"level\":\"info\",\"service\":\"incidentflow-api\",\"message\":\"container starting\",\"role\":\"${1:-php-fpm}\"}"

# Fail fast and loudly on missing configuration rather than booting into a
# state where the first request 500s for a reason nobody can see.
if [ -z "${APP_KEY:-}" ]; then
  echo "{\"level\":\"fatal\",\"message\":\"APP_KEY is not set\"}" >&2
  exit 1
fi

# Wait for PostgreSQL. Compose's depends_on only waits for the container, not
# for the database inside it to finish initialising.
if [ -n "${DB_HOST:-}" ]; then
  attempt=0
  until php -r "new PDO('pgsql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT') ?: 5432).';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));" 2>/dev/null; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
      echo "{\"level\":\"fatal\",\"message\":\"database unreachable after 30 attempts\"}" >&2
      exit 1
    fi
    echo "{\"level\":\"info\",\"message\":\"waiting for database\",\"attempt\":${attempt}}"
    sleep 2
  done
fi

# Generate the signing key pair if the mounted volume is empty. In a real
# deployment the keys are injected as secrets and this is a no-op; it exists so
# `docker compose up` works on a fresh clone with no manual step.
#
# Single-writer, because this is not a single container. api, migrate, horizon
# and scheduler share this volume and are all released together once Postgres
# reports healthy, so all four reach this check within milliseconds of each
# other. `mkdir` is atomic on POSIX: exactly one wins and generates the pair,
# and the others wait for the result. Without it two containers both see the
# file missing and run `jwt:keys --force`, and whichever finishes second
# invalidates every token the first has already signed — which surfaces later
# as inexplicable "invalid signature" errors in the realtime tier.
#
# The lock MUST be recoverable. A held lock and an abandoned one look identical
# from the outside, so a waiter that only ever waits will hang forever on a
# volume whose winner died mid-generation — lock present, keys absent, every
# later container timing out. That state is unrecoverable without deleting the
# volume by hand, which is strictly worse than the race being prevented here.
# So the holder always releases, and a waiter that times out breaks the lock and
# generates the pair itself.
JWT_KEY_FILE="${JWT_PRIVATE_KEY_PATH:-/var/www/html/storage/keys/jwt-private.pem}"
JWT_KEY_LOCK="$(dirname "$JWT_KEY_FILE")/.keygen.lock"

release_keygen_lock() {
    rmdir "$JWT_KEY_LOCK" 2>/dev/null || true
}

if [ ! -f "$JWT_KEY_FILE" ]; then
  if mkdir "$JWT_KEY_LOCK" 2>/dev/null; then
    # Released on any exit, including a crash inside jwt:keys. Without the trap
    # `set -e` would abort the script with the lock still held.
    trap release_keygen_lock EXIT
    echo "{\"level\":\"info\",\"message\":\"generating JWT key pair\"}"
    php artisan jwt:keys --force
    release_keygen_lock
    trap - EXIT
  else
    echo "{\"level\":\"info\",\"message\":\"another container is generating the JWT key pair; waiting\"}"
    waited=0
    while [ ! -f "$JWT_KEY_FILE" ] && [ "$waited" -lt 30 ]; do
      sleep 1
      waited=$((waited + 1))
    done

    if [ ! -f "$JWT_KEY_FILE" ]; then
      echo "{\"level\":\"warning\",\"message\":\"keygen lock looks abandoned; generating the pair here instead\"}"
      release_keygen_lock
      php artisan jwt:keys --force
    fi
  fi
fi

# Cache configuration and routes. Skipped when APP_DEBUG is on so that a
# developer editing a config file sees the change without a restart.
if [ "${APP_DEBUG:-false}" != "true" ]; then
  php artisan config:cache
  php artisan route:cache
  php artisan event:cache
fi

exec "$@"
