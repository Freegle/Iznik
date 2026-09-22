<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 2: the message-embeddings upsert.
 *
 * The update columns are passed as a plain LIST, not a map, and that is the
 * whole correctness question here. A list renders "col = values(col)" - update
 * each column from the row being inserted, which is what the raw statement
 * wrote. A map renders "col = ?" and binds, turning an
 * update-from-the-incoming-row into an update-to-a-fixed-value. Both are valid
 * SQL and only the golden distinguishes them.
 */
class Wave2EmbeddingTest extends TestCase
{
    private const SITE_EMBEDDING_UPSERT = '7d550cd164bd';

    public function test_embedding_upsert(): void
    {
        GoldenSql::assertUpsert(self::SITE_EMBEDDING_UPSERT, fn () => [
            DB::table('messages_embeddings'),
            [[
                'msgid' => 1,
                'subject_embedding' => 'sblob',
                'body_embedding' => 'bblob',
                'model_version' => 'v1',
            ]],
            ['msgid'],
            ['subject_embedding', 'body_embedding', 'model_version'],
        ]);
    }
}
