<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\PlainWords;
use App\Services\Ux\UxPromptService;
use App\Services\Ux\UxRollupService;
use App\Services\Ux\UxScrub;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The member surveys: the questions microvolunteers are asked, coding their written answers into
 * grouped problems, values and suggestions, the follow-up ranking and its weights, and keeping
 * member words only as long as they are wanted.
 * docs/developers/reference/ux-member-surveys.md.
 */
class UxPromptFarmingTest extends TestCase
{
    private UxPromptService $service;

    /** @var array{status: int, body: mixed, throw: bool} */
    private array $helper;

    /** @var Request[] */
    private array $sent = [];

    private int $nextUser = 100;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        foreach (['ux_prompt_responses', 'ux_prompts', 'ux_problems'] as $t) {
            DB::table($t)->delete();
        }
        config()->set('freegle.ux.prompts', ['helper_url' => 'http://ai-support-helper:3000', 'internal_secret' => 'test-secret', 'themes_timeout' => 30, 'privacy_cleared' => true]);
        $this->service = app(UxPromptService::class);
        $this->sent = [];
        $this->helper = ['status' => 200, 'throw' => false, 'body' => ['problems' => [], 'solutions' => [], 'values' => [], 'unclear_ids' => []]];
        Http::fake(function (Request $request) {
            $this->sent[] = $request;
            if ($this->helper['throw'] || ($this->helper['throw_first'] ?? 0) > 0) {
                $this->helper['throw_first'] = max(0, ($this->helper['throw_first'] ?? 0) - 1);
                throw new ConnectionException('connection refused');
            }

            return Http::response($this->helper['body'], $this->helper['status']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return int[] the response ids, in order */
    private function answer(int $prompt, array $texts): array
    {
        $ids = [];
        foreach ($texts as $text) {
            $ids[] = DB::table('ux_prompt_responses')->insertGetId([
                'prompt_id' => $prompt, 'userid' => $this->nextUser++,
                'answer' => json_encode(is_array($text) ? $text : ['text' => $text]),
            ]);
        }

        return $ids;
    }

    private function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    // ---------------------------------------------------------------- opening

    public function test_the_three_open_presets_ask_one_neutral_question_each_with_a_way_out(): void
    {
        $problems = DB::table('ux_prompts')->find($this->service->openOpen('problems'));
        $values = DB::table('ux_prompts')->find($this->service->openOpen('values'));
        $improve = DB::table('ux_prompts')->find($this->service->openOpen('improve'));

        $this->assertSame('open', $problems->kind);
        $this->assertSame('Did you have a problem the last time you used Freegle?', $problems->question);
        $this->assertSame('What has worked well for you on Freegle?', $values->question);
        $this->assertSame('What one thing would you change about Freegle?', $improve->question);
        foreach ([$problems, $values, $improve] as $p) {
            $this->assertSame('', $p->body, 'a card has no paragraph under its question');
        }
        $this->assertSame([['key' => 'nothing', 'label' => 'I had no problems'], ['key' => 'dont_understand', 'label' => "I don't understand"]], json_decode($problems->options, true));
        $this->assertSame("I can't think of one", json_decode($values->options, true)[0]['label']);
        $this->assertSame("I can't think of one", json_decode($improve->options, true)[0]['label']);
        $this->assertEquals(['preset' => 'problems', 'randomise' => false, 'placeholder' => 'If yes, what happened?'], json_decode($problems->settings, true));
        $this->assertSame('What made it good?', json_decode($values->settings, true)['placeholder']);
        $this->assertSame('What would that help you do?', json_decode($improve->settings, true)['placeholder']);
        $this->assertSame(300, (int) $problems->max_responses);
        $this->assertSame('open', $problems->status);
    }

    public function test_an_open_prompt_never_collects_more_than_300_answers_and_an_unknown_preset_is_refused(): void
    {
        $this->assertSame(300, (int) DB::table('ux_prompts')->find($this->service->openOpen('problems', 5000))->max_responses);
        $this->assertSame(50, (int) DB::table('ux_prompts')->find($this->service->openOpen('values', 50))->max_responses);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->openOpen('opinions');
    }

    public function test_opening_a_preset_again_closes_the_older_one_of_the_same_preset_only(): void
    {
        $a = $this->service->openOpen('problems');
        $v = $this->service->openOpen('values');
        $b = $this->service->openOpen('problems');

        $this->assertSame('closed', DB::table('ux_prompts')->find($a)->status);
        $this->assertSame('open', DB::table('ux_prompts')->find($v)->status);
        $this->assertSame('open', DB::table('ux_prompts')->find($b)->status);
    }

    public function test_the_standard_survey_carries_its_labels_and_randomises(): void
    {
        $survey = DB::table('ux_prompts')->find($this->service->openSurvey());

        $this->assertSame('Which part of Freegle works best for you?', $survey->question);
        $this->assertSame('', $survey->body);
        $this->assertEquals(['show' => 4, 'worst_label' => 'Which other part most needs improving? (optional)', 'none_label' => 'None of these', 'randomise' => true],
            json_decode($survey->settings, true));
        $this->assertSame([
            'Offering something', 'Asking for something', 'Replying to a post', 'Arranging a collection in chat',
            'Marking a post taken or received', 'Signing in or joining', 'Settings and email choices',
        ], array_column(json_decode($survey->options, true), 'label'));
    }

    public function test_an_awareness_question_asks_whether_you_know_how_and_never_did_you_know(): void
    {
        $id = $this->service->openAwareness('chat_promise', 'promise an item to someone in a chat?', '  In a chat about your offer,   Promise marks it for that person. ', '/chats');
        $p = DB::table('ux_prompts')->find($id);

        $this->assertSame('awareness', $p->kind);
        $this->assertSame('chat_promise', $p->flow);
        $this->assertSame('Do you know how to promise an item to someone in a chat?', $p->question);
        $this->assertSame('', $p->body, 'no instruction under the question');
        $this->assertStringNotContainsString('Did you know', $p->question.$p->body);
        $this->assertSame([['key' => 'yes', 'label' => 'Yes'], ['key' => 'no', 'label' => 'No'], ['key' => 'not_sure', 'label' => 'Not sure']], json_decode($p->options, true));
        $this->assertEquals(['reveal' => 'In a chat about your offer, Promise marks it for that person.', 'link' => '/chats', 'decoy' => false], json_decode($p->settings, true));
        $this->assertSame('open', $p->status);
        $this->assertNull($p->images, 'a picture would give the answer away before it is asked');
    }

    public function test_a_decoy_awareness_question_is_marked_and_a_link_must_be_a_path_on_this_site(): void
    {
        $settings = json_decode(DB::table('ux_prompts')->find($this->service->openAwareness('', 'pay a courier to collect an item', 'Nobody can pay to have an item collected.', null, true))->settings, true);
        $this->assertEquals(['reveal' => 'Freegle does not do this. Nobody can pay to have an item collected.', 'link' => null, 'decoy' => true], $settings);

        foreach (['https://example.com/x', '//example.com/x', 'settings', '/a b', "/ok\n/x"] as $bad) {
            $this->assertRefusedInvalid(fn () => $this->service->openAwareness('', 'do a thing', 'A reveal.', $bad), 'link');
        }
        $this->assertRefusedInvalid(fn () => $this->service->openAwareness('', ' ? ', 'A reveal.'), 'action');
        $this->assertRefusedInvalid(fn () => $this->service->openAwareness('', 'do a thing', ' ', null, true), 'reveal');
        $this->assertRefusedInvalid(fn () => $this->service->openAwareness('', 'do a thing', null, '/settings'), 'reveal');
        $this->assertRefusedInvalid(fn () => $this->service->openAwareness('bad flow!', 'do a thing', 'A reveal.'), 'flow');
    }

    public function test_an_awareness_question_without_a_reveal_is_accepted_and_stores_none(): void
    {
        $p = DB::table('ux_prompts')->find($this->service->openAwareness('chat_promise', 'promise an item to someone in a chat'));

        $this->assertSame('Do you know how to promise an item to someone in a chat?', $p->question);
        $this->assertSame('', $p->body);
        $settings = json_decode($p->settings, true);
        $this->assertArrayNotHasKey('reveal', $settings);
        $this->assertEquals(['link' => null, 'decoy' => false], $settings);
    }

    public function test_a_decoy_awareness_question_without_a_reveal_is_refused_with_a_reason(): void
    {
        $this->assertRefusedInvalid(fn () => $this->service->openAwareness('', 'pay a courier to collect an item', null, null, true), 'must be told afterwards');
        $this->assertSame(0, DB::table('ux_prompts')->where('kind', 'awareness')->count());
    }

    public function test_a_decoy_reveal_starts_with_the_fixed_line_and_is_still_limited(): void
    {
        $this->assertSame('Freegle does not do this.', UxPromptService::DECOY_LINE);

        // A typed reveal of the full 160 characters is allowed; the line in front makes the stored text longer.
        $typed = str_repeat('Nobody can pay. ', 10);
        $typed = rtrim($typed);
        $typed = $typed.str_repeat('x', UxPromptService::REVEAL_MAX - mb_strlen($typed));
        $this->assertSame(UxPromptService::REVEAL_MAX, mb_strlen($typed));
        $stored = json_decode(DB::table('ux_prompts')->find($this->service->openAwareness('give', 'pay to put your offer first', $typed, null, true))->settings, true)['reveal'];

        $this->assertStringStartsWith('Freegle does not do this. Nobody can pay.', $stored);
        $this->assertSame(mb_strlen(UxPromptService::DECOY_LINE) + 1 + UxPromptService::REVEAL_MAX, mb_strlen($stored));
        $this->assertSame(186, mb_strlen($stored));

        // One character more is refused, decoy or not, and the line is not added to a real question's reveal.
        $this->assertRefusedInvalid(fn () => $this->service->openAwareness('give', 'pay to put your offer first', $typed.'y', null, true), 'The reveal is 161 characters');
        $real = json_decode(DB::table('ux_prompts')->find($this->service->openAwareness('give', 'promise an item in a chat', 'Promise marks it.', null, false))->settings, true);
        $this->assertSame('Promise marks it.', $real['reveal']);
        $this->assertTrue((bool) json_decode(DB::table('ux_prompts')->where('question', 'Do you know how to pay to put your offer first?')->value('settings'), true)['decoy'], 'the tab still sees the decoy flag');
    }

    public function test_an_action_over_50_or_a_reveal_over_160_or_a_question_over_14_words_is_refused(): void
    {
        $action51 = str_repeat('a', 51);
        $this->assertRefusedInvalid(fn () => $this->service->openAwareness('', $action51, 'A reveal.'), 'The action is 51 characters. A phone screen fits 50.');
        $this->assertRefusedInvalid(fn () => $this->service->openAwareness('', 'promise an item in a chat', str_repeat('r', 161)), 'The reveal is 161 characters. A phone screen fits 160.');

        // Twelve short words fit in 36 characters, but make a question of 17 words.
        $crowded = 'ask a pal to do it if I go to a shop';
        $this->assertLessThanOrEqual(UxPromptService::ACTION_MAX, mb_strlen($crowded));
        $this->assertRefusedInvalid(fn () => $this->service->openAwareness('', $crowded, 'A reveal.'), 'The question would be 17 words. Use at most 14.');

        $this->assertSame(0, DB::table('ux_prompts')->where('kind', 'awareness')->count(), 'nothing is stored for a refusal');
    }

    public function test_the_longest_awareness_question_fits_both_limits(): void
    {
        // An action of exactly 50 characters made of short words: 7 words, so a question of 12 words and 70 characters.
        $action = 'change your notification settings for new messages';
        $this->assertSame(50, mb_strlen($action));
        $question = DB::table('ux_prompts')->find($this->service->openAwareness('settings', $action))->question;

        $this->assertSame('Do you know how to '.$action.'?', $question);
        $this->assertLessThanOrEqual(UxPromptService::QUESTION_MAX_WORDS, $this->words($question));
        $this->assertLessThanOrEqual(UxPromptService::QUESTION_MAX_CHARS, mb_strlen($question));
        $this->assertSame(70, mb_strlen($question), 'the template takes 20 characters, so 50 more is 70');

        // The browser test's example: 48 characters and 9 words give the longest question, 14 words and 68 characters.
        $example = 'get a message when somebody replies to your post';
        $q = DB::table('ux_prompts')->find($this->service->openAwareness('reply', $example))->question;
        $this->assertSame(14, $this->words($q));
        $this->assertSame(68, mb_strlen($q));
        $this->assertTrue(PlainWords::fits($q, UxPromptService::TITLE_MAX_GRADE), 'and it reads at grade 5.88');

        // An action of 50 characters that would make more than 14 words is refused.
        $many = 'go to a shop to get a pen so I can do a task or so';
        $this->assertSame(50, mb_strlen($many));
        $this->assertRefusedInvalid(fn () => $this->service->openAwareness('', $many), 'The question would be 21 words. Use at most 14.');
    }

    public function test_member_words_are_short_plain_and_fit(): void
    {
        // Every question a member can be asked, as the batch writes it.
        $questions = [];
        foreach (array_keys(UxPromptService::OPEN_PRESETS) as $preset) {
            $questions["open $preset"] = DB::table('ux_prompts')->find($this->service->openOpen($preset));
        }
        $questions['area survey'] = DB::table('ux_prompts')->find($this->service->openSurvey());
        $questions['awareness template'] = DB::table('ux_prompts')->find($this->service->openAwareness('chat_promise', 'promise an item to someone in a chat'));
        $prompt = $this->service->openOpen('problems');
        $this->analysedOver($prompt, 100);
        $this->problem($prompt, 'Photo uploads keep failing on my phone', 5);
        $this->problem($prompt, 'Posts take too long to be approved', 4);
        $questions['follow-up'] = DB::table('ux_prompts')->find($this->service->followup($prompt, 200, true));

        foreach ($questions as $where => $row) {
            $q = $row->question;
            $this->assertLessThanOrEqual(UxPromptService::QUESTION_MAX_WORDS, $this->words($q), "$where: $q");
            $this->assertLessThanOrEqual(UxPromptService::QUESTION_MAX_CHARS, mb_strlen($q), "$where: $q");
            $this->assertTrue(PlainWords::fits($q, 6), "$where reads at grade ".round(PlainWords::grade($q), 2).": $q");
            $this->assertSame('', $row->body, "$where has no paragraph under the question");
            foreach (json_decode($row->options, true) as $option) {
                if ($where === 'follow-up') {
                    $this->assertLessThanOrEqual(UxPromptService::TITLE_MAX, mb_strlen($option['label']), "$where title: {$option['label']}");
                } elseif ($where === 'area survey') {
                    $this->assertLessThanOrEqual(34, mb_strlen($option['label']), "an area label: {$option['label']}");
                } else {
                    $this->assertLessThanOrEqual(20, mb_strlen($option['label']), "a way out or an answer: {$option['label']}");
                }
            }
            $settings = json_decode($row->settings, true);
            if (isset($settings['placeholder'])) {
                $this->assertLessThanOrEqual(60, mb_strlen($settings['placeholder']));
            }
            foreach (['none_label'] as $label) {
                if (isset($settings[$label])) {
                    $this->assertLessThanOrEqual(20, mb_strlen($settings[$label]));
                }
            }
        }

        // The second question of the area survey is checked for length only: "(optional)" lifts its grade from 4.45 to 7.37.
        $step2 = json_decode($questions['area survey']->settings, true)['worst_label'];
        $this->assertSame('Which other part most needs improving? (optional)', $step2);
        $this->assertLessThanOrEqual(UxPromptService::QUESTION_MAX_WORDS, $this->words($step2));
        $this->assertLessThanOrEqual(UxPromptService::QUESTION_MAX_CHARS, mb_strlen($step2));
        $this->assertFalse(PlainWords::fits($step2, 6), 'which is why the grade check does not cover it');

        // The fixed limits are the ones the card is built for.
        $this->assertSame([60, 50, 160, 14, 72, 6], [UxPromptService::TITLE_MAX, UxPromptService::ACTION_MAX, UxPromptService::REVEAL_MAX,
            UxPromptService::QUESTION_MAX_WORDS, UxPromptService::QUESTION_MAX_CHARS, UxPromptService::TITLE_MAX_GRADE]);
    }

    public function test_the_last_group_is_named_other(): void
    {
        $groups = UxPromptService::PROBLEM_GROUPS;

        $this->assertCount(8, $groups);
        $this->assertSame('Other', end($groups)['name'], 'the API sorts the group called Other last, by its name');
        $this->assertSame('Other', UxPromptService::OTHER_GROUP);
        $this->assertSame(array_values(array_unique(array_column($groups, 'name'))), UxPromptService::groupNames(), 'the names are distinct');
        foreach ($groups as $g) {
            $this->assertLessThanOrEqual(40, mb_strlen($g['name']), 'the column holds 40');
            $this->assertNotSame('', $g['hint']);
        }
    }

    private function assertRefusedInvalid(callable $do, string $fragment): void
    {
        try {
            $do();
            $this->fail("expected an invalid-argument refusal containing '$fragment'");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    public function test_a_funnel_that_is_not_a_short_name_is_refused_in_plain_words(): void
    {
        $this->assertRefusedInvalid(
            fn () => $this->service->openAwareness('bad flow!', 'do a thing', 'A reveal.'),
            '--flow must be a short name such as give, using letters, digits, _ and -.'
        );
        $this->assertSame(0, DB::table('ux_prompts')->where('kind', 'awareness')->count(), 'nothing is stored for a refusal');
    }

    public function test_the_list_command_heads_the_funnel_column_funnel(): void
    {
        $this->service->openAwareness('give', 'pay to put your offer first', 'Nobody can pay.', null, true);

        $this->artisan('ux:prompt list')
            ->expectsOutputToContain('funnel')
            ->expectsOutputToContain('give')
            ->doesntExpectOutputToContain('flow')
            ->assertExitCode(0);
    }

    public function test_the_command_opens_an_open_prompt(): void
    {
        $this->artisan('ux:prompt open --preset=values')->expectsOutputToContain('Opened prompt')->assertExitCode(0);
        $this->assertSame(1, DB::table('ux_prompts')->where('kind', 'open')->count());
        $this->artisan('ux:prompt open --preset=nonsense')->assertExitCode(1);
        $this->artisan('ux:prompt open')->assertExitCode(1);
    }

    public function test_prompt_command_opens_the_survey_and_awareness_questions_and_the_tick_closes_full_ones(): void
    {
        $this->artisan('ux:prompt survey --max=10')->expectsOutputToContain('Opened prompt')->assertExitCode(0);
        $survey = DB::table('ux_prompts')->where('kind', 'survey')->where('status', 'open')->first();
        $this->assertSame(7, count(json_decode($survey->options, true)));
        $this->assertSame(10, (int) $survey->max_responses);
        // A second survey replaces the first.
        $this->artisan('ux:prompt survey')->assertExitCode(0);
        $this->assertSame('closed', DB::table('ux_prompts')->where('id', $survey->id)->value('status'));

        $this->artisan('ux:prompt awareness --flow=chat_promise --action="promise an item to someone in a chat" --reveal="In a chat about your offer, Promise marks it for that person." --link=/chats')
            ->expectsOutputToContain('Opened prompt')->assertExitCode(0);
        $aw = DB::table('ux_prompts')->where('kind', 'awareness')->first();
        $this->assertSame('chat_promise', $aw->flow);
        $this->assertSame('Do you know how to promise an item to someone in a chat?', $aw->question);
        $this->assertSame(['yes', 'no', 'not_sure'], array_column(json_decode($aw->options, true), 'key'));
        $this->assertEquals(['reveal' => 'In a chat about your offer, Promise marks it for that person.', 'link' => '/chats', 'decoy' => false], json_decode($aw->settings, true));
        $this->artisan('ux:prompt awareness --flow=x --action=q --decoy')->expectsOutputToContain('must be told afterwards')->assertExitCode(1);
        $this->artisan('ux:prompt awareness --flow=x --action=q')->expectsOutputToContain('Opened prompt')->assertExitCode(0);
        $this->assertArrayNotHasKey('reveal', json_decode(DB::table('ux_prompts')->where('question', 'Do you know how to q?')->value('settings'), true));
        $this->artisan('ux:prompt awareness --action="pay a courier" --reveal="Nobody can pay for that." --decoy')->expectsOutputToContain('Opened prompt')->assertExitCode(0);
        $decoy = json_decode(DB::table('ux_prompts')->where('question', 'Do you know how to pay a courier?')->value('settings'), true);
        $this->assertTrue((bool) $decoy['decoy']);
        $this->assertSame('Freegle does not do this. Nobody can pay for that.', $decoy['reveal']);
        $this->artisan('ux:prompt awareness --action="x" --reveal="y" --link=https://evil.example')->assertExitCode(1);
        $this->artisan('ux:prompt awareness --action="'.str_repeat('a', 51).'"')->expectsOutputToContain('A phone screen fits 50')->assertExitCode(1);

        // Ten answers fill the ten-answer survey; the tick closes it and leaves the others open.
        $open = DB::table('ux_prompts')->where('kind', 'survey')->where('status', 'open')->first();
        DB::table('ux_prompts')->where('id', $open->id)->update(['max_responses' => 2]);
        foreach ([1, 2] as $u) {
            DB::table('ux_prompt_responses')->insert(['prompt_id' => $open->id, 'userid' => $u, 'answer' => json_encode(['best' => 'give', 'worst' => 'login'])]);
        }
        $this->artisan('ux:prompts-tick')->expectsOutputToContain('Closed 1 prompt(s)')->assertExitCode(0);
        $this->assertSame('closed', DB::table('ux_prompts')->where('id', $open->id)->value('status'));
        $this->assertSame('open', DB::table('ux_prompts')->where('id', $aw->id)->value('status'));

        $this->artisan('ux:prompt close '.$aw->id)->expectsOutputToContain('Closed prompt')->assertExitCode(0);
        $this->artisan('ux:prompt close 999999')->assertExitCode(1);
        $this->artisan('ux:prompt list')->expectsOutputToContain('awareness')->assertExitCode(0);
    }

    public function test_a_skip_does_not_count_towards_a_prompts_quota(): void
    {
        $id = $this->service->openOpen('problems', 10);
        $this->answer($id, array_fill(0, 9, 'something'));
        $this->answer($id, [['skipped' => true]]);

        $this->assertSame(0, $this->service->closeFull());

        $this->answer($id, ['tenth']);
        $this->assertSame(1, $this->service->closeFull());
    }

    // ---------------------------------------------------------------- analysing

    public function test_analysing_sends_only_scrubbed_text_with_the_secret_and_files_the_themes(): void
    {
        $id = $this->service->openOpen('problems');
        [$a, $b, $c, $d, $skip] = $this->answer($id, [
            'Photos will not upload, email me joe@example.com',
            'I could not find where to put my postcode SW1A 1AA',
            'Photos fail on my phone. Please add a retry button.',
            ['choice' => 'nothing'],
            ['skipped' => true],
        ]);
        $this->helper['body'] = [
            'problems' => [
                ['title' => 'Photos will not upload', 'description' => 'Upload fails on phones', 'flow' => 'give', 'group' => 'Photos and posting', 'answer_ids' => [$a, $c, 999999], 'quotes' => [
                    ['answer_id' => $a, 'span' => 'Photos will not upload'],
                    ['answer_id' => $c, 'span' => 'photos   FAIL on my phone'],
                    ['answer_id' => $c, 'span' => 'an invented sentence'],
                    ['answer_id' => $b, 'span' => 'put my postcode'],
                    ['answer_id' => $a, 'span' => 'joe@example.com'],
                ]],
                ['title' => str_repeat('A very long title ', 20), 'description' => '', 'flow' => 'nonsense', 'group' => 'A group nobody listed', 'answer_ids' => [$b, $a, $b], 'quotes' => [['answer_id' => $b, 'span' => 'find where to put']]],
                ['title' => 'No answers behind this', 'description' => 'x', 'flow' => 'give', 'group' => 'Finding things', 'answer_ids' => [424242], 'quotes' => [['answer_id' => 424242, 'span' => 'x']]],
                ['title' => '', 'description' => 'x', 'flow' => null, 'group' => 'Finding things', 'answer_ids' => [$a, $b], 'quotes' => [['answer_id' => $a, 'span' => 'Photos']]],
                ['title' => 'Only one member said this', 'description' => '', 'flow' => 'give', 'group' => 'Finding things', 'answer_ids' => [$a], 'quotes' => [['answer_id' => $a, 'span' => 'Photos']]],
                ['title' => 'Quotes that are not in the answers', 'description' => '', 'flow' => 'give', 'group' => 'Finding things', 'answer_ids' => [$a, $b], 'quotes' => [['answer_id' => $a, 'span' => 'nobody wrote this']]],
                ['title' => 'No quotes at all', 'description' => '', 'flow' => 'give', 'group' => 'Finding things', 'answer_ids' => [$a, $b]],
            ],
            'solutions' => [
                ['title' => 'Retry failed photo uploads', 'problem_index' => 0, 'answer_ids' => [$c]],
                ['title' => 'A general wish', 'problem_index' => 1, 'answer_ids' => [$b]],
                ['title' => 'Points at a dropped problem', 'problem_index' => 2, 'answer_ids' => [$b]],
                ['title' => 'Nobody said this', 'problem_index' => 0, 'answer_ids' => []],
            ],
            'values' => [
                ['title' => 'Giving to neighbours', 'answer_ids' => [$a, $c], 'quotes' => [['answer_id' => $c, 'span' => 'add a retry']]],
                ['title' => 'Empty value', 'answer_ids' => [], 'quotes' => []],
                ['title' => 'One voice', 'answer_ids' => [$a], 'quotes' => [['answer_id' => $a, 'span' => 'Photos']]],
            ],
            'unclear_ids' => [$b, 12345, $d],
        ];

        $result = $this->service->analyse($id);

        $this->assertSame(['problems' => 2, 'values' => 1, 'solutions' => 2, 'unclear' => 1, 'answers' => 3, 'suggestions_dropped' => 1], $result);

        // What went out: ids and scrubbed text, the secret, the question, the funnels and the groups, and no user ids.
        $this->assertCount(1, $this->sent);
        $request = $this->sent[0];
        $this->assertSame('http://ai-support-helper:3000/internal/ux-themes', $request->url());
        $this->assertSame(['test-secret'], $request->header('X-Freegle-Internal-Secret'));
        $body = $request->data();
        $this->assertSame('problems', $body['preset']);
        $this->assertSame('Did you have a problem the last time you used Freegle?', $body['question']);
        $this->assertSame(UxRollupService::FLOWS, $body['flows']);
        $this->assertSame(UxPromptService::PROBLEM_GROUPS, $body['groups']);
        $this->assertSame([$a, $b, $c], array_column($body['answers'], 'id'));
        $this->assertSame('Photos will not upload, email me [email]', $body['answers'][0]['text']);
        $this->assertSame('I could not find where to put my postcode [postcode]', $body['answers'][1]['text']);
        $this->assertStringNotContainsString('userid', json_encode($body));
        $this->assertStringNotContainsString('joe@example.com', json_encode($body));

        $problems = DB::table('ux_problems')->where('kind', 'problem')->orderBy('id')->get();
        $this->assertCount(2, $problems);
        $this->assertSame('give', $problems[0]->flow);
        $this->assertSame('Photos and posting', $problems[0]->group_name, 'a problem keeps the group the helper named');
        $this->assertSame(2, (int) $problems[0]->mentions, 'the answer id that was never sent is not counted');
        $this->assertSame(2, (int) $problems[1]->mentions, 'respondents are distinct answers, not statements');
        $this->assertSame($id, (int) $problems[0]->prompt_id);
        $this->assertNull($problems[0]->parent_id);
        $evidence = json_decode($problems[0]->evidence, true);
        $this->assertSame([$a, $c], $evidence['answer_ids']);
        // Only quotes that are in the answer they name, and belong to the theme, are kept; the
        // words are as the member wrote them, not as they were matched.
        $this->assertEquals([['answer_id' => $a, 'span' => 'Photos will not upload'], ['answer_id' => $c, 'span' => 'photos   FAIL on my phone']], $evidence['quotes']);
        $this->assertEqualsWithDelta(round(PlainWords::grade('Photos will not upload'), 2), $evidence['grade'], 0.0001);
        $this->assertSame('A very long title A very long title A very long title A very', $problems[1]->title, 'cut at a word end, to 60');
        $this->assertSame(60, mb_strlen($problems[1]->title));
        $this->assertNull($problems[1]->flow, 'a flow that is not a funnel means not tied to one');
        $this->assertSame('Other', $problems[1]->group_name, 'a group that is not in the list is Other');

        $value = DB::table('ux_problems')->where('kind', 'value')->first();
        $this->assertSame('Giving to neighbours', $value->title);
        $this->assertSame(2, (int) $value->mentions);
        $this->assertEquals([['answer_id' => $c, 'span' => 'add a retry']], json_decode($value->evidence, true)['quotes']);
        $this->assertSame(1, DB::table('ux_problems')->where('kind', 'value')->count());
        $this->assertNull($value->flow);
        $this->assertNull($value->group_name);

        // A member's suggested fix is a problem row of its own kind, under the problem it answers.
        $suggestions = DB::table('ux_problems')->where('kind', 'suggestion')->orderBy('id')->get();
        $this->assertCount(2, $suggestions);
        $this->assertSame((int) $problems[0]->id, (int) $suggestions[0]->parent_id);
        $this->assertSame('Retry failed photo uploads', $suggestions[0]->title);
        $this->assertSame('give', $suggestions[0]->flow, 'a suggestion takes the funnel of its problem');
        $this->assertSame($id, (int) $suggestions[0]->prompt_id);
        $this->assertSame(1, (int) $suggestions[0]->mentions);
        $this->assertSame([$c], json_decode($suggestions[0]->evidence, true)['answer_ids']);
        $this->assertSame((int) $problems[1]->id, (int) $suggestions[1]->parent_id);
        $this->assertNull($suggestions[1]->flow, 'the problem it answers belongs to no funnel');
        // The suggestion for a problem that was not filed has no parent, so it is not stored at all.
        $this->assertSame(0, DB::table('ux_problems')->where('title', 'Points at a dropped problem')->count());
        $this->assertSame(0, DB::table('ux_problems')->whereNull('parent_id')->where('kind', 'suggestion')->count());
    }

    public function test_an_analysed_prompt_is_not_analysed_again_without_force_and_force_replaces_its_themes(): void
    {
        $id = $this->service->openOpen('problems');
        [$a, $b] = $this->answer($id, ['Photos will not upload', 'Photos fail']);
        $this->helper['body'] = ['problems' => [['title' => 'Photos', 'description' => '', 'flow' => 'give', 'group' => 'Photos and posting', 'answer_ids' => [$a, $b], 'quotes' => [['answer_id' => $a, 'span' => 'Photos']]]],
            'solutions' => [['title' => 'Retry', 'problem_index' => 0, 'answer_ids' => [$a]]], 'values' => [], 'unclear_ids' => []];
        $this->service->analyse($id);

        try {
            $this->service->analyse($id);
            $this->fail('analysed twice');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('analysed already', $e->getMessage());
        }
        $this->assertCount(1, $this->sent);

        $this->helper['body']['problems'][0]['title'] = 'Photos, better worded';
        $this->service->analyse($id, true);

        // Every row of the prompt was replaced: the problem, and the suggestion that sits under it.
        $this->assertSame(['Photos, better worded', 'Retry'], DB::table('ux_problems')->orderBy('id')->pluck('title')->all());
        $this->assertSame(1, DB::table('ux_problems')->where('kind', 'suggestion')->count());
    }

    public function test_a_title_is_cut_at_a_word_end(): void
    {
        $filed = function (string $title): string {
            $id = $this->service->openOpen('problems');
            [$a, $b] = $this->answer($id, ['Some words here', 'Some more words']);
            $this->helper['body'] = ['problems' => [['title' => $title, 'description' => '', 'flow' => null, 'group' => 'Other', 'answer_ids' => [$a, $b], 'quotes' => [['answer_id' => $a, 'span' => 'Some words']]]],
                'solutions' => [], 'values' => [], 'unclear_ids' => []];
            $this->service->analyse($id);

            return DB::table('ux_problems')->where('prompt_id', $id)->value('title');
        };

        // The 61st character is a space, so the first 60 are whole words and all of them stay.
        $sixty = 'Photos fail to load for me on my phone each time I try it ok';
        $this->assertSame(60, mb_strlen($sixty));
        $this->assertSame($sixty, $filed($sixty.' and then there is more'));
        // The limit falls inside a word, so that word goes.
        $whole = 'Photos fail to load for me on my phone every time I try it';
        $this->assertSame($whole, $filed($whole.' okay now'));
        // A comma or a dash left at the cut goes too.
        $this->assertSame($whole, $filed($whole.', so'));
        $this->assertSame($whole, $filed($whole.' - and more'));
        $this->assertSame('One two three four five six seven eight nine ten eleven', $filed('One two three four five six seven eight nine ten eleven, twelve thirteen'));
        // A title with no space in it is cut where it stands.
        $this->assertSame(str_repeat('x', 60), $filed(str_repeat('x', 75)));
        // A short title is left alone, white space tidied.
        $this->assertSame('Photos fail on my phone', $filed("  Photos   fail\non my phone  "));
        // The limit is characters, not bytes.
        $this->assertSame(60, mb_strlen($filed(str_repeat('é', 70))));
    }

    public function test_a_problem_title_that_is_not_plain_words_is_filed_and_flagged(): void
    {
        $id = $this->service->openOpen('problems');
        [$a, $b, $c] = $this->answer($id, ['Notifications come late', 'My phone buzzes late', 'Photo uploads keep failing']);
        $this->helper['body'] = ['problems' => [
            ['title' => 'Notifications arrive late', 'description' => 'They come hours after', 'flow' => 'reply', 'group' => 'Email and notifications', 'answer_ids' => [$a, $b], 'quotes' => [['answer_id' => $a, 'span' => 'come late']]],
            ['title' => 'Photo uploads keep failing on my phone', 'description' => '', 'flow' => 'give', 'group' => 'Photos and posting', 'answer_ids' => [$b, $c], 'quotes' => [['answer_id' => $c, 'span' => 'keep failing']]],
        ], 'solutions' => [], 'values' => [], 'unclear_ids' => []];

        $this->artisan("ux:prompt analyse $id")
            ->expectsOutputToContain('Analysed 3 answers: 2 problems, 0 values, 0 suggestions, 0 unclear.')
            ->expectsOutputToContain('grade 17.1 * Notifications arrive late')
            ->expectsOutputToContain('grade 4.0 Photo uploads keep failing on my phone')
            ->expectsOutputToContain('A * marks a grade above 6. Check that its words are plain.')
            ->assertExitCode(0);

        // The hard title is filed like any other: with its group, its mentions and its grade.
        $hard = DB::table('ux_problems')->where('title', 'Notifications arrive late')->first();
        $this->assertSame('problem', $hard->kind);
        $this->assertSame('Email and notifications', $hard->group_name);
        $this->assertSame(2, (int) $hard->mentions);
        $this->assertSame('open', $hard->status);
        $this->assertEqualsWithDelta(17.05, json_decode($hard->evidence, true)['grade'], 0.0001);
        $plain = DB::table('ux_problems')->where('title', 'Photo uploads keep failing on my phone')->first();
        $this->assertEqualsWithDelta(4.0, json_decode($plain->evidence, true)['grade'], 0.0001);
        $this->assertSame(2, DB::table('ux_problems')->where('kind', 'problem')->count());

        $counts = $this->service->analyse($id, true);
        $this->assertArrayNotHasKey('wording', $counts, 'there is no count of problems left out for their wording, because none is');
        $this->assertSame(['problems', 'values', 'solutions', 'unclear', 'answers', 'suggestions_dropped'], array_keys($counts));
    }

    public function test_the_analyse_output_prints_each_problem_with_its_grade_and_the_dropped_suggestions(): void
    {
        $id = $this->service->openOpen('problems');
        [$a, $b, $c] = $this->answer($id, ['Notifications come late', 'Photo uploads keep failing', 'A single complaint about email']);
        $this->helper['body'] = ['problems' => [
            ['title' => 'Photo uploads keep failing on my phone', 'description' => '', 'flow' => 'give', 'group' => 'Photos and posting', 'answer_ids' => [$b, $c], 'quotes' => [['answer_id' => $b, 'span' => 'keep failing']]],
            ['title' => 'Notifications arrive late', 'description' => '', 'flow' => 'reply', 'group' => 'Email and notifications', 'answer_ids' => [$a, $b, $c], 'quotes' => [['answer_id' => $a, 'span' => 'come late']]],
            ['title' => 'Only one member said this', 'description' => '', 'flow' => 'give', 'group' => 'Other', 'answer_ids' => [$c], 'quotes' => [['answer_id' => $c, 'span' => 'single complaint']]],
        ], 'solutions' => [['title' => 'Show email sooner', 'problem_index' => 2, 'answer_ids' => [$c]]], 'values' => [], 'unclear_ids' => []];

        $this->assertSame(0, Artisan::call("ux:prompt analyse $id"));
        $out = Artisan::output();

        $first = strpos($out, 'Analysed 3 answers: 2 problems, 0 values, 0 suggestions, 0 unclear.');
        // Most mentions first: the notifications problem has three, the photos problem two.
        $late = strpos($out, 'grade 17.1 * Notifications arrive late');
        $photos = strpos($out, 'grade 4.0 Photo uploads keep failing on my phone');
        $legend = strpos($out, 'A * marks a grade above 6. Check that its words are plain.');
        $dropped = strpos($out, '1 suggestion(s) were not stored because their problem was not filed (fewer than two members raised it, or no quote could be checked).');
        foreach (['first line' => $first, 'late' => $late, 'photos' => $photos, 'legend' => $legend, 'dropped' => $dropped] as $name => $at) {
            $this->assertNotFalse($at, "$name is printed: $out");
        }
        $this->assertTrue($first < $late && $late < $photos && $photos < $legend && $legend < $dropped, "in order: $out");
        $this->assertMatchesRegularExpression('/\[\d+\] grade 17\.1 \* Notifications arrive late/', $out);

        // With no hard title and no dropped suggestion, neither extra line is printed.
        $id2 = $this->service->openOpen('values');
        [$x, $y] = $this->answer($id2, ['Photo uploads keep failing', 'Photos fail on my phone']);
        $this->helper['body'] = ['problems' => [['title' => 'Photo uploads keep failing on my phone', 'description' => '', 'flow' => 'give', 'group' => 'Photos and posting', 'answer_ids' => [$x, $y], 'quotes' => [['answer_id' => $x, 'span' => 'keep failing']]]],
            'solutions' => [], 'values' => [], 'unclear_ids' => []];
        $this->assertSame(0, Artisan::call("ux:prompt analyse $id2"));
        $quiet = Artisan::output();
        $this->assertStringContainsString('grade 4.0 Photo uploads keep failing on my phone', $quiet);
        $this->assertStringNotContainsString('A * marks', $quiet);
        $this->assertStringNotContainsString('suggestion(s) were not stored', $quiet);
    }

    public function test_a_suggestion_for_a_problem_that_was_not_filed_is_not_stored_and_is_counted(): void
    {
        $id = $this->service->openOpen('problems');
        [$a, $b, $c] = $this->answer($id, ['Photos fail on my phone', 'Photos will not upload', 'Only I find emails too many']);
        $this->helper['body'] = ['problems' => [
            ['title' => 'Photos fail to upload', 'description' => '', 'flow' => 'give', 'group' => 'Photos and posting', 'answer_ids' => [$a, $b], 'quotes' => [['answer_id' => $a, 'span' => 'Photos fail']]],
            ['title' => 'Too many emails', 'description' => '', 'flow' => null, 'group' => 'Email and notifications', 'answer_ids' => [$c], 'quotes' => [['answer_id' => $c, 'span' => 'emails too many']]],
        ], 'solutions' => [
            ['title' => 'Let me retry a photo', 'problem_index' => 0, 'answer_ids' => [$a]],
            ['title' => 'Send fewer emails', 'problem_index' => 1, 'answer_ids' => [$c]],
        ], 'values' => [], 'unclear_ids' => []];

        $result = $this->service->analyse($id);

        $this->assertSame(1, $result['problems'], 'a problem raised by one member is not filed');
        $this->assertSame(1, $result['solutions']);
        $this->assertSame(1, $result['suggestions_dropped']);
        $this->assertSame(['Let me retry a photo'], DB::table('ux_problems')->where('kind', 'suggestion')->pluck('title')->all());
        $this->assertSame(0, DB::table('ux_problems')->where('title', 'Send fewer emails')->count());
    }

    public function test_each_problem_gets_a_group_and_an_unknown_group_becomes_other(): void
    {
        $id = $this->service->openOpen('problems');
        $ids = $this->answer($id, ['one', 'two', 'three']);
        [$a, $b] = $ids;
        $theme = fn (string $title, $group) => ['title' => $title, 'description' => '', 'flow' => null, 'group' => $group, 'answer_ids' => [$a, $b], 'quotes' => [['answer_id' => $a, 'span' => 'one']]];
        $this->helper['body'] = ['problems' => [
            $theme('Search shows far away items', 'Finding things'),
            $theme('Cannot reset my password', 'Account and settings'),
            $theme('The coder invented a group', 'Gardening'),
            $theme('The coder gave no group', null),
            $theme('A group in the wrong case', 'finding things'),
        ], 'solutions' => [], 'values' => [], 'unclear_ids' => []];

        $this->service->analyse($id);

        $group = fn (string $title) => DB::table('ux_problems')->where('title', $title)->value('group_name');
        $this->assertSame('Finding things', $group('Search shows far away items'));
        $this->assertSame('Account and settings', $group('Cannot reset my password'));
        $this->assertSame('Other', $group('The coder invented a group'));
        $this->assertSame('Other', $group('The coder gave no group'));
        $this->assertSame('Other', $group('A group in the wrong case'));
        $this->assertEmpty(array_diff(DB::table('ux_problems')->pluck('group_name')->all(), UxPromptService::groupNames()), 'every stored group is in the list');
    }

    public function test_the_leftover_theme_is_stored_as_kind_other_and_not_ranked(): void
    {
        // The coder titles what fits no theme "Other". It is stored as its own kind, so a query for problems leaves it out.
        $id = $this->service->openOpen('problems');
        [$a, $b, $c] = $this->answer($id, ['Photos fail', 'Photos will not upload', 'Odd thing nobody else said']);
        $this->helper['body'] = ['problems' => [
            ['title' => 'Photos fail to upload', 'description' => '', 'flow' => 'give', 'group' => 'Photos and posting', 'answer_ids' => [$a, $b], 'quotes' => [['answer_id' => $a, 'span' => 'Photos fail']]],
            ['title' => 'Other', 'description' => 'What fits no theme', 'flow' => null, 'group' => 'Other', 'answer_ids' => [$b, $c], 'quotes' => [['answer_id' => $c, 'span' => 'Odd thing']]],
        ], 'solutions' => [['title' => 'A wish about the leftovers', 'problem_index' => 1, 'answer_ids' => [$c]]], 'values' => [], 'unclear_ids' => []];

        $result = $this->service->analyse($id);

        $leftover = DB::table('ux_problems')->where('title', 'Other')->first();
        $this->assertSame('other', $leftover->kind);
        $this->assertNull($leftover->group_name);
        $this->assertSame(2, (int) $leftover->mentions);
        $this->assertArrayNotHasKey('grade', json_decode($leftover->evidence, true), 'only the titles members read are graded');
        $this->assertSame(1, $result['problems'], 'it is not counted as a problem');
        $this->assertSame(1, $result['suggestions_dropped'], 'and a suggestion cannot sit under it');
        $this->assertSame(1, DB::table('ux_problems')->where('kind', 'problem')->count());

        // A follow-up never offers it, however many members it holds.
        $prompt = $this->rankable();
        $this->problem($prompt, 'Other', 50, 'other');
        $first = $this->problem($prompt, 'Photos are slow', 5);
        $second = $this->problem($prompt, 'Too many emails', 3);
        $follow = DB::table('ux_prompts')->find($this->service->followup($prompt, 200, true));
        $this->assertSame(['problem:'.$first, 'problem:'.$second], array_column(json_decode($follow->options, true), 'key'));
    }

    public function test_a_suggestion_is_a_problem_row_with_a_parent_and_is_never_a_problem_of_its_own(): void
    {
        $prompt = $this->rankable();
        $problem = $this->problem($prompt, 'Photos are slow', 5);
        $this->problem($prompt, 'Too many emails', 3);
        // A suggestion raised by many members still is not a problem to rank.
        $suggestion = $this->problem($prompt, 'Let me retry a photo', 9, 'suggestion', 'open', $problem);

        $follow = DB::table('ux_prompts')->find($this->service->followup($prompt, 200, true));

        $keys = array_column(json_decode($follow->options, true), 'key');
        $this->assertCount(2, $keys);
        $this->assertNotContains('problem:'.$suggestion, $keys);
        $row = DB::table('ux_problems')->find($suggestion);
        $this->assertSame($problem, (int) $row->parent_id);
        $this->assertSame('suggestion', $row->kind);
    }

    public function test_analysing_refuses_what_it_cannot_do(): void
    {
        $id = $this->service->openOpen('problems');
        $this->answer($id, [['choice' => 'nothing'], ['skipped' => true]]);

        $this->assertRefused(fn () => $this->service->analyse($id), 'no written answers');
        $this->assertRefused(fn () => $this->service->analyse(999999), 'No open prompt');
        $survey = $this->service->openSurvey();
        $this->assertRefused(fn () => $this->service->analyse($survey), 'No open prompt');

        $this->answer($id, ['Something']);
        config()->set('freegle.ux.prompts.internal_secret', '');
        $this->assertRefused(fn () => $this->service->analyse($id), 'UX_INTERNAL_SECRET');
        $this->assertCount(0, $this->sent);
    }

    public function test_analyse_and_regroup_are_refused_until_the_privacy_gate_is_cleared(): void
    {
        $id = $this->service->openOpen('problems');
        [$a, $b] = $this->answer($id, ['Photos fail', 'Photos will not upload']);
        $problem = $this->problem($id, 'Photos fail to upload', 4);

        // Closed, whatever the value is that is not exactly on: the words off and no must not read as yes.
        foreach ([false, 'off', 'no', '0', 0, 'false', '', null, 'yes', 'on', 2] as $closed) {
            config()->set('freegle.ux.prompts.privacy_cleared', $closed);
            $this->assertRefused(fn () => $this->service->analyse($id), 'UX_PRIVACY_CLEARED');
            $this->assertRefused(fn () => $this->service->regroup($id), 'UX_PRIVACY_CLEARED');
        }
        $this->assertCount(0, $this->sent, 'nothing left the building');
        $this->artisan("ux:prompt analyse $id")->expectsOutputToContain('Free-text answers go to an AI service for coding')->assertExitCode(1);
        $this->artisan("ux:prompt regroup $id")->expectsOutputToContain('only after the public privacy wording has been checked')->assertExitCode(1);
        $this->assertCount(0, $this->sent);

        // Open on purpose: true, or the number or the word one.
        $this->helper['body'] = ['problems' => [['title' => 'Photos fail to upload', 'description' => '', 'flow' => null, 'group' => 'Other', 'answer_ids' => [$a, $b], 'quotes' => [['answer_id' => $a, 'span' => 'Photos fail']]]],
            'solutions' => [], 'values' => [], 'unclear_ids' => []];
        DB::table('ux_problems')->where('id', $problem)->delete();
        foreach ([true, 1, '1', 'true', 'TRUE'] as $open) {
            config()->set('freegle.ux.prompts.privacy_cleared', $open);
            $this->assertSame(1, $this->service->analyse($id, true)['problems']);
        }
        $this->assertCount(5, $this->sent);
    }

    public function test_regroup_changes_only_group_names_and_sends_no_answer_text(): void
    {
        $id = $this->service->openOpen('problems');
        [$a, $b] = $this->answer($id, ['Dave at Elm Road takes my offers', 'My email is too many']);
        $one = DB::table('ux_problems')->insertGetId([
            'flow' => 'give', 'kind' => 'problem', 'group_name' => 'Other', 'title' => 'Photos fail to upload', 'description' => 'Upload fails on phones',
            'prompt_id' => $id, 'mentions' => 4, 'weight' => 0.5, 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
            'evidence' => json_encode(['answer_ids' => [$a, $b], 'quotes' => [['answer_id' => $a, 'span' => 'Dave at Elm Road']], 'grade' => 4.0]),
        ]);
        $two = $this->problem($id, 'Too many emails', 3, 'problem', 'open', null, 'Email and notifications');
        $three = $this->problem($id, 'Search shows far away items', 3, 'problem', 'open', null, 'Other');
        $leftover = $this->problem($id, 'Other', 7, 'other');
        $elsewhere = $this->problem($this->service->openOpen('values'), 'A problem of another prompt', 3);
        $before = DB::table('ux_problems')->get()->keyBy('id');
        $this->helper['body'] = ['assignments' => [
            ['id' => $one, 'group' => 'Photos and posting'],
            ['id' => $two, 'group' => 'Email and notifications'],
            ['id' => $three, 'group' => 'A group nobody listed'],
            ['id' => $elsewhere, 'group' => 'Finding things'],
            ['id' => 424242, 'group' => 'Finding things'],
            ['id' => $leftover, 'group' => 'Finding things'],
        ], 'costUsd' => 0.01, 'model' => 'x'];

        $result = $this->service->regroup($id);

        $this->assertSame(['problems' => 3, 'changed' => 1], $result, 'one moved; one stayed; one unknown group stayed Other');
        $this->assertCount(1, $this->sent);
        $request = $this->sent[0];
        $this->assertSame('http://ai-support-helper:3000/internal/ux-groups', $request->url());
        $this->assertSame(['test-secret'], $request->header('X-Freegle-Internal-Secret'));
        $body = $request->data();
        $this->assertSame(UxPromptService::PROBLEM_GROUPS, $body['groups']);
        $this->assertSame([
            ['id' => $one, 'title' => 'Photos fail to upload', 'description' => 'Upload fails on phones'],
            ['id' => $two, 'title' => 'Too many emails', 'description' => ''],
            ['id' => $three, 'title' => 'Search shows far away items', 'description' => ''],
        ], $body['problems'], 'titles and descriptions only, and only the prompt\'s problems');
        $this->assertSame(['groups', 'problems'], array_keys($body));
        foreach (['Dave', 'Elm Road', 'answer', 'quote', 'span', 'evidence', 'userid'] as $never) {
            $this->assertStringNotContainsString($never, json_encode($body), "$never must not be sent");
        }

        $after = DB::table('ux_problems')->get()->keyBy('id');
        $this->assertSame('Photos and posting', $after[$one]->group_name);
        $this->assertSame('Email and notifications', $after[$two]->group_name);
        $this->assertSame('Other', $after[$three]->group_name);
        $this->assertSame('Other', $after[$elsewhere]->group_name, 'a problem of another prompt is not touched');
        $this->assertNull($after[$leftover]->group_name);
        foreach ($before as $pid => $row) {
            foreach (['title', 'description', 'mentions', 'weight', 'evidence', 'kind', 'status', 'flow', 'parent_id', 'prompt_id'] as $column) {
                $this->assertEquals($row->$column, $after[$pid]->$column, "$column of problem $pid is untouched");
            }
        }
        $this->assertSame(['Dave at Elm Road takes my offers', 'My email is too many'],
            DB::table('ux_prompt_responses')->where('prompt_id', $id)->orderBy('id')->pluck('answer')->map(fn ($j) => json_decode($j, true)['text'])->all(), 'the answers are untouched');

        $this->artisan("ux:prompt regroup $id")->expectsOutputToContain('Regrouped: 0 of 3 problem(s) changed group.')->assertExitCode(0);
        $this->assertRefused(fn () => $this->service->regroup(999999), 'No open prompt');
        $empty = $this->service->openOpen('improve');
        $this->assertRefused(fn () => $this->service->regroup($empty), 'no problems to group');
    }

    public function test_analysing_fails_cleanly_when_the_helper_does(): void
    {
        $id = $this->service->openOpen('problems');
        $this->answer($id, ['Something']);

        $this->helper['status'] = 502;
        $this->assertRefused(fn () => $this->service->analyse($id), '502');
        $this->helper['status'] = 200;
        $this->helper['body'] = ['nope' => true];
        $this->assertRefused(fn () => $this->service->analyse($id), 'no problems list');
        $this->helper['throw'] = true;
        $this->assertRefused(fn () => $this->service->analyse($id), 'could not be reached');

        $this->assertSame(0, DB::table('ux_problems')->count());
    }

    public function test_a_cut_connection_to_the_helper_is_tried_once_more(): void
    {
        $id = $this->service->openOpen('problems');
        [$a, $b, $c] = $this->answer($id, ['One', 'Two', 'Three']);
        $this->helper['throw_first'] = 1;
        $this->helper['body'] = ['problems' => [['title' => 'Slow', 'description' => '', 'flow' => null, 'group' => 'Other', 'answer_ids' => [$a, $b, $c], 'quotes' => [['answer_id' => $a, 'span' => 'One']]]],
            'solutions' => [], 'values' => [], 'unclear_ids' => []];

        $counts = $this->service->analyse($id);

        $this->assertCount(2, $this->sent, 'one cut attempt and one that went through');
        $this->assertSame(1, $counts['problems']);
    }

    public function test_the_wait_for_the_themes_outlasts_the_helpers_own_limit(): void
    {
        // The helper gives up after 10 minutes (UX_THEMES_TIMEOUT_MS in claude-agent-sdk); if the
        // batch hung up first the helper would abort and every retry would fail the same way.
        $source = file_get_contents(base_path('config/freegle.php'));
        preg_match("/'themes_timeout' => \(int\) EnvSetting::env\('UX_THEMES_TIMEOUT', (\d+)\)/", $source, $m);
        $this->assertGreaterThan(600, (int) ($m[1] ?? 0));
    }

    private function assertRefused(callable $do, string $fragment): void
    {
        try {
            $do();
            $this->fail("expected a refusal containing '$fragment'");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    public function test_the_command_analyses_and_follows_up(): void
    {
        $id = $this->service->openOpen('problems');
        [$a, $b, $c] = $this->answer($id, ['One thing', 'Another thing', 'A third thing']);
        $this->helper['body'] = ['problems' => [
            ['title' => 'First problem', 'description' => '', 'flow' => 'give', 'group' => 'Other', 'answer_ids' => [$a, $b, $c], 'quotes' => [['answer_id' => $a, 'span' => 'One thing']]],
            ['title' => 'Second problem', 'description' => '', 'flow' => null, 'group' => 'Other', 'answer_ids' => [$a, $b, $c], 'quotes' => [['answer_id' => $b, 'span' => 'Another']]],
        ], 'solutions' => [], 'values' => [], 'unclear_ids' => []];

        $this->artisan("ux:prompt analyse $id")->expectsOutputToContain('Analysed 3 answers: 2 problems, 0 values, 0 suggestions, 0 unclear.')->assertExitCode(0);
        $this->artisan("ux:prompt analyse $id")->expectsOutputToContain('analysed already')->assertExitCode(1);
        // Three written answers are not enough to rank anything.
        $this->artisan("ux:prompt followup $id --confirm")->expectsOutputToContain('3 written answers')->assertExitCode(1);
        $this->answer($id, array_fill(0, 97, 'More words'));
        // The 97 arrived after the themes were drawn, so the ranking still waits: it ranks themes
        // from 3 answers and must not say they came from 100.
        $this->artisan("ux:prompt followup $id --confirm")->expectsOutputToContain('analysed over 3 written answers')->assertExitCode(1);
        $this->artisan("ux:prompt analyse $id --force")->expectsOutputToContain('Analysed 100 answers')->assertExitCode(0);
        // The titles are shown first and nothing is opened until they are confirmed.
        $this->artisan("ux:prompt followup $id")->expectsOutputToContain('First problem')->assertExitCode(1);
        $this->assertSame(0, DB::table('ux_prompts')->where('kind', 'survey')->count());
        $this->artisan("ux:prompt followup $id --confirm")->expectsOutputToContain('Opened prompt')->assertExitCode(0);
        $this->artisan('ux:prompt followup 999999')->assertExitCode(1);
        $this->artisan('ux:prompt analyse')->assertExitCode(1);
    }

    // ---------------------------------------------------------------- the follow-up

    private function problem(int $prompt, string $title, int $mentions, string $kind = 'problem', string $status = 'open', ?int $parent = null, string $group = 'Other'): int
    {
        return DB::table('ux_problems')->insertGetId([
            'flow' => 'give', 'kind' => $kind, 'group_name' => $kind === 'problem' ? $group : null, 'title' => $title, 'prompt_id' => $prompt,
            'parent_id' => $parent, 'mentions' => $mentions, 'status' => $status, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }

    /** A prompt with enough written answers for its problems to be ranked. */
    private function rankable(string $preset = 'problems', int $written = 100): int
    {
        $prompt = $this->service->openOpen($preset);
        $this->answer($prompt, array_fill(0, $written, 'Some words'));
        $this->analysedOver($prompt, $written);

        return $prompt;
    }

    /** What analyse() records: how many answers the themes were drawn from. */
    private function analysedOver(int $prompt, int $n): void
    {
        $settings = json_decode((string) DB::table('ux_prompts')->where('id', $prompt)->value('settings'), true) ?: [];
        $settings['analysed_answers'] = $n;
        DB::table('ux_prompts')->where('id', $prompt)->update(['settings' => json_encode($settings)]);
    }

    public function test_the_follow_up_ranks_the_eight_most_mentioned_problems_three_at_a_time(): void
    {
        $prompt = $this->rankable();
        $ids = [];
        foreach (range(1, 12) as $n) {
            $ids[$n] = $this->problem($prompt, "Problem $n", $n);
        }
        $this->problem($prompt, 'A value', 99, 'value');
        $this->problem($prompt, 'Closed', 99, 'problem', 'closed');
        $this->problem($prompt + 1, 'Another prompt', 99);

        $follow = DB::table('ux_prompts')->find($this->service->followup($prompt, 200, true));

        $this->assertSame('survey', $follow->kind);
        $options = json_decode($follow->options, true);
        $this->assertCount(8, $options);
        $this->assertSame('problem:'.$ids[12], $options[0]['key']);
        $this->assertSame('Problem 12', $options[0]['label']);
        $this->assertSame('problem:'.$ids[5], $options[7]['key']);
        $this->assertSame('Which of these has been your biggest problem in the last 3 months?', $follow->question);
        $this->assertSame('', $follow->body);
        $this->assertEquals(['show' => 3, 'none_label' => 'None of these', 'followup_of' => $prompt], json_decode($follow->settings, true));
        $this->assertSame('open', $follow->status);
    }

    public function test_the_follow_up_asks_one_pick_three_at_a_time(): void
    {
        $prompt = $this->rankable();
        $this->problem($prompt, 'Photos are slow', 5);
        $this->problem($prompt, 'Too many emails', 4);
        $this->problem($prompt, 'Chats are hard to find', 3);
        $this->problem($prompt, 'Posts wait too long', 3);

        $settings = json_decode(DB::table('ux_prompts')->find($this->service->followup($prompt, 200, true))->settings, true);

        $this->assertSame(3, $settings['show']);
        $this->assertSame(UxPromptService::FOLLOWUP_SHOW, $settings['show']);
        $this->assertArrayNotHasKey('worst_label', $settings, 'no second pick, so the card has one step');
        $this->assertArrayNotHasKey('best_label', $settings);
        $this->assertArrayNotHasKey('question_hint', $settings);
        // The area survey keeps its second pick and shows four.
        $survey = json_decode(DB::table('ux_prompts')->find($this->service->openSurvey())->settings, true);
        $this->assertSame(4, $survey['show']);
        $this->assertArrayHasKey('worst_label', $survey);
    }

    public function test_a_follow_up_needs_two_problems_and_replaces_the_older_one_for_the_same_prompt(): void
    {
        $prompt = $this->rankable();
        $this->problem($prompt, 'Only one', 3);
        $this->assertRefused(fn () => $this->service->followup($prompt, 200, true), 'fewer than two');

        $this->problem($prompt, 'Second', 3);
        $first = $this->service->followup($prompt, 200, true);
        $survey = $this->service->openSurvey();
        $second = $this->service->followup($prompt, 200, true);

        $this->assertSame('closed', DB::table('ux_prompts')->find($first)->status);
        $this->assertSame('open', DB::table('ux_prompts')->find($second)->status);
        // The ordinary survey is a different thing: opening it did not close the follow-up, and the
        // follow-up did not close it.
        $this->assertSame('open', DB::table('ux_prompts')->find($survey)->status);
        $third = $this->service->openSurvey();
        $this->assertSame('open', DB::table('ux_prompts')->find($second)->status);
        $this->assertSame('closed', DB::table('ux_prompts')->find($survey)->status);
        $this->assertSame('open', DB::table('ux_prompts')->find($third)->status);
    }

    public function test_a_theme_only_one_or_two_members_raised_is_never_shown_to_other_members(): void
    {
        $prompt = $this->rankable();
        $this->problem($prompt, 'Moderator Jane Smith at Anytown deletes my posts', 1);
        $this->problem($prompt, 'Two members said this', 2);
        $a = $this->problem($prompt, 'Photos are slow', 5);
        $b = $this->problem($prompt, 'Too many emails', 3);

        $follow = DB::table('ux_prompts')->find($this->service->followup($prompt, 200, true));

        $this->assertSame(['problem:'.$a, 'problem:'.$b], array_column(json_decode($follow->options, true), 'key'));
    }

    public function test_the_follow_up_lists_the_titles_and_opens_nothing_until_they_are_confirmed(): void
    {
        $prompt = $this->rankable();
        $this->problem($prompt, 'Photos are slow', 5);
        $this->problem($prompt, 'Too many emails', 3);

        $this->assertRefused(fn () => $this->service->followup($prompt), 'Photos are slow');
        $this->assertRefused(fn () => $this->service->followup($prompt), '5 of 100 who answered');
        $this->assertRefused(fn () => $this->service->followup($prompt), '--confirm');
        $this->assertSame(0, DB::table('ux_prompts')->where('kind', 'survey')->count());
    }

    public function test_the_followup_without_confirm_lists_the_titles_with_their_grades_and_marks_the_hard_ones(): void
    {
        $prompt = $this->rankable();
        $hard = $this->problem($prompt, 'Notifications arrive late', 5);
        $plain = $this->problem($prompt, 'Photo uploads keep failing on my phone', 4);
        $stored = $this->problem($prompt, 'Too many emails', 3);
        // A grade kept when the problem was filed is the one printed; one without it is worked out.
        DB::table('ux_problems')->where('id', $stored)->update(['evidence' => json_encode(['grade' => 7.25])]);

        try {
            $this->service->followup($prompt);
            $this->fail('listed nothing');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString("  [$hard] (5 of 100 who answered) grade 17.1 * Notifications arrive late", $message);
        $this->assertStringContainsString("  [$plain] (4 of 100 who answered) grade 4.0 Photo uploads keep failing on my phone", $message);
        $this->assertStringContainsString("  [$stored] (3 of 100 who answered) grade 7.3 * Too many emails", $message);
        $this->assertStringNotContainsString('grade 4.0 *', $message, 'a plain title has no star');
        // It asks for the two checks, looking at the starred ones first, and names --skip.
        $this->assertStringContainsString('None names a person, a place or an account', $message);
        $this->assertStringContainsString('one short sentence in plain words', $message);
        $this->assertStringContainsString('"chat", not "conversation"', $message);
        $this->assertStringContainsString('Look at the ones marked * first', $message);
        $this->assertStringContainsString('--skip=<ids>', $message);
        $this->assertStringContainsString('--confirm', $message);
        $this->assertSame(0, DB::table('ux_prompts')->where('kind', 'survey')->count());
    }

    public function test_followup_skip_leaves_out_the_named_problems(): void
    {
        $prompt = $this->rankable();
        $a = $this->problem($prompt, 'Photos are slow', 5);
        $b = $this->problem($prompt, 'Too many emails', 4);
        $c = $this->problem($prompt, 'Chats are hard to find', 3);
        $ninth = [];
        foreach (range(1, 6) as $n) {
            $ninth[] = $this->problem($prompt, "Problem $n", 3);
        }

        // Without --confirm the listing applies --skip too: it shows what would be asked.
        try {
            $this->service->followup($prompt, 200, false, [$b]);
            $this->fail('listed nothing');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("[$a]", $e->getMessage());
            $this->assertStringNotContainsString("[$b]", $e->getMessage());
            $this->assertStringContainsString("[$c]", $e->getMessage());
        }

        $follow = DB::table('ux_prompts')->find($this->service->followup($prompt, 200, true, [$b]));

        $keys = array_column(json_decode($follow->options, true), 'key');
        $this->assertNotContains('problem:'.$b, $keys);
        $this->assertContains('problem:'.$a, $keys);
        $this->assertContains('problem:'.$c, $keys);
        $this->assertCount(7, $keys, 'the eight listed, less the one left out; nothing is pulled in that was not listed');
        $this->assertNotContains('problem:'.$ninth[5], $keys, 'the ninth problem was never listed, so it is not asked about');
        // Skipping is not dismissing: the problem stays open for the tab.
        $this->assertSame('open', DB::table('ux_problems')->find($b)->status);

        // The command takes the ids as a list.
        $this->artisan("ux:prompt followup $prompt --skip=$a,$b")->expectsOutputToContain("[$c]")->assertExitCode(1);
        $this->artisan("ux:prompt followup $prompt --skip=$a,$b --confirm")->expectsOutputToContain('Opened prompt')->assertExitCode(0);
        $this->artisan("ux:prompt followup $prompt --skip=oops")->expectsOutputToContain('--skip takes problem ids')->assertExitCode(1);
    }

    public function test_followup_skip_refuses_an_id_that_is_not_listed(): void
    {
        $prompt = $this->rankable();
        $this->problem($prompt, 'Photos are slow', 5);
        $this->problem($prompt, 'Too many emails', 4);
        $this->problem($prompt, 'Chats are hard to find', 3);
        $value = $this->problem($prompt, 'A value', 9, 'value');
        $closed = $this->problem($prompt, 'Closed one', 9, 'problem', 'closed');
        $thin = $this->problem($prompt, 'Two members', 2);
        $elsewhere = $this->problem($prompt + 1, 'Another prompt', 9);

        foreach ([999999, $value, $closed, $thin, $elsewhere] as $notListed) {
            $this->assertRefused(fn () => $this->service->followup($prompt, 200, true, [$notListed]), "Problem $notListed is not one of the titles listed.");
            $this->assertRefused(fn () => $this->service->followup($prompt, 200, false, [$notListed]), "Problem $notListed is not one of the titles listed.");
        }
        $this->assertSame(0, DB::table('ux_prompts')->where('kind', 'survey')->count());
    }

    public function test_followup_is_refused_when_fewer_than_two_problems_remain_after_the_skip(): void
    {
        $prompt = $this->rankable();
        $a = $this->problem($prompt, 'Photos are slow', 5);
        $b = $this->problem($prompt, 'Too many emails', 4);
        $c = $this->problem($prompt, 'Chats are hard to find', 3);

        $this->assertRefused(fn () => $this->service->followup($prompt, 200, true, [$a, $b]), 'fewer than two problems left');
        $this->assertRefused(fn () => $this->service->followup($prompt, 200, false, [$a, $b, $c]), 'fewer than two problems left');
        $this->assertSame(0, DB::table('ux_prompts')->where('kind', 'survey')->count());
        $this->assertSame(['open', 'open', 'open'], DB::table('ux_problems')->whereIn('id', [$a, $b, $c])->orderBy('id')->pluck('status')->all(), 'a skipped problem is not dismissed');
        // Two left is enough.
        $this->assertGreaterThan(0, $this->service->followup($prompt, 200, true, [$a]));
    }

    public function test_a_follow_up_is_refused_below_a_hundred_written_answers(): void
    {
        $prompt = $this->rankable('problems', 99);
        // Picks, skips and "no problems" are answers but not words.
        $this->answer($prompt, [['choice' => 'nothing'], ['skipped' => true], ['text' => '   '], ['choice' => 'dont_understand']]);
        $this->problem($prompt, 'A', 5);
        $this->problem($prompt, 'B', 4);

        $this->assertRefused(fn () => $this->service->followup($prompt, 200, true), '99 written answers');
        $this->assertRefused(fn () => $this->service->followup($prompt, 200, true), 'next answer still often adds a theme');
        $this->assertSame(0, DB::table('ux_prompts')->where('kind', 'survey')->count());

        // Answers that arrive after the analysis do not count: the themes were not drawn from them.
        $this->answer($prompt, array_fill(0, 40, 'Arrived later'));
        $this->assertRefused(fn () => $this->service->followup($prompt, 200, true), 'analysed over 99 written answers');

        // Analysing again draws the themes from all of them.
        $this->analysedOver($prompt, 140);
        $this->assertGreaterThan(0, $this->service->followup($prompt, 200, true));
    }

    public function test_analysing_records_how_many_answers_the_themes_were_drawn_from(): void
    {
        $id = $this->service->openOpen('problems');
        [$a, $b, $c] = $this->answer($id, ['One thing', 'Another thing', 'A third thing']);
        $this->helper['body'] = ['problems' => [
            ['title' => 'First problem', 'description' => '', 'flow' => 'give', 'group' => 'Other', 'answer_ids' => [$a, $b], 'quotes' => [['answer_id' => $a, 'span' => 'One thing']]],
        ], 'solutions' => [], 'values' => [], 'unclear_ids' => []];

        $this->service->analyse($id);

        $settings = json_decode(DB::table('ux_prompts')->where('id', $id)->value('settings'), true);
        $this->assertSame(3, $settings['analysed_answers']);
        $this->assertSame('problems', $settings['preset'], 'what was already in the settings is kept');
        $this->assertSame('If yes, what happened?', $settings['placeholder']);
    }

    public function test_the_tick_weighs_problems_by_how_often_each_was_shown(): void
    {
        $prompt = $this->rankable();
        $a = $this->problem($prompt, 'A', 5);
        $b = $this->problem($prompt, 'B', 4);
        $c = $this->problem($prompt, 'C', 3);
        $d = $this->problem($prompt, 'D', 3);
        $follow = $this->service->followup($prompt, 200, true);
        $all = ["problem:$a", "problem:$b", "problem:$c"];
        $this->answer($follow, [
            ['best' => "problem:$a", 'shown' => $all],
            ['best' => "problem:$a", 'shown' => ["problem:$a", "problem:$b", "problem:$d"]],
            ['best' => "problem:$b", 'shown' => $all],
            ['choice' => 'none', 'shown' => ["problem:$b", "problem:$c", "problem:$d"]],
            // These are not answers: a skip, and "I don't understand".
            ['skipped' => true],
            ['choice' => 'dont_understand'],
        ]);

        $this->assertSame(4, $this->service->weighFollowups(), 'D was shown twice but never picked, so it is weighed too');
        $this->assertEquals(0.0, (float) DB::table('ux_problems')->find($d)->weight);

        // A: shown 3 times, picked twice. B: shown 4, picked once. C: shown 3, never picked.
        $weight = fn ($id) => (float) DB::table('ux_problems')->find($id)->weight;
        $this->assertEquals(0.667, $weight($a));
        $this->assertEquals(0.25, $weight($b));
        $this->assertEquals(0.0, $weight($c));
        $evidence = json_decode(DB::table('ux_problems')->find($a)->evidence ?? '', true);
        $this->assertEquals(['prompt_id' => $follow, 'most' => 2, 'times_shown' => 3], $evidence['ranking']);
        $this->assertSame(4, json_decode(DB::table('ux_problems')->find($b)->evidence, true)['ranking']['times_shown']);
    }

    public function test_the_weight_is_the_share_of_times_shown_picked_as_biggest(): void
    {
        $prompt = $this->rankable();
        $a = $this->problem($prompt, 'A', 5);
        $b = $this->problem($prompt, 'B', 4);
        $c = $this->problem($prompt, 'C', 3);
        $follow = $this->service->followup($prompt, 200, true);
        $shown = ["problem:$a", "problem:$b", "problem:$c"];
        // Four members saw all three. A was picked once, B twice, C never. One also sent a second pick,
        // as an old client could: it is not part of the weight.
        $this->answer($follow, [
            ['best' => "problem:$a", 'worst' => "problem:$b", 'shown' => $shown],
            ['best' => "problem:$b", 'shown' => $shown],
            ['best' => "problem:$b", 'shown' => $shown],
            ['choice' => 'none', 'shown' => $shown],
        ]);

        $this->assertSame(3, $this->service->weighFollowups());

        $weight = fn ($id) => (float) DB::table('ux_problems')->find($id)->weight;
        $this->assertEquals(0.25, $weight($a), '1 pick in 4 times shown');
        $this->assertEquals(0.5, $weight($b), '2 picks in 4, and being picked as the worst changed nothing');
        $this->assertEquals(0.0, $weight($c));
        $this->assertArrayNotHasKey('least', json_decode(DB::table('ux_problems')->find($b)->evidence, true)['ranking']);
    }

    public function test_weighing_keeps_the_evidence_the_problem_already_had_and_skips_what_was_never_shown(): void
    {
        $prompt = $this->rankable();
        $a = $this->problem($prompt, 'A', 5);
        $b = $this->problem($prompt, 'B', 4);
        $c = $this->problem($prompt, 'C', 3);
        DB::table('ux_problems')->where('id', $a)->update(['evidence' => json_encode(['answer_ids' => [1, 2], 'quotes' => [['answer_id' => 1, 'span' => 'x']], 'grade' => 3.5])]);
        $follow = $this->service->followup($prompt, 200, true);
        $this->answer($follow, [['best' => "problem:$a", 'shown' => ["problem:$a", "problem:$b"]]]);

        $this->assertSame(2, $this->service->weighFollowups());

        $evidence = json_decode(DB::table('ux_problems')->find($a)->evidence, true);
        $this->assertSame([1, 2], $evidence['answer_ids']);
        $this->assertSame('x', $evidence['quotes'][0]['span']);
        $this->assertEquals(3.5, $evidence['grade']);
        $this->assertSame(1, $evidence['ranking']['times_shown']);
        $this->assertNull(DB::table('ux_problems')->find($c)->weight, 'C was never in front of anybody');
    }

    public function test_an_answer_with_no_shown_list_is_counted_as_having_seen_every_option(): void
    {
        $prompt = $this->rankable();
        $a = $this->problem($prompt, 'A', 5);
        $b = $this->problem($prompt, 'B', 4);
        $follow = $this->service->followup($prompt, 200, true);
        $this->answer($follow, [['best' => "problem:$a"], ['best' => "problem:$a"]]);

        $this->service->weighFollowups();

        $this->assertEquals(1.0, (float) DB::table('ux_problems')->find($a)->weight);
        $this->assertEquals(0.0, (float) DB::table('ux_problems')->find($b)->weight);
    }

    public function test_nothing_is_weighed_before_the_first_answer_and_the_tick_command_does_it(): void
    {
        $prompt = $this->rankable();
        $a = $this->problem($prompt, 'A', 5);
        $b = $this->problem($prompt, 'B', 4);
        $follow = $this->service->followup($prompt, 200, true);
        $this->assertSame(0, $this->service->weighFollowups());
        $this->assertNull(DB::table('ux_problems')->find($a)->weight);

        $this->answer($follow, [['best' => "problem:$a", 'shown' => ["problem:$a", "problem:$b"]]]);
        $this->artisan('ux:prompts-tick')->expectsOutputToContain('Weighed 2 problem(s)')->assertExitCode(0);

        $this->assertEquals(1.0, (float) DB::table('ux_problems')->find($a)->weight);
        $this->assertEquals(0.0, (float) DB::table('ux_problems')->find($b)->weight);
    }

    // ---------------------------------------------------------------- privacy

    private function written(int $prompt, array $answer, string $when, ?string $comment = null): int
    {
        return DB::table('ux_prompt_responses')->insertGetId([
            'prompt_id' => $prompt, 'userid' => $this->nextUser++, 'answer' => json_encode($answer),
            'comment' => $comment, 'timestamp' => $when,
        ]);
    }

    public function test_words_and_comments_are_blanked_ninety_days_after_they_were_written_whatever_the_prompt_is_doing(): void
    {
        // An open question that never filled and is still open: nothing closes it by time.
        $open = $this->service->openOpen('problems');
        $survey = $this->service->openSurvey();
        $oldText = $this->written($open, ['text' => 'A secret address'], '2026-07-01 00:00:00', 'And a comment');
        $oldNothing = $this->written($open, ['choice' => 'nothing'], '2026-07-01 00:00:00');
        $oldSkip = $this->written($open, ['skipped' => true], '2026-07-01 00:00:00');
        $newText = $this->written($open, ['text' => 'Still wanted'], '2026-10-01 00:00:00');
        $oldPick = $this->written($survey, ['best' => 'give', 'worst' => 'login'], '2026-07-01 00:00:00', 'Jane Smith is a bully');
        $newPick = $this->written($survey, ['best' => 'give', 'worst' => 'login'], '2026-10-01 00:00:00', 'Fresh comment');
        // A later touch of the prompt row does not restart anyone's clock.
        DB::table('ux_prompts')->where('id', $open)->update(['updated_at' => '2026-10-13 00:00:00']);

        $this->assertSame(2, $this->service->blankOldAnswers());

        $row = fn ($id) => DB::table('ux_prompt_responses')->find($id);
        $this->assertSame(['text' => null, 'removed' => true], json_decode($row($oldText)->answer, true));
        $this->assertNull($row($oldText)->comment, 'the comment on an old answer goes too');
        $this->assertSame(['choice' => 'nothing'], json_decode($row($oldNothing)->answer, true));
        $this->assertSame(['skipped' => true], json_decode($row($oldSkip)->answer, true));
        $this->assertSame(['text' => 'Still wanted'], json_decode($row($newText)->answer, true));
        // A survey's picks are not member words and stay for the counts; its comment is.
        $this->assertSame(['best' => 'give', 'worst' => 'login'], json_decode($row($oldPick)->answer, true));
        $this->assertNull($row($oldPick)->comment);
        $this->assertSame('Fresh comment', $row($newPick)->comment);
        $this->assertSame(6, DB::table('ux_prompt_responses')->count(), 'the rows, and so the counts, stay');

        // Doing it again finds nothing more.
        $this->assertSame(0, $this->service->blankOldAnswers());
    }

    public function test_the_tick_command_blanks_old_answers(): void
    {
        $old = $this->service->openOpen('problems');
        $id = $this->written($old, ['text' => 'Words'], '2026-07-01 00:00:00');

        $this->artisan('ux:prompts-tick')->expectsOutputToContain('Removed the words of 1 answer(s) written over 90 days ago.')->assertExitCode(0);

        $this->assertSame(['text' => null, 'removed' => true], json_decode(DB::table('ux_prompt_responses')->find($id)->answer, true));
    }

    public function test_the_checked_quotes_of_a_theme_go_when_their_answers_are_ninety_days_old_or_gone(): void
    {
        $open = $this->service->openOpen('problems');
        $old = $this->written($open, ['text' => 'The postman called Dave from Elm Road keeps taking my offers'], '2026-07-01 00:00:00');
        $new = $this->written($open, ['text' => 'Photos are slow to upload'], '2026-10-01 00:00:00');
        $gone = $this->written($open, ['text' => 'Deleted by a merge'], '2026-10-01 00:00:00');
        DB::table('ux_prompt_responses')->where('id', $gone)->delete();
        $problem = DB::table('ux_problems')->insertGetId([
            'flow' => 'give', 'kind' => 'problem', 'title' => 'Things go missing', 'prompt_id' => $open,
            'mentions' => 3, 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
            'evidence' => json_encode(['answer_ids' => [$old, $new, $gone], 'quotes' => [
                ['answer_id' => $old, 'span' => 'The postman called Dave from Elm Road'],
                ['answer_id' => $new, 'span' => 'Photos are slow'],
                ['answer_id' => $gone, 'span' => 'Deleted by a merge'],
            ]]),
        ]);

        $this->assertSame(1, $this->service->blankOldQuotes());

        $evidence = json_decode(DB::table('ux_problems')->where('id', $problem)->value('evidence'), true);
        $this->assertEquals([['answer_id' => $new, 'span' => 'Photos are slow']], $evidence['quotes']);
        $this->assertSame([$old, $new, $gone], $evidence['answer_ids'], 'the counts and ids stay');
        $this->assertSame(0, $this->service->blankOldQuotes(), 'doing it again finds nothing more');
    }

    public function test_the_tick_command_also_drops_old_quotes(): void
    {
        $open = $this->service->openOpen('problems');
        $old = $this->written($open, ['text' => 'Words'], '2026-07-01 00:00:00');
        $problem = DB::table('ux_problems')->insertGetId([
            'flow' => null, 'kind' => 'problem', 'title' => 'T', 'prompt_id' => $open,
            'mentions' => 2, 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
            'evidence' => json_encode(['answer_ids' => [$old], 'quotes' => [['answer_id' => $old, 'span' => 'Words']]]),
        ]);

        $this->artisan('ux:prompts-tick')->expectsOutputToContain('Removed the quoted words from 1 theme(s) whose answers are over 90 days old or gone.')->assertExitCode(0);

        $this->assertSame([], json_decode(DB::table('ux_problems')->where('id', $problem)->value('evidence'), true)['quotes']);
    }

    public function test_prompts_tick_runs_the_four_jobs_and_prints_their_lines(): void
    {
        // One of each: a full prompt to close, a follow-up pick to weigh, an old answer to blank, an old quote to drop.
        $full = $this->service->openSurvey(10);
        DB::table('ux_prompts')->where('id', $full)->update(['max_responses' => 1]);
        DB::table('ux_prompt_responses')->insert(['prompt_id' => $full, 'userid' => 1, 'answer' => json_encode(['best' => 'give'])]);
        $prompt = $this->rankable();
        $a = $this->problem($prompt, 'A', 5);
        $b = $this->problem($prompt, 'B', 4);
        $follow = $this->service->followup($prompt, 200, true);
        $this->answer($follow, [['best' => "problem:$a", 'shown' => ["problem:$a", "problem:$b"]]]);
        $oldAnswer = $this->written($prompt, ['text' => 'Words'], '2026-07-01 00:00:00');
        $themeRow = DB::table('ux_problems')->insertGetId([
            'flow' => null, 'kind' => 'problem', 'title' => 'T', 'prompt_id' => $prompt, 'mentions' => 2, 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
            'evidence' => json_encode(['answer_ids' => [$oldAnswer], 'quotes' => [['answer_id' => $oldAnswer, 'span' => 'Words']]]),
        ]);

        $this->assertSame(0, Artisan::call('ux:prompts-tick'));
        $out = Artisan::output();

        $this->assertStringContainsString('Closed 1 prompt(s) that had collected their answers.', $out);
        $this->assertStringContainsString('Weighed 2 problem(s) from the follow-up rankings.', $out);
        $this->assertStringContainsString('Removed the words of 1 answer(s) written over 90 days ago.', $out);
        $this->assertStringContainsString('Removed the quoted words from 1 theme(s) whose answers are over 90 days old or gone.', $out);
        $this->assertSame('closed', DB::table('ux_prompts')->find($full)->status);
        $this->assertSame([], json_decode(DB::table('ux_problems')->find($themeRow)->evidence, true)['quotes']);

    }

    public function test_prompts_tick_prints_nothing_when_there_is_nothing_to_do(): void
    {
        $this->assertSame(0, Artisan::call('ux:prompts-tick'));
        $this->assertSame('', trim(Artisan::output()));
    }

    public function test_forgetting_answers_takes_their_quotes_and_ids_out_of_the_themes(): void
    {
        $open = $this->service->openOpen('problems');
        $mine = $this->written($open, ['text' => 'Mine'], '2026-10-01 00:00:00');
        $other = $this->written($open, ['text' => 'Theirs'], '2026-10-01 00:00:00');
        $problem = DB::table('ux_problems')->insertGetId([
            'flow' => null, 'kind' => 'problem', 'title' => 'T', 'prompt_id' => $open,
            'mentions' => 2, 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
            'evidence' => json_encode(['answer_ids' => [$mine, $other], 'quotes' => [
                ['answer_id' => $mine, 'span' => 'Mine'], ['answer_id' => $other, 'span' => 'Theirs']]]),
        ]);
        // A suggestion carries the prompt id and the answers behind it, so it is cleaned the same way.
        $suggestion = $this->problem($open, 'A fix', 2, 'suggestion', 'open', $problem);
        DB::table('ux_problems')->where('id', $suggestion)->update(['evidence' => json_encode(['answer_ids' => [$mine, $other], 'quotes' => [['answer_id' => $mine, 'span' => 'Mine']]])]);

        UxPromptService::forgetAnswers([$mine]);

        $p = json_decode(DB::table('ux_problems')->where('id', $problem)->value('evidence'), true);
        $this->assertEquals([['answer_id' => $other, 'span' => 'Theirs']], $p['quotes']);
        $this->assertSame([$other], $p['answer_ids']);
        $s = json_decode(DB::table('ux_problems')->where('id', $suggestion)->value('evidence'), true);
        $this->assertSame([], $s['quotes']);
        $this->assertSame([$other], $s['answer_ids']);
        $this->assertSame(2, (int) DB::table('ux_problems')->where('id', $problem)->value('mentions'), 'a count stays');
        $this->assertSame(2, (int) DB::table('ux_problems')->where('id', $suggestion)->value('mentions'));
    }

    // ---------------------------------------------------------------- scrubbing

    public function test_the_scrub_removes_emails_phone_numbers_and_postcodes_but_not_dates_or_ordinary_numbers(): void
    {
        $this->assertSame('Mail [email] or ring [number] or [number]; I am at [postcode], [postcode].',
            UxScrub::text('Mail Joe.Bloggs+freegle@mail.example.co.uk or ring 07700 900123 or +44 (0)20 7946 0958; I am at SW1A 1AA, m1 1ae.'));
        $this->assertSame('On 2026-10-14 I posted 12 items and 3 were taken, about 250 grams.', UxScrub::text('On 2026-10-14 I posted 12 items and 3 were taken, about 250 grams.'));
        $this->assertSame('a b c', UxScrub::text("a\n\n  b\t c "));
        $this->assertSame('abcde', UxScrub::text('abcdefgh', 5));
    }
}
