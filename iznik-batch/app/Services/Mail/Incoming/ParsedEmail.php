<?php

namespace App\Services\Mail\Incoming;

use Carbon\Carbon;

/**
 * Data Transfer Object for a parsed email message.
 *
 * This immutable object holds all extracted data from an incoming email,
 * including envelope information, headers, body content, and routing hints.
 */
class ParsedEmail
{
    // ========================================
    // Core Email Properties
    // ========================================

    public readonly string $rawMessage;

    public readonly string $envelopeFrom;

    public readonly string $envelopeTo;

    public readonly ?string $subject;

    public readonly ?string $fromAddress;

    public readonly ?string $fromName;

    public readonly array $toAddresses;

    public readonly ?string $messageId;

    public readonly ?Carbon $date;

    public readonly ?string $textBody;

    public readonly ?string $htmlBody;

    // ========================================
    // Routing Properties
    // ========================================

    public readonly ?string $targetGroupName;

    public readonly bool $isToVolunteers;

    public readonly bool $isToAuto;

    // ========================================
    // Bounce Properties
    // ========================================

    public readonly ?string $bounceRecipient;

    public readonly ?string $bounceStatus;

    public readonly ?string $bounceDiagnostic;

    // ========================================
    // Chat Reply Properties
    // ========================================

    public readonly ?int $chatId;

    public readonly ?int $chatUserId;

    public readonly ?int $chatMessageId;

    // ========================================
    // Email Command Properties
    // ========================================

    public readonly ?int $commandUserId;

    public readonly ?int $commandGroupId;

    // ========================================
    // Spam/Security Properties
    // ========================================

    public readonly ?string $senderIp;

    // ========================================
    // Digest Reply Properties
    // ========================================

    public readonly bool $isDigestReply;

    // ========================================
    // Internal State
    // ========================================

    private array $headers;

    private string $envelopeToLocalPart;

    public function __construct(
        string $rawMessage,
        string $envelopeFrom,
        string $envelopeTo,
        ?string $subject,
        ?string $fromAddress,
        ?string $fromName,
        array $toAddresses,
        ?string $messageId,
        ?Carbon $date,
        ?string $textBody,
        ?string $htmlBody,
        array $headers,
        ?string $targetGroupName,
        bool $isToVolunteers,
        bool $isToAuto,
        ?string $bounceRecipient,
        ?string $bounceStatus,
        ?string $bounceDiagnostic,
        ?int $chatId,
        ?int $chatUserId,
        ?int $chatMessageId,
        ?int $commandUserId,
        ?int $commandGroupId,
        ?string $senderIp,
        bool $isDigestReply = false
    ) {
        $this->rawMessage = $rawMessage;
        $this->envelopeFrom = $envelopeFrom;
        $this->envelopeTo = $envelopeTo;
        $this->subject = $subject;
        $this->fromAddress = $fromAddress;
        $this->fromName = $fromName;
        $this->toAddresses = $toAddresses;
        $this->messageId = $messageId;
        $this->date = $date;
        $this->textBody = $textBody;
        $this->htmlBody = $htmlBody;
        $this->headers = $headers;
        $this->targetGroupName = $targetGroupName;
        $this->isToVolunteers = $isToVolunteers;
        $this->isToAuto = $isToAuto;
        $this->bounceRecipient = $bounceRecipient;
        $this->bounceStatus = $bounceStatus;
        $this->bounceDiagnostic = $bounceDiagnostic;
        $this->chatId = $chatId;
        $this->chatUserId = $chatUserId;
        $this->chatMessageId = $chatMessageId;
        $this->commandUserId = $commandUserId;
        $this->commandGroupId = $commandGroupId;
        $this->senderIp = $senderIp;
        $this->isDigestReply = $isDigestReply;

        // Cache envelope-to local part for routing checks
        $this->envelopeToLocalPart = explode('@', $envelopeTo)[0] ?? '';
    }

    // ========================================
    // Header Access
    // ========================================

    /**
     * Get a header value by name (case-insensitive).
     */
    public function getHeader(string $name): ?string
    {
        $name = strtolower($name);

        return $this->headers[$name] ?? null;
    }

