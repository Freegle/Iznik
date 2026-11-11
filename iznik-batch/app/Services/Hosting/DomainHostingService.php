<?php

namespace App\Services\Hosting;

use App\Models\DomainHosting;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Finds out whether the web domains in members' email addresses run a WordPress site, and whether it
 * is already hosted by Krystal, for the Krystal hosting ad (docs/developers/reference/ux-experiments.md,
 * "A hosting ad for members with a WordPress site").
 *
 * A candidate is the domain of the preferred email of a member active in the last ACTIVE_DAYS, where
 * at most MAX_USERS active members share it: a domain with more is a mail provider, an employer or an
 * ISP, not somebody's own site. A short list of known providers and the public-sector suffixes are
 * left out as well. Each candidate is looked at once, and again after RECHECK_DAYS.
 *
 * A look is one DNS NS query and one GET of the home page (https, then http), with a user agent that
 * says who is asking. Only what the look found is kept: the two flags, the first nameserver, the
 * Server header and the status. The page itself is never stored. Nothing here names a member.
 */
class DomainHostingService
{
    public const ACTIVE_DAYS = 90;

    public const MAX_USERS = 5;

    public const RECHECK_DAYS = 90;

    public const USER_AGENT = 'Freegle hosting check (+https://www.ilovefreegle.org)';

    /** Bytes of the home page read for the fingerprint. */
    public const BODY_LIMIT = 200000;

    public const CONNECT_TIMEOUT = 5;

    public const TIMEOUT = 8;

    /** Domains that are never somebody's own site, beyond what the MAX_USERS cap catches. */
    public const SKIP_DOMAINS = [
        'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.co.uk', 'outlook.com', 'live.com', 'live.co.uk',
        'yahoo.com', 'yahoo.co.uk', 'ymail.com', 'rocketmail.com', 'aol.com', 'aol.co.uk', 'icloud.com', 'me.com',
        'mac.com', 'msn.com', 'btinternet.com', 'btopenworld.com', 'talk21.com', 'sky.com', 'ntlworld.com',
        'virginmedia.com', 'blueyonder.co.uk', 'talktalk.net', 'tiscali.co.uk', 'plus.com', 'karoo.co.uk',
        'proton.me', 'protonmail.com', 'pm.me', 'gmx.com', 'gmx.co.uk', 'mail.com', 'email.com', 'duck.com',
        'privaterelay.appleid.com', 'user.trashnothing.com', 'users.ilovefreegle.org', 'ilovefreegle.org',
        'bigpond.com', 'xtra.co.nz', 'uwclub.net', 'phonecoop.coop', 'mailo.uk',
    ];

    /** Suffixes of domains that are never somebody's own site. */
    public const SKIP_SUFFIXES = ['.ac.uk', '.gov.uk', '.nhs.uk', '.sch.uk', '.police.uk', '.mod.uk', '.gov', '.edu', '.mil'];

    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * The domains to look at next, oldest check first and never-checked domains before any of them, at
     * most $limit of them.
     *
     * @return list<string>
     */
    public function candidates(int $limit, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $limit = max(0, $limit);
        if ($limit === 0) {
            return [];
        }

        $rows = DB::table('users_emails as e')
            ->join('users as u', 'u.id', '=', 'e.userid')
            ->where('e.preferred', 1)
            ->whereNull('u.deleted')
            ->where('u.lastaccess', '>', $now->copy()->subDays(self::ACTIVE_DAYS))
            ->where('e.email', 'like', '%@%')
            // keep-raw: the domain is an expression over the email, not a column, and the count is grouped by it
            ->selectRaw("LOWER(SUBSTRING_INDEX(e.email, '@', -1)) AS domain, COUNT(DISTINCT e.userid) AS n")
            ->groupBy('domain')
            ->having('n', '<=', self::MAX_USERS)
            ->get();

        $domains = [];
        foreach ($rows as $row) {
            $domain = (string) $row->domain;
            if (self::eligibleDomain($domain)) {
                $domains[] = $domain;
            }
        }
        if (! $domains) {
            return [];
        }

        $recheckBefore = $now->copy()->subDays(self::RECHECK_DAYS);
        $checked = [];
        foreach (array_chunk($domains, 500) as $chunk) {
            foreach (DomainHosting::whereIn('domain', $chunk)->get(['domain', 'checked_at']) as $row) {
                $checked[$row->domain] = $row->checked_at;
            }
        }

        $fresh = [];
        $stale = [];
        foreach ($domains as $domain) {
            if (! isset($checked[$domain])) {
                $fresh[] = $domain;
            } elseif ($checked[$domain]->lt($recheckBefore)) {
                $stale[$domain] = $checked[$domain]->getTimestamp();
            }
        }
        sort($fresh);
        asort($stale);

        return array_slice(array_merge($fresh, array_keys($stale)), 0, $limit);
    }

