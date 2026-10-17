<?php

namespace Tests\Support;

use App\Services\LokiService;

/**
 * Records what a service would have sent to Loki, so a test can assert on the events and
 * per-run lines without a log file. Skips LokiService's constructor (which reads config) and
 * answers as enabled.
 */
class LokiSpy extends LokiService
{
    /** @var array<int, array{type: string, subtype: string, context: array<string, mixed>}> */
    public array $events = [];

    /** @var array<int, array{job: string, event: string, context: array<string, mixed>}> */
    public array $jobs = [];

    public function __construct()
    {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function logEvent(string $type, string $subtype, array $context = []): void
    {
        $this->events[] = ['type' => $type, 'subtype' => $subtype, 'context' => $context];
    }

    public function logBatchJob(string $jobName, string $event, array $context = []): void
    {
        $this->jobs[] = ['job' => $jobName, 'event' => $event, 'context' => $context];
    }

    /** @return array<int, array{type: string, subtype: string, context: array<string, mixed>}> */
    public function eventsOf(string $type, string $subtype): array
    {
        return array_values(array_filter(
            $this->events,
            fn (array $e) => $e['type'] === $type && $e['subtype'] === $subtype
        ));
    }
}
