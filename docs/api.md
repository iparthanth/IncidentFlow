# API reference

Base URL: `/api/v1`. The machine-readable specification is
[`openapi.yaml`](./openapi.yaml).

## Conventions

### Every request

| Header | Required | Purpose |
|---|---|---|
| `Authorization: Bearer <token>` | Yes, except auth and health routes | 15-minute access token |
| `X-Organization: <slug or id>` | When the user belongs to more than one | Selects the tenant |
| `X-Request-Id: <string>` | No | Correlation id; minted by nginx if absent, echoed on the response |
| `Idempotency-Key: <uuid>` | Required on `POST /incidents` | See [idempotency](#idempotency) |

`X-Organization` may be omitted when the caller belongs to exactly one
organization. With two or more it is required — guessing would silently write
into the wrong tenant.

### Every response

Successful responses wrap the payload in `data`. Paginated responses add `meta`
and `links`.

```json
{ "data": { "id": 42, "reference": "INC-0042" } }
```

Errors use one shape, everywhere:

```json
{
  "error": {
    "code": "incident.illegal_transition",
    "message": "An incident cannot move from Open to Closed.",
    "details": { "from": "open", "to": "closed", "allowed": ["acknowledged", "resolved"] },
    "request_id": "7f3a91c2-…"
  }
}
```

Switch on `code`, never on `message` — the message is prose and will be reworded.
Quote `request_id` when reporting a problem; it is the join key across nginx,
the API, the queue workers and the realtime service.

### Error codes

| Code | Status | Meaning |
|---|---|---|
| `unauthenticated` | 401 | No or unusable credential |
| `token_expired` | 401 | Refresh and retry — the session is still valid |
| `token_revoked` | 401 | Signed out or revoked. Do not retry; sign in again |
| `forbidden` | 403 | Authenticated, but not permitted |
| `not_found` | 404 | Does not exist, or belongs to another tenant |
| `organization_required` | 400 | Member of several tenants; send `X-Organization` |
| `organization_not_found` | 404 | Unknown tenant, or not a member |
| `validation_failed` | 422 | `details.fields` maps field → messages |
| `incident.illegal_transition` | 422 | `details.allowed` lists the legal targets |
| `incident.terminal_status` | 422 | Closed incidents accept no further changes |
| `incident.status_unchanged` | 422 | Already in that status |
| `postmortem.incomplete` | 422 | `details.missing_sections` lists what blocks publication |
| `idempotency.key_required` | 400 | This endpoint requires `Idempotency-Key` |
| `idempotency.in_progress` | 409 | An earlier attempt with this key is still running |
| `idempotency.payload_mismatch` | 422 | Key reused with a different body |
| `assignee_not_eligible` | 422 | That member's role cannot be paged |
| `rate_limited` | 429 | Honour `Retry-After` |

### Rate limits

| Bucket | Limit | Keyed on |
|---|---|---|
| `api` | 180/min authenticated, 40/min anonymous | User, else IP |
| `auth` | 10/min per IP **and** 5/min per email+IP | Both, deliberately |
| `writes` | 60/min | User |
| `exports` | 10/hour | User |
| `realtime` | 60/min | User |

Login is limited on IP *and* email together. IP alone lets an attacker spray one
password across thousands of accounts from a single address; email alone lets
anyone lock a known user out of their own account.

---

## Authentication

### `POST /auth/register`

Creates the account and its first organization in one transaction, and makes the
creator its administrator.

```json
{
  "name": "Ada Lovelace",
  "email": "ada@example.com",
  "password": "correct horse battery staple",
  "password_confirmation": "correct horse battery staple",
  "organization_name": "Analytical Engines"
}
```

Passwords must be at least 12 characters. In deployed environments they are also
checked against the Have I Been Pwned corpus.

**201** → `data.access_token`, `data.user`, `data.organization`, plus a
`Set-Cookie` carrying the refresh token (HttpOnly, Secure, SameSite=Strict,
scoped to `/api/v1/auth`).

### `POST /auth/login`

**200** → same shape. Unknown email and wrong password return an identical 401,
so the endpoint cannot be used to enumerate accounts.

### `POST /auth/refresh`

Reads the refresh cookie, rotates it, and returns a new access token. The old
refresh token is revoked.

Presenting an already-rotated token means either a replay or a theft; the whole
token family is revoked and the response is 401 `token_reused`. Clients must
therefore serialise concurrent refreshes — the web app collapses them into one
in-flight promise.

### `POST /auth/logout` · `POST /auth/logout-all`

Revokes the refresh token (or every one for the user) and adds the current
access token's `jti` to a cache denylist for its remaining lifetime, so "sign
out" is immediate rather than a fifteen-minute suggestion.

### `GET /auth/me`

Returns the user, their memberships, and the resolved permission list per
organization — which is what the SPA uses to decide which controls to render.

---

## Incidents

### `GET /incidents`

| Parameter | Notes |
|---|---|
| `status[]`, `severity[]` | Repeatable |
| `service_id`, `assignee_id`, `commander_id` | |
| `q` | Title, reference and description; case-insensitive; `%` and `_` are literal |
| `from`, `to` | Creation date range |
| `active_only` | Open, acknowledged or mitigated |
| `sort` | `created_at`, `updated_at`, `severity`, `status`, `resolved_at`, `acknowledged_at`, `reference`, `title` |
| `direction` | `asc` / `desc` |
| `page`, `per_page` | Max 100 |

Anything outside the `sort` whitelist is a 422 rather than being ignored — an
unvalidated sort parameter is a column name injected into `ORDER BY`. Results
are ordered by the requested column and then by `id`, so pagination stays stable
when rows share a timestamp.

Each incident carries `status.allowed_transitions`, computed from the state
machine, so a client can render exactly the buttons that will work.

### `POST /incidents`

Requires `Idempotency-Key`. Requires the `incident.create` permission.

```json
{
  "title": "Checkout API returning 500s for all customers",
  "description": "Error rate hit 100% at 14:02 UTC.",
  "severity": "sev1",
  "service_id": 3,
  "assignee_ids": [7, 9]
}
```

`service_id`, `commander_id` and `assignee_ids` are validated *scoped to the
caller's organization* — an id from another tenant is a 422, not a silent
cross-link.

### `POST /incidents/{id}/status`

```json
{ "status": "resolved", "note": "Rolled back release 4.2.1.", "public": true }
```

Validated against the state machine:

```
open ──► acknowledged ──► mitigated ──► resolved ──► closed
  └────────────────────────────────────┘     ▲
         (small incidents resolve directly)   │
                              reopen ─────────┘
```

`closed` is terminal. A `note` is also recorded as an incident update, and
`public: true` marks it safe for a customer status page.

### Other incident routes

| Method | Path | Permission |
|---|---|---|
| `GET` | `/incidents/{id}` | `incident.view` |
| `PATCH` | `/incidents/{id}` | `incident.update` — descriptive fields only |
| `DELETE` | `/incidents/{id}` | `incident.delete` — soft delete; timeline and audit survive |
| `POST` | `/incidents/{id}/severity` | `incident.command` — a downgrade requires `reason` |
| `PUT` | `/incidents/{id}/commander` | `incident.command` |
| `GET`/`POST`/`DELETE` | `/incidents/{id}/assignees[/{user}]` | `incident.assign` |
| `GET` | `/incidents/{id}/events` | Cursor-paginated timeline |
| `GET`/`POST` | `/incidents/{id}/updates` | `update.create` |
| `GET`/`POST` | `/incidents/{id}/comments` | `comment.create` |
| `DELETE` | `/comments/{id}` | Own comment, or `comment.moderate` |

Severity and status are absent from `PATCH` on purpose: they are transitions
with notification and metric consequences, and letting them ride along in a
generic update would route them around the state machine.

---

## Postmortems

`GET /postmortems` · `GET|PUT /incidents/{id}/postmortem` ·
`POST /incidents/{id}/postmortem/publish`

Publication is gated on **content as well as role**: `summary`, `root_cause`,
`impact` and `resolution` must all be present, and `missing_sections` names
whatever is not. A postmortem with no root cause records that an incident
happened and teaches nobody anything.

Published postmortems become read-only regardless of role — other teams cite
them, so corrections are amendments rather than silent rewrites.

---

## Metrics

`GET /metrics/summary?days=30` · `GET /metrics/trends?days=30`

Returns totals, per-status and per-severity counts (zero-filled), MTTA and MTTR
with `average`, `p50`, `p90`, `p95` and `max`, and acknowledgement-SLA
attainment per severity.

Percentiles are returned alongside the average because the average alone is
misleading: a twelve-minute mean with a two-hour p95 describes a very different
on-call experience from a twelve-minute mean with a fifteen-minute p95.

Windows are capped at 366 days.

---

## Export

`GET /incidents/export` — accepts the same filters as the list, streams CSV,
requires `export.run`, limited to 10/hour, and writes an `incident.exported`
audit entry.

Cells beginning `=`, `+`, `-`, `@`, tab or carriage return are prefixed with an
apostrophe. That input is inert in PostgreSQL and inert in JSON; Excel executes
it. The vulnerability is the spreadsheet's, but this endpoint introduces it, so
this is where it is stopped.

---

## Realtime

### `POST /realtime/ticket`

Returns a 60-second token with `aud: incidentflow-realtime`, the stream URL, and
the topics it is entitled to.

```json
{
  "data": {
    "ticket": "eyJhbGciOiJSUzI1NiIs…",
    "expires_in": 60,
    "stream_url": "/realtime/stream",
    "topics": ["org:1"]
  }
}
```

An API access token is **not** accepted here — the audience check is what stops
a REST credential being replayed as a stream credential in a query string, where
it would land in access logs.

### `GET /realtime/stream?ticket=…&topics=org:1,incident:42`

Server-Sent Events. Named events match `IncidentEventType`
(`incident.created`, `incident.status_changed`, …) plus control frames:

| Event | Meaning |
|---|---|
| `stream.open` | Connected |
| `stream.replayed` | Missed events were replayed after `Last-Event-ID` |
| `stream.gap` | Cursor aged out — refetch from the API |
| `stream.reconnect` | This node is draining; reconnect elsewhere |

A `: heartbeat` comment every 15 seconds keeps intermediaries from reaping the
connection.

`GET /ws` offers the same stream over WebSocket for non-browser clients, which
can send `{"action":"subscribe","topics":[…]}`.

---

## Health

`GET /health/live` — never touches a dependency, so a database outage cannot
make the orchestrator restart every container.

`GET /health/ready` — checks the database and cache (failing either returns
503) and reports Redis without failing on it, since the API degrades to polling
rather than breaking.
