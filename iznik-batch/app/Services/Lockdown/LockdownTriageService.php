<?php

namespace App\Services\Lockdown;

use App\Models\ChatRoom;
use App\Models\MessageGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Classifies chat messages, posts and ChitChat items held by an active lockdown into
 * spam, low or risky (plan 2026-09-27-lockdown-switch.md, section 10.7), and creates the
 * lockdown_holds rows for chat and posts. ChitChat holds are created by the Go API when the
 * post is made; this service only classifies them.
 */
class LockdownTriageService
{
    public const RISK_SPAM = 'spam';

    public const RISK_LOW = 'low';

    public const RISK_RISKY = 'risky';

    public const KIND_CHAT = 'chat';

    public const KIND_POST = 'post';

    public const KIND_CHITCHAT = 'chitchat';

    /**
     * The calibration wave's spam accounts were all under a day old (plan section 8); 30
     * days sits a wide margin beyond that so genuinely new members aren't caught by age alone.
     */
    public const ESTABLISHED_ACCOUNT_DAYS = 30;

    /**
     * The calibration wave's real spam clusters shared one opening line across thousands of
     * messages (section 8: 11 distinct opening lines across 21,977 messages). Five is far
     * below any real cluster and above the handful of coincidental repeats ("is this still
     * available") seen in ordinary traffic, so it separates the two without tuning to the
     * specific wave.
     */
    public const CLUSTER_MIN_SIZE = 5;

    /** How many times a sender's own historical hourly average they may exceed before the rate itself counts against them. */
    public const RATE_TOLERANCE = 5;

    /** Floor under RATE_TOLERANCE so a sender with almost no history isn't gated by it. */
    public const RATE_MIN_ALLOWANCE = 3;

    public function __construct(private readonly ?LockdownService $lockdown = null)
    {
    }

    /**
     * Create lockdown_holds rows for the active incident: User2User chat messages left
     * unprocessed since it started, and posts left Pending since it started, excluding
     * moderators, Support and Admin as senders/posters in both cases. Safe to call
     * repeatedly; existing holds (kind, refid) are left untouched.
     */
    public function createHolds(): array
    {
        $lockdown = $this->lockdown ?? app(LockdownService::class);
        if (!$lockdown->active()) {
            return ['chat' => 0, 'post' => 0];
        }

        $row = $lockdown->current();
        $incidentId = (int) $row->incidentid;
        $startedAt = Carbon::parse($row->startedat);

        return [
            'chat' => $this->createChatHolds($incidentId, $startedAt),
            'post' => $this->createPostHolds($incidentId, $startedAt),
        ];
    }

    /**
     * Classify every unclassified hold for the current incident, oldest first. A hold whose
     * underlying chat message or post has since gone is classified risky, so it still
     * surfaces for review rather than sitting unclassified forever.
     */
    public function classifyPending(int $limit = 500): array
    {
        $lockdown = $this->lockdown ?? app(LockdownService::class);
        $incidentId = $lockdown->incidentId();
        if ($incidentId === null) {
            return ['classified' => 0];
        }

        $holds = DB::table('lockdown_holds')
            ->where('lockdownid', $incidentId)
            ->whereNull('risk')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $classified = 0;
        foreach ($holds as $hold) {
            $item = $hold->kind === self::KIND_POST
                ? $this->loadPostItem((int) $hold->refid)
                : $this->loadChatItem((int) $hold->refid);

            $risk = $item === null
                ? self::RISK_RISKY
                : $this->classify($hold->kind, $item['userid'], $item['text'], $item['when'], (int) $hold->refid);

            DB::table('lockdown_holds')->where('id', $hold->id)->update(['risk' => $risk]);
            $classified++;
        }

        return ['classified' => $classified];
    }

