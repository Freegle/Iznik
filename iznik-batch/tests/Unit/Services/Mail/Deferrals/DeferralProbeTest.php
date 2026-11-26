<?php

namespace Tests\Unit\Services\Mail\Deferrals;

use App\Monitoring\HostCommandRunner;
use App\Services\Mail\Deferrals\DeferralProbe;
use App\Services\Mail\Deferrals\RelayQueueSnapshot;
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

    private function wrap(string $queue, string $delivered = '', bool $truncated = false, ?int $windowSeconds = null): string
    {
        return DeferralProbe::MARK_QUEUE . "\n"
            . $queue . "\n"
            . ($truncated ? DeferralProbe::MARK_TRUNCATED . "\n" : '')
            . DeferralProbe::MARK_DELIVERED . "\n"
            . $delivered . "\n"
            // Before MARK_END, because the parser stops dead there.
            . ($windowSeconds !== null ? DeferralProbe::MARK_WINDOW . "\n" . $windowSeconds . "\n" : '')
            . DeferralProbe::MARK_END . "\n";
    }

    /**
     * Wrap queue lines that are attributed to a named postfix instance.
     *
     * @param  array<string, string>  $byInstance  config directory => queue lines
     */
    private function wrapInstances(array $byInstance, string $delivered = ''): string
    {
        $out = DeferralProbe::MARK_QUEUE."\n";
        foreach ($byInstance as $dir => $lines) {
            $out .= DeferralProbe::MARK_INSTANCE."\n".$dir."\n".$lines."\n";
        }

        return $out.DeferralProbe::MARK_DELIVERED."\n".$delivered."\n".DeferralProbe::MARK_END."\n";
    }

    /**
     * The failure this whole change exists to fix.
     *
     * A queue entry with no delay_reason is not a deferral - nothing has
     * refused it - and the probe used to drop it on the floor. On a relay that
     * paces a provider deliberately, that is where the entire backlog lives:
     * thousands of messages, hours old, with no error anywhere because none
     * has occurred. The delayed view could therefore report that every
     * provider was accepting our mail while a member's email ran half a day
     * late, and both statements were true.
     */
    public function test_queue_entries_nothing_has_refused_are_counted_as_waiting(): void
    {
        $waiting = $this->queueLine([
            'queue_name' => 'active',
            'queue_id' => 'WAIT00001',
            'arrival_time' => 1755270000,
            'recipients' => [['address' => 'someone@yahoo.com']],
        ]);

        $probe = new DeferralProbe($this->runner($this->wrap($waiting)));
        $snapshot = $probe->probe('relay@host', 65536);

        $this->assertSame(1, $snapshot->waiting['yahoo.com']['count']);
        $this->assertSame(1755270000, $snapshot->waiting['yahoo.com']['oldest']);

        // And emphatically NOT as a deferral: it must not count toward
        // suppressing a provider that has done nothing wrong.
        $this->assertSame([], $snapshot->groups);
        $this->assertSame([], $snapshot->addresses);
        $this->assertSame(0, $snapshot->unattributed);
    }

    /**
     * `incoming` is where a burst lands, and it is the easy one to leave out.
     * On 2026-09-13 it held 81,800 messages against 40,000 in active, so a
     * count that skipped it understated the backlog by two thirds - while
     * looking perfectly plausible.
     */
    public function test_the_incoming_queue_counts_as_waiting(): void
    {
        $probe = new DeferralProbe($this->runner($this->wrap($this->queueLine([
            'queue_name' => 'incoming',
            'recipients' => [['address' => 'someone@yahoo.com']],
        ]))));
        $snapshot = $probe->probe('relay@host', 65536);

        $this->assertSame(1, $snapshot->waiting['yahoo.com']['count']);
    }

    /**
     * Mail is only on hold because an operator put it there. That is a
     * different fact wanting a different conversation, and folding it into the
     * backlog would read as a delivery problem that does not exist.
     */
    public function test_mail_an_operator_has_held_is_not_counted_as_waiting(): void
    {
        $probe = new DeferralProbe($this->runner($this->wrap($this->queueLine([
            'queue_name' => 'hold',
            'recipients' => [['address' => 'someone@yahoo.com']],
        ]))));
        $snapshot = $probe->probe('relay@host', 65536);

        $this->assertSame([], $snapshot->waiting);
    }

    /**
     * Waiting is bucketed by recipient domain rather than by relay family,
     * because an entry that has never been attempted has no relay to name.
     */
    public function test_waiting_is_bucketed_by_domain_and_keeps_the_oldest_arrival(): void
    {
        $lines = implode("\n", [
            $this->queueLine([
                'queue_id' => 'W1',
                'arrival_time' => 1755280000,
                'recipients' => [['address' => 'a@yahoo.com']],
            ]),
            $this->queueLine([
                'queue_id' => 'W2',
                'arrival_time' => 1755270000,
                'recipients' => [['address' => 'b@yahoo.com']],
            ]),
            $this->queueLine([
                'queue_id' => 'W3',
                'arrival_time' => 1755290000,
                'recipients' => [['address' => 'c@gmail.com']],
            ]),
        ]);

        $probe = new DeferralProbe($this->runner($this->wrap($lines)));
        $snapshot = $probe->probe('relay@host', 65536);

        $this->assertSame(2, $snapshot->waiting['yahoo.com']['count']);
        $this->assertSame(1755270000, $snapshot->waiting['yahoo.com']['oldest'], 'oldest, not latest');
        $this->assertSame(1, $snapshot->waiting['gmail.com']['count']);
    }

    /**
     * Which instance holds it, because a relay that paces providers runs more
     * than one and an operator reaching for postqueue needs to know which.
     */
    public function test_waiting_records_the_instance_that_holds_it(): void
    {
        $probe = new DeferralProbe($this->runner($this->wrapInstances([
            '/etc/postfix' => $this->queueLine([
                'queue_id' => 'P1',
                'recipients' => [['address' => 'a@gmail.com']],
            ]),
            '/etc/postfix-warm' => $this->queueLine([
                'queue_id' => 'W1',
                'recipients' => [['address' => 'b@yahoo.com']],
            ]),
        ])));
        $snapshot = $probe->probe('relay@host', 65536);

        $this->assertSame('/etc/postfix', $snapshot->waiting['gmail.com']['instance']);
        $this->assertSame('/etc/postfix-warm', $snapshot->waiting['yahoo.com']['instance']);
    }

    /**
     * A depth with no drain rate cannot be acted on: 3,000 queued is fine at
     * 3,000/hour and a two-day outage at 60. The relay family rate is not a
     * substitute, because waiting is counted per domain and an unattempted
     * message names no family.
     */
    public function test_deliveries_are_counted_by_recipient_domain_as_well_as_relay(): void
    {
        $delivered = implode("\n", [
            'Sep 15 19:39:09 h postfix-warm1/smtp[1]: A1: to=<one@yahoo.com>, '
                . 'relay=mta7.am0.yahoodns.net[67.195.204.79]:25, delay=1.9, status=sent (250 ok)',
            'Sep 15 19:39:10 h postfix-warm1/smtp[2]: A2: to=<two@yahoo.com>, '
                . 'relay=mta7.am0.yahoodns.net[67.195.204.79]:25, delay=1.9, status=sent (250 ok)',
            'Sep 15 19:39:11 h postfix/smtp[3]: A3: to=<three@gmail.com>, '
                . 'relay=alt1.gmail-smtp-in.l.google.com[142.250.102.26]:25, delay=1.1, status=sent (250 ok)',
        ]);

        $probe = new DeferralProbe($this->runner($this->wrap($this->queueLine(), $delivered)));
        $snapshot = $probe->probe('relay@host', 65536);

        $this->assertSame(2, $snapshot->deliveriesForDomain('yahoo.com'));
        $this->assertSame(1, $snapshot->deliveriesForDomain('gmail.com'));
        $this->assertSame(0, $snapshot->deliveriesForDomain('never-seen.com'));
    }

    /**
     * The hop between the relay's two postfix instances logs exactly like a
     * delivery - the real recipient, and status=sent (250 ...) - and there is
     * one per message. Counted, it roughly doubles the apparent send rate for
     * every provider we pace, which is the number the delayed view divides the
     * backlog by. In one sample window it was 440 hops against about 1,000
     * real deliveries for a single domain.
     */
    public function test_the_loopback_hop_between_instances_is_not_a_delivery(): void
    {
        config([
            'freegle.mail.relay_logs.handover_port' => 10026,
            'freegle.mail.relay_logs.handover_transport' => 'relaywarm',
        ]);

        $delivered = implode("\n", [
            'Sep 15 21:22:43 h postfix-relaywarm/smtp[1]: C8F: to=<one@yahoo.com>, '
                . 'relay=127.0.0.1[127.0.0.1]:10026, delay=0.1, dsn=2.0.0, status=sent (250 ok)',
            'Sep 15 21:22:44 h postfix-warm1yahoodnsnet/smtp[2]: D9A: to=<one@yahoo.com>, '
                . 'relay=mta5.am0.yahoodns.net[67.195.228.94]:25, delay=1.9, status=sent (250 ok)',
        ]);

        $probe = new DeferralProbe($this->runner($this->wrap($this->queueLine(), $delivered)));
        $snapshot = $probe->probe('relay@host', 65536);

        $this->assertSame(1, $snapshot->deliveriesForDomain('yahoo.com'), 'the hop and the delivery are one message');
    }

    /**
     * Either signal alone is enough, so a renamed transport or a changed port
     * cannot quietly turn hops back into deliveries.
     */
    public function test_either_handover_signal_alone_excludes_the_line(): void
    {
        config([
            'freegle.mail.relay_logs.handover_port' => 10026,
            'freegle.mail.relay_logs.handover_transport' => 'relaywarm',
        ]);

        $delivered = implode("\n", [
            // Right transport, some other port.
            'Sep 15 21:22:43 h postfix-relaywarm/smtp[1]: A: to=<a@yahoo.com>, '
                . 'relay=127.0.0.1[127.0.0.1]:10027, status=sent (250 ok)',
            // Right port, some other transport name.
            'Sep 15 21:22:43 h postfix-renamed/smtp[2]: B: to=<b@yahoo.com>, '
                . 'relay=127.0.0.1[127.0.0.1]:10026, status=sent (250 ok)',
        ]);

        $probe = new DeferralProbe($this->runner($this->wrap($this->queueLine(), $delivered)));
        $snapshot = $probe->probe('relay@host', 65536);

        $this->assertSame(0, $snapshot->deliveriesForDomain('yahoo.com'));
    }

    /**
     * `tail -n` takes a number of LINES, and how much time those cover depends
     * on how busy the relay is. Measured live, a 200,000 line sample spanned
     * 2h15m, so every rate taken from it was more than double the truth - and
     * the rate is what a backlog is divided by to say when it clears.
     */
    public function test_delivery_counts_are_scaled_to_the_window_the_relay_reports(): void
    {
        $delivered = implode("\n", array_fill(0, 10, 'Sep 15 21:22:44 h postfix/smtp[2]: D: to=<a@yahoo.com>, '
            . 'relay=mta5.am0.yahoodns.net[67.195.228.94]:25, status=sent (250 ok)'));

        // Ten deliveries over two hours is five an hour, not ten.
        $probe = new DeferralProbe($this->runner(
            $this->wrap($this->queueLine(), $delivered, false, 7200)
        ));
        $snapshot = $probe->probe('relay@host', 65536);

        $this->assertSame(10, $snapshot->deliveriesForDomain('yahoo.com'), 'the raw count is unscaled');
        $this->assertSame(5, $snapshot->deliveriesPerHourForDomain('yahoo.com'));
    }

    /**
     * When the relay cannot tell us how long the sample covers we fall back to
     * treating it as an hour, which is what everything did before it was
     * measured.
     */
    public function test_an_unknown_window_leaves_the_count_alone(): void
    {
        $delivered = 'Sep 15 21:22:44 h postfix/smtp[2]: D: to=<a@yahoo.com>, '
            . 'relay=mta5.am0.yahoodns.net[67.195.228.94]:25, status=sent (250 ok)';

        $probe = new DeferralProbe($this->runner($this->wrap($this->queueLine(), $delivered)));
        $snapshot = $probe->probe('relay@host', 65536);

        $this->assertNull($snapshot->windowSeconds);
        $this->assertSame(1, $snapshot->deliveriesPerHourForDomain('yahoo.com'));
    }

    /**
     * Fail closed. A relay with two instances writes one hop line per message
     * to every paced provider, so excluding none of them means the pattern has
     * stopped matching - not that the hops stopped. Every rate is then
     * inflated by hops counted as deliveries, and an inflated rate makes a
     * backlog look like it is clearing when it is not.
     */
    public function test_no_rate_is_given_when_the_handover_pattern_matches_nothing(): void
    {
        config([
            'freegle.mail.relay_logs.handover_port' => 10026,
            'freegle.mail.relay_logs.handover_transport' => 'relaywarm',
        ]);

        // Two instances, and a delivery sample with no hop line in it at all.
        $delivered = 'Sep 15 21:22:44 h postfix/smtp[2]: D: to=<a@yahoo.com>, '
            . 'relay=mta5.am0.yahoodns.net[67.195.228.94]:25, status=sent (250 ok)';

        $probe = new DeferralProbe($this->runner($this->wrapInstances([
            '/etc/postfix' => $this->queueLine(),
            '/etc/postfix-warm' => $this->queueLine(['queue_id' => 'W1']),
        ], $delivered)));
        $snapshot = $probe->probe('relay@host', 65536);

        $this->assertFalse($snapshot->handoverExclusionLooksAlive());
        $this->assertSame(0, $snapshot->deliveriesPerHourForDomain('yahoo.com'), 'refuse rather than reassure');

        // The raw count is untouched, so the suppression side is unaffected.
        $this->assertSame(1, $snapshot->deliveriesForDomain('yahoo.com'));
    }

    /**
     * A relay with one instance has no hop to exclude, so the absence of one
     * must not gag it.
     */
    public function test_a_single_instance_relay_still_reports_a_rate(): void
    {
        $delivered = 'Sep 15 21:22:44 h postfix/smtp[2]: D: to=<a@yahoo.com>, '
            . 'relay=mta5.am0.yahoodns.net[67.195.228.94]:25, status=sent (250 ok)';

        $probe = new DeferralProbe($this->runner($this->wrap($this->queueLine(), $delivered)));
        $snapshot = $probe->probe('relay@host', 65536);

        $this->assertTrue($snapshot->handoverExclusionLooksAlive());
        $this->assertSame(1, $snapshot->deliveriesPerHourForDomain('yahoo.com'));
    }

    /**
     * The relay runs more than one postfix instance, and the second owns
     * delivery to exactly the providers this scan exists to watch. A bare
     * `postqueue -j` returns only the default instance, so the scan would
     * report a few deferrals for everyone else while tens of thousands of
     * messages to a blocked provider sat unseen in the other queue - and
     * nothing would ever suppress.
     */
    public function test_the_probe_asks_every_postfix_instance_for_its_queue(): void
    {
        $script = null;
        $probe = new DeferralProbe($this->runner(
            $this->wrap($this->queueLine()),
            function (string $target, string $sent) use (&$script) {
                $script = $sent;
            }
        ));
        $probe->probe('relay@host', 65536);

        $this->assertNotNull($script);
        $this->assertStringContainsString('postmulti -l', $script, 'must enumerate the instances');
        $this->assertStringContainsString('postqueue -c "$D" -j', $script, 'must read each instance by name');
        $this->assertStringContainsString('config_directory', $script, 'must still work where postmulti is absent');
    }

    /**
     * A queue id is unique only WITHIN an instance, so the snapshot has to
     * remember which one each came from. Purging an id against the wrong
     * instance either deletes nothing or deletes a different message.
     */
    public function test_queue_ids_are_attributed_to_the_instance_holding_them(): void
    {
        $probe = new DeferralProbe($this->runner($this->wrapInstances([
            '/etc/postfix' => $this->queueLine(['queue_id' => 'AAAAAA111']),
            '/etc/postfix-warm' => $this->queueLine(['queue_id' => 'BBBBBB222']),
        ])));

        $snapshot = $probe->probe('relay@host', 65536);
        $this->assertNotNull($snapshot);

        $groups = array_keys($snapshot->queueIds);
        $this->assertNotEmpty($groups, 'both instances should contribute deferrals');

        $ids = $snapshot->queueIdsFor($groups[0]);
        $this->assertArrayHasKey('/etc/postfix', $ids);
        $this->assertArrayHasKey('/etc/postfix-warm', $ids);
        $this->assertSame(['AAAAAA111'], $ids['/etc/postfix']);
        $this->assertSame(['BBBBBB222'], $ids['/etc/postfix-warm']);
    }

    /** A host with one instance names nothing, and must still be purgeable. */
    public function test_an_unnamed_instance_falls_back_to_the_default(): void
    {
        $probe = new DeferralProbe($this->runner($this->wrap($this->queueLine(['queue_id' => 'CCCCCC333']))));

        $snapshot = $probe->probe('relay@host', 65536);
        $this->assertNotNull($snapshot);

        $groups = array_keys($snapshot->queueIds);
        $ids = $snapshot->queueIdsFor($groups[0]);
        $this->assertSame(['CCCCCC333'], $ids[RelayQueueSnapshot::DEFAULT_INSTANCE] ?? null);
    }

    /** postsuper must be told which instance, for the same reason. */
    public function test_purge_runs_postsuper_against_each_instance(): void
    {
        $scripts = [];
        $probe = new DeferralProbe($this->runner(
            'postsuper: Deleted: 1 message',
            function (string $target, string $sent) use (&$scripts) {
                $scripts[] = $sent;
            }
        ));

        $probe->purge('relay@host', [
            '/etc/postfix' => ['AAAAAA111'],
            '/etc/postfix-warm' => ['BBBBBB222'],
        ]);

        $joined = implode("\n", $scripts);
        $this->assertStringContainsString("postsuper -c '/etc/postfix' -d -", $joined);
        $this->assertStringContainsString("postsuper -c '/etc/postfix-warm' -d -", $joined);
    }

    /** The instance path reaches a shell, so it is checked like the ids are. */
    public function test_purge_refuses_an_implausible_instance_path(): void
    {
        $called = false;
        $probe = new DeferralProbe($this->runner('x', function () use (&$called) {
            $called = true;
        }));

        $this->assertSame(0, $probe->purge('relay@host', ['; rm -rf /' => ['AAAAAA111']]));
        $this->assertFalse($called, 'nothing should reach the relay');
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

        $this->assertSame(
            [RelayQueueSnapshot::DEFAULT_INSTANCE => ['ABC123DEF']],
            $snapshot->queueIdsFor('yahoodns.net')
        );
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

        $deleted = $probe->purge('relay@host', ['/etc/postfix' => ['GOODID1234', 'rm -rf /', '; postsuper -d ALL']]);

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

        $probe->purge('relay@host', ['/etc/postfix' => ['GOODID1234']]);
    }

    public function test_purge_raises_when_nothing_was_confirmed_deleted(): void
    {
        // Silence is not success: no "Deleted: N" line means the ids were not
        // recognised, and counting them would report a purge that never was.
        $probe = new DeferralProbe($this->runner(''));

        $this->expectException(\RuntimeException::class);

        $probe->purge('relay@host', ['/etc/postfix' => ['GOODID1234']]);
    }

    public function test_purge_counts_what_the_relay_confirmed_not_what_it_was_sent(): void
    {
        // Ids can go stale between listing and deletion - the message may have
        // been delivered or expired in between - so the confirmed count is
        // lower than the count sent, and that is the honest number.
        $probe = new DeferralProbe($this->runner('postsuper: Deleted: 2 messages'));

        $this->assertSame(2, $probe->purge('relay@host', ['/etc/postfix' => ['AAAAAA1111', 'BBBBBB2222', 'CCCCCC3333']]));
    }

    // ===================================================================
    // Can we purge at all?
    //
    // postsuper is root-only and we connect unprivileged. Without a sudoers
    // grant every chunk comes back "fatal: use of this command is reserved for
    // the superuser" and the only other symptom is a queue that never shrinks.
    // ===================================================================

    public function test_knows_when_the_relay_can_purge(): void
    {
        $probe = new DeferralProbe($this->runner(
            DeferralProbe::MARK_CANPURGE . "\nCANPURGE sudo\n"
        ));

        $this->assertTrue($probe->canPurge('relay@host'));
    }

    public function test_knows_when_the_relay_has_not_been_granted_the_right(): void
    {
        $probe = new DeferralProbe($this->runner(
            DeferralProbe::MARK_CANPURGE . "\nNOPURGE deferrals\n"
        ));

        $this->assertFalse($probe->canPurge('relay@host'));
    }

    public function test_an_unreachable_relay_cannot_answer_whether_it_can_purge(): void
    {
        // Not false: "we could not ask" and "you may not" are different, and
        // treating the first as the second would cry wolf on every blip.
        $this->assertNull((new DeferralProbe($this->runner(null)))->canPurge('relay@host'));
    }

    // ===================================================================
    // Asking the provider directly
    //
    // The section marker must be ECHOED. A bare marker line is run as a
    // command, so the section never reaches stdout and every probe reads as
    // "could not tell" - which fails safe, and therefore silently. Production
    // did exactly that at 08:01 on 2026-08-19 and the only trace was a warning.
    // ===================================================================

    public function test_reads_a_provider_that_is_accepting_again(): void
    {
        $probe = new DeferralProbe($this->runner(
            DeferralProbe::MARK_ACCEPTING . "\nACCEPTING gmail-smtp-in.l.google.com 250\n"
        ));

        $this->assertTrue($probe->providerAccepting('relay@host', 'gmail.com', 'noreply@example.com'));
    }

    public function test_reads_a_provider_that_is_still_refusing(): void
    {
        $probe = new DeferralProbe($this->runner(
            DeferralProbe::MARK_ACCEPTING . "\nREFUSING mx-eu.mail.am0.yahoodns.net 421 "
            . "4.7.0 [TSS04] Messages from 185.53.57.161 temporarily deferred\n"
        ));

        $this->assertFalse($probe->providerAccepting('relay@host', 'yahoo.co.uk', 'noreply@example.com'));
    }

    public function test_a_probe_that_could_not_run_is_unknown_not_a_verdict(): void
    {
        // No marker: the script did not run. Reading that as either answer
        // would let a broken probe strand a provider or reopen a blocked one.
        $probe = new DeferralProbe($this->runner('bash: line 1: command not found'));

        $this->assertNull($probe->providerAccepting('relay@host', 'yahoo.co.uk', 'noreply@example.com'));
    }

    public function test_an_unreachable_relay_is_unknown(): void
    {
        $probe = new DeferralProbe($this->runner(null));

        $this->assertNull($probe->providerAccepting('relay@host', 'yahoo.co.uk', 'noreply@example.com'));
    }

    public function test_asks_from_the_address_postfix_would_send_from(): void
    {
        // The relay routes a throttled provider to a warm address through
        // transport_maps. An unbound probe leaves from the default address -
        // the blocked one - and answered "still refusing" for a provider that
        // was being delivered from another address, which held a suppression
        // over 10,000 members for 33 hours (2026-09-02). The script must
        // resolve the domain through the relay's maps and bind that address.
        $script = null;
        $probe = new DeferralProbe($this->runner(
            DeferralProbe::MARK_ACCEPTING . "\nACCEPTING mta5.am0.yahoodns.net 250 from 77.72.7.253 via warm1\n",
            function (string $target, string $sent) use (&$script) {
                $script = $sent;
            }
        ));

        $this->assertTrue($probe->providerAccepting('relay@host', 'yahoo.co.uk', 'noreply@example.com'));
        $this->assertNotNull($script);
        $this->assertStringContainsString('-h transport_maps', $script, 'must walk the relay\'s transport maps');
        $this->assertStringContainsString('postmap -q "$DOMAIN"', $script, 'must resolve the probed domain, not a guess');
        $this->assertStringContainsString('smtp_bind_address', $script, 'must read the transport\'s bind address');
        $this->assertStringContainsString('source_address=(bind, 0)', $script, 'must bind the socket to that address');
        $this->assertStringContainsString("DOMAIN='yahoo.co.uk'", $script);
    }

    /**
     * Not a string match: this RUNS the resolution the probe emits, against
     * stub postfix tools, and checks which address comes out.
     *
     * A throttled provider is handed to a second postfix instance over a
     * loopback hop, so the primary resolves it to a relay transport that has no
     * bind address of its own. Stopping there falls through to the global
     * default - 185.53.57.161, the address the provider blocked - and a refusal
     * from it cancels the 24h fail-open, so the suppression can never lift.
     * That is the 2026-09-02 failure, and the only thing standing between us
     * and it is that this resolution keeps walking into the next instance.
     */
    public function test_resolution_crosses_into_the_second_postfix_instance(): void
    {
        $script = null;
        $probe = new DeferralProbe($this->runner(
            DeferralProbe::MARK_ACCEPTING . "\nACCEPTING mta5.am0.yahoodns.net 250 from 77.72.7.253 via warm1yahoodnsnet\n",
            function (string $target, string $sent) use (&$script) {
                $script = $sent;
            }
        ));
        $probe->providerAccepting('relay@host', 'yahoo.co.uk', 'noreply@example.com');
        $this->assertNotNull($script);

        $bin = sys_get_temp_dir() . '/probe-stub-' . getmypid();
        @mkdir($bin, 0700, true);

        // The primary knows only that the domain goes to the relay transport,
        // and that transport has no smtp_bind_address. The warm instance holds
        // the pair that actually faces the provider.
        file_put_contents("$bin/postmulti", <<<'STUB'
#!/bin/bash
[ "$1" = "-l" ] && { echo "-            -     y  /etc/postfix"; echo "postfix-warm warm  y  /etc/postfix-warm"; }
STUB);
        file_put_contents("$bin/postconf", <<<'STUB'
#!/bin/bash
cd=/etc/postfix; a=("$@")
for ((i=0;i<${#a[@]};i++)); do [ "${a[$i]}" = "-c" ] && cd="${a[$((i+1))]}"; done
case "$*" in
  *"-h transport_maps"*)
    if [ "$cd" = "/etc/postfix" ]; then echo "texthash:/etc/postfix/warmup_transport"
    else echo "texthash:/etc/postfix-warm/warmup_transport"; fi ;;
  *"-Mf relaywarm/unix"*)
    echo "relaywarm unix - - n - 20 smtp"; echo "    -o syslog_name=postfix-relaywarm" ;;
  *"-Mf warm1yahoodnsnet/unix"*)
    echo "warm1yahoodnsnet unix - - n - 4 smtp"
    echo "    -o syslog_name=postfix-warm1yahoodnsnet"
    echo "    -o smtp_bind_address=77.72.7.253" ;;
  *"-h smtp_bind_address"*) echo "185.53.57.161" ;;
  *"-h config_directory"*) echo "/etc/postfix" ;;
