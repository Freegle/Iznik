<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EmailTracking extends Model
{
    protected $table = 'email_tracking';

    protected static function boot()
    {
        parent::boot();

        // Auto-generate tracking_id if not provided.
        static::creating(function ($model) {
            if (empty($model->tracking_id)) {
                $model->tracking_id = self::generateTrackingId();
            }
        });
    }

    protected $fillable = [
        'tracking_id',
        'email_type',
        'userid',
        'groupid',
        'recipient_email',
        'subject',
        'metadata',
        'sent_at',
        'delivered_at',
        'bounced_at',
        'opened_at',
        'clicked_at',
        'unsubscribed_at',
        'has_amp',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'bounced_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Generate a unique tracking ID
     */
    public static function generateTrackingId(): string
    {
        return Str::random(32);
    }

    /**
     * Create a new tracking record for an email
     */
    public static function createForEmail(
        string $emailType,
        string $recipientEmail,
        ?int $userId = null,
        ?int $groupId = null,
        ?string $subject = null,
        ?array $metadata = null,
        bool $hasAmp = false
    ): self {
        // Validate that user exists to avoid foreign key constraint failures.
        // If user doesn't exist, set userId to null.
        if ($userId !== null && !User::where('id', $userId)->exists()) {
            $userId = null;
        }

        // Similarly validate group exists.
        if ($groupId !== null && !Group::where('id', $groupId)->exists()) {
            $groupId = null;
        }

        return self::create([
            'tracking_id' => self::generateTrackingId(),
            'email_type' => $emailType,
            'userid' => $userId,
            'groupid' => $groupId,
            'recipient_email' => $recipientEmail,
            'subject' => $subject,
            'metadata' => $metadata,
            'sent_at' => now(),
            'has_amp' => $hasAmp,
        ]);
    }

    /**
     * Get the user this email was sent to
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userid');
    }

    /**
     * Get the group associated with this email
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupid');
    }

    /**
     * Get click events for this email
     */
    public function clicks(): HasMany
    {
        return $this->hasMany(EmailTrackingClick::class, 'email_tracking_id');
    }

    /**
     * Get image load events for this email
     */
    public function images(): HasMany
    {
        return $this->hasMany(EmailTrackingImage::class, 'email_tracking_id');
    }

    /**
     * Check if email was opened
     */
    public function wasOpened(): bool
    {
        return $this->opened_at !== null;
    }

    /**
     * Check if any link was clicked
     */
    public function wasClicked(): bool
    {
        return $this->clicked_at !== null;
    }

    /**
     * Check if user unsubscribed via this email
     */
    public function didUnsubscribe(): bool
    {
        return $this->unsubscribed_at !== null;
    }

    /**
     * Get the tracking pixel URL
     */
    public function getPixelUrl(): string
    {
        // Email tracking routes are at /e/d/... (not /apiv2/e/d/...)
        $baseUrl = config('freegle.api.base_url', 'https://api.ilovefreegle.org');
        return "{$baseUrl}/e/d/p/{$this->tracking_id}";
    }

    /**
     * Get a tracked link URL
     */
    public function getTrackedLinkUrl(string $destinationUrl, ?string $position = null, ?string $action = null): string
    {
        // Email tracking routes are at /e/d/... (not /apiv2/e/d/...)
        $baseUrl = config('freegle.api.base_url', 'https://api.ilovefreegle.org');
        // rawurlencode: base64 output can contain '+' and '/', which survive a
        // query string only when percent-encoded ('+' would decode as a space
        // and break the redirect).
        $encodedUrl = rawurlencode(base64_encode($destinationUrl));
        $url = "{$baseUrl}/e/d/r/{$this->tracking_id}?url={$encodedUrl}";

        // Sign the destination so the redirector can honour links beyond its
        // own-domain allowlist: Community News items link to arbitrary
        // external sites (parkrun, council pages...), and without a signature
        // the Go handler must bounce those to "/" to avoid being an open
        // redirect. The signature proves we minted the link at send time.
        // Reuses the AMP secret, which batch and apiv2 already share, with a
        // purpose prefix so signatures aren't interchangeable between uses.
        $secret = (string) config('freegle.amp.secret', '');
        if ($secret !== '') {
            $url .= '&sig=' . hash_hmac('sha256', 'redirect:' . $destinationUrl, $secret);
        }

        if ($position) {
            $url .= "&p=" . urlencode($position);
        }
        if ($action) {
            $url .= "&a=" . urlencode($action);
        }

        return $url;
    }

    /**
     * Get a tracked image URL
     */
    public function getTrackedImageUrl(string $originalImageUrl, string $position, ?int $scrollPercent = null): string
    {
        // Email tracking routes are at /e/d/... (not /apiv2/e/d/...)
        $baseUrl = config('freegle.api.base_url', 'https://api.ilovefreegle.org');
        $encodedUrl = base64_encode($originalImageUrl);
        $url = "{$baseUrl}/e/d/i/{$this->tracking_id}?url={$encodedUrl}&p=" . urlencode($position);

        if ($scrollPercent !== null) {
            $url .= "&s={$scrollPercent}";
        }

        return $url;
    }

    /**
     * Compact, URL-safe encoding of a positive integer id: base64url
     * (RFC 4648 §5, chars A-Z a-z 0-9 - _, no padding) of the minimal
     * big-endian byte representation. A ~9-digit id (<2^30) becomes ~5
     * chars instead of 9-10. Used to shrink the per-link payload in
     * emails with many tracked URLs (e.g. the multi-post digest).
     */
    public static function encodeId(int $id): string
    {
        if ($id <= 0) {
            return '0';
        }
        $bytes = '';
        while ($id > 0) {
            $bytes = chr($id & 0xFF) . $bytes;
            $id >>= 8;
        }

        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Short per-email reference for compact tracking links. The full
     * tracking_id is a 32-char random string; repeated across the ~8
     * links per post in a 70-post digest that alone is tens of KB. A
     * 12-char slice still carries ~72 bits of the original randomness,
     * enough to keep links unguessable.
     */
    public function compactRef(): string
    {
        return substr($this->tracking_id, 0, 12);
    }

    /**
     * Compact tracked link for one of OUR OWN resources, encoded by type
     * + id rather than a base64'd full URL:
     *   /e/d/r/{ref}/{type}/{idEnc}/{pos}
     * The Go handler reconstructs the destination from {type}+{id}
     * (e.g. m -> /message/{id}?reply=1, g -> /explore/{id}). Arbitrary
     * external URLs still use getTrackedLinkUrl()'s ?url=base64 form.
     */
    public function getCompactLinkUrl(string $type, string $idEnc, string $position): string
    {
        $baseUrl = config('freegle.api.base_url', 'https://api.ilovefreegle.org');

        return "{$baseUrl}/e/d/r/{$this->compactRef()}/{$type}/{$idEnc}/{$position}";
    }

    /**
     * Compact tracked image for one of our own resources:
     *   /e/d/i/{ref}/{type}/{idEnc}/{preset}/{pos}[?s={scrollPercent}]
     * {preset} indexes a shared dimension table (0=240x240 card thumb,
     * 1=600x400 hero, 2=avatar) so width/height/fit aren't repeated in
     * every URL — the handler reconstructs the delivery URL from the
     * attachment/user id + preset.
     *
     * When $scrollPercent is provided, the Go handler updates
     * email_tracking.scroll_depth_percent via the same max-update logic as
     * the long-form Image handler, enabling scroll depth analytics for
     * compact digest emails.
     */
    public function getCompactImageUrl(string $type, string $idEnc, int $preset, string $position, ?int $scrollPercent = null): string
    {
        $baseUrl = config('freegle.api.base_url', 'https://api.ilovefreegle.org');
        $url = "{$baseUrl}/e/d/i/{$this->compactRef()}/{$type}/{$idEnc}/{$preset}/{$position}";

        if ($scrollPercent !== null) {
            $url .= "?s={$scrollPercent}";
        }

        return $url;
    }
}
