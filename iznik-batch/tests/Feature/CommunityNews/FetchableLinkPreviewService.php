<?php

namespace Tests\Feature\CommunityNews;

use App\Services\NewsfeedLinkPreviewService;

/**
 * Deterministic host resolution for tests (there is no external DNS in the test
 * environment): literal IPs pass through so the SSRF range checks still run for
 * real, while hostnames resolve to a public IP so fetch-logic tests reach the
 * faked HTTP call with the guard still active.
 */
class FetchableLinkPreviewService extends NewsfeedLinkPreviewService
{
    protected function resolveHostIps(string $host): array
    {
        return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ['93.184.216.34'];
    }
}
