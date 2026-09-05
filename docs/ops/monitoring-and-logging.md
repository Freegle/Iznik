---
last_reviewed: 2026-09-02
owner: Freegle dev team
covers:
  - docs/ops/reference/logging.md
  - iznik-nuxt3/modtools/components/ModStatus.vue
  - iznik-batch/app/Monitoring/PlatformStatusWriter.php
---

# Monitoring and logging

How we see what the system is doing and find problems. The technical reference is
[./reference/logging.md](./reference/logging.md).

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

Not everything audit-shaped is in Loki. Sign-in and sign-out are rows in the MySQL `logs`
table, kept for a year, and are what to reach for when someone reports being logged out
unexpectedly - see [./reference/logging.md](./reference/logging.md).

Timestamps are in nanoseconds and label values must be quoted; see the query examples and
the Go wrapper referenced in [./reference/logging.md](./reference/logging.md).

## Production status at a glance: the ModTools status dot

Every ModTools page carries a small coloured dot in the navigation bar. It is the one
production health signal a moderator, or a new sysadmin, can see without any credentials
at all. Click it and a **Platform Status** panel opens listing what is wrong.

| Dot | Meaning |
|-----|---------|
| Grey | The browser has not managed to ask yet. |
| Green | Everything the monitoring checks did its work. |
| Amber | Something needs attention but the site still works. Only moderators with support or admin rights see amber; for everyone else the dot stays green, so ordinary mods are not alarmed by things they cannot act on. |
| Red | Part of the platform is not working. Everyone sees red. |

The panel headline reads **Not sure** if the browser has not had an answer for ten
minutes, which means the API itself is unreachable rather than that it reported a problem.

What feeds it matters, because it decides what the dot can and cannot tell you:

- Laravel's `monitor:scheduled-outcomes` runs every ten minutes. It does not ask whether
  jobs *ran*; it asks whether their work actually **happened** - rows written, a cursor
  moved on. A scheduler that is alive but achieving nothing still goes red.
- That same pass writes its verdict to a `config` row, which the v2 API serves at
  `/api/status` and the dot reads. There is one evaluation and two consumers, so the dot
  cannot drift away from the monitoring that pages people.
- The stored verdict carries the time it was generated. If it is more than half an hour
  old the API reports a warning naming the staleness rather than serving it as current, so
  a monitoring writer that has died reports itself instead of showing a stale green.
- Only breaches are listed in the panel. Checks that passed are left out.

Each line in the panel names the scheduled job whose work has not happened and what is
missing. The panel tells moderators to email the geeks if a problem persists for more than
an hour, so treat a red dot as something members will be asking about shortly. The checks
themselves, and how to add one, are in
[`iznik-batch/docs/scheduled-outcome-monitoring.md`](../../iznik-batch/docs/scheduled-outcome-monitoring.md).

The ModTools home page carries a second panel, for the nightly Yesterday database restore -
see [Domains, services and runbooks](domains-services-and-runbooks.md).

## Error tracking: Sentry

**Sentry** captures application errors. The **monitor-fsm** (see `monitor-fsm/README.md`)
scans unresolved Sentry issues each iteration and can propose fixes for recurring issues as
pull requests for a human to review.

## Development and test visibility: the status dashboard

The **status dashboard** (`status-nuxt`) is the development-time window into the stack: it
shows startup progress and runs the test suites. It is a development
and CI tool, not a production monitoring surface. See
[../developers/testing.md](../developers/testing.md).

## What to reach for

| Question | Tool |
|----------|------|
| "What did this user's request do?" | Loki, filtered by trace/session id |
| "What is erroring in production?" | Sentry |
| "Is the local stack healthy / did tests pass?" | status dashboard |
| "What did a moderator or member see in the logs?" | ModTools Log Viewer |
| "Is production healthy right now?" | The ModTools status dot, then Sentry |
| "Did last night's database restore work?" | The Yesterday panel on the ModTools home page |

Access to production monitoring is gated by team credentials, which are not documented
here.
