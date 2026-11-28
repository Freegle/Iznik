<?php

namespace App\Services\Servers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Asks Claude to read the week's sizing figures and write the short review at the top of the
 * report: what to change, what to watch, and what the rules cannot see.
 *
 * The figures and the verdicts come from ServerSizingAnalyser. Claude is given them worded as the
 * mail words them (ServerSizingReportMail::factsFor) and told to quote, never to work anything out,
 * so the review can be checked against the tables under it.
 * It runs on the Claude subscription through the `claude` CLI and a setup token, as the Community
 * News research does. If the call fails the report still goes out, without the review.
 */
class ServerSizingCommentary
{
    public const SYSTEM_PROMPT = <<<'TXT'
You review a weekly report on whether a small charity's rented servers are the right size. The
reader is the one part-time engineer who runs them. They know the servers; do not explain basics.

You are given JSON holding exactly what the report shows them. Each server has its package, what
it costs, a verdict, the reasons for the verdict, lines of readings (label and value), and a week
by week trend. The verdicts come from fixed rules and are already decided:
- "Move down to X" and "Move up to X" are suggestions to act on.
- "Too early to say. So far: ..." means there are not yet enough days of readings to act; what
  follows is only what the readings point to.
- "Keep as it is", "Stopped" and "Not measured" mean what they say.
- "Kept on purpose" is a server its owner has decided to keep, for the reason given. Do not
  suggest deleting, stopping or changing it, and do not list its cost as something to act on.
- "meant to run flat out" is a server that does background work. High load and busy processors
  there are intended and are never a reason to grow it; only late jobs or memory are.
- "Not keeping up" is such a server with late jobs or no memory left, and is a suggestion to act on.

Write plain text, no Markdown, in this exact shape:

CHANGES
- one line per change worth making now, most valuable first, with its monthly saving or cost
WATCH
- one line per thing to keep an eye on
CAVEATS
- one line per thing the rules cannot see that should make the reader doubt a verdict

Rules:
- Quote figures exactly as they are written in the JSON, with their units. Never work out,
  convert, add up or estimate a figure.
- For a move, quote the saving or the extra cost from the verdict. "Steps available" shows what a
  package costs in total, which is a different figure; do not present it as the cost of moving.
- Only a "Move down", "Move up" or "Not keeping up" verdict goes under CHANGES. A "Too early to say" never does.
- If a section has nothing in it, write "- Nothing this week." under it.
- You may advise against a suggested change, with the reason. Do not suggest one the verdict does not.
- With only a day or two of readings, say so once and keep the review short: a single reading is
  not a pattern, so do not draw conclusions from it.
- A database server's memory is filled on purpose by its cache, so high memory use there is not a
  reason to grow it, and it cannot shrink without the cache being made smaller first.
- Taking processors or memory away needs the server shut down; adding them does not. Disks only grow.
- At most 10 lines in total. Short plain sentences. British spelling. No dashes used as punctuation.
TXT;

    /**
     * @param  array<string, mixed>  $facts  from ServerSizingReportMail::factsFor()
     * @return array{changes: list<string>, watch: list<string>, caveats: list<string>}|null
     */
    public function write(array $facts): ?array
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
            '-p', "This week's figures:\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
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
