<?php

namespace App\Services\Automod;

use App\Models\MessageGroup;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one definition of "a Pending copy the chart can review", shared by the command that
 * reviews them (AutomodService::candidates) and the monitor that watches for the ones it cannot
 * (monitor:automod-health). They used to carry their own copies of the exclusions and drifted:
 * the monitor counted held, deleted and spam-flagged posts the review would never retry, so a
 * failure on one of them could never clear and paged every quarter of an hour for ever.
 *
 * Aliases in the returned query: mg (messages_groups), m (messages), u (users). Callers add
 * their own joins and conditions on top.
 */
final class AutomodEligibility
{
    /**
     * A Pending copy on an origin community the clean path could one day publish: not held (on
     * the copy or the message), not flagged as spam, not deleted (the copy, the message or the
     * member), not a rippled-in copy (those are never reviewed), whose content check has run and
     * has seen the latest edit (the chart's facts come from the check's findings, so it must not
     * decide on findings that predate an edit).
     *
     * @param int[]|null $eligibleGroupIds null = every community; otherwise only these
     */
    public static function reviewable(?array $eligibleGroupIds): Builder
    {
        return DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->join('users as u', 'u.id', '=', 'm.fromuser')
            ->where('mg.collection', MessageGroup::COLLECTION_PENDING)
            ->where('mg.deleted', 0)
            ->where('mg.rippled_in', 0)
            ->whereNull('mg.heldby')
            ->whereNull('m.heldby')
            ->whereNull('mg.spamreason')
            ->whereNull('m.spamreason')
            ->whereNull('m.deleted')
            ->whereNull('u.deleted')
            ->whereNotNull('mg.contentcheck_checked_at')
            // The check and the review run in the same minute, in no fixed order.
            ->where(function ($query) {
                $query->whereNull('m.editedat')
                    ->orWhereColumn('mg.contentcheck_checked_at', '>=', 'm.editedat');
            })
            ->when(
                $eligibleGroupIds !== null,
                fn ($query) => $query->whereIn('mg.groupid', $eligibleGroupIds)
            );
    }
}
