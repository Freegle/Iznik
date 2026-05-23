<?php

namespace App\Console\Commands\User;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemoveMembershipCommand extends Command
{
    protected $signature = 'user:remove-membership
                            {--user-id= : ID of the user (required)}
                            {--group-id= : ID of the group (required)}';

    protected $description = 'Remove a user from a group';

    public function handle(): int
    {
        $userId = $this->option('user-id');
        $groupId = $this->option('group-id');

        if (!$userId || !$groupId) {
            $this->error('--user-id and --group-id are required');
            return self::FAILURE;
        }

        $deleted = DB::table('memberships')
            ->where('userid', $userId)
            ->where('groupid', $groupId)
            ->delete();

        if ($deleted) {
            $this->info("Removed user #{$userId} from group #{$groupId}");
        } else {
            $this->info("User #{$userId} is not a member of group #{$groupId} — nothing to do");
        }

        return self::SUCCESS;
    }
}
