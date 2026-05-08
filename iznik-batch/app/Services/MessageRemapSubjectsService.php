<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageRemapSubjectsService
{
    // V1: Message::EXPIRE_TIME = 90 days
    private const EXPIRE_DAYS = 90;

    public function remapSubjects(bool $dryRun = false): array
    {
        $locationChangeCutoff = now()->subHours(24)->format('Y-m-d H:i:s');
        $earliestMessage = now()->subDays(self::EXPIRE_DAYS)->format('Y-m-d');

        $messages = DB::table('locations')
            ->join('messages', 'messages.locationid', '=', 'locations.id')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->leftJoin('messages_items', 'messages_items.msgid', '=', 'messages.id')
            ->leftJoin('items', 'items.id', '=', 'messages_items.itemid')
            ->leftJoin('locations as areas', 'areas.id', '=', 'locations.areaid')
            ->leftJoin('locations as postcodes', 'postcodes.id', '=', 'locations.postcodeid')
            ->where('locations.timestamp', '>=', $locationChangeCutoff)
            ->where('messages.arrival', '>=', '2021-12-21')
            ->where('messages.arrival', '>=', $earliestMessage)
            ->select(
                'messages.id',
                'messages.subject',
                'messages.type',
                'items.name as item_name',
                'locations.name as location_name',
                'locations.type as location_type',
                'areas.name as area_name',
                'postcodes.name as postcode_name',
            )
            ->get();

        Log::info("MessageRemapSubjects: checking {$messages->count()} messages");

        $changed = 0;
        $changes = [];

        foreach ($messages as $msg) {
            if (!$msg->item_name) {
                continue;
            }

            $newLocation = $this->buildLocation($msg);
            $newSubject = strtoupper($msg->type) . ': ' . $msg->item_name . ' (' . $newLocation . ')';

            // Extract old and new location parts using the same regex as V1.
            if (!preg_match('/.*?:.*\((.*)\)/', $msg->subject, $oldMatches)) {
                continue;
            }
            if (!preg_match('/.*?:.*\((.*)\)/', $newSubject, $newMatches)) {
                continue;
            }

            $oldLoc = $oldMatches[1];
            $newLoc = $newMatches[1];

            if (strcasecmp($oldLoc, $newLoc) !== 0) {
                Log::info("MessageRemapSubjects: message #{$msg->id} {$oldLoc} => {$newLoc}");

                if (!$dryRun) {
                    DB::table('messages')
                        ->where('id', $msg->id)
                        ->update(['subject' => $newSubject]);
                }

                if (count($changes) < 20) {
                    $changes[] = [
                        'id' => $msg->id,
                        'old' => $msg->subject,
                        'new' => $newSubject,
                    ];
                }

                $changed++;
            }
        }

        Log::info("MessageRemapSubjects: " . ($dryRun ? 'would change ' : 'changed ') . "{$changed} of {$messages->count()}");

        return ['changed' => $changed, 'checked' => $messages->count(), 'samples' => $changes];
    }

    private function buildLocation(object $msg): string
    {
        if ($msg->area_name && $msg->postcode_name) {
            // Area + vague postcode district (e.g. "Edinburgh EH4")
            $vaguePostcode = $this->ensureVague($msg->postcode_name);
            return $msg->area_name . ' ' . $vaguePostcode;
        }

        return $this->ensureVague($msg->location_name, $msg->location_type);
    }

    private function ensureVague(string $name, string $type = ''): string
    {
        if ($type === 'Postcode') {
            $space = strpos($name, ' ');
            if ($space !== false) {
                return substr($name, 0, $space);
            }
        }

        return $name;
    }
}
