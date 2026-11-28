<?php

namespace App\Console\Commands\Support;

use App\Console\Concerns\PreventsOverlapping;
use App\Models\BackgroundTask;
use App\Services\EmailSpoolerService;
use App\Services\Support\FirstPassFailure;
use App\Services\Support\FirstPassService;
use App\Support\EnvSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Handles chats that moderators have referred to Support: runs the AI first pass for each and
 * sends Support one mail.
 *
 * The queue is the refer_to_support rows of background_tasks, written by the Go API. Nothing else
 * is stored about a referral. queue:background-tasks leaves these rows alone, because a pass can
 * take minutes and would hold every other task up.
 *
 * Each run takes the waiting referrals oldest first, one at a time, until none are left or the run
 * has lasted long enough. Every referral ends in a mail: with the first pass when it
 * worked, and otherwise with the plain reason there is none (turned off, the helper failed or
 * stayed busy for an hour, today's limit was reached). The task is marked processed once its mail
 * has been spooled, never before, so a run that dies part-way sends the mail again rather than
 * losing it. Nothing here ever throws.
 */
#[AsCommand(name: 'support:first-pass')]
class SupportFirstPassCommand extends Command
{
    use PreventsOverlapping;

    /** A pass is started at most this many times for one referral (a busy helper does not count). */
    public const MAX_ATTEMPTS = 3;

    /**
     * After this many failures to build or spool a referral's mail, and once the referral is older
     * than max_age_minutes, the plainest mail is sent instead.
     */
    public const MAX_MAIL_FAILURES = 3;

    protected $signature = 'support:first-pass';

    protected $description = 'Run the AI first pass for chats referred to Support and mail Support';

    public function handle(FirstPassService $service, EmailSpoolerService $spooler): int
    {
        if (! $this->acquireLock()) {
            $this->info('Already running.');

            return self::SUCCESS;
        }

        try {
            $this->work($service, $spooler);
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
        return EnvSetting::flag($this->cfg('enabled', false));
    }

    private function work(FirstPassService $service, EmailSpoolerService $spooler): void
    {
        $deadline = time() + (int) $this->cfg('run_minutes', 10) * 60;
        $tried = [0];

        while (time() < $deadline) {
            // From the write node: a row this run has just marked processed must not come back.
            $task = DB::table('background_tasks')->useWritePdo()
                ->where('task_type', BackgroundTask::TASK_REFER_TO_SUPPORT)
                ->whereNull('processed_at')->whereNull('failed_at')
                ->whereNotIn('id', $tried)
                ->orderBy('id')->first();
            if (! $task) {
                return;
            }
            $tried[] = (int) $task->id;

            try {
                $wait = $this->runReferral($task, $service, $spooler);
            } catch (\Throwable $e) {
                Log::error('support:first-pass referral failed', ['task_id' => $task->id, 'error' => get_class($e).': '.$e->getMessage()]);
                $wait = false;
            }

            if ($wait) {
                // The helper is full or away; asking again straight away would only fail again.
                return;
            }
        }
    }

    /**
     * Take one referral as far as it can go now.
     *
     * @return bool true when the helper was busy or could not be reached, so the run should stop
     */
    private function runReferral(object $task, FirstPassService $service, EmailSpoolerService $spooler): bool
    {
        $data = json_decode((string) $task->data, true);
        $data = is_array($data) ? $data : [];
        $chatId = (int) ($data['chatid'] ?? 0);
        $referrerId = (int) ($data['userid'] ?? 0);
        if ($chatId === 0 || $referrerId === 0) {
            // Nobody to say referred what, so there is nothing to mail.
            // The free text goes: nothing will mail it, and a failed row is never tidied later.
            unset($data['reason'], $data['brief']);
            DB::table('background_tasks')->where('id', $task->id)->update([
                'failed_at' => DB::raw('NOW()'), 'error_message' => 'refer_to_support requires chatid and userid',
                'data' => json_encode($data),
            ]);

            return false;
        }

        $chat = DB::table('chat_rooms')->where('id', $chatId)->first(['id']);
        $referral = (object) [
            'id' => (int) $task->id,
            'chatid' => $chat ? $chatId : null,
            'userid' => (int) ($data['memberid'] ?? 0) ?: null,
            'referrerid' => $referrerId,
            'groupid' => (int) ($data['groupid'] ?? 0) ?: null,
            'reason' => is_string($data['reason'] ?? null) ? $data['reason'] : null,
            'created_at' => $task->created_at,
        ];
        // $data by reference: a finished brief is added to it below, and the mail step must see that.
        $finish = function (?array $brief, ?string $reason) use (&$data, $task, $referral, $service, $spooler): void {
            $this->mail($task, $data, $referral, $brief, $reason, $service, $spooler);
        };

        if (! $chat) {
            $finish(null, FirstPassService::REASON_NO_CHAT);

            return false;
        }
        // A pass that finished but whose mail did not go left its brief on the task: mail that, and
        // do not spend the model again.
        if (is_array($data['brief'] ?? null)) {
            $finish($data['brief'], null);

            return false;
        }
        if (! $this->enabled()) {
            $finish(null, FirstPassService::REASON_OFF);

            return false;
        }

        // Waited too long, or started as often as it may be: never run the model on it again. The
        // reason an earlier attempt left is more use than "busy" or "interrupted".
        if ($this->pastMaxAge((int) $task->id)) {
            $finish(null, $task->error_message ?: FirstPassService::REASON_BUSY);

            return false;
        }
        if ((int) $task->attempts >= self::MAX_ATTEMPTS) {
            $finish(null, $task->error_message ?: FirstPassService::REASON_INTERRUPTED);

            return false;
        }

        $used = DB::table('support_ai_runs')->where('kind', 'FirstPass')->where('modid', $referrerId)
            ->where('created_at', '>=', now()->subDay())->count();
        if ($used >= (int) $this->cfg('per_referrer_per_day', 10)) {
            $finish(null, FirstPassService::REASON_LIMIT);

            return false;
        }

        // Counted before the helper is asked, so a run that is killed mid-pass still counts.
        DB::table('background_tasks')->where('id', $task->id)->increment('attempts');
        $query = "First pass for referral {$task->id} of chat {$chatId}";

        try {
            $response = $service->ask($service->buildRequest($referral));
        } catch (FirstPassFailure $f) {
            if ($f->failureCode === 'BUSY' || $f->failureCode === 'UNREACHABLE') {
                // No model ran: the attempt is given back. An unreachable helper leaves its reason, so
                // that is what the mail says if it stays away for the hour.
                $update = ['attempts' => DB::raw('GREATEST(attempts, 1) - 1')];
                if ($f->failureCode === 'UNREACHABLE') {
                    $update['error_message'] = $f->reason;
                }
                DB::table('background_tasks')->where('id', $task->id)->update($update);

                return true;
            }
            if ($f->mayHaveSpent()) {
                $service->storeRun($referrerId, $referral->userid, $query, null, $f->reason);
            }
            if ($f->terminal || (int) $task->attempts + 1 >= self::MAX_ATTEMPTS) {
                $finish(null, $f->reason);
            } else {
                DB::table('background_tasks')->where('id', $task->id)->update(['error_message' => $f->reason]);
            }

            return false;
        }

        $brief = $response['brief'];
        $quota = is_array($response['quota'] ?? null) ? $response['quota'] : null;
        $brief['pool'] = $quota === null ? null : [
            'fiveHour' => is_numeric($quota['fiveHour'] ?? null) ? (float) $quota['fiveHour'] : null,
            'sevenDay' => is_numeric($quota['sevenDay'] ?? null) ? (float) $quota['sevenDay'] : null,
        ];
        $service->storeRun($referrerId, $referral->userid, $query, $response, null, $brief);
        // Kept on the task until the mail has gone, so a failed mail does not cost another pass. Only
        // this key is written: the row may have changed during the pass (a forgotten member's reason
        // is taken off it), and writing the whole of $data back would undo that.
        $data['brief'] = $brief;
        $this->setData((int) $task->id, 'brief', $brief);
        $finish($brief, null);

        return false;
    }

    /** Whether a referral has waited longer than max_age_minutes. */
    private function pastMaxAge(int $taskId): bool
    {
        return DB::table('background_tasks')->where('id', $taskId)
            // keep-raw: NOW() must be the database's clock, which also stamps rows queued by the Go API; the builder binds a PHP time instead.
            ->whereRaw('created_at < NOW() - INTERVAL ? MINUTE', [(int) $this->cfg('max_age_minutes', 60)])
            ->exists();
    }

    /** Set one key of a task's data, leaving the rest of the row's JSON as it now is. */
    private function setData(int $taskId, string $key, mixed $value): void
    {
        DB::table('background_tasks')->where('id', $taskId)->update(["data->{$key}" => $value]);
    }

    /**
     * Send the referral's one mail, then mark the task processed. The order matters: a task is
     * done only once its mail is in the spool, so a run that is killed, or an update that fails,
     * leaves the task for the next run. That can send a mail twice, which is better than not at all.
     * One run at a time is kept by the command's lock.
     *
     * A mail that was not spooled (the spooler threw, or answered that it sent nothing) leaves the
     * task as it is, with its brief, for the next run. Only a thrown failure is counted. Once a
     * referral has both failed MAX_MAIL_FAILURES times and waited longer than max_age_minutes, the
     * full mail is taken to be one that cannot be built, and the plainest mail is tried instead:
     * ids only, from the no-reply address. A referral is never given up on for a mail fault: it
     * keeps being tried, and the monitoring check reports it.
     *
     * Once the mail has gone, the moderator's reason and the brief are taken out of the task row:
     * they are about a member, and nothing reads them again.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $brief
     */
    private function mail(object $task, array $data, object $referral, ?array $brief, ?string $reason, FirstPassService $service, EmailSpoolerService $spooler): void
    {
        $recipients = array_values(array_filter(array_map('trim', explode(',', (string) config('freegle.mail.support_addr', 'support@ilovefreegle.org')))));
        $failures = (int) ($data['mailfails'] ?? 0);

        try {
            $plainest = $failures >= self::MAX_MAIL_FAILURES && $this->pastMaxAge((int) $task->id);
            $mail = $plainest
                ? $service->plainestMail($referral)
                : $service->referralMail($referral, $brief, $brief === null ? ($reason ?: FirstPassService::REASON_FAILED) : null);

            // The spooler answers '' when it sent nothing without throwing (the recipient's provider
            // is deferring our mail, or the address is refused).
            if ($spooler->spool($mail, $recipients) === '') {
                Log::warning('support:first-pass mail not spooled', ['task_id' => $task->id]);

                return;
            }
        } catch (\Throwable $e) {
            Log::error('support:first-pass mail failed', ['task_id' => $task->id, 'error' => get_class($e).': '.$e->getMessage()]);
            try {
                $this->setData((int) $task->id, 'mailfails', $failures + 1);
            } catch (\Throwable $e2) {
                Log::error('support:first-pass could not count a mail failure', ['task_id' => $task->id, 'error' => get_class($e2).': '.$e2->getMessage()]);
            }

            return;
        }

        try {
            DB::table('background_tasks')->where('id', $task->id)->update([
                'processed_at' => DB::raw('NOW()'),
                'data' => DB::raw("JSON_REMOVE(data, '$.reason', '$.brief', '$.mailfails')"),
            ]);
        } catch (\Throwable $e) {
            // The mail has gone but the task still looks unsent, so the next run sends it again.
            Log::error('support:first-pass could not mark a mailed referral done', ['task_id' => $task->id, 'error' => get_class($e).': '.$e->getMessage()]);
        }
    }
}
