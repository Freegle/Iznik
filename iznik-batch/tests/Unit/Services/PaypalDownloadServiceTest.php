<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\PaypalDownloadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Behaviour of {@see PaypalDownloadService} — the fallback PayPal donation
 * downloader migrated from V1 cron/paypal_download.php. The NVP API is faked;
 * the focus is response parsing, donor matching, exclusions and upsert.
 */
class PaypalDownloadServiceTest extends TestCase
{
    private PaypalDownloadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PaypalDownloadService();

        // Configure credentials and a single-day scan window for determinism.
        config([
            'freegle.paypal.username' => 'api-user',
            'freegle.paypal.password' => 'api-pass',
            'freegle.paypal.signature' => 'api-sig',
            'freegle.paypal.nvp_endpoint' => 'https://nvp.test/nvp',
            'freegle.paypal.download_days' => 1,
        ]);
    }

    /** Build a URL-encoded NVP TransactionSearch response body. */
    private function nvpBody(array $transactions, string $ack = 'Success'): string
    {
        $fields = ['ACK' => $ack];
        foreach ($transactions as $i => $t) {
            $fields["L_TRANSACTIONID{$i}"] = $t['txn'];
            $fields["L_EMAIL{$i}"] = $t['email'] ?? '';
            $fields["L_NAME{$i}"] = $t['name'] ?? '';
            $fields["L_TIMESTAMP{$i}"] = $t['timestamp'] ?? '2024-01-01T12:00:00Z';
            $fields["L_AMT{$i}"] = $t['amount'] ?? '0.00';
        }

        return http_build_query($fields);
    }

    private function fakeNvp(string $body): void
    {
        Http::fake(['nvp.test/*' => Http::response($body, 200)]);
    }

    public function test_skips_when_credentials_missing(): void
    {
        config(['freegle.paypal.username' => '']);

        $result = $this->service->download();

        $this->assertTrue($result['skipped']);
        $this->assertSame(0, $result['upserted']);
    }

    public function test_inserts_matched_donation_and_excludes_ppgf_and_zero(): void
    {
        $user = $this->createTestUser();
        DB::table('users_emails')->insert([
            'userid' => $user->id,
            'email' => 'donor@example.com',
            'canon' => User::canonMail('donor@example.com'),
            'preferred' => 1,
        ]);

        $this->fakeNvp($this->nvpBody([
            ['txn' => 'TXN-MATCH', 'email' => 'donor@example.com', 'name' => 'Real Donor', 'amount' => '12.50'],
            ['txn' => 'TXN-PPGF', 'email' => 'fund@paypal.com', 'name' => 'PayPal Giving Fund UK', 'amount' => '99.00'],
            ['txn' => 'TXN-ZERO', 'email' => 'someone@example.com', 'name' => 'Zero', 'amount' => '0.00'],
        ]));

        $result = $this->service->download();

        $this->assertFalse($result['skipped']);
        // PPGF and zero excluded; only the real donation counts.
        $this->assertSame(1, $result['found']);
        $this->assertSame(1, $result['upserted']);

        $this->assertDatabaseHas('users_donations', [
            'TransactionID' => 'TXN-MATCH',
            'userid' => $user->id,
            'Payer' => 'donor@example.com',
            'PayerDisplayName' => 'Real Donor',
            'GrossAmount' => 12.50,
        ]);
        $this->assertDatabaseMissing('users_donations', ['TransactionID' => 'TXN-PPGF']);
        $this->assertDatabaseMissing('users_donations', ['TransactionID' => 'TXN-ZERO']);
    }

    public function test_records_donation_with_null_userid_when_unmatched(): void
    {
        $this->fakeNvp($this->nvpBody([
            ['txn' => 'TXN-UNKNOWN', 'email' => 'nobody@nowhere.test', 'name' => 'Stranger', 'amount' => '5.00'],
        ]));

        $this->service->download();

        $row = DB::table('users_donations')->where('TransactionID', 'TXN-UNKNOWN')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->userid);
    }

    public function test_upserts_existing_transaction_without_duplicating(): void
    {
        $user = $this->createTestUser();
        DB::table('users_emails')->insert([
            'userid' => $user->id,
            'email' => 'donor@example.com',
            'canon' => User::canonMail('donor@example.com'),
            'preferred' => 1,
        ]);

        // Pre-existing row with no userid (e.g. an IPN insert that didn't match).
        DB::table('users_donations')->insert([
            'TransactionID' => 'TXN-DUP',
            'Payer' => 'donor@example.com',
            'PayerDisplayName' => 'Real Donor',
            'GrossAmount' => 8.00,
            'timestamp' => '2020-01-01 00:00:00',
            'userid' => null,
        ]);

        $this->fakeNvp($this->nvpBody([
            ['txn' => 'TXN-DUP', 'email' => 'donor@example.com', 'name' => 'Real Donor', 'amount' => '8.00'],
        ]));

        $this->service->download();

        // Still exactly one row, now matched to the user.
        $this->assertSame(1, DB::table('users_donations')->where('TransactionID', 'TXN-DUP')->count());
        $this->assertSame(
            $user->id,
            (int) DB::table('users_donations')->where('TransactionID', 'TXN-DUP')->value('userid')
        );
    }
}
