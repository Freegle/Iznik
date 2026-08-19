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
- `iznik-batch/app/Services/TrashNothing/Sync/ParityComparer.php` — coverage-first five-layer comparison logic (4 DB layers + Loki entry parity), see section Q ✅
- `iznik-batch/tests/fixtures/tn_sync/parity/{all_clean,layer1_missing,layer2_extra,layer3_mismatch,layer4_divergent_group}/` — per-layer parity test fixtures ✅
- `iznik-batch/tests/Feature/TrashNothing/EmailApiParityTest.php` — per-layer parity tests against `ParityComparer`, including Layer 5 (Loki) ✅

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

### I. Loki / observability parity ✅ **implemented**

> **Reconciled with section R, 2026-08-12.** Repost/crosspost *detection* has been removed from the API path entirely (section R): there is no `reposted` result, no `repost-already-bumped` skip and no `post-repost-bump` event. Historical mentions of them below have been corrected in place; the open "what `subtype` for `reposted`?" question is closed by deletion rather than by a decision. The implementation is on disk (it was briefly stashed while section R landed).

**Implementation summary** (all additive on the API side; the email path and its Loki output are untouched).

**The architecture deliberately mirrors the email path's, class for class and seam for seam** — that is a requirement, not a coincidence, because a stream that is 1:1 in content but reached by a different route drifts apart the moment either side changes:

| | email path | TN API path |
|---|---|---|
| router — decides the outcome, **never touches Loki** | `IncomingMailService::route()` | `GroupPostIngestionService::ingest()` |
| accumulates context, replaced wholesale by a `dropped()` helper | `$lastRoutingContext` / `dropped()` | same names, same semantics |
| exposes it to the caller | `getLastRoutingContext()` | `getLastRoutingContext()` |
| caller — emits **exactly one** entry after routing returns | `IncomingMailController::receive()` / `IncomingMailCommand` | `PostSyncer::processPost()` |
| Loki entry point | `LokiService::logIncomingEmail()` | `LokiService::logIngestedPost()` |

- `LokiService` — `logIngestedPost()` added; `logIncomingEmail()` keeps its exact signature and output. Both delegate to shared private `buildRoutedMessage()`/`buildRoutedEntry()` helpers, so the schema cannot drift on one side without drifting on both.
- `GroupPostIngestionService` — has **no Loki dependency for routing**. It resets its context at entry (as `route()` does), sets `group_id`/`group_name`/`user_id` at the same relative point `handleGroupPost()` does, appends `message_id` once a message exists, and uses a `dropped()` helper with the email path's replace-don't-merge semantics. Reason strings live on the class that decides them, as they do on the email path. `outcomeFor()` maps its result vocabulary to `RoutingResult`.
- `PostSyncer` — the caller. Emits one entry per post via `logRoutedPost()` (a thin wrapper over `LokiService`, factored only because this class has four call sites where the controller has one), plus `logBatchJob('tn:sync-posts', 'failed'|'completed')` for run-level events.
- **No extra classes.** An earlier draft introduced a `PostRoutingLogger` that emitted from inside the ingestion service; it has been deleted, because the email path has no equivalent and scattering emission through the router is precisely the structure this section exists to avoid.
- Tests, sharing the `CapturesRoutedLokiEntries` trait (which reads back what the real `LokiService` wrote to disk, never a mock):
  - `TnApiLokiParityTest::test_both_paths_produce_the_same_loki_entry_for_the_same_post_content` — **the test this section exists for.** Runs one TN post through BOTH real paths (`IncomingMailService::route()` and `PostSyncer::processPost()`) and diffs the two resulting Loki entries: identical labels but for `source`, identical key sets bar `tn_post_id`, and equal `routing_outcome`/`subject`/`group_id`/`group_name`/`user_id`. Fields that differ by design (`envelope_from`, `from_address`, `message_id`) are asserted explicitly so the divergence stays deliberate. The test plays `IncomingMailController`'s role by calling `logIncomingEmail()` itself after `route()` — no production code is modified, which is what makes this possible despite I.6.
    - Verified to actually catch divergence: sabotaging `PostSyncer`'s `routingOutcome` made it fail on the label diff.
    - Two fixture constraints, both documented in the test: the poster is **MODERATED**, not DEFAULT (a DEFAULT poster hits the already-known email-pends/API-approves divergence, which would swamp the signal — same reasoning as `EmailApiParityTest::seedParityUser()`); and each path gets its **own `post_id`** for the same content, because both paths synthesize the same `messages.messageid` and the API path's `tnpostid` idempotency check would otherwise see the email path's row and skip as a duplicate.
  - `TnApiLokiParityTest` also covers one-entry-per-post, the `message_id` overwrite, the synthesized-id fallback, dry-run tagging, and the disabled-Loki no-op.
  - `GroupPostIngestionServiceTest` — 8 context tests, the router's half (what `getLastRoutingContext()` holds per branch).
  - `PostSyncerTest` — the two pre-ingest skips.

The original text of this section ("email path emits structured logs at every routing decision... new path needs equivalent `loki->logEvent` calls") was **wrong on both halves**, and the `logEvent('tn-sync', ...)` calls already scattered through the API path were written against that wrong model. What follows replaces it.

#### I.1 What actually reaches Loki

Only `LokiService` writes reach Loki. `conf/alloy/config.alloy` tails **`/var/log/freegle/*.log`** only — the JSON-line files `LokiService::writeLog()` produces. Laravel's own `Log::info()`/`Log::channel('incoming_mail')` output goes to `storage/logs/laravel.log` and is **never shipped**.

Consequence: **every `TN-SYNC-TRACE` line on both paths is invisible in Loki.** The trace lines are a `tn:parity-check`-only mechanism (captured in-process, see `TNParityCheckCommand::captureTraceLogs()`); they are not, and must not be confused with, the Loki comparison this section is about. The two comparison mechanisms are independent and both are needed.

#### I.2 The email path's Loki output, in full

The email path emits **exactly one Loki entry per inbound email**, written *after* routing completes, by the caller — never by `IncomingMailService` itself (which contains zero `LokiService` references):

- `IncomingMailController::receive()` (`app/Http/Controllers/IncomingMailController.php:71`) — the production Postfix path.
- `IncomingMailCommand` (`app/Console/Commands/IncomingMailCommand.php:93`) — the CLI path.

Both call `LokiService::logIncomingEmail($envelopeFrom, $envelopeTo, $fromAddress, $subject, $messageId, $result->value, $service->getLastRoutingContext())`, producing a line in `incoming_mail.log`:

| | |
|---|---|
| **file** | `incoming_mail.log` |
| **labels** | `app=freegle`, `source=incoming_mail`, `type=routed`, `subtype=<RoutingResult->value>` |
| **message (fixed)** | `envelope_from`, `envelope_to`, `from_address`, `subject`, `message_id`, `routing_outcome` |
| **message (merged from `getLastRoutingContext()`)** | `routing_reason`, `group_id`, `group_name`, `user_id`, `message_id` (the FD msgid — note the **key collision** with the header `message_id`; the context value wins), `spam_type`, `spam_reason` |

`subtype`/`routing_outcome` use the **PascalCase** `RoutingResult` values (`Approved`, `Pending`, `IncomingSpam`, `Dropped`, `ToSystem`, …), not the API path's lowercase result strings.

This stream is a live consumer-facing feed, not just diagnostics: ModTools' incoming-email dashboard queries `sources=incoming_mail` and reads exactly these keys — see `iznik-nuxt3/modtools/stores/emailtracking.js:407-450`, `ModSupportIncomingEmail.vue`, `ModIncomingEmailDetail.vue`, `ModIncomingEmailCharts.vue`. Any parity work must not break or pollute it.

#### I.3 Every email-path case that produces a Loki entry for a TN group post

`route()` clears `lastRoutingContext` at entry (`IncomingMailService.php:99`), so the context is whatever the winning branch set. Cases, in `handleGroupPost()` order — **each of these is one Loki line the API path currently has no counterpart for**:

| # | Email-path branch | `subtype` | Context fields present |
|---|---|---|---|
| 1 | Unknown group (`findGroup()` null) | `Dropped` | `routing_reason="Post to unknown group"` only |
| 2 | Unknown user (`findUserByEmail()` null) | `Dropped` | `routing_reason="Post from unknown user"` only |
| 3 | Non-member (no Approved membership) | `Dropped` | `routing_reason="Post from non-member"` only |
| 4 | TAKEN/RECEIVED subject swallowed | `ToSystem` | **none** — context is empty (returns before it is set) |
| 5 | Spam classified | `IncomingSpam` | `group_id`, `group_name`, `user_id`, `message_id`, `spam_type`, `spam_reason` |
| 6 | `ourPostingStatus=PROHIBITED` | `Dropped` | `group_id`, `group_name`, `user_id` — **no `routing_reason`** (returns `RoutingResult::DROPPED` directly, not via `dropped()`) |
| 7 | Approved | `Approved` | `group_id`, `group_name`, `user_id`, `message_id` |
| 8 | Pending — awaiting content check (DEFAULT/UNMODERATED) | `Pending` | `group_id`, `group_name`, `user_id`, `message_id` |
| 9 | Pending — moderator reason (moderated group/user, Big Switch, worry words, unmapped user, mod poster) | `Pending` | `group_id`, `group_name`, `user_id`, `message_id` — note `pendingReason` is **not** propagated into the context, only into `laravel.log` |
| 10 | `createGroupPostMessage()` returned null (duplicate messageid, or exception → `recordFailure`) | `Approved`/`Pending` (the pre-computed result) | `group_id`, `group_name`, `user_id` — **no `message_id`**, and the failure itself is invisible in Loki |

