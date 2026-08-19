<?php

namespace Tests\Unit\Services\Mail\Deferrals;

use App\Services\Mail\Deferrals\RelayQueueSnapshot;
use Tests\TestCase;

class RelayQueueSnapshotTest extends TestCase
{
    private const YAHOO_THROTTLE = 'host mx-eu.mail.am0.yahoodns.net[188.125.72.74] said: '
        .'421 4.7.0 [TSS04] Messages from 185.53.57.161 temporarily deferred due to '
        .'unexpected volume or user complaints';

    private const GMAIL_MAILBOX_FULL = 'host alt1.gmail-smtp-in.l.google.com[142.250.102.27] said: '
        ."452-4.2.2 The recipient's inbox is out of storage space";

    // ===================================================================
    // Per-mailbox vs provider-level
    //
    // These two signals want very different thresholds, and conflating them
    // is not hypothetical. On 2026-08-19 Gmail's relay family carried 2,996
    // "4.2.2" and 2,252 "452" deferrals against 8 genuine "421"s. Counted
    // together they cleared the 500-deferral threshold, gmail.com was
    // suppressed, and 76,684 messages to 482 members were declined over two
    // and a half hours while Gmail was in fact delivering normally.
    // ===================================================================

    public function test_full_mailbox_does_not_count_against_the_provider(): void
    {
        $snapshot = new RelayQueueSnapshot;
        $snapshot->addDeferral('someone@gmail.com', self::GMAIL_MAILBOX_FULL, null, 'ABC123');

        $this->assertSame(
            [],
            $snapshot->groups,
            'a full mailbox says nothing about the provider and must not reach the relay family'
        );
        $this->assertSame(1, $snapshot->perMailbox);

        // It is still the address's problem, so the address bucket keeps it.
        $this->assertArrayHasKey('someone@gmail.com', $snapshot->addresses);
        $this->assertSame(1, $snapshot->addresses['someone@gmail.com']['count']);
    }

    public function test_provider_throttle_still_counts_against_the_provider(): void
    {
        $snapshot = new RelayQueueSnapshot;
        $snapshot->addDeferral('someone@yahoo.co.uk', self::YAHOO_THROTTLE, null, 'ABC123');

        $this->assertNotEmpty($snapshot->groups, 'a 421 throttle is exactly what the family bucket is for');
        $this->assertSame(0, $snapshot->perMailbox);

        $group = array_key_first($snapshot->groups);
        $this->assertSame(1, $snapshot->groups[$group]['count']);
        $this->assertArrayHasKey('yahoo.co.uk', $snapshot->groups[$group]['domains']);
    }

    public function test_mailbox_noise_cannot_drag_a_healthy_provider_over_the_threshold(): void
    {
        $snapshot = new RelayQueueSnapshot;

        // The shape of the real incident, scaled down.
        for ($i = 0; $i < 100; $i++) {
            $snapshot->addDeferral("full$i@gmail.com", self::GMAIL_MAILBOX_FULL, null, "Q$i");
        }
        $snapshot->addDeferral('real@gmail.com', 'host gmail-smtp-in.l.google.com said: 421 4.7.0 try again later', null, 'QX');

        $group = array_key_first($snapshot->groups);
        $this->assertSame(
            1,
            $snapshot->groups[$group]['count'],
            'only the genuine 421 should be held against Gmail, not the 100 full mailboxes'
        );
        $this->assertSame(100, $snapshot->perMailbox);
    }

    /**
     * @dataProvider perMailboxReasons
     */
    public function test_recognises_per_mailbox_wording(string $reason): void
    {
        $this->assertTrue(RelayQueueSnapshot::isPerMailbox($reason), $reason);
    }

    public static function perMailboxReasons(): array
    {
        return [
            'rfc 3463 code' => ['452 4.2.2 mailbox full'],
            'dashed code' => ["452-4.2.2 The recipient's inbox is out of storage space"],
            'over quota' => ['552 The email account that you tried to reach is over quota'],
            'over-quota hyphenated' => ['recipient over-quota'],
            'quota exceeded' => ['452 Quota exceeded for this recipient'],
            'mailbox full prose' => ['451 Mailbox full, try later'],
            'mailbox is full prose' => ['451 The mailbox is full'],
            'out of storage' => ['452 user is out of storage'],
        ];
    }

    /**
     * @dataProvider providerLevelReasons
     */
    public function test_does_not_mistake_provider_problems_for_full_mailboxes(string $reason): void
    {
        $this->assertFalse(RelayQueueSnapshot::isPerMailbox($reason), $reason);
    }

    public static function providerLevelReasons(): array
    {
        return [
            'yahoo throttle' => [self::YAHOO_THROTTLE],
            'generic 421' => ['421 4.7.0 try again later'],
            // 4.3.1 is the receiving SERVER out of space, which IS about the
            // provider - it must keep counting against them.
            'server out of storage' => ['452 4.3.1 Insufficient system storage'],
            'connection timeout' => ['connect to mx.example.com: Connection timed out'],
        ];
    }

    public function test_unattributable_reasons_are_counted_separately(): void
    {
        $snapshot = new RelayQueueSnapshot;
        $snapshot->addDeferral('someone@example.com', 'mail transport unavailable', null, null);

        $this->assertSame([], $snapshot->groups, 'a local failure blames no provider');
        $this->assertSame(1, $snapshot->unattributed);
        $this->assertSame(0, $snapshot->perMailbox, 'a local failure is not a full mailbox either');
    }
}
