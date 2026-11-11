<?php

namespace App\Console\Commands\User;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Remove repeated rows from users_aboutme, keeping the history of real changes.
 *
 * users_aboutme keeps one row per change and readers take the latest. Until the
 * API skipped unchanged saves, every settings save added a row: production held
 * 10.7M rows for 87,633 members, and in a sample of 5,265 members 99% of rows
 * repeated the text of the row before them.
 *
 * For each member, a row whose text equals the previous row's text (ordered by
 * timestamp, then id) is deleted. The first row of each run stays, so the
 * timestamp left is when the text was first written. Changes, including a return
 * to earlier text, are kept.
 *
 * Deletes one row per statement, so each write is small for the cluster and the
 * job can run while members are using the site. Works through members in userid
 * order and can be stopped and restarted with --from.
 */
#[AsCommand(name: 'users:dedupe-aboutme')]
class DedupeAboutMeCommand extends Command
{
    protected $signature = 'users:dedupe-aboutme
                            {--apply : Delete the repeated rows. Without this the command only reports what it would do}
                            {--users=500 : Members to read per batch}
                            {--pause=200 : Milliseconds to wait between batches, to keep replication quiet}
                            {--row-pause=1 : Milliseconds to wait between single-row deletes}
                            {--from=0 : Start after this userid}';

    protected $description = 'Delete users_aboutme rows that repeat the previous text for the same member';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $batch = max(1, (int) $this->option('users'));
        $pauseMs = max(0, (int) $this->option('pause'));
        $rowPauseMs = max(0, (int) $this->option('row-pause'));
        $cursor = (int) $this->option('from');

        $this->line($apply ? 'Applying changes.' : 'Dry run: nothing will be deleted. Add --apply to delete.');

        $members = 0;
        $kept = 0;
        $repeats = 0;

        while (true) {
            $userids = DB::table('users_aboutme')
                ->where('userid', '>', $cursor)
                ->orderBy('userid')
                ->distinct()
                ->limit($batch)
                ->pluck('userid')
                ->all();

            if (empty($userids)) {
                break;
            }

            // A hash of the text, not the text, one row at a time: one member has
            // over 400,000 rows, and their full text alone filled the 1 GB memory limit.
            $rows = DB::table('users_aboutme')
                // keep-raw: the builder has no way to select a SQL function of a column
                ->selectRaw('id, userid, SHA2(text, 256) AS hash')
                ->whereIn('userid', $userids)
                ->orderBy('userid')
                ->orderBy('timestamp')
                ->orderBy('id')
                ->cursor();

            $doomed = [];
            $lastUser = null;
            $lastHash = null;
            foreach ($rows as $row) {
                if ($row->userid === $lastUser && $row->hash === $lastHash) {
                    $doomed[] = $row->id;
                    continue;
                }
                $lastUser = $row->userid;
                $lastHash = $row->hash;
                $kept++;
            }

            $members += count($userids);
            $repeats += count($doomed);

            if ($apply) {
                foreach ($doomed as $i => $id) {
                    DB::table('users_aboutme')->where('id', $id)->delete();
                    if ($i > 0 && $i % 50000 === 0) {
                        $this->line("  {$i} of " . count($doomed) . ' deleted in this batch');
                    }
                    if ($rowPauseMs > 0) {
                        usleep($rowPauseMs * 1000);
                    }
                }
            }

            $cursor = (int) end($userids);
            $this->line("userid {$cursor}: {$members} members, {$kept} kept, {$repeats} repeats");

            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }

        $verb = $apply ? 'Deleted' : 'Would delete';
        $this->info("{$verb} {$repeats} repeated rows across {$members} members; {$kept} rows kept.");

        return self::SUCCESS;
    }
}
