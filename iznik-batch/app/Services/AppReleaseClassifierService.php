<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Service for classifying app release urgency and managing release scheduling.
 *
 * This service determines whether an app release should be promoted to production
 * immediately (urgent) or batched for the weekly release (Wednesday night).
 *
 * Classification is based on commit message prefixes following Conventional Commits:
 * - `hotfix:` prefix → URGENT (promote immediately)
 * - All other commits → CAN_WAIT (batch for Wednesday night)
 *
 * Note: Beta releases (TestFlight/Google Play Beta) always happen immediately.
 * This service only gates the PROMOTION to production stores.
 */
class AppReleaseClassifierService
{
    /**
     * Classification result constants.
     */
    public const CLASSIFICATION_URGENT = 'URGENT';
    public const CLASSIFICATION_CAN_WAIT = 'CAN_WAIT';
    public const CLASSIFICATION_NO_CHANGES = 'NO_CHANGES';

    /**
     * Config table keys.
     */
    protected const CONFIG_KEY_LAST_PRODUCTION_SHA = 'app_release_last_production_sha';
    protected const CONFIG_KEY_LAST_NOTIFICATION = 'app_release_last_notification';

    /**
     * Notification email address.
     */
    protected string $notificationEmail;

    public function __construct()
    {
        $this->notificationEmail = config('freegle.app_release.notification_email', 'geek-alerts@ilovefreegle.org');
    }

