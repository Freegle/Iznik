<?php

namespace Tests\Feature\Stories;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\User;
use App\Services\StoriesAskService;
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

    private function makeUserWithMessages(Group $group, int $offerCount = 0, int $outcomeCount = 0): User
    {
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        for ($i = 0; $i < $offerCount; $i++) {
            $msgId = DB::table('messages')->insertGetId([
                'type' => Message::TYPE_OFFER,
                'source' => Message::SOURCE_PLATFORM,
                'subject' => 'OFFER: item',
                'textbody' => 'test',
                'fromuser' => $user->id,
                'arrival' => now()->subDays(10)->toDateString(),
            ]);
            DB::table('messages_groups')->insert([
                'msgid' => $msgId,
                'groupid' => $group->id,
                'collection' => Membership::COLLECTION_APPROVED,
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
        $group = $this->createTestGroup();
        // 3 offers (≤ threshold), 2 outcomes (≤ threshold)
        $this->makeUserWithMessages($group, offerCount: 3, outcomeCount: 2);

        $this->artisan('stories:ask')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_email_when_offers_exceed_threshold(): void
    {
        $group = $this->createTestGroup();
        // 6 offers > threshold of 5
        $this->makeUserWithMessages($group, offerCount: 6, outcomeCount: 0);

        $this->artisan('stories:ask')->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_sends_email_when_outcomes_exceed_threshold(): void
    {
        $group = $this->createTestGroup();
        // 4 outcomes > threshold of 3
        $this->makeUserWithMessages($group, offerCount: 0, outcomeCount: 4);

        $this->artisan('stories:ask')->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    // ── Already asked ────────────────────────────────────────────────────────

    public function test_skips_user_already_in_requested_table(): void
    {
        $group = $this->createTestGroup();
        $user = $this->makeUserWithMessages($group, offerCount: 6);

        DB::table('users_stories_requested')->insert([
            'userid' => $user->id,
            'date' => now()->subYear(),
        ]);

        $this->artisan('stories:ask')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    // ── Group stories setting ────────────────────────────────────────────────

    public function test_skips_user_when_all_groups_have_stories_disabled(): void
    {
        $group = $this->createTestGroup(['settings' => ['stories' => 0]]);
        $this->makeUserWithMessages($group, offerCount: 6);

        $this->artisan('stories:ask')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_email_when_group_has_stories_enabled_explicitly(): void
    {
        $group = $this->createTestGroup(['settings' => ['stories' => 1]]);
        $this->makeUserWithMessages($group, offerCount: 6);

        $this->artisan('stories:ask')->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_sends_email_when_group_settings_null(): void
    {
        // stories defaults to 1 (enabled) when settings is NULL
        $group = $this->createTestGroup(['settings' => null]);
        $this->makeUserWithMessages($group, offerCount: 6);

        $this->artisan('stories:ask')->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    // ── Requested table recording ────────────────────────────────────────────

    public function test_records_consideration_in_requested_table(): void
    {
        $group = $this->createTestGroup();
        $user = $this->makeUserWithMessages($group, offerCount: 6);

        $this->artisan('stories:ask')->assertExitCode(0);

        $this->assertDatabaseHas('users_stories_requested', ['userid' => $user->id]);
    }

    public function test_records_consideration_even_when_stories_disabled(): void
    {
        // V1 records to users_stories_requested before checking stories flag
        $group = $this->createTestGroup(['settings' => ['stories' => 0]]);
        $user = $this->makeUserWithMessages($group, offerCount: 6);

        $this->artisan('stories:ask')->assertExitCode(0);

        $this->assertDatabaseHas('users_stories_requested', ['userid' => $user->id]);
    }

    // ── Dry-run ──────────────────────────────────────────────────────────────

    public function test_dry_run_no_email_sent(): void
    {
        $group = $this->createTestGroup();
        $this->makeUserWithMessages($group, offerCount: 6);

        $this->artisan('stories:ask', ['--dry-run' => true])->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_dry_run_no_requested_record_written(): void
    {
        $group = $this->createTestGroup();
        $user = $this->makeUserWithMessages($group, offerCount: 6);

        $this->artisan('stories:ask', ['--dry-run' => true])->assertExitCode(0);

        $this->assertDatabaseMissing('users_stories_requested', ['userid' => $user->id]);
    }

    // ── Deleted users ─────────────────────────────────────────────────────────

    public function test_skips_deleted_users(): void
    {
        $group = $this->createTestGroup();
        $user = $this->makeUserWithMessages($group, offerCount: 6);

        DB::table('users')->where('id', $user->id)->update(['deleted' => now()]);

        $this->artisan('stories:ask')->assertExitCode(0);

        Mail::assertNothingSent();
    }
}
