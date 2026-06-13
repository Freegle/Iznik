<?php

namespace Tests\Feature\Donation;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Behaviour of `donations:correct-userids` — the one-shot backfill that re-links
 * unmatched donations using the same logic as the live IPN handlers.
 */
class CorrectDonationUserIdsCommandTest extends TestCase
{
    private function insertUser(array $attrs = []): int
    {
        $row = array_merge([
            'firstname'  => 'Test',
            'lastname'   => 'Donor',
            'fullname'   => 'Test Donor',
            'systemrole' => 'User',
            'added'      => now()->subYears(2),
        ], $attrs);
        DB::table('users')->insert($row);
        return (int) DB::getPdo()->lastInsertId();
    }

    private function insertDonation(array $attrs = []): int
    {
        return (int) DB::table('users_donations')->insertGetId([
            'userid'           => $attrs['userid'] ?? null,
            'GrossAmount'      => $attrs['GrossAmount'] ?? 5.00,
            'TransactionType'  => $attrs['TransactionType'] ?? 'subscr_payment',
            'Payer'            => $attrs['Payer'] ?? 'donor@example.com',
            'PayerDisplayName' => $attrs['PayerDisplayName'] ?? 'Donor',
            'type'             => $attrs['type'] ?? 'PayPal',
            'source'           => $attrs['source'] ?? 'DonateWithPayPal',
            'timestamp'        => $attrs['timestamp'] ?? now(),
        ]);
    }

    public function test_links_unmatched_donation_via_prior_donation_with_valid_user(): void
    {
        $uid   = $this->insertUser(['fullname' => 'Prior Match Donor']);
        $payer = 'prior-paypal-only@external.test'; // not registered on the account

        // Historical matched donation from this Payer.
        $this->insertDonation(['userid' => $uid, 'Payer' => $payer, 'timestamp' => now()->subMonth()]);
        // Unmatched continuation that must be re-linked.
        $newId = $this->insertDonation(['userid' => null, 'Payer' => $payer]);

        $this->artisan('donations:correct-userids')->assertExitCode(0);

        $this->assertEquals($uid, DB::table('users_donations')->where('id', $newId)->value('userid'));
    }

    public function test_does_not_link_when_prior_user_deleted(): void
    {
        $uid   = $this->insertUser(['fullname' => 'Gone Donor', 'deleted' => now()]);
        $payer = 'deleted-paypal-only@external.test';

        $this->insertDonation(['userid' => $uid, 'Payer' => $payer, 'timestamp' => now()->subMonth()]);
        $newId = $this->insertDonation(['userid' => null, 'Payer' => $payer]);

        $this->artisan('donations:correct-userids')->assertExitCode(0);

        $this->assertNull(DB::table('users_donations')->where('id', $newId)->value('userid'));
    }

    public function test_links_by_canon_email(): void
    {
        $uid   = $this->insertUser(['fullname' => 'Canon Donor']);
        $payer = 'jane.canon.donor@gmail.com';
        $canon = User::canonMail($payer); // janecanondonor@gmailcom

        // Registered email differs from the payer (no exact match) but shares the canon.
        DB::table('users_emails')->insert([
            'userid' => $uid,
            'email'  => 'janecanondonor@gmail.com',
            'canon'  => $canon,
        ]);

        $newId = $this->insertDonation(['userid' => null, 'Payer' => $payer]);

        $this->artisan('donations:correct-userids')->assertExitCode(0);

        $this->assertEquals($uid, DB::table('users_donations')->where('id', $newId)->value('userid'));
    }

    public function test_dry_run_makes_no_changes(): void
    {
        $uid   = $this->insertUser(['fullname' => 'Dry Run Donor']);
        $payer = 'dryrun-paypal-only@external.test';

        $this->insertDonation(['userid' => $uid, 'Payer' => $payer, 'timestamp' => now()->subMonth()]);
        $newId = $this->insertDonation(['userid' => null, 'Payer' => $payer]);

        $this->artisan('donations:correct-userids', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull(DB::table('users_donations')->where('id', $newId)->value('userid'));
    }
}
