<?php

namespace Tests\Unit\Commands\Message;

use App\Services\Embedding\EmbedderContract;
use App\Services\EmbeddingService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Unit\Services\FakeEmbedder;

class RegenerateEmbeddingsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Bind a deterministic fake embedder so the command doesn't shell out
        // to the real Node/ONNX pipeline during tests.
        $this->app->bind(EmbedderContract::class, fn () => new FakeEmbedder);
    }

    public function test_no_live_messages_returns_success(): void
    {
        $this->artisan('embeddings:regenerate')
            ->expectsOutputToContain('No live messages to regenerate')
            ->assertSuccessful();
    }

    public function test_regenerates_live_messages_producing_both_embeddings(): void
    {
        $msgid = $this->seedLiveMessage('OFFER: Coffee table (SE10 1BH)', 'Solid oak, lovely.');

        $this->artisan('embeddings:regenerate')
            ->expectsOutputToContain('Regenerated')
            ->assertSuccessful();

        $row = DB::selectOne('SELECT subject_embedding, body_embedding FROM messages_embeddings WHERE msgid = ?', [$msgid]);
        $this->assertNotNull($row);
        $this->assertNotNull($row->subject_embedding);
        $this->assertNotNull($row->body_embedding);
    }

    public function test_skips_successful_and_promised_messages(): void
    {
        $liveId = $this->seedLiveMessage('OFFER: Bicycle (N1 1AA)', 'body');
        $successfulId = $this->seedLiveMessage('OFFER: Sofa (N1 1AA)', 'body', successful: 1);
        $promisedId = $this->seedLiveMessage('OFFER: Desk (N1 1AA)', 'body', promised: 1);

        $this->artisan('embeddings:regenerate')->assertSuccessful();

        $this->assertNotNull(DB::selectOne('SELECT msgid FROM messages_embeddings WHERE msgid = ?', [$liveId]));
        $this->assertNull(DB::selectOne('SELECT msgid FROM messages_embeddings WHERE msgid = ?', [$successfulId]));
        $this->assertNull(DB::selectOne('SELECT msgid FROM messages_embeddings WHERE msgid = ?', [$promisedId]));
    }

    public function test_replaces_existing_embedding_row(): void
    {
        $msgid = $this->seedLiveMessage('OFFER: Chair (SE10 1BH)', 'body');

        // First run writes a row.
        $this->artisan('embeddings:regenerate')->assertSuccessful();
        $before = DB::selectOne('SELECT subject_embedding FROM messages_embeddings WHERE msgid = ?', [$msgid]);
        $this->assertNotNull($before);

        // Overwrite stored blob with a sentinel so we can tell if regenerate
        // actually rewrote it.
        DB::update('UPDATE messages_embeddings SET subject_embedding = ? WHERE msgid = ?', [
            str_repeat("\x00", EmbeddingService::EMBEDDING_DIM * 4),
            $msgid,
        ]);

        $this->artisan('embeddings:regenerate')->assertSuccessful();

        $after = DB::selectOne('SELECT subject_embedding FROM messages_embeddings WHERE msgid = ?', [$msgid]);
        $this->assertNotSame(
            str_repeat("\x00", EmbeddingService::EMBEDDING_DIM * 4),
            $after->subject_embedding,
            'regenerate must overwrite existing rows'
        );

        $rows = DB::select('SELECT COUNT(*) as c FROM messages_embeddings WHERE msgid = ?', [$msgid]);
        $this->assertSame(1, (int) $rows[0]->c, 'upsert must not duplicate');
    }

    public function test_respects_limit_option(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->seedLiveMessage("OFFER: Item $i (SE10 1BH)", 'body');
        }

        $this->artisan('embeddings:regenerate', ['--limit' => 2])->assertSuccessful();

        $count = DB::selectOne('SELECT COUNT(*) as c FROM messages_embeddings')->c;
        $this->assertSame(2, (int) $count, '--limit must cap total rows written');
    }

    private function seedLiveMessage(string $subject, string $body, int $successful = 0, int $promised = 0): int
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
             VALUES (?, ?, ?, ?, ?, ?, ST_GeomFromText(?, 3857))',
            [$message->id, $group->id, 'Offer', $successful, $promised, now(),
             sprintf('POINT(%F %F)', $group->lng, $group->lat)]
        );

        return (int) $message->id;
    }
}
