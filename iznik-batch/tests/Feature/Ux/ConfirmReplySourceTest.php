<?php

namespace Tests\Feature\Ux;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use App\Services\Ux\Sources\ConfirmReplySource;
use App\Services\Ux\UxExperimentService;
use App\Support\ExperimentBucket;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The experiment confirm-reply counted over real rows. The time is 12:00 on 21 October and the window is 168
 * hours, so a reply is mature if it is at or before 12:00 on 14 October. The experiment started at midnight on
 * the 7th. Replies are seeded as the Go API leaves them: a chat message of type Interested that names the post,
 * and the exposure row that names the chat message.
 *
 * A helper here must not be called post, get, put or delete: the base test case has public methods of those
 * names, and a clash is a fatal error that the status API reports as "0 of 0 tests".
 */
class ConfirmReplySourceTest extends TestCase
{
    private const NAME = 'cr-source-test';

    private const ARMS = ['control' => [0, 49], 'ask' => [50, 99]];

    private ConfirmReplySource $source;

    private ?Group $group = null;

    /** @var array<string, ChatRoom> */
    private array $rooms = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 12:00:00');
        $this->source = new ConfirmReplySource;
        DB::table('ux_experiment_exposures')->where('experiment', 'like', 'cr-%')->delete();
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
    private function exp(array $design = [], string $started = '2026-10-07 00:00:00'): object
    {
        return (object) [
            'name' => self::NAME,
            'arms' => json_encode(self::ARMS),
            'design' => json_encode($design + ['channel' => 'server', 'source' => 'confirm_reply', 'window_hours' => 168]),
            'started_at' => $started,
        ];
    }

    private function group(): Group
    {
        return $this->group ??= $this->createTestGroup();
    }

    private function makePost(?User $poster = null, array $attributes = []): Message
    {
        $poster ??= $this->createTestUser();

        return $this->createTestMessage($poster, $this->group(), $attributes);
    }

    private function room(User $replier, int $posterId): ChatRoom
    {
        $key = $replier->id.'-'.$posterId;

        return $this->rooms[$key] ??= $this->createTestChatRoom($replier, User::findOrFail($posterId));
    }

    /**
     * A replier's reply to a post and the exposure the Go API writes for it, at $at.
     *
     * @param  array<string, mixed>  $reply  columns of the chat message, over a delivered Interested reply
     * @return array{0: User, 1: Message, 2: ChatMessage, 3: int} the replier, the post, the reply and the exposure id
     */
    private function makeReply(string $arm, string $at, array $reply = [], ?string $stratum = 'damaged', ?User $replier = null, ?Message $post = null, string $experiment = self::NAME): array
    {
        $replier ??= $this->createTestUser();
        $post ??= $this->makePost();
        $message = $this->createTestChatMessage($this->room($replier, $post->fromuser), $replier, $reply + [
            'type' => 'Interested', 'refmsgid' => $post->id, 'date' => $at,
            'processingrequired' => 0, 'processingsuccessful' => 1, 'platform' => 1,
        ]);
        $exposure = $this->makeExposure($replier, $arm, $message->id, $at, $stratum, $experiment);

        return [$replier, $post, $message, $exposure];
    }

    private function makeExposure(User $replier, string $arm, int $subjectId, string $at, ?string $stratum = 'damaged', string $experiment = self::NAME, string $type = 'reply'): int
    {
        return DB::table('ux_experiment_exposures')->insertGetId([
            'experiment' => $experiment, 'arm' => $arm, 'userid' => $replier->id, 'subject_type' => $type, 'subject_id' => $subjectId,
            'stratum' => $stratum, 'exposed_at' => $at, 'detail' => null,
        ]);
    }

    private function promise(Message $post, User $user, string $at): void
    {
        DB::table('messages_promises')->insert(['msgid' => $post->id, 'userid' => $user->id, 'promisedat' => $at]);
    }

    private function markTaken(Message $post, ?User $user, string $at): void
    {
        DB::table('messages_by')->insert(['msgid' => $post->id, 'userid' => $user?->id, 'timestamp' => $at, 'count' => 1]);
    }

    /**
     * The question the Go API asks, and the prompt row that holds the reply.
     *
     * @param  array<string, mixed>  $columns  columns of chat_prompts
     */
    private function makeQuestion(ChatMessage $reply, User $replier, Message $post, string $asked, array $columns = []): ChatMessage
    {
        $freegle = $this->createTestUser();
        $question = $this->createTestChatMessage($this->room($replier, $post->fromuser), $freegle, [
            'type' => 'Prompt', 'refmsgid' => $post->id, 'date' => $asked, 'message' => '@Test, before we pass your reply on: Do you still want it?',
            'processingrequired' => 0, 'processingsuccessful' => 0,
        ]);
        DB::table('chat_prompts')->insert($columns + [
            'chatmsgid' => $question->id, 'msgid' => $post->id, 'msgids' => json_encode([$post->id]), 'userid' => $replier->id,
            'heldchatmsgid' => $reply->id, 'kind' => 'confirm_reply', 'options' => json_encode([['value' => 'yes'], ['value' => 'no']]),
            'created_at' => $asked, 'expires_at' => Carbon::parse($asked, 'UTC')->addDays(3)->format('Y-m-d H:i:s'),
        ]);

        return $question;
    }

