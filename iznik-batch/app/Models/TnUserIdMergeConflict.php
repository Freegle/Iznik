<?php

namespace App\Models;

/**
 * User::merge was asked to join two accounts holding different tnuserids - two
 * Trash Nothing accounts, not one member's twins. The merge keeps one id and
 * deletes the other, after which TN posts from the lost id resolve to nobody, and
 * it cannot be undone. Refused unless forced; reported to Sentry either way, so a
 * caller deciding two TN accounts are one person is seen.
 */
class TnUserIdMergeConflict extends \RuntimeException
{
}
