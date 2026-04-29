<?php

namespace Tests\Unit\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MessagesEmbeddingsSchemaTest extends TestCase
{
    public function test_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('messages_embeddings'));
    }

    public function test_has_subject_embedding_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('messages_embeddings', 'subject_embedding'),
            'subject_embedding column should exist (renamed from embedding)'
        );
    }

    public function test_has_body_embedding_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('messages_embeddings', 'body_embedding'),
            'body_embedding column should exist for hybrid vector search'
        );
    }

    public function test_legacy_embedding_column_removed(): void
    {
        $this->assertFalse(
            Schema::hasColumn('messages_embeddings', 'embedding'),
            'legacy embedding column should be replaced by subject_embedding'
        );
    }

    public function test_body_embedding_is_nullable(): void
    {
        $rows = DB::select("
            SELECT IS_NULLABLE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'messages_embeddings'
              AND COLUMN_NAME = 'body_embedding'
        ");

        $this->assertNotEmpty($rows, 'body_embedding column must exist');
        $this->assertSame('YES', $rows[0]->IS_NULLABLE, 'body_embedding must be nullable (posts with empty body)');
    }

    public function test_subject_embedding_is_not_nullable(): void
    {
        $rows = DB::select("
            SELECT IS_NULLABLE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'messages_embeddings'
              AND COLUMN_NAME = 'subject_embedding'
        ");

        $this->assertNotEmpty($rows, 'subject_embedding column must exist');
        $this->assertSame('NO', $rows[0]->IS_NULLABLE, 'subject_embedding must be NOT NULL');
    }
}
