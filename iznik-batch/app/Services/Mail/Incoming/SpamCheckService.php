<?php

namespace App\Services\Mail\Incoming;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Spam detection service for incoming email.
 *
 * Implements all spam checks from legacy iznik-server Spam.php and MailRouter.php:
 * - Keyword matching (spam_keywords table)
 * - IP country blocking (spam_countries + GeoIP)
 * - IP reputation (too many users/groups from same IP)
 * - IP whitelist (spam_whitelist_ips)
 * - Subject reuse detection across groups
 * - Bulk volunteer mail detection
 * - Greeting spam pattern detection
 * - Reference to known spammers
 * - Spamhaus DBL blacklist
 * - SpamAssassin/rspamd external scoring
 * - Review checks (scripts, money, external emails, language, links)
 * - Image spam detection (repeated hash)
 * - Our-domain spoofing detection
 */
class SpamCheckService
{
    // Thresholds matching legacy Spam.php constants
    public const USER_THRESHOLD = 5;

    public const GROUP_THRESHOLD = 20;

    public const SUBJECT_THRESHOLD = 30;

    public const IMAGE_THRESHOLD = 5;

    public const IMAGE_THRESHOLD_TIME = 24; // hours

    public const ASSASSIN_THRESHOLD = 8;

    // Spam reason constants matching legacy
    public const REASON_NOT_SPAM = 'NotSpam';

    public const REASON_COUNTRY_BLOCKED = 'CountryBlocked';

    public const REASON_IP_USED_FOR_DIFFERENT_USERS = 'IPUsedForDifferentUsers';

    public const REASON_IP_USED_FOR_DIFFERENT_GROUPS = 'IPUsedForDifferentGroups';

    public const REASON_SUBJECT_USED_FOR_DIFFERENT_GROUPS = 'SubjectUsedForDifferentGroups';

    public const REASON_SPAMASSASSIN = 'SpamAssassin';

    public const REASON_GREETING = 'Greetings spam';

    public const REASON_REFERRED_TO_SPAMMER = 'Referenced known spammer';

    public const REASON_KNOWN_KEYWORD = 'Known spam keyword';

    public const REASON_DBL = 'URL on DBL';

    public const REASON_BULK_VOLUNTEER_MAIL = 'BulkVolunteerMail';

    public const REASON_USED_OUR_DOMAIN = 'UsedOurDomain';

    public const REASON_WORRY_WORD = 'WorryWord';

    public const REASON_SCRIPT = 'Script';

    public const REASON_LINK = 'Link';

    public const REASON_MONEY = 'Money';

    public const REASON_EMAIL = 'Email';

    public const REASON_LANGUAGE = 'Language';

    public const REASON_IMAGE_SENT_MANY_TIMES = 'SameImage';

    // Actions matching concern_keywords.action values
    public const ACTION_SPAM = 'block';

    public const ACTION_REVIEW = 'flag';

    // Greetings for greeting spam detection
    private const GREETINGS = [
        'hello', 'salutations', 'hey', 'good morning', 'sup', 'hi',
        'good evening', 'good afternoon', 'greetings',
    ];

    // URL pattern matching legacy Utils::URL_PATTERN
    private const URL_PATTERN = '/https?:\/\/[^\s<>\'"]+/i';

    // Email pattern matching legacy Message::EMAIL_REGEXP
    private const EMAIL_REGEXP = '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/';

    // Known bad URL patterns matching legacy Utils::URL_BAD
    private const URL_BAD = [
        'bit.do',
        'goo.gl/forms',
    ];

    private ?array $cachedSpamWords = null;

    /**
     * Clear the cached spam words.
     *
     * This is primarily for testing - when tests insert keywords after the
     * service is created, this allows them to clear the cache so new keywords
     * are picked up.
     */
    public function clearKeywordCache(): void
    {
        $this->cachedSpamWords = null;
    }

