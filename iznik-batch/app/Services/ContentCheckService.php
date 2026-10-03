<?php

namespace App\Services;

use App\Models\BackgroundTask;
use App\Models\Message;
use App\Services\Judgement\Judge;
use App\Services\Judgement\Subject;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Nitotm\Eld\LanguageDetector;

class ContentCheckService
{
    public function __construct(
        private readonly ?ContentEmbeddingService $embeddingService = null,
        private readonly ?MessageSpatialService $messageSpatialService = null,
        private readonly ?Judge $judge = null,
        private readonly ?TakedownService $takedownService = null,
    ) {}

    public const CHECK_PHONE_NUMBER      = 'PhoneNumber';
    public const CHECK_EMAIL_ADDRESS     = 'EmailAddress';
    public const CHECK_MESSAGING_LINK    = 'MessagingLink';
    public const CHECK_URL               = 'Url';
    public const CHECK_MONEY             = 'Money';
    public const CHECK_LANGUAGE          = 'Language';

    // Not content problems - these explain a hold that the member's or group's
    // moderation settings caused, so the mod queue says why (Discourse #9987).
    public const CHECK_MEMBER_MODERATED  = 'MemberModerated';
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
    public const CHECK_IMAGE_SPAM        = 'ImageSpam';
    public const CHECK_SPAMHAUS_DBL      = 'SpamhausDBL';
    public const CHECK_JUDGE             = 'Judge';
    public const CHECK_JUDGEMENT_UNAVAILABLE = 'JudgementUnavailable';

