# Design decisions worth defending

The six questions the brief calls out, answered with the code that implements
them. Each one is a real trade-off rather than a slogan, so each answer names
what was given up.

---

## 1. Why event timelines are append-only

**Code:** `api/app/Models/IncidentEvent.php`,
`api/database/migrations/2026_01_01_000400_create_incident_activity_tables.php`

An incident timeline is not a feature. It is the evidence a postmortem is
written from, the record a compliance auditor reads, and — during a live
incident — the shared picture six people are coordinating against. If it can be
edited after the fact, every conclusion drawn from it becomes unfalsifiable.
"The database says we acknowledged at 14:03" means nothing if someone could have
changed it to say that afterwards.

Immutability is enforced at three levels, deliberately:

1. **The schema has nowhere to write a change.** No `updated_at`, no
   `deleted_at`. "This row was modified" is not a state the table can represent.
2. **The model refuses.** `updating` and `deleting` throw a `LogicException`, so
   a stray `->save()` introduced by a future refactor fails a test instead of
   silently rewriting history.
3. **The API never exposes a mutating verb.** `IncidentEventController` has only
   `index`.

One layer is enough to be correct today. Three are what keep it correct after
eighteen months of other people's changes — and layer 2 is the one that
actually catches regressions, because it turns a silent corruption into a
red test.

Corrections are new events, never edits. If someone recorded the wrong cause,
the fix is another entry saying so, and the mistake stays visible — which is
itself information a postmortem wants.

**What this costs.** The table only grows; it is never pruned, unlike
notifications and idempotency keys. That is the deal: a permanent record is
worth the disk. Cursor pagination is used everywhere it is read, because
`LIMIT/OFFSET` over a table that grows while you page through it will repeat
and skip rows.

**Test:** `IncidentLifecycleTest::test_the_timeline_is_append_only`

---

## 2. Why Redis pub/sub for live updates, with PostgreSQL as the source of truth

**Code:** `api/app/Services/Realtime/RealtimePublisher.php`,
`realtime/src/redis.ts`, `realtime/src/hub.ts`

Redis pub/sub is fire-and-forget. No acknowledgement, no persistence, no replay.
A message published while a subscriber is reconnecting is simply gone.

That is a perfectly good property *for this job*, because the messages carry no
authority. Every event on the stream corresponds to a row already committed to
PostgreSQL. The stream is an optimisation — "something changed, look again" —
not a system of record. A lost frame costs a refetch and never costs data.

What it buys is latency and simplicity: sub-millisecond fan-out, no broker to
operate, no consumer groups, no offsets, no compaction policy.

The important part is that the loss is **made visible rather than hidden**:

- The hub keeps a small per-organization ring buffer of recent events.
- On reconnect the client sends `Last-Event-ID`.
- If the cursor is still in the buffer, the missed events are replayed.
- If it has aged out, the server sends `stream.gap` and the client refetches
  from the API.

Silently sending a partial history would be worse than admitting the gap. A
timeline with a hole in it is a correctness bug, not a cosmetic one.

The same discipline applies to failure: if Redis is down entirely,
`RealtimePublisher` logs and returns `false`. The incident is still recorded,
the API still returns 200, and the UI degrades to polling. Turning a degraded
broker into 500s would be exactly the wrong trade for a tool that people open
*because* something is already broken.

**What this costs.** Live updates are best-effort. If your product needed
guaranteed delivery — an audit stream a regulator consumes, say — this would be
the wrong choice and Redis Streams or a real broker would be right.

---

## 3. How idempotency prevents duplicate incidents

**Code:** `api/app/Http/Middleware/HandleIdempotency.php`,
`api/app/Models/IdempotencyKey.php`

The scenario is mundane and constant: a responder taps "Report incident" on a
phone with one bar of signal. The request reaches the server and succeeds. The
response is lost. The client retries. Now there are two SEV-1 records for one
outage — two pages, two commanders, and two people fighting the same fire from
different rows.

The client generates a UUID per attempt and sends it as `Idempotency-Key`. The
endpoint requires it: `POST /incidents` is registered with
`idempotency:required`.

The mechanism, in order, and why each step is where it is:

1. **INSERT the key as `in_progress` *before* running the handler.** This is the
   concurrency control, and the ordering is the whole trick. Two simultaneous
   retries race on a unique index `(user_id, endpoint, key)`; the database picks
   a winner and the loser gets a 409. A "check whether it exists, then create"
   approach leaves a window between the check and the write for the two requests
   to interleave — which is exactly the case that matters, because retries
   arrive close together.

