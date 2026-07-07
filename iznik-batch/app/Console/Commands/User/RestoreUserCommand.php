<?php

namespace App\Console\Commands\User;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Restore a user from a JSON dump file produced by user:dump.
 *
 * Run this on the target (live) system after dumping from the backup system.
 *
 * Usage: php artisan user:restore --input=/tmp/user-dump.json
 */
class RestoreUserCommand extends Command
{
    protected $signature = 'user:restore
                            {--input= : Path to the JSON dump file (required)}
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Restore a user from a JSON dump file (produced by user:dump)';

    /**
     * Columns that hold user IDs in each table, and need remapping
     * when source_userid != target_userid.
     */
    private array $userIdColumns = [
        'memberships'              => ['userid'],
        'spam_users'               => ['userid', 'byuserid'],
        'users_banned'             => ['userid'],
        'users_donations'          => ['userid'],
        'microactions'             => ['userid'],
        'giftaid'                  => ['userid'],
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
        'logs'                     => ['user'],
        'logs_sql'                 => ['userid'],
        'newsfeed'                 => ['userid'],
    ];

    /**
     * Columns to strip before inserting (auto-generated server-side).
     */
    private array $stripColumns = [
        'users_emails' => ['md5hash'],
    ];

    public function handle(): int
    {
        $inputFile = $this->option('input');
        $dryRun = $this->option('dry-run');

        if (! $inputFile) {
            $this->error('--input is required');
            $this->line('Usage: php artisan user:restore --input=/tmp/user-dump.json');

            return Command::FAILURE;
        }

        if (! file_exists($inputFile)) {
            $this->error("File not found: $inputFile");

            return Command::FAILURE;
        }

        $dump = json_decode(file_get_contents($inputFile), true);

        if (! $dump || ($dump['version'] ?? 0) !== 1) {
            $this->error('Invalid or incompatible dump file (expected version 1)');

            return Command::FAILURE;
        }

        $email = $dump['email'];
        $sourceUserId = $dump['source_userid'];

        if ($dryRun) {
            $this->warn('[DRY RUN] No changes will be made');
        }

        $this->info("Restoring user: $email (source ID: $sourceUserId)");
        $this->line("Dumped at: {$dump['dumped_at']}");

        // Find or create user on target system.
        $targetUserId = $this->findOrCreateUser($email, $dump['user'], $dryRun);

        if (! $targetUserId) {
            return Command::FAILURE;
        }

        $this->info("Target user ID: $targetUserId");

        if ($sourceUserId !== $targetUserId) {
            $this->warn("User IDs differ — source=$sourceUserId, target=$targetUserId. User ID columns will be remapped.");
        }

        // Build chat room ID mapping (needed for chat_messages/chat_roster chatid).
        $chatRoomMap = $this->buildChatRoomMap(
            $dump['tables']['chat_rooms'] ?? [],
            $sourceUserId,
            $targetUserId,
            $dryRun
        );

        // Process all tables in order.
        $tableOrder = [
            'memberships', 'spam_users', 'users_banned', 'users_donations',
            'microactions', 'giftaid', 'users_logins', 'users_emails',
            'users_comments', 'sessions', 'messages',
            'users_push_notifications', 'users_notifications',
            'chat_rooms', 'chat_roster', 'chat_messages',
            'users_searches', 'memberships_history', 'logs', 'logs_sql', 'newsfeed',
        ];

        foreach ($tableOrder as $table) {
            $rows = $dump['tables'][$table] ?? [];

            if (empty($rows)) {
                continue;
            }

            $this->processTable($table, $rows, $sourceUserId, $targetUserId, $chatRoomMap, $dryRun);
        }

        // Restore messages_groups deleted status.
        $this->restoreMessagesGroups(
            $dump['tables']['messages_groups'] ?? [],
            $dryRun
        );

        $this->info('Restore complete.');

        return Command::SUCCESS;
    }

