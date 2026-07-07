<?php

namespace App\Monitoring;

use Carbon\CarbonInterface;

/**
 * An outcome assertion for one scheduled task: "did the work actually happen?"
 *
 * Implementations live in App\Monitoring\Checks. The registry
 * (App\Monitoring\ScheduledOutcomeRegistry) wires concrete checks to the
 * scheduled tasks they monitor; monitor:scheduled-outcomes evaluates them.
 */
interface OutcomeCheck
{
    /** The scheduled task this check monitors (its console.php command string). */
    public function slug(): string;

    /** Human-readable description, surfaced in output. */
    public function description(): string;

    /** Coarse grouping: e.g. 'fire-once-output' or 'cursor-staleness'. */
    public function category(): string;

    /** Evaluate the check as of $now (the app-clock instant, UTC). */
    public function evaluate(CarbonInterface $now): OutcomeResult;
}
