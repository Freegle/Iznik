<?php

namespace App\Services\WhatJobs;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads our daily click figures from the WhatJobs partner dashboard (partner.whatjobs.com).
 *
 * WhatJobs has no API for this. The dashboard is a login that posts to /partner/login/do.ajax,
 * then one page per month, /outbound/partner/usage/YYYY-MM-new, holding a table with a row per
 * day. A page asked for without a session redirects to the login, which is how an expired or
 * refused login shows up.
 *
 * WhatJobs only starts a session for a browser-shaped user agent: a bare client name gets no
 * cookie, so the login "succeeds" and every page after it bounces.
 */
class PartnerDashboardClient
{
    public const USER_AGENT = 'Mozilla/5.0 (compatible; FreegleBatch/1.0; +https://www.ilovefreegle.org)';

    /** Dashboard column heading => our column. */
    public const COLUMNS = [
        'Billable Clicks' => 'billable_clicks',
        'Duplicate Clicks' => 'duplicate_clicks',
        'Invalid Clicks' => 'invalid_clicks',
        'Foreign Clicks' => 'foreign_clicks',
        'Latent Clicks' => 'latent_clicks',
        'Applications' => 'applications',
    ];

    private ?CookieJar $cookies = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
    ) {}

    /** A client from config, or null when no login is configured. */
    public static function fromConfig(): ?self
    {
        $username = trim((string) config('freegle.whatjobs.partner_username', ''));
        $password = (string) config('freegle.whatjobs.partner_password', '');
        if ($username === '' || $password === '') {
            return null;
        }

        return new self(rtrim((string) config('freegle.whatjobs.partner_url'), '/'), $username, $password);
    }

    /**
     * The figures for one month, as date => [our column => count].
     *
     * @return array<string, array<string, int>>
     *
     * @throws RuntimeException when the login fails or the page is not the one we know
     */
    public function month(string $yearMonth): array
    {
        $response = $this->session()->get("{$this->baseUrl}/outbound/partner/usage/{$yearMonth}-new", ['status' => 1]);
        if ($response->redirect()) {
            throw new RuntimeException("WhatJobs sent the {$yearMonth} page back to the login: the session was not accepted.");
        }
        if (! $response->successful()) {
            throw new RuntimeException("WhatJobs answered {$response->status()} for the {$yearMonth} page.");
        }

        return self::parseMonth($response->body());
    }

    /**
     * The rows of a month page's results table. A table without the columns we know means
     * WhatJobs have changed the page, which must fail rather than read as a month of nothing.
     *
     * @return array<string, array<string, int>>
     */
    public static function parseMonth(string $html): array
    {
        $doc = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($doc);
        $headings = [];
        foreach ($xpath->query('//table[@id="resultTable"]//th') as $th) {
            $headings[] = trim($th->textContent);
        }
        if (($headings[0] ?? null) !== 'Date' || ! in_array('Billable Clicks', $headings, true)) {
            throw new RuntimeException('The WhatJobs usage page has no table of daily clicks: the page has changed.');
        }

        $days = [];
        foreach ($xpath->query('//table[@id="resultTable"]//tr[td]') as $tr) {
            $cells = [];
            foreach ($xpath->query('td', $tr) as $td) {
                $cells[] = trim($td->textContent);
            }
            // The last row is the month's total.
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $cells[0] ?? '')) {
                continue;
            }
            $day = [];
            foreach ($headings as $i => $heading) {
                if (isset(self::COLUMNS[$heading])) {
                    $day[self::COLUMNS[$heading]] = (int) str_replace(',', '', $cells[$i] ?? '0');
                }
            }
            $days[$cells[0]] = $day;
        }

        return $days;
    }

    /** A request carrying the logged-in session's cookies, logging in first if need be. */
    private function session(): PendingRequest
    {
        if (! $this->cookies) {
            $cookies = new CookieJar;
            // The login page sets the session cookie that the login then authenticates.
            $this->request($cookies)->get("{$this->baseUrl}/");
            $login = $this->request($cookies)
                ->asForm()
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->post("{$this->baseUrl}/partner/login/do.ajax", ['username' => $this->username, 'password' => $this->password]);
            if (! $login->successful() || $login->json('done') !== true) {
                $why = $login->json('errorMessage') ?: "HTTP {$login->status()}";
                throw new RuntimeException("WhatJobs refused the partner login: {$why}.");
            }
            $this->cookies = $cookies;
        }

        return $this->request($this->cookies);
    }

    private function request(CookieJar $cookies): PendingRequest
    {
        return Http::withOptions(['cookies' => $cookies, 'allow_redirects' => false])
            ->withUserAgent(self::USER_AGENT)
            ->timeout(60);
    }
}
