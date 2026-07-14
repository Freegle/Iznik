<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client-generated idempotency key for chat message sends (Discourse #9913: two
 * copies of a just-sent chat message shown transiently - a retried/duplicated
 * POST created a second, genuinely-persisted row).
 *
 * Replaces the rejected 60s content-hash dedupe approach (which silently swallowed
 * legitimate identical resends like a deliberate second "ok" or "?") with a
 * client-supplied key: the same logical send always carries the same key, a
 * genuinely new message always gets a new one. The unique index makes at-most-once
 * DB-enforced and atomic (INSERT ... ON DUPLICATE KEY UPDATE - see
 * iznik-server-go/chat/chatmessage.go CreateChatMessage), closing the
 * select-then-insert race the earlier attempt left open. Nullable: older/cached
 * clients that don't send a key are unaffected (MySQL treats each NULL in a
 * unique index as distinct, so they never collide with each other or with keyed
 * rows).
 *
 * chat_messages is large/high-traffic, but a nullable ADD COLUMN with no computed
 * default and a plain ADD INDEX both default to ALGORITHM=INPLACE, LOCK=NONE on
 * Percona/InnoDB 8.0 (unlike the ENUM-widen migrations on this table, which force
 * INPLACE explicitly because MODIFY COLUMN can otherwise pick COPY) - see
 * 2026_06_25_000001_add_session_to_browse_scroll_depth for the same shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_messages')) {
            return;
        }

        if (!Schema::hasColumn('chat_messages', 'idempotency_key')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->string('idempotency_key', 64)->nullable()->after('userid');
                $table->unique(['chatid', 'userid', 'idempotency_key'], 'chat_messages_idempotency_key_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_messages', 'idempotency_key')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropUnique('chat_messages_idempotency_key_unique');
                $table->dropColumn('idempotency_key');
            });
        }
    }
};
