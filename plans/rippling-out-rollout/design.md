# Rippling Out — Browse, Reach Engine, Replies, Digest & Moderation

**Date**: 2026-06-16
**Status**: Design — under review (brainstorming output, pre-implementation)
**Base**: all work branches off `origin/master`
**Delivery**: multiple PRs split by deployability; each UI PR carries screenshots; nothing pushed/merged until approved (humans merge)

---

## 1. Purpose

The spatial/routing server is now on master, which unlocks "rippling out": a post becomes
available to people gradually, starting nearest the item and widening by drive-time over
hours/days. This document designs the consumer- and moderator-facing changes that ride on
top of rippling.

The governing principle (from `plans/ripple-thresholds/design.md` and
`plans/future/Rippling Out.md`):

> "Who sees a post from here" and "what I see here" are the **same reach** seen from each
> end. Rippling decides which posts are *available*; the browse view / digest decide which
> posts are *shown* and *in what order*.

## 2. Sub-project map & dependencies

| # | Sub-project | Depends on |
|---|---|---|
| **0** | **Reach engine** — background process that maintains, per active post, its current reach polygon and drives expansion | routing-go (`/v1/fairness`, `/v1/ripple-schedule`) |
| 1 | Browse UI — filter model, ordering, per-filter map polygon | #0 |
| 2 | Reply-eligibility — view-only/blocked state (UI + API) | #0 |
| 3 | Held external replies — email/TN hold & release | #0 |
| 4 | `/rippling` help modal + reusable `RipplingExplanation` | — (done, local) |
| 5 | Unified Digest ordering — same order as browse | #0 |
| 6 | Ripple-into-new-group moderation + mod banner | #0, multi-group (built) |
| 7 | Post reach/visibility map (mod views) | #0, #4 |
| 8 | Member FAQ "Which posts do I see" | — |

**Keystone:** #1/#2/#3/#5/#6/#7 all consume one server-side test — *is this viewer inside
this post's current reach?* — which #0 provides.

## 3. #0 — Reach engine (foundational)

### Why a background process
Immediate mails ("an item just became available near you") cannot be computed on a page
load — they must be emitted when a post's reach expands. Since the process runs anyway, it
**persists where each post is visible**, so browse/digest/reply-eligibility simply read it
instead of recomputing per request. This is "hang the `/rippling` simulation onto all active
posts."

### Scope
Reach is a per-**message** attribute, needed only while a message is in `messages_spatial`
(approved, active, not-taken — the browsable set). When a message leaves that set, its reach
stops and its row is removed. This bounds the workload to the in-flight set (small relative
to `chat_messages` / `messages`).

### Data model — `messages_reach`
Keyed by **msgid** (one reach per message, from its physical origin):

| Column | Notes |
|---|---|
| `msgid` (PK) | the message |
| `polygon` | current smoothed reach (geometry/WKT) for `ST_Contains` |
| `tick` | schedule cursor (which expansion step) |
| `next_expansion_at` | when the next tick is due |
| `status` | `expanding` / `stopped` |
| `mode`, `fairness` | params used (drive default; fairness weight) |
| `updated` | last expansion time |

A companion **notified ledger** (per msgid) records which members have already been mailed,
so expansions never re-notify.

### The expander
A Laravel scheduled command **`ripple:expand`**, every minute, gated to active hours
(6am–11pm), mirroring `messages:contentcheck` / `messages:auto-approve`. Per due message:

1. Call the **already-built** routing logic — `handleRippleSchedule` (density curve, default
   `step-70`: 70% of reachable freeglers at tick 1 nearest, the rest spread across the
   hazard ticks h1/h3/h6/h12/h24/h48…) and `/v1/fairness` for the smoothed polygon.
