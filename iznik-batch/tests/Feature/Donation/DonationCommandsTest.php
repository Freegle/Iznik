<?php

namespace Tests\Feature\Donation;

use App\Models\User;
use App\Models\UserDonation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DonationCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_thank_donors_command_runs_successfully(): void
    {
        $this->artisan('mail:donations:thank')
            ->assertExitCode(0);
    }

    public function test_thank_donors_command_displays_stats(): void
    {
        $this->artisan('mail:donations:thank')
            ->expectsOutputToContain('Thanking donors')
            ->expectsOutputToContain('Processed:')
            ->expectsOutputToContain('Emails sent:')
            ->assertExitCode(0);
    }

    public function test_thank_donors_with_donation(): void
    {
        $user = $this->createTestUser();

        UserDonation::create([
            'userid' => $user->id,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test Donor',
            'timestamp' => now(),
            'TransactionType' => 'Donation',
            'GrossAmount' => 10.00,
            'source' => 'PayPal',
        ]);

        $this->artisan('mail:donations:thank')
            ->assertExitCode(0);
    }

    public function test_ask_donations_command_runs_successfully(): void
    {
        $this->artisan('mail:donations:ask')
            ->assertExitCode(0);
    }

    public function test_ask_donations_command_displays_table(): void
    {
        // Freeze time to a point where the query window (yesterday 17:00 to today 17:00)
        // won't contain any messages_by rows created by parallel tests.
        // Using a time far in the past ensures no interference.
        $this->travelTo(now()->subYears(5));

        $this->artisan('mail:donations:ask')
            ->expectsOutputToContain('Asking for donations')
            ->expectsTable(
                ['Metric', 'Value'],
                [
                    ['Processed', '0'],
                    ['Emails sent', '0'],
                    ['Skipped (recent ask)', '0'],
                    ['Errors', '0'],
                ]
            )
            ->assertExitCode(0);

        $this->travelBack();
    }
}
