<?php

namespace App\Services\Ux\Sources;

use App\Services\Ux\ExposureSource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The experiment interested-button, counted from the database. An exposure is a signed-in web member
 * opening a post they could reply to (message/interested.go writes it, in both arms; the subject is the post),
 * and the member is counted once, on their first such view, once that view is a window old.
 *
 * Converted: the member sent a reply (a chat message of type Interested that names a post) within the window of
 * that view, to any post. The button is a step before a reply, so the result is whether it brings more replies.
 * chat_messages is read by its (userid, date, refmsgid, type) index: one range on a member's own messages.
 *
 * The guardrail is fewer_handovers: the share of counted members who were not promised an item (messages_promises)
 * or named in messages_by within the window must not rise by more than 2 points, because a reply that is
 * never answered is invisible in the reply count.
 *
 * Nothing records a view before the first run, so volume() has no history to size from and returns null.
 *
 * docs/developers/reference/ux-experiments.md, "An I'm interested button".
 */
class InterestedReplySource extends ExposureSource
{
    /** fewer_handovers: the engine's own default margin (config freegle.ux.experiments.guardrail_margin_points). */
    public const FEWER_HANDOVERS_MARGIN_POINTS = 2.0;

    /**
     * The member was promised an item or named as taking one within :window hours of the view. A withdrawn promise
     * is deleted, so this is read only once a view is a whole window old; messages_by has no row when the poster
     * marks an item taken without naming the member, in both arms alike.
     */
    private const HANDED_OVER = '(EXISTS (SELECT 1 FROM messages_promises p WHERE p.userid = c.userid'
        .' AND p.promisedat >= c.exposed_at AND p.promisedat < c.exposed_at + INTERVAL :window HOUR)'
        .' OR EXISTS (SELECT 1 FROM messages_by b WHERE b.userid = c.userid'
        .' AND b.`timestamp` >= c.exposed_at AND b.`timestamp` < c.exposed_at + INTERVAL :window HOUR))';

    public function types(): array
    {
        return ['view'];
    }

    public function words(object $exp): array
    {
        $window = self::windowText($this->windowOf($exp));

        return [
            'who' => 'members',
            'started' => 'were counted',
            'finished' => 'sent a reply within '.$window,
            'tab_started' => 'Counted',
            'tab_finished' => 'Replied within '.$window,
            'note' => 'Each member is counted once, for the first post they opened that they could reply to, and only when that was at least '.$window.' ago. '
                .'Replied means they sent a reply to any post within '.$window.' of opening it.',
        ];
    }

    public function guardrails(object $exp): array
    {
        return ['fewer_handovers' => ['label' => 'the share of members who were not promised an item or marked as taking one', 'margin_points' => self::FEWER_HANDOVERS_MARGIN_POINTS]];
    }

    protected function joins(object $exp): string
    {
        return '';
    }

    protected function converted(object $exp): string
    {
        return "EXISTS (SELECT 1 FROM chat_messages cm WHERE cm.userid = c.userid AND cm.type = 'Interested' AND cm.refmsgid IS NOT NULL"
            .' AND cm.date >= c.exposed_at AND cm.date < c.exposed_at + INTERVAL :window HOUR)';
    }

    protected function bad(object $exp): array
    {
        return ['fewer_handovers' => 'NOT '.self::HANDED_OVER];
    }

    /**
     * What the hosted detail piece draws, over every mark added since the start of the run (or the decision), of any
     * age and from any member, in either arm (only the button arm can mark, and a page opened before a stop can still
     * mark, so the arm is not asked). marks is the rows, members the distinct members. replied is a mark followed by
     * a reply from the same member to the same post at any time after it; replied_before_chase is such a reply made
     * before the chase-up named the mark (or with no chase-up), replied_after_chase after it. chased is marks the
     * chase-up has named.
     *
     * @return array{interested: array<string, int>}
     */
    protected function extra(object $exp, Carbon $now, ?Carbon $since): array
    {
        $from = ($since ?: Carbon::parse($exp->started_at, 'UTC'))->copy()->utc()->format('Y-m-d H:i:s');

        // keep-raw: one aggregate over the marks and each mark's first later reply; the query builder cannot express the derived table.
        $row = DB::selectOne(
            "SELECT COUNT(*) AS marks, COUNT(DISTINCT m.userid) AS members,
                    COALESCE(SUM(m.firstreply IS NOT NULL), 0) AS replied,
                    COALESCE(SUM(m.firstreply IS NOT NULL AND (m.chasedat IS NULL OR m.firstreply < m.chasedat)), 0) AS replied_before_chase,
                    COALESCE(SUM(m.firstreply IS NOT NULL AND m.chasedat IS NOT NULL AND m.firstreply >= m.chasedat), 0) AS replied_after_chase,
                    COALESCE(SUM(m.chasedat IS NOT NULL), 0) AS chased
             FROM (SELECT i.userid, i.chasedat,
                          (SELECT MIN(cm.date) FROM chat_messages cm
                           WHERE cm.userid = i.userid AND cm.type = 'Interested' AND cm.refmsgid = i.msgid AND cm.date >= i.added) AS firstreply
                   FROM messages_interested i
                   WHERE i.added >= ?) m",
            [$from]
        );

        return ['interested' => [
            'marks' => (int) ($row->marks ?? 0),
            'members' => (int) ($row->members ?? 0),
            'replied' => (int) ($row->replied ?? 0),
            'replied_before_chase' => (int) ($row->replied_before_chase ?? 0),
            'replied_after_chase' => (int) ($row->replied_after_chase ?? 0),
            'chased' => (int) ($row->chased ?? 0),
        ]];
    }

    public function volume(object $exp, Carbon $now): ?array
    {
        return null;
    }
}
