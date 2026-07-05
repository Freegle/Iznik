<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retire the keyword search index.
     *
     * Search is now served entirely from vector embeddings (messages_embeddings +
     * the in-memory embedding store in the Go API). The old keyword machinery -
     * the words / messages_index / items_index / words_cache tables, the
     * search_terms table and the microactions.searchterm1/2 columns that fed the
     * retired "SearchTerm" micro-volunteering challenge - is no longer read or
     * written by any code path (see the matching removals in this change set:
     * the Go pure-vector search, the Laravel index-maintenance jobs, the v1
     * Message::index no-ops and the SearchTerm challenge across Go/PHP/front-end).
     *
     * KEPT deliberately: search_history and users_searches (search analytics, still
     * used) and the damlevlim() stored function (used elsewhere).
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

        // The keyword index tables. No inter-table foreign keys, so order is free.
        Schema::dropIfExists('messages_index');
        Schema::dropIfExists('items_index');
        Schema::dropIfExists('words_cache');
        Schema::dropIfExists('search_terms');
        Schema::dropIfExists('words');

        // Legacy keyword-similarity view: only present in older production schemas
        // (it was never created by a migration), so guard with IF EXISTS.
        DB::statement('DROP VIEW IF EXISTS VW_search_term_similarities');
    }

    /**
     * Recreate the keyword-index tables and the microactions search-term columns
     * as they existed before retirement (data is not restored).
     */
    public function down(): void
    {
        if (!Schema::hasTable('words')) {
            Schema::create('words', function (Blueprint $table) {
                $table->comment('Unique words for searches');
                $table->bigIncrements('id');
                $table->string('word', 10)->unique('word_2');
                $table->string('firstthree', 3);
                $table->string('soundex', 10);
                $table->bigInteger('popularity')->default(0)->index('popularity')->comment('Negative as DESC index not supported');

                $table->index(['firstthree', 'popularity'], 'firstthree');
                $table->index(['soundex', 'popularity'], 'soundex');
                $table->index(['word', 'popularity'], 'word');
            });
        }

        if (!Schema::hasTable('words_cache')) {
            Schema::create('words_cache', function (Blueprint $table) {
                $table->bigIncrements('id')->unique('id');
                $table->string('search')->unique('search');
                $table->text('words');
                $table->timestamp('added')->useCurrent();
            });
        }

        if (!Schema::hasTable('messages_index')) {
            Schema::create('messages_index', function (Blueprint $table) {
                $table->comment('For indexing messages for search keywords');
                $table->unsignedBigInteger('msgid');
                $table->unsignedBigInteger('wordid');
                $table->bigInteger('arrival')->index('arrival')->comment('We prioritise recent messages');
                $table->unsignedBigInteger('groupid')->nullable()->index('groupid');

                $table->unique(['msgid', 'wordid'], 'msgid');
                $table->index(['wordid', 'groupid'], 'wordid');
            });
        }

        if (!Schema::hasTable('items_index')) {
            Schema::create('items_index', function (Blueprint $table) {
                $table->unsignedBigInteger('itemid')->index('itemid_2');
                $table->unsignedBigInteger('wordid');
                $table->integer('popularity')->default(0);
                $table->unsignedBigInteger('categoryid')->nullable();

                $table->unique(['itemid', 'wordid'], 'itemid');
                $table->index(['wordid', 'popularity'], 'wordid');
            });
        }

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
