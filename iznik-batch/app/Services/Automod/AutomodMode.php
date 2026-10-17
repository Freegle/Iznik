<?php

namespace App\Services\Automod;

use App\Services\AutoApproveCleanService;
use App\Support\EnvSetting;

/**
 * Which of the two independent automod rollout gates, if either, a group is in.
 *
 * The two lists are deliberately separate (plans/active/automod-flowchart.md):
 *
 * - "approve" reuses AutoApproveCleanService::enabledGroupIds() exactly — the same group
 *   set that already governs #639's clean-path auto-approve. A chart "approve" there feeds
 *   straight into that existing publish path; the chart never auto-rejects.
 * - "shadow" (freegle.automod.shadow_group_ids) runs the chart and records the verdict on
 *   messages_automod, but changes nothing a member or moderator sees. It exists purely to
 *   gather agreement numbers before a community is trusted enough to move to approve mode.
 *
 * Shadow wins. A group in the shadow list stays shadow even when the approve list, or the
 * FREEGLE_AUTOAPPROVE_ENABLED master switch, would otherwise put it in approve mode: shadow is
 * the more cautious claim, and the global switch must never silently promote a community that
 * is still being watched. The Go API (utils.AutomodMode) mirrors this.
 */
class AutomodMode
{
    public const MODE_APPROVE = 'approve';
    public const MODE_SHADOW  = 'shadow';

    /**
     * @return string|null one of self::MODE_APPROVE, self::MODE_SHADOW, or null (automod
     *                      does not run for this group at all).
     */
    public static function for(int $groupid): ?string
    {
        if (in_array($groupid, self::shadowGroupIds(), true)) {
            return self::MODE_SHADOW;
        }

        $approveGroupIds = (new AutoApproveCleanService())->enabledGroupIds();
        if ($approveGroupIds === null || in_array($groupid, $approveGroupIds, true)) {
            return self::MODE_APPROVE;
        }

        return null;
    }

    /** Whether automated review can publish on this community. */
    public static function isApproveMode(int $groupid): bool
    {
        return self::for($groupid) === self::MODE_APPROVE;
    }

    /** @return int[] */
    public static function shadowGroupIds(): array
    {
        return EnvSetting::groupIds(config('freegle.automod.shadow_group_ids'));
    }

    /**
     * Every group automod runs for at all, in either mode — for bulk queries like
     * messages:automod's candidate select, where checking self::for() per group would mean
     * pulling every Pending row just to throw most of them away.
     *
     * @return int[]|null null = every group (the approve gate's master switch is on, so
     *                     every community is in one mode or the other); [] = automod is dark
     *                     everywhere; [ids] = the union of the approve trial and shadow lists.
     */
    public static function eligibleGroupIds(): ?array
    {
        $approveGroupIds = (new AutoApproveCleanService())->enabledGroupIds();
        if ($approveGroupIds === null) {
            return null;
        }

        return array_values(array_unique(array_merge($approveGroupIds, self::shadowGroupIds())));
    }

    /**
     * The set of approve-mode communities as one SQL condition on $column (a groupid), for
     * every candidate query that has to agree on it: the clean cron, the 48h sweep, the
     * earned-reach gate, the microvolunteer queue and the health monitor. The ids are
     * integers from config, so the fragment is safe to splice. Callers put a keep-raw note
     * on their whereRaw: the shape (an IN list, a NOT IN list, or always/never) depends on
     * the switches, which the builder cannot express.
     */
    public static function approveModeSql(string $column): string
    {
        $approve = (new AutoApproveCleanService())->enabledGroupIds();
        $shadow = self::shadowGroupIds();

        if ($approve === null) {
            return $shadow === [] ? '(1 = 1)' : "({$column} NOT IN (" . implode(',', $shadow) . '))';
        }

        $approve = array_values(array_diff($approve, $shadow));

        return $approve === [] ? '(1 = 0)' : "({$column} IN (" . implode(',', $approve) . '))';
    }
}
