<?php

namespace App\Services\TrashNothing\Sync;

/**
 * Coverage-first, four-layer comparison between the legacy email path's and
 * the new API path's TN-SYNC-TRACE trace lines. Extracted from
 * TNParityCheckCommand so the layer logic is unit-testable independently of
 * the CLI (see tests/Feature/TrashNothing/EmailApiParityTest.php) and reused
 * by it unchanged. See plans/tn-api-post-ingestion.md section Q for the
 * full design rationale.
 *
 * The two paths are NOT expected to be identical: the API path is a
 * superset (it should ingest everything the email path does, plus more),
 * and the two paths resolve a post's group independently (recipient
 * address vs. coordinates), so they may legitimately disagree on group
 * per post. Byte-identical trace diffing can't express either of those.
 *
 * Layer 1 — coverage (hard fail): every post_id the email path produced a
 *   result for must also produce a [POST-RESULT] on the API path. The two
 *   API-only pre-ingest [POST-SKIP] reasons (no-coordinates /
 *   not-in-any-group-bounds) do NOT count as coverage — if the email path
 *   placed a post in a real group but the API path's coordinate-based
 *   Location::groupsNear() couldn't place it anywhere, that's a genuine
 *   placement regression, not "just a non-UK post" (those never reach the
 *   email path at all, since it only sees posts TN emailed to a Freegle
 *   group address in the first place). The skip reason is still surfaced in
 *   the failure detail when known, for diagnosis.
 * Layer 2 — extra posts (informational): post_ids the API path covered that
 *   the email path never saw. Expected and desirable — never a failure.
 * Layer 3 — same-group parity (hard fail): for post_ids where both paths
 *   created a messages row AND resolved the same groupid, the content
 *   (fromuser/type/subject/lat/lng/locationid) and routing outcome must
 *   match exactly. messageid/message/envelopefrom/fromip/sourceheader are
 *   excluded — those are synthesized differently by design (section C).
 * Layer 4 — divergence (informational): post_ids present on both paths but
 *   where either the resolved group differs, or one/both sides never
 *   created a messages row (so there's no group to compare) — reported for
 *   visibility only, never a failure.
 */
class ParityComparer
{
    /**
     * Whether a TN-SYNC-TRACE log line is relevant to parity comparison —
     * used by callers (TNParityCheckCommand, EmailApiParityTest) building
     * their own Log::listen() capture filter, so both use the exact same
     * set of tags the parsing methods below actually understand.
     *
     * [WRITE] is restricted to `table=` (DB row writes) — tn:sync also
     * emits a command-level `[WRITE] op=file-write ...` line for its
     * sync-date-file bookkeeping, which has no equivalent on the email
     * path and isn't part of per-post ingestion behaviour.
     */
    public static function isRelevantTraceLine(string $text): bool
    {
        return (bool) preg_match('/TN-SYNC-TRACE \[(POST-RESULT|EMAIL-RESULT|POST-SKIP|POST|EMAIL|LOKI)\]/', $text)
            || (bool) preg_match('/TN-SYNC-TRACE \[WRITE\] table=/', $text);
    }

    // Routing outcomes meaning a messages row was actually created (i.e. the
    // post landed on FD, awaiting or past moderation) — as opposed to
    // dropped/skipped/duplicate, where nothing was ingested. Used to turn
    // Layer 2's raw "extra post_ids" into a genuine "how many more posts are
    // we actually getting" figure.
    private const INGESTED_RESULTS = ['approved', 'pending'];

    // GroupPostIngestionService's result for a TN per-group copy, which it
    // discards without writing anything. Excluded from every count and layer —
    // see excludeApiCrossposts().
    private const RESULT_CROSSPOST = 'crosspost';

