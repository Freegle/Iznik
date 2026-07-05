<?php

namespace App\Console\Commands\CommunityNews;

use App\Services\CommunityNews\CommunityNewsChitChatService;
use Illuminate\Console\Command;

class CommunityNewsEngagementCommand extends Command
{
    protected $signature = 'community-news:engagement
                            {--area= : Only this area id}';

    protected $description = 'Report engagement (loves + replies) on the Community News ChitChat trial posts';

    public function handle(CommunityNewsChitChatService $service): int
    {
        $area = $this->option('area') !== null ? (int) $this->option('area') : null;

        $rows = $service->engagement($area);

        if (empty($rows)) {
            $this->info('No Community News ChitChat posts yet.');
            return self::SUCCESS;
        }

        $totalLoves = array_sum(array_column($rows, 'loves'));
        $totalReplies = array_sum(array_column($rows, 'replies'));

        $this->table(
            ['Newsfeed', 'Area', 'Title', 'Loves', 'Replies', 'Posted'],
            array_map(fn ($r) => [
                $r['newsfeedid'],
                $r['area'],
                \Illuminate\Support\Str::limit($r['title'], 40),
                $r['loves'],
                $r['replies'],
                $r['posted_at'],
            ], $rows)
        );

        $this->info(sprintf(
            '%d post(s): %d love(s), %d reply/replies. (Note: newsfeed has no per-post view counter.)',
            count($rows),
            $totalLoves,
            $totalReplies
        ));

        return self::SUCCESS;
    }
}
