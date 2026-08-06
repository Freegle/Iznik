<?php

namespace App\Services;

use App\Mail\Group\BoundaryErrorMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GroupBoundaryService
{
    public function checkBoundaries(bool $dryRun = false): array
    {
        $srid = config('freegle.srid', 3857);

        $groups = DB::table('groups')
            ->select('id', 'nameshort', 'poly')
            ->where('type', 'Freegle')
            ->where('publish', 1)
            ->where('onmap', 1)
            ->get()
            ->all();

        $total = count($groups);
        $errors = 0;

        if ($dryRun) {
            Log::info('Dry run: would check boundaries for groups', ['total' => $total]);
            return ['total' => $total, 'errors' => 0];
        }

        foreach ($groups as $group) {
            try {
                // keep-raw: ST_Intersection/ST_GeomFromText/COALESCE() are spatial
                // and conditional functions with no builder method; this is a
                // validity probe (result unused, an exception means the boundary
                // is invalid).
                DB::select(
                    "SELECT ST_Intersection(ST_GeomFromText(polyofficial, ?), COALESCE(simplified, polygon))
                     FROM `groups`
                     INNER JOIN `authorities` ON type = 'Freegle' AND publish = 1 AND onmap = 1
                     WHERE authorities.id = 74579 AND groups.id = ?",
                    [$srid, $group->id]
                );

                if ($group->poly) {
                    DB::select(
                        "SELECT ST_Intersection(ST_GeomFromText(poly, ?), COALESCE(simplified, polygon))
                         FROM `groups`
                         INNER JOIN `authorities` ON type = 'Freegle' AND publish = 1 AND onmap = 1
                         WHERE authorities.id = 74579 AND groups.id = ?",
                        [$srid, $group->id]
                    );
                }
            } catch (\Throwable $e) {
                Log::error("Invalid CGA/DPA boundary for group {$group->id} {$group->nameshort}", [
                    'group_id'   => $group->id,
                    'nameshort'  => $group->nameshort,
                    'error'      => $e->getMessage(),
                ]);

                // V1 mailed GEEKS_ADDR on boundary errors — preserve that notification.
                app(\App\Services\EmailSpoolerService::class)->spool(new BoundaryErrorMail($group->id, $group->nameshort, $e->getMessage()));

                $errors++;
            }
        }

        return ['total' => $total, 'errors' => $errors];
    }
}
