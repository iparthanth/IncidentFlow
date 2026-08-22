# Architecture

## The shape, and why

IncidentFlow is a **modular service-oriented architecture**: two backend
services with genuinely separate responsibilities, sharing one database of
record and one message bus. It is deliberately *not* microservices, and the
distinction matters.

Microservices means each service owns its own datastore and services
communicate only over the network. That buys independent scaling and
independent failure domains, and it costs distributed transactions, eventual
consistency, and a debugging experience where "why is this incident showing the
wrong status?" becomes an archaeology project across three logs.

An incident-management tool cannot afford eventual consistency in its core
data. When a responder marks a SEV-1 resolved, every other responder must see
that immediately and unambiguously — "the other service has not caught up yet"
is exactly the class of confusion the product exists to eliminate. So the
incident lifecycle lives in one service, behind one database, inside real
transactions.

What *is* split out is the thing that genuinely benefits from being separate:
holding thousands of idle connections open.

## Why the realtime tier is a separate service

PHP-FPM allocates a worker per in-flight request. A worker is a whole process
with its own memory. Ten thousand open Server-Sent Events streams would mean
ten thousand blocked PHP workers — a configuration that does not exist because
it cannot exist on any reasonable amount of RAM.

Node's event loop holds idle sockets almost for free. One process handles
thousands of connections with a few hundred bytes of state each. That is the
entire reason for the second service, and it is a real reason rather than
architectural fashion.

The split is drawn so that neither side duplicates the other:

- **Laravel** owns persistent business information and every decision.
- **Express** owns connections and delivery, and decides nothing.
- **Redis** carries events from one to the other.
- **React** receives normal data from Laravel and live updates from Express.

The realtime service has no database credentials. It cannot read an incident and
cannot evaluate a policy. It verifies a signed token and forwards whatever Redis
hands it. That constraint is what stops the split from silently rotting into two
services that both "sort of" own incidents.

## Data flow: one status change, end to end

```
1. Browser        POST /api/v1/incidents/42/status  {status: "resolved"}
                    │  Authorization: Bearer <15-min access token>
                    │  X-Organization: northwind
                    │  X-Request-Id: 7f3a…            ← minted by nginx
                    ▼
2. nginx          rate limit → FastCGI → php-fpm
                    ▼
3. Laravel        AuthenticateJwt      who is this?          (RS256 verify)
                  EnsureOrganization   which tenant, member? (DB lookup)
                  IncidentPolicy       may they transition?  (DB lookup)
                    ▼
4.                BEGIN
                    SELECT … FOR UPDATE          ← serialises concurrent responders
                    validate transition against the state machine
                    UPDATE incidents             ← status + resolved_at + TTR
                    INSERT incident_events       ← append-only timeline entry
                    INSERT audit_logs            ← who did what
                    INSERT notifications         ← delivery records, still 'pending'
                  COMMIT
                    ▼
5.                publish → Redis  incidentflow:org:1   ← only after commit
                  dispatch → queue SendIncidentNotification jobs
                    ▼
6. Redis          fan-out to every subscribed realtime node
                    ▼
7. Express        hub matches org + incident topics → writes SSE frames
                    ▼
8. Browser        EventSource receives `incident.resolved`
                  → React Query invalidates → refetches from Laravel
```

Two orderings in that sequence are load-bearing.

**Step 4 is one transaction.** The status change and the timeline entry become
true together or not at all. A resolved incident with no "resolved" event on its
timeline is not a cosmetic inconsistency — the timeline is the evidence a
postmortem is written from, and a gap in it invalidates every conclusion drawn.

**Step 5 happens after the commit.** Publishing inside the transaction would
announce a state change that a rollback then erases, and subscribers would show
users an incident status that PostgreSQL never recorded. Queue jobs have the
same hazard in reverse: a worker that starts before the commit reads a row that
does not exist yet.

The mechanism is deliberately boring — the service collects side effects into a
`PendingEffects` object during the transaction and flushes them after
`DB::transaction()` returns. No framework magic, no `afterCommit` hook whose
behaviour differs under test.

## Step 8: why invalidate rather than patch

It is tempting to splice the event payload straight into the client's cache and
skip the refetch. That is a mistake, and a common one.

The envelope carries *what changed*, not the full resource. A client that
reconstructs an incident from a sequence of partial events drifts from the
server's version in ways that are extremely hard to debug — and the drift shows
up as "the dashboard said mitigated but the API says resolved", which is
precisely the failure this product exists to prevent.

So an event is treated as a *hint that something changed*, and the client
refetches. It costs one small request and buys a single source of truth.

## Why Redis pub/sub, when PostgreSQL is the truth

Redis pub/sub is fire-and-forget. A message published while a node is
reconnecting is gone; there is no acknowledgement, no persistence, no replay.

That is acceptable *here* precisely because it carries no authority. Every event
delivered over the stream corresponds to a row already committed to PostgreSQL.
A dropped frame costs a refetch, never data.

The alternative — Redis Streams, or a real broker — would add durability the
system does not need and operational weight it would have to carry forever. The
honest trade is to accept the loss and make it *visible*: the hub keeps a small
replay buffer, answers `Last-Event-ID` with either the missed events or an
explicit `stream.gap`, and the client responds to a gap by refetching from the
database rather than pretending the stream was continuous.

Choosing a lossy transport is fine. Hiding the loss is not.

