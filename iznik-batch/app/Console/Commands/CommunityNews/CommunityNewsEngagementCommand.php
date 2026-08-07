<?php

namespace App\Console\Commands\CommunityNews;

use App\Services\CommunityNews\CommunityNewsChitChatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CommunityNewsEngagementCommand extends Command
{
    protected $signature = 'community-news:engagement
                            {--area= : Only this area id}';

    protected $description = 'Report engagement on the Community News trial: ChitChat loves + replies, and weekly email opens + clicks';

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

        $this->reportEmail();

        return self::SUCCESS;
    }

    /**
     * Weekly-email engagement from email_tracking (open pixel + click
     * redirects; only mails sent after tracking was wired carry these).
     * Grouped by the area stored in the tracking metadata.
     */
    private function reportEmail(): void
    {
        // keep-raw: (1) the group-by key is a JSON_EXTRACT/JSON_UNQUOTE/COALESCE
        // expression - the builder has no method for any of those functions; and
        // (2) COUNT(*)/SUM(...) are aggregates with aliases in a multi-row SELECT
        // list under GROUP BY, which no builder method projects.
        $byArea = DB::table('email_tracking')
            ->where('email_type', 'CommunityNews')
            ->selectRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.area')), '?') AS area")
            ->selectRaw('COUNT(*) AS sent')
            ->selectRaw('SUM(opened_at IS NOT NULL) AS opened')
            // keep-raw: SUM(clicked_at IS NOT NULL) AS clicked is an aliased aggregate in a
            // multi-row SELECT list under GROUP BY - no builder method projects one.
            ->selectRaw('SUM(clicked_at IS NOT NULL) AS clicked')
            ->groupBy('area')
            ->get();

        if ($byArea->isEmpty()) {
            $this->info('No tracked Community News emails yet (tracking applies to sends after it was wired in).');

            return;
        }

        $this->newLine();
        $this->table(
            ['Area', 'Sent', 'Opened', 'Clicked'],
            $byArea->map(fn ($r) => [$r->area, $r->sent, (int) $r->opened, (int) $r->clicked])->all()
        );

        // keep-raw: COUNT(*) AS clicks is an aggregate with an alias in a
        // multi-row SELECT list under GROUP BY - no builder method projects one.
        $topLinks = DB::table('email_tracking_clicks')
            ->join('email_tracking', 'email_tracking.id', '=', 'email_tracking_clicks.email_tracking_id')
            ->where('email_tracking.email_type', 'CommunityNews')
            ->select('email_tracking_clicks.link_position', DB::raw('COUNT(*) AS clicks'))
            ->groupBy('email_tracking_clicks.link_position')
            ->orderByDesc('clicks')
            ->limit(10)
            ->get();

        if ($topLinks->isNotEmpty()) {
            $this->table(['Link', 'Clicks'], $topLinks->map(fn ($r) => [$r->link_position, $r->clicks])->all());
        }
    }
}
