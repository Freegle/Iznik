<?php

namespace App\Services\AutoSplit;

use Illuminate\Database\Query\Builder;

/**
 * The auto-splitter's crops are extra messages_attachments rows: a per-item piece of one of the
 * member's photos, sharing its externaluid, with the crop in externalmods. They belong with
 * their items. Anything that sends a post's own photos somewhere (a partner feed, LoveJunk)
 * leaves them out, or the photo they were cut from would arrive two or three times, whole,
 * because a partner rebuilds the picture from the upload and not from our crop.
 * docs/developers/reference/auto-split.md.
 */
final class Crops
{
    /** True when an attachment row's externalmods describe a crop. */
    public static function isCrop(?string $externalmods): bool
    {
        $mods = json_decode((string) $externalmods, true);

        return is_array($mods) && ! empty($mods['cw']) && ! empty($mods['ch']);
    }

    /** Leave crops out of a messages_attachments query. */
    public static function exclude(Builder $query, string $column = 'externalmods'): Builder
    {
        return $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, 'not like', '%"cw":%'));
    }
}
