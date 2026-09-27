<?php

namespace App\Services\CookieYes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OAuth 2.1 + PKCE login to CookieYes's MCP server, as a public client.
 *
 * The login is started once by a person (cookieyes:authorize) and then kept
 * alive unattended with the refresh token. CookieYes's authorisation server
 * rotates the refresh token on every use and treats reuse of an old one as
 * theft, revoking the whole chain. So the new one is saved before anything
 * else happens, and refreshing is done under a lock so two processes can never
 * spend the same token.
 */
class CookieYesOAuth
{
    // Nothing listens here. The browser fails to load it, and the person pastes
    // the address it was sent to (which carries the code) back to cookieyes:authorize.
    public const REDIRECT_URI = 'http://localhost:8765/callback';

    public const SCOPE = 'mcp:read mcp:write offline_access';

    // Long enough for a person to get round to it. The code CookieYes hands back
    // is single-use and short-lived on its side, and useless without the verifier.
    private const PENDING_TTL_HOURS = 24;

    // Refresh this long before the access token actually expires.
    private const EXPIRY_MARGIN_SECONDS = 60;

    private const LOCK = 'cookieyes-oauth-refresh';

    public function __construct(private readonly CookieYesTokenStore $store)
    {
    }

    public function mcpUrl(): string
    {
        return rtrim((string) config('freegle.cookieyes.base_url'), '/') . '/mcp';
    }

    /**
     * Register a client, remember a PKCE verifier and state, and return the URL
     * the account owner opens to log in.
     */
    public function beginLogin(): string
    {
        $metadata = $this->json(
            Http::acceptJson()->timeout(30)->get(rtrim((string) config('freegle.cookieyes.base_url'), '/') . '/.well-known/oauth-authorization-server'),
            'reading the authorisation server metadata'
        );

        foreach (['authorization_endpoint', 'token_endpoint', 'registration_endpoint'] as $field) {
            if (empty($metadata[$field])) {
                throw new CookieYesException("CookieYes authorisation server metadata has no {$field}");
            }
        }

        $client = $this->json(
            Http::acceptJson()->timeout(30)->post($metadata['registration_endpoint'], [
                'client_name' => 'Freegle CookieYes watchdog',
                'redirect_uris' => [self::REDIRECT_URI],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'token_endpoint_auth_method' => 'none',
                'scope' => self::SCOPE,
            ]),
            'registering the client'
        );

        if (empty($client['client_id'])) {
            throw new CookieYesException('CookieYes client registration returned no client_id');
        }

        $verifier = $this->base64Url(random_bytes(32));
        $state = Str::random(32);

        $this->store->savePending([
            'client_id' => $client['client_id'],
            'token_endpoint' => $metadata['token_endpoint'],
            'verifier' => $verifier,
            'state' => $state,
            'created_at' => now()->timestamp,
        ]);

        return $metadata['authorization_endpoint'] . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => self::SCOPE,
            'state' => $state,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'resource' => $this->mcpUrl(),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Finish a login from the address the browser was redirected to.
     */
    public function completeLogin(string $callbackUrl): void
    {
        $pending = $this->store->pending();
        if ($pending === null) {
            throw new CookieYesException('No CookieYes login is in progress. Start one with php artisan cookieyes:authorize');
        }

        if (now()->timestamp - (int) $pending['created_at'] > self::PENDING_TTL_HOURS * 3600) {
            $this->store->forgetPending();
            throw new CookieYesException('The CookieYes login expired before it was completed. Start again with php artisan cookieyes:authorize');
        }

        parse_str((string) parse_url($callbackUrl, PHP_URL_QUERY), $params);

        if (! hash_equals((string) $pending['state'], (string) ($params['state'] ?? ''))) {
            throw new CookieYesException('The pasted address does not match the login in progress (state mismatch). Paste the address from the latest login.');
        }

        if (! empty($params['error'])) {
            $this->store->forgetPending();
            throw new CookieYesException('CookieYes refused the login: ' . $params['error'] . (empty($params['error_description']) ? '' : ' (' . $params['error_description'] . ')'));
        }

        if (empty($params['code'])) {
            throw new CookieYesException('The pasted address has no authorisation code in it');
        }

        $tokens = $this->json(
            Http::asForm()->acceptJson()->timeout(30)->post($pending['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'code' => $params['code'],
                'redirect_uri' => self::REDIRECT_URI,
                'client_id' => $pending['client_id'],
                'code_verifier' => $pending['verifier'],
                'resource' => $this->mcpUrl(),
            ]),
            'exchanging the authorisation code'
        );

        if (empty($tokens['refresh_token'])) {
            throw new CookieYesException('CookieYes did not issue a refresh token, so the watchdog could not keep running unattended. Check the login asked for offline access.');
        }

        $this->store->saveCredentials($this->withTokens([
            'client_id' => $pending['client_id'],
            'token_endpoint' => $pending['token_endpoint'],
        ], $tokens));
        $this->store->forgetPending();
    }

    /**
     * A usable access token, refreshing it if it has expired.
     */
    public function accessToken(): string
    {
        $credentials = $this->credentials();
        if ($this->stillValid($credentials)) {
            return $credentials['access_token'];
        }

        return Cache::lock(self::LOCK, 60)->block(30, function () {
            // Another process may have refreshed while we waited for the lock.
            $credentials = $this->credentials();
            if ($this->stillValid($credentials)) {
                return $credentials['access_token'];
            }

            return $this->refresh($credentials);
        });
    }

    /**
     * Forget the cached access token, after CookieYes rejected it.
     */
    public function invalidateAccessToken(): void
    {
        $credentials = $this->store->credentials();
        if ($credentials !== null) {
            unset($credentials['access_token'], $credentials['access_expires_at']);
            $this->store->saveCredentials($credentials);
        }
    }

    private function refresh(array $credentials): string
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(30)->post($credentials['token_endpoint'], [
                'grant_type' => 'refresh_token',
                'refresh_token' => $credentials['refresh_token'],
                'client_id' => $credentials['client_id'],
                'resource' => $this->mcpUrl(),
            ]);
        } catch (ConnectionException $e) {
            throw new CookieYesException('Could not reach CookieYes to refresh the login: ' . $e->getMessage(), 0, $e);
        }