    /**
     * Parses both trace-line sets and buckets post_ids into the four layers.
     *
     * @param  string[]  $emailLines
     * @param  string[]  $apiLines
     * @return array{
     *     emailPostIdCount: int, apiCoveredCount: int,
     *     emailIngestedCount: int, apiIngestedCount: int, apiCrosspostsDiscarded: string[],
     *     layer2ExtraIngested: string[],
     *     layer1Missing: string[], layer1Details: array<string, string>,
     *     layer2Extra: string[],
     *     overlapCount: int, layer3Mismatches: string[], layer4Divergences: string[],
     *     layer5Mismatches: string[], layer5Compared: int, layer5StructureOnly: int,
     *     lokiEntriesSeen: int,
     * }
     */
    public function computeLayers(array $emailLines, array $apiLines): array
    {
        $emailResults      = $this->parseResults($emailLines);
        $apiResults        = $this->parseResults($apiLines);
        $apiPreIngestSkips = $this->parsePreIngestSkips($apiLines);
        $emailMessages     = $this->parseMessages($emailLines);
        $apiMessages       = $this->parseMessages($apiLines);
        $emailPostDetails  = $this->parsePostDetails($emailLines);
        $emailStubUserIds  = $this->parseStubUserIds($emailLines);
        $apiStubUserIds    = $this->parseStubUserIds($apiLines);
        $emailLokiEntries  = $this->parseLokiEntries($emailLines);
        $apiLokiEntries    = $this->parseLokiEntries($apiLines);

        // Drop the API path's discarded per-group copies before anything else
        // uses apiResults/apiMessages — see excludeApiCrossposts() docblock.
        // They are not ingested, so counting them as coverage or as Layer 2
        // "extra" posts would misreport what the API path actually delivered.
        [$apiResults, $apiMessages, $apiCrosspostsDiscarded] = $this->excludeApiCrossposts(
            $apiResults,
            $apiMessages,
        );

        $emailPostIds     = array_keys($emailResults);
        $apiResultPostIds = array_keys($apiResults);

        // Layer 1: coverage (hard fail). Only an actual [POST-RESULT] counts as
        // "covered" — the two API-only pre-ingest skips (no-coordinates /
        // not-in-any-group-bounds) do NOT, even though the API path "saw" the
        // post, because a post the email path placed in a real group that the
        // API path couldn't place anywhere is a genuine placement regression.
        // apiPreIngestSkips is used only to enrich the failure detail below
        // (distinguishing "API couldn't place it" from "API never returned it
        // in the feed at all"), never to satisfy coverage.
        $layer1Missing = array_values(array_diff($emailPostIds, $apiResultPostIds));
        $layer1Details = [];
        foreach ($layer1Missing as $postId) {
            $layer1Details[$postId] = $this->formatLayer1Detail(
                $postId,
                $emailResults[$postId] ?? '?',
                $emailPostDetails[$postId] ?? null,
                $emailMessages[$postId] ?? null,
                $apiPreIngestSkips[$postId] ?? null,
            );
        }

        // Layer 2: extra posts (informational).
        $layer2Extra = array_values(array_diff($apiResultPostIds, $emailPostIds));

        // How many more posts are actually landing on FD via the API path vs
        // the email path — restricted to results that created a messages row
        // (approved/pending), not raw post_id counts, so a pile of dropped/
        // duplicate/skipped extras doesn't inflate the "we're getting more"
        // figure.
        $layer2ExtraIngested = array_values(array_filter(
            $layer2Extra,
            fn (string $postId) => in_array($apiResults[$postId] ?? '', self::INGESTED_RESULTS, true),
        ));

        // Layers 3 & 4: overlap, split by whether both sides created a
        // messages row on the same group.
        $overlap            = array_values(array_intersect($emailPostIds, $apiResultPostIds));
        $layer3Mismatches   = [];
        $layer4Divergences  = [];

        foreach ($overlap as $postId) {
            $this->classifyOverlapPost(
                $postId,
                $emailMessages[$postId] ?? null,
                $apiMessages[$postId] ?? null,
                $emailResults[$postId] ?? '?',
                $apiResults[$postId] ?? '?',
                $emailStubUserIds,
                $apiStubUserIds,
                $layer3Mismatches,
                $layer4Divergences,
            );
        }

        // Layer 5: Loki entry parity (hard fail) — see compareLokiEntries().
        [$layer5Mismatches, $layer5Compared, $layer5StructureOnly] = $this->compareLokiEntries(
            $overlap,
            $emailLokiEntries,
            $apiLokiEntries,
            $emailMessages,
            $apiMessages,
            $emailStubUserIds,
            $apiStubUserIds,
        );

        $emailIngestedCount = count(array_filter($emailResults, static fn (string $r) => in_array($r, self::INGESTED_RESULTS, true)));
        $apiIngestedCount   = count(array_filter($apiResults, static fn (string $r) => in_array($r, self::INGESTED_RESULTS, true)));

        return [
            'emailPostIdCount'      => count($emailPostIds),
            'apiCoveredCount'       => count($apiResultPostIds),
            'emailIngestedCount'    => $emailIngestedCount,
            'apiIngestedCount'      => $apiIngestedCount,
            'apiCrosspostsDiscarded' => $apiCrosspostsDiscarded,
            'layer2ExtraIngested'   => $layer2ExtraIngested,
            'layer1Missing'         => $layer1Missing,
            'layer1Details'         => $layer1Details,
            'layer2Extra'           => $layer2Extra,
            'overlapCount'        => count($overlap),
            'layer3Mismatches'    => $layer3Mismatches,
            'layer4Divergences'   => $layer4Divergences,
            'layer5Mismatches'    => $layer5Mismatches,
            'layer5Compared'      => $layer5Compared,
            // Pairs where the two paths resolved different groups (or one never
            // created a messages row), so only the outcome-independent
            // structural checks applied — the same pairs Layer 4 reports.
            'layer5StructureOnly' => $layer5StructureOnly,
            // Distinguishes "compared and clean" from "nothing to compare"
            // (Loki disabled for the run) — a silent zero would otherwise read
            // as a pass.
            'lokiEntriesSeen'     => count($emailLokiEntries) + count($apiLokiEntries),
        ];
    }

