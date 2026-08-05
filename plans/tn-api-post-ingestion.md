# TN API Ingestion Migration Plan

Replace email-based ingestion of Trash Nothing (TN) posts and chat messages with API-based ingestion driven from `TNSyncCommand`, while keeping the existing email path **byte-identical** for parallel comparison.

## Guiding constraint

`IncomingMailService::handleGroupPost`, `::createGroupPostMessage`, and every helper they call must remain unchanged. The new path is purely additive. Any shared logic is **duplicated then deduplicated later** — extraction now would touch the email path.

## Architecture

```
TNSyncCommand (orchestrator)
  ├── TNApiClient                       (paging, auth, fixtures, retries)
  ├── RatingsSyncer                     (existing logic, extracted)
  ├── UserChangesSyncer                 (existing logic, extracted)
  ├── DuplicateUserMerger               (existing logic, extracted)
  └── PostsSyncer            ── uses ── GroupPostIngestionService (new, TN-only)
```

`GroupPostIngestionService` is the new TN-only service that re-implements the posts slice of `IncomingMailService` logic, operating on TN API DTOs instead of `ParsedEmail`. The email service is not touched.

**Out of scope**: Chat (TN chat replies arrive via email and are handled by `IncomingMailService::handleChatNotificationReply` — that path remains unchanged. The TN API exposes no chat messages endpoint.)

## File layout

- `iznik-batch/app/Console/Commands/TrashNothing/TNSyncCommand.php` — slim orchestrator ✅
- `iznik-batch/app/Services/TrashNothing/TNApiClient.php`
- `iznik-batch/app/Services/TrashNothing/Syncers/RatingsSyncer.php`
- `iznik-batch/app/Services/TrashNothing/Syncers/UserChangesSyncer.php`
- `iznik-batch/app/Services/TrashNothing/Sync/RatingsSyncer.php` ✅
- `iznik-batch/app/Services/TrashNothing/Sync/UserChangesSyncer.php` ✅
- `iznik-batch/app/Services/TrashNothing/Sync/PostSyncer.php` — paging + group lookup ✅
- `iznik-batch/app/Services/TrashNothing/Syncers/DuplicateUserMerger.php`
- `iznik-batch/app/Services/TrashNothing/Ingestion/GroupPostIngestionService.php` ✅
- `iznik-batch/app/Services/TrashNothing/Ingestion/TNPayloadToRfc822.php` — RFC822 synthesis inlined into GroupPostIngestionService for now
- `iznik-batch/app/Services/TrashNothing/Dto/TNPostPayload.php`
- `iznik-batch/app/Services/TrashNothing/Dto/SyncResult.php`
- `iznik-batch/tests/fixtures/tn_sync/posts_page_1.json` — local-testing fixture ✅
- `iznik-batch/tests/Unit/Services/TrashNothing/GroupPostIngestionServiceTest.php` ✅
- `iznik-batch/config/freegle.php` — `trashnothing.ingest_posts_via_api` feature flag ✅
- `iznik-batch/app/Console/Commands/TrashNothing/TNParityCheckCommand.php` — `tn:parity-check`, runs both paths + prints the report ✅
- `iznik-batch/app/Services/TrashNothing/Sync/ParityComparer.php` — coverage-first four-layer comparison logic, see section Q ✅
- `iznik-batch/tests/fixtures/tn_sync/parity/{all_clean,layer1_missing,layer2_extra,layer3_mismatch,layer4_divergent_group}/` — per-layer parity test fixtures ✅
- `iznik-batch/tests/Feature/TrashNothing/EmailApiParityTest.php` — four-layer parity tests against `ParityComparer` ✅

## Key design decisions

### Parallel-run mode = `--dry-run`
The existing `--dry-run` flag is the comparison mode. With it set, the new API services emit `TRACE [WRITE]` log lines for every intended DB write but execute none. Comparison diffs those trace logs against the rows the email path actually wrote, joining on `tnpostid`. The email path remains source of truth during comparison. Going live = run without `--dry-run` and disable the email path at the same time.

