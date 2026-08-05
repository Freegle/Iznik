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
        return (bool) preg_match('/TN-SYNC-TRACE \[(POST-RESULT|EMAIL-RESULT|POST-SKIP|POST|EMAIL)\]/', $text)
            || (bool) preg_match('/TN-SYNC-TRACE \[WRITE\] table=/', $text);
    }

    // Routing outcomes meaning a messages row was actually created (i.e. the
    // post landed on FD, awaiting or past moderation) — as opposed to
    // dropped/skipped/duplicate, where nothing was ingested. Used to turn
    // Layer 2's raw "extra post_ids" into a genuine "how many more posts are
    // we actually getting" figure.
    private const INGESTED_RESULTS = ['approved', 'pending'];

    /**
     * Parses both trace-line sets and buckets post_ids into the four layers.
     *
     * @param  string[]  $emailLines
     * @param  string[]  $apiLines
     * @return array{
     *     emailPostIdCount: int, apiCoveredCount: int,
     *     emailIngestedCount: int, apiIngestedCount: int, apiDuplicatesDropped: string[],
     *     layer2ExtraIngested: string[],
     *     layer1Missing: string[], layer1Details: array<string, string>,
     *     layer2Extra: string[],
     *     overlapCount: int, layer3Mismatches: string[], layer4Divergences: string[],
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
        $apiPostDetails    = $this->parsePostDetails($apiLines);
        $emailStubUserIds  = $this->parseStubUserIds($emailLines);
        $apiStubUserIds    = $this->parseStubUserIds($apiLines);

        // Collapse TN API crosspost/repost duplicates before anything else uses
        // apiResults/apiMessages — see dedupeApiCrosspostsAndReposts() docblock.
        // Layer 1 coverage is computed against apiResultPostIds too, but a
        // dropped duplicate can never cause a false Layer 1 miss: if the email
        // path saw that exact post_id, it wasn't a candidate for dedup removal
        // in the first place (post_ids are TN-API-only identifiers the email
        // path never has).
        [$apiResults, $apiMessages, $apiDuplicatesDropped] = $this->dedupeApiCrosspostsAndReposts(
            $apiResults,
            $apiMessages,
            $apiPostDetails,
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

        $emailIngestedCount = count(array_filter($emailResults, static fn (string $r) => in_array($r, self::INGESTED_RESULTS, true)));
        $apiIngestedCount   = count(array_filter($apiResults, static fn (string $r) => in_array($r, self::INGESTED_RESULTS, true)));

        return [
            'emailPostIdCount'      => count($emailPostIds),
            'apiCoveredCount'       => count($apiResultPostIds),
            'emailIngestedCount'    => $emailIngestedCount,
            'apiIngestedCount'      => $apiIngestedCount,
            'apiDuplicatesDropped'  => $apiDuplicatesDropped,
            'layer2ExtraIngested'   => $layer2ExtraIngested,
            'layer1Missing'         => $layer1Missing,
            'layer1Details'         => $layer1Details,
            'layer2Extra'           => $layer2Extra,
            'overlapCount'        => count($overlap),
            'layer3Mismatches'    => $layer3Mismatches,
            'layer4Divergences'   => $layer4Divergences,
        ];
    }

    /**
     * Collapses TN API crosspost/repost duplicates: TN returns a distinct
     * post_id per group a poster cross-posted to, and — per confirmation
     * from the TN team — a repost also creates an entirely new post_id with
     * a new published date, rather than mutating the original. Freegle
     * already has its own cross-posting (rippling) and reposting mechanisms,
     * so counting each of these TN-side duplicates as a separate "new" post
     * would inflate Layer 2 / the ingestion-gain stat with the same
     * real-world donation counted multiple times. This has no email-path
     * equivalent to worry about: TN's partner email feed reuses the *same*
     * post_id across a crosspost's emails (confirmed early in this project),
     * so email-side duplicates already collapse naturally when parseResults()
     * builds its post_id-keyed map — only the API path needs this.
     *
     * Groups ingested posts (a messages row must exist — nothing to compare
     * without one) by (subject, rounded lat, rounded lng) and keeps
     * only the earliest-dated post_id per group as canonical; every other
     * post_id in that group is dropped entirely from apiResults/apiMessages,
     * removing it from every layer, not just Layer 2.
     *
     * @param  array<string, string>  $apiResults
     * @param  array<string, array<string, mixed>>  $apiMessages
     * @param  array<string, array{type: string, group_id: string, date: string, title: string}>  $apiPostDetails
     * @return array{0: array<string, string>, 1: array<string, array<string, mixed>>, 2: string[]}
     *   [dedupedResults, dedupedMessages, droppedPostIds]
     */
    private function dedupeApiCrosspostsAndReposts(array $apiResults, array $apiMessages, array $apiPostDetails): array
    {
        $canonicalPostIdByKey = [];
        $earliestDateByKey    = [];

        foreach ($apiMessages as $postId => $msg) {
            // Deliberately excludes fromuser: TN assigns a different numeric
            // user id per group-affiliation for the same real person (see
            // GroupPostIngestionService::findRepostCandidate() for the live
            // case that discovered this), so a cross-group crosspost by the
            // same poster legitimately shows two different fromuser values.
            // Keying on it here would silently stop collapsing exactly the
            // cross-group case this method exists to catch.
            $key = implode('|', [
                strtolower(trim((string) ($msg['subject'] ?? ''))),
                is_numeric($msg['lat'] ?? null) ? round((float) $msg['lat'], self::COORDINATE_PRECISION) : ($msg['lat'] ?? ''),
                is_numeric($msg['lng'] ?? null) ? round((float) $msg['lng'], self::COORDINATE_PRECISION) : ($msg['lng'] ?? ''),
            ]);
            $date = $apiPostDetails[$postId]['date'] ?? '';

            if (!isset($earliestDateByKey[$key]) || $date < $earliestDateByKey[$key]) {
                $earliestDateByKey[$key]    = $date;
                $canonicalPostIdByKey[$key] = $postId;
            }
        }

        $canonicalPostIds = array_fill_keys(array_values($canonicalPostIdByKey), true); // post_id => true, for O(1) lookup
        $dropped          = [];

        foreach (array_keys($apiMessages) as $postId) {
            if (!isset($canonicalPostIds[$postId])) {
                $dropped[] = $postId;
            }
        }

        foreach ($dropped as $postId) {
            unset($apiResults[$postId], $apiMessages[$postId]);
        }

        return [$apiResults, $apiMessages, $dropped];
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
     * posts never produce a [POST-RESULT] line, but the API path did "see"
     * them, so they count toward Layer 1 coverage.
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
