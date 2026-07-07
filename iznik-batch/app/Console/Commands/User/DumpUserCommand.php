<?php

namespace App\Console\Commands\User;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dump a user's data to a JSON file for later restoration.
 *
 * Run this on the backup (Yesterday) system, then use user:restore on the
 * target (live) system to apply the data.
 *
 * Usage: php artisan user:dump --email=user@example.com --output=/tmp/user-dump.json
 *        php artisan user:dump --id=12345 --output=/tmp/user-dump.json
 */
class DumpUserCommand extends Command
{
    protected $signature = 'user:dump
                            {--email= : Email address of the user to dump}
                            {--id= : User ID of the user to dump (alternative to --email)}
                            {--output= : Output file path (default: /tmp/user-<id>.json)}';

    protected $description = 'Dump a user\'s data to a JSON file for restoration on another system';

    /**
     * Tables to dump, with the columns that hold the user ID.
     * Mirrors the logic in scripts/cli/user_restore.php.
     */
    private array $tables = [
        'memberships'              => ['userid'],
        'memberships_history'      => ['userid'],
        'spam_users'               => ['userid', 'byuserid'],
        'users_banned'             => ['userid'],
        'users_donations'          => ['userid'],
        'giftaid'                  => ['userid'],
        'microactions'             => ['userid'],
        'ratings'                  => ['rater', 'ratee'],
        'users_logins'             => ['userid'],
        'users_emails'             => ['userid'],
        'users_images'             => ['userid'],
        'users_comments'           => ['userid', 'byuserid'],
        'users_searches'           => ['userid'],
        'users_stories'            => ['userid'],
        'users_aboutme'            => ['userid'],
        'users_replytime'          => ['userid'],
        'users_addresses'          => ['userid'],
        'users_push_notifications' => ['userid'],
        'users_notifications'      => ['fromuser', 'touser'],
        'users_thanks'             => ['userid'],
        'sessions'                 => ['userid'],
        'messages'                 => ['fromuser'],
        'messages_promises'        => ['userid'],
        'messages_reneged'         => ['userid'],
        'messages_by'              => ['userid'],
        'chat_rooms'               => ['user1', 'user2'],
        'chat_roster'              => ['userid'],
        'chat_messages'            => ['userid'],
        'newsfeed'                 => ['userid'],
        'isochrones_users'         => ['userid'],
        'modnotifs'                => ['userid'],
        'teams_members'            => ['userid'],
        'trysts'                   => ['user1', 'user2'],
        'locations_excluded'       => ['userid'],
        'logs'                     => ['user'],
        'logs_sql'                 => ['userid'],
    ];

    public function handle(): int
    {
        $email = $this->option('email');
        $idOption = $this->option('id');

        if (! $email && ! $idOption) {
            $this->error('--email or --id is required');
            $this->line('Usage: php artisan user:dump --email=user@example.com --output=/tmp/dump.json');
            $this->line('       php artisan user:dump --id=12345 --output=/tmp/dump.json');

            return Command::FAILURE;
        }

        if ($idOption) {
            // Find user by ID.
            $userId = (int) $idOption;
            $user = DB::table('users')->where('id', $userId)->first();

            if (! $user) {
                $this->error("User record not found for id: $userId");

                return Command::FAILURE;
            }

            // Get their preferred email (for the dump metadata).
            $userEmailRow = DB::table('users_emails')
                ->where('userid', $userId)
                ->orderByDesc('preferred')
                ->orderBy('id')
                ->first();
            $email = $userEmailRow ? $userEmailRow->email : "(no email on record)";
        } else {
            // Find the user by email.
            $userEmailRow = DB::table('users_emails')->where('email', $email)->first();

            if (! $userEmailRow) {
                $this->error("No user found with email: $email");

                return Command::FAILURE;
            }

            $userId = $userEmailRow->userid;
            $user = DB::table('users')->where('id', $userId)->first();

            if (! $user) {
                $this->error("User record not found for id: $userId");

                return Command::FAILURE;
            }
        }

        $this->info("Dumping user #$userId ($email)");

        $output = $this->option('output') ?? "/tmp/user-{$userId}.json";

        // Collect user attributes (those the restore script copies).
        $dump = [
            'version'       => 1,
            'email'         => $email,
            'dumped_at'     => now()->toIso8601String(),
            'source_userid' => $userId,
            'user'          => [
                'fullname'    => $user->fullname,
                'firstname'   => $user->firstname,
                'lastname'    => $user->lastname,
                'yahooid'     => $user->yahooid,
                'systemrole'  => $user->systemrole,
                'permissions' => $user->permissions,
            ],
            'tables'        => [],
        ];

        // Dump each table.
        foreach ($this->tables as $table => $userIdColumns) {
            $rows = [];
            $query = DB::table($table);

            // Build OR condition for all user-ID columns.
            $query->where(function ($q) use ($userIdColumns, $userId) {
                foreach ($userIdColumns as $col) {
                    $q->orWhere($col, $userId);
                }
            });

            foreach ($query->get() as $row) {
                $rows[] = (array) $row;
            }

            if (count($rows) > 0) {
                $this->line("  $table: ".count($rows).' row(s)');
            }

            $dump['tables'][$table] = $rows;
        }

        // Also dump messages_groups for all messages owned by this user.
        $messageIds = DB::table('messages')
            ->where('fromuser', $userId)
            ->pluck('id')
            ->toArray();

        $messagesGroups = [];
        if (count($messageIds) > 0) {
            foreach (DB::table('messages_groups')->whereIn('msgid', $messageIds)->get() as $row) {
                $messagesGroups[] = (array) $row;
            }
        }
        $dump['tables']['messages_groups'] = $messagesGroups;

        if (count($messagesGroups) > 0) {
            $this->line('  messages_groups: '.count($messagesGroups).' row(s)');
        }

        // Write to file.
        $json = json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($output, $json);

        $this->info("Dump written to: $output");
        $this->line('User ID: '.$userId);
        $this->line('Tables dumped: '.count(array_filter($dump['tables'], fn ($r) => count($r) > 0)));

        return Command::SUCCESS;
    }
}
