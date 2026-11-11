<?php

namespace Tests\Unit\Services\AutoSplit;

use App\Services\AutoSplit\GeminiJson;
use App\Services\AutoSplit\TextSplitter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TextSplitterTest extends TestCase
{
    private function answer(string $kind, bool $confident, array $items): array
    {
        return ['kind' => $kind, 'confident' => $confident, 'items' => $items];
    }

    public function test_several_items_split(): void
    {
        $d = TextSplitter::judge($this->answer('several_items', true, [['name' => 'Sofa', 'quantity' => 1], ['name' => 'Chairs', 'quantity' => 2]]));
        $this->assertTrue($d['split']);
        $this->assertNull($d['why']);
        $this->assertSame([['name' => 'Sofa', 'quantity' => 1], ['name' => 'Chairs', 'quantity' => 2]], $d['items']);
    }

    public function test_other_kinds_do_not_split(): void
    {
        foreach (['single', 'multiples_of_one', 'set', 'bundle', 'item_with_accessories'] as $kind) {
            $d = TextSplitter::judge($this->answer($kind, true, [['name' => 'Box of toys', 'quantity' => 1]]));
            $this->assertFalse($d['split'], $kind);
            $this->assertSame($kind, $d['kind']);
        }
    }

    public function test_an_unconfident_split_is_not_made(): void
    {
        $d = TextSplitter::judge($this->answer('several_items', false, [['name' => 'Shoes', 'quantity' => 1], ['name' => 'Clothes', 'quantity' => 1]]));
        $this->assertFalse($d['split']);
        $this->assertSame('not_confident', $d['why']);
    }

    public function test_one_item_is_not_a_split(): void
    {
        $d = TextSplitter::judge($this->answer('several_items', true, [['name' => 'Sofa', 'quantity' => 1], ['name' => '  ', 'quantity' => 1]]));
        $this->assertSame('too_few', $d['why']);
    }

    public function test_a_long_list_is_not_a_split(): void
    {
        $items = [];
        for ($i = 1; $i <= TextSplitter::MAX_ITEMS + 1; $i++) {
            $items[] = ['name' => "Thing {$i}", 'quantity' => 1];
        }
        $this->assertSame('too_many', TextSplitter::judge($this->answer('several_items', true, $items))['why']);
    }

    public function test_items_with_the_same_name_are_not_a_split(): void
    {
        $d = TextSplitter::judge($this->answer('several_items', true, [['name' => 'Curtains', 'quantity' => 1], ['name' => 'curtains', 'quantity' => 2]]));
        $this->assertSame('same_names', $d['why']);
    }

    public function test_names_and_quantities_are_tidied(): void
    {
        $d = TextSplitter::judge($this->answer('several_items', true, [
            ['name' => "  Big\n  table ", 'quantity' => 0],
            ['name' => str_repeat('x', 200), 'quantity' => 500],
        ]));
        $this->assertSame('Big table', $d['items'][0]['name']);
        $this->assertSame(1, $d['items'][0]['quantity']);
        $this->assertSame(TextSplitter::MAX_NAME, mb_strlen($d['items'][1]['name']));
        $this->assertSame(TextSplitter::MAX_QUANTITY, $d['items'][1]['quantity']);
    }

    public function test_an_answer_of_the_wrong_shape_is_no_answer(): void
    {
        $this->assertNull(TextSplitter::judge(['kind' => 'lots', 'confident' => true, 'items' => []]));
        $this->assertNull(TextSplitter::judge(['kind' => 'single', 'confident' => true]));
    }

    public function test_it_asks_the_model_with_the_whole_post_and_reads_the_answer(): void
    {
        config(['freegle.autosplit.gemini_api_key' => 'k', 'freegle.autosplit.model' => 'test-model']);
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($this->answer('several_items', true, [['name' => 'Bike', 'quantity' => 1], ['name' => 'Scooter', 'quantity' => 1]]))]]]]],
            'usageMetadata' => ['promptTokenCount' => 700, 'candidatesTokenCount' => 50, 'thoughtsTokenCount' => 300],
        ])]);

        $splitter = new TextSplitter(new GeminiJson);
        $d = $splitter->split('OFFER: Bikes (Town)', "A Bianchi bike and a scooter\nCollection only");

        $this->assertTrue($d['split']);
        $this->assertSame([700, 350], $splitter->usage());
        Http::assertSent(function ($request) {
            $text = $request['contents'][0]['parts'][0]['text'];

            return str_contains($request->url(), 'models/test-model:generateContent')
                && $request->hasHeader('x-goog-api-key', 'k')
                && ! str_contains($request->url(), 'key=')
                && str_contains($text, 'Subject: OFFER: Bikes (Town)')
                && str_contains($text, "Description: A Bianchi bike and a scooter\nCollection only")
                && $request['generationConfig']['responseSchema'] === TextSplitter::SCHEMA;
        });
    }

    public function test_no_key_means_no_call(): void
    {
        config(['freegle.autosplit.gemini_api_key' => '']);
        Http::fake();
        $this->assertNull((new TextSplitter(new GeminiJson))->split('OFFER: Sofa and bed (Town)', ''));
        Http::assertNothingSent();
    }

    public function test_a_busy_model_is_tried_again_and_a_refusal_is_not(): void
    {
        config(['freegle.autosplit.gemini_api_key' => 'k']);
        $ok = ['candidates' => [['content' => ['parts' => [['text' => json_encode($this->answer('single', true, [['name' => 'Sofa', 'quantity' => 1]]))]]]]]];
        Http::fakeSequence()->push([], 429)->push([], 503)->push($ok);
        $g = new GeminiJson;
        $g->retryPauseSeconds = 0;
        $this->assertSame('single', (new TextSplitter($g))->split('OFFER: Sofa', '')['kind']);
        Http::assertSentCount(3);
    }

    public function test_a_refused_request_is_no_answer(): void
    {
        config(['freegle.autosplit.gemini_api_key' => 'k']);
        Http::fake(['*' => Http::response(['error' => 'bad'], 400)]);
        $g = new GeminiJson;
        $g->retryPauseSeconds = 0;
        $this->assertNull((new TextSplitter($g))->split('OFFER: Sofa', ''));
        Http::assertSentCount(1);
    }
}
