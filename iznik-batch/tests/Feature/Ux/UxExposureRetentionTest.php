<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\UxExperimentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakesUxLoki;
use Tests\Support\HidesExposureTable;
use Tests\TestCase;

/**
 * How long exposure rows are kept, and what a restart does to them: the hourly tick deletes rows
 * older than 90 days, an experiment at a time and in batches, and a restart starts a name again
 * with none of the last run's rows.
 */
class UxExposureRetentionTest extends TestCase
{
    use FakesUxLoki;
    use HidesExposureTable;

    private UxExperimentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->fakeLoki();
        foreach (['ux_experiment_reads', 'ux_experiments', 'ux_flow_outcome_daily'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->delete();
        $this->service = app(UxExperimentService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function expose(string $name, int $userId, int $subjectId, string $when): void
    {
        DB::table('ux_experiment_exposures')->insert([
            'experiment' => $name, 'arm' => 'items', 'userid' => $userId, 'subject_type' => 'email',
            'subject_id' => $subjectId, 'stratum' => null, 'exposed_at' => $when, 'detail' => null,
        ]);
    }

    private function rowsFor(string $name): int
    {
        return DB::table('ux_experiment_exposures')->where('experiment', $name)->count();
    }

    public function test_the_tick_deletes_rows_older_than_ninety_days_and_keeps_the_rest(): void
    {
        // 90 days before 10:00 on the 20th is 10:00 on 22 July. A row at that second stays.
        $this->expose('ret-a', 1, 1, '2026-07-22 09:59:59');
        $this->expose('ret-a', 1, 2, '2026-07-22 10:00:00');
        $this->expose('ret-a', 1, 3, '2026-10-19 10:00:00');
        $this->expose('ret-b', 2, 1, '2026-01-01 00:00:00');

        $this->service->tick(Carbon::now('UTC'));

        $this->assertSame(2, $this->rowsFor('ret-a'));
        $this->assertSame(0, DB::table('ux_experiment_exposures')->where('experiment', 'ret-a')->where('subject_id', 1)->count(), 'the row a second older went');
        $this->assertSame(0, $this->rowsFor('ret-b'), 'every experiment is purged, not just one');
    }

    public function test_the_purge_goes_on_in_batches_until_none_are_left(): void
    {
        // More than one batch of 5000.
        $rows = [];
        for ($i = 1; $i <= 5003; $i++) {
            $rows[] = [
                'experiment' => 'ret-big', 'arm' => 'control', 'userid' => $i, 'subject_type' => 'email',
                'subject_id' => $i, 'stratum' => null, 'exposed_at' => '2026-01-01 00:00:00', 'detail' => null,
            ];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('ux_experiment_exposures')->insert($chunk);
        }
        $this->expose('ret-big', 99999, 1, '2026-10-19 00:00:00');

        $this->service->tick(Carbon::now('UTC'));

        $this->assertSame(1, $this->rowsFor('ret-big'), 'only the recent row is left');
    }

    public function test_a_tick_with_no_exposures_is_quiet(): void
    {
        $lines = $this->service->tick(Carbon::now('UTC'));

        $this->assertSame([], $lines);
    }

    public function test_a_missing_table_does_not_stop_the_tick(): void
    {
        Log::spy();

        $lines = $this->withoutExposureTable(fn () => $this->service->tick(Carbon::now('UTC')));

        $this->assertSame([], $lines);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains((string) $m, 'could not purge old exposures'))->once();
    }

    // ---------------------------------------------------------------- restart

    private function attrs(string $name): array
    {
        return [
            'flow' => 'give', 'name' => $name, 'title' => 'A test', 'hypothesis' => 'People leave at photos.',
            'change' => 'A plain link.', 'expected' => 'More people finish.',
        ];
    }

    public function test_a_restart_deletes_that_names_exposures_and_nobody_elses(): void
    {
        $id = $this->service->create($this->attrs('ret-restart'));
        $this->service->start($id);
        $this->service->stop($id);
        $this->expose('ret-restart', 1, 1, '2026-10-20 09:00:00');
        $this->expose('ret-restart', 2, 5, '2026-10-20 09:30:00');
        $this->expose('ret-other', 1, 1, '2026-10-20 09:00:00');

        $this->service->start($id);

        $this->assertSame(0, $this->rowsFor('ret-restart'), 'the new run does not count the last run\'s members');
        $this->assertSame(1, $this->rowsFor('ret-other'));
    }

    public function test_a_same_day_push_row_does_not_hold_the_key_in_the_new_run(): void
    {
        $day = intdiv(Carbon::now()->getTimestamp(), 86400);
        $id = $this->service->create($this->attrs('ret-push'));
        $this->service->start($id);
        $this->service->stop($id);
        DB::table('ux_experiment_exposures')->insert([
            'experiment' => 'ret-push', 'arm' => 'items', 'userid' => 5, 'subject_type' => 'push',
            'subject_id' => $day, 'stratum' => null, 'exposed_at' => '2026-10-20 07:00:00', 'detail' => null,
        ]);

        $this->service->start($id);
        \App\Services\Ux\UxArm::expose(5, 'ret-push', 'control', 'push', $day);

        $row = DB::table('ux_experiment_exposures')->where('experiment', 'ret-push')->first();
        $this->assertSame('control', $row->arm, 'the new run\'s row, not the old one');
    }

    public function test_the_first_start_of_a_draft_leaves_exposures_alone(): void
    {
        $id = $this->service->create($this->attrs('ret-draft'));
        $this->expose('ret-draft', 1, 1, '2026-10-20 09:00:00');

        $this->service->start($id);

        $this->assertSame(1, $this->rowsFor('ret-draft'));
    }

    public function test_a_missing_table_does_not_stop_a_restart(): void
    {
        $id = $this->service->create($this->attrs('ret-missing'));
        $this->service->start($id);
        $this->service->stop($id);
        Log::spy();

        $exp = $this->withoutExposureTable(fn () => $this->service->start($id));

        $this->assertSame('running', $exp->status);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains((string) $m, 'could not clear the old exposures'))->once();
    }
}
