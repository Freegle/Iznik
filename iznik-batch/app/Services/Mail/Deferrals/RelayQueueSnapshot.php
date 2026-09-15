<?php

namespace App\Services\Mail\Deferrals;

/**
 * What one probe of the relay saw.
 *
 * A value object rather than a pile of arrays because the scan makes several
 * decisions off the same numbers and it should be obvious which is which:
 * deferrals are bucketed both by relay family (our reputation with a
 * provider) and by individual address (one person's full mailbox), and those
 * two signals want very different thresholds.
 */
class RelayQueueSnapshot
{
    /**
     * Relay family => [count, oldest arrival unix ts, sample reason,
     *                  domains => [domain => count]]
     *
     * @var array<string, array{count:int, oldest:?int, reason:string, domains:array<string,int>}>
     */
    public array $groups = [];

    /**
     * Address => [count, oldest arrival unix ts, sample reason]
     *
     * @var array<string, array{count:int, oldest:?int, reason:string}>
     */
    public array $addresses = [];

    /** Relay family => deliveries seen in the log window. */
    public array $delivered = [];

    /**
     * Recipient domain => [count, oldest arrival unix ts, instance] for mail
     * that is QUEUED BUT NOT REFUSED.
     *
     * A provider refusing us leaves a delay_reason on the queue entry and is
     * counted above. Mail we are deliberately pacing ourselves - a warmed
     * sending address on a rate delay, or one that has spent its day
     * allowance - leaves no reason at all: nothing has gone wrong, the
     * message is simply waiting its turn. To a member the two are the same
     * email arriving hours late, so both have to be visible; only the
     * remedies differ, and they are opposites.
     *
     * @var array<string, array{count:int, oldest:?int, instance:?string}>
     */
    public array $waiting = [];

    /** Recipient domain => deliveries seen in the log window. */
    public array $deliveredByDomain = [];

    /**
     * How many seconds of log the delivery sample above actually covers.
     *
     * Null when the relay could not tell us, in which case a caller wanting a
     * rate should treat the sample as an hour - which is what everything did
     * before this was measured, and was wrong by whatever factor the relay's
     * traffic happened to make it. On the live relay a 200,000 line sample
     * covered 2h15m, so every "per hour" number taken from it was more than
     * double the truth.
     */
    public ?int $windowSeconds = null;

    /** Config directories the relay reported, one per postfix instance. */
    public array $instancesSeen = [];

    /** Log lines excluded as the loopback hop between instances. */
    public int $handoversSeen = 0;

    /** Queue ids per relay family, for --purge. */
    public array $queueIds = [];

    /** Deferred recipients we could not attribute to any relay. */
    public int $unattributed = 0;

    /**
     * Deferrals that describe ONE full mailbox rather than anything about our
     * standing with the provider. Counted for visibility only.
     */
    public int $perMailbox = 0;

    /** Used when the relay did not name an instance (a single-instance host). */
    public const DEFAULT_INSTANCE = '/etc/postfix';

    /** Queue lines that were not valid JSON (expect 1 when truncated). */
    public int $unparseableLines = 0;

    /** Whether the relay's queue listing was cut short by the byte cap. */
    public bool $truncated = false;

    public function addDeferral(string $address, string $reason, ?int $arrivalTime, ?string $queueId, ?string $instance = null): void
    {
        $address = strtolower(trim($address));
        if ($address === '') {
            return;
        }

        if (! isset($this->addresses[$address])) {
            $this->addresses[$address] = ['count' => 0, 'oldest' => null, 'reason' => $reason];
        }
        $this->addresses[$address]['count']++;
        $this->addresses[$address]['oldest'] = $this->earliest($this->addresses[$address]['oldest'], $arrivalTime);

        if (self::isPerMailbox($reason)) {
            // One person's mailbox is full. That says nothing about whether
            // the provider is accepting our mail, so it must not count toward
            // the relay family - the address bucket above is the whole of its
            // meaning.
            //
            // This is not hypothetical. On 2026-08-19 Gmail's family carried
            // 2,996 "4.2.2" and 2,252 "452" deferrals against 8 real "421"s;
            // counted together they cleared the 500 threshold and gmail.com
            // was suppressed, declining 76,684 messages to 482 members in
            // two and a half hours while Gmail was delivering normally.
            $this->perMailbox++;

            return;
        }

        $group = MxGrouper::fromDelayReason($reason);
        if ($group === null || $group === '') {
            // A local-only failure ("mail transport unavailable") blames no
            // provider. Counting it against one would suppress the wrong mail.
            $this->unattributed++;

            return;
        }

        if (! isset($this->groups[$group])) {
            $this->groups[$group] = ['count' => 0, 'oldest' => null, 'reason' => $reason, 'domains' => []];
        }
        $this->groups[$group]['count']++;
        $this->groups[$group]['oldest'] = $this->earliest($this->groups[$group]['oldest'], $arrivalTime);

        $domain = $this->domainOf($address);
        if ($domain !== null) {
            $this->groups[$group]['domains'][$domain] = ($this->groups[$group]['domains'][$domain] ?? 0) + 1;
        }

        if ($queueId !== null && $queueId !== '') {
            // Deduplicated: one queue file can hold many recipients in the
            // same family, and purging wants each id once.
            //
            // Keyed by INSTANCE as well, because a queue id is only unique
            // within one postfix instance. Purging an id against the wrong
            // instance either deletes nothing or, if the id happens to exist
            // there too, deletes a different message.
            $this->queueIds[$group][$instance ?? self::DEFAULT_INSTANCE][$queueId] = true;
        }
    }

