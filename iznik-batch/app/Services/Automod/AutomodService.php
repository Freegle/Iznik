<?php

namespace App\Services\Automod;

use App\Models\MessageAutomod;
use App\Models\MessageAutomodFeedback;
use App\Services\Lockdown\LockdownService;
use App\Services\LokiService;
use App\Support\EnvSetting;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a Pending post's facts and text to the automod Node service (automod/, POST /review),
 * walks it through the chart (automod/chart.json) and stores the verdict on messages_automod
 * (plans/active/automod-flowchart.md). One row per (msgid, groupid); a rerun (edit, or the
 * messages:automod command picking it up again) replaces the row rather than adding another.
 *
 * The service call can fail (network, timeout, backend down) without that being a moderation
 * decision: on failure this records end_node=UNAVAILABLE and leaves the post to ordinary
 * moderation rather than guessing hold or approve. A reply that is not the shape the chart
 * produces is treated the same way, never as an approve. A service that says it is busy (503)
 * has made no decision and failed nothing: nothing is written, and the post is a candidate
 * again on the next run.
 *
 * Every decision, and every failure to decide, also goes to Loki through LokiService: the batch
 * container's ordinary log file does not reach Loki, and these are the events that answer "why
 * did this post publish itself?" and "why is nothing publishing?". Each run of process() adds
 * one line with the backlog it left behind.
 */
class AutomodService
{
    /**
     * How long one run of process() may keep taking candidates. The command runs every minute
     * under withoutOverlapping(15), and a run that is still going when the next minute's tick
     * comes round makes that tick skip (the next run is then two minutes away, not one). A call
     * can start at the end of the budget and take up to a minute, so the budget leaves room for
     * that: a run ends inside its minute, or very nearly, and the next carries on from where it
     * left off. (At 50 a run of four 15-second reviews ended at 61 seconds and halved the rate.)
     */
    public const RUN_BUDGET_SECONDS = 40.0;

    /**
     * After this many reviews in a row that got no answer (the service down, busy or erroring)
     * the run stops: every further call would fail the same way, spending the budget and writing
     * a failed row for each post. The posts stay candidates and the next run tries again.
     */
    public const MAX_CONSECUTIVE_FAILURES = 3;

    /** How long to wait for the service to accept the connection. A service that is down says so at once. */
    public const CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * An approve that has sat Pending this long without publishing is stale: the community was
     * dark, the service was down, a lockdown held it. It is decided again on today's facts before
     * the clean path may publish it (the new decision restarts the wait).
     */
    public const STALE_APPROVE_HOURS = 6;

    /**
     * The most of a post's body the batch sends. The service reads at most 8,000 characters of
     * the whole text (subject and body together, after normalising) and says so when it cuts, so
     * this is deliberately larger: the service decides what it saw, and a request can never
     * exceed the service's body limit and come back 400.
     */
    public const MAX_BODY_CHARS = 20000;

    /** The longest subject sent (a subject is 255 characters at most; this only bounds a bad row). */
    public const MAX_SUBJECT_CHARS = 1000;

    /**
     * Where the automod service is on the internal network when nothing says otherwise. A blank
     * setting means this too: docker-compose and an .env.background line left as AUTOMOD_URL=
     * both give env() an empty string, and a request to "/review" fails for every post.
     */
    public const DEFAULT_URL = 'http://automod:8090';

    /** The loop name the lockdown presser sees take effect (LockdownService::ACK_LOOPS). */
    private const LOCKDOWN_LOOP = 'automod';

    /** The longest quote from the deciding step that goes to Loki. */
    private const EVIDENCE_LOG_CHARS = 160;

    /** The service's own per-run figures, kept on the row as `detail` for cost and quality work. */
    private const DETAIL_KEYS = ['features', 'ms', 'calls', 'model', 'cost_usd', 'tokens', 'prompt_sha', 'reply_sha'];

    /** How the last call to review() ended: decided, unavailable, busy or missing. */
    private string $outcome = '';

    /**
     * The subject and body exactly as they are sent to the service.
     *
     * @return array{0: string, 1: string}
     */
    public static function textSent(?string $subject, ?string $body): array
    {
        return [
            mb_substr((string) $subject, 0, self::MAX_SUBJECT_CHARS),
            mb_substr((string) $body, 0, self::MAX_BODY_CHARS),
        ];
    }

