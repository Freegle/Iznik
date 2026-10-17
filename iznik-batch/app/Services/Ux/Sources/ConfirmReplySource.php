<?php

namespace App\Services\Ux\Sources;

use App\Models\ChatMessage;
use App\Services\Ux\ExposureSource;
use App\Services\Ux\UxExperimentRefused;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The experiment confirm-reply, counted from the database. An exposure is a replier's reply to an offer that
 * looked damaged or too big to carry (chat/replyconfirm.go writes it, in both arms), and the replier is counted
 * once, on their first such reply, once that reply is a window old. Strata are why the reply was eligible:
 * damaged, car or both.
 *
 * The experiment is decided on one of two results, chosen with design.primary when it is created:
 *  - no_wasted_reply (the default): the item is handed over to the replier within the window of their reply, which
 *    means a messages_promises row for them on the post or a messages_by row naming them, or the reply never
 *    reached the poster because the replier said no. This is the result the question is for: a reply the poster
 *    does not have to read from someone who no longer wants the item. A question nobody answered is not a win: it
 *    is counted apart (never_answered), as is a post that went while the reply waited. The guardrail is
 *    fewer_handovers: the share of repliers who were not handed the item must not rise by more than 2 points,
 *    because a lost handover is invisible in this result (it moves a replier from handed over to never reached).
 *  - handover: the item is handed over to the replier within the window of their reply. The guardrail is
 *    never_delivered: repliers whose reply the question dropped (they said no, or never answered).
 *
 * docs/developers/reference/ux-experiments.md, "Confirming a reply before it is passed on".
 */
class ConfirmReplySource extends ExposureSource
{
    public const NO_WASTED_REPLY = 'no_wasted_reply';

    public const HANDOVER = 'handover';

    /**
     * never_delivered trips only if the asked arm loses more than this many points more of its repliers than
     * control does. Control loses none (only the question drops a reply), so this is a disaster guard: a
     * question nobody can see or answer, not a fine one.
     */
    public const NEVER_DELIVERED_MARGIN_POINTS = 50.0;

    /** fewer_handovers: the engine's own default margin (config freegle.ux.experiments.guardrail_margin_points). */
    public const FEWER_HANDOVERS_MARGIN_POINTS = 2.0;

    private const PROMPT_KIND = 'confirm_reply';

    /**
     * The item was handed over to the replier within :window hours of their reply. The post is the reply's
     * refmsgid (cm is the exposure's chat message). A withdrawn promise is deleted (renegePromise), so this is
     * read only once a reply is a whole window old; messages_by has no row when the poster marks an item taken
     * without naming the replier, in both arms alike.
     */
    private const HANDED_OVER = '(EXISTS (SELECT 1 FROM messages_promises p WHERE p.msgid = cm.refmsgid AND p.userid = c.userid'
        .' AND p.promisedat >= c.exposed_at AND p.promisedat < c.exposed_at + INTERVAL :window HOUR)'
        .' OR EXISTS (SELECT 1 FROM messages_by b WHERE b.msgid = cm.refmsgid AND b.userid = c.userid'
        .' AND b.`timestamp` >= c.exposed_at AND b.`timestamp` < c.exposed_at + INTERVAL :window HOUR))';

    /**
     * The reply was processed and refused, so the poster never had it. Still waiting is not "never reached". Two
     * refusals are not the replier's decision and are left out: a question nobody answered, and a post that was taken
     * or withdrawn while the reply waited. Counting them would score a win for every question that was ignored.
     */
    private const NEVER_REACHED = "(cm.processingrequired = 0 AND cm.processingsuccessful = 0 AND COALESCE(cm.processingfailreason, '') NOT IN ('"
        .ChatMessage::PROCESSFAIL_NOT_CONFIRMED."', '".ChatMessage::PROCESSFAIL_POST_GONE."'))";

    /**
     * The values of design.primary, first the default. Read by UxExperimentService::normaliseDesign, so a value
     * that is not here is refused when the experiment is created.
     *
     * @return string[]
     */
    public static function primaries(): array
    {
        return [self::NO_WASTED_REPLY, self::HANDOVER];
    }

    public function types(): array
    {
        return ['reply'];
    }

    private function primaryOf(object $exp): string
    {
        $primary = $this->designOf($exp)['primary'] ?? self::NO_WASTED_REPLY;
        if (! in_array($primary, self::primaries(), true)) {
            throw new UxExperimentRefused('design.primary for confirm_reply must be '.implode(' or ', self::primaries()).'.');
        }

        return $primary;
    }