    /**
     * Drops the TN API's per-group copies from every count and layer.
     *
     * TN stores a post as a source post (no group_id) plus one copy per group
     * it was sent to, so each group's moderators can moderate their own copy.
     * GroupPostIngestionService discards every copy — Freegle does its own
     * cross-posting via rippling — returning result='crosspost' for it. Those
     * post_ids never become FD messages, so counting them anywhere would
     * misreport what the API path ingested: they would show up as Layer 2
     * "extra posts the API path found" when in fact they were thrown away.
     *
     * This replaces an earlier heuristic that grouped ingested posts by
     * (subject, rounded lat, rounded lng) and kept the earliest-dated post_id
     * per group. That approach is now both unnecessary and wrong:
     * unnecessary because crossposts are identified exactly, by TN's own
     * group_id, before they ever reach a messages row; and wrong because the
     * heuristic could not tell a crosspost from a REPOST, which is a new
     * source post that production deliberately keeps as its own message
     * (matching the email path) — so it silently collapsed reposts and
     * under-reported the API path's ingestion gain.
     *
     * No email-path equivalent is needed: post_ids are TN-API identifiers, and
     * a post_id the email path also saw cannot be one of these copies, so this
     * can never cause a false Layer 1 miss.
     *
     * @param  array<string, string>  $apiResults
     * @param  array<string, array<string, mixed>>  $apiMessages
     * @return array{0: array<string, string>, 1: array<string, array<string, mixed>>, 2: string[]}
     *   [filteredResults, filteredMessages, discardedPostIds]
     */
    private function excludeApiCrossposts(array $apiResults, array $apiMessages): array
    {
        $discarded = [];

        foreach ($apiResults as $postId => $result) {
            if ($result === self::RESULT_CROSSPOST) {
                $discarded[] = (string) $postId;
            }
        }

        foreach ($discarded as $postId) {
            unset($apiResults[$postId], $apiMessages[$postId]);
        }

        return [$apiResults, $apiMessages, $discarded];
    }

    /**
     * Builds the full-detail block printed/asserted for a single Layer 1
     * (coverage) failure: the email path's result, its [POST] summary
     * (type/group_id/date/title — always present), its full messages-row
     * field set if the email path actually created a message for this post,
     * and — when known — the API-side pre-ingest skip reason
     * (no-coordinates / not-in-any-group-bounds), distinguishing "the API
     * saw this post but couldn't place it anywhere" from "the API never
     * returned this post_id in its feed at all".
     *
     * @param  array{type: string, group_id: string, date: string, title: string}|null  $postDetail
     * @param  array<string, mixed>|null  $message
     */
    private function formatLayer1Detail(string $postId, string $emailResult, ?array $postDetail, ?array $message, ?string $apiSkipReason): string
    {
        $parts = ["post_id={$postId}", "email_result={$emailResult}"];

        if ($postDetail !== null) {
            $parts[] = 'type=' . $postDetail['type'];
            $parts[] = 'group_id=' . $postDetail['group_id'];
            $parts[] = 'date=' . $postDetail['date'];
            $parts[] = 'title=' . $postDetail['title'];
        }

        $parts[] = 'api_status=' . ($apiSkipReason !== null ? "skipped({$apiSkipReason})" : 'never-in-feed');

        if ($message !== null) {
            $parts[] = 'message=' . json_encode($message);
        }

        return implode(' ', $parts);
    }

