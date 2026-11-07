<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Whether a post is a clearance. A clearance is an Offer with messages_bulk_items rows, but so
 * is a post the auto-splitter turned into an item list, and that one is an ordinary member's
 * Offer: it stays in freebie alerts and partner feeds and gets none of the clearance extras. So
 * "has bulk items" no longer means "is a clearance"; this does. It has a twin in Go,
 * utils.IsClearanceSQL. docs/developers/reference/auto-split.md.
 *
 * Code that wants the per-item model (item lists, per-item stats, the digest's item count)
 * should keep asking about messages_bulk_items, which covers both.
 */
final class ClearanceSql
{
    /**
     * A SQL condition, true when the post whose id is $msgid (a column or a ?-free expression)
     * is a clearance.
     */
    public static function condition(string $msgid): string
    {
        return "(EXISTS (SELECT 1 FROM messages_bulk_items cbi WHERE cbi.msgid = {$msgid})"
            ." AND NOT EXISTS (SELECT 1 FROM messages_autosplit cas WHERE cas.msgid = {$msgid} AND cas.state = 'Split'))";
    }

    public static function isClearance(int $msgid): bool
    {
        return DB::table('messages_bulk_items')->where('msgid', $msgid)->exists()
            && ! DB::table('messages_autosplit')->where('msgid', $msgid)->where('state', 'Split')->exists();
    }

    /** Narrow a query to posts that are not clearances, where $msgid is the post's id column. */
    public static function whereNotClearance(Builder $query, string $msgid): Builder
    {
        return $query->whereRaw('NOT '.self::condition($msgid));
    }
}