Cases 4, 6, 9 and 10 each drop context the other cases carry — 4 emits no context at all, 6 omits `routing_reason`, 9 omits `pendingReason`, 10 omits `message_id` and renders the creation failure invisible. These are **deliberate non-changes, not defects to fix**: the email path is frozen (see "Guiding constraint" and section M), and that freeze explicitly extends to its Loki output. The API path must **reproduce these same omissions** so the two streams line up field-for-field — adding the missing context on the API side only would itself create false divergence. Revisit as a joint improvement once the email path is retired, not before.

**Pre-`handleGroupPost` branches** (auto-reply, self-sent, known spammer, dropped sender, bounce, digest reply, `isChatNotificationReply`) also emit `Dropped`-with-`routing_reason` lines for what may be TN traffic. These have **no API-path equivalent by construction** — there is no envelope, no bounce, no auto-reply on an API post. Document as intentionally absent; they must not be counted as coverage misses.

#### I.4 What the API path emits today, and every case that is missing

Current API-path Loki calls all go through `logEvent('tn-sync', <subtype>, …)` → `batch_event.log`, labels `source=batch_event`, `type=tn-sync`. **Wrong file, wrong labels, wrong subtype vocabulary, wrong field names** (`tn_post_id`/`msg_id`/`collection` vs `message_id`/`routing_outcome`) — a Loki-side comparison against the email path is impossible today, and none of these entries appear on the ModTools incoming dashboard.

Existing (to be reshaped, see I.5):

| API branch | current event |
|---|---|
| `GroupPostIngestionService::ingest()` duplicate `tnpostid` | `post-skip-duplicate` |
| unknown/absent `fd_user_id` | `post-skip-unknown-user` |
| per-group copy discarded | `post-skip-crosspost` |
| approved | `post-create` (`collection=Approved`) |
| pending | `post-create` (`collection=Pending`, `reason`) |
| stub user created (`findOrCreateUser()`) | `user-stub-create` |

**Previously missing entirely — no Loki entry was emitted at all. All ✅ implemented:**

*In `PostSyncer::processPost()`:*
1. ✅ **`[POST-SKIP] reason=no-coordinates`** — post has no lat/lng, never reaches ingestion. Nearest email-path analogue: case 1/2 (`Dropped` + reason). → `Dropped` + `REASON_NO_COORDINATES`, no group context (no group was resolved).
2. ✅ **`[POST-SKIP] reason=not-in-any-group-bounds`** — `Location::groupsNear()` placed it nowhere. Analogue: case 1 (`Dropped`, "Post to unknown group"). **Highest-value gap**: precisely the Layer 1 coverage-regression case section Q calls out, previously invisible in Loki. → `Dropped` + `REASON_NOT_IN_ANY_GROUP_BOUNDS`.
3. ✅ **Ingestion threw** (`catch (\Throwable)` around `ingest()`) — was `Log::error` only. → `Failure` + `REASON_INGESTION_EXCEPTION`, with group context (the group *was* resolved before the throw).

*In `PostSyncer::fetchPage()` / `sync()`:*
4. ✅ **TN API call failed** (`ApiException`) — aborts the whole sync; was `Log::error` only, so a failed run looked identical in Loki to a window with no posts. → `logBatchJob('tn:sync-posts', 'failed')` with page/status/error/window.
5. ✅ **Sync-level summary** — → `logBatchJob('tn:sync-posts', 'completed')` with total/max_date/window/dry_run. Deliberately on the batch stream, not the routed one, which carries one entry per post and nothing else.

*In `GroupPostIngestionService::ingest()`:*
6. ✅ **`reason=crosspost`** (returns `'crosspost'`) → `Dropped` + `REASON_CROSSPOST`. Supersedes the repost-bump entries this section originally specified — commit `d5f0b4983` replaced coordinate-based repost/crosspost detection with TN's own source-vs-copy distinction (a post carrying a `group_id` is a per-group copy and is discarded; reposts are no longer de-duplicated at all). **This is an intended divergence, not a parity gap**: the email path receives one email per TN group and ingests each as its own message, so an item crossposted to N TN groups yields N email-path messages but ONE API-path message. The entry exists so that volume difference is explained in the stream rather than reading as posts going missing.
7. ✅ **`reason=prohibited`** (returns `'dropped'`) → `Dropped` with group + user context and **no** `routing_reason`, mirroring email case 6.
8. ✅ **`createMessage()` returned null → `'skipped'`** → `Dropped` + `REASON_MESSAGE_CREATE_FAILED`. Note the deliberate divergence: email case 10 reports its pre-computed `Approved`/`Pending` and merely omits `message_id`, whereas this path genuinely returns `'skipped'`, so reporting Approved/Pending here would misstate what happened. Logged with an explicit reason so a comparison can account for it.
9. ✅ **Non-member `[POST-META]`** — no routing entry (correct: the API path continues rather than dropping). Asymmetry recorded here: the email path drops these (case 3, `Dropped`/"Post from non-member"), so an email-side `Dropped` with an API-side `Approved`/`Pending` for the same post is expected, not a regression.

*⬜ **Not implemented — deliberately deferred.** Sub-failures still `Log::warning`-only and invisible in Loki. The email path is equally silent on all of these, so they cost nothing in parity terms; they are operational diagnostics only, and belong on `source=batch_event`, never in the routing stream (one routed entry per post is what makes the comparison countable). Pick these up if/when they actually bite:*
10. `[LOCATION-STALE]` — spatial index returned a `locationid` not present in `locations`; post ingested with no location.
11. `addToSpatialIndex()` failure — approved message never becomes searchable.
12. `notifyGroupMods()` failure — pending work never surfaced to mods.
13. Photo download / tusd upload / attachment failures in `createImageAttachments()`.

*Never occurs on the API path, by design — record as intentionally absent so it is not read as a gap:*
14. Spam (case 5). Per resolved decision #4 the spam check is always skipped for API posts, exactly as `shouldSkipSpamCheck()` does for TN emails. No API path can ever produce `subtype=IncomingSpam`.

#### I.5 Shape the API path's entries must take

To make the two streams comparable with a single Loki query, the API path must write **the same schema, into the same file, with the same label set and the same subtype vocabulary**:

- **File**: `incoming_mail.log`, via a new `LokiService::logIngestedPost()` (or a `$source` parameter on `logIncomingEmail()` — do not duplicate `writeLog()` call sites).
- **Labels**: `app=freegle`, `type=routed`, `subtype=<RoutingResult value>` — identical. **`source` must differ** (proposal: `source=tn_api`): identical `source` would silently merge API posts into the ModTools incoming-email dashboard (§I.2), which is a member-facing view of *email* traffic. Differing on exactly one label is what makes a side-by-side diff possible at all — identical on every label means the two streams are indistinguishable. ⚠️ **Decision needed** — see Open items.
- **Subtype mapping** — the API path's lowercase result strings must be mapped to the email path's `RoutingResult` values, not logged raw:

  | API result | `subtype` / `routing_outcome` |
  |---|---|
  | `approved` | `Approved` |
  | `pending` | `Pending` |
  | `dropped` | `Dropped` |
  | `skipped` | `Dropped` |
  | `duplicate` | `Dropped` (+ `routing_reason=duplicate`) |
  | `crosspost` | `Dropped` (+ `routing_reason=crosspost`) — no email-path analogue; an intended divergence, see I.4 #6 |

- **Message fields** — same keys, synthesized from the same values the RFC822 blob already uses (section C), so a diff compares like with like:
  - `envelope_from` → `null`/`''` (API path has none; email path has the TN sender)
  - `envelope_to` → `$groupEmail` (matches `messages.envelopeto`)
  - `from_address` → `null` (matches `messages.fromaddr`)
  - `subject`, `message_id` (synthesized `{postid}@tn.trashnothing.com-{groupid}`), `routing_outcome`, `routing_reason`, `group_id`, `group_name`, `user_id`, `message_id` (FD msgid)
  - **plus `tn_post_id`** on the API side only — see I.5a for why the email side cannot carry it and how the join works instead.
