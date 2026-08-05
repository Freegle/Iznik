<?php

namespace Tests\Unit\Services\FirstReply;

use App\Models\ChatMessage;
use App\Models\Message;
use App\Models\User;
use App\Services\FirstReply\EngagementService;
use App\Services\FirstReply\FreegleUserService;
use App\Services\FirstReply\PromptService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Freegle talks about a member's outstanding posts as a SET, the way a clearance
 * treats its items. Most of what is worth testing here is that grouping: one
 * message covering several posts, aggregate numbers, and an answer that applies
 * to all of them.
 */
class EngagementServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FreegleUserService::forget();
        DB::statement('DELETE FROM firstreply_prompts_sent');

        config([
            'freegle.firstreply.enabled' => true,
            // Whole-network arm: the rollout percentage is exercised separately.
            'freegle.firstreply.rollout_percent' => 100,
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
            'freegle.firstreply.chat.kind_cooldown_days' => 14,
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
    private function seedSilentPost(User $user, $group, array $attributes = []): Message
    {
        $message = $this->createTestMessage($user, $group, array_merge([
            'subject' => 'OFFER: Dining chairs (TestLocation)',
        ], $attributes));

        DB::statement(
            'INSERT INTO messages_spatial (msgid, point, successful, promised, groupid, msgtype, arrival)
             VALUES (?, ST_SRID(POINT(?, ?), 3857), 0, 0, ?, ?, NOW())',
            [
                $message->id, $group->lng, $group->lat, $group->id,
                $attributes['type'] ?? Message::TYPE_OFFER,
            ]
        );

        return $message;
    }

    /** @return array{0:User, 1:mixed, 2:array<int,Message>} */
    private function seedMemberWithPosts(int $count, array $attributes = []): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $posts = [];

        foreach (range(1, $count) as $i) {
            $posts[] = $this->seedSilentPost($user, $group, array_merge([
                'subject' => "OFFER: Item {$i} (TestLocation)",
            ], $attributes));
        }

        return [$user, $group, $posts];
    }

    private function addViews(int $msgid, int $count): void
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

    /** Give every one of these posts a photo, so the photo question is not first. */
    private function givePhotos(array $posts): void
    {
        foreach ($posts as $p) {
            DB::table('messages_attachments')->insert(['msgid' => $p->id]);
        }
    }

    private function kindsAsked(int $userId): array
    {
        return DB::table('firstreply_prompts_sent')->where('userid', $userId)
            ->orderBy('id')->pluck('kind')->all();
    }

    private function latestPromptText(): ?string
    {
        $chatmsgid = DB::table('chat_prompts')->orderByDesc('chatmsgid')->value('chatmsgid');

        return $chatmsgid === null
            ? null
            : DB::table('chat_messages')->where('id', $chatmsgid)->value('message');
    }

    private function latestMsgids(): array
    {
        $json = DB::table('chat_prompts')->orderByDesc('chatmsgid')->value('msgids');

        return $json === null ? [] : array_map('intval', json_decode((string) $json, true) ?? []);
    }

    // --- Grouping: the whole point --------------------------------------------

    public function test_one_message_covers_all_of_a_members_outstanding_posts(): void
    {
        // The case that makes per-post messaging unusable: somebody clearing a
        // house. One question about all their posts, not six separate threads.
        [, , $posts] = $this->seedMemberWithPosts(4);

        $stats = $this->service()->run();

        $this->assertSame(1, $stats['sent'], 'one message, not one per post');
        $this->assertEqualsCanonicalizing(
            array_map(static fn ($p) => (int) $p->id, $posts),
            $this->latestMsgids(),
            'and it covers every one of them'
        );
    }

    public function test_reports_views_totalled_across_the_posts(): void
    {
        // "your 3 posts have been looked at 12 times between them" is the number
        // that means something; per-post it is three discouraging small numbers.
        [, , $posts] = $this->seedMemberWithPosts(3);
        $this->givePhotos($posts);
        DB::table('messages')->whereIn('id', array_map(static fn ($p) => $p->id, $posts))
            ->update(['deliverypossible' => 1]);

        $this->addViews((int) $posts[0]->id, 5);
        $this->addViews((int) $posts[1]->id, 4);
        $this->addViews((int) $posts[2]->id, 3);

        $this->service()->run();

        $text = (string) $this->latestPromptText();
        $this->assertStringContainsString('3 outstanding posts', $text);
        $this->assertStringContainsString('12 times', $text);
    }

    public function test_a_question_only_covers_the_posts_it_applies_to(): void
    {
        // Two posts have photos, two do not. The photo question is about the two
        // that do not - asking about the others is how a useful message becomes
        // noise.
        [, , $posts] = $this->seedMemberWithPosts(4);
        $this->givePhotos([$posts[0], $posts[1]]);

        $this->service()->run();

        $this->assertEqualsCanonicalizing(
            [(int) $posts[2]->id, (int) $posts[3]->id],
            $this->latestMsgids()
        );
    }

    public function test_does_not_ask_a_wanted_poster_whether_they_could_deliver(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $post = $this->seedSilentPost($user, $group, [
            'type' => Message::TYPE_WANTED,
            'subject' => 'WANTED: Dining chairs (TestLocation)',
        ]);
        $this->givePhotos([$post]);

        for ($i = 0; $i < 4; $i++) {
            $this->service()->run();
        }

        $this->assertNotContains(PromptService::KIND_DELIVERY, $this->kindsAsked((int) $user->id));
    }

    public function test_says_nothing_about_views_until_enough_people_have_looked(): void
    {
        [$user, , $posts] = $this->seedMemberWithPosts(2);
        $this->givePhotos($posts);
        $this->addViews((int) $posts[0]->id, 2);

        for ($i = 0; $i < 4; $i++) {
            $this->service()->run();
        }

        $this->assertNotContains(
            PromptService::KIND_VIEWS,
            $this->kindsAsked((int) $user->id),
            '"2 people looked and nobody wanted them" is discouraging, not reassuring'
        );
    }

    // --- Cadence ---------------------------------------------------------------

    public function test_asks_one_question_at_a_time_and_works_through_them(): void
    {
        [$user] = $this->seedMemberWithPosts(2);

        $this->service()->run();
        $this->service()->run();

        $this->assertSame(
            [PromptService::KIND_PHOTO, PromptService::KIND_DELIVERY],
            $this->kindsAsked((int) $user->id),
            'a quiet member should be worked through gradually, not asked everything at once'
        );
    }

    public function test_does_not_repeat_a_question_within_the_cooldown(): void
    {
        [$user] = $this->seedMemberWithPosts(2);

        for ($i = 0; $i < 10; $i++) {
            $this->service()->run();
        }

        $kinds = $this->kindsAsked((int) $user->id);
        $this->assertSame(count($kinds), count(array_unique($kinds)));
    }

    public function test_posting_something_new_does_not_reset_the_clock(): void
    {
        // Due-ness is judged on the OLDEST silent post, so a steady poster cannot
        // hold off questions they have already earned by posting again.
        config(['freegle.firstreply.chat.schedule' => ['photo' => 5]]);

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $old = $this->seedSilentPost($user, $group);
        DB::table('messages_spatial')->where('msgid', $old->id)
            ->update(['arrival' => now()->subHours(9)]);

        $this->seedSilentPost($user, $group, ['subject' => 'OFFER: Just now (TestLocation)']);

        $this->assertSame(1, $this->service()->run()['sent']);
    }

    public function test_respects_a_member_who_has_turned_freegle_chat_off(): void
    {
        [$user] = $this->seedMemberWithPosts(2);
        DB::table('users')->where('id', $user->id)
            ->update(['settings' => json_encode(['freeglechat' => false])]);

        $this->assertSame(0, $this->service()->run()['sent']);
    }

    public function test_a_member_hears_at_most_once_within_the_gap(): void
    {
        config(['freegle.firstreply.chat.user_gap_hours' => 6]);
        [$user] = $this->seedMemberWithPosts(3);

        $this->service()->run();
        $this->service()->run();

        $this->assertCount(1, $this->kindsAsked((int) $user->id));
    }

    public function test_says_nothing_to_a_member_whose_posts_all_have_replies(): void
    {
        [, , $posts] = $this->seedMemberWithPosts(2);
        $poster = User::find($posts[0]->fromuser);

        foreach ($posts as $p) {
            $replier = $this->createTestUser();
            $room = $this->createTestChatRoom($replier, $poster);
            $this->createTestChatMessage($room, $replier, [
                'type' => ChatMessage::TYPE_INTERESTED,
                'refmsgid' => $p->id,
            ]);
        }

        $this->assertSame(0, $this->service()->run()['sent']);
    }

    // --- Retirement ------------------------------------------------------------

    public function test_retires_a_question_once_none_of_its_posts_are_still_waiting(): void
    {
        // Answering a stale prompt would edit posts that are already sorted.
        [, , $posts] = $this->seedMemberWithPosts(2);
        $this->service()->run();

        $chatmsgid = DB::table('chat_prompts')->orderByDesc('chatmsgid')->value('chatmsgid');
        $poster = User::find($posts[0]->fromuser);

        foreach ($posts as $p) {
            $replier = $this->createTestUser();
            $room = $this->createTestChatRoom($replier, $poster);
            $this->createTestChatMessage($room, $replier, [
                'type' => ChatMessage::TYPE_INTERESTED,
                'refmsgid' => $p->id,
            ]);
        }

        $this->service()->run();

        $expires = DB::table('chat_prompts')->where('chatmsgid', $chatmsgid)->value('expires_at');
        $this->assertNotNull($expires);
        $this->assertTrue(\Carbon\Carbon::parse($expires)->lessThanOrEqualTo(now()));
    }

    public function test_keeps_a_question_live_while_any_of_its_posts_is_still_waiting(): void
    {
        // The question still means something for the ones that remain, and
        // answering applies to those.
        [, , $posts] = $this->seedMemberWithPosts(3);
        $this->service()->run();

        $chatmsgid = DB::table('chat_prompts')->orderByDesc('chatmsgid')->value('chatmsgid');
        $before = DB::table('chat_prompts')->where('chatmsgid', $chatmsgid)->value('expires_at');

        $poster = User::find($posts[0]->fromuser);
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($replier, $poster);
        $this->createTestChatMessage($room, $replier, [
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $posts[0]->id,
        ]);

        $this->service()->run();

        $this->assertSame(
            $before,
            DB::table('chat_prompts')->where('chatmsgid', $chatmsgid)->value('expires_at')
        );
    }

    public function test_an_already_answered_question_is_left_alone(): void
    {
        // Retiring must not rewrite history: what the member said stands.
        [, , $posts] = $this->seedMemberWithPosts(1);
        $this->service()->run();

        $chatmsgid = DB::table('chat_prompts')->orderByDesc('chatmsgid')->value('chatmsgid');
        DB::table('chat_prompts')->where('chatmsgid', $chatmsgid)
            ->update(['answer' => 'add', 'answered_at' => now()]);
        $before = DB::table('chat_prompts')->where('chatmsgid', $chatmsgid)->value('expires_at');

        DB::table('messages_outcomes')->insert([
            'msgid' => $posts[0]->id,
            'outcome' => 'Taken',
            'timestamp' => now(),
        ]);
        $this->service()->run();

        $this->assertSame(
            $before,
            DB::table('chat_prompts')->where('chatmsgid', $chatmsgid)->value('expires_at')
        );
    }

    // --- Shape of what is sent --------------------------------------------------

    public function test_the_deadline_question_offers_a_date_picker(): void
    {
        // Fixed timescales are wrong for anyone with a real date in mind, and the
        // poster already knows theirs.
        [, , $posts] = $this->seedMemberWithPosts(2);
        $this->givePhotos($posts);
        DB::table('messages')->whereIn('id', array_map(static fn ($p) => $p->id, $posts))
            ->update(['deliverypossible' => 1]);

        for ($i = 0; $i < 4; $i++) {
            $this->service()->run();
        }

        $options = DB::table('chat_prompts')
            ->where('kind', PromptService::KIND_DEADLINE)->value('options');
        $decoded = json_decode((string) $options, true);

        $this->assertContains('date', array_column($decoded, 'input'));
        $this->assertContains('norush', array_column($decoded, 'value'));
    }

    public function test_the_wording_never_names_a_post(): void
    {
        // An item name reads fine as "your dining chairs" and badly as whatever
        // someone actually typed. The cards say which posts; the words do not.
        $this->seedMemberWithPosts(2, ['subject' => 'OFFER: Pending bookshelf (TestLocation)']);

        $this->service()->run();

        $this->assertStringNotContainsString('bookshelf', (string) $this->latestPromptText());
    }

    public function test_a_single_post_still_reads_naturally(): void
    {
        // Grouping must not make the one-post case sound like a spreadsheet.
        $this->seedMemberWithPosts(1);

        $this->service()->run();
        $text = (string) $this->latestPromptText();

        $this->assertStringContainsString('this', $text);
        $this->assertStringNotContainsString('1 of your posts', $text);
    }

    public function test_does_nothing_at_all_when_switched_off(): void
    {
        config(['freegle.firstreply.chat.enabled' => false]);
        $this->seedMemberWithPosts(2);

        $this->assertSame(0, $this->service()->run()['sent']);
    }

    public function test_dry_run_sends_nothing(): void
    {
        [$user] = $this->seedMemberWithPosts(2);

        $stats = $this->service()->run(true);

        $this->assertSame(1, $stats['sent'], 'dry run still reports what it would have sent');
        $this->assertCount(0, $this->kindsAsked((int) $user->id));
    }
}
