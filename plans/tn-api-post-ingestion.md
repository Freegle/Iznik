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
  ├── PostsSyncer            ── uses ── GroupPostIngestionService (new, TN-only)
  └── ChatMessagesSyncer     ── uses ── ChatMessageIngestionService (new, TN-only)
```

`GroupPostIngestionService` and `ChatMessageIngestionService` are new TN-only services that re-implement the relevant slices of `IncomingMailService` logic, but operating on TN API DTOs instead of `ParsedEmail`. The email service is not touched.

## File layout

- `iznik-batch/app/Console/Commands/TrashNothing/TNSyncCommand.php` — slim orchestrator (~150 lines)
- `iznik-batch/app/Services/TrashNothing/TNApiClient.php`
- `iznik-batch/app/Services/TrashNothing/Syncers/RatingsSyncer.php`
- `iznik-batch/app/Services/TrashNothing/Syncers/UserChangesSyncer.php`
- `iznik-batch/app/Services/TrashNothing/Syncers/PostsSyncer.php`
- `iznik-batch/app/Services/TrashNothing/Syncers/ChatMessagesSyncer.php`
- `iznik-batch/app/Services/TrashNothing/Syncers/DuplicateUserMerger.php`
- `iznik-batch/app/Services/TrashNothing/Ingestion/GroupPostIngestionService.php`
- `iznik-batch/app/Services/TrashNothing/Ingestion/ChatMessageIngestionService.php`
- `iznik-batch/app/Services/TrashNothing/Ingestion/TNPayloadToRfc822.php`
- `iznik-batch/app/Services/TrashNothing/Dto/TNPostPayload.php`
- `iznik-batch/app/Services/TrashNothing/Dto/TNChatMessagePayload.php`
- `iznik-batch/app/Services/TrashNothing/Dto/SyncResult.php`

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
- Group lookup (by name? id? — depends on API shape)
- User lookup (API gives `fd_user_id` directly — simpler than email-based lookup, but verify parity for unmapped/banned/deleted users)
- Membership check (`collection = Approved`)
- TAKEN/RECEIVED subject swallow
- Spam check decision (see open items)
- Posting-status decision tree: `ourPostingStatus`, Big Switch (`overridemoderation`), mod-post-to-pending, group `moderated` setting, unmapped user, worry words
- Side-effects: `messages_postings`, `messages_history`, `messages_groups`, `messages_spatial`, `logs (Message/Received)`, `notifyGroupMods`, `addToSpatialIndex`, `pruneSubject`, `recordFailure`

### E. Chat messages
- API equivalent of `handleChatNotificationReply` / `handleReplyToAddress` / `createChatMessageFromEmail`.
- Map: chat id, sender user id, `refmsgid`, message type (`TYPE_INTERESTED` vs `TYPE_DEFAULT`), body, attachments.
- Stale-chat / unfamiliar-sender drop logic — still applicable?
- `addEmailToUser` (email forwarding scenario) — N/A.
- `trackEmailReply` (AMP comparison stats) — N/A or replaced with TN-source tracking.
- Read-receipts from TN — separate concern; map only if the API exposes them.

### F. Ordering between syncers
Order: ratings → user-changes → posts → chats → duplicate merge.
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

### K. Feature flag / rollout
- `config('freegle.trashnothing.ingest_posts_via_api')` (default false). When false, `PostsSyncer` and `ChatMessagesSyncer` skipped entirely.
- Separate flag to disable email path once parity proven — flipped much later. Both flags on = double-write (needs idempotency from B).

### L. Test strategy
- Unit-test each new service with fixture payloads; snapshot the rows that would be written.
- Parity test: feed a representative TN email through `IncomingMailService` and a matching TN API payload through `PostsSyncer`; assert resulting `messages` / `messages_groups` / `logs` rows match (modulo source field and synthetic message-id).
- Existing `IncomingMailServiceTest` suite stays green untouched.

### M. NOT in scope
- Modifying `IncomingMailService` in any way.
- Extracting/sharing helpers between email + API paths during parallel running.
- Removing SMTP receivers or any TN-aware code paths in the mail pipeline.

## Open items still to resolve

1. **Trace log format** — JSON only, key=value only, or both?
2. **Comparison tooling** — new `tn:sync-compare` artisan command vs offline scripting?
3. **Spam check on API path** — skip entirely (TN trusted), or run it and log when it would have flagged?
4. **Worry-words on API path** — apply, or skip because TN moderates on their end?
5. **Missing user handling** — when `PostsSyncer` sees an `fd_user_id` that doesn't exist locally yet (race with `UserChangesSyncer`), what's the desired behavior? Note: all-or-nothing checkpointing means failing the sync blocks ratings progress too.
6. **Group lookup** — does the TN API give `nameshort`, `groupid`, or something else?
7. **Duplicate detection in dry-run** — confirm trace lines should carry `would_be_duplicate: true` when a row with the same `tnpostid` already exists.
