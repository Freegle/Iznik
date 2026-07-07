<?php

namespace App\Console\Commands\Data;

use App\Services\AppReleaseClassifierService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to classify app release urgency and optionally send notification.
 *
 * This is used by CircleCI to determine whether to promote a beta release
 * to production immediately or batch it for the weekly release.
 *
 * Exit codes:
 *   0 = Success (classification determined)
 *   1 = Error (classification failed)
 *
 * Use --json to get machine-readable output for CI integration.
 */
class ClassifyAppReleaseCommand extends Command
{
    use GracefulShutdown;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'data:classify-app-release
                            {--sha= : Current commit SHA to classify}
                            {--notify : Send notification email}
                            {--force-notify : Send notification even if within interval}
                            {--json : Output result as JSON}
                            {--mark-released : Mark current SHA as released to production}';

    /**
     * The console command description.
     */
    protected $description = 'Classify app release urgency (URGENT, CAN_WAIT, NO_CHANGES)';

    /**
     * Execute the console command.
     */
    public function handle(AppReleaseClassifierService $service): int
    {
        $this->registerShutdownHandlers();

        $sha = $this->option('sha');
        $notify = $this->option('notify');
        $forceNotify = $this->option('force-notify');
        $jsonOutput = $this->option('json');
        $markReleased = $this->option('mark-released');

        // If just marking as released, do that and exit.
        if ($markReleased) {
            if (!$sha) {
                $this->error('--sha is required with --mark-released');
                return Command::FAILURE;
            }
            $service->saveLastProductionSha($sha);
            $this->info("Marked SHA {$sha} as released to production.");
            return Command::SUCCESS;
        }

        Log::info('ClassifyAppReleaseCommand: Starting', [
            'sha' => $sha,
            'notify' => $notify,
        ]);

        try {
            $result = $service->classify($sha);

            if ($jsonOutput) {
                $this->line(json_encode([
                    'classification' => $result['classification'],
                    'reason' => $result['reason'],
                    'should_promote' => $result['should_promote'],
                    'commit_count' => count($result['commits']),
                    'urgent_count' => count($result['urgent_commits']),
                ], JSON_PRETTY_PRINT));
            } else {
                $this->displayResult($result);
            }

            // Send notification only for URGENT (hotfix) commits.
            // We don't need to email for CAN_WAIT or NO_CHANGES.
            if (($notify || $forceNotify) && $result['classification'] === AppReleaseClassifierService::CLASSIFICATION_URGENT) {
                $sent = $service->sendNotification($result, $forceNotify);
                if ($sent) {
                    $this->info('Notification email sent - hotfix detected!');
                } else {
                    $this->warn('Notification not sent (within rate limit interval).');
                }
            }

            // Output for CI integration - set environment variable.
            // CircleCI can capture this from stdout.
            if ($jsonOutput) {
                // JSON mode - caller parses JSON.
            } else {
                $this->newLine();
                $this->line("CLASSIFICATION={$result['classification']}");
                $this->line("SHOULD_PROMOTE=" . ($result['should_promote'] ? 'true' : 'false'));
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            Log::error('ClassifyAppReleaseCommand: Failed', [
                'error' => $e->getMessage(),
            ]);

            if ($jsonOutput) {
                $this->line(json_encode([
                    'error' => $e->getMessage(),
                    'classification' => null,
                ]));
            } else {
                $this->error('Classification failed: ' . $e->getMessage());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Display the classification result in a human-readable format.
     */
    protected function displayResult(array $result): void
    {
        $classification = $result['classification'];
        $reason = $result['reason'];
        $commits = $result['commits'];
        $urgentCommits = $result['urgent_commits'];

        $icon = match ($classification) {
            AppReleaseClassifierService::CLASSIFICATION_URGENT => '🚨',
            AppReleaseClassifierService::CLASSIFICATION_CAN_WAIT => '📦',
            AppReleaseClassifierService::CLASSIFICATION_NO_CHANGES => 'ℹ️',
        };

        $this->newLine();
        $this->line("{$icon} Classification: <comment>{$classification}</comment>");
        $this->line("   Reason: {$reason}");
        $this->line("   Commits since last production: " . count($commits));

        if ($result['should_promote']) {
            $this->newLine();
            $this->info('➡️  Recommendation: Promote to production immediately');
        } else {
            $this->newLine();
            $this->comment('➡️  Recommendation: Batch for Wednesday night release');
        }

        if (!empty($urgentCommits)) {
            $this->newLine();
            $this->line('Urgent commits:');
            foreach ($urgentCommits as $commit) {
                $shortHash = substr($commit['hash'], 0, 8);
                $this->line("  - <info>{$shortHash}</info>: {$commit['message']}");
            }
        }

        if (!empty($commits) && count($commits) <= 10) {
            $this->newLine();
            $this->line('All commits:');
            foreach ($commits as $commit) {
                $shortHash = substr($commit['hash'], 0, 8);
                $this->line("  - {$commit['date']} <info>{$shortHash}</info>: {$commit['message']}");
            }
        } elseif (count($commits) > 10) {
            $this->newLine();
            $this->line("Showing first 10 of " . count($commits) . " commits:");
            foreach (array_slice($commits, 0, 10) as $commit) {
                $shortHash = substr($commit['hash'], 0, 8);
                $this->line("  - {$commit['date']} <info>{$shortHash}</info>: {$commit['message']}");
            }
        }
    }
}
