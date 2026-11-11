<?php

namespace App\Console\Commands\Support;

use App\Services\Support\FirstPassFailure;
use App\Services\Support\FirstPassService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Evaluation only: runs the first pass again over recent finished cases and reports what it cost
 * and concluded.
 *
 * It writes nothing to cases or mail. Each pass writes a support_ai_runs row (no moderator, so it
 * never counts against a referrer) so the tokens it spends are visible. It refuses to start while any case is Pending or
 * Running, and stops if one appears, so it never competes with real referrals for the helper.
 * The model sees today's data, not the data at referral time. The report holds ids only.
 */
#[AsCommand(name: 'support:first-pass-replay')]
class SupportFirstPassReplayCommand extends Command
{
    public const DEFAULT_LIMIT = 5;

    public const MAX_LIMIT = 20;

    protected $signature = 'support:first-pass-replay {--limit=5 : Cases to replay (default 5, at most 20)}';

    protected $description = 'Replay the first pass over recent finished cases and report tokens, time and conclusions';

    public function handle(FirstPassService $service): int
    {
        $limit = max(1, min(self::MAX_LIMIT, (int) $this->option('limit') ?: self::DEFAULT_LIMIT));

        if ($this->live()) {
            $this->error('Refusing to start: a support case is Pending or Running.');

            return self::FAILURE;
        }

        $ids = DB::table('support_cases')->where('firstpass_status', 'Done')->whereNotNull('brief')
            ->orderByDesc('id')->limit($limit)->pluck('id')->all();

        $passes = [];
        $stopped = null;
        foreach ($ids as $id) {
            if ($this->live()) {
                $stopped = 'A support case appeared, so the replay stopped.';
                break;
            }
            $case = DB::table('support_cases')->where('id', $id)->first();
            try {
                $response = $service->ask($service->buildRequest($case));
            } catch (FirstPassFailure $f) {
                if ($f->mayHaveSpent()) {
                    $service->storeRun(null, $case->userid, "Replay of support case {$id}", null, $f->reason);
                }
                $passes[] = ['caseid' => (int) $id, 'error' => $f->reason];

                continue;
            }
            $brief = $response['brief'];
            // The tokens and model are kept; the member's facts and the transcript are not.
            $service->storeRun(null, $case->userid, "Replay of support case {$id}", array_diff_key($response, ['transcript' => 1, 'brief' => 1]), null);
            $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
            $passes[] = [
                'caseid' => (int) $id,
                'inputTokens' => (int) ($usage['inputTokens'] ?? 0),
                'outputTokens' => (int) ($usage['outputTokens'] ?? 0),
                'durationMs' => (int) ($usage['durationMs'] ?? 0),
                'classification' => is_string($brief['classification'] ?? null) ? $brief['classification'] : null,
                'confidence' => is_array($brief['confidence'] ?? null) && is_string($brief['confidence']['level'] ?? null) ? $brief['confidence']['level'] : null,
                'openQuestions' => is_array($brief['open_questions'] ?? null) ? count($brief['open_questions']) : 0,
            ];
        }

        $ok = array_values(array_filter($passes, fn ($p) => ! isset($p['error'])));
        $avg = fn (string $k) => $ok ? round(array_sum(array_column($ok, $k)) / count($ok), 1) : null;

        $this->line(json_encode([
            'note' => 'The model saw today\'s data, not the data at the time of referral.',
            'stopped' => $stopped,
            'passes' => $passes,
            'averages' => ['inputTokens' => $avg('inputTokens'), 'outputTokens' => $avg('outputTokens'), 'durationMs' => $avg('durationMs'), 'openQuestions' => $avg('openQuestions')],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $stopped === null ? self::SUCCESS : self::FAILURE;
    }

    private function live(): bool
    {
        return DB::table('support_cases')->whereIn('firstpass_status', ['Pending', 'Running'])->exists();
    }
}
