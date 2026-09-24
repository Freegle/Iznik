# Remove email-based ingestion of TN posts

Follow-on to `plans/tn-api-post-ingestion.md`. That plan built the API path and, at
cutover, stopped the email path **at the callers** (`TnEmailRoutingGate`), leaving
`IncomingMailService::handleGroupPost()` frozen but unreachable for TN posts. This plan
deletes the frozen code and the scaffolding that only existed to run both paths side by
side.

## Preconditions (do not start until all hold)

1. `FREEGLE_TN_INGEST_POSTS_VIA_API=true` in production, and has been for long enough
   to trust it: `tn:verify-email-coverage` has run for several weeks with only the
   expected residue of genuine misses, and no escalations.
2. Nobody expects to flip the flag back. After this change, turning it off drops every
   TN post, because there is no email path left to fall back to. That is why step 4
   retires the flag rather than leaving it.
3. Decision D1 below is made.

## Decisions to make first

### D1. Can non-TN group posts go too?

Phase 5 of `route()` is not TN-only. `targetGroupName !== null` is true for **any**
mail sent to `<nameshort>@groups.ilovefreegle.org`, and `handleGroupPost()` has a
non-TN branch: spam checking (`checkForSpam()` runs only when `shouldSkipSpamCheck()`
is false), membership checks, and so on. Tests like
`test_routes_unmoderated_member_post_to_pending_for_content_check` and
`test_routes_spam_to_incoming_spam` exercise it with ordinary members.

Removing lines 178-181 of `IncomingMailService.php` therefore also ends posting by
email for Freegle members. That looks like the intent, since nothing in the UI
advertises the group address as a way to post, but it should be measured, not assumed.

Measure it first. For about two weeks, count Phase 5 routed entries in Loki
(`LokiService::SOURCE_INCOMING_MAIL`, subtypes Approved/Pending/IncomingSpam/Dropped)
whose sender is **not** `@user.trashnothing.com` and which carry no
`X-Trash-Nothing-Post-Id`. Alternatively, scan the 48h incoming archive with
`IncomingArchiveReader`.

- **~0 real humans:** remove Phase 5 outright. This is the plan below.
- **Meaningful volume:** either keep a non-TN `handleGroupPost()` (a much smaller
  deletion: only the TN-specific branches go), or remove it and send an auto-reply
  pointing people to the website. Settle this with the team before continuing.

### D2. What happens to group-addressed mail once Phase 5 is gone

If the `if` is simply deleted, the mail falls through to Phase 6. There,
`handleDirectMail()` runs `findUserByEmail()` on the group address, finds nobody, and
drops the mail as `"Direct mail to unknown user address"`. The mail is dropped either
way, but that reason is misleading, and it costs two user lookups per email.

**Recommendation:** keep a Phase 5 branch, but have it do nothing except drop
explicitly:

```php
// Phase 5: Group posts are no longer accepted by email - TN posts are ingested
// via the TN API (tn:sync). See plans/2026-09-24-remove-tn-email-post-ingestion.md.
if ($email->targetGroupName !== null) {
    return $this->handleRetiredGroupPost($email);
}
```

`handleRetiredGroupPost()` returns `DROPPED` with a distinct reason, and splits by
sender:

| Case | Log level | Why |
|---|---|---|
| Has `X-Trash-Nothing-Post-Id` | `info`, reason `"TN group post - ingested via API"` | Expected. TN still sends one email per group per post, so this is high volume and normal. Logging it at error level would flood the logs and teach everyone to ignore it. |
| No TN header (a person or a bot emailing a group) | `warning`, reason `"Group post by email no longer supported"` | This is the case where someone's post really vanishes. Warning level keeps it visible and countable in Loki without paging anyone. |

This covers the "log errors?" question: **not at error level for TN mail**. For TN,
the real failure signal is a post that arrived by email but never reached `messages`.
`tn:verify-email-coverage` already detects exactly that, which a per-email log line
cannot. Raise the non-TN case to `error` only if D1 shows the volume is ~0, so that
any occurrence means something.

(The gate in the callers normally catches TN posts before `route()`, so in production
the TN row above fires only for mail the gate declined. It is a backstop, and it is
also what `route()` returns in tests and in any caller that skips the gate.)

