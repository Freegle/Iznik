<?php

namespace App\Services;

use App\Models\BackgroundTask;
use App\Models\Message;
use App\Models\MessageGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nitotm\Eld\LanguageDetector;

class ContentCheckService
{
    public function __construct(
        private readonly ?ContentEmbeddingService $embeddingService = null,
        private readonly ?MessageSpatialService $messageSpatialService = null,
    ) {}

    public const CHECK_CONCERN_KEYWORD    = 'ConcernKeyword';
    public const CHECK_VAGUE             = 'Vague';
    public const CHECK_PHONE_NUMBER      = 'PhoneNumber';
    public const CHECK_EMAIL_ADDRESS     = 'EmailAddress';
    public const CHECK_MESSAGING_LINK    = 'MessagingLink';
    public const CHECK_PER_GROUP_WORRY   = 'PerGroupWorryWord';
    public const CHECK_URL               = 'Url';
    public const CHECK_MONEY             = 'Money';
    public const CHECK_LANGUAGE          = 'Language';
    public const CHECK_NOT_AN_ITEM       = 'NotAnItem';

    // Not content problems - these explain a hold that the member's or group's
    // moderation settings caused, so the mod queue says why (Discourse #9987).
    public const CHECK_MEMBER_MODERATED  = 'MemberModerated';
    public const CHECK_GROUP_MODERATED   = 'GroupModerated';
    public const CHECK_NO_LOCATION       = 'NoLocation';

    /**
     * Candidate languages for the content-check language detector. Restricted to
     * languages realistically seen on UK Freegle — English/Welsh, the main UK
     * community languages, and frequent spam origins — so the detector cannot
     * rank constructed/obscure languages (Interlingua, Occitan, Esperanto, Ido,
     * Latin) top on short English and raise a false "not English" flag
     * (Discourse #9481). Scandinavian (nb/nn/da/sv) is intentionally kept.
     */
    private const LANGUAGE_DETECT_SET = [
        'en', 'cy', 'ga', 'gd', 'fr', 'de', 'nl', 'es', 'pt-BR', 'pt-PT', 'it',
        'pl', 'ro', 'cs', 'sk', 'lt', 'lv', 'bg', 'hu', 'hr', 'sl', 'sr-Latn',
        'uk', 'ru', 'ar', 'fa', 'ur', 'tr', 'so', 'he', 'zh-Hans', 'zh-Hant',
        'ja', 'ko', 'vi', 'th', 'tl', 'id', 'ms-Latn', 'hi', 'bn', 'gu', 'ta',
        'ml', 'sq', 'el-monoton', 'sv', 'da', 'nb', 'nn', 'fi', 'et', 'eu',
        'ca', 'gl',
    ];
    public const CHECK_BULK_MAIL         = 'BulkMail';
    public const CHECK_SUBJECT_REPEAT    = 'SubjectRepeat';
    public const CHECK_KNOWN_SPAMMER     = 'KnownSpammer';
    public const CHECK_GREETING_SPAM     = 'GreetingSpam';
    public const CHECK_IMAGE_SPAM        = 'ImageSpam';
    public const CHECK_SPAMHAUS_DBL      = 'SpamhausDBL';

    private const SUBJECT_THRESHOLD = 30;
    private const SUBJECT_REPEAT_WINDOW = 7; // days

    private const VAGUE_KEYWORDS = [
        'stuff', 'thing', 'item', 'junk', 'bits', 'various', 'misc',
        'miscellaneous', 'anything', 'assorted',
        'free stuff', 'free items', 'bits and pieces',
        'this and that', 'unwanted', 'clutter', 'rubbish', 'tat',
        // Category qualifiers — non-rescuing per design: "household items" still flags.
        'household', 'general',
        // "any/all" detection happens here (item-name only) rather than in the
        // per-group worry-word list, where "take all"/"any colour" body matches
        // were noisy.
        'any', 'all', 'everything',
        // Other vocabulary gaps that map to "I haven't told you what it is".
        'goods', 'sundries', 'bric-a-brac', 'odds and ends',
        // Content-free responses/fillers dropped into the item box ("Yes", "No",
        // "Please", "Thanks"). A single such token is the whole item; multi-word
        // names are rescued by any real noun, per the token logic below.
        'yes', 'yeah', 'yep', 'yup', 'no', 'nope', 'nah', 'ok', 'okay',
        'please', 'pls', 'ta', 'thanks', 'cheers',
    ];