    /**
     * Whether a delay reason describes one recipient's mailbox rather than the
     * provider's treatment of us.
     *
     * 4.2.2 is RFC 3463's "mailbox full" and is unambiguous. The wording
     * variants are matched too because providers phrase it freely and some
     * send only the 452 with prose. Note what is deliberately NOT here:
     * 4.3.1 "insufficient system storage" is the receiving SERVER running out,
     * which is provider-level and should count.
     */
    public static function isPerMailbox(string $reason): bool
    {
        foreach ([
            '/\b4\.2\.2\b/',
            '/over[- ]?quota/i',
            '/quota exceeded/i',
            '/mailbox (is )?full/i',
            '/out of storage/i',
            '/not enough storage space/i',
        ] as $pattern) {
            if (preg_match($pattern, $reason)) {
                return true;
            }
        }

        return false;
    }

    public function addDelivery(string $group): void
    {
        if ($group === '') {
            return;
        }

        $this->delivered[$group] = ($this->delivered[$group] ?? 0) + 1;
    }

    public function deliveriesFor(string $group): int
    {
        return (int) ($this->delivered[$group] ?? 0);
    }

    /**
     * One queue entry that no provider has refused - it is waiting on us.
     *
     * Bucketed by recipient DOMAIN, not by relay family: an entry that has
     * never been attempted has no relay to name, and the domain is what
     * support looks a member up by anyway.
     */
    public function addWaiting(string $address, ?int $arrivalTime, ?string $instance = null): void
    {
        $domain = $this->domainOf(strtolower(trim($address)));
        if ($domain === null) {
            return;
        }

        if (! isset($this->waiting[$domain])) {
            $this->waiting[$domain] = ['count' => 0, 'oldest' => null, 'instance' => $instance];
        }
        $this->waiting[$domain]['count']++;
        $this->waiting[$domain]['oldest'] = $this->earliest($this->waiting[$domain]['oldest'], $arrivalTime);
    }

    public function addDomainDelivery(string $domain): void
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return;
        }

        $this->deliveredByDomain[$domain] = ($this->deliveredByDomain[$domain] ?? 0) + 1;
    }

    public function deliveriesForDomain(string $domain): int
    {
        return (int) ($this->deliveredByDomain[strtolower(trim($domain))] ?? 0);
    }

    /**
     * Deliveries to a domain scaled to an hour.
     *
     * An AVERAGE over the probe's whole sample, which is a couple of hours, not
     * the rate right now. That is usually what you want - a queue taking hours
     * to clear should be divided by an hours-long average, not by a momentary
     * one - but it lags a step change. When a sending address spends its daily
     * allowance and drops to a trickle, this reads high until the sample has
     * moved past the fast part.
     *
     * Deliberately a separate method from deliveriesFor(), which the
     * suppression decision uses. Scaling that one would change when a provider
     * gets suppressed - a real change to whether mail is generated - and this
     * is a reporting number. The two should be reconciled deliberately, not as
     * a side effect of making a page read correctly.
     */
    public function deliveriesPerHourForDomain(string $domain): int
    {
        if (! $this->handoverExclusionLooksAlive()) {
            return 0;
        }

        $count = $this->deliveriesForDomain($domain);

        if ($count === 0 || $this->windowSeconds === null || $this->windowSeconds <= 0) {
            return $count;
        }

        return (int) round($count * 3600 / $this->windowSeconds);
    }

    /**
     * Is the loopback-hop exclusion still matching anything?
     *
     * A relay with a second postfix instance sends one hop line per message to
     * every paced provider, so seeing none of them means the pattern has
     * stopped matching - a renamed transport, a changed port - not that the
     * hop has stopped happening. At that point every rate is inflated by the
     * hops now being counted as deliveries, and an inflated rate makes a
     * backlog look like it is clearing when it is not.
     *
     * So refuse to give a rate at all rather than give a reassuring one. The
     * view renders that as "not draining", which is the pessimistic reading
     * and the one that gets looked at.
     */
    public function handoverExclusionLooksAlive(): bool
    {
        return count($this->instancesSeen) < 2 || $this->handoversSeen > 0;
    }

    /**
     * Whether the probe brought back any delivery data at all.
     *
     * Distinguishes "nothing is being delivered anywhere", which would be an
     * estate-wide emergency, from "we could not read the relay's log", which
     * is a gap in our instrumentation. The release check must not treat the
     * second as the first.
     */
    public function hasDeliveryData(): bool
    {
        return $this->delivered !== [];
    }

    /** @return string[] */
    /**
     * Queue ids for a group, grouped by the postfix instance holding them.
     *
     * @return array<string, string[]> config directory => queue ids
     */
    public function queueIdsFor(string $group): array
    {
        $out = [];
        foreach ($this->queueIds[$group] ?? [] as $instance => $ids) {
            $out[$instance] = array_keys($ids);
        }

        return $out;
    }

    private function domainOf(string $address): ?string
    {
        $at = strrpos($address, '@');
        if ($at === false || $at === strlen($address) - 1) {
            return null;
        }

        return substr($address, $at + 1);
    }

    private function earliest(?int $current, ?int $candidate): ?int
    {
        if ($candidate === null || $candidate <= 0) {
            return $current;
        }

        return $current === null ? $candidate : min($current, $candidate);
    }
}
