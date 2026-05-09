<?php

namespace Tests\Feature\Integrations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncTrashNothingCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
    }

    private function fakeEmpty(): void
    {
        Http::fake([
            '*trashnothing.com/fd/api/ratings*'      => Http::response(['ratings' => []], 200),
            '*trashnothing.com/fd/api/user-changes*' => Http::response(['changes' => []], 200),
        ]);
    }

    public function test_runs_cleanly_with_empty_api_responses(): void
    {
        $this->fakeEmpty();

        $this->artisan('integrations:sync-trashnothing')
            ->expectsOutputToContain('Processed 0 rating(s), 0 user change(s)')
            ->assertExitCode(0);
    }

    public function test_dry_run_outputs_dry_run_prefix(): void
    {
        $this->fakeEmpty();

        $this->artisan('integrations:sync-trashnothing', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] Would process 0 rating(s), 0 user change(s)')
            ->assertExitCode(0);
    }

    public function test_inserts_new_rating_for_existing_user(): void
    {
        $user = $this->createTestUser();

        Http::fake([
            '*trashnothing.com/fd/api/ratings*' => Http::sequence()
                ->push(['ratings' => [
                    [
                        'ratee_fd_user_id' => $user->id,
                        'rating_id'        => 42,
                        'rating'           => 'Positive',
                        'date'             => '2026-05-01T12:00:00Z',
                    ],
                ]])
                ->push(['ratings' => []]),
            '*trashnothing.com/fd/api/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('integrations:sync-trashnothing')
            ->expectsOutputToContain('Processed 1 rating(s)')
            ->assertExitCode(0);

        $this->assertEquals(1, DB::table('ratings')->where('ratee', $user->id)->where('tn_rating_id', 42)->count());
    }

    public function test_deletes_rating_when_rating_value_is_null(): void
    {
        $user = $this->createTestUser();

        DB::table('ratings')->insert([
            'ratee'        => $user->id,
            'rating'       => 'Positive',
            'timestamp'    => now(),
            'visible'      => 1,
            'tn_rating_id' => 99,
        ]);

        Http::fake([
            '*trashnothing.com/fd/api/ratings*' => Http::sequence()
                ->push(['ratings' => [
                    [
                        'ratee_fd_user_id' => $user->id,
                        'rating_id'        => 99,
                        'rating'           => null,
                        'date'             => '2026-05-01T12:00:00Z',
                    ],
                ]])
                ->push(['ratings' => []]),
            '*trashnothing.com/fd/api/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('integrations:sync-trashnothing')->assertExitCode(0);

        $this->assertEquals(0, DB::table('ratings')->where('ratee', $user->id)->where('tn_rating_id', 99)->count());
    }

    public function test_dry_run_does_not_insert_rating(): void
    {
        $user = $this->createTestUser();

        Http::fake([
            '*trashnothing.com/fd/api/ratings*' => Http::sequence()
                ->push(['ratings' => [
                    [
                        'ratee_fd_user_id' => $user->id,
                        'rating_id'        => 55,
                        'rating'           => 'Positive',
                        'date'             => '2026-05-01T12:00:00Z',
                    ],
                ]])
                ->push(['ratings' => []]),
            '*trashnothing.com/fd/api/user-changes*' => Http::response(['changes' => []], 200),
        ]);

        $this->artisan('integrations:sync-trashnothing', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] Would process 1 rating(s)')
            ->assertExitCode(0);

        $this->assertEquals(0, DB::table('ratings')->where('tn_rating_id', 55)->count());
    }

    public function test_updates_reply_time_for_tn_user(): void
    {
        $user = $this->createTestUser(['email_preferred' => 'alice-g123@user.trashnothing.com']);

        Http::fake([
            '*trashnothing.com/fd/api/ratings*'      => Http::response(['ratings' => []], 200),
            '*trashnothing.com/fd/api/user-changes*' => Http::sequence()
                ->push(['changes' => [
                    [
                        'fd_user_id'  => $user->id,
                        'reply_time'  => 3600,
                        'date'        => '2026-05-01T12:00:00Z',
                    ],
                ]])
                ->push(['changes' => []]),
        ]);

        $this->artisan('integrations:sync-trashnothing')->assertExitCode(0);

        $this->assertEquals(1, DB::table('users_replytime')->where('userid', $user->id)->where('replytime', 3600)->count());
    }

    public function test_skips_non_tn_users_in_user_changes(): void
    {
        $user = $this->createTestUser();

        Http::fake([
            '*trashnothing.com/fd/api/ratings*'      => Http::response(['ratings' => []], 200),
            '*trashnothing.com/fd/api/user-changes*' => Http::sequence()
                ->push(['changes' => [
                    [
                        'fd_user_id'  => $user->id,
                        'reply_time'  => 3600,
                        'date'        => '2026-05-01T12:00:00Z',
                    ],
                ]])
                ->push(['changes' => []]),
        ]);

        $this->artisan('integrations:sync-trashnothing')
            ->expectsOutputToContain('Processed 0 rating(s), 0 user change(s)')
            ->assertExitCode(0);

        $this->assertEquals(0, DB::table('users_replytime')->where('userid', $user->id)->count());
    }
}
