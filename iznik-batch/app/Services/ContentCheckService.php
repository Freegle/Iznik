<?php

namespace App\Services;

use App\Services\Mail\Incoming\SpamCheckService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContentCheckService
{
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

    public function __construct(private SpamCheckService $spamCheck) {}

    /**
     * Run all content checks for a single (msgid, groupid) pair.
     *
     * Returns array of failure reasons — empty means clean.
     * Each reason: ['check' => string, 'detail' => string]
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

        if ($r = $this->checkWorryWords($subject, $textbody, $groupid)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkConcernKeywords($subject, $textbody)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkSpamKeywords($subject, $textbody)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkReview($subject, $textbody)) {
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
            ->where('mg.collection', 'Pending')
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
                            'collection'              => 'Approved',
                            'approvedby'              => null,
                            'approvedat'              => now(),
                            'arrival'                 => now(),
                            'contentcheck_checked_at' => now(),
                            'contentcheck_reasons'    => null,
                        ]);

                    if ($row->msgtype === 'Offer') {
                        DB::table('background_tasks')->insert([
                            'task_type' => 'freebie_alerts_add',
                            'data'      => json_encode(['msgid' => $row->msgid]),
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
                        'task_type' => 'push_notify_group_mods',
                        'data'      => json_encode(['group_id' => $row->groupid]),
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
    // Worry words (global + per-group from settings.spammers.worrywords)
    // -------------------------------------------------------------------------

    public function checkWorryWords(string $subject, string $textbody, ?int $groupid): ?array
    {
        $words = DB::table('worrywords')->get();

        $groupWords = [];
        if ($groupid) {
            $raw = DB::table('groups')
                ->where('id', $groupid)
                ->value(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(settings, '$.spammers.worrywords'))"));
            if ($raw && $raw !== 'null') {
                foreach (explode(',', $raw) as $w) {
                    $w = trim($w);
                    if ($w !== '') {
                        $groupWords[] = (object) ['keyword' => strtolower($w), 'type' => 'Review'];
                    }
                }
            }
        }

        $allWords = array_merge($words->all(), $groupWords);
        $haystack = strtolower($subject . ' ' . $textbody);

        foreach ($allWords as $word) {
            $kw = strtolower($word->keyword);
            if (str_contains($haystack, $kw)) {
                return ['check' => 'WorryWord', 'detail' => "Matched worry word '{$kw}' (type: {$word->type})"];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Concern keywords (global — regulated/spam items)
    // -------------------------------------------------------------------------

    public function checkConcernKeywords(string $subject, string $textbody): ?array
    {
        $keywords = DB::table('concern_keywords')->get();
        $haystack = strtolower($subject . ' ' . $textbody);

        foreach ($keywords as $kw) {
            $word = strtolower($kw->keyword);
            if (str_contains($haystack, $word)) {
                return ['check' => 'ConcernKeyword', 'detail' => "Matched concern keyword '{$word}' (type: {$kw->type}; action: {$kw->action})"];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Spam keywords (spam_keywords table)
    // -------------------------------------------------------------------------

    public function checkSpamKeywords(string $subject, string $textbody): ?array
    {
        $result = $this->spamCheck->checkSpamKeywords($subject . ' ' . $textbody, [
            SpamCheckService::ACTION_SPAM,
            SpamCheckService::ACTION_REVIEW,
        ]);

        if ($result === null) {
            return null;
        }

        [, , $detail] = $result;

        return ['check' => 'SpamKeyword', 'detail' => $detail];
    }

    // -------------------------------------------------------------------------
    // Review checks — money symbols, untrusted links, external email addresses
    // -------------------------------------------------------------------------

    public function checkReview(string $subject, string $textbody): ?array
    {
        $reason = $this->spamCheck->checkReview($subject . ' ' . $textbody, false);
        if ($reason === null) {
            return null;
        }

        return ['check' => 'Review', 'detail' => "Content review triggered: {$reason}"];
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
            return ['check' => 'Vague', 'detail' => "Item name '{$itemName}' is too short"];
        }

        foreach (self::VAGUE_KEYWORDS as $keyword) {
            if ($lower === $keyword
                || str_starts_with($lower, $keyword . ' ')
                || str_ends_with($lower, ' ' . $keyword)
            ) {
                return ['check' => 'Vague', 'detail' => "Item name '{$itemName}' is too generic"];
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
            return ['check' => 'PhoneNumber', 'detail' => 'Post contains what looks like a phone number'];
        }

        // External email address detection.
        if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $haystack, $m)) {
            $email   = $m[0];
            $isOurs  = str_contains($email, '@ilovefreegle.org')
                    || str_contains($email, 'trashnothing')
                    || str_contains($email, 'yahoogroups');
            if (!$isOurs) {
                return ['check' => 'EmailAddress', 'detail' => 'Post contains an external email address'];
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
                return ['check' => 'MessagingLink', 'detail' => "Post contains a messaging app link ({$domain})"];
            }
        }

        return null;
    }
}
