# ADR 0001 — A separate Node service for realtime fan-out

**Status:** Accepted
**Date:** 2026-01-05

## Context

The product requires live incident updates: when one responder changes a
status, everyone else watching sees it without refreshing.

The API is Laravel on PHP-FPM. PHP-FPM allocates one worker process per
in-flight request, and a worker is a whole process with its own memory
footprint. A long-lived Server-Sent Events connection occupies a worker for its
entire lifetime.

Ten thousand concurrent viewers would therefore require ten thousand PHP
workers. That configuration does not exist because it cannot exist on any
sensible amount of RAM.

## Decision

Fan-out moves to a separate Node service. Laravel publishes events to Redis
after committing; the Node service subscribes and pushes to browsers over SSE
(with WebSocket available for non-browser clients).

The boundary is drawn so that neither service duplicates the other:

- Laravel owns persistent state and every decision.
- The realtime service owns connections and delivery, and decides nothing. It
  has no database credentials.

## Consequences

**Good.** Node's event loop holds idle sockets for a few hundred bytes each, so
one process handles thousands of connections. The two tiers scale
independently: streams are long and idle, API requests are short and bursty, and
they no longer contend for the same worker pool. A crash in the fan-out tier
degrades the product to polling rather than taking it down.

**Bad.** Two runtimes to build, test, deploy and keep patched. Two language
ecosystems for a reviewer to hold in their head. A wire contract between them
that must be versioned — hence `version` in the event envelope, so either side
can be deployed first.

**Accepted risk.** Redis pub/sub is fire-and-forget, so events can be lost. This
is tolerable only because PostgreSQL remains the source of truth: a dropped
frame costs a refetch, never data. The loss is made visible via `Last-Event-ID`
replay and an explicit `stream.gap` signal rather than hidden.

## Alternatives considered

**Polling.** Simplest, and genuinely adequate at low scale. Rejected because a
five-second poll from every open dashboard during a major incident — exactly
when the most people are watching — is a self-inflicted load spike at the worst
possible moment.

**Laravel Echo with Pusher or Soketi.** Rejected: a hosted dependency, or
another service to operate anyway, for a problem that is around 400 lines of
Node. It also puts a third-party vendor in the failure path of the tool you open
when things are already failing.

**Swoole or FrankenPHP.** Would let PHP hold connections and avoid the second
runtime entirely. Rejected as the less conventional choice: it changes the
concurrency model of the whole application to solve one problem, and the
operational knowledge required is less widely held than "there is a small Node
service".
