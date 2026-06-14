<?php

namespace App\Mail\Traits;

use InvalidArgumentException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\TextPart;

/**
 * Trait for adding AMP (Accelerated Mobile Pages) support to emails.
 *
 * AMP emails allow dynamic content (refreshable message lists) and
 * inline actions (replying without leaving the email client).
 *
 * Security model:
 * - Single HMAC-SHA256 token for both read and write operations
 * - Token is reusable within expiry period (default 7 days)
 * - User must also be a chat member (verified server-side)
 */
trait AmpEmail
{
    protected ?string $ampHtml = null;
    protected array $ampData = [];
    protected ?bool $ampOverride = null;

    /**
     * CSS properties and patterns that cause issues in AMP for Email.
     *
     * While the AMP spec allows some of these, Gmail and other clients
     * may reject them in practice. Better to avoid than debug delivery issues.
     *
     * @see https://amp.dev/documentation/guides-and-tutorials/learn/email-spec/amp-email-css/
     */
    protected static array $forbiddenAmpCssPatterns = [
        // pointer-events is explicitly forbidden in AMP for Email.
        '/pointer-events\s*:/i' => 'pointer-events',

        // Filter with url() is forbidden; other filter values may cause issues.
        '/filter\s*:\s*url\s*\(/i' => 'filter: url()',
        '/backdrop-filter\s*:/i' => 'backdrop-filter',

        // Clip and mask not reliably supported.
        '/clip-path\s*:/i' => 'clip-path',
        '/mask\s*:/i' => 'mask',
        '/mask-image\s*:/i' => 'mask-image',

        // @import is explicitly forbidden.
        '/@import\b/i' => '@import',

        // External stylesheets forbidden.
        '/<link[^>]+rel\s*=\s*["\']?stylesheet/i' => '<link rel="stylesheet">',

        // CSS variables don't work in Gmail even though they pass validation.
        '/var\s*\(\s*--/i' => 'CSS variables (--var) - not supported in Gmail',

        // Pseudo-elements may not work reliably.
        '/::before\b/i' => '::before pseudo-element',
        '/::after\b/i' => '::after pseudo-element',
        '/:before\b/i' => ':before pseudo-element',
        '/:after\b/i' => ':after pseudo-element',

        // Expression and behavior (legacy IE hacks, security risk).
        '/expression\s*\(/i' => 'expression()',
        '/behavior\s*:/i' => 'behavior',
    ];

    /**
     * Validate AMP HTML for forbidden CSS properties.
     *
     * Throws an exception if forbidden patterns are found. This catches
     * issues during testing before emails are sent to users.
     *
     * @param string $html The AMP HTML to validate
     * @throws InvalidArgumentException If forbidden patterns are found
     */
    protected function validateAmpCss(string $html): void
    {
        $violations = [];

        foreach (self::$forbiddenAmpCssPatterns as $pattern => $description) {
            if (preg_match($pattern, $html)) {
                $violations[] = $description;
            }
        }

        if (!empty($violations)) {
            throw new InvalidArgumentException(
                'AMP email contains forbidden CSS properties that may cause validation failures: ' .
                implode(', ', $violations) .
                '. See https://amp.dev/documentation/guides-and-tutorials/learn/email-spec/amp-email-css/'
            );
        }
    }

    /**
     * Override the AMP enabled setting.
     * Pass true to force AMP on, false to force AMP off, or null for default config.
     */
    public function setAmpOverride(?bool $enabled): static
    {
        $this->ampOverride = $enabled;
        return $this;
    }

    /**
     * Check if AMP email is enabled.
     */
    protected function isAmpEnabled(): bool
    {
        // If override is set, use it (but still require the secret for actual AMP).
        if ($this->ampOverride !== null) {
            return $this->ampOverride && !empty(config('freegle.amp.secret'));
        }

        return config('freegle.amp.enabled', true) && !empty(config('freegle.amp.secret'));
    }

    /**
     * Generate an HMAC-based token for AMP operations (both read and write).
     *
     * Tokens are reusable within their expiry period, allowing users to
     * view messages and send multiple replies from the same email.
     *
     * @param int $userId The user ID
     * @param int $resourceId The resource ID (e.g., chat ID)
     * @return array{token: string, expiry: int}
     */
    protected function generateToken(int $userId, int $resourceId): array
    {
        $secret = config('freegle.amp.secret');
        $expiryHours = config('freegle.amp.token_expiry_hours', 168); // 7 days default
        $expiry = time() + ($expiryHours * 3600);

        // Format: "amp" + user_id + resource_id + expiry
        $message = 'amp' . $userId . $resourceId . $expiry;
        $token = hash_hmac('sha256', $message, $secret);

        return [
            'token' => $token,
            'expiry' => $expiry,
        ];
    }

