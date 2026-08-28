<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NewsfeedLinkPreviewService
{
    // Matches http(s):// URLs from message text
    const URL_PATTERN = '#https?://[^\s<>"\'`{|}\\\\\^\[\]]+#i';

    public function generatePreviews(bool $dryRun = false): int
    {
        $recent = DB::select(
            "SELECT id, message FROM newsfeed WHERE DATEDIFF(NOW(), `timestamp`) < 2"
        );

        $urlsSeen = [];
        $count = 0;

        foreach ($recent as $row) {
            if (!$row->message) {
                continue;
            }

            if (preg_match_all(self::URL_PATTERN, $row->message, $matches)) {
                foreach ($matches[0] as $url) {
                    if (!$url || isset($urlsSeen[$url])) {
                        continue;
                    }
                    $urlsSeen[$url] = true;

                    if ($dryRun) {
                        $cached = DB::select(
                            "SELECT id FROM link_previews WHERE url = ? AND DATEDIFF(NOW(), retrieved) < 7",
                            [$url]
                        );
                        $status = $cached ? 'cached' : 'would fetch';
                        Log::debug("Dry run: $url — $status");
                        if (!$cached) {
                            $count++;
                        }
                        continue;
                    }

                    $id = $this->getOrCreate($url);
                    Log::debug("Url $url has preview $id");

                    if ($id) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    public function getOrCreate(string $url): ?int
    {
        $existing = DB::select(
            "SELECT id FROM link_previews WHERE url = ? AND DATEDIFF(NOW(), retrieved) < 7",
            [$url]
        );

        if ($existing) {
            return $existing[0]->id;
        }

        return $this->create($url);
    }

    /**
     * Reject URLs that resolve to a non-public address, to stop SSRF: a member can put any URL in a
     * newsfeed post and this job fetches it server-side. Without this an attacker points it at
     * internal services (cloud metadata, other containers) and reads a slice of the response back
     * via the stored preview title/description. Host resolution is split into resolveHostIps() so
     * tests can supply deterministic IPs (there is no external DNS in the test environment) while
     * these range checks still run for real.
     */
    protected function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        $v4 = @gethostbynamel($host);
        if ($v4) {
            $ips = array_merge($ips, $v4);
        }
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        return $ips;
    }

    /**
     * Public so other server-side fetchers of untrusted URLs (SourceFreshness)
     * reuse this guard rather than growing a second copy of it.
     */
    public function isFetchableUrl(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        // parse_url keeps the [...] around an IPv6 literal host; strip them so it validates as an IP.
        $host = trim($parts['host'] ?? '', '[]');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        $ips = $this->resolveHostIps($host);
        if (empty($ips)) {
            return false;
        }
        foreach ($ips as $ip) {
            // Reject private (RFC1918) and reserved (loopback, link-local, etc.) ranges.
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }

    protected function create(string $url): ?int
    {
        $fetchUrl = str_replace('http://', 'https://', $url);

        // SECURITY: block SSRF before fetching a user-supplied URL server-side (see isFetchableUrl).
        // Redirects are disabled below so a public URL cannot bounce us to an internal one.
        if (!$this->isFetchableUrl($fetchUrl)) {
            return $this->upsertInvalid($url);
        }

        try {
            $response = Http::timeout(15)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; Freegle/1.0)',
                ])->get($fetchUrl);

            if (!$response->successful()) {
                return $this->upsertInvalid($url);
            }

            // A 200 with an empty body is a real thing (tracker pixels, broken
            // CDNs). DOMDocument::loadHTML('') throws ValueError on PHP 8 - an
            // \Error, so the \Exception catch below never saw it, the row was
            // never marked invalid, and the same URL crashed the cron every
            // minute (2026-08-17, 08:45 onwards).
            if (trim($response->body()) === '') {
                return $this->upsertInvalid($url);
            }

            $data = $this->parseHtml($response->body());

            DB::statement(
                "INSERT INTO link_previews(url, title, description, image) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), title=?, description=?, image=?, retrieved=NOW()",
                [
                    $url,
                    $data['title'],
                    $data['description'],
                    $data['image'],
                    $data['title'],
                    $data['description'],
                    $data['image'],
                ]
            );

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: the parser throws \Error subclasses
            // (ValueError above being the proven case) and an unmarked row
            // retries forever.
            return $this->upsertInvalid($url);
        }
    }

    protected function upsertInvalid(string $url): int
    {
        DB::statement(
            "INSERT INTO link_previews(url, invalid) VALUES (?,1)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), retrieved=NOW()",
            [$url]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    protected function parseHtml(string $html): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html, LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $title = $this->getMeta($xpath, 'og:title', 'property')
            ?? $this->getMeta($xpath, 'twitter:title', 'name')
            ?? ($xpath->query('//title')->item(0)?->textContent ?? null);

        $description = $this->getMeta($xpath, 'og:description', 'property')
            ?? $this->getMeta($xpath, 'description', 'name');

        $image = $this->getMeta($xpath, 'og:image', 'property')
            ?? $this->getMeta($xpath, 'twitter:image', 'name');

        return [
            'title'       => $title ? preg_replace('/[[:^print:]]/', '', trim($title)) : null,
            'description' => $description ? preg_replace('/[[:^print:]]/', '', trim($description)) : null,
            'image'       => $image ? trim($image) : null,
        ];
    }

    private function getMeta(DOMXPath $xpath, string $value, string $attr): ?string
    {
        $nodes = $xpath->query("//meta[@{$attr}='{$value}']/@content");

        if ($nodes && $nodes->length > 0) {
            return $nodes->item(0)->nodeValue;
        }

        return null;
    }
}
