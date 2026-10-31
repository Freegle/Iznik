# Candidate: incident response with nobody awake

Status: draft
Last reviewed: 2026-10-28

## Goal

When something stops a large part of Freegle working, or a spam or phishing wave or a security
problem threatens it, the system notices, contains it, tells members and volunteers what is
happening in one place, and pages a person, all before anyone is awake. John Carroll's draft
"Support Interface Evolution" (version 0.1, 2026-10-28) sets the problem out: there is no 24x7
cover, "panic buttons" that stop functions need named people trained to press them, an incident
team with an owner, one place for the incident record and one board for communications, and he
warns the team would go moribund between incidents. This candidate takes the parts that can be
code (detect, contain, record, communicate, page) and leaves people the one job that needs them:
deciding, by phone, when the automatic containment is not enough.

## Why it qualifies

- Token-heavy because: detection rules over Sentry, Loki, CI and the site's own counters; a containment layer that generalises the lockdown switch into several named switches with automatic triggers and automatic lift; an incident record and a status page served from outside the main stack; member and volunteer communications; paging; and a drill harness that replays past incidents to prove each path, with tests at every layer.
- Unattended because: that is the point of it; the rules and thresholds are the only decisions, and the drills run on dev stacks.
- Worth it because: today a spam wave at 3 am runs until a moderator wakes, and an outage is found by members; the organisation cannot staff an incident team and should not need to.

## What exists today

- The lockdown switch (`docs/ops/runbooks/lockdown.md`, shipped September 2026): stops anything a member writes from reaching anyone else while the site stays up; pressed by a person from ModTools; has a check-and-lift runbook. This is John's panic button, built, for one class of incident.
- The monitor FSM (`monitor-fsm/README.md`): watches Discourse and Sentry, fixes bugs by PR, repairs master CI when it is red; has a dashboard and a status post. It is a first responder for code faults, not for operational incidents.
- Sentry, Loki and the status container (`status-nuxt/`) give the signals and a place to show a status page; there is no public status page today.
- The CI runner reaper and the Hetzner and Katapult scripts show the house pattern for automatic actions with an audit trail.
- Unknown: the last twelve months of incidents by kind and time of day, which sets the detection rules; the Sentry and Loki history can say.

## Decisions settled

- **Containment is automatic, lifting is automatic, escalation is human.** A trigger (a spam rate, an error rate, a CI red on master with a deploy pending, a login storm) flips a named switch and records why; the switch lifts itself when the trigger clears for a set time; a person is paged with the record and can hold the switch or lift it early.
- **One incident record, one status page.** The record is written by the system first and amended by people; the status page is the only place members and volunteers are told, and it is served from outside the main stack so it is up when the stack is not.
- **No incident team.** A rota of people who can be paged, which can be one person; drills are run by the system against a dev stack on a schedule, so nothing goes moribund.
- **Switches are few and named.** Writes-out (the lockdown), new accounts, new posts, outbound mail, and read-only; nothing finer until an incident shows the need.
- **Communications are templated and short**, written in advance in plain English, and the system fills in what it knows.

## Open questions for Edward

- Which signals count as triggers for the first release? Recommended: spam-report rate and content-check block rate (writes-out), Sentry error rate on the API (read-only banner), master CI red with a deploy in flight (deploy hold), new-account rate (new accounts).
- Who is on the paging rota and by what channel? Recommended: you, by phone, with a second person when one exists; the page carries the record and the switch state.
- Where does the status page live? Recommended: the status container's host or a static page on object storage, with its own domain, nothing in common with the API path.
- How long before a switch lifts itself? Recommended: 30 minutes after the trigger clears, with the page saying so.

## External prerequisites

- [ ] A paging route (phone or SMS provider, or a push to the app on your phone). Owner: Edward.
- [ ] A domain for the status page. Owner: Edward.

## Tasks

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | Incident history: pull twelve months of Sentry, Loki and lockdown events, classify by kind and hour, propose thresholds; a report | - | A | scripts, docs |
| 2 | Switches: generalise the lockdown into named switches with a trigger, a reason, an automatic lift and an audit record; ModTools shows state and lets a person hold or lift | - | B | iznik-server-go/lockdown, iznik-batch, iznik-nuxt3/modtools |
| 3 | Detection: rules over the signals from task 1 that flip switches and open an incident record | 1, 2 | B | iznik-batch, monitor-fsm |
| 4 | Status page and communications: a page outside the stack, templated member and volunteer messages, the record shown | 2 | C | status-nuxt or static, docs |
| 5 | Paging: the rota, the message with record and switch state, hold and lift from the page | 2, 3 | B | iznik-batch |
| 6 | Drills: a harness that replays a spam wave, an API error storm and a CI red on a dev stack and checks each path end to end; scheduled | 3, 4, 5 | A | scripts, tests |
| 7 | Runbook and developer reference | 6 | A | docs/ops |

## Acceptance

- A simulated spam wave on a dev stack flips writes-out within two minutes, opens a record, updates the status page, pages the rota, and lifts itself when the wave stops.
- A simulated API error storm shows the read-only banner on the site and the status page, and clears.
- A person can hold or lift any switch from ModTools or from the page, and the record shows who and why.
- The drill harness runs on a schedule and reports; a failed drill is a bug, not a note.
- Nothing in the status path depends on the main database or the API being up.

## Review burden

- PRs: 7
- Each UI PR carries: screenshots of the switch state and the status page
- Each migration PR carries: raw production SQL in the description (the incident record and switch state tables)
- Docs page(s) to add or update, with `covers:`: the lockdown runbook (becomes the switches runbook), a new incident runbook, a developer reference page

## Out of scope

- 24x7 paid cover.
- Scheduled quiet periods for the site (John's option a); the switches make it unnecessary.
- Security incident forensics; this contains and tells, it does not investigate.

## Related plans

- `docs/ops/runbooks/lockdown.md` - built on.
- `monitor-fsm/README.md` - the code-fault responder; detection reuses its signals.
- `plans/token-burn/command-log-availability.md` - the availability work makes the read-only state real rather than a banner.