    /** A member found by trying new members until the experiment's own split puts them in the arm. */
    private function memberInArm(string $arm): User
    {
        for ($i = 0; $i < 60; $i++) {
            $user = $this->createTestUser();
            if (ExperimentBucket::armIn($user->id, 'confirm-reply', self::ARMS) === $arm) {
                return $user;
            }
        }
        $this->fail("no member in the arm $arm within 60 tries");
    }

    private function counts(?Carbon $since = null, array $design = []): array
    {
        return $this->source->counts($this->exp($design), $this->now(), $since);
    }

    /** @return array<string, array<string, array<string, int>>> */
    private function reasons(array $design = [], ?Carbon $since = null): array
    {
        return $this->counts($since, $design)['extra']['reasons'];
    }

    // ---------------------------------------------------------------- the measure: a handover within the window

    public function test_a_handover_inside_the_window_converts_and_the_edges_are_exact(): void
    {
        // A strata name stands for each case, so the result says which case converted.
        $cases = [
            'at_exposure' => ['2026-10-07 10:00:00', true],
            'one_second_in' => ['2026-10-14 09:59:59', true],
            'a_window_later' => ['2026-10-14 10:00:00', false],
            'before_exposure' => ['2026-10-07 09:59:59', false],
        ];
        foreach ($cases as $stratum => [$promised]) {
            [$replier, $post] = $this->makeReply('ask', '2026-10-07 10:00:00', [], $stratum);
            $this->promise($post, $replier, $promised);
        }

        $strata = $this->counts(null, ['primary' => 'handover'])['arms']['ask']['strata'];

        foreach ($cases as $stratum => [$promised, $converts]) {
            $this->assertSame(1, $strata[$stratum]['starts'], $stratum);
            $this->assertSame($converts ? 1 : 0, $strata[$stratum]['completes'], "$stratum: promised $promised");
        }
    }

    public function test_a_messages_by_row_converts_the_same_way(): void
    {
        $cases = [
            'at_exposure' => ['2026-10-07 10:00:00', true],
            'one_second_in' => ['2026-10-14 09:59:59', true],
            'a_window_later' => ['2026-10-14 10:00:00', false],
            'before_exposure' => ['2026-10-07 09:59:59', false],
        ];
        foreach ($cases as $stratum => [$taken]) {
            [$replier, $post] = $this->makeReply('ask', '2026-10-07 10:00:00', [], $stratum);
            $this->markTaken($post, $replier, $taken);
        }
        // The poster marked the item taken without naming anyone, or named someone else.
        [, $post] = $this->makeReply('ask', '2026-10-07 10:00:00', [], 'nobody_named');
        $this->markTaken($post, null, '2026-10-07 11:00:00');
        [, $post] = $this->makeReply('ask', '2026-10-07 10:00:00', [], 'someone_else');
        $this->markTaken($post, $this->createTestUser(), '2026-10-07 11:00:00');

        $strata = $this->counts(null, ['primary' => 'handover'])['arms']['ask']['strata'];

        foreach ($cases as $stratum => [$taken, $converts]) {
            $this->assertSame($converts ? 1 : 0, $strata[$stratum]['completes'], "$stratum: taken $taken");
        }
        $this->assertSame(0, $strata['nobody_named']['completes'], 'a row with no member is nobody');
        $this->assertSame(0, $strata['someone_else']['completes']);
    }

    public function test_a_promise_to_someone_else_or_on_another_post_does_not_convert(): void
    {
        [, $post] = $this->makeReply('ask', '2026-10-07 10:00:00', [], 'someone_else');
        $this->promise($post, $this->createTestUser(), '2026-10-07 11:00:00');
        [$replier] = $this->makeReply('ask', '2026-10-07 10:00:00', [], 'another_post');
        $this->promise($this->makePost(), $replier, '2026-10-07 11:00:00');
        [$replier, $post] = $this->makeReply('ask', '2026-10-07 10:00:00', [], 'theirs');
        $this->promise($post, $replier, '2026-10-07 11:00:00');

        $strata = $this->counts(null, ['primary' => 'handover'])['arms']['ask']['strata'];

        $this->assertSame(0, $strata['someone_else']['completes']);
        $this->assertSame(0, $strata['another_post']['completes']);
        $this->assertSame(1, $strata['theirs']['completes']);
    }

