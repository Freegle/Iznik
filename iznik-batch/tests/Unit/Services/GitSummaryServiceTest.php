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

        // Clone failure returns false (distinct from null = genuinely no changes).
        $this->assertFalse($result);
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

        // Clone failure returns false (distinct from null = genuinely no changes).
        $this->assertFalse($result);
        $this->assertNotNull($loggedContext);
        $this->assertStringNotContainsString('ghp_testtoken123', $loggedContext['output']);
    }

    public function test_generate_report_logs_warning_on_clone_failure(): void
    {
        // Regression: clone failure was indistinguishable from "no changes" — both
        // returned null → both logged "No changes found" → credential lapses went
        // unnoticed for weeks.
        Config::set('freegle.git_summary.repositories', [
            [
                'name' => 'iznik-batch',
                'url' => 'file:///tmp/nonexistent-clone-fail-repo',
                'branch' => 'master',
                'category' => 'Backend',
            ],
        ]);

        $service = app(GitSummaryService::class);

        $warningLogged = false;
        $noChangesLogged = false;

        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')
            ->zeroOrMoreTimes()
            ->withArgs(function ($message) use (&$noChangesLogged) {
                if (str_contains($message, 'No changes found')) {
                    $noChangesLogged = true;
                }
                return true;
            });
        Log::shouldReceive('warning')
            ->zeroOrMoreTimes()
            ->withArgs(function ($message) use (&$warningLogged) {
                if (str_contains($message, 'Failed to fetch')) {
                    $warningLogged = true;
                }
                return true;
            });

        $service->generateReport('-7 days');

        $this->assertTrue($warningLogged, 'Expected Log::warning with "Failed to fetch" on clone failure');
        $this->assertFalse($noChangesLogged, 'Clone failure must not be logged as "No changes found"');
    }
}
