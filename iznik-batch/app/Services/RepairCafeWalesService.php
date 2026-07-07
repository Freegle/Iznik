<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Group;
use ICal\ICal;
use Illuminate\Support\Facades\Log;

class RepairCafeWalesService
{
    private const DAYS_AHEAD = 31;
    private const POSTCODE   = '/([Gg][Ii][Rr] 0[Aa]{2})|((([A-Za-z][0-9]{1,2})|(([A-Za-z][A-Ha-hJ-Yj-y][0-9]{1,2})|(([A-Za-z][0-9][A-Za-z])|([A-Za-z][A-Ha-hJ-Yj-y][0-9][A-Za-z]?))))\s?[0-9][A-Za-z]{2})/mi';

    public function __construct(private CommunityEventService $events) {}

    protected function icalUrl(): string
    {
        return 'https://repaircafewales.org/events/list/?ical=1';
    }

    public function sync(bool $dryRun = false): array
    {
        $added         = 0;
        $updated       = 0;
        $deleted       = 0;
        $now           = date('Y-m-d');
        $rangeEnd      = date('Y-m-d 23:59:59', strtotime('+' . self::DAYS_AHEAD . ' days'));
        $externalsSeen = [];

        try {
            $ical = new ICal(options: [
                'defaultSpan'                 => 2,
                'defaultTimeZone'             => 'UTC',
                'disableCharacterReplacement' => false,
                'filterDaysAfter'             => self::DAYS_AHEAD,
                'filterDaysBefore'            => 0,
                'skipRecurrence'              => false,
            ]);
            $ical->initUrl($this->icalUrl());
        } catch (\Exception $e) {
            Log::error('RepairCafeWales: failed to fetch iCal feed', ['error' => $e->getMessage()]);
            return ['added' => $added, 'updated' => $updated, 'deleted' => $deleted];
        }

        $icalEvents = $ical->eventsFromRange($now . ' 00:00:00', $rangeEnd);

        if (empty($icalEvents)) {
            Log::warning('RepairCafeWales: no events in feed');
        }

        foreach ($icalEvents as $event) {
            $externalId            = $event->uid;
            $externalsSeen[$externalId] = true;

            $title       = $event->summary ?? '';
            $description = $event->description ?? '';
            $location    = $event->location ?? '';
            $url         = $event->url ?? null;

            $start = $ical->iCalDateToDateTime($event->dtstart_array[3])->format('Y-m-d H:i:s');
            $end   = $ical->iCalDateToDateTime($event->dtend_array[3])->format('Y-m-d H:i:s');

            if (!preg_match(self::POSTCODE, $location, $matches)) {
                Log::debug('RepairCafeWales: no postcode in location', ['location' => $location]);
                continue;
            }

            $postcode = strtoupper(trim($matches[0]));
            $locRow = Location::getByName($postcode);

            if (!$locRow) {
                Log::debug('RepairCafeWales: postcode not found', ['postcode' => $postcode]);
                continue;
            }

            $groupIds = Location::groupsNear((float) $locRow->lat, (float) $locRow->lng, 15);

            if (empty($groupIds)) {
                Log::debug('RepairCafeWales: no groups near postcode', ['postcode' => $postcode]);
                continue;
            }

            $freegleGroup = Group::find($groupIds[0]);
            if (!$freegleGroup) {
                continue;
            }

            if (!$freegleGroup->getSetting('communityevents', 1)) {
                Log::debug('RepairCafeWales: communityevents disabled', ['group' => $freegleGroup->nameshort]);
                continue;
            }

            $existing = $this->events->findByExternalId($externalId);

            if ($existing) {
                if ($dryRun) {
                    Log::debug('RepairCafeWales: dry run — would update', ['external_id' => $externalId]);
                    $updated++;
                    continue;
                }

                $pendingFlag = $existing->title !== $title ||
                               $existing->location !== $location ||
                               $existing->description !== $description;

                $updateData = [
                    'title'       => $title,
                    'location'    => $location,
                    'description' => $description,
                    'contacturl'  => $url,
                ];

                if ($pendingFlag && !$existing->pending) {
                    $updateData['pending'] = 1;
                }

                $this->events->updateEvent($existing->id, $updateData);
                $this->events->removeDates($existing->id);
                $this->events->addDate($existing->id, $start, $end);
                $updated++;
            } else {
                if ($dryRun) {
                    Log::debug('RepairCafeWales: dry run — would create', ['external_id' => $externalId]);
                    $added++;
                    continue;
                }

                $eid = $this->events->createEvent(
                    null,
                    $title,
                    $location,
                    null,
                    null,
                    null,
                    $url,
                    $description,
                    $externalId
                );

                $this->events->addGroup($eid, $groupIds[0]);
                $this->events->addDate($eid, $start, $end);
                $added++;
            }
        }

        $existings = $this->events->getUpcomingByExternalIdSubstring('repaircafewales', $now);
        foreach ($existings as $e) {
            if (!array_key_exists($e->externalid, $externalsSeen)) {
                if ($dryRun) {
                    Log::debug('RepairCafeWales: dry run — would delete', ['external_id' => $e->externalid]);
                    $deleted++;
                    continue;
                }
                $this->events->markDeleted($e->id);
                $deleted++;
            }
        }

        return ['added' => $added, 'updated' => $updated, 'deleted' => $deleted];
    }
}
