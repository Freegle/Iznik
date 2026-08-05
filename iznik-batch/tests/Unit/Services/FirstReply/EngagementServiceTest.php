<?php

namespace Tests\Unit\Services\FirstReply;

use App\Models\ChatMessage;
use App\Models\Message;
use App\Services\FirstReply\EngagementService;
use App\Services\FirstReply\FreegleUserService;
use App\Services\FirstReply\PromptService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EngagementServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FreegleUserService::forget();
        DB::statement('DELETE FROM firstreply_prompts_sent');

        config([
            'freegle.firstreply.enabled' => true,
            'freegle.firstreply.chat.enabled' => true,
            // Everything due immediately, so a test does not have to invent an
            // old post just to see which question comes first.
            'freegle.firstreply.chat.schedule' => [
                'photo' => 0,
                'delivery' => 0,
                'views' => 0,
                'deadline' => 0,
            ],
            'freegle.firstreply.chat.user_gap_hours' => 0,
            'freegle.firstreply.chat.views_min' => 5,
            'freegle.firstreply.chat.max_per_post' => 4,
        ]);
    }

    private function service(): EngagementService
    {
        return app(EngagementService::class);
    }

    /**
     * A live post with no replies. messages_spatial is what the engine reads, so
     * the row has to be there for the post to be visible to it at all.
     */
    private function seedSilentPost(array $attributes = []): Message
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group, array_merge([
            'subject' => 'OFFER: Dining chairs (TestLocation)',
        ], $attributes));

        DB::statement(
            'INSERT INTO messages_spatial (msgid, point, successful, promised, groupid, msgtype, arrival)
             VALUES (?, ST_SRID(POINT(?, ?), 3857), 0, 0, ?, ?, ?)',
            [
                $message->id, $group->lng, $group->lat, $group->id,
                $attributes['type'] ?? Message::TYPE_OFFER,
                $attributes['arrival'] ?? now(),
            ]
        );

        return $message;
    }

    private function addView(int $msgid, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $viewer = $this->createTestUser();
            DB::table('messages_likes')->insert([
                'msgid' => $msgid,
                'userid' => $viewer->id,
                'type' => 'View',
                'pageview' => 1,
            ]);
        }
    }

    private function promptsFor(int $msgid): array
    {
        return DB::table('chat_prompts')->where('msgid', $msgid)->pluck('kind')->all();
    }

    public function test_asks_about_a_photo_first_on_a_post_that_has_none(): void
    {
        $message = $this->seedSilentPost();

        $stats = $this->service()->run();

        $this->assertSame(1, $stats['sent']);
        $this->assertSame([PromptService::KIND_PHOTO], $this->promptsFor((int) $message->id));
    }

    public function test_asks_one_question_per_run_and_works_through_them_in_order(): void
    {
        $message = $this->seedSilentPost();

        $this->service()->run();
        $this->service()->run();

        $this->assertSame(
            [PromptService::KIND_PHOTO, PromptService::KIND_DELIVERY],
            $this->promptsFor((int) $message->id),
            'a quiet post should be worked through gradually, not asked everything at once'
        );
    }

    public function test_never_asks_the_same_question_twice(): void
    {
        $message = $this->seedSilentPost();

        // Four kinds configured, run more times than that.
        for ($i = 0; $i < 8; $i++) {
            $this->service()->run();
        }

        $kinds = $this->promptsFor((int) $message->id);
        $this->assertSame(count($kinds), count(array_unique($kinds)));
        $this->assertLessThanOrEqual(4, count($kinds));
    }

    public function test_does_not_ask_a_wanted_poster_whether_they_could_deliver(): void
    {
        $message = $this->seedSilentPost([
            'type' => Message::TYPE_WANTED,
            'subject' => 'WANTED: Dining chairs (TestLocation)',
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->service()->run();
        }

        $this->assertNotContains(PromptService::KIND_DELIVERY, $this->promptsFor((int) $message->id));
    }

    public function test_says_nothing_about_views_until_enough_people_have_looked(): void
    {
        $message = $this->seedSilentPost();
        $this->addView((int) $message->id, 2);

        for ($i = 0; $i < 4; $i++) {
            $this->service()->run();
        }

        $this->assertNotContains(
            PromptService::KIND_VIEWS,
            $this->promptsFor((int) $message->id),
            '"2 people looked and nobody wanted it" is discouraging, not reassuring'
        );
    }

    public function test_reports_views_once_there_are_enough_of_them(): void
    {
        $message = $this->seedSilentPost();
        $this->addView((int) $message->id, 7);

        for ($i = 0; $i < 4; $i++) {
            $this->service()->run();
        }

        $this->assertContains(PromptService::KIND_VIEWS, $this->promptsFor((int) $message->id));

        $chatmsgid = DB::table('chat_prompts')
            ->where('msgid', $message->id)
            ->where('kind', PromptService::KIND_VIEWS)
            ->value('chatmsgid');
        $text = DB::table('chat_messages')->where('id', $chatmsgid)->value('message');
        $this->assertStringContainsString('7 freeglers', $text);
    }

    public function test_says_nothing_about_a_post_that_already_has_a_reply(): void
    {
        $message = $this->seedSilentPost();

        $replier = $this->createTestUser();
        $poster = \App\Models\User::find($message->fromuser);
        $room = $this->createTestChatRoom($replier, $poster);
        $this->createTestChatMessage($room, $replier, [
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $message->id,
        ]);

        $stats = $this->service()->run();

        $this->assertSame(0, $stats['sent']);
        $this->assertSame([], $this->promptsFor((int) $message->id));
    }

    public function test_respects_a_member_who_has_turned_freegle_chat_off(): void
    {
        $message = $this->seedSilentPost();
        DB::table('users')->where('id', $message->fromuser)
            ->update(['settings' => json_encode(['freeglechat' => false])]);

        $stats = $this->service()->run();

        $this->assertSame(0, $stats['sent']);
    }

    public function test_one_member_with_several_silent_posts_is_not_flooded(): void
    {
        config(['freegle.firstreply.chat.user_gap_hours' => 6]);

        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        foreach (['Dining chairs', 'Garden table', 'Bookcase'] as $item) {
            $message = $this->createTestMessage($user, $group, ['subject' => "OFFER: $item (TestLocation)"]);
            DB::statement(
                'INSERT INTO messages_spatial (msgid, point, successful, promised, groupid, msgtype, arrival)
                 VALUES (?, ST_SRID(POINT(?, ?), 3857), 0, 0, ?, ?, NOW())',
                [$message->id, $group->lng, $group->lat, $group->id, Message::TYPE_OFFER]
            );
        }

        $this->service()->run();

        $this->assertSame(
            1,
            DB::table('firstreply_prompts_sent')->where('userid', $user->id)->count(),
            'clearing out a house should not start three conversations at once'
        );
    }

    public function test_does_nothing_at_all_when_switched_off(): void
    {
        config(['freegle.firstreply.chat.enabled' => false]);
        $message = $this->seedSilentPost();

        $this->assertSame(0, $this->service()->run()['sent']);
        $this->assertSame([], $this->promptsFor((int) $message->id));
    }

    public function test_dry_run_sends_nothing(): void
    {
        $message = $this->seedSilentPost();

        $stats = $this->service()->run(true);

        $this->assertSame(1, $stats['sent'], 'dry run still reports what it would have sent');
        $this->assertSame([], $this->promptsFor((int) $message->id));
    }
}
