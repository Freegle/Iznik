<?php

namespace App\Console\Commands\User;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bring users_emails.canon and users_emails.backwards back to one definition.
 *
 * canon is canonMail(email) and backwards is its reverse. That is what V1 wrote at
 * both its insert sites, and canonMail drops the Trash Nothing -gNNNN suffix and the
 * dots in the domain on purpose. Rows written since hold other things: backwards
 * derived from the address rather than the canon, or nothing at all. A prefix search
 * over the column therefore reads a fraction of the table and says nothing about the
 * rest - see .claude/rules/mail-and-data.md.
 *
 * Both columns are recomputed from the address rather than from the stored canon,
 * because the stored canon is itself wrong on some rows, and reversing a wrong canon
 * would only spread it.
 *
 * Measured on production 2026-09-19: 3,127,849 of 4,476,456 rows already agree, so
 * about 1.2M change, plus 140,117 that have no canon yet.
 *
 * Reads nothing it does not write and writes nothing it has not compared, so it is
 * safe to stop and restart: --resume picks up from the last id it finished.
 */
class BackfillEmailCanonCommand extends Command
{
    protected $signature = 'users:backfill-email-canon
                            {--apply : Write the changes. Without this the command only reports what it would do}
                            {--chunk=2000 : Rows to read per batch}
                            {--pause=200 : Milliseconds to wait between batches, to keep replication quiet}
                            {--max=0 : Stop after this many rows examined (0 = no limit)}
                            {--resume : Carry on from where the last run finished}
                            {--from=0 : Start at this users_emails id}';

    protected $description = 'Recompute users_emails.canon and .backwards from the address, in paced batches';

    /** Where the last run finished, so a stopped run can carry on. */
    private const CURSOR_KEY = 'users_emails.canon_backfill_cursor';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunk = max(1, (int) $this->option('chunk'));
        $pauseMs = max(0, (int) $this->option('pause'));
        $max = max(0, (int) $this->option('max'));

        $cursor = (int) $this->option('from');
        if ($this->option('resume')) {
            $cursor = (int) (DB::table('config')->where('key', self::CURSOR_KEY)->value('value') ?? 0);
            $this->line("Resuming from users_emails.id > {$cursor}");
        }

        $highWater = (int) DB::table('users_emails')->max('id');
        $examined = 0;
        $changed = 0;
        $canonFilled = 0;

        $this->line($apply ? 'Applying changes.' : 'Dry run: nothing will be written. Add --apply to write.');

        while ($cursor < $highWater) {
            $rows = DB::table('users_emails')
                ->select('id', 'email', 'canon', 'backwards')
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $cursor = (int) $row->id;
                $examined++;

                $email = trim((string) $row->email);
                if ($email === '') {
                    continue;
                }

                $canon = User::canonMail(strtolower($email));
                $backwards = strrev($canon);

                if ($row->canon === $canon && $row->backwards === $backwards) {
                    continue;
                }

                if ($row->canon === null) {
                    $canonFilled++;
                }
                $changed++;

                if ($apply) {
                    DB::table('users_emails')->where('id', $row->id)->update([
                        'canon' => $canon,
                        'backwards' => $backwards,
                    ]);
                }
            }

            if ($apply) {
                DB::table('config')->updateOrInsert(
                    ['key' => self::CURSOR_KEY],
                    ['value' => (string) $cursor]
                );
            }

            $this->line("  id <= {$cursor}: {$examined} examined, {$changed} to change");

            if ($max > 0 && $examined >= $max) {
                $this->line("Stopping: reached --max={$max}.");
                break;
            }

            // Paced on purpose. This is a few million single-row updates on a cluster
            // where the nodes are coupled by flow control, so it is deliberately slower
            // than it could be.
            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }

        $verb = $apply ? 'changed' : 'would change';
        $this->info("Examined {$examined} rows, {$verb} {$changed} ({$canonFilled} had no canon).");
        Log::info('users:backfill-email-canon finished', [
            'apply' => $apply,
            'examined' => $examined,
            'changed' => $changed,
            'canon_filled' => $canonFilled,
            'cursor' => $cursor,
        ]);

        return Command::SUCCESS;
    }
}
