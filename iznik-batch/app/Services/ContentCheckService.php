<?php

namespace App\Services;

use App\Models\BackgroundTask;
use App\Models\Message;
use App\Models\MessageGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LanguageDetection\Language;

class ContentCheckService
{
    public const CHECK_CONCERN_KEYWORD    = 'ConcernKeyword';
    public const CHECK_VAGUE             = 'Vague';
    public const CHECK_PHONE_NUMBER      = 'PhoneNumber';
    public const CHECK_EMAIL_ADDRESS     = 'EmailAddress';
    public const CHECK_MESSAGING_LINK    = 'MessagingLink';
    public const CHECK_PER_GROUP_WORRY   = 'PerGroupWorryWord';
    public const CHECK_URL               = 'Url';
    public const CHECK_MONEY             = 'Money';
    public const CHECK_LANGUAGE          = 'Language';
    public const CHECK_IP_ABUSE          = 'IpAbuse';
    public const CHECK_BULK_MAIL         = 'BulkMail';
    public const CHECK_SUBJECT_REPEAT    = 'SubjectRepeat';
    public const CHECK_KNOWN_SPAMMER     = 'KnownSpammer';
    public const CHECK_GREETING_SPAM     = 'GreetingSpam';
    public const CHECK_IMAGE_SPAM        = 'ImageSpam';
    public const CHECK_SPAMHAUS_DBL      = 'SpamhausDBL';

    private const SUBJECT_THRESHOLD = 30;
    private const SUBJECT_REPEAT_WINDOW = 7; // days

    private const VAGUE_KEYWORDS = [
        'stuff', 'things', 'items', 'junk', 'bits', 'various', 'misc',
        'miscellaneous', 'anything', 'loads', 'bundle', 'random', 'assorted',
        'collection', 'lots', 'free stuff', 'free items', 'bits and pieces',
        'this and that', 'unwanted', 'clutter', 'rubbish', 'tat',
    ];

    private const MESSAGING_LINK_DOMAINS = [
        'chat.whatsapp.com',
        'wa.me',
        't.me',
        'telegram.me',
        'discord.gg',
        'discord.com/invite',
        'signal.group',
    ];

    private const GREETING_KEYWORDS = [
        'hello', 'salutations', 'hey', 'good morning', 'sup',
        'hi', 'good evening', 'good afternoon', 'greetings',
    ];

    /**
     * Run all content checks for a single (msgid, groupid) pair.
     *
     * Returns array of failure reasons — empty means clean.
     * Each reason: ['check' => string, 'category' => string|null, 'action' => string, 'detail' => string]
     */
    public function checkMessage(int $msgid, int $groupid): array
    {
        $row = DB::table('messages')
            ->select('subject', 'textbody')
            ->where('id', $msgid)
            ->first();

        if (!$row) {
            return [];
        }

        $subject  = $row->subject ?? '';
        $textbody = $row->textbody ?? '';

        $itemName = DB::table('items')
            ->join('messages_items', 'items.id', '=', 'messages_items.itemid')
            ->where('messages_items.msgid', $msgid)
            ->value('items.name');

        $reasons = [];

        if ($r = $this->checkConcernKeywords($subject, $textbody, $groupid)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkPerGroupWorryWords($subject, $textbody, $groupid)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkVagueItem($itemName)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkPhoneNumbers($subject, $textbody)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkPII($subject, $textbody, $groupid)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkMessagingLinks($subject, $textbody)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkUrls($subject, $textbody)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkMoneySymbols($subject, $textbody)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkLanguage($subject, $textbody)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkSubjectRepeat($subject, $msgid)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkKnownSpammer($textbody)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkIpAbuse($msgid)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkBulkVolunteerMail($subject, $msgid)) {
            $reasons[] = $r;
        }

        return $reasons;
    }

