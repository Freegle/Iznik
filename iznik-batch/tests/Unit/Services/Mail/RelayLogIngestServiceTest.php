<?php

namespace Tests\Unit\Services\Mail;

use App\Monitoring\HostCommandRunner;
use App\Services\Mail\RelayLogIngestService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * logs_emails is how we answer "did you actually email me?" - the moderator
 * endpoint, the GDPR dump and the AI support helper all read it, and the
 * helper treats a missing row as proof we never sent the message. So these
 * tests feed the parser the shapes a real relay produces, especially the one
 * that looks like good news and is not: the loopback hop into the second
 * postfix instance.
 */
class RelayLogIngestServiceTest extends TestCase
{
    private function runner(?string $output, ?callable $spy = null): HostCommandRunner
    {
        return new class($output, $spy) implements HostCommandRunner
        {
            public function __construct(
                private readonly ?string $output,
                private $spy,
            ) {
            }

            public function run(string $target, string $script): ?string
            {
                if ($this->spy !== null) {
                    ($this->spy)($target, $script);
                }

                return $this->output;
            }
        };
    }

    private function service(?string $output = null, ?callable $spy = null): RelayLogIngestService
    {
        config()->set('freegle.mail.relay_logs.handover_port', 10026);
        config()->set('freegle.mail.relay_logs.handover_transport', 'relaywarm');

        return new RelayLogIngestService($this->runner($output, $spy));
    }

    /**
     * The one that matters. The hop into the second instance says
     * `status=sent (250 ...)` for a message that reached nothing but our own
     * machine. Recorded as a delivery it would tell a member - through the
     * support helper - that their provider accepted a message the provider may
     * not see for hours.
     */
    public function test_the_loopback_hop_is_not_recorded_as_a_delivery(): void
    {
        $lines = [
            'Sep 15 07:26:22 localhost postfix/cleanup[1]: ABC123: message-id=<m1@example.com>',
            'Sep 15 07:26:22 localhost postfix/cleanup[1]: ABC123: info: header Subject: Your daily digest from Freegle from unknown[1.2.3.4]',
            'Sep 15 07:26:22 localhost postfix/qmgr[2]: ABC123: from=<noreply@ilovefreegle.org>, size=1, nrcpt=1 (queue active)',
            'Sep 15 07:26:22 localhost postfix-relaywarm/smtp[3]: ABC123: to=<someone@yahoo.com>, relay=127.0.0.1[127.0.0.1]:10026, delay=0.12, dsn=2.0.0, status=sent (250 2.0.0 Ok: queued as DEF456)',
            'Sep 15 07:26:22 localhost postfix/qmgr[2]: ABC123: removed',
        ];

        $parsed = $this->service()->parseLines($lines);

        $this->assertSame(1, $parsed['handovers'], 'the hop should be counted as a hop');
        $this->assertArrayNotHasKey('ABC123', $parsed['records'],
            'a message whose only recipient was the loopback hop must produce no row at all');
    }

    /**
     * The mirror image: the second instance really did deliver it, under its
     * own queue id, and that is the row we want.
     */
    public function test_the_second_instance_delivery_is_recorded(): void
    {
        $lines = [
            'Sep 15 07:26:25 localhost postfix-warm/cleanup[4]: DEF456: message-id=<m1@example.com>',
            'Sep 15 07:26:25 localhost postfix-warm/cleanup[4]: DEF456: info: header Subject: Your daily digest from Freegle from someone',
            'Sep 15 07:26:25 localhost postfix-warm/qmgr[5]: DEF456: from=<noreply@ilovefreegle.org>, size=1, nrcpt=1 (queue active)',
            'Sep 15 07:26:28 localhost postfix-warm1yahoodnsnet/smtp[6]: DEF456: to=<someone@yahoo.com>, relay=mta5.am0.yahoodns.net[1.2.3.4]:25, delay=3, dsn=2.0.0, status=sent (250 ok dirdel)',
            'Sep 15 07:26:28 localhost postfix-warm/qmgr[5]: DEF456: removed',
        ];

        $parsed = $this->service()->parseLines($lines);

        $this->assertSame(0, $parsed['handovers']);
        $this->assertArrayHasKey('DEF456', $parsed['records']);
        $record = $parsed['records']['DEF456'];
        $this->assertSame('someone@yahoo.com', $record['to']);
        $this->assertStringContainsString('250 ok dirdel', $record['status']);
        $this->assertSame('noreply@ilovefreegle.org', $record['from']);
        $this->assertSame('m1@example.com', $record['messageid']);
    }