    /**
     * Classify one item. $excludeRefId keeps a hold's own row out of its cluster and
     * earlier-activity checks when classifying a stored hold rather than a hypothetical item.
     */
    public function classify(string $kind, int $userid, string $text, \DateTimeInterface $when, ?int $excludeRefId = null): string
    {
        if ($this->isSpammer($userid)) {
            return self::RISK_SPAM;
        }

        $folded = $this->foldedFirstLine($text);
        $cluster = $this->clusterInfo($kind, $folded, $when, $excludeRefId);
        if ($cluster['count'] >= self::CLUSTER_MIN_SIZE && $this->anyIsSpammer($cluster['senders'])) {
            return self::RISK_SPAM;
        }

        $established = $this->accountOlderThan($userid, self::ESTABLISHED_ACCOUNT_DAYS, $when);
        $hasLinkOrPhrase = $this->containsLink($text) || $this->matchesIncidentPhrase($text);

        if (!$established && $hasLinkOrPhrase) {
            return self::RISK_SPAM;
        }

        if ($established
            && $this->hasEarlierActivity($kind, $userid, $when, $excludeRefId)
            && !$this->containsLink($text)
            && $cluster['count'] < self::CLUSTER_MIN_SIZE
            && $this->withinOwnRate($kind, $userid, $when)
        ) {
            return self::RISK_LOW;
        }

        return self::RISK_RISKY;
    }

    private function createChatHolds(int $incidentId, Carbon $startedAt): int
    {
        $rows = DB::table('chat_messages')
            ->join('chat_rooms', 'chat_messages.chatid', '=', 'chat_rooms.id')
            ->join('users', 'chat_messages.userid', '=', 'users.id')
            ->where('chat_rooms.chattype', ChatRoom::TYPE_USER2USER)
            ->where('chat_messages.processingrequired', 1)
            ->where('chat_messages.date', '>=', $startedAt)
            ->whereNotIn('users.systemrole', [User::SYSTEMROLE_MODERATOR, User::SYSTEMROLE_SUPPORT, User::SYSTEMROLE_ADMIN])
            ->select('chat_messages.id as id', 'chat_messages.userid as userid')
            ->get();

        return $this->insertHolds(self::KIND_CHAT, $incidentId, $rows);
    }

    private function createPostHolds(int $incidentId, Carbon $startedAt): int
    {
        $rows = DB::table('messages_groups')
            ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
            ->join('users', 'messages.fromuser', '=', 'users.id')
            ->where('messages_groups.collection', MessageGroup::COLLECTION_PENDING)
            ->where('messages_groups.deleted', 0)
            ->where('messages_groups.arrival', '>=', $startedAt)
            ->whereNotIn('users.systemrole', [User::SYSTEMROLE_MODERATOR, User::SYSTEMROLE_SUPPORT, User::SYSTEMROLE_ADMIN])
            ->select('messages_groups.msgid as id', 'messages.fromuser as userid')
            ->distinct()
            ->get();

        return $this->insertHolds(self::KIND_POST, $incidentId, $rows);
    }

    private function insertHolds(string $kind, int $incidentId, $rows): int
    {
        $created = 0;
        foreach ($rows as $row) {
            $created += DB::table('lockdown_holds')->insertOrIgnore([
                'lockdownid' => $incidentId,
                'kind' => $kind,
                'refid' => $row->id,
                'userid' => $row->userid,
                'created' => now(),
            ]);
        }

        return $created;
    }

    private function loadChatItem(int $refid): ?array
    {
        $row = DB::table('chat_messages')->where('id', $refid)->first();
        if ($row === null) {
            return null;
        }

        return ['userid' => (int) $row->userid, 'text' => (string) $row->message, 'when' => Carbon::parse($row->date)];
    }

    private function loadPostItem(int $refid): ?array
    {
        $row = DB::table('messages')->where('id', $refid)->first();
        if ($row === null) {
            return null;
        }

        return [
            'userid' => (int) $row->fromuser,
            'text' => trim(($row->subject ?? '').' '.($row->textbody ?? '')),
            'when' => Carbon::parse($row->arrival ?? $row->date),
        ];
    }

    private function isSpammer(int $userid): bool
    {
        return DB::table('spam_users')->where('userid', $userid)->where('collection', 'Spammer')->exists();
    }

    private function anyIsSpammer(array $userids): bool
    {
        if (empty($userids)) {
            return false;
        }

        return DB::table('spam_users')->whereIn('userid', $userids)->where('collection', 'Spammer')->exists();
    }

    private function containsLink(string $text): bool
    {
        return (bool) preg_match('#https?://#i', $text) || (bool) preg_match('#www\d{0,3}[.]#i', $text);
    }

