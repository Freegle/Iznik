<?php

namespace App\Console\Commands\Support;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\EmailSpoolerService;
use App\Services\Support\BriefFormatter;
use App\Services\Support\FirstPassFailure;
use App\Services\Support\FirstPassService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Runs the AI first pass for support cases and sends the referral mail for each.
 *
 * The support_cases row is the queue. Each run first sweeps (first pass off, cases that waited too
 * long, runs that were abandoned), then claims one Pending case at a time and runs it, until none
 * are left or the run has lasted long enough. The helper runs one pass at a time, so claiming
 * several up front would make them look stuck.
 *
 * This is the only thing that mails a case. Every mail is guarded by mailed_at, so running the
 * command twice, or two runs overlapping, sends it once. Nothing here ever throws: a case that
 * cannot be run gets a plain reason and the plain mail.
 */
#[AsCommand(name: 'support:first-pass')]
class SupportFirstPassCommand extends Command
{
    use PreventsOverlapping;

    /** A case is tried this many times (a busy helper does not count) before it fails. */
    public const MAX_ATTEMPTS = 3;

    /** Seconds a Running case may go untouched past the helper wait before it is called interrupted. */
    private const RUNNING_GRACE_SECONDS = 270;

    protected $signature = 'support:first-pass';

    protected $description = 'Run the AI first pass for referred support cases and mail Support';

    public function handle(FirstPassService $service, EmailSpoolerService $spooler): int
    {
        if (! $this->acquireLock()) {
            $this->info('Already running.');

            return self::SUCCESS;
        }

        try {
            $this->sweep();
            $this->work($service);
            $this->mailUnsent($service, $spooler);
        } catch (\Throwable $e) {
            Log::error('support:first-pass failed', ['error' => get_class($e).': '.$e->getMessage()]);
        } finally {
            $this->releaseLock();
        }

        return self::SUCCESS;
    }

    private function cfg(string $key, mixed $default = null): mixed
    {
        return config("freegle.support.first_pass.{$key}", $default);
    }

    private function enabled(): bool
    {
        return \App\Support\EnvSetting::flag($this->cfg('enabled', false));
    }

    /** Move a case out of Pending or Running into a final state with its plain reason. */
    private function finish(int $id, string $from, string $status, string $reason): void
    {
        DB::table('support_cases')->where('id', $id)->where('firstpass_status', $from)
            ->update(['firstpass_status' => $status, 'firstpass_error' => $reason]);
    }

    private function sweep(): void
    {
        $maxAge = (int) $this->cfg('max_age_minutes', 60);

        if (! $this->enabled()) {
            DB::table('support_cases')->where('firstpass_status', 'Pending')
                ->update(['firstpass_status' => 'Skipped', 'firstpass_error' => FirstPassService::REASON_OFF]);
        }

        // Waited too long: never run the model on a case this old. A reason from an earlier failed
        // attempt is kept, as it is more useful than "busy".
        DB::table('support_cases')->where('firstpass_status', 'Pending')
            ->whereRaw('created_at < NOW() - INTERVAL ? MINUTE', [$maxAge])
            ->update([
                'firstpass_status' => 'Failed',
                'firstpass_error' => DB::raw("COALESCE(firstpass_error, '".FirstPassService::REASON_BUSY."')"),
            ]);

        $stale = (int) $this->cfg('timeout', 330) + self::RUNNING_GRACE_SECONDS;
        DB::table('support_cases')->where('firstpass_status', 'Running')
            ->whereRaw('updated_at < NOW() - INTERVAL ? SECOND', [$stale])
            ->update(['firstpass_status' => 'Failed', 'firstpass_error' => FirstPassService::REASON_INTERRUPTED]);
    }

