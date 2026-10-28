<?php

namespace App\Services\Ux\Sources;

use App\Services\Ux\ExposureSource;
use Carbon\Carbon;

/**
 * The experiment familiar-poster, counted from the database. An exposure is a new post from someone the
 * member has chatted with (messages:familiar-posts writes it in both arms; the subject is the post). The
 * notify arm is emailed about it, control is not. Each member is counted once, on the first such post, once
 * it is a window old.
 *
 * Converted: the member replied to that post (a chat message of type Interested naming it) within the
 * window. The question is whether knowing the poster, and being told, brings a reply.
 *
 * The guardrail is unsubscribe: the share of counted members who clicked an unsubscribe link in any of our
 * mail within the window must not rise by more than 0.3 points. It reads every mail, not just this one,
 * because control is sent none, and a member annoyed by one more email may turn off another.
 *
 * Nothing records an exposure before the first run, so volume() has no history and returns null.
 *
 * docs/developers/reference/familiar-posts-email.md.
 */
class FamiliarReplySource extends ExposureSource
{
    public const UNSUBSCRIBE_MARGIN_POINTS = 0.3;

    public function types(): array
    {
        return ['post'];
    }

    public function words(object $exp): array
    {
        $window = self::windowText($this->windowOf($exp));

        return [
            'who' => 'members',
            'started' => 'were counted',
            'finished' => 'replied to the post within '.$window,
            'tab_started' => 'Counted',
            'tab_finished' => 'Replied within '.$window,
            'note' => 'Each member is counted once, for the first new post from someone they had chatted with, and only when that was at least '.$window.' ago. '
                .'Only the notify arm is emailed. Replied means they replied to that post within '.$window.'.',
        ];
    }

    public function guardrails(object $exp): array
    {
        return ['unsubscribe' => ['label' => 'the share of members who unsubscribed from any of our mail', 'margin_points' => self::UNSUBSCRIBE_MARGIN_POINTS]];
    }

    protected function joins(object $exp): string
    {
        return '';
    }

    protected function converted(object $exp): string
    {
        return "EXISTS (SELECT 1 FROM chat_messages cm WHERE cm.userid = c.userid AND cm.type = 'Interested' AND cm.refmsgid = c.subject_id"
            .' AND cm.date >= c.exposed_at AND cm.date < c.exposed_at + INTERVAL :window HOUR)';
    }

    protected function bad(object $exp): array
    {
        return ['unsubscribe' => 'EXISTS (SELECT 1 FROM email_tracking t WHERE t.userid = c.userid'
            .' AND t.unsubscribed_at >= c.exposed_at AND t.unsubscribed_at < c.exposed_at + INTERVAL :window HOUR)'];
    }

    public function volume(object $exp, Carbon $now): ?array
    {
        return null;
    }
}
