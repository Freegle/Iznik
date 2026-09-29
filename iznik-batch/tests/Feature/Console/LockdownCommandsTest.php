<?php

namespace Tests\Feature\Console;

use App\Services\Lockdown\LockdownService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\TestCase;

/**
 * The lockdown:* console commands (plan 2026-09-27-lockdown-switch.md, section 11.4):
 * lockdown:on presses the switch, lockdown:off lifts surfaces or closes the incident,
 * lockdown:status reports the current state, lockdown:tick announces new rows and records
 * what is held, lockdown:filter-spool removes waiting mail whose member content is
 * no longer fit to send (section 11.8), lockdown:report mails geeks@ a stats summary.
 */
class LockdownCommandsTest extends TestCase
{
    use IsolatedSpoolDirectory;

    private LockdownService $lockdown;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedSpoolDirectory();
        DB::table('lockdowns')->delete();
        DB::table('lockdown_holds')->delete();
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

    /**
     * A minimal pending spool file, written directly (the same pattern
     * LockdownFilterSpoolServiceTest and EmailSpoolerProcessSpoolRaceTest use), so a
     * command-level test can control exactly what lockdown:filter-spool finds without
     * spooling a real Mailable.
     */
    private function writePendingSpoolFile(string $id, ?array $about, string $type = 'test_type'): string
    {
        $path = $this->testSpoolDir.'/pending/'.$id.'.json';
        file_put_contents($path, json_encode([
            'id' => $id,
            'email_type' => $type,
            'mailable_class' => 'Test\\Mailable',
            'about' => $about,
        ]));

        return $path;
    }

    // --- lockdown:on ---

    public function test_on_requires_a_reason(): void
    {
        $this->artisan('lockdown:on')->assertFailed();
        $this->assertNull($this->lockdown->current());
    }

    public function test_on_presses_the_switch(): void
    {
        $this->artisan('lockdown:on', ['--reason' => 'test incident', '--by' => 42])
            ->assertSuccessful();

        $this->assertTrue($this->lockdown->active());
        foreach (LockdownService::SURFACES as $surface) {
            $this->assertTrue($this->lockdown->held($surface), "$surface should be held");
        }
        $this->assertSame('test incident', $this->lockdown->current()->reason);
    }

    public function test_on_refuses_a_second_press_while_active(): void
    {
        $this->lockdown->press(null, 'first');

        $this->artisan('lockdown:on', ['--reason' => 'second'])->assertFailed();
    }

    // --- lockdown:off ---

    public function test_off_requires_active_lockdown(): void
    {
        $this->artisan('lockdown:off', ['--all' => true])->assertFailed();
    }

    public function test_off_requires_surface_all_or_close(): void
    {
        $this->lockdown->press(null, 'test');

        $this->artisan('lockdown:off')->assertFailed();
    }

    public function test_off_lifts_named_surfaces(): void
    {
        $this->lockdown->press(null, 'test');

        $this->artisan('lockdown:off', ['--surface' => ['posts', 'chat']])->assertSuccessful();

        $this->assertFalse($this->lockdown->held('posts'));
        $this->assertFalse($this->lockdown->held('chat'));
        $this->assertTrue($this->lockdown->held('email'), 'only the named surfaces lift');
    }

    public function test_off_rejects_an_unknown_surface(): void
    {
        $this->lockdown->press(null, 'test');

        $this->artisan('lockdown:off', ['--surface' => ['not-a-surface']])->assertFailed();
        $this->assertTrue($this->lockdown->held('posts'), 'nothing changes on a rejected call');
    }

    public function test_off_all_lifts_every_surface(): void
    {
        $this->lockdown->press(null, 'test');

        $this->artisan('lockdown:off', ['--all' => true])->assertSuccessful();

        foreach (LockdownService::SURFACES as $surface) {
            $this->assertFalse($this->lockdown->held($surface), "$surface should be lifted");
        }
        $this->assertTrue($this->lockdown->active(), '--all lifts surfaces, it does not close the incident');
    }

    public function test_off_close_ends_the_incident(): void
    {
        $this->lockdown->press(null, 'test');

        $this->artisan('lockdown:off', ['--close' => true, '--note' => 'resolved'])
            ->assertSuccessful();

        $this->assertFalse($this->lockdown->active());
        $this->assertSame('resolved', $this->lockdown->current()->endnote);
    }

