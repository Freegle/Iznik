<?php

namespace Tests\Feature\LoveJunk;

use App\Services\LoveJunkInvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LoveJunkTnInvoiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function insertMessage(string $sourceheader, string $timestamp): int
    {
        return DB::table('messages')->insertGetId([
            'type' => 'Offer',
            'source' => 'Platform',
            'subject' => 'OFFER: test item (location)',
            'textbody' => 'test',
            'sourceheader' => $sourceheader,
            'date' => $timestamp,
            'arrival' => $timestamp,
        ]);
    }

    private function insertLoveJunkRow(int $msgId, string $timestamp): void
    {
        DB::table('lovejunk')->insert([
            'msgid' => $msgId,
            'timestamp' => $timestamp,
            'success' => 1,
            'status' => 'ok',
        ]);
    }

    public function test_command_runs_with_no_data(): void
    {
        $this->artisan('lovejunk:send-tn-invoice', ['--period' => '2020-01', '--dry-run' => true])
            ->assertExitCode(0);
    }

    public function test_dry_run_does_not_send_email(): void
    {
        $msgId = $this->insertMessage('TN-abc123', '2020-01-15 12:00:00');
        $this->insertLoveJunkRow($msgId, '2020-01-15 12:00:00');

        (new LoveJunkInvoiceService())->sendInvoice('2020-01', true);

        Mail::assertNothingSent();
    }

    public function test_returns_zero_when_no_lovejunk_data(): void
    {
        $result = (new LoveJunkInvoiceService())->sendInvoice('2020-01', true);

        $this->assertSame(0, $result['tn']);
        $this->assertSame(0, $result['fd']);
        $this->assertSame(0.0, $result['tn_amount']);
    }

    public function test_counts_tn_posts_by_sourceheader(): void
    {
        $msgId = $this->insertMessage('TN-abc123', '2020-01-15 12:00:00');
        $this->insertLoveJunkRow($msgId, '2020-01-15 12:00:00');

        $result = (new LoveJunkInvoiceService())->sendInvoice('2020-01', true);

        $this->assertSame(1, $result['tn']);
        $this->assertSame(0, $result['fd']);
    }

    public function test_counts_fd_posts_without_tn_sourceheader(): void
    {
        $msgId = $this->insertMessage('FD-something', '2020-01-15 12:00:00');
        $this->insertLoveJunkRow($msgId, '2020-01-15 12:00:00');

        $result = (new LoveJunkInvoiceService())->sendInvoice('2020-01', true);

        $this->assertSame(0, $result['tn']);
        $this->assertSame(1, $result['fd']);
    }

    public function test_calculates_percentages_correctly(): void
    {
        // 1 TN post, 1 FD post → 50/50
        $tnMsg = $this->insertMessage('TN-abc', '2020-01-10 12:00:00');
        $fdMsg = $this->insertMessage('FD-xyz', '2020-01-11 12:00:00');
        $this->insertLoveJunkRow($tnMsg, '2020-01-10 12:00:00');
        $this->insertLoveJunkRow($fdMsg, '2020-01-11 12:00:00');

        $result = (new LoveJunkInvoiceService())->sendInvoice('2020-01', true);

        $this->assertSame(50, $result['tn_percent']);
        $this->assertSame(50, $result['fd_percent']);
    }

    public function test_calculates_tn_amount_as_share_of_500(): void
    {
        // 1 TN post out of 2 total → 50% of £500 = £250
        $tnMsg = $this->insertMessage('TN-abc', '2020-01-10 12:00:00');
        $fdMsg = $this->insertMessage('FD-xyz', '2020-01-11 12:00:00');
        $this->insertLoveJunkRow($tnMsg, '2020-01-10 12:00:00');
        $this->insertLoveJunkRow($fdMsg, '2020-01-11 12:00:00');

        $result = (new LoveJunkInvoiceService())->sendInvoice('2020-01', true);

        $this->assertEqualsWithDelta(250.0, $result['tn_amount'], 0.01);
    }

    public function test_filters_by_period_start_inclusive(): void
    {
        // Post on Jan 1st should be included (>= start)
        $msgId = $this->insertMessage('TN-abc', '2020-01-01 00:00:00');
        $this->insertLoveJunkRow($msgId, '2020-01-01 00:00:00');

        $result = (new LoveJunkInvoiceService())->sendInvoice('2020-01', true);

        $this->assertSame(1, $result['tn']);
    }

    public function test_filters_out_posts_before_period(): void
    {
        // Post on Dec 31st should not be included in January period
        $msgId = $this->insertMessage('TN-abc', '2019-12-31 23:59:59');
        $this->insertLoveJunkRow($msgId, '2019-12-31 23:59:59');

        $result = (new LoveJunkInvoiceService())->sendInvoice('2020-01', true);

        $this->assertSame(0, $result['tn']);
    }

    public function test_filters_out_posts_at_period_end(): void
    {
        // Post on Feb 1st should not be included in January period (exclusive)
        $msgId = $this->insertMessage('TN-abc', '2020-02-01 00:00:00');
        $this->insertLoveJunkRow($msgId, '2020-02-01 00:00:00');

        $result = (new LoveJunkInvoiceService())->sendInvoice('2020-01', true);

        $this->assertSame(0, $result['tn']);
    }

    public function test_deduplicates_by_subject(): void
    {
        // Same subject → counted as one post
        $msg1 = $this->insertMessage('TN-abc', '2020-01-10 12:00:00');
        $msg2 = DB::table('messages')->insertGetId([
            'type' => 'Offer',
            'source' => 'Platform',
            'subject' => 'OFFER: test item (location)',
            'textbody' => 'test',
            'sourceheader' => 'TN-def',
            'date' => '2020-01-11 12:00:00',
            'arrival' => '2020-01-11 12:00:00',
        ]);
        $this->insertLoveJunkRow($msg1, '2020-01-10 12:00:00');
        $this->insertLoveJunkRow($msg2, '2020-01-11 12:00:00');

        $result = (new LoveJunkInvoiceService())->sendInvoice('2020-01', true);

        // Same subject → deduplicated to 1
        $this->assertSame(1, $result['tn']);
    }
}
