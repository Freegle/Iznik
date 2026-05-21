<?php

namespace App\Services\TrashNothing\Sync;

use App\Services\LokiService;
use Illuminate\Support\Facades\Log;

class PostSyncer
{
    private const PAGE_SIZE = 100;

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
        // TODO: implement post sync
        Log::info('TN-SYNC-TRACE [POSTS] not yet implemented');
        return [0, null];
    }
}
