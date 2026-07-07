<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages_embeddings')) {
            return;
        }

        // Hybrid vector search needs two embeddings per message: one for the
        // subject (high weight) and one for the body (low weight).
        // The old `embedding` column stored subject+body combined, which
        // diluted short queries like "table" against long bodies — so its
        // bytes can't be reused under the new semantics.

        if (! Schema::hasColumn('messages_embeddings', 'body_embedding')) {
            DB::statement('ALTER TABLE messages_embeddings
                ADD COLUMN body_embedding BLOB NULL AFTER embedding');
        }

        if (Schema::hasColumn('messages_embeddings', 'embedding')
            && ! Schema::hasColumn('messages_embeddings', 'subject_embedding')) {
            DB::statement('ALTER TABLE messages_embeddings
                CHANGE COLUMN embedding subject_embedding BLOB NOT NULL');
        }

        // Old combined-embedding bytes are semantically wrong under the new
        // subject-only meaning; wipe and let embeddings:regenerate repopulate.
        DB::statement('TRUNCATE TABLE messages_embeddings');
    }

    public function down(): void
    {
        if (! Schema::hasTable('messages_embeddings')) {
            return;
        }

        if (Schema::hasColumn('messages_embeddings', 'subject_embedding')
            && ! Schema::hasColumn('messages_embeddings', 'embedding')) {
            DB::statement('ALTER TABLE messages_embeddings
                CHANGE COLUMN subject_embedding embedding BLOB NOT NULL');
        }

        if (Schema::hasColumn('messages_embeddings', 'body_embedding')) {
            Schema::table('messages_embeddings', function ($t) {
                $t->dropColumn('body_embedding');
            });
        }
    }
};
