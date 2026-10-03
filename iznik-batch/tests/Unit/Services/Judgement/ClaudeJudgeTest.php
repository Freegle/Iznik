<?php

namespace Tests\Unit\Services\Judgement;

use App\Services\Judgement\ClaudeJudge;
use App\Services\Judgement\Subject;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClaudeJudgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('freegle.judgement.api_key', 'test-key');
        Config::set('freegle.judgement.model', 'claude-opus-5');
        Config::set('freegle.judgement.chat_model', 'claude-opus-5');
        Config::set('freegle.judgement.timeout', 30);
        Config::set('freegle.judgement.retries', 2);
    }

    private function claudeResponse(array $answers, array $extraContentBlocksBefore = []): array
    {
        return [
            'content' => array_merge($extraContentBlocksBefore, [
                ['type' => 'text', 'text' => json_encode(['answers' => $answers])],
            ]),
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 40],
        ];
    }

    #[Test]
    public function unavailable_without_an_api_key_and_makes_no_request(): void
    {
        Config::set('freegle.judgement.api_key', null);
        Http::fake();

        $verdict = (new ClaudeJudge())->judge(new Subject(kind: Subject::KIND_POST, title: 'Sofa'));

        $this->assertFalse($verdict->available);
        Http::assertNothingSent();
    }

    #[Test]
    public function successful_response_is_parsed_into_a_verdict(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->claudeResponse([
                ['id' => 'animal', 'answer' => 'yes', 'confidence' => 0.95, 'reason' => 'A live rabbit.'],
                ['id' => 'unsafe_by_design', 'answer' => 'no', 'confidence' => 0.99, 'reason' => 'Not a weapon.'],
            ]), 200),
        ]);

        $verdict = (new ClaudeJudge())->judge(new Subject(kind: Subject::KIND_POST, title: 'Rabbit needs a home'));

        $this->assertTrue($verdict->available);
        $this->assertTrue($verdict->yesAtOrAbove('animal', 0.9));
        $this->assertTrue($verdict->noAtOrAbove('unsafe_by_design', 0.9));
        $this->assertSame(1200, $verdict->inputTokens);
        $this->assertSame(40, $verdict->outputTokens);
    }

    #[Test]
    public function extracts_the_first_text_block_when_a_thinking_block_comes_first(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->claudeResponse(
                answers: [['id' => 'scam', 'answer' => 'no', 'confidence' => 0.9, 'reason' => 'Looks fine.']],
                extraContentBlocksBefore: [['type' => 'thinking', 'thinking' => 'reasoning about it...']],
            ), 200),
        ]);

        $verdict = (new ClaudeJudge())->judge(new Subject(kind: Subject::KIND_CHAT, body: 'hi'));

        $this->assertTrue($verdict->available);
        $this->assertTrue($verdict->noAtOrAbove('scam', 0.8));
    }

    #[Test]
    public function ignores_answers_for_questions_that_were_not_asked(): void
    {
        Http::fake([
            // Chat only asks free/scam/decent, but the model (wrongly) also answers 'animal'.
            'api.anthropic.com/*' => Http::response($this->claudeResponse([
                ['id' => 'free', 'answer' => 'no', 'confidence' => 0.9, 'reason' => 'x'],
                ['id' => 'animal', 'answer' => 'yes', 'confidence' => 0.99, 'reason' => 'should be dropped'],
            ]), 200),
        ]);

        $verdict = (new ClaudeJudge())->judge(new Subject(kind: Subject::KIND_CHAT, body: 'hi'));

        $this->assertNull($verdict->answer('animal'));
        $this->assertNotNull($verdict->answer('free'));
    }

    #[Test]
    public function unparseable_json_is_unavailable(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'not json at all']],
                'usage'   => ['input_tokens' => 100, 'output_tokens' => 5],
            ], 200),
        ]);

        $verdict = (new ClaudeJudge())->judge(new Subject(kind: Subject::KIND_POST, title: 'x'));

        $this->assertFalse($verdict->available);
    }

    #[Test]
    public function retries_on_429_then_succeeds(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['error' => 'rate limited'], 429)
                ->push($this->claudeResponse([['id' => 'scam', 'answer' => 'no', 'confidence' => 0.9, 'reason' => 'x']]), 200),
        ]);

        $verdict = (new ClaudeJudge())->judge(new Subject(kind: Subject::KIND_CHAT, body: 'hi'));

        $this->assertTrue($verdict->available);
        Http::assertSentCount(2);
    }

    #[Test]
    public function gives_up_as_unavailable_after_exhausting_retries(): void
    {
        Config::set('freegle.judgement.retries', 2);
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'server error'], 503),
        ]);

        $verdict = (new ClaudeJudge())->judge(new Subject(kind: Subject::KIND_POST, title: 'x'));

        $this->assertFalse($verdict->available);
        Http::assertSentCount(3); // 1 initial + 2 retries
    }

    #[Test]
    public function does_not_retry_on_a_plain_4xx_that_is_not_429(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'bad request'], 400),
        ]);

        $verdict = (new ClaudeJudge())->judge(new Subject(kind: Subject::KIND_POST, title: 'x'));

        $this->assertFalse($verdict->available);
        Http::assertSentCount(1);
    }

    #[Test]
    public function system_prompt_carries_ephemeral_cache_control_and_is_identical_across_subjects(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->claudeResponse([
                ['id' => 'scam', 'answer' => 'no', 'confidence' => 0.9, 'reason' => 'x'],
            ]), 200),
        ]);

        $judge = new ClaudeJudge();
        $judge->judge(new Subject(kind: Subject::KIND_CHAT, body: 'first post'));
        $judge->judge(new Subject(kind: Subject::KIND_CHAT, body: 'second, completely different post'));

        $systemPrompts = [];
        Http::assertSent(function ($request) use (&$systemPrompts) {
            $system = $request->data()['system'] ?? null;
            if (is_array($system)) {
                $this->assertSame('ephemeral', $system[0]['cache_control']['type'] ?? null);
                $systemPrompts[] = $system[0]['text'];
            }

            return true;
        });

        $this->assertCount(2, $systemPrompts);
        $this->assertSame($systemPrompts[0], $systemPrompts[1]);
    }

    #[Test]
    public function user_turn_carries_no_member_identifying_fields(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->claudeResponse([
                ['id' => 'scam', 'answer' => 'no', 'confidence' => 0.9, 'reason' => 'x'],
            ]), 200),
        ]);

        (new ClaudeJudge())->judge(new Subject(kind: Subject::KIND_POST, title: 'Sofa', body: 'Free to collect'));

        Http::assertSent(function ($request) {
            $userText = $request->data()['messages'][0]['content'][0]['text'];
            $decoded  = json_decode($userText, true);

            foreach (['userid', 'email', 'location', 'lat', 'lng', 'name', 'address'] as $banned) {
                $this->assertArrayNotHasKey($banned, $decoded);
            }

            return true;
        });
    }

    #[Test]
    public function report_subject_includes_report_reason_in_the_user_turn(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->claudeResponse([
                ['id' => 'report', 'answer' => 'yes', 'confidence' => 0.9, 'reason' => 'x'],
            ]), 200),
        ]);

        (new ClaudeJudge())->judge(new Subject(
            kind: Subject::KIND_REPORT,
            title: 'Sofa',
            reportReason: 'this looks fake',
        ));

        Http::assertSent(function ($request) {
            $decoded = json_decode($request->data()['messages'][0]['content'][0]['text'], true);

            return ($decoded['report_reason'] ?? null) === 'this looks fake'
                && in_array('report', $decoded['question_ids'] ?? [], true);
        });
    }

    #[Test]
    public function photos_are_attached_as_image_blocks_up_to_three(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->claudeResponse([
                ['id' => 'scam', 'answer' => 'no', 'confidence' => 0.9, 'reason' => 'x'],
            ]), 200),
        ]);

        $photos = [
            ['base64' => 'aaa', 'mime_type' => 'image/jpeg'],
            ['base64' => 'bbb', 'mime_type' => 'image/jpeg'],
            ['base64' => 'ccc', 'mime_type' => 'image/jpeg'],
            ['base64' => 'ddd', 'mime_type' => 'image/jpeg'],
        ];

        (new ClaudeJudge())->judge(new Subject(kind: Subject::KIND_POST, title: 'Sofa', photos: $photos));

        Http::assertSent(function ($request) {
            $content     = $request->data()['messages'][0]['content'];
            $imageBlocks = array_values(array_filter($content, fn ($b) => $b['type'] === 'image'));

            $this->assertCount(3, $imageBlocks);
            $this->assertSame('aaa', $imageBlocks[0]['source']['data']);

            return true;
        });
    }

    #[Test]
    public function uses_the_chat_model_for_chat_subjects(): void
    {
        Config::set('freegle.judgement.model', 'claude-opus-5');
        Config::set('freegle.judgement.chat_model', 'claude-sonnet-5');

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->claudeResponse([
                ['id' => 'scam', 'answer' => 'no', 'confidence' => 0.9, 'reason' => 'x'],
            ]), 200),
        ]);

        (new ClaudeJudge())->judge(new Subject(kind: Subject::KIND_CHAT, body: 'hi'));

        Http::assertSent(fn ($request) => $request->data()['model'] === 'claude-sonnet-5');
    }

    #[Test]
    public function uses_adaptive_thinking_not_budget_tokens(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->claudeResponse([
                ['id' => 'scam', 'answer' => 'no', 'confidence' => 0.9, 'reason' => 'x'],
            ]), 200),
        ]);

        (new ClaudeJudge())->judge(new Subject(kind: Subject::KIND_CHAT, body: 'hi'));

        Http::assertSent(function ($request) {
            $thinking = $request->data()['thinking'];

            return ($thinking['type'] ?? null) === 'adaptive' && !isset($thinking['budget_tokens']);
        });
    }
}
