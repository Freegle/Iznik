<?php

namespace App\Monitoring\Checks;

use App\Monitoring\OutcomeCheck;
use App\Monitoring\OutcomeResult;
use Carbon\CarbonInterface;

/**
 * Base for outcome checks. Handles the two cross-cutting concerns shared by
 * every check — an optional enabling precondition and an optional active-hours
 * window — and delegates the actual assertion to check().
 *
 * Both yield SKIPPED (never BREACH) so a deliberately-disabled job, or a job
 * checked outside its due window, can't raise a false alarm.
 */
abstract class AbstractOutcomeCheck implements OutcomeCheck
{
    protected string $slug;
    protected string $description = '';
    protected string $category = 'general';
    protected string $severity = 'error';

    /** @var (callable():bool)|null */
    protected $enabledWhen = null;

    protected ?int $activeFromHour = null;
    protected ?int $activeToHour = null;
    protected ?string $timezone = null;

    public function slug(): string
    {
        return $this->slug;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    public function describedAs(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function inCategory(string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function withSeverity(string $severity): static
    {
        $this->severity = $severity;

        return $this;
    }

    /** Gate the check on a (usually config-derived) precondition. */
    public function enabledWhen(callable $callback): static
    {
        $this->enabledWhen = $callback;

        return $this;
    }

    /**
     * Only evaluate during [$fromHour, $toHour) in the given timezone (default
     * freegle.timezone). Used to check a daily job after its due time + slack.
     */
    public function activeBetween(int $fromHour, int $toHour, ?string $timezone = null): static
    {
        $this->activeFromHour = $fromHour;
        $this->activeToHour = $toHour;
        $this->timezone = $timezone;

        return $this;
    }

    protected function isEnabled(): bool
    {
        return $this->enabledWhen === null ? true : (bool) ($this->enabledWhen)();
    }

    protected function isWithinActiveWindow(CarbonInterface $now): bool
    {
        if ($this->activeFromHour === null || $this->activeToHour === null) {
            return true;
        }

        $tz = $this->timezone ?? config('freegle.timezone', 'Europe/London');
        $hour = $now->copy()->setTimezone($tz)->hour;

        return $hour >= $this->activeFromHour && $hour < $this->activeToHour;
    }

    public function evaluate(CarbonInterface $now): OutcomeResult
    {
        if (! $this->isEnabled()) {
            return OutcomeResult::skipped($this->slug, 'disabled by precondition');
        }

        if (! $this->isWithinActiveWindow($now)) {
            return OutcomeResult::skipped(
                $this->slug,
                "outside active window {$this->activeFromHour}:00-{$this->activeToHour}:00"
            );
        }

        return $this->check($now);
    }

    /** The concrete assertion. Only reached when enabled and in-window. */
    abstract protected function check(CarbonInterface $now): OutcomeResult;
}
