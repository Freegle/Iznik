---
last_reviewed: 2026-10-16
owner: Freegle dev team
covers:
  - docs/ops/reference/logging.md
  - iznik-nuxt3/modtools/components/ModStatus.vue
  - iznik-batch/app/Monitoring/PlatformStatusWriter.php
  - iznik-batch/app/Console/Commands/Monitor/ApiBackupTrafficCommand.php
  - iznik-batch/app/Console/Commands/Monitor/ChatRosterCommand.php
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

A [lockdown](runbooks/lockdown.md) turns this dot red too, for everyone, the same as a
platform fault, because it is one. It sits alongside the separate red banner that
appears on every ModTools page while a lockdown is on (see
[Getting started](../moderators/getting-started.md)); the dot is the general "something
is wrong" signal, the banner names the specific incident.

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

### Members' API requests on a backup node

The load balancer sends every API request to one active apiv2 node and uses the others only
as backups while it is down. A backup that goes on serving members after the active node is
back raises no error anywhere: everything works, but the backup is also the database read
node and carries load it should not.

`monitor:api-backup-traffic` looks for that every night at 05:40. It counts, from Loki, the
requests each node served in each hour of the previous day, leaving out the nodes' own health
checks, and takes the busiest node to be the active one. It raises a Sentry error when another
node served more than a tenth of any hour's requests. A restart's failover lasts a minute or
two and stays well under that; clients held on a backup for hours do not. The error names the
node, the hours and the peak share. The first place to look is the load balancer's stick rules
for the API backend, which should only ever pin a client to the active node.

The whole day is checked because pinned clients drift back by themselves overnight, so a
night-only window would miss a pin-up after a daytime deploy. If Loki cannot be queried, or
holds no API traffic for the day, that is reported as an error too rather than as a pass.

### Chat participants who are never emailed

The chat mailer only emails people who have a row in `chat_roster` for the room, and
marking a chat read only updates an existing row. A participant without one sees messages
arrive in the chat but never gets an email, and their read state never sticks. Nothing
reports it: old account merges left thousands of rooms like that, found only when a member
asked why they had not been emailed.

`monitor:chat-roster` looks for that every night at 05:45. It takes every member chat with a
message in the last two days and raises a Sentry error for any live participant with no
roster row: both members of a member-to-member chat, and the member of a chat with
moderators (moderators are added to that roster when they are mailed). The error counts them
and names up to ten rooms and users. Look first at whatever creates or moves rooms, such as
account merges, for a path that leaves the roster behind. No member chat at all in two days
is reported as an error too, not a pass.

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
| "Did we actually email this member?" | `logs_emails` - see [reference/logging.md](reference/logging.md) |
| "Why is mail slow / backed up?" | [reference/outbound-mail.md](reference/outbound-mail.md), which separates our own delay from a provider throttling us |

Access to production monitoring is gated by team credentials, which are not documented
here.
