# Matched-posts email (vector-based resurrection)

Resurrect the old `Relevant.php` matched-posts email, rebuilt: **vector** matching, **standalone** mail, scheduled **every 10 min**, per-post dedup. Own PR (NOT PR #956 / `FEATURE_DIGEST_RELEVANCE`, which is left untouched).

Design + decisions: see memory `project_matched_posts_email_resurrection`. Validated on live prod 2026-07-19: ~76-108 fresh Offer/Wanted posts / 10-15 min, open universe ~22k, 190k embeddings → arrival-driven, ~100 vector calls/run.

## Decisions (locked)
1. Separate PR; leave #956 alone.
2. Both directions (new arrival ↔ your existing post) — one vector search per fresh post yields both.
3. "Already viewed" = genuine clicks only (`messages_likes` View + `pageview=1`).
4. Per-user cooldown ~4h (`users.lastrelevantcheck`) + per-(msgid,userid) ledger.

## Architecture
Laravel `matches:notify` (every 10 min) → for each fresh post calls new Go apiv2 `GET /message/:id/matches` (opposite-type vector matches, bbox, reach for the post owner, threshold) → Laravel resolves recipients (both directions), applies exclusions, renders adaptive email, spools, writes ledger, bumps cooldown.

Always-on guards: exclude own posts, taken/received/withdrawn/deleted/unapproved, distance/bbox, similarity floor 0.6, `relevantallowed` opt-out, per-email match cap.

## Status
| # | Task | Status | Notes |
|---|------|--------|-------|
| G1 | Go `GET /message/:id/matches` (opposite-type, bbox, reach-for-owner, threshold, killswitch `FEATURE_MATCHED_POSTS`) + tests | ✅ | 4 tests pass; Go suite 3523✓ |
| L1 | Migration `messages_matched_notified` (PK msgid,userid; mailed_at; FKs) | ✅ | 2026_07_19_000001 |
| L2 | `FreegleApiClient::matchesForPost` GET + tests | ✅ | GET added, guarded |
| L3 | `MatchedPostsService` (fresh candidates SQL, call Go, both-direction fan-out, exclusions) + tests | ✅ | 6 service tests |
| L4 | `MatchedPosts` Mailable + MJML template (adaptive hero/list) reusing head/footer + tracking | ✅ | self-contained card |
| L5 | `matches:notify` command (orchestrate, spool, ledger, cooldown) + tests | ✅ | 2 command tests |
| L6 | Wire `mail:test matched` preview (--matched-count) | ✅ | renders both layouts |
| L7 | Schedule every 10 min in console.php | ✅ | everyTenMinutes |
| L8 | Opt-out via relevantallowed eligibility; 1-click unsub = follow-up | ✅ | noted in docs |
| L9 | Integration test (Mailpit): full flow render+send | ✅ | MatchedPostsIntegrationTest |
| V1 | Go suite green | ✅ | 3523✓ incl 4 new |
| V2 | Laravel suite green | ✅ | full suite 4862 ✓ 0 fail |
| V3 | Visual review + screenshots (hero/list) via Mailpit | ✅ | matched-hero.png, matched-list.png; color-coded by direction, on-brand |
| V4 | Docs (developer doc) | ✅ | reference/matched-posts-email.md |
| D1 | PR from fresh origin/master clone (copy new files + edit patches) + screenshots | ✅ | PR #1122; screenshots on pr-assets branch; CI #9606 running |

## Touched-existing-files (for PR isolation)
- `iznik-server-go/router/routes.go` (register route)
- `iznik-server-go/swagger/swagger.go` (maybe)
- `iznik-batch/routes/console.php` (schedule)
- `iznik-batch/app/Console/Commands/Mail/TestMailCommand.php` (preview)
(all other files NEW → copy verbatim)

**PR isolation note:** of the 6 modified files, only `console.php` also differs
between master and this branch (it carries the committed users:cleanup change for
PR #1118). The other 5 are identical on master → copy verbatim. For console.php:
start from master's version and insert ONLY the `matches:notify` Schedule block
(after master's users:cleanup block) — do NOT bring the users:cleanup edit across.
Verify the clone diff shows feature-only before pushing.
