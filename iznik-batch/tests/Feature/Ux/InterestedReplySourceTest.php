<?php

namespace Tests\Feature\Ux;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Group;
use App\Models\Message;
use App\Models\MessageInterested;
use App\Models\User;
use App\Services\Ux\Sources\InterestedReplySource;
use App\Services\Ux\UxExperimentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The experiment interested-button counted over real rows. The time is 12:00 on 21 October and the window is 168
 * hours, so a view is mature if it is at or before 12:00 on 14 October. The experiment started at midnight on the
 * 7th. A view is the exposure the Go API writes (subject = the post), and a reply is a chat message of type
 * Interested that names a post.
 *
 * A helper here must not be called post, get, put or delete: the base test case has public methods of those
 * names, and a clash is a fatal error that the status API reports as "0 of 0 tests".
 */
class InterestedReplySourceTest extends TestCase
{
    private const NAME = 'ib-source-test';

    private const ARMS = ['control' => [0, 49], 'button' => [50, 99]];

    private InterestedReplySource $source;

    private ?Group $group = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 12:00:00');
        $this->source = new InterestedReplySource;
        DB::table('ux_experiment_exposures')->where('experiment', 'like', 'ib-%')->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function now(): Carbon
    {
        return Carbon::parse('2026-10-21 12:00:00', 'UTC');
    }

    /** @param array<string, mixed> $design */
    private function exp(array $design = []): object
    {
        return (object) [
            'name' => self::NAME,
            'arms' => json_encode(self::ARMS),
            'design' => json_encode($design + ['channel' => 'server', 'source' => 'interested_reply', 'window_hours' => 168]),
            'started_at' => '2026-10-07 00:00:00',
        ];
    }

    private function makePost(?User $poster = null): Message
    {
        $this->group ??= $this->createTestGroup();

        return $this->createTestMessage($poster ?? $this->createTestUser(), $this->group);
    }

