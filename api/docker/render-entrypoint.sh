#!/bin/sh
#
# Entrypoint for the single-container (PaaS) image.
#
# Two jobs before handing off to the normal entrypoint:
#   1. Bind whatever port the platform assigned.
#   2. Materialise injected PEM secrets onto disk.
#
# Everything else — APP_KEY validation, waiting for PostgreSQL, config caching —
# is already handled by docker/entrypoint.sh, which this execs into.
set -eu

: "${PORT:=8080}"
export PORT

envsubst '${PORT}' \
  < /etc/nginx/templates/default.conf.template \
  > /etc/nginx/http.d/default.conf

# The realtime service verifies tokens with the public half of this pair. If
# each deploy generated a fresh keypair, every realtime connection would start
# failing the moment the API restarted — and the failure would look like a
# realtime bug rather than a key rotation. So on a PaaS the keys are injected
# as secrets and written here; generation is only a local-development fallback.
KEY_DIR="${JWT_KEY_DIR:-/var/www/html/storage/keys}"
mkdir -p "$KEY_DIR"

if [ -n "${JWT_PRIVATE_KEY:-}" ]; then
  printf '%s\n' "$JWT_PRIVATE_KEY" > "$KEY_DIR/jwt-private.pem"
  chmod 600 "$KEY_DIR/jwt-private.pem"
  echo "{\"level\":\"info\",\"message\":\"JWT private key loaded from environment\"}"
fi

if [ -n "${JWT_PUBLIC_KEY:-}" ]; then
  printf '%s\n' "$JWT_PUBLIC_KEY" > "$KEY_DIR/jwt-public.pem"
  chmod 644 "$KEY_DIR/jwt-public.pem"
fi

exec /usr/local/bin/entrypoint "$@"