    private function work(FirstPassService $service): void
    {
        if (! $this->enabled()) {
            return;
        }

        $deadline = time() + (int) $this->cfg('run_minutes', 10) * 60;
        $tried = [0];

        while (time() < $deadline) {
            $id = DB::table('support_cases')->where('firstpass_status', 'Pending')
                ->whereNotIn('id', $tried)
                ->whereRaw('created_at >= NOW() - INTERVAL ? MINUTE', [(int) $this->cfg('max_age_minutes', 60)])
                ->orderBy('id')->value('id');
            if (! $id) {
                return;
            }
            $tried[] = (int) $id;

            // The claim is the update: if another run took it, nothing changes and we move on.
            $claimed = DB::table('support_cases')->where('id', $id)->where('firstpass_status', 'Pending')
                ->update(['firstpass_status' => 'Running']);
            if ($claimed !== 1) {
                continue;
            }

            try {
                $busy = $this->runCase((int) $id, $service);
            } catch (\Throwable $e) {
                Log::error('support:first-pass case failed', ['case_id' => $id, 'error' => get_class($e).': '.$e->getMessage()]);
                $this->recordFailure((int) $id, FirstPassService::REASON_FAILED, false);
                $busy = false;
            }

            if ($busy) {
                // The helper is full; asking again straight away would only be refused again.
                return;
            }
        }
    }

    /** @return bool true when the helper said it was busy */
    private function runCase(int $id, FirstPassService $service): bool
    {
        $case = DB::table('support_cases')->where('id', $id)->first();
        if (! $case) {
            return false;
        }

        $cap = (int) $this->cfg('per_referrer_per_day', 10);
        if ($case->referrerid) {
            $used = DB::table('support_ai_runs')->where('kind', 'FirstPass')->where('modid', $case->referrerid)
                ->whereRaw('created_at >= NOW() - INTERVAL 1 DAY')->count();
            if ($used >= $cap) {
                $this->finish($id, 'Running', 'Skipped', FirstPassService::REASON_LIMIT);

                return false;
            }
        }

        // The facts have not changed if the same member's chat was done in the last day: reuse.
        $recent = DB::table('support_cases')->where('chatid', $case->chatid)->where('id', '!=', $id)
            ->where('firstpass_status', 'Done')->whereNotNull('brief')
            ->whereRaw('userid <=> ?', [$case->userid])
            ->whereRaw('created_at >= NOW() - INTERVAL 1 DAY')
            ->orderByDesc('id')->first(['brief', 'firstpass_runid']);
        if ($case->chatid && $recent) {
            DB::table('support_cases')->where('id', $id)->where('firstpass_status', 'Running')->update([
                'firstpass_status' => 'Done', 'firstpass_error' => null,
                'brief' => $recent->brief, 'firstpass_runid' => $recent->firstpass_runid,
            ]);

            return false;
        }

        try {
            $request = $service->buildRequest($case);
            $response = $service->ask($request);
        } catch (FirstPassFailure $f) {
            if ($f->failureCode === 'BUSY') {
                // Back to Pending without counting an attempt.
                DB::table('support_cases')->where('id', $id)->where('firstpass_status', 'Running')->update(['firstpass_status' => 'Pending']);

                return true;
            }
            if ($f->helperRan) {
                $this->storeRun($case, null, $f->reason);
            }
            $this->recordFailure($id, $f->reason, $f->terminal);

            return false;
        }

        $brief = $response['brief'];
        $quota = is_array($response['quota'] ?? null) ? $response['quota'] : null;
        $brief['pool'] = $quota === null ? null : [
            'fiveHour' => is_numeric($quota['fiveHour'] ?? null) ? (float) $quota['fiveHour'] : null,
            'sevenDay' => is_numeric($quota['sevenDay'] ?? null) ? (float) $quota['sevenDay'] : null,
        ];

        $runId = $this->storeRun($case, $response, null, $brief);
        DB::table('support_cases')->where('id', $id)->where('firstpass_status', 'Running')->update([
            'firstpass_status' => 'Done', 'firstpass_error' => null,
            'brief' => json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'firstpass_runid' => $runId,
        ]);

        return false;
    }

    /** Count the failure; try again later unless it cannot help or this was the last attempt. */
    private function recordFailure(int $id, string $reason, bool $terminal): void
    {
        $row = DB::table('support_cases')->where('id', $id)->first(['firstpass_attempts']);
        $attempts = (int) ($row->firstpass_attempts ?? 0) + 1;
        $final = $terminal || $attempts >= self::MAX_ATTEMPTS;

        DB::table('support_cases')->where('id', $id)->where('firstpass_status', 'Running')->update([
            'firstpass_attempts' => min($attempts, 255),
            'firstpass_status' => $final ? 'Failed' : 'Pending',
            'firstpass_error' => $reason,
        ]);
    }

