<?php

namespace Tests\Feature\Group;

use App\Models\Group;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AlertNoMessagesCommandTest extends TestCase
{
    private function insertApprovedMessage(int $groupId, string $arrival): void
    {
        $messageId = DB::table('messages')->insertGetId([
            'source'    => 'Email',
            'arrival'   => $arrival,
            'date'      => $arrival,
            'fromip'    => '127.0.0.1',
            'envelopefrom' => 'test@example.com',
        ]);

        DB::table('messages_groups')->insert([
            'msgid'     => $messageId,
            'groupid'   => $groupId,
            'arrival'   => $arrival,
            'collection' => 'Approved',
        ]);
    }

    public function test_smoke_no_groups(): void
    {
        Mail::fake();

        $this->artisan('groups:alert-no-messages')
            ->expectsOutputToContain('Found 0 group(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_no_alert_when_groups_have_recent_messages(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->insertApprovedMessage($group->id, now()->subDays(3)->toDateTimeString());

        $this->artisan('groups:alert-no-messages')
            ->expectsOutputToContain('Found 0 group(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_alerts_when_group_has_no_recent_messages(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->insertApprovedMessage($group->id, now()->subDays(10)->toDateTimeString());

        $this->artisan('groups:alert-no-messages')
            ->expectsOutputToContain('Found 1 group(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_alerts_when_group_has_never_received_messages(): void
    {
        Mail::fake();

        $this->createTestGroup();

        $this->artisan('groups:alert-no-messages')
            ->expectsOutputToContain('Found 1 group(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_playground_groups(): void
    {
        Mail::fake();

        // Use DB::insert directly because createTestGroup() prohibits custom nameshort.
        DB::table('groups')->insert([
            'nameshort' => 'playground-' . uniqid(),
            'namefull'  => 'Playground Test Group',
            'type'      => Group::TYPE_FREEGLE,
            'onhere'    => 1,
            'publish'   => 1,
            'region'    => 'Test',
            'lat'       => 51.5,
            'lng'       => -0.1,
        ]);

        $this->artisan('groups:alert-no-messages')
            ->expectsOutputToContain('Found 0 group(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_non_freegle_groups(): void
    {
        Mail::fake();

        $this->createTestGroup(['type' => Group::TYPE_OTHER]);

        $this->artisan('groups:alert-no-messages')
            ->expectsOutputToContain('Found 0 group(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_groups_not_onhere(): void
    {
        Mail::fake();

        $this->createTestGroup(['onhere' => 0]);

        $this->artisan('groups:alert-no-messages')
            ->expectsOutputToContain('Found 0 group(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_custom_days_threshold(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->insertApprovedMessage($group->id, now()->subDays(3)->toDateTimeString());

        // 3 days is OK for default threshold (7), but would trigger at --days=2
        $this->artisan('groups:alert-no-messages', ['--days' => 2])
            ->expectsOutputToContain('Found 1 group(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_dry_run_does_not_send(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->insertApprovedMessage($group->id, now()->subDays(10)->toDateTimeString());

        $this->artisan('groups:alert-no-messages', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Found 1 group(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }
}
