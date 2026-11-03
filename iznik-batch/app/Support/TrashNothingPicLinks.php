<?php

namespace App\Support;

/**
 * The link to a post's photos on TrashNothing, which TrashNothing puts in the post's text.
 *
 * TrashNothing ends a post with "Check out the pictures at:" and a trashnothing.com/pics/
 * address, and sometimes with the address alone. Freegle shows the photos as attachments, so
 * the link is taken out of the text whichever way the post arrives.
 */
class TrashNothingPicLinks
{
    public static function strip(?string $textBody): ?string
    {
        if (! $textBody) {
            return $textBody;
        }

        // The "Check out the pictures..." block with its address.
        $textBody = preg_replace(
            '/Check out the pictures[\s\S]*?https:\/\/trashnothing[\s\S]*?pics\/[a-zA-Z0-9]*/',
            '',
            $textBody
        );

        // An address on a line of its own, with no intro.
        $textBody = preg_replace('/^https:\/\/trashnothing\.com\/pics\/\S*[ \t]*$/m', '', $textBody);

        // What is left where the link was: blank lines run together, and none at the end.
        return rtrim(preg_replace("/\n{3,}/", "\n\n", $textBody));
    }
}