    /**
     * The support_ai_runs row for a pass the helper answered. Only fixed reasons are stored as the
     * error; the helper's own text is never kept.
     *
     * @param  array<string, mixed>|null  $response
     * @param  array<string, mixed>|null  $brief
     */
    private function storeRun(object $case, ?array $response, ?string $error, ?array $brief = null): int
    {
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $quota = is_array($response['quota'] ?? null) ? $response['quota'] : [];
        $before = is_array($response['quotaBefore'] ?? null) ? $response['quotaBefore'] : [];
        $pct = fn ($v) => is_numeric($v) ? max(0, min(100, round((float) $v, 2))) : null;

        return (int) DB::table('support_ai_runs')->insertGetId([
            'modid' => $case->referrerid,
            'kind' => 'FirstPass',
            'userid' => $case->userid,
            'query' => "First pass for support case {$case->id}",
            'analysis' => $brief !== null ? BriefFormatter::markdown($brief) : null,
            'transcript' => isset($response['transcript']) && is_string($response['transcript']) ? mb_substr($response['transcript'], 0, 1_000_000) : null,
            'status' => $error === null ? 'Success' : 'Error',
            'error' => $error,
            'driver' => isset($response['driver']) ? mb_substr((string) $response['driver'], 0, 16) : null,
            'model' => isset($response['model']) ? mb_substr((string) $response['model'], 0, 64) : null,
            'input_tokens' => max(0, (int) ($usage['inputTokens'] ?? 0)),
            'output_tokens' => max(0, (int) ($usage['outputTokens'] ?? 0)),
            'cache_creation_tokens' => max(0, (int) ($usage['cacheCreation'] ?? 0)),
            'cache_read_tokens' => max(0, (int) ($usage['cacheRead'] ?? 0)),
            'duration_ms' => max(0, (int) ($usage['durationMs'] ?? 0)),
            'cost_usd' => is_numeric($response['costUsd'] ?? null) ? max(0, min(999999, (float) $response['costUsd'])) : 0,
            'quota_5h_before' => $pct($before['fiveHour'] ?? null),
            'quota_5h_after' => $pct($quota['fiveHour'] ?? null),
            'quota_7d_before' => $pct($before['sevenDay'] ?? null),
            'quota_7d_after' => $pct($quota['sevenDay'] ?? null),
        ]);
    }

    /**
     * Mail every case that has reached a final state and not yet been mailed. The mailed_at update
     * is what decides, so it goes once however often this runs.
     */
    private function mailUnsent(FirstPassService $service, EmailSpoolerService $spooler): void
    {
        $cases = DB::table('support_cases')
            ->whereIn('firstpass_status', ['Done', 'Failed', 'Skipped'])
            ->whereNull('mailed_at')
            ->whereRaw('created_at >= NOW() - INTERVAL 7 DAY')
            ->orderBy('id')->limit(100)->get();

        $recipients = array_values(array_filter(array_map('trim', explode(',', (string) config('freegle.mail.support_addr', 'support@ilovefreegle.org')))));

        foreach ($cases as $case) {
            try {
                $won = DB::table('support_cases')->where('id', $case->id)->whereNull('mailed_at')->update(['mailed_at' => DB::raw('NOW()')]);
                if ($won !== 1) {
                    continue;
                }
                $brief = null;
                if ($case->firstpass_status === 'Done' && $case->brief) {
                    $decoded = json_decode((string) $case->brief, true);
                    $brief = is_array($decoded) ? $decoded : null;
                }
                $reason = $brief === null ? ($case->firstpass_error ?: FirstPassService::REASON_FAILED) : null;
                $mail = $service->referralMail($case, $brief, $reason);
                if ($mail === null) {
                    continue;
                }
                try {
                    $spooler->spool($mail, $recipients);
                } catch (\Throwable $e) {
                    // Nothing went out, so let the next run try again.
                    DB::table('support_cases')->where('id', $case->id)->update(['mailed_at' => null]);
                    throw $e;
                }
            } catch (\Throwable $e) {
                Log::error('support:first-pass mail failed', ['case_id' => $case->id, 'error' => get_class($e).': '.$e->getMessage()]);
            }
        }
    }
}
