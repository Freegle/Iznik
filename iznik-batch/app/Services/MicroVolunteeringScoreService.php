<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MicroVolunteeringScoreService
{
    private const TRUST_BASIC = 'Basic';
    private const TRUST_MODERATE = 'Moderate';
    private const PROMOTE_THRESHOLD = 90;
    private const PROMOTE_DAYS = 7;

    public function scoreAndPromote(string $since = '48 hours ago', bool $dryRun = false): array
    {
        $scored = $this->score($since, $dryRun);
        $promoted = $this->promote($dryRun);

        return ['scored' => $scored, 'promoted' => $promoted];
    }

    private function score(string $since, bool $dryRun = false): int
    {
        $cutoff = date('Y-m-d', strtotime($since));

        $users = DB::table('microactions')
            ->where('timestamp', '>', $cutoff)
            ->distinct()
            ->pluck('userid');

        Log::info("MicroVolunteeringScore: scoring {$users->count()} users");

        $scored = 0;

        foreach ($users as $userId) {
            $actions = DB::table('microactions')
                ->where('timestamp', '>=', $cutoff)
                ->where('userid', $userId)
                ->whereNotNull('msgid')
                ->get();

            foreach ($actions as $action) {
                $others = DB::table('microactions')
                    ->where('timestamp', '>=', $cutoff)
                    ->where('msgid', $action->msgid)
                    ->where('userid', '!=', $userId)
                    ->whereNotNull('result')
                    ->get();

                if ($others->count() <= 1) {
                    continue;
                }

                // Tally verdicts.
                $verdict = [];
                foreach ($others as $other) {
                    $verdict[$other->result] = ($verdict[$other->result] ?? 0) + 1;
                }
                arsort($verdict);
                $majority = array_key_first($verdict);

                $scorePositive = ($majority === $action->result) ? 1 : 0;
                $scoreNegative = ($majority !== $action->result) ? 1 : 0;

                $changed = false;

                // Only update if changed and not manually overridden by a mod.
                if ($scorePositive != $action->score_positive) {
                    if (!$dryRun) {
                        DB::table('microactions')
                            ->where('id', $action->id)
                            ->whereNull('modfeedback')
                            ->update(['score_positive' => $scorePositive]);
                    }
                    $changed = true;
                }

                if ($scoreNegative != $action->score_negative) {
                    if (!$dryRun) {
                        DB::table('microactions')
                            ->where('id', $action->id)
                            ->whereNull('modfeedback')
                            ->update(['score_negative' => $scoreNegative]);
                    }
                    $changed = true;
                }

                if ($changed) {
                    $scored++;
                }
            }
        }

        return $scored;
    }

    private function promote(bool $dryRun = false): int
    {
        $count = 0;

        // keep-raw: 100 * SUM(score_positive) / (SUM(score_positive) + SUM(score_negative))
        // is arithmetic combining two aggregates under GROUP BY, projected as an ALIAS
        // ("score") that the HAVING clause then filters on. No query-builder method
        // projects an aliased aggregate expression in a multi-row SELECT list, and
        // havingRaw's "score >= ?" only works because that alias exists - both stay raw.
        $users = DB::table('microactions')
            ->join('users', 'users.id', '=', 'microactions.userid')
            ->where('users.trustlevel', self::TRUST_BASIC)
            ->groupBy('microactions.userid')
            ->selectRaw('microactions.userid, 100 * SUM(score_positive) / (SUM(score_positive) + SUM(score_negative)) AS score')
            ->havingRaw('score >= ?', [self::PROMOTE_THRESHOLD])
            ->pluck('microactions.userid');

        foreach ($users as $userId) {
            // DATEDIFF(MAX(timestamp), MIN(timestamp)) converts to two plain aggregate
            // queries plus a calendar-day diff in PHP (see coding-standards: DATEDIFF
            // counts calendar-day boundaries, so both ends are truncated to startOfDay()
            // before diffing - matches MySQL's date-only semantics exactly).
            $maxTs = DB::table('microactions')->where('userid', $userId)->max('timestamp');
            $minTs = DB::table('microactions')->where('userid', $userId)->min('timestamp');

            $duration = ($maxTs !== null && $minTs !== null)
                ? Carbon::parse($maxTs)->startOfDay()->diffInDays(Carbon::parse($minTs)->startOfDay(), true)
                : null;

            if ($duration >= self::PROMOTE_DAYS) {
                if (!$dryRun) {
                    DB::table('users')
                        ->where('id', $userId)
                        ->update(['trustlevel' => self::TRUST_MODERATE]);
                    Log::info("MicroVolunteeringScore: promoted user {$userId} to Moderate");
                }
                $count++;
            }
        }

        return $count;
    }
}
