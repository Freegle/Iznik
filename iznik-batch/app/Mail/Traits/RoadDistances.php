<?php

namespace App\Mail\Traits;

use App\Services\Ripple\ReachService;

/**
 * Road-mile distances for email cards, matching the road miles the site shows
 * instead of crow-flies. One batched routing call per email covers every card;
 * any post the engine cannot answer keeps the caller's crow-flies text.
 */
trait RoadDistances
{
    /** @var array<int, float> msgid => road miles */
    protected array $roadMilesByMsgid = [];

    /**
     * One routing call for all of this email's posts. $messages is iterable of
     * objects with id/lat/lng (real coordinates - only a DISTANCE leaves here,
     * exactly as the existing crow-flies calculation).
     */
    protected function fillRoadMiles(?float $userLat, ?float $userLng, iterable $messages): void
    {
        if ($userLat === null || $userLng === null) {
            return;
        }
        $targets = [];
        foreach ($messages as $m) {
            if ($m && $m->lat && $m->lng) {
                $targets[(int) $m->id] = [(float) $m->lat, (float) $m->lng];
            }
        }
        $this->roadMilesByMsgid = app(ReachService::class)->driveMetrics($userLat, $userLng, $targets);
    }

    /** "about N miles by road" when the engine answered for this post, else null. */
    protected function roadDistanceText(int $msgid): ?string
    {
        $miles = $this->roadMilesByMsgid[$msgid] ?? null;
        if ($miles === null) {
            return null;
        }

        return $miles < 1 ? '< 1 mile by road' : 'about ' . round($miles) . ' miles by road';
    }
}