    public function test_off_lifting_email_runs_filter_spool_first(): void
    {
        $this->lockdown->press(null, 'test');
        $sender = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $this->createTestUser());
        $badMessage = $this->createTestChatMessage($room, $sender, ['reviewrejected' => 1]);
        $this->writePendingSpoolFile('bad', [
            'chatmessages' => [$badMessage->id], 'messages' => [], 'newsfeed' => [], 'users' => [],
        ], 'chat_notification');

        $this->artisan('lockdown:off', ['--surface' => ['email']])
            ->expectsOutputToContain('Filtered spool before lifting email: checked 1, removed 1.')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->testSpoolDir.'/pending/bad.json');
        $this->assertFalse($this->lockdown->held('email'));
    }

    public function test_off_lifting_a_non_email_surface_does_not_run_filter_spool(): void
    {
        $this->lockdown->press(null, 'test');
        $path = $this->writePendingSpoolFile('untouched', null, 'welcome');

        $this->artisan('lockdown:off', ['--surface' => ['posts']])
            ->doesntExpectOutputToContain('Filtered spool')
            ->assertSuccessful();

        $this->assertFileExists($path, 'lifting a surface other than email must not touch the spool at all');
    }

    public function test_off_close_also_runs_filter_spool_first(): void
    {
        // --close lifts every surface including email (LockdownOffCommand's docblock), so
        // it runs the same pre-check.
        $this->lockdown->press(null, 'test');
        $sender = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $this->createTestUser());
        $badMessage = $this->createTestChatMessage($room, $sender, ['reviewrejected' => 1]);
        $this->writePendingSpoolFile('bad', [
            'chatmessages' => [$badMessage->id], 'messages' => [], 'newsfeed' => [], 'users' => [],
        ]);

        $this->artisan('lockdown:off', ['--close' => true])
            ->expectsOutputToContain('Filtered spool before lifting email: checked 1, removed 1.')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->testSpoolDir.'/pending/bad.json');
    }

    public function test_off_refuses_to_lift_email_when_filter_spool_errors(): void
    {
        $this->lockdown->press(null, 'test');
        $this->writePendingSpoolFile('broken', [
            'chatmessages' => [999999999], 'messages' => [], 'newsfeed' => [], 'users' => [],
        ]);
        // A plain file sitting at the lockdown-removed destination makes
        // LockdownFilterSpoolService::remove()'s rename() fail (the path is not a usable
        // directory), so filter() counts an error instead of a removal.
        file_put_contents($this->testSpoolDir.'/lockdown-removed', 'not a directory');

        $this->artisan('lockdown:off', ['--surface' => ['email']])
            ->expectsOutputToContain('reported 1 error(s) - refusing to lift email')
            ->assertFailed();

        $this->assertTrue($this->lockdown->held('email'), 'email must stay held when the filter cannot be trusted');
    }

    public function test_off_close_refuses_when_filter_spool_errors(): void
    {
        $this->lockdown->press(null, 'test');
        $this->writePendingSpoolFile('broken', [
            'chatmessages' => [999999999], 'messages' => [], 'newsfeed' => [], 'users' => [],
        ]);
        file_put_contents($this->testSpoolDir.'/lockdown-removed', 'not a directory');

        $this->artisan('lockdown:off', ['--close' => true])->assertFailed();

        $this->assertTrue($this->lockdown->active(), 'close must not proceed when the filter cannot be trusted');
    }

    // --- lockdown:status ---

    public function test_status_with_no_lockdown_ever(): void
    {
        $this->artisan('lockdown:status')->assertSuccessful();
    }

    public function test_status_reports_active_state(): void
    {
        $this->lockdown->press(null, 'test reason');

        $this->artisan('lockdown:status')
            ->expectsOutputToContain('LOCKDOWN ACTIVE')
            ->assertSuccessful();
    }

    // --- lockdown:tick ---

    public function test_tick_is_a_no_op_with_no_lockdown_ever(): void
    {
        Mail::fake();

        $this->artisan('lockdown:tick')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_tick_announces_a_new_row_once(): void
    {
        // Mail::raw() is a no-op under Mail::fake() (MailFake::raw() has an empty body,
        // confirmed by reading vendor/laravel/framework's MailFake), so it never shows up
        // in assertSent()/assertNothingSent() - the command's own output is the only signal
        // available, same as DeprecatedEndpointsCommandTest's Mail::raw()-based assertions.
        Mail::fake();
        $this->lockdown->press(null, 'test reason');

        $this->artisan('lockdown:tick')
            ->expectsOutputToContain('Announced 1.')
            ->assertSuccessful();

        $row = DB::table('lockdowns')->orderByDesc('id')->first();
        $this->assertNotNull($row->announcedat);

        // A second run with nothing new must not announce again.
        $this->artisan('lockdown:tick')
            ->expectsOutputToContain('Announced 0.')
            ->assertSuccessful();
    }

    public function test_tick_announces_each_later_change_separately(): void
    {
        Mail::fake();
        $this->lockdown->press(null, 'test reason');
        $this->artisan('lockdown:tick')
            ->expectsOutputToContain('Announced 1.')
            ->assertSuccessful();

        $this->lockdown->setSurfaces(['posts' => false], null);
        $this->artisan('lockdown:tick')
            ->expectsOutputToContain('Announced 1.')
            ->assertSuccessful();
    }

    public function test_tick_records_holds_while_active(): void
    {
        Mail::fake();
        $sender = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($sender, $group);
        $room = $this->createTestChatRoom($sender, $this->createTestUser());
        DB::table('chat_messages')->insert([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'date' => now(),
            'message' => 'is this still available',
            'type' => 'Default',
            'processingrequired' => 1,
        ]);

        $this->lockdown->press(null, 'test reason');

        $this->artisan('lockdown:tick')->assertSuccessful();

        $this->assertSame(
            1,
            DB::table('lockdown_holds')->where('kind', 'chat')->count(),
            'tick should have created a hold for the unprocessed User2User message'
        );
    }

    public function test_tick_acks_once_a_lockdown_exists(): void
    {
        Mail::fake();
        $incidentId = $this->lockdown->press(null, 'test reason');

        $this->artisan('lockdown:tick')->assertSuccessful();

        $ack = DB::table('lockdown_acks')->where('loop', 'tick')->first();
        $this->assertNotNull($ack, 'tick must ack so the presser sees this loop take effect');
        $this->assertSame($this->lockdown->current()->id, (int) $ack->lockdownrowid);
        $this->assertSame($incidentId, $this->lockdown->current()->incidentid);
    }

    public function test_tick_does_not_ack_with_no_lockdown_ever(): void
    {
        Mail::fake();

        $this->artisan('lockdown:tick')->assertSuccessful();

        $this->assertNull(DB::table('lockdown_acks')->where('loop', 'tick')->first());
    }

    public function test_tick_runs_filter_spool_while_email_is_held(): void
    {
        Mail::fake();
        $this->lockdown->press(null, 'test reason');
        $sender = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $this->createTestUser());
        $badMessage = $this->createTestChatMessage($room, $sender, ['reviewrejected' => 1]);
        $this->writePendingSpoolFile('bad', [
            'chatmessages' => [$badMessage->id], 'messages' => [], 'newsfeed' => [], 'users' => [],
        ]);

        $this->artisan('lockdown:tick')
            ->expectsOutputToContain('Filtered spool: checked 1, removed 1.')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->testSpoolDir.'/pending/bad.json');
    }

    public function test_tick_releases_held_posts_once_posts_is_lifted(): void
    {
        Mail::fake();
        $this->lockdown->press(null, 'test reason');
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);
        DB::table('messages_groups')->where('msgid', $message->id)->update([
            'collection' => \App\Models\MessageGroup::COLLECTION_PENDING,
            'arrival' => now()->addSecond(),
        ]);

        $this->artisan('lockdown:tick')->expectsOutputToContain('Posts released 0.')->assertSuccessful();
        $this->assertNull(DB::table('lockdown_holds')->where('kind', 'post')->value('outcome'), 'still held while posts is held');

        $this->lockdown->setSurfaces(['posts' => false], null);
        $this->artisan('lockdown:tick')->assertSuccessful();

        $this->assertNotNull(DB::table('lockdown_holds')->where('kind', 'post')->value('outcome'),
            'the tick admits held posts itself, without waiting for messages:contentcheck');
        $this->assertNotSame('releasing', DB::table('lockdown_holds')->where('kind', 'post')->value('outcome'));
    }

    public function test_tick_records_the_email_queue_after_close(): void
    {
        Mail::fake();
        $incidentId = $this->lockdown->press(null, 'test reason');
        $this->lockdown->close(null, 'done');
        $this->writePendingSpoolFile('one', null);
        $this->writePendingSpoolFile('two', null);

        $this->artisan('lockdown:tick')
            ->expectsOutputToContain('Emails queued 2.')
            ->assertSuccessful();

        $this->assertEquals(2, DB::table('lockdown_counters')->where(['lockdownid' => $incidentId, 'kind' => 'queue:email'])->value('count'));
    }

    public function test_tick_does_not_run_filter_spool_when_email_is_not_held(): void
    {
        Mail::fake();
        $this->lockdown->press(null, 'test reason');
        $this->lockdown->setSurfaces(['email' => false], null);
        $path = $this->writePendingSpoolFile('untouched', null);

        $this->artisan('lockdown:tick')
            ->doesntExpectOutputToContain('Filtered spool')
            ->assertSuccessful();

        $this->assertFileExists($path);
    }

    // --- lockdown:filter-spool ---

    public function test_filter_spool_reports_nothing_to_do_when_the_queue_is_clean(): void
    {
        $this->artisan('lockdown:filter-spool')
            ->expectsOutputToContain('Checked 0, removed 0, errors 0.')
            ->assertSuccessful();
    }

    public function test_filter_spool_removes_a_file_whose_content_is_gone(): void
    {
        $sender = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $this->createTestUser());
        $badMessage = $this->createTestChatMessage($room, $sender, ['reviewrejected' => 1]);
        $this->writePendingSpoolFile('bad', [
            'chatmessages' => [$badMessage->id], 'messages' => [], 'newsfeed' => [], 'users' => [],
        ], 'chat_notification');

        // count() only writes a lockdown_counters row while a lockdown is active
        // (section 11.6); filter-spool only ever runs during one in production.
        $this->lockdown->press(null, 'test');

        $this->artisan('lockdown:filter-spool')
            ->expectsOutputToContain('Checked 1, removed 1, errors 0.')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->testSpoolDir.'/pending/bad.json');
        $this->assertSame(
            1,
            (int) (DB::table('lockdown_counters')->where('kind', 'filtered:email:chat_notification')->first()->count ?? 0)
        );
    }

    public function test_filter_spool_leaves_a_file_with_no_about_alone(): void
    {
        $path = $this->writePendingSpoolFile('no_about', null, 'welcome');

        $this->artisan('lockdown:filter-spool')
            ->expectsOutputToContain('Checked 0, removed 0, errors 0.')
            ->assertSuccessful();

        $this->assertFileExists($path);
    }

    public function test_filter_spool_fails_the_command_when_a_file_cannot_be_checked(): void
    {
        $this->writePendingSpoolFile('broken', [
            'chatmessages' => [999999999], 'messages' => [], 'newsfeed' => [], 'users' => [],
        ]);
        file_put_contents($this->testSpoolDir.'/lockdown-removed', 'not a directory');

        $this->artisan('lockdown:filter-spool')
            ->expectsOutputToContain('Checked 1, removed 0, errors 1.')
            ->assertFailed();
    }

    // --- lockdown:report ---

    public function test_report_is_a_no_op_with_no_lockdown_ever(): void
    {
        Mail::fake();

        $this->artisan('lockdown:report')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_report_mails_geeks_while_active(): void
    {
        // Mail::raw() is a no-op under Mail::fake() (see test_tick_announces_a_new_row_once),
        // so the positive case is checked via the command's own output, matching
        // DeprecatedEndpointsCommandTest's convention for the same Mail::raw() pattern.
        Mail::fake();
        $incidentId = $this->lockdown->press(null, 'test reason');
        $this->lockdown->count('push', 3);

        $this->artisan('lockdown:report')
            ->expectsOutput("Report emailed for incident {$incidentId}.")
            ->assertSuccessful();
    }

    public function test_report_includes_acks_and_leaked_since_press(): void
    {
        Mail::fake();
        $this->lockdown->press(null, 'test reason');
        // One loop has caught up to the current state (out of the 8 named in the plan);
        // the rest have never acked at all.
        $this->lockdown->ack('push');
        $this->lockdown->count('leaked:push');

        $this->artisan('lockdown:report')
            ->expectsOutputToContain('Acks: 1/8 loops caught up. Leaked since press: 1.')
            ->assertSuccessful();
    }

    public function test_report_is_a_no_op_once_closed_without_closing_flag(): void
    {
        Mail::fake();
        $this->lockdown->press(null, 'test reason');
        $this->lockdown->close(null, 'done');

        $this->artisan('lockdown:report')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_report_closing_sends_even_though_closed(): void
    {
        Mail::fake();
        $incidentId = $this->lockdown->press(null, 'test reason');
        $this->lockdown->close(null, 'done');

        $this->artisan('lockdown:report', ['--closing' => true])
            ->expectsOutput("Report emailed for incident {$incidentId}.")
            ->assertSuccessful();
    }
}