    /**
     * A hash of the text a decision covers, so Loki and the decision row can say which text the
     * chart read and the publish can say which text was live; a difference is an edit the chart
     * did not see.
     */
    public static function textSha(?string $subject, ?string $body): string
    {
        [$s, $b] = self::textSent($subject, $body);

        return hash('sha256', $s . "\n" . $b);
    }

    public function __construct(
        private readonly ?AutomodFactsService $factsService = null,
        private readonly ?LokiService $loki = null,
        private readonly ?LockdownService $lockdown = null
    ) {
    }

    /** How the last review() ended: 'decided', 'unavailable', 'busy' (no decision, nothing written) or 'missing'. */
    public function lastOutcome(): string
    {
        return $this->outcome;
    }

    /**
     * @param bool $persist whether to write the messages_automod row. False for
     *                       automod:evaluate's live-compare mode, where the sampled posts are
     *                       already decided (Approved/Rejected, not Pending) and writing a
     *                       verdict for them would be noise rather than a moderation record.
     * @param Carbon|null $asOf read the member signals as they stood at this moment (the same
     *                       live-compare mode: see AutomodFactsService::facts()).
     * @return array<string, mixed>|null the decoded /review response on success, or null if
     *                                    the service could not be reached, was busy, or did not
     *                                    answer in the chart's shape (a row is still written when
     *                                    $persist is true, except for a busy service).
     */
    public function review(int $msgid, int $groupid, string $mode, ?string $backend = null, bool $persist = true, ?Carbon $asOf = null): ?array
    {
        $this->outcome = '';

        // The decision is dated from the moment the text is read, not from when the reply lands
        // 10 to 20 seconds later: an edit made while the model is answering must count as later
        // than the decision, or the row would look current for text it never saw.
        $readAt = now();
        $startedAt = microtime(true);

        $message = DB::table('messages')
            ->where('id', $msgid)
            ->first(['subject', 'textbody', 'type']);

        if (!$message) {
            Log::warning('AutomodService: message not found', ['msgid' => $msgid, 'groupid' => $groupid]);
            $this->outcome = 'missing';

            return null;
        }

        [$subject, $body] = self::textSent($message->subject, $message->textbody);
        $textSha = self::textSha($message->subject, $message->textbody);

        $facts = $this->factsService()->facts($msgid, $groupid, $asOf);
        $rules = $this->factsService()->rules($groupid);
        $otherPosts = $facts['other_posts'] ?? [];
        unset($facts['other_posts']);

        // A community that has moderators check every post gets no automated decision: the
        // answer is the community's setting, recorded once so the countdown and the line show it.
        if (!empty($facts['group_disallows'])) {
            $decoded = [
                'chart' => 'freegle-automod',
                'version' => '',
                'verdict' => MessageAutomod::VERDICT_HOLD,
                'end' => 'GROUP_MODERATES_ALL',
                'reason' => ($facts['group_disallows_detail'] ?? 'This community has moderators check every post') . '.',
                'path' => [],
            ];
            if ($persist) {
                $context = $this->record($msgid, $groupid, $mode, $decoded, $readAt, $startedAt, $textSha);
                $this->clearQualitySample($msgid, $groupid);
                $this->logDecision($context + ['quality_sample' => false]);
            }
            $this->outcome = 'decided';

            return $decoded;
        }

        $timeout = EnvSetting::positiveInt(config('freegle.automod.timeout'), 60);

        $payload = [
            'msgid' => $msgid,
            'groupid' => $groupid,
            'subject' => $subject,
            'body' => $body,
            'type' => $message->type ?? '',
            // Objects, not arrays: an empty PHP array encodes as [] and the service wants {}.
            'facts' => (object) $facts,
            'rules' => (object) $rules,
            'other_posts' => $otherPosts,
            // The service stops asking and answers "hold" at the deadline, a little before this
            // side's HTTP timeout would throw the whole reply away.
            'deadline_ms' => max(1000, ($timeout - 2) * 1000),
        ];

        if ($backend) {
            $payload['backend'] = $backend;
        }

        $url = rtrim(EnvSetting::text(config('freegle.automod.url'), self::DEFAULT_URL), '/') . '/review';

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)->timeout($timeout)->post($url, $payload);

