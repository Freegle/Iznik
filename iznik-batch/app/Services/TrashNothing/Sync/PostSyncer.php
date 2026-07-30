<?php

namespace App\Services\TrashNothing\Sync;

use App\Models\Group;
use App\Models\Location;
use App\Services\ItemService;
use App\Services\LokiService;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use Illuminate\Support\Facades\Log;
use OpenAPI\Client\Api\PostsApi;
use OpenAPI\Client\ApiException;
use OpenAPI\Client\Configuration;

class PostSyncer
{
    // /posts/all enforces per_page <= 50.
    private const PAGE_SIZE = 50;
    // TN API rate limit is 2 requests/second; enforce a minimum 750ms gap.
    private const MIN_REQUEST_INTERVAL_US = 750_000;

    private GroupPostIngestionService $ingestionService;
    private float $lastRequestTime = 0.0;

    public function __construct(
        private bool $dryRun,
        private bool $localTesting,
        private string $apiKey,
        private string $apiBaseUrl,
        private LokiService $loki,
        // Override for --local-testing fixture lookup, e.g. for parity tests that
        // need a scenario-specific fixture directory instead of the shared default.
        // Defaults to tests/fixtures/tn_sync.
        private ?string $fixtureDir = null,
    ) {
        $this->ingestionService = new GroupPostIngestionService(
            dryRun: $this->dryRun,
            loki: $this->loki,
            itemService: app(ItemService::class),
        );
    }

    /**
     * @return array{int, string|null} [count, maxDate]
     */
    public function sync(string $from, string $to): array
    {
        $count   = 0;
        $maxDate = null;
        $api     = $this->buildApiClient();

        // /posts/all requires date_max within 1 day of date_min, so walk day-by-day.
        $cursor = new \DateTime($from, new \DateTimeZone('UTC'));
        $end    = new \DateTime($to,   new \DateTimeZone('UTC'));

        while ($cursor < $end) {
            $next      = (clone $cursor)->modify('+1 day');
            $windowEnd = $next < $end ? $next : $end;

            $page = 1;
            do {
                [$posts, $hasMore] = $this->fetchPage($api, $page, $cursor, $windowEnd);
                if ($posts === null) {
                    break 2; // API error — abort entire sync
                }

                Log::info('TN-SYNC-TRACE [POSTS-PAGE] page=' . $page . ' count=' . count($posts));

                foreach ($posts as $post) {
                    $count++;
                    $maxDate = $this->processPost($post, $maxDate);
                }

                $page++;
            } while ($hasMore);

            $cursor = $windowEnd;
        }

        Log::info('TN-SYNC-TRACE [POSTS-DONE] total=' . $count . ' max_date=' . ($maxDate ?? 'null'));

        return [$count, $maxDate];
    }

    /**
     * @return array{array|null, bool} [posts, hasMore] — posts is null on API error
     */
    private function fetchPage(PostsApi $api, int $page, \DateTime $from, \DateTime $to): array
    {
        if ($this->localTesting) {
            return $this->fetchPageFromFixture($page);
        }

        $this->throttle();

        try {
            $response = $api->getAllPosts(
                types: 'offer,wanted',
                date_min: $from->format('Y-m-d\TH:i:s'),
                date_max: $to->format('Y-m-d\TH:i:s'),
                per_page: self::PAGE_SIZE,
                page: $page,
            );
        } catch (ApiException $e) {
            Log::error('TN sync: posts API failed on page ' . $page, [
                'status' => $e->getCode(),
                'error'  => $e->getMessage(),
            ]);
            return [null, false];
        }

        $posts = $response->getPosts() ?? [];

        return [$posts, count($posts) >= self::PAGE_SIZE];
    }

    /**
     * @return array{array, bool} [posts, hasMore]
     */
    private function fetchPageFromFixture(int $page): array
    {
        $fixtureDir  = $this->fixtureDir ?? 'tests/fixtures/tn_sync';
        $fixtureFile = base_path("{$fixtureDir}/posts_page_{$page}.json");

        if (!file_exists($fixtureFile)) {
            Log::info('TN-SYNC-TRACE [POSTS-PAGE] missing fixture file=' . $fixtureFile);
            return [[], false];
        }

        $payload = json_decode(file_get_contents($fixtureFile), true);
        $posts   = is_array($payload) ? ($payload['posts'] ?? []) : [];

        return [$posts, count($posts) >= self::PAGE_SIZE];
    }