    /**
     * Get the SHA of the last production release.
     */
    public function getLastProductionSha(): ?string
    {
        try {
            $row = DB::table('config')
                ->where('key', self::CONFIG_KEY_LAST_PRODUCTION_SHA)
                ->first();

            return $row ? $row->value : null;
        } catch (\Exception $e) {
            Log::warning('AppReleaseClassifierService: Failed to get last production SHA', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Save the SHA of the last production release.
     */
    public function saveLastProductionSha(string $sha): void
    {
        DB::statement(
            "INSERT INTO config (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?",
            [self::CONFIG_KEY_LAST_PRODUCTION_SHA, $sha, $sha]
        );
    }

    /**
     * Get commits since the last production release.
     *
     * @param string|null $currentSha Current commit SHA (if known).
     * @return array Array of commits with hash, author, date, message.
     */
    public function getCommitsSinceLastProduction(?string $currentSha = null): array
    {
        $lastSha = $this->getLastProductionSha();

        $tempDir = sys_get_temp_dir() . '/iznik_release_' . uniqid();
        mkdir($tempDir);

        try {
            // Clone the production branch.
            $repoUrl = 'https://github.com/Freegle/iznik-nuxt3.git';
            $branch = 'production';

            $cloneCmd = sprintf(
                'git clone --single-branch --branch %s %s %s 2>&1',
                escapeshellarg($branch),
                escapeshellarg($repoUrl),
                escapeshellarg($tempDir)
            );
            exec($cloneCmd, $output, $return);

            if ($return !== 0) {
                Log::error('AppReleaseClassifierService: Failed to clone repository', [
                    'output' => implode("\n", $output),
                ]);
                return [];
            }

            $cwd = getcwd();
            chdir($tempDir);

            // Get the current HEAD SHA if not provided.
            if ($currentSha === null) {
                exec('git rev-parse HEAD', $headOutput);
                $currentSha = trim($headOutput[0] ?? '');
            }

            // Get commits since last production release.
            if ($lastSha) {
                $logCmd = sprintf(
                    'git log %s..%s --pretty=format:"%%H|%%an|%%ad|%%s" --date=short 2>&1',
                    escapeshellarg($lastSha),
                    escapeshellarg($currentSha)
                );
            } else {
                // No previous release - get last 7 days of commits.
                $logCmd = 'git log --since="-7 days" --pretty=format:"%H|%an|%ad|%s" --date=short 2>&1';
            }

            exec($logCmd, $commits, $return);
            chdir($cwd);

            if (empty($commits)) {
                return [];
            }

            return array_map(function ($commit) {
                $parts = explode('|', $commit, 4);
                return [
                    'hash' => $parts[0] ?? '',
                    'author' => $parts[1] ?? '',
                    'date' => $parts[2] ?? '',
                    'message' => $parts[3] ?? '',
                ];
            }, $commits);

        } finally {
            exec(sprintf('rm -rf %s', escapeshellarg($tempDir)));
        }
    }

    /**
     * Find commits with the hotfix: prefix (Conventional Commits standard).
     *
     * @param array $commits Array of commits.
     * @return array All hotfix commits found.
     */
    public function findHotfixCommits(array $commits): array
    {
        $hotfixCommits = [];

        foreach ($commits as $commit) {
            $message = trim($commit['message']);
            // Check for hotfix: prefix (case-insensitive)
            if (preg_match('/^hotfix:/i', $message)) {
                $hotfixCommits[] = $commit;
            }
        }

        return $hotfixCommits;
    }

    /**
     * Classify commits and determine if production release is needed.
     *
     * Classification is simple:
     * - If any commit has `hotfix:` prefix → URGENT
     * - Otherwise → CAN_WAIT (batch for Wednesday night)
     *
     * @param string|null $currentSha Current commit SHA.
     * @return array Classification result.
     */
    public function classify(?string $currentSha = null): array
    {
        $commits = $this->getCommitsSinceLastProduction($currentSha);

        // No changes since last production release.
        if (empty($commits)) {
            return [
                'classification' => self::CLASSIFICATION_NO_CHANGES,
                'reason' => 'No commits since last production release',
                'commits' => [],
                'urgent_commits' => [],
                'should_promote' => false,
            ];
        }

        Log::info('AppReleaseClassifierService: Classifying commits', [
            'count' => count($commits),
        ]);

        // Check for hotfix: prefix (Conventional Commits standard).
        $hotfixCommits = $this->findHotfixCommits($commits);

        if (!empty($hotfixCommits)) {
            $hotfixMessages = array_map(fn($c) => $c['message'], $hotfixCommits);
            return [
                'classification' => self::CLASSIFICATION_URGENT,
                'reason' => 'Hotfix commits detected: ' . implode(', ', $hotfixMessages),
                'commits' => $commits,
                'urgent_commits' => $hotfixCommits,
                'should_promote' => true,
            ];
        }

        // No hotfix commits - batch for Wednesday.
        return [
            'classification' => self::CLASSIFICATION_CAN_WAIT,
            'reason' => 'No hotfix: commits found - batching for Wednesday night release',
            'commits' => $commits,
            'urgent_commits' => [],
            'should_promote' => false,
        ];
    }

    /**
     * Check if enough time has passed since the last notification.
     */
    protected function canSendNotification(): bool
    {
        try {
            $row = DB::table('config')
                ->where('key', self::CONFIG_KEY_LAST_NOTIFICATION)
                ->first();

            if (!$row) {
                return true;
            }

            $lastNotification = (int) $row->value;
            $interval = config('freegle.app_release.notification_interval', 3600);

            return (time() - $lastNotification) >= $interval;
        } catch (\Exception $e) {
            return true;
        }
    }

    /**
     * Update the last notification timestamp.
     */
    protected function updateNotificationTimestamp(): void
    {
        $timestamp = (string) time();

        DB::statement(
            "INSERT INTO config (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?",
            [self::CONFIG_KEY_LAST_NOTIFICATION, $timestamp, $timestamp]
        );
    }

    /**
     * Send notification email about the classification.
     *
     * @param array $result Classification result.
     * @param bool $force Force sending even if within interval.
     * @return bool Whether the notification was sent.
     */
    public function sendNotification(array $result, bool $force = false): bool
    {
        if (!$force && !$this->canSendNotification()) {
            Log::info('AppReleaseClassifierService: Skipping notification (within interval)');
            return false;
        }

        $classification = $result['classification'];
        $reason = $result['reason'];
        $commitCount = count($result['commits'] ?? []);
        $urgentCount = count($result['urgent_commits'] ?? []);

        $subject = match ($classification) {
            self::CLASSIFICATION_URGENT => "🚨 App Release: URGENT - Immediate promotion recommended",
            self::CLASSIFICATION_CAN_WAIT => "📦 App Release: Batched for Wednesday",
            self::CLASSIFICATION_NO_CHANGES => "ℹ️ App Release: No changes to promote",
        };

        $body = "App Release Classification Report\n";
        $body .= "================================\n\n";
        $body .= "Classification: {$classification}\n";
        $body .= "Reason: {$reason}\n";
        $body .= "Commits since last production: {$commitCount}\n";

        if ($urgentCount > 0) {
            $body .= "Urgent commits: {$urgentCount}\n\n";
            $body .= "Urgent commit details:\n";
            foreach ($result['urgent_commits'] as $commit) {
                $body .= "  - {$commit['hash']}: {$commit['message']}\n";
            }
        }

        $body .= "\n";

        if ($commitCount > 0 && $commitCount <= 20) {
            $body .= "All commits:\n";
            foreach ($result['commits'] as $commit) {
                $body .= "  - {$commit['date']} {$commit['hash']}: {$commit['message']}\n";
            }
        } elseif ($commitCount > 20) {
            $body .= "Recent commits (showing first 20 of {$commitCount}):\n";
            foreach (array_slice($result['commits'], 0, 20) as $commit) {
                $body .= "  - {$commit['date']} {$commit['hash']}: {$commit['message']}\n";
            }
        }

        $body .= "\n---\n";
        $body .= "Generated: " . date('Y-m-d H:i:s') . "\n";

        if ($classification === self::CLASSIFICATION_URGENT) {
            $body .= "\nTo manually trigger production promotion, go to CircleCI and trigger a new pipeline with parameter:\n";
            $body .= "  run_manual_promote = true\n";
        }

        try {
            Mail::raw($body, function ($message) use ($subject) {
                $message->to($this->notificationEmail)
                    ->from(config('mail.from.address'), config('mail.from.name'))
                    ->subject($subject);
            });

            $this->updateNotificationTimestamp();

            Log::info('AppReleaseClassifierService: Notification sent', [
                'classification' => $classification,
                'to' => $this->notificationEmail,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('AppReleaseClassifierService: Failed to send notification', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Trigger a CircleCI pipeline for manual promotion.
     *
     * @return array Result with 'success' and 'message'.
     */
    public function triggerManualPromotion(): array
    {
        $token = config('freegle.app_release.circleci_token');
        $project = config('freegle.app_release.circleci_project');

        if (empty($token)) {
            return [
                'success' => false,
                'message' => 'CircleCI token not configured',
            ];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(30)
                ->post("https://circleci.com/api/v2/project/{$project}/pipeline", [
                    'branch' => 'production',
                    'parameters' => [
                        'run_manual_promote' => true,
                    ],
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'message' => "Pipeline triggered: {$data['id']}",
                    'pipeline_id' => $data['id'],
                ];
            }

            return [
                'success' => false,
                'message' => "CircleCI API error: " . $response->status(),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check the days since last production release.
     */
    public function daysSinceLastProduction(): ?int
    {
        try {
            $row = DB::table('config')
                ->where('key', self::CONFIG_KEY_LAST_PRODUCTION_SHA)
                ->first();

            if (!$row) {
                return null;
            }

            // We store SHA, not timestamp. Need to check git for the date.
            // For now, return null to indicate we need to track this differently.
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Determine if it's time for the weekly scheduled release (Wednesday night).
     *
     * @param string|null $now Override current time for testing.
     * @return bool
     */
    public function isWeeklyReleaseTime(?string $now = null): bool
    {
        $timestamp = $now ? strtotime($now) : time();
        $dayOfWeek = (int) date('N', $timestamp); // 1 = Monday, 7 = Sunday
        $hour = (int) date('G', $timestamp);

        // Wednesday (3) at night (after 10 PM UTC).
        return $dayOfWeek === 3 && $hour >= 22;
    }
}