        // 400/401 with invalid_grant means the refresh token is dead (expired,
        // revoked, or already spent). Anything else is worth another try later.
        if (in_array($response->status(), [400, 401], true)) {
            throw new CookieYesAuthException('The CookieYes login has expired or been revoked (' . ($response->json('error') ?? $response->status()) . '). Run php artisan cookieyes:authorize to log in again.');
        }

        $tokens = $this->json($response, 'refreshing the login');
        $credentials = $this->withTokens($credentials, $tokens);
        $this->store->saveCredentials($credentials);

        return $credentials['access_token'];
    }

    private function credentials(): array
    {
        $credentials = $this->store->credentials();
        if (empty($credentials['refresh_token'])) {
            throw new CookieYesAuthException('The CookieYes watchdog is not logged in. Run php artisan cookieyes:authorize.');
        }

        return $credentials;
    }

    private function stillValid(array $credentials): bool
    {
        return ! empty($credentials['access_token'])
            && (int) ($credentials['access_expires_at'] ?? 0) - self::EXPIRY_MARGIN_SECONDS > now()->timestamp;
    }

    private function withTokens(array $credentials, array $tokens): array
    {
        if (empty($tokens['access_token'])) {
            throw new CookieYesException('CookieYes returned no access token');
        }

        $credentials['access_token'] = $tokens['access_token'];
        $credentials['access_expires_at'] = now()->timestamp + (int) ($tokens['expires_in'] ?? 3600);
        if (! empty($tokens['refresh_token'])) {
            $credentials['refresh_token'] = $tokens['refresh_token'];
        }

        return $credentials;
    }

    private function json(Response $response, string $doing): array
    {
        if (! $response->successful()) {
            throw new CookieYesException("CookieYes returned HTTP {$response->status()} when {$doing}: " . Str::limit($response->body(), 200));
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new CookieYesException("CookieYes returned something other than JSON when {$doing}");
        }

        return $json;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