## Why per-organization channels with reference counting

Channels are named `incidentflow:org:{id}`, and a realtime node subscribes to
one only while it has a connected member of that organization — released when
the last one disconnects.

The obvious alternative, `PSUBSCRIBE incidentflow:org:*`, makes every node
receive every tenant's traffic and discard most of it. Adding a node would then
multiply broker traffic rather than divide client load, which defeats the point
of having more than one.

Reference counting costs one integer per organization and makes horizontal
scaling actually work.

## Authentication: three credentials, three threat models

| Credential | Lifetime | Where it lives | Revocable |
|---|---|---|---|
| Access token (JWT, `aud: api`) | 15 min | Browser memory only | Via cache denylist on logout |
| Refresh token (opaque) | 30 days | HttpOnly cookie | Yes — row in the database |
| Realtime ticket (JWT, `aud: realtime`) | 60 s | Query string | Expires faster than it matters |

**RS256, not HS256.** The realtime service must verify tokens the API mints.
With a shared secret it could also *forge* them — a compromise of the fan-out
tier would yield an administrator token for any tenant. With an asymmetric key
it holds only the public half and can verify but never sign.

**The access token carries no roles.** It answers "who is this?"; the database
answers "what may they do?". That costs one indexed lookup per request and buys
immediate revocation: removing someone's role takes effect on their very next
request, not whenever their token happens to expire.

**The realtime ticket does carry role and organization**, because the Express
service has no database to ask. The cost is bounded staleness — a role revoked
right now stays effective on an open stream for at most sixty seconds — and that
is a documented trade rather than an oversight.

**Why a separate ticket at all:** `EventSource` cannot set headers, so a browser
SSE client must put its credential in the query string, where it lands in nginx
access logs, browser history, and every proxy in between. Handing over the
15-minute API token for that would be careless. A 60-second token that grants
nothing but a read stream is worthless by the time a log rotation runs.

## Multi-tenancy without a global scope

Every tenant-scoped query names its organization explicitly. Laravel's global
scopes were considered and rejected.

A global scope makes queries safe *by accident*. It works inside an HTTP request
where a "current tenant" exists, and it fails open the moment code runs
elsewhere — a queue worker, an artisan command, the scheduler — where there is
no request and therefore no tenant. The failure mode is silent and total: a
report that quietly includes every customer's incidents.

Instead, `EnsureOrganizationContext` resolves and *verifies* the organization
once per request and binds it to the container, and every query names it. The
safety comes from a check that is visible at the call site rather than from an
invisible one that might not have run.

Policies enforce the same boundary at the object level: every object-level check
confirms the resource belongs to the request's organization before it considers
the role at all. That is the control for broken-object-level authorization —
a valid token for tenant A used against an id belonging to tenant B — which no
amount of role checking catches.

## Append-only by construction

`incident_events` and `audit_logs` are immutable at three levels:

1. **Schema** — no `updated_at`, no `deleted_at`. There is nowhere to record a
   modification, so "this was edited" is not a state the database can represent.
2. **Model** — `updating` and `deleting` throw. A stray `->save()` in future
   code fails a test rather than quietly rewriting history.
3. **API** — no route or policy exposes a mutating verb for either resource.

One layer would be enough to be *correct today*. Three are what keep it correct
after eighteen months of other people's changes.

Corrections are appended as new events, never applied in place. A timeline that
can be edited after the fact is unfalsifiable, and a postmortem built on it
proves nothing.

## Metrics without a rollup table

MTTA and MTTR come from two denormalised columns, `time_to_acknowledge_seconds`
and `time_to_resolve_seconds`, written inside the same transaction as the
transition that produces them.

Computing them from timestamps at query time would need date arithmetic that
differs between PostgreSQL and the SQLite the test suite runs on — which in
practice means metrics that are never covered by tests. Storing the duration
makes the aggregate a plain `AVG()` and keeps the number stable even if an
administrator later corrects a timestamp: the metric records what the response
actually took at the time.

Percentiles and daily buckets are computed in PHP over a capped row set, for the
same portability reason (`percentile_cont` and `date_trunc` do not exist in
SQLite). When a tenant outgrows the cap, the replacement is a nightly rollup
table keyed on `(organization_id, date, severity)` — and the output shape of
`MetricsService` is already exactly what such a table would store.

## Rejected alternatives

| Considered | Why not |
|---|---|
| WebSockets as the only transport | The data flow is one-way. SSE gets reconnection and `Last-Event-ID` replay for free and survives every HTTP proxy. WebSocket is provided too, for non-browser consumers that want duplex. |
| Laravel Echo / Pusher / Soketi | A hosted dependency for a problem that is ~400 lines of Node, plus another vendor in the failure path of the tool you open when things are already failing. |
| Global Eloquent scopes for tenancy | Fails open outside an HTTP request. See above. |
| Native database enums | Adding a severity becomes an `ALTER TYPE` with an exclusive lock on a hot table. Short strings plus PHP enums give the same type safety in application code and none of the migration pain. |
| Event sourcing for incidents | The timeline is already an append-only event log. Making it the *only* representation would mean rebuilding state to answer "what is the status?", which is the single most frequent query in the product. |
| Storing roles in the JWT | Revocation would lag by the token's lifetime. One indexed lookup is cheaper than that surprise. |
| Microservices | Distributed transactions across incident state and its timeline, for a product whose entire value is an unambiguous shared picture. |
