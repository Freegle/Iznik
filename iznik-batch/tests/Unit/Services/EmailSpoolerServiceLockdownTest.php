<?php

namespace Tests\Unit\Services;

use App\Mail\Welcome\WelcomeMail;
use App\Services\Lockdown\LockdownService;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\TestCase;

/**
 * EmailSpoolerService::spool() and ::processSpool() under the lockdown switch (plan
 * 2026-09-27-lockdown-switch.md, section 11.7): spool() refuses NOTHING - a held,
 * non-allowlisted type is written to the spool exactly as normal, and counted
 * spooled_held:<type> once, at that write. processSpool() is where the actual delay
 * happens: it puts such a file straight back to pending/ unchanged (no attempt, no
 * backoff, no failed-dir move, no age-based expiry) every pass until the lockdown lifts,
 * while an allowlisted type is sent through and counted leaked:email:<type> at that
 * send - the relay handoff, not the earlier spool-time write, so the same mail is never
 * counted twice. Ordinary (no lockdown) behaviour is covered by EmailSpoolerServiceTest;
 * this file is only the lockdown-aware paths.
 */
class EmailSpoolerServiceLockdownTest extends TestCase
{
    use IsolatedSpoolDirectory;

    private LockdownService $lockdown;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedSpoolDirectory();
        DB::table('lockdowns')->delete();
        DB::table('lockdown_counters')->delete();
        // The cache is a static, process-wide property (section 11.6), so a row cached
        // by an earlier test class in this same PHPUnit process would otherwise leak in.
        LockdownService::flushCache();
        $this->lockdown = new LockdownService();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSpoolDirectory();
        parent::tearDown();
    }

    private function counterFor(string $kind): int
    {
        $row = DB::table('lockdown_counters')->where('kind', $kind)->first();

        return $row ? (int) $row->count : 0;
    }

    public function test_spool_writes_a_non_allowlisted_type_while_held_and_counts_it_once(): void
    {
        $email = $this->uniqueEmail('recipient');
        $mailable = new WelcomeMail($email);
        $this->lockdown->press(null, 'test lockdown');

        $id = $this->spooler->spool($mailable, $email, 'welcome');

        $this->assertNotSame('', $id, 'spool() must never refuse - the file is written, only counted');
        $this->assertFileExists($this->testSpoolDir . '/pending/' . $id . '.json');
        $this->assertSame(1, $this->counterFor('spooled_held:welcome'));
    }

    public function test_spool_writes_an_allowlisted_type_while_held_without_counting_it(): void
    {
        $email = $this->uniqueEmail('recipient');
        $mailable = new WelcomeMail($email);
        $this->lockdown->press(null, 'test lockdown');

        $id = $this->spooler->spool($mailable, $email, 'verify_email');

        $this->assertNotSame('', $id);
        $this->assertFileExists($this->testSpoolDir . '/pending/' . $id . '.json');
        $this->assertSame(0, $this->counterFor('spooled_held:verify_email'), 'allowlisted mail is not held, so nothing to count at spool time');
        $this->assertSame(
            0,
            $this->counterFor('leaked:email:verify_email'),
            'a leak is counted at the actual send (processSpool), not at the earlier spool-time write'
        );
    }

    public function test_spool_sends_normally_once_email_is_lifted(): void
    {
        $email = $this->uniqueEmail('recipient');
        $this->lockdown->press(null, 'test lockdown');
        $this->spooler->spool(new WelcomeMail($email), $email, 'welcome');
        $this->assertSame(1, $this->counterFor('spooled_held:welcome'));

        $this->lockdown->setSurfaces(['email' => false], null);

        $id = $this->spooler->spool(new WelcomeMail($email), $email, 'welcome');
        $this->assertNotSame('', $id);
        $this->assertFileExists($this->testSpoolDir . '/pending/' . $id . '.json');
        // Not held any more, so this second file adds nothing to the earlier count.
        $this->assertSame(1, $this->counterFor('spooled_held:welcome'));
    }

    public function test_process_spool_holds_a_non_allowlisted_type_queued_before_the_press(): void
    {
        $email = $this->uniqueEmail('recipient');
        $mailable = new WelcomeMail($email);
        // Spooled while email was NOT held - lands in pending/ normally.
        $id = $this->spooler->spool($mailable, $email, 'welcome');
        $this->assertFileExists($this->testSpoolDir . '/pending/' . $id . '.json');

        $this->lockdown->press(null, 'test lockdown');

        $stats = $this->spooler->processSpool();

        $this->assertSame(1, $stats['held_by_lockdown']);
        $this->assertSame(0, $stats['sent']);
        $this->assertFileExists(
            $this->testSpoolDir . '/pending/' . $id . '.json',
            'a held file goes back to pending unchanged, not to sent or failed'
        );
        // Nothing was written to spool WHILE held, so nothing is counted here -
        // processSpool() never counts spooled_held itself (see the next test).
        $this->assertSame(0, $this->counterFor('spooled_held:welcome'));
    }

    public function test_process_spool_does_not_recount_a_file_held_across_repeated_passes(): void
    {
        $email = $this->uniqueEmail('recipient');
        $this->lockdown->press(null, 'test lockdown');
        $id = $this->spooler->spool(new WelcomeMail($email), $email, 'welcome');
        $this->assertSame(1, $this->counterFor('spooled_held:welcome'), 'counted once, at spool() time');

        // Three more passes over the same still-held file - none of them may add to the
        // count. Recounting on every pass is exactly the shape of bug that reached
        // 10,777 for one member in 106 minutes for the chat notification counter.
        $this->spooler->processSpool();
        $this->spooler->processSpool();
        $this->spooler->processSpool();

        $this->assertFileExists($this->testSpoolDir . '/pending/' . $id . '.json');
        $this->assertSame(1, $this->counterFor('spooled_held:welcome'));
    }

    public function test_process_spool_still_sends_an_allowlisted_type_while_held(): void
    {
        $email = $this->uniqueEmail('recipient');
        $mailable = new WelcomeMail($email);
        $id = $this->spooler->spool($mailable, $email, 'verify_email');

        $this->lockdown->press(null, 'test lockdown');

        $stats = $this->spooler->processSpool();

        $this->assertSame(1, $stats['sent']);
        $this->assertSame(0, $stats['held_by_lockdown']);
        $this->assertFileDoesNotExist($this->testSpoolDir . '/pending/' . $id . '.json');
        $this->assertSame(1, $this->counterFor('leaked:email:verify_email'), 'counted at the send, the actual relay handoff');
    }

    public function test_no_age_based_cleanup_expires_a_waiting_file(): void
    {
        $email = $this->uniqueEmail('recipient');
        $this->lockdown->press(null, 'test lockdown');
        $id = $this->spooler->spool(new WelcomeMail($email), $email, 'welcome');
        $path = $this->testSpoolDir . '/pending/' . $id . '.json';

        // Back-date the file as if it had sat waiting for many hours.
        touch($path, now()->subHours(30)->timestamp);

        $stats = $this->spooler->processSpool();

        $this->assertSame(1, $stats['held_by_lockdown']);
        $this->assertSame(0, $stats['invalid'] ?? 0);
        $this->assertFileExists($path, 'age alone must never move a held file to failed/');
        $this->assertFileDoesNotExist($this->testSpoolDir . '/failed/' . $id . '.json');

        // cleanupSent() only ever prunes sent/ (and its ledger markers) - it must not
        // reach into pending/ regardless of how old a waiting file has become.
        $this->spooler->cleanupSent(0);
        $this->assertFileExists($path);
    }

    public function test_ack_is_written_for_each_file_passing_through_the_spool(): void
    {
        $this->lockdown->press(null, 'test lockdown');
        $email = $this->uniqueEmail('recipient');
        $this->spooler->spool(new WelcomeMail($email), $email, 'verify_email');

        $this->spooler->processSpool();

        $ack = DB::table('lockdown_acks')->where('loop', 'mail-spool')->first();
        $this->assertNotNull($ack, 'mail-spool must ack so the presser sees this loop take effect');
        $this->assertEquals($this->lockdown->current()->id, $ack->lockdownrowid);
    }
}
