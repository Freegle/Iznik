<?php

namespace Tests\Feature\CommunityNews;

use App\Services\NewsfeedLinkPreviewService;

/**
 * Pins the SSRF guard to "nothing is fetchable", making the source-freshness
 * check inert. Tests that are not about that check bind this so they do not
 * depend on whether their item URLs happen to resolve.
 */
class UnreachableLinkPreviewService extends NewsfeedLinkPreviewService
{
    protected function resolveHostIps(string $host): array
    {
        return [];
    }
}
