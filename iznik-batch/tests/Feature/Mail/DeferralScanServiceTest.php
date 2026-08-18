<?php

namespace Tests\Feature\Mail;

use App\Services\Mail\Deferrals\DeferralScanService;
use App\Services\Mail\Deferrals\RelayQueueSnapshot;
use App\Services\Mail\MailSuppressionService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The decision layer: what gets suppressed, what gets released, and what is
 * deliberately left alone.
 */
class DeferralScanServiceTest extends TestCase
{
    private DeferralScanService $scan;

    private MailSuppressionService $suppressions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'freegle.mail.deferrals.mxgroup_min_deferred' => 500,
            'freegle.mail.deferrals.mxgroup_max_delivered_per_hour' => 10,
            'freegle.mail.deferrals.address_min_deferred' => 5,
            'freegle.mail.deferrals.address_min_hours' => 24,
            'freegle.mail.deferrals.release_max_deferred' => 100,
            'freegle.mail.deferrals.release_clear_scans' => 2,
            'freegle.mail.deferrals.stale_after_hours' => 24,
        ]);

        $this->suppressions = app(MailSuppressionService::class);
        $this->suppressions->flushCache();
        $this->scan = new DeferralScanService($this->suppressions);
    }

    private const YAHOO_REASON = 'host mta7.am0.yahoodns.net[67.195.228.94] said: 421 4.7.0 '
        . '[TSS04] Messages from 185.53.57.161 temporarily deferred due to unexpected volume';

    private function snapshotWithYahooBlocked(int $deferred = 52000, ?int $delivered = 1): RelayQueueSnapshot
    {
        $s = new RelayQueueSnapshot;
        $s->groups['yahoodns.net'] = [
            'count' => $deferred,
            'oldest' => strtotime('2026-08-15 16:38:00'),
            'reason' => self::YAHOO_REASON,
            'domains' => ['yahoo.co.uk' => 30000, 'yahoo.com' => 12000, 'sky.com' => 6000, 'aol.com' => 4000],
        ];
        if ($delivered !== null) {
            $s->delivered['l.google.com'] = 5000;
            $s->delivered['yahoodns.net'] = $delivered;
        }

        return $s;
    }

    // ===================================================================
    // Suppression
    // ===================================================================

    public function test_suppresses_a_relay_family_that_has_stopped_taking_our_mail(): void
    {
        $result = $this->scan->apply($this->snapshotWithYahooBlocked());

        $this->assertCount(1, $result['suppressed']);
        $this->assertSame('yahoodns.net', $result['suppressed'][0]['value']);
        $this->assertSame('Yahoo', $result['suppressed'][0]['provider']);

        $this->assertDatabaseHas('mail_suppressions', [
            'scope' => 'mxgroup',
            'value' => 'yahoodns.net',
            'provider' => 'Yahoo',
            'released_at' => null,
        ]);
    }

    public function test_records_the_deferral_start_not_the_moment_we_noticed(): void
    {
        // Moderators are shown "delayed since X". X has to be the start of
        // the member's problem, not the start of our awareness of it.
        $this->scan->apply($this->snapshotWithYahooBlocked());

        $row = DB::table('mail_suppressions')->where('scope', 'mxgroup')->first();

        $this->assertSame('2026-08-15 16:38:00', (string) $row->deferred_since);
    }

    public function test_materialises_a_domain_row_for_every_domain_seen_behind_the_relay(): void
    {
        // Including sky.com, which is Yahoo-hosted and would never be guessed
        // from the domain name.
        $this->scan->apply($this->snapshotWithYahooBlocked());

        $domains = DB::table('mail_suppressions')->where('scope', 'domain')->pluck('value')->all();

        $this->assertEqualsCanonicalizing(
            ['yahoo.co.uk', 'yahoo.com', 'sky.com', 'aol.com'],
            $domains
        );
    }

    public function test_the_gate_closes_for_every_domain_behind_the_relay(): void
    {
        $this->scan->apply($this->snapshotWithYahooBlocked());
        $this->suppressions->flushCache();

        $this->assertTrue($this->suppressions->isSuppressed('someone@sky.com'));
        $this->assertTrue($this->suppressions->isSuppressed('SOMEONE@Yahoo.CO.UK'));
        $this->assertFalse($this->suppressions->isSuppressed('someone@gmail.com'));
    }

    public function test_does_not_suppress_a_relay_that_is_merely_busy(): void
    {
        // A big backlog while deliveries continue is congestion, not a block.
        $s = $this->snapshotWithYahooBlocked(deferred: 52000, delivered: 5000);

        $result = $this->scan->apply($s);

        $this->assertSame([], $result['suppressed']);
        $this->assertDatabaseCount('mail_suppressions', 0);
    }

    public function test_does_not_suppress_a_backlog_below_the_threshold(): void
    {
        $result = $this->scan->apply($this->snapshotWithYahooBlocked(deferred: 100, delivered: 0));

        $this->assertSame([], $result['suppressed']);
    }

    public function test_suppresses_on_queue_depth_alone_but_says_it_was_blind(): void
    {
        // No delivery data means we cannot tell a block from a quiet hour.
        // A backlog this size is still worth acting on, but the reason must
        // record that the decision rested on half the evidence.
        $s = $this->snapshotWithYahooBlocked(deferred: 52000, delivered: null);

        $result = $this->scan->apply($s);

        $this->assertCount(1, $result['suppressed']);
        $this->assertTrue($result['suppressed'][0]['blind']);

        $row = DB::table('mail_suppressions')->where('scope', 'mxgroup')->first();
        $this->assertStringContainsString('no delivery data', (string) $row->reason);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $result = $this->scan->apply($this->snapshotWithYahooBlocked(), dryRun: true);

        $this->assertCount(1, $result['suppressed']);
        $this->assertDatabaseCount('mail_suppressions', 0);
    }

    public function test_does_not_duplicate_an_existing_suppression(): void
    {
        $this->scan->apply($this->snapshotWithYahooBlocked());
        $result = $this->scan->apply($this->snapshotWithYahooBlocked());

        $this->assertSame([], $result['suppressed']);
        $this->assertSame(1, DB::table('mail_suppressions')->where('scope', 'mxgroup')->count());
    }

    public function test_only_one_active_row_can_exist_per_target(): void
    {
        // Enforced in the schema, not just in the scan. A half-finished run
        // leaving two active rows for one provider would be invisible:
        // activeByKey() maps on scope+value, so the second would keep
        // gating mail long after the first was released.
        $this->scan->apply($this->snapshotWithYahooBlocked());

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('mail_suppressions')->insert([
            'scope' => 'mxgroup',
            'value' => 'yahoodns.net',
            'first_seen' => now(),
            'last_seen' => now(),
        ]);
    }

    public function test_released_rows_may_repeat_so_history_survives(): void
    {
        $this->scan->apply($this->snapshotWithYahooBlocked());
        $this->scan->apply($this->recovered());
        $this->scan->apply($this->recovered());

        // Same provider blocks us again a week later.
        $result = $this->scan->apply($this->snapshotWithYahooBlocked());

        $this->assertCount(1, $result['suppressed']);
        $this->assertSame(
            2,
            DB::table('mail_suppressions')->where('scope', 'mxgroup')->count(),
            'the first episode should still be on the record'
        );
    }
    // ===================================================================
    // Per-address tier
    // ===================================================================

    private function snapshotWithFullMailbox(int $count = 9, string $age = '-3 days'): RelayQueueSnapshot
    {
        $s = new RelayQueueSnapshot;
        $s->addresses['full@example.com'] = [
            'count' => $count,
            'oldest' => strtotime($age),
            'reason' => 'host mx.example.com[203.0.113.10] said: 452 4.2.2 The email account '
                . 'that you tried to reach is over quota',
        ];
        $s->delivered['example.com'] = 500;

        return $s;
    }

    public function test_suppresses_a_single_mailbox_that_has_been_full_for_a_while(): void
    {
        $result = $this->scan->apply($this->snapshotWithFullMailbox());

        $this->assertCount(1, $result['suppressed']);
        $this->assertSame('address', $result['suppressed'][0]['scope']);
        $this->assertSame('full@example.com', $result['suppressed'][0]['value']);
    }

    public function test_does_not_suppress_a_mailbox_on_a_short_burst_of_retries(): void
    {
        // Retries within the hour are the relay working normally.
        $result = $this->scan->apply($this->snapshotWithFullMailbox(count: 9, age: '-10 minutes'));

        $this->assertSame([], $result['suppressed']);
    }

    public function test_does_not_add_an_address_row_when_its_provider_is_already_suppressed(): void
    {
        $this->scan->apply($this->snapshotWithYahooBlocked());

        $s = new RelayQueueSnapshot;
        $s->addresses['someone@sky.com'] = [
            'count' => 20,
            'oldest' => strtotime('-3 days'),
            'reason' => self::YAHOO_REASON,
        ];
        $s->groups['yahoodns.net'] = [
            'count' => 52000, 'oldest' => strtotime('-3 days'),
            'reason' => self::YAHOO_REASON, 'domains' => ['sky.com' => 20],
        ];

        $result = $this->scan->apply($s);

        $this->assertSame(
            0,
            DB::table('mail_suppressions')->where('scope', 'address')->count(),
            'an address row under an already-suppressed provider adds nothing but noise'
        );
        $this->assertSame([], $result['suppressed']);
    }

    public function test_a_still_full_mailbox_is_not_released_after_two_scans(): void
    {
        // The relay-sized release threshold (100) is meaningless for one
        // mailbox, which holds single figures. Left as it was, a member
        // whose mailbox was still bouncing us would be released on the
        // second scan - about half an hour - defeating the whole tier.
        $this->scan->apply($this->snapshotWithFullMailbox());
        $this->scan->apply($this->snapshotWithFullMailbox());
        $result = $this->scan->apply($this->snapshotWithFullMailbox());

        $this->assertSame([], $result['released']);
        $this->assertDatabaseHas('mail_suppressions', [
            'scope' => 'address', 'value' => 'full@example.com', 'released_at' => null,
        ]);
    }

    public function test_a_mailbox_is_released_once_it_stops_deferring(): void
    {
        $this->scan->apply($this->snapshotWithFullMailbox());

        $empty = new RelayQueueSnapshot;
        $empty->delivered['example.com'] = 500;

        $this->assertSame([], $this->scan->apply($empty)['released'], 'one clear scan is not enough');

        $result = $this->scan->apply($empty);
        $this->assertCount(1, $result['released']);
        $this->assertSame('full@example.com', $result['released'][0]['value']);
    }

    public function test_a_relapse_between_two_quiet_scans_breaks_the_streak(): void
    {
        // The band between release_max_deferred (100) and
        // mxgroup_min_deferred (500) is the gap: a relapse into it trips
        // neither the suppression refresh nor the release accumulation, so
        // the counter used to sit frozen and the next quiet scan released a
        // provider that had never been quiet twice running.
        $this->scan->apply($this->snapshotWithYahooBlocked());

        $this->scan->apply($this->recovered());

        $relapse = new RelayQueueSnapshot;
        $relapse->groups['yahoodns.net'] = [
            'count' => 300, 'oldest' => strtotime('-2 hours'),
            'reason' => self::YAHOO_REASON, 'domains' => ['yahoo.co.uk' => 300],
        ];
        $relapse->delivered['yahoodns.net'] = 2;
        $this->scan->apply($relapse);

        $result = $this->scan->apply($this->recovered());

        $this->assertSame([], $result['released'], 'the two clear scans have to be consecutive');
    }
    // ===================================================================
    // Release
    // ===================================================================

    private function recovered(): RelayQueueSnapshot
    {
        $s = new RelayQueueSnapshot;
        $s->groups['yahoodns.net'] = [
            'count' => 5, 'oldest' => strtotime('-1 hour'),
            'reason' => self::YAHOO_REASON, 'domains' => ['yahoo.co.uk' => 5],
        ];
        $s->delivered['yahoodns.net'] = 4000;

        return $s;
    }

    public function test_release_needs_two_consecutive_clear_scans(): void
    {
        $this->scan->apply($this->snapshotWithYahooBlocked());

        $first = $this->scan->apply($this->recovered());
        $this->assertSame([], $first['released'], 'one quiet scan can just be a backoff window');
        $this->assertDatabaseHas('mail_suppressions', ['scope' => 'mxgroup', 'released_at' => null]);

        $second = $this->scan->apply($this->recovered());
        $this->assertCount(1, $second['released']);
        $this->assertSame('yahoodns.net', $second['released'][0]['value']);
    }

    public function test_release_cascades_to_the_domain_rows(): void
    {
        $this->scan->apply($this->snapshotWithYahooBlocked());
        $this->scan->apply($this->recovered());
        $this->scan->apply($this->recovered());

        $this->assertSame(
            0,
            DB::table('mail_suppressions')->whereNull('released_at')->count(),
            'a released provider must not leave orphan domain rows gating mail for ever'
        );

        $this->suppressions->flushCache();
        $this->assertFalse($this->suppressions->isSuppressed('someone@sky.com'));
    }

    public function test_a_clear_scan_followed_by_a_bad_one_resets_the_counter(): void
    {
        $this->scan->apply($this->snapshotWithYahooBlocked());
        $this->scan->apply($this->recovered());
        $this->scan->apply($this->snapshotWithYahooBlocked());
        $result = $this->scan->apply($this->recovered());

        $this->assertSame([], $result['released'], 'the clear run has to be consecutive');
    }

    public function test_does_not_release_while_deliveries_have_not_resumed(): void
    {
        $this->scan->apply($this->snapshotWithYahooBlocked());

        // Backlog drained (we purged it) but the provider is still refusing.
        $s = new RelayQueueSnapshot;
        $s->groups['yahoodns.net'] = [
            'count' => 0, 'oldest' => null,
            'reason' => self::YAHOO_REASON, 'domains' => [],
        ];
        $s->delivered['l.google.com'] = 5000;

        $this->scan->apply($s);
        $result = $this->scan->apply($s);

        $this->assertSame([], $result['released']);
    }

    public function test_a_long_gap_does_not_release_a_provider_that_is_still_blocking(): void
    {
        // The probe was down for a day. When it comes back and finds the
        // provider still solidly blocked, that IS confirmation - releasing on
        // the strength of the stale last_seen it is about to overwrite would
        // reopen the floodgates onto a queue that still cannot drain.
        $this->scan->apply($this->snapshotWithYahooBlocked());

        DB::table('mail_suppressions')->update(['last_seen' => now()->subDays(3)]);

        $result = $this->scan->apply($this->snapshotWithYahooBlocked());

        $this->assertSame([], $result['released']);
        $this->assertDatabaseHas('mail_suppressions', ['scope' => 'mxgroup', 'released_at' => null]);
    }

    public function test_fails_open_when_the_probe_has_not_confirmed_a_suppression_for_too_long(): void
    {
        // Quietly not mailing a whole provider for ever, because our own
        // probe broke, is worse than the problem this feature solves.
        $this->scan->apply($this->snapshotWithYahooBlocked());

        DB::table('mail_suppressions')->update(['last_seen' => now()->subDays(3)]);

        $result = $this->scan->apply(new RelayQueueSnapshot);

        $this->assertCount(1, $result['released']);
        $this->assertTrue($result['released'][0]['stale']);
    }
}
