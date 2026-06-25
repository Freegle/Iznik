# Auto-Approve x Rippling: Earned-Reach Review Gate - Design

> Status: design, for review. Extends PR #639 (post-moderation) to be safe under
> live rippling-out. Mod-facing summary: `AUTO-APPROVE-FOR-MODERATORS.md`.
> Background + interaction review: memory `finding_pr639_stale_vs_rippling.md`,
> workflow `w9jtr672a`.

## Problem

Pre-#639, every NULL-status member's post was human-approved before it could enter
`messages_spatial` and therefore before it could ripple. #639 auto-publishes clean posts
(~20 min, `collection=Approved`, `approvedby=NULL`), so an **unreviewed** post becomes
ripple-eligible ~26 min after arrival and can spread network-wide before any human looks.
The C4/C9/D1 guards (already merged + CI-green) stop rippled-in rows polluting the
oversight queues, but they do not address the core issue: **reach currently runs ahead of
review**.

## Principle: reach is earned by review

Auto-published posts spread only as far as their review supports. Well-reviewed posts go
fast and far; unreviewed ones stay local. Review comes from two pools (microvolunteers and
moderators) feeding one window.

### The numbers (agreed)

- **Review weight**: microvolunteer approve = **1**; moderator check = **2**.
- **Required weight to be rippled into N communities = 2 x N.** (2 new communities => 4
  microvol approvals, or 2 mod checks, or any mix to weight 4.)
- **Reject is easy and does NOT scale**: 2 microvol rejects, or 1 mod reject, on any copy
  halts expansion and withdraws that copy.
- **~1h hold** before the first ripple (origin reviewers get first look).
- **Capped reach (b1)**: if weight does not keep up, the post simply stops expanding. It is
  not delayed-then-released; it stays at its current reach. Already-reached copies remain
  until a reject withdraws them.

### Threshold: global, scaled by N (not strictly per-group-local)

"Per rippled-out group" sets the **threshold** (2 x N); the **sources** are pooled across
the post's max ripple area (not tied to one receiving group). So the gate is:

> cumulative review weight for the post >= 2 x (number of communities it has rippled into),
> where any in-area microvolunteer approve counts 1 and any in-area moderator check counts 2.

Both pools share the same in-area catchment and closeness ordering (see Reviewer pools), so
"scaled per group" governs how much review is needed while the area bound governs who may
supply it. A strictly per-group "2 of *this* group's own microvols" model is not used.

## Reviewer pools

**Both pools are drawn from within the post's maximum ripple area, ordered by closeness to
the post.** Same catchment, same ordering - we ask the people the post can actually reach,
nearest first.

- **Microvolunteers** - eligible if they are inside the post's max ripple area; the
  CheckMessage challenge selection (`getApprovedMessageChallenge`, Go) is ordered by
  distance from the member to the post (nearest first). Existing eligibility (not own post,
  trust gates, one-vote-per-(user,msgid)) is unchanged; we add the max-ripple-area bound +
  closeness ordering and drop the implicit own-group-only restriction.
- **Moderators** - a mod check counts toward weight only if it comes from a community
  inside the post's max ripple area. In practice a mod check is recorded
  (`messages_groups.checkedat`) against the origin row or a rippled-in row, and those
  communities are within reach by construction.

The closeness ordering is selection/surfacing only; any in-area approve/check still counts
the same weight (microvol = 1, mod = 2) wherever in the area it comes from.

## Components

1. **Ratification weight (read model).** A function, given `msgid`, returns current weight:
   `(# microactions WHERE msgid=? AND result='Approve')` + `2 * (# in-area
   messages_groups rows for the msgid WHERE checkedat IS NOT NULL)`. Lives in the batch
   ripple path (PHP), mirrored in Go only if the countdown/UI needs to show it.

2. **ExpandService expansion gate (PHP).** Two changes in
   `iznik-batch/app/Services/Ripple/ExpandService.php`:
   - **Hold**: do not `initialiseNew()` a post until `arrival <= NOW() - INTERVAL 1 HOUR`
     OR it has a mod check (`checkedat`). (Auto-published posts only; mod-approved/manually
     approved posts are unaffected because they carry `approvedby`/`checkedat`.)
   - **Earned-reach cap**: before rippling into the next community(ies) (the
     `rippleIntoNewGroups` / reach-advance step), require `weight(msgid) >= 2 *
     (currentRippledGroups + newGroupsThisStep)`. If not met, **pause** this post's
     expansion this run (leave reach + existing rippled-in rows untouched); it resumes a
     later run once weight catches up.

