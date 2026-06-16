<?php

namespace App\Monitoring\Checks;

use App\Monitoring\OutcomeResult;
use Carbon\CarbonInterface;

/**
 * Wraps an arbitrary closure returning an OutcomeResult. Use for bespoke
 * signals the standard primitives can't express — e.g. a timestamp embedded in
 * a JSON `config` value, or a cross-table comparison. The base class still
 * applies the enabledWhen / activeBetween gating before the closure runs.
 */
class CallbackCheck extends AbstractOutcomeCheck
{
    /**
     * @param  callable(CarbonInterface):OutcomeResult  $callback
     */
    public function __construct(
        string $slug,
        protected $callback,
    ) {
        $this->slug = $slug;
        $this->category = 'general';
    }

    protected function check(CarbonInterface $now): OutcomeResult
    {
        return ($this->callback)($now);
    }
}
