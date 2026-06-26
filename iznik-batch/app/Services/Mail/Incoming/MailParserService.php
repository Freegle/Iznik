<?php

namespace App\Services\Mail\Incoming;

use Carbon\Carbon;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Message;

/**
 * Service for parsing raw email messages into structured ParsedEmail objects.
 *
 * This service extracts all relevant information from an incoming email,
 * including headers, body content, and routing hints derived from the
 * envelope addresses and message structure.
 *
 * Uses zbateson/mail-mime-parser - a pure PHP library with excellent RFC
 * compliance and no extension dependencies.
 */
class MailParserService
{
    /**
     * Parse a raw email message.
     *
     * @param  string  $rawMessage  The complete raw email including headers and body
     * @param  string  $envelopeFrom  The SMTP envelope sender (MAIL FROM)
     * @param  string  $envelopeTo  The SMTP envelope recipient (RCPT TO)
     * @return ParsedEmail The parsed email data
     */
    public function parse(string $rawMessage, string $envelopeFrom, string $envelopeTo): ParsedEmail
    {
        // Parse the message using zbateson/mail-mime-parser
        $message = Message::from($rawMessage, false);

        // Extract basic headers
        $subject = $message->getSubject();
        [$fromAddress, $fromName] = $this->extractFrom($message);
        $toAddresses = $this->extractToAddresses($message);
        $messageId = $this->extractMessageId($message);
        $date = $this->extractDate($message);

        // Extract body content
        $textBody = $message->getTextContent();
        $htmlBody = $message->getHtmlContent();

        // Extract all headers (lowercase keys for consistent access)
        $headers = $this->extractAllHeaders($message);

        // Analyze envelope-to for routing information
        $routingInfo = $this->analyzeEnvelopeTo($envelopeTo);

        // Check for bounce information
        $bounceInfo = $this->extractBounceInfo($message, $envelopeFrom, $envelopeTo);

        // Check for chat notification reply
        $chatInfo = $this->extractChatInfo($envelopeTo);

        // Check for email commands (digestoff, etc.)
        $commandInfo = $this->extractCommandInfo($envelopeTo);

        // Extract sender IP
        $senderIp = $this->extractSenderIp($headers);

        // Check if this is a reply to a digest email
        $isDigestReply = $this->isDigestReply($envelopeTo, $headers);

        return new ParsedEmail(
            rawMessage: $rawMessage,
            envelopeFrom: $envelopeFrom,
            envelopeTo: $envelopeTo,
            subject: $subject,
            fromAddress: $fromAddress,
            fromName: $fromName,
            toAddresses: $toAddresses,
            messageId: $messageId,
            date: $date,
            textBody: $textBody ?: null,
            htmlBody: $htmlBody ?: null,
            headers: $headers,
            targetGroupName: $routingInfo['groupName'],
            isToVolunteers: $routingInfo['isVolunteers'],
            isToAuto: $routingInfo['isAuto'],
            bounceRecipient: $bounceInfo['recipient'],
            bounceStatus: $bounceInfo['status'],
            bounceDiagnostic: $bounceInfo['diagnostic'],
            chatId: $chatInfo['chatId'],
            chatUserId: $chatInfo['userId'],
            chatMessageId: $chatInfo['messageId'],
            commandUserId: $commandInfo['userId'],
            commandGroupId: $commandInfo['groupId'],
            senderIp: $senderIp,
            isDigestReply: $isDigestReply
        );
    }

    /**
     * Extract from address and name.
     *
     * @return array{0: ?string, 1: ?string} [address, name]
     */
    private function extractFrom(Message $message): array
    {
        $fromHeader = $message->getHeader('From');

        if (! $fromHeader instanceof AddressHeader) {
            return [null, null];
        }

        $email = $fromHeader->getEmail();
        $name = $fromHeader->getPersonName();

        // If display name is same as email or empty, treat as no name
        if ($name === $email || $name === '') {
            $name = null;
        }

        return [$email, $name];
    }

    /**
     * Extract all To addresses.
     *
     * @return array<string>
     */
    private function extractToAddresses(Message $message): array
    {
        $toHeader = $message->getHeader('To');

        if (! $toHeader instanceof AddressHeader) {
            return [];
        }

        $addresses = [];
        foreach ($toHeader->getAddresses() as $addr) {
            $email = $addr->getEmail();
            if ($email !== null) {
                $addresses[] = $email;
            }
        }

        return $addresses;
    }