    // ---------------------------------------------------------------- who is counted

    public function test_a_reply_exactly_a_window_old_is_counted_and_a_second_younger_is_not(): void
    {
        $this->makeReply('control', '2026-10-14 12:00:00', [], 'exactly');
        $this->makeReply('control', '2026-10-14 12:00:01', [], 'a_second_young');

        $c = $this->counts();

        $this->assertSame(1, $c['arms']['control']['starts']);
        $this->assertArrayHasKey('exactly', $c['arms']['control']['strata']);
        $this->assertArrayNotHasKey('a_second_young', $c['arms']['control']['strata']);
        $this->assertSame(['control' => 2, 'ask' => 0], $c['extra']['exposed'], 'both are in the progress count');
    }

    public function test_each_replier_is_counted_once_on_their_first_exposure(): void
    {
        $asked = $this->memberInArm('ask');
        $control = $this->memberInArm('control');
        // The asked replier replied twice: the first reply was not handed over, the second was.
        $this->makeReply('ask', '2026-10-07 10:00:00', [], 'first', $asked);
        [, $post] = $this->makeReply('ask', '2026-10-08 10:00:00', [], 'second', $asked);
        $this->promise($post, $asked, '2026-10-08 11:00:00');
        [, $post] = $this->makeReply('control', '2026-10-07 10:00:00', [], 'first', $control);
        $this->promise($post, $control, '2026-10-07 11:00:00');

        $c = $this->counts(null, ['primary' => 'handover']);

        $this->assertSame(1, $c['arms']['ask']['starts']);
        $this->assertSame(0, $c['arms']['ask']['completes'], 'counted on the first reply, which was not handed over');
        $this->assertSame(1, $c['arms']['control']['starts']);
        $this->assertSame(1, $c['arms']['control']['completes']);
    }

    public function test_a_young_first_exposure_is_not_replaced_by_a_later_one(): void
    {
        $replier = $this->createTestUser();
        $this->makeReply('ask', '2026-10-20 10:00:00', [], 'young', $replier);
        // Written later, so it has the higher id, but it carries an older time (two writers whose clocks disagree).
        $this->makeReply('ask', '2026-10-10 10:00:00', [], 'older', $replier);

        $c = $this->counts();

        $this->assertSame(0, $c['arms']['ask']['starts'], 'the first exposure is young, so the replier is not counted yet');
        $this->assertSame(['control' => 0, 'ask' => 1], $c['extra']['exposed']);
        $this->assertSame(['control' => 0, 'ask' => 0], $c['extra']['counted']);
    }

    public function test_the_reasons_are_the_strata(): void
    {
        $this->makeReply('ask', '2026-10-07 10:00:00', [], 'damaged');
        $this->makeReply('ask', '2026-10-07 10:00:00', [], 'car');
        $this->makeReply('ask', '2026-10-07 10:00:00', [], 'both');
        $this->makeReply('control', '2026-10-07 10:00:00', [], 'car');

        $c = $this->counts();

        $this->assertEqualsCanonicalizing(['damaged', 'car', 'both'], array_keys($c['arms']['ask']['strata']));
        $this->assertSame(['car'], array_keys($c['arms']['control']['strata']));
        $this->assertSame(['starts' => 1, 'completes' => 0], $c['arms']['ask']['strata']['both']);
    }

    // ---------------------------------------------------------------- the two primaries

    public function test_never_delivered_counts_withdrawn_and_unconfirmed_replies_only(): void
    {
        $this->makeReply('ask', '2026-10-07 10:00:00', ['processingsuccessful' => 0, 'processingfailreason' => ChatMessage::PROCESSFAIL_REPLY_WITHDRAWN]);
        $this->makeReply('ask', '2026-10-07 10:00:00', ['processingsuccessful' => 0, 'processingfailreason' => ChatMessage::PROCESSFAIL_NOT_CONFIRMED]);
        $this->makeReply('ask', '2026-10-07 10:00:00', ['processingsuccessful' => 0, 'processingfailreason' => ChatMessage::PROCESSFAIL_SPAMMER]);
        $this->makeReply('ask', '2026-10-07 10:00:00', ['processingsuccessful' => 0, 'processingfailreason' => null]);
        $this->makeReply('ask', '2026-10-07 10:00:00');
        $this->makeReply('control', '2026-10-07 10:00:00');

        $guards = $this->counts(null, ['primary' => 'handover'])['guards'];

        $this->assertSame(['bad' => 2, 'base' => 5], $guards['never_delivered']['ask'], 'its base is the repliers counted');
        $this->assertSame(['bad' => 0, 'base' => 1], $guards['never_delivered']['control']);
        $this->assertArrayNotHasKey('fewer_handovers', $guards);
    }

