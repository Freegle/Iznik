<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restore the legacy whitelist entries that the one-off concern_keywords
 * migration turned into flag words.
 *
 * spam_keywords.action carried three values, but the migration only
 * special-cased 'Spam', so 'Whitelist' fell through to 'review' alongside
 * 'Review'. Whitelist rows are the protective ones - place and shop names
 * that would otherwise trip a shorter blocked word - so instead of shielding
 * those names they became live flags. On production that put 13 keywords into
 * the review list that were never meant to flag anything: grass, Butt Green,
 * Butt Street, Cock Hotel, Cock Lane, Cock Street, Day Lewis Pharmacy, Dick
 * Place, Lloyd's pharmacy, queer as folk, Skyrmans Fee, Superdrug Pharmacy
 * and Tilney Cum Islington. Each of the shorter words they exist to protect
 * against - ass, cock, dick, queer, Pharmacy, pound - is itself a live flag,
 * so those names flagged twice over.
 *
 * Of the 19 whitelist words, five survived as 'allowed' only because they
 * also existed in worrywords as type 'Allowed' and were inserted first, which
 * is why the damage looked partial.
 *
 * 'grass' is the exception and is deleted rather than allowed. Under the old
 * engine it needed protecting because matching was on substrings, so it caught
 * the blocked word 'ass'. Matching is now anchored to word boundaries, so
 * nothing fires inside 'grass' and it needs no entry. An 'allowed' entry would
 * be worse than useless: allowed phrases are cut out of the text before
 * scanning, so it would take the word out of four Schedule 9 plant keywords
 * that end in it - crimson fountain grass, perennial veldt grass, purple
 * pampas grass and purple veldt grass - all of which block rather than flag.
 * 'glass' is left absent for the same reason.
 *
 * Driven off spam_keywords rather than a fixed list of ids, so it is
 * idempotent and matches whatever that table holds. The two tables use
 * different collations, so every join names one explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('concern_keywords') || !Schema::hasTable('spam_keywords')) {
            return;
        }

        DB::statement("
            UPDATE concern_keywords ck
              JOIN spam_keywords sk
                ON LOWER(sk.word) COLLATE utf8mb4_unicode_ci
                 = LOWER(ck.keyword) COLLATE utf8mb4_unicode_ci
               SET ck.category = 'allowed'
             WHERE sk.action = 'Whitelist'
               AND ck.category = 'review'
               AND ck.scope = 'global'
        ");

        // 'grass' protects nothing under word-boundary matching, and as an
        // allowed phrase it would be cut out of the text before scanning,
        // disabling the four Schedule 9 plant keywords that end in it.
        DB::table('concern_keywords')
            ->where('keyword', 'grass')
            ->where('scope', 'global')
            ->delete();
    }

    public function down(): void
    {
        if (!Schema::hasTable('concern_keywords') || !Schema::hasTable('spam_keywords')) {
            return;
        }

        DB::statement("
            UPDATE concern_keywords ck
              JOIN spam_keywords sk
                ON LOWER(sk.word) COLLATE utf8mb4_unicode_ci
                 = LOWER(ck.keyword) COLLATE utf8mb4_unicode_ci
               SET ck.category = 'review'
             WHERE sk.action = 'Whitelist'
               AND ck.category = 'allowed'
               AND ck.scope = 'global'
        ");
    }
};
