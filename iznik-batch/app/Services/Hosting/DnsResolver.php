<?php

namespace App\Services\Hosting;

/**
 * The NS records of a domain, as a list of lower-case hostnames without the trailing dot, or an empty
 * list when there are none or the lookup fails. A class, not a function, so a test can bind a fake in
 * the container instead of asking the network.
 */
class DnsResolver
{
    /** @return list<string> */
    public function nameservers(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_NS);
        if (! is_array($records)) {
            return [];
        }

        $out = [];
        foreach ($records as $r) {
            $target = strtolower(rtrim((string) ($r['target'] ?? ''), '.'));
            if ($target !== '') {
                $out[] = $target;
            }
        }
        sort($out);

        return $out;
    }
}
