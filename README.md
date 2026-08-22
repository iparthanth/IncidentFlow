# IncidentFlow

A production-grade incident management platform: employees report production
incidents, engineers coordinate the response, and managers track service
reliability.

Built as a **modular service-oriented architecture** — not microservices. Two
backend services with clearly separated responsibilities, one database that owns
the truth, and a message bus between them.

```
                     ┌─────────────────────────┐
                     │   React 19 + TypeScript  │
                     │        (Vite SPA)        │
                     └────────────┬─────────────┘
                                  │ HTTPS
                     ┌────────────┴─────────────┐
                     │      nginx (one origin)  │
                     └──────┬────────────┬──────┘
                 REST /api  │            │  SSE + WebSocket /realtime
                            │            │
              ┌─────────────┴──┐      ┌──┴──────────────────┐
              │  Laravel 12    │      │  Node 22 + Express  │
              │  PHP 8.3 API   │      │  realtime fan-out   │
              └────────┬───────┘      └──────────┬──────────┘
                       │                         │
              ┌────────┴───────┐         ┌───────┴────────┐
              │  PostgreSQL 16 │         │    Redis 7     │
              │ source of truth│◄────────┤  pub/sub + queue│
              └────────────────┘ publish └───────┬────────┘
                                                 │
                                        ┌────────┴────────┐
                                        │ Laravel Horizon │
                                        │  queue workers  │
                                        └─────────────────┘
```

## The division of responsibility

The two services do **not** duplicate CRUD. Each owns one thing:

| Service | Owns | Never does |
|---|---|---|
| **Laravel API** | Persistent business state, authorization, the incident lifecycle, audit trail | Hold open connections |
| **Express realtime** | Live connections and event delivery | Touch the database, or decide anything |
| **Redis** | Carrying events from Laravel to Express; queue backing | Store anything that matters |
| **PostgreSQL** | Every fact the product asserts | — |
| **React** | Normal data from Laravel, live updates from Express | Own business rules |

The realtime service has no database credentials at all. It cannot read an
incident, and it cannot decide who may see one — it verifies a short-lived token
and forwards what Redis hands it. That constraint is what keeps the split honest.

## Running it

### The whole stack (recommended)

```bash
docker compose up --build
```

Then open <http://localhost:8080>. Mail is captured by Mailpit at
<http://localhost:8025>; the queue dashboard is at `/horizon`.

Migrations and demo data run automatically via the one-shot `migrate` service.

**Demo accounts** — password `incidentflow` for all of them:

| Email | Role | Can |
|---|---|---|
| `admin@incidentflow.test` | Administrator | Everything, including the audit log |
| `commander@incidentflow.test` | Incident Commander | Assign, change severity, publish postmortems |
| `responder@incidentflow.test` | Responder | Drive an incident through its lifecycle |
| `reporter@incidentflow.test` | Reporter | Open incidents, comment |
| `viewer@incidentflow.test` | Viewer | Read only |

### Without Docker

The API runs standalone on SQLite with no other services:

```bash
cd api
cp .env.local.example .env
composer install
php artisan key:generate
php artisan jwt:keys
php artisan migrate --seed
php artisan serve
```

```bash
cd web
npm install
npm run dev          # proxies /api to :8000
```

Live updates are disabled in this mode (`REALTIME_ENABLED=false`); the SPA falls
back to refetching. Everything else works.

#### Adding live updates without Docker

With a local Redis you get the real thing — a durable queue and live fan-out.
Redis has no official Windows build; the maintained port at
[tporadowski/redis](https://github.com/tporadowski/redis) is a portable ZIP that
needs no installer or admin rights.

```bash
redis-server /path/to/redis.conf          # or: brew services start redis
```

Then uncomment the Redis block at the bottom of `api/.env.local.example`, and run
the two extra processes:

```bash
cd api && php artisan queue:work redis    # Horizon needs pcntl/posix — Linux only
```

```bash
cd realtime
REDIS_URL=redis://127.0.0.1:6379 JWT_PUBLIC_KEY_PATH=../api/storage/keys/jwt-public.pem npm run dev
```

The header in the SPA switches from amber "Reconnecting…" to a green "Live" once
the stream is up.

## Verified

Run from a clean checkout:

```bash
cd api && composer test          # 104 tests, 518 assertions
cd realtime && npm test          # 26 tests
cd web && npm test               # 23 tests
cd web && npm run build
```

End-to-end tests drive a browser against the whole stack:

```bash
docker compose up -d --wait
cd web && npm run e2e            # 7 tests
```

They are written for the compose stack. Against `php artisan serve` they still
pass, but that server handles one request at a time, so concurrent queries on a
page queue behind each other — raise the assertion ceiling with
`E2E_EXPECT_TIMEOUT=45000`.

The API suite runs on in-memory SQLite locally and against real PostgreSQL 16 in
CI, because portable-looking schema-builder calls can still behave differently on
the engine that ships.

## What is actually implemented

**Core** — registration and login, incident creation with SEV-1…SEV-4, responder
assignment, a guarded status lifecycle, an append-only timeline, internal
comments, live updates, postmortems, MTTA/MTTR with percentiles, a permanent
audit log, queued email, CSV export, and an admin view.

**Production concerns** — role-based access control, request validation
(including query parameters), rate limiting, idempotency keys, pagination with a
stable sort, optimistic UI updates, one central error shape, structured JSON
logs, correlation IDs propagated across all three services, liveness and
readiness probes, soft deletes, database transactions around every state change,
queue retry with backoff, OpenAPI documentation, unit/feature/integration/E2E
tests, dev and prod Docker configurations, GitHub Actions CI with image
scanning, secrets kept out of the repository, and seeded demo accounts.

## Documentation

| Document | What it covers |
|---|---|
| [docs/running-incidentflow.pdf](docs/running-incidentflow.pdf) | Step-by-step setup and manual verification guide, written for a newcomer |
| [docs/architecture.md](docs/architecture.md) | Why the system is shaped this way, and what was rejected |
| [docs/api.md](docs/api.md) | Endpoint reference, conventions, error codes |
| [docs/openapi.yaml](docs/openapi.yaml) | Machine-readable API specification |
| [docs/runbook.md](docs/runbook.md) | Deploying, migrating, and what to do when it breaks |
| [docs/interview-notes.md](docs/interview-notes.md) | The design decisions worth defending, in depth |
| [docs/adr/](docs/adr/) | Architecture decision records |

## Repository layout

```
api/         Laravel 12 — REST API, domain logic, queue workers
realtime/    Node 22 + Express — SSE and WebSocket fan-out
web/         React 19 + TypeScript + Vite — the SPA
infra/nginx/ Reverse proxy: one origin for everything
docs/        Architecture, API reference, runbook, ADRs
```

## Licence

MIT.
