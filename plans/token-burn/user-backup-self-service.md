# Candidate: user backup index and self-service restore

Status: draft
Last reviewed: 2026-10-28

## Goal

Support can find which nightly backup holds a member in the state they want and request a
restore from ModTools, instead of a developer restoring backups blind at one to two hours a go.
A snapshot index is built after each nightly restore on Yesterday, exposed through the
yesterday-api, with a signed restore request queue and a ModTools lookup. The design is
`plans/2026-03-30-user-backup-index-design.md` (March 2026); nothing of it is built.

## Why it qualifies

- Token-heavy because: a Laravel indexing command, a SQLite index on its own volume, three or four yesterday-api endpoints with request signing, a sequential job queue, a ModTools support page, a change to the restore script, and tests at each layer, all against the Yesterday machinery which is awkward to test.
- Unattended because: the design answers every question it raises (what is captured, where it lives, how requests are signed, who may ask); it runs entirely on Yesterday and dev stacks.
- Worth it because: every restore today is a developer's hour or two, found by trial; the support team cannot do it at all; with this, they can, and the developer time goes.

## What exists today

- The design file: index after each nightly restore (`restore-backup.sh` calling `php artisan user:index-snapshot --backup-date=...`), users active in the last 90 days, SQLite at `/data/user-snapshots.db` on a host-mounted volume, schema and retention in its sections 1 to 3, endpoints in 2, queue in 3, shared-secret signing and authorisation in 4, ModTools UI in 5.
- No `user:index-snapshot` command in iznik-batch; `yesterday/api/server.js` only has LVM snapshot handling (checked 2026-10-28).
- Yesterday's nightly timeline and the backup driver are known (restore done by about 08:50 UTC), so the index has a fixed slot to run in.
- The restore itself, from a chosen backup to a live account, is still a developer action in the design (section 3 "live restore execution"); the queue hands it a job.

## Decisions settled

- **The index is metadata only, on Yesterday, never a copy of member data elsewhere.** What is captured is the design's list; nothing more.
- **Requests are signed with the shared secret and limited to Support and Admin**, as the design says; Yesterday has no user sessions.
- **Sequential queue.** One restore at a time, because the live restore is heavy and ordering matters.
- **The index survives the nightly MySQL restore** by living on its own volume.

## Open questions for Edward

- Does the restore request end in an automatic live restore, or in a job that a developer runs with the backup already identified? Recommended: the second for the first release; automatic once the identified-backup step has been right ten times.
- Ninety days of `lastaccess` for the index, or everyone? Recommended: 90 days as designed; the rare older case stays manual.
- Should the index also note merged and deleted accounts, so a "where did this member go" question is answerable? Recommended: yes, it is a few columns and most support questions are that shape.

## External prerequisites

- [ ] Nothing outside the repo; Yesterday and its volume exist.

## Tasks

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | Indexer command and schema; retention; hooked into `restore-backup.sh`; tests on a seeded backup | - | A | iznik-batch, yesterday scripts |
| 2 | yesterday-api endpoints: snapshots by email, restore request (signed), request status, request list; auth and signing tests | 1 | B | yesterday/api |
| 3 | Job queue in the index database; sequential worker; job steps as designed | 2 | B | yesterday/api |
| 4 | ModTools: User Backup Lookup page for Support; request and status | 2 | C | iznik-nuxt3/modtools, iznik-server-go (signing proxy if needed) |
| 5 | Docs: ops runbook for Yesterday updated, developer reference page, moderator guide for Support | 3, 4 | A | docs |

## Acceptance

- The morning after a restore, the index holds every member active in the last 90 days from that backup with the designed fields, and the previous days are retained as designed.
- A Support volunteer in ModTools enters an email and sees which backups hold that member and in what state.
- A restore request is signed, accepted, queued and visible with its status; an unsigned or non-Support request is refused.
- The queue processes one job at a time and records the outcome.
- No member data leaves Yesterday except what the lookup shows, and that is the designed fields only.

## Review burden

- PRs: 5
- Each UI PR carries: screenshots of the lookup page
- Each migration PR carries: none expected (SQLite on Yesterday, not production MySQL)
- Docs page(s) to add or update, with `covers:`: the Yesterday ops page, a developer reference page for the index and endpoints

## Out of scope

- Restoring anything other than a member account.
- Changing how backups are taken.

## Related plans

- `plans/2026-03-30-user-backup-index-design.md` - the design; keep it.
