<?php

namespace Tests\Feature\Paf;

use App\Models\PafImport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * paf:import-run against a faked paf-extractor sidecar.
 */
class ImportRunCommandTest extends TestCase
{
    private const EXTRACTOR = 'http://paf-extractor.test';

    private string $postcode;

    private int $udprn;

    /** What the fake extractor's GET /jobs/current answers, in order; the last repeats. */
    private array $states = [];

    private string $delta = '';

    /** What the fake extractor's GET /latest answers; null answers 500. */
    private ?array $latest = null;

    /** GET /latest cannot connect at all. */
    private bool $latestUnreachable = false;

    /** @var Request[] */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'freegle.paf.extractor_url' => self::EXTRACTOR,
            'freegle.paf.batch_size' => 2,
            'freegle.paf.pause_ms' => 0,
        ]);

        $this->postcode = 'ZQ' . random_int(10, 99) . ' ' . random_int(1, 9) . 'ZZ';
        DB::table('locations')->insert([
            'name' => $this->postcode,
            'type' => 'Postcode',
            'osm_place' => 0,
            'osm_amenity' => 0,
            'osm_shop' => 0,
        ]);
        $this->udprn = random_int(900000000, 999999999);

        // Earlier requests in the shared test database would be picked up first.
        PafImport::query()->update(['status' => PafImport::STATUS_DONE]);

        // One fake for every request, keyed on the request, because a second Http::fake()
        // would never be reached.
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'POST') {
                $this->posts[] = $request;
            }

            if ($request->method() === 'POST' && $path === '/jobs') {
                return Http::response(['phase' => 'downloading'], 202);
            }
            if ($path === '/latest') {
                if ($this->latestUnreachable) {
                    throw new ConnectionException('Connection refused');
                }
                return $this->latest ? Http::response($this->latest) : Http::response(['error' => 'AFD unreachable'], 500);
            }
            if ($path === '/jobs/current') {
                $state = count($this->states) > 1 ? array_shift($this->states) : $this->states[0];

                return Http::response($state);
            }
            if (str_ends_with($path, '/delta')) {
                return Http::response($this->delta);
            }
            if (str_ends_with($path, '/commit')) {
                return Http::response(['phase' => 'committed']);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });
    }

    private function line(int $n, ?string $postcode = null): string
    {
        $udprn = $this->udprn + $n;

        return ($postcode ?? $this->postcode) . ",TESTTOWN,,,Test Road,,{$n},,,,,,{$udprn},S, ,1A,1,0,1,2\r\n";
    }

    private function request(array $attrs = []): PafImport
    {
        return PafImport::create(array_merge([
            'status' => PafImport::STATUS_QUEUED,
            'unlock_key' => 'AB1CD2EF3GH4JK5LM6NP7QR8',
            'edition' => 'Y26M08',
        ], $attrs));
    }

    private function readyState(PafImport $import, int $deltaLines): array
    {
        return [
            'id' => $import->id,
            'phase' => 'ready',
            'progress' => 100,
            'totalLines' => 32429019,
            'deltaLines' => $deltaLines,
            'detail' => "{$deltaLines} of 32429019 lines are new or changed",
        ];
    }

    public function test_does_nothing_without_a_request(): void
    {
        $this->artisan('paf:import-run --poll=0')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_runs_a_request_through_to_done(): void
    {
        $import = $this->request();
        $this->delta = $this->line(1) . $this->line(2) . $this->line(3) . $this->line(4, 'ZZ99 9ZZ');
        $this->states = [
            ['id' => $import->id, 'phase' => 'downloading', 'progress' => 50, 'detail' => 'Downloaded 211 of 422 MB'],
            ['id' => $import->id, 'phase' => 'extracting', 'progress' => 40, 'detail' => 'Extracting CSV PAF'],
            ['id' => $import->id, 'phase' => 'comparing', 'progress' => 0, 'detail' => 'Sorting the new release'],
            $this->readyState($import, 4),
        ];

        $this->artisan('paf:import-run --poll=0')->assertExitCode(0);

        $import->refresh();
        $this->assertSame(PafImport::STATUS_DONE, $import->status);
        $this->assertSame(100, $import->progress);
        $this->assertNull($import->unlock_key);
        $this->assertSame(32429019, $import->lines_in_release);
        $this->assertSame(4, $import->lines_to_apply);
        $this->assertSame(4, $import->lines_applied);
        $this->assertSame(3, $import->inserted);
        $this->assertSame(0, $import->updated);
        $this->assertSame(1, $import->skipped);
        $this->assertNotNull($import->started_at);
        $this->assertNotNull($import->finished_at);
        $this->assertSame(3, DB::table('paf_addresses')->whereBetween('udprn', [$this->udprn + 1, $this->udprn + 3])->count());

        // The job was started with the key and edition, and committed with the line whose
        // postcode we do not have, so the extractor offers it again next time.
        $this->assertSame(
            ['id' => $import->id, 'key' => 'AB1CD2EF3GH4JK5LM6NP7QR8', 'edition' => 'Y26M08'],
            $this->posts[0]->data()
        );
        $this->assertSame(rtrim($this->line(4, 'ZZ99 9ZZ')) . "\n", $this->posts[1]->body());
        $this->assertFileDoesNotExist(storage_path("app/paf/delta-{$import->id}.csv"));
    }

    public function test_records_the_extractors_failure(): void
    {
        $import = $this->request();
        $this->states = [[
            'id' => $import->id,
            'phase' => 'failed',
            'error' => 'The key was not accepted for this release. Check it matches the edition.',
        ]];

        $this->artisan('paf:import-run --poll=0')->assertExitCode(0);

        $import->refresh();
        $this->assertSame(PafImport::STATUS_FAILED, $import->status);
        $this->assertStringContainsString('key was not accepted', $import->error);
        $this->assertNull($import->unlock_key);
    }

    public function test_fails_when_the_extractor_has_lost_the_job(): void
    {
        $import = $this->request(['status' => PafImport::STATUS_EXTRACTING]);
        $this->states = [['phase' => 'idle']];

        $this->artisan('paf:import-run --poll=0')->assertExitCode(0);

        $import->refresh();
        $this->assertSame(PafImport::STATUS_FAILED, $import->status);
        $this->assertStringContainsString('no longer has this job', $import->error);
    }

    public function test_waits_while_the_extractor_is_busy_with_another_job(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([self::EXTRACTOR . '/jobs' => Http::response(['error' => 'Job 7 is still extracting'], 409)]);
        $import = $this->request();

        $this->artisan('paf:import-run --poll=0')->assertExitCode(0);

        $import->refresh();
        $this->assertSame(PafImport::STATUS_QUEUED, $import->status);
        $this->assertStringContainsString('Waiting for the extractor', $import->detail);
    }

    public function test_carries_on_from_where_an_earlier_run_stopped(): void
    {
        $import = $this->request([
            'status' => PafImport::STATUS_APPLYING,
            'unlock_key' => null,
            'lines_to_apply' => 3,
            'lines_applied' => 2,
            'inserted' => 2,
        ]);
        $file = storage_path("app/paf/delta-{$import->id}.csv");
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, $this->line(1) . $this->line(2) . $this->line(3));
        $this->states = [$this->readyState($import, 3)];

        $this->artisan('paf:import-run --poll=0')->assertExitCode(0);

        $import->refresh();
        $this->assertSame(PafImport::STATUS_DONE, $import->status);
        $this->assertSame(3, $import->lines_applied);
        $this->assertSame(3, $import->inserted);
        // Only the line not yet applied was applied.
        $this->assertSame(1, DB::table('paf_addresses')->whereBetween('udprn', [$this->udprn + 1, $this->udprn + 3])->count());
        $this->assertFileDoesNotExist($file);
    }

    public function test_records_which_release_the_latest_is_and_marks_the_housekeeping_task_done(): void
    {
        DB::table('housekeeper_tasks')->updateOrInsert(['task_key' => 'paf-database'], [
            'name' => 'PAF Database Update', 'placeholder' => 1, 'interval_hours' => 720,
            'last_run_at' => null, 'last_status' => null, 'last_summary' => null,
        ]);
        $this->latest = ['size' => 440401920, 'published' => '2026-10-03'];
        $import = $this->request(['edition' => null]);
        $this->delta = $this->line(1);
        $this->states = [$this->readyState($import, 1)];

        $this->artisan('paf:import-run --poll=0')->assertExitCode(0);

        $import->refresh();
        $this->assertSame(PafImport::STATUS_DONE, $import->status);
        $this->assertSame('2026-10-03', $import->release_published->format('Y-m-d'));
        $task = DB::table('housekeeper_tasks')->where('task_key', 'paf-database')->first();
        $this->assertSame('success', $task->last_status);
        $this->assertNotNull($task->last_run_at);
        $this->assertSame("Applied by PAF import {$import->id}: 1 new, 0 changed", $task->last_summary);
    }

    public function test_an_import_goes_ahead_when_the_latest_release_cannot_be_read(): void
    {
        $import = $this->request(['edition' => null]);
        $this->states = [['id' => $import->id, 'phase' => 'downloading', 'progress' => 0]];
        $this->states[] = ['id' => $import->id, 'phase' => 'failed', 'error' => 'stop here'];

        $this->artisan('paf:import-run --poll=0');

        $import->refresh();
        $this->assertNull($import->release_published);
        // It got as far as the extractor, which is what failed it.
        $this->assertSame('stop here', $import->error);
    }

    public function test_an_import_goes_ahead_when_the_extractor_cannot_be_reached_for_the_latest_release(): void
    {
        $this->latestUnreachable = true;
        $import = $this->request(['edition' => null]);
        $this->states = [['id' => $import->id, 'phase' => 'failed', 'error' => 'stop here']];

        $this->artisan('paf:import-run --poll=0');

        $import->refresh();
        $this->assertNull($import->release_published);
        // It got as far as the extractor, which is what failed it.
        $this->assertSame('stop here', $import->error);
    }

    public function test_an_archived_release_does_not_ask_for_the_latest(): void
    {
        $import = $this->request();
        $this->states = [['id' => $import->id, 'phase' => 'failed', 'error' => 'stop here']];

        $this->artisan('paf:import-run --poll=0');

        Http::assertNotSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/latest'));
    }
}
