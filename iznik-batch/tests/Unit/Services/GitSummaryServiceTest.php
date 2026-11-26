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

    /**
     * A change behind a permission check must not be described as something all
     * volunteers can do. The summary previously said "volunteers can now..." for
     * work only Support or Admin staff could reach, because the prompt only ever
     * saw commit messages and file names - never the access checks in the diff.
     */
    public function test_prompt_carries_the_access_checks_found_in_the_diff(): void
    {
        $prompt = $this->capturePrompt([
            [
                'repo' => 'iznik-nuxt3',
                'category' => 'ModTools',
                'commits' => [['date' => '2026-08-21', 'message' => 'feat: add bulk member purge']],
                'stat' => ' modtools/pages/members/purge.vue | 20 ++++',
                'diff' => "diff --git a/modtools/pages/members/purge.vue b/modtools/pages/members/purge.vue\n"
                    . "--- a/modtools/pages/members/purge.vue\n"
                    . "+++ b/modtools/pages/members/purge.vue\n"
                    . "@@ -1,3 +1,6 @@ setup\n"
                    . "+const { supportOrAdmin } = useMe()\n"
                    . "+if (!supportOrAdmin.value) return\n",
            ],
        ]);

        // Assert on the whole note, not its parts: "supportOrAdmin" also appears in the
        // guidelines further down, so a looser assertion would pass without the signal.
        $this->assertStringContainsString(
            "- modtools/pages/members/purge.vue: Support and Admin staff only (supportOrAdmin)",
            $prompt
        );
    }

    /**
     * The instruction is worth asserting on its own: without it the model has the
     * evidence but no reason to act on it.
     */
    public function test_prompt_tells_the_model_to_state_who_can_use_a_change(): void
    {
        $prompt = $this->capturePrompt([
            [
                'repo' => 'iznik-nuxt3',
                'category' => 'ModTools',
                'commits' => [['date' => '2026-08-21', 'message' => 'feat: something']],
                'stat' => ' a.vue | 1 +',
                'diff' => '',
            ],
        ]);

        $this->assertStringContainsString('who can actually use', $prompt);
        $this->assertStringContainsString('Support and Admin staff can now', $prompt);
    }

    /**
     * An admin-only area is often obvious from the path even when the guard lives
     * elsewhere, so the path counts as evidence too.
     */
    public function test_an_admin_only_path_is_reported_even_without_a_guard_keyword(): void
    {
        $signals = app(GitSummaryService::class)->extractAccessSignals([
            [
                'repo' => 'iznik-nuxt3',
                'category' => 'ModTools',
                'commits' => [],
                'stat' => '',
                'diff' => "--- a/modtools/pages/sysadmin/spammers.vue\n"
                    . "+++ b/modtools/pages/sysadmin/spammers.vue\n"
                    . "@@ -1,1 +1,2 @@\n"
                    . "+const x = 1\n",
            ],
        ]);

        $this->assertStringContainsString('sysadmin', $signals);
        $this->assertStringContainsString('Admin', $signals);
    }

    /**
     * Ordinary work must not acquire an access section it does not need.
     */
    public function test_no_access_section_when_nothing_is_gated(): void
    {
        $signals = app(GitSummaryService::class)->extractAccessSignals([
            [
                'repo' => 'iznik-nuxt3',
                'category' => 'FD',
                'commits' => [],
                'stat' => '',
                'diff' => "--- a/components/Foo.vue\n+++ b/components/Foo.vue\n@@ -1,1 +1,2 @@\n+const x = 1\n",
            ],
        ]);

        $this->assertSame('', $signals);
    }

    /**
     * A false restriction note is the same mistake pointing the other way: it would have
     * the summary tell volunteers they cannot use something they can.
     */
    public function test_a_name_merely_containing_a_marker_is_not_treated_as_a_guard(): void
    {
        $signals = app(GitSummaryService::class)->extractAccessSignals([
            [
                'repo' => 'iznik-nuxt3',
                'category' => 'FD',
                'commits' => [],
                'stat' => '',
                'diff' => "--- a/components/Foo.vue\n+++ b/components/Foo.vue\n@@ -1,1 +1,2 @@\n+const thisAdminish = 1\n",
            ],
        ]);

        $this->assertSame('', $signals);
    }

    /**
     * Laravel's own app/Support and tests/Support directories have nothing to do with
     * Freegle Support staff, and must not be reported as restricted.
     */
    public function test_a_laravel_support_directory_is_not_mistaken_for_support_staff(): void
    {
        $signals = app(GitSummaryService::class)->extractAccessSignals([
            [
                'repo' => 'iznik-batch',
                'category' => 'Backend',
                'commits' => [],
                'stat' => '',
                'diff' => "--- a/tests/Support/SeedsSpatialIndex.php\n+++ b/tests/Support/SeedsSpatialIndex.php\n@@ -1,1 +1,2 @@\n+\$x = 1;\n",
            ],
        ]);

        $this->assertSame('', $signals);
    }

    /**
     * A sysadmin page guarded by supportOrAdmin is open to Support, whatever its
     * directory implies. Reporting both would give the model two different answers for
     * one file, so the guard in the code wins over the convention in the path.
     */
    public function test_a_guard_in_the_file_beats_the_convention_of_its_path(): void
    {
        $signals = app(GitSummaryService::class)->extractAccessSignals([
            [
                'repo' => 'iznik-nuxt3',
                'category' => 'ModTools',
                'commits' => [],
                'stat' => '',
                'diff' => "--- a/modtools/pages/sysadmin/index.vue\n+++ b/modtools/pages/sysadmin/index.vue\n@@ -1,1 +1,2 @@\n+const { supportOrAdmin } = useMe()\n",
            ],
        ]);

        $this->assertSame(
            "- modtools/pages/sysadmin/index.vue: Support and Admin staff only (supportOrAdmin)\n",
            $signals
        );
    }

    /**
     * Capture the prompt that would go to Gemini.
     */
    private function capturePrompt(array $allChanges): string
    {
        $captured = '';

        $gemini = \Mockery::mock(\App\Services\GeminiService::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateContent')
            ->andReturnUsing(function ($prompt) use (&$captured) {
                $captured = $prompt;

                return 'summary';
            });

        (new GitSummaryService($gemini))->summarizeAllChanges($allChanges);

        return $captured;
    }
}