    /**
     * Classifies a single overlapping post_id into Layer 3 (same-group
     * mismatch) or Layer 4 (divergence), appending to the passed-by-reference
     * result arrays.
     *
     * @param  array<string, mixed>|null  $emailMsg
     * @param  array<string, mixed>|null  $apiMsg
     * @param  int[]  $emailStubUserIds
     * @param  int[]  $apiStubUserIds
     * @param  string[]  $layer3Mismatches
     * @param  string[]  $layer4Divergences
     */
    private function classifyOverlapPost(
        string $postId,
        ?array $emailMsg,
        ?array $apiMsg,
        string $emailResult,
        string $apiResult,
        array $emailStubUserIds,
        array $apiStubUserIds,
        array &$layer3Mismatches,
        array &$layer4Divergences,
    ): void {
        if ($emailMsg === null || $apiMsg === null) {
            // At least one side never created a messages row (dropped/skipped
            // before ingestion) — no group to compare against, so this is a
            // divergence to report, not a same-group parity failure.
            $missingSide = match (true) {
                $emailMsg === null && $apiMsg === null => 'either side',
                $emailMsg === null => 'email side',
                default => 'API side',
            };
            $layer4Divergences[] = sprintf(
                'post_id=%s email_result=%s api_result=%s (no messages row on %s)',
                $postId,
                $emailResult,
                $apiResult,
                $missingSide,
            );
            return;
        }

        if ((string) ($emailMsg['groupid'] ?? '') !== (string) ($apiMsg['groupid'] ?? '')) {
            $layer4Divergences[] = sprintf(
                'post_id=%s different groups: email groupid=%s, api groupid=%s',
                $postId,
                $emailMsg['groupid'] ?? '?',
                $apiMsg['groupid'] ?? '?',
            );
            return;
        }

        // Same group on both sides — Layer 3: content + outcome must match
        // exactly. messageid/message/envelopefrom/fromip/sourceheader are
        // deliberately excluded — synthesized differently by design.
        $fieldDiffs = $this->diffMessageFields($emailMsg, $apiMsg);

        // `result` depends on the resolved user's membership/mapping state, which
        // is only comparable when both sides actually resolved to a real,
        // pre-existing user. If either side had to freshly stub-create the poster
        // this run (see parseStubUserIds()), the two are independently-created
        // rows with no shared identity, so a routing difference reflects that
        // divergence, not a genuine regression — skip the comparison, same
        // rationale as excluding `fromuser` from diffMessageFields() above.
        $emailUserIsStub = in_array((int) ($emailMsg['fromuser'] ?? -1), $emailStubUserIds, true);
        $apiUserIsStub   = in_array((int) ($apiMsg['fromuser'] ?? -1), $apiStubUserIds, true);
        if ($emailResult !== $apiResult && !$emailUserIsStub && !$apiUserIsStub) {
            $fieldDiffs[] = "result: email={$emailResult} api={$apiResult}";
        }

        if (!empty($fieldDiffs)) {
            $layer3Mismatches[] = 'post_id=' . $postId . ' groupid=' . $emailMsg['groupid'] . ': ' . implode('; ', $fieldDiffs);
        }
    }

    /**
     * `fromuser` is deliberately excluded from comparison. In production both
     * paths resolve to the same pre-existing user row (email by address
     * match, API by TN's fd_user_id), so they coincidentally agree — but
     * that's not guaranteed by either resolution mechanism itself. It
     * definitely doesn't hold when testing against live TN data with a
     * disposable DB: EmailReplaySyncer's stub-user creation (test-harness
     * only, see its docblock) has no TN numeric ID to key on — only an email
     * address — so it always gets a fresh auto-increment id, while the API
     * path's stub uses TN's real fd_user_id. Comparing the two would flag a
     * "mismatch" on every post where both sides had to stub-create the
     * poster, which is a test-harness artifact, not a content regression.
     *
     * @param  array<string, mixed>  $emailMsg
     * @param  array<string, mixed>  $apiMsg
     * @return string[]
     */
    // lat/lng round-trip through different code paths (email: header parsing;
    // API: JSON decoding) and can differ in float precision for the same
    // real-world coordinate — the API commonly returns fewer significant
    // decimal digits than the email path's parsed value (observed live:
    // email=51.360871130429 vs api=51.36087; email=51.232574885119 vs
    // api=51.232574 — both genuinely the same location). Round to 4 decimal
    // places (~11m) before comparing; 6dp (~11cm) was tried first and still
    // false-positived on these, since the API's own value had already lost
    // precision beyond ~6 significant digits before rounding even applies.
    private const COORDINATE_FIELDS = ['lat', 'lng'];
    private const COORDINATE_PRECISION = 4;

