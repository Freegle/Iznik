<?php

namespace Tests\Feature\Ux;

use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use App\Services\Ux\Sources\FamiliarReplySource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The experiment familiar-poster counted over real rows. The time is 12:00 on 21 October and the window is 48
 * hours, so an exposure is mature if it is at or before 12:00 on 19 October. The experiment started at midnight on
 * the 14th. An exposure is a new post from someone the member chatted with (subject = the post); a reply is a chat
 * message of type Interested that names that post.
 *
 * A helper here must not be called post, get, put or delete: the base test case has public methods of those names.
 */
class FamiliarReplySourceTest extends TestCase
{
    private const NAME = 'fp-source-test';

    private const ARMS = ['control' => [0, 49], 'notify' => [50, 99]];

    private FamiliarReplySource $source;

    private ?Group $group = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 12:00:00');
        $this->source = new FamiliarReplySource;
        DB::table('ux_experiment_exposures')->where('experiment', 'like', 'fp-%')->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function exp(): object
    {
        return (object) [
            'name' => self::NAME,
            'arms' => json_encode(self::ARMS),
            'design' => json_encode(['channel' => 'email', 'type' => 'FamiliarPosts', 'source' => 'familiar_reply', 'window_hours' => 48]),
            'started_at' => '2026-10-14 00:00:00',
        ];
    }

    private function makePost(): Message
    {
        $this->group ??= $this->createTestGroup();

        return $this->createTestMessage($this->createTestUser(), $this->group);
    }

    private function exposed(User $member, Message $post, string $arm, string $at): void
    {
        DB::table('ux_experiment_exposures')->insert([
            'experiment' => self::NAME, 'arm' => $arm, 'userid' => $member->id, 'subject_type' => 'post',
            'subject_id' => $post->id, 'stratum' => null, 'exposed_at' => $at, 'detail' => null,
        ]);
    }

    private function reply(User $member, Message $post, string $at): void
    {
        $room = $this->createTestChatRoom($member, User::findOrFail($post->fromuser));
        $this->createTestChatMessage($room, $member, ['type' => 'Interested', 'refmsgid' => $post->id, 'date' => $at]);
    }

    /** @return array<string, mixed> */
    private function counts(): array
    {
        return $this->source->counts($this->exp(), Carbon::parse('2026-10-21 12:00:00', 'UTC'), null);
    }

    public function test_a_reply_to_the_post_within_the_window_converts(): void
    {
        $post = $this->makePost();
        $replied = $this->createTestUser();
        $late = $this->createTestUser();
        $control = $this->createTestUser();
        $young = $this->createTestUser();

        $this->exposed($replied, $post, 'notify', '2026-10-15 09:00:00');
        $this->reply($replied, $post, '2026-10-15 18:00:00');
        // Replied, but after the 48 hours.
        $this->exposed($late, $post, 'notify', '2026-10-15 09:00:00');
        $this->reply($late, $post, '2026-10-18 09:00:00');
        $this->exposed($control, $post, 'control', '2026-10-15 09:00:00');
        // Too young to count yet.
        $this->exposed($young, $post, 'notify', '2026-10-20 09:00:00');

        $counts = $this->counts();

        $this->assertSame(2, $counts['arms']['notify']['starts']);
        $this->assertSame(1, $counts['arms']['notify']['completes']);
        $this->assertSame(1, $counts['arms']['control']['starts']);
        $this->assertSame(0, $counts['arms']['control']['completes']);
        $this->assertSame(['control' => 1, 'notify' => 3], $counts['extra']['exposed']);
    }

    public function test_a_reply_to_another_post_does_not_convert(): void
    {
        $post = $this->makePost();
        $other = $this->makePost();
        $member = $this->createTestUser();
        $this->exposed($member, $post, 'notify', '2026-10-15 09:00:00');
        $this->reply($member, $other, '2026-10-15 10:00:00');

        $this->assertSame(0, $this->counts()['arms']['notify']['completes']);
    }

    public function test_unsubscribing_from_any_mail_in_the_window_trips_the_guardrail(): void
    {
        $post = $this->makePost();
        $member = $this->createTestUser();
        $calm = $this->createTestUser();
        $this->exposed($member, $post, 'control', '2026-10-15 09:00:00');
        $this->exposed($calm, $post, 'control', '2026-10-15 09:00:00');
        DB::table('email_tracking')->insert([
            'email_type' => 'UnifiedDigestDaily', 'userid' => $member->id, 'recipient_email' => 'x@example.com',
            'sent_at' => '2026-10-15 07:00:00', 'unsubscribed_at' => '2026-10-16 07:00:00',
        ]);

        $guard = $this->counts()['guards']['unsubscribe']['control'];

        $this->assertSame(['bad' => 1, 'base' => 2], $guard);
    }

    public function test_it_counts_post_exposures_only_and_has_no_volume_history(): void
    {
        $this->assertSame(['post'], $this->source->types());
        $this->assertNull($this->source->volume($this->exp(), Carbon::now()));
        $this->assertArrayHasKey('unsubscribe', $this->source->guardrails($this->exp()));
    }
}
