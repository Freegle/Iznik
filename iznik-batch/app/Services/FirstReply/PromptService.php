<?php

namespace App\Services\FirstReply;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A question Freegle asks a member, with tappable answers.
 *
 * The two questions that most change whether a post succeeds - "could you
 * deliver?" and "when does it need to be gone?" - used to be modals fired at
 * posting time. Both components are still in the tree and neither is wired to
 * anything, which is a fair verdict on the format: at the moment of posting the
 * member is trying to finish, and a question about logistics for an item nobody
 * has asked for yet is noise. The same question two hours later, when the post
 * has been up a while and nothing has happened, is useful.
 *
 * Email cannot carry the buttons, so it does not try. The notification says what
 * is being asked and takes the member to the chat, which is where the answer
 * happens. That is a deliberate extra step for the member and the reason prompts
 * are rationed hard: each one has to be worth the trip.
 *
 * The prompt lives as an ordinary chat message (so email, push, unread state and
 * history all work with no changes) plus a chat_prompts row holding the machine-
 * readable part. Answering is the only thing that reads the options; everything
 * else in the system sees a normal message with normal text.
 *
 * This service only ASKS. Answering happens in the Go API
 * (chat.AnswerChatPrompt), because that is where the member's tap actually
 * arrives; a second implementation here would mean two versions of what "by
 * this weekend" means, drifting apart the first time either was touched.
 */
class PromptService
{
    /** Offer to deliver. Answering patches messages.deliverypossible. */
    public const KIND_DELIVERY = 'delivery';

    /** Set a deadline. Answering patches messages.deadline. */
    public const KIND_DEADLINE = 'deadline';

    /** How many people have opened the post. Nothing to answer. */
    public const KIND_VIEWS = 'views';

    /** No photo on the post yet. Answering records intent; the button navigates. */
    public const KIND_PHOTO = 'photo';

    public function __construct(private FreegleUserService $freegle)
    {
    }

    /**
     * Ask $userId a question. Returns the new chat message id, or null if Freegle
     * could not speak (no system account, no room).
     *
     * The message is inserted with processingrequired=1, which is what hands it to
     * the normal chat pipeline: ChatProcessService marks it deliverable, bumps the
     * room's latestmessage so it appears in the chat list, queues the push, and
     * ChatNotificationService emails it. Nothing here duplicates any of that.
     *
     * $msgids is the SET of posts the answer applies to. Freegle talks about a
     * member's outstanding posts together, the way a clearance treats its items,
     * so one question usually covers several. The chat message's refmsgid is set
     * only when there is exactly one - that drives the single item card in the
     * notification email, and there is no sensible single card for six.
     *
     * @param array<int,array{value:string,label:string,variant?:string,action?:string}> $options
     * @param int[] $msgids
     */
    public function send(int $userId, string $kind, string $text, array $options = [], array $msgids = []): ?int
    {
        $freegleId = $this->freegle->userId();
        if ($freegleId === null || $freegleId === $userId) {
            return null;
        }

        $chatId = $this->getOrCreateRoom($freegleId, $userId);
        if ($chatId === null) {
            return null;
        }

        $expiryDays = max(1, (int) config('freegle.firstreply.chat.expiry_days', 7));

        $msgids = array_values(array_unique(array_map('intval', $msgids)));
        $single = count($msgids) === 1 ? $msgids[0] : null;

        try {
            return DB::transaction(function () use ($chatId, $freegleId, $kind, $text, $options, $msgids, $single, $expiryDays) {
                $chatMsgId = DB::table('chat_messages')->insertGetId([
                    'chatid' => $chatId,
                    'userid' => $freegleId,
                    'message' => $text,
                    'type' => ChatMessage::TYPE_PROMPT,
                    'refmsgid' => $single,
                    'date' => now(),
                    'processingrequired' => 1,
                    // Not sent from a browser or app - same as the tryst reminder.
                    'platform' => 0,
                ]);

                DB::table('chat_prompts')->insert([
                    'chatmsgid' => $chatMsgId,
                    'msgid' => $single,
                    'msgids' => json_encode($msgids),
                    'kind' => $kind,
                    'options' => json_encode(array_values($options)),
                    'expires_at' => now()->addDays($expiryDays),
                    'created_at' => now(),
                ]);

                return (int) $chatMsgId;
            });
        } catch (\Throwable $e) {
            Log::warning('firstreply: could not send prompt', [
                'userid' => $userId, 'kind' => $kind, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The one User2User room between Freegle and this member, created on first
     * need. Freegle is always user1 so the pair is stable whichever way the unique
     * key on (user1, user2, chattype) is consulted.
     */
    private function getOrCreateRoom(int $freegleId, int $userId): ?int
    {
        try {
            $existing = DB::table('chat_rooms')
                ->where('chattype', ChatRoom::TYPE_USER2USER)
                ->where(function ($q) use ($freegleId, $userId) {
                    $q->where(function ($q2) use ($freegleId, $userId) {
                        $q2->where('user1', $freegleId)->where('user2', $userId);
                    })->orWhere(function ($q2) use ($freegleId, $userId) {
                        $q2->where('user1', $userId)->where('user2', $freegleId);
                    });
                })
                ->value('id');

            if ($existing) {
                return (int) $existing;
            }

            return (int) DB::table('chat_rooms')->insertGetId([
                'user1' => $freegleId,
                'user2' => $userId,
                'chattype' => ChatRoom::TYPE_USER2USER,
                'latestmessage' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('firstreply: could not open Freegle chat', [
                'userid' => $userId, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