    private function diffMessageFields(array $emailMsg, array $apiMsg): array
    {
        $fieldsToCompare = ['type', 'subject', 'lat', 'lng', 'locationid'];
        $fieldDiffs = [];

        foreach ($fieldsToCompare as $field) {
            $emailVal = $emailMsg[$field] ?? null;
            $apiVal   = $apiMsg[$field] ?? null;

            if (in_array($field, self::COORDINATE_FIELDS, true) && is_numeric($emailVal) && is_numeric($apiVal)) {
                $matches = round((float) $emailVal, self::COORDINATE_PRECISION) === round((float) $apiVal, self::COORDINATE_PRECISION);
            } else {
                $matches = (string) $emailVal === (string) $apiVal;
            }

            if (!$matches) {
                $fieldDiffs[] = "{$field}: email=" . json_encode($emailVal) . ' api=' . json_encode($apiVal);
            }
        }

        return $fieldDiffs;
    }

    /**
     * Parses `TN-SYNC-TRACE [POST-RESULT|EMAIL-RESULT] post_id=X result=Y`
     * lines into a post_id => result map, lowercased so the email path's
     * PascalCase enum values (e.g. "Dropped") compare equal to the API
     * path's lowercase strings (e.g. "dropped"). Last occurrence wins if a
     * post_id appears twice (shouldn't happen in practice, but keeps this
     * defensive).
     *
     * [EMAIL-RESULT] (emitted by EmailReplaySyncer, not IncomingMailService)
     * is the reliable per-post outcome marker for the email path — some
     * IncomingMailService skip branches (e.g. unknown-group) never emit
     * their own internal [POST-RESULT] line, but EmailReplaySyncer emits
     * [EMAIL-RESULT] unconditionally after every route() call.
     *
     * @param  string[]  $lines
     * @return array<string, string>
     */
    public function parseResults(array $lines): array
    {
        $results = [];
        foreach ($lines as $line) {
            if (preg_match('/TN-SYNC-TRACE \[(?:POST-RESULT|EMAIL-RESULT)\] post_id=(\S+) result=(\S+)/', $line, $m)) {
                $results[$m[1]] = strtolower($m[2]);
            }
        }
        return $results;
    }

    /**
     * Parses the API-only pre-ingest [POST-SKIP] lines emitted by
     * PostSyncer::processPost() before GroupPostIngestionService::ingest()
     * is ever called (no-coordinates / not-in-any-group-bounds) — these
     * posts never produce a [POST-RESULT] line, and deliberately do NOT count
     * toward Layer 1 coverage (see computeLayers(): a post the email path
     * placed in a real group that the API path could place nowhere is a
     * genuine regression). Used only to enrich the Layer 1 failure detail.
     *
     * @param  string[]  $lines
     * @return array<string, string> post_id => reason
     */
    public function parsePreIngestSkips(array $lines): array
    {
        $skips = [];
        foreach ($lines as $line) {
            if (preg_match('/TN-SYNC-TRACE \[POST-SKIP\] reason=(no-coordinates|not-in-any-group-bounds).*post_id=(\S+)/', $line, $m)) {
                $skips[$m[2]] = $m[1];
            }
        }
        return $skips;
    }