    /**
     * Find the user by email on the target system, or create them.
     * Copies user attributes and clears deleted/forgotten.
     */
    private function findOrCreateUser(string $email, array $userAttrs, bool $dryRun): ?int
    {
        $existing = DB::table('users_emails')->where('email', $email)->first();

        if ($existing) {
            $userId = $existing->userid;
            $this->line("Found existing user #$userId on target system");
        } else {
            $this->line('User not found on target system — creating');

            if ($dryRun) {
                $this->line('[DRY RUN] Would create new user with email: '.$email);

                return -1; // Placeholder for dry run.
            }

            $userId = DB::table('users')->insertGetId([
                'fullname'   => $userAttrs['fullname'],
                'firstname'  => $userAttrs['firstname'],
                'lastname'   => $userAttrs['lastname'],
                'added'      => now(),
                'lastaccess' => now(),
                'systemrole' => $userAttrs['systemrole'] ?? 'User',
            ]);

            DB::table('users_emails')->insert([
                'userid'    => $userId,
                'email'     => $email,
                'preferred' => 1,
                'added'     => now(),
            ]);

            $this->line("Created user #$userId");
        }

        // Apply user attributes from dump.
        $updateAttrs = array_filter([
            'fullname'    => $userAttrs['fullname'],
            'firstname'   => $userAttrs['firstname'],
            'lastname'    => $userAttrs['lastname'],
            'yahooid'     => $userAttrs['yahooid'],
            'systemrole'  => $userAttrs['systemrole'],
            'permissions' => $userAttrs['permissions'],
            'deleted'     => null,
            'forgotten'   => null,
        ], fn ($v) => $v !== null || in_array($v, ['deleted', 'forgotten']));

        // Always clear deleted and forgotten even if null.
        $updateAttrs['deleted'] = null;
        $updateAttrs['forgotten'] = null;

        if ($dryRun) {
            $this->line('[DRY RUN] Would update user attributes and clear deleted/forgotten');
        } else {
            DB::table('users')->where('id', $userId)->update($updateAttrs);
            $this->line('Updated user attributes, cleared deleted/forgotten');
        }

        return $userId;
    }

    /**
     * Build a mapping from source chat room ID to target chat room ID.
     * Chat rooms are identified by their type + groupid + user1 + user2.
     */
    private function buildChatRoomMap(array $chatRooms, int $sourceUserId, int $targetUserId, bool $dryRun): array
    {
        $map = [];

        foreach ($chatRooms as $room) {
            $sourceRoomId = $room['id'];

            // Remap user IDs in room.
            $user1 = ($room['user1'] == $sourceUserId) ? $targetUserId : $room['user1'];
            $user2 = ($room['user2'] == $sourceUserId) ? $targetUserId : $room['user2'];

            // Look for matching room on target.
            $query = DB::table('chat_rooms')
                ->where('chattype', $room['chattype']);

            if ($room['groupid'] ?? null) {
                $query->where('groupid', $room['groupid']);
            }
            if ($user1) {
                $query->where('user1', $user1);
            }
            if ($user2) {
                $query->where('user2', $user2);
            }

            $existing = $query->first();

            if ($existing) {
                $map[$sourceRoomId] = $existing->id;
                $this->line("  chat_rooms: source #$sourceRoomId → target #{$existing->id}");
            } else {
                // Room doesn't exist on target yet — it'll be created when we process chat_rooms.
                // We record a placeholder; it will be updated after insertion.
                $map[$sourceRoomId] = null;
            }
        }

        return $map;
    }