    // Ambiguous single-token entries: each one has many legitimate uses
    // ("baby bundle", "stamp collection", "lots of seedlings"). Treat as
    // vague only when they co-occur with another (non-ambiguous) vague
    // token in the same item name.
    private const VAGUE_AMBIGUOUS = [
        'bundle', 'collection', 'lots', 'random', 'loads',
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
        if ($r = $this->checkNotAnItem($subject, $textbody, $itemName)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkPhoneNumbers($subject, $textbody, $groupid)) {
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
        if ($r = $this->checkSubjectRepeat($subject, $msgid, $itemName)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkKnownSpammer($textbody)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkBulkVolunteerMail($subject, $msgid)) {
            $reasons[] = $r;
        }

        return $this->dedupeReasons($reasons);
    }

    /**
     * Collapse reasons that flag the SAME keyword more than once.
     *
     * Concern keywords and the legacy per-group worry words overlap: a per-group
     * worry word that has been migrated into concern_keywords (scope=group) is
     * matched by BOTH checkConcernKeywords and checkPerGroupWorryWords, producing
     * two reasons ("Matched concern keyword 'x'" and "Matched per-group worry
     * word 'x'") for the one word. Keep a single reason per keyword, preferring
     * the richer ConcernKeyword (it carries a category that drives mod guidance).
     * Reasons without a keyword (Vague, PhoneNumber, …) are never de-duplicated.
     */
    private function dedupeReasons(array $reasons): array
    {
        $indexByKeyword = [];
        $out = [];

        foreach ($reasons as $reason) {
            $keyword = isset($reason['keyword']) ? strtolower(trim((string) $reason['keyword'])) : '';

            if ($keyword === '') {
                $out[] = $reason;
                continue;
            }

            if (!isset($indexByKeyword[$keyword])) {
                $indexByKeyword[$keyword] = count($out);
                $out[] = $reason;
                continue;
            }

            // Already have a reason for this keyword; prefer ConcernKeyword.
            $existingIndex = $indexByKeyword[$keyword];
            if ($reason['check'] === self::CHECK_CONCERN_KEYWORD
                && $out[$existingIndex]['check'] !== self::CHECK_CONCERN_KEYWORD) {
                $out[$existingIndex] = $reason;
            }
        }

        return $out;
    }

    /**
     * Run the text-based content checks relevant to a chat message and return
     * the first failure reason (or null when clean).
     *
     * This is the chat analogue of checkMessage(). Chat messages live in
     * chat_messages (not the messages table) and carry no group, item, IP or
     * bulk-mail context, so only the text checks apply. Global concern keywords
     * are used (groupid 0). Mirrors the checks V1 ChatMessage::process() ran via
     * Spam::checkReview() for Moderated members, plus messaging-app-link
     * detection.
     *
     * Phone numbers are deliberately NOT checked in chat: sharing a phone
     * number is normal and expected when arranging a handover, so flagging it
     * produced too many false positives. (V1's Spam::checkReview() never
     * checked phone numbers either.) The checkPhoneNumbers() check runs for
     * posts via checkMessage(), but only when the group's restrictpersonalinfo
     * rule is set.
     *
     * @return array|null Reason ['check','category','action','detail'] or null.
     */
    public function checkChatMessage(string $message): ?array
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        return $this->checkConcernKeywords('', $message, 0)
            ?? $this->checkUrls('', $message)
            ?? $this->checkMessagingLinks('', $message)
            ?? $this->checkMoneySymbols('', $message)
            ?? $this->checkKnownSpammer($message)
            ?? $this->checkLanguage('', $message);
    }

    /**
     * Only NEW approved-on-arrival posts are content-checked (bounded by arrival),
     * so the historical backlog of already-live posts is never rescanned.
     */
    private const APPROVED_CHECK_WINDOW_HOURS = 24;

