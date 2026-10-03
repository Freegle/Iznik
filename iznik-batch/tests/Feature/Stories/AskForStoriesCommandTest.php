<?php

namespace Tests\Feature\Stories;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AskForStoriesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeUserWithMessages(int $offerCount = 0, int $outcomeCount = 0, array $userAttrs = []): User
    {
        $user = $this->createTestUser($userAttrs);

        for ($i = 0; $i < $offerCount; $i++) {
            DB::table('messages')->insert([
                'type' => Message::TYPE_OFFER,
                'source' => Message::SOURCE_PLATFORM,
                'subject' => 'OFFER: item',
                'textbody' => 'test',
                'fromuser' => $user->id,
                'arrival' => now()->subDays(10)->toDateString(),
            ]);
        }

        // messages_by.msgid has FK to messages.id and unique(msgid, userid), so create real messages
        for ($i = 0; $i < $outcomeCount; $i++) {
            $msgId = DB::table('messages')->insertGetId([
                'type' => Message::TYPE_TAKEN,
                'source' => Message::SOURCE_PLATFORM,
                'subject' => 'TAKEN: item',
                'textbody' => 'test',
                'fromuser' => $user->id,
                'arrival' => now()->subDays(5)->toDateString(),
            ]);
            DB::table('messages_by')->insert([
                'userid' => $user->id,
                'msgid' => $msgId,
            ]);
        }

        return $user;
    }

    // ── Smoke tests ──────────────────────────────────────────────────────────

    public function test_command_runs_with_no_data(): void
    {
        $this->artisan('stories:ask')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_dry_run_shows_prefix(): void
    {
        $this->artisan('stories:ask', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run]')
            ->assertExitCode(0);
    }

    // ── Threshold filtering ──────────────────────────────────────────────────

    public function test_no_email_below_both_thresholds(): void
    {
        // 3 offers (≤ threshold), 2 outcomes (≤ threshold)
        $this->makeUserWithMessages(offerCount: 3, outcomeCount: 2);

        $this->artisan('stories:ask')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_email_when_offers_exceed_threshold(): void
    {
        // 6 offers > threshold of 5
        $this->makeUserWithMessages(offerCount: 6, outcomeCount: 0);

        $this->artisan('stories:ask')->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_sends_email_when_outcomes_exceed_threshold(): void
    {
        // 4 outcomes > threshold of 3
        $this->makeUserWithMessages(offerCount: 0, outcomeCount: 4);

        $this->artisan('stories:ask')->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    // ── Already asked ────────────────────────────────────────────────────────

    public function test_skips_user_already_in_requested_table(): void
    {
        $user = $this->makeUserWithMessages(offerCount: 6);

        DB::table('users_stories_requested')->insert([
            'userid' => $user->id,
            'date' => now()->subYear(),
        ]);

        $this->artisan('stories:ask')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    // ── Newsletters & stories preference ─────────────────────────────────────
    //
    // There is no per-group stories toggle any more, only the member-level
    // "Newsletters & stories" preference (users.newslettersallowed), the same
    // one Stories Newsletter and Community News honour.

    public function test_skips_user_when_newsletters_and_stories_opted_out(): void
    {
        $this->makeUserWithMessages(offerCount: 6, userAttrs: ['newslettersallowed' => 0]);

        $this->artisan('stories:ask')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_email_when_newsletters_and_stories_allowed(): void
    {
        // newslettersallowed defaults to 1 (allowed) for a new member.
        $this->makeUserWithMessages(offerCount: 6, userAttrs: ['newslettersallowed' => 1]);

        $this->artisan('stories:ask')->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    // ── Requested table recording ────────────────────────────────────────────

    public function test_records_consideration_in_requested_table(): void
    {
        $user = $this->makeUserWithMessages(offerCount: 6);

        $this->artisan('stories:ask')->assertExitCode(0);

        $this->assertDatabaseHas('users_stories_requested', ['userid' => $user->id]);
    }

    public function test_records_consideration_even_when_stories_disabled(): void
    {
        // V1 records to users_stories_requested before checking the newsletters/stories preference.
        $user = $this->makeUserWithMessages(offerCount: 6, userAttrs: ['newslettersallowed' => 0]);

        $this->artisan('stories:ask')->assertExitCode(0);

        $this->assertDatabaseHas('users_stories_requested', ['userid' => $user->id]);
    }

    // ── Dry-run ──────────────────────────────────────────────────────────────

    public function test_dry_run_no_email_sent(): void
    {
        $this->makeUserWithMessages(offerCount: 6);

        $this->artisan('stories:ask', ['--dry-run' => true])->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_dry_run_no_requested_record_written(): void
    {
        $user = $this->makeUserWithMessages(offerCount: 6);

        $this->artisan('stories:ask', ['--dry-run' => true])->assertExitCode(0);

        $this->assertDatabaseMissing('users_stories_requested', ['userid' => $user->id]);
    }

    // ── Deleted users ─────────────────────────────────────────────────────────

    public function test_skips_deleted_users(): void
    {
        $user = $this->makeUserWithMessages(offerCount: 6);

        DB::table('users')->where('id', $user->id)->update(['deleted' => now()]);

        $this->artisan('stories:ask')->assertExitCode(0);

        Mail::assertNothingSent();
    }
}
