<?php

namespace Tests\Unit\Services;

use App\Services\ItemCleanerService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ItemCleanerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['freegle.ai_images.gemini_api_key' => 'test-key', 'freegle.ai_images.clean_model' => 'test-model']);
    }

    private function answer(array $items): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode($items)]]]]]];
    }

    public function test_each_name_gets_the_item_it_means(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->answer(['walking frame', 'Wheelchair', 'none']))]);

        $items = (new ItemCleanerService)->clean(['Aluminium walking frame', 'PUCHING WELCHIAR', 'Hello can anyone help']);

        $this->assertSame([
            'Aluminium walking frame' => 'walking frame',
            'PUCHING WELCHIAR' => 'wheelchair',
            'Hello can anyone help' => 'none',
        ], $items);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'models/test-model:generateContent')
            && $r->hasHeader('x-goog-api-key', 'test-key')
            && str_contains($r['contents'][0]['parts'][0]['text'], "1. Aluminium walking frame\n2. PUCHING WELCHIAR"));
    }

    public function test_an_answer_out_of_step_with_the_names_is_not_used(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->answer(['walking frame']))]);

        $this->assertSame([], (new ItemCleanerService)->clean(['Aluminium walking frame', 'Sofa']));
    }

    public function test_a_failed_request_cleans_nothing(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('busy', 503)]);

        $this->assertSame([], (new ItemCleanerService)->clean(['Sofa']));
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        config(['freegle.ai_images.gemini_api_key' => '']);
        Http::fake();

        $this->assertSame([], (new ItemCleanerService)->clean(['Sofa']));
        Http::assertNothingSent();
    }

    public function test_long_lists_go_in_batches(): void
    {
        $names = array_map(fn ($i) => "Item $i", range(1, ItemCleanerService::BATCH + 5));
        Http::fake(function ($request) {
            $count = preg_match_all('/^\d+\. Item/m', $request['contents'][0]['parts'][0]['text']);

            return Http::response($this->answer(array_fill(0, $count, 'thing')));
        });

        $items = (new ItemCleanerService)->clean($names);

        Http::assertSentCount(2);
        $this->assertCount(count($names), $items);
    }
}
