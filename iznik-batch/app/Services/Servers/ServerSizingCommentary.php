<?php

namespace App\Services\Servers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Asks Claude to read the week's sizing figures and write the short review at the top of the
 * report: what to change, what to watch, and what the rules cannot see.
 *
 * The figures and the verdicts come from ServerSizingAnalyser. Claude is given them as data and
 * told to add no numbers of its own, so the review can be checked against the tables under it.
 * It runs on the Claude subscription through the `claude` CLI and a setup token, as the Community
 * News research does. If the call fails the report still goes out, without the review.
 */
class ServerSizingCommentary
{
    public const SYSTEM_PROMPT = <<<'TXT'
You review a weekly report on whether a small charity's rented servers are the right size. The
reader is the one part-time engineer who runs them. They know the servers; do not explain basics.

You are given JSON: each server's package, cost, a week of load, processor and memory readings,
a week-by-week trend, its disks, and a verdict worked out by fixed rules (keep, step_down, step_up,
wait, stopped, unmeasured). "wait" means there are not yet enough days of readings to act, and
"indication" is what the readings point to so far.

Write plain text, no Markdown, in this exact shape:

CHANGES
- one line per change worth making now, most valuable first, with the monthly saving or cost
WATCH
- one line per thing to keep an eye on
CAVEATS
- one line per thing the rules cannot see that should make the reader doubt a verdict

Rules:
- Use only numbers that appear in the JSON. Never work out or estimate a new figure.
- Money is the "paid" figure, in pounds a month.
- If a section has nothing in it, write "- Nothing this week." under it.
- Do not recommend a change the verdict does not support. You may advise against one it does, with the reason.
- A database server's memory is filled on purpose by its cache, so high memory use there is not a reason to grow it, and it cannot shrink without the cache being made smaller first.
- Taking processors or memory away needs the server shut down; adding them does not. Disks only grow.
- At most 12 lines in total. Short plain sentences. British spelling. No dashes used as punctuation.
TXT;

    /**
     * @param  array<string, mixed>  $analysis
     * @return array{changes: list<string>, watch: list<string>, caveats: list<string>}|null
     */
    public function write(array $analysis): ?array
    {
        $token = trim((string) config('freegle.servers.oauth_token', ''));
        if ($token === '') {
            Log::info('servers:rightsize-report has no Claude token; sending without the review');

            return null;
        }

        $configDir = trim((string) config('freegle.servers.claude_config_dir', ''));
        if ($configDir === '') {
            $configDir = rtrim(sys_get_temp_dir(), '/').'/servers-claude';
        }
        @mkdir($configDir, 0700, true);

        $command = [
            (string) config('freegle.servers.claude_bin', 'claude'),
            '-p', "This week's figures:\n".json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            '--append-system-prompt', self::SYSTEM_PROMPT,
            '--model', (string) config('freegle.servers.model', 'opus'),
            '--output-format', 'json',
        ];

        try {
            // Run in the empty config directory, so the CLI's file tools have nothing of ours to read.
            $result = Process::timeout(300)
                ->path($configDir)
                ->env([
                    'CLAUDE_CODE_OAUTH_TOKEN' => $token,
                    'CLAUDE_CONFIG_DIR' => $configDir,
                    // With an API key in the environment the CLI bills the metered key, not the
                    // subscription; false drops it from the child's environment.
                    'ANTHROPIC_API_KEY' => false,
                ])
                ->run($command);
        } catch (\Throwable $e) {
            Log::warning('servers:rightsize-report claude CLI threw', ['error' => $e->getMessage()]);

            return null;
        }

        $decoded = json_decode($result->output(), true);

        if (! $result->successful() || ! is_array($decoded) || ! empty($decoded['is_error'])
            || ! is_string($decoded['result'] ?? null)) {
            Log::warning('servers:rightsize-report claude CLI failed', [
                'exit' => $result->exitCode(),
                'err' => mb_substr($result->errorOutput(), 0, 500),
                'result' => is_array($decoded) ? mb_substr((string) ($decoded['result'] ?? ''), 0, 300) : null,
            ]);

            return null;
        }

        return $this->parse($decoded['result']);
    }

    /**
     * Read the three headed lists. Null when none of the headings is there, so a reply in some
     * other shape is left out of the mail and not shown raw.
     *
     * @return array{changes: list<string>, watch: list<string>, caveats: list<string>}|null
     */
    public function parse(string $text): ?array
    {
        $sections = ['changes' => [], 'watch' => [], 'caveats' => []];
        $current = null;
        $seen = false;

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            // "CHANGES", "Changes:", "**WATCH**" and "## Caveats" are all the same heading.
            // A bulleted line is an item even when it is a single heading word.
            $bulleted = preg_match('/^(?:[-*•]|\d+[.)])\s+\S/u', $line) === 1;
            $heading = $bulleted ? '' : strtolower(trim($line, " \t:*#_-"));

            if (array_key_exists($heading, $sections)) {
                $current = $heading;
                $seen = true;

                continue;
            }

            if ($current === null || $line === '') {
                continue;
            }

            // The marker must be followed by a space, or "12.5 GB" on a wrapped line would lose its "12.".
            if (preg_match('/^(?:[-*•]|\d+[.)])\s+(.+)$/u', $line, $m)) {
                $sections[$current][] = trim($m[1]);
            } elseif ($sections[$current] !== []) {
                // A wrapped line belongs to the item above it.
                $sections[$current][count($sections[$current]) - 1] .= ' '.$line;
            }
        }

        return $seen ? $sections : null;
    }
}
