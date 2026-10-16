<?php

namespace Tests\Feature\Ux;

use App\Models\User;
use App\Models\UxExperimentExposure;
use App\Services\LokiService;
use App\Services\UserDataExportService;
use App\Services\UserManagementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ux_experiment_exposures is keyed by member, so it is part of forgetting a member (both ways an
 * account is forgotten), merging two accounts, exporting a member's data and dumping it for support.
 * Every one of them must also work on a database the SQL has not been run on: an erasure, a merge or
 * an export must never depend on an analytics table.
 */
class UxExposureErasureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function exposure(int $userId, int $subjectId = 1, string $experiment = 'erase-test', string $arm = 'items'): void
    {
        DB::table('ux_experiment_exposures')->insert([
            'experiment' => $experiment, 'arm' => $arm, 'userid' => $userId, 'subject_type' => 'email',
            'subject_id' => $subjectId, 'stratum' => null, 'exposed_at' => '2026-10-19 08:00:00', 'detail' => null,
        ]);
    }

    private function rowsFor(int $userId): int
    {
        return DB::table('ux_experiment_exposures')->where('userid', $userId)->count();
    }

    // ---------------------------------------------------------------- a removed TrashNothing account (User::forget)

    public function test_a_removed_account_loses_its_exposures_and_nobody_elses(): void
    {
        $gone = $this->createTestUser();
        $other = $this->createTestUser();
        $this->exposure($gone->id, 1);
        $this->exposure($gone->id, 2);
        $this->exposure($other->id, 1);

        $gone->forget('TN account removed');

        $this->assertSame(0, $this->rowsFor($gone->id));
        $this->assertSame(1, $this->rowsFor($other->id));
    }

    public function test_a_dry_run_removal_keeps_the_exposures(): void
    {
        $user = $this->createTestUser();
        $this->exposure($user->id);

        $user->forget('TN account removed', true);

        $this->assertSame(1, $this->rowsFor($user->id));
    }

    // ---------------------------------------------------------------- a forgotten member (UserManagementService)

    private function service(): UserManagementService
    {
        return new UserManagementService($this->createMock(LokiService::class));
    }

    public function test_forgetting_a_member_deletes_their_exposures_and_nobody_elses(): void
    {
        $gone = $this->createTestUser();
        $other = $this->createTestUser();
        $this->exposure($gone->id, 1);
        $this->exposure($gone->id, 2, 'another-test');
        $this->exposure($other->id, 1);

        $this->service()->forgetUser($gone->id, 'Asked to be forgotten');

        $this->assertSame(0, $this->rowsFor($gone->id));
        $this->assertSame(1, $this->rowsFor($other->id));
    }

    // ---------------------------------------------------------------- merging two accounts

    public function test_a_merge_moves_the_exposures_to_the_surviving_account(): void
    {
        $survivor = $this->createTestUser();
        $gone = $this->createTestUser();
        $this->exposure($gone->id, 1);
        $this->exposure($gone->id, 2);
        $this->exposure($survivor->id, 3);

        $this->assertTrue(User::merge($survivor->id, $gone->id, 'Test merge'));

        $this->assertSame(3, $this->rowsFor($survivor->id));
        $this->assertSame(0, $this->rowsFor($gone->id));
    }

    public function test_a_merge_leaves_a_row_that_clashes_where_it_is_without_an_error(): void
    {
        $survivor = $this->createTestUser();
        $gone = $this->createTestUser();
        // The survivor already has a row for this email, so the other account's row cannot move.
        $this->exposure($survivor->id, 1);
        $this->exposure($gone->id, 1);
        $this->exposure($gone->id, 2);

        $this->assertTrue(User::merge($survivor->id, $gone->id, 'Test merge'));

        $this->assertSame(2, $this->rowsFor($survivor->id), 'the row that could move did');
        $this->assertSame(1, $this->rowsFor($gone->id), 'the clashing row stayed');
    }

    public function test_a_dry_run_merge_moves_nothing(): void
    {
        $survivor = $this->createTestUser();
        $gone = $this->createTestUser();
        $this->exposure($gone->id, 1);

        User::merge($survivor->id, $gone->id, 'Test merge', false, null, true);

        $this->assertSame(1, $this->rowsFor($gone->id));
        $this->assertSame(0, $this->rowsFor($survivor->id));
    }

    public function test_the_model_is_a_plain_one_that_does_not_audit(): void
    {
        $this->assertNotContains(\OwenIt\Auditing\Contracts\Auditable::class, class_implements(UxExperimentExposure::class));
        $this->assertSame('ux_experiment_exposures', (new UxExperimentExposure)->getTable());
    }

    // ---------------------------------------------------------------- the data export

    private function exportFor(User $user): array
    {
        $exportId = DB::table('users_exports')->insertGetId(['userid' => $user->id, 'tag' => 'test-tag', 'requested' => now()]);
        (new UserDataExportService)->processAll();
        $export = DB::table('users_exports')->where('id', $exportId)->first();

        return json_decode(gzinflate($export->data), true);
    }

    public function test_the_export_lists_which_arm_each_send_used_without_the_ids_of_the_things(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        $this->exposure($user->id, 31, 'digest-subject', 'items');
        $this->exposure($user->id, 32, 'digest-subject', 'control');
        $other = $this->createTestUser();
        $this->exposure($other->id, 33, 'digest-subject', 'items');

        $json = $this->exportFor($user);

        $this->assertCount(2, $json['experiments']);
        $this->assertSame(['arm', 'experiment', 'exposed_at', 'subject_type'], $this->sortedKeys($json['experiments'][0]));
        $this->assertSame('items', $json['experiments'][0]['arm']);
        $this->assertSame('control', $json['experiments'][1]['arm']);
        $this->assertSame('email', $json['experiments'][0]['subject_type']);
    }

    public function test_the_export_has_an_empty_list_for_a_member_with_none(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);

        $this->assertSame([], $this->exportFor($user)['experiments']);
    }

    /** @return string[] */
    private function sortedKeys(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);

        return $keys;
    }

    // ---------------------------------------------------------------- the support dump (user:dump)

    private function dumpFor(User $user): array
    {
        $path = sys_get_temp_dir().'/uxexposure-dump-'.$user->id.'.json';
        $this->artisan('user:dump', ['--id' => $user->id, '--output' => $path])->assertExitCode(0);
        $dump = json_decode((string) file_get_contents($path), true);
        unlink($path);

        return $dump;
    }

    public function test_the_support_dump_carries_the_exposures(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        $this->exposure($user->id, 31);

        $dump = $this->dumpFor($user);

        $this->assertCount(1, $dump['tables']['ux_experiment_exposures']);
        $this->assertSame('items', $dump['tables']['ux_experiment_exposures'][0]['arm']);
    }
}
