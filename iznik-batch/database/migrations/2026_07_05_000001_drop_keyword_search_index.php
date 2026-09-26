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
     * NOT dropped here, deferred to a follow-up: words, words_cache, items_index
     * and messages_index. These backed the V1 keyword search - Item::typeahead()/
     * create()/delete() and Message::search()/searchActiveInBounds() (the
     * iznik-server PHP tree), plus Search::bump()/delete() from auto-repost. That
     * V1 PHP tree was removed wholesale on 2026-07-09 (commit c14a7125b, an
     * ancestor of this branch), and the Laravel port does NOT maintain these
     * indexes (AutoRepostService deliberately skips the Search::bump() side effect,
     * per its own header comment), so NO live code reads or writes these four
     * tables any more - they are already dead. They are left in place only because
     * dropping them is a separate, larger migration, and items_index in particular
     * is still referenced by test-fixtures.sql, so dropping it now would crash CI
     * fixture setup until that fixture is updated too. A follow-on migration can
     * drop these four now-dead tables.
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
