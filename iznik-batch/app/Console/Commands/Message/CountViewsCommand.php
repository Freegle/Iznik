<?php

namespace App\Console\Commands\Message;

use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Count how many members have viewed each recent post into messages_viewcounts, for the ModTools
 * dashboard's Popular Posts.
 *
 * Counting live does not fit the dashboard's 40s deadline: the network-wide 90 days hold about 63M
 * View rows in messages_likes, and reading them takes 54s on production even as one index scan.
 * Done once a night here, the dashboard reads a stored number per post and counts live only for the
 * posts that arrived since this ran.
 *
 * It walks back --days whole days, newest first, so a run that stops early leaves the oldest days
 * with last night's counts rather than none. Older rows are left as they are: views keep coming after
 * a post is a month old, but measured on production they were about 4% of a post's total, so a count
 * frozen at 60 days is within a few views. Posts nobody has viewed get no row.
 *
 * Every row a run writes carries counted_at = the database's NOW() when the run started, which is
 * what the dashboard reads to find where the stored counts end.
 */
#[AsCommand(name: 'messages:count-views')]
class CountViewsCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    // Posts per messages_likes statement: a bounded IN list of keyed lookups on the msgid index.
    public const BATCH = 1000;

    protected $signature = 'messages:count-views
                            {--days=60 : How many days of posts, back from today, to recount}';

    protected $description = 'Count views per recent post into messages_viewcounts for the dashboard';

    public function handle(): int
    {
        $this->registerShutdownHandlers();
        $days = max(1, (int) $this->option('days'));

        return $this->runWithLogging(function () use ($days) {
            $runStart = Carbon::parse(DB::selectOne('SELECT NOW() AS now')->now);
            $dayEnd = $runStart->copy();
            $dayStart = $runStart->copy()->startOfDay();
            $posts = 0;
            $rows = 0;

            for ($i = 0; $i < $days && ! $this->shouldStop(); $i++) {
                [$p, $r] = $this->countDay($dayStart, $dayEnd, $runStart);
                $posts += $p;
                $rows += $r;
                $dayEnd = $dayStart->copy();
                $dayStart = $dayStart->copy()->subDay();
            }

            $this->info("Counted views for {$posts} posts over {$days} days; {$rows} have been viewed.");

            return Command::SUCCESS;
        });
    }

    /**
     * Recount the posts that arrived in [$from, $to). Returns [posts looked at, rows written].
     */
    private function countDay(Carbon $from, Carbon $to, Carbon $runStart): array
    {
        $arrivals = DB::table('messages')
            ->where('arrival', '>=', $from->toDateTimeString())
            ->where('arrival', '<', $to->toDateTimeString())
            ->whereNull('deleted')
            ->pluck('arrival', 'id')
            ->all();

        $written = 0;
        foreach (array_chunk(array_keys($arrivals), self::BATCH) as $ids) {
            $counts = DB::table('messages_likes')
                ->select('msgid', DB::raw('COUNT(*) AS views'))
                ->whereIn('msgid', $ids)
                ->where('type', 'View')
                ->groupBy('msgid')
                ->get();

            $rows = [];
            foreach ($counts as $c) {
                $rows[] = [
                    'msgid' => $c->msgid,
                    'arrival' => Carbon::parse($arrivals[$c->msgid])->toDateTimeString(),
                    'views' => (int) $c->views,
                    'counted_at' => $runStart->toDateTimeString(),
                ];
            }

            if ($rows) {
                DB::table('messages_viewcounts')->upsert($rows, ['msgid'], ['arrival', 'views', 'counted_at']);
                $written += count($rows);
            }
        }

        return [count($arrivals), $written];
    }
}
