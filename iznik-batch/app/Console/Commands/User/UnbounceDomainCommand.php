<?php

namespace App\Console\Commands\User;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UnbounceDomainCommand extends Command
{
    protected $signature = 'users:unbounce-domain
                            {--domain= : Email domain to unbounce (required, e.g. gmail.com)}';

    protected $description = 'Clear the bouncing flag for all users whose preferred email is on a given domain';

    public function handle(): int
    {
        $domain = $this->option('domain');

        if (!$domain) {
            $this->error('--domain is required');
            return self::FAILURE;
        }

        $emails = DB::table('users_emails')
            ->join('users', 'users_emails.userid', '=', 'users.id')
            ->where('users_emails.email', 'like', '%@' . $domain)
            ->whereNotNull('users_emails.bounced')
            ->whereNull('users.deleted')
            ->select('users_emails.id as email_id', 'users_emails.userid')
            ->get();

        $count = 0;

        foreach ($emails as $row) {
            DB::table('users_emails')->where('id', $row->email_id)->update(['bounced' => null]);
            DB::table('users')->where('id', $row->userid)->update(['bouncing' => 0]);
            $count++;
        }

        $this->info("Unbounced {$count} email(s) on domain: {$domain}");

        return self::SUCCESS;
    }
}
