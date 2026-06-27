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
| 1 | Plan + parity capture | 🔄 | this file |
| 2 | Go simulator: add tnpostid/locationid/textbody/has_outcome/has_success to pool query | ⬜ | digest_simulator.go |
| 3 | Go: implement dedup (getDeduplicationKey+normalizeSubject+normalizeBody+bodiesMatch parity), merge groupids, top-scoring representative | ⬜ | |
| 4 | Go: section into topPicks(available, cap 65)/cameAndWent(has_success, deduped); per-section counts + title count | ⬜ | drop pool_size as headline; report deduped counts |
| 5 | Go: compile-check (go build on a db node) | ⬜ | no local toolchain |
| 6 | Frontend RipplingExplorer.vue: render Top picks(N)/Came and went(N) sections, same order/format, deduped+capped, header count matches | ⬜ | drop preview-only "Promised" (fold into Top picks) |
| 7 | Tests (Go dedup unit; vitest if feasible) | ⬜ | |
| 8 | Commit master + push (CI) | ⬜ | NO deploy (routing native dance + Netlify are separate) |

## Deploy (SEPARATE — not part of this)
- iznik-routing-go → native db1/2/3 via unmonitor/SIGINT/monitor dance (graph reload ~5min).
- frontend → Netlify.