    public function test_no_wasted_reply_counts_a_handover_or_a_reply_that_never_reached_the_poster(): void
    {
        $this->makeReply('ask', '2026-10-07 10:00:00', ['processingrequired' => 0, 'processingsuccessful' => 0], 'refused');
        $this->makeReply('ask', '2026-10-07 10:00:00', ['processingrequired' => 1, 'processingsuccessful' => 0], 'waiting');
        $this->makeReply('ask', '2026-10-07 10:00:00', [], 'delivered');
        [$replier, $post] = $this->makeReply('ask', '2026-10-07 10:00:00', [], 'handed_over');
        $this->promise($post, $replier, '2026-10-07 12:00:00');

        $c = $this->counts(null, ['primary' => 'no_wasted_reply']);

        $strata = $c['arms']['ask']['strata'];
        $this->assertSame(1, $strata['refused']['completes'], 'processed and refused: the poster never had it');
        $this->assertSame(0, $strata['waiting']['completes'], 'still waiting is not "never reached"');
        $this->assertSame(0, $strata['delivered']['completes'], 'delivered, and nobody was handed anything');
        $this->assertSame(1, $strata['handed_over']['completes']);
        $this->assertSame(4, $c['arms']['ask']['starts']);
    }

    public function test_no_wasted_reply_scores_a_no_but_not_a_question_nobody_answered_nor_a_post_that_went(): void
    {
        $gone = ['processingrequired' => 0, 'processingsuccessful' => 0];
        $this->makeReply('ask', '2026-10-07 10:00:00', $gone + ['processingfailreason' => ChatMessage::PROCESSFAIL_REPLY_WITHDRAWN], 'said_no');
        $this->makeReply('ask', '2026-10-07 10:00:00', $gone + ['processingfailreason' => ChatMessage::PROCESSFAIL_NOT_CONFIRMED], 'never_answered');
        $this->makeReply('ask', '2026-10-07 10:00:00', $gone + ['processingfailreason' => ChatMessage::PROCESSFAIL_POST_GONE], 'post_gone');

        $strata = $this->counts(null, ['primary' => 'no_wasted_reply'])['arms']['ask']['strata'];

        $this->assertSame(1, $strata['said_no']['completes'], 'the replier did not want it: the poster is spared a wasted reply');
        $this->assertSame(0, $strata['never_answered']['completes'], 'ignoring the question is not a win');
        $this->assertSame(0, $strata['post_gone']['completes'], 'nor is a post that went while the reply waited');
    }

    public function test_no_wasted_reply_watches_handovers_not_falling_by_more_than_two_points(): void
    {
        $this->makeReply('ask', '2026-10-07 10:00:00', ['processingrequired' => 0, 'processingsuccessful' => 0]);
        [$replier, $post] = $this->makeReply('ask', '2026-10-07 10:00:00');
        $this->promise($post, $replier, '2026-10-07 12:00:00');
        $this->makeReply('control', '2026-10-07 10:00:00');

        $guards = $this->counts(null, ['primary' => 'no_wasted_reply'])['guards'];

        $this->assertSame(['fewer_handovers'], array_keys($guards));
        $this->assertSame(['bad' => 1, 'base' => 2], $guards['fewer_handovers']['ask'], 'a refused reply is not a handover, and a handed over replier is not bad');
        $this->assertSame(['bad' => 1, 'base' => 1], $guards['fewer_handovers']['control']);
        $this->assertSame(['fewer_handovers'], array_keys($this->source->guardrails($this->exp(['primary' => 'no_wasted_reply']))));
        $this->assertSame(2.0, $this->source->guardrails($this->exp(['primary' => 'no_wasted_reply']))['fewer_handovers']['margin_points']);
    }

    public function test_no_wasted_reply_is_the_default_primary_and_the_words_follow_the_primary(): void
    {
        $cases = [
            'no primary, a day' => [['window_hours' => 24], 'had no wasted reply within a day', 'No wasted reply within a day'],
            'no primary, a week' => [['window_hours' => 168], 'had no wasted reply within 7 days', 'No wasted reply within 7 days'],
            'no wasted reply, 36 hours' => [['primary' => 'no_wasted_reply', 'window_hours' => 36], 'had no wasted reply within 36 hours', 'No wasted reply within 36 hours'],
            'handover, a day' => [['primary' => 'handover', 'window_hours' => 24], 'were promised the item or marked as taking it within a day', 'Promised or taken within a day'],
            'handover, a week' => [['primary' => 'handover', 'window_hours' => 168], 'were promised the item or marked as taking it within 7 days', 'Promised or taken within 7 days'],
        ];

        foreach ($cases as $label => [$design, $finished, $tabFinished]) {
            $words = $this->source->words($this->exp($design));

            $this->assertSame('repliers', $words['who'], $label);
            $this->assertSame('were counted', $words['started'], $label);
            $this->assertSame('Counted', $words['tab_started'], $label);
            $this->assertSame($finished, $words['finished'], $label);
            $this->assertSame($tabFinished, $words['tab_finished'], $label);
            $this->assertDoesNotMatchRegularExpression('/\bsent\b/i', implode(' ', $words), $label);
            $this->assertStringStartsWith('Each replier is counted once, for their first reply to an offer that looked damaged or too big to carry, and only when that reply is at least', $words['note'], $label);
        }
        // The default is the result the question is for, and a design with no primary at all must not throw.
        $this->assertSame(ConfirmReplySource::NO_WASTED_REPLY, ConfirmReplySource::primaries()[0]);
        $this->assertSame('had no wasted reply within 7 days', $this->source->words($this->exp())['finished']);
    }

