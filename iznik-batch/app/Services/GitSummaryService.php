<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Service for generating AI-powered git commit summaries.
 *
 * Analyzes commits across multiple repositories and generates human-readable
 * summaries organized by user impact. Summaries can be sent to Discourse
 * via email-to-forum integration.
 */
class GitSummaryService
{
    /**
     * Config table key for storing last run timestamp.
     */
    protected const CONFIG_KEY_LAST_RUN = 'git_summary_last_run';

    /**
     * Gemini service for AI summarization.
     */
    protected GeminiService $gemini;

    /**
     * GeekAlerts email address for failure notifications.
     */
    protected string $geekAlertsEmail;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
        $this->geekAlertsEmail = config('freegle.mail.geek_alerts_addr', 'geek-alerts@ilovefreegle.org');
    }

    /**
     * Get the last run timestamp, defaulting to max days back.
     *
     * @param string|null $override Override date (YYYY-MM-DD or relative like '-3 days').
     * @return int Unix timestamp.
     */
    public function getLastRunTime(?string $override = null): int
    {
        if ($override !== null) {
            return strtotime($override);
        }

        try {
            $row = DB::table('config')
                ->where('key', self::CONFIG_KEY_LAST_RUN)
                ->first();

            if ($row && !empty($row->value)) {
                $lastRun = (int) $row->value;

                // Ensure we don't go back more than max days.
                $maxDaysBack = config('freegle.git_summary.max_days_back', 7);
                $maxBack = time() - ($maxDaysBack * 24 * 60 * 60);
                if ($lastRun < $maxBack) {
                    $lastRun = $maxBack;
                }

                return $lastRun;
            }
        } catch (\Exception $e) {
            Log::warning('GitSummaryService: Failed to read last run time', [
                'error' => $e->getMessage(),
            ]);
        }

        // Default to max days back.
        $maxDaysBack = config('freegle.git_summary.max_days_back', 7);
        return time() - ($maxDaysBack * 24 * 60 * 60);
    }

    /**
     * Save the current run timestamp.
     */
    public function saveRunTime(): void
    {
        $timestamp = (string) time();

        DB::statement(
            "INSERT INTO config (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?",
            [self::CONFIG_KEY_LAST_RUN, $timestamp, $timestamp]
        );
    }

    /**
     * Get commits and diffs for a repository since a given time.
     *
     * @param string $repoUrl Repository URL.
     * @param string $branch Branch name.
     * @param int $since Unix timestamp.
     * @param int|null $until Unix timestamp (optional end date for catching up).
     * @return array|null Repository changes or null if no changes.
     */
    public function getRepositoryChanges(string $repoUrl, string $branch, int $since, ?int $until = null): ?array
    {
        $tempDir = sys_get_temp_dir() . '/iznik_git_' . uniqid();
        mkdir($tempDir);

        try {
            // Embed GitHub token for private repos if available.
            $cloneUrl = $repoUrl;
            $token = config('freegle.git_summary.github_token');
            if ($token && str_contains($repoUrl, 'github.com')) {
                $cloneUrl = str_replace('https://github.com/', "https://{$token}@github.com/", $repoUrl);
            }

            // Clone the repository.
            $cloneCmd = sprintf(
                'git clone --single-branch --branch %s %s %s 2>&1',
                escapeshellarg($branch),
                escapeshellarg($cloneUrl),
                escapeshellarg($tempDir)
            );
            exec($cloneCmd, $output, $return);

            if ($return !== 0) {
                $rawOutput = implode("\n", $output);
                if ($token) {
                    $rawOutput = str_replace($token, '***', $rawOutput);
                }
                Log::error('GitSummaryService: Failed to clone repository', [
                    'url' => $repoUrl,
                    'branch' => $branch,
                    'output' => $rawOutput,
                ]);
                return null;
            }

            $cwd = getcwd();
            chdir($tempDir);

            // Get commits since the timestamp (and optionally until a date).
            $sinceDate = date('Y-m-d H:i:s', $since);
            $untilArg = '';
            if ($until !== null) {
                $untilDate = date('Y-m-d H:i:s', $until);
                $untilArg = sprintf(' --until=%s', escapeshellarg($untilDate));
            }
            $logCmd = sprintf(
                'git log --since=%s%s --pretty=format:"%%H|%%an|%%ad|%%s" --date=short 2>&1',
                escapeshellarg($sinceDate),
                $untilArg
            );
            exec($logCmd, $commits, $return);

            if (empty($commits)) {
                chdir($cwd);
                return null;
            }

            // Get the diff for all commits combined.
            $firstCommit = explode('|', $commits[count($commits) - 1])[0];
            $lastCommit = explode('|', $commits[0])[0];

            $diffCmd = sprintf(
                'git diff --stat %s^..%s 2>&1',
                escapeshellarg($firstCommit),
                escapeshellarg($lastCommit)
            );
            exec($diffCmd, $statOutput, $return);

            // Get full diff (limited to avoid token limits).
            $diffCmd = sprintf(
                'git diff %s^..%s 2>&1',
                escapeshellarg($firstCommit),
                escapeshellarg($lastCommit)
            );
            exec($diffCmd, $diffOutput, $return);

            chdir($cwd);

            // Limit diff size to avoid token limits (approx 50k characters).
            $fullDiff = implode("\n", $diffOutput);
            if (strlen($fullDiff) > 50000) {
                $fullDiff = substr($fullDiff, 0, 50000) . "\n\n... (diff truncated due to size)";
            }

            return [
                'commits' => array_map(function ($commit) {
                    $parts = explode('|', $commit, 4);
                    return [
                        'hash' => $parts[0] ?? '',
                        'author' => $parts[1] ?? '',
                        'date' => $parts[2] ?? '',
                        'message' => $parts[3] ?? '',
                    ];
                }, $commits),
                'stat' => implode("\n", $statOutput),
                'diff' => $fullDiff,
            ];
        } finally {
            // Cleanup.
            exec(sprintf('rm -rf %s', escapeshellarg($tempDir)));
        }
    }

    /**
     * Generate AI summary of all changes across repositories.
     *
     * @param array $allChanges Array of repository changes.
     * @return string AI-generated summary.
     */
    public function summarizeAllChanges(array $allChanges): string
    {
        if (!$this->gemini->isConfigured()) {
            return "Error: Gemini API key not configured. Cannot generate AI summary.";
        }

        $prompt = "You are summarizing code changes for readers familiar with Freegle (a UK community reuse network similar to Freecycle where people give away unwanted items and help each other).\n\n";

        $prompt .= "IMPORTANT: Many features require changes across multiple code repositories (frontend + backend). ";
        $prompt .= "When you see the same feature or fix mentioned in multiple repositories, describe the OVERALL PURPOSE once, not each technical implementation separately.\n\n";

        $prompt .= "The following repositories were updated:\n\n";

        foreach ($allChanges as $change) {
            $prompt .= "=== {$change['repo']} ({$change['category']}) ===\n";
            $prompt .= "Commits:\n";
            foreach ($change['commits'] as $commit) {
                $prompt .= "- {$commit['date']}: {$commit['message']}\n";
            }
            $prompt .= "\nFiles changed:\n{$change['stat']}\n\n";
        }

        $prompt .= "\n\nPlease provide a structured summary organized by user impact.\n\n";
        $prompt .= "Start with a brief intro paragraph (2-3 sentences) that:\n";
        $prompt .= "- Explains this is an AI-generated summary of recent code changes\n";
        $prompt .= "- Uses British English spelling and phrasing (e.g., 'organised' not 'organized', 'whilst' is acceptable)\n";
        $prompt .= "- Has a straightforward, matter-of-fact tone - not overly enthusiastic or promotional\n\n";

        $prompt .= "Then organise the changes into these sections:\n\n";
        $prompt .= "## FREEGLE DIRECT (Main Website for Members)\n";
        $prompt .= "List changes that affect regular users of the Freegle website (3-5 bullet points).\n";
        $prompt .= "IMPORTANT: Sort items by impact - put changes that affect the most users most significantly at the top of the list.\n\n";

        $prompt .= "## MODTOOLS (Volunteer Website)\n";
        $prompt .= "List changes that affect volunteers/moderators (3-5 bullet points).\n";
        $prompt .= "IMPORTANT: Sort items by impact - put changes that affect the most volunteers most significantly at the top of the list.\n\n";

        $prompt .= "## BACKEND SYSTEMS (Behind the scenes)\n";
        $prompt .= "List technical improvements that don't directly change the user interface (2-3 bullet points).\n";
        $prompt .= "IMPORTANT: Sort items by impact - put changes with the biggest effect on system performance or reliability at the top.\n\n";

        $prompt .= "Guidelines:\n";
        $prompt .= "- When a feature spans multiple repos (e.g., frontend + API), describe it ONCE under the appropriate user-facing category\n";
        $prompt .= "- Use simple, direct language. Avoid formal business speak. Say 'makes it easier' not 'ensuring a smoother experience'\n";
        $prompt .= "- Use active, plain language: 'We fixed' not 'has been resolved', 'You can now' not 'functionality has been enhanced'\n";
        $prompt .= "- Use British English spelling and phrasing throughout\n";
        $prompt .= "- Keep tone casual and straightforward, like explaining to a friend - not overly formal or corporate\n";
        $prompt .= "- Focus on WHAT changed, not HOW it was implemented technically\n";
        $prompt .= "- Identify prototype/experimental/investigation code clearly (look for test files, 'investigate', 'analyse', 'simulation', 'prototype' in commit messages or file paths)\n";
        $prompt .= "- When describing prototype work, say 'investigating', 'prototyping', 'testing approaches for' rather than implying it's live\n";
        $prompt .= "- If a category has no changes, say 'No changes in this period'\n";
        $prompt .= "- Be specific but concise - get straight to the point\n";
        $prompt .= "- Use bullet points starting with '-'\n\n";
        $prompt .= "Do not include repository names in your summary - just describe the changes by user impact.";

        $summary = $this->gemini->generateContent($prompt);

        if ($summary === null) {
            return "Error generating summary: Gemini API call failed.";
        }

        return $summary;
    }

    /**
     * Generate the full report.
     *
     * @param string|null $sinceOverride Override the since date.
     * @param string|null $untilOverride Override the until date (for catching up on missed weeks).
     * @return array Result with 'html', 'changes', 'since_date', optionally 'until_date'.
     */
    public function generateReport(?string $sinceOverride = null, ?string $untilOverride = null): array
    {
        $since = $this->getLastRunTime($sinceOverride);
        $sinceDate = date('Y-m-d', $since);

        $until = $untilOverride !== null ? strtotime($untilOverride) : null;
        $untilDate = $until !== null ? date('Y-m-d', $until) : null;

        Log::info('GitSummaryService: Analyzing changes', [
            'since' => $sinceDate,
            'until' => $untilDate,
        ]);

        // Collect all changes first.
        $allChanges = [];
        $repositories = config('freegle.git_summary.repositories', []);

        foreach ($repositories as $repo) {
            Log::info('GitSummaryService: Processing repository', [
                'name' => $repo['name'],
                'branch' => $repo['branch'],
            ]);

            $changes = $this->getRepositoryChanges($repo['url'], $repo['branch'], $since, $until);

            if ($changes === null) {
                Log::info('GitSummaryService: No changes found', ['repo' => $repo['name']]);
                continue;
            }

            Log::info('GitSummaryService: Found commits', [
                'repo' => $repo['name'],
                'count' => count($changes['commits']),
            ]);

            $allChanges[] = [
                'repo' => $repo['name'],
                'category' => $repo['category'],
                'commits' => $changes['commits'],
                'stat' => $changes['stat'],
                'diff' => $changes['diff'],
            ];
        }

        if (empty($allChanges)) {
            Log::info('GitSummaryService: No changes in any repository');
            $result = [
                'html' => $this->buildEmptyEmail($sinceDate, $untilDate),
                'changes' => [],
                'since_date' => $sinceDate,
            ];
            if ($untilDate !== null) {
                $result['until_date'] = $untilDate;
            }
            return $result;
        }

        // Summarize all changes together with AI.
        Log::info('GitSummaryService: Generating AI summary');
        $summary = $this->summarizeAllChanges($allChanges);

        // Build the email.
        $html = $this->buildEmail($summary, $sinceDate, $untilDate);

        $result = [
            'html' => $html,
            'changes' => $allChanges,
            'since_date' => $sinceDate,
            'summary' => $summary,
        ];
        if ($untilDate !== null) {
            $result['until_date'] = $untilDate;
        }
        return $result;
    }

    /**
     * Convert inline Markdown to HTML.
     *
     * Handles **bold**, *italic*, and `code` formatting.
     */
    protected function convertInlineMarkdown(string $text): string
    {
        // Convert **bold** to <strong>
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);

        // Convert *italic* to <em> (but not if it's a bullet point marker)
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text);

        // Convert `code` to <code>
        $text = preg_replace('/`([^`]+)`/', '<code style="background-color: #f0f0f0; padding: 2px 4px; border-radius: 3px;">$1</code>', $text);

        return $text;
    }

    /**
     * Build the email content with AI-generated summary.
     */
    protected function buildEmail(string $aiSummary, string $sinceDate, ?string $untilDate = null): string
    {
        $generatedDate = date('l, j F Y \a\t H:i');
        $dateRange = $untilDate !== null
            ? "{$sinceDate} to {$untilDate}"
            : $sinceDate;

        // Process line by line to properly handle different elements.
        $lines = explode("\n", $aiSummary);
        $htmlLines = [];
        $inList = false;
        $currentParagraph = '';

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines.
            if (empty($line)) {
                if ($currentParagraph) {
                    $htmlLines[] = '<p style="line-height: 1.6; margin-bottom: 12px;">' . $this->convertInlineMarkdown($currentParagraph) . '</p>';
                    $currentParagraph = '';
                }
                if ($inList) {
                    $htmlLines[] = '</ul>';
                    $inList = false;
                }
                continue;
            }

            // Handle headings.
            if (preg_match('/^## (.+)$/', $line, $matches)) {
                if ($currentParagraph) {
                    $htmlLines[] = '<p style="line-height: 1.6; margin-bottom: 12px;">' . $this->convertInlineMarkdown($currentParagraph) . '</p>';
                    $currentParagraph = '';
                }
                if ($inList) {
                    $htmlLines[] = '</ul>';
                    $inList = false;
                }
                $htmlLines[] = '<h2 style="color: #2c5282; margin-top: 24px; margin-bottom: 16px; font-size: 20px; display: block;">' . htmlspecialchars($matches[1]) . '</h2>';
                $htmlLines[] = '<div style="height: 8px;"></div>';
                continue;
            }

            if (preg_match('/^# (.+)$/', $line, $matches)) {
                if ($currentParagraph) {
                    $htmlLines[] = '<p style="line-height: 1.6; margin-bottom: 12px;">' . $this->convertInlineMarkdown($currentParagraph) . '</p>';
                    $currentParagraph = '';
                }
                if ($inList) {
                    $htmlLines[] = '</ul>';
                    $inList = false;
                }
                $htmlLines[] = '<h1 style="color: #1a365d; margin-top: 24px; margin-bottom: 20px; font-size: 24px; display: block;">' . htmlspecialchars($matches[1]) . '</h1>';
                $htmlLines[] = '<div style="height: 8px;"></div>';
                continue;
            }

            // Handle bullet points.
            if (preg_match('/^[-*]\s+(.+)$/', $line, $matches)) {
                if ($currentParagraph) {
                    $htmlLines[] = '<p style="line-height: 1.6; margin-bottom: 12px;">' . $this->convertInlineMarkdown($currentParagraph) . '</p>';
                    $currentParagraph = '';
                }
                if (!$inList) {
                    $htmlLines[] = '<ul style="margin: 12px 0; padding-left: 24px; line-height: 1.6;">';
                    $inList = true;
                }
                $htmlLines[] = '<li style="margin-bottom: 8px;">' . $this->convertInlineMarkdown($matches[1]) . '</li>';
                continue;
            }

            // Regular paragraph text.
            if ($inList) {
                $htmlLines[] = '</ul>';
                $inList = false;
            }
            if ($currentParagraph) {
                $currentParagraph .= ' ';
            }
            $currentParagraph .= $line;
        }

        // Close any remaining open elements.
        if ($currentParagraph) {
            $htmlLines[] = '<p style="line-height: 1.6; margin-bottom: 12px;">' . $this->convertInlineMarkdown($currentParagraph) . '</p>';
        }
        if ($inList) {
            $htmlLines[] = '</ul>';
        }

        $htmlSummary = implode("\n", $htmlLines);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
    <div style="background-color: white; border-radius: 8px; padding: 32px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h1 style="color: #1a365d; border-bottom: 3px solid #4299e1; padding-bottom: 12px; margin-bottom: 24px; font-size: 28px;">
            Freegle Code Changes Summary
        </h1>

        <div style="background-color: #ebf8ff; border-left: 4px solid #4299e1; padding: 16px; margin-bottom: 24px; border-radius: 4px;">
            <p style="margin: 0; line-height: 1.6;">
                <strong>Changes since:</strong> {$dateRange}<br>
                <strong>Generated:</strong> {$generatedDate}
            </p>
        </div>

        <div style="color: #2d3748;">
            {$htmlSummary}
        </div>

        <hr style="border: none; border-top: 2px solid #e2e8f0; margin: 32px 0;">

        <p style="color: #718096; font-size: 14px; line-height: 1.6; margin: 0;">
            If you have any questions about these changes, please reply to this post.
        </p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Build an empty email when there are no changes.
     */
    protected function buildEmptyEmail(string $sinceDate, ?string $untilDate = null): string
    {
        $generatedDate = date('l, j F Y \a\t H:i');
        $dateRange = $untilDate !== null
            ? "{$sinceDate} to {$untilDate}"
            : $sinceDate;

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
    <div style="background-color: white; border-radius: 8px; padding: 32px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h1 style="color: #1a365d; border-bottom: 3px solid #4299e1; padding-bottom: 12px; margin-bottom: 24px; font-size: 28px;">
            Freegle Code Changes Summary
        </h1>

        <div style="background-color: #ebf8ff; border-left: 4px solid #4299e1; padding: 16px; margin-bottom: 24px; border-radius: 4px;">
            <p style="margin: 0; line-height: 1.6;">
                <strong>Changes since:</strong> {$dateRange}<br>
                <strong>Generated:</strong> {$generatedDate}
            </p>
        </div>

        <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 16px; margin-bottom: 24px; border-radius: 4px;">
            <p style="margin: 0; line-height: 1.6;">
                No code changes were found in any repository during this period.
            </p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Send the report via email.
     *
     * @param string|null $sinceOverride Override the since date.
     * @param string|null $untilOverride Override the until date (for catching up).
     * @param string|null $emailOverride Override the recipient email.
     * @param bool $updateTimestamp Whether to update the last run timestamp.
     * @return array Result with 'success', 'message', 'report'.
     */
    public function sendReport(?string $sinceOverride = null, ?string $untilOverride = null, ?string $emailOverride = null, bool $updateTimestamp = true): array
    {
        $report = $this->generateReport($sinceOverride, $untilOverride);

        $subject = date('d-m-Y') . ' Freegle Code Changes Summary (AI Generated)';
        $to = $emailOverride ?? config('freegle.git_summary.discourse_email');

        try {
            // Use configurable FROM address - Discourse may filter by sender
            $from = config('freegle.git_summary.from_address', 'geeks@ilovefreegle.org');
            $fromName = config('freegle.git_summary.from_name', 'Freegle Geeks');

            // Generate plain text version for email clients that prefer it (like Discourse)
            $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</h1>', '</h2>'], "\n", $report['html']));
            $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');
            $plainText = preg_replace('/\n{3,}/', "\n\n", $plainText); // Collapse multiple newlines

            Mail::send([], [], function ($message) use ($to, $subject, $from, $fromName, $report, $plainText) {
                $message->to($to)
                    ->from($from, $fromName)
                    ->subject($subject)
                    ->html($report['html'])
                    ->text($plainText);
            });

            if ($updateTimestamp && $sinceOverride === null) {
                $this->saveRunTime();
            }

            Log::info('GitSummaryService: Report sent successfully', [
                'to' => $to,
                'since' => $report['since_date'],
                'commit_count' => array_sum(array_map(fn($c) => count($c['commits']), $report['changes'])),
            ]);

            return [
                'success' => true,
                'message' => "Report sent to {$to}",
                'report' => $report,
            ];

        } catch (\Exception $e) {
            Log::error('GitSummaryService: Failed to send report', [
                'error' => $e->getMessage(),
            ]);

            $this->sendAlert("Failed to send git summary report: " . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'report' => $report,
            ];
        }
    }

    /**
     * Send alert email on failure.
     */
    protected function sendAlert(string $error): void
    {
        try {
            $subject = 'Git Summary Report Failed';
            $body = "Failed to generate or send the git summary report.\n\n"
                . "Error: {$error}\n\n"
                . "Please investigate and manually run if necessary.";

            Mail::raw($body, function ($message) use ($subject) {
                $message->to($this->geekAlertsEmail)
                    ->from(config('mail.from.address'), config('mail.from.name'))
                    ->subject($subject);
            });

        } catch (\Exception $e) {
            Log::error('GitSummaryService: Failed to send alert email', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
