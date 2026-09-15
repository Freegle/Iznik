<?php

namespace Tests\Feature\Mail;

use App\Services\Mail\Deferrals\RelayQueueRecorder;
use App\Services\Mail\Deferrals\RelayQueueSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the delayed view is allowed to claim about the queue.
 *
 * The bug being guarded here is not a crash - it is a page that said
 * "every provider is accepting our mail" while thousands of messages sat
 * hours deep behind our own rate limiting. So these tests are mostly about
 * what gets KEPT and what gets thrown away, because a threshold set wrong in
 * either direction reproduces the original failure: too high and the backlog
 * is invisible again, too low and it is buried in noise.
 */
class RelayQueueRecorderTest extends TestCase
{
    private function recorder(): RelayQueueRecorder
    {
        config([
            'freegle.mail.relay_queue.min_queued' => 25,
            'freegle.mail.relay_queue.min_age_minutes' => 120,
            'freegle.mail.relay_queue.max_rows' => 500,
        ]);

        return app(RelayQueueRecorder::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('mail_relay_queue')->delete();
    }

    private function snapshotWithWaiting(string $domain, int $count, int $ageMinutes, ?string $instance = null): RelayQueueSnapshot
    {
        $snapshot = new RelayQueueSnapshot;
        $arrival = Carbon::now()->subMinutes($ageMinutes)->getTimestamp();

        for ($i = 0; $i < $count; $i++) {
            $snapshot->addWaiting("member{$i}@{$domain}", $arrival, $instance);
        }

        return $snapshot;
    }

    /**
     * The headline case: mail nothing has refused is recorded, with the depth
     * and the drain rate beside each other. Depth alone cannot be acted on -
     * 3,000 queued is a normal evening at 3,000/hour and an outage at 60.
     */
    public function test_it_records_mail_waiting_on_our_own_pacing(): void
    {
        $snapshot = $this->snapshotWithWaiting('yahoo.com', 40, 300, '/etc/postfix-warm');
        for ($i = 0; $i < 1500; $i++) {
            $snapshot->addDomainDelivery('yahoo.com');
        }

        $totals = $this->recorder()->record($snapshot);

        $this->assertSame(1, $totals['rows']);
        $this->assertSame(40, $totals['waiting']);

        $row = DB::table('mail_relay_queue')->where('domain', 'yahoo.com')->first();
        $this->assertNotNull($row);
        $this->assertEquals(40, $row->waiting);
        $this->assertEquals(0, $row->deferred, 'nothing refused it');
        $this->assertEquals(1500, $row->deliveredperhour);
        $this->assertSame('/etc/postfix-warm', $row->instance);
        $this->assertNotNull($row->oldest);
    }

    /**
     * Depth is one way in. A handful of messages stuck for a day would never
     * clear a depth threshold, and is exactly the shape nobody notices.
     */
    public function test_a_small_but_old_queue_still_qualifies(): void
    {
        $this->recorder()->record($this->snapshotWithWaiting('slowprovider.com', 3, 600));

        $this->assertDatabaseHas('mail_relay_queue', ['domain' => 'slowprovider.com', 'waiting' => 3]);
    }

    /**
     * And the other way in. A sending address on a rate delay always has
     * SOMETHING queued - that is what pacing IS - so a view that showed every
     * one of them would be permanently alarming and therefore ignored.
     */
    public function test_a_small_and_recent_queue_is_not_worth_showing(): void
    {
        $this->recorder()->record($this->snapshotWithWaiting('busy.com', 3, 5));

        $this->assertDatabaseMissing('mail_relay_queue', ['domain' => 'busy.com']);
    }

    /**
     * A big queue qualifies immediately, before it has had time to get old.
     * Waiting two hours to say a backlog exists would mean the view is only
     * ever right about yesterday.
     */
    public function test_a_large_and_recent_queue_qualifies_on_depth_alone(): void
    {
        $this->recorder()->record($this->snapshotWithWaiting('sudden.com', 500, 1));

        $this->assertDatabaseHas('mail_relay_queue', ['domain' => 'sudden.com', 'waiting' => 500]);
    }

    /**
     * It is a snapshot, not a history. A domain that has cleared loses its
     * row rather than being left at zero, because a stale row reading
     * "0 waiting since Tuesday" is worse than no row: someone has to work out
     * that it is not current.
     */
    public function test_a_domain_that_has_cleared_loses_its_row(): void
    {
        $recorder = $this->recorder();
        $recorder->record($this->snapshotWithWaiting('yahoo.com', 40, 300));
        $this->assertDatabaseHas('mail_relay_queue', ['domain' => 'yahoo.com']);

        $recorder->record($this->snapshotWithWaiting('gmail.com', 40, 300));

        $this->assertDatabaseMissing('mail_relay_queue', ['domain' => 'yahoo.com']);
        $this->assertDatabaseHas('mail_relay_queue', ['domain' => 'gmail.com']);
    }

    /**
     * An empty snapshot empties the table. Otherwise the last bad day's rows
     * would sit there for ever looking like today's.
     */
    public function test_an_empty_snapshot_clears_the_table(): void
    {
        $recorder = $this->recorder();
        $recorder->record($this->snapshotWithWaiting('yahoo.com', 40, 300));

        $recorder->record(new RelayQueueSnapshot);

        $this->assertSame(0, DB::table('mail_relay_queue')->count());
    }

    /**
     * Refusals reach the same row, so one line tells the whole story of a
     * domain instead of making the reader join two tables to find out which
     * half applies to the member in front of them.
     */
    public function test_refused_mail_is_recorded_alongside_waiting_mail(): void
    {
        $snapshot = new RelayQueueSnapshot;
        $reason = 'host mta7.am0.yahoodns.net[67.195.228.94] said: 421 4.7.0 [TSS04] '
            . 'Messages from 185.53.57.161 temporarily deferred';

        for ($i = 0; $i < 30; $i++) {
            $snapshot->addDeferral("refused{$i}@yahoo.com", $reason, Carbon::now()->subHours(5)->getTimestamp(), "Q{$i}");
        }

        $this->recorder()->record($snapshot);

        $row = DB::table('mail_relay_queue')->where('domain', 'yahoo.com')->first();
        $this->assertNotNull($row);
        $this->assertEquals(30, $row->deferred);
        $this->assertEquals(0, $row->waiting);
    }

    /**
     * An estate-wide episode names thousands of domains and nobody reads the
     * five hundredth, so the table is capped - worst first, so the cap drops
     * the ones that do not matter rather than an arbitrary slice.
     */
    public function test_the_table_is_capped_worst_first(): void
    {
        config(['freegle.mail.relay_queue.max_rows' => 3]);

        $snapshot = new RelayQueueSnapshot;
        $arrival = Carbon::now()->subHours(5)->getTimestamp();
        foreach ([500, 400, 300, 200, 100] as $i => $count) {
            for ($n = 0; $n < $count; $n++) {
                $snapshot->addWaiting("m{$n}@domain{$i}.com", $arrival);
            }
        }

        $this->recorder()->record($snapshot);

        $this->assertSame(3, DB::table('mail_relay_queue')->count());
        $this->assertDatabaseHas('mail_relay_queue', ['domain' => 'domain0.com']);
        $this->assertDatabaseMissing('mail_relay_queue', ['domain' => 'domain4.com']);
    }

    /**
     * A dry run reports without writing, so the scheduled command's --dry-run
     * really is safe to point at production.
     */
    public function test_a_dry_run_writes_nothing(): void
    {
        $totals = $this->recorder()->record($this->snapshotWithWaiting('yahoo.com', 40, 300), true);

        $this->assertSame(1, $totals['rows'], 'still reports what it would record');
        $this->assertSame(0, DB::table('mail_relay_queue')->count());
    }
}
