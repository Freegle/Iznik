<?php

namespace Tests\Feature\Mail;

use App\Services\Mail\MailSuppressionService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MailSuppressionServiceTest extends TestCase
{
    private MailSuppressionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MailSuppressionService;
    }

    /**
     * mail_suppressed_counts.userid is a foreign key onto users, so a count
     * cannot be recorded against an id that does not exist - and
     * recordSuppressed() swallows the rejection rather than break a send loop,
     * so the row just silently never appears.
     */
    private function heldUserId(): int
    {
        return (int) $this->createTestUser()->id;
    }

    private function suppress(string $scope, string $value, array $extra = []): int
    {
        $this->service->flushCache();

        return DB::table('mail_suppressions')->insertGetId(array_merge([
            'scope' => $scope,
            'value' => $value,
            'reason' => '421 4.7.0 [TSS04] temporarily deferred',
            'provider' => 'Yahoo',
            'deferred_since' => '2026-08-15 16:38:00',
            'first_seen' => now(),
            'last_seen' => now(),
            'message_count' => 52000,
        ], $extra));
    }

    public function test_nothing_is_suppressed_by_default(): void
    {
        $this->assertFalse($this->service->isSuppressed('someone@yahoo.co.uk'));
    }

    public function test_suppresses_by_domain(): void
    {
        $this->suppress('domain', 'yahoo.co.uk');
        $this->service->flushCache();

        $this->assertTrue($this->service->isSuppressed('someone@yahoo.co.uk'));
        $this->assertFalse($this->service->isSuppressed('someone@gmail.com'));
    }

    public function test_matching_ignores_case_and_whitespace(): void
    {
        $this->suppress('domain', 'yahoo.co.uk');
        $this->service->flushCache();

        $this->assertTrue($this->service->isSuppressed('  SomeOne@Yahoo.CO.UK  '));
    }

    public function test_suppresses_by_exact_address(): void
    {
        $this->suppress('address', 'full@example.com');
        $this->service->flushCache();

        $this->assertTrue($this->service->isSuppressed('full@example.com'));
        $this->assertFalse($this->service->isSuppressed('other@example.com'));
    }

    public function test_an_address_row_wins_over_its_domain_row(): void
    {
        // The address row carries the more accurate reason: one full mailbox
        // is a different story from a provider refusing everyone.
        $this->suppress('domain', 'example.com', ['reason' => 'provider deferral', 'provider' => 'Example']);
        $this->suppress('address', 'full@example.com', ['reason' => '452 4.2.2 over quota', 'provider' => null]);
        $this->service->flushCache();

        $row = $this->service->suppressionFor('full@example.com');

        $this->assertStringContainsString('over quota', (string) $row->reason);
    }

    public function test_an_mxgroup_row_alone_does_not_gate_anything(): void
    {
        // The mxgroup row carries the evidence; the domain children are what
        // the sending loops match on. Gating on the group alone would need a
        // DNS lookup per recipient, which is exactly what we are avoiding.
        $this->suppress('mxgroup', 'yahoodns.net');
        $this->service->flushCache();

        $this->assertFalse($this->service->isSuppressed('someone@yahoo.co.uk'));
    }

    public function test_a_released_suppression_stops_gating(): void
    {
        $this->suppress('domain', 'yahoo.co.uk', ['released_at' => now()]);
        $this->service->flushCache();

        $this->assertFalse($this->service->isSuppressed('someone@yahoo.co.uk'));
    }

    public function test_the_same_withheld_mail_is_counted_once(): void
    {
        // The chat notifier skips a suppressed recipient WITHOUT advancing
        // chat_roster.lastmsgemailed, so the same unread messages come round on
        // every run. Counting each pass is what put one member at 10,777 held
        // "mails" in 106 minutes on prod. The message id is the identity: seeing
        // it again is the same mail, not another one.
        $this->suppress('domain', 'yahoo.co.uk');
        $this->service->flushCache();

        $userId = $this->heldUserId();
        $this->service->shouldSkip('held@yahoo.co.uk', $userId, 'chat', 1000);
        $this->service->shouldSkip('held@yahoo.co.uk', $userId, 'chat', 1000);
        $this->service->shouldSkip('held@yahoo.co.uk', $userId, 'chat', 1000);

        $this->assertSame(1, (int) DB::table('mail_suppressed_counts')
            ->where('userid', $userId)->where('emailtype', 'chat')->value('count'));
    }

    public function test_a_new_mail_still_counts(): void
    {
        $this->suppress('domain', 'yahoo.co.uk');
        $this->service->flushCache();

        $userId = $this->heldUserId();
        $this->service->shouldSkip('held@yahoo.co.uk', $userId, 'chat', 1000);
        $this->service->shouldSkip('held@yahoo.co.uk', $userId, 'chat', 1000);
        $this->service->shouldSkip('held@yahoo.co.uk', $userId, 'chat', 1001);

        $this->assertSame(2, (int) DB::table('mail_suppressed_counts')
            ->where('userid', $userId)->where('emailtype', 'chat')->value('count'));
    }

    public function test_a_caller_without_an_identity_counts_every_call(): void
    {
        // Once-per-run mailers have no per-mail id and do not need one: each call
        // really is a separate mail withheld.
        $this->suppress('domain', 'yahoo.co.uk');
        $this->service->flushCache();

        $userId = $this->heldUserId();
        $this->service->shouldSkip('held@yahoo.co.uk', $userId, 'engage');
        $this->service->shouldSkip('held@yahoo.co.uk', $userId, 'engage');

        $this->assertSame(2, (int) DB::table('mail_suppressed_counts')
            ->where('userid', $userId)->where('emailtype', 'engage')->value('count'));
    }

    public function test_exposes_the_delayed_since_date_and_provider(): void
    {
        $this->suppress('domain', 'yahoo.co.uk');
        $this->service->flushCache();

        $row = $this->service->suppressionFor('someone@yahoo.co.uk');

        $this->assertSame('Yahoo', $row->provider);
        $this->assertSame('2026-08-15 16:38:00', (string) $row->deferred_since);
    }

    public function test_tolerates_a_missing_or_malformed_address(): void
    {
        $this->suppress('domain', 'yahoo.co.uk');
        $this->service->flushCache();

        $this->assertFalse($this->service->isSuppressed(null));
        $this->assertFalse($this->service->isSuppressed(''));
        $this->assertFalse($this->service->isSuppressed('not-an-address'));
    }

    public function test_suppression_for_returns_the_row_so_callers_can_report_it(): void
    {
        // shouldSkip() needs the row, not just a boolean, so the count it
        // writes records WHICH suppression held the mail. Deriving that
        // later would mean reimplementing the mailer address ranking.
        $id = $this->suppress('domain', 'yahoo.co.uk');
        $this->service->flushCache();

        $row = $this->service->suppressionFor('someone@yahoo.co.uk');

        $this->assertNotNull($row);
        $this->assertSame($id, (int) $row->id);
    }
    public function test_never_suppresses_our_own_operational_mail(): void
    {
        // The alert saying a provider has stopped accepting our mail is
        // exactly the message we would be dropping. The volume is trivial, so
        // there is nothing to save and everything to lose.
        $this->suppress('domain', 'ilovefreegle.org');
        $this->suppress('domain', 'users.ilovefreegle.org');
        $this->service->flushCache();

        $this->assertFalse($this->service->isSuppressed('geeks@ilovefreegle.org'));
        $this->assertFalse($this->service->isSuppressed('geek-alerts@ilovefreegle.org'));
        $this->assertFalse($this->service->isSuppressed('somegroup-volunteers@groups.ilovefreegle.org'));
    }

    // ===================================================================
    // Counting what we declined to send
    // ===================================================================

    public function test_records_and_accumulates_what_it_declined_to_generate(): void
    {
        $user = $this->createTestUser();

        $this->service->recordSuppressed($user->id, 'digest_immediate');
        $this->service->recordSuppressed($user->id, 'digest_immediate');
        $this->service->recordSuppressed($user->id, 'chat');

        $this->assertDatabaseHas('mail_suppressed_counts', [
            'userid' => $user->id, 'emailtype' => 'digest_immediate', 'count' => 2,
        ]);
        $this->assertDatabaseHas('mail_suppressed_counts', [
            'userid' => $user->id, 'emailtype' => 'chat', 'count' => 1,
        ]);
    }

    public function test_a_fresh_episode_starts_counting_again_rather_than_resuming(): void
    {
        $user = $this->createTestUser();

        $this->service->recordSuppressed($user->id, 'chat');
        $this->service->recordSuppressed($user->id, 'chat');
        DB::table('mail_suppressed_counts')->update(['caughtup_at' => now()->subDay()]);

        $this->service->recordSuppressed($user->id, 'chat');

        $row = DB::table('mail_suppressed_counts')->where('userid', $user->id)->first();
        $this->assertSame(1, (int) $row->count, 'the second episode is its own story');
        $this->assertNull($row->caughtup_at);
    }

    public function test_should_skip_both_answers_and_counts(): void
    {
        $user = $this->createTestUser();
        $id = $this->suppress('domain', 'yahoo.co.uk');
        $this->service->flushCache();

        $this->assertTrue($this->service->shouldSkip('someone@yahoo.co.uk', $user->id, 'digest_daily'));
        $this->assertFalse($this->service->shouldSkip('someone@gmail.com', $user->id, 'digest_daily'));

        $this->assertSame(
            1,
            DB::table('mail_suppressed_counts')->where('userid', $user->id)->count(),
            'only the skipped one should be counted'
        );
    }

    public function test_records_which_suppression_was_in_force_at_the_time(): void
    {
        // Recorded now, not re-derived later: by the time anything reports on
        // this the suppression will have been released and the member's
        // address may have changed.
        $user = $this->createTestUser();
        $id = $this->suppress('domain', 'yahoo.co.uk');
        $this->service->flushCache();

        $this->service->shouldSkip('someone@yahoo.co.uk', $user->id, 'chat');

        $this->assertDatabaseHas('mail_suppressed_counts', [
            'userid' => $user->id,
            'emailtype' => 'chat',
            'suppressionid' => $id,
        ]);
    }

    public function test_counting_never_breaks_the_send_loop(): void
    {
        // Losing a counter costs accuracy in one catch-up email. It must not
        // cost us the whole digest run.
        $this->service->recordSuppressed(null, 'chat');
        $this->service->recordSuppressed(0, 'chat');
        $this->service->recordSuppressed(999999999, 'chat');

        $this->assertTrue(true, 'no exception escaped');
    }
}
