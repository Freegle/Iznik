<?php

namespace App\Services\Automod;

use App\Models\Group;
use App\Services\ContentCheckService;
use Illuminate\Support\Facades\DB;

/**
 * Computes the boolean facts and per-group rule toggles the automod chart (automod/chart.json)
 * is asked about, so the chart only ever has to walk a fixed set of yes/no answers plus the
 * narrow text questions it sends to a model backend.
 *
 * Several facts here used to be computed inline elsewhere and are now sourced from a single
 * place so the flowchart, AutoApproveCleanService and any future consumer agree:
 *
 * - member_veto / group_disallows relocate AutoApproveCleanService::hasDangerSignals() and
 *   groupAllowsAutoApprove() (PR #639) verbatim - the flowchart replaces AutoApproveCleanService
 *   as the place that decides whether a post is safe to release automatically, but the checks
 *   themselves are unchanged.
 * - member_moderated is any explicit posting status on the community. A blank status is the
 *   auto-moderated tier, the one post-moderation exists for (AutoApproveCleanService selects
 *   ourPostingStatus IS NULL). ContentCheckService::isUserModerated() counts blank as
 *   moderated because it answers a different question, so it is not reused here.
 * - no_location / outside_uk port the bounding-box logic ModTools already shows moderators
 *   (ModMessage.vue noLocation/outsideUK) so the member-facing warning and the automated
 *   decision agree. A location that has collapsed to ~(0,0) - an ungeocoded email post, say -
 *   counts as "no location", not a real point off the coast of west Africa (Discourse #9865).
 * - The content-check facts (spam_signal, personal_info, not_english, vague, concern_keyword)
 *   are derived from the same messages_groups.contentcheck_reasons JSON ContentCheckService
 *   already wrote, grouping its CHECK_* constants into the categories the chart asks about.
 *   MemberModerated, GroupModerated and NoLocation are deliberately excluded from this scan:
 *   ContentCheckService only appends those reason entries once promotion has already been
 *   ruled out (see holdReasons()), so they cannot be relied on to be present here - member_veto,
 *   group_disallows and no_location are computed independently instead.
 */
class AutomodFactsService
{
    /** messages.type values that count as "no location" / "outside UK" candidates. */
    private const LOCATION_RELEVANT_TYPES = ['Offer', 'Wanted'];

    /** ContentCheckService::CHECK_* constants that map to the "spam_signal" fact. */
    private const SPAM_SIGNAL_CHECKS = [
        ContentCheckService::CHECK_BULK_MAIL,
        ContentCheckService::CHECK_SUBJECT_REPEAT,
        ContentCheckService::CHECK_KNOWN_SPAMMER,
        ContentCheckService::CHECK_GREETING_SPAM,
        ContentCheckService::CHECK_IMAGE_SPAM,
        ContentCheckService::CHECK_SPAMHAUS_DBL,
        ContentCheckService::CHECK_URL,
        ContentCheckService::CHECK_MONEY,
    ];

    /** ContentCheckService::CHECK_* constants that map to the "personal_info" fact. */
    private const PERSONAL_INFO_CHECKS = [
        ContentCheckService::CHECK_PHONE_NUMBER,
        ContentCheckService::CHECK_EMAIL_ADDRESS,
        ContentCheckService::CHECK_MESSAGING_LINK,
    ];

    /** ContentCheckService::CHECK_* constants that map to the "vague" fact. */
    private const VAGUE_CHECKS = [
        ContentCheckService::CHECK_VAGUE,
        ContentCheckService::CHECK_NOT_AN_ITEM,
    ];

    /** ContentCheckService::CHECK_* constants that map to the "concern_keyword" fact. */
    private const CONCERN_KEYWORD_CHECKS = [
        ContentCheckService::CHECK_CONCERN_KEYWORD,
        ContentCheckService::CHECK_PER_GROUP_WORRY,
    ];

    public function __construct(
        private readonly ?ContentCheckService $contentCheckService = null
    ) {
    }

