<?php

namespace App\Console\Commands\Message;

use App\Mail\Message\FamiliarPosts;
use App\Models\Message;
use App\Models\User;
use App\Services\EmailSpoolerService;
use App\Services\FamiliarPostsService;
use App\Services\Ux\UxArm;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The experiment familiar-poster: tell a member about new posts from people they have recently
 * chatted with, without it going badly, in case knowing the poster makes them more likely to reply.
 *
 * Hourly. It does nothing unless the experiment is serving arms. It reads the new posts since the last
 * run (config row familiar_posts_cursor, at most window_hours back) and, for each member who knows a
 * poster (FamiliarPostsService), records a `post` exposure for each post in both arms. Members in the
 * `notify` arm also get one email naming those posts, at most max_posts_per_email of them.
 *
 * Not too many: a member is reached at most once in min_gap_hours and on at most max_days_per_week of
 * the last seven days, counted from the exposures, so both arms are held to the same limits. A member
 * who is deleted, inactive, bouncing, on holiday or has our mail off is not reached at all.
 *
 * docs/developers/reference/familiar-posts-email.md.
 */
#[AsCommand(name: 'messages:familiar-posts')]
class FamiliarPostsCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    public const EXPERIMENT = 'familiar-poster';

    public const ARM_NOTIFY = 'notify';

    public const CURSOR_KEY = 'familiar_posts_cursor';

    protected $signature = 'messages:familiar-posts
                            {--dry-run : List what would be sent without sending or writing anything}';

    protected $description = 'Experiment familiar-poster: email members new posts from people they have chatted with';

    public function handle(EmailSpoolerService $spooler, FamiliarPostsService $service): int
    {
        $this->registerShutdownHandlers();

        if (! UxArm::serving(self::EXPERIMENT)) {
            $this->info('The experiment '.self::EXPERIMENT.' is not running, so nothing to do.');

            return Command::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN MODE - nothing sent, nothing written.');
        }

        return $this->runWithLogging(function () use ($spooler, $service, $dryRun) {
            $now = Carbon::now('UTC');
            $from = $this->from($now);
            $totals = ['posts' => 0, 'members' => 0, 'notify' => 0, 'control' => 0, 'capped' => 0, 'no_mail' => 0];

            $byMember = [];
            $maxPosts = max(1, (int) config('freegle.familiar_posts.max_posts_per_email', 3));
            foreach ($service->newPosts($from, $now) as $post) {
                if ($this->shouldStop()) {
                    break;
                }
                $totals['posts']++;
                foreach ($service->recipientsFor($post->id, $post->fromuser, $now) as $member) {
                    if (count($byMember[$member] ?? []) < $maxPosts) {
                        $byMember[$member][$post->id] = $post->fromuser;
                    }
                }
            }

            foreach ($byMember as $member => $posts) {
                if ($this->shouldStop()) {
                    break;
                }
                $this->reach((int) $member, $posts, $now, $spooler, $dryRun, $totals);
            }

            if (! $dryRun && ! $this->shouldStop()) {
                DB::table('config')->updateOrInsert(['key' => self::CURSOR_KEY], ['value' => $now->format('Y-m-d H:i:s')]);
            }

            $prefix = $dryRun ? 'Would ' : '';
            $this->table(['Operation', 'Count'], [
                ['New posts read', number_format($totals['posts'])],
                ['Members who know a poster', number_format($totals['members'])],
                [$prefix.'Email (notify arm)', number_format($totals['notify'])],
                [$prefix.'Count only (control arm)', number_format($totals['control'])],
                ['Skipped, reached too recently', number_format($totals['capped'])],
                ['Skipped, no mail wanted', number_format($totals['no_mail'])],
            ]);
            Log::info('Familiar posts completed', $totals + ['dry_run' => $dryRun]);

            return Command::SUCCESS;
        });
    }

    /** Where this run starts: the last run's end, but never more than window_hours ago. */
    private function from(Carbon $now): Carbon
    {
        $floor = $now->copy()->subHours(max(1, (int) config('freegle.familiar_posts.window_hours', 6)));
        $cursor = DB::table('config')->where('key', self::CURSOR_KEY)->value('value');
        if (! $cursor) {
            return $now->copy()->subHour();
        }

        $at = Carbon::parse($cursor, 'UTC');

        return $at->greaterThan($floor) ? $at : $floor;
    }

    /**
     * One member: check the limits and their mail settings, record the exposures in their arm, and mail
     * the notify arm.
     *
     * @param  array<int, int>  $posts  post id => poster id
     * @param  array<string, int>  $totals
     */
    private function reach(int $member, array $posts, Carbon $now, EmailSpoolerService $spooler, bool $dryRun, array &$totals): void
    {
        // A literal, so UxSwitchManifestTest can match the call to its entry in config/ux_switches.php.
        $arm = UxArm::for($member, 'familiar-poster');
        if ($arm === '') {
            return;
        }
        $totals['members']++;

        if ($this->tooSoon($member, $now)) {
            $totals['capped']++;

            return;
        }

        $user = User::find($member);
        $email = ($user && $user->sendOurMails()) ? $user->email_preferred : null;
        if (! $email) {
            $totals['no_mail']++;

            return;
        }

        $notify = $arm === self::ARM_NOTIFY;
        $totals[$notify ? 'notify' : 'control']++;
        if ($dryRun) {
            $this->line("Would reach member $member ($arm) about ".implode(', ', array_keys($posts)));

            return;
        }

        $written = [];
        foreach ($posts as $msgid => $poster) {
            if (UxArm::expose($member, self::EXPERIMENT, $arm, 'post', (int) $msgid, null, ['poster' => (int) $poster])) {
                $written[] = (int) $msgid;
            }
        }
        if (! $notify || ! $written) {
            return;
        }

        $messages = Message::query()->whereIn('id', $written)->with(['attachments', 'fromUser'])->get()->all();
        try {
            $spooler->spool(new FamiliarPosts($user, $email, $messages));
        } catch (\Throwable $e) {
            foreach ($written as $msgid) {
                UxArm::retract($member, self::EXPERIMENT, 'post', $msgid);
            }
            throw $e;
        }
    }

    /**
     * Reached in the last min_gap_hours, or on max_days_per_week of the last seven days. Counted from the
     * exposures, which both arms write, so the limits fall on both arms alike.
     */
    private function tooSoon(int $member, Carbon $now): bool
    {
        $gap = max(1, (int) config('freegle.familiar_posts.min_gap_hours', 24));
        $perWeek = max(1, (int) config('freegle.familiar_posts.max_days_per_week', 2));

        $rows = fn () => DB::table('ux_experiment_exposures')
            ->where('experiment', self::EXPERIMENT)
            ->where('userid', $member);

        if ($rows()->where('exposed_at', '>=', $now->copy()->subHours($gap)->format('Y-m-d H:i:s'))->exists()) {
            return true;
        }

        $days = $rows()->where('exposed_at', '>=', $now->copy()->subDays(7)->format('Y-m-d H:i:s'))
            ->pluck('exposed_at')
            ->map(fn ($at) => substr((string) $at, 0, 10))
            ->unique()
            ->count();

        return $days >= $perWeek;
    }
}
