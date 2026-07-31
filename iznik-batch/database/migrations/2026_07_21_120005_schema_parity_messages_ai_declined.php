<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rebuild messages_ai_declined to match production.
     *
     * Live and the migrations describe structurally different tables that happen
     * to share a name:
     *
     *   live        (id PK auto_increment, msgid, userid, timestamp)
     *               UNIQUE (msgid, userid), KEY idx_msgid, KEY idx_userid, no FK
     *   migrations  (msgid PK, created), FK msgid -> messages.id CASCADE
     *
     * Current code only ever writes msgid - `INSERT IGNORE INTO
     * messages_ai_declined (msgid)` in the Go API, and a LEFT JOIN on msgid in
     * MessageIllustrationsService - so both shapes "work" and the divergence has
     * gone unnoticed. On live the ignored insert silently stores userid = 0.
     *
     * Production is the reality tests should be written against, so the migrated
     * shape is brought into line with it rather than the other way round.
     */
    public function up(): void
    {
        if (!Schema::hasTable('messages_ai_declined')) {
            return;
        }

        // Already the production shape.
        if (Schema::hasColumn('messages_ai_declined', 'userid')) {
            return;
        }

        // The FK has to go before the primary key it sits on can be replaced.
        $fk = DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'messages_ai_declined')
            ->where('constraint_type', 'FOREIGN KEY')
            ->value('constraint_name');
        if ($fk) {
            DB::statement(sprintf('ALTER TABLE `messages_ai_declined` DROP FOREIGN KEY `%s`', $fk));
        }

        DB::statement('ALTER TABLE `messages_ai_declined` DROP PRIMARY KEY');
        DB::statement('ALTER TABLE `messages_ai_declined` MODIFY `msgid` BIGINT NOT NULL');

        if (Schema::hasColumn('messages_ai_declined', 'created')) {
            DB::statement(
                'ALTER TABLE `messages_ai_declined`
                 CHANGE `created` `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
            );
        }

        DB::statement('ALTER TABLE `messages_ai_declined` ADD `userid` BIGINT NOT NULL AFTER `msgid`');
        DB::statement(
            'ALTER TABLE `messages_ai_declined`
             ADD `id` BIGINT NOT NULL AUTO_INCREMENT FIRST, ADD PRIMARY KEY (`id`)'
        );
        DB::statement('ALTER TABLE `messages_ai_declined` ADD UNIQUE KEY `unique_msg_user` (`msgid`, `userid`)');
        DB::statement('ALTER TABLE `messages_ai_declined` ADD KEY `idx_msgid` (`msgid`)');
        DB::statement('ALTER TABLE `messages_ai_declined` ADD KEY `idx_userid` (`userid`)');
    }

    public function down(): void
    {
        if (!Schema::hasTable('messages_ai_declined') || !Schema::hasColumn('messages_ai_declined', 'userid')) {
            return;
        }

        Schema::dropIfExists('messages_ai_declined');
        Schema::create('messages_ai_declined', function (Blueprint $table) {
            $table->unsignedBigInteger('msgid')->primary();
            $table->timestamp('created')->useCurrent();
            $table->foreign('msgid')->references('id')->on('messages')->onDelete('cascade');
        });
    }
};