    /**
     * Extract Message-ID header.
     */
    private function extractMessageId(Message $message): ?string
    {
        $messageId = $message->getHeaderValue('Message-ID');
        if ($messageId === null) {
            return null;
        }

        // Remove angle brackets if present
        return trim($messageId, '<>');
    }

    /**
     * Extract and parse Date header.
     */
    private function extractDate(Message $message): ?Carbon
    {
        $dateHeader = $message->getHeader('Date');

        if ($dateHeader === null) {
            return null;
        }

        // zbateson provides DateTime parsing via DateHeader
        if (method_exists($dateHeader, 'getDateTime')) {
            $dateTime = $dateHeader->getDateTime();
            if ($dateTime !== null) {
                return Carbon::instance($dateTime);
            }
        }

        // Fallback: try parsing the raw value
        $dateValue = $message->getHeaderValue('Date');
        if ($dateValue !== null) {
            try {
                return Carbon::parse($dateValue);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Extract all headers as lowercase-keyed array.
     *
     * @return array<string, string>
     */
    private function extractAllHeaders(Message $message): array
    {
        $headers = [];

        foreach ($message->getAllHeaders() as $header) {
            $name = strtolower($header->getName());
            $value = $header->getRawValue();

            // If we already have this header, append (for multi-value headers)
            if (isset($headers[$name])) {
                $headers[$name] .= ', '.$value;
            } else {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * Analyze envelope-to address for routing information.
     *
     * @return array{groupName: ?string, isVolunteers: bool, isAuto: bool}
     */
    private function analyzeEnvelopeTo(string $envelopeTo): array
    {
        $result = [
            'groupName' => null,
            'isVolunteers' => false,
            'isAuto' => false,
        ];

        // Parse the envelope-to address
        $parts = explode('@', $envelopeTo);
        if (count($parts) !== 2) {
            return $result;
        }

        $localPart = $parts[0];
        $domain = $parts[1];

        // Only process group domain addresses for group routing
        $groupDomain = config('freegle.mail.group_domain');
        if ($domain !== $groupDomain) {
            return $result;
        }

        // Check for special suffixes
        if (str_ends_with($localPart, '-volunteers')) {
            $result['isVolunteers'] = true;
            $result['groupName'] = substr($localPart, 0, -11); // Remove '-volunteers'
        } elseif (str_ends_with($localPart, '-auto')) {
            $result['isAuto'] = true;
            $result['groupName'] = substr($localPart, 0, -5); // Remove '-auto'
        } elseif (str_ends_with($localPart, '-subscribe')) {
            $result['groupName'] = substr($localPart, 0, -10); // Remove '-subscribe'
        } elseif (str_ends_with($localPart, '-unsubscribe')) {
            $result['groupName'] = substr($localPart, 0, -12); // Remove '-unsubscribe'
        } else {
            // Plain group address
            $result['groupName'] = $localPart;
        }

        return $result;
    }

    /**
     * Extract bounce/DSN information from the message.
     *
     * @return array{recipient: ?string, status: ?string, diagnostic: ?string}
     */
    private function extractBounceInfo(Message $message, string $envelopeFrom, string $envelopeTo): array
    {
        $result = [
            'recipient' => null,
            'status' => null,
            'diagnostic' => null,
        ];

        // Check if this looks like a bounce
        // RFC 5321: Bounces should use null sender (empty MAIL FROM:<>) or MAILER-DAEMON
        // Some MTAs also use postmaster@
        $isFromBounceSource = $envelopeFrom === '' ||  // Null sender (RFC 5321 compliant)
            stripos($envelopeFrom, 'mailer-daemon') !== false ||
            stripos($envelopeFrom, 'postmaster') !== false;

        // Get the full Content-Type header including parameters
        // Use getRawHeaderValue to ensure we get the complete header with continuation lines
        $contentTypeHeader = $message->getHeader('Content-Type');
        $contentType = $contentTypeHeader ? $contentTypeHeader->getRawValue() : '';
        $isDeliveryReport = stripos($contentType, 'multipart/report') !== false &&
            stripos($contentType, 'delivery-status') !== false;

        // Check subject for bounce patterns (legacy compatibility)
        // This must be checked BEFORE the early return so we don't miss bounces
        // that only have indicative subjects without proper DSN format
        $subject = $message->getHeaderValue('Subject') ?? '';
        $isBounceSubject = $this->isBounceSubject($subject);

        if (! $isFromBounceSource && ! $isDeliveryReport && ! $isBounceSubject) {
            return $result;
        }

        // If only detected by subject (no DSN format), set generic bounce status
        if ($isBounceSubject && ! $isFromBounceSource && ! $isDeliveryReport) {
            $result['status'] = '5.0.0';  // Generic permanent failure
            $result['diagnostic'] = 'Detected by subject pattern: '.$subject;

            return $result;
        }

        // Look for delivery-status part in the message
        $childCount = $message->getChildCount();
        for ($i = 0; $i < $childCount; $i++) {
            $child = $message->getChild($i);
            if ($child === null) {
                continue;
            }

            $childContentType = $child->getHeaderValue('Content-Type') ?? '';
            if (stripos($childContentType, 'message/delivery-status') !== false) {
                $content = $child->getContent();
                if ($content !== null) {
                    $result = $this->parseDeliveryStatus($content);
                    break;
                }
            }
        }

        // If no delivery-status part, try to parse from body
        if ($result['recipient'] === null) {
            $textBody = $message->getTextContent();
            if ($textBody) {
                $result = $this->extractBounceFromBody($textBody);
            }
        }

        // Final fallback: if we still have no status but have a bounce subject, set generic status
        // This handles cases like postmaster@ with bounce subject but no proper DSN format
        if ($result['status'] === null && $isBounceSubject) {
            $result['status'] = '5.0.0';  // Generic permanent failure
            $result['diagnostic'] = 'Detected by subject pattern: '.$subject;
        }

        return $result;
    }

    /**
     * Check if subject matches bounce patterns (legacy compatibility).
     *
     * Ported from iznik-server/include/message/Message.php bounce_subjects
     */
    private function isBounceSubject(string $subject): bool
    {
        $bounceSubjects = [
            'Unable to deliver your message',
            'Mail delivery failed',
            'Delivery Status Notification',
            'Undelivered Mail Returned to Sender',
            'Local delivery error',
            'Returned mail',
            'delivery failure',
            'Delivery has failed',
            'Email delivery failure',
            'Undeliverable',
            'User unknown',
            'Could not send message',
            'Unknown user',
            'Malformed recipient',
            'invalid address',
        ];

        foreach ($bounceSubjects as $pattern) {
            if (stripos($subject, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse delivery-status content.
     *
     * @return array{recipient: ?string, status: ?string, diagnostic: ?string}
     */
    private function parseDeliveryStatus(string $content): array
    {
        $result = [
            'recipient' => null,
            'status' => null,
            'diagnostic' => null,
        ];

        // Parse Final-Recipient
        if (preg_match('/Final-Recipient:\s*(?:rfc822;?\s*)?(\S+)/i', $content, $matches)) {
            $result['recipient'] = trim($matches[1]);
        }

        // Parse Status
        if (preg_match('/Status:\s*(\d\.\d\.\d)/i', $content, $matches)) {
            $result['status'] = $matches[1];
        }

        // Parse Diagnostic-Code
        if (preg_match('/Diagnostic-Code:\s*(?:smtp;?\s*)?(.+?)(?:\r?\n(?!\s)|\z)/is', $content, $matches)) {
            $result['diagnostic'] = trim($matches[1]);
        }

        return $result;
    }

    /**
     * Try to extract bounce info from plain text body.
     *
     * @return array{recipient: ?string, status: ?string, diagnostic: ?string}
     */
    private function extractBounceFromBody(string $body): array
    {
        $result = [
            'recipient' => null,
            'status' => null,
            'diagnostic' => null,
        ];

        // Look for email address in angle brackets after common bounce indicators
        if (preg_match('/<([^>]+@[^>]+)>.*?(\d\d\d\s+.+?)(?:\r?\n|\z)/is', $body, $matches)) {
            $result['recipient'] = $matches[1];
            // Extract status code from diagnostic
            if (preg_match('/(\d\.\d\.\d)/', $matches[2], $statusMatch)) {
                $result['status'] = $statusMatch[1];
            }
            $result['diagnostic'] = trim($matches[2]);
        }

        return $result;
    }

    /**
     * Extract chat notification reply information from envelope-to.
     *
     * Format: notify-{chatId}-{userId}[-{messageId}]@users.ilovefreegle.org
     *
     * @return array{chatId: ?int, userId: ?int, messageId: ?int}
     */
    private function extractChatInfo(string $envelopeTo): array
    {
        $result = [
            'chatId' => null,
            'userId' => null,
            'messageId' => null,
        ];

        $parts = explode('@', $envelopeTo);
        if (count($parts) !== 2) {
            return $result;
        }

        $localPart = $parts[0];
        $domain = $parts[1];

        // Must be user domain
        $userDomain = config('freegle.mail.user_domain');
        if ($domain !== $userDomain) {
            return $result;
        }

        // Check for notify- prefix
        if (! str_starts_with($localPart, 'notify-')) {
            return $result;
        }

        // Parse: notify-{chatId}-{userId}[-{messageId}]
        $notifyParts = explode('-', substr($localPart, 7)); // Remove 'notify-'

        if (count($notifyParts) >= 2) {
            $result['chatId'] = is_numeric($notifyParts[0]) ? (int) $notifyParts[0] : null;
            $result['userId'] = is_numeric($notifyParts[1]) ? (int) $notifyParts[1] : null;

            if (isset($notifyParts[2]) && is_numeric($notifyParts[2])) {
                $result['messageId'] = (int) $notifyParts[2];
            }
        }

        return $result;
    }

    /**
     * Extract email command information from envelope-to.
     *
     * Supported formats:
     * - digestoff-{userId}-{groupId}@users.ilovefreegle.org
     *
     * @return array{userId: ?int, groupId: ?int}
     */
    private function extractCommandInfo(string $envelopeTo): array
    {
        $result = [
            'userId' => null,
            'groupId' => null,
        ];

        $parts = explode('@', $envelopeTo);
        if (count($parts) !== 2) {
            return $result;
        }

        $localPart = $parts[0];
        $domain = $parts[1];

        // Must be user domain
        $userDomain = config('freegle.mail.user_domain');
        if ($domain !== $userDomain) {
            return $result;
        }

        // Check for digestoff- prefix
        if (str_starts_with($localPart, 'digestoff-')) {
            $commandParts = explode('-', substr($localPart, 10)); // Remove 'digestoff-'

            if (count($commandParts) >= 2) {
                $result['userId'] = is_numeric($commandParts[0]) ? (int) $commandParts[0] : null;
                $result['groupId'] = is_numeric($commandParts[1]) ? (int) $commandParts[1] : null;
            }
        }

        return $result;
    }

    /**
     * Extract sender IP address from headers.
     */
    private function extractSenderIp(array $headers): ?string
    {
        // Check X-Freegle-IP first (our own header)
        if (isset($headers['x-freegle-ip'])) {
            return $headers['x-freegle-ip'];
        }

        // Check X-Originating-IP (common webmail header)
        if (isset($headers['x-originating-ip'])) {
            $ip = $headers['x-originating-ip'];

            // Often wrapped in square brackets
            return trim($ip, '[]');
        }

        // Check X-Trash-Nothing-User-IP (Trash Nothing posts)
        if (isset($headers['x-trash-nothing-user-ip'])) {
            return $headers['x-trash-nothing-user-ip'];
        }

        return null;
    }

    /**
     * Check if this email is a reply to a digest email.
     *
     * Digest emails are sent from noreply@. We detect replies by checking:
     * 1. Envelope-to is noreply@{user_domain}
     * 2. In-Reply-To header contains "UnifiedDigest" (our Message-ID pattern)
     */
    private function isDigestReply(string $envelopeTo, array $headers): bool
    {
        $userDomain = config('freegle.mail.user_domain');
        $noreplyAddr = config('freegle.mail.noreply_addr');

        // Check if addressed to noreply@
        if (strtolower($envelopeTo) === strtolower($noreplyAddr)) {
            return true;
        }

        // Check if In-Reply-To references a digest email
        $inReplyTo = $headers['in-reply-to'] ?? null;
        if ($inReplyTo !== null && str_contains($inReplyTo, 'UnifiedDigest')) {
            return true;
        }

        return false;
    }
}