## Steps

Each step should leave the full suite green. Steps 1-3 can be one PR; 4-6 another.

### 1. Delete the email-path group-post code (`IncomingMailService.php`)

- Replace Phase 5 as in D2.
- Delete `handleGroupPost()`, `createGroupPostMessage()`, and the helpers only they
  use. Candidates from a call-site scan: `normaliseTnPostId`, `findLiveTnMessage`,
  `attachGroupToTnMessage`, `isTakenOrReceivedSubject`, `shouldSkipSpamCheck`,
  `checkForSpam`, `isSpam` (already dead: its only in-file reference is its own
  definition), `containsWorryWords`, `extractLocationFromSubject`, `parseSubject`,
  `findClosestPostcodeId`, `scrapeTnImageUrls`, `extractTnImageUrlsFromPage`,
  `isTnImageUrl`, `stripTnPicLinks`, `createTnImageAttachments`, `addToSpatialIndex`,
  `notifyGroupMods`, `pruneSubject`, `recordFailure`.
- **Keep** helpers the chat, volunteer and direct-mail paths still use:
  `determineSourceHeader` and `computeImageHash` (chat photo storage, around lines
  2253/2347), `getSpamAssassinScore` (`handleDirectMail`), `findGroup` (volunteers),
  `findUserByEmail`, and `addEmailToUser`.
- Re-check each of these by grepping at the time. Several "external" hits are comments
  in `GroupPostIngestionService` saying "mirrors IncomingMailService::X", not calls.
  Before deleting the public ones (`scrapeTnImageUrls`, `createTnImageAttachments`,
  `recordFailure`), confirm nothing outside this file calls them on an
  `IncomingMailService` instance.
- Update the "MIRRORED BY HAND in IncomingMailService" comments in
  `GroupPostIngestionService`. The API path is now the only implementation, so its
  helpers stop being "direct duplicates" and become the canonical ones.

### 2. Delete the parallel-run scaffolding

This existed only to compare the two paths, which no longer both exist:

- `EmailPathMirrorDriftTest`, which fails on any edit to `handleGroupPost()`. Delete
  it; the method is gone.
- `EmailReplaySyncer`, `TNReplayEmailsCommand` (`tn:replay-emails`),
  `TNParityCheckCommand` (`tn:parity-check`) and `ParityComparer`. Once the email path
  is gone, `route()` drops every replayed post, so all of these report nothing.
  `tn:verify-email-coverage` already replaced them.
- Tests: `EmailApiParityTest`, `TNParityCheckCommandTest`, `TnApiLokiParityTest`,
  `tests/fixtures/tn_sync/parity/**`.
- `TnCrosspostSingleMessageTest`, which tests the email path's crosspost collapse.
- In `IncomingMailServiceTest`, delete the group-post tests (the list starts around
  `test_routes_unmoderated_member_post_*`, `test_routes_spam_*`, `test_group_post_*`,
  `test_*_forces_post_to_pending`, `test_taken_subject_*`, and so on). Replace them
  with tests for D2's two cases, **plus** one pinning that `-volunteers` mail still
  reaches Phase 4. The parser still sets `targetGroupName` for volunteer mail, so
  ordering is what protects it.
- `LocationIdTest` and `SourceHeaderTest`: keep whatever still covers the chat and
  direct paths, and delete the rest.
- Check whether `SpamCheckService`/`SpamCheckServiceTest` depend on anything deleted.
  They reference `pruneSubject`/`isSpam` names, probably their own copies.

### 3. Simplify `TnEmailRoutingGate`

The gate's job changes from "skip routing during the cutover" to "select TN posts for
the archive inventory".

- `ArchiveInventoryService` still needs `isTrashNothingGroupPost()`. Keep that, and
  keep `claimedByAnEarlierPhase()`: it is still what stops a TN chat reply being
  counted as a post.
- `shouldSkipRouting()` no longer needs to read the flag. Either make it
  unconditional (keeping `OUTCOME_SKIPPED` in the archive, and saving a pointless
  `route()` call), or delete it and the two caller branches and let D2's Phase 5 drop
  handle it. **Recommend keeping it, unconditional.** The archive's
  `routing_outcome = SkippedTnApi` stays meaningful, and it avoids a second Loki
  "Dropped" entry per TN email burying real drops on the ModTools incoming-mail
  dashboard.
