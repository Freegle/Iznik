<?php

namespace Tests\Unit\Services;

use App\Services\Embedding\EmbedderContract;
use App\Services\EmbeddingService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmbeddingServiceTest extends TestCase
{
    public function test_preprocess_strips_offer_prefix(): void
    {
        $service = new EmbeddingService(new FakeEmbedder);
        $this->assertSame('Coffee table', $service->preprocessSubject('OFFER: Coffee table'));
        $this->assertSame('Coffee table', $service->preprocessSubject('Offered: Coffee table'));
    }

    public function test_preprocess_strips_wanted_prefix(): void
    {
        $service = new EmbeddingService(new FakeEmbedder);
        $this->assertSame('Bicycle', $service->preprocessSubject('WANTED: Bicycle'));
        $this->assertSame('Bicycle', $service->preprocessSubject('Requested: Bicycle'));
    }

    public function test_preprocess_strips_trailing_location_suffix(): void
    {
        $service = new EmbeddingService(new FakeEmbedder);
        $this->assertSame('Coffee table', $service->preprocessSubject('OFFER: Coffee table (SE10 1BH)'));
        $this->assertSame('Kitchen table', $service->preprocessSubject('WANTED: Kitchen table (LS6 3HF)'));
    }

    public function test_preprocess_preserves_middle_parens_in_item(): void
    {
        // The bracket-counting parser treats the LAST matching pair as the
        // location; any earlier parenthesised notes stay inside the item.
        $service = new EmbeddingService(new FakeEmbedder);
        $this->assertSame(
            'Set of 4 dining chairs (oak)',
            $service->preprocessSubject('OFFER: Set of 4 dining chairs (oak) (N1 9LT)')
        );
    }

    public function test_preprocess_handles_empty_and_non_canonical(): void
    {
        $service = new EmbeddingService(new FakeEmbedder);
        $this->assertSame('', $service->preprocessSubject(''));
        // No colon → subject doesn't match the canonical shape; embed as-is
        // (fallback), only stripping a bare type keyword if obvious.
        $this->assertSame('Random text no structure', $service->preprocessSubject('Random text no structure'));
        $this->assertSame('Just an item', $service->preprocessSubject('OFFER: Just an item'));
    }

    public function test_preprocess_subject_handles_nested_location_brackets(): void
    {
        // Location may itself contain parentheses — the bracket-counting
        // parser must find the OUTERMOST pair, not the innermost.
        $service = new EmbeddingService(new FakeEmbedder);
        $this->assertSame(
            'Books',
            $service->preprocessSubject('WANTED: Books (London (Central))')
        );
    }

    public function test_process_messages_stores_subject_and_body_embeddings(): void
    {
        $msgid = $this->seedMessage('OFFER: Coffee table (SE10 1BH)', 'Solid oak, lovely condition.');

        $embedder = new FakeEmbedder;
        $service = new EmbeddingService($embedder);

        $count = $service->processMessages([
            (object) [
                'msgid' => $msgid,
                'subject' => 'OFFER: Coffee table (SE10 1BH)',
                'body' => 'Solid oak, lovely condition.',
            ],
        ]);

        $this->assertSame(1, $count);

        $row = DB::selectOne('SELECT subject_embedding, body_embedding, model_version FROM messages_embeddings WHERE msgid = ?', [$msgid]);
        $this->assertNotNull($row, 'embedding row should be inserted');
        $this->assertNotNull($row->subject_embedding);
        $this->assertNotNull($row->body_embedding);
        $this->assertSame(1024, strlen($row->subject_embedding), '256 float32 = 1024 bytes');
        $this->assertSame(1024, strlen($row->body_embedding));
        $this->assertSame('nomic-embed-text-v1.5-dim256', $row->model_version);

        // Subject embedded as just the item name (structured parse drops type + location).
        $this->assertArrayHasKey("s:$msgid", $embedder->textsSeen);
        $this->assertSame('Coffee table', $embedder->textsSeen["s:$msgid"]);

        // Body embedded raw — there is no structured "location field" in the
        // body, and regex-stripping it risks cutting into real descriptions.
        $this->assertArrayHasKey("b:$msgid", $embedder->textsSeen);
        $this->assertSame('Solid oak, lovely condition.', $embedder->textsSeen["b:$msgid"]);
    }

    public function test_process_messages_with_empty_body_stores_null_body_embedding(): void
    {
        $msgid = $this->seedMessage('OFFER: Bicycle (N1 1AA)', '');

        $embedder = new FakeEmbedder;
        $service = new EmbeddingService($embedder);

        $count = $service->processMessages([
            (object) ['msgid' => $msgid, 'subject' => 'OFFER: Bicycle (N1 1AA)', 'body' => ''],
        ]);

        $this->assertSame(1, $count);

        $row = DB::selectOne('SELECT subject_embedding, body_embedding FROM messages_embeddings WHERE msgid = ?', [$msgid]);
        $this->assertNotNull($row->subject_embedding);
        $this->assertNull($row->body_embedding, 'empty body → NULL body_embedding');

        $this->assertArrayHasKey("s:$msgid", $embedder->textsSeen);
        $this->assertArrayNotHasKey("b:$msgid", $embedder->textsSeen, 'empty body must not be sent to embedder');
    }

    public function test_process_messages_upsert_replaces_existing_row(): void
    {
        $msgid = $this->seedMessage('OFFER: Old (SE10 1BH)', 'Old body');

        (new EmbeddingService(new FakeEmbedder(seed: 1.0)))->processMessages([
            (object) ['msgid' => $msgid, 'subject' => 'OFFER: Old (SE10 1BH)', 'body' => 'Old body'],
        ]);

        $before = DB::selectOne('SELECT subject_embedding FROM messages_embeddings WHERE msgid = ?', [$msgid]);

        $count = (new EmbeddingService(new FakeEmbedder(seed: 2.0)))->processMessages([
            (object) ['msgid' => $msgid, 'subject' => 'OFFER: New (SE10 1BH)', 'body' => 'New body'],
        ]);

        $this->assertSame(1, $count);

        $rows = DB::select('SELECT subject_embedding FROM messages_embeddings WHERE msgid = ?', [$msgid]);
        $this->assertCount(1, $rows, 'upsert must not create duplicates');
        $this->assertNotSame(
            $before->subject_embedding,
            $rows[0]->subject_embedding,
            'upsert must replace the vector with the new one'
        );
    }

    public function test_process_messages_returns_false_on_embedder_failure(): void
    {
        $msgid = $this->seedMessage('OFFER: Thing (SE10 1BH)', 'body');

        $service = new EmbeddingService(new FakeEmbedder(fail: true));

        $this->assertFalse($service->processMessages([
            (object) ['msgid' => $msgid, 'subject' => 'OFFER: Thing (SE10 1BH)', 'body' => 'body'],
        ]));
    }

    private function seedMessage(string $subject, string $body): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message = \App\Models\Message::create([
            'type' => \App\Models\Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => $subject,
            'textbody' => $body,
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);

        DB::statement(
            'INSERT INTO messages_spatial (msgid, groupid, msgtype, successful, promised, arrival, point)
             VALUES (?, ?, ?, 0, 0, ?, ST_GeomFromText(?, 3857))',
            [$message->id, $group->id, 'Offer', now(), sprintf('POINT(%F %F)', $group->lng, $group->lat)]
        );

        return (int) $message->id;
    }
}

/**
 * Deterministic id-keyed fake embedder.
 */
class FakeEmbedder implements EmbedderContract
{
    public array $textsSeen = []; // id => text

    public function __construct(
        private float $seed = 0.0,
        private bool $fail = false,
    ) {}

    public function embed(array $texts): array|false
    {
        if ($this->fail) {
            return false;
        }

        $out = [];
        foreach ($texts as $id => $text) {
            $this->textsSeen[$id] = $text;
            $out[$id] = $this->vectorFor($text);
        }

        return $out;
    }

    private function vectorFor(string $text): array
    {
        $h = hash('sha256', $text.'|'.$this->seed, true);
        $v = [];
        for ($i = 0; $i < 256; $i++) {
            $byte = ord($h[$i % 32]) + (($i >> 5) * 13);
            $v[] = (($byte % 256) / 255.0) - 0.5;
        }
        $norm = 0.0;
        foreach ($v as $x) {
            $norm += $x * $x;
        }
        $norm = sqrt($norm) ?: 1.0;
        foreach ($v as $i => $x) {
            $v[$i] = $x / $norm;
        }

        return $v;
    }
}
