# Rippling-Out Auto-Join: Membership Side-Effect Mitigations

**Date:** 2026-06-23
**Branch:** `feat/ripple-poster-membership-on-rippled` (PR #855) - all of this ships *as part of 855*, not as separate PRs.
**Status:** Design approved, ready for implementation plan.

## 1. Background

PR #855 (`ExpandService::addPosterMembershipToRippledGroups`) makes the poster a
`Member`/`Approved` of every group their post ripples into, so the rippled post behaves
like a normal post (replies, mod contact, abuse tracking). Today it copies the poster's
home-group email settings, writes a `memberships_history` row (`processingrequired=1`),
and logs `Group/Joined` with `text='Rippled'`.

That auto-join is **unexpected** for the user and has a family of knock-on user-visible
side effects. A 13-agent investigation (live system only - Laravel `iznik-batch`, Go
`iznik-server-go`, frontend `iznik-nuxt3`; the retired V1 PHP implementation is obsolete
and not the source of truth) mapped ~27 distinct side effects. The product decision is to **keep
the membership** (mods expect that people who post are members - a workflow contract that
overrides the "don't join" option) and **mitigate the side effects piecemeal**, all on PR #855.

This document is the agreed design for those mitigations.

## 2. Decision ledger

Side effects and their disposition. "No-op" = confirmed acceptable, no change.

| # | Side effect | Disposition |
|---|---|---|
| A1 | Welcome-email storm (one per rippled group) | **Fix**: suppress per-group welcomes for rippled joins; send ONE bundled intro email per post |
| A2 | Immediate-email volume explosion (home `emailfrequency=-1` copied) | **Fix**: downgrade only `emailfrequency=-1` (immediate) -> `24` (daily); preserve every other home value (a no-email `0` stays `0`) |
| A3 | Daily digest / push scope expansion | No-op |
| A4 | Events / volunteering digest content widening | No-op: **copy from home** (separate emails but one-per-user with cadence guard, so no extra emails - only wider content) |
| A4n | Newsfeed-chitchat digest | No-op: it is unified per user and governed by the account-level `notificationmails` setting (not per-group), so a rippled membership does not change it - preserve existing setting |
| A5 | Birthday email "from" an unknown group | **Fix**: suppress for rippled memberships |
| A5b | Stories-ask / newsletter / engage | No-op: gated on *any* membership, already satisfied by home group |
| B1 | Groups appear in "My Communities" UI | No-op |
| B2 | Leave / re-join loop | **Fix**: a `Group/Left` log suppresses both re-join AND re-rippling the post into that group; existing rippled rows in left groups are pulled |
| B3 | Per-group vs account "simple email" conflict | No-op: confirmed no conflict (see §3) |
| B4 | Silent `MODERATED` posting status on rippled group | No-op |
| C1 | False "seen on many groups" spam flag | **Fix**: exclude `logs.text='Rippled'` from `checkSeenOnManyGroups` |
| C2 | Flagged-comment review fan-out | No-op |
| C3 | membercount / ratings / microvolunteering inflation | No-op |
| D1 | SAR export duplicates the post per group | **Fix**: dedup in `UserDataExportService::getMessages` |
| D2 | `forgetUser` leaves `memberships_history` | No-op |
| D3 | Auto-memberships block inactive auto-forget | No-op: home-group membership already blocks it (moot) |

Plus two added deliverables: a **backfill artisan command** and **top-level member/mod docs**.

## 3. Resolved investigation findings (rationale for the no-ops)

- **B3 - simple email (no conflict).** `simplemail` lives in `users.settings` JSON and is
  only a join-time default plus the account-level `None` opt-out. The nuxt
  `EmailSettingsSection.vue` `simpleSettings` computed returns `true` whenever `simplemail`
  is set, regardless of per-group divergence, so a rippled group at daily while real groups
  are immediate does NOT flip the user into the advanced/per-group view. At send time
  `memberships.emailfrequency` is the sole authority (`UnifiedDigestService` getUsersForDigest);
  `simplemail` is never consulted except `None` as a global opt-out. Forcing daily is therefore
  safe and invisible. A `Full` (immediate-everywhere) user gets the rippled group silently
  delivered daily instead of immediate - which is exactly the A2 intent.
- **A4 - events/volunteering are NOT in the Unified digest** (`mail:events-digest`,
  `mail:volunteering-digest` are separate crons with their own templates) but ARE one-email-
  per-user with a 3-day cadence guard (`BuildsUserRoundups::eligibleUsers`, gated on
  `eventsallowed=1`/`volunteeringallowed=1`). Leaving them at the home setting adds zero extra
  emails - only wider roundup content. Decision: copy from home (PR #855's existing behaviour).
- **D3 - moot.** Posting always does `INSERT IGNORE INTO memberships` for the home group
  (`message.go` JoinAndPost), and `forgetInactiveUsers` is blocked by any membership row, so the
  home membership already blocks auto-forget. The only edge case (leaving the home group while
  keeping rippled ones) is covered by the B2 leave behaviour.
- **Opt-out posters (no-email, home `emailfrequency=0`) keep `0`:** they are still auto-joined
  (mods expect posters to be members) but their rippled memberships preserve `emailfrequency=0`,
  so they receive no ongoing group-feed mail. They still get the single one-off intro notice
  (the intro is a transactional notice about their own post, sent regardless of digest opt-out).
  Only `emailfrequency=-1` (immediate) is downgraded to `24` (daily); all other home values are
  preserved verbatim.

## 4. Schema changes (all instant-add)

New migrations in `iznik-batch/database/migrations/`:

1. `memberships.rippled TINYINT(1) NOT NULL DEFAULT 0` - marks a ripple-added membership.
   Consumed by NewsfeedDigest + Birthday suppression and the backfill command.
2. `memberships_history.rippled TINYINT(1) NOT NULL DEFAULT 0` - lets
   `MembershipsProcessingService` suppress the per-group welcome for rippled joins without
   depending on the (possibly-deleted) membership row.
3. `rippling_reach.ripple_intro_sent TINYINT(1) NOT NULL DEFAULT 0` - one intro email per post.
4. Composite index `logs(user, groupid, type, subtype)` - makes the `Group/Left` guard a
   point-lookup.

Go reads memberships via an explicit-column struct (`MembershipTable`), so adding a column it
does not select is safe; verify no `SELECT *` scan path breaks during implementation.

## 5. Component design

All file references are on the PR #855 branch.

### 5.1 Membership creation - `ExpandService::addPosterMembershipToRippledGroups`

- Set `rippled=1`. **Copy** `eventsallowed` / `volunteeringallowed` from the home group
  (unchanged from current PR #855). For `emailfrequency`, copy the home value but **downgrade
  only immediate to daily**: `emailfrequency = (home == -1) ? 24 : home`. So a no-email home
  setting (`0`) is preserved as `0`, a daily home (`24`) stays `24`, and only immediate (`-1`)
  becomes daily (`24`).
- Add a guard so a poster who has left a group is never re-joined:
  `AND NOT EXISTS (SELECT 1 FROM logs l WHERE l.user=? AND l.groupid=g.id AND l.type='Group' AND l.subtype='Left')`.
  This respects self-leave, mod-removal, ban, and partner-removal (all write `Group/Left`).
- Write `memberships_history` with `rippled=1` (still `processingrequired=1` so abuse
  detection runs; only the welcome is suppressed downstream).
- Keep the `Group/Joined`, `text='Rippled'` log (needed for provenance and the C1 exclusion).
- After the loop, if at least one membership was added for this post and
  `rippling_reach.ripple_intro_sent=0`, set it to 1 and spool one `RippleIntroMail` to the
  poster (sent to all posters including `None` opt-outs).

### 5.2 Leave also pulls the post - `rippleIntoNewGroups` + reconciliation

- `rippleIntoNewGroups`: add the same `NOT EXISTS Group/Left` guard to the
  `messages_groups` INSERT, so the post is never (re-)rippled into a group the poster left.
- New reconciliation step (Laravel-side, in `ExpandService`, run each expand tick), removing
  the post from groups the poster has left:
  ```sql
  UPDATE messages_groups mg
    JOIN messages m ON m.id = mg.msgid
    JOIN logs l ON l.user = m.fromuser AND l.groupid = mg.groupid
                 AND l.type='Group' AND l.subtype='Left'
  SET mg.deleted = 1
  WHERE mg.rippled_in = 1 AND mg.deleted = 0
  ```
  Soft-delete (`deleted=1`) matches the codebase pattern (feeds/digests filter `deleted=0`),
  is auditable, and (together with the guard) is idempotent. The home-group row
  (`rippled_in=0`) is never touched. Implementation may bound the scan to recent `Group/Left`
  logs for efficiency; correctness does not require it.
- **Log the removal.** Each post pulled from a group writes a `logs` row for audit:
  `type='Message'`, `subtype='Deleted'`, `msgid`, `groupid`, `user=poster`, `byuser=NULL`,
  `text='Rippling: removed on leave'`. One row per (msgid, group) removed; do not re-log a row
  already soft-deleted (so re-runs stay idempotent).

### 5.3 Welcome -> bundled intro email

- `MembershipsProcessingService::processEntry`: guard the `GroupWelcomeMail` spool with
  `&& !($entry->rippled ?? false)` so rippled joins send no per-group welcome.
- New `App\Mail\Ripple\RippleIntroMail` (extends `MjmlMailable`) + MJML/text templates.
  Copy must explain, in plain language:
  - **Lead reassurance (top)** - "there's nothing you need to do"; this just helps the post reach
    the right person.
  - **What happened & why** - the post is reaching nearby communities the user is not in
    (so more people see it), and we added them so it behaves like a normal post.
  - **What we set up** - daily digest for these communities; community events/volunteering left at
    their normal setting.
  - **What they can change & how** - adjust email frequency per community in Settings; leave any
    community (the post is pulled from it and they are not re-added).
  - **Each community's own welcome message** - the per-group `groups.welcomemail` text for the
    rippled-into communities the poster joined, bundled into this one email (gathered at send
    time in `ExpandService::maybeSendRippleIntro` and passed as `welcomeGroups`). This replaces
    the suppressed per-group welcomes so the poster still sees what each community wanted to say,
    without a welcome email per community. Because step-70 front-loads ~70% of reach into the
    first tick, the one intro (sent at first join) carries the bulk of the welcome texts.
- **Copy-review checkpoint:** render a sample into Mailpit (the batch `mail:test` path) and get
  Edward's sign-off on the wording before the PR is finalized. Iterate on copy in Mailpit.

### 5.4 Birthday suppression via `memberships.rippled`

- `BirthdayService` (members fetch): add `->where('memberships.rippled', 0)` so a rippled
  membership never causes a birthday email "from" a group the user never knowingly joined.
- Events/volunteering need no change (copied-from-home `eventsallowed`/`volunteeringallowed`
  already gate them via `BuildsUserRoundups::eligibleUsers`).
- Newsfeed-chitchat digest needs no change: it is unified per user and governed by the
  account-level `notificationmails` setting, which a rippled membership does not affect.

### 5.5 Spam + data fixes

- C1 - `MembershipsProcessingService::checkSeenOnManyGroups`: add
  `AND (logs.text IS NULL OR logs.text != 'Rippled')` to the `Group/Joined` count so
  ripple-joins do not trip the multi-group spam flag.
- D1 - `UserDataExportService::getMessages`: dedup so a rippled post appears once
  (one row per `msgid`, keeping the origin/home group context) rather than once per group.

## 6. Backfill artisan command

New command (e.g. `ripple:backfill-memberships`) to retroactively apply this behaviour to
members already auto-joined before these changes (identified by their `Group/Joined`
`text='Rippled'` logs, since `memberships.rippled` is unset on pre-existing rows).

- **Per user**, idempotent. Flags: `--user=ID` (scope to one), `--limit=N`, `--send`.
- **Dry-run by default**: with no flags it reports what it would do (counts, sample users) and
  changes nothing. DB updates *and* intro emails happen only with `--send`.
- On `--send`, per rippled user: set `memberships.rippled=1` on their rippled memberships and
  apply the §5.1 email-frequency policy (downgrade only immediate `-1` -> `24`; preserve all
  other values, including `0`), and send the `RippleIntroMail` once (tracked via
  `rippling_reach.ripple_intro_sent`, or an equivalent per-user guard, so re-runs never
  re-send). Already-sent welcomes cannot be unsent; the backfill only normalizes going forward.

## 7. Member / moderator documentation

Promote the existing `plans/rippling-out-rollout/CHANGES-FOR-MEMBERS.md` and
`CHANGES-FOR-MODERATORS.md` to **two top-level files** and make them current:

- `RIPPLING-OUT-FOR-MEMBERS.md`
- `RIPPLING-OUT-FOR-MODERATORS.md`

They must reflect current behaviour: auto-join on ripple, the daily-digest default, the intro
email, events/volunteering left as-is, leaving a community pulls the post and prevents re-add,
and (for mods) that posters appear as members, what the `text='Rippled'` provenance means, and
that membercount/stats may include rippled members. The `plans/` copies become historical.

## 8. Testing

- `ExpandServiceTest`: emailfrequency=24 + rippled=1 + events/vol copied; `Group/Left` guard
  blocks re-join and blocks re-ripple; reconciliation soft-deletes the post in a left group;
  intro email spooled once per post; intro sent to a `None` poster.
- `MembershipsProcessingServiceTest`: welcome suppressed when `memberships_history.rippled=1`;
  `checkSeenOnManyGroups` ignores `text='Rippled'` joins.
- `NewsfeedDigestServiceTest`, `BirthdayServiceTest`: rippled memberships excluded.
- `UserDataExportService` test: rippled post appears once.
- `RippleIntroMail` test: renders; correct recipient/subject.
- Backfill command test: dry-run changes nothing; `--send` sets flags, normalizes frequency,
  sends one intro, and is idempotent on re-run.
- Run the full relevant suites locally (Laravel + Go) before pushing, per repo rules.

## 9. Out of scope (explicit no-ops)

A3, A5b (stories/newsletter/engage), B1, B3, B4, C2, C3, D2, D3 - confirmed acceptable, no
change. The "don't auto-join" option is rejected (mods expect posters to be members). No
"first-class rippled membership type" - the only marker is a single internal `rippled` boolean.
