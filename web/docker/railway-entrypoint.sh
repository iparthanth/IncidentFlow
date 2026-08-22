#!/bin/sh
# Renders the edge config, then hands off to nginx.
set -eu

: "${PORT:=8080}"
: "${API_INTERNAL:=api.railway.internal:8080}"
: "${REALTIME_INTERNAL:=realtime.railway.internal:3001}"

# nginx needs an explicit resolver to re-resolve upstreams at request time, and
# hardcoding a platform's DNS address is exactly the kind of detail that changes
# without warning. The container already knows its own nameserver.
RESOLVER="$(awk '/^nameserver/ { print $2; exit }' /etc/resolv.conf)"
if [ -z "${RESOLVER}" ]; then
  echo "{\"level\":\"fatal\",\"message\":\"no nameserver in /etc/resolv.conf; cannot configure nginx resolver\"}" >&2
  exit 1
fi
# An IPv6 nameserver has to be bracketed in the resolver directive.
case "${RESOLVER}" in
  *:*) RESOLVER="[${RESOLVER}]" ;;
esac
export PORT API_INTERNAL REALTIME_INTERNAL RESOLVER

envsubst '${PORT} ${API_INTERNAL} ${REALTIME_INTERNAL} ${RESOLVER}' \
  < /etc/nginx/templates/default.conf.template \
  > /etc/nginx/conf.d/default.conf

echo "{\"level\":\"info\",\"message\":\"edge configured\",\"port\":\"${PORT}\",\"api\":\"${API_INTERNAL}\",\"realtime\":\"${REALTIME_INTERNAL}\",\"resolver\":\"${RESOLVER}\"}"
nginx -t
exec nginx -g 'daemon off;'
