<?php

namespace Tests\Feature\Donation;

use App\Mail\Donation\DonationThankPrepMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Behaviour of `mail:donations:thank-prep` — the daily rich-card digest for
 * the person composing thank-you replies. Distinct from the hourly status
 * mail tested in {@see DonationSummaryCommandTest}; the two have no shared
 * filtering or templating logic, by design.
 */
class DonationThankPrepCommandTest extends TestCase
{
    private function insertDonation(array $attributes = []): int
    {
        return (int) DB::table('users_donations')->insertGetId([
            'userid'           => $attributes['userid']           ?? null,
            'GrossAmount'      => $attributes['GrossAmount']      ?? 10.00,
            'TransactionType'  => $attributes['TransactionType']  ?? 'Completed',
            'Payer'            => $attributes['Payer']            ?? 'donor@example.com',
            'PayerDisplayName' => $attributes['PayerDisplayName'] ?? ($attributes['Payer'] ?? 'donor@example.com'),
            'type'             => $attributes['type']             ?? 'PayPal',
            'source'           => $attributes['source']           ?? 'DonateWithPayPal',
            'giftaidconsent'   => $attributes['giftaidconsent']   ?? 0,
            'thanked'          => $attributes['thanked']          ?? null,
            'timestamp'        => $attributes['timestamp']        ?? now(),
        ]);
    }