    /**
     * Get all headers.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    // ========================================
    // Message Type Detection
    // ========================================

    /**
     * Check if this is a bounce/DSN message.
     */
    public function isBounce(): bool
    {
        return $this->bounceRecipient !== null || $this->bounceStatus !== null;
    }

    /**
     * Check if this is a permanent bounce (5.x.x status).
     */
    public function isPermanentBounce(): bool
    {
        if ($this->bounceStatus === null) {
            return false;
        }

        return str_starts_with($this->bounceStatus, '5');
    }

    /**
     * Check if this is a reply to a chat notification email.
     */
    public function isChatNotificationReply(): bool
    {
        return $this->chatId !== null;
    }

    /**
     * Check if this is an auto-reply (vacation, OOO, etc.).
     */
    public function isAutoReply(): bool
    {
        // Check Auto-Submitted header (RFC 3834)
        $autoSubmitted = $this->getHeader('auto-submitted');
        if ($autoSubmitted !== null && strtolower($autoSubmitted) !== 'no') {
            return TRUE;
        }

        // Check subject patterns (matches legacy Message::isAutoreply)
        $autoReplySubjects = [
            'Auto Response', 'Autoresponder', 'If your enquiry is urgent',
            'Thankyou for your enquiry', 'Thanks for your email',
            'Thanks for contacting', 'Thank you for your enquiry',
            'Many thanks for your', 'Automatic reply', 'Automated reply',
            'Auto-Reply', 'Out of Office', 'maternity leave', 'paternity leave',
            'return to the office', 'due to return', 'annual leave',
            'on holiday', 'vacation reply', 'YOUR ORDER MANAGEMENT REQUEST',
        ];

        $subject = $this->subject ?? '';
        foreach ($autoReplySubjects as $pattern) {
            if (stripos($subject, $pattern) !== FALSE) {
                return TRUE;
            }
        }

        // Check body patterns (matches legacy Message::isAutoreply)
        $autoReplyBodies = [
            'I aim to respond within', 'Our team aims to respond',
            'reply as soon as possible', 'with clients right now',
            'Automated response', 'Please note his new address',
            'THIS IS AN AUTO-RESPONSE MESSAGE', 'out of the office',
            'on annual leave', 'Thank you so much for your email enquiry',
            "Thanks for your email enquiry", "don't check this very often",
            'below to complete the verification process',
            'We respond to emails as quickly as we can',
            'this email address is no longer in use', 'away from the office',
            "I won't be able to check any emails until after",
            "I'm on leave at the moment",
            "We'll get back to you as soon as possible",
            'currently on leave', 'To complete this verification',
            'I am currently away from my computer, but will reply to your message as soon as I return',
            "E-mails to personal mailboxes aren't monitored",
            'I am currently unavailable',
            'We appreciate your patience while we get back to you.',
        ];

        $body = $this->textBody ?? '';
        foreach ($autoReplyBodies as $pattern) {
            if (stripos($body, $pattern) !== FALSE) {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * Check if this email is addressed to group volunteers.
     */
    public function isToVolunteers(): bool
    {
        return $this->isToVolunteers;
    }

    /**
     * Check if this email is addressed to the group auto address.
     */
    public function isToAuto(): bool
    {
        return $this->isToAuto;
    }

    // ========================================
    // Digest Reply Detection
    // ========================================

    /**
     * Check if this is a reply to a digest email (sent from noreply@).
     */
    public function isDigestReply(): bool
    {
        return $this->isDigestReply;
    }

    // ========================================
    // Email Command Detection
    // ========================================

    /**
     * Check if this is a subscribe command.
     */
    public function isSubscribeCommand(): bool
    {
        return str_ends_with($this->envelopeToLocalPart, '-subscribe');
    }

    /**
     * Check if this is an unsubscribe command.
     */
    public function isUnsubscribeCommand(): bool
    {
        return str_ends_with($this->envelopeToLocalPart, '-unsubscribe');
    }

    /**
     * Check if this is a digest-off command.
     */
    public function isDigestOffCommand(): bool
    {
        return str_starts_with($this->envelopeToLocalPart, 'digestoff-');
    }

    // ========================================
    // Trash Nothing Detection
    // ========================================

    /**
     * Check if this email came from Trash Nothing.
     */
    public function isFromTrashNothing(): bool
    {
        // Check for TN secret header
        $tnSecret = $this->getHeader('x-trash-nothing-secret');
        if ($tnSecret !== null) {
            return true;
        }

        // Check for TN-specific domain in envelope-from
        $tnDomain = config('freegle.mail.trashnothing_domain');

        return str_contains($this->envelopeFrom, $tnDomain);
    }

    /**
     * Get Trash Nothing post ID if present.
     */
    public function getTrashNothingPostId(): ?string
    {
        return $this->getHeader('x-trash-nothing-post-id');
    }

    /**
     * Get Trash Nothing user IP if present.
     */
    public function getTrashNothingUserIp(): ?string
    {
        return $this->getHeader('x-trash-nothing-user-ip');
    }

    /**
     * Get Trash Nothing post coordinates if present.
     */
    public function getTrashNothingCoordinates(): ?string
    {
        return $this->getHeader('x-trash-nothing-post-coordinates');
    }

    /**
     * Get Trash Nothing source (Web, App, etc.) if present.
     */
    public function getTrashNothingSource(): ?string
    {
        return $this->getHeader('x-trash-nothing-source');
    }

    /**
     * Get Trash Nothing secret header for authentication.
     */
    public function getTrashNothingSecret(): ?string
    {
        return $this->getHeader('x-trash-nothing-secret');
    }

    // ========================================
    // Serialization for Validation/Comparison
    // ========================================

    /**
     * Convert to array for comparison/logging.
     *
     * This is useful for the validation test mode where we compare
     * Laravel parsing results against PHP parsing results.
     */
    public function toArray(): array
    {
        return [
            'envelope_from' => $this->envelopeFrom,
            'envelope_to' => $this->envelopeTo,
            'subject' => $this->subject,
            'from_address' => $this->fromAddress,
            'from_name' => $this->fromName,
            'to_addresses' => $this->toAddresses,
            'message_id' => $this->messageId,
            'date' => $this->date?->toIso8601String(),
            'has_text_body' => $this->textBody !== null,
            'has_html_body' => $this->htmlBody !== null,
            'target_group_name' => $this->targetGroupName,
            'is_to_volunteers' => $this->isToVolunteers,
            'is_to_auto' => $this->isToAuto,
            'is_bounce' => $this->isBounce(),
            'is_permanent_bounce' => $this->isPermanentBounce(),
            'bounce_recipient' => $this->bounceRecipient,
            'bounce_status' => $this->bounceStatus,
            'is_chat_reply' => $this->isChatNotificationReply(),
            'chat_id' => $this->chatId,
            'chat_user_id' => $this->chatUserId,
            'chat_message_id' => $this->chatMessageId,
            'is_auto_reply' => $this->isAutoReply(),
            'is_digest_reply' => $this->isDigestReply(),
            'is_subscribe_command' => $this->isSubscribeCommand(),
            'is_unsubscribe_command' => $this->isUnsubscribeCommand(),
            'is_digestoff_command' => $this->isDigestOffCommand(),
            'command_user_id' => $this->commandUserId,
            'command_group_id' => $this->commandGroupId,
            'sender_ip' => $this->senderIp,
            'is_from_trash_nothing' => $this->isFromTrashNothing(),
        ];
    }

    /**
     * Get a fingerprint for quick comparison.
     *
     * This hash can be used to quickly check if two ParsedEmail objects
     * have identical routing-relevant properties.
     */
    public function getRoutingFingerprint(): string
    {
        $data = [
            $this->envelopeFrom,
            $this->envelopeTo,
            $this->targetGroupName,
            $this->isBounce(),
            $this->isChatNotificationReply(),
            $this->isSubscribeCommand(),
            $this->isUnsubscribeCommand(),
            $this->isDigestOffCommand(),
            $this->isAutoReply(),
        ];

        return md5(json_encode($data));
    }
}
