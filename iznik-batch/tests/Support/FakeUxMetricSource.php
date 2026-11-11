<?php

namespace Tests\Support;

use App\Services\Ux\UxMetricSource;
use Carbon\Carbon;

/**
 * A metric source the engine tests control. They register it as config('freegle.ux.sources.fake') and
 * set what it counts, what its guardrails are and what its volume is; the engine resolves it with
 * app(), so its settings are static. Every call to counts() is recorded.
 */
final class FakeUxMetricSource implements UxMetricSource
{
    /** @var array{arms?: array<string, array<string, mixed>>, guards?: array<string, array<string, array{bad: int, base: int}>>, extra?: array<string, mixed>} */
    public static array $counts = [];

    /** @var array<string, array{label: string, margin_points: float}> */
    public static array $guardrails = [];

    /** @var array{per_arm: int, rate: float}|null */
    public static ?array $volume = null;

    public static ?\Throwable $fails = null;

    /** @var array<int, array{now: Carbon, since: ?Carbon}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$counts = [];
        self::$guardrails = [];
        self::$volume = null;
        self::$fails = null;
        self::$calls = [];
    }

    /**
     * Counts for the arms, as arm => [starts, completes].
     *
     * @param  array<string, array{0: int, 1: int}>  $arms
     * @param  array<string, array<string, array{0: int, 1: int}>>  $guards  name => arm => [bad, base]
     */
    public static function counting(array $arms, array $guards = []): void
    {
        $out = ['arms' => [], 'guards' => [], 'extra' => ['exposed' => [], 'counted' => []]];
        foreach ($arms as $arm => [$starts, $completes]) {
            $out['arms'][$arm] = ['starts' => $starts, 'completes' => $completes, 'strata' => ['all' => ['starts' => $starts, 'completes' => $completes]]];
            $out['extra']['exposed'][$arm] = $starts + 10;
            $out['extra']['counted'][$arm] = $starts;
        }
        foreach ($guards as $name => $byArm) {
            foreach ($byArm as $arm => [$bad, $base]) {
                $out['guards'][$name][$arm] = ['bad' => $bad, 'base' => $base];
            }
        }
        self::$counts = $out;
    }

    public function types(): array
    {
        return ['email'];
    }

    public function words(object $exp): array
    {
        return [
            'who' => 'members',
            'started' => 'were counted',
            'finished' => 'clicked a link within a day',
            'tab_started' => 'Counted',
            'tab_finished' => 'Clicked within a day',
            'note' => 'Each member is counted once.',
        ];
    }

    public function counts(object $exp, Carbon $now, ?Carbon $since): array
    {
        self::$calls[] = ['now' => $now->copy(), 'since' => $since?->copy()];
        if (self::$fails) {
            throw self::$fails;
        }

        return self::$counts + ['arms' => [], 'guards' => [], 'extra' => []];
    }

    public function guardrails(object $exp): array
    {
        return self::$guardrails;
    }

    public function volume(object $exp, Carbon $now): ?array
    {
        return self::$volume;
    }
}
