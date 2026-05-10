<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Location;
use Html2Text\Html2Text;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RestartProjectService
{
    private const API_BASE     = 'https://restarters.net/api/v2';
    private const NEARBY_MILES = 15;
    private const DAYS_AHEAD   = 31;

    public function __construct(private CommunityEventService $events) {}

    public function sync(bool $dryRun = false): array
    {
        $added         = 0;
        $updated       = 0;
        $deleted       = 0;
        $now           = date('Y-m-d');
        $latest        = date('Y-m-d', strtotime('+' . self::DAYS_AHEAD . ' days'));
        $externalsSeen = [];

        $groups = $this->apiGet('/groups/names');

        if ($groups === null) {
            Log::error('Restart: failed to fetch groups list');
            return ['added' => $added, 'updated' => $updated, 'deleted' => $deleted];
        }

        foreach ($groups as $group) {
            if (str_contains($group['name'] ?? '', '[INACTIVE]')) {
                continue;
            }

            $details = $this->apiGet("/groups/{$group['id']}");
            if (!$details) {
                continue;
            }

            if (($details['location']['country_code'] ?? '') !== 'GB') {
                continue;
            }

            $lat = (float) ($details['location']['lat'] ?? 0);
            $lng = (float) ($details['location']['lng'] ?? 0);
            if (!$lat || !$lng) {
                continue;
            }

            $groupIds = Location::groupsNear($lat, $lng, self::NEARBY_MILES);
            if (empty($groupIds)) {
                Log::debug('Restart: no Freegle groups near', ['group' => $group['name'], 'lat' => $lat, 'lng' => $lng]);
                continue;
            }

            $freegleGroup = Group::find($groupIds[0]);
            if (!$freegleGroup) {
                continue;
            }

            if (!$freegleGroup->getSetting('communityevents', 1)) {
                Log::debug('Restart: communityevents disabled', ['freegle_group' => $freegleGroup->nameshort]);
                continue;
            }

            $events = $this->apiGet("/groups/{$group['id']}/events", [
                'start' => $now,
                'end'   => $latest,
            ]);
            if (!$events) {
                continue;
            }

            foreach ($events as $event) {
                if (!($event['approved'] ?? false)) {
                    continue;
                }

                $eventDetails = $this->apiGet("/events/{$event['id']}");
                if (!$eventDetails) {
                    continue;
                }

                $externalId         = "Restart-{$event['id']}";
                $externalsSeen[$externalId] = true;

                $html        = new Html2Text($eventDetails['description'] ?? '');
                $description = $html->getText();

                $title = $event['title'];
                if (!str_contains($title, 'Repair Cafe')) {
                    $title = "Repair Cafe: $title";
                }

                $url      = $eventDetails['link'] ?? ($details['website'] ?? null);
                $email    = $details['email'] ?? null;
                $location = $event['location'] ?? '';

                $existing = $this->events->findByExternalId($externalId);

                if ($existing) {
                    if ($dryRun) {
                        Log::debug('Restart: dry run — would update event', ['external_id' => $externalId]);
                        $updated++;
                        continue;
                    }

                    $pendingFlag = $existing->title !== $title ||
                                  $existing->location !== $location ||
                                  $existing->description !== $description;

                    $updateData = [
                        'title'        => $title,
                        'location'     => $location,
                        'description'  => $description,
                        'contacturl'   => $url,
                        'contactemail' => $email,
                    ];

                    if ($pendingFlag && !$existing->pending) {
                        $updateData['pending'] = 1;
                    }

                    $this->events->updateEvent($existing->id, $updateData);
                    $this->events->removeDates($existing->id);
                    $this->events->addDate($existing->id, $event['start'], $event['end']);
                    $updated++;
                } else {
                    if ($dryRun) {
                        Log::debug('Restart: dry run — would create event', ['external_id' => $externalId]);
                        $added++;
                        continue;
                    }

                    $contactName = $eventDetails['group']['name'] ?? null;

                    $eid = $this->events->createEvent(
                        null,
                        $title,
                        $location,
                        $contactName,
                        null,
                        $email,
                        $url,
                        $description,
                        $externalId
                    );

                    $this->events->addGroup($eid, $groupIds[0]);
                    $this->events->addDate($eid, $event['start'], $event['end']);
                    $added++;
                }
            }
        }

        $existings = $this->events->getUpcomingByExternalIdPrefix('Restart-', $now);
        foreach ($existings as $e) {
            if (!array_key_exists($e->externalid, $externalsSeen)) {
                if ($dryRun) {
                    Log::debug('Restart: dry run — would delete old event', ['external_id' => $e->externalid]);
                    $deleted++;
                    continue;
                }
                $this->events->markDeleted($e->id);
                $deleted++;
            }
        }

        return ['added' => $added, 'updated' => $updated, 'deleted' => $deleted];
    }

    private function apiGet(string $path, array $params = []): ?array
    {
        do {
            $response  = Http::get(self::API_BASE . $path, $params);
            $throttled = str_contains($response->body(), 'Too Many Requests');

            if ($throttled) {
                Log::warning('Restart: API throttled, retrying', ['path' => $path]);
                sleep(5);
            }
        } while ($throttled);

        if (!$response->successful()) {
            Log::error('Restart: API error', ['path' => $path, 'status' => $response->status()]);
            return null;
        }

        $data = $response->json('data');

        if ($data === null) {
            Log::warning('Restart: no data in response', ['path' => $path]);
        }

        return $data;
    }
}
