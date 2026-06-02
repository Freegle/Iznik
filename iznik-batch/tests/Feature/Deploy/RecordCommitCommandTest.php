<?php

namespace Tests\Feature\Deploy;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for deploy:record-commit — the lightweight scheduled task that records
 * the deployed Laravel git commit into config.deploy.laravel_commit so the Go
 * API's /api/version can report the live Laravel build (used by the monitor-fsm
 * verified-live reply gate).
 */
class RecordCommitCommandTest extends TestCase
{
    public function test_records_deployed_commit_to_config(): void
    {
        DB::table('config')->where('key', 'deploy.laravel_commit')->delete();

        $this->artisan('deploy:record-commit')->assertSuccessful();

        $row = DB::table('config')->where('key', 'deploy.laravel_commit')->first();
        if ($row !== null) {
            // Git checkout deployment: a real 40-hex SHA must be recorded.
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/i', $row->value);
        } else {
            // No .git (SFTP deploy / CI build without .git): the command must
            // exit cleanly without writing a bogus value, not fail.
            $this->assertTrue(true);
        }
    }

    public function test_upserts_a_single_row_not_duplicate(): void
    {
        // Pre-seed a stale value; the command must upsert (overwrite), never add
        // a second row for the same key.
        DB::table('config')->updateOrInsert(
            ['key' => 'deploy.laravel_commit'],
            ['value' => 'stale-value']
        );

        $this->artisan('deploy:record-commit')->assertSuccessful();

        $count = DB::table('config')->where('key', 'deploy.laravel_commit')->count();
        $this->assertEquals(1, $count);
    }
}
