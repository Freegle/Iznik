<?php

namespace App\Services\TrashNothing\Sync;

use App\Services\LokiService;
use Illuminate\Support\Facades\Log;
use OpenAPI\Client\Api\PostsApi;
use OpenAPI\Client\ApiException;
use OpenAPI\Client\Configuration;

class PostSyncer
{
    private const PAGE_SIZE = 100;

    // TODO: temporarily just requesting posts for the FreeglePlayground group.
    private const GROUP_IDS = '8444';

    public function __construct(
        private bool $dryRun,
        private bool $localTesting,
        private string $apiKey,
        private string $apiBaseUrl,
        private LokiService $loki,
    ) {}

    /**
     * @return array{int, string|null} [count, maxDate]
     */
    public function sync(string $from, string $to): array
    {
        $page = 1;
        $count = 0;
        $maxDate = null;
        $api = $this->buildApiClient();

        do {
            [$posts, $numPages] = $this->fetchPage($api, $page, $from, $to);
            if ($posts === null) {
                break;
            }

            Log::info("TN-SYNC-TRACE [POSTS-PAGE] page={$page} count=" . count($posts));

            foreach ($posts as $post) {
                $count++;
                $maxDate = $this->processPost($post, $maxDate);
            }

            $page++;
        } while ($posts && $page <= $numPages);

        Log::info("TN-SYNC-TRACE [POSTS-DONE] total={$count} max_date=" . ($maxDate ?? 'null'));

        return [$count, $maxDate];
    }

    /**
     * @return array{array|null, int} [posts, numPages] — posts is null on API error
     */
    private function fetchPage(PostsApi $api, int $page, string $from, string $to): array
    {
        if ($this->localTesting) {
            return $this->fetchPageFromFixture($page);
        }

        try {
            $response = $api->getPosts(
                types: 'offer,wanted',
                sources: 'groups',
                sort_by: 'date',
                group_ids: self::GROUP_IDS,
                per_page: self::PAGE_SIZE,
                page: $page,
                date_min: new \DateTime($from),
                date_max: new \DateTime($to),
                outcomes: 'all',
            );
        } catch (ApiException $e) {
            Log::error("TN sync: posts API failed on page {$page}", [
                'status' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);
            return [null, 0];
        }

        return [$response->getPosts() ?? [], $response->getNumPages() ?? 1];
    }

    /**
     * @return array{array, int} [posts, numPages]
     */
    private function fetchPageFromFixture(int $page): array
    {
        $fixtureFile = base_path("tests/fixtures/tn_sync/posts_page_{$page}.json");

        if (!file_exists($fixtureFile)) {
            Log::info("TN-SYNC-TRACE [POSTS-PAGE] missing fixture file={$fixtureFile}");
            return [[], 0];
        }

        $payload = json_decode(file_get_contents($fixtureFile), true);

        return [
            is_array($payload) ? ($payload['posts'] ?? []) : [],
            is_array($payload) ? (int) ($payload['num_pages'] ?? 1) : 1,
        ];
    }

    private function processPost(mixed $post, ?string $maxDate): ?string
    {
        $date = is_array($post) ? ($post['date'] ?? null) : $post->getDate()?->format('Y-m-d\TH:i:s\Z');
        $postId = is_array($post) ? ($post['post_id'] ?? '') : $post->getPostId();
        $type = is_array($post) ? ($post['type'] ?? '') : $post->getType();
        $groupId = is_array($post) ? ($post['group_id'] ?? '') : $post->getGroupId();
        $title = is_array($post) ? ($post['title'] ?? '') : $post->getTitle();

        if ($date && (!$maxDate || $date > $maxDate)) {
            $maxDate = $date;
        }

        // TODO: ingest post into the database.
        Log::info("TN-SYNC-TRACE [POST] post_id={$postId} type={$type} group_id={$groupId} date={$date} title=" . substr((string) $title, 0, 60));

        return $maxDate;
    }

    private function buildApiClient(): PostsApi
    {
        $config = Configuration::getDefaultConfiguration()
            ->setApiKey('api_key', $this->apiKey)
            ->setHost($this->apiBaseUrl);

        return new PostsApi(config: $config);
    }
}