    /**
     * Run all message-level spam checks (matching legacy Spam::checkMessage).
     *
     * Returns null if message is clean, or [isSpam, reason, detail] if spam found.
     *
     * @return array{bool, string, string}|null
     */
    public function checkMessage(ParsedEmail $email): ?array
    {
        $ip = $email->senderIp;
        $fromName = $email->fromName ?? '';
        $subject = $email->subject ?? '';
        $body = (new StripQuotedService)->strip($email->textBody ?? '');

        // Check for our-domain spoofing in from name
        $groupDomain = config('freegle.mail.group_domain');
        $userDomain = config('freegle.mail.user_domain');

        if (stripos($fromName, $groupDomain) !== false || stripos($fromName, $userDomain) !== false) {
            return [true, self::REASON_USED_OUR_DOMAIN, "Used our domain inside from name {$fromName}"];
        }

        // IP-based checks (only for non-TN, non-internal IPs)
        $fromTN = $email->isFromTrashNothing();

        if ($ip && ! $fromTN) {
            // Skip internal IPs
            if (str_starts_with($ip, '10.')) {
                $ip = null;
            } else {
                // Check IP whitelist
                if ($this->isIPWhitelisted($ip)) {
                    $ip = null;
                }
            }
        }

        if ($ip && ! $fromTN) {
            // Check IP country blocking
            $countryResult = $this->checkIPCountry($ip);
            if ($countryResult !== null) {
                return $countryResult;
            }

            // Check IP reputation - too many users
            $userResult = $this->checkIPUsers($ip);
            if ($userResult !== null) {
                return $userResult;
            }

            // Check IP reputation - too many groups
            $groupResult = $this->checkIPGroups($ip);
            if ($groupResult !== null) {
                return $groupResult;
            }
        }

        // Subject reuse detection (only for subjects >= 10 chars)
        $prunedSubject = $this->pruneSubject($subject);
        if (strlen($prunedSubject) >= 10) {
            $subjectResult = $this->checkSubjectReuse($prunedSubject);
            if ($subjectResult !== null) {
                return $subjectResult;
            }
        }

        // Bulk volunteer mail detection
        $bulkResult = $this->checkBulkVolunteerMail($email);
        if ($bulkResult !== null) {
            return $bulkResult;
        }

        // Greeting spam detection
        $greetingResult = $this->checkGreetingSpam($prunedSubject, $body);
        if ($greetingResult !== null) {
            return $greetingResult;
        }

        // Reference to known spammers
        $spammerRef = $this->checkReferToSpammer($body);
        if ($spammerRef !== null) {
            return [true, self::REASON_REFERRED_TO_SPAMMER, "Refers to known spammer {$spammerRef}"];
        }

        // Keyword-based spam checks (body + subject, both Spam and Review actions)
        $fromAddress = $email->fromAddress ?? '';
        $supportAddr = config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org');
        $infoAddr = 'info@'.explode('@', $supportAddr)[1];

        if ($fromAddress !== $supportAddr && $fromAddress !== $infoAddr) {
            $keywordResult = $this->checkSpamKeywords($body, [self::ACTION_REVIEW, self::ACTION_SPAM]);
            if ($keywordResult !== null) {
                return $keywordResult;
            }

            $keywordResult = $this->checkSpamKeywords($subject, [self::ACTION_REVIEW, self::ACTION_SPAM]);
            if ($keywordResult !== null) {
                return $keywordResult;
            }
        }

        return null;
    }

