<?php

namespace App\Services\TrashNothing\Verify;

use App\Models\Location;
use App\Models\Message;
use App\Services\TrashNothing\Sync\PostSyncer;
use Carbon\CarbonImmutable;

/**
 * Decides, for each TN post email in an archive inventory, whether the API path
 * ingested it — and if not, whether its absence is expected or a real gap.
 *
 * Extracted from the command for the same reason ParityComparer was (section
 * Q): the classification is where all the subtlety lives, and it should be
 * unit-testable without a CLI or a live API in the way.
 *
 * The naive check — "is there a messages row with this tnpostid?" — produces
 * constant false alarms, because several classes of post are absent BY DESIGN.
 * See plans/tn-api-post-ingestion.md section S.4. In descending order of
 * expected volume:
 *
 *  - CROSSPOST. TN gives each per-group copy of a post its own post id, and the
 *    email path ingests each copy as its own message, so an item crossposted to
 *    N groups produces N emails. The API path deliberately ingests only the
 *    source post (empty group_id) and discards the copies, letting Freegle's
 *    own rippling do the cross-posting — see
 *    GroupPostIngestionService::REASON_CROSSPOST and commit d5f0b4983. So N-1
 *    of those post ids will never have a messages row, and never should.
 *  - UNPLACEABLE. The post's coordinates fall outside every Freegle group's
 *    bounds (or are absent), so the API path dropped it. Not a Freegle
 *    regression — it is out of our area. Decided from the LIVE post wherever
 *    a lookup happens: the archived email's coordinates header is only ever
 *    allowed to confirm out-of-area, never to assume it — see
 *    headerPlacesNowhere().
 *  - DELETED / RESOLVED / BUMPED. Handled exactly as tn:parity-check's Layer 1
 *    reclassification handles them.
 *
 * Anything left over is a genuine coverage gap: TN emailed us a placeable
 * source post that is still live, and it is not in the database.
 */
class CoverageVerifier
{
    /** Post ids to look up in one `messages` query. */
    private const DB_CHUNK = 500;

    public const COVERED = 'covered';

    public const CROSSPOST = 'crosspost';

    public const UNPLACEABLE = 'unplaceable';

    public const DELETED = 'deleted';

    public const RESOLVED = 'resolved';

    public const BUMPED = 'bumped';

    public const LOOKUP_ERROR = 'lookup_error';

    public const GENUINE = 'genuine';

    /**
     * Classify every post in the inventory.
     *
     * @param  array<string, array{post_id: string, timestamp: string, subject: string|null, lat: float|null, lng: float|null, envelope_to: string, outcome: string|null, path: string}>  $inventory
     * @return array{
     *     counts: array<string, int>,
     *     covered: string[],
     *     crosspost: string[],
     *     unplaceable: string[],
     *     deleted: string[],
     *     resolved: string[],
     *     bumped: string[],
     *     lookup_error: string[],
     *     genuine: array<string, array{post_id: string, subject: string|null, timestamp: string, date: string|null, envelope_to: string, post: mixed}>,
     *     api_lookups: int
     * }
     */
    public function verify(
        array $inventory,
        PostSyncer $syncer,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $buckets = [
            self::COVERED      => [],
            self::CROSSPOST    => [],
            self::UNPLACEABLE  => [],
            self::DELETED      => [],
            self::RESOLVED     => [],
            self::BUMPED       => [],
            self::LOOKUP_ERROR => [],
        ];
        $genuine    = [];
        $apiLookups = 0;

        $covered = $this->coveredPostIds(array_keys($inventory));

        foreach ($inventory as $postId => $entry) {
            if (isset($covered[$postId])) {
                $buckets[self::COVERED][] = $postId;
                continue;
            }

            // Free local check before the rate-limited remote one — but only
            // when the email's header actually said something. A header that
            // names coordinates outside every group's bounds is a post the
            // email path would itself have dropped as an unknown group, so
            // there is nothing to ask TN about. Crossposts always carry real
            // coordinates, so this cannot swallow one. A missing or malformed
            // header says nothing at all, and must NOT be read as
            // "unplaceable" — that is exactly the coverage gap this check
            // exists to catch, so those go to the API like any other absentee
            // and are judged on the live post's own coordinates.
            if ($this->headerPlacesNowhere($entry)) {
                $buckets[self::UNPLACEABLE][] = $postId;
                continue;
            }

            $apiLookups++;
            $result = $syncer->lookupPostById($postId);

            $bucket = $this->classifyLookup($result, $from, $to);

            if ($bucket !== self::GENUINE) {
                $buckets[$bucket][] = $postId;
                continue;
            }

            $genuine[$postId] = [
                'post_id'     => $postId,
                'subject'     => $entry['subject'],
                'timestamp'   => $entry['timestamp'],
                'date'        => $result['date'],
                'envelope_to' => $entry['envelope_to'],
                'post'        => $result['post'],
            ];
        }

        $counts = array_map('count', $buckets);
        $counts[self::GENUINE] = count($genuine);

        return array_merge($buckets, [
            'counts'      => $counts,
            self::GENUINE => $genuine,
            'api_lookups' => $apiLookups,
        ]);
    }

