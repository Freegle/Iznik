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
}