    /**
     * A genuine delivery to a provider we are NOT pacing goes straight out
     * from the primary, and must be unaffected by any of this.
     */
    public function test_an_ordinary_delivery_is_unaffected(): void
    {
        $lines = [
            'Sep 15 07:26:22 localhost postfix/qmgr[2]: 0E1F2A: from=<noreply@ilovefreegle.org>, size=1, nrcpt=1 (queue active)',
            'Sep 15 07:26:22 localhost postfix/smtp[7]: 0E1F2A: to=<someone@gmail.com>, relay=aspmx.l.google.com[1.2.3.4]:25, delay=0.3, dsn=2.0.0, status=sent (250 OK)',
            'Sep 15 07:26:22 localhost postfix/qmgr[2]: 0E1F2A: removed',
        ];

        $parsed = $this->service()->parseLines($lines);

        $this->assertArrayHasKey('0E1F2A', $parsed['records']);
        $this->assertSame('someone@gmail.com', $parsed['records']['0E1F2A']['to']);
    }

    /**
     * One message, two recipients, one of each. Dropping the whole record
     * would lose a delivery that really happened.
     */
    public function test_a_mixed_message_keeps_the_recipient_that_really_went_out(): void
    {
        $lines = [
            'Sep 15 07:26:22 localhost postfix/qmgr[2]: A1B2C3: from=<noreply@ilovefreegle.org>, size=1, nrcpt=2 (queue active)',
            'Sep 15 07:26:22 localhost postfix-relaywarm/smtp[3]: A1B2C3: to=<a@yahoo.com>, relay=127.0.0.1[127.0.0.1]:10026, dsn=2.0.0, status=sent (250 2.0.0 Ok: queued as XYZ)',
            'Sep 15 07:26:22 localhost postfix/smtp[7]: A1B2C3: to=<b@gmail.com>, relay=aspmx.l.google.com[1.2.3.4]:25, dsn=2.0.0, status=sent (250 OK)',
            'Sep 15 07:26:22 localhost postfix/qmgr[2]: A1B2C3: removed',
        ];

        $parsed = $this->service()->parseLines($lines);

        $this->assertSame(1, $parsed['handovers']);
        $this->assertArrayHasKey('A1B2C3', $parsed['records']);
        $this->assertSame('b@gmail.com', $parsed['records']['A1B2C3']['to'],
            'the real delivery survives; the hop does not overwrite it');
    }

    /**
     * The transport could be renamed, or the port changed. Either signal alone
     * still has to catch the hop, because relying on one makes a rename enough
     * to turn every hop back into a false "sent".
     */
    public function test_either_signal_alone_still_catches_the_hop(): void
    {
        $tagOnly = ['Sep 15 07:26:22 localhost postfix-relaywarm/smtp[3]: AAAAA1: to=<a@yahoo.com>, relay=10.0.0.9[10.0.0.9]:2525, dsn=2.0.0, status=sent (250 ok)',
            'Sep 15 07:26:22 localhost postfix/qmgr[2]: AAAAA1: removed'];
        $relayOnly = ['Sep 15 07:26:22 localhost postfix/smtp[3]: BBBBB2: to=<a@yahoo.com>, relay=127.0.0.1[127.0.0.1]:10026, dsn=2.0.0, status=sent (250 ok)',
            'Sep 15 07:26:22 localhost postfix/qmgr[2]: BBBBB2: removed'];

        $this->assertSame(1, $this->service()->parseLines($tagOnly)['handovers'], 'the tag alone must catch it');
        $this->assertSame(1, $this->service()->parseLines($relayOnly)['handovers'], 'the loopback relay alone must catch it');
    }

    /**
     * A message still in flight when the slice ended gets a row now and is
     * filled in on a later run. V1 did this, and it is what keeps a row
     * present for a message that is merely slow rather than lost - which
     * matters because the support helper reads a missing row as "never sent".
     */
    public function test_a_message_still_in_flight_is_still_recorded(): void
    {
        $lines = [
            'Sep 15 07:26:22 localhost postfix/cleanup[1]: F1F2F3: info: header Subject: Something from Freegle from unknown[1.2.3.4]',
            'Sep 15 07:26:22 localhost postfix/qmgr[2]: F1F2F3: from=<noreply@ilovefreegle.org>, size=1, nrcpt=1 (queue active)',
        ];

        $parsed = $this->service()->parseLines($lines);

        $this->assertArrayHasKey('F1F2F3', $parsed['records']);
        $this->assertArrayNotHasKey('to', $parsed['records']['F1F2F3']);
    }

