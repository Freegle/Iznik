<?php

namespace Tests\Feature\Location;

use App\Services\PostcodeRemapService;
use Tests\TestCase;

class SyncPgsqlCommandTest extends TestCase
{
    // ── Command tests ─────────────────────────────────────────────────────────

    public function test_runs_cleanly_and_outputs_remapped_count(): void
    {
        $mock = $this->createMock(PostcodeRemapService::class);
        $mock->method('remapPostcodes')->with(null, null)->willReturn(42);
        $this->app->instance(PostcodeRemapService::class, $mock);

        $this->artisan('locations:sync-pgsql')
            ->expectsOutputToContain('Remapped 42 postcodes.')
            ->assertExitCode(0);
    }

    public function test_calls_remap_postcodes_with_no_arguments(): void
    {
        $mock = $this->createMock(PostcodeRemapService::class);
        $mock->expects($this->once())
            ->method('remapPostcodes')
            ->with(null, null)
            ->willReturn(0);
        $this->app->instance(PostcodeRemapService::class, $mock);

        $this->artisan('locations:sync-pgsql')->assertExitCode(0);
    }

    public function test_handles_postgres_unavailable_by_outputting_zero(): void
    {
        $mock = $this->createMock(PostcodeRemapService::class);
        $mock->method('remapPostcodes')->willReturn(0);
        $this->app->instance(PostcodeRemapService::class, $mock);

        $this->artisan('locations:sync-pgsql')
            ->expectsOutputToContain('Remapped 0 postcodes.')
            ->assertExitCode(0);
    }
}
