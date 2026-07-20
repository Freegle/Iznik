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

    /**
     * The donate/leave/rejoin gap: donations recorded against an account that was
     * later DELETED are stranded there (their userid is non-null, so the original
     * whereNull() scan never saw them). When the same person's email now lives on
     * a new, live account, re-point the stranded donations to it.
     */
    public function test_repoints_donation_stranded_on_deleted_account_via_email(): void
    {
        // Old account, since deleted (person left).
        $deadUid = $this->insertUser(['fullname' => 'Left Donor', 'deleted' => now()->subMonth()]);
        // New live account they rejoined with, holding the same email.
        $liveUid = $this->insertUser(['fullname' => 'Rejoined Donor']);
        $payer   = 'left-and-rejoined@example.test';
        DB::table('users_emails')->insert([
            'userid' => $liveUid,
            'email'  => $payer,
            'canon'  => User::canonMail($payer),
        ]);

        // Donation attached to the now-deleted account while it was still live.
        $strandedId = $this->insertDonation(['userid' => $deadUid, 'Payer' => $payer, 'timestamp' => now()->subYear()]);

        $this->artisan('donations:correct-userids')->assertExitCode(0);

        $this->assertEquals(
            $liveUid,
            DB::table('users_donations')->where('id', $strandedId)->value('userid'),
            'Donation stranded on a deleted account should be re-pointed to the live account owning the email.'
        );
    }

    public function test_repoints_donation_stranded_on_deleted_account_via_canon(): void
    {
        $deadUid = $this->insertUser(['fullname' => 'Left Canon Donor', 'deleted' => now()->subMonth()]);
        $liveUid = $this->insertUser(['fullname' => 'Rejoined Canon Donor']);
        $payer   = 'jane.rejoin.canon@gmail.com';
        DB::table('users_emails')->insert([
            'userid' => $liveUid,
            'email'  => 'janerejoincanon@gmail.com', // differs from payer, shares canon
            'canon'  => User::canonMail($payer),
        ]);

        $strandedId = $this->insertDonation(['userid' => $deadUid, 'Payer' => $payer, 'timestamp' => now()->subYear()]);

        $this->artisan('donations:correct-userids')->assertExitCode(0);

        $this->assertEquals($liveUid, DB::table('users_donations')->where('id', $strandedId)->value('userid'));
    }

    /**
     * Guard rail: donations already on a LIVE account must never be moved. That is
     * the duplicate-live-account case, which is resolved by a human account merge,
     * not by this backfill.
     */
    public function test_does_not_touch_donation_on_a_live_account(): void
    {
        $liveA = $this->insertUser(['fullname' => 'Live A (has the donation)']);
        $liveB = $this->insertUser(['fullname' => 'Live B (now owns the email)']);
        $payer = 'shared-live@example.test';
        DB::table('users_emails')->insert([
            'userid' => $liveB,
            'email'  => $payer,
            'canon'  => User::canonMail($payer),
        ]);

        $donationId = $this->insertDonation(['userid' => $liveA, 'Payer' => $payer, 'timestamp' => now()->subYear()]);

        $this->artisan('donations:correct-userids')->assertExitCode(0);

        $this->assertEquals(
            $liveA,
            DB::table('users_donations')->where('id', $donationId)->value('userid'),
            'A donation on a live account must be left alone (merge case, not a re-point).'
        );
    }

    public function test_deleted_account_repoint_respects_dry_run(): void
    {
        $deadUid = $this->insertUser(['fullname' => 'Dry Left Donor', 'deleted' => now()->subMonth()]);
        $liveUid = $this->insertUser(['fullname' => 'Dry Rejoined Donor']);
        $payer   = 'dryrun-left@example.test';
        DB::table('users_emails')->insert([
            'userid' => $liveUid,
            'email'  => $payer,
            'canon'  => User::canonMail($payer),
        ]);

        $strandedId = $this->insertDonation(['userid' => $deadUid, 'Payer' => $payer, 'timestamp' => now()->subYear()]);

        $this->artisan('donations:correct-userids', ['--dry-run' => true])->assertExitCode(0);

        $this->assertEquals(
            $deadUid,
            DB::table('users_donations')->where('id', $strandedId)->value('userid'),
            'Dry run must not move a stranded donation.'
        );
    }

    /**
     * If no live account owns the payer email (and there is no prior live
     * donation), a donation stranded on a deleted account stays put — we have
     * nowhere confident to move it.
     */
    public function test_leaves_stranded_donation_when_no_live_account_owns_email(): void
    {
        $deadUid = $this->insertUser(['fullname' => 'Orphan Left Donor', 'deleted' => now()->subMonth()]);
        $payer   = 'no-live-owner@example.test';

        $strandedId = $this->insertDonation(['userid' => $deadUid, 'Payer' => $payer, 'timestamp' => now()->subYear()]);

        $this->artisan('donations:correct-userids')->assertExitCode(0);

        $this->assertEquals($deadUid, DB::table('users_donations')->where('id', $strandedId)->value('userid'));
    }
}
