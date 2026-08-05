<?php

namespace App\Services\FirstReply;

use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What Freegle says to someone whose posts nobody has answered.
 *
 * 44% of rippled posts get no reply at all, and from the poster's side a post
 * that is quietly working and a post that has failed look exactly the same:
 * nothing happens. That silence is the actual product problem. It is not fixable
 * by inventing activity - a fake reply from a fake account would be a lie, and
 * would waste the poster's time on a handover that never comes - so everything
 * here is either true information the poster cannot currently see, or a question
 * whose answer genuinely improves their posts' chances.
 *
 * **It talks about a member's outstanding posts as a SET, not one at a time.**
 * That is the whole shape of this, and it follows bulk freegling: a clearance is
 * one conversation about many items, not one conversation per item. So the unit
 * here is the member, not the post.
 *
 * Per-post messaging was tried and is wrong. Somebody clearing a house has six
 * posts going; drip-feeding a question about each produces a thread where every
 * message opens "still nothing on this one" about a different thing, half of them
 * are about items that have since gone, and the member has to hold the mapping in
 * their head. Grouped, the same information becomes one message that is actually
 * useful: "your 6 outstanding posts have been looked at 47 times between them",
 * and one "could you deliver?" covering all of them.
 *
 * The four things, in the order they become due:
 *
 *  photo    - posts with no picture are much harder to want. Asked early, while
 *             editing still feels like part of posting.
 *  delivery - the biggest single lever a poster controls. Someone who cannot
 *             collect is not a lost cause if the poster will drop things off.
 *  views    - "people ARE looking" is the reassurance that stops a poster
 *             concluding the site is broken. Only sent once enough people have
 *             looked; "1 person viewed this" is worse than saying nothing.
 *  deadline - what turns "someday" into a date for everyone who sees them.
 *             Last, because it only matters once the easy wins have not worked.
 *
 * Each message covers only the posts its question actually applies to - photo
 * covers the ones with no photo, delivery covers the OFFERs - and answering
 * applies to exactly those. A member gets a given question at most once per
 * cooldown however many posts they have, so the volume is bounded by the member
 * rather than by how much they are giving away.
 *
 * The failure mode being designed against is well documented elsewhere: a helper
 * bot that cannot be told apart from a real reply, and cannot be turned off,
 * trains people to ignore the notification that matters.
 */
class EngagementService
{
    public function __construct(
        private PromptService $prompts,
        private MaxReachService $maxReach,
        private Metrics $metrics,
    ) {
    }