3. **Reject action (Go + ModTools).** `iznik-server-go/message/markchecked.go`: add a
   `Reject` path to the oversight endpoint (`collection='Pending'`, set `heldby`,
   `checkedat=NULL`) scoped to the mod's group; ModTools Checked/Trusted pages get a Reject
   control alongside "Mark as checked". A mod reject on the origin copy halts ripple (the
   post leaves `messages_spatial`, so ExpandService stops); on a rippled-in copy it trims
   that community (mirrors the existing rippled-in secondary-reject behaviour).

4. **Microvolunteer reject quorum (existing).** 2 microvol rejects already call
   `sendForReview()` (Approved->Pending on that group). For the origin copy this removes it
   from `messages_spatial` and so halts ripple - reuse as-is; just confirm ExpandService
   halts cleanly when the origin row is no longer Approved.

5. **Review-gate observability (the delay we are imposing must be visible).** Capping reach
   on under-review silently slows rippling, so we instrument and surface it:
   - **Instrument**: when the gate pauses a post (weight < 2N), stamp the start of the
     await-review hold; clear it when the post resumes (weight caught up) or terminates
     (rippled to its full extent / withdrawn / capped-out). Store on `rippling_reach`
     (e.g. `awaiting_review_since` nullable + `awaiting_review_seconds` accumulator), so a
     post that pauses/resumes repeatedly accrues total delay.
   - **Surface on the SysAdmin rippling page** (`ModSysAdminRippling.vue` <-
     `iznik-server-go/.../rippling/metrics.go`, the existing dashboard with the reply/reach
     KPIs). New tiles/series over the page's date range:
     - **Posts currently awaiting review** (count) and **median time awaiting review**.
     - **Reach delayed by review** - total await-review post-hours over the range (how much
       rippling the review gate is holding back).
     - **Capped-out posts** - posts that ended below their reachable extent purely because
       review never arrived (the reach we are forgoing for safety), vs those that resumed.
   This is the dial that tells us whether the review pools can keep up, or whether the
   `2 x N` weighting / 1h hold needs tuning.

## Data / flow

- Weight is derived from `microactions` + `messages_groups.checkedat` (no new tally table
  for v1; add a cached `messages.ratify_weight` only if the per-minute loop proves hot).
- The await-review instrumentation **does** need persisted state on `rippling_reach`
  (`awaiting_review_since`, `awaiting_review_seconds`) so the dashboard can report delay
  without reconstructing it from logs. Additive migration.
- `ripple:expand` already runs every minute and is the natural place to re-evaluate the
  gate and update the await-review accumulator, so paused posts resume automatically as
  weight accrues and the delay metric stays current.

## Testing

- **PHP (ExpandService)**: hold (no ripple < 1h, ripples >= 1h or with a check); cap (N=1
  needs weight 2, N=2 needs weight 4; weight from microvol approves vs mod checks vs mix;
  paused post resumes when weight catches up; reject/halt). 
- **Go**: markchecked Reject path (collection/heldby/scope/403s); challenge selection
  closeness ordering + network-wide pool; mod-check-in-area weighting boundary;
  `metrics.go` await-review fields (currently-awaiting count, median wait, delayed
  post-hours, capped-out vs resumed) over a date range with exact counts.
- **PHP**: await-review accumulator (pause stamps `awaiting_review_since`, resume adds to
  `awaiting_review_seconds`, repeated pause/resume accrues correctly).
- **Vitest**: Reject control on Checked/Trusted pages; the new await-review tiles/series on
  `ModSysAdminRippling.vue`.

## Out of scope (sibling spec)

Chat "rippled-out review": extend `widerchatreview` (already UNIONs flagged chats across
opted-in groups, `chatmessage.go:955-1021`, currently single-mod/no-quorum) with the same
weighting + quorum + active widening. Separate spec + PR after this lands.

## Decisions (defaults taken; veto any on review)

1. **Capped reach (B1)** - confirmed. No quorum => the post stops expanding and stays local;
   not delayed-then-released. The forgone reach is measured (component 5) so we can see the
   safety/reach trade-off.
2. **Global threshold scaled by N** - `weight >= 2N`. Both pools (microvols + mods) are
   drawn from within the post's max ripple area and surfaced **ordered by closeness**; any
   in-area approve/check counts its weight (microvol 1, mod 2) regardless of where in the
   area it came from. The strictly per-group-local model is not used.
3. **In-area catchment** - "max ripple area" is the post's full reachable polygon. A mod
   check is counted via `messages_groups.checkedat` on the post's own rows (origin /
   rippled-in), which are in-area by construction; microvol challenge selection is bounded
   to the same polygon and distance-ordered.
4. **Hold exemption** - a manually/mod-approved post (`approvedby` set) skips the 1h hold
   and ripples immediately; the hold and the earned-reach cap apply only to auto-published
   posts (`approvedby IS NULL`).

Anything to change here is cheap to revise before build; otherwise these stand.
