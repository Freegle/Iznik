<?php

namespace App\Console\Commands\User;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UnspamUserCommand extends Command
{
    protected $signature = 'user:unspam
                            {--email= : Email address of the user to unspam (required)}';

    protected $description = 'Remove a user from the spam and banned lists';

    public function handle(): int
    {
        $email = $this->option('email');

        if (!$email) {
            $this->error('--email is required');
            return self::FAILURE;
        }

        $emailRow = DB::table('users_emails')->where('email', $email)->first();
        if (!$emailRow) {
            $this->error("No user found with email: {$email}");
            return self::FAILURE;
        }

        $userId = $emailRow->userid;

        $bannedDeleted = DB::table('users_banned')->where('userid', $userId)->delete();
        $spamDeleted = DB::table('spam_users')->where('userid', $userId)->delete();

        $this->info("Unspammed user #{$userId} ({$email}): removed from users_banned ({$bannedDeleted}), spam_users ({$spamDeleted})");

        return self::SUCCESS;
    }
}
