<?php

namespace Tests\Unit\Services;

use App\Services\GitSummaryService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GitSummaryServiceTest extends TestCase
{
    public function test_token_is_sanitised_from_log_output(): void
    {
        Config::set('freegle.git_summary.github_token', 'ghp_testtoken123');

        $service = app(GitSummaryService::class);

        // Capture what gets logged.
        $loggedContext = null;
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) use (&$loggedContext) {
                $loggedContext = $context;

                return str_contains($message, 'Failed to clone');
            });

        // Allow any debug/info logs from other parts of the service.
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        // Must use a github.com URL to trigger token injection. git clone will
        // fail (DNS/HTTP error) and include the URL in its error output — the
        // token must be sanitised from that output regardless of failure mode.
        $result = $service->getRepositoryChanges(
            'https://github.com/Freegle/nonexistent-repo.git',
            'master',
            time() - 3600
        );

        $this->assertNull($result);
        $this->assertNotNull($loggedContext, 'Log::error should have been called');

        // The original URL (without token) should be logged.
        $this->assertEquals('https://github.com/Freegle/nonexistent-repo.git', $loggedContext['url']);

        // The token must NOT appear in the output.
        $this->assertStringNotContainsString('ghp_testtoken123', $loggedContext['output']);

        // The redacted placeholder should be present (git includes the URL in its error).
        $this->assertStringContainsString('***', $loggedContext['output']);
    }

    public function test_non_github_url_does_not_get_token_injected(): void
    {
        Config::set('freegle.git_summary.github_token', 'ghp_testtoken123');

        $service = app(GitSummaryService::class);

        $loggedContext = null;
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) use (&$loggedContext) {
                $loggedContext = $context;

                return str_contains($message, 'Failed to clone');
            });

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        // file:// protocol — no network needed, and token must NOT be injected
        // because this isn't a github.com URL.
        $result = $service->getRepositoryChanges(
            'file:///tmp/nonexistent-repo-' . uniqid(),
            'master',
            time() - 3600
        );

        $this->assertNull($result);
        $this->assertNotNull($loggedContext);
        $this->assertStringNotContainsString('ghp_testtoken123', $loggedContext['output']);
    }
}