- **`routing_reason` strings must be byte-identical** to the email path's where an analogue exists (`"Post to unknown group"`, `"Post from unknown user"`, `"Post from non-member"`), so a diff can group on them. New API-only reasons (`no-coordinates`, `not-in-any-group-bounds`, `crosspost`, `duplicate`, `message-create-failed`, `ingestion-exception`) get their own distinct, documented strings.
- **Dry-run gating**: unlike DB writes, Loki entries should be emitted in `--dry-run` too (that is the whole point of a parallel-run comparison), tagged `dry_run=true` as `findOrCreateUser()` already does. Confirm this does not pollute production dashboards before enabling.

#### I.5a Correlating the two streams — the email side cannot carry `tn_post_id`

**The email path is frozen for this work, and the freeze explicitly includes its Loki output.** No new field may be added to `logIncomingEmail()`'s payload, to `getLastRoutingContext()`, or to the `IncomingMailController`/`IncomingMailCommand` call sites. An earlier draft of this section proposed adding `tn_post_id` caller-side; **that is ruled out.**

That constraint is binding, because the TN post id is genuinely not recoverable from the email-side Loki line as it stands:

- It lives in the `X-Trash-Nothing-Post-Id` header (`ParsedEmail::getTrashNothingPostId()`, `ParsedEmail.php:361`), which is never written to Loki.
- It is **not** derivable from the logged `message_id`. A real TN email's `Message-ID` is TN's own and does not contain the post id. (`EmailReplaySyncer::buildRawEmail()` *does* synthesize `{postid}@tn.trashnothing.com` at line 225 — but that is the replay harness fabricating a header, not what production email carries. Do not build the join on it.)

So the correlation must be done **outside Loki, in two tiers**:

**Tier 1 — exact, for entries that created a message** (`Approved`, `Pending`, `IncomingSpam`). Here `getLastRoutingContext()` sets `message_id` to the FD msgid, and because `array_merge()` puts the context last, that value **overwrites** the RFC822 `message_id` in the payload. So the email-side Loki line carries the FD msgid, and `messages.tnpostid` resolves it to the TN post id with a single DB lookup. Join that against the API side's own `tn_post_id`. Exact, and needs zero email-path change — it works precisely *because* of the existing key collision noted in I.2.

**Tier 2 — aggregate only, for entries that created no message** (`Dropped`, `ToSystem` — cases 1, 2, 3, 4, 6). There is no message row, therefore no `tnpostid` anywhere, therefore **no per-post join is possible from Loki for these outcomes at all**. Compare them by **distribution**: counts per `subtype` × `routing_reason` over the same time window, email vs API. A per-post answer for these cases has to come from `tn:parity-check`'s trace lines, which do carry `post_id` on both sides.

This split is a real limitation of the Loki comparison, not a temporary gap — accept it and scope the Loki work accordingly. Loki answers *"is the outcome mix the same, and is volume holding up?"*; `tn:parity-check` answers *"did post X land identically?"*. Do not try to make Loki do the second job.

#### I.6 Comparison-harness limitation

`EmailReplaySyncer::sync()` — the email side of `tn:parity-check` — calls `IncomingMailService::route()` **directly** and never calls `logIncomingEmail()` (only `logEvent('tn-sync', 'email-replay', …)`). So a `tn:parity-check` run produces **no** `source=incoming_mail` entries, and the Loki comparison cannot be exercised through the parity tool.

~~Adding `logIncomingEmail()` to `EmailReplaySyncer` would fix that... treated as in-scope for the freeze and left alone.~~

✅ **SUPERSEDED — `EmailReplaySyncer` now emits `logIncomingEmail()`**, on the explicit instruction to make `tn:parity-check` compare Loki logs (see section Q, Layer 5). The freeze is not violated: `IncomingMailService` is untouched, and the syncer is the email path's *caller* within the harness — the same role `IncomingMailController` plays in production — so emitting there reproduces production behaviour rather than changing the path.

**Consequence, updated: Loki parity IS now verifiable from `tn:parity-check`**, against both fixtures and live data, and is a hard-fail layer. The earlier conclusion that it could only be checked against real production traffic no longer holds.

#### I.7 Ordering note

`logEvent`'s existing `tn-sync` events (ratings, user-changes, user-merge, user-stub-create) are a *different* concern from post-routing parity and should stay on `batch_event.log` as they are. Only the per-post routing outcome moves to the `incoming_mail`-shaped stream. `user-stub-create` is the ambiguous one: it has no email-path analogue (the email path *drops* an unknown user, case 2, where the API path creates a stub and continues), so it stays a `batch_event` diagnostic, but the divergence must be noted in the comparison so an email-side `Dropped/"Post from unknown user"` with an API-side `Approved` is not read as a regression.

### J. Dry-run / fixture-test parity
- Every DB-write call in new services must respect `dryRun`.
- Convention: services receive `bool $dryRun` in the constructor.
- Fixture files at `tests/fixtures/tn_sync/posts_page_*.json` and `chat_messages_page_*.json` — schema decided before generation.
- Trace log format: resolved as key=value, not JSON — see Resolved decisions #3.