    /**
     * @return array<string, mixed> boolean facts, plus a "*_detail" string alongside any fact
     *                               that carries a human-readable reason for a moderator/chart.
     */
    public function facts(int $msgid, int $groupid): array
    {
        $message = DB::table('messages')
            ->where('id', $msgid)
            ->first(['fromuser', 'lat', 'lng', 'type', 'subject', 'arrival']);

        $fromuser = $message->fromuser ?? null;
        $lat = $message->lat !== null ? (float) $message->lat : null;
        $lng = $message->lng !== null ? (float) $message->lng : null;
        $type = $message->type ?? null;

        $reasons = $this->contentCheckReasons($msgid, $groupid);

        [$memberVeto, $memberVetoDetail] = $this->memberVeto($msgid, $groupid, $fromuser);
        [$groupDisallows, $groupDisallowsDetail] = $this->groupDisallows($groupid);
        [$spamSignal, $spamSignalDetail] = $this->reasonCategory($reasons, self::SPAM_SIGNAL_CHECKS);
        [$personalInfo, $personalInfoDetail] = $this->reasonCategory($reasons, self::PERSONAL_INFO_CHECKS);
        [$vague, $vagueDetail] = $this->reasonCategory($reasons, self::VAGUE_CHECKS);
        [$concernKeyword, $concernKeywordDetail] = $this->reasonCategory($reasons, self::CONCERN_KEYWORD_CHECKS);
        [$notEnglish] = $this->reasonCategory($reasons, [ContentCheckService::CHECK_LANGUAGE]);

        $noLocation = $this->noLocation($lat, $lng, $type);
        [$duplicate, $duplicateDetail] = $this->duplicate($msgid, $groupid, $message);

        return [
            'member_veto' => $memberVeto,
            'member_veto_detail' => $memberVetoDetail,
            'member_moderated' => $this->memberHasPostingStatus($groupid, $fromuser),
            'group_disallows' => $groupDisallows,
            'group_disallows_detail' => $groupDisallowsDetail,
            'no_location' => $noLocation,
            'outside_uk' => $this->outsideUk($lat, $lng, $noLocation),
            'duplicate' => $duplicate,
            'duplicate_detail' => $duplicateDetail,
            'spam_signal' => $spamSignal,
            'spam_signal_detail' => $spamSignalDetail,
            'personal_info' => $personalInfo,
            'personal_info_detail' => $personalInfoDetail,
            'not_english' => $notEnglish,
            'vague' => $vague,
            'vague_detail' => $vagueDetail,
            'concern_keyword' => $concernKeyword,
            'concern_keyword_detail' => $concernKeywordDetail,
            'is_offer' => $type === 'Offer',
            'is_wanted' => $type === 'Wanted',
        ];
    }

    /**
     * The group's own rule toggles (ModSettingsGroup.vue's rulelist - fullymoderated,
     * restrictpersonalinfo, allowloans, and around thirty others), returned as-is.
     *
     * These are deliberately not enumerated or curated here: the chart (automod/chart.json)
     * references whichever rule name a "text" node's check.rule needs generically, the same
     * way Go already treats groups.rules as an unstructured blob rather than a fixed struct
     * (iznik-server-go/message/autoapproveat.go) - so this is the raw decoded column, and the
     * chart owns interpreting it.
     */
    public function rules(int $groupid): array
    {
        $group = Group::find($groupid);

        return $group->rules ?? [];
    }

    /**
     * The subject a member would recognise as "the same post": no OFFER:/WANTED: prefix,
     * no trailing "(Place)", case and spacing ignored.
     */
    public static function normaliseSubject(?string $subject): string
    {
        $s = strtolower(trim((string) $subject));
        $s = preg_replace('/^(offer|wanted|taken|received)\s*:\s*/', '', $s);
        $s = preg_replace('/\s*\([^)]*\)\s*$/', '', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /**
     * The same poster already has this post open on the community, or posted it again
     * sooner than the community's repost interval allows. Looks back 60 days.
     *
     * @return array{0: bool, 1: ?string}
     */
    private function duplicate(int $msgid, int $groupid, ?object $message): array
    {
        if (!$message || !$message->fromuser || !$message->type) {
            return [false, null];
        }

        $mine = self::normaliseSubject($message->subject);
        if ($mine === '') {
            return [false, null];
        }

        $others = DB::table('messages as m')
            ->join('messages_groups as mg', 'mg.msgid', '=', 'm.id')
            ->where('m.fromuser', $message->fromuser)
            ->where('m.type', $message->type)
            ->where('m.id', '<>', $msgid)
            ->where('mg.groupid', $groupid)
            ->whereIn('mg.collection', ['Approved', 'Pending'])
            ->where('mg.deleted', 0)
            ->whereNull('m.deleted')
            ->where('m.arrival', '>=', now()->subDays(60))
            ->get(['m.id', 'm.subject', 'm.arrival']);

        $group = Group::find($groupid);
        $reposts = $group ? $group->getSetting('reposts', []) : [];
        $intervalDays = (int) ($message->type === 'Offer' ? ($reposts['offer'] ?? 3) : ($reposts['wanted'] ?? 7));

        foreach ($others as $other) {
            $theirs = self::normaliseSubject($other->subject);
            if ($theirs === '' || ($theirs !== $mine && !str_starts_with($theirs, $mine) && !str_starts_with($mine, $theirs))) {
                continue;
            }

            $finished = DB::table('messages_outcomes')
                ->where('msgid', $other->id)
                ->whereIn('outcome', ['Taken', 'Received', 'Withdrawn'])
                ->exists();

            if (!$finished) {
                return [true, "Same as the member's open post #{$other->id}"];
            }

            $since = \Carbon\Carbon::parse($other->arrival)->diffInDays(\Carbon\Carbon::parse($message->arrival ?? now()), true);
            if ($since < $intervalDays) {
                return [true, "Posted again {$since} days after #{$other->id}; this community allows a repost after {$intervalDays} days"];
            }
        }

        return [false, null];
    }

    private function memberHasPostingStatus(int $groupid, ?int $fromuser): bool
    {
        if (!$fromuser) {
            return true;
        }

        $membership = DB::table('memberships')
            ->where('userid', $fromuser)
            ->where('groupid', $groupid)
            ->first(['ourPostingStatus']);

        return $membership === null || ($membership->ourPostingStatus !== null && $membership->ourPostingStatus !== '');
    }

    private function contentCheckService(): ContentCheckService
    {
        return $this->contentCheckService ?? new ContentCheckService();
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function memberVeto(int $msgid, int $groupid, ?int $fromuser): array
    {
        if (!$fromuser) {
            return [false, null];
        }

        // A microvolunteer flagged the post as not OK.
        if (DB::table('microactions')
            ->where('msgid', $msgid)
            ->where('actiontype', 'CheckMessage')
            ->where('result', 'Reject')
            ->exists()) {
            return [true, 'A microvolunteer flagged this post'];
        }

        // A moderator has left a note on this member.
        if (DB::table('users_comments')->where('userid', $fromuser)->exists()) {
            return [true, 'A moderator has left a note on this member'];
        }

        // A recent negative moderation action against this member (rejection, deletion,
        // modmail, spam classification) - not a self-initiated action.
        $dangerLogDays = (int) config('freegle.autoapprove.danger_log_days', 90);
        if (DB::table('logs')
            ->where('user', $fromuser)
            ->where('timestamp', '>=', now()->subDays($dangerLogDays))
            ->where(function ($q) {
                $q->whereColumn('byuser', '!=', 'user')->orWhereNull('byuser');
            })
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('type', 'Message')->whereIn('subtype', ['Rejected', 'Deleted', 'Replied']);
                })->orWhere(function ($q2) {
                    $q2->where('type', 'User')->whereIn('subtype', ['Mailed', 'Rejected', 'Deleted', 'Suspect', 'ClassifiedSpam']);
                });
            })
            ->exists()) {
            return [true, 'A recent moderation action was taken against this member'];
        }

