<?php

namespace Tests\Feature\Monitor;

use App\Monitoring\HostPatchTracker;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HostPatchTracker turns "security updates or a reboot are pending" into a
 * verdict on age, recorded as the cron_job_status row `host-patch <host>`.
 */
class HostPatchTrackerTest extends TestCase
{
    private HostPatchTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = new HostPatchTracker();
        config([
            'freegle.monitoring.patch_max_age_days' => 9,
            'freegle.monitoring.patch_max_age_overrides' => 'db2=16, db3=16',
        ]);
        DB::table('cron_job_status')->where('command', 'like', 'host-patch %')->delete();
    }

    protected function tearDown(): void
    {
        DB::table('cron_job_status')->where('command', 'like', 'host-patch %')->delete();
        parent::tearDown();
    }

    private function probe(int $security = 0, string $reboot = 'no', int $rebootSince = 0): string
    {
        return "REBOOT:{$reboot}\nREBOOT_SINCE:{$rebootSince}\nSECURITY:{$security}\nMONIT_ABSENT\n";
    }

    private function row(string $host): object
    {
        $row = DB::table('cron_job_status')->where('command', "host-patch {$host}")->first();
        $this->assertNotNull($row, "no host-patch row for {$host}");

        return $row;
    }

    public function test_an_up_to_date_host_is_a_success(): void
    {
        $this->tracker->record('ha', $this->probe(), Carbon::parse('2026-10-22 12:00:00'));

        $row = $this->row('ha');
        $this->assertSame(0, (int) $row->last_exit_code);
        $this->assertStringStartsWith('up to date', $row->last_output);
    }

    public function test_updates_pending_within_the_limit_are_a_success_with_their_age(): void
    {
        $start = Carbon::parse('2026-10-20 12:00:00');
        $this->tracker->record('ha', $this->probe(security: 3), $start);
        $this->tracker->record('ha', $this->probe(security: 3), $start->copy()->addDays(2));

        $row = $this->row('ha');
        $this->assertSame(0, (int) $row->last_exit_code);
        $this->assertStringContainsString('3 security updates pending for 2 days', $row->last_output);
    }

    public function test_updates_pending_longer_than_the_weekly_limit_fail(): void
    {
        $start = Carbon::parse('2026-10-01 12:00:00');
        $this->tracker->record('bulk2', $this->probe(security: 1), $start);
        $this->tracker->record('bulk2', $this->probe(security: 1), $start->copy()->addDays(8));
        $this->assertSame(0, (int) $this->row('bulk2')->last_exit_code, '8 days is inside 7 + 2');

        $this->tracker->record('bulk2', $this->probe(security: 1), $start->copy()->addDays(10));
        $row = $this->row('bulk2');
        $this->assertSame(1, (int) $row->last_exit_code);
        $this->assertStringContainsString('1 security update pending for 10 days', $row->last_output);
    }

    public function test_alternate_week_hosts_get_the_longer_limit(): void
    {
        $this->assertSame(9, $this->tracker->limitDays('ha'));
        $this->assertSame(16, $this->tracker->limitDays('db2'));
        $this->assertSame(16, $this->tracker->limitDays('db3'));

        $start = Carbon::parse('2026-10-01 12:00:00');
        $this->tracker->record('db2', $this->probe(security: 2), $start);
        $this->tracker->record('db2', $this->probe(security: 2), $start->copy()->addDays(12));
        $this->assertSame(0, (int) $this->row('db2')->last_exit_code, '12 days is normal when patched fortnightly');

        $this->tracker->record('db2', $this->probe(security: 2), $start->copy()->addDays(17));
        $this->assertSame(1, (int) $this->row('db2')->last_exit_code);
    }

    public function test_a_reboot_pending_too_long_fails_and_starts_from_the_flag_file_time(): void
    {
        $now = Carbon::parse('2026-10-22 12:00:00');
        $flagged = $now->copy()->subDays(12)->getTimestamp();

        // First ever probe: the flag file's own mtime says it has been 12 days.
        $this->tracker->record('ha', $this->probe(reboot: 'yes', rebootSince: $flagged), $now);

        $row = $this->row('ha');
        $this->assertSame(1, (int) $row->last_exit_code);
        $this->assertStringContainsString('reboot pending for 12 days', $row->last_output);
    }

    public function test_the_clock_stops_when_the_condition_clears_and_restarts_afresh(): void
    {
        $start = Carbon::parse('2026-10-01 12:00:00');
        $this->tracker->record('ha', $this->probe(security: 2), $start);
        $this->tracker->record('ha', $this->probe(security: 2), $start->copy()->addDays(12));
        $this->assertSame(1, (int) $this->row('ha')->last_exit_code);

        // Patched: back to green, and the old start is forgotten.
        $this->tracker->record('ha', $this->probe(), $start->copy()->addDays(13));
        $this->assertSame(0, (int) $this->row('ha')->last_exit_code);
        $this->assertStringStartsWith('up to date', $this->row('ha')->last_output);

        // New updates arrive: age counts from now, not from the old start.
        $this->tracker->record('ha', $this->probe(security: 1), $start->copy()->addDays(14));
        $row = $this->row('ha');
        $this->assertSame(0, (int) $row->last_exit_code);
        $this->assertStringContainsString('pending for 0 hours', $row->last_output);
    }

    public function test_a_garbled_reply_leaves_the_existing_row_alone(): void
    {
        $now = Carbon::parse('2026-10-22 12:00:00');
        $this->tracker->record('ha', $this->probe(security: 2), $now);
        $before = $this->row('ha')->last_output;

        $this->tracker->record('ha', "ssh: something odd\n", $now->copy()->addHour());

        $this->assertSame($before, $this->row('ha')->last_output);
    }

    public function test_each_host_has_its_own_row(): void
    {
        $now = Carbon::parse('2026-10-22 12:00:00');
        $this->tracker->record('ha', $this->probe(security: 1), $now);
        $this->tracker->record('db1', $this->probe(), $now);

        $this->assertStringContainsString('1 security update pending', $this->row('ha')->last_output);
        $this->assertStringStartsWith('up to date', $this->row('db1')->last_output);
    }
}
