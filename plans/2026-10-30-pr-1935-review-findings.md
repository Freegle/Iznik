# PR 1935 review findings: memberships implicit and added_origin

Reviewed 2026-10-30 against head `e7785d80e7` (branch `memberships-mod-messaging-and-origin`).
Adversarial review at maximum effort: twelve finder angles, twenty verifiers, one sweep. Every
finding below was verified against the PR head; the sweep's two were verified by hand after the
orchestrator stopped. Tick each as it is dealt with.

Severity is for this PR as it ships. Several are latent until TrashNothing API ingestion is
turned back on or `memberships:backfill-tn --send` runs, and are marked so.

## Fix before merge

- [ ] **Placement gives the group's moderators access to the poster's private chats and profile.**
  `iznik-server-go/chat/chatmessage.go:1509` (canSeeChatRoom fallback), `iznik-server-go/user/authorization.go:31`
  (IsModOfUser), `iznik-server-go/chat/chatroom.go:741` and `:1821` (common groups, report routing).
  All key moderator access on any shared membership row with no implicit, rippled or origin filter.
  A `tn_unaddressed` row on group G lets G's moderators read and post into the poster's chats about
  posts on other groups, read their emails, settings and tnuserid through `GET /api/user/:id/chatrooms`,
  and routes chat reports about the poster to G's volunteers (the central-team report is refused
  with "Groups in common exist"). The poster had no row before, so this is new exposure. It lands
  with the backfill, before Phase 5, and the plan's Phase 5 restriction list names none of these
  paths. Confirmed on the PR head: none of the four queries filter on implicit or origin.

- [ ] **Placement re-enrols the poster in three mailings.**
  `iznik-batch/app/Services/TrashNothing/Ingestion/GroupPostIngestionService.php:558` turns off
  digests and roundups through emailfrequency, eventsallowed and volunteeringallowed only.
  `StoriesNewsletterService.php:201` (monthly), `EngageEmailService.php:87` (daily at-risk and
  inactive mails) and `CommunityNewsEmailService.php:298` (flag-gated) select recipients by
  membership existence. None of the three, nor the `receivingOurMails` scope at `User.php:793`,
  checks tnuserid or implicit. A TN poster with no membership was in none of those sets before.

- [ ] **Moderator add clears a TN placement without consent.**
  `iznik-server-go/membership/membership.go:1574` calls `ClearRippledMembership` for any caller on
  an existing row, and `rippling/membership.go:36` now matches `rippled = 1 OR implicit = 1`.
  ModTools Add Member (`ModAddMemberModal.vue` then `PUT /memberships {userid, groupid}`) on a TN
  poster with an implicit row makes the placement ordinary and writes the Group/Joined log with
  byuser = the member, so the audit row says the member did it. Only the self-join and partner
  paths are tested. Four finders raised this independently. Latent until rows exist; permanent
  once it happens because consent only goes one way. Fix: clear only when byuser == userid or on
  the partner path, or key TN consent on added_origin rather than widening the ripple clear.

- [ ] **Test comment contradicts production config.**
  `iznik-server-go/test/membership_added_origin_test.go:72` says production's strict mode would
  refuse an unknown ENUM value. `ops/hosts/db-node/mysql/my.cnf.template:34` has no
  STRICT_TRANS_TABLES, Laravel's connection sets `strict => FALSE`, and `user.go:2656` already
  documents that an unknown ENUM is coerced to '' in non-strict mode. A Go-only origin value would
  be stored as '' with RowsAffected 1, and `membership.go:1595` never reads `result.Error`.

## Confirmed, low

- [ ] **Backfill is not idempotent for one shape.** `TnMembershipBackfillService.php:180` returns
  ACTION_CREATE for a pair with no membership without calling `addressedPostSince`. A later
  addressed post on the same group means run one creates an implicit row dated at the unaddressed
  post, and run two finds that row, sees the later post, and clears it with a "became ordinary"
  log. The class docblock (lines 38 and 39), `BackfillTnCommand.php:14`, `trashnothing.md:332`
  and the plan all say re-running changes nothing. `ordinaryAfter()` returns false for a null
  membership, so run one's report also says contact_after = no for the pair. No test covers a
  later addressed post with no membership. Fix: in the null branch, create then clear when
  `addressedPostSince($userid, $groupid, $pair['arrival'])` is true, and compute contact_after
  from the row after apply.

