<?php

namespace Tests\Unit\Services\CookieYes;

use App\Services\CookieYes\CookieYesAuthException;
use App\Services\CookieYes\CookieYesException;
use App\Services\CookieYes\CookieYesOAuth;
use App\Services\CookieYes\CookieYesTokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CookieYesOAuthTest extends TestCase
{
    private const BASE = 'https://app.cookieyes.com';

    private CookieYesTokenStore $store;

    private CookieYesOAuth $oauth;

    protected function setUp(): void
    {
        parent::setUp();
        config(['freegle.cookieyes.base_url' => self::BASE]);
        DB::table('config')->whereIn('key', [CookieYesTokenStore::KEY, CookieYesTokenStore::PENDING_KEY])->delete();
        $this->store = new CookieYesTokenStore();
        $this->oauth = new CookieYesOAuth($this->store);
    }

    /**
     * One fake for the whole authorisation server, keyed on the request, because
     * Http::fake() merges and the first stub wins.
     *
     * @param  callable(Request): mixed  $token  answers the token endpoint
     */
    private function fakeServer(callable $token): void
    {
        Http::fake(function (Request $request) use ($token) {
            $url = $request->url();

            if (str_ends_with($url, '/.well-known/oauth-authorization-server')) {
                return Http::response([
                    'issuer' => self::BASE,
                    'authorization_endpoint' => self::BASE . '/oauth2/auth',
                    'token_endpoint' => self::BASE . '/oauth2/token',
                    'registration_endpoint' => self::BASE . '/oauth2/register',
                ]);
            }

            if (str_ends_with($url, '/oauth2/register')) {
                return Http::response(['client_id' => 'client-123'], 201);
            }

            if (str_ends_with($url, '/oauth2/token')) {
                return $token($request);
            }

            return Http::response('unexpected ' . $url, 500);
        });
    }

    private function tokens(string $access, ?string $refresh, int $expiresIn = 3600): \GuzzleHttp\Promise\PromiseInterface
    {
        $body = ['access_token' => $access, 'token_type' => 'bearer', 'expires_in' => $expiresIn];
        if ($refresh !== null) {
            $body['refresh_token'] = $refresh;
        }

        return Http::response($body);
    }

    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        return $params;
    }

    private function seedCredentials(array $overrides = []): void
    {
        $this->store->saveCredentials(array_merge([
            'client_id' => 'client-123',
            'token_endpoint' => self::BASE . '/oauth2/token',
            'refresh_token' => 'refresh-old',
            'access_token' => 'access-old',
            'access_expires_at' => now()->subMinute()->timestamp,
        ], $overrides));
    }

    public function test_begin_login_registers_a_public_client_and_returns_a_pkce_login_url(): void
    {
        $this->fakeServer(fn () => Http::response('no', 500));

        $url = $this->oauth->beginLogin();

        $this->assertStringStartsWith(self::BASE . '/oauth2/auth?', $url);
        $params = $this->query($url);
        $pending = $this->store->pending();

        $this->assertSame('code', $params['response_type']);
        $this->assertSame('client-123', $params['client_id']);
        $this->assertSame(CookieYesOAuth::REDIRECT_URI, $params['redirect_uri']);
        $this->assertSame('S256', $params['code_challenge_method']);
        $this->assertSame(self::BASE . '/mcp', $params['resource']);
        $this->assertStringContainsString('offline_access', $params['scope']);
        $this->assertSame($pending['state'], $params['state']);
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='),
            $params['code_challenge']
        );

        Http::assertSent(function (Request $request) {
            return str_ends_with($request->url(), '/oauth2/register')
                && $request['token_endpoint_auth_method'] === 'none'
                && $request['redirect_uris'] === [CookieYesOAuth::REDIRECT_URI]
                && in_array('refresh_token', $request['grant_types'], true);
        });
    }

    public function test_complete_login_exchanges_the_code_and_stores_the_tokens_encrypted(): void
    {
        $this->fakeServer(fn () => $this->tokens('access-1', 'refresh-1'));

        $this->oauth->beginLogin();
        $pending = $this->store->pending();

        $this->oauth->completeLogin(CookieYesOAuth::REDIRECT_URI . '?code=abc&state=' . $pending['state']);

        Http::assertSent(function (Request $request) use ($pending) {
            return str_ends_with($request->url(), '/oauth2/token')
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'abc'
                && $request['code_verifier'] === $pending['verifier']
                && $request['client_id'] === 'client-123'
                && $request['redirect_uri'] === CookieYesOAuth::REDIRECT_URI
                && $request['resource'] === self::BASE . '/mcp';
        });

        $this->assertNull($this->store->pending());

        $raw = DB::table('config')->where('key', CookieYesTokenStore::KEY)->value('value');
        $this->assertStringNotContainsString('refresh-1', $raw);

        $this->assertSame('refresh-1', $this->store->credentials()['refresh_token']);

        // The fresh access token is used without another trip to the server.
        Http::fake(fn () => Http::response('should not be called', 500));
        $this->assertSame('access-1', $this->oauth->accessToken());
    }

    public function test_complete_login_rejects_a_state_mismatch(): void
    {
        $this->fakeServer(fn () => $this->tokens('access-1', 'refresh-1'));
        $this->oauth->beginLogin();

        try {
            $this->oauth->completeLogin(CookieYesOAuth::REDIRECT_URI . '?code=abc&state=forged');
            $this->fail('Expected a state mismatch to be rejected');
        } catch (CookieYesException $e) {
            $this->assertStringContainsString('state', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/oauth2/token'));
    }

    public function test_complete_login_rejects_an_expired_pending_login(): void
    {
        $this->fakeServer(fn () => $this->tokens('access-1', 'refresh-1'));
        $this->oauth->beginLogin();
        $state = $this->store->pending()['state'];

        $this->travel(16)->minutes();

        $this->expectException(CookieYesException::class);
        $this->expectExceptionMessage('expired');
        $this->oauth->completeLogin(CookieYesOAuth::REDIRECT_URI . '?code=abc&state=' . $state);
    }

    public function test_complete_login_without_a_pending_login_says_to_start_one(): void
    {
        $this->expectException(CookieYesException::class);
        $this->expectExceptionMessage('cookieyes:authorize');
        $this->oauth->completeLogin(CookieYesOAuth::REDIRECT_URI . '?code=abc&state=x');
    }

    public function test_complete_login_reports_a_refused_consent(): void
    {
        $this->fakeServer(fn () => $this->tokens('access-1', 'refresh-1'));
        $this->oauth->beginLogin();
        $state = $this->store->pending()['state'];

        $this->expectException(CookieYesException::class);
        $this->expectExceptionMessage('access_denied');
        $this->oauth->completeLogin(CookieYesOAuth::REDIRECT_URI . '?error=access_denied&state=' . $state);
    }

    public function test_complete_login_fails_when_no_refresh_token_is_issued(): void
    {
        // Without a refresh token the watchdog could only run for an hour.
        $this->fakeServer(fn () => $this->tokens('access-1', null));
        $this->oauth->beginLogin();
        $state = $this->store->pending()['state'];

        $this->expectException(CookieYesException::class);
        $this->expectExceptionMessage('refresh token');
        $this->oauth->completeLogin(CookieYesOAuth::REDIRECT_URI . '?code=abc&state=' . $state);
    }

    public function test_an_expired_access_token_is_refreshed_and_the_rotated_refresh_token_saved(): void
    {
        $this->seedCredentials();
        $this->fakeServer(fn () => $this->tokens('access-new', 'refresh-new'));

        $this->assertSame('access-new', $this->oauth->accessToken());

        Http::assertSent(function (Request $request) {
            return $request['grant_type'] === 'refresh_token'
                && $request['refresh_token'] === 'refresh-old'
                && $request['client_id'] === 'client-123'
                && $request['resource'] === self::BASE . '/mcp';
        });
        $this->assertSame('refresh-new', $this->store->credentials()['refresh_token']);
    }

    public function test_a_refresh_without_a_new_refresh_token_keeps_the_old_one(): void
    {
        $this->seedCredentials();
        $this->fakeServer(fn () => $this->tokens('access-new', null));

        $this->oauth->accessToken();

        $this->assertSame('refresh-old', $this->store->credentials()['refresh_token']);
    }

    public function test_a_valid_access_token_is_reused(): void
    {
        $this->seedCredentials(['access_expires_at' => now()->addHour()->timestamp]);
        Http::fake(fn () => Http::response('should not be called', 500));

        $this->assertSame('access-old', $this->oauth->accessToken());
        Http::assertNothingSent();
    }

    public function test_invalidating_the_access_token_forces_a_refresh(): void
    {
        $this->seedCredentials(['access_expires_at' => now()->addHour()->timestamp]);
        $this->fakeServer(fn () => $this->tokens('access-new', 'refresh-new'));

        $this->oauth->invalidateAccessToken();

        $this->assertSame('access-new', $this->oauth->accessToken());
    }

    public function test_a_revoked_refresh_token_asks_for_a_new_login(): void
    {
        $this->seedCredentials();
        $this->fakeServer(fn () => Http::response(['error' => 'invalid_grant'], 400));

        $this->expectException(CookieYesAuthException::class);
        $this->expectExceptionMessage('cookieyes:authorize');
        $this->oauth->accessToken();
    }

    public function test_a_server_error_during_refresh_is_not_mistaken_for_a_lost_login(): void
    {
        $this->seedCredentials();
        $this->fakeServer(fn () => Http::response('bad gateway', 502));

        try {
            $this->oauth->accessToken();
            $this->fail('Expected the refresh to fail');
        } catch (CookieYesAuthException $e) {
            $this->fail('A 502 must not be reported as a lost login');
        } catch (CookieYesException $e) {
            $this->assertStringContainsString('502', $e->getMessage());
        }

        // The refresh token was not spent, so it is kept for the next run.
        $this->assertSame('refresh-old', $this->store->credentials()['refresh_token']);
    }

    public function test_never_authorised_asks_for_a_login(): void
    {
        $this->expectException(CookieYesAuthException::class);
        $this->expectExceptionMessage('cookieyes:authorize');
        $this->oauth->accessToken();
    }
}