    /**
     * Which of these post ids already have a message.
     *
     * @param  string[]  $postIds
     * @return array<string, true> set, for O(1) membership
     */
    private function coveredPostIds(array $postIds): array
    {
        $covered = [];

        foreach (array_chunk($postIds, self::DB_CHUNK) as $chunk) {
            $found = Message::whereIn('tnpostid', $chunk)
                ->pluck('tnpostid')
                ->all();

            foreach ($found as $tnPostId) {
                $covered[(string) $tnPostId] = true;
            }
        }

        return $covered;
    }

    /**
     * Does the archived email's own header place this post outside every group?
     *
     * Purely an optimisation: a `true` here saves a request against a 2-req/s
     * limit, and it is safe because the X-Trash-Nothing-Post-Coordinates header
     * is what the email path itself placed the post from — a post it would have
     * dropped as an unknown group cannot be evidence that the API path lost
     * one.
     *
     * The reverse inference is NOT safe, so this returns false for anything it
     * cannot positively establish. An absent header, a malformed one, or one
     * that does resolve to a group all mean "ask TN"; isPlaceable() then
     * decides from the live post. Short-circuiting to UNPLACEABLE on a missing
     * header would file the Layer 1 coverage regression this whole check exists
     * to catch as expected-absent, and file it in the very bucket nobody reads.
     */
    private function headerPlacesNowhere(array $entry): bool
    {
        if ($entry['lat'] === null || $entry['lng'] === null) {
            return false;
        }

        return ! $this->isPlaceable((float) $entry['lat'], (float) $entry['lng']);
    }

    /**
     * Would PostSyncer have found a group for these coordinates?
     *
     * The same Location::groupsNear() call processPost() places a post with, so
     * the answer reconstructs the API path's own decision rather than guessing
     * at it.
     */
    private function isPlaceable(?float $lat, ?float $lng): bool
    {
        if ($lat === null || $lng === null) {
            return false;
        }

        return ! empty(Location::groupsNear($lat, $lng, limit: 1));
    }

    /**
     * Map a lookupPostById() result onto a bucket.
     *
     * @param  array{status: string, date: string|null, outcome: string|null, group_id: string|null, lat: float|null, lng: float|null, post: mixed}  $result
     */
    private function classifyLookup(array $result, CarbonImmutable $from, CarbonImmutable $to): string
    {
        if ($result['status'] === 'not_found') {
            return self::DELETED;
        }

        // Couldn't reach TN. Deliberately NOT counted as a miss — an API blip
        // must not manufacture a coverage alert, still less a backfill write.
        if ($result['status'] !== 'found') {
            return self::LOOKUP_ERROR;
        }

        // A per-group copy. The single largest source of expected absences.
        if (! empty($result['group_id'])) {
            return self::CROSSPOST;
        }

        if (in_array($result['outcome'], PostSyncer::RESOLVED_OUTCOMES, true)) {
            return self::RESOLVED;
        }

        // TN mutates `date` on repost/edit, which moves a post out of the
        // window anchored to when it was first emailed — so the sync covering
        // that window was never offered it.
        if ($this->isOutsideWindow($result['date'], $from, $to)) {
            return self::BUMPED;
        }

        // Last, and against the live post rather than the email: processPost()
        // places a post from the coordinates TN holds NOW, so these are the
        // ones that decide whether it was dropped as no-coordinates /
        // not-in-any-group-bounds. A post TN never mapped has none at all, and
        // one whose location was edited after the email may place differently
        // from the header. Backfilling either would be a no-op that comes back
        // as a repeat miss on the next run.
        if (! $this->isPlaceable($result['lat'], $result['lng'])) {
            return self::UNPLACEABLE;
        }

        return self::GENUINE;
    }

    private function isOutsideWindow(?string $date, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        if ($date === null) {
            return false;
        }

        try {
            $parsed = CarbonImmutable::parse($date, 'UTC');
        } catch (\Throwable) {
            return false;
        }

        return $parsed->lt($from) || $parsed->gt($to);
    }
}
