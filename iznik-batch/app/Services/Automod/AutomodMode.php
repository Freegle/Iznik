<?php

namespace App\Services\Automod;

use App\Services\AutoApproveCleanService;

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
 * A group can only be in one mode: approve takes precedence if (implausibly) a group ends
 * up listed in both, since approve is the stronger, already-live behaviour.
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
        $approveGroupIds = (new AutoApproveCleanService())->enabledGroupIds();
        if ($approveGroupIds === null || in_array($groupid, $approveGroupIds, true)) {
            return self::MODE_APPROVE;
        }

        if (in_array($groupid, self::shadowGroupIds(), true)) {
            return self::MODE_SHADOW;
        }

        return null;
    }

    /** @return int[] */
    public static function shadowGroupIds(): array
    {
        $csv = trim((string) config('freegle.automod.shadow_group_ids', ''));
        if ($csv === '') {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $csv))));
    }

    /**
     * Every group automod runs for at all, in either mode — for bulk queries like
     * messages:automod's candidate select, where checking self::for() per group would mean
     * pulling every Pending row just to throw most of them away.
     *
     * @return int[]|null null = every group (the approve gate's master switch is on, so
     *                     the shadow list is moot — approve wins for any groupid, per
     *                     self::for()); [] = automod is dark everywhere; [ids] = the union
     *                     of the approve trial and shadow lists.
     */
    public static function eligibleGroupIds(): ?array
    {
        $approveGroupIds = (new AutoApproveCleanService())->enabledGroupIds();
        if ($approveGroupIds === null) {
            return null;
        }

        return array_values(array_unique(array_merge($approveGroupIds, self::shadowGroupIds())));
    }
}
