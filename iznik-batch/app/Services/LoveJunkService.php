<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LoveJunkService
{
    private string $apiUrl;
    private string $secret;

    public function __construct()
    {
        $this->apiUrl = config('freegle.lovejunk.api', '');
        $this->secret = config('freegle.lovejunk.secret', '');
    }

    public function sync(bool $dryRun = false): array
    {
        $result = ['sent' => 0, 'edited' => 0, 'completed_or_deleted' => 0, 'failed' => 0];
        $since = now()->subDay()->toDateString();

        $edited = DB::select("
            SELECT DISTINCT messages.id, lovejunk.status
            FROM messages
            INNER JOIN lovejunk ON lovejunk.msgid = messages.id
            INNER JOIN messages_groups ON messages_groups.msgid = messages.id
            INNER JOIN messages_edits ON messages_edits.msgid = messages.id
            INNER JOIN `groups` ON groups.id = messages_groups.groupid
            WHERE messages.arrival >= ?
              AND messages_edits.timestamp > lovejunk.timestamp
              AND messages.type = 'Offer'
              AND messages_groups.collection = 'Approved'
              AND groups.onlovejunk = 1
            ORDER BY messages.arrival ASC
        ", [$since]);

        foreach ($edited as $msg) {
            $lj = json_decode($msg->status, true);
            if ($lj && isset($lj['draftId'])) {
                if (!$dryRun) {
                    $this->editMessage($msg->id, $lj['draftId']);
                }
                $result['edited']++;
            }
        }

        $newMsgs = DB::select("
            SELECT messages.id
            FROM messages
            LEFT JOIN lovejunk ON lovejunk.msgid = messages.id
            INNER JOIN messages_groups ON messages_groups.msgid = messages.id
            INNER JOIN `groups` ON groups.id = messages_groups.groupid
            WHERE messages.arrival >= ?
              AND messages.type = 'Offer'
              AND lovejunk.msgid IS NULL
              AND messages_groups.collection = 'Approved'
              AND groups.onlovejunk = 1
            ORDER BY messages.arrival ASC
        ", [$since]);

        foreach ($newMsgs as $msg) {
            if ($dryRun) {
                // Don't call LoveJunk API or write to lovejunk table.
                $result['sent']++;
                continue;
            }

            if ($this->sendMessage($msg->id)) {
                $result['sent']++;
            } else {
                $result['failed']++;
            }
        }

        $withOutcomes = DB::select("
            SELECT DISTINCT messages.id
            FROM messages_outcomes
            INNER JOIN messages ON messages.id = messages_outcomes.msgid
            INNER JOIN messages_groups ON messages_groups.msgid = messages.id
            INNER JOIN lovejunk ON lovejunk.msgid = messages_outcomes.msgid
            INNER JOIN `groups` ON groups.id = messages_groups.groupid
            WHERE messages_outcomes.timestamp >= ?
              AND messages.type = 'Offer'
              AND lovejunk.success = 1
              AND lovejunk.deleted IS NULL
              AND lovejunk.status LIKE '{%'
              AND groups.onlovejunk = 1
            ORDER BY messages.arrival ASC
        ", [$since]);

        foreach ($withOutcomes as $msg) {
            if (!$dryRun) {
                $this->completeOrDelete($msg->id);
            }
            $result['completed_or_deleted']++;
        }

        return $result;
    }

    private function sendMessage(int $msgId): bool
    {
        $data = $this->buildPayload($msgId);

        if ($data === null) {
            Log::warning("LoveJunk: cannot send message $msgId, missing required data");
            $this->recordResult(false, $msgId, 'Missing required data');
            return false;
        }

        try {
            $response = Http::post($this->buildUrl('/freegle/drafts'), $data);

            if ($response->successful()) {
                $body = $response->json('body', []);
                $this->recordResult(true, $msgId, json_encode($body));
                return true;
            }

            // 409 Conflict means LoveJunk already has this draft — it's an idempotency
            // signal, not a failure worth alerting on. Log at warning level so it stays
            // visible in laravel.log but stops being escalated to Sentry as an error.
            $status = $response->status();
            $logger = $status === 409 ? 'warning' : 'error';
            Log::$logger("LoveJunk: failed to send message $msgId", ['status' => $status]);
            $this->recordResult(false, $msgId, $status . ' ' . $response->body());
        } catch (\Exception $e) {
            Log::error("LoveJunk: exception sending message $msgId: " . $e->getMessage());
            $this->recordResult(false, $msgId, $e->getMessage());
        }

        return false;
    }

    private function editMessage(int $msgId, string $draftId): bool
    {
        $data = $this->buildPayload($msgId) ?? [];

        DB::table('lovejunk')->where('msgid', $msgId)->update(['timestamp' => now()]);

        try {
            $response = Http::put($this->buildUrl('/freegle/drafts/' . $draftId), $data);
            return $response->successful() || $response->status() === 410;
        } catch (\Exception $e) {
            Log::error("LoveJunk: exception editing message $msgId: " . $e->getMessage());
            return false;
        }
    }

    private function completeOrDelete(int $msgId): void
    {
        $fromUser = DB::table('messages')->where('id', $msgId)->value('fromuser');

        $promises = DB::select("
            SELECT mp.userid, u.ljuserid
            FROM messages_promises mp
            INNER JOIN users u ON u.id = mp.userid
            WHERE mp.msgid = ? AND u.ljuserid IS NOT NULL
        ", [$msgId]);

        $completed = false;

        foreach ($promises as $promise) {
            $chatRoom = DB::table('chat_rooms')
                ->whereNotNull('ljofferid')
                ->where(function ($q) use ($fromUser, $promise) {
                    $q->where(function ($inner) use ($fromUser, $promise) {
                        $inner->where('user1', $fromUser)->where('user2', $promise->userid);
                    })->orWhere(function ($inner) use ($fromUser, $promise) {
                        $inner->where('user1', $promise->userid)->where('user2', $fromUser);
                    });
                })
                ->first();

            if ($chatRoom) {
                $this->completeOffer((int) $chatRoom->id, $msgId, (string) $chatRoom->ljofferid);
                $completed = true;
            }
        }

        if (!$completed) {
            $this->deleteOffer($msgId);
        }
    }

    private function completeOffer(int $chatId, int $msgId, string $ljOfferId): void
    {
        try {
            $response = Http::post($this->buildUrl('/freegle/offers/' . $ljOfferId . '/complete'));
            $statusStr = json_encode($response->status() . ' ' . $response->reason());
        } catch (\Exception $e) {
            $statusStr = json_encode($e->getMessage());
        }

        DB::table('lovejunk')
            ->where('msgid', $msgId)
            ->update(['deleted' => now(), 'deletestatus' => $statusStr]);
    }

    private function deleteOffer(int $msgId): void
    {
        $lj = DB::table('lovejunk')->where('msgid', $msgId)->where('success', 1)->first();

        if (!$lj) {
            return;
        }

        $status = json_decode($lj->status, true);

        if (!isset($status['draftId'])) {
            return;
        }

        try {
            $response = Http::delete($this->buildUrl('/freegle/drafts/' . $status['draftId']));
            $statusStr = json_encode($response->status() . ' ' . $response->reason());
        } catch (\Exception $e) {
            $statusStr = json_encode($e->getMessage());
        }

        DB::table('lovejunk')
            ->where('msgid', $msgId)
            ->update(['deleted' => now(), 'deletestatus' => $statusStr]);
    }

    private function buildPayload(int $msgId): ?array
    {
        $msg = DB::selectOne("
            SELECT m.id, m.textbody, m.fromuser, m.locationid, m.lat, m.lng,
                   m.sourceheader, m.subject, m.type,
                   i.name as item,
                   u.fullname, u.firstname, u.lastname,
                   l.name as postcode,
                   la.name as area
            FROM messages m
            LEFT JOIN messages_items mi ON mi.msgid = m.id
            LEFT JOIN items i ON i.id = mi.itemid
            LEFT JOIN users u ON u.id = m.fromuser
            LEFT JOIN locations l ON l.id = m.locationid
            LEFT JOIN locations la ON la.id = l.areaid
            WHERE m.id = ?
            LIMIT 1
        ", [$msgId]);

        if (!$msg) {
            return null;
        }

        $item = $msg->item;

        if (!$item && $msg->subject) {
            if (preg_match('/.*(OFFER|WANTED|TAKEN|RECEIVED) *[:\\-](.*)\\(.*\\)/', $msg->subject, $matches)) {
                $item = trim($matches[2]);
            }
        }

        $postcode = $msg->postcode;

        if (!$postcode || !$item || $msg->type !== 'Offer') {
            return null;
        }

        $lat = $msg->lat !== null ? round((float) $msg->lat, 3) : null;
        $lng = $msg->lng !== null ? round((float) $msg->lng, 3) : null;

        $source = str_starts_with((string) $msg->sourceheader, 'TN-') ? 'trashnothing' : 'freegle';

        $firstName = $msg->fullname ?: $msg->firstname;
        $lastName = $msg->fullname ? ' ' : ($msg->lastname ?? '');

        $images = null;
        $attachments = DB::table('messages_attachments')
            ->where('msgid', $msgId)
            ->whereNotNull('externaluid')
            ->get(['externaluid']);

        if ($attachments->isNotEmpty()) {
            $tusBase = config('freegle.tus_uploader');
            $images = [];
            foreach ($attachments as $att) {
                $uid = $att->externaluid;
                if (str_starts_with($uid, 'freegletusd-')) {
                    $suffix = substr($uid, strlen('freegletusd-'));
                    $images[] = ['url' => $tusBase . '/' . $suffix . '/'];
                }
            }
        }

        return [
            'freegleId' => $msgId,
            'title' => $item,
            'description' => $msg->textbody,
            'source' => $source,
            'userData' => [
                'userId' => $msg->fromuser,
                'firstName' => $firstName,
                'lastName' => $lastName,
            ],
            'locationData' => [
                'postcode' => $postcode,
                'latitude' => $lat,
                'longitude' => $lng,
                'area' => $msg->area,
            ],
            'images' => $images,
        ];
    }

    private function buildUrl(string $path): string
    {
        $url = $this->apiUrl . $path;
        if ($this->secret) {
            $url .= '?secret=' . $this->secret;
        }
        return $url;
    }

    private function recordResult(bool $success, int $msgId, string $status): void
    {
        DB::table('lovejunk')->upsert(
            ['msgid' => $msgId, 'success' => $success ? 1 : 0, 'status' => $status],
            ['msgid'],
            ['success', 'status']
        );
    }
}
