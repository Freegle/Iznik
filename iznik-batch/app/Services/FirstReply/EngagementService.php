<?php

namespace App\Services\FirstReply;

use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What Freegle says to someone whose post nobody has answered.
 *
 * 44% of rippled posts get no reply at all, and from the poster's side a post
 * that is quietly working and a post that has failed look exactly the same:
 * nothing happens. That silence is the actual product problem. It is not fixable
 * by inventing activity - a fake reply from a fake account would be a lie, and
 * would waste the poster's time on a handover that never comes - so everything
 * here is either true information the poster cannot currently see, or a question
 * whose answer genuinely improves the post's chances.
 *
 * The four things, in the order they become due:
 *
 *  photo    - a post with no picture is much harder to want. Asked early, while
 *             editing still feels like part of posting.
 *  delivery - the biggest single lever a poster controls. Someone who cannot
 *             collect is not a lost cause if the poster will drop it off.
 *  views    - "people ARE looking" is the reassurance that stops a poster
 *             concluding the site is broken. Only sent once enough people have
 *             looked; "1 person viewed this" is worse than saying nothing.
 *  deadline - what turns "someday" into "this weekend" for everyone who sees it.
 *             Last, because it only matters once the easy wins have not worked.
 *
 * One prompt per post per run, at most a handful per post ever, and never two to
 * the same member within the configured gap however many posts they have. The
 * failure mode being designed against is well documented elsewhere: a helper bot
 * that cannot be told apart from a real reply, and cannot be turned off, trains
 * people to ignore the notification that matters.
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

        foreach ($this->silentPosts($maxAge, $limit) as $post) {
            $stats['considered']++;

            try {
                $kind = $this->dueKind($post, $cfg);
                if ($kind === null) {
                    $stats['skipped']++;
                    continue;
                }

                if (!$this->userIsAvailable((int) $post->fromuser, $cfg)) {
                    $stats['skipped']++;
                    continue;
                }

                $prompt = $this->compose($kind, $post);
                if ($prompt === null) {
                    $stats['skipped']++;
                    continue;
                }

                if ($dryRun) {
                    $stats['sent']++;
                    $stats[$kind] = ($stats[$kind] ?? 0) + 1;
                    continue;
                }

                // Claim the (post, kind) slot BEFORE sending. The unique key is what
                // makes the whole engine idempotent, and claiming after sending would
                // leave a window where a second worker sends the same question again.
                if (!$this->claim((int) $post->msgid, (int) $post->fromuser, $kind)) {
                    $stats['skipped']++;
                    continue;
                }

                $sent = $this->prompts->send(
                    (int) $post->fromuser,
                    $kind,
                    $prompt['text'],
                    $prompt['options'],
                    (int) $post->msgid
                );

                if ($sent === null) {
                    // Give the slot back so a transient failure is retried rather
                    // than silently costing the poster the question.
                    $this->release((int) $post->msgid, $kind);
                    $stats['skipped']++;
                    continue;
                }

                $this->metrics->record('prompt_sent');
                $this->metrics->record('prompt_sent_' . $kind);
                $stats['sent']++;
                $stats[$kind] = ($stats[$kind] ?? 0) + 1;
            } catch (\Throwable $e) {
                $stats['skipped']++;
                Log::warning('firstreply: engagement failed for post', [
                    'msgid' => $post->msgid ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Live posts with no reply yet.
     *
     * messages_spatial is already "approved, open, not taken" so it does the
     * heavy lifting; the anti-join is against any Interested reply from someone
     * other than the poster. Held replies count as replies on purpose: the poster
     * has an answer coming, so nudging them about a silent post would be wrong.
     *
     * @return \Illuminate\Support\Collection<int,object>
     */
    private function silentPosts(int $maxAgeHours, int $limit)
    {
        return collect(DB::select(
            "SELECT ms.msgid AS msgid, ms.arrival AS arrival, ms.msgtype AS msgtype,
                    m.fromuser AS fromuser, m.subject AS subject,
                    m.deliverypossible AS deliverypossible, m.deadline AS deadline
             FROM messages_spatial ms
             JOIN messages m ON m.id = ms.msgid
             JOIN users u ON u.id = m.fromuser
             WHERE ms.arrival > DATE_SUB(NOW(), INTERVAL ? HOUR)
               AND m.deleted IS NULL
               AND u.deleted IS NULL
               AND NOT EXISTS (
                     SELECT 1 FROM chat_messages cm
                     WHERE cm.refmsgid = ms.msgid
                       AND cm.type = 'Interested'
                       AND cm.userid <> m.fromuser
                   )
               AND NOT EXISTS (
                     SELECT 1 FROM messages_outcomes mo WHERE mo.msgid = ms.msgid
                   )
             ORDER BY ms.arrival ASC
             LIMIT ?",
            [$maxAgeHours, $limit]
        ));
    }

    /**
     * Which question, if any, is due for this post. The first one whose hour has
     * passed, that applies, and that has not been asked wins - so a post that has
     * been quiet for a day works through them in order rather than getting all
     * four at once.
     *
     * @param array<string,mixed> $cfg
     */
    private function dueKind(object $post, array $cfg): ?string
    {
        $ageHours = $this->ageHours($post);
        $schedule = (array) ($cfg['schedule'] ?? []);

        $already = DB::table('firstreply_prompts_sent')
            ->where('msgid', $post->msgid)
            ->pluck('kind')
            ->all();

        if (count($already) >= (int) ($cfg['max_per_post'] ?? 4)) {
            return null;
        }

        // Config order is the priority order.
        foreach ($schedule as $kind => $dueAfter) {
            if (in_array($kind, $already, true)) {
                continue;
            }
            if ($ageHours < (float) $dueAfter) {
                continue;
            }
            if (!$this->applies($kind, $post, $cfg)) {
                continue;
            }

            return $kind;
        }

        return null;
    }

    /** Is this question worth asking about this particular post? */
    private function applies(string $kind, object $post, array $cfg): bool
    {
        switch ($kind) {
            case PromptService::KIND_PHOTO:
                return !$this->hasPhoto((int) $post->msgid);

            case PromptService::KIND_DELIVERY:
                // Only OFFERs: asking someone who WANTS something whether they
                // could deliver it makes no sense.
                return $post->msgtype === Message::TYPE_OFFER && !$post->deliverypossible;

            case PromptService::KIND_DEADLINE:
                return $post->deadline === null;

            case PromptService::KIND_VIEWS:
                return $this->viewCount((int) $post->msgid, (int) $post->fromuser) >= (int) ($cfg['views_min'] ?? 5);

            default:
                return false;
        }
    }

    /**
     * The question itself.
     *
     * @return array{text:string, options:array<int,array<string,string>>}|null
     */
    private function compose(string $kind, object $post): ?array
    {
        $item = $this->itemName((string) ($post->subject ?? ''));

        switch ($kind) {
            case PromptService::KIND_PHOTO:
                return [
                    'text' => "Nobody's replied about {$item} yet. Posts with a photo get a lot more interest,"
                        . " because people can see what they're getting. Could you add one?",
                    'options' => [
                        ['value' => 'add', 'label' => 'Add a photo', 'variant' => 'primary', 'action' => 'editmessage'],
                        ['value' => 'none', 'label' => "I haven't got a photo", 'variant' => 'secondary'],
                    ],
                ];

            case PromptService::KIND_DELIVERY:
                return [
                    'text' => "Still nothing about {$item}. Some freeglers would love it but have no way to"
                        . ' collect. Could you drop it off, if it worked for you?',
                    'options' => [
                        ['value' => 'maybe', 'label' => 'Maybe, if it works for me', 'variant' => 'primary'],
                        ['value' => 'no', 'label' => 'Collection only', 'variant' => 'secondary'],
                    ],
                ];

            case PromptService::KIND_DEADLINE:
                return [
                    'text' => "Still no replies about {$item}. If you need it gone by a certain date, we'll show"
                        . ' that on your post, which nudges people who were going to think about it.',
                    'options' => [
                        ['value' => 'weekend', 'label' => 'By this weekend', 'variant' => 'primary'],
                        ['value' => 'week', 'label' => 'Within a week', 'variant' => 'primary'],
                        ['value' => 'twoweeks', 'label' => 'Within two weeks', 'variant' => 'primary'],
                        ['value' => 'norush', 'label' => "There's no rush", 'variant' => 'secondary'],
                    ],
                ];

            case PromptService::KIND_VIEWS:
                $views = $this->viewCount((int) $post->msgid, (int) $post->fromuser);
                $text = "Good news: {$views} freeglers have looked at {$item}. Nobody's replied yet,"
                    . ' but people are definitely looking.';

                $stillToCome = $this->stillToCome($post);
                if ($stillToCome !== null) {
                    $text .= " It's also still spreading - it'll be shown to around {$stillToCome} more people"
                        . ' over the next few days.';
                }

                return ['text' => $text, 'options' => []];

            default:
                return null;
        }
    }

    /**
     * Roughly how many more freeglers the post will be shown to as it keeps
     * rippling out. Null when we cannot say, in which case we say nothing rather
     * than guess - a made-up number here would be exactly the dishonesty this
     * whole feature is meant to avoid.
     */
    private function stillToCome(object $post): ?int
    {
        $max = $this->maxReach->maxCumulativeUsers((int) $post->msgid);
        if ($max === null || $max <= 0) {
            return null;
        }

        try {
            $notified = (int) DB::table('rippling_reach_notified')->where('msgid', $post->msgid)->count();
        } catch (\Throwable) {
            return null;
        }

        $remaining = $max - $notified;

        // Round to something that reads as an estimate, because it is one.
        return $remaining >= 50 ? (int) (round($remaining / 50) * 50) : null;
    }

    /**
     * Genuine page-opens, not list-scroll impressions, and not the poster
     * checking their own post - which they will have done, and which would make
     * "1 freegler has looked at this" mean nobody at all.
     */
    private function viewCount(int $msgid, ?int $poster = null): int
    {
        try {
            $q = DB::table('messages_likes')
                ->where('msgid', $msgid)
                ->where('type', 'View')
                ->where('pageview', 1);

            if ($poster !== null) {
                $q->where('userid', '<>', $poster);
            }

            return (int) $q->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function hasPhoto(int $msgid): bool
    {
        try {
            return DB::table('messages_attachments')->where('msgid', $msgid)->exists();
        } catch (\Throwable) {
            // Cannot tell - assume there is one, so we never nag someone who has
            // already done the thing we are about to ask for.
            return true;
        }
    }

    /**
     * Has this member heard from Freegle too recently, or asked not to hear at
     * all? The gap is per MEMBER, not per post: someone clearing out a house
     * posts ten things in an evening and must not get ten conversations.
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

    /** Take the (post, kind) slot. False if someone else already has it. */
    private function claim(int $msgid, int $userId, string $kind): bool
    {
        try {
            return DB::table('firstreply_prompts_sent')->insertOrIgnore([
                'msgid' => $msgid,
                'userid' => $userId,
                'kind' => $kind,
                'sent_at' => now(),
            ]) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function release(int $msgid, string $kind): void
    {
        try {
            DB::table('firstreply_prompts_sent')->where('msgid', $msgid)->where('kind', $kind)->delete();
        } catch (\Throwable) {
            // Worst case the question is never asked for this post, which is a
            // far smaller problem than asking it twice.
        }
    }

    /**
     * How long the post has been up, in hours. Computed from timestamps rather
     * than Carbon's diff helpers, whose absolute-vs-signed default has changed
     * between major versions - and "how old is this" silently coming back
     * positive for a future date would fire every prompt at once.
     */
    private function ageHours(object $post): float
    {
        if (empty($post->arrival)) {
            return 0.0;
        }

        $seconds = now()->getTimestamp() - \Carbon\Carbon::parse($post->arrival)->getTimestamp();

        return max(0.0, $seconds / 3600.0);
    }

    /**
     * "OFFER: Dining chairs (Edinburgh EH1)" -> "your dining chairs".
     *
     * Falls back to "your post" for anything empty or unusually long, rather than
     * echoing a wall of text back at the member. Every caller reads as "... about
     * {$item} ...", so the possessive belongs here.
     */
    private function itemName(string $subject): string
    {
        $s = preg_replace('/^\s*(OFFER|WANTED|TAKEN|RECEIVED)\s*:\s*/i', '', $subject) ?? $subject;
        $s = preg_replace('/\s*\([^)]*\)\s*$/', '', $s) ?? $s;
        $s = trim($s);

        if ($s === '' || mb_strlen($s) > 60) {
            return 'your post';
        }

        return 'your ' . mb_strtolower($s);
    }
}
