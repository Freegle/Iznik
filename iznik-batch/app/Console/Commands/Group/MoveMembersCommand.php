<?php

namespace App\Console\Commands\Group;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MoveMembersCommand extends Command
{
    protected $signature = 'group:move-members
                            {--from= : Short name of the source group (required)}
                            {--to= : Short name of the destination group (required)}';

    protected $description = 'Move all members from one group to another';

    public function handle(): int
    {
        $fromName = $this->option('from');
        $toName = $this->option('to');

        if (!$fromName || !$toName) {
            $this->error('--from and --to are required');
            return self::FAILURE;
        }

        $fromGroup = DB::table('groups')->where('nameshort', $fromName)->first();
        if (!$fromGroup) {
            $this->error("Source group not found: {$fromName}");
            return self::FAILURE;
        }

        $toGroup = DB::table('groups')->where('nameshort', $toName)->first();
        if (!$toGroup) {
            $this->error("Destination group not found: {$toName}");
            return self::FAILURE;
        }

        $memberships = DB::table('memberships')->where('groupid', $fromGroup->id)->get();

        $moved = 0;
        $skipped = 0;

        foreach ($memberships as $membership) {
            $alreadyInDest = DB::table('memberships')
                ->where('userid', $membership->userid)
                ->where('groupid', $toGroup->id)
                ->exists();

            if ($alreadyInDest) {
                $skipped++;
                continue;
            }

            DB::table('memberships')
                ->where('userid', $membership->userid)
                ->where('groupid', $fromGroup->id)
                ->update(['groupid' => $toGroup->id]);

            $moved++;
        }

        DB::table('memberships')->where('groupid', $fromGroup->id)->delete();

        $this->info("Moved {$moved} member(s) from {$fromName} to {$toName} ({$skipped} skipped — already in destination)");

        return self::SUCCESS;
    }
}