    private const SUBJECT_THRESHOLD = 30;
    private const SUBJECT_REPEAT_WINDOW = 7; // days

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
     * Run all content checks for a single message.
     *
     * Returns array of failure reasons — empty means clean.
     * Each reason: ['check' => string, 'category' => string|null, 'action' => string, 'detail' => string]
     */
    public function checkMessage(int $msgid): array
    {
        $row = DB::table('messages')
            ->select('subject', 'textbody', 'type')
            ->where('id', $msgid)
            ->first();

        if (!$row) {
            return [];
        }

        $subject  = $row->subject ?? '';
        $textbody = $row->textbody ?? '';
        $msgtype  = $row->type ?? null;

        $itemName = DB::table('items')
            ->join('messages_items', 'items.id', '=', 'messages_items.itemid')
            ->where('messages_items.msgid', $msgid)
            ->value('items.name');

        $reasons = [];

        // The AI judge (ai-judgement.md) replaces every word-list check that used to run
        // here. checkConcernKeywords, checkVagueItem, checkNotAnItem, checkGreetingSpam and
        // their keyword constants/tables were deleted outright on 2026-09-27 (concern_keywords
        // and spam_keywords are unread by this class now; the tables are the coordinator's to
        // drop). checkMessage() stays side-effect free: it is also called read-only by the
        // audit command, so a 'takedown' verdict is surfaced as a reason for
        // processUnprocessed() to act on, never acted on here.
        if ($r = $this->checkWithJudge($msgid, $subject, $textbody, $itemName, $msgtype)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkPhoneNumbers($subject, $textbody)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkPII($subject, $textbody)) {
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

        return $reasons;
    }

    /**
     * Ask the AI judge (ai-judgement.md) the configured yes/no questions about
     * this post and translate its verdict into a reason, or null when the
     * judge is unavailable or every answer is a plain no.
     *
     * freegle.judgement.questions maps question id => ['outcome' => 'takedown'|'wait', ...].
     * A takedown-outcome question only takes down when its yes-confidence is
     * at/above freegle.judgement.threshold; below threshold it is downgraded to
     * 'wait' (same reason text) rather than dropped, so a shaky "yes" still
     * reaches a moderator instead of promoting silently. A wait-outcome
     * question (unsafe, vague) has no escalation path - any plain yes is
     * 'wait', since wait is already the mildest outcome.
     *
     * Never call the judge with a member identifier, email or location - the
     * Subject built here carries none of those by construction.
     *
     * @return array{check:string, category:string|null, action:string, detail:string}|null
     */
    private function checkWithJudge(int $msgid, string $subject, string $textbody, ?string $itemName, ?string $msgtype): ?array
    {
        $judgeSubject = new Subject(
            kind: Subject::KIND_POST,
            title: $subject,
            body: $textbody,
            itemName: $itemName,
            postType: $msgtype,
            photos: $this->fetchPhotosForJudge($msgid),
        );

        $judge = $this->judge ?? app(Judge::class);
        $verdict = $judge->judge($judgeSubject);

        if (!$verdict->available) {
            return [
                'check'    => self::CHECK_JUDGE,
                'category' => self::CHECK_JUDGEMENT_UNAVAILABLE,
                'action'   => 'wait',
                'detail'   => 'The AI content judge was unavailable, so only the deterministic checks ran on this post',
            ];
        }

        $threshold = (float) config('freegle.judgement.threshold', 0.8);
        $questions = (array) config('freegle.judgement.questions', []);

        $takedownIds = [];
        $waitIds = [];
        foreach ($judgeSubject->questionIds() as $id) {
            $outcome = $questions[$id]['outcome'] ?? 'wait';
            if ($outcome === 'takedown') {
                $takedownIds[] = $id;
            } else {
                $waitIds[] = $id;
            }
        }

        $hit = $verdict->firstTakedownReason($takedownIds, $threshold);
        if ($hit !== null) {
            return [
                'check'    => self::CHECK_JUDGE,
                'category' => $hit['id'],
                'action'   => 'takedown',
                'detail'   => $hit['reason'] ?? "The AI judge found this post breaks the site rule '{$hit['id']}'",
            ];
        }

        foreach (array_merge($takedownIds, $waitIds) as $id) {
            $answer = $verdict->answer($id);
            if ($answer !== null && $answer['answer'] === 'yes') {
                return [
                    'check'    => self::CHECK_JUDGE,
                    'category' => $id,
                    'action'   => 'wait',
                    'detail'   => $answer['reason'] ?? "The AI judge flagged this post against the site rule '{$id}'",
                ];
            }
        }

        return null;
    }

    /**
     * Up to three photos for this post, base64-encoded, for the judge to see -
     * "photos are attached when the post has them" (ai-judgement.md). Reuses
     * EeeVisionService's delivery-proxy URL builder, since that is already the
     * one place that turns an attachment's externaluid into a fetchable URL.
     *
     * Fetched concurrently; a fetch failure or a non-image response for one
     * photo is dropped silently rather than failing the whole check - a
     * missing photo just means the judge answers from text alone, same as
     * a post with no photos.
     *
     * @return array<int, array{base64: string, mime_type: string}>
     */
    private function fetchPhotosForJudge(int $msgid): array
    {
        $externaluids = DB::table('messages_attachments')
            ->where('msgid', $msgid)
            ->orderByDesc('primary')
            ->orderBy('id')
            ->limit(3)
            ->pluck('externaluid')
            ->all();

        if (empty($externaluids)) {
            return [];
        }

        $urls = array_map(fn ($uid) => EeeVisionService::buildImageUrl($uid), $externaluids);

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn ($url) => $pool->timeout(10)->get($url),
            $urls
        ));

        $photos = [];
        foreach ($responses as $response) {
            if ($response instanceof \Throwable || !$response->successful()) {
                continue;
            }
            $mime = trim(explode(';', $response->header('Content-Type') ?? '')[0]);
            if (!str_starts_with($mime, 'image/')) {
                continue;
            }
            $photos[] = ['base64' => base64_encode($response->body()), 'mime_type' => $mime];
        }

