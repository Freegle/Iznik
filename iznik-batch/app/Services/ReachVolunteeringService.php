<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Location;
use Html2Text\Html2Text;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReachVolunteeringService
{
    private const EXPIRE_AGE_DAYS = 31;
    private const POSTCODE        = '/([Gg][Ii][Rr] 0[Aa]{2})|((([A-Za-z][0-9]{1,2})|(([A-Za-z][A-Ha-hJ-Yj-y][0-9]{1,2})|(([A-Za-z][0-9][A-Za-z])|([A-Za-z][A-Ha-hJ-Yj-y][0-9][A-Za-z]?))))\s?[0-9][A-Za-z]{2})/mi';

    protected function fetchFeedData(string $feedUrl): string
    {
        $response = Http::timeout(120)
            ->withBasicAuth(
                config('freegle.reach_volunteering.username'),
                config('freegle.reach_volunteering.password')
            )
            ->get($feedUrl);

        if (!$response->successful()) {
            throw new \RuntimeException("Reach Volunteering feed request failed: HTTP {$response->status()}");
        }

        return $response->body();
    }

    /**
     * Fetch the feed and decode it to an array of opportunities, retrying on
     * payloads that don't decode to an array.
     *
     * Reach's Drupal feed intermittently returns a PHP fatal-error page
     * ("Maximum execution time of 30 seconds exceeded") with an HTTP 200, so a
     * successful HTTP status isn't enough — we have to validate the body. The
     * timeout is load-related, so a later attempt often succeeds.
     *
     * On total failure we throw rather than return [] on purpose: returning an
     * empty set would make the caller treat every Reach opportunity as absent
     * and mark the lot deleted on a transient outage.
     */
    protected function fetchOpportunities(string $feedUrl): array
    {
        $attempts = max(1, (int) config('freegle.reach_volunteering.fetch_attempts', 3));
        $delay    = max(0, (int) config('freegle.reach_volunteering.retry_delay_seconds', 30));

        $body    = '';
        $decoded = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $body    = $this->fetchFeedData($feedUrl);
            $decoded = json_decode($body, true, 512, JSON_INVALID_UTF8_IGNORE);

            if (is_array($decoded)) {
                return $decoded;
            }

            Log::warning('ReachVolunteering: feed did not decode to array', [
                'attempt'      => $attempt,
                'attempts'     => $attempts,
                'json_error'   => json_last_error_msg(),
                'decoded_type' => gettype($decoded),
                'body_length'  => strlen($body),
                'body_sample'  => substr($body, 0, 500),
            ]);

            if ($attempt < $attempts && $delay > 0) {
                sleep($delay);
            }
        }

        // All attempts exhausted — the body still isn't a JSON array. Capture
        // enough context to triage without a repro (Sentry doesn't record
        // `$body` as a frame var) and throw to protect the deletion phase.
        $type    = gettype($decoded);
        $jsonErr = json_last_error_msg();
        $bodyLen = strlen($body);
        $sample  = substr($body, 0, 200);

        Log::error('ReachVolunteering: unexpected feed payload', [
            'attempts'     => $attempts,
            'json_error'   => $jsonErr,
            'decoded_type' => $type,
            'body_length'  => $bodyLen,
            'body_sample'  => substr($body, 0, 500),
        ]);

        throw new \RuntimeException(sprintf(
            'Reach Volunteering feed: expected JSON array after %d attempt(s), got %s (json_error=%s, body_length=%d, body_sample=%s)',
            $attempts,
            $type,
            $jsonErr,
            $bodyLen,
            $sample
        ));
    }

    public function sync(bool $dryRun = false): array
    {
        $added   = 0;
        $updated = 0;
        $deleted = 0;

        $feedUrl = config('freegle.reach_volunteering.feed_url');
        $opps    = $this->fetchOpportunities($feedUrl);

        $externalsSeen = [];
        $urlsSeen      = [];

        foreach ($opps as $opp) {
            $jobId      = $opp['job_id'] ?? null;
            $externalId = "reach-{$jobId}";
            $url        = $opp['url'] ?? '';

            // Track as seen BEFORE any early returns to avoid spurious deletes.
            $externalsSeen[$externalId] = true;
            $urlsSeen[$url]             = true;

            $datePosted = $opp['date_posted'] ?? null;
            $ageInDays  = $datePosted
                ? (time() - strtotime($datePosted)) / 86400
                : PHP_INT_MAX;

            if ($ageInDays > self::EXPIRE_AGE_DAYS) {
                Log::debug('ReachVolunteering: skipping old opportunity', ['job_id' => $jobId, 'date_posted' => $datePosted]);
                continue;
            }

            // New format: location field contains "Town, Postcode, Country"
            $locationField = $opp['location'] ?? '';
            if (!preg_match(self::POSTCODE, $locationField, $matches)) {
                Log::debug('ReachVolunteering: no postcode in location', ['location' => $locationField]);
                continue;
            }

            $postcode = strtoupper($matches[0]);
            $locRow   = Location::getByName($postcode);

            if (!$locRow) {
                Log::debug('ReachVolunteering: postcode not found', ['postcode' => $postcode]);
                continue;
            }

            $groupIds = Location::groupsNear((float) $locRow->lat, (float) $locRow->lng);

            if (empty($groupIds)) {
                Log::debug('ReachVolunteering: no groups near postcode', ['postcode' => $postcode]);
                continue;
            }

            $group = Group::find($groupIds[0]);
            if (!$group) {
                continue;
            }

            if (!$group->getSetting('volunteering', 1)) {
                Log::debug('ReachVolunteering: volunteering disabled', ['group' => $group->nameshort]);
                continue;
            }

            $title    = $opp['title'] ?? '';
            $descRaw  = $opp['description'] ?? '';
            $desc     = (new Html2Text($descRaw))->getText();

            $organisation = $opp['organisation'] ?? null;
            if ($organisation) {
                $desc = "Posted by {$organisation}.\n\n{$desc}";
            }

            // Strip country suffix from location display.
            $location   = preg_replace('/,\s*(United Kingdom|UK|England|Scotland|Wales|Northern Ireland)\s*$/i', '', $locationField);
            $commitment = $opp['other_details'] ?? null;

            // Match by externalid OR contacturl (handles migration from old format).
            $existing = DB::table('volunteering')
                ->where('contacturl', $url)
                ->orWhere('externalid', $externalId)
                ->first();

            if ($existing) {
                if ($dryRun) {
                    $updated++;
                    continue;
                }

                DB::table('volunteering')->where('id', $existing->id)->update([
                    'title'         => $title,
                    'location'      => $location,
                    'description'   => $desc,
                    'contacturl'    => $url,
                    'externalid'    => $externalId,
                    'timecommitment' => $commitment,
                ]);

                $updated++;
            } else {
                if ($dryRun) {
                    $added++;
                    continue;
                }

                $vid = DB::table('volunteering')->insertGetId([
                    'title'         => $title,
                    'location'      => $location,
                    'description'   => $desc,
                    'contacturl'    => $url,
                    'timecommitment' => $commitment,
                    'externalid'    => $externalId,
                    'pending'       => false,
                    'online'        => false,
                    'deleted'       => 0,
                    'added'         => now(),
                ]);

                DB::table('volunteering_groups')->insertOrIgnore([
                    'volunteeringid' => $vid,
                    'groupid'        => $groupIds[0],
                    'arrival'        => now(),
                ]);

                $added++;
            }
        }

        // Mark stale records as deleted. Only look at rows that aren't already
        // deleted or expired — V1's reachvolunteering.php scanned every
        // reach-% row including the 9k+ already-deleted ones, producing huge
        // no-op UPDATE batches and a misleading "deleted: N" count. With
        // this filter the count reflects real new deletes only and the
        // command does proportional work to the live set.
        $existings = DB::table('volunteering')
            ->where('externalid', 'LIKE', 'reach-%')
            ->where('deleted', 0)
            ->where('expired', 0)
            ->get(['id', 'externalid', 'contacturl']);

        foreach ($existings as $e) {
            $seenByExternalId = array_key_exists($e->externalid, $externalsSeen);
            $seenByUrl        = $e->contacturl && array_key_exists($e->contacturl, $urlsSeen);

            if ($seenByExternalId || $seenByUrl) {
                continue;
            }

            if ($dryRun) {
                $deleted++;
                continue;
            }

            DB::table('volunteering')->where('id', $e->id)->update(['deleted' => 1]);
            $deleted++;
        }

        return ['added' => $added, 'updated' => $updated, 'deleted' => $deleted];
    }
}
