<?php

namespace App\Mail\Digest;

use App\Mail\MjmlMailable;
use App\Mail\Traits\AmpEmail;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\User;
use App\Services\UnifiedDigestService;
use App\Support\EmojiUtils;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Unified Freegle digest email.
 *
 * Contains posts from all communities the user is a member of,
 * with cross-posted items deduplicated.
 */
class UnifiedDigest extends MjmlMailable
{
    use AmpEmail;
    use LoggableEmail;
    use TrackableEmail;

    public string $userSite;

    public string $deliveryUrl;

    protected Collection $preparedPosts;

    protected int $digestNumber;

    public function __construct(
        protected User $user,
        protected Collection $posts,
        protected string $mode,
        protected Collection $sponsors = new Collection()
    ) {
        parent::__construct();

        $this->userSite = config('freegle.sites.user');
        $this->deliveryUrl = config('freegle.delivery.base_url');

        // Look up digest number for this user.
        $this->digestNumber = $this->getDigestNumber();

        // Initialize email tracking BEFORE preparePosts so trackedUrl() works.
        $userId = $this->user->exists ? $this->user->id : null;

        $this->initTracking(
            'UnifiedDigest',
            $this->user->email_preferred,
            $userId,
            null,
            $this->getSubject(),
            [
                'mode' => $this->mode,
                'post_count' => $this->posts->count(),
                'digest_number' => $this->digestNumber,
                'has_amp' => $this->isAmpEnabled(),
            ]
        );

        // Prepare posts with tracking URLs and decoded text.
        $this->preparedPosts = $this->preparePosts();
    }

    /**
     * Get the recipient's user ID for common header tracking.
     */
    protected function getRecipientUserId(): ?int
    {
        return $this->user->id ?? null;
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        $result = $this->mjmlView('emails.mjml.digest.unified', array_merge([
            'user' => $this->user,
            'posts' => $this->preparedPosts,
            'postCount' => $this->posts->count(),
            'sponsors' => $this->sponsors,
            'mode' => $this->mode,
            'settingsUrl' => $this->trackedUrl($this->userSite . '/settings', 'footer_settings', 'settings'),
            'unsubscribeUrl' => $this->trackedUrl($this->userSite . '/unsubscribe', 'footer_unsubscribe', 'unsubscribe'),
            'browseUrl' => $this->trackedUrl($this->userSite . '/browse', 'browse_cta', 'browse'),
            'userSite' => $this->userSite,
        ], $this->getTrackingData()), 'emails.text.digest.unified')
            ->to($this->user->email_preferred)
            ->applyLogging('UnifiedDigest');

        // Render AMP variant if enabled.
        if ($this->isAmpEnabled()) {
            $ampPosts = $this->prepareAmpPosts();

            $this->renderAmpTemplate('emails.amp.digest.unified', [
                'user' => $this->user,
                'posts' => $ampPosts,
                'postCount' => $this->posts->count(),
                'settingsUrl' => $this->trackedUrl($this->userSite . '/settings', 'amp_settings', 'settings'),
                'unsubscribeUrl' => $this->trackedUrl($this->userSite . '/unsubscribe', 'amp_unsubscribe', 'unsubscribe'),
                'browseUrl' => $this->trackedUrl($this->userSite . '/browse', 'amp_browse', 'browse'),
                'userSite' => $this->userSite,
            ]);

            $result->withSymfonyMessage(function ($message) {
                $this->applyAmpToMessage($message);
            });
        }

        return $result;
    }

    /**
     * Get the subject line.
     *
     * Format: "5 new posts near you - Sofa, Coffee table, Books..."
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                config('freegle.branding.name')
            ),
            subject: $this->getSubject(),
        );
    }

    protected function getSubject(): string
    {
        $count = $this->posts->count();
        $itemNames = $this->getItemNamesForSubject();

        $subject = "{$count} new post" . ($count === 1 ? '' : 's') . " near you";

        if ($itemNames) {
            $subject .= " - {$itemNames}";
        }

        return $subject;
    }

    /**
     * Get item names for the subject line teaser.
     *
     * @return string Comma-separated item names, max 50 chars
     */
    protected function getItemNamesForSubject(): string
    {
        $names = [];
        $totalLength = 0;
        $maxLength = 50;

        foreach ($this->posts as $post) {
            $message = $post['message'];
            $itemName = $this->extractItemName($message->subject);

            // Truncate individual item names to 25 chars.
            if (strlen($itemName) > 25) {
                $itemName = substr($itemName, 0, 22) . '...';
            }

            $newLength = $totalLength + strlen($itemName) + ($totalLength > 0 ? 2 : 0);

            if ($newLength > $maxLength) {
                break;
            }

            $names[] = $itemName;
            $totalLength = $newLength;
        }

        return implode(', ', $names);
    }

