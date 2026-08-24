<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GroupMaintenanceService
{
    /**
     * Update member and moderator counts for all groups.
     *
     * Migrated from the legacy V1 PHP membercounts cron script.
     */
    public function updateMemberCounts(bool $dryRun = false): array
    {
        $stats = [
            'groups_updated' => 0,
        ];

        // Two grouped passes over memberships rather than a pair of counts for every
        // group in turn. With ~507 groups that was 1,014 separate range scans of a
        // 4.96M-row table every hour; this is two scans the groupid index walks once
        // each.
        //
        // keep-raw: the query builder has no way to put an aggregate column beside a
        // grouped column - selectRaw/DB::raw is the only way to express COUNT(*) here.
        $memberCounts = DB::table('memberships')
            ->groupBy('groupid')
            ->selectRaw('groupid, COUNT(*) AS cnt')
            ->pluck('cnt', 'groupid');

        // keep-raw: same aggregate-beside-grouped-column limitation.
        $modCounts = DB::table('memberships')
            ->whereIn('role', ['Owner', 'Moderator'])
            ->groupBy('groupid')
            ->selectRaw('groupid, COUNT(*) AS cnt')
            ->pluck('cnt', 'groupid');

        $groups = DB::table('groups')->select('id', 'membercount', 'modcount')->get();

        foreach ($groups as $group) {
            // A group with no members at all appears in neither result.
            $memberCount = (int) ($memberCounts[$group->id] ?? 0);
            $modCount = (int) ($modCounts[$group->id] ?? 0);

            // Only write where the answer changed. Most groups neither gain nor lose a
            // member in a given hour, and every write here is copied to all three
            // database nodes.
            if ((int) $group->membercount === $memberCount && (int) $group->modcount === $modCount) {
                continue;
            }

            if (!$dryRun) {
                DB::table('groups')
                    ->where('id', $group->id)
                    ->update([
                        'membercount' => $memberCount,
                        'modcount' => $modCount,
                    ]);
            }

            $stats['groups_updated']++;
        }

        return $stats;
    }

    /**
     * Fix locations where latitude and longitude are swapped.
     *
     * UK locations should have lat > lng (lat ~50-60, lng ~-8 to +2).
     * When lat < lng, the coordinates are likely swapped.
     * Excludes BF (British Forces) postcodes which may legitimately have lat > lng.
     *
     * Also fixes any messages referencing the affected locations.
     * Sends an alert email if any skewed locations are found.
     *
     * Migrated from the legacy V1 PHP locations_skewwhiff cron script.
     */
    public function fixSkewedLocations(bool $dryRun = false): array
    {
        $stats = [
            'locations_fixed' => 0,
            'messages_fixed' => 0,
        ];

        $locations = DB::select(
            "SELECT DISTINCT locations.id, lat, lng, name FROM locations WHERE lat < lng AND locations.name NOT LIKE 'BF%'"
        );

        $details = '';

        foreach ($locations as $location) {
            $details .= "{$location->id}, {$location->name}, {$location->lat}, {$location->lng}\n";

            if (!$dryRun) {
                // Swap lat and lng.
                DB::table('locations')
                    ->where('id', $location->id)
                    ->update([
                        'lat' => $location->lng,
                        'lng' => $location->lat,
                    ]);
            }

            $stats['locations_fixed']++;

            // Fix messages referencing this location.
            $messages = DB::table('messages')
                ->where('locationid', $location->id)
                ->select('id')
                ->get();

            foreach ($messages as $msg) {
                if (!$dryRun) {
                    DB::table('messages')
                        ->where('id', $msg->id)
                        ->update([
                            'lat' => $location->lng,
                            'lng' => $location->lat,
                        ]);
                }

                $details .= "...fix message {$msg->id}\n";
                $stats['messages_fixed']++;
            }
        }

        if ($stats['locations_fixed'] > 0) {
            Log::warning("Fixed {$stats['locations_fixed']} skewed locations", $stats);

            if (!$dryRun) {
                Mail::raw(
                    $details,
                    function ($message) use ($stats) {
                        $message->to(config('freegle.alerts.geek_email', 'geek-alerts@ilovefreegle.org'))
                            ->from('geeks@ilovefreegle.org')
                            ->subject("{$stats['locations_fixed']} locations lat/lngs skewwhiff");
                    }
                );
            }
        } else {
            Log::info('All locations ok');
        }

        return $stats;
    }
}
