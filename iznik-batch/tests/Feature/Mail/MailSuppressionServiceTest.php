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
        $this->suppress('domain', 'yahoo.co.uk');
        $this->service->flushCache();

        $this->assertTrue($this->service->shouldSkip('someone@yahoo.co.uk', $user->id, 'digest_daily'));
        $this->assertFalse($this->service->shouldSkip('someone@gmail.com', $user->id, 'digest_daily'));

        $this->assertSame(
            1,
            DB::table('mail_suppressed_counts')->where('userid', $user->id)->count(),
            'only the skipped one should be counted'
        );
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