    /**
     * Process all unprocessed messages in batches of 100.
     *
     * Covers Pending posts awaiting their first check, and NEW Approved-on-arrival
     * posts (e.g. from unmoderated members) that bypass the Pending queue - those
     * are checked too but never auto-demoted; problems are surfaced to mods.
     *
     * Returns stats: ['approved' => int, 'kept_pending' => int, 'blocked' => int,
     *                 'checked_approved' => int, 'flagged_approved' => int,
     *                 'checked_held' => int, 'flagged_held' => int, 'errors' => int]
     */
    public function processUnprocessed(bool $dryRun = false): array
    {
        $stats = [
            'approved'         => 0,
            'kept_pending'     => 0,
            'blocked'          => 0,
            'checked_approved' => 0,
            'flagged_approved' => 0,
            'checked_held'     => 0,
            'flagged_held'     => 0,
            'errors'           => 0,
        ];

        // Per-row processing, shared by the two candidate queries below.
        $processChunk = function ($candidates) use (&$stats, $dryRun) {
                foreach ($candidates as $row) {
                    try {
                        $reasons = $this->checkMessage((int) $row->msgid, (int) $row->groupid);

                        // A moderator is holding this copy: record what the check found so
                        // they get the reasons, but never promote or block it - that would
                        // take the post out from under them (9816/9815).
                        if ($row->heldby !== null) {
                            $this->recordCheckOnly($row, $reasons, $dryRun, $stats, 'held');
                            continue;
                        }

                        // Already-live (Approved-on-arrival) posts: content-check them but
                        // never auto-demote a post members can already see. Clean -> just
                        // record the check; any reasons -> store them and notify mods.
                        if ($row->collection === MessageGroup::COLLECTION_APPROVED) {
                            $this->recordCheckOnly($row, $reasons, $dryRun, $stats, 'approved');
                            continue;
                        }

                        $userModerated  = $this->isUserModerated((int) $row->msgid, (int) $row->groupid, (int) $row->fromuser);
                        $groupModerated = $this->isGroupModerated((int) $row->groupid);
                        $isModerated    = $userModerated || $groupModerated;
                        // Never auto-promote an Offer/Wanted we couldn't locate (NULL lat -
                        // subject didn't geocode and no usable poster fallback): it would go
                        // live undiscoverable. Keep it in the mod queue so a moderator adds a
                        // postcode via the "add a postcode" prompt (Discourse #9865).
                        $missingLocation = $row->lat === null
                                        && in_array($row->msgtype, ['Offer', 'Wanted'], true);
                        $promote     = empty($reasons) && !$isModerated && !$missingLocation;
                        $hasBlock    = !$promote && !empty(array_filter(
                            $reasons,
                            fn($r) => ($r['action'] ?? 'flag') === 'block'
                        ));

                        // A post held for a STATUS reason rather than a content reason used to
                        // store no reasons at all, so it arrived in the mod queue with nothing
                        // saying why - "there is no explanation of why the post needs Approval"
                        // (Discourse #9987). Record the cause too. Appended after $hasBlock is
                        // computed so it can never turn a flag into a block.
                        if (!$promote && !$hasBlock) {
                            $reasons = array_merge(
                                $reasons,
                                $this->holdReasons($userModerated, $groupModerated, $missingLocation)
                            );
                        }

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

                                // Clearance/bulk-offer posts are excluded from freebiealerts.app.
                                if ($row->msgtype === Message::TYPE_OFFER &&
                                    !DB::table('messages_bulk_items')->where('msgid', $row->msgid)->exists()) {
                                    DB::table('background_tasks')->insert([
                                        'task_type' => BackgroundTask::TASK_FREEBIE_ALERTS_ADD,
                                        'data'      => json_encode(['msgid' => (int) $row->msgid]),
                                    ]);
                                }

                                // Now Approved — add to the spatial index immediately so the
                                // post shows in browse/search without waiting for the periodic
                                // messages:update-spatial-index reconciler.
                                ($this->messageSpatialService ?? app(MessageSpatialService::class))->addApprovedMessage((int) $row->msgid);

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
        };

        // The old single query OR'd the two cases (Pending, or recent Approved)
        // together. No index leads with the selective predicate, so MySQL could
        // only satisfy the ORDER BY mg.msgid + LIMIT by walking the `deleted`
        // index - millions of rows - re-filtering each (EXPLAIN: ~4.59M rows,
        // filtered 2.5%). Splitting into two passes lets each use an existing
        // index. Which posts get checked, and how, is unchanged.
        $base = fn () => DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->join('users as u', 'u.id', '=', 'm.fromuser')
            ->select('mg.msgid', 'mg.groupid', 'mg.collection', 'mg.heldby', DB::raw('m.type as msgtype'), DB::raw('m.fromuser as fromuser'), DB::raw('m.lat as lat'))
            // Either never checked, or checked and then edited. The edit stamps
            // messages.editedat rather than clearing the check stamp, because the
            // stamp is also what lets a moderator see the post at all - clearing it
            // made a post vanish from the queue of the moderator who had just edited
            // it (Discourse 10001). "Edited since checked" is derived by comparing
            // the two timestamps, so there is no separate mark to clear and the state
            // cannot drift; re-stamping contentcheck_checked_at on completion resolves
            // the comparison by itself. Both cases need the same scan, so both are
            // picked up here. The OR is not the driving predicate: each pass below
            // leads with collection or arrival, so the index choice is unchanged and
            // the row set is already small.
            ->where(function ($q) {
                $q->whereNull('mg.contentcheck_checked_at')
                    ->orWhereColumn('m.editedat', '>', 'mg.contentcheck_checked_at');
            })
            ->where('mg.deleted', 0)
            // Held messages ARE checked - checking is not acting. Skipping them entirely
            // (the old "never fight a mod" rule, 9816/9815) left contentcheck_checked_at
            // NULL for as long as the hold lasted, so the moderator holding the post never
            // saw why it needed a look, and surfaces that count only checked rows reported
            // fewer held posts than were in front of them (Discourse 9481/635). What must
            // not happen is re-promoting or blocking it out from under them - see the
            // heldby branch in the processing loop, which records the result and stops.
            ->whereNull('m.deleted')
            ->whereNotNull('m.fromuser')
            ->whereNull('u.deleted')
            ->orderBy('mg.msgid')
            ->orderBy('mg.groupid');

        // Pending posts awaiting a check - their first, or a fresh one after an edit.
        // Served by the single-column `collection` index; being a secondary index it
        // returns rows already ordered by the appended (msgid, groupid) clustered key,
        // so no filesort, and Pending is the small live mod queue.
        $base()
            ->where('mg.collection', MessageGroup::COLLECTION_PENDING)
            ->chunk(100, $processChunk);

        // NEW approved-on-arrival posts, bounded to recent arrivals so the
        // historical backlog of live posts is never rescanned. Served by the
        // `arrival` index range over just the recent window.
        $base()
            ->where('mg.collection', MessageGroup::COLLECTION_APPROVED)
            ->where('mg.arrival', '>', now()->subHours(self::APPROVED_CHECK_WINDOW_HOURS))
            ->chunk(100, $processChunk);

        return $stats;
    }

    /**
     * Record the content check WITHOUT acting on the post - used where changing the
     * collection would be wrong:
     *   'approved' - already live, and we never demote a post members can already see;
     *   'held'     - a moderator has claimed it, and promoting or blocking it would take
     *                it out from under them (9816/9815).
     * Either way a clean post is simply stamped as checked, and a post with reasons keeps
     * its reasons stored and notifies the group's mods so a human can review it. Storing
     * the reasons is the point for a held post: it is what tells the moderator holding it
     * why it needed a look (Discourse 9481/635).
     *
     * @param string $kind 'approved' or 'held' - selects which stats counters to bump.
     */
    private function recordCheckOnly(object $row, array $reasons, bool $dryRun, array &$stats, string $kind = 'approved'): void
    {
        $hasReasons = !empty($reasons);
        $flaggedKey = 'flagged_' . $kind;
        $checkedKey = 'checked_' . $kind;

        if ($dryRun) {
            $stats[$hasReasons ? $flaggedKey : $checkedKey]++;
            return;
        }

        if ($hasReasons) {
            DB::transaction(function () use ($row, $reasons, &$stats, $flaggedKey) {
                DB::table('messages_groups')
                    ->where('msgid', $row->msgid)
                    ->where('groupid', $row->groupid)
                    ->update([
                        'contentcheck_checked_at' => now(),
                        'contentcheck_reasons'    => json_encode($reasons),
                    ]);

                DB::table('background_tasks')->insert([
                    'task_type' => BackgroundTask::TASK_PUSH_NOTIFY_GROUP_MODS,
                    'data'      => json_encode(['group_id' => (int) $row->groupid]),
                ]);

                $stats[$flaggedKey]++;
            });

            Log::info("ContentCheck: flagged {$kind} message #{$row->msgid} on group #{$row->groupid}", ['reasons' => $reasons]);
            return;
        }

        DB::table('messages_groups')
            ->where('msgid', $row->msgid)
            ->where('groupid', $row->groupid)
            ->update([
                'contentcheck_checked_at' => now(),
                'contentcheck_reasons'    => null,
            ]);

        $stats[$checkedKey]++;
    }

    /**
     * Return true if the message's author has a moderated posting status on this group.
     * NULL or 'MODERATED' → moderated. Any explicit non-moderated value → not moderated.
     *
     * @param int      $msgid    Message ID (used to look up fromuser if not provided).
     * @param int      $groupid  Group ID.
     * @param int|null $fromuser Known fromuser value; skips the messages query when supplied.
     */
    /**
     * Why a post is being kept pending when the content itself was clean.
     *
     * Without these a moderator sees a post sitting in the queue with no
     * indication of what put it there, which is what Discourse #9987 reported.
     * These are 'flag', never 'block' - they explain a hold, they don't cause one.
     *
     * @return array<int, array{check:string, category:null, action:string, detail:string}>
     */
    private function holdReasons(bool $userModerated, bool $groupModerated, bool $missingLocation): array
    {
        $reasons = [];

        if ($groupModerated) {
            $reasons[] = [
                'check'    => self::CHECK_GROUP_MODERATED,
                'category' => null,
                'action'   => 'flag',
                'detail'   => 'This group moderates all posts, whatever the member\'s setting',
            ];
        }

        // Only worth saying if the group isn't moderating everything anyway.
        if ($userModerated && !$groupModerated) {
            $reasons[] = [
                'check'    => self::CHECK_MEMBER_MODERATED,
                'category' => null,
                'action'   => 'flag',
                'detail'   => 'This member\'s posts are moderated',
            ];
        }

        if ($missingLocation) {
            $reasons[] = [
                'check'    => self::CHECK_NO_LOCATION,
                'category' => null,
                'action'   => 'flag',
                'detail'   => 'We could not work out where this post is - add a postcode before approving',
            ];
        }

        return $reasons;
    }

    public function isUserModerated(int $msgid, int $groupid, ?int $fromuser = null): bool
    {
        if ($fromuser === null) {
            $fromuser = DB::table('messages')->where('id', $msgid)->value('fromuser');
        }

        if (!$fromuser) {
            return true;
        }

        $membership = DB::table('memberships')
            ->where('userid', $fromuser)
            ->where('groupid', $groupid)
            ->first(['ourPostingStatus']);

        if ($membership === null) {
            // No membership row at all. On every Freegle-native path a post only exists
            // because a member posted to their own group, so a missing membership is
            // unexpected and stays moderated. A TrashNothing API post is different by
            // design: GroupPostIngestionService places it on the group its coordinates
            // resolve to (Location::groupsNear()), not one the poster chose, so the
            // poster frequently isn't a member and ingestion falls back to the same
            // 'DEFAULT' a brand-new member gets. Calling that moderated would strand
            // every non-member TN post in the mod queue, because these posts now arrive
            // Pending awaiting this check and nothing else would ever promote them.
            return !$this->isTrashNothingPost($msgid);
        }

        $status = $membership->ourPostingStatus;

        if ($status === null || $status === '' || strtoupper($status) === 'MODERATED') {
            return true;
        }
        if (strtoupper($status) === 'PROHIBITED') {
            return true;
        }

        return false;
    }

    /**
     * True if the message came from the TrashNothing API ingestion path
     * (GroupPostIngestionService stamps messages.tnpostid). Only consulted when the
     * poster has no membership on the group - see isUserModerated().
     */
    private function isTrashNothingPost(int $msgid): bool
    {
        return DB::table('messages')->where('id', $msgid)->value('tnpostid') !== null;
    }

    /**
     * Return true if the group's "All Posts Moderated" setting is on.
     *
     * This must read settings.moderated — the enforcement setting ModTools
     * writes and apiv2 checks — NOT rules.fullymoderated, which is the
     * member-facing rules questionnaire answer ("Do you moderate all posts?")
     * and routinely disagrees with the real setting (Discourse #9987: groups
     * with the setting off had every post held because their questionnaire
     * said yes, and vice versa).
     */
    public function isGroupModerated(int $groupid): bool
    {
        $settingsJson = DB::table('groups')->where('id', $groupid)->value('settings');
        if (!$settingsJson) {
            return false;
        }
        $settings = is_string($settingsJson) ? json_decode($settingsJson, true) : $settingsJson;

        return !empty($settings['moderated']);
    }

    // -------------------------------------------------------------------------
    // Fuzzy keyword matching.
    // Goal: catch plurals / common inflections / single-character typos without
    // matching unrelated 1-edit neighbours of short keywords.
    //
    // For keywords < 8 chars every levenshtein-1 neighbour is almost always a
    // different word ("poof"↔"roof", "lend"↔"led", "cash"↔"case", "formic"↔
    // "formica", "rocket"↔"socket", "selling"↔"telling"), so we accept only
    // exact matches and an explicit set of inflectional suffixes. For keywords
    // ≥ 8 chars (mostly chemistry/plant names) we keep levenshtein-1 typo-
    // tolerance since real typo-catching dominates the false-positive rate.
    // -------------------------------------------------------------------------

    private const FUZZY_LEVENSHTEIN_MIN_KW_LEN = 8;

    /**
     * Strip every 'allowed'-category concern keyword (global, plus this
     * group's, when a group is given) from the text, word-boundary anchored
     * and case-insensitive. Run before any flagging scan so whitelisted
     * phrases - typically place names like 'Cashes Green' or 'Butt Road' -
     * can't feed their words to literal/regex/fuzzy keyword matches.
     * Replaced with a space so the surrounding words stay separated.
     */
    private function removeAllowedKeywords(string $text, int $groupid): string
    {
        $allowed = DB::table('concern_keywords')
            ->where(function ($q) use ($groupid) {
                $q->where('scope', 'global')
                  ->orWhere(function ($q2) use ($groupid) {
                      $q2->where('scope', 'group')->where('group_id', $groupid);
                  });
            })
            ->where('category', 'allowed')
            ->pluck('keyword');

        foreach ($allowed as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase === '') {
                continue;
            }
            $text = (string) preg_replace(
                '/\b' . preg_quote($phrase, '/') . '\b/i',
                ' ',
                $text
            );
        }

        return $text;
    }

    private function matchesFuzzy(string $haystack, string $keyword): bool
    {
        $kwLower = strtolower($keyword);
        $kwLen   = strlen($kwLower);
        if ($kwLen === 0) {
            return false;
        }

        // Multi-word phrases: token-by-token matching can never match a phrase
        // like "discounted price" against individual haystack tokens. Use a
        // word-boundary-anchored phrase match instead (Discourse #9620/283).
        if (str_contains($kwLower, ' ')) {
            return (bool) preg_match('/\b' . preg_quote($kwLower, '/') . '\b/', $haystack);
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

            if ($kwLen >= self::FUZZY_LEVENSHTEIN_MIN_KW_LEN) {
                $tokLen = strlen($tokLow);
                $ratio  = $tokLen / $kwLen;
                if ($ratio >= 0.75 && $ratio <= 1.25 && $this->damerauLevenshtein($tokLow, $kwLower) <= 1) {
                    // Reject initial-consonant swaps: "hangers" vs "bangers" differ only
                    // at position 0 and produce a completely different word, not a typo.
                    $firstDiff = null;
                    $minLen    = min(strlen($tokLow), strlen($kwLower));
                    for ($i = 0; $i < $minLen; $i++) {
                        if ($tokLow[$i] !== $kwLower[$i]) {
                            $firstDiff = $i;
                            break;
                        }
                    }
                    if ($firstDiff !== 0) {
                        return true;
                    }
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
        ];
        $vowels = 'aeiou';
        $len    = strlen($kwLower);

        if ($len > 1 && str_ends_with($kwLower, 'y')) {
            $variants[] = substr($kwLower, 0, -1) . 'ies';
        }

        if (str_ends_with($kwLower, 'e')) {
            // English: drop the trailing 'e' before -ing; add only 'd' for -ed.
            // Avoids producing wrong forms like "trueed" / "trueing".
            $variants[] = $kwLower . 'd';
            $variants[] = substr($kwLower, 0, -1) . 'ing';
        } else {
            $variants[] = $kwLower . 'ed';
            $variants[] = $kwLower . 'ing';
            // CVC rule: for words ending consonant-vowel-consonant (e.g. "swap"),
            // double the final consonant before -ed/-ing ("swapped", "swapping").
            if ($len >= 3) {
                $last = $kwLower[$len - 1];
                $pen  = $kwLower[$len - 2];
                if (!str_contains($vowels, $last) && str_contains($vowels, $pen)) {
                    $variants[] = $kwLower . $last . 'ed';
                    $variants[] = $kwLower . $last . 'ing';
                }
            }
        }

        return $variants;
    }

    /**
     * Optimal string alignment distance (restricted Damerau-Levenshtein).
     * Counts insertions, deletions, substitutions, and adjacent transpositions
     * each as cost 1. Catches "cannibas"↔"cannabis" (transposition) as distance 1
     * where standard levenshtein would score 2.
     */
    private function damerauLevenshtein(string $a, string $b): int
    {
        $la = strlen($a);
        $lb = strlen($b);

        if ($la === 0) {
            return $lb;
        }
        if ($lb === 0) {
            return $la;
        }

        // d[$i][$j] = edit distance between a[0..$i-1] and b[0..$j-1]
        $d = [];
        for ($i = 0; $i <= $la; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $lb; $j++) {
            $d[0][$j] = $j;
        }

        for ($i = 1; $i <= $la; $i++) {
            for ($j = 1; $j <= $lb; $j++) {
                $cost = ($a[$i - 1] === $b[$j - 1]) ? 0 : 1;
                $d[$i][$j] = min(
                    $d[$i - 1][$j] + 1,        // deletion
                    $d[$i][$j - 1] + 1,        // insertion
                    $d[$i - 1][$j - 1] + $cost // substitution
                );
                if ($i > 1 && $j > 1
                    && $a[$i - 1] === $b[$j - 2]
                    && $a[$i - 2] === $b[$j - 1]
                ) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + $cost); // transposition
                }
            }
        }

        return $d[$la][$lb];
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

    // Like safePreg, but returns the substring the pattern actually matched
    // rather than a bool. Regex-mode concern keywords store a PATTERN (e.g.
    // 'crack\s+cocaine'), not a literal word, so the mod-facing reason needs
    // what the pattern matched in the post text, not the pattern itself -
    // otherwise the flag notice reads as regex soup (Discourse #10024).
    private function safePregCapture(string $pattern, string $subject): ?string
    {
        $result = @preg_match($pattern, $subject, $matches);
        if (preg_last_error() !== PREG_NO_ERROR) {
            Log::warning('ContentCheck: invalid regex pattern', [
                'pattern' => $pattern,
                'error'   => preg_last_error_msg(),
            ]);
            return null;
        }
        return $result === 1 ? $matches[0] : null;
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

        // 'allowed'-category entries are a whitelist: text matching them is
        // removed BEFORE scanning, so a flagging keyword can't fire on a word
        // inside a whitelisted phrase. V1's worry words and the Go display path
        // both do this; this path only excluded allowed rows from the flagger
        // list, which left the whitelist with no effect - 'Cashes Green' kept
        // tripping the fuzzy keyword 'cash' via its 'cashes' inflection
        // (Discourse 9944).
        $original = $this->removeAllowedKeywords($subject . ' ' . $textbody, $groupid);
        $haystack = strtolower($original);

        foreach ($keywords as $kw) {
            $word = trim($kw->keyword);
            if ($word === '') {
                continue;
            }

            // For regex mode, $word is a PATTERN rather than the literal text
            // to display - capture what it actually matched so the mod-facing
            // reason names real text from the post, not the pattern.
            $matchedText = null;

            $matched = match ($kw->match_mode) {
                'regex'   => ($matchedText = $this->safePregCapture('/' . $word . '/i', $original)) !== null,
                'literal' => preg_match('/\b' . preg_quote(strtolower($word), '/') . '\b/', $haystack) === 1,
                default   => $this->matchesFuzzy($haystack, $word),
            };

            if (!$matched) {
                continue;
            }

            if (!empty($kw->exclude) && $this->safePreg('/' . $kw->exclude . '/i', $original)) {
                continue;
            }

            // Contextual check: if the embedding service identifies this as an
            // innocent use of the keyword (e.g. "glue gun" vs real weapon),
            // skip the flag. Falls back to flagging when the sidecar is absent.
            if ($this->embeddingService?->isInnocentContext($original, $kw->category)) {
                continue;
            }

            $displayWord = $matchedText ?? $word;

            return [
                'check'    => self::CHECK_CONCERN_KEYWORD,
                'category' => $kw->category,
                'action'   => $kw->action ?? 'flag',
                'keyword'  => $displayWord,
                'detail'   => "Matched concern keyword '{$displayWord}'",
            ];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Vague item name.
    //
    // Flag only if every "significant" token in the name is itself a vague
    // keyword. A token is significant if it is more than 2 characters, not a
    // stopword, and not purely numeric. Short item names like "TV" or "PC"
    // therefore pass through (no significant tokens), and names that include
    // a specific noun ("Assorted picture frames", "Marilyn monroe stuff")
    // pass because the specific token rescues them.
    // -------------------------------------------------------------------------

    private const VAGUE_STOPWORDS = [
        'of', 'and', 'the', 'to', 'for', 'a', 'an', 'or', 'with', 'in', 'on', 'at', 'by',
    ];

    private const TOKEN_SPLIT_PATTERN = '/[\s,;\-\/!?()\.]+/';

    public function checkVagueItem(?string $itemName): ?array
    {
        if ($itemName === null) {
            return null;
        }

        $trimmed = trim($itemName);
        if ($trimmed === '') {
            return null;
        }

        $lower = strtolower($trimmed);

        $tokens = preg_split(self::TOKEN_SPLIT_PATTERN, $lower, -1, PREG_SPLIT_NO_EMPTY);

        $vagueSet     = $this->vagueTokenSet();
        $ambiguousSet = $this->ambiguousTokenSet();

        $significantCount = 0;
        $unambiguousVague = 0;
        $allVague         = true;

        foreach ($tokens as $token) {
            if (in_array($token, self::VAGUE_STOPWORDS, true)) {
                continue;
            }
            if (preg_match('/^\d+$/', $token)) {
                continue;
            }
            $isShort = mb_strlen($token) <= 2;

            if ($isShort) {
                // Short vague tokens (e.g. "any", "all" — but those are 3 chars
                // so they wouldn't land here; left for symmetry) count as vague.
                // Short non-vague tokens (TV, PC) rescue: identify a specific
                // category and shouldn't be treated as vague-by-default.
                if (isset($vagueSet[$token])) {
                    $significantCount++;
                    $unambiguousVague++;
                } else {
                    $allVague = false;
                    break;
                }
                continue;
            }

            $significantCount++;

            if (isset($vagueSet[$token])) {
                $unambiguousVague++;
            } elseif (isset($ambiguousSet[$token])) {
                // Counted as significant but only "vague" when paired with an
                // unambiguous vague token elsewhere in the item.
            } else {
                $allVague = false;
                break;
            }
        }

        if ($significantCount === 0 || !$allVague) {
            return null;
        }

        // Ambiguous tokens alone (e.g. "bundle", "stamp collection") don't
        // flag — they need an unambiguous vague companion to convert into a
        // real signal.
        if ($unambiguousVague === 0) {
            return null;
        }

        return ['check' => self::CHECK_VAGUE, 'category' => null, 'detail' => "Item name '{$itemName}' is too generic"];
    }

    /**
     * Flat token set built from VAGUE_KEYWORDS plus their inflectional
     * variants (item→items, thing→things) so we don't have to hand-maintain
     * plurals.
     */
    private function vagueTokenSet(): array
    {
        static $set = null;
        if ($set === null) {
            $set = $this->buildTokenSet(self::VAGUE_KEYWORDS);
        }
        return $set;
    }

    private function ambiguousTokenSet(): array
    {
        static $set = null;
        if ($set === null) {
            $set = $this->buildTokenSet(self::VAGUE_AMBIGUOUS);
        }
        return $set;
    }

    private function buildTokenSet(array $keywords): array
    {
        $set = [];
        foreach ($keywords as $kw) {
            // Split each keyword the same way checkVagueItem splits its
            // input — that way hyphenated entries like "bric-a-brac"
            // contribute "bric" and "brac" to the set, matching the input
            // tokens after they get split on the hyphen.
            $tokens = preg_split(self::TOKEN_SPLIT_PATTERN, strtolower($kw), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($tokens as $t) {
                if (in_array($t, self::VAGUE_STOPWORDS, true)) {
                    continue;
                }
                $set[$t] = true;
                foreach ($this->inflectionVariants($t) as $variant) {
                    $set[$variant] = true;
                }
            }
        }
        return $set;
    }

    // -------------------------------------------------------------------------
    // Not-an-item — flag posts that are non-physical requests/offers (services,
    // accommodation/rentals, jobs/work, help/advice) rather than a physical
    // object. Freegle is for giving away things, so these are diverted to mods
    // for review (action=flag, NOT auto-blocked). Keyword design is word-boundary
    // + exclusion-guarded, validated against the production reject log to avoid
    // false positives on real items: "vacuum cleaner" is not a cleaner-person,
    // "removal boxes" is not a removal service, "job lot" is a bundle of goods,
    // "dinner service" is crockery, and "ladder loan" is borrowing an item
    // (which Freegle allows).
    // -------------------------------------------------------------------------

    /** Physical-item phrases that collide with non-item keywords — skip these. */
    private const NOT_AN_ITEM_EXCLUSIONS = [
        '/\b(vacuum|patio|window|oven|carpet|drain|fabric|pressure|jet|steam|spot|paint|tile|glass|leather|toilet|kitchen|shower|hoover|floor|wheel|pool|gutter|bbq|grill|mould|mold|nit|comb)\s+cleaner/',
        '/\b(removal|moving|packing|cardboard|home|house|strong|storage|archive)\s+(box|boxes|crate|crates|bag|bags|paper)/',
        '/\b(hair|paint|stain|tick|rust|odou?r|live|nit|mole|wart|graffiti|ear\s?wax)\s+removal/',
        '/\bremoval\s+(box|boxes|cream|kit|tool|tools)/',
        '/\bjob\s*lot\b/',
        '/\b(dinner|tea|coffee|table|place|china|dining)\s+service\b/',
        '/\b(loan|borrow|lend)\b/',
        '/gardener[\'’]?s\s+world/',
        '/\bdecorator[\'’]?s?\s+(spare|spares|tool|tools|paint|table|caddy|kit|trestle)/',
        '/\b(motorway|emergency|bus|train|rail|ferry|postal|customer|council|nhs|social|financial|funeral|armed|secret|support|care|delivery)\s+services?\b/',
    ];

    /** Non-item trigger patterns, grouped by category; first match wins. */
    private const NOT_AN_ITEM_PATTERNS = [
        'accommodation' => [
            '/\b(room|rooms|flat|house|garage|lock\s?-?\s?up|warehouse|studio|annexe?|bedsit|bedroom|property|driveway|parking\s+space)\b[^.!?\n]{0,30}\b(to|for)\s+(rent|let)\b/',
            '/\bto let\b/', '/\bfor rent\b/', '/\bto rent\b/', '/\brenting\b/',
            '/\blodger\b/', '/\bflat\s?share\b/', '/\bhouse\s?share\b/',
            '/\baccommodation\b/', '/\btenant\b/', '/\broom available\b/',
        ],
        'service' => [
            '/\bman\s+(with|and)\s+a?\s?(van|car)\b/', '/\bman\s*&\s*van\b/',
            '/\b(cleaner|gardener|plumber|electrician|decorator|builder|tutor|handyman|hairdresser|barber|painter|joiner|locksmith)\s+(wanted|needed|required|available)\b/',
            '/\b(need(ed)?|looking\s+for|want(ed)?|require[d]?)\s+a\s+(cleaner|gardener|plumber|electrician|decorator|builder|tutor|handyman|babysitter|child\s?minder|dog\s?walker|hairdresser|barber)\b/',
            '/\b(domestic|house|home|office|end[\s-]of[\s-]tenancy)\s+cleaner\b/',
            '/\b(cleaning|gardening|ironing|babysitting|child\s?minding|tutoring|decorating|plumbing|catering|delivery|moving)\s+service\b/',
            '/\bdog\s?walk(er|ing)\b/', '/\bbaby\s?sit(ter|ting)\b/',
            '/\bchild\s?mind(er|ing)\b/', '/\bhandy\s?man\b/', '/\bmassage\b/',
            '/\bskill\s?swap\b/', '/\bservices\b/', '/\bservice\s+offered\b/',
        ],
        'work' => [
            '/\bvacanc(y|ies)\b/', '/\bhiring\b/', '/\bwork\s+wanted\b/',
            '/\b(part|full)[\s-]?time\s+job\b/', '/\bemployment\b/',
            '/\bjob\s+vacancy\b/', '/\blooking\s+for\s+work\b/', '/\bzero\s+hours?\b/',
        ],
        'advice' => [
            '/\badvice\b/', '/\bany\s+recommendations?\b/',
            '/\bcan\s+(anyone|someone)\s+recommend\b/',
            '/\blooking\s+for\s+(advice|recommendations?|someone\s+to)\b/',
            '/\brecommendations?\s+for\s+a\b/',
        ],
    ];

    /**
     * Flag a post that appears to be a non-physical request/offer rather than an
     * item. Pure text; returns a flag reason (kept Pending for mod review) or null.
     */
    public function checkNotAnItem(string $subject, string $textbody, ?string $itemName = null): ?array
    {
        $hay = strtolower(trim($subject . ' ' . $textbody . ' ' . ($itemName ?? '')));
        if ($hay === '') {
            return null;
        }

        // Exclusion guard: physical items that would otherwise match a keyword.
        foreach (self::NOT_AN_ITEM_EXCLUSIONS as $ex) {
            if (preg_match($ex, $hay)) {
                return null;
            }
        }

        foreach (self::NOT_AN_ITEM_PATTERNS as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $hay, $m)) {
                    $matched = trim($m[0]);
                    return [
                        'check'    => self::CHECK_NOT_AN_ITEM,
                        'category' => $category,
                        'action'   => 'flag',
                        'detail'   => "Post may be a non-physical request ({$category}) rather than an item — matched \"{$matched}\"",
                    ];
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Phone numbers — UK format check, applied to posts only (NOT chat: sharing
    // a number to arrange a handover is normal). Requires a proper UK prefix
    // (0, +44, or 0044) followed by 9–10 digits (with optional spaces/hyphens).
    // This specificity avoids false positives from short numeric strings like
    // flat numbers or times.
    //
    // Only flagged when the group's restrictpersonalinfo rule is set — phone
    // numbers are explicitly called out in that setting's description ("eg
    // telephone numbers, addresses"). Groups without the rule never see this
    // flag. (V1 had no universal phone-number check; the setting description
    // makes the intent clear.)
    // -------------------------------------------------------------------------

    public function checkPhoneNumbers(string $subject, string $textbody, int $groupid): ?array
    {
        $rulesJson = DB::table('groups')->where('id', $groupid)->value('rules');
        if ($rulesJson) {
            $rules = is_string($rulesJson) ? json_decode($rulesJson, true) : $rulesJson;
            if (empty($rules['restrictpersonalinfo'])) {
                return null;
            }
        } else {
            return null;
        }

        $haystack = $subject . ' ' . $textbody;

        // (?<!\d) / (?!\d) instead of \b — \b doesn't fire before a literal "+"
        // (non-word/non-word boundary), which made "Ring +44 ..." slip through.
        if (preg_match('/(?<!\d)(?:(?:\+44|0044)\s?|0)(?:\d[\s\-]?){9,10}(?!\d)/', $haystack)) {
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
    // PII — external email addresses, gated by the group rule restrictpersonalinfo.
    // Phone numbers are also gated by the same rule via checkPhoneNumbers().
    // Both checks are described by the setting: "Do you restrict personal info
    // in posts eg telephone numbers, addresses?"
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
        // Same whitelist cleaning as checkConcernKeywords: an allowed phrase
        // must neutralise per-group worry words too (the Go display path
        // applies Allowed removal to the combined global+group list).
        $haystack = strtolower($this->removeAllowedKeywords($subject . ' ' . $textbody, $groupid));

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            if ($this->matchesFuzzy($haystack, $word)) {
                return [
                    'check'    => self::CHECK_PER_GROUP_WORRY,
                    'category' => null,
                    'action'   => 'flag',
                    'keyword'  => $word,
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
    // Language detection — flag a message as non-English/Welsh ONLY when the
    // detector is confident. Uses nitotm/efficient-language-detector (ELD): it
    // picks the correct top language on short, list-style Freegle posts where the
    // old trigram library (patrickschur) mis-ranked plain English as a Latinate
    // language (Discourse #9919, #9481), and its isReliable() lets us leave
    // genuinely ambiguous short text alone rather than false-flagging it.
    // The optional $detector callable returns ['lang' => code, 'reliable' => bool];
    // used in tests for deterministic results.
    // -------------------------------------------------------------------------

    public function checkLanguage(string $subject, string $textbody, ?callable $detector = null): ?array
    {
        $text = trim(str_ireplace('xxx', '', $textbody));

        // Skip very short text — low spam risk and inherently ambiguous for any
        // language detector, so checking it only generates false flags (#9481).
        if (strlen($text) <= 80) {
            return null;
        }

        try {
            $detect = $detector ?? static function (string $t): array {
                static $eld = null;
                $eld ??= new LanguageDetector();
                $res = $eld->detect($t);

                return ['lang' => $res->language, 'reliable' => $res->isReliable()];
            };
            $result   = $detect($text);
            $lang     = $result['lang'] ?? '';
            $reliable = (bool) ($result['reliable'] ?? false);

            // Flag only when the detector is confident the text is neither English
            // nor Welsh. Ambiguous text (isReliable() === false) is never flagged.
            if ($lang !== '' && $lang !== 'en' && $lang !== 'cy' && $reliable) {
                return [
                    'check'    => self::CHECK_LANGUAGE,
                    'category' => null,
                    'action'   => 'flag',
                    'detail'   => "Post appears to be in language '{$lang}' rather than English or Welsh",
                ];
            }
        } catch (\Exception $e) {
            Log::warning('ContentCheck: language detection error: ' . $e->getMessage());
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

    public function checkSubjectRepeat(string $subject, int $msgid, ?string $itemName = null): ?array
    {
        // V1 parity: use the item name (pruned subject) for the length guard, not the full
        // subject. The type prefix ("Offer: ") adds 7+ chars, making "Offer: Test" 11 chars
        // but the actual item is "Test" (4 chars). Without this, test subjects accumulate
        // across many groups over time and falsely flag legitimate mod/tester posts.
        $textToCheck = ($itemName !== null && trim($itemName) !== '') ? trim($itemName) : trim($subject);
        if (strlen($textToCheck) < 10) {
            return null;
        }

        // Count distinct groups with same subject in the past N days.
        // Exclude rippled-in rows (messages_groups.rippled_in = 1): rippling-out
        // (ExpandService::rippleIntoNewGroups) inserts one messages_groups row
        // per nearby group for the SAME message, so a single post fans out to
        // 20-30 groups sharing one subject — which otherwise trips this check
        // as if it were mass-submission spam (Discourse #9808/250). Only native
        // (rippled_in = 0) postings count; genuine cross-group spam still has
        // rippled_in = 0 rows and is unaffected.
        $distinctGroupCount = DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->where('m.subject', $subject)
            ->where('mg.arrival', '>=', now()->subDays(self::SUBJECT_REPEAT_WINDOW))
            ->where('mg.deleted', 0)
            ->where('mg.rippled_in', 0)
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
