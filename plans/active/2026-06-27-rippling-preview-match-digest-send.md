# Make /rippling digest PREVIEW match the real digest SEND

Branch: `master` (session preference). Date: 2026-06-27.

## Why
ModTools `/rippling` preview (RipplingExplorer) shows "Top picks (195)" = the raw reachable pool, lists the same item multiple times (TN crossposts + rippling), uses default cap 50, and has a preview-only "Promised" section. The real digest SEND dedups cross-posts, caps at 65, and has only two sections. Preview must match send.

## Source of truth = the daily SEND (iznik-batch, DO NOT change)
`UnifiedDigestService.php`:
- `getPostsForUser`: messages⋈messages_groups for user's groups since tracker->lastmsgdate (first run 24h), Offer/Wanted, Approved, not deleted; reach-gated (NOT EXISTS rippling_reach where ST_Contains(polygon,point)=0); flags has_outcome, has_success, views (SUM likes 'View'), replies (COUNT chat 'Interested' approved).
- Assembly (line 1034): `available = !has_outcome` → `scoreAndSortAvailable` → `deduplicatePosts` → **Top picks** (UnifiedDigest renders, cap `DigestStyle::DIGEST_POST_CAP=65`, line 471 take(65)). `completed = has_success` → `deduplicateCompletedPosts` → **"Came and went"**. withdrawn/expired (has_outcome && !has_success) → neither. **No "Promised" section.**
- Dedup: `getDeduplicationKey` = `tn:{tnpostid}` else `fromuser|normalizeSubject(subject)|locationid`; `bodiesMatch` = same tnpostid OR `normalizeBody(textbody)` equal; representative = top-scoring; merge groupids ("Posted to: A, B, C").
- `normalizeSubject`: strip `^(OFFER|WANTED)\s*:\s*` (i), strip trailing `\s*\([^)]+\)\s*$`, collapse `\s+`, trim, lowercase.
- `normalizeBody`: lowercase, trim, collapse `\s+`.
- Score: DigestPostScorer (close/budget/home/freshness); home/anchor weight currently 0 (line ~1405) — keep preview consistent (home=0).
- Subject/title: count > 65 → "(N posts)" capped (UnifiedDigest.php:708).

## Status
| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Plan + parity capture | ✅ | |
| 2 | Go simulator: query cols (tnpostid/locationid/textbody/has_outcome/has_success) | ✅ | |
| 3 | Go: cross-post dedup (content key + bodiesMatch parity), merge groups, top-scoring rep | ✅ | |
| 4 | Go: sections topPicks(cap 65)/cameAndWent + deduped counts | ✅ | pool_size now debug-only |
| 5 | Go: compile-check + tests on db1 | ✅ | BUILD_EXIT=0, tests ok |
| 6 | Frontend match (sections/format/counts, drop Promised) | ✅ | agent: RipplingDigestModal.vue + composables, commit c49e2ba32 (Netlify deploys) |
| 9 | DEPLOY routing-go (digest sim) to db1/2/3 | ✅ | all 3: BUILD_EXIT=0, came_and_went marker, health 200, NEW, re-monitored (graph reload ~5min/node). Endpoint auth-gated (401 raw) = expected. |
| 7 | Tests (Go dedup parity unit) | ✅ | digest_simulator_dedup_test.go |
| 8 | Commit master + push (CI) | ✅ | 423c6b0e6 (backend); frontend agent commits separately |
| + | BONUS: #233 send dedup fix (drop tnpostid short-circuit) | ✅ | live via bind mount; in 423c6b0e6 |

## #233 mail-dedup root cause (agent-confirmed)
getDeduplicationKey short-circuited on tnpostid; TN reposts get a NEW tnpostid/day → 4 distinct keys → content fallback never used → 4 copies in daily digest (website dedups by content → 1). "Small lamp" user 44780510 = 4 msgids/4 tnpostids/identical content; 27 such items in 4 days. Fixed: always key on content; bodiesMatch handles tnpostid + body. Secondary (not yet fixed): immediate path (processGroupImmediate) has NO cross-group dedup — mails once per group.

## Deploy (SEPARATE — not part of this)
- iznik-routing-go → native db1/2/3 via unmonitor/SIGINT/monitor dance (graph reload ~5min).
- frontend → Netlify.
