<?php

namespace App\Mail\Matched;

use App\Mail\Digest\DigestStyle;
use App\Mail\MjmlMailable;
use App\Mail\Traits\AvatarResolver;
use App\Mail\Traits\TrackableEmail;
use App\Models\Message;
use App\Models\User;
use App\Services\LoginLinkService;
use App\Services\MatchedPostsService;
use App\Support\SubjectParser;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The matched-posts email: the opposite-type posts near a member that match
 * their own open Offer/Wanted. Resurrects the V1 "Any of these take your fancy?"
 * mail, rebuilt on vector matching. The layout adapts to how many we matched —
 * a single hero card for one match, a compact list for several.
 *
 * Each item is ['message' => Message (the matched post to show), 'reason' =>
 * Message (the recipient's own post it matched), 'score' => float].
 */
class MatchedPosts extends MjmlMailable
{
    use AvatarResolver, TrackableEmail;

    /**
     * @param  array<int, array{message: Message, reason: Message, score: float}>  $items
     */
    public function __construct(
        public readonly User $user,
        public readonly string $recipientEmail,
        public readonly array $items,
        public readonly ?string $settingsUrl = null,
    ) {
        parent::__construct();
    }

    private ?string $cachedOptOutUrl = null;

    protected function getRecipientUserId(): ?int
    {
        return $this->user->id;
    }

    /**
     * A one-click, key-authenticated opt-out that turns off ONLY these
     * matched-posts emails (relevantallowed=0) — not a full account
     * unsubscribe. Used for both the List-Unsubscribe header and the visible
     * footer link. Computed once so the key is minted a single time.
     */
    protected function optOutUrl(): string
    {
        if ($this->cachedOptOutUrl === null) {
            $key = app(LoginLinkService::class)->getOrCreateKey((int) $this->user->id);
            $v2 = rtrim(config('freegle.api.v2_url', 'https://api.ilovefreegle.org/apiv2'), '/');
            $this->cachedOptOutUrl = $v2 . '/user/relevantoff?u=' . $this->user->id . '&k=' . $key;
        }

        return $this->cachedOptOutUrl;
    }

    protected function listUnsubscribeUrl(int $userId): string
    {
        return $this->optOutUrl();
    }

    protected function getSubject(): string
    {
        $count = count($this->items);
        $names = array_values(array_unique(array_map(
            fn ($i) => MatchedPostsService::itemName($i['message']),
            $this->items
        )));

        if ($count === 1) {
            $m = $this->items[0]['message'];
            $verb = $m->type === 'Offer' ? 'Someone is offering' : 'Someone wants';

            return $verb . ': ' . $names[0];
        }

        // Nod to the V1 "Any of these take your fancy?" subject, with a count and
        // a couple of item names so it isn't generic.
        $lead = implode(', ', array_slice($names, 0, 2));
        $more = count($names) > 2 ? ', and more' : '';

        return $count . ' posts you might fancy: ' . $lead . $more;
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
        // Attach the X-Freegle-* tracking headers and the RFC 8058
        // List-Unsubscribe headers (which use our targeted relevantoff opt-out
        // via listUnsubscribeUrl()).
        $this->configureMessage();

        $userSite = config('freegle.sites.user', 'https://www.ilovefreegle.org');

        [$userLat, $userLng] = $this->recipientLatLng();

        $posts = [];
        foreach (array_values($this->items) as $i => $item) {
            $posts[] = $this->prepareCard($item['message'], $item['reason'], $i, $userLat, $userLng, $userSite);
        }

        return $this->mjmlView('emails.mjml.matched.digest', [
            'posts' => $posts,
            'count' => count($posts),
            'offerColor' => DigestStyle::OFFER_GREEN,
            'wantedColor' => DigestStyle::WANTED_BLUE,
            'browseUrl' => $userSite . '/browse?src=matched',
            'settingsUrl' => $this->settingsUrl ?: $userSite . '/settings?src=matched',
            'optOutUrl' => $this->optOutUrl(),
            'userSite' => $userSite,
            'email' => $this->recipientEmail,
        ]);
    }

    /**
     * @return array{0: ?float, 1: ?float}
     */
    private function recipientLatLng(): array
    {
        $loc = $this->user->lastLocation ?? null;
        if ($loc && $loc->lat !== null && $loc->lng !== null) {
            return [(float) $loc->lat, (float) $loc->lng];
        }

        return [null, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareCard(Message $message, Message $reason, int $index, ?float $userLat, ?float $userLng, string $userSite): array
    {
        $isOffer = $message->type === 'Offer';

        $imageUrl = $this->messageImageUrl($message, 240, 240);
        $heroUrl = $this->messageImageUrl($message, 600, 400);
        $placeholder = $isOffer
            ? config('freegle.images.offer_placeholder')
            : config('freegle.images.wanted_placeholder');

        $subject = html_entity_decode($message->subject ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        [, , $locationName] = SubjectParser::parse($subject);

        $distanceText = null;
        if ($userLat !== null && $userLng !== null && $message->lat && $message->lng) {
            $miles = $this->haversineMiles($userLat, $userLng, (float) $message->lat, (float) $message->lng);
            $distanceText = $miles < 1 ? '< 1 mile' : round($miles) . ' miles';
        }

        $posterUser = $message->relationLoaded('fromUser') ? $message->fromUser : null;
        $groupName = null;
        $groupUrl = null;
        if ($message->relationLoaded('groups') && $message->groups->isNotEmpty()) {
            $g = $message->groups->first();
            $groupName = $g->namefull ?: $g->nameshort;
            $groupUrl = $this->trackedResourceUrl('g', (int) $g->id, "g{$index}", $userSite . '/explore/' . $g->id);
        }

        return [
            'message' => $message,
            'type' => $message->type,
            'itemName' => MatchedPostsService::itemName($message),
            'locationName' => $locationName,
            'thumbImageUrl' => $imageUrl ?? $placeholder,
            'heroImageUrl' => $heroUrl ?? $placeholder,
            'viewUrl' => $this->trackedResourceUrl('s', (int) $message->id, "v{$index}", $userSite . '/message/' . $message->id),
            'messageUrl' => $this->trackedResourceUrl('m', (int) $message->id, "p{$index}", $userSite . '/message/' . $message->id . '?reply=1'),
            'distanceText' => $distanceText,
            'posterName' => $posterUser?->displayname ?? $message->fromname ?? 'A Freegler',
            'posterAvatarUrl' => $this->resolveAvatarUrl($posterUser, 36),
            'groupName' => $groupName,
            'groupUrl' => $groupUrl,
            // Why this is in the email: which of the recipient's own posts it matched.
            'reasonType' => $reason->type,
            'reasonItem' => MatchedPostsService::itemName($reason),
        ];
    }

    /** Delivery-service cover-crop URL for the post's primary photo, or null. */
    private function messageImageUrl(Message $message, int $width, int $height): ?string
    {
        if (! $message->relationLoaded('attachments') || $message->attachments->isEmpty()) {
            return null;
        }
        $attachment = $message->attachments
            ->filter(fn ($a) => ! empty($a->externaluid) || ! empty($a->externalurl) || (int) ($a->archived ?? 0) === 1)
            ->sortByDesc('primary')
            ->first();
        if (! $attachment) {
            return null;
        }

        $source = ! empty($attachment->externalurl)
            ? $attachment->externalurl
            : rtrim(config('freegle.images.domain', 'https://images.ilovefreegle.org'), '/') . '/timg_' . $attachment->id . '.jpg';

        $base = rtrim(config('freegle.delivery.base_url', 'https://delivery.ilovefreegle.org'), '/');

        return $base . '/?url=' . urlencode($source) . '&w=' . $width . '&h=' . $height . '&fit=cover';
    }

    private function haversineMiles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 3958.8; // miles
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * asin(min(1.0, sqrt($a)));
    }
}
