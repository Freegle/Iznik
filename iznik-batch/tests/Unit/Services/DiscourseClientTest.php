<?php

namespace Tests\Unit\Services;

use App\Services\DiscourseClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Discourse REST client mirrors V1 Utils::curlWithRetry + the per-call
 * error check the V1 callers did: retry on rate-limiting (HTTP 429 or an
 * "errors" body containing "too many"), throw on persistent rate-limiting or
 * any other error response. The earlier port swallowed these, so a throttled
 * getUser silently dropped that Discourse user and falsely flagged the groups
 * they moderate as NOT REPRESENTED.
 */
class DiscourseClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'freegle.discourse.url' => 'https://discourse.test',
            'freegle.discourse.api_key' => 'test-key',
            'freegle.discourse.api_username' => 'system',
            'freegle.discourse.max_retries' => 5,
            'freegle.discourse.retry_delay_s' => 0, // don't sleep in tests
        ]);
    }

    public function test_get_user_retries_on_http_429_then_succeeds(): void
    {
        Http::fakeSequence()
            ->push(['errors' => ['Too many requests']], 429)
            ->push(['errors' => ['Too many requests']], 429)
            ->push(['single_sign_on_record' => ['external_id' => '12345']], 200);

        $user = (new DiscourseClient())->getUser(1, 'alice');

        $this->assertSame('12345', $user['single_sign_on_record']['external_id']);
        Http::assertSentCount(3);
    }

    public function test_get_user_retries_when_body_says_too_many(): void
    {
        // A 200 whose body carries a "too many" error is still rate-limiting.
        Http::fakeSequence()
            ->push(['errors' => ['You have performed this action too many times']], 200)
            ->push(['single_sign_on_record' => ['external_id' => '7']], 200);

        $user = (new DiscourseClient())->getUser(7, 'bob');

        $this->assertSame('7', $user['single_sign_on_record']['external_id']);
        Http::assertSentCount(2);
    }

    public function test_get_user_throws_after_max_retries(): void
    {
        Http::fake(fn () => Http::response(['errors' => ['Too many requests']], 429));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/max retries/i');

        (new DiscourseClient())->getUser(1, 'alice');
    }

    public function test_get_user_throws_on_non_rate_limit_error_body(): void
    {
        // A non-rate-limit error must abort loudly, not be swallowed.
        Http::fake(fn () => Http::response(['errors' => ['The requested URL or resource could not be found.']], 404));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not be found/');

        (new DiscourseClient())->getUser(99, 'ghost');
    }

    public function test_get_all_users_returns_members(): void
    {
        Http::fake(fn () => Http::response(['members' => [['id' => 1, 'username' => 'a']]], 200));

        $members = (new DiscourseClient())->getAllUsers();

        $this->assertCount(1, $members);
        $this->assertSame('a', $members[0]['username']);
    }

    public function test_get_user_email_returns_primary_email(): void
    {
        Http::fake(fn () => Http::response(['email' => 'mod@example.com'], 200));

        $this->assertSame('mod@example.com', (new DiscourseClient())->getUserEmail('alice'));
    }

    public function test_find_category_by_slug_returns_category(): void
    {
        Http::fake(fn () => Http::response(['category' => ['id' => 18, 'name' => 'AGM 2026']], 200));

        $category = (new DiscourseClient())->findCategoryBySlug('agm-2026');

        $this->assertSame(18, $category['id']);
    }

    public function test_find_category_by_slug_returns_null_when_absent(): void
    {
        // A missing category is an expected outcome for the AGM setup command
        // (that is how it decides to create one), not an error to throw on.
        Http::fake(fn () => Http::response(['errors' => ['The requested URL or resource could not be found.']], 404));

        $this->assertNull((new DiscourseClient())->findCategoryBySlug('agm-2099'));
    }

    public function test_update_site_setting_sends_singular_update_existing_user(): void
    {
        // Discourse reads the backfill flag from `update_existing_user` (singular).
        // Sending `update_existing_users` is silently ignored: the setting saves
        // but no existing user is touched, which is exactly the failure this
        // assertion exists to prevent.
        Http::fake(fn () => Http::response('', 204));

        (new DiscourseClient())->updateSiteSetting('default_categories_watching', '7|18', true);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/admin/site_settings/default_categories_watching')
                && $request['default_categories_watching'] === '7|18'
                && $request['update_existing_user'] === 'true';
        });
    }

    public function test_update_site_setting_omits_backfill_flag_when_not_requested(): void
    {
        Http::fake(fn () => Http::response('', 204));

        (new DiscourseClient())->updateSiteSetting('default_categories_watching', '7', false);

        Http::assertSent(fn ($request) => !isset($request['update_existing_user']));
    }

    public function test_write_accepts_204_with_empty_body(): void
    {
        // The site-settings endpoint answers 204 with no body. Treating a
        // non-JSON body as a failure would make every successful write throw.
        Http::fake(fn () => Http::response('', 204));

        $this->assertSame([], (new DiscourseClient())->updateSiteSetting('default_categories_watching', '7', false));
    }

    public function test_get_with_an_empty_body_still_throws(): void
    {
        // The empty-body allowance is for writes only. A GET that comes back
        // empty is still the malformed response the existing callers abort on,
        // rather than something to read quietly as "no data".
        Http::fake(fn () => Http::response('', 200));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unexpected non-JSON/');

        (new DiscourseClient())->getUserEmail('alice');
    }

    public function test_write_retries_on_rate_limit(): void
    {
        // Admin writes are rate-limited hard; the retry must cover PUT as well as GET.
        Http::fakeSequence()
            ->push(['errors' => ['You’ve performed this action too many times.']], 429)
            ->push('', 204);

        (new DiscourseClient())->updateSiteSetting('default_categories_watching', '7', false);

        Http::assertSentCount(2);
    }

    public function test_create_category_posts_name_and_permissions(): void
    {
        Http::fake(fn () => Http::response(['category' => ['id' => 42]], 200));

        $category = (new DiscourseClient())->createCategory([
            'name' => 'AGM 2027',
            'slug' => 'agm-2027',
            'permissions' => ['everyone' => 2, 'staff' => 1],
        ]);

        $this->assertSame(42, $category['id']);
        Http::assertSent(function ($request) {
            // Assert on the encoded body: nested keys go out as
            // permissions[everyone]=2, which is the shape Discourse expects.
            $body = urldecode($request->body());

            return $request->method() === 'POST'
                && str_contains($request->url(), '/categories.json')
                && str_contains($body, 'name=AGM 2027')
                && str_contains($body, 'permissions[everyone]=2')
                && str_contains($body, 'permissions[staff]=1');
        });
    }

    public function test_get_site_setting_reads_value_by_name(): void
    {
        Http::fake(fn () => Http::response([
            'site_settings' => [
                ['setting' => 'default_categories_watching', 'value' => '7|18'],
            ],
        ], 200));

        $this->assertSame('7|18', (new DiscourseClient())->getSiteSetting('default_categories_watching'));
    }
}
