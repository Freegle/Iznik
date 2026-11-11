<?php

namespace Tests\Feature\Deploy;

use App\Console\Commands\Deploy\CheckLagCommand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckLagCommandTest extends TestCase
{
    private const DEPLOYED = 'd000000000000000000000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        DB::table('cron_job_status')->where('command', CheckLagCommand::JOB)->delete();
        DB::table('config')->upsert([['key' => 'deploy.laravel_commit', 'value' => self::DEPLOYED]], ['key'], ['value']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A production commit merging $master in at $at, newest first in the list. */
    private function autoMerge(string $master, string $at): array
    {
        return [
            'sha' => 'p'.substr($master, 1),
            'parents' => [['sha' => 'prev'], ['sha' => $master]],
            'commit' => ['message' => "Auto-merge master to production - something\n\nbody", 'committer' => ['date' => $at]],
        ];
    }

    private function fake(array $production, array $undeployed): void
    {
        Http::fake([
            'api.github.com/repos/Freegle/Iznik/commits*' => Http::response($production),
            'api.github.com/repos/Freegle/Iznik/compare/*' => Http::response(['commits' => array_map(fn ($s) => ['sha' => $s], $undeployed)]),
        ]);
    }

    private function row(): object
    {
        return DB::table('cron_job_status')->where('command', CheckLagCommand::JOB)->first();
    }

    public function test_up_to_date_records_now(): void
    {
        $this->fake([$this->autoMerge('a1', '2026-10-19T10:00:00Z')], []);

        $this->artisan('deploy:check-lag')->assertSuccessful();

        $this->assertSame('2026-10-20 12:00:00', Carbon::parse($this->row()->last_run_at)->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('includes the latest green master', $this->row()->last_output);
    }

    public function test_behind_records_when_the_oldest_waiting_commit_went_green(): void
    {
        // a3 and a2 went green after the deploy; a1 is deployed.
        $this->fake([
            $this->autoMerge('a3', '2026-10-15T09:00:00Z'),
            ['sha' => 'x', 'parents' => [['sha' => 'y']], 'commit' => ['message' => 'Bump app version', 'committer' => ['date' => '2026-10-14T09:00:00Z']]],
            $this->autoMerge('a2', '2026-10-12T08:30:00Z'),
            $this->autoMerge('a1', '2026-10-10T08:00:00Z'),
        ], ['a2', 'b-unmerged', 'a3']);

        $this->artisan('deploy:check-lag')->assertSuccessful();

        $row = $this->row();
        $this->assertSame('2026-10-12 08:30:00', Carbon::parse($row->last_run_at)->format('Y-m-d H:i:s'));
        $this->assertSame(0, (int) $row->last_exit_code);
        $this->assertStringContainsString('behind green master by 3 commit(s)', $row->last_output);
    }

    public function test_a_github_failure_leaves_the_row_alone(): void
    {
        DB::table('cron_job_status')->insert([
            'command' => CheckLagCommand::JOB, 'last_run_at' => '2026-10-11 00:00:00',
            'last_finished_at' => '2026-10-11 00:00:00', 'last_exit_code' => 0, 'last_output' => 'before', 'updated_at' => now(),
        ]);
        Http::fake(['api.github.com/*' => Http::response('rate limited', 403)]);

        $this->artisan('deploy:check-lag')->assertFailed();

        $this->assertSame('before', $this->row()->last_output);
        $this->assertSame('2026-10-11 00:00:00', Carbon::parse($this->row()->last_run_at)->format('Y-m-d H:i:s'));
    }

    public function test_no_recorded_deploy_fails_without_writing(): void
    {
        DB::table('config')->where('key', 'deploy.laravel_commit')->delete();
        Http::fake();

        $this->artisan('deploy:check-lag')->assertFailed();

        $this->assertNull(DB::table('cron_job_status')->where('command', CheckLagCommand::JOB)->first());
        Http::assertNothingSent();
    }
}
