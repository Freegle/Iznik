<?php

namespace App\Services\Servers;

use App\Mail\Servers\ServerSizingReportMail;
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
        );

        return [
            'analysis' => $analysis,
            'review' => $withReview ? $this->commentary->write(ServerSizingReportMail::factsFor($analysis)) : null,
        ];
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
