#!/usr/bin/env bash
# Nightly database backup for a single-host deployment.
#
# IncidentFlow's premise is a timeline nobody can rewrite. On a VPS that
# timeline lives on one disk you own: no managed snapshots, no point-in-time
# recovery. This script is the entire difference between "immutable history"
# and "immutable until the disk dies".
#
#   crontab -e
#   15 3 * * * /opt/incidentflow/scripts/backup-db.sh >> /var/log/incidentflow-backup.log 2>&1
set -Eeuo pipefail

STACK_DIR="${STACK_DIR:-/opt/incidentflow}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/incidentflow}"
KEEP_DAYS="${KEEP_DAYS:-14}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TARGET="${BACKUP_DIR}/incidentflow-${STAMP}.sql.gz"

log() { printf '{"ts":"%s","level":"%s","msg":"%s"}\n' "$(date -u +%FT%TZ)" "$1" "$2"; }

cd "$STACK_DIR"
mkdir -p "$BACKUP_DIR"

# shellcheck disable=SC1091
set -a; . "${STACK_DIR}/.env"; set +a

# --no-owner keeps the dump restorable into a database owned by a different
# role, which is what you will actually have on a rebuilt box.
if ! docker compose exec -T postgres \
      pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" --no-owner --clean --if-exists \
      | gzip -9 > "$TARGET"; then
  log error "pg_dump failed; removing partial file"
  rm -f "$TARGET"
  exit 1
fi

# A backup nobody checks is a guess. gzip -t catches the common real failure:
# the dump was truncated because the disk filled mid-write, which otherwise
# produces a plausible-looking file that only fails on the day you need it.
if ! gzip -t "$TARGET"; then
  log error "archive failed integrity check; removing"
  rm -f "$TARGET"
  exit 1
fi

SIZE=$(stat -c%s "$TARGET")
if [ "$SIZE" -lt 1024 ]; then
  log error "archive suspiciously small (${SIZE} bytes); removing"
  rm -f "$TARGET"
  exit 1
fi

log info "backup ok ${TARGET} (${SIZE} bytes)"

# Off-box copy. A backup on the same disk as the database survives exactly the
# failures that do not matter. Configure rclone (any S3/Drive/B2 remote) and set
# BACKUP_REMOTE, or replace this block with scp to another host.
if [ -n "${BACKUP_REMOTE:-}" ] && command -v rclone >/dev/null 2>&1; then
  if rclone copy "$TARGET" "$BACKUP_REMOTE" --quiet; then
    log info "copied off-box to ${BACKUP_REMOTE}"
  else
    log error "off-box copy FAILED — local copy retained"
  fi
else
  log warn "BACKUP_REMOTE unset or rclone missing; backup exists only on this host"
fi

find "$BACKUP_DIR" -name 'incidentflow-*.sql.gz' -mtime "+${KEEP_DAYS}" -delete
log info "pruned archives older than ${KEEP_DAYS} days"
