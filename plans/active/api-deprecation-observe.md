# API endpoint deprecation-and-observe

**Status:** implemented — PR #1021 (mechanism) + PR #1025 (applied to #984's endpoints)
**Owner:** geeks@ilovefreegle.org

> **Mechanism evolved (2026-07-09, PR #1025):** the sections below describe the
> *sunset date* living in the OpenAPI spec as an `x-sunset` extension. That turned
> out to be impossible here: `swagger.json` is **go-swagger-generated at build**,
> and adding `x-sunset` to a `swagger:route` block **breaks generation** (empirically
> verified; `// Deprecated: true` survives, `x-sunset` does not). So the source of
> truth moved into the code: `deprecation.Marker(endpoint, sunset)` **registers**
> each deprecated route and serves the set at `GET /apiv2/deprecated`, which the
> batch command reads instead of parsing the spec. This is also *safer* than a
> config map — the registry IS the set of `Marker()`'d (hit-logging) routes, so the
> observed set and the logging can't drift into a false "safe to retire". Read the
> design below for the rationale; substitute "registry / GET /apiv2/deprecated" for
> every "`x-sunset` in `swagger.json`".

## Problem

We have a PR that rationalises some apiv2 (`iznik-server-go`) endpoints. We can't
just delete them: three caller populations may still hit them and they retire at
very different rates.

- **Web SPA** — updates atomically on deploy; drains within days.
- **Native apps** (Capacitor Freegle/ModTools) — users run old builds for
  weeks-to-months; a fixed timer would false-alarm on stragglers. `app_min_webversion`
  is a lever to force the tail down, but we have to *see* the tail first.
- **External callers** (TrashNothing, IPNs, housekeeper extension, `/userdump` key
  holders) — we cannot deploy them at all. Some "dead" endpoints may still have
  external traffic the manual audit (commit `470a2051e`) could miss.

So the clock is not a safe retirement trigger. Retirement must be gated on
**observed-zero usage**, and when an external caller shows up on a supposedly-dead
endpoint the intent is **keep the endpoint and chase the consumer down** — which
means we need enough caller identity in the signal to know *who* to chase.

## Goal

A lightweight deprecate-then-observe loop:

1. Mark an endpoint deprecated with a sunset date.
2. Log every hit to it (already-flowing infra).
3. After the sunset date, an overnight job emails geeks@ per endpoint: **safe to
   retire** (zero hits) or **still in use** (count + caller breakdown to chase).
4. A human retires it, or removes the sunset marker to keep + chase.

## Non-goals (deliberately not building)

- No new DB counter table, no Go scheduled job, no per-call Sentry alerts, no
  registry data structure, no API-key→partner-name resolution service, no dashboard.
- No automatic retirement. Retiring stays a human edit (delete route + handler +
  spec entry), same as the existing audit's hard-delete step.

## Design

Three parts. The spec is the source of truth for *what* is deprecated and *when* it
sunsets; Loki is the source of truth for *whether it's still called*; the artisan
command joins them.

### 1. Mark deprecated in the spec

In `iznik-server-go/swagger/swagger.json`, on each rationalised operation:

- `"deprecated": true` (standard Swagger 2.0).
- `"x-sunset": "YYYY-MM-DD"` (vendor extension; the fortnight+ date after which
  calls are no longer expected). This is the *arm* date, not a hard retire date.

The `deprecated: true` set is the canonical list the analyzer reads back — no
separate registry. The sunset **date lives only here**, so there is a single source
of truth for it (the Go side never hard-codes it — see below).

### 2. Log every hit (Go)

A tiny per-route middleware on the deprecated routes in
`iznik-server-go/router/routes.go`, using the existing `misc/loki.go` client
(`LogCustom`, which writes JSON entries → Alloy → Loki, resilient to Loki downtime).
One entry per hit under its own `source="deprecated_endpoint"` stream:

- `endpoint` — the **route pattern** (`GET /message/:id`), taken from
  `c.Method()` + `c.Route().Path`. NOT the filled URL.
- **caller context already on the request, best-effort, no new lookups:**
  `user_agent`, `app_version` (if the request carries one), `user_id` (if
  authenticated), and any API-key / partner header present (the external-caller
  chase handle).

The middleware also sets a `Deprecation: true` response header (a boolean marker,
no date → no duplication of the spec's `x-sunset`) so well-behaved external
consumers can self-detect. Logging is side-effect only: it never changes the
response.

> **Why a dedicated stream, not the existing request log:** `misc/lokiMiddleware.go`
> already logs every request, but its `endpoint` is `c.Path()` — the concrete
> URL *with IDs filled in* (`/api/message/12345`), so matching a route would mean a
> brittle regex over a high-cardinality field, and the user-agent lives in a
> *separate* `api_headers` stream with only 7-day retention (shorter than our
> window). A dedicated line puts the route pattern + caller identity in one
> queryable place. Do not "simplify" this back to reusing the request log.

### 3. Overnight artisan command (Laravel, batch-prod)

New `monitor:deprecated-endpoints` command in
`iznik-batch/app/Console/Commands/Monitor/`, modelled on `EmailHealthCommand`
(config-driven, scheduled via `routes/console.php`, cron-tab badge). Nightly it:

1. Reads `swagger.json`, collects operations with `deprecated: true` **and an
   `x-sunset` date that has passed**. Endpoints still inside their grace window are
   skipped — **no email before sunset.**
2. For each, queries Loki `query_range` for `source="deprecated_endpoint"` +
   `endpoint="…"` **from that endpoint's sunset date to now** — not a fixed trailing
   window, so we only count the *unexpected* post-grace calls and never mistake
   pre-sunset expected traffic for "still in use". `LokiService` is currently
   write-only, so add a small `query_range` read (via the `Http` facade against
   `LOKI_URL`, new `config('freegle.loki.query_url')`).
3. If there is ≥1 past-sunset endpoint, emails geeks@ (`config('freegle.geeks_addr')`,
   via `Mail::raw`) a single report:
   - **Safe to retire:** endpoints with zero hits since sunset.
   - **Still in use:** endpoints with hits — count + observation span (days since
     sunset) + a small caller breakdown (top user-agents / api keys / user ids)
     so it doubles as the chase-down worklist.

   The observation span lets the human avoid a premature all-clear the morning
   after sunset (0 hits in 1 day ≠ 0 hits in 10) — no minimum-window rule baked in,
   just the number in front of them. If no endpoint is past its sunset date, it
   sends nothing.

### 4. Retire, or keep + chase

- **Safe to retire** → human deletes route + handler + spec entry (+ tests).
- **Still in use, decided to keep** (external caller we'll chase, not drop) →
  remove `x-sunset` (or flip `deprecated` off) in the spec so it drops out of the
  nightly report. No new "mute" mechanism — a spec edit is the mute. This also
  prevents nightly nagging about an endpoint we've consciously kept.

## Testing

- **Go:** unit-test the hit-logger emits a `deprecated_endpoint` entry with the
  expected `endpoint` route pattern + caller fields; a route test that a deprecated
  route still serves normally (logging never changes the response).
- **PHP:** unit-test the command's verdict logic — past-sunset filtering (pre-sunset
  skipped), zero-hits→"safe to retire", non-zero→"still in use" with breakdown,
  and "no past-sunset endpoints → no email". Mock the Loki `query_range` response.
- **Spec:** `swagger_test` already guards drift; ensure `x-sunset` doesn't break it.

## Rollout

1. Land the mechanism (spec flags on the PR's endpoints + Go middleware + artisan
   command + scheduler entry) with sunset dates a fortnight+ out.
2. Ship the client changes that stop calling the endpoints (web immediately; app in
   its release train).
3. Watch the nightly emails after sunset. Web should be silent fast; app tail
   informs whether to bump `app_min_webversion`; external callers get chased.
4. Retire the confirmed-dead ones; keep + chase the rest.

## Grounding (existing infra reused)

- `iznik-server-go/misc/loki.go` — `LokiClient.LogCustom(source, labels, data)`.
- `iznik-server-go/misc/lokiMiddleware.go` — existing per-request logger (why we
  don't reuse it: logs `c.Path()`, not the route pattern).
- `iznik-server-go/router/routes.go` — central router; deprecated routes live here.
- `iznik-server-go/swagger/swagger.json` — hand-maintained spec; `swagger_test` guards it.
- `iznik-batch/app/Services/LokiService.php` — write-only today; add a `query_range` read.
- `iznik-batch/app/Console/Commands/Monitor/EmailHealthCommand.php` — command idiom.
- `iznik-batch/routes/console.php:315` — `Schedule::command('monitor:email-health')` pattern.
- `iznik-batch/config/freegle.php` — `loki` block + `geeks_addr` recipient.
