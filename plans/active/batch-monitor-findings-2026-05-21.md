# Batch error triage — handoff brief (2026-05-21)

From a multi-day watch of `freegledocker-batch-prod` laravel.log (monitor: "batch errors").
Three classes of error seen. **Two are actionable bugs; the rest is understood noise — do not chase.**

Prod log inside container: `freegledocker-batch-prod:/var/www/html/storage/logs/laravel.log`
(access: `docker exec freegledocker-batch-prod ...`). Do **not** run scripts/migrations against prod;
read logs only.

---

## ACTION 1 — ChitChat report emails silently lost (real bug, fix this)

**Symptom:** Background task `email_chitchat_report` fails all 3 retries with
`email_chitchat_report requires user_email`, then dies. Sporadic: Apr 30, May 8, May 21 — each a
3-attempt burst for one task id.

**Root cause:** Producer is the Go API. When a user reports a newsfeed/ChitChat post, it queues the
support-notification email but fetches the reporter address with a `preferred = 1` filter:

`iznik-server-go/newsfeed/newsfeed.go:1044`
```sql
SELECT u.fullname, ue.email FROM users u
LEFT JOIN users_emails ue ON ue.userid = u.id AND ue.preferred = 1
WHERE u.id = ?
```
If the reporter has **no email flagged `preferred = 1`**, `ue.email` is NULL → task queued with empty
`user_email` (`newsfeed.go:1046-1052`). Laravel consumer requires it
(`iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php:264-268`) → 3 fails → task
dead → **the report to ChitChat support is never sent; mods never see the reported post.**

**Fix (Go producer):** select best-available email instead of requiring preferred:
```sql
SELECT u.fullname, ue.email FROM users u
LEFT JOIN users_emails ue ON ue.userid = u.id
WHERE u.id = ?
ORDER BY ue.preferred DESC, ue.id ASC
LIMIT 1
```
Small, low-risk. Needs `iznik-server-go` rebuild. Add/extend a test near
`iznik-server-go/test/newsfeed_test.go:261` (existing test asserts the task is queued) to cover a
reporter with no preferred email.

**Open product question (ask the user):** when a reporter has **zero** emails at all (rare), should the
support report still go out without a reporter address, or is dropping it acceptable? If "still send",
also relax the consumer so missing `user_email` doesn't hard-fail.

---

## ACTION 2 — Weekly GitSummary drops iznik-batch (config + masking)

**Symptom:** Every ~7 days at 18:00 (Apr 15, 22, 29, May 6, 13, 20): `GitSummaryService: Failed to
clone repository` for `iznik-batch.git` —
`fatal: could not read Username for 'https://github.com': No such device or address`.

**Root cause A (config, needs human):** `GIT_SUMMARY_GITHUB_TOKEN` is unset on batch-prod
(`iznik-batch/config/freegle.php:227`). The other 4 repos in the run are public so they clone
anonymously; **iznik-batch is private** and needs auth. Fix: add a GitHub PAT (repo-read scope) to
`.env.background` on batch-prod as `GIT_SUMMARY_GITHUB_TOKEN=...`. (Prod secret — only the user can set
it.)

**Root cause B (code masking, optional fix):** on clone failure
`GitSummaryService::getRepositoryChanges()` returns `null` (`GitSummaryService.php:133`), and the caller
treats `null` identically to "no changes" → logs `No changes found` (`:307`). So the weekly tech-summary
email to `discoursereplies+Tech@ilovefreegle.org` has **silently omitted all iznik-batch changes for
6+ weeks.** Recommended: distinguish clone-failure from genuinely-empty so the report shows
"iznik-batch: failed to fetch" — prevents a future credential lapse from silently recurring.

---

## DO NOT CHASE — understood, no code action

**SMTP `250` transients.** `Error processing auto-repost/chase-up for group #NNN: Expected response
code "250" but got empty code.` A few per day, always at the `:01–:03` cron burst when reposts/chase-ups
hammer the relay. Connection established then dropped mid-conversation under peak concurrency. Jobs
catch it, count it in the run summary (`errors:N`), and continue — one email lost per occurrence. Rate
is low and steady. *Possible* improvement if ever desired: one retry-with-backoff in the send loop would
absorb nearly all of these. Not worth a change at current volume.

**SMTP connection-refused bursts.** `Connection could not be established with host "mail-host:25"
(Connection refused)`. `mail-host` → 10.220.0.217 via /etc/hosts (resolves fine). Comes in occasional
bursts (339 on Apr 18, 47 on May 16, 15 in one second on May 18 23:00) = brief SMTP relay restarts.
Affects welcomes, EngageMail, VolunteeringDigest — those recipients are skipped (logged WARNING), no
retry. Infra-level, not code. Watch for sustained bursts only.

**Reach Volunteering feed.** `Reach Volunteering feed: expected JSON array` / `unexpected feed payload`.
Daily 21:00 cron. Upstream Reach (a Drupal site) returns HTTP 200 with an HTML body containing
`Fatal error: Maximum execution time of 30 seconds exceeded` — their feed endpoint times out and emits
an error page instead of JSON. **Entirely upstream**; Freegle code already detects it and aborts cleanly
(`iznik-batch/app/Services/ReachVolunteeringService.php:42-52`, enhanced diagnostics already deployed).
Failed May 19 + 20. Consequence: no new Reach opportunities sync until they fix it; aging ones expire
normally. If it persists, notify the Reach contact — no patch on our side.

---

## Constraints (from CLAUDE.md / memory)
- NEVER push without explicit user instruction. NEVER merge PRs.
- Don't run tests/migrations/dry-runs against prod locally — write code only; let user/CI run.
- Plans live in `FreegleDocker/plans/`, never in subdirectory repos.
- Go API (iznik-server-go) needs a rebuild after changes.