    public function test_the_note_defines_the_result_the_tab_shows_by_its_own_name(): void
    {
        // A note that defines a word the screen never shows leaves the real label undefined.
        foreach (['handover' => 'Promised or taken', 'no_wasted_reply' => 'No wasted reply'] as $primary => $label) {
            $words = $this->source->words($this->exp(['primary' => $primary]));

            $this->assertStringStartsWith($label, $words['tab_finished'], $primary);
            $this->assertStringContainsString($label . ' means the poster promised the item to the replier or marked it as taken by them within 7 days of the reply', $words['note'], $primary);
            $this->assertStringNotContainsString('Handed over', $words['note'], $primary);
        }
    }

    public function test_the_primary_is_refused_unless_the_source_offers_it(): void
    {
        $withSource = ['channel' => 'server', 'source' => 'confirm_reply', 'window_hours' => 168];

        try {
            UxExperimentService::normaliseDesign(['primary' => 'handover'], 'reply');
            $this->fail('a primary with no source must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('only for an experiment with a design.source', $e->getMessage());
        }

        try {
            UxExperimentService::normaliseDesign(['channel' => 'email', 'source' => 'email_click', 'window_hours' => 24, 'primary' => 'handover'], 'reply');
            $this->fail('a source that offers no choice must refuse a primary');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not offered by the source email_click', $e->getMessage());
        }

        foreach (['handovers', 'no_wasted_replies', '', 5, ['handover']] as $bad) {
            try {
                UxExperimentService::normaliseDesign($withSource + ['primary' => $bad], 'reply');
                $this->fail('a primary the source does not offer must be refused: '.json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('design.primary for the source confirm_reply must be no_wasted_reply or handover', $e->getMessage());
            }
        }

        foreach (['handover', 'no_wasted_reply'] as $good) {
            $this->assertSame($good, UxExperimentService::normaliseDesign($withSource + ['primary' => $good], 'reply')['primary']);
        }
        $this->assertArrayNotHasKey('primary', UxExperimentService::normaliseDesign($withSource, 'reply'), 'no primary is stored when none is given');
    }

    // ---------------------------------------------------------------- the holdout look, and the detail

    public function test_the_since_read_counts_first_exposures_from_the_decision(): void
    {
        $this->makeReply('ask', '2026-10-08 10:00:00', [], 'before');
        $this->makeReply('ask', '2026-10-10 10:00:00', [], 'after');
        // Another experiment's row, and a row of another subject type, are not this experiment's.
        $this->makeReply('ask', '2026-10-10 10:00:00', [], 'other_run', null, null, 'cr-other');
        $this->makeExposure($this->createTestUser(), 'ask', 4242, '2026-10-10 10:00:00', 'other_type', self::NAME, 'push');

        $since = Carbon::parse('2026-10-09 00:00:00', 'UTC');
        $c = $this->counts($since);

        $this->assertSame(['after'], array_keys($c['arms']['ask']['strata']), 'only the exposure since the decision');
        $this->assertSame(['control' => 0, 'ask' => 1], $c['extra']['exposed']);

        // The cells of the detail follow the same bound, the same experiment and the same subject type: the reply of the
        // other experiment and the row of the other type are in no stratum and in no cell.
        $reasons = $this->reasons([], $since);
        $this->assertEqualsCanonicalizing(['all', 'after'], array_keys($reasons), 'neither the other experiment nor the other type has a reason');
        $this->assertSame(1, $reasons['all']['ask']['replies'], 'only the reply since the decision');
        $this->assertSame(0, $reasons['all']['control']['replies']);

        // With no decision yet the bound is the start of the run: both of this experiment's replies and nothing else.
        $whole = $this->reasons();
        $this->assertEqualsCanonicalizing(['all', 'before', 'after'], array_keys($whole));
        $this->assertSame(2, $whole['all']['ask']['replies'], 'the reply of the other experiment and the push row are left out');
    }

    public function test_the_detail_cells_count_every_first_reply_whatever_its_age(): void
    {
        $this->makeReply('ask', '2026-10-19 10:00:00');       // two days old: not mature
        $this->makeReply('ask', '2026-10-07 10:00:00');       // mature
        $this->makeReply('control', '2026-10-19 10:00:00', ['processingsuccessful' => 0]);

        $all = $this->reasons()['all'];

        $this->assertSame(2, $all['ask']['replies']);
        $this->assertSame(2, $all['ask']['reached'], 'the young reply reached the poster too');
        $this->assertSame(1, $all['ask']['counted'], 'only the mature reply is counted');
        $this->assertSame(1, $all['control']['replies']);
        $this->assertSame(0, $all['control']['reached']);
        $this->assertSame(0, $all['control']['counted']);
    }

    public function test_the_detail_takes_the_answers_from_the_prompt_of_the_reply(): void
    {
        $asked = '2026-10-09 10:00:00';
        $make = function (array $columns, array $reply = []) use ($asked) {
            [$replier, $post, $message] = $this->makeReply('ask', $asked, $reply);
            $this->makeQuestion($message, $replier, $post, $asked, $columns);
        };
        $make(['answer' => 'yes', 'answered_via' => 'button', 'answered_at' => '2026-10-09 10:00:05']);
        $make(['answer' => 'no', 'answered_via' => 'button', 'answered_at' => '2026-10-09 10:30:00'], ['processingsuccessful' => 0, 'processingfailreason' => ChatMessage::PROCESSFAIL_REPLY_WITHDRAWN]);
        $make([], ['processingsuccessful' => 0, 'processingfailreason' => ChatMessage::PROCESSFAIL_NOT_CONFIRMED]);    // no answer in three days
        $make([], ['processingrequired' => 1, 'processingsuccessful' => 0]);                                          // still waiting
        $make(['answer' => 'expired', 'answered_via' => 'post_gone', 'answered_at' => '2026-10-09 10:20:00'], ['processingsuccessful' => 0, 'processingfailreason' => ChatMessage::PROCESSFAIL_POST_GONE]);
        $this->makeReply('ask', $asked);                                                                              // never asked
        $this->makeReply('control', $asked);

        $ask = $this->reasons()['all']['ask'];

        $this->assertSame(6, $ask['replies']);
        $this->assertSame(5, $ask['asked']);
        $this->assertSame(1, $ask['yes_button']);
        $this->assertSame(1, $ask['no']);
        $this->assertSame(1, $ask['never_answered'], 'the batch dropped it as not confirmed');
        $this->assertSame(1, $ask['post_gone'], 'the offer went while the reply waited');
        $this->assertSame(1, $ask['waiting']);
        $this->assertSame(2, $ask['reached'], 'the yes and the one that never asked');
        $this->assertSame(0, $this->reasons()['all']['control']['asked']);
    }

    public function test_the_detail_all_row_is_the_sum_of_the_reasons_and_has_every_arm(): void
    {
        $this->makeReply('ask', '2026-10-07 10:00:00', [], 'damaged');
        $this->makeReply('ask', '2026-10-07 10:00:00', [], 'damaged');
        $this->makeReply('control', '2026-10-07 10:00:00', [], 'car');
        $this->makeReply('ask', '2026-10-07 10:00:00', [], null);
        $this->makeReply('retired', '2026-10-07 10:00:00', [], 'both');      // an arm the experiment no longer has

        $reasons = $this->reasons();

        $this->assertEqualsCanonicalizing(['all', 'damaged', 'car', 'unknown'], array_keys($reasons), 'a null stratum reads unknown');
        foreach ($reasons as $reason => $byArm) {
            $this->assertSame(['control', 'ask'], array_keys($byArm), "$reason has every arm of the experiment");
        }
        $this->assertSame(['replies' => 0, 'asked' => 0, 'yes_button' => 0, 'no' => 0, 'never_answered' => 0, 'post_gone' => 0, 'waiting' => 0, 'reached' => 0, 'counted' => 0, 'handed_over' => 0], $reasons['car']['ask']);
        foreach (['control', 'ask'] as $arm) {
            foreach (array_keys($reasons['all'][$arm]) as $cell) {
                $sum = 0;
                foreach (['damaged', 'car', 'unknown'] as $reason) {
                    $sum += $reasons[$reason][$arm][$cell];
                }
                $this->assertSame($sum, $reasons['all'][$arm][$cell], "all.$arm.$cell is the sum of the reasons");
            }
        }
        $this->assertSame(3, $reasons['all']['ask']['replies']);
        $this->assertSame(1, $reasons['all']['control']['replies']);
    }

    // ---------------------------------------------------------------- the size of a run

    /** A time far from any other test's rows, so only this test's rows are in the window. */
    private function farNow(): Carbon
    {
        return Carbon::parse('2031-03-10 12:00:00', 'UTC');
    }

    /**
     * An eligible reply for volume(): a damaged (or large) offer, classified before the reply, and a website reply to it.
     *
     * @return array{0: User, 1: Message, 2: ChatMessage}
     */
    private function makeEligibleReply(string $at = '2031-02-27 12:00:00', array $post = [], array $eee = [], array $reply = [], ?User $replier = null, ?User $poster = null): array
    {
        $replier ??= $this->createTestUser();
        $message = $this->makePost($poster, $post + ['type' => 'Offer']);
        DB::table('messages_eee')->insert($eee + [
            'msgid' => $message->id, 'item_condition' => 'damaged', 'size_bucket' => 'small', 'weight_bucket' => '1_5kg',
            'model' => 'test-model', 'prompt_version' => 'v1', 'classified_at' => '2031-02-26 12:00:00',
        ]);
        $chat = $this->createTestChatMessage($this->room($replier, $message->fromuser), $replier, $reply + [
            'type' => 'Interested', 'refmsgid' => $message->id, 'date' => $at, 'platform' => 1,
        ]);

        return [$replier, $message, $chat];
    }

    public function test_volume_is_half_the_repliers_of_the_last_intake_and_their_handover_rate(): void
    {
        // Four repliers and seven replies. The reply of the first replier to the first post, and of the third to the third, were handed over.
        [$r1, $p1] = $this->makeEligibleReply('2031-02-27 12:00:00');
        [$r2] = $this->makeEligibleReply('2031-02-27 13:00:00', [], [], [], null, User::find($p1->fromuser));
        [$r3, $p3] = $this->makeEligibleReply('2031-02-28 12:00:00');
        $this->makeEligibleReply('2031-02-28 13:00:00', [], [], [], $r2);
        [, $p5] = $this->makeEligibleReply('2031-03-01 12:00:00');
        $this->makeEligibleReply('2031-03-01 13:00:00', [], [], [], $r1);
        $this->makeEligibleReply('2031-03-02 12:00:00', [], [], [], $r3);
        $this->promise($p1, $r1, '2031-02-27 18:00:00');
        $this->promise($p3, $r3, '2031-03-05 11:59:59');                       // inside the week of the reply
        $this->promise($p5, $this->createTestUser(), '2031-03-01 14:00:00');   // to someone who did not reply

        $volume = $this->source->volume($this->exp(['intake_hours' => 168]), $this->farNow());

        $this->assertNotNull($volume);
        $this->assertSame(2, $volume['per_arm'], 'half of the four repliers');
        $this->assertEqualsWithDelta(2 / 7, $volume['rate'], 0.0001, 'two of the seven replies were handed over');
    }

    public function test_volume_leaves_out_what_the_experiment_would_not_ask(): void
    {
        [$keeper, $keep] = $this->makeEligibleReply('2031-02-27 12:00:00');
        $this->makeEligibleReply('2031-02-27 12:00:00');
        // One of the two replies that are left in was handed over, so the rate says how many replies were left in.
        $this->promise($keep, $keeper, '2031-02-27 15:00:00');

        // A TrashNothing replier, and a poster whose preferred address is a TrashNothing one.
        $this->makeEligibleReply('2031-02-27 12:00:00', [], [], [], $this->createTestUser(['tnuserid' => 4321]));
        $this->makeEligibleReply('2031-02-27 12:00:00', [], [], [], null, $this->createTestUser(['email_preferred' => 'tn'.uniqid().'@user.trashnothing.com']));
        // A poster who might deliver takes the car out of the question, but not damage.
        $this->makeEligibleReply('2031-02-27 12:00:00', ['deliverypossible' => 1], ['item_condition' => 'reusable', 'size_bucket' => 'large']);
        // An email reply, a wanted post, a post nobody classified, a classification before the window, and a reply held by rippling.
        $this->makeEligibleReply('2031-02-27 12:00:00', [], [], ['platform' => 0]);
        $this->makeEligibleReply('2031-02-27 12:00:00', ['type' => 'Wanted']);
        [, $unclassified] = $this->makeEligibleReply('2031-02-27 12:00:00');
        DB::table('messages_eee')->where('msgid', $unclassified->id)->delete();
        $this->makeEligibleReply('2031-02-27 12:00:00', [], ['classified_at' => '2031-01-01 12:00:00']);
        [$held, $heldPost, $heldReply] = $this->makeEligibleReply('2031-02-27 12:00:00');
        DB::table('rippling_held_replies')->insert([
            'chatid' => $heldReply->chatid, 'chatmsgid' => $heldReply->id, 'msgid' => $heldPost->id, 'replieruserid' => $held->id,
            'source' => 'web', 'status' => 'held', 'created_at' => '2031-02-27 12:00:00',
        ]);
        // Replies outside the intake: too young to have had their week, and before the intake began.
        $this->makeEligibleReply('2031-03-05 12:00:00');
        $this->makeEligibleReply('2031-02-20 12:00:00');
        // The poster's own message about their own post.
        $this->createTestChatMessage($this->room(User::findOrFail($keep->fromuser), $keeper->id), User::findOrFail($keep->fromuser), [
            'type' => 'Interested', 'refmsgid' => $keep->id, 'date' => '2031-02-27 12:00:00', 'platform' => 1,
        ]);

        $volume = $this->source->volume($this->exp(['intake_hours' => 168]), $this->farNow());

        // Only the two plain replies are left in, and one of them was handed over.
        $this->assertNotNull($volume);
        $this->assertSame(1, $volume['per_arm'], 'two repliers are left in, so one on each arm');
        $this->assertEqualsWithDelta(0.5, $volume['rate'], 0.0001, 'one of two replies: anything left in by mistake would lower it');
    }

    public function test_volume_is_null_with_nothing_to_go_on_and_when_the_query_fails(): void
    {
        // The table is hidden first, before this test has read or written messages_eee or messages: a transaction that
        // has touched them holds a lock that can make the rename wait out its five seconds and fail.
        Log::spy();
        $volume = $this->withoutMessagesEee(fn () => $this->source->volume($this->exp(), $this->farNow()));

        $this->assertNull($volume, 'a size estimate that fails must not stop a start');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'could not size the run'))->once();

        $this->assertNull($this->source->volume($this->exp(), $this->farNow()), 'no replies in the window');

        // One replier is not enough to halve.
        $this->makeEligibleReply();
        $this->assertNull($this->source->volume($this->exp(), $this->farNow()), 'fewer than two repliers');
    }