        // A known or suspected spammer.
        if (DB::table('spam_users')
            ->where('userid', $fromuser)
            ->whereIn('collection', ['Spammer', 'PendingAdd'])
            ->exists()) {
            return [true, 'This member is a known or suspected spammer'];
        }

        // A moderation review is outstanding on this membership.
        if (DB::table('memberships')
            ->where('userid', $fromuser)
            ->where('groupid', $groupid)
            ->whereNotNull('reviewrequestedat')
            ->where(function ($q) {
                $q->whereNull('reviewedat')->orWhereColumn('reviewedat', '<', 'reviewrequestedat');
            })
            ->exists()) {
            return [true, 'A membership review is outstanding for this member'];
        }

        return [false, null];
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function groupDisallows(int $groupid): array
    {
        $group = Group::find($groupid);
        if (!$group) {
            return [true, 'Group not found'];
        }
        if (!$group->getSetting('publish', true)) {
            return [true, 'Group is not published'];
        }
        if ($group->isClosed()) {
            return [true, 'Group is closed'];
        }
        if ($group->getAttribute('autofunctionoverride')) {
            return [true, 'Group has automated functions overridden'];
        }
        if ($group->getAttribute('overridemoderation') === 'ModerateAll') {
            return [true, 'Group moderation is overridden to moderate all posts'];
        }
        if (!empty($group->getSetting('moderated', 0))) {
            return [true, 'Group moderates all posts'];
        }
        $rules = $group->rules ?? [];
        if (!empty($rules['fullymoderated'])) {
            return [true, 'Group rules say all posts are moderated'];
        }

        return [false, null];
    }

    /**
     * @return array<int, array{check: string, category: ?string, action: string, detail: string}>
     */
    private function contentCheckReasons(int $msgid, int $groupid): array
    {
        $json = DB::table('messages_groups')
            ->where('msgid', $msgid)
            ->where('groupid', $groupid)
            ->value('contentcheck_reasons');

        if (!$json) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, array{check: string, category: ?string, action: string, detail: string}> $reasons
     * @param string[] $checks
     * @return array{0: bool, 1: ?string}
     */
    private function reasonCategory(array $reasons, array $checks): array
    {
        foreach ($reasons as $reason) {
            if (in_array($reason['check'] ?? null, $checks, true)) {
                return [true, $reason['detail'] ?? null];
            }
        }

        return [false, null];
    }

    private function noLocation(?float $lat, ?float $lng, ?string $type): bool
    {
        if (!in_array($type, self::LOCATION_RELEVANT_TYPES, true)) {
            return false;
        }

        if ($lat === null) {
            return true;
        }

        // Collapsed to ~(0,0): an unset/ungeocoded point (e.g. an email post whose subject
        // location couldn't be geocoded), not a real location. UK lat is ~49-61, so a real
        // post is never within 0.5 degrees of (0,0). See ModMessage.vue noLocation and
        // Discourse #9865.
        return abs($lat) < 0.5 && abs($lng ?? 0.0) < 0.5;
    }

    private function outsideUk(?float $lat, ?float $lng, bool $noLocation): bool
    {
        if ($noLocation || $lat === null || $lng === null) {
            return false;
        }

        return $lng < -16 || $lat < 49 || $lng > 4 || $lat > 64;
    }
}