- Fix the class docblock and its `IncomingMailService.php:NNN` line references, which
  will be stale.

### 4. Retire `FREEGLE_TN_INGEST_POSTS_VIA_API`

Once the email path is gone, "off" is only a way to lose posts. Remove the flag, and
make every place that reads it unconditional:

- `TNSyncCommand` (the `PostSyncer` gate; keep `--local-testing` as is)
- `routes/console.php` (the `tn:verify-email-coverage` schedule)
- `TNVerifyEmailCoverageCommand` (the guard and `--force`; the guard's premise, that
  both paths stamp `tnpostid`, no longer holds)
- `ScheduledOutcomeRegistry` (`tn:sync (posts)` check)
- the `ExpandService` comment (~line 1905)
- `config/freegle.php`, `.env.example`, `.env.background.example`, and the orb/CI
  environment if it sets the flag

### 5. Leave alone

- **Historical data.** Email-era TN messages keep `sourceheader` `TN-Web`/`-Facebook`/
  `-Mobile`, `source = Email`, and their per-group copies. `tn:merge-crossposts` and
  the rippling `tn_duplicate_sat_out` hold-back still handle unmerged email-era copies
  until the merge has run to completion. Removing those is a separate job after that.
- **TN chat replies** (Phase 2, `notify-`/`replyto-`). There is no TN chat API, so these
  stay on email indefinitely.
- **TN subscribe mail** (Phase 1), volunteers mail (Phase 4), bounces.
- **The incoming archive** and `tn:verify-email-coverage`. They remain useful for as
  long as TN keeps emailing us. If TN is ever asked to stop sending partner post
  emails, retire the verifier then. That is a question for TN, and outside this plan.

### 6. Docs

`docs/developers/reference/trashnothing.md` covers `IncomingMailService.php`, `Sync/**`,
`Verify/**` and `Commands/TrashNothing/**`, so `check-docs-freshness` will require it.

- "Message Delivery (Email-Based)": say post delivery is API-only. Email now carries
  only chat replies and subscribes.
- "Post Ingestion via API, and the email cutover": drop the one-flag table, and
  describe the steady state. The "five intentional differences" list becomes history.
  Keep the facts still true of old data (crosspost collapse, `sourceheader`), and drop
  the parity-check guidance.
- "Verifying nothing is dropped": remove the flag references and `tn:parity-check`.
- "Data Flow Summary" / "Key File References": remove the deleted classes.
- Mark `plans/tn-api-post-ingestion.md` as superseded, or prune it; its "IncomingMailService
  is frozen" constraint no longer applies.
- `.claude/rules/mail-and-data.md` / `tests-and-ci.md`: grep for
  `handleGroupPost`, `EmailPathMirrorDrift` and `parity-check`, and fix what they say.

## Verification

- Full `iznik-batch` suite, and the orb if any test files it names were deleted.
- `grep -rn "handleGroupPost\|createGroupPostMessage\|EmailReplaySyncer\|ParityComparer\|ingest_posts_via_api\|tn:parity-check\|tn:replay-emails"`
  across the repo (docs, Go, nuxt, CI) should return nothing unexpected.
- Replay a real archived TN post email, a TN chat reply, a `-volunteers` email and a
  non-TN email to a group address through `mail:incoming` locally. Confirm the
  outcomes are, respectively: SkippedTnApi, ToUser, ToVolunteers, and Dropped with the
  warning.
- After deploy, watch in Loki for the non-TN warning count and the Dropped mix on the
  incoming-mail stream. Also check that `tn:verify-email-coverage` is unchanged.

## Open questions

- D1: the measured volume of non-TN email posts, and what the team wants for them.
- Should the non-TN case send an auto-reply ("posting by email is no longer supported,
  please use ilovefreegle.org")? It is cheap: the digest-reply handler already does
  something similar. It is kinder than a silent drop, but it is a backscatter risk
  for spam sent to group addresses, so reply only to known members.