    /**
     * One pass. Returns per-kind counts of what was sent.
     *
     * @return array<string,int>
     */
    public function run(bool $dryRun = false): array
    {
        $stats = ['considered' => 0, 'sent' => 0, 'skipped' => 0];

        if (!config('freegle.firstreply.enabled') || !config('freegle.firstreply.chat.enabled')) {
            return $stats;
        }

        $cfg = config('freegle.firstreply.chat');
        $maxAge = max(1, (int) ($cfg['max_age_hours'] ?? 72));
        $limit = max(1, (int) ($cfg['batch_limit'] ?? 200));

        // Before saying anything new, stop offering answers to questions that
        // have stopped applying. A "could you deliver?" sitting there with live
        // buttons under posts that were all collected yesterday is not merely
        // clutter - it is wrong, and answering it edits finished posts.
        $stats['retired'] = $dryRun ? 0 : $this->retireStalePrompts();

        foreach ($this->membersWithSilentPosts($maxAge, $limit) as $userId) {
            $stats['considered']++;

            try {
                $posts = $this->silentPostsFor($userId, $maxAge);
                if (empty($posts)) {
                    $stats['skipped']++;
                    continue;
                }

                if (!$this->userIsAvailable($userId, $cfg)) {
                    $stats['skipped']++;
                    continue;
                }

                $due = $this->dueKind($userId, $posts, $cfg);
                if ($due === null) {
                    $stats['skipped']++;
                    continue;
                }

                [$kind, $applicable] = $due;

                $prompt = $this->compose($kind, $applicable);
                if ($prompt === null) {
                    $stats['skipped']++;
                    continue;
                }

                if ($dryRun) {
                    $stats['sent']++;
                    $stats[$kind] = ($stats[$kind] ?? 0) + 1;
                    continue;
                }

                $msgids = array_map(static fn ($p) => (int) $p->msgid, $applicable);

                // Send and record in ONE transaction. The cadence gate is only as
                // good as the record of what was sent, so a message that got
                // delivered but not recorded is re-sent on every subsequent run,
                // for ever - which is precisely the unstoppable-bot failure this
                // whole design exists to avoid. Sending is itself transactional
                // and touches nothing but the database (delivery and push happen
                // later, off processingrequired), so if the bookkeeping row will
                // not write, the message can and must be rolled back with it.
                $sent = null;

                DB::transaction(function () use ($userId, $kind, $prompt, $msgids, &$sent) {
                    $sent = $this->prompts->send(
                        $userId,
                        $kind,
                        $prompt['text'],
                        $prompt['options'],
                        $msgids
                    );

                    if ($sent === null) {
                        return;
                    }

                    DB::table('firstreply_prompts_sent')->insert([
                        'userid' => $userId,
                        'kind' => $kind,
                        'postcount' => count($msgids),
                        'sent_at' => now(),
                    ]);
                });

                if ($sent === null) {
                    $stats['skipped']++;
                    continue;
                }

                $this->metrics->record('prompt_sent');
                $this->metrics->record('prompt_sent_' . $kind);
                $stats['sent']++;
                $stats[$kind] = ($stats[$kind] ?? 0) + 1;
            } catch (\Throwable $e) {
                $stats['skipped']++;
                Log::warning('firstreply: engagement failed for member', [
                    'userid' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Members who have at least one live post with no reply, oldest waiter first.
     *
     * @return int[]
     */
    private function membersWithSilentPosts(int $maxAgeHours, int $limit): array
    {
        $query = DB::table('messages_spatial as ms')
            ->join('messages as m', 'm.id', '=', 'ms.msgid')
            ->join('users as u', 'u.id', '=', 'm.fromuser')
            ->where('ms.arrival', '>', now()->subHours($maxAgeHours))
            ->whereNull('m.deleted')
            ->whereNull('u.deleted')
            ->whereNotExists(fn ($q) => $q->select('cm.id')
                ->from('chat_messages as cm')
                ->whereColumn('cm.refmsgid', 'ms.msgid')
                ->where('cm.type', 'Interested')
                ->whereColumn('cm.userid', '<>', 'm.fromuser'))
            ->whereNotExists(fn ($q) => $q->select('mo.id')
                ->from('messages_outcomes as mo')
                ->whereColumn('mo.msgid', 'ms.msgid'))
            ->groupBy('m.fromuser')
            ->orderByRaw('MIN(ms.arrival) ASC')
            ->limit($limit);

        // Deliberately NOT filtered by the rollout. Holdout posts still get the
        // delivery and deadline questions - see applicable().
        return $query->pluck('m.fromuser')->map('intval')->all();
    }

    /**
     * One member's silent posts, oldest first, with everything the questions need
     * to know about them.
     *
     * @return array<int,object>
     */
    private function silentPostsFor(int $userId, int $maxAgeHours): array
    {
        $query = DB::table('messages_spatial as ms')
            ->join('messages as m', 'm.id', '=', 'ms.msgid')
            ->select('ms.msgid', 'ms.arrival', 'ms.msgtype', 'm.deliverypossible', 'm.deadline')
            // The attachment's id, or null - truthy either way, and no aggregate.
            ->selectSub(
                DB::table('messages_attachments as a')
                    ->select('a.id')
                    ->whereColumn('a.msgid', 'ms.msgid')
                    ->limit(1),
                'hasphoto'
            )
            ->selectSub(
                DB::table('messages_likes as l')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('l.msgid', 'ms.msgid')
                    ->where('l.type', 'View')
                    ->where('l.pageview', 1)
                    ->whereColumn('l.userid', '<>', 'm.fromuser'),
                'views'
            )
            ->where('m.fromuser', $userId)
            ->where('ms.arrival', '>', now()->subHours($maxAgeHours))
            ->whereNull('m.deleted')
            ->whereNotExists(fn ($q) => $q->select('cm.id')
                ->from('chat_messages as cm')
                ->whereColumn('cm.refmsgid', 'ms.msgid')
                ->where('cm.type', 'Interested')
                ->whereColumn('cm.userid', '<>', 'm.fromuser'))
            ->whereNotExists(fn ($q) => $q->select('mo.id')
                ->from('messages_outcomes as mo')
                ->whereColumn('mo.msgid', 'ms.msgid'))
            ->orderBy('ms.arrival');

        return $query->get()->all();
    }

    /**
     * Which question is due for this member, and which of their posts it applies
     * to. Null when none is.
     *
     * Due-ness is judged on their OLDEST silent post: that is the one that has
     * been waiting, and it stops someone who posts something new every hour from
     * resetting the clock on questions they have already earned.
     *
     * @param array<int,object> $posts
     * @param array<string,mixed> $cfg
     * @return array{0:string, 1:array<int,object>}|null
     */
    private function dueKind(int $userId, array $posts, array $cfg): ?array
    {
        $schedule = (array) ($cfg['schedule'] ?? []);
        $cooldownDays = max(1, (int) ($cfg['kind_cooldown_days'] ?? 14));

        $oldestAge = $this->ageHours($posts[0]);

        $recent = DB::table('firstreply_prompts_sent')
            ->where('userid', $userId)
            ->where('sent_at', '>', now()->subDays($cooldownDays))
            ->pluck('kind')
            ->all();

        // Config order is the priority order.
        foreach ($schedule as $kind => $dueAfter) {
            if (in_array($kind, $recent, true)) {
                continue;
            }
            if ($oldestAge < (float) $dueAfter) {
                continue;
            }

            $applicable = $this->applicable($kind, $posts, $cfg);
            if (!empty($applicable)) {
                return [$kind, $applicable];
            }
        }

        return null;
    }

    /**
     * The subset of a member's silent posts a given question is actually about.
     * Asking "could you deliver?" about a WANTED, or "add a photo" about posts
     * that already have one, is how a helpful message becomes noise.
     *
     * @param array<int,object> $posts
     * @return array<int,object>
     */
    private function applicable(string $kind, array $posts, array $cfg): array
    {
        // The trial arm decides which QUESTIONS a post gets, not whether it is
        // spoken to at all.
        //
        // `delivery` and `deadline` change the post itself - they set
        // deliverypossible and deadline, which everyone browsing then sees. That
        // is a product improvement in its own right, and it is not what the
        // first-reply trial is measuring. Withholding them from the holdout arm
        // would cost those posters something real to buy no experimental
        // cleanliness, so they are asked of everyone.
        //
        // `photo` and `views` stay inside the trial: both are about how the wait
        // FEELS rather than about what the post says, which is exactly what is
        // being measured, and neither changes anything a browser can see.
        if (!in_array($kind, [PromptService::KIND_DELIVERY, PromptService::KIND_DEADLINE], true)) {
            $posts = array_values(array_filter(
                $posts,
                static fn ($p) => Rollout::includes((int) $p->msgid)
            ));

            if (empty($posts)) {
                return [];
            }
        }

        switch ($kind) {
            case PromptService::KIND_PHOTO:
                return array_values(array_filter($posts, static fn ($p) => !$p->hasphoto));

            case PromptService::KIND_DELIVERY:
                // Only OFFERs: asking someone who WANTS something whether they
                // could deliver it makes no sense.
                return array_values(array_filter(
                    $posts,
                    static fn ($p) => $p->msgtype === Message::TYPE_OFFER && !$p->deliverypossible
                ));

            case PromptService::KIND_DEADLINE:
                return array_values(array_filter($posts, static fn ($p) => $p->deadline === null));

            case PromptService::KIND_VIEWS:
                // Judged on the total across their posts, which is the number the
                // message actually quotes.
                $total = array_sum(array_map(static fn ($p) => (int) $p->views, $posts));

                return $total >= (int) ($cfg['views_min'] ?? 5) ? $posts : [];

            default:
                return [];
        }
    }

    /**
     * The question itself, phrased for however many posts it covers.
     *
     * Deliberately says nothing about WHICH posts by name. An item name reads
     * fine as "your dining chairs" and terribly as whatever someone actually
     * typed, and there is no way to tell the two apart. The chat and the
     * notification email both render the posts themselves, so the question can
     * just be the question.
     *
     * @param array<int,object> $posts
     * @return array{text:string, options:array<int,array<string,string>>}|null
     */
    private function compose(string $kind, array $posts): ?array
    {
        $n = count($posts);
        $many = $n > 1;

        switch ($kind) {
            case PromptService::KIND_PHOTO:
                return [
                    'text' => $many
                        ? "{$n} of your posts have had no reply and have no photo. Posts with a photo get"
                            . " a lot more interest, because people can see what they're getting."
                            . ' Could you add some?'
                        : "Nobody's replied about this yet, and it has no photo. Posts with a photo get a"
                            . " lot more interest, because people can see what they're getting."
                            . ' Could you add one?',
                    'options' => [
                        [
                            'value' => 'add',
                            'label' => $many ? 'Add photos' : 'Add a photo',
                            'variant' => 'primary',
                            'action' => 'editmessage',
                        ],
                        ['value' => 'none', 'label' => "I haven't got photos", 'variant' => 'secondary'],
                    ],
                ];

            case PromptService::KIND_DELIVERY:
                return [
                    // "might", not "would". Nobody has replied, so there is no
                    // evidence anyone wants these at all - claiming otherwise is
                    // the same invention this whole feature refuses to make.
                    'text' => $many
                        ? "Still nothing on {$n} of your offers. Some freeglers might love them but have"
                            . ' no way to collect. Could you drop things off, if it worked for you?'
                        : 'Still nothing on this one. Some freeglers might love it but have no way to'
                            . ' collect. Could you drop it off, if it worked for you?',
                    'options' => [
                        ['value' => 'maybe', 'label' => 'Maybe, if it works for me', 'variant' => 'primary'],
                        ['value' => 'no', 'label' => 'Collection only', 'variant' => 'secondary'],
                    ],
                ];

            case PromptService::KIND_DEADLINE:
                return [
                    'text' => $many
                        ? "Still no replies on {$n} of your posts. If you need them gone by a certain"
                            . " date, we'll show that, which nudges people who were going to think about it."
                        : "Still no replies. If you need this gone by a certain date, we'll show that on"
                            . ' your post, which nudges people who were going to think about it.',
                    'options' => [
                        // A date picker rather than fixed choices: "by this weekend"
                        // is wrong for anyone moving house on the 14th, and the
                        // poster already knows their own date.
                        ['value' => 'date', 'label' => 'Pick a date', 'variant' => 'primary', 'input' => 'date'],
                        ['value' => 'norush', 'label' => "There's no rush", 'variant' => 'secondary'],
                    ],
                ];

            case PromptService::KIND_VIEWS:
                $views = array_sum(array_map(static fn ($p) => (int) $p->views, $posts));

                $text = $many
                    ? "Good news: your {$n} outstanding posts have been looked at {$views} times between"
                        . " them. Nobody's replied yet, but people are definitely looking."
                    : "Good news: {$views} freeglers have looked at this. Nobody's replied yet, but"
                        . ' people are definitely looking.';

                $stillToCome = $this->stillToCome($posts);
                if ($stillToCome !== null) {
                    $text .= $many
                        ? " They're also still spreading - they'll be shown to around {$stillToCome} more"
                            . ' people over the next few days.'
                        : " It's also still spreading - it'll be shown to around {$stillToCome} more"
                            . ' people over the next few days.';
                }

                return ['text' => $text, 'options' => []];

            default:
                return null;
        }
    }

    /**
     * Roughly how many more freeglers these posts will be shown to as they keep
     * rippling out. Null when we cannot say, in which case we say nothing rather
     * than guess - a made-up number here would be exactly the dishonesty this
     * whole feature is meant to avoid.
     *
     * @param array<int,object> $posts
     */
    private function stillToCome(array $posts): ?int
    {
        $remaining = 0;

        foreach ($posts as $post) {
            $max = $this->maxReach->maxCumulativeUsers((int) $post->msgid);
            if ($max === null || $max <= 0) {
                continue;
            }

            try {
                $notified = (int) DB::table('rippling_reach_notified')
                    ->where('msgid', $post->msgid)->count();
            } catch (\Throwable) {
                return null;
            }

            $remaining += max(0, $max - $notified);
        }

        // Round to something that reads as an estimate, because it is one.
        return $remaining >= 50 ? (int) (round($remaining / 50) * 50) : null;
    }

    /**
     * Retire unanswered prompts none of whose posts are still silent - they have
     * replies, outcomes, or have been deleted.
     *
     * Expiring rather than deleting: the conversation should still read back in
     * order, and a question that visibly went unanswered is part of the story. It
     * just stops offering buttons, which is what stops it editing posts that are
     * no longer live.
     *
     * A prompt covering several posts is retired only when they have ALL stopped
     * being silent. While any of them is still waiting the question still means
     * something, and answering it applies to whichever are still live.
     *
     * @return int how many were retired
     */
    private function retireStalePrompts(): int
    {
        try {
            return DB::update(
                "UPDATE chat_prompts cp
                 SET cp.expires_at = NOW()
                 WHERE cp.answered_at IS NULL
                   AND (cp.expires_at IS NULL OR cp.expires_at > NOW())
                   AND cp.msgids IS NOT NULL
                   AND NOT EXISTS (
                         SELECT 1
                         FROM JSON_TABLE(cp.msgids, '$[*]' COLUMNS (mid BIGINT PATH '$')) jt
                         JOIN messages m ON m.id = jt.mid
                         WHERE m.deleted IS NULL
                           AND NOT EXISTS (
                                 SELECT 1 FROM messages_outcomes mo WHERE mo.msgid = jt.mid
                               )
                           AND NOT EXISTS (
                                 SELECT 1 FROM chat_messages cm
                                 WHERE cm.refmsgid = jt.mid
                                   AND cm.type = ?
                                   AND cm.userid <> m.fromuser
                               )
                       )",
                ['Interested']
            );
        } catch (\Throwable $e) {
            Log::warning('firstreply: could not retire stale prompts', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Has this member heard from Freegle too recently, or asked not to hear at
     * all? The gap is per MEMBER, which is now the natural unit: one message
     * covers everything they have outstanding.
     */
    private function userIsAvailable(int $userId, array $cfg): bool
    {
        $settings = DB::table('users')->where('id', $userId)->value('settings');
        if ($settings) {
            $decoded = json_decode((string) $settings, true);
            if (is_array($decoded) && array_key_exists('freeglechat', $decoded) && !$decoded['freeglechat']) {
                return false;
            }
        }

        $gapHours = max(0, (int) ($cfg['user_gap_hours'] ?? 6));
        if ($gapHours === 0) {
            return true;
        }

        return !DB::table('firstreply_prompts_sent')
            ->where('userid', $userId)
            ->where('sent_at', '>', now()->subHours($gapHours))
            ->exists();
    }

    /**
     * How long a post has been up, in hours. Computed from timestamps rather than
     * Carbon's diff helpers, whose absolute-vs-signed default has changed between
     * major versions - and "how old is this" silently coming back positive for a
     * future date would fire every prompt at once.
     */
    private function ageHours(object $post): float
    {
        if (empty($post->arrival)) {
            return 0.0;
        }

        $seconds = now()->getTimestamp() - \Carbon\Carbon::parse($post->arrival)->getTimestamp();

        return max(0.0, $seconds / 3600.0);
    }
}