    /**
     * Seed the high-water mark so the test isn't gated by the lazy first-run
     * init (which would mark MAX(id) and skip everything we just inserted).
     * Tests that want a clean slate call this with 0 before inserting.
     */
    private function setLastSentId(int $id): void
    {
        DB::statement(
            "INSERT INTO config (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?",
            ['donation_thank_prep_last_id', (string) $id, (string) $id]
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Tests assert on a known empty state. Each test starts at id=0 so
        // the digest will pick up freshly inserted donations.
        $this->setLastSentId(0);
    }

    private function insertUser(array $attributes = []): int
    {
        $id = $attributes['id'] ?? null;
        $row = array_merge([
            'firstname'  => 'Test',
            'lastname'   => 'Donor',
            'fullname'   => 'Test Donor',
            'systemrole' => 'User',
            'added'      => now()->subYears(2),
        ], $attributes);
        if ($id !== null) {
            $row['id'] = $id;
        }
        DB::table('users')->insert($row);
        return $id ?? (int) DB::getPdo()->lastInsertId();
    }

    public function test_no_email_when_no_new_donations_since_last_mark(): void
    {
        Mail::fake();

        // Insert a donation then advance the high-water mark past it so
        // nothing is "new" by the time we run.
        $id = $this->insertDonation(['GrossAmount' => 12.00]);
        $this->setLastSentId($id);

        $this->artisan('mail:donations:thank-prep')
            ->expectsOutputToContain('No donations need thanks today')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_digest_for_new_donations_only(): void
    {
        Mail::fake();

        $this->insertDonation(['GrossAmount' => 15.00, 'Payer' => 'alice@example.com']);
        $this->insertDonation(['GrossAmount' => 25.50, 'Payer' => 'bob@example.com']);

        $this->artisan('mail:donations:thank-prep')
            ->expectsOutputToContain('Sent thank-prep digest for 2 donor(s) needing thanks, totalling £40.50')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
        Mail::assertSent(DonationThankPrepMail::class, function (DonationThankPrepMail $mail) {
            return $mail->envelope()->subject === 'Donations needing thanks: 2 donors, £40.50'
                && count($mail->cards) === 2;
        });
    }

    public function test_dry_run_does_not_send(): void
    {
        Mail::fake();
        $this->insertDonation(['GrossAmount' => 20.00]);

        $this->artisan('mail:donations:thank-prep', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would send thank-prep digest for 1 donor(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_high_water_mark_prevents_repeats(): void
    {
        Mail::fake();

        // First batch.
        $this->insertDonation(['GrossAmount' => 10.00, 'Payer' => 'first@example.com']);
        $this->insertDonation(['GrossAmount' => 20.00, 'Payer' => 'second@example.com']);

        $this->artisan('mail:donations:thank-prep')->assertExitCode(0);
        Mail::assertSentCount(1);

        // Second batch arrives after the digest ran.
        $this->insertDonation(['GrossAmount' => 30.00, 'Payer' => 'third@example.com']);

        $this->artisan('mail:donations:thank-prep')->assertExitCode(0);

        // Two mails total, and the second one only carries the new donation.
        Mail::assertSentCount(2);
        $second = Mail::sent(DonationThankPrepMail::class)->last();
        $this->assertNotNull($second);
        $this->assertCount(1, $second->cards);
        $this->assertSame('third@example.com', $second->cards[0]['donation']['payer']);
    }

    public function test_first_run_with_unseeded_mark_initialises_to_max_id(): void
    {
        Mail::fake();

        // Wipe the seeded mark so the service hits its lazy init path.
        DB::table('config')->where('key', 'donation_thank_prep_last_id')->delete();

        // Existing donations represent the "historical backlog" the service
        // must NOT dump on first run.
        $a = $this->insertDonation(['GrossAmount' => 5.00]);
        $b = $this->insertDonation(['GrossAmount' => 5.00]);

        $this->artisan('mail:donations:thank-prep')
            ->expectsOutputToContain('No donations need thanks today')
            ->assertExitCode(0);

        Mail::assertNothingSent();

        // Mark should have been initialised to MAX(id) so the next genuinely
        // new donation triggers an email.
        $row = DB::table('config')->where('key', 'donation_thank_prep_last_id')->first();
        $this->assertNotNull($row);
        $this->assertSame((string) $b, $row->value);

        $this->insertDonation(['GrossAmount' => 7.00, 'Payer' => 'after@example.com']);
        $this->artisan('mail:donations:thank-prep')->assertExitCode(0);
        Mail::assertSentCount(1);
    }

    public function test_dry_run_on_fresh_deploy_does_not_persist_mark(): void
    {
        Mail::fake();

        // Fresh deploy: no high-water mark yet.
        DB::table('config')->where('key', 'donation_thank_prep_last_id')->delete();
        $this->insertDonation(['GrossAmount' => 9.00]);

        $this->artisan('mail:donations:thank-prep', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        Mail::assertNothingSent();

        // A dry run must have NO side effects: the lazy first-run init must not
        // persist the mark, so the row is still absent and a later real run can
        // initialise it normally.
        $row = DB::table('config')->where('key', 'donation_thank_prep_last_id')->first();
        $this->assertNull($row, 'dry run must not create the high-water-mark config row');
    }

    public function test_unmatched_donor_flagged_for_sleuthing(): void
    {
        Mail::fake();

        $this->insertDonation([
            'GrossAmount' => 50.00,
            'Payer'       => 'JONES MR P',
            'source'      => 'BankTransfer',
            'type'        => 'External',
        ]);

        $this->artisan('mail:donations:thank-prep')->assertExitCode(0);

        Mail::assertSent(DonationThankPrepMail::class, function (DonationThankPrepMail $mail) {
            $card = $mail->cards[0];
            return $card['user'] === null
                && in_array('Unmatched donor — needs sleuthing', $card['flags'], true)
                && $card['donation']['source'] === 'Bank transfer';
        });
    }

    public function test_enriches_matched_donor_with_user_giftaid_and_history(): void
    {
        Mail::fake();

        $userId = $this->insertUser([
            'firstname' => 'Elizabeth',
            'lastname'  => 'Hibbert-Jones',
            'fullname'  => 'Elizabeth Hibbert-Jones',
            'added'     => now()->subYears(3),
        ]);

        DB::table('users_emails')->insert([
            ['userid' => $userId, 'email' => 'elizabeth@example.com',                      'preferred' => 1],
            ['userid' => $userId, 'email' => 'liz.hj@example.com',                         'preferred' => 0],
            // Synthetic chat-only address — must be filtered out.
            ['userid' => $userId, 'email' => "elizabeth-{$userId}@users.ilovefreegle.org", 'preferred' => 0],
        ]);

        DB::table('giftaid')->insert([
            'userid'            => $userId,
            'period'            => 'Past4YearsAndFuture',
            'fullname'          => 'Elizabeth Hibbert-Jones',
            'firstname'         => 'Elizabeth',
            'lastname'          => 'Hibbert-Jones',
            'homeaddress'       => '14 Acacia Avenue, NR1 1JW',
            'postcode'          => 'NR1 1JW',
            'housenameornumber' => '14',
            'reviewed'          => now()->subMonths(2),
        ]);

        // Historical donation (last month). We advance the high-water mark
        // past it so it surfaces in the donor's history list within today's
        // card, NOT as a standalone card in the digest.
        $oldId = $this->insertDonation([
            'userid'          => $userId,
            'GrossAmount'     => 10.00,
            'Payer'           => 'elizabeth@example.com',
            'TransactionType' => 'recurring_payment',
            'timestamp'       => now()->subMonth(),
            'giftaidconsent'  => 1,
        ]);
        $this->setLastSentId($oldId);

        // Today's new donation.
        $this->insertDonation([
            'userid'          => $userId,
            'GrossAmount'     => 10.00,
            'Payer'           => 'elizabeth@example.com',
            'TransactionType' => 'recurring_payment',
            'giftaidconsent'  => 1,
        ]);

        $this->artisan('mail:donations:thank-prep')->assertExitCode(0);

        Mail::assertSent(DonationThankPrepMail::class, function (DonationThankPrepMail $mail) use ($userId) {
            $card = $mail->cards[0];
            return $card['user'] !== null
                && $card['user']['id'] === $userId
                && $card['user']['displayName'] === 'Elizabeth Hibbert-Jones'
                && count($card['aliases']) === 1
                && $card['aliases'][0] === 'elizabeth@example.com'
                && $card['giftaid'] !== null
                && $card['giftaid']['period'] === 'Past4YearsAndFuture'
                && $card['giftaid']['postcode'] === 'NR1 1JW'
                && $card['giftaid']['declined'] === false
                && count($card['donationHistory']) === 1
                && (float) $card['donationHistory'][0]['amount'] === 10.0
                && in_array('Recurring', $card['flags'], true);
        });
    }

    public function test_pgf_donation_flagged_to_avoid_double_claim(): void
    {
        Mail::fake();

        $this->insertDonation([
            'GrossAmount' => 5.00,
            'Payer'       => 'anon@paypal.example',
            'source'      => 'PayPalGivingFund',
            'type'        => 'External',
        ]);

        $this->artisan('mail:donations:thank-prep')->assertExitCode(0);

        Mail::assertSent(DonationThankPrepMail::class, function (DonationThankPrepMail $mail) {
            return in_array('PGF — Gift Aid claimed by PayPal', $mail->cards[0]['flags'], true);
        });
    }

    public function test_declined_giftaid_distinguished_from_no_row(): void
    {
        Mail::fake();

        $userA = $this->insertUser(['firstname' => 'No', 'lastname' => 'GA', 'fullname' => 'No GA']);
        $this->insertDonation([
            'userid' => $userA, 'GrossAmount' => 10.00,
            'Payer'  => 'noga@example.com', 'PayerDisplayName' => 'No GA',
        ]);

        $userB = $this->insertUser(['firstname' => 'Said', 'lastname' => 'No', 'fullname' => 'Said No']);
        DB::table('giftaid')->insert([
            'userid'      => $userB,
            'period'      => 'Declined',
            'fullname'    => 'Said No',
            'homeaddress' => '',
            'reviewed'    => now()->subYear(),
        ]);
        $this->insertDonation([
            'userid' => $userB, 'GrossAmount' => 20.00,
            'Payer'  => 'declined@example.com', 'PayerDisplayName' => 'Said No',
        ]);

        $this->artisan('mail:donations:thank-prep')->assertExitCode(0);

        Mail::assertSent(DonationThankPrepMail::class, function (DonationThankPrepMail $mail) use ($userA, $userB) {
            $byId = [];
            foreach ($mail->cards as $card) {
                if ($card['user'] !== null) {
                    $byId[$card['user']['id']] = $card;
                }
            }
            $a = $byId[$userA] ?? null;
            $b = $byId[$userB] ?? null;
            return $a !== null && $a['giftaid'] === null
                && $b !== null && $b['giftaid'] !== null
                && $b['giftaid']['declined'] === true
                && $b['giftaid']['period'] === 'Declined';
        });
    }

    public function test_clean_snippet_decodes_legacy_emoji_and_html_entities(): void
    {
        Mail::fake();

        $userId = $this->insertUser(['firstname' => 'Chat', 'lastname' => 'Donor']);
        $this->insertDonation(['userid' => $userId, 'GrossAmount' => 5.00, 'Payer' => 'chat@example.com']);

        // Mod note with HTML entity + legacy emoji escape (stored as
        // \\u<hex>\\u per EmojiUtils). Must round-trip cleanly.
        DB::table('users_comments')->insert([
            'userid'   => $userId,
            'byuserid' => null,
            'date'     => now()->subWeek(),
            'user1'    => null,
            'user2'    => 'Tom &amp; Mary said thanks \\\\u1f600\\\\u — lovely chat.',
            'flag'     => 0,
        ]);

        $this->artisan('mail:donations:thank-prep')->assertExitCode(0);

        Mail::assertSent(DonationThankPrepMail::class, function (DonationThankPrepMail $mail) {
            $note = $mail->cards[0]['modNotes'][0] ?? null;
            if ($note === null) {
                return false;
            }
            $snip = $note['snippet'];
            return str_contains($snip, 'Tom & Mary')
                && !str_contains($snip, '&amp;')
                && str_contains($snip, "\u{1F600}")
                && !str_contains($snip, 'u1f600');
        });
    }
}
