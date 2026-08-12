<?php

namespace App\Services;

use App\Support\GreatCircle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Maintains users_approxlocs — the privacy-blurred point cloud of recently-active members.
 *
 * Ported from V1 Nearby::updateLocations() (iznik-server/include/user/Nearby.php), which was
 * deleted with the rest of V1 in c14a7125b without a Laravel equivalent, leaving the table with
 * readers but no writer. Its readers are:
 *
 *   - iznik-routing-go/reachable_groups.go — the table is the driving side of the STRAIGHT_JOIN
 *     that decides which groups a ripple can reach, so a member absent here is invisible to reach
 *   - iznik-spatial-go/dataset_userapproxlocs.go — point dataset served by the spatial API
 *   - iznik-routing-go/cmd/rippleextract — ripple simulator extract
 *   - iznik-server-go/userdump — GDPR user dump
 *
 * Each row is a member's location blurred by ~400m, so nothing here is precise enough to identify
 * a household; the readers treat it as an approximate point cloud, not an address book.
 */
class UserApproxLocService
{
    /**
     * V1 Engage::USER_INACTIVE — half a year in seconds. A member who has not accessed the site
     * within this window is "inactive" and drops out of the point cloud.
     */
    public const USER_INACTIVE_SECONDS = 365 * 24 * 60 * 60 / 2;

    /** V1 Utils::BLUR_USER — metres to blur a member's location by. */
    public const BLUR_USER = 400;

    /** Members read per SELECT. */
    private const READ_CHUNK = 2000;

    /** Rows per bulk upsert statement. */
    private const WRITE_CHUNK = 500;

    /** Rows per DELETE when pruning, so a big catch-up prune can't stall Galera replication. */
    private const PRUNE_CHUNK = 5000;

    /**
     * The inactivity cutoff, V1-style: date-granular, ~181.5 days ago
     * (now - USER_INACTIVE + 1 day, formatted as a date). Members whose lastaccess predates it
     * are not written, and rows whose timestamp predates it are pruned.
     */
    public function cutoff(): string
    {
        return Carbon::now()
            ->subSeconds((int) self::USER_INACTIVE_SECONDS)
            ->addDay()
            ->format('Y-m-d');
    }

    /**
     * Refresh the point cloud: upsert a blurred point for every recently-active member whose
     * location we can resolve, then prune rows that have aged out.
     *
     * @param  bool  $dryRun  Count what would change without writing.
     * @param  int|null  $limit  Stop after considering this many members (for a bounded manual run).
     * @return array{considered:int,upserted:int,no_location:int,pruned:int}
     */
    public function updateLocations(bool $dryRun = false, ?int $limit = null): array
    {
        $cutoff = $this->cutoff();
        $stats = ['considered' => 0, 'upserted' => 0, 'no_location' => 0, 'pruned' => 0];

        $lastId = 0;

        while (true) {
            $take = self::READ_CHUNK;

            if ($limit !== null) {
                $remaining = $limit - $stats['considered'];

                if ($remaining <= 0) {
                    break;
                }

                $take = min($take, $remaining);
            }

            $members = $this->readMembers($cutoff, $lastId, $take);

            if ($members->isEmpty()) {
                break;
            }

            $pending = [];

            foreach ($members as $member) {
                $stats['considered']++;
                $lastId = (int) $member->id;

                $point = $this->resolvePoint($member);

                if ($point === null) {
                    $stats['no_location']++;

                    continue;
                }

                // V1's `if ($lat || $lng)` guard. 0,0 is missing data, not a place in the
                // Atlantic — 1,629 locations on live sit there. Note it takes BOTH coordinates
                // being falsy: the Greenwich meridian runs through east London, so lng exactly
                // 0 alongside a real lat is a genuine location.
                if (!$point[0] && !$point[1]) {
                    $stats['no_location']++;

                    continue;
                }

                [$lat, $lng] = $this->blur($point[0], $point[1]);
                $pending[] = [(int) $member->id, $lat, $lng, $member->lastaccess];
                $stats['upserted']++;
            }

            if (!$dryRun) {
                foreach (array_chunk($pending, self::WRITE_CHUNK) as $chunk) {
                    $this->upsert($chunk);
                }
            }
        }

        if (!$dryRun) {
            $stats['pruned'] = $this->prune($cutoff);
        }

        Log::info('users_approxlocs refreshed', $stats + ['cutoff' => $cutoff, 'dry_run' => $dryRun]);

        return $stats;
    }

