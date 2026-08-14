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
 *    regression — it is out of our area.
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

            // Free local check before the rate-limited remote one. A post whose
            // own coordinates place it nowhere was dropped by the API path on
            // purpose, so there is nothing to ask TN about. Crossposts always
            // carry real coordinates, so this cannot swallow one.
            if (! $this->isPlaceable($entry)) {
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
     * Reconstruct the API path's placement decision from the email's own
     * coordinates.
     *
     * Post-cutover there is no email-path verdict to compare against, but the
     * X-Trash-Nothing-Post-Coordinates header carries the same lat/lng the API
     * would have used, so running the same Location::groupsNear() lookup
     * recovers the decision independently. If the coordinates DO resolve to a
     * group and the post is still missing, that is the Layer 1 coverage
     * regression this whole check exists to catch — so this must only return
     * false when the post genuinely places nowhere.
     */
    private function isPlaceable(array $entry): bool
    {
        if ($entry['lat'] === null || $entry['lng'] === null) {
            return false;
        }

        return ! empty(Location::groupsNear((float) $entry['lat'], (float) $entry['lng'], limit: 1));
    }

    /**
     * Map a lookupPostById() result onto a bucket.
     *
     * @param  array{status: string, date: string|null, outcome: string|null, group_id: string|null, post: mixed}  $result
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
