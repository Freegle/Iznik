<?php

namespace Tests\Feature\Message;

use App\Mail\Message\FamiliarPosts;
use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use App\Services\EmailSpoolerService;
use App\Services\Ux\UxArm;
use App\Services\Ux\UxExperimentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\TestCase;

/**
 * messages:familiar-posts, the experiment familiar-poster: who knows a poster, both arms recorded, only the
 * notify arm mailed, the limits, and nothing at all while the experiment is not live. The spooler is replaced,
 * so no mail leaves; the mailables it is given are kept.
 */
class FamiliarPostsCommandTest extends TestCase
{
    private ?Group $group = null;

    /** @var array<int, FamiliarPosts> */
    private array $spooled = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->spooled = [];
        $this->mock(EmailSpoolerService::class, function ($m) {
            $m->shouldReceive('spool')->andReturnUsing(function ($mail) {
                $this->spooled[] = $mail;

                return 'spool-id';
            });
        });
        DB::table('config')->whereIn('key', [UxExperimentService::CONFIG_KEY, UxArm::SWITCH_KEY, 'familiar_posts_cursor'])->delete();
        UxArm::flush();
    }

    protected function tearDown(): void
    {
        UxArm::flush();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- setting the scene

    /** Serve the experiment with every member in one arm. */
    private function serve(string $arm = 'notify'): void
    {
        $arms = $arm === 'notify'
            ? ['control' => [1, 0], 'notify' => [0, 99]]
            : ['control' => [0, 99], 'notify' => [1, 0]];
        DB::table('config')->upsert([
            ['key' => UxExperimentService::CONFIG_KEY, 'value' => json_encode(['familiar-poster' => ['arms' => $arms, 'until' => time() + 3600]])],
            ['key' => UxArm::SWITCH_KEY, 'value' => '1'],
        ], ['key'], ['value']);
        UxArm::flush();
    }

    private function member(array $attributes = []): User
    {
        $user = $this->createTestUser($attributes + ['lastaccess' => now()]);
        $this->group ??= $this->createTestGroup();
        $this->createMembership($user, $this->group);

        return $user;
    }

    /** A chat between the two in which each wrote, the newest $daysAgo days ago. */
    private function chatted(User $a, User $b, int $daysAgo = 5): int
    {
        $at = now()->subDays($daysAgo);
        $room = $this->createTestChatRoom($a, $b, ['latestmessage' => $at]);
        $this->createTestChatMessage($room, $a, ['date' => $at]);
        $this->createTestChatMessage($room, $b, ['date' => $at]);

        return (int) $room->id;
    }

    private function newPost(User $poster, array $attributes = []): Message
    {
        return $this->createTestMessage($poster, $this->group, $attributes + ['arrival' => now()->subMinutes(20)]);
    }

    private function run_(array $options = []): void
    {
        $this->artisan('messages:familiar-posts', $options)->assertExitCode(0);
    }

    private function exposures(): \Illuminate\Support\Collection
    {
        return DB::table('ux_experiment_exposures')->where('experiment', 'familiar-poster')->get();
    }

    // ---------------------------------------------------------------- who is told

    public function test_a_member_who_chatted_with_the_poster_is_mailed_in_the_notify_arm(): void
    {
        $this->serve('notify');
        $poster = $this->member(['fullname' => 'Jane Smith']);
        $friend = $this->member();
        $this->chatted($poster, $friend);
        $post = $this->newPost($poster, ['subject' => 'OFFER: Sofa (Leeds)']);

        $this->run_();

        $this->assertCount(1, $this->spooled);
        $this->assertSame('Jane Smith is offering: Sofa', $this->spooled[0]->envelope()->subject);
        $row = $this->exposures()->sole();
        $this->assertSame([(int) $friend->id, 'notify', 'post', (int) $post->id], [(int) $row->userid, $row->arm, $row->subject_type, (int) $row->subject_id]);
    }

    public function test_control_is_counted_and_not_mailed(): void
    {
        $this->serve('control');
        $poster = $this->member();
        $friend = $this->member();
        $this->chatted($poster, $friend);
        $this->newPost($poster);

        $this->run_();

        $this->assertCount(0, $this->spooled);
        $this->assertSame('control', $this->exposures()->sole()->arm);
    }

    public function test_nothing_happens_while_the_experiment_is_not_live(): void
    {
        $poster = $this->member();
        $friend = $this->member();
        $this->chatted($poster, $friend);
        $this->newPost($poster);

        $this->run_();

        $this->assertCount(0, $this->spooled);
        $this->assertCount(0, $this->exposures());
        $this->assertNull(DB::table('config')->where('key', 'familiar_posts_cursor')->value('value'));
    }

    public function test_each_reason_not_to_tell_a_member_is_respected(): void
    {
        $this->serve('notify');
        $poster = $this->member();
        $told = $this->member();
        $this->chatted($poster, $told);

        // Only the poster wrote in the chat.
        $oneWay = $this->member();
        $room = $this->createTestChatRoom($poster, $oneWay, ['latestmessage' => now()->subDay()]);
        $this->createTestChatMessage($room, $poster, ['date' => now()->subDay()]);

        // Chatted, but too long ago.
        $stale = $this->member();
        $this->chatted($poster, $stale, 100);

        // Rated the poster down, or was rated down by them.
        $downed = $this->member();
        $this->chatted($poster, $downed);
        DB::table('ratings')->insert(['rater' => $downed->id, 'ratee' => $poster->id, 'rating' => 'Down', 'timestamp' => now()]);
        $downedBy = $this->member();
        $this->chatted($poster, $downedBy);
        DB::table('ratings')->insert(['rater' => $poster->id, 'ratee' => $downedBy->id, 'rating' => 'Down', 'timestamp' => now()]);

        // Blocked the chat.
        $blocked = $this->member();
        $blockedRoom = $this->chatted($poster, $blocked);
        DB::table('chat_roster')->insert(['chatid' => $blockedRoom, 'userid' => $blocked->id, 'status' => 'Blocked', 'date' => now()]);

        // Turned off mail about posts they might like.
        $optedOut = $this->member(['relevantallowed' => 0]);
        $this->chatted($poster, $optedOut);

        // Not a member of the post's community.
        $outsider = $this->createTestUser(['lastaccess' => now()]);
        $this->chatted($poster, $outsider);

        $post = $this->newPost($poster);

        // Already replied to the post.
        $replied = $this->member();
        $repliedRoom = $this->chatted($poster, $replied);
        $this->createTestChatMessage(\App\Models\ChatRoom::find($repliedRoom), $replied, ['type' => 'Interested', 'refmsgid' => $post->id, 'date' => now()]);

        $this->run_();

        $this->assertSame([(int) $told->id], $this->exposures()->pluck('userid')->map(fn ($id) => (int) $id)->all());
        $this->assertCount(1, $this->spooled);
    }

    public function test_a_post_that_is_not_new_is_left_alone(): void
    {
        $this->serve('notify');
        $poster = $this->member();
        $friend = $this->member();
        $this->chatted($poster, $friend);

        $reposted = $this->newPost($poster);
        DB::table('messages_groups')->where('msgid', $reposted->id)->update(['autoreposts' => 1]);
        $old = $this->newPost($poster, ['arrival' => now()->subHours(8)]);
        $taken = $this->newPost($poster);
        DB::table('messages_outcomes')->insert(['msgid' => $taken->id, 'outcome' => 'Taken', 'timestamp' => now()]);
        $pending = $this->newPost($poster);
        DB::table('messages_groups')->where('msgid', $pending->id)->update(['collection' => 'Pending']);

        $this->run_();

        $this->assertCount(0, $this->exposures());
        $this->assertCount(0, $this->spooled);
    }

    // ---------------------------------------------------------------- not too many

    public function test_several_posts_go_in_one_email_up_to_the_limit(): void
    {
        config(['freegle.familiar_posts.max_posts_per_email' => 2]);
        $this->serve('notify');
        $friend = $this->member();
        foreach (range(1, 3) as $i) {
            $poster = $this->member();
            $this->chatted($poster, $friend);
            $this->newPost($poster);
        }

        $this->run_();

        $this->assertCount(1, $this->spooled);
        $this->assertCount(2, $this->spooled[0]->messages);
        $this->assertSame('New posts from people you have chatted with', $this->spooled[0]->envelope()->subject);
        $this->assertCount(2, $this->exposures());
    }

    public function test_a_member_is_reached_at_most_once_a_day(): void
    {
        $this->serve('notify');
        $friend = $this->member();
        $first = $this->member();
        $this->chatted($first, $friend);
        $this->newPost($first);
        $this->run_();
        $this->assertCount(1, $this->spooled);

        Carbon::setTestNow(now()->addHours(2));
        $second = $this->member();
        $this->chatted($second, $friend);
        $this->newPost($second);
        $this->run_();
        Carbon::setTestNow();

        $this->assertCount(1, $this->spooled);
    }

    public function test_a_member_is_reached_on_at_most_two_days_a_week(): void
    {
        $this->serve('notify');
        $friend = $this->member();
        foreach ([6, 3] as $daysAgo) {
            DB::table('ux_experiment_exposures')->insert([
                'experiment' => 'familiar-poster', 'arm' => 'notify', 'userid' => $friend->id,
                'subject_type' => 'post', 'subject_id' => 1000 + $daysAgo, 'exposed_at' => now()->subDays($daysAgo),
            ]);
        }
        $poster = $this->member();
        $this->chatted($poster, $friend);
        $this->newPost($poster);

        $this->run_();

        $this->assertCount(0, $this->spooled);
    }

    public function test_at_most_the_limit_of_members_are_told_about_one_post(): void
    {
        config(['freegle.familiar_posts.max_recipients_per_post' => 2]);
        $this->serve('notify');
        $poster = $this->member();
        foreach ([1, 2, 3] as $daysAgo) {
            $this->chatted($poster, $this->member(), $daysAgo);
        }
        $this->newPost($poster);

        $this->run_();

        $this->assertCount(2, $this->spooled);
    }

    public function test_a_post_is_read_once(): void
    {
        $this->serve('notify');
        $poster = $this->member();
        $friend = $this->member();
        $this->chatted($poster, $friend);
        $this->newPost($poster);

        $this->run_();
        DB::table('ux_experiment_exposures')->delete();
        $this->run_();

        $this->assertCount(1, $this->spooled);
    }

    public function test_a_dry_run_writes_and_sends_nothing(): void
    {
        $this->serve('notify');
        $poster = $this->member();
        $this->chatted($poster, $this->member());
        $this->newPost($poster);

        $this->run_(['--dry-run' => true]);

        $this->assertCount(0, $this->spooled);
        $this->assertCount(0, $this->exposures());
    }

    // ---------------------------------------------------------------- the email

    public function test_the_email_names_the_poster_and_has_a_tracked_reply_link(): void
    {
        $poster = $this->member(['fullname' => 'Jane Smith']);
        $friend = $this->member();
        $post = $this->newPost($poster, ['subject' => 'WANTED: Lamp (Leeds)', 'type' => Message::TYPE_WANTED]);
        $mail = new FamiliarPosts($friend, $friend->email_preferred, [$post->fresh(['attachments', 'fromUser'])]);

        $this->assertSame('Jane Smith is looking for: Lamp', $mail->envelope()->subject);
        $mail->build();
        $html = $this->renderView($mail);

        $this->assertStringContainsString('Jane Smith has a new post', $html);
        $this->assertStringContainsString('You chatted with Jane Smith on Freegle recently.', $html);
        $this->assertStringContainsString('WANTED', $html);
        $this->assertStringContainsString('We send this at most twice a week.', $html);
        $this->assertStringContainsString('/user/relevantoff?u='.$friend->id, $html);
        $this->assertStringContainsString('Change your email settings', $html);
        preg_match_all('#<a href="([^"]+)"[^>]*>Reply</a>#', $html, $m);
        $this->assertCount(1, $m[1]);
        $this->assertStringNotContainsString('/message/'.$post->id.'?reply=1', $m[1][0]);
        $this->assertSame([(int) $poster->id], $mail->about()['users']);
        $this->assertSame([(int) $post->id], $mail->about()['messages']);
    }

    public function test_a_poster_with_no_name_is_someone(): void
    {
        $poster = $this->member(['fullname' => null, 'firstname' => null, 'lastname' => null]);
        $friend = $this->member();
        $post = $this->newPost($poster, ['subject' => 'OFFER: Sofa (Leeds)']);
        $mail = new FamiliarPosts($friend, $friend->email_preferred, [$post->fresh(['attachments', 'fromUser'])]);

        $this->assertSame('Someone is offering: Sofa', $mail->envelope()->subject);
    }

    /** The MJML the mail would send, rendered from the data it built (the MJML server is not needed). */
    private function renderView(FamiliarPosts $mail): string
    {
        $template = new ReflectionProperty(\App\Mail\MjmlMailable::class, 'mjmlTemplate');
        $data = new ReflectionProperty(\App\Mail\MjmlMailable::class, 'mjmlData');

        return view($template->getValue($mail), $data->getValue($mail))->render();
    }
}