2. Compute the **delta** of newly-included freeglers vs the notified ledger.
3. Persist the new polygon + advance `tick` + schedule `next_expansion_at`.
4. Enqueue **immediate mails** to newly-reached members on the *Full* (immediate) setting.
5. If reach crosses into a new group → insert a `messages_groups` row with
   `collection='Pending'`, `arrival=NOW()` (see #6). **Cross-posting is always on and is
   not per-group configurable.**
6. Apply stop conditions: enough replies / max reach (group-boundary ceiling) / taken /
   withdrawn → `status='stopped'`.

### Reach test (used everywhere)
`ST_Contains(messages_reach.polygon, <viewer point>)` — this **flips** today's browse test
(today: *your* isochrone contains the post point) to *the post's grown reach contains you*.

### Smoothing
The polygon comes from `/v1/fairness` (server marching-squares + `removeCollinear`); the
smooth edges are produced by **client-side Chaikin** (3 iterations, as in
`RipplingExplorer.vue:553`). The browse map and #7 reuse the same Chaikin step so the edges
match the explorer.

### Deployability
Stage 1 (reach calculation + persistence) is **dark** — no user-visible change — and can
deploy well ahead of any front-end. Immediate mails (step 4) are a later, flagged stage.

## 4. #1 — Browse UI

- **Filter "Show" selector** keeps three states; **the label stays "Nearby"** for users
  (it is simply fed by the adaptive reach now). "All my communities" and single-group
  unchanged. Internally maps to the existing `me.settings.browseView`.
- **Travel-time slider + transport selector removed** — reach is solved automatically.
- **Ordering** (one rule, three user-facing options; default is the smart one):
  - **Default "New to you":** unseen *and* newly-visible-to-you posts first, then the
    `/rippling` order **R**; once seen, a post falls back into R.
  - **"Newest posted":** pure recency.
  - **"Nearby":** nearest-first.
  - **R** = the digest scorer (`iznik-routing-go/digest_simulator.go::scoreDigestPost`),
    using the **weights currently used on the `/rippling` page** (closeness 1.0 / freshness
    0.5 / budget 1.0 / anchor 0), descending.
  - **R is not a separate dropdown entry** — it is the order the default falls back to. The
    user-facing sort stays the three options above; no extra "rippling order" control.
- **Post-list source per filter:**
  - *Nearby* → posts in `messages_spatial` whose `messages_reach.polygon` contains **any of
    the viewer's defined locations**. Viewers may have **multiple locations** on the browse
    page; the reach test (here, in #2 reply-eligibility, and in #5 digest selection) must
    consider **all of them** — a post qualifies if its reach contains *any* viewer location.
  - *My communities* → all membership posts (superset; some are view-only — see #2).
  - *Single group* → that group's posts.
- **Map polygon adapts to the filter** (always visualising exactly the reach producing the
  list):
  - *Nearby* → the smooth reach polygon (Chaikin-smoothed `/v1/fairness`).
  - *My communities* → each membership group's **own area** drawn as a **separate** shape
    (memberships are non-contiguous — do not merge).
  - *Single group* → that one group's area.
  - Map re-renders on every filter change.
- **Multiple locations** preserved and fully handled — the reach test is evaluated against
  every one of the viewer's locations (post qualifies if *any* covers it). The map draws the
  reach for each location.

## 5. #2 — Reply-eligibility

- **Rule:** a post is **reply-eligible** for you **iff you are inside its current reach**
  (i.e. it would also appear under "Nearby"). No membership override.
- Under *My communities* / single-group you also see membership posts **outside** your reach;
  those render **view-only** — reply blocked — with a friendly message:
  *"We're showing this to people closest to it first — you'll be able to reply once it
  reaches your area,"* linking the **#8 member FAQ**.
- **Enforced in both the UI and the API**, so the block cannot be bypassed. The API uses the
  same `ST_Contains` reach test (against **all** the viewer's locations).
- **Metric:** count how often a reply is blocked by reach (per-block event), surfaced in
  sysadmin (§15), so we can see how often members hit this new limitation.

## 6. #3 — Held external replies

In-app replies can't arrive early (#2 blocks them in UI + API). Email and TrashNothing
**bypass** that gate, so #3 enforces the same reach test for external channels.

- A reply to post **P** from location **L** outside P's current reach → **held** (not
  delivered to the poster).
- **Storage:** a **separate table named `chat_messages_rippling`** — *not* `chat_messages_held`
  (the word "hold" already means a moderator's manual hold; this is distinct) and *not* a flag
  on the large `chat_messages` table. Bounded by the in-flight (`messages_spatial`) set.
  Columns: id, `chatid`, `chatmsgid`, `msgid` (P), `replieruserid`, replier `lat`/`lng`,
  `created`, `releasedat`, `status` (`held`/`released`/`dropped`/`taken-gone`). Indexed by
  `msgid` (release on tick) and `chatid` (mod display).
- **Release** runs inside the ripple engine: each tick, after expanding P's reach, release
  any held replies whose **L** now falls inside the new polygon (`ST_Contains`), in **FIFO**
  order → clear hold + notify the poster (reuse existing chat plumbing).
- **Subsequent chat messages** in a held chat are held **using the existing hold mechanism**.
- **Moderator visibility:** in ModTools / Chat Review a held chat shows **why** it's held
  (rippling-reach hold), **distinct** from a moderator's own hold. **Members never see this
  reason.**
- **Terminal cases:** P **taken/withdrawn before** L is covered → do **not** deliver; tell
  the replier it's already gone. Reach **maxes out** (group boundary) still not covering L →
  **release anyway** so genuine interest isn't stranded.
- **Stats:** counts of held / released / dropped / taken-gone replies, surfaced in **sysadmin**
  (§15) so we can monitor how often external replies are being held and for how long.

## 7. #4 — Help modal & reusable explanation (done, local)

- `components/RipplingExplanation.vue` — reusable presentational component (general "rippling
  out" explanation; British English; uses the phrase "rippling out").
- `modtools/components/RipplingHelpModal.vue` — composes `RipplingExplanation` + the
  explorer-specific "Using the map" section; opened from a "How does this work?" link on the
  `/rippling` explorer header.
- Reused by #6 (banner "Learn more") and #7 (reach map).
- Currently on the wrong branch (`fix/pending-url-spam-collection`) amid unrelated edits;
  these 5 files (component, modal, explorer, 2 specs) are carried onto the master-based
  branch and the rest left behind.

## 8. #5 — Unified Digest ordering

- Digest post **selection** uses the same reach (posts whose `messages_reach` covers the
  recipient at send time).
- Digest **ordering** = flat **R** (the digest scorer) — **no unseen tier**, because each
  post is emailed once (the browse unseen-first overlay exists only because browse is
  revisited and would otherwise re-surface the same posts). Website and email therefore share
  one ordering rule.

## 9. #6 — Ripple-into-new-group moderation + banner

- When reach crosses into a new group, #0 inserts a `messages_groups` row
  `collection='Pending'`, `arrival=NOW()`. **Cross-posting is always on, not per-group
  configurable.**
- The **existing moderation pipeline handles it for free**: `ContentCheckService` keys off
  per-row `mg.contentcheck_checked_at`; `AutoApproveService` and the 30-min visibility guard
  key off per-row `mg.arrival`. A newly-added row is genuinely fresh-pending on the new group
  regardless of the message's age elsewhere. (One fix: the insert must **force**
  `collection='Pending'` — the existing TN-dedup path inherits source collection.)
- **Mod banner:** in `ModMessage.vue` (after the existing "Also on:" block, ~line 151), a
  `NoticeMessage variant="info"`: *"This post is starting to become available to some of your
  group members,"* with a "Learn more" link opening `RipplingExplanation`. Trigger: context
  group's row is recent while the message has older rows on other groups.

## 10. #7 — Post reach/visibility map

- A button on **any** message in mod views (pending, approved, …) — label TBD at copy time
  (candidates: "Who can see this?", "Reach", "Visible area").
- Renders the message's **live** `messages_reach.polygon` (Chaikin-smoothed, same as browse).
  For a brand-new **pending** post not yet in `messages_spatial`, shows the **prospective**
  initial catchment (computed on the fly). A post pending on a rippled-into group already has
  a real reach (active on its origin group) → shows the genuine current polygon.
- Reuses `RipplingExplanation` for the explanatory text.

## 11. #8 — Member FAQ "Which posts do I see"

Member-facing help entry in **`/help`**, simple language, flagged as a new change. Final copy:

> **Which posts do I see?**
> When someone offers something, we show it to people nearby first, then gradually ripple it
> out to people further away as time passes. This keeps freegling local — neighbours get first
> chance to collect, which means less travel and a fairer chance for everyone.
>
> Because of this, you might occasionally find you're not able to reply to a post because it
> hasn't rippled out to your area yet. As soon as it reaches you, you'll be able to reply.
> (This is a new change to how Freegle works.)

Linked from the #2 blocked-reply message.

## 12. Cross-cutting decisions

- Branch off **`origin/master`** (not local `master` HEAD, not the current branch).
- **One big feature** conceptually, **delivered as multiple PRs split by deployability**.
- **Reach infrastructure (#0) deploys first (dark); browse (#1/#2) and email/digest (#5)
  changes deploy *last*.** Mod-facing and other backend stages sit in between.
- Each UI-affecting PR includes **before/after screenshots**.
- **PRs may be pushed from now on** (run CI + adversarial review on them); **not merged** —
  humans merge. **Make inter-PR dependencies explicit** in each PR description.
- `RipplingExplanation` shared by #4/#6/#7; #6 + #7 build together (both `ModMessage` UI +
  reach polygon).
- `iznik-routing-go` / `iznik-spatial-go` are on master but absent from the current tree — a
  master-based worktree restores them.

## 13. Deployability-ordered PR plan

Ordered by deployability — reach infra first, **browse and email last**:

| PR | Contents | Stage |
|---|---|---|
| A | #0 reach calculation + `messages_reach` + `ripple:expand` (no mails) | Dark — **first** |
| B | #0 immediate mails on expansion (flagged/allowlisted, like daily-posts push) | Backend |
| C | #3 held external replies + mod chat-held reason | Backend + mod |
| D | #6 mod banner + #7 reach map (+ #4 modal, already local) | Mod UI |
| E | #1 browse UI (filter/order/map polygon) + #2 reply-eligibility + #8 member FAQ | **Consumer — last** |
| F | #5 digest ordering uses reach | **Email — last** |

Rationale: reach infra is invisible and safe to bake in early; mod-facing tooling lets
volunteers see what's coming; the **consumer browse change and the digest email change land
last**, once everything they depend on is proven. #8's FAQ ships with E because #2's
blocked-reply message links it (it must not describe behaviour that isn't live yet). #4 modal
rides with D (first PR to touch the explorer); it's already built locally.

## 14. Resolved decisions & residual risks

Resolved:
- **Cross-posting**: always on, **not** per-group configurable.
- **`R` weights**: the weights **currently used on the `/rippling` page** (closeness 1.0 /
  freshness 0.5 / budget 1.0 / anchor 0).
- **Group-area polygons** (My communities map): **CGA** boundary polygons; rendering multiple
  is fine.
- **Member FAQ location**: in **`/help`**.
- **Multiple viewer locations**: reach test checks **all** of them (post qualifies if any
  location is covered).

Residual:
- **#7 button label** — finalise at implementation (candidates: "Who can see this?", "Reach",
  "Visible area").
- Immediate-mail volume — watch via existing ripple/digest metrics + §15.

## 15. Metrics & sysadmin

Surface in sysadmin (alongside existing `ripple_algorithm_metrics`):
- **Reply-blocked-by-reach** count (#2) — how often members hit the new "can't reply yet"
  limitation.
- **Held external replies** (#3) — counts by status (held / released / dropped / taken-gone)
  and hold duration, so we can monitor external-reply holding.
- Immediate-mail volume per expansion (#0).
