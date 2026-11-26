<?php

namespace Tests\Unit\Commands\Message;

use App\Services\Embedding\EmbedderContract;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Unit\Services\FakeEmbedder;

class GenerateEmbeddingsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(EmbedderContract::class, fn () => new FakeEmbedder);
    }

    public function test_no_messages_returns_success(): void
    {
        $this->artisan('embeddings:generate', ['--limit' => 1])
            ->expectsOutputToContain('No messages need embedding')
            ->assertSuccessful();
    }

    public function test_backfill_option_increases_limit(): void
    {
        $this->artisan('embeddings:generate', ['--backfill' => true])
            ->expectsOutputToContain('No messages need embedding')
            ->assertSuccessful();
    }

    public function test_messages_embeddings_table_exists(): void
    {
        $this->assertTrue(
            DB::getSchemaBuilder()->hasTable('messages_embeddings'),
            'messages_embeddings table should exist'
        );
    }

    public function test_generates_embeddings_for_missing_rows(): void
    {
        $msgid = $this->seedLiveMessage('OFFER: Coffee table (SE10 1BH)', 'Solid oak.');

        $this->artisan('embeddings:generate', ['--limit' => 10])
            ->expectsOutputToContain('Generated 1 embeddings')
            ->assertSuccessful();

        $row = DB::selectOne('SELECT subject_embedding, body_embedding FROM messages_embeddings WHERE msgid = ?', [$msgid]);
        $this->assertNotNull($row);
        $this->assertNotNull($row->subject_embedding);
        $this->assertNotNull($row->body_embedding);
    }

    public function test_skips_messages_that_already_have_an_embedding(): void
    {
        $msgid = $this->seedLiveMessage('OFFER: Bike (N1 1AA)', 'body');

        // Pre-populate an embedding row for this msgid.
        DB::insert(
            'INSERT INTO messages_embeddings (msgid, subject_embedding, body_embedding, model_version) VALUES (?, ?, NULL, ?)',
            [$msgid, str_repeat("\x00", 1024), 'nomic-embed-text-v1.5-dim256']
        );

        $this->artisan('embeddings:generate', ['--limit' => 10])
            ->expectsOutputToContain('No messages need embedding')
            ->assertSuccessful();

        // Pre-existing row must still hold the sentinel (command skipped it).
        $row = DB::selectOne('SELECT subject_embedding FROM messages_embeddings WHERE msgid = ?', [$msgid]);
        $this->assertSame(str_repeat("\x00", 1024), $row->subject_embedding);
    }

    private function seedLiveMessage(string $subject, string $body): int
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
            [$message->id, $group->id, 'Offer', now(),
             sprintf('POINT(%F %F)', $group->lng, $group->lat)]
        );

        return (int) $message->id;
    }
}
