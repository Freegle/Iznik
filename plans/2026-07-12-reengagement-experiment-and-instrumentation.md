# Re-engagement: experiment, journey segmentation & effectiveness instrumentation

Builds on `2026-06-14-reengagement-emails.md` (the 3-stage nearby → impact → preferences
sequence). Adds the ability to **experiment between copy variants on a subset of users with a
real control holdout**, **tailor by the user's first-action journey**, and **graph
effectiveness in the sysadmin dashboard**. Still fully dark by default.

## Adversarial + UI review outcome (see PR #754 review comment)

Fixed here:
- **List-Unsubscribe blocker** — `ReengageMail` now emits the RFC 8058 header with the *keyed*
  one-click URL (`/one-click-unsubscribe/{id}/{key}`), not the session-only `/unsubscribe/{id}`
  that silently no-ops for a mail-client one-click POST.
- **`relevantallowed` opt-out** now honoured in candidate selection (parity with `engage`).
- **Plain-text escaping** — user content and URLs in the text parts use `{!! !!}` (were `{{ }}`,
  which mangled apostrophes/ampersands and would break `&`-carrying tracked URLs).
- **Subject singular** — "1 free thing" not "1 free things".
- **Perf** — candidate query `select('id')` only; `lastaccess` index recommended (below).
- **Engagement opt-out** scoped to Approved memberships (a stale row can't override a `0`).

Still **go-live prerequisites** (not code-fixable here):
1. **Exclude in-reengage-sequence users from `mail:engage`** (its Inactive mailer fires from day 14
   weekly with no upper bound → double-mails the same lapse) **and from the unified digest**.
2. Add a `lastaccess` index (or `(deleted, lastaccess)`) before opening the allowlist wide.
3. Product/privacy sign-off on the stage-2 "impact" collage (third-party names/avatars to lapsed users).

## Experiment layer

Config-driven, deterministic, dark by default (`config/freegle.php` → `reengage.experiment`):

- **Stable assignment**: `ExperimentBucket::bucket($userId, $experiment)` = `crc32(userId|experiment) % 100`
  (the CRC32 choice `UnifiedDigestService` already uses; salted by experiment name so experiments
  don't correlate). Held constant across all three stages so a user keeps the same arm.
- **Arms** (`control`/`a`/`b`, config ratios over 0-99): **`control` is a true holdout** — a
  `reengage` row is recorded (so it progresses through the stages and is provably drawn from the
  same eligible pool) but **no mail is sent**, making treatment lift measurable. Fixed ratios, not
  a bandit — a bandit would erode the holdout.
- **Limited pilot**: two independent knobs both dark by default — `FREEGLE_REENGAGE_ALLOWLIST`
  (who is eligible at all, unchanged) and `FREEGLE_REENGAGE_EXPERIMENT_ROLLOUT_PCT` (0→10→50→100,
  what fraction of eligible users enter the experiment). `rollout_pct=0` = today's exact behaviour
  (everyone eligible gets arm `a`, no holdout).
- **Variants**: arm `b` currently ships a different **subject line** per stage (a classic, high-signal
  A/B), plus segment-aware framing. Full body variants drop in as `nearby-b`/… templates pointed at
  by `STAGE_TEMPLATE` — the plumbing (assignment, recording, graphs) already supports N arms.

## Journey segmentation

Each send records the user's **first action when they joined** — `offer` / `wanted` / `replier` /
`other` — from two cheap indexed queries (`messages.type` first Offer/Wanted vs first `chat_messages`).
Recorded per row so copy and effectiveness can be cut by it; the subject framing already varies by segment.

## Effectiveness instrumentation → sysadmin graph

- `ReengageMail` now `use`s `TrackableEmail`: an **open pixel** and **tracked CTA** land opens/clicks in
  the shared `email_tracking` table; the per-send `email_tracking_id` is stored on the `reengage` row.
- **Real outcome**, not inferred: `mail:reengage-outcomes` (daily 16:30) writes `reengaged_at` /
  `reengaged_via` (`login`/`reply`/`post`) and `outcome='Reengaged'` from the precise `logs`/`messages`
  event trail within `outcome_window_days` — for treatment **and** control rows, so lift is real.
- New columns (migration `2026_07_12_000000_add_experiment_and_tracking_to_reengage_table`):
  `experiment, arm, bucket, segment, email_tracking_id, reengaged_at, reengaged_via`.
- **Sysadmin dashboard**: new **Reengagement** tab (`ModSysAdminReengageEffectiveness.vue`) backed by
  `GET /modtools/email/stats/reengage` (`emailtracking.ReengageEffectiveness`): the sent → opened →
  clicked → reengaged funnel, broken down by stage, by arm (with **lift vs control** highlighted), and
  by journey segment, over a date range.

## Files

- Batch: `app/Support/ExperimentBucket.php`, `app/Services/ReengageService.php`,
  `app/Mail/Reengage/ReengageMail.php`, `app/Console/Commands/Mail/UpdateReengageOutcomesCommand.php`,
  `config/freegle.php`, `routes/console.php`, the 6 reengage templates, migration above,
  `tests/Unit/Services/ReengageExperimentTest.php`.
- API: `iznik-server-go/emailtracking/emailtracking.go`, `router/routes.go`.
- Frontend: `iznik-nuxt3/api/EmailTrackingAPI.js`, `modtools/stores/emailtracking.js`,
  `modtools/components/ModSysAdminReengageEffectiveness.vue`, `modtools/pages/sysadmin/index.vue`.

## Rollout

Merge (dark) → set a small `FREEGLE_REENGAGE_ALLOWLIST` pilot → after the go-live prerequisites above,
`FREEGLE_REENGAGE_EXPERIMENT_ROLLOUT_PCT=10` → watch the Reengagement tab (control-lift, unsubscribe/
complaint rates) → ramp.
