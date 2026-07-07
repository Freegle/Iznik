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
    private const TABLES = [
        'memberships'              => ['userid'],
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
        'memberships_history'      => ['userid'],
        'newsfeed'                 => ['userid'],
    ];

    // Tables with composite primary keys (no auto-increment 'id'):
    // 'userid_col' is the user FK, 'other_keys' are the remaining PK columns.
    private const TABLES_COMPOSITE = [
        'users_banned' => ['userid_col' => 'userid', 'other_keys' => ['groupid']],
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

            foreach (self::TABLES_COMPOSITE as $table => $spec) {
                $useridCol = $spec['userid_col'];
                $otherKeys = $spec['other_keys'];
                $selectCols = array_merge([$useridCol], $otherKeys);

                $backupRows = DB::connection('backup_demerge')
                    ->table($table)
                    ->where($useridCol, $backupUserId)
                    ->get($selectCols);

                foreach ($backupRows as $row) {
                    $query = DB::table($table)->where($useridCol, $fromBackupUserId);
                    foreach ($otherKeys as $col) {
                        $query->where($col, $row->$col);
                    }
                    $deleted = $query->delete();

                    if ($deleted) {
                        $keyDesc = implode(',', array_map(fn($c) => "{$c}={$row->$c}", $otherKeys));
                        $this->line("  Deleted {$table}.({$useridCol}={$fromBackupUserId},{$keyDesc})");
                        $totalDeleted++;
                    }
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
