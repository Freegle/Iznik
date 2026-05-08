<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages_attachments') && ! Schema::hasColumn('messages_attachments', 'contenttype')) {
            \DB::statement("ALTER TABLE messages_attachments ADD COLUMN contenttype VARCHAR(64) NULL AFTER externalmods");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages_attachments', 'contenttype')) {
            \DB::statement("ALTER TABLE messages_attachments DROP COLUMN contenttype");
        }
    }
};
