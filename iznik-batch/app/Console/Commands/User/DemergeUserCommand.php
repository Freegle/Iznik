<?php

namespace App\Console\Commands\User;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DemergeUserCommand extends Command
{
    protected $signature = 'user:demerge
                            {--email= : Email of the user to demerge (required)}
                            {--from-email= : Email of the user they were merged into (required)}
                            {--backup-host=localhost : Backup database host}
                            {--backup-port=3309 : Backup database port}
                            {--backup-user=root : Backup database user}
                            {--backup-password= : Backup database password (required)}
                            {--backup-db= : Backup database name (defaults to main DB name)}';

    protected $description = 'Undo a user merge using data from a backup database';

    // Tables with auto-increment 'id': delete backup rows from live by userid + id.
    // memberships/memberships_history were dropped by
    // 2026_09_20_000001_remove_group_model.php (ai-judgement.md) - there is no
    // longer a membership concept for a merge to have carried over, so nothing
    // to demerge for them.
    private const TABLES = [
        'spam_users'               => ['userid', 'byuserid'],
        'users_logins'             => ['userid'],
        'users_emails'             => ['userid'],
        'users_comments'           => ['userid', 'byuserid'],
        'sessions'                 => ['userid'],
        'messages'                 => ['fromuser'],
        'users_push_notifications' => ['userid'],
        'users_notifications'      => ['fromuser', 'touser'],
        'chat_rooms'               => ['user1', 'user2'],
        'chat_roster'              => ['userid'],
        'chat_messages'            => ['userid'],
        'users_searches'           => ['userid'],
        'newsfeed'                 => ['userid'],
    ];

    public function handle(): int
    {
        $email = $this->option('email');
        $fromEmail = $this->option('from-email');
        $backupHost = $this->option('backup-host');
        $backupPort = $this->option('backup-port');
        $backupUser = $this->option('backup-user');
        $backupPassword = $this->option('backup-password');
        $backupDb = $this->option('backup-db') ?: DB::getDatabaseName();

        if (!$email) {
            $this->error('--email is required');
            return self::FAILURE;
        }

        if (!$fromEmail) {
            $this->error('--from-email is required');
            return self::FAILURE;
        }

        if (!$backupHost) {
            $this->error('--backup-host is required');
            return self::FAILURE;
        }

        if ($backupPassword === null) {
            $this->error('--backup-password is required');
            return self::FAILURE;
        }

        $backupConfig = [
            'driver' => 'mysql',
            'host' => $backupHost,
            'port' => (int) $backupPort,
            'database' => $backupDb,
            'username' => $backupUser,
            'password' => $backupPassword,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];

        config(['database.connections.backup_demerge' => $backupConfig]);

        try {
            $backupEmail = DB::connection('backup_demerge')
                ->table('users_emails')
                ->where('email', $email)
                ->first();

            if (!$backupEmail) {
                $this->error("User with email '{$email}' not found on backup DB");
                return self::FAILURE;
            }

            $fromBackupEmail = DB::connection('backup_demerge')
                ->table('users_emails')
                ->where('email', $fromEmail)
                ->first();

            if (!$fromBackupEmail) {
                $this->error("User with email '{$fromEmail}' not found on backup DB");
                return self::FAILURE;
            }

            $backupUserId = $backupEmail->userid;
            $fromBackupUserId = $fromBackupEmail->userid;

            $this->info("Demerging backup user #{$backupUserId} from #{$fromBackupUserId}...");

            $totalDeleted = 0;

            foreach (self::TABLES as $table => $keys) {
                foreach ($keys as $key) {
                    $backupRows = DB::connection('backup_demerge')
                        ->table($table)
                        ->where($key, $backupUserId)
                        ->get(['id']);

                    foreach ($backupRows as $row) {
                        $deleted = DB::table($table)
                            ->where($key, $fromBackupUserId)
                            ->where('id', $row->id)
                            ->delete();

                        if ($deleted) {
                            $this->line("  Deleted {$table}.id={$row->id} ({$key}={$fromBackupUserId})");
                            $totalDeleted++;
                        }
                    }
                }
            }

            // users_banned (one row per group) was dropped by
            // 2026_09_20_000001_remove_group_model.php in favour of a single
            // users.banned/bannedby pair on `users` itself (ai-judgement.md) - there
            // is no child-table row left to delete. Undo it the way
            // UnspamUserCommand does: if live's "into" user currently holds exactly
            // the ban backup shows for the demerge target, the merge carried it
            // over, so clear it. Leave any ban the "into" user already had in its
            // own right alone.
            $backupBan = DB::connection('backup_demerge')
                ->table('users')
                ->where('id', $backupUserId)
                ->first(['banned', 'bannedby']);

            if ($backupBan && $backupBan->banned) {
                $liveBan = DB::table('users')
                    ->where('id', $fromBackupUserId)
                    ->first(['banned', 'bannedby']);

                if ($liveBan
                    && (string) $liveBan->banned === (string) $backupBan->banned
                    && (string) $liveBan->bannedby === (string) $backupBan->bannedby
                ) {
                    DB::table('users')
                        ->where('id', $fromBackupUserId)
                        ->update(['banned' => null, 'bannedby' => null]);

                    $this->line("  Cleared users.banned/bannedby carried onto user #{$fromBackupUserId}");
                    $totalDeleted++;
                }
            }

            $backupUser = DB::connection('backup_demerge')
                ->table('users')
                ->where('id', $backupUserId)
                ->first();

            if ($backupUser && $backupUser->yahooid) {
                DB::table('users')
                    ->where('yahooid', $backupUser->yahooid)
                    ->update(['yahooid' => null]);
            }

            $this->info("Demerge complete. Deleted {$totalDeleted} merged row(s).");
            $this->info("Next step: run 'php artisan user:dump --email={$email} --output=/tmp/demerged.json' on the backup and then 'php artisan user:restore --input=/tmp/demerged.json' on live.");

        } catch (\Exception $e) {
            $this->error("Failed to connect to backup DB: " . $e->getMessage());
            return self::FAILURE;
        } finally {
            DB::purge('backup_demerge');
        }

        return self::SUCCESS;
    }
}
