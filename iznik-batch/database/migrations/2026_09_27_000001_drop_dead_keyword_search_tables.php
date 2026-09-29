<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the tables behind the retired keyword search.
     *
     * words, words_cache, items_index and messages_index backed the V1 keyword
     * search (Item::typeahead, Message::search, Search::bump). Search is served
     * from vector embeddings and no code reads or writes these tables.
     *
     * The two index tables hold foreign keys into words, so they go first.
     */
    public function up(): void
    {
        Schema::dropIfExists('messages_index');
        Schema::dropIfExists('items_index');
        Schema::dropIfExists('words_cache');
        Schema::dropIfExists('words');
    }

    /**
     * Recreate the tables empty, as they were before (data is not restored).
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

        if (!Schema::hasTable('items_index')) {
            Schema::create('items_index', function (Blueprint $table) {
                $table->unsignedBigInteger('itemid')->index('itemid_2');
                $table->unsignedBigInteger('wordid');
                $table->integer('popularity')->default(0);
                $table->unsignedBigInteger('categoryid')->nullable();

                $table->unique(['itemid', 'wordid'], 'itemid');
                $table->index(['wordid', 'popularity'], 'wordid');
                $table->foreign(['itemid'])->references(['id'])->on('items')->onUpdate('no action')->onDelete('cascade');
                $table->foreign(['wordid'])->references(['id'])->on('words')->onUpdate('no action')->onDelete('cascade');
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
                $table->foreign(['msgid'])->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
                $table->foreign(['groupid'])->references(['id'])->on('groups')->onUpdate('no action')->onDelete('set null');
                $table->foreign(['wordid'])->references(['id'])->on('words')->onUpdate('no action')->onDelete('cascade');
            });
        }
    }
};