    /**
     * Run $work with messages_eee missing. The table is renamed on a SECOND connection, because MySQL commits on DDL and
     * a rename on the test's own connection would end the transaction every test runs in and leave its rows behind for
     * the classes after it (.claude/rules/laravel-batch-traps.md, "DDL inside a test commits"). It is put back whatever
     * happens.
     */
    private function withoutMessagesEee(callable $work): mixed
    {
        $c = config('database.connections.mysql');
        $host = $c['write']['host'] ?? [$c['host'] ?? '127.0.0.1'];
        $host = is_array($host) ? reset($host) : $host;
        $side = new \PDO(
            "mysql:host={$host};port={$c['port']};dbname={$c['database']};charset=utf8mb4",
            $c['username'],
            $c['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        // A rename that cannot get its lock fails in seconds instead of waiting for a year.
        $side->exec('SET SESSION lock_wait_timeout = 5');
        $side->exec('RENAME TABLE messages_eee TO messages_eee_hidden');

        try {
            return $work();
        } finally {
            $side->exec('RENAME TABLE messages_eee_hidden TO messages_eee');
        }
    }

    // ---------------------------------------------------------------- edges

    public function test_a_vanished_chat_message_counts_as_not_converted(): void
    {
        $gone = $this->createTestUser();
        $this->makeExposure($gone, 'ask', 987654321, '2026-10-07 10:00:00');

        foreach (['handover', 'no_wasted_reply'] as $primary) {
            $c = $this->counts(null, ['primary' => $primary]);

            $this->assertSame(1, $c['arms']['ask']['starts'], "$primary: counted");
            $this->assertSame(0, $c['arms']['ask']['completes'], "$primary: but not converted, in either arm alike");
        }
    }

    public function test_guardrails_and_margins_per_primary(): void
    {
        $handover = $this->source->guardrails($this->exp(['primary' => 'handover']));
        $wasted = $this->source->guardrails($this->exp(['primary' => 'no_wasted_reply']));
        $default = $this->source->guardrails($this->exp());

        $this->assertSame(['never_delivered'], array_keys($handover));
        $this->assertSame(50.0, $handover['never_delivered']['margin_points'], 'a disaster guard');
        $this->assertSame(ConfirmReplySource::NEVER_DELIVERED_MARGIN_POINTS, $handover['never_delivered']['margin_points']);
        $this->assertSame(['fewer_handovers'], array_keys($wasted));
        $this->assertSame(2.0, $wasted['fewer_handovers']['margin_points']);
        $this->assertSame($wasted, $default, 'no primary is no_wasted_reply');
        foreach ([$handover, $wasted] as $guards) {
            foreach ($guards as $g) {
                $this->assertStringStartsWith('the share of repliers', $g['label'], 'a label is the subject of a sentence: one share');
            }
        }
        $this->assertSame(['reply'], $this->source->types());
    }
}