    /**
     * Extract item name from subject line.
     * Removes OFFER/WANTED prefix and location suffix.
     */
    protected function extractItemName(string $subject): string
    {
        // Remove OFFER/WANTED prefix.
        $name = preg_replace('/^(OFFER|WANTED)\s*:\s*/i', '', $subject);

        // Remove location suffix (stuff in parentheses at the end).
        $name = preg_replace('/\s*\([^)]+\)\s*$/', '', $name);

        return trim($name);
    }

    /**
     * Prepare posts with tracking URLs and processed data.
     */
    protected function preparePosts(): Collection
    {
        $totalPosts = $this->posts->count();

        return $this->posts->map(function ($post, $index) use ($totalPosts) {
            $message = $post['message'];
            $postedToGroups = $post['postedToGroups'];

            // Get group names for "Posted to:" display.
            $postedToText = count($postedToGroups) > 1
                ? $this->formatPostedTo($postedToGroups)
                : null;

            // Get image URL via delivery service.
            $imageUrl = $this->getMessageImageUrl($message);

            // Create tracked image URL with scroll depth.
            $scrollPercent = $totalPosts > 0 ? round(($index / $totalPosts) * 100) : 0;
            $trackedImage = $imageUrl
                ? $this->trackedImageUrl($imageUrl, "image_{$index}", $scrollPercent)
                : null;

            // Decode emoji sequences in message text.
            $messageText = $message->textbody
                ? EmojiUtils::decodeEmojis($message->textbody)
                : null;

            // Create tracked URL for this message (with position tracking).
            $messageUrl = $this->trackedUrl(
                $this->userSite . '/message/' . $message->id,
                "post_{$index}",
                'view_message'
            );

            return [
                'message' => $message,
                'messageText' => $messageText,
                'messageUrl' => $messageUrl,
                'imageUrl' => $imageUrl,
                'trackedImageUrl' => $trackedImage,
                'postedToText' => $postedToText,
                'type' => $message->type,
                'subject' => $message->subject,
                'itemName' => $this->extractItemName($message->subject),
            ];
        });
    }

    /**
     * Prepare posts with AMP reply URLs and tokens.
     */
    protected function prepareAmpPosts(): Collection
    {
        $ampApiBase = config('freegle.amp.api_base', $this->userSite);
        $userId = $this->user->id;

        return $this->preparedPosts->map(function ($post) use ($ampApiBase, $userId) {
            $messageId = $post['message']->id;
            $token = $this->generateToken($userId, $messageId);

            $post['ampReplyUrl'] = "{$ampApiBase}/amp/digest/reply?" . http_build_query([
                'rt' => $token,
                'uid' => $userId,
                'mid' => $messageId,
            ]);

            // Fallback URL for non-AMP clients or AMP form errors.
            $post['fallbackReplyUrl'] = $post['messageUrl'];

            return $post;
        });
    }

    /**
     * Get the digest number for this user (count of previously sent digests).
     */
    protected function getDigestNumber(): int
    {
        return (int) DB::table('email_tracking')
            ->where('userid', $this->user->id)
            ->where('email_type', 'UnifiedDigest')
            ->count();
    }

    /**
     * Format the "Posted to" text for display.
     */
    protected function formatPostedTo(array $groupIds): string
    {
        $groupNames = DB::table('groups')
            ->whereIn('id', $groupIds)
            ->pluck('nameshort');

        return 'Posted to: ' . $groupNames->implode(', ');
    }

    /**
     * Get the message image URL via delivery service.
     */
    protected function getMessageImageUrl($message): ?string
    {
        if (!$message->attachments || $message->attachments->isEmpty()) {
            return null;
        }

        $attachment = $message->attachments->first();

        // If there's an external URL, use it directly.
        if (!empty($attachment->externalurl)) {
            return $this->getDeliveryUrl($attachment->externalurl, 300);
        }

        // Build URL from image domain - message images use timg_ prefix for thumbnails.
        $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
        $sourceUrl = "{$imagesDomain}/timg_{$attachment->id}.jpg";

        return $this->getDeliveryUrl($sourceUrl, 300);
    }

    /**
     * Get a URL via the delivery service for image resizing/optimization.
     */
    protected function getDeliveryUrl(string $sourceUrl, int $width): string
    {
        if (!$this->deliveryUrl) {
            return $sourceUrl;
        }

        return $this->deliveryUrl . '/?url=' . urlencode($sourceUrl) . '&w=' . $width;
    }
}