            if ($response->status() === 503) {
                // "Busy" is the service declining to start, not a failure to decide. Writing a
                // failed row would overwrite a standing decision, cost the post a five-minute
                // wait for its retry and count towards the monitor; writing nothing leaves it a
                // candidate for the very next run.
                Log::info('AutomodService: /review is busy', ['msgid' => $msgid, 'groupid' => $groupid]);
                $this->loki()->logEvent('automod', 'busy', ['msgid' => $msgid, 'groupid' => $groupid, 'mode' => $mode]);
                $this->outcome = 'busy';

                return null;
            }

            if (!$response->successful()) {
                $error = 'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 300);
                Log::warning('AutomodService: /review returned an error', [
                    'msgid' => $msgid,
                    'groupid' => $groupid,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 300),
                ]);
                $this->unavailable($msgid, $groupid, $mode, $persist, $error, $readAt);

                return null;
            }

            $decoded = $response->json();
            if (!is_array($decoded)) {
                Log::warning('AutomodService: /review returned an unparseable body', [
                    'msgid' => $msgid,
                    'groupid' => $groupid,
                ]);
                $this->unavailable($msgid, $groupid, $mode, $persist, 'unparseable body', $readAt);

                return null;
            }

            if (!self::wellFormed($decoded)) {
                $error = 'malformed decision: ' . substr(json_encode([
                    'verdict' => $decoded['verdict'] ?? null,
                    'end' => $decoded['end'] ?? null,
                    'path' => gettype($decoded['path'] ?? []),
                ]), 0, 300);
                Log::warning('AutomodService: /review answered in a shape the chart does not produce', [
                    'msgid' => $msgid,
                    'groupid' => $groupid,
                    'error' => $error,
                ]);
                $this->unavailable($msgid, $groupid, $mode, $persist, $error, $readAt);

                return null;
            }

            if ($persist) {
                $context = $this->record($msgid, $groupid, $mode, $decoded, $readAt, $startedAt, $textSha);
                $sampled = $this->stampQualitySample($msgid, $groupid, $mode, $decoded);
                $this->logDecision($context + ['quality_sample' => $sampled]);
            }
            $this->outcome = 'decided';

            return $decoded;
        } catch (\Exception $e) {
            Log::warning('AutomodService: /review call failed', [
                'msgid' => $msgid,
                'groupid' => $groupid,
                'error' => $e->getMessage(),
            ]);
            $this->unavailable($msgid, $groupid, $mode, $persist, $e->getMessage(), $readAt);

            return null;
        }
    }

    /**
     * A decision the chart could have produced, and nothing else. A verdict this side knows, a
     * named end node, a path that is a list (it may be empty), and the verdict and the end node
     * agreeing: an approve ends at APPROVE and nowhere else, with no step that said yes or that
     * the backend could not answer. A hold may end anywhere, including UNAVAILABLE (the service
     * saying it could not decide). Anything else is not trusted, and an "approve" least of all:
     * a service fault or a version skew must never be able to publish a post.
     *
     * @param array<string, mixed> $decoded
     */
    public static function wellFormed(array $decoded): bool
    {
        $verdict = $decoded['verdict'] ?? null;
        $end = $decoded['end'] ?? null;
        $path = $decoded['path'] ?? [];

        if (!in_array($verdict, [MessageAutomod::VERDICT_APPROVE, MessageAutomod::VERDICT_HOLD], true)
            || !is_string($end) || $end === ''
            || !is_array($path)) {
            return false;
        }

        if ($verdict === MessageAutomod::VERDICT_APPROVE) {
            if ($end !== 'APPROVE') {
                return false;
            }

            foreach ($path as $step) {
                if (is_array($step) && (($step['answer'] ?? null) === 'yes' || ($step['model'] ?? null) === 'unavailable')) {
                    return false;
                }
            }

            return true;
        }

        return $end !== 'APPROVE';
    }

    /**
     * Picks up Pending posts in shadow/approve groups once their content check has run, and
     * runs review() for any that have no messages_automod row yet, were edited since their last
     * one, or whose last one predates their latest content check (see candidates()). Communities
     * that can publish are reviewed before communities that only record.
     *
     * Does nothing while posts are held by the lockdown: nothing publishes then, so a review is
     * pure cost (and a flood is when it would cost most). The rows wait for the first run after
     * the lift.
     *
     * @param float $budgetSeconds how long to keep taking candidates (see RUN_BUDGET_SECONDS).
     * @return array{reviewed: int, unavailable: int, errors: int, timeboxed: int, busy: int, lockdown: int, breaker: int}
     */
    public function process(int $limit = 200, float $budgetSeconds = self::RUN_BUDGET_SECONDS): array
    {
        $stats = ['reviewed' => 0, 'unavailable' => 0, 'errors' => 0, 'timeboxed' => 0, 'busy' => 0, 'lockdown' => 0, 'breaker' => 0];

        $eligibleGroupIds = AutomodMode::eligibleGroupIds();
        if ($eligibleGroupIds === []) {
            // Automod is dark everywhere - nothing to do.
            return $stats;
        }

        $lockdown = $this->lockdown();
        $lockdown->ack(self::LOCKDOWN_LOOP);
        if ($lockdown->held('posts')) {
            $stats['lockdown'] = 1;
            Log::info('AutomodService: posts are held by the lockdown; no reviews this run');
            $this->loki()->logBatchJob('messages:automod', 'run', ['lockdown' => true, 'reviewed' => 0]);

            return $stats;
        }

        $candidates = $this->candidates($eligibleGroupIds)
            // Communities that can publish go first: a recording-only (shadow) backlog must never
            // delay a publish. Then oldest first, so a backlog is FIFO.
            // keep-raw: the approve-mode set is a dynamic id list from config, or always/never.
            ->orderByRaw('(' . AutomodMode::approveModeSql('mg.groupid') . ') DESC')
            ->orderBy('mg.msgid')
            ->orderBy('mg.groupid')
            ->limit($limit)
            ->get(['mg.msgid', 'mg.groupid']);

        $started = $this->runClock();
        $durationsMs = [];
        $failuresInARow = 0;

        foreach ($candidates as $i => $row) {
            if ($this->runClock() - $started >= $budgetSeconds) {
                $stats['timeboxed'] = count($candidates) - $i;
                Log::info("AutomodService: run budget spent after {$i} posts; {$stats['timeboxed']} left for the next run");
                break;
            }

            // Re-read per post: a press landing partway through a run stops the next review.
            $lockdown->ack(self::LOCKDOWN_LOOP);
            if ($lockdown->held('posts')) {
                $stats['lockdown'] = 1;
                Log::info('AutomodService: the lockdown was pressed mid run; the rest wait for the lift');
                break;
            }

            $msgid = (int) $row->msgid;
            $groupid = (int) $row->groupid;

            $mode = AutomodMode::for($groupid);
            if ($mode === null) {
                // Config changed between the query and this row being processed - skip it,
                // the next run will pick it up correctly (or not at all).
                continue;
            }

            try {
                $callStarted = microtime(true);
                $result = $this->review($msgid, $groupid, $mode);
                $durationsMs[] = (int) round((microtime(true) - $callStarted) * 1000);

                if ($result === null) {
                    if ($this->outcome === 'busy') {
                        $stats['busy']++;
                    } else {
                        $stats['unavailable']++;
                        $stats['reviewed']++;
                    }
                    $failuresInARow++;
                } else {
                    $stats['reviewed']++;
                    $failuresInARow = 0;
                }
            } catch (\Exception $e) {
                Log::error("AutomodService: error reviewing message #{$msgid} group #{$groupid}: " . $e->getMessage());
                $stats['errors']++;
                $failuresInARow++;
            }

            if ($failuresInARow >= self::MAX_CONSECUTIVE_FAILURES) {
                $stats['breaker'] = 1;
                Log::warning('AutomodService: ' . self::MAX_CONSECUTIVE_FAILURES . ' reviews in a row got no answer; stopping this run, the rest wait for the next');
                break;
            }
        }

        $this->logRun($eligibleGroupIds, $stats, $durationsMs);

        return $stats;
    }

    /** The run clock, in seconds. Overridable so a test can spend the budget at will. */
    protected function runClock(): float
    {
        return microtime(true);
    }

    /**
     * The Pending copies the chart has yet to decide, or has decided on something that has since
     * moved on. Built on AutomodEligibility::reviewable(), which is also what the health monitor
     * counts, so the two cannot disagree about which posts a review would pick up. A copy the
     * clean path could never publish (held, flagged as spam, deleted, or from a deleted member)
     * is not reviewed: the model call would be wasted.
     *
     * A decision is decided again when:
     * - there is none yet;
     * - the post was edited at or after it (a decision in the same second as an edit may have
     *   read the text first), or the content check ran again at or after it;
     * - it could not be made (UNAVAILABLE) and five minutes have passed;
     * - it was recorded in shadow mode and the community is now approve-only (a shadow decision
     *   was never a promise: the wait restarts from a decision made in approve mode);
     * - it is an approve that has sat Pending for hours without publishing and is not a quality
     *   sample or a pulled-back copy (the facts it saw are old; see STALE_APPROVE_HOURS).
     *
     * @param int[]|null $eligibleGroupIds
     */
    private function candidates(?array $eligibleGroupIds): Builder
    {
        return AutomodEligibility::reviewable($eligibleGroupIds)
            ->leftJoin('messages_automod as ma', function ($join) {
                $join->on('ma.msgid', '=', 'mg.msgid')
                    ->on('ma.groupid', '=', 'mg.groupid');
            })
            ->where(function ($query) {
                $query->whereNull('ma.id')
                    ->orWhereColumn('m.editedat', '>=', 'ma.created')
                    ->orWhereColumn('ma.created', '<=', 'mg.contentcheck_checked_at')
                    ->orWhere(function ($q) {
                        $q->where('ma.end_node', 'UNAVAILABLE')
                            ->where('ma.created', '<', now()->subMinutes(5));
                    })
                    ->orWhere(function ($q) {
                        // keep-raw: the approve-mode set is a dynamic id list from config, or always/never.
                        $q->where('ma.mode', MessageAutomod::MODE_SHADOW)
                            ->whereRaw(AutomodMode::approveModeSql('mg.groupid'));
                    })
                    ->orWhere(function ($q) {
                        $q->where('ma.mode', MessageAutomod::MODE_APPROVE)
                            ->where('ma.verdict', MessageAutomod::VERDICT_APPROVE)
                            ->where('ma.created', '<', now()->subHours(self::STALE_APPROVE_HOURS))
                            ->where('mg.quality_sample', 0)
                            ->where('mg.needs_moderator', 0);
                    });
            });
    }

    /**
     * One line per run, to Loki and the log: what this run did and what it left behind, so a
     * backlog, an outage or a slow model is visible without reading the database.
     *
     * @param int[]|null $eligibleGroupIds
     * @param array<string, int> $stats
     * @param int[] $durationsMs
     */
    private function logRun(?array $eligibleGroupIds, array $stats, array $durationsMs): void
    {
        $waiting = $this->candidates($eligibleGroupIds);
        $candidatesWaiting = (clone $waiting)->count();
        $oldest = $candidatesWaiting > 0 ? $waiting->min('mg.contentcheck_checked_at') : null;

        $unavailableRows = DB::table('messages_automod as ma')
            ->join('messages_groups as mg', function ($join) {
                $join->on('mg.msgid', '=', 'ma.msgid')->on('mg.groupid', '=', 'ma.groupid');
            })
            ->where('ma.end_node', 'UNAVAILABLE')
            ->where('mg.collection', \App\Models\MessageGroup::COLLECTION_PENDING)
            ->where('mg.deleted', 0)
            ->when($eligibleGroupIds !== null, fn ($q) => $q->whereIn('mg.groupid', $eligibleGroupIds))
            ->count();

        sort($durationsMs);
        $context = array_merge($stats, [
            'candidates_waiting' => $candidatesWaiting,
            'oldest_wait_minutes' => $oldest ? (int) now()->diffInMinutes(Carbon::parse($oldest), true) : null,
            'unavailable_rows' => $unavailableRows,
            'ms_p50' => self::percentile($durationsMs, 0.5),
            'ms_p95' => self::percentile($durationsMs, 0.95),
        ]);

        Log::info('Automod run', $context);
        $this->loki()->logBatchJob('messages:automod', 'run', $context);
    }

    /** @param int[] $sorted ascending */
    private static function percentile(array $sorted, float $p): ?int
    {
        if ($sorted === []) {
            return null;
        }

        return $sorted[(int) min(count($sorted) - 1, floor($p * count($sorted)))];
    }

    private function factsService(): AutomodFactsService
    {
        return $this->factsService ?? new AutomodFactsService();
    }

    private function lockdown(): LockdownService
    {
        return $this->lockdown ?? app(LockdownService::class);
    }

    private function loki(): LokiService
    {
        return $this->loki ?? app(LokiService::class);
    }

    /**
     * Writes the decision (replacing the post's previous one) and returns what the Loki event
     * should carry; the event itself is written by logDecision() once the quality sample, which
     * is part of it, has been settled.
     *
     * @param array<string, mixed> $decoded the /review response body.
     * @return array<string, mixed>
     */
    private function record(int $msgid, int $groupid, string $mode, array $decoded, Carbon $readAt, float $startedAt, string $textSha): array
    {
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $detail = [];
        foreach (self::DETAIL_KEYS as $key) {
            if (array_key_exists($key, $decoded)) {
                $detail[$key] = $decoded[$key];
            }
        }
        $detail['elapsed_ms'] = $elapsedMs;
        $detail['text_sha'] = $textSha;

        $path = is_array($decoded['path'] ?? null) ? $decoded['path'] : [];
        $previous = MessageAutomod::where('msgid', $msgid)->where('groupid', $groupid)->first(['id', 'path']);

        MessageAutomod::updateOrCreate(
            ['msgid' => $msgid, 'groupid' => $groupid],
            [
                'mode' => $mode,
                'chart_version' => (string) ($decoded['version'] ?? ''),
                'verdict' => $decoded['verdict'] ?? MessageAutomod::VERDICT_HOLD,
                'end_node' => $decoded['end'] ?? 'UNKNOWN',
                'reason' => $decoded['reason'] ?? null,
                'path' => $path,
                'detail' => $detail,
                'created' => $readAt,
            ]
        );

        // A moderator's "this step is wrong" is about the answer they were looking at. When a
        // re-run answers differently the mark is about a decision that no longer stands: it must
        // not count against the new one, or stop the moderator marking it again. A re-run that
        // reaches the same answers (only the timings moved) keeps it.
        if ($previous !== null && self::answers($previous->path) !== self::answers($path)) {
            MessageAutomodFeedback::where('automodid', $previous->id)->delete();
        }

        $deciding = $path === [] ? null : end($path);

        return [
            'msgid' => $msgid,
            'groupid' => $groupid,
            'mode' => $mode,
            'verdict' => $decoded['verdict'] ?? MessageAutomod::VERDICT_HOLD,
            'end_node' => $decoded['end'] ?? 'UNKNOWN',
            'chart_version' => (string) ($decoded['version'] ?? ''),
            'model' => $decoded['model'] ?? null,
            'ms' => $decoded['ms'] ?? $elapsedMs,
            'cost_usd' => $decoded['cost_usd'] ?? null,
            'elapsed_ms' => $elapsedMs,
            'tokens' => $decoded['tokens'] ?? null,
            'calls' => $decoded['calls'] ?? null,
            'cache_hit' => $decoded['cache_hit'] ?? null,
            'prompt_sha' => $decoded['prompt_sha'] ?? null,
            'reply_sha' => $decoded['reply_sha'] ?? null,
            'text_sha' => $textSha,
            // The step that decided it, and the few words of the post it quoted (bounded: the
            // model chooses a short quote). The rest of the path is on the row.
            'deciding_node' => is_array($deciding) ? ($deciding['node'] ?? null) : null,
            'deciding_evidence' => is_array($deciding) && isset($deciding['evidence'])
                ? mb_substr((string) $deciding['evidence'], 0, self::EVIDENCE_LOG_CHARS)
                : null,
        ];
    }

    /**
     * The node and answer of each step, without the timings and probabilities that differ from
     * one run to the next.
     *
     * @param mixed $path
     * @return array<int, string>
     */
    private static function answers(mixed $path): array
    {
        if (!is_array($path)) {
            return [];
        }

        return array_map(
            fn ($step) => is_array($step) ? (($step['node'] ?? '') . ':' . ($step['answer'] ?? '')) : (string) json_encode($step),
            array_values($path)
        );
    }

    /** @param array<string, mixed> $context */
    private function logDecision(array $context): void
    {
        Log::info("AutomodService: {$context['verdict']} ({$context['end_node']}) for message #{$context['msgid']} on group #{$context['groupid']} in {$context['elapsed_ms']}ms", [
            'msgid' => $context['msgid'], 'groupid' => $context['groupid'], 'mode' => $context['mode'],
            'verdict' => $context['verdict'], 'end_node' => $context['end_node'],
        ]);
        $this->loki()->logEvent('automod', 'decision', $context);
    }

    private function unavailable(int $msgid, int $groupid, string $mode, bool $persist, string $error, Carbon $readAt): void
    {
        $this->outcome = 'unavailable';

        if (!$persist) {
            return;
        }

        // How long and how often this post has failed to be decided, kept across the retries
        // (each rewrites the row's own timestamp) so the monitor can tell a blip from an outage.
        $attempts = 1;
        $firstFailedAt = $readAt;
        $previous = MessageAutomod::where('msgid', $msgid)->where('groupid', $groupid)->first(['end_node', 'detail', 'created']);
        if ($previous !== null && $previous->end_node === 'UNAVAILABLE') {
            $earlier = is_array($previous->detail) ? $previous->detail : [];
            $attempts = (int) ($earlier['attempts'] ?? 1) + 1;
            $firstFailedAt = !empty($earlier['first_failed_at'])
                ? Carbon::parse($earlier['first_failed_at'])
                : Carbon::parse($previous->created);
        }

        MessageAutomod::updateOrCreate(
            ['msgid' => $msgid, 'groupid' => $groupid],
            [
                'mode' => $mode,
                'chart_version' => '',
                'verdict' => MessageAutomod::VERDICT_HOLD,
                'end_node' => 'UNAVAILABLE',
                'reason' => 'Automated review unavailable',
                'path' => [],
                'detail' => [
                    'error' => $error,
                    'attempts' => $attempts,
                    'first_failed_at' => $firstFailedAt->toDateTimeString(),
                ],
                'created' => $readAt,
            ]
        );
        $this->clearQualitySample($msgid, $groupid);

        $this->loki()->logEvent('automod', 'unavailable', [
            'msgid' => $msgid,
            'groupid' => $groupid,
            'mode' => $mode,
            'error' => $error,
            'attempts' => $attempts,
        ]);
    }

    /**
     * The quality sample is decided with the verdict, not when the wait runs out: an approve on
     * an approve-mode community that falls in the sample is stamped now, so the Pending card can
     * say so and the countdown never promises a publish that the sample will keep back. Any other
     * outcome withdraws a stamp an earlier approve left: the sample is a sample of what the chart
     * approved, so a post it now holds (after an edit) or could not decide is no longer in it.
     *
     * @param array<string, mixed> $decoded
     * @return bool whether the post is in the sample
     */
    private function stampQualitySample(int $msgid, int $groupid, string $mode, array $decoded): bool
    {
        if ($mode !== MessageAutomod::MODE_APPROVE || ($decoded['verdict'] ?? null) !== MessageAutomod::VERDICT_APPROVE) {
            $this->clearQualitySample($msgid, $groupid);

            return false;
        }

        if (!QualitySample::sampled($msgid)) {
            return false;
        }

        $stamped = DB::table('messages_groups')
            ->where('msgid', $msgid)
            ->where('groupid', $groupid)
            ->where('quality_sample', 0)
            ->update(['quality_sample' => 1]);

        if ($stamped > 0) {
            Log::info("AutomodService: message #{$msgid} on group #{$groupid} held back as a quality sample");
            $this->loki()->logEvent('automod', 'quality_sample', ['msgid' => $msgid, 'groupid' => $groupid]);
        }

        return true;
    }

    private function clearQualitySample(int $msgid, int $groupid): void
    {
        DB::table('messages_groups')
            ->where('msgid', $msgid)
            ->where('groupid', $groupid)
            ->where('quality_sample', 1)
            ->update(['quality_sample' => 0]);
    }
}