2. **Compare a hash of the request body.** Reusing a key with *different*
   content is a client bug. Returning the first request's incident for it would
   hide that bug behind a plausible-looking success, so it is a 422. The hash is
   computed over recursively key-sorted JSON, so a client that serialises its
   object in a different order on retry still matches.

3. **Store the response.** A replay returns the original body byte-for-byte with
   `Idempotent-Replayed: true`, without re-running the work — so no second
   timeline event, no second page.

4. **Delete the key if the handler failed.** Otherwise a client that simply
   corrected its input and retried with the same key would be permanently stuck.

**What this costs.** A row per unsafe request, pruned after 24 hours by
`incidentflow:prune`. And clients must generate keys — which is why the web app
does it centrally in `api-client.ts` rather than at each call site, deriving the
key from the request id so that the *same logical attempt* keeps its key across
an internal token-refresh retry.

**Tests:** `IdempotencyTest` — six cases including payload mismatch, key
ordering, failure release, and per-user scoping.

---

## 4. How authorization differs from authentication

**Code:** `api/app/Auth/JwtGuard.php`,
`api/app/Http/Middleware/EnsureOrganizationContext.php`,
`api/app/Policies/`, `api/app/Enums/OrganizationRole.php`

Two different questions, answered by two different layers, on purpose.

**Authentication — "who is this?"** `JwtGuard` verifies the RS256 signature,
checks issuer and audience, consults the logout denylist, and loads the user.
It answers identity and nothing else. The access token contains no roles at all.

**Authorization — "what may they do?"** Policies, evaluated per action against
the database, on every request.

Keeping roles *out* of the token is the decision worth defending. Embedding them
is the common shortcut, and it makes revocation lag by the token's lifetime:
demote someone and they keep administrator powers for the next fifteen minutes.
Reading the role from the database costs one indexed lookup and makes revocation
immediate. During an incident — where "remove that contractor's access now" is a
real request — fifteen minutes is not an acceptable answer.

Three layers stack:

| Layer | Question | Failure |
|---|---|---|
| `AuthenticateJwt` | Is this a valid identity? | 401, with `token_expired` vs `token_revoked` distinguished so the client knows whether to refresh or sign out |
| `EnsureOrganizationContext` | Which tenant, and do they belong to it? | 404 — confirming an organization exists to a non-member is a leak |
| Policies | May this person do this to this object? | 403 |

Two details inside the policies matter more than the role checks:

**Tenant match comes first.** Every object-level check confirms the resource
belongs to the request's organization *before* considering the role. Without
that, a valid administrator token for tenant A operates on tenant B's incident
by guessing an id — broken object-level authorization, the vulnerability class
that no amount of role checking catches. Every object route is tested across a
tenant boundary, not only across a role boundary.

**Policies check permissions, never roles.** `hasPermission(IncidentTransition)`,
not `role === 'responder'`. Adding a role is then one line in the role →
permission map instead of an audit of every conditional in the codebase. A unit
test asserts the ladder is monotonic — every role holds a superset of the one
below — which catches the subtle regression where a refactor accidentally
removes a permission from a senior role.

**Tests:** `IncidentAuthorizationTest`, `OrganizationRoleTest`

---

## 5. Why transactions must wrap state changes *and* timeline events

**Code:** `api/app/Services/Incidents/IncidentService.php`

Consider resolving an incident without a transaction, and the process dying
between the two writes:

- **Status updated, event not written.** The incident shows resolved with no
  "resolved" entry on its timeline. The postmortem is built from a record with a
  hole in it, and MTTR is computed from a resolution nobody can point to.
- **Event written, status not updated.** The timeline says resolved, the
  dashboard says acknowledged, and the on-call engineer is paged for something
  that is already fixed.

Both are corruption, and neither is loud. So every mutation in `IncidentService`
follows the same shape:

```php
$result = DB::transaction(function () use (...) {
    $fresh = $this->lock($incident);          // SELECT … FOR UPDATE
    // validate against the state machine
    $fresh->save();                            // status + clocks
    $this->timeline->record(...);              // exactly one event
    $this->audit->recordModelUpdate(...);      // who did it
    $effects->addNotifications($this->notifications->prepare(...));
    return $fresh;
});

// Only now, past the commit:
$this->publisher->publish(...);
$this->notifications->dispatch($effects->notifications);
```

Three things are doing work here.

**The row lock.** Two responders clicking "Resolve" simultaneously would both
read `acknowledged`, both pass the transition check, and both write a resolved
event — one incident, two resolutions, and an MTTR taken from whichever write
landed last. `lockForUpdate()` serialises them; the second one gets a clean 422
saying the incident is already resolved.