        return $photos;
    }

    /**
     * Run the text-based content checks relevant to a chat message and return
     * the first failure reason (or null when clean).
     *
     * This is the chat analogue of checkMessage(). Chat messages live in
     * chat_messages (not the messages table) and carry no group, item, IP or
     * bulk-mail context, so only the text checks apply. The AI judge is not
     * used here (ai-judgement.md scopes it to posts/events/reports; a chat
     * message has no subject and is usually too short for a useful verdict).
     * Mirrors the checks V1 ChatMessage::process() ran via Spam::checkReview()
     * for Moderated members, plus messaging-app-link detection.
     *
     * Phone numbers are deliberately NOT checked in chat: sharing a phone
     * number is normal and expected when arranging a handover, so flagging it
     * produced too many false positives. (V1's Spam::checkReview() never
     * checked phone numbers either.) The checkPhoneNumbers() check runs
     * unconditionally for posts via checkMessage().
     *
     * @return array|null Reason ['check','category','action','detail'] or null.
     */
    public function checkChatMessage(string $message): ?array
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        return $this->checkUrls('', $message)
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
     *                 'taken_down' => int, 'checked_approved' => int,
     *                 'flagged_approved' => int, 'checked_held' => int,
     *                 'flagged_held' => int, 'errors' => int]
     */
    public function processUnprocessed(bool $dryRun = false): array
    {
        $stats = [
            'approved'         => 0,
            'kept_pending'     => 0,
            'blocked'          => 0,
            'taken_down'       => 0,
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
                        $reasons = $this->checkMessage((int) $row->msgid);

                        // A moderator is holding this copy, or sent the post back to pending
                        // for its moderators to decide: record what the check found so they
                        // get the reasons, but never promote or block it - that would take the
                        // post out from under them (9816/9815, 122011064).
                        if ($row->heldby !== null
                            || (int) ($row->needs_moderator ?? 0) === 1
                            || (int) ($row->locked_by_home ?? 0) === 1) {
                            $this->recordCheckOnly($row, $reasons, $dryRun, $stats, 'held');
                            continue;
                        }

                        // Already-live (Approved-on-arrival) posts: content-check them but
                        // never auto-demote a post members can already see. Clean -> just
                        // record the check; any reasons -> store them and notify mods.
                        if ($row->collection === Message::COLLECTION_APPROVED) {
                            $this->recordCheckOnly($row, $reasons, $dryRun, $stats, 'approved');
                            continue;
                        }

                        // One national moderation state per member now (self-moderating-
                        // community.md) - users.postingstatus is the only thing left that can
                        // hold a content-clean post. There is no per-group setting to OR in.
                        $userModerated = $this->isUserModerated((int) $row->msgid, (int) $row->fromuser);

                        // The AI judge found this breaks a site rule seriously enough to take
                        // down outright (ai-judgement.md), rather than just hold for a mod -
                        // e.g. a scam link, illegal item, or under-age listing. Short-circuits
                        // everything below: no promote/block/pending decision needed once a
                        // post is going straight to Rejected. Counted even in a dry run so
                        // "how many would this take down" is visible without acting on it.
                        $takedown = null;
                        foreach ($reasons as $reason) {
                            if (($reason['action'] ?? 'flag') === 'takedown') {
                                $takedown = $reason;
                                break;
                            }
                        }
                        if ($takedown !== null) {
                            $stats['taken_down']++;
                            if (!$dryRun) {
                                ($this->takedownService ?? app(TakedownService::class))
                                    ->takeDown((int) $row->msgid, $takedown['detail']);
                            }
                            continue;
                        }

                        // Never auto-promote an Offer/Wanted we couldn't locate (NULL lat -
                        // subject didn't geocode and no usable poster fallback): it would go
                        // live undiscoverable. Keep it in the mod queue so a moderator adds a
                        // postcode via the "add a postcode" prompt (Discourse #9865).
                        $missingLocation = $row->lat === null
                                        && in_array($row->msgtype, ['Offer', 'Wanted'], true);

                        // A 'wait' reason (judge below-threshold, or a wait-outcome question
                        // like vague/unsafe) explains itself to a moderator but must never
                        // block promotion or be mistaken for a block - only genuine
                        // deterministic 'block' reasons do that.
                        $blockingReasons = array_filter(
                            $reasons,
                            fn($r) => !in_array($r['action'] ?? 'flag', ['takedown', 'wait'], true)
                        );
                        $promote     = empty($blockingReasons) && !$userModerated && !$missingLocation;
                        $hasBlock    = !$promote && !empty(array_filter(
                            $blockingReasons,
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
                                $this->holdReasons($userModerated, $missingLocation)
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
                            // A judge 'wait' reason (below-threshold takedown, or vague/unsafe)
                            // never blocks promotion, but it is still worth a mod's attention
                            // on a post that is already live - keep it rather than wiping the
                            // check clean, exactly as recordCheckOnly() does for
                            // Approved-on-arrival posts.
                            $waitReasons = array_values(array_filter(
                                $reasons,
                                fn($r) => ($r['action'] ?? 'flag') === 'wait'
                            ));

                            DB::transaction(function () use ($row, $waitReasons, &$stats) {
                                DB::table('messages')
                                    ->where('id', $row->msgid)
                                    ->update([
                                        'collection'              => Message::COLLECTION_APPROVED,
                                        'approvedby'              => null,
                                        'approvedat'              => now(),
                                        'contentcheck_checked_at' => now(),
                                        'contentcheck_reasons'    => empty($waitReasons) ? null : json_encode($waitReasons),
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

                            Log::info("ContentCheck: approved message #{$row->msgid}");
                        } elseif ($hasBlock) {
                            DB::table('messages')
                                ->where('id', $row->msgid)
                                ->update([
                                    'collection'              => Message::COLLECTION_SPAM,
                                    'contentcheck_checked_at' => now(),
                                    'contentcheck_reasons'    => json_encode($reasons),
                                ]);

                            $stats['blocked']++;
                            Log::info("ContentCheck: blocked message #{$row->msgid}", ['reasons' => $reasons]);
                        } else {
                            DB::transaction(function () use ($row, $reasons, &$stats) {
                                DB::table('messages')
                                    ->where('id', $row->msgid)
                                    ->update([
                                        'contentcheck_checked_at' => now(),
                                        'contentcheck_reasons'    => empty($reasons) ? null : json_encode($reasons),
                                    ]);

                                // Notify national mods that a post needs a look. No group_id:
                                // ProcessBackgroundTasksCommand::handlePushNotifyGroupMods
                                // accepts a msgid-only payload and notifies every mod.
                                DB::table('background_tasks')->insert([
                                    'task_type' => BackgroundTask::TASK_PUSH_NOTIFY_GROUP_MODS,
                                    'data'      => json_encode(['msgid' => (int) $row->msgid]),
                                ]);

                                $stats['kept_pending']++;
                            });

                            Log::info("ContentCheck: kept pending message #{$row->msgid}", ['reasons' => $reasons]);
                        }
                    } catch (\Exception $e) {
                        Log::error("ContentCheck: error processing message #{$row->msgid}: " . $e->getMessage());
                        $stats['errors']++;
                    }
                }
        };

        // One state per post now (self-moderating-community.md) - messages carries its own
        // collection/heldby/contentcheck_* directly, so there is no messages_groups join and
        // no groupid. Two passes (Pending, and recently-arrived Approved) kept for the same
        // reason as before: each leads with a selective predicate (collection, then arrival)
        // that has its own index, rather than one OR'd query falling back to a `deleted` scan.
        $base = fn () => DB::table('messages as m')
            ->join('users as u', 'u.id', '=', 'm.fromuser')
            ->select('m.id as msgid', 'm.collection', 'm.heldby', DB::raw('m.type as msgtype'), DB::raw('m.fromuser as fromuser'), DB::raw('m.lat as lat'))
            // Either never checked, or checked and then edited. The edit stamps
            // messages.editedat rather than clearing the check stamp, because the
            // stamp is also what lets a moderator see the post at all - clearing it
            // made a post vanish from the queue of the moderator who had just edited
            // it (Discourse 10001). "Edited since checked" is derived by comparing
            // the two timestamps, so there is no separate mark to clear and the state
            // cannot drift; re-stamping contentcheck_checked_at on completion resolves
            // the comparison by itself.
            ->where(function ($q) {
                $q->whereNull('m.contentcheck_checked_at')
                    ->orWhereColumn('m.editedat', '>', 'm.contentcheck_checked_at');
            })
            ->whereNull('m.deleted')
            // Held messages ARE checked - checking is not acting. Skipping them entirely
            // (the old "never fight a mod" rule, 9816/9815) left contentcheck_checked_at
            // NULL for as long as the hold lasted, so the moderator holding the post never
            // saw why it needed a look, and surfaces that count only checked rows reported
            // fewer held posts than were in front of them (Discourse 9481/635). What must
            // not happen is re-promoting or blocking it out from under them - see the
            // heldby branch in the processing loop, which records the result and stops.
            ->whereNotNull('m.fromuser')
            ->whereNull('u.deleted')
            ->orderBy('m.id');

        // Pending posts awaiting a check - their first, or a fresh one after an edit.
        $base()
            ->where('m.collection', Message::COLLECTION_PENDING)
            ->chunk(100, $processChunk);

        // NEW approved-on-arrival posts, bounded to recent arrivals so the
        // historical backlog of live posts is never rescanned. Served by the
        // `arrival` index range over just the recent window.
        $base()
            ->where('m.collection', Message::COLLECTION_APPROVED)
            ->where('m.arrival', '>', now()->subHours(self::APPROVED_CHECK_WINDOW_HOURS))
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
     * its reasons stored and notifies mods so a human can review it. Storing the reasons is
     * the point for a held post: it is what tells the moderator holding it why it needed a
     * look (Discourse 9481/635).
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
                DB::table('messages')
                    ->where('id', $row->msgid)
                    ->update([
                        'contentcheck_checked_at' => now(),
                        'contentcheck_reasons'    => json_encode($reasons),
                    ]);

                // See the TODO in processUnprocessed() - same unresolved group_id gap.
                DB::table('background_tasks')->insert([
                    'task_type' => BackgroundTask::TASK_PUSH_NOTIFY_GROUP_MODS,
                    'data'      => json_encode(['msgid' => (int) $row->msgid]),
                ]);

                $stats[$flaggedKey]++;
            });

            Log::info("ContentCheck: flagged {$kind} message #{$row->msgid}", ['reasons' => $reasons]);
            return;
        }

        DB::table('messages')
            ->where('id', $row->msgid)
            ->update([
                'contentcheck_checked_at' => now(),
                'contentcheck_reasons'    => null,
            ]);

        $stats[$checkedKey]++;
    }

    /**
     * Why a post is being kept pending when the content itself was clean.
     *
     * Without these a moderator sees a post sitting in the queue with no
     * indication of what put it there, which is what Discourse #9987 reported.
     * These are 'flag', never 'block' - they explain a hold, they don't cause one.
     *
     * One national moderation state per member now (self-moderating-community.md): there is
     * no group setting left to check, so only CHECK_MEMBER_MODERATED (users.postingstatus)
     * and CHECK_NO_LOCATION remain (CHECK_GROUP_MODERATED itself was deleted 2026-09-27).
     *
     * @return array<int, array{check:string, category:null, action:string, detail:string}>
     */
    private function holdReasons(bool $userModerated, bool $missingLocation): array
    {
        $reasons = [];

        if ($userModerated) {
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

    /**
     * One national moderation state per user now (self-moderating-community.md), held in
     * users.postingstatus. 2026_09_20_000001_remove_group_model.php backfilled it from the
     * old per-membership ourPostingStatus with PROHIBITED > MODERATED > UNMODERATED > DEFAULT
     * precedence, so a member moderated on any one group came out moderated overall.
     */
    public function isUserModerated(int $msgid, ?int $fromuser = null): bool
    {
        if ($fromuser === null) {
            $fromuser = DB::table('messages')->where('id', $msgid)->value('fromuser');
        }

        if (!$fromuser) {
            return true;
        }

        $status = DB::table('users')
            ->where('id', $fromuser)
            ->value('postingstatus');

        if ($status === null || $status === '' || strtoupper($status) === 'MODERATED') {
            return true;
        }
        if (strtoupper($status) === 'PROHIBITED') {
            return true;
        }

        return false;
    }


    // -------------------------------------------------------------------------
    // Phone numbers — UK format check, applied to posts only (NOT chat: sharing
    // a number to arrange a handover is normal). Requires a proper UK prefix
    // (0, +44, or 0044) followed by 9–10 digits (with optional spaces/hyphens).
    // This specificity avoids false positives from short numeric strings like
    // flat numbers or times.
    //
    // Runs unconditionally, national (frozen-settings.md: restrictpersonalinfo
    // was 74% "restrict" live, same call as checkPII() below, so the
    // deterministic check stays and the per-group "don't restrict" branch is
    // deleted).
    // -------------------------------------------------------------------------

    public function checkPhoneNumbers(string $subject, string $textbody): ?array
    {
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
    // PII — external email addresses. Runs unconditionally, national
    // (frozen-settings.md: restrictpersonalinfo was 74% "restrict" live, so
    // the deterministic check stays and the other branch is deleted). Phone
    // numbers are checked the same way via checkPhoneNumbers().
    // -------------------------------------------------------------------------

    public function checkPII(string $subject, string $textbody): ?array
    {
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
     * check service disagrees with the current state. Read-only — no DB writes.
     * There is one national moderation state now, not a per-group one, so this
     * always scans everything - results carry no group key.
     *
     * @param int      $limit      Max rows per collection to examine (0 = no limit).
     * @param int|null $sinceDays  Only consider rows with m.arrival within the last N days (null = no time filter).
     */
    public function auditExisting(int $limit = 500, ?int $sinceDays = null): array
    {
        $disagreements = [];

        foreach (['Approved', 'Pending'] as $collection) {
            $query = DB::table('messages as m')
                ->join('users as u', 'u.id', '=', 'm.fromuser')
                ->select('m.id as msgid', 'm.collection')
                ->where('m.collection', $collection)
                ->whereNull('m.deleted')
                ->whereNotNull('m.fromuser')
                ->whereNull('u.deleted');

            if ($sinceDays !== null && $sinceDays > 0) {
                $query->where('m.arrival', '>=', now()->subDays($sinceDays));
                $query->orderByDesc('m.arrival');
            }

            if ($limit > 0) {
                $query->limit($limit);
            }

            $rows = $query->get();

            foreach ($rows as $row) {
                try {
                    $reasons     = $this->checkMessage((int) $row->msgid);
                    $isModerated = $this->isUserModerated((int) $row->msgid);

                    if ($collection === 'Approved' && !empty($reasons)) {
                        $disagreements[] = [
                            'msgid'        => (int) $row->msgid,
                            'collection'   => 'Approved',
                            'type'         => 'should_flag',
                            'reasons'      => $reasons,
                            'is_moderated' => $isModerated,
                        ];
                    } elseif ($collection === 'Pending' && empty($reasons) && !$isModerated) {
                        $disagreements[] = [
                            'msgid'        => (int) $row->msgid,
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

        // Count other posts with the same subject in the past N days. There is
        // no rippling and no per-community fan-out any more (self-moderating-
        // community.md): a single national post is a single messages row, so
        // "posted to 30 groups" becomes "the same subject used for 30 distinct
        // posts" — still a mass-submission signal, just counted on messages
        // directly instead of joining the dropped messages_groups table.
        $distinctPostCount = DB::table('messages as m')
            ->where('m.subject', $subject)
            ->where('m.id', '!=', $msgid)
            ->where('m.arrival', '>=', now()->subDays(self::SUBJECT_REPEAT_WINDOW))
            ->whereNull('m.deleted')
            ->count();

        if ($distinctPostCount >= self::SUBJECT_THRESHOLD) {
            return [
                'check'    => self::CHECK_SUBJECT_REPEAT,
                'category' => null,
                'action'   => 'flag',
                'detail'   => "Subject recently posted {$distinctPostCount} times",
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