    private function processPost(mixed $post, ?string $maxDate): ?string
    {
        $date      = is_array($post) ? ($post['date'] ?? null) : $post->getDate()?->format('Y-m-d\TH:i:s\Z');
        $postId    = is_array($post) ? ($post['post_id'] ?? '') : $post->getPostId();
        $type      = is_array($post) ? ($post['type'] ?? '') : $post->getType();
        $groupId   = is_array($post) ? ($post['group_id'] ?? '') : $post->getGroupId();
        $title     = is_array($post) ? ($post['title'] ?? '') : $post->getTitle();
        $lat       = is_array($post) ? ($post['latitude'] ?? null) : $post->getLatitude();
        $lng       = is_array($post) ? ($post['longitude'] ?? null) : $post->getLongitude();

        if ($date && (!$maxDate || $date > $maxDate)) {
            $maxDate = $date;
        }

        Log::info('TN-SYNC-TRACE [POST] post_id=' . $postId . ' type=' . $type . ' group_id=' . $groupId . ' date=' . $date . ' title=' . substr((string) $title, 0, 60));

        // Resolve the Freegle group purely from the post's own lat/lng — the group TN thinks
        // a post belongs to (group_id) is just where the member happened to post it, which
        // drifts out of step with Freegle's group boundaries, so it is never used to place a
        // post. Location::groupsNear() is the same polygon-containment-then-nearest-centroid
        // logic used for member group boundaries (see Location::groupsNear() and the Go port
        // in iznik-server-go/location/location.go).
        if ($lat === null || $lng === null) {
            Log::info('TN-SYNC-TRACE [POST-SKIP] reason=no-coordinates group_id=' . $groupId . ' post_id=' . $postId);
            return $maxDate;
        }

        $group = $this->findGroupByLocation((float) $lat, (float) $lng);
        if ($group === null) {
            Log::info('TN-SYNC-TRACE [POST-SKIP] reason=not-in-any-group-bounds lat=' . $lat . ' lng=' . $lng . ' post_id=' . $postId);
            return $maxDate;
        }

        // TN also returns an out-of-spec `freegle_group_ids` field (see the comment on
        // Post::__construct()) — the Freegle groups the poster has allowed moderator
        // messages from. Both array (fixture) and Post object post shapes support
        // ArrayAccess, so this works for either without a getter on the model.
        //
        // Unlike messages_groups.mod_messaging_allowed's table-wide default (allowed,
        // for ordinary Freegle posts), every TN post is disallowed by default: TN
        // posters haven't consented to mod contact unless the resolved group is
        // explicitly present in freegle_group_ids. A missing/empty field (e.g. a
        // non-FD API key) means no consent was given, so it stays disallowed.
        $freegleGroupIds           = $post['freegle_group_ids'] ?? [];
        $moderatorMessagingAllowed = in_array($group->id, $freegleGroupIds, true);

        // Own trace tag (not [POST-RESULT]/[POST-SKIP]/[WRITE]) — EmailApiParityTest diffs
        // those tags line-for-line against the email path, which has no equivalent of this
        // API-only field.
        Log::info('TN-SYNC-TRACE [POST-META] post_id=' . $postId . ' moderator_messaging_allowed=' . ($moderatorMessagingAllowed ? 'true' : 'false'));

        try {
            $result = $this->ingestionService->ingest($post, $group, $moderatorMessagingAllowed);
            Log::info('TN-SYNC-TRACE [POST-RESULT] post_id=' . $postId . ' result=' . $result);
        } catch (\Throwable $e) {
            Log::error('TN sync: post ingestion failed', [
                'post_id' => $postId,
                'error'   => $e->getMessage(),
            ]);
        }

        return $maxDate;
    }

    /**
     * Looks up a single post by ID directly (GET /posts/{id}), bypassing the
     * date-range listing. Used by TNParityCheckCommand to distinguish a
     * genuine Layer 1 miss (post exists, in-window, unresolved outcome, but
     * /posts/all never returned it — a real bug) from false-positive causes
     * confirmed in production: TN mutating a post's `date` on repost/edit —
     * which moves it out of any date-window anchored to when it was first
     * emailed — the post being deleted outright after the fact, or the post
     * having reached an outcome (satisfied/withdrawn) that means it was never
     * going to be posted to FD in the first place. See plans/
     * tn-api-post-ingestion.md section Q for the confirmed live examples.
     *
     * @return array{status: 'found'|'not_found'|'error', date: string|null, outcome: string|null}
     */
    public function lookupPostById(string $postId): array
    {
        $this->throttle();

        try {
            $post = $this->buildApiClient()->getPost($postId);
            return [
                'status'  => 'found',
                'date'    => $post->getDate()?->format('Y-m-d\TH:i:s\Z'),
                'outcome' => $post->getOutcome(),
            ];
        } catch (ApiException $e) {
            if ($e->getCode() === 404) {
                return ['status' => 'not_found', 'date' => null, 'outcome' => null];
            }
            Log::warning('TN parity: single-post lookup failed', ['post_id' => $postId, 'error' => $e->getMessage()]);
            return ['status' => 'error', 'date' => null, 'outcome' => null];
        }
    }

    /**
     * Resolve the Freegle group whose area contains this post's coordinates.
     */
    private function findGroupByLocation(float $lat, float $lng): ?Group
    {
        $groupIds = Location::groupsNear($lat, $lng, limit: 1);

        return empty($groupIds) ? null : Group::find($groupIds[0]);
    }

    private function throttle(): void
    {
        $elapsed = microtime(true) - $this->lastRequestTime;
        $waitUs  = self::MIN_REQUEST_INTERVAL_US - (int) ($elapsed * 1_000_000);
        if ($waitUs > 0) {
            usleep($waitUs);
        }
        $this->lastRequestTime = microtime(true);
    }

    private function buildApiClient(): PostsApi
    {
        $config = Configuration::getDefaultConfiguration()
            ->setApiKey('api_key', $this->apiKey);

        return new PostsApi(config: $config);
    }
}