    /** A domain worth looking at: a hostname with a dot, not a provider and not public sector. */
    public static function eligibleDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || strlen($domain) > 191 || ! str_contains($domain, '.')) {
            return false;
        }
        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain)) {
            return false;
        }
        if (in_array($domain, self::SKIP_DOMAINS, true)) {
            return false;
        }
        foreach (self::SKIP_SUFFIXES as $suffix) {
            if (str_ends_with($domain, $suffix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Look at one domain. Returns the facts the row is written from.
     *
     * @return array{wordpress: bool, krystal: bool, nameserver: ?string, server: ?string, status: ?int}
     */
    public function probe(string $domain): array
    {
        $nameservers = $this->dns->nameservers($domain);
        $krystal = false;
        foreach ($nameservers as $ns) {
            if (str_contains($ns, 'krystal')) {
                $krystal = true;
            }
        }

        $response = $this->fetch('https://'.$domain.'/') ?? $this->fetch('http://'.$domain.'/');

        $wordpress = false;
        $server = null;
        $status = null;
        if ($response) {
            $status = $response->status();
            $server = substr(trim((string) $response->header('Server')), 0, 64) ?: null;
            $body = substr($response->body(), 0, self::BODY_LIMIT);
            $wordpress = self::looksLikeWordPress($body);
            foreach (['Server', 'X-Powered-By', 'X-Hosted-By'] as $header) {
                if (stripos((string) $response->header($header), 'krystal') !== false) {
                    $krystal = true;
                }
            }
        }

        return [
            'wordpress' => $wordpress,
            'krystal' => $krystal,
            'nameserver' => $nameservers ? substr($nameservers[0], 0, 191) : null,
            'server' => $server,
            'status' => $status,
        ];
    }

    /** The WordPress fingerprints a home page carries: its asset paths, its REST link or its generator tag. */
    public static function looksLikeWordPress(string $body): bool
    {
        return (bool) preg_match('#/wp-content/|/wp-includes/|/wp-json/?|<meta[^>]+name="generator"[^>]+content="WordPress#i', $body);
    }

    private function fetch(string $url): ?Response
    {
        try {
            return Http::withUserAgent(self::USER_AGENT)
                ->withHeaders(['Accept' => 'text/html'])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->withOptions(['allow_redirects' => ['max' => 3], 'verify' => true])
                ->get($url);
        } catch (ConnectionException $e) {
            return null;
        } catch (\Throwable $e) {
            Log::info('hosting: could not fetch '.$url.': '.$e->getMessage());

            return null;
        }
    }

    /** Write what a look found, creating the row or replacing the last look. */
    public function record(string $domain, array $facts, ?Carbon $now = null): DomainHosting
    {
        $row = DomainHosting::firstOrNew(['domain' => strtolower($domain)]);
        $row->wordpress = $facts['wordpress'];
        $row->krystal = $facts['krystal'];
        $row->nameserver = $facts['nameserver'];
        $row->server = $facts['server'];
        $row->status = $facts['status'];
        $row->checked_at = $now ?? Carbon::now();
        $row->save();

        return $row;
    }

    /**
     * Look at up to $limit candidates and record each. Returns the counts.
     *
     * @return array{looked: int, wordpress: int, krystal: int, unreachable: int}
     */
    public function scan(int $limit, ?Carbon $now = null): array
    {
        $counts = ['looked' => 0, 'wordpress' => 0, 'krystal' => 0, 'unreachable' => 0];
        foreach ($this->candidates($limit, $now) as $domain) {
            $facts = $this->probe($domain);
            $this->record($domain, $facts, $now);
            $counts['looked']++;
            $counts['wordpress'] += $facts['wordpress'] ? 1 : 0;
            $counts['krystal'] += $facts['krystal'] ? 1 : 0;
            $counts['unreachable'] += $facts['status'] === null ? 1 : 0;
        }

        return $counts;
    }
}
