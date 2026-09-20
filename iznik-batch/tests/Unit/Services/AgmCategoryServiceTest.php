<?php

namespace Tests\Unit\Services;

use App\Services\AgmCategoryService;
use App\Services\DiscourseClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The AGM category is created open (everyone may reply, only staff and
 * Announcers may start topics), announced separately once the information posts
 * exist, and closed to read-only afterwards.
 *
 * The behaviour worth guarding is the backfill: Discourse only applies
 * `default_categories_watching` to existing users when the setting's value
 * actually changes, so re-sending an unchanged value silently touches nobody.
 */
class AgmCategoryServiceTest extends TestCase
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

    private function service(): AgmCategoryService
    {
        return new AgmCategoryService(new DiscourseClient());
    }

    private function fakeCategoryMissing(): void
    {
        Http::fake([
            'discourse.test/c/*/find_by_slug.json*' => Http::response(['errors' => ['Not found']], 404),
            'discourse.test/categories.json' => Http::response(['category' => ['id' => 42]], 200),
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function fakeCategoryExists(string $watching = '7', array $extra = []): void
    {
        Http::fake(array_merge([
            'discourse.test/c/*/find_by_slug.json*' => Http::response([
                'category' => ['id' => 18, 'name' => 'AGM 2026', 'color' => '0088CC', 'text_color' => 'FFFFFF'],
            ], 200),
            'discourse.test/admin/site_settings.json*' => Http::response([
                'site_settings' => [['setting' => 'default_categories_watching', 'value' => $watching]],
            ], 200),
            'discourse.test/admin/site_settings/*' => Http::response('', 204),
            'discourse.test/categories/*' => Http::response(['category' => ['id' => 18]], 200),
        ], $extra));
    }

    public function test_setup_skips_when_api_key_absent(): void
    {
        config(['freegle.discourse.api_key' => '']);
        Http::fake();

        $this->assertTrue($this->service()->setup(2027)['skipped']);
        Http::assertNothingSent();
    }

    public function test_setup_creates_category_everyone_can_reply_to_but_not_start(): void
    {
        $this->fakeCategoryMissing();

        $result = $this->service()->setup(2027);

        $this->assertTrue($result['created']);
        $this->assertSame(42, $result['categoryId']);
        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST' || !str_contains($request->url(), '/categories.json')) {
                return false;
            }

            $body = urldecode($request->body());

            return str_contains($body, 'name=AGM 2027')
                && str_contains($body, 'slug=agm-2027')
                && str_contains($body, 'permissions[everyone]=2')
                && str_contains($body, 'permissions[staff]=1')
                && str_contains($body, 'permissions[Announcers]=1')
                && str_contains($body, 'description=Watching AGM 2027 is on by default.');
        });
    }

    public function test_setup_does_not_switch_watching_on(): void
    {
        // Watching must stay off until the information posts exist, otherwise
        // the first draft post notifies every user on the forum.
        $this->fakeCategoryMissing();

        $this->service()->setup(2027);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'site_settings'));
    }

    public function test_setup_reapplies_to_an_existing_category_rather_than_duplicating_it(): void
    {
        $this->fakeCategoryExists();

        $result = $this->service()->setup(2026);

        $this->assertFalse($result['created']);
        $this->assertSame(18, $result['categoryId']);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/categories.json'));
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_contains($r->url(), '/categories/18.json')
            && str_contains(urldecode($r->body()), 'permissions[everyone]=2'));
    }

    public function test_announce_appends_the_category_and_backfills_existing_users(): void
    {
        $this->fakeCategoryExists(watching: '7');

        $result = $this->service()->announce(2026);

        $this->assertTrue($result['backfilled']);
        $this->assertSame('7|18', $result['value']);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/admin/site_settings/default_categories_watching')
                && $request['default_categories_watching'] === '7|18'
                && $request['update_existing_user'] === 'true';
        });
    }

    public function test_announce_preserves_other_watched_categories(): void
    {
        $this->fakeCategoryExists(watching: '7|16');

        $this->assertSame('7|16|18', $this->service()->announce(2026)['value']);
    }

    public function test_announce_does_nothing_when_already_watching(): void
    {
        // Re-sending an unchanged value backfills nobody, so claiming success
        // would be a lie. Report it instead.
        $this->fakeCategoryExists(watching: '7|18');

        $result = $this->service()->announce(2026);

        $this->assertTrue($result['alreadyWatching']);
        $this->assertFalse($result['backfilled']);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), 'site_settings'));
    }

    public function test_announce_force_removes_then_readds_so_the_backfill_actually_runs(): void
    {
        $this->fakeCategoryExists(watching: '7|18');

        $result = $this->service()->announce(2026, force: true);

        $this->assertTrue($result['backfilled']);

        // First write drops the category (no backfill needed) so the second is a
        // real change that Discourse will apply to existing users.
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_contains($r->url(), 'site_settings')
            && $r['default_categories_watching'] === '7'
            && !isset($r['update_existing_user']));
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_contains($r->url(), 'site_settings')
            && $r['default_categories_watching'] === '7|18'
            && $r['update_existing_user'] === 'true');
    }

    public function test_announce_fails_when_the_category_does_not_exist(): void
    {
        $this->fakeCategoryMissing();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/discourse:agm setup/');

        $this->service()->announce(2099);
    }

    public function test_close_unwatches_with_backfill_and_makes_it_read_only(): void
    {
        $this->fakeCategoryExists(watching: '7|18');

        $result = $this->service()->close(2026);

        $this->assertTrue($result['wasWatching']);
        $this->assertSame('7', $result['value']);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_contains($r->url(), 'site_settings')
            && $r['default_categories_watching'] === '7'
            && $r['update_existing_user'] === 'true');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_contains($r->url(), '/categories/18.json')
            && str_contains(urldecode($r->body()), 'permissions[everyone]=3')
            && str_contains(urldecode($r->body()), 'permissions[staff]=1'));
    }

    public function test_close_still_locks_down_when_it_was_never_watched(): void
    {
        $this->fakeCategoryExists(watching: '7');

        $result = $this->service()->close(2026);

        $this->assertFalse($result['wasWatching']);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), 'site_settings'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/categories/18.json'));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->fakeCategoryExists(watching: '7');

        $setup = $this->service()->setup(2026, dryRun: true);
        $announce = $this->service()->announce(2026, dryRun: true);
        $close = $this->service()->close(2026, dryRun: true);

        $this->assertTrue($setup['dryRun']);
        $this->assertSame('7|18', $announce['value']);
        $this->assertSame('7', $close['value']);
        Http::assertNotSent(fn (Request $r) => in_array($r->method(), ['POST', 'PUT'], true));
    }
}
