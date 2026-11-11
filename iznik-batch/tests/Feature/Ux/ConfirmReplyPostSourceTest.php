<?php

namespace Tests\Feature\Ux;

use App\Models\ChatMessage;
use App\Models\Message;
use App\Models\User;
use App\Services\Ux\Sources\ConfirmReplyPostSource;
use App\Services\Ux\UxExperimentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The experiment confirm-reply-post counted over real rows. The time is 12:00 on 21 October and the window is 168
 * hours, so a poster's first exposure is mature if it is at or before 12:00 on 14 October. The run started at midnight
 * on the 7th. An exposure is the poster and their post, as chat/replyconfirm.go writes it (subject "post").
 *
 * A helper here must not be called post, get, put or delete: the base test case has public methods of those names.
 */
class ConfirmReplyPostSourceTest extends TestCase
{
    private const NAME = 'crp-source-test';

    private const ARMS = ['control' => [0, 49], 'ask' => [50, 99]];

    private ConfirmReplyPostSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 12:00:00');
        $this->source = new ConfirmReplyPostSource;
        DB::table('ux_experiment_exposures')->where('experiment', 'like', 'crp-%')->delete();
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
            'design' => json_encode($design + ['channel' => 'server', 'source' => 'confirm_reply_post', 'window_hours' => 168]),
            'started_at' => '2026-10-07 00:00:00',
        ];
    }

    private function makeOffer(?User $poster = null): Message
    {
        return $this->createTestMessage($poster ?? $this->createTestUser());
    }

    /** The poster's exposure for a post, as the Go API records it. */
    private function exposed(Message $offer, string $arm, string $at, string $stratum = 'car'): void
    {
        DB::table('ux_experiment_exposures')->insert([
            'experiment' => self::NAME, 'arm' => $arm, 'userid' => $offer->fromuser, 'subject_type' => 'post',
            'subject_id' => $offer->id, 'stratum' => $stratum, 'exposed_at' => $at, 'detail' => null,
        ]);
    }

    /** A reply the poster was given: processed and delivered. */
    private function passedOn(User $replier, Message $offer, string $at): ChatMessage
    {
        return $this->replyTo($replier, $offer, $at, ['processingrequired' => 0, 'processingsuccessful' => 1]);
    }

    /** A reply the question dropped before the poster saw it. */
    private function dropped(User $replier, Message $offer, string $at, string $reason = ChatMessage::PROCESSFAIL_NOT_CONFIRMED): ChatMessage
    {
        return $this->replyTo($replier, $offer, $at, ['processingrequired' => 0, 'processingsuccessful' => 0, 'processingfailreason' => $reason]);
    }

    /** @param array<string, mixed> $attributes */
    private function replyTo(User $replier, Message $offer, string $at, array $attributes): ChatMessage
    {
        $room = $this->createTestChatRoom($replier, User::findOrFail($offer->fromuser));

        return $this->createTestChatMessage($room, $replier, $attributes + ['type' => 'Interested', 'refmsgid' => $offer->id, 'date' => $at]);
    }

    private function promise(Message $offer, User $to, string $at): void
    {
        DB::table('messages_promises')->insert(['msgid' => $offer->id, 'userid' => $to->id, 'promisedat' => $at]);
    }

    private function taken(Message $offer, string $at): void
    {
        DB::table('messages_outcomes')->insert(['msgid' => $offer->id, 'outcome' => 'Taken', 'timestamp' => $at]);
    }

    /** @return array<string, mixed> */
    private function counts(?Carbon $since = null): array
    {
        return $this->source->counts($this->exp(), $this->now(), $since);
    }

    // ---------------------------------------------------------------- the cohort

    public function test_a_poster_is_counted_once_on_their_first_eligible_post_when_it_is_a_window_old(): void
    {
        $poster = $this->createTestUser();
        $first = $this->makeOffer($poster);
        $second = $this->makeOffer($poster);
        $this->exposed($first, 'ask', '2026-10-08 10:00:00');
        $this->exposed($second, 'ask', '2026-10-09 10:00:00');

        // Another poster's first post is not a window old yet: exposed, not counted.
        $young = $this->makeOffer();
        $this->exposed($young, 'control', '2026-10-16 10:00:00');

        $c = $this->counts();

        $this->assertSame(1, $c['arms']['ask']['starts'], 'one poster, however many posts');
        $this->assertSame(0, $c['arms']['control']['starts']);
        $this->assertSame(['control' => 1, 'ask' => 1], $c['extra']['exposed']);
        $this->assertSame(['control' => 0, 'ask' => 1], $c['extra']['counted']);
        $this->assertArrayHasKey('car', $c['arms']['ask']['strata']);
    }

    // ---------------------------------------------------------------- the primary

    public function test_a_poster_converts_when_every_reply_they_saw_was_promised_or_taken(): void
    {
        $promised = $this->makeOffer();
        $this->exposed($promised, 'ask', '2026-10-08 10:00:00');
        $taker = $this->createTestUser();
        $this->passedOn($taker, $promised, '2026-10-08 10:00:00');
        $this->promise($promised, $taker, '2026-10-09 10:00:00');

        $named = $this->makeOffer();
        $this->exposed($named, 'ask', '2026-10-08 11:00:00');
        $collector = $this->createTestUser();
        $this->passedOn($collector, $named, '2026-10-08 11:00:00');
        DB::table('messages_by')->insert(['msgid' => $named->id, 'userid' => $collector->id, 'timestamp' => '2026-10-10 10:00:00', 'count' => 1]);

        $c = $this->counts();

        $this->assertSame(2, $c['arms']['ask']['starts']);
        $this->assertSame(2, $c['arms']['ask']['completes']);
    }

    public function test_a_reply_the_poster_saw_that_came_to_nothing_is_a_dead_reply(): void
    {
        // Two repliers passed on; one was promised, the other came to nothing.
        $offer = $this->makeOffer();
        $this->exposed($offer, 'control', '2026-10-08 10:00:00');
        $taker = $this->createTestUser();
        $this->passedOn($taker, $offer, '2026-10-08 10:00:00');
        $this->passedOn($this->createTestUser(), $offer, '2026-10-08 12:00:00');
        $this->promise($offer, $taker, '2026-10-09 10:00:00');

        // Promised only after the window: dead within it.
        $late = $this->makeOffer();
        $this->exposed($late, 'control', '2026-10-08 10:00:00');
        $slow = $this->createTestUser();
        $this->passedOn($slow, $late, '2026-10-08 10:00:00');
        $this->promise($late, $slow, '2026-10-15 10:00:00');

        $c = $this->counts();

        $this->assertSame(2, $c['arms']['control']['starts']);
        $this->assertSame(0, $c['arms']['control']['completes']);
    }

    public function test_a_reply_dropped_before_the_poster_saw_it_is_not_a_dead_reply(): void
    {
        $offer = $this->makeOffer();
        $this->exposed($offer, 'ask', '2026-10-08 10:00:00');
        $this->dropped($this->createTestUser(), $offer, '2026-10-08 10:00:00');
        $this->dropped($this->createTestUser(), $offer, '2026-10-08 11:00:00', ChatMessage::PROCESSFAIL_REPLY_WITHDRAWN);

        $c = $this->counts();

        $this->assertSame(1, $c['arms']['ask']['completes'], 'no reply was passed on, so none was dead');
        $this->assertSame(['n' => 1, 'base' => 1], $c['extra']['information']['nothing_passed_on']['arms']['ask'], 'which is shown for information');
    }

    public function test_the_posters_own_message_is_not_a_reply(): void
    {
        $poster = $this->createTestUser();
        $offer = $this->makeOffer($poster);
        $this->exposed($offer, 'ask', '2026-10-08 10:00:00');
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($replier, $poster);
        $this->createTestChatMessage($room, $poster, ['type' => 'Interested', 'refmsgid' => $offer->id, 'date' => '2026-10-08 10:00:00',
            'processingrequired' => 0, 'processingsuccessful' => 1]);

        $this->assertSame(1, $this->counts()['arms']['ask']['completes']);
    }

    // ---------------------------------------------------------------- the guardrails

    public function test_not_taken_counts_posts_not_taken_within_the_window_of_the_first_exposure(): void
    {
        $inTime = $this->makeOffer();
        $this->exposed($inTime, 'control', '2026-10-08 10:00:00');
        $this->taken($inTime, '2026-10-15 09:59:59');

        $tooLate = $this->makeOffer();
        $this->exposed($tooLate, 'control', '2026-10-08 10:00:00');
        $this->taken($tooLate, '2026-10-15 10:00:00');

        $never = $this->makeOffer();
        $this->exposed($never, 'control', '2026-10-08 10:00:00');

        $c = $this->counts();

        $this->assertSame(2, $c['guards']['not_taken']['control']['bad']);
        $this->assertSame(3, $c['guards']['not_taken']['control']['base']);
    }

    public function test_nothing_passed_on_is_shown_for_information_and_is_never_a_guardrail(): void
    {
        $given = $this->makeOffer();
        $this->exposed($given, 'ask', '2026-10-08 10:00:00');
        $this->passedOn($this->createTestUser(), $given, '2026-10-08 10:00:00');

        $held = $this->makeOffer();
        $this->exposed($held, 'ask', '2026-10-08 10:00:00');
        $this->replyTo($this->createTestUser(), $held, '2026-10-08 10:00:00', ['processingrequired' => 1, 'processingsuccessful' => 0]);

        $c = $this->counts();

        $info = $c['extra']['information']['nothing_passed_on'];
        $this->assertSame('the share of posters who had no reply passed on within 7 days', $info['label']);
        $this->assertSame(['n' => 1, 'base' => 2], $info['arms']['ask']);
        $this->assertSame(['n' => 0, 'base' => 0], $info['arms']['control']);
        $this->assertArrayNotHasKey('nothing_passed_on', $c['guards'], 'the engine never sees it, so it cannot revert the run');
    }

    public function test_not_taken_is_the_only_guardrail_with_a_five_point_margin(): void
    {
        $g = $this->source->guardrails($this->exp());

        $this->assertSame(['not_taken'], array_keys($g));
        $this->assertSame(5.0, $g['not_taken']['margin_points']);
        $this->assertSame('the share of posts not taken within 7 days', $g['not_taken']['label']);
    }

    public function test_words_say_what_a_dead_reply_is_and_name_the_guardrails(): void
    {
        $w = $this->source->words($this->exp());

        $this->assertSame('posters', $w['who']);
        $this->assertSame('saw no dead reply within 7 days', $w['finished']);
        $this->assertSame('No dead reply within 7 days', $w['tab_finished']);
        $this->assertStringContainsString('counted once', $w['note']);
        $this->assertStringContainsString('A dead reply is one passed on to the poster', $w['note']);
        $this->assertStringContainsString('dropped before the poster saw it', $w['note']);
        $this->assertStringContainsString('not taken within 7 days', $w['note']);
        $this->assertStringContainsString('no reply passed on', $w['note']);
        $this->assertStringContainsString('for information only', $w['note']);
    }

    public function test_the_source_is_registered_and_offers_one_primary(): void
    {
        $this->assertSame(ConfirmReplyPostSource::class, config('freegle.ux.sources.confirm_reply_post'));
        $this->assertSame(['no_dead_reply'], ConfirmReplyPostSource::primaries());

        $design = ['channel' => 'server', 'source' => 'confirm_reply_post', 'window_hours' => 168];
        $this->assertSame('no_dead_reply', UxExperimentService::normaliseDesign($design + ['primary' => 'no_dead_reply'], 'reply')['primary']);

        $this->expectException(\InvalidArgumentException::class);
        UxExperimentService::normaliseDesign($design + ['primary' => 'handover'], 'reply');
    }

    // ---------------------------------------------------------------- volume

    /** An offer the run would ask about: large, no delivery, classified before its replies. */
    private function eligibleOffer(): Message
    {
        $offer = $this->makeOffer();
        DB::table('messages')->where('id', $offer->id)->update(['type' => 'Offer', 'deliverypossible' => 0]);
        DB::table('messages_eee')->insert(['msgid' => $offer->id, 'is_eee' => 0, 'item_condition' => 'reusable', 'size_bucket' => 'large',
            'weight_bucket' => '5_20kg', 'model' => 'test', 'prompt_version' => 'v1', 'classified_at' => '2026-10-10 09:00:00']);

        return $offer;
    }

    public function test_volume_sizes_a_run_from_eligible_offers_and_their_posters(): void
    {
        // Two eligible offers from two posters, with replies inside the intake that ended a window ago. One poster saw
        // a single reply and promised it: no dead reply. The other saw two and promised one: a dead reply.
        $clean = $this->eligibleOffer();
        $taker = $this->createTestUser();
        $this->passedOn($taker, $clean, '2026-10-10 10:00:00');
        $this->promise($clean, $taker, '2026-10-11 10:00:00');

        $dead = $this->eligibleOffer();
        $other = $this->createTestUser();
        $this->passedOn($other, $dead, '2026-10-10 10:00:00');
        $this->passedOn($this->createTestUser(), $dead, '2026-10-10 11:00:00');
        $this->promise($dead, $other, '2026-10-11 10:00:00');

        // A large offer the poster may deliver is not one the run asks about.
        $delivers = $this->eligibleOffer();
        DB::table('messages')->where('id', $delivers->id)->update(['deliverypossible' => 1]);
        $this->passedOn($this->createTestUser(), $delivers, '2026-10-10 10:00:00');

        $v = $this->source->volume($this->exp(['intake_hours' => 168]), $this->now());

        $this->assertNotNull($v);
        $this->assertSame(1, $v['per_arm'], 'two posters, one on each arm');
        $this->assertSame(0.5, $v['rate'], 'one of the two posts had no dead reply');
    }

    public function test_volume_is_null_with_too_little_to_go_on(): void
    {
        $this->assertNull($this->source->volume($this->exp(['intake_hours' => 168]), $this->now()));
    }
}
