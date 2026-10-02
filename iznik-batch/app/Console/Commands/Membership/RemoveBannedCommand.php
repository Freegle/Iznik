<?php

namespace App\Console\Commands\Membership;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * membership:remove-banned - a ban means "off this group, and cannot rejoin", so a
 * memberships row for a member banned from that group should not exist at all.
 *
 * Subscribe-by-email had no ban check, so the Subscribe mail TrashNothing sends for each
 * of its members' groups put banned members straight back on the group, with nothing in
 * the modlog to show how (Discourse #10086). IncomingMailService::handleSubscribe now
 * refuses those; this clears up the rows already written, and any older row where a ban
 * left the membership standing.
 *
 * Removes the membership and logs Group/Left "via ban" - the same pair the Ban action in
 * the API writes. Posts already on the group are left alone: banning does not withdraw
 * them, so that stays a moderator decision. Galera-safe: one row per statement. Reports
 * only unless you pass --commit.
 */
class RemoveBannedCommand extends Command
{
    protected $signature = 'membership:remove-banned
                            {--commit : Actually remove; without this it only reports what it would do}
                            {--user= : Restrict to a single user ID}
                            {--limit=100000 : Max banned memberships to process}';

    protected $description = 'Remove memberships held by members banned from that group';

    public function handle(): int
    {
        $dryRun = ! $this->option('commit');
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;
        $limit = (int) $this->option('limit');

        $query = DB::table('users_banned as b')
            ->join('memberships as m', function ($j) {
                $j->on('m.userid', '=', 'b.userid')
                    ->on('m.groupid', '=', 'b.groupid');
            })
            ->select([
                'm.id as membershipid',
                'b.userid',
                'b.groupid',
                'b.byuser',
                'b.date as bandate',
                'm.added',
            ])
            ->orderBy('m.id')
            ->limit($limit);

        if ($userId !== null) {
            $query->where('b.userid', $userId);
        }

        $rows = $query->get();

        $rejoined = 0;
        $removed = 0;

        foreach ($rows as $row) {
            // Joined after the ban = put back by a join path that did not check the ban.
            // Joined before it = a ban that left the membership standing.
            if ($row->added > $row->bandate) {
                $rejoined++;
            }

            if ($dryRun) {
                continue;
            }

            DB::table('memberships')->where('id', $row->membershipid)->delete();

            DB::table('logs')->insert([
                'timestamp' => now(),
                'type' => 'Group',
                'subtype' => 'Left',
                'user' => $row->userid,
                'byuser' => $row->byuser,
                'groupid' => $row->groupid,
                'text' => 'via ban',
            ]);

            $removed++;
        }

        $this->info(sprintf(
            '%s %d membership(s) held by banned members, %d of which were (re)joined after the ban.',
            $dryRun ? 'Would remove' : 'Removed',
            $dryRun ? $rows->count() : $removed,
            $rejoined,
        ));

        if ($dryRun) {
            $this->comment('Dry run - no changes written. Re-run with --commit to apply.');
        }

        return Command::SUCCESS;
    }
}
