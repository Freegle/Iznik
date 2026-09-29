<?php

namespace App\Services\Lockdown;

use App\Models\ChatRoom;
use App\Models\MessageGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records what an active lockdown is holding, and releases held ChitChat posts once ChitChat
 * is lifted (plan 2026-09-27-lockdown-switch.md, section 11.11).
 *
 * A lockdown_holds row is written for each User2User chat message and each post held since
 * the press, so Support can count and browse them and each one is released once. The Go API
 * writes the ChitChat rows itself when the post is made. Nothing here judges whether a held
 * item is spam: whoever is behind a wave is found and dealt with in Support tools, and on
 * release every item goes through the checks that would have run on the day.
 */
class LockdownHoldsService
{
    public const KIND_CHAT = 'chat';

    public const KIND_POST = 'post';

    public const KIND_CHITCHAT = 'chitchat';

    /**
     * Held items are released in batches of this size, one after another with no pause, until
     * none remain. It bounds the size of each query, not the pace of the release.
     */
    public const RELEASE_BATCH_SIZE = 300;

    public function __construct(private readonly ?LockdownService $lockdown = null)
    {
    }

    /**
     * Create lockdown_holds rows for the active incident: User2User chat messages left
     * unprocessed since it started, and posts left Pending since it started, excluding
     * moderators, Support and Admin as senders and posters. Safe to call repeatedly;
     * existing holds (kind, refid) are left untouched.
     */
    public function createHolds(): array
    {
        $lockdown = $this->lockdown ?? app(LockdownService::class);
        if (!$lockdown->active()) {
            return ['chat' => 0, 'post' => 0];
        }

        $row = $lockdown->current();
        $incidentId = (int) $row->incidentid;
        $startedAt = Carbon::parse($row->startedat);

        return [
            'chat' => $this->createChatHolds($incidentId, $startedAt),
            'post' => $this->createPostHolds($incidentId, $startedAt),
        ];
    }

    /**
     * Once ChitChat is no longer held, unhide every ChitChat post the lockdown hid, oldest
     * first, stopping at once if ChitChat is held again part way through. A post whose
     * sender Support has marked as a spammer (or is waiting to be) stays hidden, as chat
     * and posts from them are refused by their own checks on release.
     */
    public function releaseChitChatHolds(): int
    {
        $lockdown = $this->lockdown ?? app(LockdownService::class);
        $released = 0;

        while (!$lockdown->held(self::KIND_CHITCHAT)) {
            $batch = DB::table('lockdown_holds')
                ->where('kind', self::KIND_CHITCHAT)
                ->whereNull('outcome')
                ->orderBy('id')
                ->limit(self::RELEASE_BATCH_SIZE)
                ->get();
            if ($batch->isEmpty()) {
                break;
            }

            $spammers = DB::table('spam_users')
                ->whereIn('userid', $batch->pluck('userid')->filter()->unique())
                ->whereIn('collection', ['Spammer', 'PendingAdd'])
                ->pluck('userid')
                ->flip();

            foreach ($batch as $hold) {
                if ($hold->userid !== null && $spammers->has($hold->userid)) {
                    DB::table('lockdown_holds')->where('id', $hold->id)->update([
                        'outcome' => 'rejected',
                        'releasedat' => now(),
                    ]);
                    continue;
                }

                DB::table('newsfeed')->where('id', $hold->refid)->update([
                    'hidden' => null,
                    'hiddenby' => null,
                ]);
                DB::table('lockdown_holds')->where('id', $hold->id)->update([
                    'outcome' => 'released',
                    'releasedat' => now(),
                ]);
                $released++;
            }
        }

        return $released;
    }

    /**
     * Close every open hold whose item is no longer waiting: a chat message deleted or
     * already processed, a post withdrawn or no longer pending anywhere (a moderator may
     * still approve it during a lockdown), a ChitChat post deleted or no longer hidden.
     * These are marked 'gone', so they stop counting as held and are not listed. Runs
     * whether or not anything is held, so nothing is left open after a close.
     */
    public function closeGoneHolds(): int
    {
        $gone = ['outcome' => 'gone', 'releasedat' => now()];

        $closed = DB::table('lockdown_holds')
            ->where('kind', self::KIND_CHAT)
            ->whereNull('outcome')
            ->whereNotExists(function ($q) {
                $q->from('chat_messages')
                    ->whereColumn('chat_messages.id', 'lockdown_holds.refid')
                    ->where('chat_messages.processingrequired', 1);
            })
            ->update($gone);

        $closed += DB::table('lockdown_holds')
            ->where('kind', self::KIND_POST)
            ->whereNull('outcome')
            ->whereNotExists(function ($q) {
                $q->from('messages_groups')
                    ->whereColumn('messages_groups.msgid', 'lockdown_holds.refid')
                    ->where('messages_groups.collection', MessageGroup::COLLECTION_PENDING)
                    ->where('messages_groups.deleted', 0);
            })
            ->update($gone);

        $closed += DB::table('lockdown_holds')
            ->where('kind', self::KIND_CHITCHAT)
            ->whereNull('outcome')
            ->whereNotExists(function ($q) {
                $q->from('newsfeed')
                    ->whereColumn('newsfeed.id', 'lockdown_holds.refid')
                    ->whereNotNull('newsfeed.hidden');
            })
            ->update($gone);

        return $closed;
    }

    private function createChatHolds(int $incidentId, Carbon $startedAt): int
    {
        $rows = DB::table('chat_messages')
            ->join('chat_rooms', 'chat_messages.chatid', '=', 'chat_rooms.id')
            ->join('users', 'chat_messages.userid', '=', 'users.id')
            ->where('chat_rooms.chattype', ChatRoom::TYPE_USER2USER)
            ->where('chat_messages.processingrequired', 1)
            ->where('chat_messages.date', '>=', $startedAt)
            ->whereNotIn('users.systemrole', [User::SYSTEMROLE_MODERATOR, User::SYSTEMROLE_SUPPORT, User::SYSTEMROLE_ADMIN])
            ->select('chat_messages.id as id', 'chat_messages.userid as userid')
            ->get();

        return $this->insertHolds(self::KIND_CHAT, $incidentId, $rows);
    }

    private function createPostHolds(int $incidentId, Carbon $startedAt): int
    {
        $rows = DB::table('messages_groups')
            ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
            ->join('users', 'messages.fromuser', '=', 'users.id')
            ->where('messages_groups.collection', MessageGroup::COLLECTION_PENDING)
            ->where('messages_groups.deleted', 0)
            ->where('messages_groups.arrival', '>=', $startedAt)
            ->whereNotIn('users.systemrole', [User::SYSTEMROLE_MODERATOR, User::SYSTEMROLE_SUPPORT, User::SYSTEMROLE_ADMIN])
            ->select('messages_groups.msgid as id', 'messages.fromuser as userid')
            ->distinct()
            ->get();

        return $this->insertHolds(self::KIND_POST, $incidentId, $rows);
    }

    private function insertHolds(string $kind, int $incidentId, $rows): int
    {
        $created = 0;
        foreach ($rows as $row) {
            $created += DB::table('lockdown_holds')->insertOrIgnore([
                'lockdownid' => $incidentId,
                'kind' => $kind,
                'refid' => $row->id,
                'userid' => $row->userid,
                'created' => now(),
            ]);
        }

        return $created;
    }
}