    /** A member's view of a post, as the Go API records it. */
    private function viewOf(User $member, Message $post, string $arm, string $at, ?string $experiment = null): void
    {
        DB::table('ux_experiment_exposures')->insert([
            'experiment' => $experiment ?? self::NAME, 'arm' => $arm, 'userid' => $member->id, 'subject_type' => 'view',
            'subject_id' => $post->id, 'stratum' => null, 'exposed_at' => $at, 'detail' => null,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function reply(User $member, Message $post, string $at, array $attributes = []): ChatMessage
    {
        $room = $this->createTestChatRoom($member, User::findOrFail($post->fromuser));

        return $this->createTestChatMessage($room, $member, $attributes + ['type' => 'Interested', 'refmsgid' => $post->id, 'date' => $at]);
    }

    private function mark(User $member, Message $post, string $added, ?string $chased = null): MessageInterested
    {
        return MessageInterested::create(['msgid' => $post->id, 'userid' => $member->id, 'added' => $added, 'chasedat' => $chased]);
    }

    /** @return array<string, mixed> */
    private function counts(array $design = [], ?Carbon $since = null): array
    {
        return $this->source->counts($this->exp($design), $this->now(), $since);
    }

    // ---------------------------------------------------------------- the cohort

    public function test_a_member_is_counted_once_on_their_first_view_when_it_is_a_window_old(): void
    {
        $post = $this->makePost();
        $replied = $this->createTestUser();
        $silent = $this->createTestUser();
        $control = $this->createTestUser();
        $young = $this->createTestUser();

        $this->viewOf($replied, $post, 'button', '2026-10-10 09:00:00');
        $this->reply($replied, $post, '2026-10-11 09:00:00');
        $this->viewOf($silent, $post, 'button', '2026-10-10 09:00:00');
        $this->viewOf($control, $post, 'control', '2026-10-10 09:00:00');
        // Immature: shown in exposed, not counted.
        $this->viewOf($young, $post, 'button', '2026-10-20 09:00:00');

        $counts = $this->counts();

        $this->assertSame(2, $counts['arms']['button']['starts']);
        $this->assertSame(1, $counts['arms']['button']['completes']);
        $this->assertSame(1, $counts['arms']['control']['starts']);
        $this->assertSame(0, $counts['arms']['control']['completes']);
        $this->assertSame(['control' => 1, 'button' => 3], $counts['extra']['exposed']);
        $this->assertSame(['control' => 1, 'button' => 2], $counts['extra']['counted']);
    }

    public function test_only_the_first_view_counts_and_a_later_one_does_not_replace_an_immature_first(): void
    {
        $postA = $this->makePost();
        $postB = $this->makePost();
        $twice = $this->createTestUser();
        $this->viewOf($twice, $postA, 'button', '2026-10-10 09:00:00');
        $this->viewOf($twice, $postB, 'button', '2026-10-12 09:00:00');

        $this->assertSame(1, $this->counts()['arms']['button']['starts']);

        // The first view is too young, so the member is not counted at all, whatever the later one is.
        $late = $this->createTestUser();
        $this->viewOf($late, $postA, 'control', '2026-10-20 09:00:00');
        $this->viewOf($late, $postB, 'control', '2026-10-01 09:00:00', 'ib-other');

        $this->assertSame(0, $this->counts()['arms']['control']['starts']);
    }

    public function test_a_view_from_before_the_start_of_the_run_is_not_counted(): void
    {
        $post = $this->makePost();
        $member = $this->createTestUser();
        $this->viewOf($member, $post, 'button', '2026-10-06 23:59:59');

        $this->assertSame(0, $this->counts()['arms']['button']['starts']);
    }

    // ---------------------------------------------------------------- converted

    public function test_a_reply_converts_inside_the_window_and_the_edges_are_exact(): void
    {
        $post = $this->makePost();
        $cases = [
            'at_view' => ['2026-10-07 10:00:00', true],
            'one_second_in' => ['2026-10-14 09:59:59', true],
            'a_window_later' => ['2026-10-14 10:00:00', false],
            'before_view' => ['2026-10-07 09:59:59', false],
        ];
        $members = [];
        foreach ($cases as $name => [$repliedAt]) {
            $members[$name] = $this->createTestUser();
            $this->viewOf($members[$name], $post, 'button', '2026-10-07 10:00:00');
            $this->reply($members[$name], $post, $repliedAt);
        }

        $counts = $this->counts();

        $this->assertSame(4, $counts['arms']['button']['starts']);
        $this->assertSame(2, $counts['arms']['button']['completes']);
    }

    public function test_a_reply_to_any_post_converts(): void
    {
        $viewed = $this->makePost();
        $other = $this->makePost();
        $member = $this->createTestUser();
        $this->viewOf($member, $viewed, 'button', '2026-10-08 10:00:00');
        $this->reply($member, $other, '2026-10-09 10:00:00');

        $this->assertSame(1, $this->counts()['arms']['button']['completes']);
    }

    public function test_only_a_reply_that_names_a_post_converts(): void
    {
        $post = $this->makePost();
        $noPost = $this->createTestUser();
        $chat = $this->createTestUser();
        $someoneElse = $this->createTestUser();
        foreach ([$noPost, $chat, $someoneElse] as $member) {
            $this->viewOf($member, $post, 'button', '2026-10-08 10:00:00');
        }
        $this->reply($noPost, $post, '2026-10-09 10:00:00', ['refmsgid' => null]);
        $this->reply($chat, $post, '2026-10-09 10:00:00', ['type' => 'Default']);
        // Another member's reply is not this member's.
        $this->reply($this->createTestUser(), $post, '2026-10-09 10:00:00');

        $counts = $this->counts();

        $this->assertSame(3, $counts['arms']['button']['starts']);
        $this->assertSame(0, $counts['arms']['button']['completes']);
    }

    // ---------------------------------------------------------------- guardrail fewer_handovers

    public function test_the_guardrail_counts_members_not_promised_or_taking_within_the_window(): void
    {
        $post = $this->makePost();
        $promised = $this->createTestUser();
        $taking = $this->createTestUser();
        $none = $this->createTestUser();
        $tooLate = $this->createTestUser();
        $before = $this->createTestUser();
        foreach ([$promised, $taking, $none, $tooLate, $before] as $member) {
            $this->viewOf($member, $post, 'button', '2026-10-07 10:00:00');
        }
        DB::table('messages_promises')->insert(['msgid' => $post->id, 'userid' => $promised->id, 'promisedat' => '2026-10-08 10:00:00']);
        DB::table('messages_by')->insert(['msgid' => $post->id, 'userid' => $taking->id, 'timestamp' => '2026-10-14 09:59:59', 'count' => 1]);
        DB::table('messages_promises')->insert(['msgid' => $post->id, 'userid' => $tooLate->id, 'promisedat' => '2026-10-14 10:00:00']);
        DB::table('messages_promises')->insert(['msgid' => $post->id, 'userid' => $before->id, 'promisedat' => '2026-10-07 09:00:00']);

        $guard = $this->counts()['guards']['fewer_handovers']['button'];

        $this->assertSame(5, $guard['base']);
        $this->assertSame(3, $guard['bad']);
    }

    public function test_a_guardrail_exists_with_a_two_point_margin_and_plain_label(): void
    {
        $guards = $this->source->guardrails($this->exp());

        $this->assertSame(['fewer_handovers'], array_keys($guards));
        $this->assertSame(2.0, $guards['fewer_handovers']['margin_points']);
        $this->assertStringContainsString('not promised', $guards['fewer_handovers']['label']);
    }

    // ---------------------------------------------------------------- extra()

    public function test_extra_counts_marks_replies_and_chase_ups(): void
    {
        $post = $this->makePost();
        $otherPost = $this->makePost();
        $a = $this->createTestUser();
        $b = $this->createTestUser();
        $c = $this->createTestUser();
        $d = $this->createTestUser();

        // a: replied before the chase-up named the mark.
        $this->mark($a, $post, '2026-10-15 09:00:00', '2026-10-16 09:30:00');
        $this->reply($a, $post, '2026-10-15 20:00:00');
        // b: replied after the chase-up.
        $this->mark($b, $post, '2026-10-15 09:00:00', '2026-10-16 09:30:00');
        $this->reply($b, $post, '2026-10-17 08:00:00');
        // c: replied, never chased (so before it).
        $this->mark($c, $post, '2026-10-18 09:00:00');
        $this->reply($c, $post, '2026-10-18 09:05:00');
        // d: two marks, chased, no reply to either; a reply to a different post does not count; a reply from before the mark does not.
        $this->mark($d, $post, '2026-10-15 09:00:00', '2026-10-16 09:30:00');
        $this->mark($d, $otherPost, '2026-10-15 09:00:00', '2026-10-16 09:30:00');
        $this->reply($d, $this->makePost(), '2026-10-17 08:00:00');
        $this->reply($d, $post, '2026-10-14 08:00:00');
        // A mark from before the run is left out.
        $this->mark($this->createTestUser(), $post, '2026-10-06 09:00:00');

        $interested = $this->counts()['extra']['interested'];

        $this->assertSame([
            'marks' => 5,
            'members' => 4,
            'replied' => 3,
            'replied_before_chase' => 2,
            'replied_after_chase' => 1,
            'chased' => 4,
        ], $interested);
    }

    public function test_extra_is_zero_with_no_marks_and_respects_since(): void
    {
        $this->assertSame(
            ['marks' => 0, 'members' => 0, 'replied' => 0, 'replied_before_chase' => 0, 'replied_after_chase' => 0, 'chased' => 0],
            $this->counts()['extra']['interested']
        );

        $post = $this->makePost();
        $this->mark($this->createTestUser(), $post, '2026-10-10 09:00:00');
        $this->mark($this->createTestUser(), $post, '2026-10-19 09:00:00');

        $this->assertSame(2, $this->counts()['extra']['interested']['marks']);
        $this->assertSame(1, $this->counts([], Carbon::parse('2026-10-18 00:00:00', 'UTC'))['extra']['interested']['marks']);
    }

    // ---------------------------------------------------------------- the source's words and registration

    public function test_words_explain_the_count_in_plain_english(): void
    {
        $words = $this->source->words($this->exp());

        $this->assertSame('members', $words['who']);
        $this->assertSame('Replied within 7 days', $words['tab_finished']);
        $this->assertStringContainsString('counted once', $words['note']);
        $this->assertStringContainsString('at least 7 days ago', $words['note']);
        $this->assertSame(['view'], $this->source->types());
    }

    public function test_volume_has_nothing_to_size_from(): void
    {
        $this->assertNull($this->source->volume($this->exp(), $this->now()));
    }

    public function test_the_source_is_registered_and_offers_no_primary(): void
    {
        $this->assertSame(InterestedReplySource::class, config('freegle.ux.sources.interested_reply'));
        $this->assertFalse(method_exists(InterestedReplySource::class, 'primaries'));

        $design = ['channel' => 'server', 'source' => 'interested_reply', 'window_hours' => 168];
        $this->assertSame('interested_reply', UxExperimentService::normaliseDesign($design, 'reply')['source']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not offered by the source interested_reply');
        UxExperimentService::normaliseDesign($design + ['primary' => 'handover'], 'reply');
    }
}
