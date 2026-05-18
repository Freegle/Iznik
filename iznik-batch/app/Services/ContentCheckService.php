<?php

namespace App\Services;

use App\Models\BackgroundTask;
use App\Models\Message;
use App\Models\MessageGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContentCheckService
{
    public const CHECK_CONCERN_KEYWORD = 'ConcernKeyword';
    public const CHECK_VAGUE           = 'Vague';
    public const CHECK_PHONE_NUMBER    = 'PhoneNumber';
    public const CHECK_EMAIL_ADDRESS   = 'EmailAddress';
    public const CHECK_MESSAGING_LINK  = 'MessagingLink';

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

    /**
     * Run all content checks for a single (msgid, groupid) pair.
     *
     * Returns array of failure reasons — empty means clean.
     * Each reason: ['check' => string, 'category' => string|null, 'detail' => string]
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
        if ($r = $this->checkVagueItem($itemName)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkPII($subject, $textbody, $groupid)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkMessagingLinks($subject, $textbody)) {
            $reasons[] = $r;
        }

        return $reasons;
    }

    /**
     * Process all unprocessed pending messages.
     *
     * Returns stats: ['approved' => int, 'kept_pending' => int, 'errors' => int]
     */
    public function processUnprocessed(bool $dryRun = false): array
    {
        $stats = ['approved' => 0, 'kept_pending' => 0, 'errors' => 0];

        $candidates = DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->join('users as u', 'u.id', '=', 'm.fromuser')
            ->select('mg.msgid', 'mg.groupid', 'm.type as msgtype')
            ->where('mg.collection', MessageGroup::COLLECTION_PENDING)
            ->whereNull('mg.contentcheck_checked_at')
            ->where('mg.deleted', 0)
            ->whereNull('m.deleted')
            ->whereNotNull('m.fromuser')
            ->whereNull('u.deleted')
            ->get();

        foreach ($candidates as $row) {
            try {
                $reasons     = $this->checkMessage((int) $row->msgid, (int) $row->groupid);
                $isModerated = $this->isUserModerated((int) $row->msgid, (int) $row->groupid)
                            || $this->isGroupModerated((int) $row->groupid);
                $promote     = empty($reasons) && !$isModerated;

                if ($dryRun) {
                    $promote ? $stats['approved']++ : $stats['kept_pending']++;
                    continue;
                }

                if ($promote) {
                    DB::table('messages_groups')
                        ->where('msgid', $row->msgid)
                        ->where('groupid', $row->groupid)
                        ->update([
                            'collection'              => MessageGroup::COLLECTION_APPROVED,
                            'approvedby'              => null,
                            'approvedat'              => now(),
                            'arrival'                 => now(),
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
                    Log::info("ContentCheck: approved message #{$row->msgid} on group #{$row->groupid}");
                } else {
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
                    Log::info("ContentCheck: kept pending message #{$row->msgid} on group #{$row->groupid}", ['reasons' => $reasons]);
                }
            } catch (\Exception $e) {
                Log::error("ContentCheck: error processing message #{$row->msgid}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Return true if the message's author has a moderated posting status on this group.
     * NULL or 'MODERATED' → moderated. Any explicit non-moderated value → not moderated.
     */
    public function isUserModerated(int $msgid, int $groupid): bool
    {
        $fromuser = DB::table('messages')->where('id', $msgid)->value('fromuser');
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
    // Fuzzy keyword matching — V1 WorryWords parity.
    // Splits the haystack into tokens and accepts a token if its levenshtein
    // distance from the keyword is ≤ 1 AND its length is within ±25% of the
    // keyword length. This catches plurals and single-character typos without
    // the false positives of bare str_contains (e.g. "hash" vs "hashtagging").
    // -------------------------------------------------------------------------

    private function matchesFuzzy(string $haystack, string $keyword): bool
    {
        $kwLower = strtolower($keyword);
        $kwLen   = strlen($kwLower);
        if ($kwLen === 0) {
            return false;
        }

        foreach (preg_split('/\s+/', $haystack, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $tokLen = strlen($token);
            $ratio  = $tokLen / $kwLen;
            if ($ratio >= 0.75 && $ratio <= 1.25 && levenshtein($token, $kwLower) <= 1) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Concern keywords — unified table replacing worrywords + spam_keywords.
    // Supports match_mode (fuzzy/literal/regex), global + per-group scope,
    // exclude patterns, and category-specific frontend guidance.
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
                'regex'  => @preg_match('/' . $word . '/i', $original) === 1,
                'literal' => preg_match('/\b' . preg_quote(strtolower($word), '/') . '\b/', $haystack) === 1,
                default  => $this->matchesFuzzy($haystack, $word),
            };

            if (!$matched) {
                continue;
            }

            if (!empty($kw->exclude) && @preg_match('/' . $kw->exclude . '/i', $original)) {
                continue;
            }

            return [
                'check'    => self::CHECK_CONCERN_KEYWORD,
                'category' => $kw->category,
                'detail'   => "Matched concern keyword '{$word}'",
            ];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Vague item name
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
            ) {
                return ['check' => self::CHECK_VAGUE, 'category' => null, 'detail' => "Item name '{$itemName}' is too generic"];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // PII — phone numbers and email addresses (when group rule restrictpersonalinfo)
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

        // UK phone number detection — broad pattern covering mobile and landline formats.
        if (preg_match('/\b(?:(?:\+44|0044)\s?|0)(?:\d[\s\-]?){9,10}\b/', $haystack)) {
            return ['check' => self::CHECK_PHONE_NUMBER, 'category' => null, 'detail' => 'Post contains what looks like a phone number'];
        }

        // External email address detection.
        if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $haystack, $m)) {
            $email   = $m[0];
            $isOurs  = str_contains($email, '@ilovefreegle.org')
                    || str_contains($email, 'trashnothing')
                    || str_contains($email, 'yahoogroups');
            if (!$isOurs) {
                return ['check' => self::CHECK_EMAIL_ADDRESS, 'category' => null, 'detail' => 'Post contains an external email address'];
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
     * @param int|null $groupid  Restrict audit to a single group (null = all groups).
     * @param int      $limit    Max rows per collection to examine (0 = no limit).
     */
    public function auditExisting(?int $groupid = null, int $limit = 500): array
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
}
