# Runbook

Operating IncidentFlow. Written for whoever is on call, which may be you in six
months with no memory of writing it.

## Deploying

```bash
export APP_VERSION=$(git rev-parse --short HEAD)
docker compose -f docker-compose.yml -f docker-compose.prod.yml build
docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm migrate
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

**Migrations run as a separate one-shot job, never in the container entrypoint.**
With three API replicas, an entrypoint migration is three processes racing
through the same schema change. The best case is two of them wasting time; the
worst is a half-applied migration and a schema nobody can reason about.

The entrypoint therefore only waits for the database, generates keys if the
volume is empty, and caches config. It never alters the schema.

### Zero-downtime constraints

Every migration must be safe against the *previous* version of the code, because
both versions run at once during a rolling deploy. In practice:

- Add columns nullable, or with a default. Backfill in a later release.
- Never rename or drop a column in the same release that stops using it. Split
  it: stop writing → deploy → drop → deploy.
- Add indexes concurrently on large tables (`CREATE INDEX CONCURRENTLY`); a
  plain `CREATE INDEX` takes a write lock for its duration.

CI verifies that every migration rolls back and re-applies cleanly. A migration
nobody can reverse is a deployment with no way back.

### Rolling back

```bash
APP_VERSION=<previous-sha> docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

Roll the *code* back first and think about the schema separately. Reversing a
migration that has already accepted writes usually loses data; a forward fix is
almost always the better answer.

---

## Health and probes

| Endpoint | Answers | Fails when |
|---|---|---|
| `GET /api/v1/health/live` | Is the process wedged? | Effectively never — it touches nothing |
| `GET /api/v1/health/ready` | Should traffic come here? | Database or cache unreachable |
| `GET /healthz` (realtime) | Is the node running? | Effectively never |
| `GET /readyz` (realtime) | Should streams land here? | Redis unreachable, or draining |
| `GET /metrics` (realtime) | Prometheus scrape | — |

Liveness deliberately checks nothing. If it checked PostgreSQL, a database
outage would make the orchestrator kill and restart every container — converting
a recoverable dependency failure into a guaranteed crash loop.

---

## When something is wrong

### Everything you need starts with the request id

Every response carries `X-Request-Id`. The same value appears in nginx's access
log, the API's structured logs, the queue worker's logs, inside the Redis event
envelope, and on the timeline entry that resulted. One grep reconstructs a whole
user action across four processes:

```bash
docker compose logs --no-color | grep '<request-id>'
```

If a user reports a problem, the first question is "what does the page say the
request id was?" — the error UI shows it precisely so they can tell you.

### Live updates have stopped

Symptom: the stream indicator in the header shows "Not live", or incidents stop
updating without a refresh.

1. **Is Redis reachable from both sides?**
   ```bash
   docker compose exec redis redis-cli ping
   docker compose exec realtime wget -qO- http://127.0.0.1:3001/readyz
   ```
2. **Do the channel prefixes match?** This is the most common cause. The API's
   `REALTIME_CHANNEL_PREFIX` and the realtime service's `REDIS_CHANNEL_PREFIX`
   must be identical, or the publisher and the subscriber sit on different
   channels and nothing is ever delivered — with no error anywhere.
   ```bash
   docker compose exec redis redis-cli PSUBSCRIBE 'incidentflow:*'
   # then change an incident and watch for traffic
   ```
3. **Is nginx buffering?** `proxy_buffering off` on `/realtime/` is what keeps
   SSE alive. If it were on, events would arrive in batches or not at all.
4. **Is the ticket being rejected?** Check `incidentflow_realtime_auth_failures_total`
   on `/metrics`. A spike after a deploy usually means the key pair changed and
   the two services now disagree.

**This is not an outage.** PostgreSQL is the source of truth; the product works
without the stream, just without live updates. Do not page for it out of hours.

### Notifications are not arriving

1. Check the rows, not the queue: `notifications` with `status = 'pending'` more
   than five minutes old means the job never made it onto the queue.
2. `php artisan notifications:retry-stale --dry-run` reports what the sweeper
   would re-queue. It runs automatically every five minutes.
3. `status = 'failed'` with `last_error` populated means delivery was attempted
   and rejected — read the error before assuming it is the queue.
4. Horizon's dashboard at `/horizon` shows throughput and failed jobs.

A retry never re-runs the incident update; the job only moves a notification row
from `pending` to `sent`.

### Everything is 401

Almost always the JWT key pair.

```bash
docker compose exec api php artisan tinker
>>> app(App\Services\Auth\KeyProvider::class)->publicKey();
```

If the API and the realtime service were given different keys, REST works and
streams fail. If the key pair changed entirely, every existing session is
invalid — which is the correct behaviour, but tell people rather than letting
them discover it.

### The database is slow

Check the obvious index first. The incident list's default query is
`organization_id + status + created_at`, which is covered by a composite index;
a query plan showing a sequential scan on `incidents` means either the index is
missing or the query lost its `organization_id` filter — and the second of those
is a tenancy bug, not a performance bug. Investigate accordingly.

---

## Routine operations

### Rotating the JWT key pair

Signs every session out immediately. Do it deliberately.

```bash
docker compose exec api php artisan jwt:keys --force
docker compose restart api realtime horizon scheduler
```

The realtime service must be restarted too — it caches the public key at boot.

### Pruning

`incidentflow:prune` runs nightly and removes expired idempotency keys, long-dead
refresh tokens, and notifications read more than 90 days ago.

It deliberately does **not** touch `audit_logs` or `incident_events`. Both are
permanent by design: the value of an audit trail is answering questions asked
long after anyone thought to ask them. Set `AUDIT_LOG_RETENTION_DAYS` only if a
data-protection policy requires it.

```bash
docker compose exec api php artisan incidentflow:prune --dry-run
```

### Backups

PostgreSQL holds everything that matters. Redis holds cache, queue and pub/sub —
losing it costs in-flight jobs and nothing else, which is why it runs with
`--appendonly no`.

```bash
docker compose exec postgres pg_dump -U incidentflow incidentflow | gzip > backup.sql.gz
```

Restore-test this. An untested backup is a belief, not a backup.

### Scaling

- **API** — stateless; add replicas freely.
- **Realtime** — stateless; each node subscribes only to the organizations it
  has live connections for, so adding nodes divides client load without
  multiplying broker traffic.
- **Horizon** — add replicas for throughput. Jobs are idempotent.
- **Scheduler** — exactly one. The tasks take a shared lock, but running one
  instance means the lock is a safety net rather than the mechanism.

---

## Security notes

- The refresh cookie is HttpOnly, Secure and SameSite=Strict, scoped to
  `/api/v1/auth`. Never widen that path; it is what keeps the cookie off the
  hundreds of API calls with no use for it.
- The realtime service receives the **public** key only. If you ever find
  yourself giving it the private key, stop — it would then be able to mint
  administrator tokens for any tenant.
- Nothing but nginx publishes a port in production. PostgreSQL, Redis and both
  services are reachable only on the compose network.
- `DatabaseSeeder` refuses to run when `APP_ENV=production`. Do not remove that
  guard; the demo accounts have a published password.
