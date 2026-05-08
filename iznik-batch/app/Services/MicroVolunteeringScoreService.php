<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MicroVolunteeringScoreService
{
    private const TRUST_BASIC = 'Basic';
    private const TRUST_MODERATE = 'Moderate';
    private const PROMOTE_THRESHOLD = 90;
    private const PROMOTE_DAYS = 7;

    public function scoreAndPromote(string $since = '48 hours ago'): array
    {
        $this->score($since);
        $promoted = $this->promote();

        return ['promoted' => $promoted];
    }

    private function score(string $since): void
    {
        $cutoff = date('Y-m-d', strtotime($since));

        $users = DB::table('microactions')
            ->where('timestamp', '>', $cutoff)
            ->distinct()
            ->pluck('userid');

        Log::info("MicroVolunteeringScore: scoring {$users->count()} users");

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

                // Only update if changed and not manually overridden by a mod.
                if ($scorePositive != $action->score_positive) {
                    DB::table('microactions')
                        ->where('id', $action->id)
                        ->whereNull('modfeedback')
                        ->update(['score_positive' => $scorePositive]);
                }

                if ($scoreNegative != $action->score_negative) {
                    DB::table('microactions')
                        ->where('id', $action->id)
                        ->whereNull('modfeedback')
                        ->update(['score_negative' => $scoreNegative]);
                }
            }
        }
    }

    private function promote(): int
    {
        $count = 0;

        $users = DB::table('microactions')
            ->join('users', 'users.id', '=', 'microactions.userid')
            ->where('users.trustlevel', self::TRUST_BASIC)
            ->groupBy('microactions.userid')
            ->selectRaw('microactions.userid, 100 * SUM(score_positive) / (SUM(score_positive) + SUM(score_negative)) AS score')
            ->havingRaw('score >= ?', [self::PROMOTE_THRESHOLD])
            ->pluck('microactions.userid');

        foreach ($users as $userId) {
            $duration = DB::table('microactions')
                ->where('userid', $userId)
                ->selectRaw('DATEDIFF(MAX(timestamp), MIN(timestamp)) AS diff')
                ->value('diff');

            if ($duration >= self::PROMOTE_DAYS) {
                DB::table('users')
                    ->where('id', $userId)
                    ->update(['trustlevel' => self::TRUST_MODERATE]);
                $count++;
                Log::info("MicroVolunteeringScore: promoted user {$userId} to Moderate");
            }
        }

        return $count;
    }
}
