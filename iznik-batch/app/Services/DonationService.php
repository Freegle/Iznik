<?php

namespace App\Services;

use App\Mail\Donation\DonationThankYou;
use App\Mail\Donation\AskForDonation;
use App\Mail\Traits\FeatureFlags;
use App\Models\User;
use App\Models\UserDonation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DonationService
{
    use FeatureFlags;

    public const EMAIL_TYPE_THANK = 'DonationThank';
    public const EMAIL_TYPE_ASK = 'DonationAsk';
    /**
     * Excluded payer patterns (test payments, aggregators like PayPal Giving Fund).
     */
    protected const EXCLUDED_PAYERS = [
        'ppgfukpay@paypalgivingfund.org',
    ];

    /**
     * Days to look back for recent donations.
     */
    public const RECENT_DONATION_DAYS = 7;

    /**
     * Days between donation asks.
     */
    public const ASK_INTERVAL_DAYS = 7;

    /**
     * Send thank you emails to recent donors who haven't been thanked.
     */
    public function thankDonors(bool $dryRun = false): array
    {
        $stats = [
            'processed' => 0,
            'emails_sent' => 0,
            'errors' => 0,
        ];

        // Check if this email type is enabled.
        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE_THANK)) {
            Log::info('DonationThank emails disabled via FREEGLE_MAIL_ENABLED_TYPES');

            return $stats;
        }

        $donors = $this->getUnthankedDonors();

        foreach ($donors as $donor) {
            try {
                $user = User::find($donor->userid);

                if (!$user || !$user->email_preferred) {
                    continue;
                }

                if (!$dryRun) {
                    app(\App\Services\EmailSpoolerService::class)->spool(new DonationThankYou($user));
                    $this->markAsThanked($donor->userid);
                }

                $stats['emails_sent']++;
                $stats['processed']++;
            } catch (\Exception $e) {
                Log::error("Error thanking donor {$donor->userid}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Get donors who haven't been thanked yet.
     */
    protected function getUnthankedDonors(): Collection
    {
        $excludeCondition = $this->getExcludedPayersCondition();

        return DB::table('users_donations')
            ->select('users_donations.userid')
            ->leftJoin('users_thanks', 'users_thanks.userid', '=', 'users_donations.userid')
            ->where('users_donations.timestamp', '>', now()->subDays(self::RECENT_DONATION_DAYS))
            ->whereNotNull('users_donations.userid')
            ->whereNull('users_thanks.userid')
            ->whereRaw($excludeCondition)
            ->distinct()
            ->get();
    }

    /**
     * Mark a user as thanked for their donation.
     */
    protected function markAsThanked(int $userId): void
    {
        DB::table('users_thanks')->insert([
            'userid' => $userId,
        ]);
    }

    /**
     * Get SQL condition to exclude test/internal payers.
     */
    protected function getExcludedPayersCondition(string $field = 'Payer'): string
    {
        $conditions = [];

        foreach (self::EXCLUDED_PAYERS as $payer) {
            $conditions[] = "{$field} != '{$payer}'";
        }

        return '(' . implode(' AND ', $conditions) . ')';
    }

    /**
     * Ask users for donations after they've received items.
     */
    public function askForDonations(bool $dryRun = false): array
    {
        $stats = [
            'processed' => 0,
            'emails_sent' => 0,
            'skipped_recent_ask' => 0,
            'errors' => 0,
        ];

        // Check if this email type is enabled.
        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE_ASK)) {
            Log::info('DonationAsk emails disabled via FREEGLE_MAIL_ENABLED_TYPES');

            return $stats;
        }

        $recipients = $this->getUsersWhoReceivedItems();

        foreach ($recipients as $recipient) {
            try {
                $user = User::find($recipient->userid);

                if (!$user || !$user->email_preferred) {
                    continue;
                }

                // Check if we asked recently.
                $lastAsk = $this->getLastAskDate($recipient->userid);

                if ($lastAsk && $lastAsk->gt(now()->subDays(self::ASK_INTERVAL_DAYS))) {
                    $stats['skipped_recent_ask']++;
                    continue;
                }

                // Get the message they received.
                $recentMessage = $this->getRecentReceivedMessage($recipient->userid);

                if ($recentMessage) {
                    if (!$dryRun) {
                        app(\App\Services\EmailSpoolerService::class)->spool(new AskForDonation($user, $recentMessage->subject));
                        $this->recordAsk($recipient->userid);
                    }
                    $stats['emails_sent']++;
                }

                $stats['processed']++;
            } catch (\Exception $e) {
                Log::error("Error asking donation from {$recipient->userid}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Get users who have received items recently.
     */
    protected function getUsersWhoReceivedItems(): Collection
    {
        $start = now()->subDay()->setTime(17, 0);
        $end = now()->setTime(17, 0);

        return DB::table('messages_by')
            ->select('userid', DB::raw('COUNT(*) as count'))
            ->join('users', 'users.id', '=', 'messages_by.userid')
            ->where('messages_by.timestamp', '>=', $start)
            ->where('messages_by.timestamp', '<', $end)
            ->groupBy('userid')
            ->orderByDesc('count')
            ->get();
    }

    /**
     * Get the last time we asked a user for a donation.
     */
    protected function getLastAskDate(int $userId): ?\Carbon\Carbon
    {
        $lastAsk = DB::table('users_donations_asks')
            ->where('userid', $userId)
            ->max('timestamp');

        return $lastAsk ? \Carbon\Carbon::parse($lastAsk) : null;
    }

    /**
     * Get the most recent message a user received.
     */
    protected function getRecentReceivedMessage(int $userId): ?object
    {
        $mysqlTime = now()->subDays(90);

        return DB::table('messages_by')
            ->select('messages.id as msgid', 'messages.date', 'messages.subject')
            ->join('messages', 'messages.id', '=', 'messages_by.msgid')
            ->join('chat_messages', function ($join) use ($userId) {
                $join->on('chat_messages.refmsgid', '=', 'messages.id')
                    ->where('chat_messages.type', '=', 'Interested')
                    ->where('chat_messages.userid', '=', $userId);
            })
            ->where('messages.type', '=', 'Offer')
            ->where('messages_by.userid', '=', $userId)
            ->where('messages_by.userid', '!=', DB::raw('messages.fromuser'))
            ->where('messages.arrival', '>=', $mysqlTime)
            ->orderByDesc('messages_by.timestamp')
            ->first();
    }

    /**
     * Record that we asked a user for a donation.
     */
    protected function recordAsk(int $userId): void
    {
        DB::table('users_donations_asks')->insert([
            'userid' => $userId,
            'timestamp' => now(),
        ]);
    }

    /**
     * Update the ads-off donation target.
     *
     * Calculates how much more needs to be donated in the current 24-hour window
     * to reach the target that disables ads. Updates the config table.
     *
     * Migrated from iznik-server/scripts/cron/donations_ads_target.php
     */
    public function updateAdsTarget(bool $dryRun = false): array
    {
        $targetMax = DB::table('config')
            ->where('key', 'ads_off_target_max')
            ->value('value');

        if ($targetMax === NULL) {
            Log::warning('ads_off_target_max not found in config table');
            return ['target_max' => 0, 'donated_24h' => 0, 'remaining' => 0, 'ads_enabled' => 1];
        }

        $targetMax = (float) $targetMax;

        $excludeCondition = $this->getExcludedPayersCondition('Payer');

        $donated24h = (float) DB::selectOne("
            SELECT COALESCE(SUM(GrossAmount), 0) AS total
            FROM users_donations
            WHERE TIMESTAMPDIFF(HOUR, users_donations.timestamp, NOW()) <= 24
            AND {$excludeCondition}
        ")->total;

        $remaining = (int) ceil($targetMax - $donated24h);
        if ($remaining < 0) {
            $remaining = 0;
        }

        if (!$dryRun) {
            DB::table('config')->updateOrInsert(
                ['key' => 'ads_off_target'],
                ['value' => $remaining]
            );

            DB::table('config')->updateOrInsert(
                ['key' => 'ads_enabled'],
                ['value' => $remaining > 0 ? 1 : 0]
            );
        }

        return [
            'target_max' => $targetMax,
            'donated_24h' => $donated24h,
            'remaining' => $remaining,
            'ads_enabled' => $remaining > 0 ? 1 : 0,
        ];
    }

    /**
     * Get donation statistics.
     */
    public function getStats(): array
    {
        $thisMonth = now()->startOfMonth();

        $monthlyTotal = UserDonation::where('timestamp', '>=', $thisMonth)
            ->sum('GrossAmount');

        $donorCount = UserDonation::where('timestamp', '>=', $thisMonth)
            ->distinct('userid')
            ->count('userid');

        return [
            'monthly_total' => $monthlyTotal,
            'donor_count' => $donorCount,
            'target' => config('freegle.donation.target', 2500),
        ];
    }
}
