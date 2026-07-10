---
last_reviewed: 2026-07-09
owner: Freegle dev team
covers:
  - Logging.md
  - SENTRY-INTEGRATION.md
  - SENTRY-AUTOFIX.md
---

# Monitoring and logging

How we see what the system is doing and find problems. The technical reference is
[../../Logging.md](../../Logging.md).

## Logging: Loki

**Grafana Loki** is the log aggregation backbone. Application and service logs flow into
Loki and are queried with LogQL.

- In **development**, Loki is available locally (port 3100) and you can query it directly.
- In **production**, logs are shipped to Loki and retained per category, with tiered
  retention (short retention for high-volume categories, longer for audit-style logs).
- Logs carry **trace and session ids** so a single request or user action can be followed
  across services. Client-side tracing feeds the same system.

For moderators and support, a **Log Viewer** in ModTools surfaces the relevant logs
without needing direct Loki access.

Timestamps are in nanoseconds and label values must be quoted; see the query examples and
the Go wrapper referenced in [../../Logging.md](../../Logging.md).

## Error tracking: Sentry

**Sentry** captures application errors. Freegle also runs tooling around Sentry that can
propose fixes for recurring issues as pull requests for a human to review. The
architecture is in [../../SENTRY-INTEGRATION.md](../../SENTRY-INTEGRATION.md) and
[../../SENTRY-AUTOFIX.md](../../SENTRY-AUTOFIX.md). (Those two overlap and are candidates
for consolidation; treat them as one system described twice.)

## Development and test visibility: the status dashboard

The **status dashboard** (`status-nuxt`) is the development-time window into the stack: it
shows startup progress, runs the test suites, and integrates Sentry. It is a development
and CI tool, not a production monitoring surface. See
[../developers/03-testing.md](../developers/03-testing.md).

## What to reach for

| Question | Tool |
|----------|------|
| "What did this user's request do?" | Loki, filtered by trace/session id |
| "What is erroring in production?" | Sentry |
| "Is the local stack healthy / did tests pass?" | status dashboard |
| "What did a moderator or member see in the logs?" | ModTools Log Viewer |

Access to production monitoring is gated by team credentials, which are not documented
here.