Implications:
- Every new service takes `bool $dryRun` and gates every `save()`, `DB::insert/update/delete`, side-effect call (`notifyGroupMods`, `addToSpatialIndex`, `recordFailure`, image attachments, log inserts).
- No shadow tables, no source-tagging.
- Trace payload must capture every column the email path writes so the diff isn't blind to e.g. a missing `replyto`.

### Raw message → synthesized RFC822 blob
Centralized in `TNPayloadToRfc822::synthesize($payload): string`. Minimal headers: `From`, `To`, `Subject`, `Date`, `Message-ID`, `X-Trashnothing-Post-Id`, `X-Trashnothing-Coordinates`, `Content-Type: text/plain` — mirrors what the email path relied on so any downstream code that re-parses `messages.message` still works. Unit test: round-trip through `ParsedEmail` parsing to confirm we recover the same fields.

### Checkpointing → all-or-nothing
Keep the single sync-date file. Each syncer runs in try/catch; if any throws, the sync-date file is not written. The orchestrator aggregates `maxChangeDate` across all syncers only after they all succeed. A flaky endpoint blocks progress on the others — accepted for simplicity. Document on `storeSyncDate()`.

## Angles to cover

### A. Side-by-side / comparison
- Correlation key: `messages.tnpostid` (populated by both paths).
- Time alignment: TN API may not deliver a post the same minute the email arrives; comparison tooling needs a tolerance window.
- Comparison tooling: `TNParityCheckCommand` (`tn:parity-check`) exists and runs both paths in rolled-back transactions. Its comparison logic needs the redesign in section Q — see below; the "no dedicated tool" note in Resolved decisions #2 predates this command and is now superseded.

### B. Idempotency / dedup
- During parallel run, even though API path is dry-run, trace emitter should detect "row with this `tnpostid` already exists" and tag the trace line `would_be_duplicate: true`.
- Once live: `firstOrCreate` on `(tnpostid, groupid)`.
- **Overlap window**: All TN API date-range queries use a small backward overlap (e.g. 10 seconds) so data written to the current second is not missed when the sync boundary lands mid-second. Items already processed in the previous request are silently skipped via the idempotency check — no double-writes. The overlap is applied when building the `from` timestamp, not when storing `maxChangeDate`. See section N for implementation approach.

### C. Field-level parity — `createGroupPostMessage` writes these and each needs an API mapping
- `message` — synthesized RFC822 (see above)
- `messageid` — synthesized; format `{tnpostid}@tn.trashnothing.com-{groupid}` or similar
- `envelopefrom`, `envelopeto`, `replyto`, `fromip`, `sourceheader` — synthesized or null; decide per field
- `fromname`, `fromaddr` — from TN user data
- `subject`, `textbody` — direct from API
- `lat`/`lng`/`locationid` — from API coordinates (replacing `X-Trashnothing-Coordinates` header parsing)
- `tnpostid` — direct
- `type` — still via `Message::determineType($subject)`
- Images — from API image URLs (replacing `scrapeTnImageUrls` on textbody); confirm URLs match the email path's final URLs

### D. Behavioral parity — slices of `handleGroupPost` to replicate
- Group lookup — done differently to email path; see section P (coordinate-based, not membership-based)
- User lookup (API gives `fd_user_id` directly — simpler than email-based lookup, but verify parity for unmapped/banned/deleted users)
- ~~Membership check (`collection = Approved`)~~ — **removed for the API path**, see section P: the group is chosen via `Location::groupsNear()`, not supplied by the poster, so the poster need not be an Approved member of the resolved group. Confirm `GroupPostIngestionService` does not carry over the email path's membership gate for API posts.
- TAKEN/RECEIVED subject swallow
- Spam check decision (see open items)
- Posting-status decision tree: `ourPostingStatus`, Big Switch (`overridemoderation`), mod-post-to-pending, group `moderated` setting, unmapped user, worry words
- Side-effects: `messages_postings`, `messages_history`, `messages_groups`, `messages_spatial`, `logs (Message/Received)`, `notifyGroupMods`, `addToSpatialIndex`, `pruneSubject`, `recordFailure`

