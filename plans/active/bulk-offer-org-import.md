# Bulk-offer org import + outreach recording

**Status:** building (2026-06-19)
**Branch (PR):** feat/bulk-offer-org-import (built off master via clone; main checkout untouched)
**Related:** plans/community-reuse-outreach-methodology.md (this is the operational `OutreachRound`, simplified: org = user, clearance = the bulk-offer message). Skill: `.claude/skills/community-reuse-outreach`.

## What this delivers
An artisan command to import community organisations (from the `community-reuse-outreach` CSV) as new-or-existing Freegle **users**, and record each org's details + preferences **for a specific bulk offer**, WITHOUT sending the automatic welcome email. The per-org intro is sent later (separately) via chat, linking to the bulk-offer page.

## Design decisions (grounded in the codebase)
- **Org = a Freegle user.** Find-or-create by email/canon (`UserEmail` + `User::canonMail`), `users.source = 'cold_outreach'`, `fullname` = org name. Reuses the "pseudo-Freegle-user" path from the methodology plan.
- **No welcome email.** Welcome mail is sent by the separate `mail:welcome:send` scheduler, which only picks up users `added` within the last day (cursor in `batch_email_progress`). Suppress by setting `users.added` to >1 day in the past on newly-created org users. (No observer fires on user create.)
- **Bulk offer = a `messages` row** (page at `/message/{id}`). My table only references `messages.id`, so it does not depend on the unmerged bulk-items branch (#618).
- **New table `messages_bulk_outreach`** = one row per (bulk offer, org user): org snapshot + per-offer preferences (clusters/why) + outreach lifecycle (status, chatid, sent_at, outcome) + PECR `suppressed_until`. UNIQUE(msgid, userid) so re-running the import updates rather than duplicates.

## Command
`bulkoffer:import-orgs --msgid=<bulk offer messages.id> --input=<csv> [--dry-run] [--source=cold_outreach]`
- CSV columns (from the outreach list): Tier, Cost to donor, Organisation, Type, Area, Email, Website, Likely wants / why, Cluster, Activity evidence, Confidence, Source.
- Per row: validate email; find-or-create user (backdate `added`, set source, fullname); upsert a `messages_bulk_outreach` row keyed (msgid,userid) with the org snapshot + preferences, status `Imported`.
- `--dry-run` reports created/matched/updated counts with no writes. Validates `--msgid` exists.

## Tasks
| # | Task | Status |
|---|------|--------|
| 1 | migration + idempotent prod SQL | building |
| 2 | MessagesBulkOutreach model | pending |
| 3 | ImportOrgsCommand | pending |
| 4 | tests (create, match-existing, no-welcome, dry-run, re-run-upsert) | pending |
| 5 | validate (status container, iznik_batch_test) | pending |
| 6 | PR | pending |

## Next (separate PR): `bulkoffer:send-intro`
For a msgid, for each `Imported` (human-approved) row: get-or-create User2User chat (donor/system user -> org user), post a chat message with the per-org adapted intro + `refmsgid` = the bulk offer + link to `/message/{msgid}`; set status `Sent`, `chatid`, `sent_at`, `sent_via='chat'`. Human-gated, `--dry-run`, dark by default. The table already carries the fields for this.