    /**
     * Process all unprocessed pending messages in batches of 100.
     *
     * Returns stats: ['approved' => int, 'kept_pending' => int, 'blocked' => int, 'errors' => int]
     */
    public function processUnprocessed(bool $dryRun = false): array
    {
        $stats = ['approved' => 0, 'kept_pending' => 0, 'blocked' => 0, 'errors' => 0];

        DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->join('users as u', 'u.id', '=', 'm.fromuser')
            ->select('mg.msgid', 'mg.groupid', DB::raw('m.type as msgtype'), DB::raw('m.fromuser as fromuser'))
            ->where('mg.collection', MessageGroup::COLLECTION_PENDING)
            ->whereNull('mg.contentcheck_checked_at')
            ->where('mg.deleted', 0)
            ->whereNull('m.deleted')
            ->whereNotNull('m.fromuser')
            ->whereNull('u.deleted')
            ->orderBy('mg.msgid')
            ->orderBy('mg.groupid')
            ->chunk(100, function ($candidates) use (&$stats, $dryRun) {
                foreach ($candidates as $row) {
                    try {
                        $reasons     = $this->checkMessage((int) $row->msgid, (int) $row->groupid);
                        $isModerated = $this->isUserModerated((int) $row->msgid, (int) $row->groupid, (int) $row->fromuser)
                                    || $this->isGroupModerated((int) $row->groupid);
                        $promote     = empty($reasons) && !$isModerated;
                        $hasBlock    = !$promote && !empty(array_filter(
                            $reasons,
                            fn($r) => ($r['action'] ?? 'flag') === 'block'
                        ));

                        if ($dryRun) {
                            if ($promote) {
                                $stats['approved']++;
                            } elseif ($hasBlock) {
                                $stats['blocked']++;
                            } else {
                                $stats['kept_pending']++;
                            }
                            continue;
                        }

                        if ($promote) {
                            DB::transaction(function () use ($row, &$stats) {
                                DB::table('messages_groups')
                                    ->where('msgid', $row->msgid)
                                    ->where('groupid', $row->groupid)
                                    ->update([
                                        'collection'              => MessageGroup::COLLECTION_APPROVED,
                                        'approvedby'              => null,
                                        'approvedat'              => now(),
                                        'contentcheck_checked_at' => now(),
                                        'contentcheck_reasons'    => null,
                                    ]);

                                if ($row->msgtype === Message::TYPE_OFFER) {
                                    DB::table('background_tasks')->insert([
                                        'task_type' => BackgroundTask::TASK_FREEBIE_ALERTS_ADD,
                                        'data'      => json_encode(['msgid' => (int) $row->msgid]),
                                    ]);
                                }

                                $stats['approved']++;
                            });

                            Log::info("ContentCheck: approved message #{$row->msgid} on group #{$row->groupid}");
                        } elseif ($hasBlock) {
                            DB::table('messages_groups')
                                ->where('msgid', $row->msgid)
                                ->where('groupid', $row->groupid)
                                ->update([
                                    'collection'              => MessageGroup::COLLECTION_SPAM,
                                    'contentcheck_checked_at' => now(),
                                    'contentcheck_reasons'    => json_encode($reasons),
                                ]);

                            $stats['blocked']++;
                            Log::info("ContentCheck: blocked message #{$row->msgid} on group #{$row->groupid}", ['reasons' => $reasons]);
                        } else {
                            DB::transaction(function () use ($row, $reasons, &$stats) {
                                DB::table('messages_groups')
                                    ->where('msgid', $row->msgid)
                                    ->where('groupid', $row->groupid)
                                    ->update([
                                        'contentcheck_checked_at' => now(),
                                        'contentcheck_reasons'    => empty($reasons) ? null : json_encode($reasons),
                                    ]);

                                DB::table('background_tasks')->insert([
                                    'task_type' => BackgroundTask::TASK_PUSH_NOTIFY_GROUP_MODS,
                                    'data'      => json_encode(['group_id' => (int) $row->groupid]),
                                ]);

                                $stats['kept_pending']++;
                            });

                            Log::info("ContentCheck: kept pending message #{$row->msgid} on group #{$row->groupid}", ['reasons' => $reasons]);
                        }
                    } catch (\Exception $e) {
                        Log::error("ContentCheck: error processing message #{$row->msgid}: " . $e->getMessage());
                        $stats['errors']++;
                    }
                }
            });

        return $stats;
    }

