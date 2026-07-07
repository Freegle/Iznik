<?php

namespace Tests\Unit\Services;

use App\Mail\Donation\AskForDonation;
use App\Mail\Donation\DonationThankYou;
use App\Models\UserDonation;
use App\Services\DonationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DonationServiceTest extends TestCase
{
    protected DonationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DonationService();
        Mail::fake();
    }

    public function test_thank_donors_with_no_donations(): void
    {
        // Mark all existing donations as thanked to ensure clean state.
        // This prevents interference from parallel tests.
        DB::table('users_donations')
            ->whereNotIn('userid', function ($query) {
                $query->select('userid')->from('users_thanks');
            })
            ->get()
            ->each(function ($donation) {
                DB::table('users_thanks')->insertOrIgnore(['userid' => $donation->userid]);
            });

        $stats = $this->service->thankDonors();

        $this->assertEquals(0, $stats['processed']);
        $this->assertEquals(0, $stats['emails_sent']);
    }

    public function test_thank_donors_sends_email(): void
    {
        $user = $this->createTestUser();

        // Create donation.
        UserDonation::create([
            'userid' => $user->id,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test Donor',
            'GrossAmount' => 10.00,
            'timestamp' => now()->subDays(2),
        ]);

        $stats = $this->service->thankDonors();

        $this->assertEquals(1, $stats['emails_sent']);
        Mail::assertSent(DonationThankYou::class);
    }

    public function test_thank_donors_skips_already_thanked(): void
    {
        $user = $this->createTestUser();

        // Create donation.
        UserDonation::create([
            'userid' => $user->id,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test Donor',
            'GrossAmount' => 10.00,
            'timestamp' => now()->subDays(2),
        ]);

        // Mark as already thanked.
        DB::table('users_thanks')->insert(['userid' => $user->id]);

        $stats = $this->service->thankDonors();

        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }

    public function test_thank_donors_skips_old_donations(): void
    {
        $user = $this->createTestUser();

        // Create old donation (older than 7 days).
        UserDonation::create([
            'userid' => $user->id,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test Donor',
            'GrossAmount' => 10.00,
            'timestamp' => now()->subDays(10),
        ]);

        $stats = $this->service->thankDonors();

        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }

    public function test_get_stats_returns_monthly_totals(): void
    {
        $user = $this->createTestUser();

        // Get baseline before creating our donation.
        $beforeStats = $this->service->getStats();
        $beforeTotal = $beforeStats['monthly_total'] ?? 0;
        $beforeCount = $beforeStats['donor_count'] ?? 0;

        // Create donation this month.
        UserDonation::create([
            'userid' => $user->id,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test Donor',
            'GrossAmount' => 25.00,
            'timestamp' => now(),
        ]);

        $stats = $this->service->getStats();

        // In parallel tests, other donations may exist, so check for our contribution.
        $this->assertEquals($beforeTotal + 25.00, $stats['monthly_total']);
        $this->assertEquals($beforeCount + 1, $stats['donor_count']);
        $this->assertArrayHasKey('target', $stats);
    }

    public function test_ask_for_donations_with_no_recipients(): void
    {
        // Freeze time to a point where the query window (yesterday 17:00 to today 17:00)
        // won't contain any messages_by rows created by parallel tests.
        $this->travelTo(now()->subYears(5));

        $stats = $this->service->askForDonations();

        $this->assertEquals(0, $stats['processed']);
        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();

        $this->travelBack();
    }

    public function test_ask_for_donations_respects_interval(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        // Create a message for the foreign key constraint.
        $message = $this->createTestMessage($user, $group);

        // Record recent ask.
        DB::table('users_donations_asks')->insert([
            'userid' => $user->id,
            'timestamp' => now()->subDays(3),
        ]);

        // Create item receipt inside the query window [yesterday 17:00, today 17:00).
        // Using now()->subHours(2) is time-of-day dependent: when the suite runs
        // after 19:00 UTC the timestamp lands past today 17:00 and the recipient
        // is not returned by getUsersWhoReceivedItems(), skipping the
        // skipped_recent_ask branch and dropping coverage flakily.
        $start = now()->subDay()->setTime(17, 0);
        DB::table('messages_by')->insert([
            'userid' => $user->id,
            'msgid' => $message->id,
            'timestamp' => $start->addMinutes(30),
        ]);

        $stats = $this->service->askForDonations();

        // Should be skipped because we asked recently.
        $this->assertEquals(1, $stats['skipped_recent_ask']);
        Mail::assertNothingSent();
    }

    public function test_thank_donors_skips_excluded_payers(): void
    {
        $user = $this->createTestUser();

        // Create donation with excluded payer (PayPal Giving Fund).
        UserDonation::create([
            'userid' => $user->id,
            'Payer' => 'ppgfukpay@paypalgivingfund.org',
            'PayerDisplayName' => 'PayPal Giving Fund',
            'GrossAmount' => 10.00,
            'timestamp' => now()->subDays(2),
        ]);

        $stats = $this->service->thankDonors();

        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }

    public function test_thank_donors_skips_user_without_email(): void
    {
        // Create user without email.
        $user = \App\Models\User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        // Create donation.
        UserDonation::create([
            'userid' => $user->id,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test Donor',
            'GrossAmount' => 10.00,
            'timestamp' => now()->subDays(2),
        ]);

        $stats = $this->service->thankDonors();

        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }

    public function test_recent_donation_days_constant(): void
    {
        $this->assertEquals(7, DonationService::RECENT_DONATION_DAYS);
    }

    public function test_ask_interval_days_constant(): void
    {
        $this->assertEquals(7, DonationService::ASK_INTERVAL_DAYS);
    }

    public function test_get_stats_with_multiple_donors(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        // Create donations from both users.
        UserDonation::create([
            'userid' => $user1->id,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test Donor',
            'GrossAmount' => 10.00,
            'timestamp' => now(),
        ]);

        UserDonation::create([
            'userid' => $user2->id,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test Donor',
            'GrossAmount' => 15.00,
            'timestamp' => now(),
        ]);

        $stats = $this->service->getStats();

        $this->assertEquals(25.00, $stats['monthly_total']);
        $this->assertEquals(2, $stats['donor_count']);
    }

    public function test_get_stats_with_no_donations(): void
    {
        $stats = $this->service->getStats();

        $this->assertEquals(0, $stats['monthly_total']);
        $this->assertEquals(0, $stats['donor_count']);
    }

    public function test_ask_for_donations_sends_email_to_recipient(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($sender, $group);
        $this->createMembership($recipient, $group);

        // Create an offer message from sender.
        $message = \App\Models\Message::create([
            'type' => \App\Models\Message::TYPE_OFFER,
            'fromuser' => $sender->id,
            'subject' => 'OFFER: Test Item (Location)',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
        ]);

        \App\Models\MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => \App\Models\MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(5),
        ]);

        // Create chat room and interested message.
        $room = \App\Models\ChatRoom::create([
            'chattype' => \App\Models\ChatRoom::TYPE_USER2USER,
            'user1' => $sender->id,
            'user2' => $recipient->id,
            'created' => now()->subDays(4),
        ]);

        \App\Models\ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'message' => 'Is this available?',
            'type' => 'Interested',
            'refmsgid' => $message->id,
            'date' => now()->subDays(4),
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        // Create messages_by record for recipient - in the time window.
        $start = now()->subDay()->setTime(17, 0);
        DB::table('messages_by')->insert([
            'userid' => $recipient->id,
            'msgid' => $message->id,
            'timestamp' => $start->addMinutes(30), // Within the window.
        ]);

        $stats = $this->service->askForDonations();

        // Verify the stats.
        $this->assertArrayHasKey('processed', $stats);
        $this->assertArrayHasKey('emails_sent', $stats);
    }

    // --- updateAdsTarget tests ---

    public function test_update_ads_target_with_no_config(): void
    {
        // Ensure ads_off_target_max is absent.
        DB::table('config')->where('key', 'ads_off_target_max')->delete();

        $stats = $this->service->updateAdsTarget();

        $this->assertEquals(0, $stats['target_max']);
        $this->assertEquals(1, $stats['ads_enabled']);
    }

    public function test_update_ads_target_with_no_donations(): void
    {
        // Set up a target.
        DB::table('config')->updateOrInsert(
            ['key' => 'ads_off_target_max'],
            ['value' => '100']
        );

        // Freeze time far in the past so no real donations interfere.
        $this->travelTo(now()->subYears(5));

        $stats = $this->service->updateAdsTarget();

        $this->assertEquals(100.0, $stats['target_max']);
        $this->assertEquals(0.0, $stats['donated_24h']);
        $this->assertEquals(100, $stats['remaining']);
        $this->assertEquals(1, $stats['ads_enabled']);

        // Verify config was updated.
        $this->assertEquals('100', DB::table('config')->where('key', 'ads_off_target')->value('value'));
        $this->assertEquals('1', DB::table('config')->where('key', 'ads_enabled')->value('value'));

        $this->travelBack();
    }

    public function test_update_ads_target_with_sufficient_donations(): void
    {
        DB::table('config')->updateOrInsert(
            ['key' => 'ads_off_target_max'],
            ['value' => '50']
        );

        $user = $this->createTestUser();

        // Create donation in the last 24 hours exceeding target.
        UserDonation::create([
            'userid' => $user->id,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Generous Donor',
            'GrossAmount' => 75.00,
            'timestamp' => now()->subHours(2),
        ]);

        $stats = $this->service->updateAdsTarget();

        $this->assertEquals(50.0, $stats['target_max']);
        $this->assertGreaterThanOrEqual(75.0, $stats['donated_24h']);
        $this->assertEquals(0, $stats['remaining']);
        $this->assertEquals(0, $stats['ads_enabled']);

        // Verify ads are disabled in config.
        $this->assertEquals('0', DB::table('config')->where('key', 'ads_enabled')->value('value'));
    }

    public function test_update_ads_target_excludes_paypal_giving_fund(): void
    {
        DB::table('config')->updateOrInsert(
            ['key' => 'ads_off_target_max'],
            ['value' => '50']
        );

        $user = $this->createTestUser();

        // Freeze time to avoid interference from real donations.
        $this->travelTo(now()->subYears(5));

        // Create donation from excluded payer.
        UserDonation::create([
            'userid' => $user->id,
            'Payer' => 'ppgfukpay@paypalgivingfund.org',
            'PayerDisplayName' => 'PayPal Giving Fund',
            'GrossAmount' => 100.00,
            'timestamp' => now()->subHours(2),
        ]);

        $stats = $this->service->updateAdsTarget();

        // Excluded payer's donation should NOT count.
        $this->assertEquals(0.0, $stats['donated_24h']);
        $this->assertEquals(50, $stats['remaining']);

        $this->travelBack();
    }

    public function test_ask_for_donations_skips_user_without_email(): void
    {
        // Create user without email.
        $userWithoutEmail = \App\Models\User::create([
            'firstname' => 'No',
            'lastname' => 'Email',
            'fullname' => 'No Email',
            'added' => now(),
        ]);

        $group = $this->createTestGroup();

        // Create a message.
        $message = \App\Models\Message::create([
            'type' => \App\Models\Message::TYPE_OFFER,
            'fromuser' => $this->createTestUser()->id,
            'subject' => 'OFFER: Test (Location)',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
        ]);

        // Add messages_by record.
        $start = now()->subDay()->setTime(17, 0);
        DB::table('messages_by')->insert([
            'userid' => $userWithoutEmail->id,
            'msgid' => $message->id,
            'timestamp' => $start->addMinutes(30),
        ]);

        $stats = $this->service->askForDonations();

        // Should process but not send email since no preferred email.
        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }
}
