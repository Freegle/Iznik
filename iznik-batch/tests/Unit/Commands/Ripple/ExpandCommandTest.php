<?php

namespace Tests\Unit\Commands\Ripple;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExpandCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Rippling ships dark; enable it so the command exercises the real engine path.
        config(['freegle.ripple.enabled' => true]);
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM messages_spatial');
    }

    public function test_command_runs_clean_and_reports(): void
    {
        Http::fake(); // no spatial posts → no routing calls, but be safe

        $this->artisan('ripple:expand', ['--dry-run' => true, '--limit' => 10])
            ->expectsOutputToContain('Initialised:')
            ->assertExitCode(0);
    }
}
