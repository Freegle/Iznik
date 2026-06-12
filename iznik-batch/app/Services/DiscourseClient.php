<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Discourse REST API, authenticated with the system
 * Api-Key/Api-Username headers (V1 used the same headers in
 * discourse_checkusers.php / discourse_not_signed_up.php).
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
     * All trust_level_0 members (effectively every user), first 1000 — overrides
     * Discourse's default page size of 20.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllUsers(): array
    {
        $resp = $this->request()->get($this->baseUrl.'/groups/trust_level_0/members.json', [
            'limit' => 1000,
            'offset' => 0,
        ]);
        $data = $resp->json();

        if (!is_array($data) || !isset($data['members'])) {
            $err = is_array($data) && isset($data['errors']) ? implode(', ', (array) $data['errors']) : 'unexpected response';
            throw new \RuntimeException('Discourse getAllUsers: '.$err);
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
        return (array) $this->request()->get($this->baseUrl."/admin/users/{$id}/{$username}.json")->json();
    }

    /**
     * Public profile of a user — includes watched/tracked category ids,
     * user_option (mailing_list_mode, email_digests) and bio_raw.
     *
     * @return array<string, mixed>
     */
    public function getUserPublic(string $username): array
    {
        $data = $this->request()->get($this->baseUrl."/u/{$username}.json")->json();

        return is_array($data) && isset($data['user']) ? (array) $data['user'] : [];
    }

    /** The user's primary email address. */
    public function getUserEmail(string $username): string
    {
        $data = $this->request()->get($this->baseUrl."/users/{$username}/emails.json")->json();

        return is_array($data) ? (string) ($data['email'] ?? '') : '';
    }

    /**
     * Add a watched category to the user, preserving the ones they already
     * watch (V1 SetWatchCategory).
     *
     * @param  array<int, int>  $alreadyWatching
     */
    public function setWatchCategory(string $username, array $alreadyWatching, int $categoryId): void
    {
        $watch = array_values($alreadyWatching);
        $watch[] = $categoryId;

        $this->request()->asForm()->put($this->baseUrl."/users/{$username}.json", [
            'watched_category_ids' => $watch,
        ]);
    }

    /** Set the user's bio (V1 SetBio). */
    public function setBio(string $username, string $bio): void
    {
        $this->request()->asForm()->put($this->baseUrl."/users/{$username}.json", [
            'bio_raw' => $bio,
        ]);
    }
}
