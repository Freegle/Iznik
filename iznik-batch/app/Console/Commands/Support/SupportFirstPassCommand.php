<?php

namespace App\Console\Commands\Support;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\EmailSpoolerService;
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
            $this->mailSafely($service, $spooler);
            try {
                $this->work($service, fn () => $this->mailSafely($service, $spooler));
            } catch (\Throwable $e) {
                Log::error('support:first-pass work failed', ['error' => get_class($e).': '.$e->getMessage()]);
            }
            $this->mailSafely($service, $spooler);
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
            ->where('created_at', '<', now()->subMinutes($maxAge))
            ->update([
                'firstpass_status' => 'Failed',
                'firstpass_error' => DB::raw("COALESCE(firstpass_error, '".FirstPassService::REASON_BUSY."')"),
            ]);

        $stale = (int) $this->cfg('timeout', 510) + self::RUNNING_GRACE_SECONDS;
        DB::table('support_cases')->where('firstpass_status', 'Running')
            ->where('updated_at', '<', now()->subSeconds($stale))
            ->update(['firstpass_status' => 'Failed', 'firstpass_error' => FirstPassService::REASON_INTERRUPTED]);
    }

    /** @param  \Closure  $afterCase  Called after each case, so a finished case is mailed at once. */
    private function work(FirstPassService $service, \Closure $afterCase): void
    {
        if (! $this->enabled()) {
            return;
        }

        $deadline = time() + (int) $this->cfg('run_minutes', 10) * 60;
        $tried = [0];

        while (time() < $deadline) {
            $id = DB::table('support_cases')->where('firstpass_status', 'Pending')
                ->whereNotIn('id', $tried)
                ->where('created_at', '>=', now()->subMinutes((int) $this->cfg('max_age_minutes', 60)))
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

            $afterCase();

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

        $chat = $case->chatid ? DB::table('chat_rooms')->where('id', $case->chatid)->first(['id']) : null;
        if (! $chat) {
            // Nothing to read, so the helper is not asked; Support still gets the plain mail.
            $this->finish($id, 'Running', 'Skipped', FirstPassService::REASON_NO_CHAT);

            return false;
        }

        // Reuse costs nothing, so it comes before the cap.
        if ($this->reuseBrief($case, $service)) {
            return false;
        }

        $cap = (int) $this->cfg('per_referrer_per_day', 10);
        if ($case->referrerid) {
            $used = DB::table('support_ai_runs')->where('kind', 'FirstPass')->where('modid', $case->referrerid)
                ->where('created_at', '>=', now()->subDay())->count();
            if ($used >= $cap) {
                $this->finish($id, 'Running', 'Skipped', FirstPassService::REASON_LIMIT);

                return false;
            }
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
            if ($f->mayHaveSpent()) {
                $service->storeRun($case->referrerid, $case->userid, "First pass for support case {$case->id}", null, $f->reason);
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

        $runId = $service->storeRun($case->referrerid, $case->userid, "First pass for support case {$case->id}", $response, null, $brief);
        DB::table('support_cases')->where('id', $id)->where('firstpass_status', 'Running')->update([
            'firstpass_status' => 'Done', 'firstpass_error' => null,
            'brief' => json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'firstpass_runid' => $runId,
        ]);

        return false;
    }

    /**
     * The facts have not changed if the same member's chat was done in the last day, the new
     * referrer asked the same thing (or nothing), and nothing visible was said in the chat since.
     *
     * @return bool true when the earlier brief was copied onto this case
     */
    private function reuseBrief(object $case, FirstPassService $service): bool
    {
        $recent = DB::table('support_cases as c')
            ->leftJoin('support_ai_runs as r', 'r.id', '=', 'c.firstpass_runid')
            ->where('c.chatid', $case->chatid)->where('c.id', '!=', $case->id)
            ->where('c.firstpass_status', 'Done')->whereNotNull('c.brief')
            ->when(
                $case->userid === null,
                fn ($q) => $q->whereNull('c.userid'),
                fn ($q) => $q->where('c.userid', $case->userid),
            )
            ->where('c.created_at', '>=', now()->subDay())
            ->orderByDesc('c.id')
            ->first(['c.brief', 'c.firstpass_runid', 'c.reason', DB::raw('COALESCE(r.created_at, c.created_at) as ran_at')]);
        if (! $recent) {
            return false;
        }

        $asked = (string) ($case->reason ?? '');
        if ($asked !== '' && $asked !== (string) ($recent->reason ?? '')) {
            return false;
        }
        if ($service->hasVisibleMessageAfter((int) $case->chatid, (string) $recent->ran_at)) {
            return false;
        }

        return DB::table('support_cases')->where('id', $case->id)->where('firstpass_status', 'Running')->update([
            'firstpass_status' => 'Done', 'firstpass_error' => null,
            'brief' => $recent->brief, 'firstpass_runid' => $recent->firstpass_runid,
        ]) === 1;
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

    /** Mail what is unsent; a failure here must never stop the rest of the run. */
    private function mailSafely(FirstPassService $service, EmailSpoolerService $spooler): void
    {
        try {
            $this->mailUnsent($service, $spooler);
        } catch (\Throwable $e) {
            Log::error('support:first-pass mail failed', ['error' => get_class($e).': '.$e->getMessage()]);
        }
    }

    /**
     * Mail every case that has reached a final state and not yet been mailed. The mail is built
     * first, then mailed_at is claimed, then it is spooled; the claim is what decides, so it goes
     * once however often this runs, and any failure after the claim puts it back for the next run.
     */
    private function mailUnsent(FirstPassService $service, EmailSpoolerService $spooler): void
    {
        $cases = DB::table('support_cases')
            ->whereIn('firstpass_status', ['Done', 'Failed', 'Skipped'])
            ->whereNull('mailed_at')
            ->where('created_at', '>=', now()->subDays(7))
            ->orderBy('id')->limit(100)->get();

        $recipients = array_values(array_filter(array_map('trim', explode(',', (string) config('freegle.mail.support_addr', 'support@ilovefreegle.org')))));

        foreach ($cases as $case) {
            $claimed = false;
            try {
                $brief = null;
                if ($case->firstpass_status === 'Done' && $case->brief) {
                    $decoded = json_decode((string) $case->brief, true);
                    $brief = is_array($decoded) ? $decoded : null;
                }
                $reason = $brief === null ? ($case->firstpass_error ?: FirstPassService::REASON_FAILED) : null;
                $mail = $service->referralMail($case, $brief, $reason);

                $won = DB::table('support_cases')->where('id', $case->id)->whereNull('mailed_at')->update(['mailed_at' => DB::raw('NOW()')]);
                if ($won !== 1) {
                    continue;
                }
                $claimed = true;
                $spooler->spool($mail, $recipients);
            } catch (\Throwable $e) {
                if ($claimed) {
                    // Nothing went out, so let the next run try again.
                    DB::table('support_cases')->where('id', $case->id)->update(['mailed_at' => null]);
                }
                Log::error('support:first-pass mail failed', ['case_id' => $case->id, 'error' => get_class($e).': '.$e->getMessage()]);
            }
        }
    }
}
