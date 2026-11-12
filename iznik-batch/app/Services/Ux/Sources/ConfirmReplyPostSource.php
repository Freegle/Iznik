<?php

namespace App\Services\Ux\Sources;

use App\Services\Ux\ExposureSource;
use App\Services\Ux\UxExperimentRefused;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The experiment confirm-reply-post, counted from the database by what the poster saw. The POSTER is in an arm: in
 * ask, every replier to their offers that look damaged, or too big to carry, is asked whether they still want it
 * before the reply is passed on. An exposure is the poster and the post (chat/replyconfirm.go writes it at the first
 * eligible reply, in both arms), and the poster is counted once, on their first such post, once it is a window old.
 * Strata are why the post was eligible: damaged, car or both.
 *
 * Primary, no_dead_reply: the poster saw no dead reply on that post within the window of the exposure. A dead reply
 * is one passed on to the poster (processed and delivered) whose replier was not promised the item and not recorded
 * as taking it (messages_promises, messages_by) by the end of the window. A reply dropped before the poster saw it,
 * because the replier said no or did not answer in 3 days, is not dead: the poster never had it.
 *
 * Guardrail, so that hiding replies cannot score as a win: not_taken, the post was not taken within the window,
 * margin 5 points (fewer items finding a home).
 *
 * For information only, never used to decide: nothing_passed_on, the poster was given no reply at all within the
 * window. The change raises it on purpose, since a reply the replier withdraws is never passed on and most of these
 * posts get one reply, so as a guardrail it would revert the run for doing what it is meant to do. It is shown per
 * arm beside the result so that over-hiding is visible; not_taken is what stops it from winning.
 *
 * The first run, confirm-reply, counted each replier and scored a dropped reply as a failure on both of its measures,
 * though a dropped reply cost the poster nothing. docs/developers/reference/ux-experiments.md, "Confirming a reply,
 * judged per poster".
 */
class ConfirmReplyPostSource extends ExposureSource
{
    public const NO_DEAD_REPLY = 'no_dead_reply';

    public const NOT_TAKEN_MARGIN_POINTS = 5.0;

    /** A reply to the post (alias d) that the poster was given, inside the window of the exposure. */
    private const PASSED_ON = "d.refmsgid = c.subject_id AND d.type = 'Interested' AND d.userid <> c.userid"
        .' AND d.processingrequired = 0 AND d.processingsuccessful = 1 AND d.date < c.exposed_at + INTERVAL :window HOUR';

    /** The replier of d was promised the item, or recorded as taking it, by the end of the window. */
    private const HANDED_OVER = '(EXISTS (SELECT 1 FROM messages_promises p WHERE p.msgid = c.subject_id AND p.userid = d.userid'
        .' AND p.promisedat < c.exposed_at + INTERVAL :window HOUR)'
        .' OR EXISTS (SELECT 1 FROM messages_by b WHERE b.msgid = c.subject_id AND b.userid = d.userid'
        .' AND b.timestamp < c.exposed_at + INTERVAL :window HOUR))';

    /**
     * The values of design.primary. Read by UxExperimentService::normaliseDesign, so a value that is not here is
     * refused when the experiment is created.
     *
     * @return string[]
     */
    public static function primaries(): array
    {
        return [self::NO_DEAD_REPLY];
    }

    public function types(): array
    {
        return ['post'];
    }

    private function checkPrimary(object $exp): void
    {
        $primary = $this->designOf($exp)['primary'] ?? self::NO_DEAD_REPLY;
        if (! in_array($primary, self::primaries(), true)) {
            throw new UxExperimentRefused('design.primary for confirm_reply_post must be '.implode(' or ', self::primaries()).'.');
        }
    }

    public function words(object $exp): array
    {
        $this->checkPrimary($exp);
        $window = self::windowText($this->windowOf($exp));

        return [
            'who' => 'posters',
            'started' => 'were counted',
            'finished' => 'saw no dead reply within '.$window,
            'tab_started' => 'Counted',
            'tab_finished' => 'No dead reply within '.$window,
            'note' => 'Each poster is counted once, for their first offer that looked damaged or too big to carry and got a reply, and only when that was at least '.$window.' ago. '
                .'A dead reply is one passed on to the poster whose replier was not promised the item or marked as taking it within '.$window.'. '
                .'A reply dropped before the poster saw it, because the replier said no or did not answer in 3 days, is not dead. '
                .'The share of posts not taken within '.$window.' stops hiding replies from looking like a win. '
                .'The share of posters who had no reply passed on is shown for information only: asking is meant to raise it, so it does not decide.',
        ];
    }

    public function guardrails(object $exp): array
    {
        $window = self::windowText($this->windowOf($exp));

        return [
            'not_taken' => ['label' => 'the share of posts not taken within '.$window, 'margin_points' => self::NOT_TAKEN_MARGIN_POINTS],
        ];
    }