    /**
     * One page of members to consider: anyone with a membership whose lastaccess is inside the
     * cutoff, walked by ascending id so a long run pages without OFFSET.
     *
     * V1 used `INNER JOIN memberships ... DISTINCT`; whereExists is the same set without the join
     * fan-out. Like V1 this deliberately does not filter on membership collection or on
     * users.deleted — the readers apply their own filters (reachable_groups.go, for instance,
     * requires collection='Approved' and lastaccess within 90 days).
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function readMembers(string $cutoff, int $afterId, int $take): \Illuminate\Support\Collection
    {
        return DB::table('users as u')
            ->leftJoin('locations as l', 'l.id', '=', 'u.lastlocation')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('memberships as m')
                    ->whereColumn('m.userid', 'u.id');
            })
            ->where('u.id', '>', $afterId)
            ->where('u.lastaccess', '>=', $cutoff)
            ->orderBy('u.id')
            ->limit($take)
            ->select('u.id', 'u.lastaccess', 'l.lat as loc_lat', 'l.lng as loc_lng')
            // keep-raw: JSON path extraction, which the builder has no expression for. Read as raw
            // JSON text rather than CAST to DECIMAL because a JSON null casts to 0.000000, not
            // NULL, which would silently place the member in the Atlantic.
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(u.settings, '$.mylocation.lat')) AS myloc_lat")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(u.settings, '$.mylocation.lng')) AS myloc_lng")
            ->get();
    }

    /**
     * A member's raw point, resolved the way V1 getLatLng(usedef=FALSE, usegroup=FALSE) did:
     * settings.mylocation first, else their lastlocation, else nothing. There is deliberately no
     * fallback to their last posted message, their group's centre, or the centre of Britain —
     * a member whose location we don't know stays out of the cloud rather than being planted
     * somewhere they aren't.
     *
     * V1 accepted mylocation when only its lat was set, then tried to insert a NULL lng into a
     * NOT NULL column; here both coordinates must be present for mylocation to win, matching
     * UnifiedDigestService::resolveUserLatLng.
     *
     * @return array{0:float,1:float}|null
     */
    private function resolvePoint(object $member): ?array
    {
        if (is_numeric($member->myloc_lat) && is_numeric($member->myloc_lng)) {
            return [(float) $member->myloc_lat, (float) $member->myloc_lng];
        }

        if ($member->loc_lat !== null && $member->loc_lng !== null) {
            return [(float) $member->loc_lat, (float) $member->loc_lng];
        }

        return null;
    }

    /**
     * Blur a point by BLUR_USER metres, V1 Utils::blur: the direction is derived from the
     * coordinates themselves so it is deterministic (the point must not jitter between runs,
     * or members would appear to move around the map), and the result is rounded to 4 dp.
     *
     * The same algorithm lives in Go as utils.Blur and in ExpandService::blurOrigin for the
     * poster's origin; all three must agree, so change them together.
     *
     * @return array{0:float,1:float}
     */
    private function blur(float $lat, float $lng): array
    {
        $dir = ($lat * 1000 + $lng * 1000) % 360;
        $pos = GreatCircle::getPositionByDistance(self::BLUR_USER, $dir, $lat, $lng);

        return [round($pos['lat'], 4), round($pos['lng'], 4)];
    }

    /**
     * Bulk upsert.
     *
     * timestamp is set explicitly to the member's lastaccess (V1 parity): the column is
     * ON UPDATE CURRENT_TIMESTAMP, so leaving it out would stamp it with the run time and the
     * prune below could then never age anybody out.
     *
     * position is SRID 3857 in POINT(lng lat) order — the axis order reachable_groups.go and the
     * spatial dataset read it back in.
     *
     * @param  list<array{0:int,1:float,2:float,3:string}>  $rows
     */
    private function upsert(array $rows): void
    {
        $srid = (int) config('freegle.srid', 3857);

        $placeholders = implode(', ', array_fill(0, count($rows), '(?, ?, ?, ST_SRID(POINT(?, ?), ?), ?)'));

        $bindings = [];

        foreach ($rows as [$userid, $lat, $lng, $lastaccess]) {
            array_push($bindings, $userid, $lat, $lng, $lng, $lat, $srid, $lastaccess);
        }

        // keep-raw: the builder's upsert() binds column values only, so it cannot put the
        // ST_SRID(POINT(...)) spatial constructor in the VALUES list, and the SPATIAL index on
        // position means the geometry has to be built in the same statement.
        DB::statement(
            "INSERT INTO users_approxlocs (userid, lat, lng, position, timestamp)
             VALUES $placeholders AS new
             ON DUPLICATE KEY UPDATE
               lat = new.lat,
               lng = new.lng,
               position = new.position,
               timestamp = new.timestamp",
            $bindings
        );
    }

    /**
     * Drop members who have gone inactive. Chunked: after a long gap in the schedule the cutoff
     * jumps forward and this can be a large delete, which as a single statement would hold a long
     * transaction and risk Galera flow control on the live cluster.
     */
    private function prune(string $cutoff): int
    {
        $pruned = 0;

        do {
            $deleted = DB::table('users_approxlocs')
                ->where('timestamp', '<', $cutoff)
                ->limit(self::PRUNE_CHUNK)
                ->delete();
            $pruned += $deleted;
        } while ($deleted === self::PRUNE_CHUNK);

        return $pruned;
    }
}
