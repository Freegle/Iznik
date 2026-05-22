<?php

namespace App\Console\Commands\User;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AddMembershipCommand extends Command
{
    protected $signature = 'user:add-membership
                            {--email= : Email address of the user (required)}
                            {--group= : Short name of the group (required)}
                            {--role=Member : Membership role (Member, Moderator, Owner)}';

    protected $description = 'Add a user to a group by email and group short name';

    public function handle(): int
    {
        $email = $this->option('email');
        $groupName = $this->option('group');
        $role = $this->option('role');

        if (!$email || !$groupName) {
            $this->error('--email and --group are required');
            return self::FAILURE;
        }

        $emailRow = DB::table('users_emails')->where('email', $email)->first();
        if (!$emailRow) {
            $this->error("No user found with email: {$email}");
            return self::FAILURE;
        }

        $group = DB::table('groups')->where('nameshort', $groupName)->first();
        if (!$group) {
            $this->error("No group found with short name: {$groupName}");
            return self::FAILURE;
        }

        $existing = DB::table('memberships')
            ->where('userid', $emailRow->userid)
            ->where('groupid', $group->id)
            ->first();

        if ($existing) {
            $this->info("User #{$emailRow->userid} is already a member of {$groupName}");
            return self::SUCCESS;
        }

        DB::table('memberships')->insert([
            'userid' => $emailRow->userid,
            'groupid' => $group->id,
            'role' => $role,
            'collection' => 'Approved',
            'emailfrequency' => 24,
            'added' => now(),
        ]);

        $this->info("Added user #{$emailRow->userid} to {$groupName} as {$role}");
        return self::SUCCESS;
    }
}
