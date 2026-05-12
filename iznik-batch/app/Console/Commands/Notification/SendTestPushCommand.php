<?php

namespace App\Console\Commands\Notification;

use App\Services\PushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Send a test push notification to a user's registered devices.
 *
 * This command is useful for verifying that push notification subscriptions
 * are working correctly for a user.
 *
 * Usage: php artisan push:test-notification --email=user@example.com --app=mt
 *        php artisan push:test-notification --id=12345 --app=fd
 */
class SendTestPushCommand extends Command
{
    protected $signature = 'push:test-notification
                            {--email= : Email address of the user}
                            {--id= : User ID of the user (alternative to --email)}
                            {--app=mt : App type: fd (Freegle Direct) or mt (ModTools), default: mt}';

    protected $description = 'Send a test push notification to a user';

    public function handle(PushNotificationService $pushService): int
    {
        $email = $this->option('email');
        $idOption = $this->option('id');
        $app = $this->option('app');

        // Validate app option
        if (! in_array($app, ['fd', 'mt'])) {
            $this->error("Invalid --app value: $app. Must be 'fd' or 'mt'");
            return Command::FAILURE;
        }

        // Resolve user by email or id
        if ($idOption) {
            // Find user by ID
            $userId = (int) $idOption;
            $user = DB::table('users')->where('id', $userId)->first();

            if (! $user) {
                $this->error("User record not found for id: $userId");
                return Command::FAILURE;
            }

            // Get their preferred email (for display)
            $userEmailRow = DB::table('users_emails')
                ->where('userid', $userId)
                ->orderByDesc('preferred')
                ->orderBy('id')
                ->first();
            $email = $userEmailRow ? $userEmailRow->email : "(no email on record)";
        } else if ($email) {
            // Find the user by email
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
        } else {
            $this->error('--email or --id is required');
            $this->line('Usage: php artisan push:test-notification --email=user@example.com --app=mt');
            $this->line('       php artisan push:test-notification --id=12345 --app=fd');
            return Command::FAILURE;
        }

        $this->info("Sending test notification to user #$userId ($email)");

        // Determine apptype based on app option
        $apptype = $app === 'mt' ? 'ModTools' : 'User';
        $modtools = ($app === 'mt');

        // Look up push notification subscriptions
        $subscriptions = DB::select(
            "SELECT * FROM users_push_notifications WHERE userid = ? AND apptype = ?",
            [$userId, $apptype]
        );

        if (empty($subscriptions)) {
            $this->error("No push notification subscriptions found for user #$userId with apptype=$apptype");
            return Command::FAILURE;
        }

        // List the subscriptions
        $this->info("Found " . count($subscriptions) . " subscription(s):");
        foreach ($subscriptions as $sub) {
            $truncatedToken = substr($sub->subscription, 0, 20) . '...';
            $this->line("  - Type: {$sub->type}, Token: $truncatedToken");
        }

        // Send the notification
        $this->line('');
        $this->info('Sending notification...');
        $count = $pushService->notify($userId, $modtools);

        if ($count === 0) {
            $this->error("Failed to send notifications (count: $count)");
            return Command::FAILURE;
        }

        $this->info("Successfully sent $count notification(s)");
        return Command::SUCCESS;
    }
}