- [ ] **Merge produces a state no writer creates.** `iznik-server-go/user/user.go:3348` and
  `iznik-batch/app/Models/User.php:1296` take the older row's added_origin but LEAST of implicit
  and LEAST of rippled independently. A ripple join (1, 1, rippled) older than a TN placement
  (1, 0, tn_unaddressed) merges to implicit 1, rippled 0, origin rippled. The SQL re-run
  (`2026_10_28_000001_add_implicit_to_memberships_migration.sql:44`) then clears it because its
  guard is only `added_origin <> 'tn_unaddressed'`, and the Phase 5 removal key
  (implicit 1 AND origin rippled) would delete a row that is partly a TN placement.
  `TestMergeMembershipConflictKeepsTheSurvivorsOriginWhenItIsOlder` pins exactly this state.
  Rare: needs a TN twin merge with both rows on one group.

- [ ] **SQL header and plan disagree on the re-run timing.** The header at
  `2026_10_28_000001_add_implicit_to_memberships_migration.sql:11` (and the PHP docblock) says
  re-run just before the dual-write code goes live. The plan (2a, lines 374 to 378, and the
  production sequence row 3) and the PR body say after both Go and the batch are live. Neither
  mentions the other run. Until the correct re-run, the old Go clear and the old batch
  `RippleEngagementService::clear` set rippled alone, about a thousand rows a day, and this PR
  already moves eleven readers to `rippled = 0 AND implicit = 0`: PosterMessagingGroups
  (`message.go:2933`, so a 403 on mod reply and notify 0 on approve and reject notices),
  `story.go:149` and `:198`, `chatmessage.go:494` (frozen attribution snapshot, not repaired by
  the re-run), `attribution.go:41`, `metrics.go:189`, `analytics.go:973`, `BirthdayService.php:56`,
  `ReengageContentService.php:243`, `ReplyAttributionBackfillService.php:81`,
  `RippleEngagementService.php:267`. Nothing alarms if the re-run is mistimed or skipped.
  Fix the header, and consider leaving the readers on rippled in this PR.

- [ ] **Retention regression.** `iznik-batch/app/Services/UserManagementService.php:289` skips
  anyone with a membership row. A TN poster whose only row is a permanent placement (never
  auto-removed, by design) is no longer swept by the six-month inactive forget.
  `docs/members/your-account.md:139` promises removal for accounts that never joined a community.
  Small, unmeasured set: stamped posters who lost all memberships and then had a post placed.

- [ ] **Ripple backfill overwrites a known origin.** `RippleMembershipBackfillService.php:101`
  writes `added_origin = rippled` and `implicit = 1` over any row whose latest Group/Joined log is
  Rippled, with no guard on the current origin. A row re-created by a path that writes no join log
  (`user:add-membership`, `group:move-members`) after a ripple leave is re-flagged and loses its
  moderator origin. Contrast `MembershipOriginBackfillService.php:180`, which only touches
  unknown. Manual-only command; the plan lists it for retirement.

- [ ] **Auto-approve gate opens for TN API posters.** `AutoApproveService.php:307` returned false
  for a poster with no membership. Ingestion now writes one with added = now, so a
  content-check-flagged post from a non-member TN poster publishes at about 49 hours with no
  moderator action, as ordinary members' posts already do. Not mentioned in any plan, doc or test,
  and the recordMembership docblock says a missing row leaves the post exactly as before, which is
  false for this reader. Decide and document, or gate on tnpostid.

- [ ] **Origin backfill cost.** `MembershipOriginBackfillService.php:179` re-reads about 3.3M join
  log rows by primary key on the write node (db3), although the read-host scan already read them
  and kept only the id. Line 309 (`postedNearJoin`) issues one EXISTS per ambiguous row from both
  chunk loops: about 650K in the memberships pass and up to about 930K in the history pass, on
  every dry run. Fix: carry text, byuser and timestamp (or a short origin code) in the temp table,
  and answer the probes per chunk with one `fromuser IN (...)` plus arrival-window query.

- [ ] **Docs freshness gate fails on the branch.** `node scripts/check-docs-freshness.mjs --base b3a0ef9b26`
  exits 1 for eight pages: `docs/developers/architecture.md`, `reference/chat-prompts.md`,
  `reference/donations-and-gift-aid.md`, `reference/ux-member-surveys.md`,
  `reference/ux-telemetry.md`, `members/getting-started.md`, `members/rippling-out.md`,
  `moderators/moderating-posts.md`. A last_reviewed bump satisfies it. Also:
  `moderating-posts.md:157` now understates the rule since PosterMessagingGroups requires
  implicit = 0, and `rippling-algorithm.md` and `trashnothing.md` document behaviour in files their
  covers lists omit (addedorigin, membership.go, Membership.php, MembershipsProcessingService,
  BirthdayService, ReengageContentService, story.go). Separately, `scripts/hooks/pre-commit:6`
  claims CI enforces the gate; no CI job runs it.