    /**
     * Return true if the message's author has a moderated posting status on this group.
     * NULL or 'MODERATED' → moderated. Any explicit non-moderated value → not moderated.
     *
     * @param int      $msgid    Message ID (used to look up fromuser if not provided).
     * @param int      $groupid  Group ID.
     * @param int|null $fromuser Known fromuser value; skips the messages query when supplied.
     */
    public function isUserModerated(int $msgid, int $groupid, ?int $fromuser = null): bool
    {
        if ($fromuser === null) {
            $fromuser = DB::table('messages')->where('id', $msgid)->value('fromuser');
        }

        if (!$fromuser) {
            return true;
        }

        $status = DB::table('memberships')
            ->where('userid', $fromuser)
            ->where('groupid', $groupid)
            ->value('ourPostingStatus');

        if ($status === null || $status === '' || strtoupper($status) === 'MODERATED') {
            return true;
        }
        if (strtoupper($status) === 'PROHIBITED') {
            return true;
        }

        return false;
    }

    /**
     * Return true if the group's rules have fullymoderated = true.
     */
    public function isGroupModerated(int $groupid): bool
    {
        $rulesJson = DB::table('groups')->where('id', $groupid)->value('rules');
        if (!$rulesJson) {
            return false;
        }
        $rules = is_string($rulesJson) ? json_decode($rulesJson, true) : $rulesJson;

        return !empty($rules['fullymoderated']);
    }

    // -------------------------------------------------------------------------
    // Fuzzy keyword matching.
    // Goal: catch plurals / common inflections / single-character typos without
    // matching unrelated 1-edit neighbours of short keywords.
    //
    // For short keywords (< 6 chars) every levenshtein-1 neighbour is almost
    // always a different word ("poof"↔"roof", "lend"↔"led", "cash"↔"case"),
    // so we accept only exact matches and an explicit set of inflectional
    // suffixes. For longer keywords (≥ 6 chars) we keep the levenshtein-1
    // generosity since real typo-catching dominates the false-positive rate.
    // -------------------------------------------------------------------------

