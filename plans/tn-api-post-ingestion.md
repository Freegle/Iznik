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
- Comparison tooling: TBD — new artisan command `tn:sync-compare` vs offline scripting.

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
- Trace log format: machine-parseable JSON (`TRACE [WRITE] {"table":...,"op":...,"set":{...}}`). Possibly emit both JSON (for diff tool) and existing `key=value` style (for humans) — open item.

### K. Feature flag / rollout ✅
- `config('freegle.trashnothing.ingest_posts_via_api')` (default false) added to `config/freegle.php`.
- `TNSyncCommand` skips `PostSyncer` unless flag is true OR `--local-testing` is set; emits `TN-SYNC-TRACE [POSTS-SKIP] reason=feature-flag-off` when skipped.
- Separate flag to disable email path once parity proven — flipped much later. Both flags on = double-write (needs idempotency from B).

### L. Test strategy ✅ (posts)
- `GroupPostIngestionServiceTest` covers: null user skip, unknown user skip, non-member skip, duplicate detection (idempotency), dry-run trace log + no DB writes, pending routing (unmapped user + moderated group), live approved creation, live pending creation, RFC822 blob content.
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

### P. Coordinate-based group selection & moderator-messaging consent (new, not yet implemented)

Follows on from the section 6 decision (`Location::groupsNear()` replacing TN's `group_id`) and the `freegle_group_ids` starter code in `PostSyncer.php`.

**Group selection consequences (TODO):**
- Since the group is *chosen for* the post (via `groupsNear()` on lat/lng) rather than *supplied by* the poster, the poster is frequently not an Approved member of the resolved group. The email path's membership check (`collection = Approved`, see section D) must **not** be applied to API posts — confirm this is the case in `GroupPostIngestionService` and add an explicit test (`GroupPostIngestionServiceTest`) covering "post to a group the TN user is not a member of" as a success case, not a skip.
- Audit the rest of `handleGroupPost`'s decision tree (section D: `ourPostingStatus`, unmapped-user handling, moderated-group handling) for any other logic that implicitly assumes the poster is a member of the target group, since that assumption no longer holds on the API path.
- Confirm `notifyGroupMods` / any group-scoped side effects still fire correctly for a poster who isn't a member.
- Decide whether a non-member TN post should always land as pending (mod review) regardless of the group's `moderated` setting, given the poster has no established relationship with that group — currently undecided.

**Moderator-messaging consent (TODO):**
- `PostSyncer::processPost()` (lines ~164–177) already computes `$moderatorMessagingAllowed` from `freegle_group_ids` (confirmed to already be in Freegle's own group-id space) but only logs it (`TN-SYNC-TRACE [POST-META] ... moderator_messaging_allowed=...`) — it is not persisted or acted on.
- No existing storage for this concept anywhere in the schema (checked `messages`, `messages_groups`, `groups.settings`/`rules`) — this will need new storage, most naturally a per-post column (e.g. on `messages` or `messages_groups`, since consent is per-TN-post) rather than reusing any per-user consent flag (`users.marketingconsent` etc., which are unrelated platform-marketing consent, not mod-contact consent).
- Design and implement: schema change, `GroupPostIngestionService::ingest()` wiring to persist the flag, and the actual gate in the mod-messaging feature (ModTools UI/API) that reads it before allowing a moderator to contact the poster directly.
- Add fixture/test coverage for `freegle_group_ids` — none exists today (`tests/fixtures/tn_sync/*.json`, `PostSyncerTest.php`).
- Confirm behaviour when `freegle_group_ids` is absent (non-FD API key) vs present-but-empty (explicit no groups consented) — should probably default to "not allowed" either way, but make it explicit.

### M. NOT in scope
- Modifying `IncomingMailService` in any way.
- Extracting/sharing helpers between email + API paths during parallel running.
- Removing SMTP receivers or any TN-aware code paths in the mail pipeline.
- Chat messages — TN chat replies continue via the email path indefinitely (no TN chat API).

## Open items still to resolve

- See section P: removing the membership check on the API path (group is chosen, not supplied), and persisting/acting on `moderatorMessagingAllowed` from `freegle_group_ids` (new storage + gating logic needed, none exists today).

## Resolved decisions

2. **Comparison tooling** — no dedicated tool. Same approach as the TNSync V1→iznik-batch port: run with `--dry-run` in parallel alongside the email path; TRACE logs are inspected manually (via Loki) against DB rows written by the email path, joined on `tnpostid`. No artisan compare command. ✅
3. **Trace log format** — key=value (`TN-SYNC-TRACE [WRITE] table=foo op=insert set=...`). Human-readable, consistent across all syncers. No JSON. ✅
4. **Spam check on API path** — skipped entirely. The email path uses `shouldSkipSpamCheck()` to skip for TN emails with a valid secret; all API posts are from TN by definition, so the check is always skipped. Behavior is identical to the email path. ✅
4. **Worry-words on API path** — applied. `GroupPostIngestionService::subjectContainsWorryWords()` is a direct duplicate of `IncomingMailService::containsWorryWords()` and runs unconditionally, matching the email path. ✅
5. **Missing user handling** — stub user created via `findOrCreateUser()`: inserts a minimal `users` row (explicit `id = $fdUserId`), a synthetic `tn{id}@user.trashnothing.com` email, and an Approved membership. `UserChangesSyncer` fills in the real name/email on the next sync. In dry-run mode the stub is not created and the post is skipped. ✅
6. **Group lookup** — superseded by commit `676ed453b`. TN's `group_id` in API responses is TN's own opaque internal ID (not the Freegle `nameshort` originally assumed), and drifts out of step with Freegle's group boundaries, so it is never used for placement. `PostSyncer::processPost()` instead resolves the group from the post's lat/lng via `Location::groupsNear($lat, $lng, limit: 1)`, matching the member-placement logic. No config map exists from TN group IDs to Freegle groups. ✅
7. **Duplicate detection in dry-run** — trace lines carry `would_be_duplicate=true` when a row with the same `tnpostid` already exists. ✅
