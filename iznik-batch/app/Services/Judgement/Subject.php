<?php

namespace App\Services\Judgement;

/**
 * Something to be judged: a post, a chat message, an event, a volunteering opportunity, a
 * newsfeed post, or a report against one of these. Carries only what the questions need — no
 * member identifiers, emails or locations (ai-judgement.md).
 */
class Subject
{
    public const KIND_POST         = 'post';
    public const KIND_CHAT         = 'chat';
    public const KIND_EVENT        = 'event';
    public const KIND_VOLUNTEERING = 'volunteering';
    public const KIND_NEWSFEED     = 'newsfeed';
    public const KIND_REPORT       = 'report';

    /**
     * @param string $kind One of the KIND_* constants.
     * @param string|null $title
     * @param string|null $body
     * @param string|null $itemName The item name, for posts.
     * @param string|null $postType 'Offer' or 'Wanted', for posts.
     * @param array $photos Up to three, each ['base64' => string, 'mime_type' => string].
     * @param string|null $reportReason The reporter's own words, for a report subject only.
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $title = null,
        public readonly ?string $body = null,
        public readonly ?string $itemName = null,
        public readonly ?string $postType = null,
        public readonly array $photos = [],
        public readonly ?string $reportReason = null,
    ) {
    }

    /**
     * The question ids this kind of subject is judged against. Matches the table in
     * ai-judgement.md: posts get everything; chat gets free/scam/decent; event, volunteering
     * and newsfeed get scam/decent; a report always adds 'report' on top of the post questions
     * it concerns (callers pass KIND_REPORT with the post's own text and the reporter's reason).
     */
    public function questionIds(): array
    {
        return match ($this->kind) {
            self::KIND_CHAT => ['free', 'scam', 'decent'],
            self::KIND_EVENT, self::KIND_VOLUNTEERING, self::KIND_NEWSFEED => ['scam', 'decent'],
            self::KIND_REPORT => [
                'free', 'legal', 'medicine', 'age_restricted', 'animal', 'unsafe_by_design', 'cash_value',
                'not_an_item', 'scam', 'decent', 'unsafe', 'vague', 'report',
            ],
            default => ['free', 'legal', 'medicine', 'age_restricted', 'animal', 'unsafe_by_design',
                'cash_value', 'not_an_item', 'scam', 'decent', 'unsafe', 'vague'],
        };
    }
}