    /**
     * Build the AMP API URL for fetching chat messages.
     *
     * @param int $chatId The chat ID
     * @param int $userId The user ID
     * @param int|null $excludeMessageId Message ID to exclude (shown statically)
     * @param int|null $sinceMessageId Messages newer than this are marked as NEW
     * @param int|null $emailTrackingId Optional email tracking ID for AMP render tracking
     * @return string The full API URL with token parameters
     */
    protected function buildAmpChatUrl(
        int $chatId,
        int $userId,
        ?int $excludeMessageId = null,
        ?int $sinceMessageId = null,
        ?int $emailTrackingId = null
    ): string {
        $baseUrl = config('freegle.amp.api_url', 'https://api.ilovefreegle.org/amp');
        $token = $this->generateToken($userId, $chatId);

        $url = sprintf(
            '%s/chat/%d?rt=%s&uid=%d&exp=%d',
            $baseUrl,
            $chatId,
            $token['token'],
            $userId,
            $token['expiry']
        );

        if ($excludeMessageId) {
            $url .= '&exclude=' . $excludeMessageId;
        }

        if ($sinceMessageId) {
            $url .= '&since=' . $sinceMessageId;
        }

        if ($emailTrackingId) {
            $url .= '&tid=' . $emailTrackingId;
        }

        return $url;
    }

    /**
     * Build the AMP API URL for posting a reply.
     *
     * Uses the same HMAC token as read operations, with tracking ID as separate param.
     *
     * @param int $chatId The chat ID
     * @param int $userId The user ID
     * @param int|null $emailTrackingId Optional email tracking ID for analytics
     * @return string The full API URL with token
     */
    protected function buildAmpReplyUrl(int $chatId, int $userId, ?int $emailTrackingId = null): string
    {
        $baseUrl = config('freegle.amp.api_url', 'https://api.ilovefreegle.org/amp');
        $token = $this->generateToken($userId, $chatId);

        $url = sprintf(
            '%s/chat/%d/reply?rt=%s&uid=%d&exp=%d',
            $baseUrl,
            $chatId,
            $token['token'],
            $userId,
            $token['expiry']
        );

        if ($emailTrackingId) {
            $url .= '&tid=' . $emailTrackingId;
        }

        return $url;
    }

    /**
     * Set the AMP HTML content for the email.
     *
     * @param string $ampHtml The compiled AMP HTML
     */
    protected function setAmpContent(string $ampHtml): void
    {
        $this->ampHtml = $ampHtml;
    }

    /**
     * Render an AMP template and set as AMP content.
     *
     * @param string $template The Blade template path
     * @param array $data The template data
     */
    protected function renderAmpTemplate(string $template, array $data = []): void
    {
        $this->ampData = array_merge($this->getDefaultAmpData(), $data);

        // The MJML/HTML part is run through MjmlCompilerService, which strips
        // XML-illegal C0 control characters. The AMP part is NOT compiled
        // through it — the rendered Blade IS the AMP — so a stray control char
        // in user content (a post description, a job title) reaches the AMP
        // raw and makes Gmail reject the ENTIRE AMP as invalid (intermittent:
        // depends on which posts/jobs land in this digest). Strip the same
        // illegal control chars here (TAB/LF/CR kept). Byte-wise is UTF-8-safe
        // (these byte values never occur inside a multi-byte sequence).
        $this->ampHtml = preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
            '',
            view($template, $this->ampData)->render()
        );
    }

    /**
     * Get default data for AMP templates.
     */
    protected function getDefaultAmpData(): array
    {
        return [
            'ampApiUrl' => config('freegle.amp.api_url'),
            'siteName' => config('freegle.branding.name', 'Freegle'),
            'userSite' => config('freegle.sites.user'),
        ];
    }

    /**
     * Apply AMP content to the Symfony message.
     *
     * This should be called within withSymfonyMessage() to add the
     * text/x-amp-html MIME part alongside the regular HTML.
     *
     * Email structure becomes:
     * - multipart/alternative
     *   - text/plain
     *   - text/x-amp-html (AMP version)
     *   - text/html (fallback HTML version)
     *
     * @param Email $message The Symfony email message
     */
    protected function applyAmpToMessage(Email $message): void
    {
        if (!$this->ampHtml || !$this->isAmpEnabled()) {
            return;
        }

        // AMP for Email caps the AMP document at 200,000 bytes; beyond that
        // Gmail rejects the WHOLE AMP as invalid ("dynamic content not
        // rendered"). A large multi-post digest can cross it (~85+ posts).
        // Ship the valid HTML rather than an invalid AMP: skip attaching the
        // AMP part when it's over the limit. 199,000 leaves a small margin.
        if (strlen($this->ampHtml) > 199000) {
            \Illuminate\Support\Facades\Log::info('AMP part dropped: over AMP4Email 200KB document limit', [
                'mailable' => static::class,
                'amp_bytes' => strlen($this->ampHtml),
            ]);

            return;
        }

        // Validate AMP HTML for forbidden CSS before using it.
        // This will throw an exception during testing if problems are found.
        $this->validateAmpCss($this->ampHtml);

        $body = $message->getBody();

        // Get existing parts.
        $htmlBody = $message->getHtmlBody();
        $textBody = $message->getTextBody();

        if (!$htmlBody) {
            return;
        }

        // Create the AMP part with correct MIME type.
        $ampPart = new TextPart($this->ampHtml, 'utf-8', 'x-amp-html');

        // Create text part if exists.
        $parts = [];
        if ($textBody) {
            $parts[] = new TextPart($textBody, 'utf-8', 'plain');
        }

        // Add AMP part (must come before HTML for proper fallback).
        $parts[] = $ampPart;

        // Add HTML part.
        $parts[] = new TextPart($htmlBody, 'utf-8', 'html');

        // Create new alternative part with all versions.
        $alternativePart = new AlternativePart(...$parts);

        // Replace the message body.
        $message->setBody($alternativePart);
    }
}
