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

    /** Queue ids per relay family, for --purge. */
    public array $queueIds = [];

    /** Deferred recipients we could not attribute to any relay. */
    public int $unattributed = 0;

    /**
     * Deferrals that describe ONE full mailbox rather than anything about our
     * standing with the provider. Counted for visibility only.
     */
    public int $perMailbox = 0;

    /** Queue lines that were not valid JSON (expect 1 when truncated). */
    public int $unparseableLines = 0;

    /** Whether the relay's queue listing was cut short by the byte cap. */
    public bool $truncated = false;

    public function addDeferral(string $address, string $reason, ?int $arrivalTime, ?string $queueId): void
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
            $this->queueIds[$group][$queueId] = true;
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
    public function queueIdsFor(string $group): array
    {
        return array_keys($this->queueIds[$group] ?? []);
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
