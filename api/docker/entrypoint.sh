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
if [ ! -f "${JWT_PRIVATE_KEY_PATH:-/var/www/html/storage/keys/jwt-private.pem}" ]; then
  echo "{\"level\":\"info\",\"message\":\"generating JWT key pair\"}"
  php artisan jwt:keys --force
fi

# Cache configuration and routes. Skipped when APP_DEBUG is on so that a
# developer editing a config file sees the change without a restart.
if [ "${APP_DEBUG:-false}" != "true" ]; then
  php artisan config:cache
  php artisan route:cache
  php artisan event:cache
fi

exec "$@"