**Side effects wait for the commit.** Publishing inside the transaction would
announce a state change a rollback then erases — subscribers would see a
resolved incident PostgreSQL never recorded. A queue job dispatched inside it
can start before the commit and read a row that does not exist yet. So the
transaction only *records intent* in a `PendingEffects` object, and the caller
flushes once the commit is real. Plain code, no framework hook whose behaviour
differs under test.

**Timestamps are written in exactly one place.** `applyTransitionTimestamps()`
is the only code that can decide an incident became acknowledged or resolved,
which is what makes MTTA and MTTR trustworthy. Two rules live there and both are
about honesty:

- `acknowledged_at` is never overwritten. Time-to-acknowledge measures the first
  human response; an incident acknowledged twice was not acknowledged faster the
  second time.
- `resolved_at` *is* cleared on reopen. An incident that came back was not
  resolved, and leaving the old duration would quietly flatter MTTR.

**Tests:** `IncidentLifecycleTest` — acknowledgement recorded once, resolution
backfilling a missing acknowledgement, reopen clearing the resolution.

---

## 6. How failed notifications retry without repeating the incident update

**Code:** `api/app/Services/Notifications/NotificationDispatcher.php`,
`api/app/Jobs/SendIncidentNotification.php`

The failure to avoid: an email provider times out, the job retries, and the
retry re-runs the incident update — so the incident gets resolved twice, the
timeline gains a duplicate entry, and everyone gets paged again.

The separation that prevents it is a split into two phases:

```php
// Inside the incident's transaction — writes rows, sends nothing.
$notifications = $dispatcher->prepare($incident, $type, $actor, $payload);

// After commit — enqueues jobs, writes nothing about the incident.
$dispatcher->dispatch($notifications);
```

A notification row is created **before** the job is dispatched, in state
`pending`. The job's entire responsibility is moving *that row* from `pending`
to `sent`. It takes a notification id, not an incident id, and it has no code
path that can touch the incident at all. So a retry re-attempts delivery and
nothing else — the difference between "the email went out twice" and "the
incident was resolved twice".

Everything else follows from that:

- **Exponential backoff** (`[10, 30, 120, 300]` seconds, 5 attempts). A provider
  that just rate-limited you will not be happier one second later, and hammering
  it slows recovery for every other job in the queue.
- **The job takes an id, not a serialised model.** A serialised model is a
  snapshot from dispatch time; by the time a retry runs the notification may
  have been read or the incident resolved. Re-reading means every attempt works
  from current truth.
- **Terminal states are terminal.** A notification already `sent` or `read`
  returns immediately, so a duplicate dispatch cannot send twice.
- **Failure is recorded on the row**, not only in `failed_jobs`. An operator can
  see "this page never reached anyone" in the product, which is the only place
  they will be looking during an incident.
- **A sweeper closes the last gap.** If the process dies *between* the commit
  and the dispatch, the rows exist and no job was ever created. Nothing is lost,
  but nothing is delivered either — and a SEV-1 page that silently never arrives
  is the worst failure mode this system has. `notifications:retry-stale` runs
  every five minutes and re-queues anything still `pending`. It is safe to run
  concurrently because the job itself is idempotent.

**What this costs.** A notifications table that grows, pruned after 90 days by
`incidentflow:prune`. And at-least-once delivery: a crash between "email sent"
and "row marked sent" sends a duplicate. For a paging system that is the correct
side to err on — a duplicate page is an annoyance, a missed page is an outage.

---

## Other decisions that tend to come up

**Why `INC-0001` per tenant rather than a global sequence?** A shared sequence
leaks how many incidents every other customer has had. The counter lives on the
organization row and is allocated under a row lock inside the creation
transaction — race-free and O(1), rather than a `COUNT(*)` that degrades as the
table grows.

**Why is CSV export the only place with formula-injection escaping?** Because it
is the only place that produces a file a spreadsheet will execute. A title of
`=cmd|'/c calc'!A1` is inert in PostgreSQL and inert in JSON; Excel runs it. The
vulnerability belongs to the spreadsheet, but the endpoint is where it is
introduced, so that is where it is stopped.

**Why does readiness check the database but liveness does not?** Liveness asks
"is this process wedged?" If it checked PostgreSQL, a database outage would make
the orchestrator kill and restart every API container — turning a recoverable
dependency failure into a guaranteed crash loop. Readiness asks "should traffic
come here?" and correctly says no. Redis is reported in readiness but does not
fail it, because the API degrades gracefully without live updates.

**Why is `preventLazyLoading` off in production?** An N+1 is a performance bug.
Failing a request over one during an incident is a worse outcome than serving it
slowly. It stays on in development and CI, where it is a test failure — which is
where you want to find it.
