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

        // users_banned was dropped by 2026_09_20_000001_remove_group_model.php in favour
        // of a single users.banned/bannedby pair (ai-judgement.md) - clear those instead.
        $wasBanned = DB::table('users')->where('id', $userId)->whereNotNull('banned')->exists();
        DB::table('users')->where('id', $userId)->update(['banned' => null, 'bannedby' => null]);
        $spamDeleted = DB::table('spam_users')->where('userid', $userId)->delete();

        $bannedCleared = $wasBanned ? 1 : 0;
        $this->info("Unspammed user #{$userId} ({$email}): cleared ban ({$bannedCleared}), spam_users ({$spamDeleted})");

        return self::SUCCESS;
    }
}