    private function matchesIncidentPhrase(string $text): bool
    {
        $lowered = mb_strtolower($text);
        $lockdown = $this->lockdown ?? app(LockdownService::class);
        foreach ($lockdown->phrases() as $phrase) {
            if ($phrase !== '' && str_contains($lowered, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function foldedFirstLine(string $text): string
    {
        return mb_strtolower(trim(strtok(trim($text), "\n")));
    }

    /**
     * How many chat messages or posts in the day before $when share this folded first line,
     * and who sent them. Counts items, not distinct senders: a real spam cluster is one line
     * repeated by many messages (see CLUSTER_MIN_SIZE), so items is what it's calibrated against.
     */
    private function clusterInfo(string $kind, string $folded, \DateTimeInterface $when, ?int $excludeRefId): array
    {
        if ($folded === '') {
            return ['count' => 0, 'senders' => []];
        }

        $windowStart = Carbon::parse($when)->subDay();

        if ($kind === self::KIND_POST) {
            $query = DB::table('messages')
                ->where('arrival', '<=', $when)
                ->where('arrival', '>=', $windowStart);
            if ($excludeRefId !== null) {
                $query->where('id', '!=', $excludeRefId);
            }
            $candidates = $query->select('fromuser', 'subject', 'textbody')->get();
            $matching = $candidates->filter(fn ($row) => $this->foldedFirstLine(trim(($row->subject ?? '').' '.($row->textbody ?? ''))) === $folded);

            return ['count' => $matching->count(), 'senders' => $matching->pluck('fromuser')->unique()->values()->all()];
        }

        $query = DB::table('chat_messages')
            ->where('date', '<=', $when)
            ->where('date', '>=', $windowStart);
        if ($excludeRefId !== null) {
            $query->where('id', '!=', $excludeRefId);
        }
        $candidates = $query->select('userid', 'message')->get();
        $matching = $candidates->filter(fn ($row) => $this->foldedFirstLine((string) $row->message) === $folded);

        return ['count' => $matching->count(), 'senders' => $matching->pluck('userid')->unique()->values()->all()];
    }

    private function accountOlderThan(int $userid, int $days, \DateTimeInterface $when): bool
    {
        $added = DB::table('users')->where('id', $userid)->value('added');
        if ($added === null) {
            return false;
        }

        return Carbon::parse($added)->addDays($days)->lte($when);
    }

    private function hasEarlierActivity(string $kind, int $userid, \DateTimeInterface $when, ?int $excludeRefId): bool
    {
        if ($kind === self::KIND_POST) {
            $query = DB::table('messages')->where('fromuser', $userid)->where('arrival', '<', $when);
            if ($excludeRefId !== null) {
                $query->where('id', '!=', $excludeRefId);
            }

            return $query->exists();
        }

        $query = DB::table('chat_messages')->where('userid', $userid)->where('date', '<', $when);
        if ($excludeRefId !== null) {
            $query->where('id', '!=', $excludeRefId);
        }

        return $query->exists();
    }

    /**
     * Whether the sender's activity in the hour before $when is within RATE_TOLERANCE times
     * their own historical hourly average over the preceding 30 days (floored at
     * RATE_MIN_ALLOWANCE so a sender with almost no history isn't gated on it).
     */
    private function withinOwnRate(string $kind, int $userid, \DateTimeInterface $when): bool
    {
        $table = $kind === self::KIND_POST ? 'messages' : 'chat_messages';
        $userCol = $kind === self::KIND_POST ? 'fromuser' : 'userid';
        $timeCol = $kind === self::KIND_POST ? 'arrival' : 'date';

        $windowStart = Carbon::parse($when)->subDays(30);
        $lastHourStart = Carbon::parse($when)->subHour();

        $historical = DB::table($table)->where($userCol, $userid)
            ->where($timeCol, '>=', $windowStart)
            ->where($timeCol, '<', $lastHourStart)
            ->count();
        $hoursOfHistory = max(1, $windowStart->diffInHours($lastHourStart));
        $average = $historical / $hoursOfHistory;

        $lastHour = DB::table($table)->where($userCol, $userid)
            ->where($timeCol, '>=', $lastHourStart)
            ->where($timeCol, '<=', $when)
            ->count();

        $allowance = max(self::RATE_MIN_ALLOWANCE, $average * self::RATE_TOLERANCE);

        return $lastHour <= $allowance;
    }
}
