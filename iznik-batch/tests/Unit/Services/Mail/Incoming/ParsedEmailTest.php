<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Services\Mail\Incoming\ParsedEmail;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParsedEmailTest extends TestCase
{
    /**
     * Build a ParsedEmail with sensible defaults, overridable per-test.
     * Keys match the constructor's named parameters.
     */
    private function makeEmail(array $overrides = []): ParsedEmail
    {
        $defaults = [
            'rawMessage' => 'raw',
            'envelopeFrom' => 'sender@example.com',
            'envelopeTo' => 'group@example.com',
            'subject' => 'Hello',
            'fromAddress' => 'sender@example.com',
            'fromName' => 'Sender',
            'toAddresses' => ['group@example.com'],
            'messageId' => '<abc@example.com>',
            'date' => Carbon::parse('2026-01-01T00:00:00Z'),
            'textBody' => 'body text',
            'htmlBody' => null,
            'headers' => [],
            'targetGroupName' => 'MyGroup',
            'isToVolunteers' => false,
            'isToAuto' => false,
            'bounceRecipient' => null,
            'bounceStatus' => null,
            'bounceDiagnostic' => null,
            'chatId' => null,
            'chatUserId' => null,
            'chatMessageId' => null,
            'commandUserId' => null,
            'commandGroupId' => null,
            'senderIp' => '1.2.3.4',
            'isDigestReply' => false,
        ];

        $args = array_merge($defaults, $overrides);

        return new ParsedEmail(...$args);
    }

    // -------------------------------------------------------------------
    // Header access
    // -------------------------------------------------------------------

    public function test_get_header_is_case_insensitive(): void
    {
        $email = $this->makeEmail(['headers' => ['x-custom-header' => 'value1']]);

        $this->assertSame('value1', $email->getHeader('X-Custom-Header'));
        $this->assertSame('value1', $email->getHeader('x-custom-header'));
    }

    public function test_get_header_returns_null_when_absent(): void
    {
        $email = $this->makeEmail(['headers' => []]);

        $this->assertNull($email->getHeader('missing'));
    }

    public function test_get_headers_returns_all_headers(): void
    {
        $headers = ['a' => '1', 'b' => '2'];
        $email = $this->makeEmail(['headers' => $headers]);

        $this->assertSame($headers, $email->getHeaders());
    }

    // -------------------------------------------------------------------
    // Bounce detection
    // -------------------------------------------------------------------

    public function test_is_bounce_false_when_no_bounce_fields(): void
    {
        $email = $this->makeEmail(['bounceRecipient' => null, 'bounceStatus' => null]);

        $this->assertFalse($email->isBounce());
    }

    public function test_is_bounce_true_when_bounce_recipient_set(): void
    {
        $email = $this->makeEmail(['bounceRecipient' => 'someone@example.com']);

        $this->assertTrue($email->isBounce());
    }

    public function test_is_bounce_true_when_bounce_status_set(): void
    {
        $email = $this->makeEmail(['bounceStatus' => '5.1.1']);

        $this->assertTrue($email->isBounce());
    }

    public function test_is_permanent_bounce_false_when_status_null(): void
    {
        $email = $this->makeEmail(['bounceStatus' => null]);

        $this->assertFalse($email->isPermanentBounce());
    }

    public function test_is_permanent_bounce_true_for_5xx_status(): void
    {
        $email = $this->makeEmail(['bounceStatus' => '5.1.1']);

        $this->assertTrue($email->isPermanentBounce());
    }

    public function test_is_permanent_bounce_false_for_4xx_status(): void
    {
        $email = $this->makeEmail(['bounceStatus' => '4.2.2']);

        $this->assertFalse($email->isPermanentBounce());
    }

    // -------------------------------------------------------------------
    // Chat reply detection
    // -------------------------------------------------------------------

    public function test_is_chat_notification_reply_true_when_chat_id_set(): void
    {
        $email = $this->makeEmail(['chatId' => 42]);

        $this->assertTrue($email->isChatNotificationReply());
    }

    public function test_is_chat_notification_reply_false_when_chat_id_null(): void
    {
        $email = $this->makeEmail(['chatId' => null]);

        $this->assertFalse($email->isChatNotificationReply());
    }

    // -------------------------------------------------------------------
    // Auto-Submitted header (RFC 3834)
    // -------------------------------------------------------------------

    public function test_is_auto_submitted_false_when_header_absent(): void
    {
        $email = $this->makeEmail(['headers' => []]);

        $this->assertFalse($email->isAutoSubmitted());
    }

    public function test_is_auto_submitted_false_when_header_is_no(): void
    {
        $email = $this->makeEmail(['headers' => ['auto-submitted' => 'No']]);

        $this->assertFalse($email->isAutoSubmitted());
    }

    public function test_is_auto_submitted_true_for_other_values(): void
    {
        $email = $this->makeEmail(['headers' => ['auto-submitted' => 'auto-replied']]);

        $this->assertTrue($email->isAutoSubmitted());
    }

    // -------------------------------------------------------------------
    // Auto-reply detection
    // -------------------------------------------------------------------

    public function test_is_auto_reply_false_for_ordinary_email(): void
    {
        $email = $this->makeEmail(['subject' => 'Hello there', 'textBody' => 'Just a normal message.']);

        $this->assertFalse($email->isAutoReply());
    }

    public function test_is_auto_reply_true_via_auto_submitted_header(): void
    {
        $email = $this->makeEmail([
            'subject' => 'Nothing special',
            'textBody' => 'Nothing special',
            'headers' => ['auto-submitted' => 'auto-generated'],
        ]);

        $this->assertTrue($email->isAutoReply());
    }

    public function test_is_auto_reply_true_via_subject_pattern(): void
    {
        $email = $this->makeEmail(['subject' => 'Out of Office: back Monday', 'textBody' => 'n/a']);

        $this->assertTrue($email->isAutoReply());
    }

    public function test_is_auto_reply_true_via_body_pattern(): void
    {
        $email = $this->makeEmail(['subject' => 'Re: your item', 'textBody' => 'I am currently on annual leave until next week.']);

        $this->assertTrue($email->isAutoReply());
    }

    public function test_is_auto_reply_false_with_null_subject_and_body(): void
    {
        $email = $this->makeEmail(['subject' => null, 'textBody' => null]);

        $this->assertFalse($email->isAutoReply());
    }

    // -------------------------------------------------------------------
    // Simple boolean passthroughs
    // -------------------------------------------------------------------

    public function test_is_to_volunteers_passthrough(): void
    {
        $this->assertTrue($this->makeEmail(['isToVolunteers' => true])->isToVolunteers());
        $this->assertFalse($this->makeEmail(['isToVolunteers' => false])->isToVolunteers());
    }

    public function test_is_to_auto_passthrough(): void
    {
        $this->assertTrue($this->makeEmail(['isToAuto' => true])->isToAuto());
        $this->assertFalse($this->makeEmail(['isToAuto' => false])->isToAuto());
    }

    public function test_is_digest_reply_passthrough(): void
    {
        $this->assertTrue($this->makeEmail(['isDigestReply' => true])->isDigestReply());
        $this->assertFalse($this->makeEmail(['isDigestReply' => false])->isDigestReply());
    }

    // -------------------------------------------------------------------
    // Email command detection (derived from envelope-to local part)
    // -------------------------------------------------------------------

    public static function commandDetectionProvider(): array
    {
        return [
            'subscribe command' => ['group-subscribe@example.com', 'isSubscribeCommand', true],
            'not a subscribe command' => ['group@example.com', 'isSubscribeCommand', false],
            'unsubscribe command' => ['group-unsubscribe@example.com', 'isUnsubscribeCommand', true],
            'not an unsubscribe command' => ['group@example.com', 'isUnsubscribeCommand', false],
            'digestoff command' => ['digestoff-group@example.com', 'isDigestOffCommand', true],
            'not a digestoff command' => ['group@example.com', 'isDigestOffCommand', false],
        ];
    }

    #[DataProvider('commandDetectionProvider')]
    public function test_email_command_detection(string $envelopeTo, string $method, bool $expected): void
    {
        $email = $this->makeEmail(['envelopeTo' => $envelopeTo]);

        $this->assertSame($expected, $email->{$method}());
    }

    // -------------------------------------------------------------------
    // Trash Nothing detection
    // -------------------------------------------------------------------

    public function test_is_from_trash_nothing_true_via_secret_header(): void
    {
        $email = $this->makeEmail(['headers' => ['x-trash-nothing-secret' => 'shh']]);

        $this->assertTrue($email->isFromTrashNothing());
    }

    public function test_is_from_trash_nothing_true_via_envelope_domain(): void
    {
        $email = $this->makeEmail(['envelopeFrom' => 'notify@trashnothing.com', 'headers' => []]);

        $this->assertTrue($email->isFromTrashNothing());
    }

    public function test_is_from_trash_nothing_false_otherwise(): void
    {
        $email = $this->makeEmail(['envelopeFrom' => 'sender@example.com', 'headers' => []]);

        $this->assertFalse($email->isFromTrashNothing());
    }

    public static function trashNothingHeaderGetterProvider(): array
    {
        return [
            'post id' => ['x-trash-nothing-post-id', 'getTrashNothingPostId', 'tn-123'],
            'user ip' => ['x-trash-nothing-user-ip', 'getTrashNothingUserIp', '9.9.9.9'],
            'coordinates' => ['x-trash-nothing-post-coordinates', 'getTrashNothingCoordinates', '51.5,-0.1'],
            'source' => ['x-trash-nothing-source', 'getTrashNothingSource', 'App'],
            'secret' => ['x-trash-nothing-secret', 'getTrashNothingSecret', 'shh'],
        ];
    }

    #[DataProvider('trashNothingHeaderGetterProvider')]
    public function test_trash_nothing_header_getters_return_value_when_present(string $header, string $method, string $value): void
    {
        $email = $this->makeEmail(['headers' => [$header => $value]]);

        $this->assertSame($value, $email->{$method}());
    }

    #[DataProvider('trashNothingHeaderGetterProvider')]
    public function test_trash_nothing_header_getters_return_null_when_absent(string $header, string $method): void
    {
        $email = $this->makeEmail(['headers' => []]);

        $this->assertNull($email->{$method}());
    }

    // -------------------------------------------------------------------
    // Serialization
    // -------------------------------------------------------------------

    public function test_to_array_reflects_object_state(): void
    {
        $email = $this->makeEmail([
            'envelopeFrom' => 'a@example.com',
            'envelopeTo' => 'b@example.com',
            'subject' => 'Subj',
            'chatId' => 7,
            'date' => Carbon::parse('2026-03-04T05:06:07Z'),
        ]);

        $array = $email->toArray();

        $this->assertSame('a@example.com', $array['envelope_from']);
        $this->assertSame('b@example.com', $array['envelope_to']);
        $this->assertSame('Subj', $array['subject']);
        $this->assertTrue($array['is_chat_reply']);
        $this->assertSame(7, $array['chat_id']);
        $this->assertSame('2026-03-04T05:06:07+00:00', $array['date']);
        $this->assertTrue($array['has_text_body']);
        $this->assertFalse($array['has_html_body']);
    }

    public function test_to_array_handles_null_date(): void
    {
        $email = $this->makeEmail(['date' => null]);

        $this->assertNull($email->toArray()['date']);
    }

    // -------------------------------------------------------------------
    // Routing fingerprint
    // -------------------------------------------------------------------

    public function test_routing_fingerprint_is_a_32_char_hex_string(): void
    {
        $fingerprint = $this->makeEmail()->getRoutingFingerprint();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $fingerprint);
    }

    public function test_routing_fingerprint_is_deterministic(): void
    {
        $args = ['envelopeFrom' => 'x@example.com', 'chatId' => 5];

        $this->assertSame(
            $this->makeEmail($args)->getRoutingFingerprint(),
            $this->makeEmail($args)->getRoutingFingerprint()
        );
    }

    public function test_routing_fingerprint_differs_for_different_routing_state(): void
    {
        // The fingerprint captures the routing STATE (bounce / chat-reply /
        // command / auto), not the specific ids behind it — isChatNotificationReply()
        // is chatId !== null, so chatId 1 and 2 are the SAME state. Vary a state
        // predicate instead: a chat-notification reply (chatId set) vs a plain email.
        $a = $this->makeEmail(['chatId' => 1]);
        $b = $this->makeEmail(['chatId' => null]);

        $this->assertNotSame($a->getRoutingFingerprint(), $b->getRoutingFingerprint());
    }
}
