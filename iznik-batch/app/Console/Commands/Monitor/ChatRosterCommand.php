<?php

namespace App\Console\Commands\Monitor;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Nightly: finds chats with recent messages in which a live participant has no
 * chat_roster row, and raises a Sentry error naming them.
 *
 * The chat mailer only emails people who have a roster row in the room
 * (ChatNotificationService::getMembersToNotify), and marking a chat read only
 * updates an existing row. So a participant without one is never emailed and
 * their read state never sticks, and nothing anywhere reports it. Account merges
 * once moved rooms without their roster rows and left 8,348 rooms like that,
 * found only when a member asked why they had not been emailed.
 *
 * The participants checked are the ones the mailer reads from the roster: both
 * members of a User2User chat and the member of a User2Mod chat. Moderators are
 * added to a User2Mod roster when they are mailed, so a missing moderator row is
 * not a fault.
 */
#[AsCommand(name: 'monitor:chat-roster')]
class ChatRosterCommand extends Command
{
    protected $signature = 'monitor:chat-roster';

    protected $description = 'Raises a Sentry error for chats where a live participant has no roster row, so is never emailed';

    // How many of the missing rows to name in the error.
    private const EXAMPLES = 10;

    public function handle(): int
    {
        $hours = (int) config('freegle.chat_roster_monitor.window_hours', 48);
        $until = now();
        $since = $until->copy()->subHours($hours);

        // Rooms come from recent messages: chat_messages is indexed on date and
        // chat_rooms.latestmessage is not. A message dated in the future is not
        // recent, so the window ends now. Read from the write node, which has
        // every roster row the moment its room does.
        $rooms = DB::table('chat_rooms')
            ->useWritePdo()
            ->whereIn('chattype', ['User2User', 'User2Mod'])
            ->whereIn('id', DB::table('chat_messages')->select('chatid')->whereBetween('date', [$since, $until])->distinct())
            ->get(['id', 'chattype', 'user1', 'user2']);

        if ($rooms->isEmpty()) {
            // No member chat at all for two days is not "no gaps": the query or
            // the data is broken, and passing would hide exactly that.
            return $this->raise(sprintf(
                '[ChatRoster] No User2User or User2Mod chat had a message in the last %d hours, so the roster check could not run.',
                $hours
            ));
        }

        // [chatid, userid] the mailer would look for in the roster.
        $expected = [];
        foreach ($rooms as $room) {
            if ($room->user1) {
                $expected[] = [$room->id, $room->user1, $room->chattype];
            }
            if ($room->chattype === 'User2User' && $room->user2) {
                $expected[] = [$room->id, $room->user2, $room->chattype];
            }
        }

        $present = [];
        foreach ($rooms->pluck('id')->chunk(1000) as $ids) {
            DB::table('chat_roster')
                ->useWritePdo()
                ->whereIn('chatid', $ids->all())
                ->get(['chatid', 'userid'])
                ->each(function ($row) use (&$present) {
                    $present[$row->chatid.':'.$row->userid] = true;
                });
        }

        // A deleted account is not mailed whatever its roster says, so only live
        // participants count.
        $live = [];
        foreach (collect($expected)->pluck(1)->unique()->chunk(1000) as $ids) {
            DB::table('users')
                ->useWritePdo()
                ->whereIn('id', $ids->all())
                ->whereNull('deleted')
                ->pluck('id')
                ->each(function ($id) use (&$live) {
                    $live[$id] = true;
                });
        }

        $checked = array_values(array_filter($expected, fn ($e) => isset($live[$e[1]])));
        $missing = array_values(array_filter($checked, fn ($e) => ! isset($present[$e[0].':'.$e[1]])));

        if (! empty($missing)) {
            $examples = array_map(
                fn ($e) => "chat {$e[0]} ({$e[2]}) user {$e[1]}",
                array_slice($missing, 0, self::EXAMPLES)
            );

            return $this->raise(sprintf(
                '[ChatRoster] %d live participant%s in chats active in the last %d hours %s no chat_roster row, so the chat mailer never emails them: %s%s. Look for what created or moved these rooms without their roster rows (an account merge did once).',
                count($missing),
                count($missing) === 1 ? '' : 's',
                $hours,
                count($missing) === 1 ? 'has' : 'have',
                implode(', ', $examples),
                count($missing) > self::EXAMPLES ? ', ...' : ''
            ));
        }

        $this->info(sprintf(
            'Chat roster OK: all %d participants of %d chats active in the last %d hours have a roster row.',
            count($checked), $rooms->count(), $hours
        ));

        return self::SUCCESS;
    }

    private function raise(string $message): int
    {
        $this->error($message);
        Log::warning($message);

        if (app()->bound('sentry')) {
            app('sentry')->captureMessage($message, \Sentry\Severity::error());
        }

        return self::FAILURE;
    }
}
