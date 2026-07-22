<?php

namespace App\Services\TrashNothing\Sync;

use App\Models\Group;
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
        $fixtureFile = base_path("tests/fixtures/tn_sync/posts_page_{$page}.json");

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

        if ($date && (!$maxDate || $date > $maxDate)) {
            $maxDate = $date;
        }

        Log::info('TN-SYNC-TRACE [POST] post_id=' . $postId . ' type=' . $type . ' group_id=' . $groupId . ' date=' . $date . ' title=' . substr((string) $title, 0, 60));

        // Resolve the Freegle group by nameshort — TN uses the Freegle group nameshort
        // as the group_id in its API responses, matching how the email path resolves
        // groups via IncomingMailService::findGroup($email->targetGroupName).
        $group = $this->findGroup((string) $groupId);
        if ($group === null) {
            Log::info('TN-SYNC-TRACE [POST-SKIP] reason=unknown-group group_id=' . $groupId . ' post_id=' . $postId);
            return $maxDate;
        }

        try {
            $result = $this->ingestionService->ingest($post, $group);
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
     * Mirrors IncomingMailService::findGroup()
     */
    private function findGroup(string $nameshort): ?Group
    {
        if (empty($nameshort)) {
            return null;
        }

        return Group::where('nameshort', $nameshort)->first();
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
