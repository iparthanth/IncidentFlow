# ADR 0002 — Explicit tenant scoping, not a global Eloquent scope

**Status:** Accepted
**Date:** 2026-01-06

## Context

Every incident, service, postmortem, notification and audit entry belongs to an
organization. Nothing may ever cross that boundary.

Laravel's idiomatic tool for this is a global scope: register one on each model
and every query is filtered automatically, forever, with no call site needing to
remember.

## Decision

No global scope. Every tenant-scoped query names its organization explicitly via
`forOrganization()`, and `EnsureOrganizationContext` middleware guarantees a
verified organization is always available to name.

## Rationale

A global scope makes queries safe *by accident*, and its failure mode is silent.

It works by reading "the current tenant" from somewhere ambient — a container
binding, a static, a session. That value exists inside an HTTP request. It does
not exist in a queue worker, an artisan command, the scheduler, or a test that
constructs a model directly. In those contexts the scope either throws in an
unexpected place or, far worse, resolves to null and filters nothing.

"Filters nothing" means a nightly report that quietly includes every customer's
incidents. Nobody notices, because the output looks plausible.

Naming the organization at the call site means a missing filter is *visible in
the code being reviewed*, and a query that forgets one is a bug in that query
rather than a silent dependency on ambient state that may or may not have been
set.

## Consequences

**Good.** Queue jobs, commands and the scheduler have no special case — they
name the organization exactly as a controller does. A code reviewer can see
tenancy in the diff. The failure mode changes from "silently returns everything"
to "returns nothing", which is loud.

**Bad.** More typing, and one more thing a new contributor must learn. A
forgotten `forOrganization()` is possible in a way it would not be with a global
scope.

**Mitigation.** Policies enforce the boundary a second time at the object level:
`allowsWithin()` confirms the resource's `organization_id` matches the request's
organization before considering the role at all. Tests probe every object-level
route across a tenant boundary, not only across a role boundary — because
broken object-level authorization is the failure this is really guarding
against, and it is the one that role checks never catch.
