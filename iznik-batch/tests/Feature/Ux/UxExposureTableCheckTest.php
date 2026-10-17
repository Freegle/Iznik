<?php

namespace Tests\Feature\Ux;

use App\Models\UxExperimentExposure;
use App\Services\LokiService;
use App\Services\UserDataExportService;
use App\Services\UserManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LagsTheReadNode;
use Tests\TestCase;

/**
 * An erasure, a merge or an export asks whether ux_experiment_exposures exists so that it never depends on an
 * analytics table the SQL may not have made yet. That question has to go to the write node.
 * Schema::hasTable goes to the read node, and a read node that is behind (db2 while it donates the nightly
 * backup) answers "no" for a table the write node has just made. The delete is then skipped without an error
 * and the member's rows stay until the 90 day purge.
 *
 * Each test here gives the code a connection that is not in a transaction, whose reads go to a database that
 * has not got the table. A test's own connection is in a transaction and so reads from the write node, which
 * is why the other tests of these paths cannot see it.
 */
class UxExposureTableCheckTest extends TestCase
{
    use LagsTheReadNode;

    /** A member id no fixture uses. The tests that write through the lagging connection clean up after themselves. */
    private const MEMBER = 9100000001;

    public function test_the_table_is_there_for_the_check_that_asks_the_write_node(): void
    {
        $this->assertTrue(UxExperimentExposure::tableExists());
    }

    public function test_a_read_node_that_is_behind_says_no_and_the_write_node_says_yes(): void
    {
        $this->withLaggingReadNode([], function (string $lag) {
            $this->assertFalse(Schema::connection($lag)->hasTable('ux_experiment_exposures'), 'Laravel asks the read node, which has not applied the SQL');
            $this->assertTrue(UxExperimentExposure::tableExists($lag), 'the write node has the table');
        });
    }

    public function test_forgetting_a_member_deletes_the_exposure_rows_when_the_read_node_is_behind(): void
    {
        $this->withLaggingReadNode(['users_emails', 'messages', 'chat_prompts'], function (string $lag) {
            $this->seedExposures($lag);
            DB::setDefaultConnection($lag);

            try {
                (new UserManagementService($this->createMock(LokiService::class)))->forgetUser(self::MEMBER, 'Asked to be forgotten');
                $left = $this->exposureRows($lag);
            } finally {
                DB::setDefaultConnection('mysql');
                $this->cleanUp($lag);
            }

            $this->assertSame(0, $left, 'the rows are on the write node and the erasure has to find them');
        });
    }

    public function test_an_export_lists_the_exposure_rows_when_the_read_node_is_behind(): void
    {
        $this->withLaggingReadNode([], function (string $lag) {
            $this->seedExposures($lag);
            DB::setDefaultConnection($lag);

            try {
                $method = new \ReflectionMethod(UserDataExportService::class, 'getExperiments');
                $rows = $method->invoke(new UserDataExportService, self::MEMBER);
            } finally {
                DB::setDefaultConnection('mysql');
                $this->cleanUp($lag);
            }

            $this->assertCount(2, $rows, 'an export that leaves rows out is not the member\'s data');
            $this->assertSame(['experiment', 'arm', 'subject_type', 'exposed_at'], array_keys($rows[0]));
        });
    }

    public function test_no_code_asks_the_read_node_whether_the_table_exists(): void
    {
        $found = [];
        $files = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $files++;
            if (preg_match('/Schema::(?:connection\([^)]*\)->)?hasTable\(\s*[\'"]ux_experiment_exposures[\'"]/', (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = substr($file->getPathname(), strlen(base_path()) + 1);
            }
        }

        $this->assertGreaterThan(100, $files, 'the scan found the tree: an empty one would check nothing');
        $this->assertSame([], $found, 'ask UxExperimentExposure::tableExists(), which asks the write node');
    }

    private function seedExposures(string $connection): void
    {
        DB::connection($connection)->table('ux_experiment_exposures')->where('userid', self::MEMBER)->delete();
        foreach ([1, 2] as $subject) {
            DB::connection($connection)->table('ux_experiment_exposures')->insert([
                'experiment' => 'lag-test', 'arm' => 'items', 'userid' => self::MEMBER, 'subject_type' => 'email',
                'subject_id' => $subject, 'exposed_at' => now()->utc()->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function exposureRows(string $connection): int
    {
        return DB::connection($connection)->table('ux_experiment_exposures')->useWritePdo()->where('userid', self::MEMBER)->count();
    }

    /** What the erasure wrote through the lagging connection was committed, so it is taken out by hand. */
    private function cleanUp(string $connection): void
    {
        $db = DB::connection($connection);
        $db->table('ux_experiment_exposures')->where('userid', self::MEMBER)->delete();
        $db->table('logs')->where('user', self::MEMBER)->where('type', 'User')->where('subtype', 'Deleted')->delete();
        $db->table('users_deletions')->where('userid', self::MEMBER)->delete();
    }
}
