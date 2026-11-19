<?php

namespace App\Services\TrashNothing\Verify;

/**
 * What tn:chat-parity-check found: counts by kind, the failures of each layer, and what is only
 * reported. Holds ids, kinds, reasons and field names, never text.
 */
final class ChatParityReport
{
    /** @var array<string, array<string, int>> counter => kind => n */
    private array $counts = [];

    /** @var array<string, list<array>> layer => failures */
    private array $failures = [];

    /** @var array<string, list<array>> section => notes */
    private array $notes = [];

    public function count(string $counter, string $kind): void
    {
        $this->counts[$counter][$kind] = ($this->counts[$counter][$kind] ?? 0) + 1;
    }

    public function fail(string $layer, array $detail): void
    {
        $this->failures[$layer][] = $detail;
    }

    public function note(string $section, array $detail): void
    {
        $this->notes[$section][] = $detail;
    }

    public function failed(): bool
    {
        return $this->failures !== [];
    }

    /** @return array<string, int> */
    public function counts(string $counter): array
    {
        return $this->counts[$counter] ?? [];
    }

    public function total(string $counter): int
    {
        return array_sum($this->counts($counter));
    }

    /** @return list<array> */
    public function failures(string $layer): array
    {
        return $this->failures[$layer] ?? [];
    }

    /** @return list<array> */
    public function notes(string $section): array
    {
        return $this->notes[$section] ?? [];
    }

    public function toArray(): array
    {
        return ['counts' => $this->counts, 'failures' => $this->failures, 'notes' => $this->notes, 'failed' => $this->failed()];
    }

    /** One line per layer and section, for the batch log. */
    public function summary(): array
    {
        return [
            'emails' => $this->total('email'),
            'agree' => $this->total('agree'),
            'extras' => $this->total('extra'),
            'coverage_failures' => count($this->failures('coverage')),
            'outcome_failures' => count($this->failures('outcome')),
            'content_failures' => count($this->failures('content')),
            'extra_failures' => count($this->failures('extras')),
            'explained' => count($this->notes('explained')),
            'email_extra_text' => count($this->notes('email_extra_text')),
            'unverifiable' => count($this->notes('unverifiable')),
        ];
    }
}