    /**
     * Parses `TN-SYNC-TRACE [WRITE] table=users op=insert set=id=X,...` lines
     * into a set of user ids that were freshly created *this run*, on
     * whichever side's lines are passed in. Both `GroupPostIngestionService::
     * findOrCreateUser()` (API path) and `EmailReplaySyncer::ensureUserExists()`
     * (email path, test-harness only) emit this shape when they have to
     * stub-create a poster. Used to gate the `result` field comparison in
     * classifyOverlapPost() — a freshly-created stub has no shared identity
     * across the two paths (the email path only has an address to go on, no
     * TN numeric id), so its routing outcome isn't a meaningful signal to
     * compare, only real regressions on already-resolved users are.
     *
     * @param  string[]  $lines
     * @return int[]
     */
    public function parseStubUserIds(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            if (preg_match('/TN-SYNC-TRACE \[WRITE\] table=users op=insert set=id=(\d+)/', $line, $m)) {
                $ids[] = (int) $m[1];
            }
        }
        return $ids;
    }

    /**
     * Parses `TN-SYNC-TRACE [WRITE] table=messages op=insert set={json}`
     * lines into a tnpostid => decoded-fields map. Both paths emit this line
     * with the same key set (see class docblock), keyed by their shared
     * `tnpostid` field rather than the surrounding `post_id=` on other lines,
     * since that's what's actually inside the JSON payload here.
     *
     * @param  string[]  $lines
     * @return array<string, array<string, mixed>>
     */
    public function parseMessages(array $lines): array
    {
        $messages = [];
        foreach ($lines as $line) {
            if (!str_contains($line, 'TN-SYNC-TRACE [WRITE] table=messages op=insert set=')) {
                continue;
            }
            $json = substr($line, strpos($line, 'set=') + 4);
            $decoded = json_decode($json, true);
            if (is_array($decoded) && isset($decoded['tnpostid'])) {
                $messages[(string) $decoded['tnpostid']] = $decoded;
            }
        }
        return $messages;
    }

    /**
     * Labels that must be IDENTICAL between the two paths' Loki entries.
     * `source` is excluded because it is the one label deliberately allowed to
     * differ (incoming_mail vs tn_api) — see LokiService::SOURCE_TN_API.
     */
    private const LOKI_IGNORED_LABELS = ['source'];

    /**
     * Message keys excluded from the KEY-SET comparison, because one path
     * legitimately has them and the other does not:
     *  - tn_post_id: API-only; the email path cannot carry it (section I.5a).
     *  - dry_run: only present when the API path runs with --dry-run.
     */
    private const LOKI_IGNORED_KEYS = ['tn_post_id', 'dry_run'];

    /**
     * Message fields whose VALUES must match when the two paths agree on the
     * group. Deliberately excludes:
     *  - envelope_from / from_address: the API path has no SMTP envelope.
     *  - message_id: each path created its own messages row, so the numeric ids
     *    differ by construction.
     *  - user_id: handled separately, gated on stub-created users (see
     *    parseStubUserIds() — a freshly stubbed poster has no shared identity
     *    across the paths, so its id is not a meaningful comparison).
     */
    private const LOKI_COMPARED_FIELDS = ['routing_outcome', 'subject', 'group_id', 'group_name', 'routing_reason'];

    /**
     * Parses `TN-SYNC-TRACE [LOKI] post_id=X entry={json}` lines into a
     * post_id => decoded-entry map.
     *
     * Emitted by each path's CALLER — EmailReplaySyncer for the email path,
     * PostSyncer for the API path — mirroring where production emits its Loki
     * entry (IncomingMailController / PostSyncer respectively). Traced rather
     * than read back from incoming_mail.log because the email path's entry
     * carries no tn_post_id and so could not otherwise be correlated per post.
     *
     * @param  string[]  $lines
     * @return array<string, array<string, mixed>>
     */
    public function parseLokiEntries(array $lines): array
    {
        $entries = [];
        foreach ($lines as $line) {
            if (!preg_match('/TN-SYNC-TRACE \[LOKI\] post_id=(\S+) entry=(.*)$/', $line, $m)) {
                continue;
            }
            $decoded = json_decode($m[2], true);
            if (is_array($decoded)) {
                $entries[$m[1]] = $decoded;
            }
        }
        return $entries;
    }

    /**
     * Message keys ALWAYS present on both paths, because LokiService::
     * buildRoutedMessage() writes them unconditionally. Everything else in the
     * message comes from the routing context, which legitimately varies by
     * branch — see the docblock on compareLokiEntries().
     */
    private const LOKI_FIXED_FIELDS = [
        'envelope_from', 'envelope_to', 'from_address', 'subject', 'message_id', 'routing_outcome',
    ];

    /**
     * Layer 5 — Loki entry parity (hard fail).
     *
     * Layers 1-4 compare what each path wrote to the DATABASE. This one
     * compares what each path reported to LOKI, a separate contract: the two
     * streams are consumed together by one query spanning both `source`
     * values, so a divergence there breaks the comparison even when ingestion
     * itself was correct.
     *
     * Split into two levels, for the same reason Layers 3 and 4 are split:
     *
     *  - STRUCTURAL, checked for every overlapping post. Only invariants that
     *    hold REGARDLESS of routing outcome: the label key set, the two
     *    expected `source` values, presence of the always-written message
     *    fields, and internal consistency between the `subtype` label and the
     *    `routing_outcome` field.
     *
     *  - OUTCOME-DEPENDENT, checked only where both paths created a messages
     *    row on the SAME group. Label VALUES (`subtype` is the outcome), the
     *    context key set, and the compared field values all legitimately
     *    differ when the two paths reached different outcomes — that is not a
     *    logging bug, it is the routing difference Layer 4 already reports.
     *
     * The distinction was found the hard way on live data: a post already
     * ingested by an earlier run made the email path take its duplicate-
     * messageid branch (keeping group/user context, no message_id) while the
     * API path took its postAlreadyExists() branch (routing_reason only). Both
     * are correct, and both are exactly what the email path does — but an
     * unconditional key-set comparison reported them as a Loki failure.
     *
     * @param  string[]  $overlap  post_ids present on both paths
     * @param  array<string, array<string, mixed>>  $emailEntries
     * @param  array<string, array<string, mixed>>  $apiEntries
     * @param  array<string, array<string, mixed>>  $emailMessages
     * @param  array<string, array<string, mixed>>  $apiMessages
     * @param  int[]  $emailStubUserIds
     * @param  int[]  $apiStubUserIds
     * @return array{0: string[], 1: int, 2: int}  [mismatches, fullyComparedCount, structureOnlyCount]
     */
    private function compareLokiEntries(
        array $overlap,
        array $emailEntries,
        array $apiEntries,
        array $emailMessages,
        array $apiMessages,
        array $emailStubUserIds,
        array $apiStubUserIds,
    ): array {
        $mismatches    = [];
        $fullyCompared = 0;
        $structureOnly = 0;

        foreach ($overlap as $postId) {
            $email = $emailEntries[$postId] ?? null;
            $api   = $apiEntries[$postId] ?? null;

            // A path that handled the post but reported nothing to Loki is
            // itself a failure: the item silently vanishes from the stream.
            if ($email === null || $api === null) {
                if ($email === null && $api === null) {
                    continue;  // Neither side emitted — Loki disabled for this run.
                }
                $mismatches[] = sprintf(
                    'post_id=%s: only the %s path emitted a Loki entry',
                    $postId,
                    $email !== null ? 'email' : 'API',
                );
                continue;
            }

            $mismatches = array_merge($mismatches, $this->diffLokiStructure($postId, $email, $api));

            // Outcome-dependent checks need the two paths to have landed the
            // post on the same group — otherwise the outcomes, and therefore
            // the context each carries, legitimately differ.
            $emailMsg = $emailMessages[$postId] ?? null;
            $apiMsg   = $apiMessages[$postId] ?? null;
            $sameGroup = $emailMsg !== null && $apiMsg !== null
                && (string) ($emailMsg['groupid'] ?? '') === (string) ($apiMsg['groupid'] ?? '');

            if (!$sameGroup) {
                $structureOnly++;
                continue;
            }

            $fullyCompared++;
            $mismatches = array_merge(
                $mismatches,
                $this->diffLokiOutcome($postId, $email, $api, $emailMsg, $apiMsg, $emailStubUserIds, $apiStubUserIds),
            );
        }

        return [$mismatches, $fullyCompared, $structureOnly];
    }

    /**
     * Outcome-INDEPENDENT checks: these must hold for every post, whatever
     * either path decided to do with it.
     *
     * @return string[]
     */
    private function diffLokiStructure(string $postId, array $email, array $api): array
    {
        $mismatches = [];

        $emailLabelKeys = array_keys($email['labels'] ?? []);
        $apiLabelKeys   = array_keys($api['labels'] ?? []);
        sort($emailLabelKeys);
        sort($apiLabelKeys);
        if ($emailLabelKeys !== $apiLabelKeys) {
            $mismatches[] = sprintf(
                'post_id=%s: label key sets differ: email=%s api=%s',
                $postId,
                json_encode($emailLabelKeys),
                json_encode($apiLabelKeys),
            );
        }

        foreach ([['email', $email, 'incoming_mail'], ['api', $api, 'tn_api']] as [$side, $entry, $expectedSource]) {
            if (($entry['labels']['source'] ?? null) !== $expectedSource) {
                $mismatches[] = sprintf(
                    'post_id=%s: %s entry has source=%s, expected %s',
                    $postId,
                    $side,
                    var_export($entry['labels']['source'] ?? null, true),
                    $expectedSource,
                );
            }

            $missing = array_values(array_diff(self::LOKI_FIXED_FIELDS, array_keys($entry['message'] ?? [])));
            if ($missing !== []) {
                $mismatches[] = sprintf(
                    'post_id=%s: %s entry is missing always-present field(s) %s',
                    $postId,
                    $side,
                    json_encode($missing),
                );
            }

            // The subtype label and the routing_outcome field are two copies of
            // the same value; a dashboard filtering on one and reading the
            // other would silently disagree if they ever drifted.
            if (($entry['labels']['subtype'] ?? null) !== ($entry['message']['routing_outcome'] ?? null)) {
                $mismatches[] = sprintf(
                    'post_id=%s: %s entry subtype label (%s) does not match routing_outcome (%s)',
                    $postId,
                    $side,
                    var_export($entry['labels']['subtype'] ?? null, true),
                    var_export($entry['message']['routing_outcome'] ?? null, true),
                );
            }
        }

        return $mismatches;
    }

    /**
     * Outcome-DEPENDENT checks, valid only once both paths are known to have
     * landed the post on the same group.
     *
     * @return string[]
     */
    private function diffLokiOutcome(
        string $postId,
        array $email,
        array $api,
        array $emailMsg,
        array $apiMsg,
        array $emailStubUserIds,
        array $apiStubUserIds,
    ): array {
        $mismatches = [];

        $emailLabels = array_diff_key($email['labels'] ?? [], array_flip(self::LOKI_IGNORED_LABELS));
        $apiLabels   = array_diff_key($api['labels'] ?? [], array_flip(self::LOKI_IGNORED_LABELS));
        if ($emailLabels !== $apiLabels) {
            $mismatches[] = sprintf(
                'post_id=%s: labels differ: email=%s api=%s',
                $postId,
                json_encode($emailLabels),
                json_encode($apiLabels),
            );
        }

        $emailKeys = array_values(array_diff(array_keys($email['message'] ?? []), self::LOKI_IGNORED_KEYS));
        $apiKeys   = array_values(array_diff(array_keys($api['message'] ?? []), self::LOKI_IGNORED_KEYS));
        sort($emailKeys);
        sort($apiKeys);
        if ($emailKeys !== $apiKeys) {
            $mismatches[] = sprintf(
                'post_id=%s: message field sets differ: email-only=%s api-only=%s',
                $postId,
                json_encode(array_values(array_diff($emailKeys, $apiKeys))),
                json_encode(array_values(array_diff($apiKeys, $emailKeys))),
            );
        }

        foreach (self::LOKI_COMPARED_FIELDS as $field) {
            $emailValue = $email['message'][$field] ?? null;
            $apiValue   = $api['message'][$field] ?? null;
            if ((string) $emailValue !== (string) $apiValue) {
                $mismatches[] = sprintf(
                    'post_id=%s: %s differs: email=%s api=%s',
                    $postId,
                    $field,
                    var_export($emailValue, true),
                    var_export($apiValue, true),
                );
            }
        }

        // user_id only when neither side stub-created this poster during the
        // run — same gate classifyOverlapPost() applies to `result`.
        $emailStubbed = in_array((int) ($emailMsg['fromuser'] ?? 0), $emailStubUserIds, true);
        $apiStubbed   = in_array((int) ($apiMsg['fromuser'] ?? 0), $apiStubUserIds, true);
        if (!$emailStubbed && !$apiStubbed
            && (string) ($email['message']['user_id'] ?? '') !== (string) ($api['message']['user_id'] ?? '')) {
            $mismatches[] = sprintf(
                'post_id=%s: user_id differs: email=%s api=%s',
                $postId,
                var_export($email['message']['user_id'] ?? null, true),
                var_export($api['message']['user_id'] ?? null, true),
            );
        }

        return $mismatches;
    }

    /**
     * Parses `TN-SYNC-TRACE [POST] post_id=X type=Y group_id=Z date=D title=T`
     * lines into a post_id => details map. Applied to one side's lines at a
     * time by the caller (email or API) — both paths emit this tag, so
     * calling it on the combined set would silently merge two different
     * posts' details under one key.
     *
     * @param  string[]  $lines
     * @return array<string, array{type: string, group_id: string, date: string, title: string}>
     */
    public function parsePostDetails(array $lines): array
    {
        $details = [];
        foreach ($lines as $line) {
            if (preg_match('/TN-SYNC-TRACE \[POST\] post_id=(\S+) type=(\S+) group_id=(\S+) date=(\S+) title=(.*)$/', $line, $m)) {
                $details[$m[1]] = [
                    'type'     => $m[2],
                    'group_id' => $m[3],
                    'date'     => $m[4],
                    'title'    => $m[5],
                ];
            }
        }
        return $details;
    }
}