    public function words(object $exp): array
    {
        $window = self::windowText($this->windowOf($exp));
        $wasted = $this->primaryOf($exp) === self::NO_WASTED_REPLY;

        return [
            'who' => 'repliers',
            'started' => 'were counted',
            'finished' => $wasted ? 'had no wasted reply within '.$window : 'were promised the item or marked as taking it within '.$window,
            'tab_started' => 'Counted',
            'tab_finished' => $wasted ? 'No wasted reply within '.$window : 'Promised or taken within '.$window,
            'note' => 'Each replier is counted once, for their first reply to an offer that looked damaged or too big to carry, and only when that reply is at least '.$window.' old. '
                .($wasted
                    ? 'No wasted reply means the poster promised the item to the replier or marked it as taken by them within '.$window.' of the reply, or the replier said no to the question.'
                    : 'Promised or taken means the poster promised the item to the replier or marked it as taken by them within '.$window.' of the reply.'),
        ];
    }

    public function guardrails(object $exp): array
    {
        if ($this->primaryOf($exp) === self::NO_WASTED_REPLY) {
            return ['fewer_handovers' => ['label' => 'the share of repliers who were not promised the item or marked as taking it', 'margin_points' => self::FEWER_HANDOVERS_MARGIN_POINTS]];
        }

        return ['never_delivered' => ['label' => 'the share of repliers whose reply was never passed on', 'margin_points' => self::NEVER_DELIVERED_MARGIN_POINTS]];
    }

    protected function joins(object $exp): string
    {
        // LEFT, so a chat message that has gone is a replier who did not convert, in both arms alike.
        return 'LEFT JOIN chat_messages cm ON cm.id = c.subject_id';
    }

    protected function converted(object $exp): string
    {
        return $this->primaryOf($exp) === self::NO_WASTED_REPLY
            ? '('.self::HANDED_OVER.' OR '.self::NEVER_REACHED.')'
            : self::HANDED_OVER;
    }

    protected function bad(object $exp): array
    {
        if ($this->primaryOf($exp) === self::NO_WASTED_REPLY) {
            return ['fewer_handovers' => 'NOT '.self::HANDED_OVER];
        }

        return ['never_delivered' => "cm.processingfailreason IN ('".ChatMessage::PROCESSFAIL_REPLY_WITHDRAWN."', '".ChatMessage::PROCESSFAIL_NOT_CONFIRMED."')"];
    }

    /**
     * What the hosted detail piece draws: for each reason (the stratum) and for all of them, and for each arm,
     * every first reply of a replier from the start of the run (or the decision), of any age. Counted and
     * handed_over are the mature ones only. post_gone is a question closed because the post was taken or withdrawn first. never_answered is the batch's own verdict (the reply was dropped as not
     * confirmed), and waiting is a question not yet answered whose reply is still held, so neither needs a clock.
     * Only the two buttons answer the question, so a yes is always a tap. The first reply is released after a yes.
     *
     * @return array{reasons: array<string, array<string, array<string, int>>>}
     */
    protected function extra(object $exp, Carbon $now, ?Carbon $since): array
    {
        $window = $this->windowOf($exp);
        $from = ($since ?: Carbon::parse($exp->started_at, 'UTC'))->copy()->utc()->format('Y-m-d H:i:s');
        $mature = $now->copy()->utc()->subHours($window)->format('Y-m-d H:i:s');
        $handed = str_replace(':window', (string) $window, self::HANDED_OVER);

        // keep-raw: one grouped query over each replier's first exposure and the chat message and prompt it names; the query builder cannot express the derived table.
        $rows = DB::select(
            "SELECT c.arm, c.stratum, COUNT(*) AS replies,
                    SUM(cp.chatmsgid IS NOT NULL) AS asked,
                    SUM(cp.answer = 'yes' AND cp.answered_via = 'button') AS yes_button,
                    SUM(cp.answer = 'no') AS no_count,
                    SUM(cp.answered_at IS NULL AND cm.processingfailreason = ?) AS never_answered,
                    SUM(cp.answer = 'expired') AS post_gone,
                    SUM(cp.chatmsgid IS NOT NULL AND cp.answered_at IS NULL AND cm.processingrequired = 1) AS waiting,
                    SUM(cm.processingsuccessful = 1) AS reached,
                    SUM(c.exposed_at <= ?) AS counted,
                    SUM(c.exposed_at <= ? AND $handed) AS handed_over
             FROM (SELECT x.* FROM ux_experiment_exposures x
                   JOIN (SELECT MIN(id) id FROM ux_experiment_exposures WHERE experiment = ? AND exposed_at >= ? AND subject_type = 'reply' GROUP BY userid) f ON f.id = x.id) c
             LEFT JOIN chat_messages cm ON cm.id = c.subject_id
             LEFT JOIN chat_prompts cp ON cp.heldchatmsgid = cm.id AND cp.kind = ?
             GROUP BY c.arm, c.stratum",
            [ChatMessage::PROCESSFAIL_NOT_CONFIRMED, $mature, $mature, $exp->name, $from, self::PROMPT_KIND]
        );

        $arms = array_map('strval', array_keys(json_decode($exp->arms, true) ?: []));
        $cells = ['replies' => 0, 'asked' => 0, 'yes_button' => 0, 'no' => 0, 'never_answered' => 0, 'post_gone' => 0, 'waiting' => 0, 'reached' => 0, 'counted' => 0, 'handed_over' => 0];
        $reasons = ['all' => array_fill_keys($arms, $cells)];
        foreach ($rows as $r) {
            if (! in_array($r->arm, $arms, true)) {
                continue;
            }
            $stratum = $r->stratum === null ? 'unknown' : (string) $r->stratum;
            $reasons[$stratum] ??= array_fill_keys($arms, $cells);
            $one = ['replies' => (int) $r->replies, 'asked' => (int) $r->asked, 'yes_button' => (int) $r->yes_button, 'no' => (int) $r->no_count, 'never_answered' => (int) $r->never_answered, 'post_gone' => (int) $r->post_gone, 'waiting' => (int) $r->waiting, 'reached' => (int) $r->reached,
                'counted' => (int) $r->counted, 'handed_over' => (int) $r->handed_over];
            $reasons[$stratum][$r->arm] = $one;
            foreach ($one as $key => $n) {
                $reasons['all'][$r->arm][$key] += $n;
            }
        }

        return ['reasons' => $reasons];
    }

