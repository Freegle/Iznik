<?php

/**
 * The deploy note for a finished UX experiment: the pull request that removed its code adds one line here, and the
 * hourly ux:experiments-tick records the close within the hour after the batch deploys
 * (UxExperimentService::applyCleanups, the same as `ux:experiment close <name> --cleanup-pr=<url>`).
 *
 * An entry:
 *   ['name' => 'my-experiment', 'cleanup_pr' => 'https://github.com/Freegle/Iznik/pull/NNNN'],
 *
 * It closes only an experiment with a verdict (adopted, reverted, inconclusive or stopped) that has no cleanup
 * recorded. A draft, a running or an unknown experiment is left alone, and so is one whose name is still in
 * config/ux_switches.php, because its code is evidently not gone. An entry is harmless once it has been applied, so
 * it stays. tests/Unit/Ux/UxCleanupsTest.php fails an entry that is malformed or names a switch that still exists in
 * ux_switches.php, iznik-nuxt3/ux-experiments.manifest.json or ux.ServerSwitches.
 *
 * docs/developers/reference/ux-experiments.md, "Corpse control".
 */
return [
    ['name' => 'give-photos-carry-on', 'cleanup_pr' => 'https://github.com/Freegle/Iznik/pull/1852'],
    ['name' => 'reply-what-happens-next', 'cleanup_pr' => 'https://github.com/Freegle/Iznik/pull/1852'],
    ['name' => 'join-what-happens-next', 'cleanup_pr' => 'https://github.com/Freegle/Iznik/pull/1852'],
    ['name' => 'reply-job-interstitial', 'cleanup_pr' => 'https://github.com/Freegle/Iznik/pull/1868'],
    ['name' => 'digest-subject', 'cleanup_pr' => 'https://github.com/Freegle/Iznik/pull/1881'],
    ['name' => 'push-posts-title', 'cleanup_pr' => 'https://github.com/Freegle/Iznik/pull/1903'],
];
