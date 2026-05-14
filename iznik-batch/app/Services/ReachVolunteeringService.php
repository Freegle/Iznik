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

    public function sync(bool $dryRun = false): array
    {
        $added   = 0;
        $updated = 0;
        $deleted = 0;

        $feedUrl = config('freegle.reach_volunteering.feed_url');
        $body    = $this->fetchFeedData($feedUrl);

        $opps = json_decode($body, true, 512, JSON_INVALID_UTF8_IGNORE);

        if (!is_array($opps)) {
            throw new \RuntimeException('Reach Volunteering feed: expected JSON array');
        }

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

        // Mark stale records as deleted.
        $existings = DB::table('volunteering')
            ->where('externalid', 'LIKE', 'reach-%')
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
