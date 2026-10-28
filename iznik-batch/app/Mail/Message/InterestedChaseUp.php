<?php

namespace App\Mail\Message;

use App\Mail\Contracts\DescribesMemberContent;
use App\Mail\MjmlMailable;
use App\Mail\Traits\PostThumbnail;
use App\Mail\Traits\TrackableEmail;
use App\Models\Message;
use App\Models\User;
use App\Services\MatchedPostsService;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The reminder for posts a member marked "I'm interested" in and has not replied to: one email per member,
 * naming every such post that is still open, each with a Reply button that opens the post with the reply pane
 * open. Sent once per mark by messages:interested-chaseup, which sets chasedat on the marks it names.
 *
 * The member asked for this reminder when they pressed the button, so it is transactional and carries no
 * List-Unsubscribe; a member who has turned our mail off is not sent it.
 */
class InterestedChaseUp extends MjmlMailable implements DescribesMemberContent
{
    use PostThumbnail, TrackableEmail;

    /**
     * @param  array<int, Message>  $messages  the open posts, with attachments loaded
     */
    public function __construct(
        public readonly User $user,
        public readonly string $recipientEmail,
        public readonly array $messages,
    ) {
        parent::__construct();

        $this->initTracking(
            'InterestedChaseUp',
            $this->recipientEmail,
            (int) $this->user->id,
            null,
            $this->getSubject(),
            ['posts' => count($this->messages)]
        );
    }

    protected function getSubject(): string
    {
        $count = count($this->messages);

        if ($count === 1) {
            return 'Still interested? '.MatchedPostsService::itemName(array_values($this->messages)[0]).' is still available';
        }

        return "Still interested? $count posts are still available";
    }

    protected function unsubscribeType(): ?string
    {
        return null;
    }

    protected function getRecipientUserId(): ?int
    {
        return (int) $this->user->id;
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
                'type' => $message->type,
                'imageUrl' => $this->postThumbnailUrl($message) ?? $placeholder,
                'viewUrl' => $this->trackedResourceUrl('s', (int) $message->id, "v$i", "$userSite/message/{$message->id}"),
                'replyUrl' => $this->trackedResourceUrl('m', (int) $message->id, "p$i", "$userSite/message/{$message->id}?reply=1"),
            ];
        }

        return $this->mjmlView('emails.mjml.message.interested-chaseup', array_merge([
            'posts' => $posts,
            'count' => count($posts),
            'myPostsUrl' => $this->trackedUrl("$userSite/myposts", 'myposts_link', 'myposts'),
            'settingsUrl' => $this->trackedUrl("$userSite/settings", 'footer_settings', 'settings'),
            'email' => $this->recipientEmail,
        ], $this->getTrackingData()));
    }

    /**
     * Names the posts it reminds about and no author; filter-spool removes this mail once none of them is
     * still Approved.
     */
    public function about(): array
    {
        return [
            'chatmessages' => [],
            'messages' => array_values(array_map(fn (Message $m) => (int) $m->id, $this->messages)),
            'newsfeed' => [],
            'users' => [],
        ];
    }
}
