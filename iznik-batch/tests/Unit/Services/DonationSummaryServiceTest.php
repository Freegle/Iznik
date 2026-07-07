<?php

namespace Tests\Unit\Services;

use App\Mail\Donation\DonationSummaryMail;
use App\Models\Group;
use App\Models\Membership;
use App\Services\DonationSummaryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DonationSummaryServiceTest extends TestCase
{
    private DonationSummaryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DonationSummaryService();

        // The service queries users_donations globally (no group/day scoping
        // beyond "today"), so any committed rows left by a previous test
        // method would inflate counts/totals for this test.
        DB::table('users_donations')->whereRaw('DATE(timestamp) = DATE(NOW())')->delete();
    }

    private function insertDonation(array $attributes = []): void
    {
        DB::insert(
            'INSERT INTO users_donations (userid, GrossAmount, TransactionType, Payer, timestamp)
             VALUES (?, ?, ?, ?, ?)',
            [
                $attributes['userid'] ?? null,
                $attributes['GrossAmount'] ?? 10.00,
                $attributes['TransactionType'] ?? 'Completed',
                $attributes['Payer'] ?? 'donor@example.com',
                $attributes['timestamp'] ?? now(),
            ]
        );
    }

    public function test_no_donations_returns_zero_result_and_sends_nothing(): void
    {
        Mail::fake();

        $result = $this->service->sendDailySummary();

        $this->assertSame(['donations' => 0, 'total' => 0.0, 'sent' => false], $result);
        Mail::assertNothingSent();
    }

    public function test_sums_multiple_donations_and_sends_one_email(): void
    {
        Mail::fake();

        $this->insertDonation(['GrossAmount' => 15.00, 'Payer' => 'alice@example.com']);
        $this->insertDonation(['GrossAmount' => 25.50, 'Payer' => 'bob@example.com']);
        $this->insertDonation(['GrossAmount' => 4.49, 'Payer' => 'carol@example.com']);

        $result = $this->service->sendDailySummary();

        $this->assertSame(3, $result['donations']);
        $this->assertEqualsWithDelta(44.99, $result['total'], 0.001);
        $this->assertTrue($result['sent']);

        Mail::assertSentCount(1);
        Mail::assertSent(DonationSummaryMail::class, function ($mail) {
            return $mail->recipientEmail === config('freegle.mail.fundraising_addr')
                && abs($mail->total - 44.99) < 0.001
                && str_contains($mail->htmlContent, 'alice@example.com')
                && str_contains($mail->htmlContent, 'bob@example.com')
                && str_contains($mail->htmlContent, 'carol@example.com');
        });
    }

    public function test_dry_run_reports_totals_but_sends_nothing(): void
    {
        Mail::fake();

        $this->insertDonation(['GrossAmount' => 20.00]);

        $result = $this->service->sendDailySummary(true);

        $this->assertSame(1, $result['donations']);
        $this->assertEqualsWithDelta(20.00, $result['total'], 0.001);
        $this->assertFalse($result['sent']);

        Mail::assertNothingSent();
    }

    public function test_ignores_donations_from_other_days(): void
    {
        Mail::fake();

        $this->insertDonation(['GrossAmount' => 50.00, 'timestamp' => now()->subDay()]);
        $this->insertDonation(['GrossAmount' => 5.00, 'timestamp' => now()->addDay()]);

        $result = $this->service->sendDailySummary();

        $this->assertSame(['donations' => 0, 'total' => 0.0, 'sent' => false], $result);
        Mail::assertNothingSent();
    }

    public function test_flags_recurring_payment_type(): void
    {
        Mail::fake();

        $this->insertDonation(['GrossAmount' => 5.00, 'TransactionType' => 'recurring_payment']);

        $this->service->sendDailySummary();

        Mail::assertSent(DonationSummaryMail::class, function ($mail) {
            return str_contains($mail->htmlContent, 'Recurring');
        });
    }

    public function test_flags_subscr_payment_type_as_recurring(): void
    {
        Mail::fake();

        $this->insertDonation(['GrossAmount' => 5.00, 'TransactionType' => 'subscr_payment']);

        $this->service->sendDailySummary();

        Mail::assertSent(DonationSummaryMail::class, function ($mail) {
            return str_contains($mail->htmlContent, 'Recurring');
        });
    }

    public function test_does_not_flag_a_one_off_completed_payment_as_recurring(): void
    {
        Mail::fake();

        $this->insertDonation(['GrossAmount' => 5.00, 'TransactionType' => 'Completed']);

        $this->service->sendDailySummary();

        Mail::assertSent(DonationSummaryMail::class, function ($mail) {
            return !str_contains($mail->htmlContent, 'Recurring');
        });
    }

    public function test_escapes_html_special_characters_in_payer_name(): void
    {
        Mail::fake();

        $this->insertDonation(['GrossAmount' => 5.00, 'Payer' => '<script>alert("x")</script>']);

        $this->service->sendDailySummary();

        Mail::assertSent(DonationSummaryMail::class, function ($mail) {
            return !str_contains($mail->htmlContent, '<script>')
                && str_contains($mail->htmlContent, '&lt;script&gt;');
        });
    }

    public function test_flags_birthday_for_donor_whose_group_was_founded_on_this_day(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $group = $this->createTestGroup([
            'onmap' => 1,
            'founded' => now()->subYears(3)->format('Y-m-d'),
        ]);
        $this->createMembership($user, $group, ['role' => Membership::ROLE_MEMBER]);

        $this->insertDonation([
            'userid' => $user->id,
            'GrossAmount' => 12.00,
            'TransactionType' => 'Completed',
        ]);

        $this->service->sendDailySummary();

        Mail::assertSent(DonationSummaryMail::class, function ($mail) {
            return str_contains($mail->htmlContent, 'Birthday?');
        });
    }

    public function test_does_not_flag_birthday_when_no_matching_group_anniversary(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $group = $this->createTestGroup([
            'onmap' => 1,
            // Founded six months from now (mod 12) - never matches today/yesterday/two-days-ago.
            'founded' => now()->addMonths(6)->subYears(2)->format('Y-m-d'),
        ]);
        $this->createMembership($user, $group, ['role' => Membership::ROLE_MEMBER]);

        $this->insertDonation([
            'userid' => $user->id,
            'GrossAmount' => 12.00,
            'TransactionType' => 'Completed',
        ]);

        $this->service->sendDailySummary();

        Mail::assertSent(DonationSummaryMail::class, function ($mail) {
            return !str_contains($mail->htmlContent, 'Birthday?');
        });
    }

    public function test_does_not_check_birthday_when_donation_has_no_userid(): void
    {
        Mail::fake();

        // No userid on the donation - donorHasBirthdayGroup should never run,
        // regardless of any birthday groups that may exist in the database.
        $this->insertDonation(['userid' => null, 'GrossAmount' => 8.00]);

        $result = $this->service->sendDailySummary();

        $this->assertSame(1, $result['donations']);
        Mail::assertSent(DonationSummaryMail::class, function ($mail) {
            return !str_contains($mail->htmlContent, 'Birthday?');
        });
    }

    public function test_skips_birthday_check_when_matching_recurring_donation_seen_last_month(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $group = $this->createTestGroup([
            'onmap' => 1,
            'founded' => now()->subYears(4)->format('Y-m-d'),
        ]);
        $this->createMembership($user, $group, ['role' => Membership::ROLE_MEMBER]);

        // A prior recurring donation of the same amount within the last month
        // makes the service skip the (expensive) birthday check entirely, even
        // though this donor genuinely belongs to a group with today's anniversary.
        $this->insertDonation([
            'userid' => $user->id,
            'GrossAmount' => 9.99,
            'TransactionType' => 'recurring_payment',
            'timestamp' => now()->subDays(10),
        ]);
        $this->insertDonation([
            'userid' => $user->id,
            'GrossAmount' => 9.99,
            'TransactionType' => 'recurring_payment',
        ]);

        $this->service->sendDailySummary();

        Mail::assertSent(DonationSummaryMail::class, function ($mail) {
            return !str_contains($mail->htmlContent, 'Birthday?');
        });
    }
}
