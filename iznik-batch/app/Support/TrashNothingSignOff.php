<?php

namespace App\Support;

/**
 * The sign-off on a moderator's message to a TrashNothing member.
 *
 * TrashNothing shows our mail to its members as coming from a Freegle user. The sender name
 * we give it ("{Group} Volunteers", or the moderator's name) is lost, so without this a member
 * can't tell a message from the community's moderators from one from another member. It goes
 * in the body, which TrashNothing does show.
 */
class TrashNothingSignOff
{
    public static function line(string $groupName): string
    {
        return 'From the volunteers who run ' . $groupName;
    }

    public static function append(string $body, string $groupName): string
    {
        return rtrim($body) . "\n\n" . self::line($groupName);
    }
}
