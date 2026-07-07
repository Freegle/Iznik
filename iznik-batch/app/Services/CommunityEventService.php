<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CommunityEventService
{
    public function findByExternalId(string $externalId): ?object
    {
        return DB::table('communityevents')->where('externalid', $externalId)->first();
    }

    public function createEvent(
        ?int $userId,
        string $title,
        string $location,
        ?string $contactName,
        ?string $contactPhone,
        ?string $contactEmail,
        ?string $contactUrl,
        ?string $description,
        ?string $externalId = null
    ): int {
        DB::table('communityevents')->insert([
            'userid'       => $userId,
            'pending'      => 1,
            'title'        => $title,
            'location'     => $location,
            'contactname'  => $contactName,
            'contactphone' => $contactPhone,
            'contactemail' => $contactEmail,
            'contacturl'   => $contactUrl,
            'description'  => $description,
            'externalid'   => $externalId,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    public function updateEvent(int $id, array $attributes): void
    {
        DB::table('communityevents')->where('id', $id)->update($attributes);
    }

    public function addDate(int $eventId, string $start, string $end): void
    {
        if (strtotime($end) < strtotime($start)) {
            $end = date('Y-m-d H:i:s', strtotime($start) + 3600);
        }

        DB::table('communityevents_dates')->insert([
            'eventid' => $eventId,
            'start'   => $start,
            'end'     => $end,
        ]);
    }

    public function removeDates(int $eventId): void
    {
        DB::table('communityevents_dates')->where('eventid', $eventId)->delete();
    }

    public function addGroup(int $eventId, int $groupId): void
    {
        DB::table('communityevents_groups')->insertOrIgnore([
            'eventid' => $eventId,
            'groupid' => $groupId,
        ]);
        // V1 also creates a Newsfeed entry and pushes notifications to group mods here,
        // but events are pending=1 so they're not visible to users until a mod approves them.
        // Those side effects are omitted; the mod will see the event in their pending queue.
    }

    public function markDeleted(int $eventId): void
    {
        DB::table('communityevents')->where('id', $eventId)->update(['deleted' => 1]);
    }

    public function getUpcomingByExternalIdPrefix(string $prefix, string $fromDate): array
    {
        return DB::table('communityevents')
            ->join('communityevents_dates', 'communityevents.id', '=', 'communityevents_dates.eventid')
            ->where('communityevents.externalid', 'LIKE', $prefix . '%')
            ->where('communityevents_dates.start', '>=', $fromDate)
            ->select('communityevents.id', 'communityevents.externalid')
            ->get()
            ->toArray();
    }

    public function getUpcomingByExternalIdSubstring(string $substring, string $fromDate): array
    {
        return DB::table('communityevents')
            ->join('communityevents_dates', 'communityevents.id', '=', 'communityevents_dates.eventid')
            ->where('communityevents.externalid', 'LIKE', '%' . $substring . '%')
            ->where('communityevents_dates.start', '>=', $fromDate)
            ->select('communityevents.id', 'communityevents.externalid')
            ->get()
            ->toArray();
    }
}
