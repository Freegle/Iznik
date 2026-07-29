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
 *   result for must also be covered by the API path — either via its own
 *   [POST-RESULT], or via one of its two pre-ingest [POST-SKIP] reasons
 *   (no-coordinates / not-in-any-group-bounds). A post the email path could
 *   place that the API path drops entirely is a real regression.
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

    /**
     * Parses both trace-line sets and buckets post_ids into the four layers.
     *
     * @param  string[]  $emailLines
     * @param  string[]  $apiLines
     * @return array{
     *     emailPostIdCount: int, apiCoveredCount: int,
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

        $emailPostIds       = array_keys($emailResults);
        $apiResultPostIds   = array_keys($apiResults);
        $apiCoveragePostIds = array_unique(array_merge($apiResultPostIds, array_keys($apiPreIngestSkips)));

        // Layer 1: coverage (hard fail). Report the email path's full picture of
        // each missing post — there's nothing on the API side to show, since
        // the whole point of a Layer 1 failure is that it never covered this
        // post_id at all.
        $layer1Missing = array_values(array_diff($emailPostIds, $apiCoveragePostIds));
        $layer1Details = [];
        foreach ($layer1Missing as $postId) {
            $layer1Details[$postId] = $this->formatLayer1Detail(
                $postId,
                $emailResults[$postId] ?? '?',
                $emailPostDetails[$postId] ?? null,
                $emailMessages[$postId] ?? null,
            );
        }

        // Layer 2: extra posts (informational).
        $layer2Extra = array_values(array_diff($apiResultPostIds, $emailPostIds));

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
                $layer3Mismatches,
                $layer4Divergences,
            );
        }

        return [
            'emailPostIdCount'   => count($emailPostIds),
            'apiCoveredCount'    => count($apiCoveragePostIds),
            'layer1Missing'      => $layer1Missing,
            'layer1Details'      => $layer1Details,
            'layer2Extra'        => $layer2Extra,
            'overlapCount'       => count($overlap),
            'layer3Mismatches'   => $layer3Mismatches,
            'layer4Divergences'  => $layer4Divergences,
        ];
    }

    /**
     * Builds the full-detail block printed/asserted for a single Layer 1
     * (coverage) failure: the email path's result, its [POST] summary
     * (type/group_id/date/title — always present), and its full
     * messages-row field set if the email path actually created a message
     * for this post.
     *
     * @param  array{type: string, group_id: string, date: string, title: string}|null  $postDetail
     * @param  array<string, mixed>|null  $message
     */
    private function formatLayer1Detail(string $postId, string $emailResult, ?array $postDetail, ?array $message): string
    {
        $parts = ["post_id={$postId}", "email_result={$emailResult}"];

        if ($postDetail !== null) {
            $parts[] = 'type=' . $postDetail['type'];
            $parts[] = 'group_id=' . $postDetail['group_id'];
            $parts[] = 'date=' . $postDetail['date'];
            $parts[] = 'title=' . $postDetail['title'];
        }

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
     * @param  string[]  $layer3Mismatches
     * @param  string[]  $layer4Divergences
     */
    private function classifyOverlapPost(
        string $postId,
        ?array $emailMsg,
        ?array $apiMsg,
        string $emailResult,
        string $apiResult,
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

        if ($emailResult !== $apiResult) {
            $fieldDiffs[] = "result: email={$emailResult} api={$apiResult}";
        }

        if (!empty($fieldDiffs)) {
            $layer3Mismatches[] = 'post_id=' . $postId . ' groupid=' . $emailMsg['groupid'] . ': ' . implode('; ', $fieldDiffs);
        }
    }

    /**
     * @param  array<string, mixed>  $emailMsg
     * @param  array<string, mixed>  $apiMsg
     * @return string[]
     */
    private function diffMessageFields(array $emailMsg, array $apiMsg): array
    {
        $fieldsToCompare = ['fromuser', 'type', 'subject', 'lat', 'lng', 'locationid'];
        $fieldDiffs = [];

        foreach ($fieldsToCompare as $field) {
            $emailVal = $emailMsg[$field] ?? null;
            $apiVal   = $apiMsg[$field] ?? null;
            if ((string) $emailVal !== (string) $apiVal) {
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