    public function test_connection_noise_is_ignored(): void
    {
        $lines = [
            'Sep 15 07:26:22 localhost postfix/smtpd[9]: connect from unknown[1.2.3.4]',
            'Sep 15 07:26:22 localhost postfix/smtpd[9]: disconnect from unknown[1.2.3.4] ehlo=1 quit=1',
            'Sep 15 07:26:22 localhost postfix/postfix-script[10]: refreshing the Postfix mail system',
        ];

        $parsed = $this->service()->parseLines($lines);

        $this->assertSame([], $parsed['records']);
    }

    /**
     * The relay is asked only for the bytes appended since last time. V1 read
     * the whole multi-gigabyte log every ten minutes on a 2GB box.
     */
    public function test_it_asks_the_relay_only_for_new_bytes(): void
    {
        config()->set('freegle.mail.relay_logs.enabled', true);
        config()->set('freegle.mail.relay_logs.host', 'logs@relay');
        config()->set('freegle.mail.relay_logs.path', '/var/log/mail.log');
        config()->set('freegle.mail.relay_logs.max_slice_bytes', 1024);
        // Somewhere to read FROM, so this exercises the incremental path rather
        // than the cold start below.
        Cache::forever('mail.relaylog.offset', 2048);

        $script = null;
        $service = $this->service("OFFSET 4096\n", function (string $target, string $sent) use (&$script) {
            $script = $sent;
        });

        $service->ingest();

        $this->assertNotNull($script);
        $this->assertStringContainsString('tail -c +', $script, 'must read from an offset, not the whole file');
        $this->assertStringContainsString('OFFSET', $script, 'must report where it got to');
        $this->assertStringContainsString('-lt "$OFF" ] && OFF=0', $script, 'must restart when the log is rotated');
    }

    /**
     * A first run wants to keep up from here, not to backfill.
     *
     * Asking for "everything up to the cap" with no offset drags the cap's worth
     * of log through ssh and into memory, to record deliveries that already
     * happened - the slowest possible request and the least useful one. In
     * production that request could not finish inside the ssh timeout at all,
     * so the ingest never started.
     */
    public function test_a_first_run_starts_at_the_end_of_the_log_and_ingests_nothing(): void
    {
        config()->set('freegle.mail.relay_logs.enabled', true);
        config()->set('freegle.mail.relay_logs.host', 'logs@relay');
        Cache::forget('mail.relaylog.offset');

        $scripts = [];
        $service = $this->service("SIZE 123456\n", function (string $target, string $sent) use (&$scripts) {
            $scripts[] = $sent;
        });

        $stats = $service->ingest();

        $this->assertSame(0, $stats['written'], 'a first run records nothing');
        $this->assertSame(0, $stats['lines']);
        $this->assertSame(123456, (int) Cache::get('mail.relaylog.offset'), 'it remembers the end of the log');

        $joined = implode("\n", $scripts);
        $this->assertStringContainsString('stat -c %s', $joined, 'asks only how long the log is');
        $this->assertStringNotContainsString('tail -c +', $joined, 'must not pull any of the log itself');
    }

    /** A relay that cannot answer the size must not leave a bogus offset. */
    public function test_a_first_run_that_cannot_reach_the_relay_records_no_offset(): void
    {
        config()->set('freegle.mail.relay_logs.enabled', true);
        config()->set('freegle.mail.relay_logs.host', 'logs@relay');
        Cache::forget('mail.relaylog.offset');

        $stats = $this->service(null)->ingest();

        $this->assertTrue($stats['failed']);
        $this->assertNull(Cache::get('mail.relaylog.offset'));
    }

    /**
     * A failed fetch must not move the offset, or the lines it could not read
     * would be skipped for ever rather than retried.
     */
    public function test_a_failed_fetch_does_not_lose_ground(): void
    {
        config()->set('freegle.mail.relay_logs.enabled', true);
        config()->set('freegle.mail.relay_logs.host', 'logs@relay');
        Cache::forever('mail.relaylog.offset', 2048);

        $stats = $this->service(null)->ingest();

        $this->assertTrue($stats['failed']);
        $this->assertSame(0, $stats['written']);
    }
}
