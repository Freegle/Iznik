<?php

namespace Tests\Feature\Command;

use Tests\TestCase;

class ClearAllCachesCommandTest extends TestCase
{
    /**
     * Test that clear:all shows the deprecation warning.
     *
     * Note: We don't mock Artisan::call() as it causes "removed error handlers"
     * warnings affecting all tests. The actual cache operations run, which is safe.
     */
    public function test_shows_deprecation_warning(): void
    {
        $this->artisan('clear:all')
            ->expectsOutput('clear:all is deprecated. Use deploy:refresh instead.')
            ->assertSuccessful();
    }

    public function test_delegates_to_deploy_refresh(): void
    {
        $this->artisan('clear:all')
            ->expectsOutput('Refreshing application after deployment...')
            ->assertSuccessful();
    }
}