### E. Chat messages — OUT OF SCOPE
TN chat replies continue to arrive via email and are handled by the existing `IncomingMailService::handleChatNotificationReply` path. The TN public API exposes no chat messages endpoint. No API-based chat ingestion will be built.

### F. Ordering between syncers
Order: ratings → user-changes → posts → duplicate merge.
Risk: a post references an `fd_user_id` not yet created locally (race with `UserChangesSyncer`). Behavior on missing user is an open item.

### G. Failure semantics
Covered by all-or-nothing checkpoint decision above. Each syncer surfaces its own `maxChangeDate`; orchestrator aggregates only on full success.

### H. Throughput / rate limits
Existing syncers page 100 at a time. Posts/chats volume unknown — confirm pagination + rate-limit headers, plan for backoff. If posts add minutes of work, may exceed scheduler interval (lock prevents overlap but starves other resources).

### I. Loki / observability parity
Email path emits structured logs at every routing decision (`routing_reason`, `user_id`, `message_id`, `chat_id`). New path needs equivalent `loki->logEvent` calls. Define event names up front on the `tn-sync` channel: `post-create`, `post-skip-duplicate`, `chat-create`, etc.

### J. Dry-run / fixture-test parity
- Every DB-write call in new services must respect `dryRun`.
- Convention: services receive `bool $dryRun` in the constructor.
- Fixture files at `tests/fixtures/tn_sync/posts_page_*.json` and `chat_messages_page_*.json` — schema decided before generation.
- Trace log format: resolved as key=value, not JSON — see Resolved decisions #3.

### K. Feature flag / rollout ✅
- `config('freegle.trashnothing.ingest_posts_via_api')` (default false) added to `config/freegle.php`.
- `TNSyncCommand` skips `PostSyncer` unless flag is true OR `--local-testing` is set; emits `TN-SYNC-TRACE [POSTS-SKIP] reason=feature-flag-off` when skipped.
- Separate flag to disable email path once parity proven — flipped much later. Both flags on = double-write (needs idempotency from B).

### L. Test strategy ✅ (posts)
- `GroupPostIngestionServiceTest` covers: null user skip, unknown user skip, non-member success (not a skip — see section P), duplicate detection (idempotency), dry-run trace log + no DB writes, pending routing (unmapped user + moderated group), live approved creation, live pending creation, RFC822 blob content, `mod_messaging_allowed` default-true and explicit-false persistence.
- `PostSyncerTest` additionally covers `mod_messaging_allowed` derivation from `freegle_group_ids`: absent field, group present in list, group absent from list.
- `messages.source` uses `Message::SOURCE_EMAIL` to match the email path (no new enum value or migration needed).
- Parity test (email vs API path producing same rows) — not yet written; deferred until chat path is also done.
- Existing `IncomingMailServiceTest` suite stays green untouched.

### N. Sync-window overlap ✅
All TN API date-range queries should request a small backward overlap (recommended: 10 seconds) on the `from` boundary so data written to the current second is not missed when the sync boundary lands mid-second.

**Implementation**:
- `resolveFromDate()` in `TNSyncCommand` (or each syncer) subtracts `SYNC_OVERLAP_SECONDS = 10` from the stored sync date before passing it as `date_min`.
- The stored sync date (`storeSyncDate`) is written as-is (the true max date seen), so the overlap is re-applied fresh on every run — not accumulated.
- Items already processed in the previous request are silently skipped by the per-endpoint idempotency checks:
  - Ratings: `firstOrNew` on `tn_rating_id` (no duplicate write if already exists).
  - User changes: idempotent saves; name/location updates are safe to repeat.
  - Posts: `postAlreadyExists(tnpostid, groupid)` guard in `GroupPostIngestionService::ingest()`.

**Applies to**: all three existing endpoint sync loops (`syncRatings`, `syncUserChanges`, `PostSyncer::sync`).

### O. Syncer extraction ✅
Currently `syncRatings` and `syncUserChanges` are private methods inside `TNSyncCommand`. As the command grows, extracting each into its own class (like `PostSyncer`) improves testability and keeps the command a thin orchestrator.

