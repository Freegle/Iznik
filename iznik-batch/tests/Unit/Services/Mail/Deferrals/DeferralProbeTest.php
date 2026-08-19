<?php

namespace Tests\Unit\Services\Mail\Deferrals;

use App\Monitoring\HostCommandRunner;
use App\Services\Mail\Deferrals\DeferralProbe;
use Tests\TestCase;

/**
 * The probe is the only thing standing between us and flying blind, so these
 * tests feed it the shapes a real relay actually produces - including the
 * awkward ones - rather than an idealised sample.
 */
class DeferralProbeTest extends TestCase
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

    private function queueLine(array $overrides = []): string
    {
        return json_encode(array_merge([
            'queue_name' => 'deferred',
            'queue_id' => 'ABC123DEF',
            'arrival_time' => 1755270000,
            'message_size' => 4096,
            'sender' => 'noreply@ilovefreegle.org',
            'recipients' => [
                [
                    'address' => 'someone@yahoo.co.uk',
                    'delay_reason' => 'host mta7.am0.yahoodns.net[67.195.228.94] said: 421 4.7.0 '
                        . '[TSS04] Messages from 185.53.57.161 temporarily deferred due to '
                        . 'unexpected volume or user complaints (in reply to MAIL FROM command)',
                ],
            ],
        ], $overrides));
    }

    private function wrap(string $queue, string $delivered = '', bool $truncated = false): string
    {
        return DeferralProbe::MARK_QUEUE . "\n"
            . $queue . "\n"
            . ($truncated ? DeferralProbe::MARK_TRUNCATED . "\n" : '')
            . DeferralProbe::MARK_DELIVERED . "\n"
            . $delivered . "\n"
            . DeferralProbe::MARK_END . "\n";
    }

    public function test_returns_null_when_the_relay_is_unreachable(): void
    {
        $probe = new DeferralProbe($this->runner(null));

        $this->assertNull($probe->probe('relay@host', 1024));
    }

    public function test_refuses_output_that_never_reached_the_end_marker(): void
    {
        // Half a queue listing understates every count, and understated
        // counts silently fail to trip a threshold. Better to see nothing
        // than to see half and believe it.
        $probe = new DeferralProbe($this->runner(DeferralProbe::MARK_QUEUE . "\n" . $this->queueLine()));

        $this->assertNull($probe->probe('relay@host', 1024));
    }

    public function test_buckets_deferrals_by_relay_family(): void
    {
        $probe = new DeferralProbe($this->runner($this->wrap(
            $this->queueLine() . "\n" . $this->queueLine(['queue_id' => 'ZZZ999'])
        )));

        $snapshot = $probe->probe('relay@host', 1024);

        $this->assertSame(2, $snapshot->groups['yahoodns.net']['count']);
        $this->assertSame(1755270000, $snapshot->groups['yahoodns.net']['oldest']);
        $this->assertSame(2, $snapshot->addresses['someone@yahoo.co.uk']['count']);
    }

    public function test_records_the_recipient_domains_seen_behind_a_relay(): void
    {
        // This is what lets the sending-loop check be a plain indexed lookup
        // rather than a DNS query: the queue has already told us that
        // sky.com goes via Yahoo, which no amount of guessing would.
        $queue = implode("\n", [
            $this->queueLine(),
            $this->queueLine(['recipients' => [[
                'address' => 'someone@sky.com',
                'delay_reason' => 'host mta5.am0.yahoodns.net[1.2.3.4] said: 421 4.7.0 [TSS04] temporarily deferred',
            ]]]),
        ]);

        $snapshot = (new DeferralProbe($this->runner($this->wrap($queue))))->probe('relay@host', 1024);

        $this->assertEqualsCanonicalizing(
            ['yahoo.co.uk', 'sky.com'],
            array_keys($snapshot->groups['yahoodns.net']['domains'])
        );
    }

    public function test_ignores_recipients_that_have_not_been_attempted_yet(): void
    {
        // delay_reason is absent until Postfix has actually tried. A message
        // sitting in incoming is not evidence of anything.
        $queue = $this->queueLine(['recipients' => [['address' => 'nobody@example.com']]]);

        $snapshot = (new DeferralProbe($this->runner($this->wrap($queue))))->probe('relay@host', 1024);

        $this->assertSame([], $snapshot->groups);
        $this->assertSame([], $snapshot->addresses);
    }

    public function test_ignores_queues_that_are_not_deferred_or_active(): void
    {
        $queue = $this->queueLine(['queue_name' => 'hold']);

        $snapshot = (new DeferralProbe($this->runner($this->wrap($queue))))->probe('relay@host', 1024);

        $this->assertSame([], $snapshot->groups);
    }

    public function test_counts_a_deferral_that_blames_no_provider_separately(): void
    {
        $queue = $this->queueLine(['recipients' => [[
            'address' => 'someone@example.com',
            'delay_reason' => 'mail transport unavailable',
        ]]]);

        $snapshot = (new DeferralProbe($this->runner($this->wrap($queue))))->probe('relay@host', 1024);

        $this->assertSame([], $snapshot->groups);
        $this->assertSame(1, $snapshot->unattributed);
        // It is still a deferral for that address, just not a provider's fault.
        $this->assertSame(1, $snapshot->addresses['someone@example.com']['count']);
    }

    public function test_parses_delivered_lines_into_relay_families(): void
    {
        $delivered = implode("\n", [
            'Aug 18 09:00:01 relay postfix/smtp[1]: A1: to=<a@gmail.com>, relay=gmail-smtp-in.l.google.com[1.2.3.4]:25, delay=1, status=sent (250 ok)',
            'Aug 18 09:00:02 relay postfix/smtp[2]: A2: to=<b@gmail.com>, relay=gmail-smtp-in.l.google.com[1.2.3.4]:25, delay=1, status=sent (250 ok)',
            'Aug 18 09:00:03 relay postfix/local[3]: A3: to=<c@localhost>, relay=local, delay=0, status=sent (delivered to mailbox)',
        ]);

        $snapshot = (new DeferralProbe($this->runner($this->wrap($this->queueLine(), $delivered))))
            ->probe('relay@host', 1024);

        $this->assertSame(2, $snapshot->deliveriesFor('google.com'));
        // A local delivery is not a provider accepting our mail, so it must
        // not count as evidence that one has recovered.
        $this->assertSame(0, $snapshot->deliveriesFor('local'));
        $this->assertTrue($snapshot->hasDeliveryData());
    }

    public function test_distinguishes_no_deliveries_from_no_delivery_data(): void
    {
        // "Nothing is being delivered anywhere" is an estate-wide emergency.
        // "We could not read the relay's log" is a gap in our instruments.
        // Suppression decisions differ, so these must not look the same.
        $snapshot = (new DeferralProbe($this->runner($this->wrap($this->queueLine()))))
            ->probe('relay@host', 1024);

        $this->assertFalse($snapshot->hasDeliveryData());
        $this->assertSame(0, $snapshot->deliveriesFor('yahoodns.net'));
    }

    public function test_reports_truncation_rather_than_pretending_the_queue_was_complete(): void
    {
        $snapshot = (new DeferralProbe($this->runner($this->wrap($this->queueLine(), '', true))))
            ->probe('relay@host', 1024);

        $this->assertTrue($snapshot->truncated);
    }

    public function test_survives_a_half_written_json_line_from_truncation(): void
    {
        $queue = $this->queueLine() . "\n" . '{"queue_name":"deferred","recipi';

        $snapshot = (new DeferralProbe($this->runner($this->wrap($queue))))->probe('relay@host', 1024);

        $this->assertSame(1, $snapshot->groups['yahoodns.net']['count']);
        $this->assertSame(1, $snapshot->unparseableLines);
    }

    public function test_dedupes_queue_ids_for_purging(): void
    {
        // One queue file can hold several recipients in the same family, and
        // postsuper wants each id once.
        $queue = $this->queueLine(['recipients' => [
            ['address' => 'a@yahoo.co.uk', 'delay_reason' => 'host mta7.am0.yahoodns.net[1.2.3.4] said: 421 temporarily deferred'],
            ['address' => 'b@yahoo.com', 'delay_reason' => 'host mta7.am0.yahoodns.net[1.2.3.4] said: 421 temporarily deferred'],
        ]]);

        $snapshot = (new DeferralProbe($this->runner($this->wrap($queue))))->probe('relay@host', 1024);

        $this->assertSame(['ABC123DEF'], $snapshot->queueIdsFor('yahoodns.net'));
    }

    public function test_purge_refuses_anything_that_is_not_a_queue_id(): void
    {
        // The ids come from the relay's own listing, but they end up in a
        // shell command on a production host.
        $sent = [];
        $probe = new DeferralProbe($this->runner(
            'postsuper: Deleted: 1 message',
            function ($target, $script) use (&$sent) {
                $sent[] = $script;
            }
        ));

        $deleted = $probe->purge('relay@host', ['GOODID1234', 'rm -rf /', '; postsuper -d ALL']);

        $this->assertSame(1, $deleted);
        $this->assertStringContainsString('GOODID1234', $sent[0]);
        $this->assertStringNotContainsString('rm -rf', $sent[0]);
        $this->assertStringNotContainsString('ALL', $sent[0]);
    }

    // ===================================================================
    // A purge that cannot purge must say so
    //
    // 2026-08-19: postsuper is root-only and we connect as an unprivileged
    // user, so every chunk came back "fatal: use of this command is reserved
    // for the superuser". The old code counted the ids it SENT, so the command
    // reported purging 100,153 messages while deleting none, and the queue was
    // left to expire into a DSN per message.
    // ===================================================================

    public function test_purge_raises_when_the_relay_refuses_postsuper(): void
    {
        $probe = new DeferralProbe($this->runner(
            'postsuper: fatal: use of this command is reserved for the superuser'
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/reserved for the superuser/');

        $probe->purge('relay@host', ['GOODID1234']);
    }

    public function test_purge_raises_when_nothing_was_confirmed_deleted(): void
    {
        // Silence is not success: no "Deleted: N" line means the ids were not
        // recognised, and counting them would report a purge that never was.
        $probe = new DeferralProbe($this->runner(''));

        $this->expectException(\RuntimeException::class);

        $probe->purge('relay@host', ['GOODID1234']);
    }

    public function test_purge_counts_what_the_relay_confirmed_not_what_it_was_sent(): void
    {
        // Ids can go stale between listing and deletion - the message may have
        // been delivered or expired in between - so the confirmed count is
        // lower than the count sent, and that is the honest number.
        $probe = new DeferralProbe($this->runner('postsuper: Deleted: 2 messages'));

        $this->assertSame(2, $probe->purge('relay@host', ['AAAAAA1111', 'BBBBBB2222', 'CCCCCC3333']));
    }

    public function test_purge_does_nothing_when_given_nothing_usable(): void
    {
        $called = false;
        $probe = new DeferralProbe($this->runner('ok', function () use (&$called) {
            $called = true;
        }));

        $this->assertSame(0, $probe->purge('relay@host', ['../../etc/passwd']));
        $this->assertFalse($called, 'must not open a shell on the relay with nothing to do');
    }
}
