<?php

namespace Tests\Feature\Discourse;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for discourse:agm, which sets up, announces and closes the annual AGM
 * category. Driven end to end through the real AgmCategoryService with the
 * Discourse HTTP calls faked, so the command's reporting and exit codes are
 * asserted against the same behaviour the service actually produces.
 *
 * The reporting matters as much as the writes here: announce is expected to say
 * plainly when it did nothing, because Discourse only backfills existing users
 * when the setting's value actually changes.
 */
class AgmCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'freegle.discourse.url' => 'https://discourse.test',
            'freegle.discourse.api_key' => 'test-key',
            'freegle.discourse.api_username' => 'system',
            'freegle.discourse.max_retries' => 3,
            'freegle.discourse.retry_delay_s' => 0,
            'freegle.discourse.agm.announcers_group' => 'Announcers',
            'freegle.discourse.agm.colour' => '0088CC',
            'freegle.discourse.agm.text_colour' => 'FFFFFF',
            'freegle.discourse.agm.description' => 'Watching :name is on by default.',
        ]);
    }

    private function fakeMissing(): void
    {
        Http::fake([
            'discourse.test/c/*/find_by_slug.json*' => Http::response(['errors' => ['Not found']], 404),
            'discourse.test/categories.json' => Http::response(['category' => ['id' => 42]], 200),
        ]);
    }

    private function fakeExisting(string $watching = '7'): void
    {
        Http::fake([
            'discourse.test/c/*/find_by_slug.json*' => Http::response([
                'category' => ['id' => 18, 'name' => 'AGM 2026', 'color' => '0088CC', 'text_color' => 'FFFFFF'],
            ], 200),
            'discourse.test/admin/site_settings.json*' => Http::response([
                'site_settings' => [['setting' => 'default_categories_watching', 'value' => $watching]],
            ], 200),
            'discourse.test/admin/site_settings/*' => Http::response('', 204),
            'discourse.test/categories/*' => Http::response(['category' => ['id' => 18]], 200),
        ]);
    }

    public function test_setup_reports_the_new_category_and_the_next_step(): void
    {
        $this->fakeMissing();

        $this->artisan('discourse:agm', ['action' => 'setup', '--year' => 2027])
            ->expectsOutputToContain('Created category "AGM 2027"')
            ->expectsOutputToContain('reply / see')
            ->expectsOutputToContain('discourse:agm announce --year=2027')
            ->assertExitCode(0);
    }

    public function test_setup_on_an_existing_category_says_the_description_is_left_alone(): void
    {
        $this->fakeExisting();

        $this->artisan('discourse:agm', ['action' => 'setup', '--year' => 2026])
            ->expectsOutputToContain('Re-applied settings to existing')
            ->expectsOutputToContain('Description left as-is')
            ->assertExitCode(0);
    }

    public function test_announce_reports_the_new_setting_value_and_the_backfill(): void
    {
        $this->fakeExisting(watching: '7');

        $this->artisan('discourse:agm', ['action' => 'announce', '--year' => 2026])
            ->expectsOutputToContain('default_categories_watching = "7|18"')
            ->expectsOutputToContain('Existing users backfilled')
            ->assertExitCode(0);
    }

    public function test_announce_says_plainly_when_it_did_nothing(): void
    {
        // Re-sending an unchanged value backfills nobody, so the command must
        // not imply it worked. It points at --force instead.
        $this->fakeExisting(watching: '7|18');

        $this->artisan('discourse:agm', ['action' => 'announce', '--year' => 2026])
            ->expectsOutputToContain('is already in')
            ->expectsOutputToContain('nothing happened')
            ->expectsOutputToContain('--force')
            ->assertExitCode(0);
    }

    public function test_close_reports_the_unwatch_and_the_locked_down_permissions(): void
    {
        $this->fakeExisting(watching: '7|18');

        $this->artisan('discourse:agm', ['action' => 'close', '--year' => 2026])
            ->expectsOutputToContain('Closed the AGM 2026 category')
            ->expectsOutputToContain('Watching rows for this category removed')
            ->expectsOutputToContain('see only')
            ->expectsOutputToContain('Topics are kept')
            ->assertExitCode(0);
    }

    public function test_close_reports_when_there_was_nothing_to_unwatch(): void
    {
        $this->fakeExisting(watching: '7');

        $this->artisan('discourse:agm', ['action' => 'close', '--year' => 2026])
            ->expectsOutputToContain('nothing to unwatch')
            ->assertExitCode(0);
    }

    public function test_dry_run_says_nothing_was_written_and_writes_nothing(): void
    {
        $this->fakeExisting(watching: '7');

        $this->artisan('discourse:agm', ['action' => 'announce', '--year' => 2026, '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        Http::assertNotSent(fn ($r) => in_array($r->method(), ['POST', 'PUT'], true));
    }

    public function test_an_unknown_action_fails_without_calling_discourse(): void
    {
        Http::fake();

        $this->artisan('discourse:agm', ['action' => 'wibble'])
            ->expectsOutputToContain('Unknown action')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_a_missing_category_fails_with_a_usable_message(): void
    {
        $this->fakeMissing();

        $this->artisan('discourse:agm', ['action' => 'announce', '--year' => 2099])
            ->expectsOutputToContain('does not exist')
            ->assertExitCode(1);
    }

    public function test_it_skips_when_no_api_key_is_configured(): void
    {
        config(['freegle.discourse.api_key' => '']);
        Http::fake();

        $this->artisan('discourse:agm', ['action' => 'setup'])
            ->expectsOutputToContain('not configured')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }
}
