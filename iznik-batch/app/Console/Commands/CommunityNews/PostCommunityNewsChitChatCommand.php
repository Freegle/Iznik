<?php

namespace App\Console\Commands\CommunityNews;

use App\Services\CommunityNews\CommunityNewsChitChatService;
use Illuminate\Console\Command;

class PostCommunityNewsChitChatCommand extends Command
{
    protected $signature = 'community-news:post-chitchat
                            {--area= : Only post for this area id}
                            {--count= : How many items to post per area this run (default: config)}
                            {--force : Ignore the per-area cadence (post even if posted recently)}
                            {--dry-run : Compose posts but do not write newsfeed rows}';

    protected $description = 'Drip Community News items to ChitChat (the newsfeed) as the Freegle account — the engagement trial';

    public function handle(CommunityNewsChitChatService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $area = $this->option('area') !== null ? (int) $this->option('area') : null;
        $count = $this->option('count') !== null ? (int) $this->option('count') : null;

        // Manual-vs-cron mutex, as on community-news:email/research: prevents a
        // manual drip racing the hourly cron into double posts (the dup-guard
        // only catches identical consecutive posts by the system account).
        $lock = $dryRun ? null : \Illuminate\Support\Facades\Cache::lock('community-news:post-chitchat', 3600);
        if ($lock && !$lock->get()) {
            $this->warn('Another community-news:post-chitchat run is in progress; skipping.');

            return self::SUCCESS;
        }

        try {
            $result = $service->drip($dryRun, $area, (bool) $this->option('force'), $count);
        } finally {
            $lock?->release();
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Posted {$result['posts']} item(s) across {$result['areas']} area(s).");

        return self::SUCCESS;
    }
}
