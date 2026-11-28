<?php

namespace App\Services\Servers;

use App\Mail\Servers\ServerSizingReportMail;
use App\Models\ServerJobAlert;
use App\Models\ServerSample;
use Illuminate\Support\Carbon;

/**
 * Gathers everything the weekly sizing report needs: what Katapult says we rent and what it
 * offers, the stored readings, the analysis of the two, and Claude's review of the analysis.
 */
class ServerSizingReport
{
    public function __construct(
        private readonly KatapultClient $katapult,
        private readonly ServerSizingAnalyser $analyser,
        private readonly ServerSizingCommentary $commentary,
    ) {}

    /**
     * @return array{analysis: array<string, mixed>, review: array{changes: list<string>, watch: list<string>, caveats: list<string>}|null}
     */
    public function build(bool $withReview = true): array
    {
        $now = Carbon::now();

        $analysis = $this->analyser->analyse(
            $this->katapult->machines(),
            $this->katapult->packages(),
            $this->katapult->disks(),
            $this->katapult->prices(),
            $this->samplesByHost($now),
            $now,
            (float) config('freegle.servers.price_discount', 0),
            (int) config('freegle.servers.min_history_days', 14),
            self::parseHostNotes((string) config('freegle.servers.host_notes', '')),
            $this->lateJobs($now),
            (float) config('freegle.servers.other_monthly_cost', 0),
            (float) config('freegle.servers.vat_rate', 0.2),
        );

        return [
            'analysis' => $analysis,
            'review' => $withReview ? $this->commentary->write(ServerSizingReportMail::factsFor($analysis)) : null,
        ];
    }

    /**
     * "db-9=bulk|Background work;spare=keep|Kept so work can be resumed" as name => role and note.
     * An entry with a role nobody knows is left out, so a typing slip does not change a verdict.
     *
     * @return array<string, array{role: string, note: string}>
     */
    public static function parseHostNotes(string $setting): array
    {
        $notes = [];

        foreach (explode(';', $setting) as $entry) {
            if (! preg_match('/^\s*([^=\s]+)\s*=\s*(bulk|keep)\s*(?:\|\s*(.*?))?\s*$/s', $entry, $m)) {
                continue;
            }
            $notes[strtolower($m[1])] = ['role' => $m[2], 'note' => trim($m[3] ?? '')];
        }

        return $notes;
    }

    /**
     * How long each late-job alert showed in the last week, longest first. Each stored row is one
     * fifteen-minute look, so four rows are an hour.
     *
     * @return list<array{slug: string, hours: float}>
     */
    private function lateJobs(Carbon $now): array
    {
        $late = [];

        foreach (ServerJobAlert::where('sampled_at', '>=', $now->copy()->subDays(7))->pluck('slug')->countBy() as $slug => $looks) {
            $late[] = ['slug' => (string) $slug, 'hours' => round($looks / 4, 1)];
        }
        usort($late, fn (array $a, array $b) => $b['hours'] <=> $a['hours']);

        return $late;
    }

    /**
     * Readings for the trend weeks, oldest first, grouped by host name.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function samplesByHost(Carbon $now): array
    {
        $byHost = [];

        ServerSample::where('sampled_at', '>=', $now->copy()->subWeeks(ServerSizingAnalyser::TREND_WEEKS))
            ->orderBy('sampled_at')
            ->orderBy('id')
            ->toBase()
            ->cursor()
            ->each(function (object $row) use (&$byHost) {
                $byHost[$row->host][] = (array) $row;
            });

        return $byHost;
    }
}