    protected function information(object $exp): array
    {
        $window = self::windowText($this->windowOf($exp));

        return [
            'nothing_passed_on' => [
                'label' => 'the share of posters who had no reply passed on within '.$window,
                'sql' => 'NOT EXISTS (SELECT 1 FROM chat_messages d WHERE '.self::PASSED_ON.')',
            ],
        ];
    }

    protected function joins(object $exp): string
    {
        return '';
    }

    protected function converted(object $exp): string
    {
        $this->checkPrimary($exp);

        return 'NOT EXISTS (SELECT 1 FROM chat_messages d WHERE '.self::PASSED_ON.' AND NOT '.self::HANDED_OVER.')';
    }

    protected function bad(object $exp): array
    {
        return [
            'not_taken' => "NOT EXISTS (SELECT 1 FROM messages_outcomes o WHERE o.msgid = c.subject_id AND o.outcome = 'Taken'"
                .' AND o.timestamp < c.exposed_at + INTERVAL :window HOUR)',
        ];
    }

    /**
     * What a run could count, to refuse a start that could not be read: the posters with an eligible offer that got a
     * reply in one intake (halved for each arm), and the share of those posts with no dead reply. It looks at the
     * intake_hours of replies that ended one window ago, so every post has had its whole window. It repeats the gates
     * of planReplyConfirm in SQL, so it overstates a little: a child car seat that is also damaged or bulky is in it, and
     * so is a reply made before its post was classified. The join order is forced (STRAIGHT_JOIN): chat_messages is by
     * far the biggest table. Any error is logged and skips the check: a size estimate must not stop a start.
     */
    public function volume(object $exp, Carbon $now): ?array
    {
        $window = $this->windowOf($exp);
        $intake = max(1, (int) ($this->designOf($exp)['intake_hours'] ?? config('freegle.ux.channels.server.intake_hours', 168)));
        $to = $now->copy()->utc()->subHours($window);
        $from = $to->copy()->subHours($intake);
        $notTn = "NOT EXISTS (SELECT 1 FROM users u WHERE u.id = %s AND (u.tnuserid IS NOT NULL
            OR EXISTS (SELECT 1 FROM users_emails tne WHERE tne.userid = u.id AND tne.preferred = 1 AND tne.email LIKE '%%@user.trashnothing.com')))";

        try {
            // keep-raw: a size estimate over the posts the question applies to; the gates repeat planReplyConfirm's and the query builder cannot express the correlated tests or the derived table.
            $rows = DB::select(
                "SELECT COUNT(*) AS posts, COUNT(DISTINCT t.fromuser) AS posters,
                        COALESCE(SUM(NOT EXISTS (SELECT 1 FROM chat_messages d WHERE d.refmsgid = t.id AND d.type = 'Interested' AND d.userid <> t.fromuser
                            AND d.processingrequired = 0 AND d.processingsuccessful = 1 AND d.date < t.first + INTERVAL '$window' HOUR
                            AND NOT EXISTS (SELECT 1 FROM messages_promises p WHERE p.msgid = t.id AND p.userid = d.userid AND p.promisedat < t.first + INTERVAL '$window' HOUR)
                            AND NOT EXISTS (SELECT 1 FROM messages_by b WHERE b.msgid = t.id AND b.userid = d.userid AND b.timestamp < t.first + INTERVAL '$window' HOUR))), 0) AS clean
                 FROM (SELECT STRAIGHT_JOIN m.id, m.fromuser, MIN(cm.date) AS first
                       FROM messages_eee e
                       JOIN messages m ON m.id = e.msgid AND m.type = 'Offer' AND m.fromuser IS NOT NULL
                       JOIN chat_messages cm ON cm.refmsgid = m.id AND cm.type = 'Interested' AND cm.userid <> m.fromuser AND cm.date >= ? AND cm.date < ?
                       JOIN chat_rooms cr ON cr.id = cm.chatid AND cr.chattype = 'User2User'
                       WHERE e.classified_at >= ? AND e.classified_at < ?
                         AND e.id = (SELECT MAX(x.id) FROM messages_eee x WHERE x.msgid = e.msgid)
                         AND (e.item_condition = 'damaged' OR ((e.size_bucket = 'large' OR e.weight_bucket IN ('20_100kg', 'over_100kg')) AND m.deliverypossible = 0))
                         AND ".sprintf($notTn, 'cm.userid').'
                         AND '.sprintf($notTn, 'm.fromuser').'
                         AND NOT EXISTS (SELECT 1 FROM rippling_held_replies rhr WHERE rhr.chatmsgid = cm.id)
                       GROUP BY m.id, m.fromuser) t',
                [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), $from->copy()->subDays(21)->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            Log::warning('ConfirmReplyPostSource: could not size the run, so the power check is skipped: '.$e->getMessage());

            return null;
        }

        $posts = (int) ($rows[0]->posts ?? 0);
        $posters = (int) ($rows[0]->posters ?? 0);
        if ($posts === 0 || $posters < 2) {
            return null;
        }

        return ['per_arm' => intdiv($posters, 2), 'rate' => ((int) $rows[0]->clean) / $posts];
    }
}