- [ ] **Weak test.** `PostSyncerTest.php:349` latches on the first array post, so the
  deserialized Post shape is never actually tested; the assertion passes if only one shape is
  detected. Fix: a fresh syncer per shape, one captureMessage asserted for each.

- [ ] **Backfill consent rule is broader than live.** `TnMembershipBackfillService.php:152` counts
  any own-row addressed post, including native Freegle and TN email posts that live code does not
  treat as consent (ingestion clears only for the current TN API post). Documented at lines 139 to
  141 and in the plan, but it means a re-run after go-live clears placements live code keeps.

- [ ] **Origin list pinned in one copy only.** `addedorigin.go:30` (Go), `Membership.php:109`,
  the PHP migration ORIGINS and the two ENUM literals in the .sql are identical today, but only
  the PHP list is compared with information_schema. A Go test selecting COLUMN_TYPE for both
  tables and comparing with `all` would pin the rest. (`test/chatprompt_test.go:56` is the precedent.)

- [ ] **Raw SQL copies are untested.** The rippled-to-implicit copy exists as a PHP chunk loop, a
  stored procedure in the .sql and the closing check; only the PHP is tested, and production can
  only run the .sql (`AppServiceProvider.php:202` blocks artisan migrate). A verifier ran both
  .sql files on a real MySQL 8.0.46 with production sql_mode and found no defect: INSTANT adds in
  the right positions, drift corrected both ways, tn_unaddressed untouched, idempotent, PHP twin
  gives identical rows. The risk is predicate drift later, not a bug now.

- [ ] **Subscribe mail and partner PUT disagree on consent.** `IncomingMailService.php:1265`
  returns early on an existing membership and clears nothing; `membership.go:1516` (partner PUT)
  clears implicit. Both are what the plans say; item 7 of `plans/2026-10-29-tn-api-ingestion-fixes.md`
  tracks it. Noted so it is not lost.

## Trivial

- Backlog check false alarm: backfilled history rows have a historic added and processingrequired 1,
  so a `monitor:scheduled-outcomes` tick before the next `memberships:process` minute raises one
  Sentry error that self-clears. About a 1 in 10 chance per run. Runbook note: run just after a
  ten-minute tick.
- Log text comes from `manual` and added_origin from `origin` (`membership.go:1624`), so a
  hand-crafted request can record one join two ways. No shipped client does.
- `useSystemLogFormatter.js:274` has no Subscribed branch, so an email-subscribe join reads
  "auto-joined when posting/replying" there while `ModLog.vue:39` gets it right. Same on master.
- `default:unknown` GORM tags at `user.go:166` and `:205` are unreachable because ForInsert never
  returns ''. The plan names a test for them that is not in the PR.
- Naked strings for role, collection, group type and the new origin values in the new tests
  (`BackfillOriginCommandTest.php:22` and 21 other added lines) against coding-standards.md:51;
  `MembershipOriginBackfillService.php:67` keys on the literal 'via partner' next to the new
  `JOIN_LOG_CHOSEN` constant.
- `writeMembership` uses the DB facade so no Laravel Auditing rows are written; same pattern as
  ExpandService, and insertOrIgnore is the reason.
- The three-insert transaction at `GroupPostIngestionService.php:572` has no deadlock retry and
  recordMembership swallows the failure; the duplicate-post check means that post is never retried.
- `auth.js:692` always sends `manual` as a boolean, so a caller that omits origin is stored as
  replied with no report, and a boolean third argument gets a 400. No such caller exists.

## Refuted

- The Kotlin `FreegleApi.kt:334` joinGroup client is uncalled, documented as an experiment that
  does not ship, and sends groupid as a JSON string that Fiber's BodyParser already rejects with 400.
- Reading an absent freegle_group_ids as no consent, ingesting anyway and reporting once per run is
  the documented decision (plan lines 574 to 579, trashnothing.md:349).
- The reach-mail enqueue split between ingestion and the backfill has no visible effect: backfilled
  rows are mail-off and reach mail only considers emailfrequency = -1.
- ForInsert validating constants at nine of twelve sites is the documented design; the three
  variable sites are tested.

## Not verified

The verifiers for two efficiency candidates never reported. Both are low confidence:

- The implicit copy runs two UPDATEs per 10,000-id range (`..._migration.sql:50`) where one
  `SET implicit = rippled WHERE implicit <> rippled` plus the tn_unaddressed guard would do; on the
  first run the second statement cannot match anything.
- `user.go:3344` adds one UPDATE per shared group inside the per-membership merge loop; one
  set-based UPDATE before the loop would cover all shared groups.
