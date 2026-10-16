<?php

namespace App\Services\Ux;

use Carbon\Carbon;

/**
 * Where an experiment's counts come from when they are not flow events in Loki. An experiment names
 * its source in design.source, and config('freegle.ux.sources') maps the name to the class.
 * docs/developers/reference/ux-experiments.md, "Experiments in mail, push and the server".
 *
 * The engine reads and decides through this exactly as it does through Loki: counts per arm and
 * stratum, then BetaBinomial for the probabilities, then the same adopt, revert and inconclusive
 * rules. Only the counting and the nouns change.
 */
interface UxMetricSource
{
    /**
     * The exposure subject types this source counts, for example ['email'].
     *
     * @return string[]
     */
    public function types(): array;

    /**
     * The nouns the experiment is written with, in place of the funnel's: who, started, finished,
     * tab_started, tab_finished and note. A window is written as "a day", "7 days" or "N hours".
     * started and tab_started name the members counted ("were counted", "Counted"), who are fewer than the
     * members the experiment has reached so far, so they never say "sent": the tab writes "Exposed" for those.
     * The note says how the source counts, and what any word in it means that the tab does not explain.
     *
     * @return array{who: string, started: string, finished: string, tab_started: string, tab_finished: string, note: string}
     */
    public function words(object $exp): array;

    /**
     * Counts since the experiment started (or since $since, for an adopted experiment's holdout), as
     * of $now:
     *
     *   ['arms'   => [arm => ['starts' => n, 'completes' => n, 'strata' => [stratum => ['starts' => n, 'completes' => n]]]],
     *    'guards' => [name => [arm => ['bad' => n, 'base' => n]]],
     *    'extra'  => ['exposed' => [arm => n], 'counted' => [arm => n], ...]]
     *
     * Every arm of the experiment is present, zero when nothing was counted. 'starts' is the members
     * counted, and 'extra' shows progress before anything has matured.
     *
     * @return array{arms: array<string, array<string, mixed>>, guards: array<string, array<string, array{bad: int, base: int}>>, extra: array<string, mixed>}
     */
    public function counts(object $exp, Carbon $now, ?Carbon $since): array;

    /**
     * The guardrails: name => ['label' => ..., 'margin_points' => ...]. A guardrail trips only when
     * the arm is worse by more than its own margin. A label is the subject of a sentence ("<label>
     * got worse"), so it is one share or one number.
     *
     * @return array<string, array{label: string, margin_points: float}>
     */
    public function guardrails(object $exp): array;

    /**
     * What a run of this experiment could count: ['per_arm' => members counted on each arm over the
     * whole run, 'rate' => 0..1], so start() can refuse a test too small to read. Null when there is
     * nothing to go on, which skips the check.
     *
     * @return array{per_arm: int, rate: float}|null
     */
    public function volume(object $exp, Carbon $now): ?array;
}