esac
STUB);
        file_put_contents("$bin/postmap", <<<'STUB'
#!/bin/bash
case "$*" in
  *"/etc/postfix/warmup_transport"*) echo "relaywarm:[127.0.0.1]:10026" ;;
  *"/etc/postfix-warm/warmup_transport"*) echo "warm1yahoodnsnet:" ;;
esac
STUB);
        foreach (['postmulti', 'postconf', 'postmap'] as $f) {
            chmod("$bin/$f", 0700);
        }

        // Everything the probe emits before it hands over to python is the
        // resolution; run exactly that, then report what it decided.
        $resolution = substr($script, 0, strpos($script, 'python3 - '));
        $out = shell_exec('PATH=' . escapeshellarg($bin) . ':$PATH bash -c '
            . escapeshellarg($resolution . "\necho \"BIND=\$BIND TR=\$TR\"") . ' 2>&1');

        array_map('unlink', glob("$bin/*"));
        @rmdir($bin);

        $this->assertStringContainsString('BIND=77.72.7.253', (string) $out,
            'must bind the warm instance address that actually faces the provider');
        $this->assertStringContainsString('TR=warm1yahoodnsnet', (string) $out,
            'must report the pair transport, not the loopback relay');
        $this->assertStringNotContainsString('BIND=185.53.57.161', (string) $out,
            'falling through to the blocked default is the 2026-09-02 bug');
    }

    public function test_refuses_to_put_a_bogus_domain_in_a_shell(): void
    {
        $called = false;
        $probe = new DeferralProbe($this->runner('x', function () use (&$called) {
            $called = true;
        }));

        $this->assertNull($probe->providerAccepting('relay@host', 'yahoo.co.uk; rm -rf /', 'noreply@example.com'));
        $this->assertFalse($called, 'must not open a shell on the relay with a domain it rejected');
    }

    public function test_purge_does_nothing_when_given_nothing_usable(): void
    {
        $called = false;
        $probe = new DeferralProbe($this->runner('ok', function () use (&$called) {
            $called = true;
        }));

        $this->assertSame(0, $probe->purge('relay@host', ['/etc/postfix' => ['../../etc/passwd']]));
        $this->assertFalse($called, 'must not open a shell on the relay with nothing to do');
    }
}