    /**
     * Check message content for spam keywords (matching legacy Spam::checkSpam).
     *
     * Also checks Spamhaus DBL for URLs and our-domain spoofing in URLs.
     *
     * @param  string  $message  Text to check
     * @param  array  $actions  Actions to match ('Spam', 'Review')
     * @return array{bool, string, string}|null
     */
    public function checkSpamKeywords(string $message, array $actions): ?array
    {
        $ret = null;

        // Strip job text URLs (matches legacy)
        $message = preg_replace('/\<https\:\/\/www\.ilovefreegle\.org\/jobs\/.*\>.*$/im', '', $message);

        // Decode HTML entities used by spammers
        $message = str_replace('&#616;', 'i', $message);
        $message = str_replace('&#537;', 's', $message);
        $message = str_replace('&#206;', 'I', $message);
        $message = str_replace('=C2', '£', $message);

        // Check keywords
        $keywords = $this->getSpamWords();
        foreach ($keywords as $keyword) {
            $word = trim($keyword->word);
            if (strlen($word) === 0) {
                continue;
            }

            $pattern = '/\b'.preg_quote($word, '/').'\b/i';

            if (in_array($keyword->action, $actions) && preg_match($pattern, $message)) {
                // Check exclude pattern
                if (! empty($keyword->exclude) && @preg_match('/'.$keyword->exclude.'/i', $message)) {
                    continue;
                }

                $ret = [true, self::REASON_KNOWN_KEYWORD, "Refers to keyword '{$word}'"];
            }
        }

        // Check URLs for Spamhaus DBL and our-domain spoofing
        if (preg_match_all(self::URL_PATTERN, $message, $matches)) {
            $checked = [];
            $groupDomain = config('freegle.mail.group_domain');
            $userDomain = config('freegle.mail.user_domain');

            foreach ($matches[0] as $url) {
                // Check for bad URL patterns
                $bad = false;
                $url2 = str_replace(['http:', 'https:'], '', $url);
                foreach (self::URL_BAD as $badone) {
                    if (strpos($url2, $badone) !== false) {
                        $bad = true;
                    }
                }

                if (! $bad && strlen($url) > 0) {
                    $urlDomain = substr($url, strpos($url, '://') + 3);

                    if (! isset($checked[$urlDomain])) {
                        // Check Spamhaus DBL
                        if ($this->checkSpamhausDbl("http://{$urlDomain}")) {
                            $ret = [true, self::REASON_DBL, "Blacklisted url {$urlDomain}"];
                            $checked[$urlDomain] = $ret;
                        }

                        // Check for our-domain spoofing in URLs
                        if (preg_match('/.+'.preg_quote($groupDomain, '/').'/', $urlDomain) ||
                            preg_match('/.+'.preg_quote($userDomain, '/').'/', $urlDomain)) {
                            $ret = [true, self::REASON_USED_OUR_DOMAIN, "Used our domain inside {$urlDomain}"];
                            $checked[$urlDomain] = $ret;
                        }
                    }
                }
            }
        }

        return $ret;
    }

    /**
     * Review check (matching legacy Spam::checkReview).
     *
     * Checks for: scripts, links, keywords, money symbols, external emails,
     * references to spammers, and non-English language.
     *
     * @param  string  $message  Text to check
     * @param  bool  $checkLanguage  Whether to check language
     * @return string|null Reason constant if review needed, null if clean
     */
    public function checkReview(string $message, bool $checkLanguage = true): ?string
    {
        // Spammer trick: encoded dot in URLs
        $message = str_replace('&#12290;', '.', $message);

        if (strlen($message) === 0) {
            return null;
        }

        $check = null;

        // Script tag detection
        if (! $check && stripos($message, '<script') !== false) {
            $check = self::REASON_SCRIPT;
        }

        // URL/link detection
        if (! $check) {
            $check = $this->checkReviewLinks($message);
        }

        // Keyword check (Review action only)
        if (! $check) {
            $keywords = $this->getSpamWords();
            foreach ($keywords as $word) {
                $w = $word->type !== 'regex' ? preg_quote($word->word, '/') : $word->word;

                if ($word->action === self::ACTION_REVIEW &&
                    preg_match('/\b'.$w.'\b/i', $message) &&
                    (empty($word->exclude) || ! @preg_match('/'.$word->exclude.'/i', $message))) {
                    $check = self::REASON_KNOWN_KEYWORD;
                }
            }
        }

        // Money symbols
        if (! $check && (strpos($message, '$') !== false || strpos($message, '£') !== false || strpos($message, '(a)') !== false)) {
            $check = self::REASON_MONEY;
        }

        // External email addresses
        if (! $check) {
            $check = $this->checkExternalEmails($message);
        }

        // Reference to known spammer
        if (! $check && $this->checkReferToSpammer($message) !== null) {
            $check = self::REASON_REFERRED_TO_SPAMMER;
        }

        // Language detection
        if (! $check && $checkLanguage) {
            $check = $this->checkLanguage($message);
        }

        return $check;
    }

