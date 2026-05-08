<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhatjobsSpamService
{
    /**
     * Delete jobs with identical body content posted across many locations (spam).
     *
     * V1: Jobs::deleteSpammyJobs('jobs') in cron/whatjobs_spam.php.
     * Uses a do-while DELETE LIMIT 1 loop to avoid long-running single transactions.
     */
    public function deleteSpammyJobs(): array
    {
        Log::info('WhatjobsSpam: counting spammy jobs');

        $spams = DB::table('jobs')
            ->selectRaw('COUNT(*) as count, title, bodyhash')
            ->whereNotNull('bodyhash')
            ->groupBy('bodyhash')
            ->having('count', '>', 50)
            ->get();

        Log::info('WhatjobsSpam: deleting spammy jobs', ['spam_hashes' => $spams->count()]);

        $totalDeleted = 0;

        foreach ($spams as $spam) {
            Log::info("Delete spammy job {$spam->title} * {$spam->count}");

            do {
                $affected = DB::table('jobs')
                    ->where('bodyhash', $spam->bodyhash)
                    ->limit(1)
                    ->delete();
            } while ($affected > 0);

            $totalDeleted += $spam->count;
        }

        return [
            'deleted' => $totalDeleted,
            'spam_hashes' => $spams->count(),
        ];
    }
}