    /**
     * Process one table: insert/update all rows from the dump.
     */
    private function processTable(
        string $table,
        array $rows,
        int $sourceUserId,
        int $targetUserId,
        array &$chatRoomMap,
        bool $dryRun
    ): void {
        $userIdCols = $this->userIdColumns[$table] ?? [];
        $stripCols = $this->stripColumns[$table] ?? [];
        $count = 0;

        foreach ($rows as $row) {
            // Strip auto-generated columns.
            foreach ($stripCols as $col) {
                unset($row[$col]);
            }

            // Remap user IDs.
            foreach ($userIdCols as $col) {
                if (isset($row[$col]) && $row[$col] == $sourceUserId) {
                    $row[$col] = $targetUserId;
                }
            }

            // Remap chatid for chat_messages and chat_roster.
            if (in_array($table, ['chat_messages', 'chat_roster']) && isset($row['chatid'])) {
                $sourceChatId = $row['chatid'];

                if (array_key_exists($sourceChatId, $chatRoomMap)) {
                    if ($chatRoomMap[$sourceChatId] === null) {
                        // Room was not found at map-build time; try again now.
                        $existing = DB::table('chat_rooms')->where('id', $sourceChatId)->first();
                        $chatRoomMap[$sourceChatId] = $existing ? $existing->id : $sourceChatId;
                    }
                    $row['chatid'] = $chatRoomMap[$sourceChatId] ?? $sourceChatId;
                }
            }

            if ($dryRun) {
                $count++;

                continue;
            }

            $this->upsertRow($table, $row, $userIdCols);
            $count++;

            // After inserting a chat_room, update the map with the real target ID.
            if ($table === 'chat_rooms') {
                $sourceRoomId = $row['id'];

                if (($chatRoomMap[$sourceRoomId] ?? null) === null) {
                    $chatRoomMap[$sourceRoomId] = DB::getPdo()->lastInsertId() ?: $sourceRoomId;
                }
            }
        }

        $prefix = $dryRun ? '[DRY RUN] Would process' : 'Processed';
        $this->line("  $table: $prefix $count row(s)");
    }

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE only the user-ID columns.
     *
     * Mirrors V1 user_restore.php: on conflict, remap the user ID column(s)
     * but do NOT overwrite all other data already on the target system.
     *
     * @param  string[]  $userIdCols  Columns holding user IDs (e.g. ['userid'])
     */
    private function upsertRow(string $table, array $row, array $userIdCols): void
    {
        if (empty($row)) {
            return;
        }

        $columns = array_keys($row);
        $quotedCols = array_map(fn ($c) => "`$c`", $columns);
        $placeholders = array_fill(0, count($columns), '?');

        // On duplicate, only remap user-ID columns (and keep id stable via LAST_INSERT_ID).
        // This matches V1: "$key = $luid, id = LAST_INSERT_ID(id)".
        $updateClauses = ['`id` = LAST_INSERT_ID(`id`)'];
        foreach ($userIdCols as $col) {
            $updateClauses[] = "`$col` = VALUES(`$col`)";
        }

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $table,
            implode(', ', $quotedCols),
            implode(', ', $placeholders),
            implode(', ', $updateClauses)
        );

        try {
            DB::statement($sql, array_values($row));
        } catch (\Exception $e) {
            $this->warn("  Warning: skipped row in $table: ".$e->getMessage());
            Log::warning('user:restore upsert failed', ['table' => $table, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Restore the deleted/arrival status of messages_groups entries.
     * Mirrors the "undelete messages" section of user_restore.php.
     */
    private function restoreMessagesGroups(array $rows, bool $dryRun): void
    {
        if (empty($rows)) {
            return;
        }

        $count = 0;

        foreach ($rows as $row) {
            if ($dryRun) {
                $count++;

                continue;
            }

            DB::table('messages_groups')
                ->where('msgid', $row['msgid'])
                ->where('groupid', $row['groupid'])
                ->update([
                    'deleted' => $row['deleted'],
                    'arrival' => $row['arrival'],
                ]);

            $count++;
        }

        $prefix = $dryRun ? '[DRY RUN] Would restore' : 'Restored';
        $this->line("  messages_groups: $prefix $count row(s)");
    }
}
