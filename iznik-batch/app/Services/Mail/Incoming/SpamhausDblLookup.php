<?php

namespace App\Services\Mail\Incoming;

/**
 * Asks the Spamhaus Domain Block List whether a domain is listed. The question
 * is a DNS query for <host>.dbl.spamhaus.org: an answer with an A record means
 * the domain is on the list, no answer means it is not.
 *
 * SpamCheckService resolves this from the container for every lookup, so the
 * test suite can bind one built with a list of hosts and never touch the
 * network. A real lookup from a test takes hundreds of milliseconds through
 * the container's resolver, and its answer depends on the network rather than
 * on the code under test.
 */
class SpamhausDblLookup
{
    /**
     * @param  list<string>|null  $listed  Hosts to report as listed, instead of asking DNS.
     */
    public function __construct(private readonly ?array $listed = null)
    {
    }

    public function isListed(string $host): bool
    {
        if ($this->listed !== null) {
            return in_array(strtolower($host), array_map('strtolower', $this->listed), true);
        }

        return $this->aRecords($host.'.dbl.spamhaus.org') !== [];
    }

    /**
     * The A records for a name, or none. Kept apart from the decision so a test
     * can stand in for the resolver.
     *
     * @return list<array<string, mixed>>
     */
    protected function aRecords(string $name): array
    {
        return @dns_get_record($name, DNS_A) ?: [];
    }
}
