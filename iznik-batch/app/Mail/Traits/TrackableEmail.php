<?php

namespace App\Mail\Traits;

use App\Models\EmailTracking;

/**
 * Trait to add email tracking capabilities to MJML mailables.
 *
 * Usage:
 * 1. Add `use TrackableEmail;` to your mailable class
 * 2. Call `$this->initTracking(...)` in the mailable constructor
 * 3. Pass `$tracking` to your MJML view data
 * 4. In your MJML template, use tracking helper methods for links
 *
 * Key MJML elements that benefit from tracking:
 * - mj-button: CTA buttons should use tracked links
 * - mj-image: Can be proxied for scroll depth estimation
 * - Links in mj-text: Should use tracked links
 * - Tracking pixel: Added automatically via getTrackingPixelHtml()
 */
trait TrackableEmail
{
    protected ?EmailTracking $tracking = null;

    /**
     * Initialize email tracking for this mailable.
     *
     * @param string $emailType Type of email (e.g., 'Digest', 'Chat', 'Welcome')
     * @param string $recipientEmail Recipient's email address
     * @param int|null $userId User ID if known
     * @param int|null $groupId Group ID if applicable
     * @param string|null $subject Email subject line
     * @param array|null $metadata Additional context (message_id, etc.)
     * @param bool $hasAmp Whether this email includes AMP content
     */
    protected function initTracking(
        string $emailType,
        string $recipientEmail,
        ?int $userId = null,
        ?int $groupId = null,
        ?string $subject = null,
        ?array $metadata = null,
        bool $hasAmp = false
    ): void {
        $this->tracking = EmailTracking::createForEmail(
            $emailType,
            $recipientEmail,
            $userId,
            $groupId,
            $subject,
            $metadata,
            $hasAmp
        );
    }

    /**
     * Get the tracking record.
     */
    public function getTracking(): ?EmailTracking
    {
        return $this->tracking;
    }

    /**
     * Get HTML for a 1x1 tracking pixel.
     * Add this at the end of your email body.
     */
    public function getTrackingPixelHtml(): string
    {
        if (!$this->tracking) {
            return '';
        }

        $pixelUrl = $this->tracking->getPixelUrl();
        return '<img src="' . htmlspecialchars($pixelUrl) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;" />';
    }

    /**
     * Get MJML for a tracking pixel image.
     * Add this inside an mj-section at the end of your email.
     */
    public function getTrackingPixelMjml(): string
    {
        if (!$this->tracking) {
            return '';
        }

        $pixelUrl = $this->tracking->getPixelUrl();
        return '<mj-image src="' . htmlspecialchars($pixelUrl) . '" width="1px" height="1px" alt="" padding="0" />';
    }

    /**
     * Get a tracked URL for a link or button.
     *
     * @param string $destinationUrl The actual destination URL
     * @param string|null $position Link position identifier (e.g., 'cta_button', 'item_1')
     * @param string|null $action Action type (e.g., 'cta', 'unsubscribe', 'view_item')
     */
    public function trackedUrl(string $destinationUrl, ?string $position = null, ?string $action = null): string
    {
        if (!$this->tracking) {
            return $destinationUrl;
        }

        return $this->tracking->getTrackedLinkUrl($destinationUrl, $position, $action);
    }

    /**
     * Get a tracked image URL for scroll depth estimation.
     *
     * @param string $originalImageUrl The original image URL
     * @param string $position Image position (e.g., 'header', 'item_1', 'footer')
     * @param int|null $scrollPercent Estimated scroll percentage when this image is visible
     */
    public function trackedImageUrl(string $originalImageUrl, string $position, ?int $scrollPercent = null): string
    {
        if (!$this->tracking) {
            return $originalImageUrl;
        }

        return $this->tracking->getTrackedImageUrl($originalImageUrl, $position, $scrollPercent);
    }

    /**
     * Compact tracked link for one of our own resources (message, group, …).
     * Emits /e/d/r/{ref}/{type}/{idEnc}/{pos} instead of base64'ing the full
     * destination — far shorter for emails with many internal links. Falls
     * back to the plain destination URL when tracking is disabled.
     *
     * @param string $type     Resource type code (e.g. 'm', 's', 'g')
     * @param int    $id       Resource id (message/group id)
     * @param string $position Short position code for click analytics
     * @param string $fallbackUrl Direct URL used when tracking is off
     */
    public function trackedResourceUrl(string $type, int $id, string $position, string $fallbackUrl): string
    {
        if (!$this->tracking) {
            return $fallbackUrl;
        }

        return $this->tracking->getCompactLinkUrl(
            $type,
            \App\Models\EmailTracking::encodeId($id),
            $position
        );
    }

    /**
     * Compact tracked image for one of our own resources (message photo,
     * avatar). Emits /e/d/i/{ref}/{type}/{idEnc}/{preset}/{pos}[?s={scrollPercent}].
     * Falls back to the direct image URL when tracking is disabled.
     *
     * Pass $scrollPercent so the Go handler can update scroll_depth_percent via
     * the same max-update logic as the long-form Image handler.
     */
    public function trackedResourceImageUrl(string $type, int $id, int $preset, string $position, string $fallbackUrl, ?int $scrollPercent = null): string
    {
        if (!$this->tracking) {
            return $fallbackUrl;
        }

        return $this->tracking->getCompactImageUrl(
            $type,
            \App\Models\EmailTracking::encodeId($id),
            $preset,
            $position,
            $scrollPercent
        );
    }

    /**
     * Get tracking data to pass to MJML views.
     * Include this in your mjmlView() data array.
     */
    protected function getTrackingData(): array
    {
        return [
            'tracking' => $this->tracking,
            'trackingPixelMjml' => $this->getTrackingPixelMjml(),
            'trackingPixelHtml' => $this->getTrackingPixelHtml(),
        ];
    }
}
