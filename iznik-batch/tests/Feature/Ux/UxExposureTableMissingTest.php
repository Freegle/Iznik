<?php

namespace Tests\Feature\Ux;

use App\Models\User;
use App\Models\UxExperimentExposure;
use App\Services\LokiService;
use App\Services\UserDataExportService;
use App\Services\UserManagementService;
use Illuminate\Support\Facades\DB;
use Tests\Support\HidesExposureTable;
use Tests\TestCase;

/**
 * The same erasure, merge, export and dump paths as UxExposureErasureTest, on a database the SQL
 * has not been run on: ux_experiment_exposures is gone for every test in this class. An erasure, a
 * merge or an export must never depend on an analytics table, and the batch can be live before the
 * SQL is run (docs/developers/reference/ux-experiments.md).
 *
 * The table is hidden straight after the application boots and before the test reads anything, so
 * the test's transaction has no snapshot of it yet and Schema::hasTable sees the table as missing.
 */
class UxExposureTableMissingTest extends TestCase
{
    use HidesExposureTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hideExposureTable();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            // After the transaction has been rolled back, so no lock is held on the renamed table.
            $this->restoreExposureTable();
        }
    }

    public function test_the_table_really_is_missing_for_these_tests(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('ux_experiment_exposures'));
        $this->assertFalse(UxExperimentExposure::tableExists(), 'and the check that asks the write node agrees');
    }

    public function test_a_removed_account_is_forgotten_even_when_the_table_is_missing(): void
    {
        $user = $this->createTestUser();

        $user->forget('TN account removed');

        $this->assertNotNull(DB::table('users')->where('id', $user->id)->value('forgotten'));
    }

    public function test_a_dry_run_removal_is_quiet_when_the_table_is_missing(): void
    {
        $user = $this->createTestUser();

        $user->forget('TN account removed', true);

        $this->assertNull(DB::table('users')->where('id', $user->id)->value('forgotten'));
    }

    public function test_forgetting_a_member_works_when_the_table_is_missing(): void
    {
        $user = $this->createTestUser();

        (new UserManagementService($this->createMock(LokiService::class)))->forgetUser($user->id, 'Asked to be forgotten');

        $this->assertNotNull(DB::table('users')->where('id', $user->id)->value('forgotten'));
    }

    public function test_a_merge_works_when_the_table_is_missing(): void
    {
        $survivor = $this->createTestUser();
        $gone = $this->createTestUser();

        $this->assertTrue(User::merge($survivor->id, $gone->id, 'Test merge'));

        $this->assertSame(0, DB::table('users')->where('id', $gone->id)->count(), 'the merged account went');
    }

    public function test_the_export_is_made_when_the_table_is_missing(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        $exportId = DB::table('users_exports')->insertGetId(['userid' => $user->id, 'tag' => 'test-tag', 'requested' => now()]);

        (new UserDataExportService)->processAll();

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $json = json_decode(gzinflate($export->data), true);
        $this->assertSame([], $json['experiments']);
        $this->assertSame($user->id, $json['basic']['id'], 'and the rest of the export is intact');
    }

    public function test_the_support_dump_skips_a_missing_table_instead_of_failing(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        $path = sys_get_temp_dir().'/uxexposure-missing-dump-'.$user->id.'.json';

        $this->artisan('user:dump', ['--id' => $user->id, '--output' => $path])
            ->expectsOutputToContain('ux_experiment_exposures: no such table, skipped')
            ->assertExitCode(0);

        $dump = json_decode((string) file_get_contents($path), true);
        unlink($path);
        $this->assertArrayNotHasKey('ux_experiment_exposures', $dump['tables']);
        $this->assertArrayHasKey('memberships', $dump['tables'], 'the other tables are still dumped');
    }
}
