<?php

namespace Tests\Feature\Donation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DonationSummaryCommandTest extends TestCase
{
    private function insertDonation(array $attributes = []): void
    {
        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, TransactionType, Payer, timestamp)
             VALUES (?, ?, ?, ?, NOW())",
            [
                $attributes['userid'] ?? null,
                $attributes['GrossAmount'] ?? 10.00,
                $attributes['TransactionType'] ?? 'Completed',
                $attributes['Payer'] ?? 'donor@example.com',
            ]
        );
    }

    public function test_smoke_no_donations_today(): void
    {
        Mail::fake();

        $this->artisan('mail:donations:summary')
            ->expectsOutputToContain('No donations today')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_summary_with_donations(): void
    {
        Mail::fake();

        $this->insertDonation(['GrossAmount' => 15.00, 'Payer' => 'alice@example.com']);
        $this->insertDonation(['GrossAmount' => 25.50, 'Payer' => 'bob@example.com']);

        $this->artisan('mail:donations:summary')
            ->expectsOutputToContain('Sent summary for 2 donation(s) totalling £40.50')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_dry_run_does_not_send_email(): void
    {
        Mail::fake();

        $this->insertDonation(['GrossAmount' => 20.00]);

        $this->artisan('mail:donations:summary', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would send summary for 1 donation(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_old_donations(): void
    {
        Mail::fake();

        // Insert donation from yesterday
        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, TransactionType, Payer, timestamp)
             VALUES (NULL, 50.00, 'Completed', 'old@example.com', DATE_SUB(NOW(), INTERVAL 1 DAY))"
        );

        $this->artisan('mail:donations:summary')
            ->expectsOutputToContain('No donations today')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_flags_recurring_donations(): void
    {
        Mail::fake();

        $this->insertDonation([
            'GrossAmount' => 5.00,
            'TransactionType' => 'recurring_payment',
            'Payer' => 'subscriber@example.com',
        ]);

        $this->artisan('mail:donations:summary')
            ->expectsOutputToContain('Sent summary for 1 donation(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_total_is_sum_of_all_donations(): void
    {
        Mail::fake();

        $this->insertDonation(['GrossAmount' => 10.00]);
        $this->insertDonation(['GrossAmount' => 10.00]);
        $this->insertDonation(['GrossAmount' => 10.00]);

        $this->artisan('mail:donations:summary')
            ->expectsOutputToContain('totalling £30.00')
            ->assertExitCode(0);
    }
}
