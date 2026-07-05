<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retire the SearchTerm micro-volunteering machinery.
     *
     * search_terms and microactions.searchterm1/2 fed the retired "SearchTerm"
     * micro-volunteering challenge (removed across Go/PHP/front-end earlier in
     * this change set) and are no longer read or written by any code path.
     *
     * NOT dropped, despite the original plan for this migration: words,
     * words_cache, items_index and messages_index. Item::typeahead()/create()/
     * delete() (iznik-server/include/message/Item.php, live behind the item API
     * and the item-name-suggestion UI) and Message::search()/searchActiveInBounds()
     * (iznik-server/include/message/Message.php, live behind the ModTools message
     * search endpoint in http/api/messages.php) still query these tables via the
     * Search class, and Message::repost()/autoRepost() (driven by the live
     * AutoRepostService) still call Search::bump()/delete() against
     * messages_index. Dropping them crashed fixture setup in CI (items_index)
     * and would have broken those live features in production. Only the
     * message-text keyword search has actually been replaced by vector
     * embeddings so far; item search and auto-repost have not been migrated.
     *
     * KEPT deliberately: search_history and users_searches (search analytics, still
     * used) and the damlevlim() stored function.
     */
    public function up(): void
    {
        // microactions.searchterm1/2 fed the retired SearchTerm challenge. Drop
        // their foreign keys and the composite unique that references them before
        // dropping the columns themselves.
        if (Schema::hasColumn('microactions', 'searchterm1')) {
            Schema::table('microactions', function (Blueprint $table) {
                $table->dropForeign(['searchterm1']);
                $table->dropForeign(['searchterm2']);
                $table->dropUnique('userid_3');
                $table->dropColumn(['searchterm1', 'searchterm2']);
            });
        }

        Schema::dropIfExists('search_terms');

        // Legacy keyword-similarity view: only present in older production schemas
        // (it was never created by a migration), so guard with IF EXISTS.
        DB::statement('DROP VIEW IF EXISTS VW_search_term_similarities');
    }

    /**
     * Recreate search_terms and the microactions search-term columns as they
     * existed before retirement (data is not restored).
     */
    public function down(): void
    {
        if (!Schema::hasTable('search_terms')) {
            Schema::create('search_terms', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('term')->unique('term');
                $table->integer('count')->default(0)->index('count');
            });
        }

        if (!Schema::hasColumn('microactions', 'searchterm1')) {
            Schema::table('microactions', function (Blueprint $table) {
                $table->unsignedBigInteger('searchterm1')->nullable()->index('searchterm1');
                $table->unsignedBigInteger('searchterm2')->nullable()->index('searchterm2');
                $table->unique(['userid', 'searchterm1', 'searchterm2'], 'userid_3');
                $table->foreign(['searchterm1'])->references(['id'])->on('search_terms')->onUpdate('no action')->onDelete('cascade');
                $table->foreign(['searchterm2'])->references(['id'])->on('search_terms')->onUpdate('no action')->onDelete('cascade');
            });
        }
    }
};
