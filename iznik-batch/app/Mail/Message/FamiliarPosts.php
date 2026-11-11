<?php

namespace App\Mail\Message;

use App\Mail\Contracts\DescribesMemberContent;
use App\Mail\MjmlMailable;
use App\Mail\Traits\PostThumbnail;
use App\Mail\Traits\TrackableEmail;
use App\Models\Message;
use App\Models\User;
use App\Services\LoginLinkService;
use App\Services\MatchedPostsService;
use App\Services\UnsubscribeService;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * New posts from people the member has chatted with recently: the `notify` arm of the experiment
 * familiar-poster, sent by messages:familiar-posts. Each post is a card naming the poster, with a Reply
 * button that opens the post with the reply pane open.
 *
 * It is a "posts you might want" mail, so it belongs to the relevant category, like the matched-posts
 * mail: the footer and List-Unsubscribe link turn off that kind of mail only (relevantallowed = 0).
 *
 * docs/developers/reference/familiar-posts-email.md.
 */
class FamiliarPosts extends MjmlMailable implements DescribesMemberContent
{
    use PostThumbnail, TrackableEmail;

    private ?string $cachedOptOutUrl = null;

    /**
     * @param  array<int, Message>  $messages  the new posts, with attachments and fromUser loaded
     */
    public function __construct(
        public readonly User $user,
        public readonly string $recipientEmail,
        public readonly array $messages,
    ) {
        parent::__construct();

        $this->initTracking(
            'FamiliarPosts',
            $this->recipientEmail,
            (int) $this->user->id,
            null,
            $this->getSubject(),
            ['posts' => count($this->messages)]
        );
    }

    protected function getSubject(): string
    {
        if (count($this->messages) === 1) {
            $m = array_values($this->messages)[0];
            $verb = $m->type === Message::TYPE_OFFER ? 'is offering' : 'is looking for';

            return self::posterName($m).' '.$verb.': '.MatchedPostsService::itemName($m);
        }

        return 'New posts from people you have chatted with';
    }

    protected function unsubscribeType(): ?string
    {
        return UnsubscribeService::TYPE_RELEVANT;
    }

    protected function getRecipientUserId(): ?int
    {
        return (int) $this->user->id;
    }

    /** The one-click opt-out the matched-posts mail uses: turns off relevant mail only. */
    protected function optOutUrl(): string
    {
        if ($this->cachedOptOutUrl === null) {
            $key = app(LoginLinkService::class)->getOrCreateKey((int) $this->user->id);
            $v2 = rtrim(config('freegle.api.v2_url', 'https://api.ilovefreegle.org/apiv2'), '/');
            $this->cachedOptOutUrl = $v2.'/user/relevantoff?u='.$this->user->id.'&k='.$key;
        }

        return $this->cachedOptOutUrl;
    }

    protected function listUnsubscribeUrl(int $userId): string
    {
        return $this->optOutUrl();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org'),
                config('freegle.branding.name', 'Freegle')
            ),
            to: [new Address($this->recipientEmail)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        $this->configureMessage();

        $userSite = rtrim(config('freegle.sites.user', 'https://www.ilovefreegle.org'), '/');

        $posts = [];
        foreach (array_values($this->messages) as $i => $message) {
            $isOffer = $message->type === Message::TYPE_OFFER;
            $placeholder = $isOffer
                ? config('freegle.images.offer_placeholder')
                : config('freegle.images.wanted_placeholder');

            $posts[] = [
                'itemName' => MatchedPostsService::itemName($message),
                'isOffer' => $isOffer,
                'poster' => self::posterName($message),
                'imageUrl' => $this->postThumbnailUrl($message) ?? $placeholder,
                'viewUrl' => $this->trackedResourceUrl('s', (int) $message->id, "v$i", "$userSite/message/{$message->id}"),
                'replyUrl' => $this->trackedResourceUrl('m', (int) $message->id, "p$i", "$userSite/message/{$message->id}?reply=1"),
            ];
        }

        return $this->mjmlView('emails.mjml.message.familiar-posts', array_merge([
            'posts' => $posts,
            'count' => count($posts),
            'settingsUrl' => $this->trackedUrl("$userSite/settings", 'footer_settings', 'settings'),
            'optOutUrl' => $this->optOutUrl(),
            'perWeek' => max(1, (int) config('freegle.familiar_posts.max_days_per_week', 2)),
            'email' => $this->recipientEmail,
        ], $this->getTrackingData()));
    }

    /**
     * Names the posts and their posters; filter-spool removes this mail once none of the posts is still
     * Approved.
     */
    public function about(): array
    {
        return [
            'chatmessages' => [],
            'messages' => array_values(array_map(fn (Message $m) => (int) $m->id, $this->messages)),
            'newsfeed' => [],
            'users' => array_values(array_unique(array_filter(array_map(fn (Message $m) => (int) $m->fromuser, $this->messages)))),
        ];
    }

    /** The poster's name as members see it, or "Someone" when the account has gone. */
    private static function posterName(Message $m): string
    {
        $name = $m->fromUser?->display_name;

        return ($name && $name !== 'Freegle User') ? $name : 'Someone';
    }
}
