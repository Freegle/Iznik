<?php

namespace App\Support;

/**
 * The sign-off on a moderator's message to a TrashNothing member.
 *
 * TrashNothing shows our mail to its members as coming from a Freegle user. The sender name
 * we give it is lost, so without this a member can't tell a message from Freegle's volunteers
 * from one from another member. It goes in the body, which TrashNothing does show.
 */
class TrashNothingSignOff
{
    public static function line(string $name): string
    {
        return 'From the volunteers who run ' . $name;
    }

    public static function append(string $body, string $name): string
    {
        return rtrim($body) . "\n\n" . self::line($name);
    }
}
