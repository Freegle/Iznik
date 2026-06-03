# Auto-approve NULL-status posts after a configurable delay

Branch: `feature/autoapprove-delay` (worktree `autoapprove`)

## Goal

Members whose group posting status is **NULL** ("auto-moderated" tier — never explicitly
set) currently have their posts stuck in Pending until a mod acts or the 48-hour fallback
fires. Introduce a faster, safer path: their **content-check-clean** posts auto-approve
after a configurable delay (default **20 minutes**), giving mods and microvolunteers a
window to intervene, **unless** danger signals are present, with a configurable
**quality-check sample** held back for manual review.

Plus a modtools Approved-page filter dropdown (auto-approved / recently-joined / outside
CGA) that defaults to summary view for the auto-approved variant.

## Production sizing (live, last 30 days)

- 66,074 posts; 11.3% from members who joined that group in the last 30 days.
- Of recent-joiner posts: **NULL 76.5%**, DEFAULT 22.9%, MODERATED 0.3%, UNMODERATED 0.3%.
- The 20-minute path carries ~5,700 posts/30d (~190/day) — today they wait for a mod or 48h.

## Key architecture (from code exploration)

- **All posts already land in Pending.** Go `handleJoinAndPost` always inserts Pending;
  PHP `JoinAndPost`/email (MailRouter) route NULL → Pending. **No routing change needed.**
- `messages:contentcheck` (every minute, `ContentCheckService::processUnprocessed`)
  runs spam/worry/PII/language checks **once** per fresh pending row:
  - clean + user not-moderated (DEFAULT/UNMODERATED) + group not moderated → promote now.
  - clean + user **NULL**/MODERATED **or** group moderated → kept Pending, sets
    `contentcheck_checked_at=now`, `contentcheck_reasons=NULL`.
  - flagged (`action=flag`) → kept Pending with `contentcheck_reasons` JSON.
  - blocked (`action=block`) → collection Spam.
- `messages:auto-approve` (hourly, `AutoApproveService`, 48h) is the existing safety net.
- Approve = `UPDATE messages_groups SET collection='Approved', approvedby=NULL,
  approvedat=NOW(), arrival=NOW()` + log `Autoapproved` + spatial index + freebie alerts
  for OFFERs. Digest cron mails it (reads collection='Approved', arrival>lastsent).

## Design — new batch service `AutoApproveCleanService`

`messages:auto-approve-clean`, scheduled **every minute** (`withoutOverlapping`,
`runInBackground`). Picks up Pending rows that:

1. poster's `memberships.ourPostingStatus IS NULL` — the auto-moderated tier (the
   "reinterpret NULL as group settings" change; explicit MODERATED stays moderated,
   DEFAULT/UNMODERATED already approved immediately by contentcheck).
