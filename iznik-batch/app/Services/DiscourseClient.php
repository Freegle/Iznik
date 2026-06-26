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
        $maxRetries = max(1, (int) config('freegle.discourse.max_retries', 60));
        $retryDelay = max(0, (int) config('freegle.discourse.retry_delay_s', 1));

        for ($attempt = 1; ; $attempt++) {
            $resp = $this->request()->get($url, $query);
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
}
