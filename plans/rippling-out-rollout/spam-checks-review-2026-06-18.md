# Spam checks vs rippling-out - review (2026-06-18)

37-agent workflow inventoried **96 spam/suspect checks** across iznik-server (V1), iznik-batch,
and iznik-server-go, classified content vs behavioral, and assessed the **32 behavioral/rate-limit**
ones against rippling-out. Raw output was ephemeral (/tmp); this is the synthesis.

## 1. Is there a frequent-location-change check? NO.

There is no automated spam/suspect check for frequent location changes anywhere in the three
codebases. The only location-change code WRITES A LOG entry (`SUBTYPE_POSTCODECHANGE`,
`iznik-server/include/misc/Log.php:62`, `iznik-server-go/user/user.go:2039`) and updates
`users.lastlocation` - neither counts velocity or raises a flag. `checkReplyDistance` does not
substitute (it measures the spread of posts a user REPLIED to, not their location velocity, and a
spammer who posts-once-per-location and never replies evades it). So this is a real, unguarded gap.

## 2. The single most important NEW signal: location-change velocity

> **IMPLEMENTED in PR #816** (`feature/rippling-location-velocity`, CI green). `CheckLocationChangeVelocity`
> in `iznik-server-go/user/user.go` runs at the PostcodeChange log point in `ProcessSettingsUpdate`. It
> counts DISTINCT postcodes set in 24h; at `>= RapidLocationChangeThreshold` (4) it flags the user's
> memberships for moderator review (`memberships.reviewrequestedat`/`reviewreason`) and writes a `Suspect`
> log. Mods/owners exempt. The chosen surface is the existing member-review flag (non-destructive, already
> in mod tooling) rather than the `newsfeedmodstatus=Suppressed` + `spam_users` PendingAdd originally
> sketched below; it does not shadow-ban or block the move, matching the rollout's review-not-punish stance.

Under the old model reach was bounded by group memberships (visible to mods). Under rippling, reach
is bounded by DECLARED LOCATION, which a user changes at will with zero friction. Set location to
Glasgow, post spam, move to Bristol, move to Leeds - three separated audiences from one account with
no group-join fingerprint. Nothing guards this today.

Recommendation: at the `settings.mylocation` write path (`iznik-server-go/user/user.go` ~2039, where
PostcodeChange is logged), count distinct postcode IDs in `logs` for the user in the last 24h; if >= 3,
set `newsfeedmodstatus = Suppressed` (shadow-ban new posts pending review) + a `spam_users` PendingAdd,
and log a distinct `RapidPostcodeChange` subtype for mod tooling. Non-destructive (mirrors the chat-spam
philosophy) - do NOT hard-block the location change itself, just gate the reach amplification.

## 3. Recommendations (nothing should be fully RETIRED - each check still guards something)

### RELAX
- **"Seen on many groups"** (`Spam.php:522`, `SEEN_THRESHOLD=16`): join count no longer multiplies reach
  (#10 single-group posting; reach is the location isochrone). Raise to ~35-40, or reframe to flag groups
  whose centroid is far from the user's stated location (a location/spread mismatch is the remaining signal).
  16 is now a false-positive factory for power users with many local groups.
- **`activedistance`** (Go mod-info display, `user.go:1277`): "groups 150 miles apart" was a reach signal;
  under rippling it misleads mods. Repurpose the same bounding-box UI to show location-change spread instead.

### TIGHTEN
- **`checkReplyDistance` / `replyambit`** (`Spam.php:1021`): legitimate reply radius is now capped by the
  ~30-min isochrone, so 100 miles is too permissive. Tighten to ~50 miles (keep the per-group
  `settings.spammers.replydistance` override for rural groups). Still catches mass-reply scammers, and
  matters because the email/TN held-reply and API-direct paths sit outside the in-app reply gate.
- **Unbounded IP / subject checks** (`Spam.php:140/154/167`, `SpamCheckService.php:446/499`): add a 90-day
  window (they currently accumulate years of legitimate NAT/subject history) and lower the group/subject
  thresholds (single-group posting makes a high distinct-group count a much weaker signal).
- **PostcodeChange log** (`user.go:2039`): add the velocity gate from section 2.

### REPURPOSE
- **IP -> too many distinct groups** (`SpamCheckService.php:473`): under single-group posting, a high
  per-IP group count is now a "many users behind one NAT" artefact, not "one actor, many groups". Decompose
  by `fromuser`: flag when a single `fromuser` reaches many groups from one IP (the location-hopping vector),
  not the raw per-IP count.

### KEEP AS-IS
All content checks, known-spammer-list / shared-IP checks (`Spam.php:1097`), IP country block, bulk
volunteer-address mail, chat image-hash rate limits, duplicate-chat-message flood, HAProxy per-user rate
limiting, ban / member-review / reviewrequired / Suppressed flags. None is made redundant by rippling.

Cleanup noted: `SpamCheckService.php:905` chat-image-hash appears to have no production caller and a
threshold mismatch (5 vs 20 in `IncomingMailService.php:2173`) - wire it up or delete + canonicalize.

## Note on reply-eligibility enforcement
Several assessments assumed reply-eligibility is UI-only. Fix #5 (branch `feature/rippling-browse`) added a
server-side `403 not_in_reach` on the in-app reply write path, so the in-app path IS gated; the held-reply
email/TN path and any other API paths remain the reason to keep `checkReplyDistance` as defence in depth.