2. `contentcheck_checked_at IS NOT NULL AND contentcheck_reasons IS NULL` — content
   check ran and was **clean**. (This is how "all posts still go through spam checks;
   suspect → Pending" is honoured: suspect rows have reasons and are excluded.)
3. `mg.arrival <= NOW() - INTERVAL {delay} MINUTE`, where `{delay}` is the per-group
   `settings.autoapprove.delay_minutes` (JSON_EXTRACT) falling back to
   `config('freegle.autoapprove.delay_minutes', 20)`.
4. `mg.heldby IS NULL`, `m.heldby IS NULL`, `mg.spamreason IS NULL`, `m.spamreason IS NULL`,
   `mg.deleted=0`, `m.deleted IS NULL`, `u.deleted IS NULL`.
5. Group is **not moderated**: `settings.moderated` falsy AND `rules.fullymoderated` falsy
   AND `overridemoderation != 'ModerateAll'` AND publish AND not closed AND not
   `autofunctionoverride` (mirrors AutoApproveService gating, minus the 48h membership age).
6. **No danger signals** (per msgid/groupid/fromuser) — see below.
7. **Not** in the quality-check sample (deterministic per msgid; percent from
   `settings.autoapprove.quality_check_percent` ?? `config(...quality_check_percent, 0)`).

Then approve (same side effects as `AutoApproveService::approveOnGroup`) + log Autoapproved.

### Danger signals (veto → leave Pending for a mod)

- **Microvolunteering reject**: `microactions` where `msgid=`, `actiontype='CheckMessage'`,
  `result='Reject'` → count ≥ 1.
- **User notes**: `users_comments` where `userid=fromuser` → any row.
- **Recent negative mod action**: `logs` where `user=fromuser` AND `byuser<>user` AND
  `timestamp >= NOW()-{danger_log_days}` AND
  ((type='Message' AND subtype IN ('Rejected','Deleted','Replied')) OR
   (type='User' AND subtype IN ('Mailed','Rejected','Deleted','Suspect','ClassifiedSpam'))).
- **Known spammer**: `spam_users` where `userid=fromuser` AND collection IN ('Spammer','PendingAdd').
- **Membership review pending**: `memberships.reviewrequestedat IS NOT NULL` AND
  (`reviewedat IS NULL` OR `reviewedat < reviewrequestedat`).

(Worry-words / concern keywords already excluded via `contentcheck_reasons IS NULL`.)

### Quality-check sample

`abs(crc32((string)$msgid)) % 100 < percent` → **held** (skip; mod reviews, or 48h fallback
catches it). Deterministic so a message never oscillates.

## Frontend / Go API filter (secondary)

- Go `ListMessagesMT` (`/api/modtools/messages`): new `filter` param — `autoapproved`
  (`mg.approvedby IS NULL`), `recentjoin` (join memberships added ≥ NOW()-7d),
  `outsidecga` (spatial: message point not within group polygon). Go tests for each.
- Approved page `[[id]]/[[term]].vue`: `<b-form-select>` dropdown (default "All"); pass
  `filter` to `fetchMessagesMT`; when `autoapproved` selected set the summary miscStore key.
- `ModSettingsGroup.vue`: two `<ModGroupSetting>` controls (delay minutes, quality %).

## Config / settings

- `config/freegle.php` → `autoapprove` block (delay_minutes=20, quality_check_percent=0,
  danger_log_days=90).
- `iznik-server/include/group/Group.php` `defaultSettings['autoapprove']` gains
  `delay_minutes`/`quality_check_percent` (for the modtools UI + PHP parity).

## Gotchas (thought through)

1. **Re-evaluation**: contentcheck only touches each row once (`contentcheck_checked_at IS
   NULL`). This service must query already-checked rows → keys off `checked_at NOT NULL AND
   reasons NULL`, NOT `checked_at NULL`.
2. **Per-group delay** must be in the SQL (JSON_EXTRACT COALESCE default) so a group's
   shorter/longer override is honoured; a single global threshold would be wrong.
3. **Moderated groups**: contentcheck keeps clean posts Pending with reasons=NULL even on
   moderated groups, so this service must explicitly exclude moderated/Big-Switch groups.
4. **Races**: query filters `collection='Pending'` and the UPDATE uses `collection<>'Approved'`
   so a mod approve/reject in the window can't double-fire; held rows excluded.
5. **48h service overlap**: both set approvedby=NULL and guard on collection; harmless.
6. **Deleted user/message**: filtered out (no Autoapproved log on invisible rows).
7. **No membership-age gate** (unlike 48h service): the brief wants NULL members to post
   after the delay regardless of join age; danger signals + content checks + QA sample
   carry the risk. New-member visibility is also covered by the recent-join filter.
8. **arrival reset on approve**: digest keys off arrival>lastsent; per-(msgid,groupid)
   update only resets the row being approved. Correct for multi-group posts.
9. **No schema migration / no routing change** → minimal blast radius.

## Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Design doc | ✅ | this file |
| 2 | AutoApproveCleanService + tests (TDD) | ✅ | 25 unit tests; 29/29 green after held-FK fix |
| 3 | AutoApproveCleanCommand + test | ✅ | 4 command tests |
| 4 | Schedule entry (console.php) | ✅ | everyMinute, withoutOverlapping |
| 5 | config/freegle.php defaults | ✅ | autoapprove block (no Group.php change — absent=site default) |
| 6 | Go ListMessagesMT filter + tests | ✅ | autoapproved/recentjoin/outsidecga + 3 Go tests; suite running |
| 7 | Frontend approved-page dropdown + summary | ✅ | b-form-select; summary on autoapproved |
| 8 | ModSettingsGroup.vue settings controls | ✅ | delay_minutes + quality_check_percent |
| 9 | Run all suites via worktree status API | ✅ | full Laravel 3962/3962 ✓; Go 3004/3004 ✓; Vitest modtools 4475/4475 ✓ |
| 10 | Push + PR (Freegle/Iznik) | ✅ | PR #639 — https://github.com/Freegle/Iznik/pull/639 (awaiting CI; never merge) |
