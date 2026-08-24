<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Discourse REST API, authenticated with the system
 * Api-Key/Api-Username headers (V1 used the same headers in
 * discourse_not_signed_up.php).
 *
 * All methods return associative arrays (Discourse JSON). Callers are expected
 * to check {@see isConfigured()} first; when the API key is unset the cron
 * commands skip rather than firing unauthenticated requests.
 */
class DiscourseClient
{
    private string $baseUrl;

    private string $apiKey;

    private string $apiUsername;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('freegle.discourse.url'), '/');
        $this->apiKey = (string) config('freegle.discourse.api_key');
        $this->apiUsername = (string) config('freegle.discourse.api_username', 'system');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'Api-Key' => $this->apiKey,
            'Api-Username' => $this->apiUsername,
            'User-Agent' => 'Freegle',
        ])->timeout(60);
    }

    /**
     * GET a Discourse JSON endpoint, mirroring V1 Utils::curlWithRetry + the
     * per-call error check the callers (GetAllUsers/GetUser/GetUserEmail) did.
     *
     * Discourse rate-limits the admin API aggressively. V1 retried on HTTP 429
     * (or an "errors" body containing "too many") up to 60× with a 1s delay,
     * and threw on any other error response — so the run aborted loudly rather
     * than emailing a report built on partially-resolved Discourse users. The
     * earlier port did neither: a 429 silently became a body with no
     * single_sign_on_record, so the mod looked absent from Discourse and their
     * groups were falsely flagged NOT REPRESENTED. Restore the retry + throw.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function getJson(string $url, array $query = []): array
    {
        return $this->send('GET', $url, $query) ?? [];
    }

    /**
     * Issue a request, retrying while Discourse rate-limits us.
     *
     * Writes go out form-encoded rather than as JSON because that is the shape
     * the admin endpoints were verified against by hand: nested keys such as
     * `permissions[everyone]=2` are what Discourse's own admin UI sends.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null  null only when $notFoundIsNull and Discourse replied 404
     */
    private function send(string $method, string $url, array $payload = [], bool $notFoundIsNull = false): ?array
    {
        $maxRetries = max(1, (int) config('freegle.discourse.max_retries', 60));
        $retryDelay = max(0, (int) config('freegle.discourse.retry_delay_s', 1));

        for ($attempt = 1; ; $attempt++) {
            $resp = match ($method) {
                'GET' => $this->request()->get($url, $payload),
                'POST' => $this->request()->asForm()->post($url, $payload),
                'PUT' => $this->request()->asForm()->put($url, $payload),
                default => throw new \InvalidArgumentException("Discourse: unsupported method {$method}"),
            };
            $data = $resp->json();
            $errors = is_array($data) && isset($data['errors']) ? (array) $data['errors'] : [];

            $rateLimited = $resp->status() === 429;
            foreach ($errors as $error) {
                if (stripos((string) $error, 'too many') !== false) {
                    $rateLimited = true;
                }
            }

            if ($rateLimited) {
                if ($attempt >= $maxRetries) {
                    throw new \RuntimeException("Discourse: max retries ($maxRetries) exceeded due to rate limiting: {$url}");
                }
                if ($retryDelay > 0) {
                    sleep($retryDelay);
                }
                continue;
            }

            if ($notFoundIsNull && $resp->status() === 404) {
                return null;
            }

            // The site-settings endpoint answers 204 with an empty body. That is
            // a success; treating a non-JSON body as malformed would make every
            // successful write throw. Writes only: a GET that comes back empty
            // is still the malformed response the existing callers throw on,
            // rather than something to be quietly read as "no data".
            if ($method !== 'GET' && $resp->successful() && trim((string) $resp->body()) === '') {
                return [];
            }

            if (!is_array($data)) {
                throw new \RuntimeException("Discourse: unexpected non-JSON response (HTTP {$resp->status()}): {$url}");
            }
            if ($errors !== []) {
                throw new \RuntimeException('Discourse: '.implode(', ', $errors).": {$url}");
            }

            return $data;
        }
    }

    /**
     * All trust_level_0 members (effectively every user), first 1000 — overrides
     * Discourse's default page size of 20.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllUsers(): array
    {
        $data = $this->getJson($this->baseUrl.'/groups/trust_level_0/members.json', [
            'limit' => 1000,
            'offset' => 0,
        ]);

        if (!isset($data['members'])) {
            throw new \RuntimeException('Discourse getAllUsers: unexpected response (no members)');
        }

        return $data['members'];
    }

    /**
     * Admin view of a user — includes bounce_score, single_sign_on_record
     * (external_id) and last_emailed_at.
     *
     * @return array<string, mixed>
     */
    public function getUser(int $id, string $username): array
    {
        return $this->getJson($this->baseUrl."/admin/users/{$id}/{$username}.json");
    }

    /** The user's primary email address. */
    public function getUserEmail(string $username): string
    {
        $data = $this->getJson($this->baseUrl."/users/{$username}/emails.json");

        return (string) ($data['email'] ?? '');
    }

    /**
     * Look a category up by slug, or null when it does not exist yet.
     *
     * @return array<string, mixed>|null
     */
    public function findCategoryBySlug(string $slug): ?array
    {
        $data = $this->send('GET', $this->baseUrl."/c/{$slug}/find_by_slug.json", [], notFoundIsNull: true);

        return $data === null ? null : ($data['category'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createCategory(array $params): array
    {
        $data = $this->send('POST', $this->baseUrl.'/categories.json', $params) ?? [];

        return $data['category'] ?? $data;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function updateCategory(int $id, array $params): array
    {
        $data = $this->send('PUT', $this->baseUrl."/categories/{$id}.json", $params) ?? [];

        return $data['category'] ?? $data;
    }

    /** Current value of a site setting. */
    public function getSiteSetting(string $name): string
    {
        $data = $this->getJson($this->baseUrl.'/admin/site_settings.json', ['names' => $name]);

        foreach (($data['site_settings'] ?? []) as $setting) {
            if (($setting['setting'] ?? null) === $name) {
                return (string) ($setting['value'] ?? '');
            }
        }

        throw new \RuntimeException("Discourse: site setting not found: {$name}");
    }

    /**
     * Change a site setting, optionally applying it to existing users.
     *
     * The backfill flag is `update_existing_user` - singular. The plural
     * spelling is accepted by the request and then silently ignored, so the
     * setting saves while every existing user is left untouched. Discourse also
     * backfills by diffing the previous value against the new one, so re-sending
     * a value that is already set is a no-op no matter what this flag says.
     *
     * @return array<string, mixed>
     */
    public function updateSiteSetting(string $name, string $value, bool $backfillExistingUsers = false): array
    {
        $payload = [$name => $value];

        if ($backfillExistingUsers) {
            $payload['update_existing_user'] = 'true';
        }

        return $this->send('PUT', $this->baseUrl.'/admin/site_settings/'.$name, $payload) ?? [];
    }
}