    private function matchesFuzzy(string $haystack, string $keyword): bool
    {
        $kwLower = strtolower($keyword);
        $kwLen   = strlen($kwLower);
        if ($kwLen === 0) {
            return false;
        }

        $variants = $this->inflectionVariants($kwLower);

        foreach (preg_split('/\s+/', $haystack, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            // Strip common edge punctuation so "cash," or "(money)" still match.
            $token = trim($token, ".,;:!?\"'()[]{}");
            if ($token === '') {
                continue;
            }
            $tokLow = strtolower($token);

            if ($tokLow === $kwLower) {
                return true;
            }

            if (in_array($tokLow, $variants, true)) {
                return true;
            }

            if ($kwLen >= 6) {
                $tokLen = strlen($tokLow);
                $ratio  = $tokLen / $kwLen;
                if ($ratio >= 0.75 && $ratio <= 1.25 && levenshtein($tokLow, $kwLower) <= 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Return the inflectional variants we accept as equivalent to the keyword
     * (plurals, -ing, -ed). Keeps the "catches plurals" intent of fuzzy mode
     * without admitting arbitrary 1-edit neighbours.
     */
    private function inflectionVariants(string $kwLower): array
    {
        $variants = [
            $kwLower . 's',
            $kwLower . 'es',
            $kwLower . 'ing',
            $kwLower . 'ed',
        ];
        if (strlen($kwLower) > 1 && str_ends_with($kwLower, 'y')) {
            $variants[] = substr($kwLower, 0, -1) . 'ies';
        }
        return $variants;
    }

    // -------------------------------------------------------------------------
    // Safe regex helper — logs invalid patterns and returns false rather than
    // suppressing errors silently. For keyword matches, false means no match
    // (conservative — avoids false positives). For exclude patterns, the caller
    // treats false as non-matching (conservative — still flags the message).
    // -------------------------------------------------------------------------

    private function safePreg(string $pattern, string $subject): bool
    {
        $result = @preg_match($pattern, $subject);
        if (preg_last_error() !== PREG_NO_ERROR) {
            Log::warning('ContentCheck: invalid regex pattern', [
                'pattern' => $pattern,
                'error'   => preg_last_error_msg(),
            ]);
            return false;
        }
        return $result === 1;
    }

    // -------------------------------------------------------------------------
    // Concern keywords — unified table replacing worrywords + spam_keywords.
    // Supports match_mode (fuzzy/literal/regex), global + per-group scope,
    // exclude patterns, category-specific frontend guidance, and action
    // (flag = keep pending for review; block = move to Spam collection).
    // -------------------------------------------------------------------------

    public function checkConcernKeywords(string $subject, string $textbody, int $groupid): ?array
    {
        $keywords = DB::table('concern_keywords')
            ->where(function ($q) use ($groupid) {
                $q->where('scope', 'global')
                  ->orWhere(function ($q2) use ($groupid) {
                      $q2->where('scope', 'group')->where('group_id', $groupid);
                  });
            })
            ->where('category', '!=', 'allowed')
            ->get();

        $haystack = strtolower($subject . ' ' . $textbody);
        $original = $subject . ' ' . $textbody;

        foreach ($keywords as $kw) {
            $word = trim($kw->keyword);
            if ($word === '') {
                continue;
            }

            $matched = match ($kw->match_mode) {
                'regex'   => $this->safePreg('/' . $word . '/i', $original),
                'literal' => preg_match('/\b' . preg_quote(strtolower($word), '/') . '\b/', $haystack) === 1,
                default   => $this->matchesFuzzy($haystack, $word),
            };

            if (!$matched) {
                continue;
            }

            if (!empty($kw->exclude) && $this->safePreg('/' . $kw->exclude . '/i', $original)) {
                continue;
            }

            return [
                'check'    => self::CHECK_CONCERN_KEYWORD,
                'category' => $kw->category,
                'action'   => $kw->action ?? 'flag',
                'detail'   => "Matched concern keyword '{$word}'",
            ];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Vague item name — matches keyword at start, middle, or end of name.
    // -------------------------------------------------------------------------

    public function checkVagueItem(?string $itemName): ?array
    {
        if ($itemName === null) {
            return null;
        }

        $lower = strtolower(trim($itemName));

        if (mb_strlen($lower) < 3) {
            return ['check' => self::CHECK_VAGUE, 'category' => null, 'detail' => "Item name '{$itemName}' is too short"];
        }

        foreach (self::VAGUE_KEYWORDS as $keyword) {
            if ($lower === $keyword
                || str_starts_with($lower, $keyword . ' ')
                || str_ends_with($lower, ' ' . $keyword)
                || str_contains($lower, ' ' . $keyword . ' ')
            ) {
                return ['check' => self::CHECK_VAGUE, 'category' => null, 'detail' => "Item name '{$itemName}' is too generic"];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Phone numbers — UK format check, applied to all messages.
    // Requires a proper UK prefix (0, +44, or 0044) followed by 9–10 digits
    // (with optional spaces/hyphens). This specificity avoids false positives
    // from short numeric strings like flat numbers or times.
    // -------------------------------------------------------------------------

    public function checkPhoneNumbers(string $subject, string $textbody): ?array
    {
        $haystack = $subject . ' ' . $textbody;

        if (preg_match('/\b(?:(?:\+44|0044)\s?|0)(?:\d[\s\-]?){9,10}\b/', $haystack)) {
            return [
                'check'    => self::CHECK_PHONE_NUMBER,
                'category' => null,
                'action'   => 'flag',
                'detail'   => 'Post contains what looks like a phone number',
            ];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // PII — external email addresses, only when group rule restrictpersonalinfo
    // is set. Phone numbers are checked universally via checkPhoneNumbers().
    // -------------------------------------------------------------------------

    public function checkPII(string $subject, string $textbody, int $groupid): ?array
    {
        $rulesJson = DB::table('groups')->where('id', $groupid)->value('rules');
        if (!$rulesJson) {
            return null;
        }
        $rules = is_string($rulesJson) ? json_decode($rulesJson, true) : $rulesJson;
        if (empty($rules['restrictpersonalinfo'])) {
            return null;
        }

        $haystack = $subject . ' ' . $textbody;

        if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $haystack, $m)) {
            $email  = $m[0];
            $isOurs = str_contains($email, '@ilovefreegle.org')
                   || str_contains($email, 'trashnothing')
                   || str_contains($email, 'yahoogroups');
            if (!$isOurs) {
                return [
                    'check'    => self::CHECK_EMAIL_ADDRESS,
                    'category' => null,
                    'action'   => 'flag',
                    'detail'   => 'Post contains an external email address',
                ];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Messaging app invite links
    // -------------------------------------------------------------------------

    public function checkMessagingLinks(string $subject, string $textbody): ?array
    {
        $haystack = strtolower($subject . ' ' . $textbody);

        foreach (self::MESSAGING_LINK_DOMAINS as $domain) {
            if (str_contains($haystack, $domain)) {
                return ['check' => self::CHECK_MESSAGING_LINK, 'category' => null, 'detail' => "Post contains a messaging app link ({$domain})"];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Audit mode — scan Pending + Approved and report disagreements
    // -------------------------------------------------------------------------

    /**
     * Scan existing Pending and Approved messages and report where the content
     * check service disagrees with the current state.  Read-only — no DB writes.
     *
     * @param int|null $groupid    Restrict audit to a single group (null = all groups).
     * @param int      $limit      Max rows per collection to examine (0 = no limit).
     * @param int|null $sinceDays  Only consider rows with mg.arrival within the last N days (null = no time filter).
     */
    public function auditExisting(?int $groupid = null, int $limit = 500, ?int $sinceDays = null): array
    {
        $disagreements = [];

        foreach (['Approved', 'Pending'] as $collection) {
            $query = DB::table('messages_groups as mg')
                ->join('messages as m', 'm.id', '=', 'mg.msgid')
                ->join('users as u', 'u.id', '=', 'm.fromuser')
                ->select('mg.msgid', 'mg.groupid', 'mg.collection')
                ->where('mg.collection', $collection)
                ->where('mg.deleted', 0)
                ->whereNull('m.deleted')
                ->whereNotNull('m.fromuser')
                ->whereNull('u.deleted');

            if ($groupid !== null) {
                $query->where('mg.groupid', $groupid);
            }

            if ($sinceDays !== null && $sinceDays > 0) {
                $query->where('mg.arrival', '>=', now()->subDays($sinceDays));
                $query->orderByDesc('mg.arrival');
            }

            if ($limit > 0) {
                $query->limit($limit);
            }

            $rows = $query->get();

            foreach ($rows as $row) {
                try {
                    $reasons     = $this->checkMessage((int) $row->msgid, (int) $row->groupid);
                    $isModerated = $this->isUserModerated((int) $row->msgid, (int) $row->groupid)
                                || $this->isGroupModerated((int) $row->groupid);

                    if ($collection === 'Approved' && !empty($reasons)) {
                        $disagreements[] = [
                            'msgid'        => (int) $row->msgid,
                            'groupid'      => (int) $row->groupid,
                            'collection'   => 'Approved',
                            'type'         => 'should_flag',
                            'reasons'      => $reasons,
                            'is_moderated' => $isModerated,
                        ];
                    } elseif ($collection === 'Pending' && empty($reasons) && !$isModerated) {
                        $disagreements[] = [
                            'msgid'        => (int) $row->msgid,
                            'groupid'      => (int) $row->groupid,
                            'collection'   => 'Pending',
                            'type'         => 'should_approve',
                            'reasons'      => [],
                            'is_moderated' => false,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::warning("ContentCheck audit: error on message #{$row->msgid}: " . $e->getMessage());
                }
            }
        }

        return $disagreements;
    }

    // -------------------------------------------------------------------------
    // Per-group worry words — comma-separated list in groups.settings JSON
    // under the path $.spammers.worrywords (V1 WorryWords.php parity).
    // Uses the same fuzzy matching as global concern keywords.
    // -------------------------------------------------------------------------

    public function checkPerGroupWorryWords(string $subject, string $textbody, int $groupid): ?array
    {
        $raw = DB::table('groups')
            ->where('id', $groupid)
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(settings, '$.spammers.worrywords')) AS worrywords")
            ->value('worrywords');

        if (!$raw || $raw === 'null') {
            return null;
        }

        $words    = array_filter(array_map('trim', explode(',', $raw)));
        $haystack = strtolower($subject . ' ' . $textbody);

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            if ($this->matchesFuzzy($haystack, $word)) {
                return [
                    'check'    => self::CHECK_PER_GROUP_WORRY,
                    'category' => null,
                    'action'   => 'flag',
                    'detail'   => "Matched per-group worry word '{$word}'",
                ];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // URL detection — flag messages containing untrusted URLs (V1 Spam.php parity).
    // Uses the same regex as V1's Utils::URL_PATTERN. Domains with count >= 3 in
    // spam_whitelist_links (excluding known short-link services) are trusted.
    // -------------------------------------------------------------------------

    private const URL_PATTERN = '#(?i)\b(((?:(?:http|https):(?:/{1,3}|[a-z0-9%])|www\d{0,3}[.]|[a-z0-9.\-]+[.][a-z]{2,4}/)(?:[^\s()<>]+|\(([^\s()<>]+|(\([^\s()<>]+\)))*\))+(?:\(([^\s()<>]+|(\([^\s()<>]+\)))*\)|[^\s`!()\[\]{};:\'".,<>?«»“”‘’]))|(\.com\/))#m';

    private const URL_SHORTLINK_BLOCKLIST = ['linkedin', 'goo.gl', 'bit.ly', 'tinyurl'];

    public function checkUrls(string $subject, string $textbody): ?array
    {
        $text = $subject . ' ' . $textbody;

        if (!preg_match_all(self::URL_PATTERN, $text, $matches)) {
            return null;
        }

        $trustedDomains = DB::table('spam_whitelist_links')
            ->where('count', '>=', 3)
            ->where('domain', 'not like', '%linkedin%')
            ->where('domain', 'not like', '%goo.gl%')
            ->where('domain', 'not like', '%bit.ly%')
            ->where('domain', 'not like', '%tinyurl%')
            ->where(DB::raw('LENGTH(domain)'), '>', 5)
            ->pluck('domain')
            ->map(fn ($d) => strtolower($d))
            ->toArray();

        foreach ($matches[0] as $url) {
            $lower = strtolower($url);
            $stripped = preg_replace('#^https?://#i', '', $lower);

            $trusted = false;
            foreach ($trustedDomains as $domain) {
                if (str_starts_with($stripped, $domain)) {
                    $trusted = true;
                    break;
                }
            }

            if (!$trusted) {
                return [
                    'check'    => self::CHECK_URL,
                    'category' => null,
                    'action'   => 'flag',
                    'detail'   => 'Post contains an untrusted URL',
                ];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Money symbols — flag £ or $ in subject or body (V1 Spam.php parity).
    // -------------------------------------------------------------------------

    public function checkMoneySymbols(string $subject, string $textbody): ?array
    {
        $text = $subject . ' ' . $textbody;

        if (str_contains($text, '£') || str_contains($text, '$')) {
            return [
                'check'    => self::CHECK_MONEY,
                'category' => null,
                'action'   => 'flag',
                'detail'   => 'Post contains a money symbol',
            ];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Language detection — flag non-English/Welsh messages over 50 chars
    // (V1 Spam.php parity using patrickschur/language-detection).
    // English is accepted if it is the top language, or if P(en|cy) >= 0.8 *
    // P(top language) — the same lax threshold V1 uses.
    // -------------------------------------------------------------------------

    public function checkLanguage(string $subject, string $textbody): ?array
    {
        $text = trim(str_ireplace('xxx', '', strtolower($textbody)));

        if (strlen($text) <= 50) {
            return null;
        }

        try {
            $ld   = new Language();
            $lang = $ld->detect($text)->close();

            if (empty($lang)) {
                return null;
            }

            reset($lang);
            $firstLang = key($lang);
            $firstProb = $lang[$firstLang] ?? 0;
            $enProb    = $lang['en'] ?? 0;
            $cyProb    = $lang['cy'] ?? 0;
            $ourProb   = max($enProb, $cyProb);

            $isAcceptable = ($firstLang === 'en' || $firstLang === 'cy' || $ourProb >= 0.8 * $firstProb);

            if (!$isAcceptable) {
                return [
                    'check'    => self::CHECK_LANGUAGE,
                    'category' => null,
                    'action'   => 'flag',
                    'detail'   => "Post appears to be in language '{$firstLang}' rather than English or Welsh",
                ];
            }
        } catch (\Exception $e) {
            Log::warning('ContentCheck: language detection error: ' . $e->getMessage());
        }

        return null;
    }

    public function checkIpAbuse(int $msgid): ?array
    {
        $fromip = DB::table('messages')->where('id', $msgid)->value('fromip');

        if (!$fromip) {
            return null;
        }

        // IP used by 5+ different users
        $userCount = DB::table('messages')
            ->where('fromip', $fromip)
            ->whereNotNull('fromuser')
            ->distinct('fromuser')
            ->count();

        if ($userCount > 5) {
            return [
                'check'    => self::CHECK_IP_ABUSE,
                'category' => null,
                'action'   => 'flag',
                'detail'   => "IP {$fromip} recently used by {$userCount} different user accounts",
            ];
        }

        // IP used to post to 20+ different groups
        $groupCount = DB::table('messages_groups')
            ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
            ->where('messages.fromip', $fromip)
            ->distinct('messages_groups.groupid')
            ->count();

        if ($groupCount >= 20) {
            return [
                'check'    => self::CHECK_IP_ABUSE,
                'category' => null,
                'action'   => 'flag',
                'detail'   => "IP {$fromip} recently used to post to {$groupCount} different groups",
            ];
        }

        return null;
    }

    public function checkBulkVolunteerMail(string $subject, int $msgid): ?array
    {
        $msg = DB::table('messages')->where('id', $msgid)->first();

        if (!$msg || !$msg->envelopeto) {
            return null;
        }

        // Only check volunteer address messages
        if (!str_contains($msg->envelopeto, '-volunteers@ilovefreegle.org')) {
            return null;
        }

        // Check sender sending to 20+ volunteer addresses in 24h
        $senderCount = DB::table('messages')
            ->where('envelopefrom', $msg->envelopefrom)
            ->where('envelopeto', 'like', '%-volunteers@ilovefreegle.org')
            ->where('arrival', '>=', now()->subHours(24))
            ->count();

        if ($senderCount >= 20) {
            return [
                'check'    => self::CHECK_BULK_MAIL,
                'category' => null,
                'action'   => 'flag',
                'detail'   => "Sender {$msg->envelopefrom} has mailed {$senderCount} group volunteer addresses in 24h",
            ];
        }

        // Check subject sent to 20+ volunteer addresses in 24h
        $subjectCount = DB::table('messages')
            ->where('subject', $subject)
            ->where('envelopeto', 'like', '%-volunteers@ilovefreegle.org')
            ->where('arrival', '>=', now()->subHours(24))
            ->count();

        if ($subjectCount >= 20) {
            return [
                'check'    => self::CHECK_BULK_MAIL,
                'category' => null,
                'action'   => 'flag',
                'detail'   => "Subject '{$subject}' has been sent to {$subjectCount} group volunteer addresses in 24h",
            ];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // checkSubjectRepeat — flag mass-submission spam (V1 parity)
    // -------------------------------------------------------------------------

    public function checkSubjectRepeat(string $subject, int $msgid): ?array
    {
        // Don't check very short subjects - might be something like "TAKEN"
        if (strlen(trim($subject)) < 10) {
            return null;
        }

        // Count distinct groups with same subject in the past N days
        $distinctGroupCount = DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->where('m.subject', $subject)
            ->where('mg.arrival', '>=', now()->subDays(self::SUBJECT_REPEAT_WINDOW))
            ->where('mg.deleted', 0)
            ->distinct('mg.groupid')
            ->count();

        if ($distinctGroupCount >= self::SUBJECT_THRESHOLD) {
            return [
                'check'    => self::CHECK_SUBJECT_REPEAT,
                'category' => null,
                'action'   => 'flag',
                'detail'   => "Subject recently posted to {$distinctGroupCount} different groups",
            ];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // checkKnownSpammer — flag messages containing spammer email (V1 parity)
    // -------------------------------------------------------------------------

    public function checkKnownSpammer(string $textbody): ?array
    {
        // Extract all email addresses from the text
        if (!preg_match_all('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $textbody, $matches)) {
            return null;
        }

        // Check each email against the spam_users table
        foreach ($matches[0] as $email) {
            $spammer = DB::table('spam_users')
                ->join('users_emails', 'spam_users.userid', '=', 'users_emails.userid')
                ->where('spam_users.collection', 'Spammer')
                ->where('users_emails.email', $email)
                ->first();

            if ($spammer) {
                return [
                    'check'    => self::CHECK_KNOWN_SPAMMER,
                    'category' => null,
                    'action'   => 'flag',
                    'detail'   => "Message references known spammer email: {$email}",
                ];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // checkGreetingSpam — greeting + link pattern (V1 Spam.php parity)
    // Detects classic spam pattern: greeting (hello, hi, hey, good X, etc.) + HTTP link
    // -------------------------------------------------------------------------

    public function checkGreetingSpam(string $subject, string $textbody): ?array
    {
        $text = strtolower($subject . ' ' . $textbody);

        // Check for greeting in subject or first line of body
        $hasGreeting = false;
        foreach (self::GREETING_KEYWORDS as $greeting) {
            if (str_contains($text, $greeting)) {
                $hasGreeting = true;
                break;
            }
        }

        if (!$hasGreeting) {
            return null;
        }

        // Check for HTTP/PHP link
        if (preg_match('#https?://#i', $subject . ' ' . $textbody) ||
            preg_match('#www\d{0,3}[.]#', $subject . ' ' . $textbody)) {
            return [
                'check'    => self::CHECK_GREETING_SPAM,
                'category' => null,
                'action'   => 'flag',
                'detail'   => 'Post contains greeting combined with HTTP link (classic spam pattern)',
            ];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // checkImageSpam — duplicate image hash in 24 hours (V1 MailRouter.php parity)
    // Detects when the same image (by hash) has been used more than 5 times in 24h
    // -------------------------------------------------------------------------

    public function checkImageSpam(int $msgid): ?array
    {
        // Get all image hashes attached to this message
        $hashes = DB::table('messages_attachments')
            ->where('msgid', $msgid)
            ->whereNotNull('hash')
            ->pluck('hash')
            ->toArray();

        if (empty($hashes)) {
            return null;
        }

        // For each hash, check if it's been used more than 5 times in the last 24 hours
        foreach ($hashes as $hash) {
            $count = DB::table('messages_attachments as ma')
                ->join('messages as m', 'm.id', '=', 'ma.msgid')
                ->where('ma.hash', $hash)
                ->where('m.arrival', '>=', now()->subHours(24))
                ->count();

            if ($count > 5) {
                return [
                    'check'    => self::CHECK_IMAGE_SPAM,
                    'category' => null,
                    'action'   => 'flag',
                    'detail'   => "Image hash {$hash} has been used {$count} times in the last 24 hours",
                ];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // checkSpamhaus — Spamhaus DBL lookup (V1 Spam.php parity)
    // For each URL in the message, do a DNS lookup: {domain}.zen.spamhaus.org
    // If the lookup returns an A record (not NXDOMAIN), the domain is blocked.
    //
    // The dnsLookup parameter allows for test mocking. If not provided, uses PHP's
    // dns_get_record() function. For testing, pass a closure that returns DNS results.
    // -------------------------------------------------------------------------

    public function checkSpamhaus(string $subject, string $textbody, ?callable $dnsLookup = null): ?array
    {
        $text = $subject . ' ' . $textbody;

        // Extract URLs using the same pattern as checkUrls
        if (!preg_match_all(self::URL_PATTERN, $text, $matches)) {
            return null;
        }

        // Default DNS lookup using PHP's dns_get_record
        if ($dnsLookup === null) {
            $dnsLookup = function (string $domain): array {
                $checkDomain = $domain . '.zen.spamhaus.org';
                // Suppress warnings from dns_get_record
                $result = @dns_get_record($checkDomain, DNS_A);
                return $result ?: [];
            };
        }

        foreach ($matches[0] as $url) {
            // Extract domain from URL
            $urlLower = strtolower($url);
            // Remove protocol
            $stripped = preg_replace('#^https?://#i', '', $urlLower);
            // Remove trailing path
            $domain = preg_replace('#/.*$#', '', $stripped);
            // Remove www prefix for cleaner lookups
            $domain = preg_replace('#^www\d{0,3}\.#', '', $domain);

            // Check Spamhaus
            $dnsResult = $dnsLookup($domain);
            if (!empty($dnsResult)) {
                return [
                    'check'    => self::CHECK_SPAMHAUS_DBL,
                    'category' => null,
                    'action'   => 'flag',
                    'detail'   => "Domain {$domain} is listed in Spamhaus DBL",
                ];
            }
        }

        return null;
    }
}
