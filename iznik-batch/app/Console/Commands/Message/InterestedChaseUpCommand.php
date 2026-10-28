<?php

namespace App\Console\Commands\Message;

use App\Mail\Message\InterestedChaseUp;
use App\Models\Message;
use App\Models\User;
use App\Services\EmailSpoolerService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Reminds members of posts they marked "I'm interested" in and have not replied to: one email per member naming
 * every such post that is still open. A mark is picked once it is chaseup_after_hours old and until it is
 * max_age_days old, with chasedat still empty, and chasedat is set on exactly the marks the run names, so each
 * mark is named once and a second run finds nothing.
 *
 * A post that has gone (deleted, taken or received, promised, or no longer approved on a community) is left
 * alone, and so is a mark that has had a reply: both age out. A member who is deleted, has no usable address,
 * is bouncing or has turned our mail off is not mailed, but their marks still get chasedat, so they are not
 * picked again every hour for the rest of the week.
 *
 * Tracking: docs/developers/reference/ux-experiments.md, "An I'm interested button".
 */
#[AsCommand(name: 'messages:interested-chaseup')]
class InterestedChaseUpCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'messages:interested-chaseup
                            {--dry-run : List what would be sent without sending or writing anything}';

    protected $description = 'Remind members of open posts they marked "I\'m interested" in and have not replied to';

    public function handle(EmailSpoolerService $spooler): int
    {
        $this->registerShutdownHandlers();

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN MODE - nothing sent, nothing written.');
        }

        return $this->runWithLogging(function () use ($spooler, $dryRun) {
            $now = Carbon::now();
            $chunk = max(1, (int) config('freegle.interested.chunk', 200));
            $totals = ['members' => 0, 'emails' => 0, 'posts' => 0, 'skipped' => 0];

            // Members are walked in id order from a cursor, so a dry run (which writes nothing) still finishes.
            $after = 0;
            while (! $this->shouldStop()) {
                $userids = $this->due($now)->where('i.userid', '>', $after)
                    ->select('i.userid')->distinct()->orderBy('i.userid')->limit($chunk)->pluck('i.userid')->all();
                if (! $userids) {
                    break;
                }
                $after = (int) end($userids);

                $rows = $this->due($now)->whereIn('i.userid', $userids)->select('i.id', 'i.userid', 'i.msgid')->orderBy('i.id')->get()->groupBy('userid');
                foreach ($userids as $userid) {
                    if ($this->shouldStop()) {
                        break;
                    }
                    $this->chase((int) $userid, ($rows->get($userid) ?? collect())->all(), $spooler, $dryRun, $totals);
                }
            }

            $prefix = $dryRun ? 'Would ' : '';
            $this->table(['Operation', 'Count'], [
                [$prefix.'Members with posts to chase', number_format($totals['members'])],
                [$prefix.'Members emailed', number_format($totals['emails'])],
                [$prefix.'Posts named', number_format($totals['posts'])],
                [$prefix.'Members skipped (no mail wanted)', number_format($totals['skipped'])],
            ]);
            Log::info('Interested chase-up completed', $totals + ['dry_run' => $dryRun]);

            return Command::SUCCESS;
        });
    }

    /**
     * The marks to chase: not yet chased, in the age window, on an open post, and not followed by a reply from the
     * member to that post. The window is read from the (chasedat, added) index; the rest are keyed lookups, each
     * on the mark's own post or member (chat_messages by its userid index).
     */
    private function due(Carbon $now): Builder
    {
        $after = max(0, (int) config('freegle.interested.chaseup_after_hours', 24));
        $maxAge = max(1, (int) config('freegle.interested.max_age_days', 7));

        return DB::table('messages_interested as i')
            ->whereNull('i.chasedat')
            ->where('i.added', '<=', $now->copy()->subHours($after)->toDateTimeString())
            ->where('i.added', '>=', $now->copy()->subDays($maxAge)->toDateTimeString())
            ->whereExists(fn ($q) => $q->from('messages as m')->whereColumn('m.id', 'i.msgid')->whereNull('m.deleted'))
            ->whereExists(fn ($q) => $q->from('messages_groups as mg')->whereColumn('mg.msgid', 'i.msgid')
                ->where('mg.collection', 'Approved')->where('mg.deleted', 0))
            ->whereNotExists(fn ($q) => $q->from('messages_outcomes as o')->whereColumn('o.msgid', 'i.msgid'))
            ->whereNotExists(fn ($q) => $q->from('messages_promises as p')->whereColumn('p.msgid', 'i.msgid'))
            ->whereNotExists(fn ($q) => $q->from('chat_messages as cm')->whereColumn('cm.userid', 'i.userid')
                ->whereColumn('cm.refmsgid', 'i.msgid')->where('cm.type', 'Interested'));
    }

    /**
     * One member: mail them the posts, then record that the marks were named. The marks are claimed before the
     * send, as matches:notify does, so a failure after the send cannot name them twice; a failed send gives the
     * claim back.
     *
     * @param  array<int, object>  $marks
     * @param  array<string, int>  $totals
     */
    private function chase(int $userid, array $marks, EmailSpoolerService $spooler, bool $dryRun, array &$totals): void
    {
        $ids = array_map(fn ($m) => (int) $m->id, $marks);
        $totals['members']++;

        $user = User::find($userid);
        $email = ($user && $user->sendOurMails()) ? $user->email_preferred : null;
        if (! $email) {
            $totals['skipped']++;
            $this->claim($ids, $dryRun);

            return;
        }

        $messages = Message::query()->whereIn('id', array_map(fn ($m) => (int) $m->msgid, $marks))->with(['attachments', 'groups'])->get()->all();
        if (! $messages) {
            return;
        }

        $totals['emails']++;
        $totals['posts'] += count($messages);
        if ($dryRun) {
            $this->line("Would email user $userid about ".implode(', ', array_map(fn ($m) => $m->id, $messages)));

            return;
        }

        if ($this->claim($ids, false) === 0) {
            return;
        }
        try {
            $spooler->spool(new InterestedChaseUp($user, $email, $messages));
        } catch (\Throwable $e) {
            DB::table('messages_interested')->whereIn('id', $ids)->update(['chasedat' => null]);
            throw $e;
        }
    }

    /** Set chasedat on the marks that still have none. Returns how many were set. */
    private function claim(array $ids, bool $dryRun): int
    {
        if ($dryRun) {
            return count($ids);
        }

        return DB::table('messages_interested')->whereIn('id', $ids)->whereNull('chasedat')->update(['chasedat' => Carbon::now()->toDateTimeString()]);
    }
}