    /**
     * What a run could count, to refuse a start that could not be read: the repliers who would be in one intake
     * (halved for each arm) and the share handed the item. It looks at the intake_hours of eligible replies that
     * ended one window ago, so every reply has had its whole window to be handed over, which is how
     * EmailClickSource sizes a mail run. It repeats the gates of planReplyConfirm in SQL, so it overstates:
     * AMP and LoveJunk replies are in it (they cannot be told from the website's), and so is a reply made in
     * the hour before its post was classified, which the run would not ask about. A replier counts once however
     * many eligible replies they make, as the run counts them. The join order is forced (STRAIGHT_JOIN): chat_messages is
     * by far the biggest table, and its date index would otherwise be read first, where the classified posts are few.
     * Any error is logged and skips the check: a size estimate must not stop a start.
     */
    public function volume(object $exp, Carbon $now): ?array
    {
        $window = $this->windowOf($exp);
        $intake = max(1, (int) ($this->designOf($exp)['intake_hours'] ?? config('freegle.ux.channels.server.intake_hours', 168)));
        $to = $now->copy()->utc()->subHours($window);
        $from = $to->copy()->subHours($intake);
        $handed = str_replace([':window', 'c.exposed_at', 'c.userid'], [(string) $window, 'cm.date', 'cm.userid'], self::HANDED_OVER);

        try {
            // keep-raw: a size estimate over the posts the question applies to; the gates repeat askReplierToConfirm's and the query builder cannot express the correlated tests.
            $rows = DB::select(
                "SELECT STRAIGHT_JOIN COUNT(*) AS replies, COUNT(DISTINCT cm.userid) AS repliers, COALESCE(SUM($handed), 0) AS handed_over
                 FROM messages_eee e
                 JOIN messages m ON m.id = e.msgid AND m.type = 'Offer' AND m.fromuser IS NOT NULL
                 JOIN chat_messages cm ON cm.refmsgid = m.id AND cm.type = 'Interested' AND cm.platform = 1 AND cm.userid <> m.fromuser AND cm.date >= ? AND cm.date < ?
                 JOIN chat_rooms cr ON cr.id = cm.chatid AND cr.chattype = 'User2User'
                 WHERE e.classified_at >= ? AND e.classified_at < ?
                   AND e.id = (SELECT MAX(x.id) FROM messages_eee x WHERE x.msgid = e.msgid)
                   AND (e.item_condition = 'damaged' OR ((e.size_bucket = 'large' OR e.weight_bucket IN ('20_100kg', 'over_100kg')) AND m.deliverypossible = 0))
                   AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id IN (cm.userid, m.fromuser) AND (u.tnuserid IS NOT NULL
                       OR EXISTS (SELECT 1 FROM users_emails tne WHERE tne.userid = u.id AND tne.preferred = 1 AND tne.email LIKE '%@user.trashnothing.com')))
                   AND NOT EXISTS (SELECT 1 FROM rippling_held_replies rhr WHERE rhr.chatmsgid = cm.id)",
                [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), $from->copy()->subDays(21)->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            Log::warning('ConfirmReplySource: could not size the run, so the power check is skipped: '.$e->getMessage());

            return null;
        }

        $replies = (int) ($rows[0]->replies ?? 0);
        $repliers = (int) ($rows[0]->repliers ?? 0);
        if ($replies === 0 || $repliers < 2) {
            return null;
        }

        return ['per_arm' => intdiv($repliers, 2), 'rate' => ((int) $rows[0]->handed_over) / $replies];
    }
}