    /**
     * Check if IP is in the whitelist.
     */
    public function isIPWhitelisted(string $ip): bool
    {
        return DB::table('spam_whitelist_ips')
            ->where('ip', $ip)
            ->exists();
    }

    /**
     * Check IP against country blocklist via GeoIP.
     *
     * @return array{bool, string, string}|null
     */
    public function checkIPCountry(string $ip): ?array
    {
        $country = $this->lookupIPCountry($ip);
        if ($country === null) {
            return null;
        }

        $blocked = DB::table('spam_countries')
            ->where('country', $country)
            ->exists();

        if ($blocked) {
            return [true, self::REASON_COUNTRY_BLOCKED, "Blocking IP {$ip} as it's in {$country}"];
        }

        return null;
    }

    /**
     * Look up the country for an IP address using GeoIP.
     *
     * This method can be overridden in tests to avoid requiring the GeoIP database.
     */
    protected function lookupIPCountry(string $ip): ?string
    {
        try {
            $mmdbPath = config('freegle.geoip.mmdb_path', '/usr/share/GeoIP/GeoLite2-Country.mmdb');
            if (! file_exists($mmdbPath)) {
                return null;
            }

            $reader = new \GeoIp2\Database\Reader($mmdbPath);
            $record = $reader->country($ip);

            return $record->country->name;
        } catch (\Exception $e) {
            Log::debug('GeoIP lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Check if IP has been used by too many different users (matching legacy).
     *
     * @return array{bool, string, string}|null
     */
    public function checkIPUsers(string $ip): ?array
    {
        $users = DB::table('messages_history')
            ->select('fromname')
            ->where('fromip', $ip)
            ->whereNotNull('groupid')
            ->groupBy('fromuser')
            ->orderBy('arrival', 'desc')
            ->get();

        $numUsers = $users->count();

        if ($numUsers > self::USER_THRESHOLD) {
            $list = $users->pluck('fromname')->implode(', ');

            return [true, self::REASON_IP_USED_FOR_DIFFERENT_USERS,
                "IP {$ip} recently used for {$numUsers} different users ({$list})"];
        }

        return null;
    }

    /**
     * Check if IP has been used for too many different groups (matching legacy).
     *
     * @return array{bool, string, string}|null
     */
    public function checkIPGroups(string $ip): ?array
    {
        $groups = DB::table('messages_history')
            ->join('groups', 'groups.id', '=', 'messages_history.groupid')
            ->select('groups.nameshort')
            ->where('fromip', $ip)
            ->groupBy('groupid')
            ->get();

        $numGroups = $groups->count();

        if ($numGroups >= self::GROUP_THRESHOLD) {
            $list = $groups->pluck('nameshort')->implode(', ');

            return [true, self::REASON_IP_USED_FOR_DIFFERENT_GROUPS,
                "IP {$ip} recently used for {$numGroups} different groups ({$list})"];
        }

        return null;
    }

    /**
     * Check if pruned subject has been used across too many groups.
     *
     * @return array{bool, string, string}|null
     */
    public function checkSubjectReuse(string $prunedSubject): ?array
    {
        $count = DB::table('messages_history')
            ->where('prunedsubject', 'LIKE', "{$prunedSubject}%")
            ->whereNotNull('groupid')
            ->distinct('groupid')
            ->count('groupid');

        if ($count >= self::SUBJECT_THRESHOLD) {
            // Check whitelist
            $whitelisted = DB::table('spam_whitelist_subjects')
                ->where('subject', $prunedSubject)
                ->exists();

            if (! $whitelisted) {
                return [true, self::REASON_SUBJECT_USED_FOR_DIFFERENT_GROUPS,
                    "Warning - subject {$prunedSubject} recently used on {$count} groups"];
            }
        }

        return null;
    }

    /**
     * Check if sender or subject has been sent to too many volunteer addresses.
     *
     * @return array{bool, string, string}|null
     */
    public function checkBulkVolunteerMail(ParsedEmail $email): ?array
    {
        $groupDomain = config('freegle.mail.group_domain');
        $from = $email->envelopeFrom;
        $subject = $email->subject ?? '';
        $cutoff = now()->subHours(24)->format('Y-m-d H:i:s');

        // Check sender to many volunteer addresses
        $senderCount = DB::table('messages')
            ->where('envelopefrom', $from)
            ->where('envelopeto', 'LIKE', "%-volunteers@{$groupDomain}")
            ->where('arrival', '>=', $cutoff)
            ->count();

        if ($senderCount >= self::GROUP_THRESHOLD) {
            return [true, self::REASON_BULK_VOLUNTEER_MAIL,
                "Warning - {$from} mailed {$senderCount} group volunteer addresses recently"];
        }

        // Check subject to many volunteer addresses
        $subjectCount = DB::table('messages')
            ->where('subject', 'LIKE', $subject)
            ->where('envelopeto', 'LIKE', "%-volunteers@{$groupDomain}")
            ->where('arrival', '>=', $cutoff)
            ->count();

        if ($subjectCount >= self::GROUP_THRESHOLD) {
            return [true, self::REASON_BULK_VOLUNTEER_MAIL,
                "Warning - subject {$subject} mailed to {$subjectCount} group volunteer addresses recently"];
        }

        return null;
    }

    /**
     * Check for greeting spam pattern (matching legacy Spam::checkMessage).
     *
     * Greeting spam has: greetings in subject + line1, OR greetings in line1 + line3,
     * combined with HTTP links or .php references.
     *
     * @return array{bool, string, string}|null
     */
    public function checkGreetingSpam(string $subject, string $body): ?array
    {
        if (stripos($body, 'http') === false && stripos($body, '.php') === false) {
            return null;
        }

        $lines = explode("\n", $body);
        $line1 = $lines[0] ?? '';
        $line3 = $lines[2] ?? '';

        $subjGreeting = false;
        $line1Greeting = false;
        $line3Greeting = false;

        foreach (self::GREETINGS as $greeting) {
            if (stripos($subject, $greeting) === 0) {
                $subjGreeting = true;
            }
            if (stripos($line1, $greeting) === 0) {
                $line1Greeting = true;
            }
            if (stripos($line3, $greeting) === 0) {
                $line3Greeting = true;
            }
        }

        if (($subjGreeting && $line1Greeting) || ($line1Greeting && $line3Greeting)) {
            return [true, self::REASON_GREETING, 'Message looks like a greetings spam'];
        }

        return null;
    }

    /**
     * Check if text references a known spammer's email address.
     *
     * @return string|null The spammer's email if found, null otherwise
     */
    public function checkReferToSpammer(string $text): ?string
    {
        if (strpos($text, '@') === false) {
            return null;
        }

        if (preg_match_all(self::EMAIL_REGEXP, $text, $matches)) {
            foreach ($matches[0] as $email) {
                $found = DB::table('spam_users')
                    ->join('users_emails', 'spam_users.userid', '=', 'users_emails.userid')
                    ->where('spam_users.collection', 'Spammer')
                    ->where('users_emails.email', 'LIKE', $email)
                    ->value('users_emails.email');

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Check a URL against Spamhaus DBL.
     *
     * Performs a DNS lookup against dbl.spamhaus.org to check if the domain
     * is on the block list.
     */
    public function checkSpamhausDbl(string $url): bool
    {
        try {
            // Extract domain from URL
            $parsed = parse_url($url);
            $host = $parsed['host'] ?? '';
            if (empty($host)) {
                return false;
            }

            // Strip www. prefix
            $host = preg_replace('/^www\./', '', $host);

            // Query Spamhaus DBL
            $lookup = $host.'.dbl.spamhaus.org';
            $records = @dns_get_record($lookup, DNS_A);

            // If we get a result, the domain is listed
            return ! empty($records);
        } catch (\Exception $e) {
            Log::debug('Spamhaus DBL check failed', ['url' => $url, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Run SpamAssassin check via spamc (matching legacy MailRouter::checkSpam).
     *
     * Only checks messages whose subject is NOT in standard Freegle format
     * (e.g., "OFFER: Item (Location)").
     *
     * @param  string  $rawMessage  The full raw email message
     * @param  string  $subject  The message subject
     * @return array{float|null, bool} [score, isSpam]
     */
    public function checkSpamAssassin(string $rawMessage, string $subject): array
    {
        // Only content-check if subject is NOT in standard Freegle format
        if (preg_match('/.*?\:(.*)\(.*\)/', $subject)) {
            return [null, false];
        }

        $host = config('freegle.spam_check.spamassassin_host', '127.0.0.1');
        $port = (int) config('freegle.spam_check.spamassassin_port', 783);

        try {
            $score = $this->querySpamd($rawMessage, $host, $port);

            if ($score !== null && $score >= self::ASSASSIN_THRESHOLD) {
                return [$score, true];
            }

            return [$score, false];
        } catch (\Exception $e) {
            Log::warning('SpamAssassin check failed', ['error' => $e->getMessage()]);

            return [null, false];
        }
    }

    /**
     * Query spamd server. Can be overridden in tests.
     */
    protected function querySpamd(string $message, string $host, int $port): ?float
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 5);
        if (! $socket) {
            Log::warning('Cannot connect to spamd', ['host' => $host, 'port' => $port, 'error' => $errstr]);

            return null;
        }

        $length = strlen($message);
        // Must use SPAMC/1.2 — spamd rejects 1.5.
        $request = "CHECK SPAMC/1.2\r\nContent-length: {$length}\r\n\r\n{$message}";

        stream_set_timeout($socket, 30);
        fwrite($socket, $request);
        // Signal end-of-write so spamd knows the full message has been sent.
        stream_socket_shutdown($socket, STREAM_SHUT_WR);

        $response = '';
        while (! feof($socket)) {
            $response .= fread($socket, 8192);
            $info = stream_get_meta_data($socket);
            if ($info['timed_out']) {
                fclose($socket);
                Log::warning('SpamAssassin timed out after 30 seconds', ['host' => $host]);

                return null;
            }
        }
        fclose($socket);

        // Parse score from response: "Spam: True ; 15.5 / 5.0"
        if (preg_match('/Spam:\s*(True|False|Yes|No)\s*;\s*([\d.]+)\s*\//', $response, $matches)) {
            return (float) $matches[2];
        }

        return null;
    }

    /**
     * Check links in message for review (matching legacy Spam::checkReview link logic).
     */
    private function checkReviewLinks(string $message): ?string
    {
        // Check for removed URLs
        if (stripos($message, '(URL removed)') !== false) {
            return self::REASON_LINK;
        }

        if (preg_match_all(self::URL_PATTERN, $message, $matches)) {
            // Get whitelisted domains (count >= 3, length > 5, excluding shorteners)
            $whitelistedDomains = DB::table('spam_whitelist_links')
                ->where('count', '>=', 3)
                ->whereRaw('LENGTH(domain) > 5')
                ->where('domain', 'NOT LIKE', '%linkedin%')
                ->where('domain', 'NOT LIKE', '%goo.gl%')
                ->where('domain', 'NOT LIKE', '%bit.ly%')
                ->where('domain', 'NOT LIKE', '%tinyurl%')
                ->pluck('domain')
                ->toArray();

            $valid = 0;
            $total = 0;

            foreach ($matches[0] as $url) {
                // Skip bad URLs
                $bad = false;
                $url2 = str_replace(['http:', 'https:'], '', $url);
                foreach (self::URL_BAD as $badone) {
                    if (strpos($url2, $badone) !== false) {
                        $bad = true;
                    }
                }

                if (! $bad && strlen($url) > 0) {
                    $domain = substr($url, strpos($url, '://') + 3);
                    $total++;
                    $trusted = false;

                    foreach ($whitelistedDomains as $whitelisted) {
                        if (stripos($domain, $whitelisted) === 0) {
                            $valid++;
                            $trusted = true;
                        }
                    }
                }
            }

            if ($valid < $total) {
                return self::REASON_LINK;
            }
        }

        return null;
    }

    /**
     * Check for external email addresses in message text.
     */
    private function checkExternalEmails(string $message): ?string
    {
        if (preg_match_all(self::EMAIL_REGEXP, $message, $matches)) {
            foreach ($matches[0] as $email) {
                // Exclude our domains, partner domains, noreply on our domain
                $isNoreplyOnOurDomain = stripos($email, 'noreply@') === 0 &&
                    stripos($email, 'ilovefreegle.org') !== false;

                if (! $this->isOurDomain($email) &&
                    strpos($email, 'trashnothing') === false &&
                    strpos($email, 'yahoogroups') === false &&
                    ! $isNoreplyOnOurDomain) {
                    return self::REASON_EMAIL;
                }
            }
        }

        return null;
    }

    /**
     * Check if email is on one of our domains (matching legacy Mail::ourDomain).
     */
    private function isOurDomain(string $email): bool
    {
        $internalDomains = config('freegle.mail.internal_domains', []);
        foreach ($internalDomains as $domain) {
            if (stripos($email, '@'.$domain) !== false) {
                return true;
            }
        }
        // Also check the base domain
        if (stripos($email, '@ilovefreegle.org') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Check message language (matching legacy Spam::checkReview language detection).
     *
     * Only checks messages > 50 chars. Accepts English and Welsh.
     */
    private function checkLanguage(string $message): ?string
    {
        // Strip 'xxx' and trim
        $message = str_ireplace('xxx', '', strtolower(trim($message)));

        if (strlen($message) <= 50) {
            return null;
        }

        try {
            if (! class_exists(\LanguageDetection\Language::class)) {
                return null;
            }

            $ld = new \LanguageDetection\Language;
            $lang = $ld->detect($message)->close();

            if (empty($lang)) {
                return null;
            }

            reset($lang);
            $firstLang = key($lang);
            $firstProb = $lang[$firstLang] ?? 0;
            $enProb = $lang['en'] ?? 0;
            $cyProb = $lang['cy'] ?? 0;
            $ourProb = max($enProb, $cyProb);

            // Accept if English/Welsh is first, or our probability is >= 80% of the top language
            if ($firstLang === 'en' || $firstLang === 'cy' || $ourProb >= 0.8 * $firstProb) {
                return null;
            }

            return self::REASON_LANGUAGE;
        } catch (\Exception $e) {
            Log::debug('Language detection failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Prune a subject line to its core content (matching legacy Message::getPrunedSubject).
     *
     * Strips OFFER/WANTED/TAKEN/RECEIVED prefix and location suffix.
     */
    public function pruneSubject(string $subject): string
    {
        // Strip standard prefixes: OFFER:, WANTED:, TAKEN:, RECEIVED:
        $subject = preg_replace('/^(OFFER|WANTED|TAKEN|RECEIVED)\s*:\s*/i', '', $subject);

        // Strip location suffix in parentheses at end
        $subject = preg_replace('/\s*\([^)]*\)\s*$/', '', $subject);

        return trim($subject);
    }

    /**
     * Check if a chat image hash has been used too many times recently.
     *
     * @return bool true if the image has been used more than IMAGE_THRESHOLD times
     */
    public function checkImageSpam(string $hash): bool
    {
        $count = DB::table('chat_images')
            ->join('chat_messages', 'chat_images.id', '=', 'chat_messages.imageid')
            ->where('chat_images.hash', $hash)
            ->whereRaw('TIMESTAMPDIFF(HOUR, chat_messages.date, NOW()) <= ?', [self::IMAGE_THRESHOLD_TIME])
            ->count();

        return $count > self::IMAGE_THRESHOLD;
    }

    /**
     * Get spam keywords from concern_keywords (global, non-whitelist entries).
     * Aliases keyword→word and match_mode→type to preserve existing field access.
     */
    private function getSpamWords(): array
    {
        if ($this->cachedSpamWords === null) {
            $this->cachedSpamWords = DB::table('concern_keywords')
                ->select('keyword as word', 'match_mode as type', 'action', 'exclude')
                ->where('scope', 'global')
                ->where('group_id', 0)
                ->where('category', '!=', 'allowed')
                ->whereRaw('LENGTH(TRIM(keyword)) > 0')
                ->get()
                ->all();
        }

        return $this->cachedSpamWords;
    }
}