### K. Feature flag / rollout ✅
- `config('freegle.trashnothing.ingest_posts_via_api')` (default false) added to `config/freegle.php`.
- `TNSyncCommand` skips `PostSyncer` unless flag is true OR `--local-testing` is set; emits `TN-SYNC-TRACE [POSTS-SKIP] reason=feature-flag-off` when skipped.
- ~~Separate flag to disable email path once parity proven — flipped much later. Both flags on = double-write (needs idempotency from B).~~ **Superseded (2026-08-19): ONE flag.** The staged pair was collapsed into `FREEGLE_TN_INGEST_POSTS_VIA_API`, because neither half is safe on its own — API-on-with-email-routing double-writes every post, email-off-with-API-off drops them all — and pre-cutover comparison uses `tn:sync --dry-run` / `tn:parity-check`, neither of which needs the email path off. The flag now drives, in one step: `PostSyncer` (`TNSyncCommand`), the email-routing skip (`TnEmailRoutingGate`, replacing `FREEGLE_TN_SKIP_EMAIL_ROUTING`, which is removed), the `tn:verify-email-coverage` schedule and its own guard, the `tn:sync (posts)` outcome check, and the lifting of the TN exclusion in `ExpandService::rippleIntoNewGroups` (TN posts now live on one group, so Freegle's rippling does the cross-posting). `GroupPostIngestionService` also stamps `sourceheader = 'TN-API'` rather than `'Email'`, so the three `TN-` consumers — LoveJunk invoicing, LoveJunk source attribution, and the freebie-alert skip — keep working after the cutover.

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

### Q. Parity check redesign — coverage-first, five-layer model ✅ complete (`TNParityCheckCommand` rewritten; logic extracted to `ParityComparer`; `EmailApiParityTest` rewritten with per-layer fixture coverage)

`TNParityCheckCommand` (`tn:parity-check`) currently asserts the email path and API path produce **byte-identical** `TN-SYNC-TRACE` output — an exact line-for-line diff (`normalizeLines()` + `===` comparison). That model is wrong: the two paths are not supposed to be identical.

- The API path is a **superset** — it should ingest every post the email path does, and may additionally ingest posts the email path never saw (e.g. TN posts with no corresponding email, or where the email never arrived).
- The API path resolves its group **independently**, via `Location::groupsNear()` on the post's own coordinates (section P / Resolved decision #7), while the email path resolves its group from the recipient address. The two are expected to legitimately disagree on which group a post lands in.

Byte-identical trace diffing can't express either of these — it fails the moment the API path processes one extra post or picks a different (correct) group. The replacement model is coverage-first, not equality-first. Four layers compare what each path wrote to the database; a fifth compares what each path reported to Loki:

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

**Layer 5 — Loki entry parity (hard fail).** ✅ **Implemented.** Layers 1-4 all compare what each path wrote to the **database**. Layer 5 compares what each path reported to **Loki**, which is a separate contract: the two streams are consumed together by one query spanning both `source` values, so a difference in entry shape breaks that comparison even when the ingestion itself was correct — and no other layer can see it.
  - **Correlation.** Each path's *caller* emits a `TN-SYNC-TRACE [LOKI] post_id=<id> entry={json}` line carrying the entry it just wrote — `EmailReplaySyncer` for the email path, `PostSyncer` for the API path, mirroring where production emits (`IncomingMailController` / `PostSyncer`). Traced rather than read back from `incoming_mail.log` because the email path's entry carries no `tn_post_id` (section I.5a) and could not otherwise be correlated per post. `LokiService::logIncomingEmail()`/`logIngestedPost()` now return the entry they wrote (previously `void`) to make this possible; callers ignoring the return are unaffected.
  - **Two levels of check**, split on the same gate as Layers 3/4:
    - *Outcome-INDEPENDENT structure*, checked for **every** overlapping post — label **key** set, the two expected `source` values, presence of the always-written message fields (`envelope_from`, `envelope_to`, `from_address`, `subject`, `message_id`, `routing_outcome`), and internal consistency between the `subtype` label and the `routing_outcome` field (a dashboard filtering on one and reading the other would silently disagree if they drifted).
    - *Outcome-DEPENDENT*, only where both paths landed the post on the **same group** — label **values** (`subtype` is the outcome), the context key set, and the compared field values.
  - ⚠️ **Bug found and fixed on live data.** The first implementation compared label values and message key sets unconditionally, treating them as shape. They are not: both legitimately vary with the routing branch — that variation *is* the context-omission mirroring section I.3 depends on (case 2 = reason only; case 6 = group/user, no reason; case 10 = group/user, no `message_id`). On the 2026-08-04 window, `post_id=47102958` had already been ingested by an earlier run, so the email path took its duplicate-`messageid` branch (case 10: Pending, group/user context, no `message_id`) while the API path took `postAlreadyExists()` (Dropped, `routing_reason` only). Both correct; Layer 4 already reported the pair as a divergence — but Layer 5 double-reported it as a hard failure. Fixed by the two-level split above; regression covered by `test_layer5_does_not_flag_divergent_outcomes_that_layer4_already_explains`, with `test_layer5_still_flags_structural_breakage_on_a_divergent_pair` pinning that the structural checks still fire on such pairs. Re-run on the same window: **PASS** (`compared=0 structure-only=1 mismatches=0`).
  - `layer5StructureOnly` counts the pairs that got structure-checked only, so the report distinguishes them from fully-compared ones.
  - **Excluded by design**, asserted rather than ignored silently: `envelope_from`/`from_address` (the API path has no SMTP envelope), `message_id` (each path created its own `messages` row, so the numeric ids differ by construction), and `user_id` when either side stub-created the poster this run (reusing `parseStubUserIds()`, the same gate `classifyOverlapPost()` applies to `result`).
  - **A post handled by one path but reported to Loki by neither/only one** is itself a Layer 5 failure — that item silently vanishes from the stream.
  - **`lokiEntriesSeen`** distinguishes "compared and clean" from "nothing to compare" (Loki disabled for the run); the report prints `[NOT CHECKED]` in the latter case rather than showing a silent zero that reads as a pass.
  - **Enabling.** `TNParityCheckCommand::isolateLokiOutput()` force-enables Loki for the run and redirects `log_path` to a throwaway directory, so replaying real posts through both paths cannot inject duplicate entries into the production stream ModTools' dashboards read.
  - ⚠️ **This required `EmailReplaySyncer` to emit `logIncomingEmail()`** — reversing the "closed as ruled out" decision recorded in I.6, on the explicit instruction to make `tn:parity-check` compare Loki logs. `IncomingMailService` is still untouched: the syncer is the email path's *caller* in the harness, the same role the controller plays in production, so this reproduces production behaviour rather than altering it.
  - **Verified to catch what the other layers cannot**: sabotaging only the API path's Loki `subject` (leaving every DB write correct) failed Layer 5 alone, with Layers 1-4 still green.

**Live-run findings, 2026-08-19 — clearing the noise before a 30-day sweep.** A one-hour window (`--date-min=2026-08-08T14:00:00Z --date-max=...T15:00:00Z`) reported `Layer 3 mismatches=3`, `Layer 5 mismatches=11`. Triaged, only one of the eleven was a real difference; the rest were tool artifacts that would have swamped a wide sweep:
  - ✅ **Layer 5 did not apply Layer 3's stub-user gate.** Four posts reported `email=Pending api=Approved` as Layer 5 hard failures while Layer 3 reported zero mismatches for the same posts — Layer 3 excuses a routing difference when either side stub-created the poster this run (`parseStubUserIds()`), Layer 5 did not, so it hard-failed on exactly the divergence Layer 3 had just dismissed. This is the same class of bug as the Layer 4 double-reporting fixed earlier. Fixed in `diffLokiOutcome()`: one `$outcomeComparable` gate now covers both copies of the outcome (the `subtype` label and the `routing_outcome` field) and the `user_id` check that already had it. Regression tests: `test_layer5_does_not_flag_an_outcome_difference_on_a_stub_created_poster`, plus `test_layer5_still_flags_an_outcome_difference_on_a_resolved_poster` pinning that a genuine divergence on two already-resolved users still fails.
  - ✅ **`OFFERED:` vs `OFFER:` counted as a content mismatch.** The email path keeps the prefix TN put in the email subject (what the member typed); the API path always synthesizes `strtoupper(type) . ': '`. Both parse to the same `Message::determineType()` and `type` is compared separately, so the prefix is a naming convention, not content — but it failed Layer 3 *and* Layer 5 on 2 of the 31 overlapping posts. `ParityComparer::normalizeSubject()` now canonicalizes the prefix before comparing (`SUBJECT_PREFIX_CANONICAL`). A genuinely edited title still fails, which is correct: the third Layer 3 mismatch, `post_id=47116976` "Table frame" vs "Table frame & glass top", is TN mutating a post's title after its email went out — the same class as the `date` bump in the Layer 1 notes above, and worth seeing.
  - ✅ **Real ingestion bug, not a tool artifact: the API path was ingesting thumbnails.** `GroupPostIngestionService::bestPhotoUrl()` took `images[0]`, under a docblock claiming it "prefers the highest-resolution image". TN documents `images` as ordered *smallest to largest* (`PublicApi/docs/Model/Photo.md`), so it took the smallest — observed live fetching a `220x294` thumbnail for a post where the email path scraped the `1200x900` original. Fixed to take the last entry, falling back to `url` ("a large version of this photo"). It was invisible to all five layers because `message_attachments` writes were not traced by the API path (next item), so fixing that trace is what makes this class of bug observable at all.
  - ✅ **The API path emitted no `[WRITE] table=message_attachments` line outside `--dry-run`**, while the email path emits one unconditionally — so on a live parity run the email path appears to write attachments and the API path appears to write none. Now emitted unconditionally on both, with the intended count.
  - ✅ **`[POST] group_id=` meant two different things.** On the email side it is the resolved *Freegle* group id; on the API side it is *TN's* group id (empty on a source post). Renamed the API side to `tn_group_id=`, the name `[POST-SKIP] reason=crosspost` already used.
  - ✅ **The dev `batch` container was uploading photos to production.** The `batch` service in `docker-compose.yml` set no `TUS_UPLOADER`, so it inherited the config default — `uploads.ilovefreegle.org` — and every parity run pushed real blobs to the production upload store, at a WAN round trip each (observed: a 120s curl timeout per photo when the link was slow, against ~2s for the local tusd). Now set to `http://tusd:8080/tus`, matching `apiv2`; `batch-prod` still sets the production value explicitly. The command's own docblock also claimed the API path ran `dryRun=true` and therefore suppressed TUS uploads — wrong on both counts, and corrected.
    - **Standing caveat, by design, not an open defect:** both paths still download every photo from TN and upload it for real, and the resulting blobs are orphaned once the transaction rolls back (the `message_attachments` rows referencing them are gone). The API path runs `dryRun=false` deliberately — transaction rollback is what makes the run safe, and the path has to write for real to exercise stub-user creation — so nothing suppresses uploads on either side. This is the dominant cost of a wide date window; check `TUS_UPLOADER` points somewhere local before a long sweep.

**Output**: plaintext summary counts per layer, plus lists of the failing `post_id`s (Layer 1 misses, Layer 3 mismatches, Layer 5 divergences) — not the full raw trace dump `TNParityCheckCommand` used to print. ✅ Implemented as designed.

**Real-run observation — two distinct causes of Layer 1 misses, confirmed against live TN data (2026-07-29):**
- **Boundary propagation lag** (self-resolving). The API path's own returned `max_date` can trail the query window's `to` by tens of seconds (e.g. one run returned `max_date=2026-07-29T18:41:54Z` against a `to` of `18:42:24Z`) — TN's public Posts API hadn't indexed the newest post(s) yet at query time. Confirmed self-resolving: re-running the identical `--date-min` window ~43 minutes later, the earlier boundary miss (`post_id=47081111`, "Rice cooker") was gone from the failure list. A production run on a schedule will simply pick these up next cycle — not a real regression.
- **Persistent TN-side data gap** (not a Freegle bug, does not resolve with time). Three posts — `post_id=47081039` ("Chest of drawers", Newlyn TR18), `47081059` ("dfs grey cord sofa", Southport PR8), `47081073` ("Filing cabinet", Fleet Hampshire GU51) — were present in TN's partner email feed (`fd-post-log.csv`) but never appeared in TN's public Posts API at all, confirmed by re-running the same window ~43 minutes later with all pagination fetched (`page=1 count=50` + `page=2 count=38`, correctly terminated). Each is chronologically sandwiched between other posts that *did* sync correctly on both sides, ruling out a pagination or window-boundary explanation. This looks like a genuine completeness gap in TN's own public API relative to their partner email channel, worth raising with TN directly. Until/unless resolved on TN's side, expect a small, low, roughly-constant trickle of Layer 1 misses on real runs that are not Freegle regressions — worth keeping in mind when triaging a `tn:parity-check` failure so a handful of misses isn't mistaken for a code regression.

**Live-data testing infrastructure — email-path stub user creation.** ✅ **Implemented.** Testing `tn:parity-check` against real live TN data (not local fixtures) requires a real `groups` table with `polyindex` boundary data, plus real `users`/`users_emails`/`memberships` for the specific posters in the test window — without matching users, every email-path post dropped as `unknown-user` before ever reaching `createGroupPostMessage()`, never exercising that code path. The API path already handles this (`GroupPostIngestionService::findOrCreateUser()` stub-creates a user keyed on TN's numeric `fd_user_id`); the email path had no equivalent, and per the guiding constraint `IncomingMailService` can never be touched to add one.
  - Fix: `EmailReplaySyncer::ensureUserExists()` (new, test-harness-only — this syncer is explicitly "not a scheduled job... meant to be run once against a disposable test database", never real production traffic) stub-creates a user + Approved membership before calling `route()`, mirroring the API path's approach. Only creates a stub if no matching `users_emails` row already exists; never touches an existing user.
  - ⚠️ **Consequence — `fromuser` excluded from Layer 3 comparison**: unlike the API path's stub, which keys on TN's real numeric `fd_user_id` (so repeat runs converge on the same row), the email path only has an email address to go on — no shared ID — so it always gets a fresh auto-increment id. An overlapping post now legitimately gets two different `fromuser` values when both sides had to stub-create the same real-world poster. Removed `fromuser` from `ParityComparer::diffMessageFields()`'s compared fields (rationale documented on the method) rather than accept permanent false-positive Layer 3 noise.
  - ⚠️ **Related bug found and fixed**: `EmailReplaySyncer::buildRawEmail()` only added the `X-Trash-Nothing-Secret` header when the injected secret value was non-empty (`!empty($record['secret'])`). `IncomingMailService::shouldSkipSpamCheck()` already has a fallback for an unconfigured secret ("accept any TN email with a secret header, regardless of value") — but that fallback only fires if the header is present at all, and with no `FREEGLE_TRASHNOTHING_SECRET` configured (true in this dev environment — no real production secret available locally), the injected value was `''`, so the header was silently omitted and the fallback never triggered. Every stub-created post was routing through the real spam classifier instead of skipping it, landing as `IncomingSpam` instead of `approved`/`pending`. This was invisible before the stub-user fix, since every post dropped at `unknown-user` first. Fixed by checking `isset()` instead of `!empty()` — confirmed live, `result=IncomingSpam` → `result=Pending` on re-run.
  - ✅ **`result` divergence for stub-created users — fixed** (superseding an earlier "known, unresolved limitation" note here). `tn:parity-check` runs live mode without `--dry-run` (writes for real, no transaction rollback — see the constructor comment on `$apiSyncer`), so state persists across runs, and identity resolution can diverge: if an earlier run already synced a real Freegle account for a TN member's `fd_user_id` (e.g. via `UserChangesSyncer`, with a real `lastlocation`), the API path finds that mapped, existing user and routes normally, while the email path — resolving by address, with no shared key — stub-creates a *separate*, unmapped new row, forcing `pending` regardless of posting status. Observed live: `post_id=47102938`, `result: email=pending api=approved`, alongside two more posts flagged purely on coordinate rounding. Rather than exclude `result` from Layer 3 comparison entirely (it's genuine signal when both sides resolve to a real user), both stub-creation sites now log the assigned id (`EmailReplaySyncer::ensureUserExists()` was updated to include `id=` on its trace line, matching `GroupPostIngestionService::findOrCreateUser()`'s existing format), and a new `ParityComparer::parseStubUserIds()` builds a per-side set of ids created *this run*. `classifyOverlapPost()` now only compares `result` when neither side's `fromuser` is in that post's stub set — a routing difference on a freshly-stub-created poster reflects the identity divergence, not a regression. Confirmed live: re-running the exact window that previously flagged all 3 as Layer 3 failures now reports 0 mismatches, full **PASS**.
  - ✅ **Coordinate float-precision bug found and fixed**: `lat`/`lng` round-trip through different code paths (email: header parsing; API: JSON decoding) and can differ in float precision for the same real-world coordinate. Initially rounded to 6dp (~11cm) via `COORDINATE_FIELDS`/`COORDINATE_PRECISION`, but that still false-positived live (`51.360871130429` vs `51.36087`; `51.232574885119` vs `51.232574`) because the API's own value had already lost precision beyond ~6 significant digits before rounding even applies. Reduced to 4dp (~11m), confirmed sufficient against all observed live cases.

**Crosspost/repost de-duplication.** ✅ **Implemented.** Live testing surfaced a large asymmetry (4 email posts vs 76 API posts in one 9-minute window) — investigated by extracting and checking every API post's lat/lng against a UK bounding box, confirming this wasn't bogus non-UK placements leaking through (all 76 checked out as genuine UK locations; TN's public `/posts/all` is simply a much broader firehose than the curated partner email feed, which is the intended benefit of the API path). But TN confirmed a repost creates an entirely new `post_id` with a new published date (not a mutation of the original), and cross-posting to multiple groups likewise gets a distinct `post_id` per group — so counting each of these TN-side duplicates as a separate "new" post would double-count the same real-world donation. Freegle already has its own cross-posting (rippling) and reposting mechanisms, so this dedup happens purely in the parity tool's counting, not in real ingestion.
  - `ParityComparer::dedupeApiCrosspostsAndReposts()` (new) groups API-side ingested posts (a messages row must exist — nothing to compare without one) by `(fromuser, subject, rounded lat, rounded lng)` and keeps only the earliest-dated `post_id` per group; every other `post_id` in that group is dropped entirely from `apiResults`/`apiMessages` before any layer is computed, not just from the Layer 2/ingestion-gain stats. Runs before `apiResultPostIds` is derived, so a dropped duplicate is invisible everywhere downstream.
  - No email-side equivalent needed: TN's partner email feed reuses the *same* `post_id` across a crosspost's emails (confirmed early in this project), so `parseResults()`'s post_id-keyed map already collapses email-side duplicates naturally.
  - New `computeLayers()` return key `apiDuplicatesDropped` (the dropped `post_id`s), surfaced in the report as its own informational section plus a summary count line, deliberately separate from the four layers (dedup happens before layer computation, so it's not really "Layer 0" — just a preprocessing step worth being visible about).
  - Confirmed live on the exact window that raised the question: 1 genuine duplicate found and collapsed (`47102946`, a repost/crosspost of `47102938` "Electric sander," identical subject and coordinates) — correctly leaving every other same-coordinate cluster alone once their subjects were checked (e.g. four "gents/rayleigh mountain bike" listings at the same spot were genuinely different items posted close together, not TN duplicates), confirming the `(fromuser, subject, lat, lng)` key doesn't over-merge.

**Implementation notes**:
- No changes needed in `GroupPostIngestionService` — every trace line needed is already emitted. One correction found in `IncomingMailService`/`EmailReplaySyncer`: see the Layer 1 note above (`[EMAIL-RESULT]`, not `[POST-RESULT]`, is the email path's reliable outcome marker).
- ✅ The parsing/layer comparison logic (`parseResults`/`parsePreIngestSkips`/`parseMessages`/`parsePostDetails` + `computeLayers`/`classifyOverlapPost`/`diffMessageFields`/`formatLayer1Detail`) was extracted out of `TNParityCheckCommand` into a new standalone class, `App\Services\TrashNothing\Sync\ParityComparer`, so it's unit-testable independently of the CLI. `TNParityCheckCommand` now just runs both paths, calls `(new ParityComparer())->computeLayers(...)`, and prints the report (`captureTraceLogs`/`printReport` stay on the command; `normalizeLines`/`printLineDiff`/the old `===` check are gone).
- ✅ `EmailReplaySyncer` and `PostSyncer` each gained an optional trailing constructor param (`?string $fixtureCsvPath`, `?string $fixtureDir`) to override the hardcoded `--local-testing` fixture path/directory, defaulting to the original shared fixtures when omitted — needed so each parity test scenario can point at its own dedicated fixture files without touching the shared `tests/fixtures/tn_sync/{fd_post_log.csv,posts_page_1.json}`.
- ✅ `tests/Feature/TrashNothing/EmailApiParityTest.php` rewritten from the old byte-identical diff to 10 tests against `ParityComparer` (one "flags the issue" + one "silent when clean" per layer, the four silent cases sharing one `all_clean` fixture), using 5 new dedicated fixture pairs under `tests/fixtures/tn_sync/parity/{all_clean,layer1_missing,layer2_extra,layer3_mismatch,layer4_divergent_group}/`. Tests construct `EmailReplaySyncer`/`PostSyncer` directly (not via artisan) to pass the fixture-path overrides.
  - Found and fixed a real issue while building the `all_clean` fixture (verified via `artisan tinker`, not guesswork): a `DEFAULT`-status membership walks into an existing, already-documented divergence — `IncomingMailService::handleGroupPost()` no longer auto-approves `DEFAULT` posters on arrival, while `GroupPostIngestionService::ingest()` still does — so even byte-identical content routes to different outcomes (`pending` vs `approved`). Switched the shared `seedParityUser()` helper to `MODERATED` (pends on both paths), matching the original test's approach, with the reasoning documented on the helper.

### R. Repost vs crosspost de-duplication in production ingestion ⚠️ REVISED TWICE — now a one-line `group_id` check

> **Status as of 2026-08-12.** This section's original coordinate/subject-matching "repost bump" design is gone, replaced first by a group-scoped crosspost matcher and then — once TN's actual data model was understood — by a trivial field check.
>
> **TN's data model (the key fact, confirmed 2026-08-12):** TN stores a post as a **source post**, which carries **no `group_id`**, plus **one copy per group** it was sent to, each carrying a **`group_id`**. The copies exist so each group's moderators can approve/deny/edit their own copy independently. So *every* post with a `group_id` is by construction a per-group copy — a crosspost — and the whole of crosspost detection collapses to:
>
> ```php
> $tnGroupId = $this->getField($post, 'group_id', 'getGroupId');
> if ($tnGroupId !== null && $tnGroupId !== '') {
>     return 'crosspost';   // per-group copy; Freegle cross-posts via rippling
> }
> ```
>
> | TN action | What TN emits | API path does | Same as email path? |
> |---|---|---|---|
> | **Post to one group** | source post (no `group_id`) + 1 copy | ingests the source, discards the copy | ❌ no |
> | **Crosspost to N groups** | source post + N copies | ingests the source, discards all N copies | ❌ no |
> | **Repost / bump** | a new source post (no `group_id`) | creates its own new FD message | ✅ yes |
>
> **Reposts** are deliberately not de-duplicated — the email path doesn't, so neither does this one. A repost is a new *source* post, so it has no `group_id`, passes the check and creates its own message, exactly as it does when it arrives by email.
>
> **`group_id` is used only as a has-it/hasn't-it flag.** Its *value* is still never used for placement — resolved decision #7 stands (it is TN's own opaque internal id, which drifts out of step with Freegle group boundaries); `PostSyncer` still resolves the group from the post's own coordinates via `Location::groupsNear()`.
>
> **Removed by this simplification** (all of it now dead): `CROSSPOST_MATCH_RADIUS_METERS`, `findCrosspostCandidate()`, `normalizeSubjectForCrosspostMatch()`, `haversineMeters()`, and before them `bumpAsRepost()`, `normalizePostDate()`, the `messages.date` idempotency check, the `'reposted'` result and the `post-repost-bump` Loki event. ~340 lines of heuristic matching replaced by one field test. The crosspost branch writes nothing at all, so it needs no idempotency handling; `postAlreadyExists()` still covers the same `post_id` arriving twice across overlapping sync windows.
>
> **Everything the heuristic could get wrong is gone with it**: no more 50m-radius false matches, no more "two different people posting identical subject text nearby", and no more repost-that-moved-groups being misread as a crosspost. It is now exact.
>
> **Fixtures corrected**: every post in `tests/fixtures/tn_sync/posts_page_1.json` and `tests/fixtures/tn_sync/parity/*/posts_page_1.json` carried a `group_id`, written when we believed `group_id` was simply always present. Under the correct model those were all per-group copies and would now be discarded wholesale, so they have been changed to `"group_id": null` — they are meant to represent ingestable source posts. `makePost()` in `GroupPostIngestionServiceTest` likewise now defaults to `null` instead of `'TestGroup'`.
>
> ⚠️ **To verify against live data before go-live**: that `/posts/all` actually returns source posts and not only per-group copies. If the public feed carried copies alone, this check would discard the entire feed. Suggested check: count `group_id === null` over a live window with `tn:parity-check`, and confirm the count is in line with the number of distinct real items.
>
> **Knock-on items** (open, see Open items):
> - ✅ **`ParityComparer` updated**: `dedupeApiCrosspostsAndReposts()` (the `(subject, lat, lng)` heuristic) is deleted, replaced by `excludeApiCrossposts()`, which drops any post_id whose result is `crosspost` from `apiResults`/`apiMessages` before any layer is computed. Discarded copies therefore count nowhere — not as Layer 1 coverage, not as Layer 2 extras, not in the ingestion-gain figure — and reposts, which the heuristic used to collapse, are now counted in full. Return key renamed `apiDuplicatesDropped` → `apiCrosspostsDiscarded`; `tn:parity-check`'s summary line and detail section updated to match.
> - The old `bumpAsRepost()` re-pointed `messages.tnpostid` at the newest post_id so TN's inbound `PATCH /message/tn/:tnpostid` (iznik-server-go) would still resolve after a repost. Each repost now has its own row and resolves on its own, but the rows are independent — an edit sent against one post_id does not touch the other.
> - **TN-side edits/moderation now only reach us via the source post.** Since we discard the per-group copies, any edit or moderation a group's mods make to *their copy* on TN is invisible to Freegle. That is the intended trade (Freegle moderates its own single message), but it is a behaviour change worth stating explicitly.
> - `ExpandService`'s blanket "never ripple a TN post" guard is now the thing standing between "one FD message per TN item" and "that item reaching only one group". With crossposts discarded, lifting this guard is what makes rippling actually cover the groups TN used to cover. Sequence the two together.
>
> The original design and its rationale are preserved verbatim below for the record — **it no longer describes the code.**

Section Q's crosspost/repost dedup (`ParityComparer::dedupeApiCrosspostsAndReposts()`) only affected the parity tool's *counting* — it never touched real ingestion. But the same TN behavior it was compensating for (a repost/bump creates a brand-new `post_id` with a new published date rather than mutating the original) applies to production API ingestion too: left alone, `GroupPostIngestionService` would create a second, separate FD message for what's really the same donation being bumped. Freegle already has its own bump/repost UI and `AutoRepostService`, so the right behavior is to detect this case in `GroupPostIngestionService::ingest()` itself and bump the existing message rather than insert a new one.

**Match key** — normalized subject + coordinate proximity (≤`REPOST_MATCH_RADIUS_METERS` = 50m), deliberately **excluding `fromuser`** and, as of the fix below, **not scoped to any one group**. `fromuser` was the initial design (confirmed via `AskUserQuestion`) but live testing against the real "Electric sander" repost pair (`post_id` `47102938`/`47102946`) disproved it: TN assigns a different numeric user id per group-affiliation for the same real person (`99010031`/`99010032` on repeated runs vs `5595742` for what all other evidence — identical subject, identical coordinates, sequential post ids/dates — showed was the same poster and the same physical item). Re-confirmed via `AskUserQuestion`: dropped `fromuser` from the match. `findRepostCandidate()` searches live (Approved/Pending, not deleted, no resolved outcome) messages, filtered in PHP by normalized-subject equality (`normalizeSubjectForRepostMatch()` — lowercase/trim/collapse-whitespace) and haversine distance.

**Group scope — widened to global, not per-group.** ✅ **Fixed.** The initial implementation scoped `findRepostCandidate()`'s search to the newly-resolved `$group` only. That missed the crosspost case entirely: TN gives a crosspost to another TN group its own distinct `post_id`, resolved independently via `Location::groupsNear()` on its own coordinates — exactly like a repost, and just as capable of landing on a *different* Freegle group than the original. Explicit instruction: "crossposts from TN should not result in multiple posts on FD — FD handles all crossposting itself, so each unique item from TN should only be ingested once." Fixed by dropping the `mg.groupid = $groupId` filter from `findRepostCandidate()` entirely — it now searches across all groups — and returning the candidate's own `groupid` so `ingest()`/`bumpAsRepost()` bump the message in *its* group (wherever that is), never create a new one in the newly-resolved `$group`. Removing the group filter without adding something else back would have broken the query in a different way: the old `ORDER BY mg.arrival DESC LIMIT 50`, unscoped, would just return the 50 most recently-active messages system-wide — almost never the real candidate. Replaced with a coordinate bounding-box `WHERE` clause (±`REPOST_MATCH_RADIUS_METERS` converted to degrees lat/lng) so the query stays narrow and correct at the new global scope; `haversineMeters()` still does the precise circular check within that box. Covered by a new test, `test_crosspost_to_a_different_group_bumps_the_original_instead_of_creating_a_second_message` — two groups at the same coordinates, original message seeded in group1, a second TN `post_id` resolves to group2, asserts the group1 message is bumped and no messages/messages_groups row is created for group2. Full test suite (5148 tests) passed.

**Idempotency** — inferred from existing message state rather than a new tracking table (confirmed via `AskUserQuestion`). The comparison field is `messages.date` (the latest TN content date the candidate reflects), **not** `messages_groups.arrival` (ingestion wall-clock time). This was a real, initially-shipped bug: `arrival` is always "now" at ingestion time, so `arrival >= repost's own date` was true unconditionally (the repost's TN date is necessarily in the past relative to when we process it) — every genuine repost was silently classified `reason=repost-already-bumped` and dropped instead of bumping. Found via a direct `tinker` reproduction using the real group (id 126680) and real coordinates/subject from the "Electric sander" pair. Fixed by comparing against `messages.date` instead, and having `bumpAsRepost()` advance `messages.date` to the repost's own date when it fires.

**Behavior**: when `findRepostCandidate()` finds a match and the idempotency check doesn't short-circuit it, `bumpAsRepost()` updates `messages_groups.arrival`/`autoreposts` (+1), advances `messages.date`, inserts a `logs` row (`subtype=Repost`, `user` = the *original* poster's `fromuser`, not the new post's resolved user — the message being bumped still belongs to whoever originally posted it), and inserts a `messages_postings` row (`repost=1`, `autorepost=0`) — mirroring `AutoRepostService::repost()` as the reference pattern rather than extracting/reusing it (different trigger, same DB shape). `ingest()` returns `'reposted'` in this case; the idempotent-skip case returns `'duplicate'` as before.

**Confirmed live** (2026-08-05, `tn:parity-check --date-min=2026-08-04T14:00:43Z --date-max=2026-08-04T14:09:43Z`, real "Electric sander" pair): on the API path, `47102946` ingested first as a new message (`msgid=1286`); `47102938` was then correctly matched against it and skipped with `reason=repost-already-bumped` instead of creating a second message — the two TN `post_id`s now merge into one FD message via production ingestion itself, not just in the parity tool's counting. Full test suite (5147 tests) passed after the fix.

**Follow-up on `ParityComparer::dedupeApiCrosspostsAndReposts()`**: its dedup key was still `(fromuser, subject, lat, lng)` — the exact key the production fix above had already proven unreliable, since TN assigns a different numeric user id per group-affiliation for the same real person. Fixed by dropping `fromuser` from this key too, matching `findRepostCandidate()`'s reasoning — the key is now `(subject, rounded lat, rounded lng)` only. Re-run against the same live "Electric sander" window: output unchanged (`api ingested=71`, `collapsed=0`), confirming the fix is safe — that window has no genuine cross-group crosspost to exercise the changed path. Now that production ingestion itself matches across all groups (not just the same one — see above), this parity-tool-side dedup is likely fully redundant: a production-side crosspost/repost already comes back as `result=reposted`/`duplicate` on its second `post_id`, which `INGESTED_RESULTS` (`approved`/`pending` only) already excludes from every count, without needing the tool's own pre-layer dedup step at all. Not yet removed — left in place as a harmless no-op pending confirmation, since deleting it wasn't asked for and it costs nothing to keep. Worth revisiting if it's ever seen actually collapsing something on a live run again (it shouldn't, post-fix).

### M. NOT in scope
- Modifying `IncomingMailService` in any way.
- Extracting/sharing helpers between email + API paths during parallel running.
- Removing SMTP receivers or any TN-aware code paths in the mail pipeline.
- Chat messages — TN chat replies continue via the email path indefinitely (no TN chat API).

## Open items still to resolve

- ~~**`subtype` for `result=reposted` (section I).**~~ **Closed 2026-08-12 by deletion** — there is no `reposted` result any more (section R). A repost is a new source post that creates its own message, so it gets an ordinary `Approved`/`Pending` entry like any other post (`test_repost_emits_a_normal_routing_entry_like_any_other_source_post`).
- ~~**Section I's implementation is stashed, not in the working tree.**~~ **Reconciled 2026-08-12** — the stash was popped, its two conflicts in `GroupPostIngestionService` resolved in favour of the new source-vs-copy model (the orphaned `handleRepostCandidate()` wrapper, whose callees `d5f0b4983` had deleted, was removed), and the repost-bump Loki entries replaced by the crosspost entry below. `LokiService::logIngestedPost()` and the `PostSyncer` routing emissions are now on disk and covered by tests. The `PostRoutingLogger` class that draft introduced has since been deleted in favour of mirroring the email path's architecture directly — see the table at the top of section I.
- ~~**Whether `Dropped`/`crosspost` should stay in the routed stream given its volume.**~~ **Closed 2026-08-12: it stays.** Decided on the governing principle that **being 1:1 with the email path's Loki output is what matters**, ahead of any other consideration. The email path emits exactly one `type=routed` entry per item it handles, with no exceptions and no volume-based suppression, so the API path does the same — one entry per post, always. The alternative (crossposts on `batch_event` only) was rejected precisely because it would break that invariant: posts the syncer handled would be absent from the routed stream, so its totals would no longer reconcile against the feed and a genuinely missing post would be indistinguishable from a deliberately suppressed one.
  - Accepted consequence: `Dropped` will dominate a naive `count by (subtype)` panel, since TN emits one discarded copy per group per post. Filter on `routing_reason != "crosspost"` for outcome-mix comparisons. That is a dashboard-query concern, not a reason to log less.
  - **This invariant is now enforced structurally, not merely asserted**: emission is a single statement in `PostSyncer::processPost()` after `ingest()` returns, so a post cannot produce two entries — if `ingest()` throws, nothing was emitted and the catch emits once instead. `CapturesRoutedLokiEntries::onlyRoutedEntry()` additionally asserts exactly one entry in every test that reads the stream.
- Two earlier candidate decisions are **closed as ruled out** by the email-path freeze, with their consequences recorded in I.5a and I.6: adding `tn_post_id` to the email-side entry (join instead via the FD msgid → `messages.tnpostid`, which only works for outcomes that created a message — everything else is aggregate-only), and having `EmailReplaySyncer` emit `logIncomingEmail()` (so Loki parity is checkable only against live production traffic, never from `tn:parity-check`).
- See section P: the ModTools-side gate that reads `messages_groups.mod_messaging_allowed` before allowing a moderator to message a poster directly. Deliberately deferred — there's no existing contact-poster feature to gate, so this needs a concrete feature design before implementation, not just a wiring task.

### S. Post-cutover coverage verification via the incoming email archive ✅ implemented

**Files** (all additive; `IncomingMailService` untouched):
- `iznik-batch/app/Services/TrashNothing/Verify/TnEmailRoutingGate.php` ✅
- `iznik-batch/app/Services/TrashNothing/Verify/ArchiveInventoryService.php` ✅
- `iznik-batch/app/Services/TrashNothing/Verify/CoverageVerifier.php` ✅
- `iznik-batch/app/Console/Commands/TrashNothing/TNVerifyEmailCoverageCommand.php` — `tn:verify-email-coverage` ✅
- `PostSyncer::lookupPostById()` gains `group_id` + `post`; new `ingestFetchedPost()`; `RESOLVED_OUTCOMES` moved here from `TNParityCheckCommand` so both consumers share one definition ✅
- Tests: `TnEmailRoutingGateTest`, `ArchiveInventoryServiceTest`, `CoverageVerifierTest`, `TNVerifyEmailCoverageCommandTest` ✅

**Three deviations from the design below, all found during implementation:**

1. **The skip predicate needed widening in what it *excludes*.** The design said "targetGroupName !== null && TN post header". That is wrong: `MailParserService::analyzeEnvelopeTo()` strips the `-volunteers`/`-auto` suffix and *still* reports a `targetGroupName`, so the predicate as designed would have swallowed volunteer mail, which `route()` handles in Phase 4 — before group posts. `isToVolunteers`/`isToAuto` are now excluded explicitly, with `isChatNotificationReply()` checked defensively alongside. Covered by `TnEmailRoutingGateTest::test_ignores_volunteers_address_even_with_the_tn_header`.
2. **The archive's `routing_outcome` is now recorded, not left absent.** S.2 accepted losing it as a consequence of not calling `route()`. In fact the callers can still call `recordOutcome()` themselves, so they stamp `TnEmailRoutingGate::OUTCOME_SKIPPED` (`'SkippedTnApi'`) — deliberately not a `RoutingResult` value, since nothing routed it. Strictly better than the designed behaviour and free. The verifier still does not depend on the field.
3. **`unplaceable` is checked before the API lookup, not after.** The S.4 table implies the API call comes first. Checking placement locally first is both cheaper (a post that places nowhere needs no request against a 2-req/s limit) and safe: crossposts always carry real coordinates, so the local check cannot swallow one. Pinned by `CoverageVerifierTest::test_a_post_outside_every_group_boundary_is_expected_absent`, which asserts no lookup happens.

**One guard the design did not call for**: the command refuses to run unless `FREEGLE_TN_INGEST_POSTS_VIA_API` is on (override with `--force`). Both paths stamp `messages.tnpostid`, so while the email path is still routing, a "covered" post proves nothing about the API path — the check would report a clean bill of health that means nothing. The schedule entry is gated on the same flag.

**Alerting**: the command fails only on the escalations in S.5 (repeat miss, backfill cap breached, backfill error), never on a genuine miss alone — section Q's persistent TN-side gap means a small residue of misses is the expected steady state, and failing on it would train everyone to ignore the command. Volume-based alerting on the Loki counts covers the space in between.

---

Original design follows.

Once `FREEGLE_TN_INGEST_POSTS_VIA_API` is on and the email path is switched off, `tn:parity-check` stops working: Layers 3 and 5 compare what each path *wrote*, and the email path no longer writes anything. What remains necessary is Layer 1 — **coverage**: proof that every post TN emailed us also got ingested by the API path. This section designs a standing production check for exactly that, using the incoming email archive as an independent witness.

The email side is reduced from an *ingestion path* to an *inventory*. That is a large simplification: no disposable database, no rolled-back transactions, no running two paths in parallel. Just "here are the post ids that arrived by email; are they all in `messages`?"

#### S.1 Why the archive works as the witness

- Both production entry points archive the raw email **before** routing: `IncomingMailController::receive()` (`app/Http/Controllers/IncomingMailController.php:50`, the Postfix→HTTP path) and `IncomingMailCommand` (`app/Console/Commands/IncomingMailCommand.php:56`, CLI). Archiving therefore survives switching routing off, because it happens first.
- `batch-prod` bind-mounts `./iznik-batch:/var/www/html` (`docker-compose.yml:1682`), so `storage/incoming-archive` is on the host filesystem, and the same container both receives mail and runs the scheduler. No volume plumbing or cross-host copying is needed.
- Archive format (`IncomingArchiveService`): `storage/incoming-archive/YYYY-MM-DD/HHMMSS_rand.json`, containing `version`, `timestamp` (ISO-8601 UTC), `envelope.from`/`envelope.to`, base64 `raw_email`, and `routing_outcome` stamped afterwards by `recordOutcome()`.
- Retention is 48h, enforced hourly by `mail:cleanup-archive` (`routes/console.php:761`), deleting by **mtime**.

Chosen over the alternatives (TN's `fd-post-log.csv`, a Postfix-level sink, or a Loki-to-Loki diff) because it is already built, already running in production, and captures the raw email — so a flagged miss can be diagnosed from the actual message rather than from a post id alone.

#### S.2 Switching the email path off — caller-level skip

**Decision**: config-flagged early return in the two callers, after archiving, before `route()`. `IncomingMailService` stays frozen.

**The skip predicate must be precise, or chat breaks.** TN chat replies still arrive by email and remain in scope (section E). The two are disjoint by envelope recipient and both are visible to the caller after `parse()`:

- chat replies — Phase 2 of `route()`, gated on `isChatNotificationReply()` (`IncomingMailService.php:118`), i.e. `notify-`/`replyto-` addresses.
- group posts — Phase 5, gated on `$email->targetGroupName !== null` (`IncomingMailService.php:179`).

So skip **only** when `targetGroupName !== null` **and** `getTrashNothingPostId() !== null` (`ParsedEmail.php:361`). Anything else — chat replies, bounces, direct mail, non-TN group posts — routes exactly as before.

Accepted consequences, both of which follow from not calling `route()`:
- `recordOutcome()` never fires for these, so the archived `routing_outcome` stays absent. The verifier must not depend on that field.
- No `logIncomingEmail()` entry, so TN posts disappear from the ModTools incoming-email dashboard (`iznik-nuxt3/modtools/stores/emailtracking.js:407-450`). They are still visible on the API path's own routed stream under its own `source` label; extending the dashboard to query both sources is a follow-up, deliberately not in scope here.

#### S.3 The command

`tn:verify-email-coverage`, scheduled hourly, over a window `[now − lag − interval − overlap, now − lag]`.

- **lag = 8h** (decision: 6–12h). Comfortably inside 48h retention with ~39h of slack for a missed or repeated run, and orders of magnitude beyond the ~30s TN indexing lag measured in section Q.
- **overlap ≈ 15 min**, mirroring the sync's own backward overlap (section B). Re-checking a covered post is free and idempotent, so overlap generously rather than risk losing a boundary post.
- **Window from the JSON `timestamp` field, never file mtime** — `recordOutcome()` rewrites the file, so mtime means different things on different paths.

Pipeline:

1. Select the date directories intersecting the window, then prefilter on the `HHMMSS` filename prefix before opening anything.
2. Cheap substring scan of the decoded `raw_email` for `X-Trash-Nothing-Post-Id` to select TN posts; full `MailParserService::parse()` only on the hits, to recover post id, coordinates (`ParsedEmail::getTrashNothingCoordinates()`, `:377`) and subject. Avoids MIME-parsing every non-TN email in the window.
3. Batched `SELECT tnpostid FROM messages WHERE tnpostid IN (…)`. Present ⇒ covered, done.
4. Reclassify every absentee (S.4) before calling it a miss.
5. Auto-ingest genuine misses under the rails in S.5.
6. Report via `logBatchJob('tn:verify-email-coverage', …)` so it is queryable and alertable alongside the existing streams.

#### S.4 Reclassifying absentees — the part that matters

A naive existence check produces constant false alarms. **Decision: one `GET /posts/{id}` per absentee**, reusing the machinery already built for `tn:parity-check`.

`PostSyncer::lookupPostById()` (`PostSyncer.php:349-367`) currently returns `status`/`date`/`outcome`; it needs **`group_id` added**. That single call then resolves every class below at once:

| class | signal | verdict |
|---|---|---|
| **Crosspost copy** | `group_id` non-empty | **Expected absent.** The dominant category. |
| Deleted on TN | 404 → `status=not_found` | Expected absent |
| Resolved before sync ran | `outcome` ∈ `RESOLVED_OUTCOMES` | Expected absent |
| Date bumped out of window | `date` outside `[from, to]` | Expected absent |
| Unplaceable | see below | Real coverage loss |
| Everything else | — | **Genuine miss** |

**Crossposts are the reason this cannot be skipped.** Per `GroupPostIngestionService::REASON_CROSSPOST` (`GroupPostIngestionService.php:49-58`), the email path receives one email per TN group and ingests each as its own message, so an item crossposted to N groups yields **N email-path messages but exactly one API-path message**. N−1 archived post ids will never have a `messages` row, by design. Only the source post (empty `group_id`) is ingested — commit `d5f0b4983`.

The `(subject, lat, lng)` heuristic is **not** an acceptable substitute: it was deliberately deleted in that same commit (see section Q's `excludeApiCrossposts()` note), and it cannot detect deleted or resolved posts at all.

**Unplaceable posts** need reconstructing rather than looking up. The API path drops posts with no coordinates or outside all group bounds (`PostSyncer::REASON_NO_COORDINATES` / `REASON_NOT_IN_ANY_GROUP_BOUNDS`). Post-cutover there is no email-path verdict to compare against — but running `Location::groupsNear()` on the email's own `X-Trash-Nothing-Post-Coordinates` recovers the decision independently. If the email's coordinates *do* resolve to a group and the post still isn't ingested, that is precisely the Layer 1 regression this whole check exists to catch, and it must never be filed as "expected absent".

**The known TN-side gap** (section Q, 2026-07-29) means a low, roughly constant residue of genuine misses that are not Freegle regressions. Alert thresholds must tolerate it; tune after observing real volumes.

#### S.5 On a genuine miss — auto-ingest, behind rails

**Decision: report *and* auto-ingest**, making the verifier a self-healing backstop rather than only a detector. Because it writes member-visible data on a schedule, it needs rails:

1. **Reuse `PostSyncer::processPost()`** on the fetched post rather than reimplementing. That inherits coordinate-based group resolution, `freegle_group_ids` consent handling and the single-Loki-entry emission invariant for free. Requires only a public entry point for a single already-fetched post.
2. **Ingest only true source posts** — `group_id` empty, `status=found`, outcome unresolved. Auto-ingesting a crosspost copy would recreate exactly the duplication the API path exists to eliminate. Every other class in S.4 is expected-absent and must never be written.
3. **Idempotent by construction** — `GroupPostIngestionService::ingest()` already skips on an existing `tnpostid` (`REASON_DUPLICATE`), so overlapping windows and re-runs are safe.
4. **Cap per run** (e.g. 20). Above the cap, ingest **nothing** and alert loudly: a mass miss means the sync is broken, and quietly backfilling hundreds of hours-late posts is worse than paging a human.
5. **Age guard** — refuse posts whose TN `date` is far older than the window. A very stale post surfacing suddenly is more likely a data problem than a real miss.
6. **Escalate on repeat** — an auto-ingested post must read as covered on the next run. If it does not, ingestion is failing rather than lagging: hard alert.
7. **Ship report-only first.** The flag should default to detection-only until the observed miss population matches what S.4 predicts; only then enable writes. The crosspost share in particular is unknown until measured against real traffic.

#### S.6 Reporting

`logBatchJob('tn:verify-email-coverage', 'completed'|'failed')` carrying: files scanned, TN posts found, covered, absent, per-class reclassification counts (crosspost / deleted / resolved / bumped / unplaceable / genuine), auto-ingested count, and the window. Genuine misses listed by post id.

Note this is a *batch* stream entry, not a routed one — the same split section I draws between per-post routing entries and run-level events. Auto-ingested posts emit their own routed entry through `processPost()`; adding a `backfill` marker key to that entry is additive and safe for the ModTools dashboard, which maps a fixed allowlist of keys rather than iterating them (`emailtracking.js:430-450`). This does **not** reopen I.5a's ruling against adding `tn_post_id` to the *email-side* entry — that concerned the frozen email path, which by this point is off.

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
