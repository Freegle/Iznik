<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VisualiseService
{
    private const MAX_DISTANCE_METRES = 30000;

    public function scan(string $ago = '30 minutes ago', bool $dryRun = false): int
    {
        $since = date('Y-m-d H:i:s', strtotime($ago));

        $rows = DB::select(
            "SELECT DISTINCT messages.id, a.id AS attid, messages.fromuser, messages_by.userid AS touser,
                    messages_by.timestamp
             FROM messages
             INNER JOIN messages_by ON messages.id = messages_by.msgid
             INNER JOIN messages_attachments a ON messages.id = a.msgid
             WHERE messages_by.timestamp > ? AND messages.type = ? AND messages_by.userid IS NOT NULL
             ORDER BY messages_by.timestamp DESC",
            [$since, Message::TYPE_OFFER]
        );

        $count = 0;

        foreach ($rows as $row) {
            $fromUser = User::find($row->fromuser);
            $toUser   = User::find($row->touser);

            if (!$fromUser || !$toUser) {
                continue;
            }

            if (!$this->allowsProfile($fromUser) || !$this->allowsProfile($toUser)) {
                continue;
            }

            [$flat, $flng] = $fromUser->getLatLng();
            [$tlat, $tlng] = $toUser->getLatLng();

            if (!$flat || !$flng || !$tlat || !$tlng) {
                continue;
            }

            $metres = $this->distanceMetres($flat, $flng, $tlat, $tlng);

            if ($metres >= self::MAX_DISTANCE_METRES) {
                continue;
            }

            if ($dryRun) {
                Log::debug('Visualise: dry run — would insert', [
                    'msgid' => $row->id,
                    'from' => $row->fromuser,
                    'to' => $row->touser,
                    'metres' => $metres,
                ]);
                $count++;
                continue;
            }

            DB::statement(
                "INSERT IGNORE INTO visualise (msgid, attid, timestamp, fromuser, touser, fromlat, fromlng, tolat, tolng, distance)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$row->id, $row->attid, $row->timestamp, $row->fromuser, $row->touser, $flat, $flng, $tlat, $tlng, $metres]
            );

            $count++;
        }

        return $count;
    }

    private function allowsProfile(User $user): bool
    {
        $settings = $user->settings;
        if (is_array($settings) && array_key_exists('useprofile', $settings)) {
            return (bool) $settings['useprofile'];
        }
        return true;
    }

    private function distanceMetres(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return (int) round($earthRadius * 2 * asin(sqrt($a)));
    }
}
