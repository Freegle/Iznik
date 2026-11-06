<?php

namespace Tests\Feature\TrashNothing;

use App\Models\User;
use App\Models\UserEmail;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TNBackfillUserIdsCommandTest extends TestCase
{
    private const TN_ID = 99150101;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'freegle.trashnothing.api_key' => 'test-key',
            'freegle.trashnothing.api_base_url' => 'https://trashnothing.com/fd/api',
        ]);

        $this->user = User::create(['fullname' => 'Test Member', 'added' => now()]);
        UserEmail::create([
            'userid' => $this->user->id,
            'email' => 'backfill' . uniqid() . '-g1@user.trashnothing.com',
            'preferred' => 1,
        ]);
    }

    private function fakeFeed(): void
    {
        Http::fake(['*/user-changes*' => Http::response(['changes' => [[
            'fd_user_id' => $this->user->id,
            'tn_user_id' => self::TN_ID,
            'date' => '2026-02-01T10:00:00',
        ]]], 200)]);
    }

    public function test_it_reports_and_writes_nothing_without_commit(): void
    {
        $this->fakeFeed();

        $this->artisan('tn:backfill-user-ids', ['--from' => '2026-01-01'])
            ->expectsOutputToContain('stamped: 1')
            ->expectsOutputToContain('Dry run: nothing written')
            ->assertExitCode(0);

        $this->assertNull($this->user->fresh()->tnuserid);
    }

    public function test_it_stamps_the_account_with_commit(): void
    {
        $this->fakeFeed();

        $this->artisan('tn:backfill-user-ids', ['--from' => '2026-01-01', '--commit' => true])
            ->assertExitCode(0);

        $this->assertSame(self::TN_ID, (int) $this->user->fresh()->tnuserid);
    }

    /** How much the feed left is what decides whether it is safe to go on. */
    public function test_it_says_how_many_accounts_are_left_with_no_tn_id(): void
    {
        $this->fakeFeed();

        $left = function (array $options): int {
            Artisan::call('tn:backfill-user-ids', ['--from' => '2026-01-01'] + $options);
            preg_match('/accounts with a TN address and no TN id: (\d+)/', Artisan::output(), $found);

            return (int) $found[1];
        };

        $before = $left([]);
        $after = $left(['--commit' => true]);

        $this->assertGreaterThan(0, $before);
        $this->assertSame($before - 1, $after);
    }

    public function test_it_needs_a_start_date(): void
    {
        $this->artisan('tn:backfill-user-ids')->assertExitCode(1);
    }

    public function test_it_fails_when_the_feed_cannot_be_read(): void
    {
        Http::fake(['*/user-changes*' => Http::response('Internal Server Error', 500)]);

        $this->artisan('tn:backfill-user-ids', ['--from' => '2026-01-01'])->assertExitCode(1);
    }
}