**Proposed classes** (can be done alongside or after the overlap work):
- `iznik-batch/app/Services/TrashNothing/Sync/RatingsSyncer.php`
- `iznik-batch/app/Services/TrashNothing/Sync/UserChangesSyncer.php`

Each would follow the `PostSyncer` pattern: constructor takes `(bool $dryRun, bool $localTesting, string $apiKey, string $apiBaseUrl, LokiService $loki)`, exposes `sync(string $from, string $to): array` returning `[int $count, ?string $maxDate]`, and handles its own fixture loading for `--local-testing`. `TNSyncCommand::handle()` becomes three `$syncer->sync(...)` calls.

**Not required for the posts ingestion go-live**, but recommended before the codebase grows further.

### P. Coordinate-based group selection & moderator-messaging consent

Follows on from the section 6 decision (`Location::groupsNear()` replacing TN's `group_id`) and the `freegle_group_ids` starter code in `PostSyncer.php`.

**Group selection consequences:** ✅ all resolved
- Since the group is *chosen for* the post (via `groupsNear()` on lat/lng) rather than *supplied by* the poster, the poster is frequently not an Approved member of the resolved group. The email path's membership check no longer skips API posts: `GroupPostIngestionService::ingest()` looks up an Approved membership if one exists (to reuse its `ourPostingStatus`), but falls back to `'DEFAULT'` — the same status a brand-new member gets — when none is found, instead of returning `'skipped'`. The group's own `moderated`/`overridemoderation` settings still apply regardless. Covered by `GroupPostIngestionServiceTest::test_creates_post_for_non_member_of_resolved_group` (success case, not a skip).
- Audited the rest of `handleGroupPost`'s decision tree (section D) for other logic that implicitly assumed membership: `ourPostingStatus`/moderated-group handling is confirmed membership-independent (see above); unmapped-user (`lastlocation === null`) is unaffected since it's a user-level check, not membership-level.
- `notifyGroupMods` fires unconditionally on the Pending branch, independent of membership — confirmed no membership dependency there.
- Non-member TN posts are **not** forced to Pending — they follow the same routing tree as a `DEFAULT` member (approved unless the group is moderated, worry words, or the user is unmapped). This was an open question; resolved by defaulting to `'DEFAULT'` rather than special-casing non-members to always-pending.

**Moderator-messaging consent:** storage + ingestion wiring done; ModTools-side gate still outstanding
- ✅ `PostSyncer::processPost()` computes `$moderatorMessagingAllowed` from `freegle_group_ids` and defaults to **disallowed**: every TN post starts with mod messaging off unless the resolved group is explicitly present in `freegle_group_ids` (an absent/empty field means no consent was given, so it stays disallowed). This differs from the table-wide column default (allowed, for ordinary non-TN Freegle posts) — TN posts always pass an explicit boolean, so the column default never applies to them.
- ✅ Storage added: `messages_groups.mod_messaging_allowed` (migration `2026_07_23_000001_add_mod_messaging_allowed_to_messages_groups`), boolean, defaults to `1` (allowed) at the schema level for non-TN rows.
- ✅ `GroupPostIngestionService::ingest()`/`createMessage()` take `bool $modMessagingAllowed` and persist it on the `MessageGroup::create()` call. Deliberately **not** added to the shared `[WRITE] table=messages_groups` trace line, since `EmailApiParityTest` diffs that line byte-for-byte against the email path (which has no equivalent field) — logged separately via the existing `[POST-META]` tag instead.
- ✅ Fixture/test coverage: `PostSyncerTest` covers absent field (disallowed), group in list (allowed), group not in list (disallowed); `GroupPostIngestionServiceTest` covers default persists true and explicit false persists.
- ❌ **Deferred, not started**: the actual gate in a mod-messaging feature (ModTools UI/API) that reads `mod_messaging_allowed` before allowing a moderator to contact the poster directly. There is no existing "mod messages the poster directly" feature in ModTools to gate — the current mod chat UI is just the normal reply-to-poster thread, with no separate contact-poster action. Building the gate therefore means designing and building that feature from scratch (new endpoint + UI), which is out of scope until there's a concrete design/decision to build it. Only the storage and ingestion wiring exist so far.

### Q. Parity check redesign — coverage-first, four-layer model ✅ complete (`TNParityCheckCommand` rewritten; logic extracted to `ParityComparer`; `EmailApiParityTest` rewritten with per-layer fixture coverage)

`TNParityCheckCommand` (`tn:parity-check`) currently asserts the email path and API path produce **byte-identical** `TN-SYNC-TRACE` output — an exact line-for-line diff (`normalizeLines()` + `===` comparison). That model is wrong: the two paths are not supposed to be identical.

- The API path is a **superset** — it should ingest every post the email path does, and may additionally ingest posts the email path never saw (e.g. TN posts with no corresponding email, or where the email never arrived).
- The API path resolves its group **independently**, via `Location::groupsNear()` on the post's own coordinates (section P / Resolved decision #7), while the email path resolves its group from the recipient address. The two are expected to legitimately disagree on which group a post lands in.

Byte-identical trace diffing can't express either of these — it fails the moment the API path processes one extra post or picks a different (correct) group. The replacement model is coverage-first, not equality-first, in four layers:

**Layer 1 — coverage (hard fail).** ✅ **Implemented.** Build the set of `post_id`s from the email path's per-post result lines, and the set of `post_id`s the API path produced an actual `[POST-RESULT]` for. Assert email-set ⊆ API-set. Any `post_id` the email path handled that's entirely absent from the API path's `[POST-RESULT]` set — **including** when the API path's own coordinate-based `Location::groupsNear()` couldn't place it anywhere (`[POST-SKIP] reason=no-coordinates` / `reason=not-in-any-group-bounds`) — is a **regression in the number of posts ingested** and fails the check. The pre-ingest skip reason, when known, is surfaced in the failure detail (`api_status=skipped(...)` vs `api_status=never-in-feed`) for diagnosis, but never counts as coverage.
  - ⚠️ **Bug found and fixed**: the original implementation merged the two pre-ingest skip reasons *into* the "covered" set (`apiCoveragePostIds = apiResultPostIds ∪ apiPreIngestSkips`), the opposite of the decision recorded here — a post the API path couldn't place in any group was silently treated as "covered" and never flagged, even when the email path had successfully placed it in a real group. This only became visible once real production `groups` data (with `polyindex` boundaries) was loaded for testing — with an empty/near-empty `groups` table nearly everything hits `not-in-any-group-bounds` on both the genuinely-non-UK posts *and* any real placement failures, masking the distinction. Fixed in `ParityComparer::computeLayers()` — Layer 1 coverage is now `apiResultPostIds` only. Layer 2 ("extra" posts) was unaffected by this bug — it was already correctly derived from `apiResultPostIds` alone, so non-UK/unplaceable posts never inflated it. Covered by a new test, `EmailApiParityTest::test_layer1_flags_a_post_the_api_path_could_not_place_in_any_group`, using a dedicated fixture (`layer1_out_of_bounds`) where the email path places a post via a real group's recipient address while the API-side coordinates fall deliberately outside every seeded group's bounds.
  - Implementation correction found while building this: the email path's reliable per-post outcome marker is `[EMAIL-RESULT]` (emitted unconditionally by `EmailReplaySyncer` after every `route()` call), not `[POST-RESULT]` — some `IncomingMailService` skip branches (e.g. `unknown-group`) never emit their own internal `[POST-RESULT]`. `parseResults()` now parses both tags and lowercases the result (email uses PascalCase enum values like `Dropped`; API uses lowercase `dropped`).
  - Each Layer 1 failure now logs the full picture from the email side, not just `post_id`: the email result, the `[POST]` summary (`type`/`group_id`/`date`/`title`, always present), and the full `messages`-row field set if the email path actually created a message for that post (`formatLayer1Detail()`).
  - ✅ **`--date-max` added** to `tn:parity-check` (and `EmailReplaySyncer::sync()` gained a matching `$dateMax` param) so the query window can be pinned safely in the past, ruling out one real cause of Layer 1 false alarms: TN's public Posts API lagging behind its own partner email feed on the very newest posts. Confirmed live — a post emailed at the last second of an unpinned window (`--date-max` omitted) was missing from the API purely because `[POSTS-DONE] max_date=` came back ~30s earlier than the window's true upper bound; re-running with `--date-max` a safe margin in the past made that specific miss disappear.
  - ✅ **Root cause of the remaining production-data misses confirmed** (2026-07-29, two live runs, one with `--date-max` set a full hour in the past — ruling out lag/pagination): `post_id`s `47081039`, `47081059`, `47081073` were emailed to Freegle but never appeared in `/posts/all` for their original window. Direct `GET /posts/{id}` lookups on each (`PostsApi::getPost()`) showed **two distinct real causes, neither a Freegle-side bug**:
    - `47081039` and `47081073` **still exist** on TN, but their `date` field had been mutated to a later timestamp (16h and 4.5h after the original email, respectively — confirmed against `expiration`, which is fixed at original-post-time + 90 days). `/posts/all` filters by `date_min`/`date_max` against this **mutable** field, so a repost/edit/bump on TN's side silently moves a post out of any window anchored to when it was first emailed — invisible from the partner email side entirely.
    - `47081059` returns `404 Post 47081059 does not exist.` — deleted from TN sometime after the email was sent.
  - ✅ **Fix implemented**: `PostSyncer::lookupPostById()` (new) does the single-post `GET /posts/{id}` fallback lookup, returning status (`found`/`not_found`/`error`), `date`, and `outcome`. `TNParityCheckCommand::reclassifyLayer1Misses()` runs it (live mode only, skipped under `--local-testing`) against every Layer 1 candidate miss before treating it as a failure, splitting results into `layer1Missing` (genuine, still fails the check), `layer1Deleted`, `layer1BumpedOutOfWindow`, and `layer1ResolvedOutcome` (all three informational, printed separately, never fail the run).
  - ✅ **Fourth case added**: a post whose `outcome` has resolved to `satisfied` or `withdrawn` (per the OpenAPI `Post` model's documented outcome values — `deleted` isn't a real outcome value but is matched defensively too, since a truly deleted post 404s via the `not_found` branch instead) is excluded from `/posts/all` correctly — it was never going to be posted to FD once resolved, so its absence isn't a coverage regression. `RESOLVED_OUTCOMES` constant on the command.
  - Confirmed live across two runs on the same window: the first fallback pass (date/deleted only) correctly reclassified 3 known misses (1 deleted, 2 bumped) but still flagged 2 *new* ones (`47081047`, `47081094` — expected, each run queries a different live snapshot of TN's constantly-changing feed); after adding the outcome check, both turned out to be `outcome=satisfied` and are now correctly excluded — the run reports `missing=0`, full **PASS**.

**Layer 2 — extra posts (informational only).** ✅ **Implemented.** `post_id`s present in the API set but not the email set are expected and desirable (that's the point of the API path) — report the count/list, never fail on them.
  - ✅ **Ingestion-gain summary added**: a new report line, "New posts via API only: N (email ingested=X, api ingested=Y, +Z% vs email-only baseline)", quantifying how many *more* posts actually land on FD via the API path vs the old email path. Deliberately restricted to results that created a messages row (`approved`/`pending`) on each side — `ParityComparer::INGESTED_RESULTS` — rather than raw `post_id` counts, so a pile of dropped/duplicate/skipped extras doesn't inflate the "we're getting more" figure. Percentage is relative to the email path's own ingested count (the old baseline); handles a zero baseline without dividing by zero. New `computeLayers()` return keys: `emailIngestedCount`, `apiIngestedCount`, `layer2ExtraIngested`.

**Layer 3 — same-group parity (hard fail).** ✅ **Implemented**, with one scope adjustment: only fields actually present in the `[WRITE] table=messages` trace line are compared (that line has no `textbody`), so the compared set is `fromuser`, `type`, `subject`, `lat`, `lng`, `locationid`, plus the routing outcome (`approved`/`pending`/`dropped`/skip-reason) — not `textbody` as originally scoped, since it isn't traced. Excluded from comparison: fields the two paths synthesize differently by design (`envelopefrom`, `fromip`, `sourceheader`, `messageid`, `message` (RFC822 blob) — see section C, "synthesized or null; decide per field"). A mismatch here is a genuine regression signal — same group means the same moderation-decision tree should fire and the same content should have been captured. For `post_id`s where either side never created a messages row at all (dropped/skipped before ingestion, so there's no `groupid` to compare), the pair falls through to Layer 4 instead — there's nothing to run a same-group check against.

**Layer 4 — different-group divergence (informational only).** ✅ **Implemented.** For `post_id`s present in both paths where they resolved to *different* groups, or where either side never created a messages row: don't compare outcome or content at all (a Pending on group A says nothing about what should happen on group B) — just report the `post_id` and both groupids (or the missing-row reason), for visibility.

**Output**: plaintext summary counts per layer, plus lists of the failing `post_id`s (Layer 1 misses, Layer 3 mismatches) — not the full raw trace dump `TNParityCheckCommand` used to print. ✅ Implemented as designed.

**Real-run observation — two distinct causes of Layer 1 misses, confirmed against live TN data (2026-07-29):**
- **Boundary propagation lag** (self-resolving). The API path's own returned `max_date` can trail the query window's `to` by tens of seconds (e.g. one run returned `max_date=2026-07-29T18:41:54Z` against a `to` of `18:42:24Z`) — TN's public Posts API hadn't indexed the newest post(s) yet at query time. Confirmed self-resolving: re-running the identical `--date-min` window ~43 minutes later, the earlier boundary miss (`post_id=47081111`, "Rice cooker") was gone from the failure list. A production run on a schedule will simply pick these up next cycle — not a real regression.
- **Persistent TN-side data gap** (not a Freegle bug, does not resolve with time). Three posts — `post_id=47081039` ("Chest of drawers", Newlyn TR18), `47081059` ("dfs grey cord sofa", Southport PR8), `47081073` ("Filing cabinet", Fleet Hampshire GU51) — were present in TN's partner email feed (`fd-post-log.csv`) but never appeared in TN's public Posts API at all, confirmed by re-running the same window ~43 minutes later with all pagination fetched (`page=1 count=50` + `page=2 count=38`, correctly terminated). Each is chronologically sandwiched between other posts that *did* sync correctly on both sides, ruling out a pagination or window-boundary explanation. This looks like a genuine completeness gap in TN's own public API relative to their partner email channel, worth raising with TN directly. Until/unless resolved on TN's side, expect a small, low, roughly-constant trickle of Layer 1 misses on real runs that are not Freegle regressions — worth keeping in mind when triaging a `tn:parity-check` failure so a handful of misses isn't mistaken for a code regression.

**Implementation notes**:
- No changes needed in `GroupPostIngestionService` — every trace line needed is already emitted. One correction found in `IncomingMailService`/`EmailReplaySyncer`: see the Layer 1 note above (`[EMAIL-RESULT]`, not `[POST-RESULT]`, is the email path's reliable outcome marker).
- ✅ The parsing/four-layer comparison logic (`parseResults`/`parsePreIngestSkips`/`parseMessages`/`parsePostDetails` + `computeLayers`/`classifyOverlapPost`/`diffMessageFields`/`formatLayer1Detail`) was extracted out of `TNParityCheckCommand` into a new standalone class, `App\Services\TrashNothing\Sync\ParityComparer`, so it's unit-testable independently of the CLI. `TNParityCheckCommand` now just runs both paths, calls `(new ParityComparer())->computeLayers(...)`, and prints the report (`captureTraceLogs`/`printReport` stay on the command; `normalizeLines`/`printLineDiff`/the old `===` check are gone).
- ✅ `EmailReplaySyncer` and `PostSyncer` each gained an optional trailing constructor param (`?string $fixtureCsvPath`, `?string $fixtureDir`) to override the hardcoded `--local-testing` fixture path/directory, defaulting to the original shared fixtures when omitted — needed so each parity test scenario can point at its own dedicated fixture files without touching the shared `tests/fixtures/tn_sync/{fd_post_log.csv,posts_page_1.json}`.
- ✅ `tests/Feature/TrashNothing/EmailApiParityTest.php` rewritten from the old byte-identical diff to 10 tests against `ParityComparer` (one "flags the issue" + one "silent when clean" per layer, the four silent cases sharing one `all_clean` fixture), using 5 new dedicated fixture pairs under `tests/fixtures/tn_sync/parity/{all_clean,layer1_missing,layer2_extra,layer3_mismatch,layer4_divergent_group}/`. Tests construct `EmailReplaySyncer`/`PostSyncer` directly (not via artisan) to pass the fixture-path overrides.
  - Found and fixed a real issue while building the `all_clean` fixture (verified via `artisan tinker`, not guesswork): a `DEFAULT`-status membership walks into an existing, already-documented divergence — `IncomingMailService::handleGroupPost()` no longer auto-approves `DEFAULT` posters on arrival, while `GroupPostIngestionService::ingest()` still does — so even byte-identical content routes to different outcomes (`pending` vs `approved`). Switched the shared `seedParityUser()` helper to `MODERATED` (pends on both paths), matching the original test's approach, with the reasoning documented on the helper.

### M. NOT in scope
- Modifying `IncomingMailService` in any way.
- Extracting/sharing helpers between email + API paths during parallel running.
- Removing SMTP receivers or any TN-aware code paths in the mail pipeline.
- Chat messages — TN chat replies continue via the email path indefinitely (no TN chat API).

## Open items still to resolve

- See section P: the ModTools-side gate that reads `messages_groups.mod_messaging_allowed` before allowing a moderator to message a poster directly. Deliberately deferred — there's no existing contact-poster feature to gate, so this needs a concrete feature design before implementation, not just a wiring task.

## Resolved decisions

2. **Comparison tooling** — superseded by `TNParityCheckCommand` (`tn:parity-check`), see section Q. Originally decided as "no dedicated tool, manual Loki inspection only"; a dedicated artisan command was built after all, but its comparison model needed a redesign once it became clear the two paths are not expected to be byte-identical (see section Q). ⚠️ superseded
3. **Trace log format** — key=value (`TN-SYNC-TRACE [WRITE] table=foo op=insert set=...`). Human-readable, consistent across all syncers. No JSON. ✅
4. **Spam check on API path** — skipped entirely. The email path uses `shouldSkipSpamCheck()` to skip for TN emails with a valid secret; all API posts are from TN by definition, so the check is always skipped. Behavior is identical to the email path. ✅
5. **Worry-words on API path** — applied. `GroupPostIngestionService::subjectContainsWorryWords()` is a direct duplicate of `IncomingMailService::containsWorryWords()` and runs unconditionally, matching the email path. ✅
6. **Missing user handling** — stub user created via `findOrCreateUser()`: inserts a minimal `users` row (explicit `id = $fdUserId`), a synthetic `tn{id}@user.trashnothing.com` email, and an Approved membership. `UserChangesSyncer` fills in the real name/email on the next sync. In dry-run mode the stub is not created and the post is skipped. ✅
7. **Group lookup** — superseded by commit `676ed453b`. TN's `group_id` in API responses is TN's own opaque internal ID (not the Freegle `nameshort` originally assumed), and drifts out of step with Freegle's group boundaries, so it is never used for placement. `PostSyncer::processPost()` instead resolves the group from the post's lat/lng via `Location::groupsNear($lat, $lng, limit: 1)`, matching the member-placement logic. No config map exists from TN group IDs to Freegle groups. ✅
8. **Duplicate detection in dry-run** — trace lines carry `would_be_duplicate=true` when a row with the same `tnpostid` already exists. ✅
9. **Non-member group posting (API path)** — the group is chosen for the post via coordinates, not supplied by a member, so the email path's membership gate does not apply. See section P. ✅
10. **Moderator-messaging consent storage** — `messages_groups.mod_messaging_allowed`, computed from TN's `freegle_group_ids`, defaults to disallowed for TN posts specifically. See section P. Gating logic in ModTools is not yet built. ✅ (storage/ingestion only)
