<?php

namespace App\Services\TrashNothing\Ingestion;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageGroup;
use App\Models\SpamUser;
use App\Models\User;
use App\Models\UserBanned;
use App\Services\ItemService;
use App\Services\LokiService;
use App\Services\Mail\Incoming\RoutingResult;
use App\Services\SpatialQueryService;
use App\Services\TrashNothing\ModMessaging;
use App\Services\TusService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ingests a TN API post into the database, mirroring the logic of
 * IncomingMailService::handleGroupPost + ::createGroupPostMessage.
 *
 * The email path (IncomingMailService) is never touched. Logic here is
 * duplicated — not extracted — until parity is proven.
 *
 * Because the two are tied together only by hand, a fix made to one and not
 * the other is invisible. Tests\Unit\Services\TrashNothing\EmailPathMirrorDriftTest
 * pins a digest of both mirrored email-path methods and fails the moment
 * either changes, so the mirror is at least LOOKED at on every such edit.
 * tn:parity-check remains the thorough check of whether it is right.
 *
 * With $dryRun = true, no DB writes occur. Every would-be write emits a
 * TN-SYNC-TRACE [WRITE] log line for diffing against the email path.
 */
class GroupPostIngestionService
{
    /**
     * routing_reason values that MUST stay byte-identical to the email path's,
     * so a comparison can group on them. Sourced from
     * IncomingMailService::handleGroupPost()'s dropped() calls.
     */
    public const REASON_UNKNOWN_USER = 'Post from unknown user';

    public const REASON_NON_MEMBER = 'Post from non-member';

    /**
     * Sourced from IncomingMailService::route()'s isKnownSpammer() drop, which
     * happens before the group post is looked at at all.
     */
    public const REASON_KNOWN_SPAMMER = 'Known spammer';

    /**
     * API-only routing_reason values — no email-path branch produces these.
     * Stable strings: dashboards and the parity comparison group on them.
     */
    public const REASON_NO_USER_ID = 'no-user-id';

    /**
     * The poster could not be resolved because TN could not be asked who they
     * are (5xx, 429, timeout, challenge), or because their username's address
     * belongs to a Freegle user with a different tnuserid. See TnUserProvisioner.
     *
     * Both exist only because this path CREATES a Freegle user for a TN poster
     * Freegle has never met — a deliberate divergence from email-path case 2,
     * which still drops such a post as REASON_UNKNOWN_USER. Expect API-side
     * Pending (a new user is unmapped) against email-side Dropped/unknown-user
     * for the same post; that is the feature working, not a parity gap.
     */
    public const REASON_TN_USER_LOOKUP_FAILED = TnUserProvisioner::REASON_LOOKUP_FAILED;

    public const REASON_TN_USERNAME_CLASH = TnUserProvisioner::REASON_USERNAME_CLASH;

    public const REASON_DUPLICATE = 'duplicate';

    /**
     * The poster is banned from the group the post's coordinates resolved to.
     *
     * The email path has no branch of its own for this: a ban deletes the
     * membership and its subscribe handler will not re-join a banned member, so a
     * banned member's post falls into its REASON_NON_MEMBER drop. This path joins
     * the poster to the group itself (see joinGroup()), so it must refuse a ban
     * before that, or it would quietly undo the ban.
     */
    public const REASON_BANNED = 'banned';

    /**
     * A TN per-group COPY of a source post, discarded so Freegle's own rippling
     * does the cross-posting instead.
     *
     * An intended divergence rather than a parity gap: the email path receives
     * one email per TN group and ingests each as its own message, so an item
     * crossposted to N TN groups yields N email-path messages but ONE API-path
     * message. Expect email-side Approved/Pending against an API-side
     * Dropped/crosspost for the same item — that is the feature working.
     */
    public const REASON_CROSSPOST = 'crosspost';

    public const REASON_MESSAGE_CREATE_FAILED = 'message-create-failed';

    /**
     * messages.sourceheader for a post ingested from the TN API.
     *
     * The email path derives this from the X-trash-nothing-Source header, giving
     * TN-Web / TN-Facebook / TN-Mobile / TN-native-app
     * (IncomingMailService::determineSourceHeader). The API returns no equivalent
     * — its own `source` field says which TN feed the post came from, not which
     * client posted it — so the API path records the path instead.
     *
     * The 'TN-' PREFIX is the part that has to be right: three consumers key off
     * it and would silently misbehave on a post stamped plain 'Email' —
     * LoveJunkInvoiceService (the monthly TN invoice splits revenue on
     * `sourceheader LIKE 'TN-%'`), LoveJunkService (partner source attribution),
     * and ProcessBackgroundTasksCommand (skips freebie-alert creation for TN
     * posts, because TN syndicates those itself).
     */
    public const SOURCEHEADER = 'TN-API';

    /**
     * logs.text of the Group/Joined row joinGroup() writes. The unaddressed form tells a
     * moderator reading the log why the member arrived, and is how
     * restoreMailIfNowChosen() recognises a silence that ingestion imposed.
     */
    public const JOINED_TEXT = 'Posted via TrashNothing';

    public const JOINED_UNADDRESSED_TEXT = 'Posted via TrashNothing, to a community they did not choose';

    /**
     * Context from the last ingest() call, for the caller to attach to its Loki
     * entry — mirroring IncomingMailService::$lastRoutingContext exactly, right
     * down to which branches populate which keys.
     *
     * This service deliberately does NOT log to Loki itself, because the email
     * path's router doesn't either: IncomingMailService accumulates context and
     * its callers (IncomingMailController / IncomingMailCommand) emit exactly
     * one entry after routing returns. PostSyncer is this path's equivalent
     * caller. Keeping emission at the caller is what guarantees one entry per
     * item on both paths.
     */
    private array $lastRoutingContext = [];

    public function __construct(
        private readonly bool $dryRun,
        private readonly LokiService $loki,
        private readonly ItemService $itemService,
        private readonly TnUserProvisioner $userProvisioner,
    ) {}

    /**
     * Context from the last routing decision.
     *
     * Mirrors IncomingMailService::getLastRoutingContext().
     */
    public function getLastRoutingContext(): array
    {
        return $this->lastRoutingContext;
    }

    /**
     * Set the routing reason and return the ingest result.
     *
     * Mirrors IncomingMailService::dropped(), including the fact that it
     * REPLACES the accumulated context rather than merging into it — which is
     * why the email path's early drops carry a routing_reason and nothing else.
     * The extra $result parameter is the one difference: the email path always
     * returns RoutingResult::DROPPED here, whereas this path distinguishes
     * 'crosspost'/'duplicate'/'skipped'/'dropped' for its caller (all of which
     * still map to a Dropped subtype — see outcomeFor()).
     */
    private function dropped(string $reason, array $extraContext = [], string $result = 'dropped'): string
    {
        $this->lastRoutingContext = array_merge(['routing_reason' => $reason], $extraContext);

        return $result;
    }

    /**
     * Map an ingest() result to the email path's RoutingResult vocabulary, so
     * both paths' Loki entries use the same subtype values.
     *
     * 'crosspost'/'duplicate'/'skipped' all collapse to Dropped: none of them
     * creates a message, and the routing_reason distinguishes them.
     */
    public static function outcomeFor(string $result): RoutingResult
    {
        return match ($result) {
            'approved' => RoutingResult::APPROVED,
            'pending' => RoutingResult::PENDING,
            default => RoutingResult::DROPPED,
        };
    }

    /**
     * Ingest a single TN API post for the given Freegle group.
     *
     * @param  mixed  $post  OpenAPI Post object or fixture array
     * @param  Group  $group  Resolved Freegle group
     * @param  bool  $modMessagingAllowed  Whether mods on $group may message this poster directly
     * @return string  'approved'|'pending'|'duplicate'|'crosspost'|'dropped'|'skipped'
     */
    public function ingest(mixed $post, Group $group, bool $modMessagingAllowed = true): string
    {
        $postId   = $this->getField($post, 'post_id', 'getPostId');
        $tnUserId = $this->getField($post, 'user_id', 'getUserId');
        $title    = $this->getField($post, 'title', 'getTitle') ?? '';
        $content  = $this->getField($post, 'content', 'getContent') ?? '';
        $date     = $this->getField($post, 'date', 'getDate');
        $lat      = $this->getField($post, 'latitude', 'getLatitude');
        $lng      = $this->getField($post, 'longitude', 'getLongitude');
        $tnType   = strtolower((string) $this->getField($post, 'type', 'getType'));
        $photos   = $this->getField($post, 'photos', 'getPhotos') ?? [];

        $subject = strtoupper($tnType) . ': ' . $title;

        // Mirrors IncomingMailService::route(), which clears the context at
        // entry so each item's context is only what its own winning branch set.
        $this->lastRoutingContext = [];

        // Crosspost de-duplication — API-path-only behaviour, deliberately NOT
        // mirroring the email path (which de-duplicates nothing).
        //
        // "The posts without a group ID are the "source" post
        // and the ones with group IDs are the copies that are sent to each group
        // because TN keeps a separate copy per group
        // so that each group moderator can approve/deny/edit as they see fit."
        // -Andrew
        //
        // Note this uses `group_id` purely as a HAS-IT/HASN'T-IT flag. Its
        // value is still never used for placement (resolved decision #7: it is
        // TN's own opaque internal id, which drifts out of step with Freegle
        // group boundaries) — PostSyncer resolves the group from the post's own
        // coordinates via Location::groupsNear().
        //
        // REPOSTS are unaffected and deliberately not de-duplicated, matching
        // the email path: a repost is a new source post, so it has no
        // `group_id`, passes this check, and creates its own FD message exactly
        // as it does when it arrives by email.
        $tnGroupId = $this->getField($post, 'group_id', 'getGroupId');
        if ($tnGroupId !== null && $tnGroupId !== '') {
            Log::info('TN-SYNC-TRACE [POST-SKIP] reason=crosspost tnpostid=' . $postId . ' tn_group_id=' . $tnGroupId . ' resolved_groupid=' . $group->id);
            $this->loki->logEvent('tn-sync', 'post-skip-crosspost', [
                'tn_post_id'  => $postId,
                'tn_group_id' => $tnGroupId,
                'group_id'    => $group->id,
            ]);
            // Deliberate divergence from the email path, which ingests one
            // message per TN group copy — see REASON_CROSSPOST. Recorded so the
            // resulting volume difference is explained in the comparison rather
            // than looking like posts going missing.
            return $this->dropped(self::REASON_CROSSPOST, result: 'crosspost');
        }

        // Idempotency: skip if this post was already ingested for this group,
        // whether the message we made is still live or has since been deleted —
        // see existingMessageForGroup() for why deleted still counts.
        $existing = $this->existingMessageForGroup((string) $postId, $group->id);
        if ($existing !== null) {
            $existingDeleted = $existing->deleted !== null;
            Log::info('TN-SYNC-TRACE [POST-SKIP] reason=duplicate tnpostid=' . $postId . ' groupid=' . $group->id . ' msgid=' . $existing->id . ($existingDeleted ? ' existing_deleted=true' : '') . ($this->dryRun ? ' would_be_duplicate=true' : ''));
            // existing_deleted separates "we already hold this" from "we held it
            // and it was deliberately removed", which is otherwise invisible in
            // the stream and is the case an operator needs to see before
            // concluding a post went missing.
            $this->loki->logEvent('tn-sync', 'post-skip-duplicate', [
                'tn_post_id'       => $postId,
                'group_id'         => $group->id,
                'message_id'       => (int) $existing->id,
                'existing_deleted' => $existingDeleted,
            ]);
            // No email-path analogue: the email path only discovers a duplicate
            // when the messages.messageid unique index rejects the insert, which
            // it reports as case 10 (a null return, no Loki entry of its own).
            return $this->dropped(self::REASON_DUPLICATE, result: 'duplicate');
        }

        // Resolve the Freegle user from TN's user id. TN's `user_id` is TN's OWN id,
        // not a Freegle id: treating it as one would hand the post to whichever
        // unrelated account happens to hold the number. The mapping is
        // users.tnuserid; a TN poster Freegle has never met is looked up on TN and
        // created (see TnUserProvisioner), deliberately unlike email-path case 2 —
        // see REASON_TN_USER_LOOKUP_FAILED. The post is skipped only when that fails.
        $user = $tnUserId ? $this->userProvisioner->resolveOrCreate((int) $tnUserId) : null;
        if ($user === null) {
            $reason = $tnUserId ? $this->unresolvedUserReason() : self::REASON_NO_USER_ID;
            $traceReason = match ($reason) {
                self::REASON_UNKNOWN_USER => 'unknown-user',
                default                   => $reason,
            };
            Log::info('TN-SYNC-TRACE [POST-SKIP] reason=' . $traceReason . ' tnpostid=' . $postId . ' tn_user_id=' . $tnUserId);
            $this->loki->logEvent('tn-sync', 'post-skip-unknown-user', [
                'tn_post_id' => $postId,
                'tn_user_id' => $tnUserId,
                'reason'     => $traceReason,
            ]);
            // Mirrors email-path case 2, which sets routing_reason and NOTHING
            // else — no group_id/group_name, even though the email path has
            // resolved the group by this point. dropped() REPLACES the context
            // rather than merging, which is what reproduces that omission here.
            return $this->dropped($reason, result: 'skipped');
        }

        // Known spammers cannot post anywhere. Mirrors IncomingMailService::route(),
        // which drops mail from a confirmed spammer before routing it at all - so
        // before lastaccess is touched, and with routing_reason and nothing else.
        // Only a confirmed 'Spammer', as there: 'PendingAdd' is a report awaiting
        // a decision, not a verdict.
        if (SpamUser::where('userid', $user->id)->where('collection', 'Spammer')->exists()) {
            Log::info('TN-SYNC-TRACE [POST-SKIP] reason=known-spammer tnpostid=' . $postId . ' user_id=' . $user->id);
            $this->loki->logEvent('tn-sync', 'post-skip-known-spammer', [
                'tn_post_id' => $postId,
                'user_id'    => $user->id,
            ]);

            return $this->dropped(self::REASON_KNOWN_SPAMMER);
        }

        // Update user's last access.
        Log::info('TN-SYNC-TRACE [WRITE] table=users op=update where=id=' . $user->id . ' set=lastaccess=now()');
        if (!$this->dryRun) {
            // ->save() rather than a query-builder update: $user is already a
            // loaded Eloquent model, and the repo convention is that writes go
            // through it so model events fire and Laravel Auditing sees them
            // (iznik-batch/CLAUDE.md, "Code Style"). The trace line above is
            // diffed byte-for-byte against the email path and is unaffected.
            $user->lastaccess = now();
            $user->save();
        }

        // The group here was chosen for the post (via Location::groupsNear() on its
        // own coordinates), not by the poster, so they are often not on it yet. On
        // the email path TN subscribes its member to the group before mailing a post
        // to it; this path has no such step, so it joins the poster itself - see
        // joinGroup() - and from then on applies the email path's membership logic
        // unchanged.
        $membershipRow = Membership::where('userid', $user->id)
            ->where('groupid', $group->id)
            ->first();

        // Banned from this group: never join them, and never post for them. Both
        // representations, as ExpandService checks before rippling into a group:
        // users_banned (what a ban writes now, after deleting the membership) and a
        // legacy collection='Banned' membership. The email path refuses the same
        // poster twice over - its subscribe handler will not re-join a banned member,
        // so their post then falls into the non-member drop below - and this is that
        // pair collapsed into one check, with its own reason.
        if ($membershipRow?->collection === Membership::COLLECTION_BANNED
            || UserBanned::where('userid', $user->id)->where('groupid', $group->id)->exists()) {
            Log::info('TN-SYNC-TRACE [POST-SKIP] reason=banned tnpostid=' . $postId . ' user_id=' . $user->id . ' group_id=' . $group->id);
            $this->loki->logEvent('tn-sync', 'post-skip-banned', [
                'tn_post_id' => $postId,
                'user_id'    => $user->id,
                'group_id'   => $group->id,
            ]);
            // dropped() replaces the context, so this carries routing_reason and
            // nothing else - the same shape as the email path's non-member drop.
            return $this->dropped(self::REASON_BANNED);
        }

        // A post placed here by its coordinates alone ($modMessagingAllowed false -
        // "unaddressed", see ModMessaging) comes from someone who never chose this
        // community. They are joined all the same, because the membership is what lets
        // its moderators moderate them: set a posting status and see who they are. But
        // silently - see joinGroup() - and Go's modmessaging bars the membership from
        // everything a ripple-join is barred from. The post still never auto-approves
        // (AutoApproveService skips an unaddressed origin row). The email
        // path never sees a post like this - TN only mails the groups its member chose -
        // so there is nothing there to mirror.
        if ($membershipRow === null) {
            $membershipRow = $this->joinGroup($user, $group, chosen: $modMessagingAllowed);
        } elseif ($modMessagingAllowed && $membershipRow->collection === Membership::COLLECTION_APPROVED) {
            $this->restoreMailIfNowChosen($user, $group, $membershipRow);
        }

        // Check membership - mirrors IncomingMailService::handleGroupPost() from
        // here on. Having just joined them, the only poster left who is not an
        // Approved member is one whose existing membership is still Pending (a
        // group that approves joins), and the email path refuses that post too.
        $membership = $membershipRow?->collection === Membership::COLLECTION_APPROVED ? $membershipRow : null;

        if ($membershipRow !== null && $membership === null) {
            Log::info('TN-SYNC-TRACE [POST-SKIP] reason=non-member tnpostid=' . $postId . ' user_id=' . $user->id . ' group_id=' . $group->id);
            $this->loki->logEvent('tn-sync', 'post-skip-non-member', [
                'tn_post_id' => $postId,
                'user_id'    => $user->id,
                'group_id'   => $group->id,
                'collection' => $membershipRow->collection,
            ]);

            return $this->dropped(self::REASON_NON_MEMBER);
        }

        // The email path swallows TAKEN/RECEIVED subjects here. Nothing to mirror:
        // PostSyncer only asks TN for offers and wanteds, so neither can arrive.
        // Nor its spam check, which it skips for a TrashNothing post.

        // Set context early - we know the group and user at this point.
        // Positioned exactly where IncomingMailService::handleGroupPost() sets
        // its equivalent, which is what makes the earlier drops above carry a
        // routing_reason and nothing else on BOTH paths.
        $this->lastRoutingContext = [
            'group_id'   => $group->id,
            'group_name' => $group->nameshort ?? $group->namefull ?? '',
            'user_id'    => $user->id,
        ];

        // PROHIBITED means the group's mods have stopped this person posting there,
        // so it is a refusal, checked before anything else can decide the outcome.
        // The web/API submit refuses it outright ("You are not allowed to post on
        // this group", message_submit.go) and rippling treats it as a hard stop.
        // The email path only looks at it in its last routing branch, after the
        // overrides below have replaced it with MODERATED and after the unmapped and
        // worry-word checks, so there a prohibited poster on a moderated group, or
        // with no location, or with a concern word, lands Pending for a moderator to
        // approve. Checked here first, so none of those let the post through. This
        // is the one place the routing below deliberately differs from the email path.
        if (strtoupper((string) $membership?->ourPostingStatus) === Membership::POSTING_STATUS_PROHIBITED) {
            Log::info('TN-SYNC-TRACE [POST-SKIP] reason=prohibited tnpostid=' . $postId . ' user_id=' . $user->id);
            // Mirrors email-path case 6, which returns RoutingResult::DROPPED
            // directly rather than via dropped(), so it keeps the group/user
            // context set above and carries NO routing_reason. Returning without
            // calling dropped() reproduces that exactly.
            return 'dropped';
        }

        // Determine posting status, applying the same override hierarchy as the email
        // path. NULL - which every join leaves, including both kinds joinGroup() makes -
        // is moderated, exactly as there: it is neither DEFAULT nor UNMODERATED below.
        $postingStatus = $membership?->ourPostingStatus;

        $overrideModeration = $group->overridemoderation ?? 'None';
        if ($overrideModeration === 'ModerateAll') {
            $postingStatus = 'MODERATED';
        }

        if ($user->isModeratorOf($group->id)) {
            $postingStatus = 'MODERATED';
        }

        $groupSettings = is_array($group->settings) ? $group->settings : (json_decode($group->settings ?? '{}', true) ?: []);
        if (!empty($groupSettings['moderated'])) {
            $postingStatus = 'MODERATED';
        }

        // Determine routing result.
        $routingResult = 'pending';
        $pendingReason = null;
        // True when the post is Pending ONLY so the content check can gate it - an
        // unmoderated poster, clean of the checks above - rather than for a moderator
        // reason. Mirrors IncomingMailService::handleGroupPost()'s flag of the same
        // name, and drives the same "don't notify mods, don't index" branch below.
        $awaitingContentCheck = false;

        if ($user->lastlocation === null) {
            $pendingReason = 'unmapped user';
        } elseif ($this->subjectContainsWorryWords($subject, $content)) {
            $pendingReason = 'worry words';
        } else {
            // Unmoderated posters (DEFAULT/UNMODERATED) are NOT approved on arrival. They
            // start Pending so messages:contentcheck gates them: clean posts are
            // auto-promoted within a minute, while posts matching a concern keyword or
            // content rule are held for a moderator instead of going live unchecked.
            // This matches IncomingMailService::handleGroupPost() and the web/API
            // submit path (iznik-server-go message.go). Approving on arrival here was
            // an unintended divergence - the email path was changed to gate these and
            // this path was not - not a deliberate one like those documented elsewhere
            // in this file. PROHIBITED never reaches here - it is refused above.
            $awaitingContentCheck = in_array($postingStatus, ['DEFAULT', 'UNMODERATED'], true);
        }

        // Create the message record.
        $messageId = $this->createMessage($user, $group, $subject, $content, $lat, $lng, $date, $postId, $photos, $modMessagingAllowed);

        if ($messageId === null) {
            // The email path's equivalent (case 10: a duplicate messageid, or an
            // exception after the row was created) still reports its
            // pre-computed Approved/Pending outcome and merely omits message_id.
            // This path genuinely returns 'skipped' instead, so reporting
            // Approved/Pending here would misstate what happened. The divergence
            // is real and expected — logged as Dropped with an explicit reason
            // so a comparison can account for it rather than trip over it.
            // dropped() would REPLACE the group/user context set above, which
            // the email path keeps in this branch, so set the reason directly.
            $this->lastRoutingContext['routing_reason'] = self::REASON_MESSAGE_CREATE_FAILED;

            return 'skipped';
        }

        // Mirrors IncomingMailService::handleGroupPost(), which appends the
        // created message id to the context it set earlier. On both paths this
        // key COLLIDES with the synthesized RFC822 message_id the caller passes
        // to Loki, and wins — see LokiService::buildRoutedMessage(). That is
        // what lets the email side be joined back to messages.tnpostid.
        $this->lastRoutingContext['message_id'] = $messageId;

        // Record in messages_postings (matches email path #12).
        Log::info('TN-SYNC-TRACE [WRITE] table=messages_postings op=insert set=msgid=' . $messageId . ',groupid=' . $group->id . ',repost=0,autorepost=0');
        if (!$this->dryRun) {
            DB::table('messages_postings')->insert([
                'msgid'      => $messageId,
                'groupid'    => $group->id,
                'repost'     => 0,
                'autorepost' => 0,
                'date'       => now(),
            ]);
        }

        // Note: unmoderated posters are no longer Approved on arrival (see the
        // routing note above). This branch is retained for completeness / any future
        // caller, matching the same retained branch on the email path; unmoderated
        // posters take the awaiting-content-check branch below.
        if ($routingResult === 'approved') {
            Log::info('TN-SYNC-TRACE [WRITE] table=messages_groups op=update where=msgid=' . $messageId . ' set=collection=Approved,approvedat=now()');
            if (!$this->dryRun) {
                MessageGroup::where('msgid', $messageId)
                    ->where('needs_moderator', 0)
                    ->where('locked_by_home', 0)
                    ->update([
                        'collection' => MessageGroup::COLLECTION_APPROVED,
                        'approvedat' => now(),
                    ]);
                $this->addToSpatialIndex($messageId, $group->id);
            }
            $this->loki->logEvent('tn-sync', 'post-create', ['tn_post_id' => $postId, 'msg_id' => $messageId, 'collection' => 'Approved']);
        } elseif ($awaitingContentCheck) {
            // Unmoderated poster: start Pending and let messages:contentcheck promote it
            // (clean) or hold it and notify mods (flagged). Deliberately NO mod
            // notification and NO spatial index here - both are the content-check job's
            // to do, so clean posts create no mod work and flagged posts never go live
            // unchecked. Identical to the email path's branch of the same name; the
            // trace and Loki lines are unchanged from the moderator-reason branch below
            // so the two pending kinds stay indistinguishable to a parity comparison,
            // exactly as they are on the email side.
            Log::info('TN-SYNC-TRACE [WRITE] table=messages_groups op=update where=msgid=' . $messageId . ' set=collection=Pending reason=' . ($pendingReason ?? 'posting-status'));
            if (!$this->dryRun) {
                MessageGroup::where('msgid', $messageId)->update(['collection' => MessageGroup::COLLECTION_PENDING]);
            }
            $this->loki->logEvent('tn-sync', 'post-create', ['tn_post_id' => $postId, 'msg_id' => $messageId, 'collection' => 'Pending', 'reason' => $pendingReason]);
        } else {
            // Pending for a moderator reason (moderated user/group, worry words,
            // unmapped user, Big Switch, mod poster) - notify mods now.
            Log::info('TN-SYNC-TRACE [WRITE] table=messages_groups op=update where=msgid=' . $messageId . ' set=collection=Pending reason=' . ($pendingReason ?? 'posting-status'));
            if (!$this->dryRun) {
                MessageGroup::where('msgid', $messageId)->update(['collection' => MessageGroup::COLLECTION_PENDING]);
                $this->notifyGroupMods($group->id);
            }
            $this->loki->logEvent('tn-sync', 'post-create', ['tn_post_id' => $postId, 'msg_id' => $messageId, 'collection' => 'Pending', 'reason' => $pendingReason]);
            // $pendingReason is deliberately NOT recorded as routing_reason. The
            // email path computes the same value and keeps it out of its Loki
            // context too (case 9 — it reaches laravel.log only), so exposing it
            // here would show up as a difference between the paths where none
            // exists. It remains available above on the batch_event stream.
        }

        return $routingResult;
    }

    private function createMessage(
        User $user,
        Group $group,
        string $subject,
        string $content,
        mixed $lat,
        mixed $lng,
        mixed $date,
        string $postId,
        array $photos,
        bool $modMessagingAllowed,
    ): ?int {
        try {
            $type = Message::determineType($subject);

            // Synthesized message-ID: unique per post+group, stable across retries.
            $messageid = $postId . '@tn.trashnothing.com-' . $group->id;

            // Resolve location: use API coordinates directly (replaces header parsing).
            $locationId = null;
            if ($lat !== null && $lng !== null) {
                $locationId = $this->findClosestPostcodeId((float) $lat, (float) $lng);
            } else {
                [$lat, $lng] = $user->getLatLng();
                if ($user->lastlocation) {
                    $locationId = $user->lastlocation;
                }
            }

            if ($lat !== null && $lng !== null && $locationId === null) {
                $locationId = $this->findClosestPostcodeId((float) $lat, (float) $lng);
            }

            // The id came from the spatial index, which is a separate store and can
            // outlive the row it points at - a purged or renumbered location leaves a
            // stale entry behind. users.lastlocation is a foreign key, so writing an id
            // that is no longer in `locations` throws, and because that happens inside
            // createMessage the whole post is lost rather than just its location. A
            // post with no location is still worth having, so verify before trusting it.
            if ($locationId !== null && !DB::table('locations')->where('id', $locationId)->exists()) {
                Log::info('TN-SYNC-TRACE [LOCATION-STALE] spatial index returned locationid=' . $locationId
                    . ' which is not in locations; ingesting without a location');
                $locationId = null;
            }

            // TN is the master for a TN member's location (tn:sync keeps lastlocation in
            // step with it), so a post only fills it in when it is empty; the post's own
            // point is where the item is, not where the member is.
            if ($locationId && $user->id && $user->lastlocation === null) {
                Log::info('TN-SYNC-TRACE [WRITE] table=users op=update where=id=' . $user->id . ' set=lastlocation=' . $locationId);
                if (!$this->dryRun) {
                    $user->lastlocation = $locationId;
                    $user->save();
                }
            }

            $fromName = $user->fullname ?? $user->firstname ?? null;

            // TN's own post date, however it arrived. The OpenAPI client hands
            // us a DateTime; an array-shaped post (fixtures, --local-testing,
            // the backfill path) carries the same value as an ISO-8601 string,
            // and taking now() for those silently rewrote every such post's
            // date to ingestion time.
            $postedAt = $this->normalizePostDate($date);

            // Synthesize minimal RFC822 message blob so downstream code that
            // re-parses messages.message still recovers the key fields.
            $dateStr = ($postedAt ?? now())->format('D, d M Y H:i:s +0000');
            $groupEmail = $group->nameshort . '@' . config('freegle.mail.group_domain', 'groups.ilovefreegle.org');
            $rfc822 = $this->synthesizeRfc822($fromName, $groupEmail, $subject, $dateStr, $messageid, $postId, $lat, $lng, $content);

            $msgData = [
                'date'            => $postedAt !== null ? $postedAt->format('Y-m-d H:i:s') : now(),
                'source'          => Message::SOURCE_EMAIL,
                'sourceheader'    => self::SOURCEHEADER,
                'message'         => $rfc822,
                'fromuser'        => $user->id,
                'envelopefrom'    => null,
                'envelopeto'      => $groupEmail,
                'fromname'        => $fromName,
                'fromaddr'        => null,
                'replyto'         => null,
                'fromip'          => null,
                'fromcountry'     => null,
                'subject'         => $subject,
                'suggestedsubject' => $subject,
                'messageid'       => $messageid,
                'tnpostid'        => $postId,
                'textbody'        => $content,
                'type'            => $type,
                'lat'             => $lat !== null ? (float) $lat : null,
                'lng'             => $lng !== null ? (float) $lng : null,
                'locationid'      => $locationId,
                'spamtype'        => null,
                'spamreason'      => null,
            ];

            Log::info('TN-SYNC-TRACE [WRITE] table=messages op=insert set=' . json_encode([
                'messageid' => $messageid,
                'tnpostid'  => $postId,
                'groupid'   => $group->id,
                'fromuser'  => $user->id,
                'type'      => $type,
                'subject'   => $subject,
                'lat'       => $lat,
                'lng'       => $lng,
                'locationid' => $locationId,
            ]));

            $message = null;
            if (!$this->dryRun) {
                $message = Message::create($msgData);
                if (!$message || !$message->id) {
                    Log::error('TN post ingestion: failed to create message record');
                    return null;
                }
            }

            $messageId = $message?->id ?? 0;

            // messages_groups entry — starts as Incoming; collection updated after routing.
            // NB: this trace line is diffed byte-for-byte against the email path in
            // EmailApiParityTest, so mod_messaging_allowed (an API-only field with no
            // email-path equivalent) is deliberately NOT included here — see the
            // separate TN-SYNC-TRACE [POST-META] line in PostSyncer for that.
            Log::info('TN-SYNC-TRACE [WRITE] table=messages_groups op=insert set=msgid=' . $messageId . ',groupid=' . $group->id . ',msgtype=' . $type . ',collection=Incoming');
            if (!$this->dryRun) {
                MessageGroup::create([
                    'msgid'                 => $messageId,
                    'groupid'               => $group->id,
                    'msgtype'               => $type,
                    'collection'            => MessageGroup::COLLECTION_INCOMING,
                    'arrival'               => now(),
                    'mod_messaging_allowed' => $modMessagingAllowed,
                ]);
            }

            // messages_items link (weight statistics, same as email path).
            if (!$this->dryRun) {
                $this->itemService->recordFromSubject($messageId, $subject);
            }

            // messages_history.
            $prunedSubject = $this->pruneSubject($subject);
            Log::info('TN-SYNC-TRACE [WRITE] table=messages_history op=insert set=msgid=' . $messageId . ',groupid=' . $group->id . ',fromuser=' . $user->id);
            if (!$this->dryRun) {
                DB::table('messages_history')->insert([
                    'groupid'       => $group->id,
                    'source'        => Message::SOURCE_EMAIL,
                    'fromuser'      => $user->id,
                    'envelopefrom'  => null,
                    'envelopeto'    => $groupEmail,
                    'fromname'      => $fromName,
                    'fromaddr'      => null,
                    'fromip'        => null,
                    'subject'       => $subject,
                    'prunedsubject' => $prunedSubject,
                    'messageid'     => $messageid,
                    'msgid'         => $messageId,
                ]);
            }

            // Log receipt.
            Log::info('TN-SYNC-TRACE [WRITE] table=logs op=insert set=type=Message,subtype=Received,msgid=' . $messageId . ',groupid=' . $group->id);
            if (!$this->dryRun) {
                DB::table('logs')->insert([
                    'timestamp' => now(),
                    'type'      => 'Message',
                    'subtype'   => 'Received',
                    'groupid'   => $group->id,
                    'user'      => $user->id,
                    'msgid'     => $messageId,
                    'text'      => $messageid,
                ]);
            }

            // Process TN photos from API (replaces scraping trashnothing.com/pics/ links).
            if (!empty($photos)) {
                $this->createImageAttachments($messageId, $photos);
            }

            return $this->dryRun ? -1 : $messageId;

        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                Log::info('TN post ingestion: duplicate messageid, skipping', ['tnpostid' => $postId]);
                return null;
            }

            Log::error('TN post ingestion: failed to create message', [
                'tnpostid' => $postId,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Join the poster to the group the post resolved to, as the membership TN's
     * subscribe mail would have given them on the email path.
     *
     * Mirrors IncomingMailService::handleSubscribe(): an Approved member on the daily
     * digest, with ourPostingStatus left NULL as every join path leaves it - which
     * the routing treats as moderated, so a poster's posts on a group new to them
     * are held for its moderators until a moderator says otherwise - and a
     * Group/Joined log so moderators can see how they arrived. Never called for a
     * banned poster; ingest() refuses those first.
     *
     * A poster who never chose the community ($chosen false) gets the same membership
     * with every mail setting off - no digest, no events or volunteering roundup -
     * because a membership is what Freegle's mail is sent by and they have not opted in
     * to any of it. EmailSpoolerService refuses the rest for someone who is unaddressed
     * everywhere. Its Joined log says so (JOINED_UNADDRESSED_TEXT), which is also what
     * restoreMailIfNowChosen() reads to know that this silence was ours to lift.
     *
     * In a dry run nothing is written, and the unsaved membership stands in for
     * the one that would have been, so routing is traced as it would really run.
     */
    private function joinGroup(User $user, Group $group, bool $chosen): Membership
    {
        $membership = new Membership();
        $membership->userid         = $user->id;
        $membership->groupid        = $group->id;
        $membership->role           = Membership::ROLE_MEMBER;
        $membership->collection     = Membership::COLLECTION_APPROVED;
        $membership->added          = now();
        $membership->emailfrequency = $chosen ? Membership::EMAIL_FREQUENCY_DAILY : Membership::EMAIL_FREQUENCY_NEVER;
        if (!$chosen) {
            $membership->eventsallowed       = false;
            $membership->volunteeringallowed = 0;
        }

        Log::info('TN-SYNC-TRACE [WRITE] table=memberships op=insert set=userid=' . $user->id . ',groupid=' . $group->id . ',collection=Approved' . ($chosen ? '' : ',silent=1'));
        Log::info('TN-SYNC-TRACE [WRITE] table=logs op=insert set=type=Group,subtype=Joined,user=' . $user->id . ',groupid=' . $group->id);
        if ($this->dryRun) {
            return $membership;
        }

        try {
            $membership->save();
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent join got there first (another sync run, or TN's subscribe
            // mail for the same member); theirs is the membership.
            return Membership::where('userid', $user->id)->where('groupid', $group->id)->firstOrFail();
        }

        DB::table('logs')->insert([
            'timestamp' => now(),
            'type'      => 'Group',
            'subtype'   => 'Joined',
            'user'      => $user->id,
            'byuser'    => $user->id,
            'groupid'   => $group->id,
            'text'      => $chosen ? self::JOINED_TEXT : self::JOINED_UNADDRESSED_TEXT,
        ]);

        return $membership;
    }

    /**
     * Turn a silent membership's mail on once TN says the poster chose the community.
     *
     * An addressed post on a community where joinGroup() once joined them silently means
     * they have now picked it on TN, so the membership is an ordinary one and gets what
     * joinGroup() gives any chosen join. Nothing else would ever do this: every join path,
     * here and in Go, is a no-op on a membership that already exists.
     *
     * Only the silence ingestion imposed is lifted. Three things must hold: the poster's
     * tie to the community is still only unaddressed posts (read before this post's own
     * row is written); their most recent Joined log there is ingestion's silent join,
     * so they did not arrive some other way; and all three settings are still off, so
     * neither they nor a moderator has changed them since.
     */
    private function restoreMailIfNowChosen(User $user, Group $group, Membership $membership): void
    {
        $silent = (int) $membership->emailfrequency === Membership::EMAIL_FREQUENCY_NEVER
            && !$membership->eventsallowed
            && !$membership->volunteeringallowed;

        if (!$silent || !ModMessaging::isUnaddressedOn($user->id, $group->id)) {
            return;
        }

        $lastJoin = DB::table('logs')
            ->where('user', $user->id)
            ->where('groupid', $group->id)
            ->where('type', 'Group')
            ->where('subtype', 'Joined')
            ->orderByDesc('id')
            ->value('text');

        if ($lastJoin !== self::JOINED_UNADDRESSED_TEXT) {
            return;
        }

        Log::info('TN-SYNC-TRACE [WRITE] table=memberships op=update where=id=' . $membership->id
            . ' set=emailfrequency=' . Membership::EMAIL_FREQUENCY_DAILY . ',eventsallowed=1,volunteeringallowed=1');
        if ($this->dryRun) {
            return;
        }

        $membership->emailfrequency      = Membership::EMAIL_FREQUENCY_DAILY;
        $membership->eventsallowed       = true;
        $membership->volunteeringallowed = 1;
        $membership->save();
    }

    /**
     * The routing_reason for a TN user id the provisioner could not resolve.
     *
     * TN having no usable user is the email path's unknown user, so it keeps
     * that path's wording; the other failures can only happen here.
     */
    private function unresolvedUserReason(): string
    {
        return match ($this->userProvisioner->lastFailureReason()) {
            TnUserProvisioner::REASON_LOOKUP_FAILED   => self::REASON_TN_USER_LOOKUP_FAILED,
            TnUserProvisioner::REASON_USERNAME_CLASH  => self::REASON_TN_USERNAME_CLASH,
            default                                   => self::REASON_UNKNOWN_USER,
        };
    }

    /**
     * The message we already hold for this TN post on this group, live or
     * deleted, or null if we have never ingested it here. Lowest id wins, so
     * the answer is stable if an older duplicate somehow survives.
     *
     * DELETED MESSAGES DELIBERATELY STILL COUNT, which is why this is not the
     * email path's findLiveTnMessage() (IncomingMailService.php:3135) with a
     * different name. The two ask different questions:
     *
     *  - findLiveTnMessage() asks "is there a live message I can hang another
     *    group off?", so it must skip deleted ones — attaching a messages_groups
     *    row to a deleted message would put the group's copy on something no
     *    member can see.
     *  - this asks "have we ingested this post for this group before?" — an
     *    idempotency guard, which a deleted message answers yes to just as
     *    firmly as a live one. Filtering deleted out here would mean the next
     *    run whose window still covers the post RE-CREATES it, resurrecting
     *    something a moderator, the member or a user purge deliberately removed,
     *    and doing so again on every overlapping run.
     *
     * The two places that soft-delete a TN message as a *duplicate* rather than
     * as a decision — the email path's lost-create-race branch
     * (IncomingMailService.php:2999) and TnMergeCrosspostsCommand — both null
     * tnpostid in the same update, so they never match here and never block a
     * later ingest.
     *
     * Re-ingesting a post whose message was deliberately deleted is therefore an
     * explicit human action, not a side effect of a window overlap: clear the
     * tnpostid on the deleted row and re-run the sync for that window.
     */
    private function existingMessageForGroup(string $tnPostId, int $groupId): ?object
    {
        return DB::table('messages')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->where('messages.tnpostid', $tnPostId)
            ->where('messages_groups.groupid', $groupId)
            ->orderBy('messages.id')
            ->select('messages.id', 'messages.deleted')
            ->first();
    }

    /**
     * Duplicate of IncomingMailService::containsWorryWords (subject + body).
     * Kept separate per plan: no extraction until email path parity is proven.
     *
     * Reads concern_keywords (scope=global) rather than the legacy 'worrywords'
     * table, for the same reason the email path was changed in ac3f80c82:
     * worrywords is a one-time migration snapshot (see MigrateConcernKeywordsCommand)
     * that is never written to again, so every keyword/whitelist edit made via the
     * current admin UI (ModSupportConcernKeywords) only reaches concern_keywords.
     * Reading worrywords here meant a moderator whitelisting a phrase (e.g.
     * 'Cashes Green', Discourse #9944) had no effect on posts arriving via the TN
     * API - identical content was held here but let through by email.
     */
    private function subjectContainsWorryWords(string $subject, string $body): bool
    {
        if (str_contains($subject, '£') || str_contains($body, '£')) {
            return true;
        }

        $worryWords = DB::table('concern_keywords')->where('scope', 'global')->get();

        foreach ($worryWords as $ww) {
            if ($ww->category === 'allowed') {
                $pattern  = '/\b' . preg_quote($ww->keyword, '/') . '\b/i';
                $subject  = preg_replace($pattern, '', $subject) ?? $subject;
                $body     = preg_replace($pattern, '', $body) ?? $body;
            }
        }

        foreach ($worryWords as $ww) {
            if ($ww->category !== 'allowed' && str_contains($ww->keyword, ' ')) {
                if (stripos($subject, $ww->keyword) !== false || stripos($body, $ww->keyword) !== false) {
                    return true;
                }
            }
        }

        $allWords = preg_split('/\b/', $subject . ' ' . $body);
        foreach ($allWords as $word) {
            $word = trim($word);
            if (empty($word)) {
                continue;
            }
            foreach ($worryWords as $ww) {
                if ($ww->category !== 'allowed' && !empty($ww->keyword)) {
                    $ratio = strlen($word) / strlen($ww->keyword);
                    if ($ratio >= 0.75 && $ratio <= 1.25 && levenshtein(strtolower($ww->keyword), strtolower($word)) < 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Duplicate of IncomingMailService::pruneSubject.
     */
    private function pruneSubject(?string $subject): ?string
    {
        if ($subject === null) {
            return null;
        }
        $pruned = preg_replace('/\s*\([^)]+\)\s*$/', '', $subject);
        $pruned = preg_replace('/^(OFFER|WANTED|TAKEN|RECEIVED)\s*:\s*/i', '', $pruned);
        return trim($pruned);
    }

    /**
     * Duplicate of IncomingMailService::findClosestPostcodeId.
     */
    private function findClosestPostcodeId(float $lat, float $lng): ?int
    {
        $ids = (new SpatialQueryService())->nearestIds('postcodes', $lat, $lng, 1);
        return $ids[0] ?? null;
    }

    /**
     * Duplicate of IncomingMailService::addToSpatialIndex.
     */
    private function addToSpatialIndex(int $messageId, int $groupId): void
    {
        $message = Message::query()->useWritePdo()->find($messageId);
        if (!$message || (!$message->lat && !$message->lng)) {
            return;
        }

        $srid = config('freegle.srid', 3857);
        $mg   = DB::table('messages_groups')->useWritePdo()
            ->where('msgid', $messageId)
            ->where('groupid', $groupId)
            ->first();

        $arrival = $mg->arrival ?? now();
        $msgType = $message->type;

        try {
            DB::statement(
                "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival)
                 VALUES (?, ST_GeomFromText('POINT({$message->lng} {$message->lat})', ?), ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                 point = ST_GeomFromText('POINT({$message->lng} {$message->lat})', ?),
                 groupid = ?, msgtype = ?, arrival = ?",
                [$messageId, $srid, $groupId, $msgType, $arrival, $srid, $groupId, $msgType, $arrival]
            );
        } catch (\Exception $e) {
            Log::warning('TN post ingestion: failed to add to spatial index', [
                'message_id' => $messageId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Duplicate of IncomingMailService::notifyGroupMods.
     */
    private function notifyGroupMods(int $groupId): void
    {
        try {
            $count = app(\App\Services\PushNotificationService::class)->notifyGroupMods($groupId);
            Log::info('TN post ingestion: notified group mods', ['group_id' => $groupId, 'count' => $count]);
        } catch (\Throwable $e) {
            Log::warning('TN post ingestion: failed to notify group mods', ['group_id' => $groupId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Download TN API photo URLs and create MessageAttachment records.
     * Replaces scrapeTnImageUrls + createTnImageAttachments from email path —
     * photos are delivered directly by the API, no HTML scraping needed.
     */
    private function createImageAttachments(int $messageId, array $photos): int
    {
        // Emitted unconditionally, with the INTENDED count, exactly as the
        // email path's createTnImageAttachments() does — otherwise a live
        // parity run shows the email path writing attachments and the API path
        // apparently writing none, which reads as a regression rather than as
        // a hole in the trace.
        Log::info('TN-SYNC-TRACE [WRITE] table=message_attachments op=insert set=msgid=' . $messageId . ' count=' . count($photos) . ($this->dryRun ? ' (dry-run, not fetching)' : ''));

        if ($this->dryRun) {
            return count($photos);
        }

        $tusService = app(TusService::class);
        $created    = 0;
        $isFirst    = true;

        foreach ($photos as $photo) {
            $url = $this->bestPhotoUrl($photo);
            if (!$url) {
                continue;
            }

            try {
                $response = Http::timeout(120)->get($url);
                if (!$response->successful()) {
                    Log::warning('TN post ingestion: failed to download photo', ['url' => $url, 'status' => $response->status()]);
                    continue;
                }

                $imageData   = $response->body();
                $contentType = $response->header('Content-Type') ?? 'image/jpeg';
                $hash        = $this->computeImageHash($imageData);

                if ($hash && MessageAttachment::where('msgid', $messageId)->where('hash', $hash)->exists()) {
                    continue;
                }

                $tusUrl = $tusService->upload($imageData, $contentType);
                if (!$tusUrl) {
                    Log::warning('TN post ingestion: failed to upload photo to tusd', ['url' => $url]);
                    continue;
                }

                $externalUid = TusService::urlToExternalUid($tusUrl);

                MessageAttachment::create([
                    'msgid'       => $messageId,
                    'externaluid' => $externalUid,
                    'hash'        => $hash,
                    'primary'     => $isFirst,
                ]);

                $created++;
                $isFirst = false;

            } catch (\Exception $e) {
                Log::warning('TN post ingestion: exception processing photo', ['url' => $url, 'error' => $e->getMessage()]);
            }
        }

        return $created;
    }

    /**
     * Return the best available URL from a TN Photo object or array.
     *
     * TN documents `images` as "all the versions of this photo ordered from
     * SMALLEST to largest" (see PublicApi/docs/Model/Photo.md), so the LAST
     * entry is the highest resolution — taking the first ingested a thumbnail
     * (220x294 observed live) where the email path, which scrapes the post
     * body, gets the full-size image (1200x900 for the same post). Falls back
     * to `url` ("a large version of this photo"), which TN also guarantees is
     * present somewhere in `images`.
     */
    private function bestPhotoUrl(mixed $photo): ?string
    {
        if (is_array($photo)) {
            $images = $photo['images'] ?? [];
            if (!empty($images)) {
                return end($images)['url'] ?? $photo['url'] ?? null;
            }
            return $photo['url'] ?? null;
        }

        // OpenAPI Photo object
        $images = $photo->getImages() ?? [];
        if (!empty($images)) {
            return end($images)->getUrl() ?? $photo->getUrl();
        }
        return $photo->getUrl();
    }

    /**
     * Compute perceptual hash for image deduplication.
     * Duplicate of IncomingMailService::computeImageHash.
     */
    private function computeImageHash(string $imageData): ?string
    {
        try {
            $img = @\imagecreatefromstring($imageData);
            if (!$img) {
                return substr(md5($imageData), 0, 16);
            }
            if (class_exists(\Jenssegers\ImageHash\ImageHash::class)) {
                $hasher = new \Jenssegers\ImageHash\ImageHash;
                $hash   = $hasher->hash($img)->toHex();
                \imagedestroy($img);
                return substr($hash, 0, 16);
            }
            \imagedestroy($img);
            return substr(md5($imageData), 0, 16);
        } catch (\Exception $e) {
            return substr(md5($imageData), 0, 16);
        }
    }

    /**
     * Synthesize a minimal RFC822 message blob so any code that re-parses
     * messages.message can still recover the key fields.
     */
    private function synthesizeRfc822(
        ?string $fromName,
        string $groupEmail,
        string $subject,
        string $date,
        string $messageId,
        string $tnPostId,
        mixed $lat,
        mixed $lng,
        string $body,
    ): string {
        $from = $fromName ? "{$fromName} <noreply@trashnothing.com>" : 'noreply@trashnothing.com';
        $coords = ($lat !== null && $lng !== null) ? "{$lat},{$lng}" : '';

        return implode("\r\n", [
            "From: {$from}",
            "To: {$groupEmail}",
            "Subject: {$subject}",
            "Date: {$date}",
            "Message-ID: <{$messageId}>",
            "X-Trashnothing-Post-Id: {$tnPostId}",
            // Keeps a re-parse of this blob agreeing with messages.sourceheader:
            // IncomingMailService::determineSourceHeader() returns 'TN-' . this value.
            'X-Trash-Nothing-Source: API',
            $coords ? "X-Trashnothing-Coordinates: {$coords}" : '',
            'Content-Type: text/plain; charset=utf-8',
            '',
            $body,
        ]);
    }

    /**
     * Unified field accessor for both OpenAPI objects and fixture arrays.
     *
     * @param  mixed   $post      OpenAPI Post object or array
     * @param  string  $arrayKey  Key name for array (fixture) access
     * @param  string  $method    Getter method name for object access
     */
    /**
     * TN's post date as a DateTimeInterface, or null when there isn't a usable one.
     *
     * The API client returns a DateTime, but the same post reaches us as a plain
     * array with an ISO-8601 string from fixtures, --local-testing and anything
     * that rebuilt the post from JSON. Both must keep TN's date; only a missing
     * or unparseable value falls back to now().
     */
    private function normalizePostDate(mixed $date): ?\DateTimeInterface
    {
        if ($date instanceof \DateTimeInterface) {
            return $date;
        }

        if (is_string($date) && trim($date) !== '') {
            try {
                return new \DateTimeImmutable($date);
            } catch (\Exception $e) {
                Log::warning('TN post ingestion: unparseable post date, using now()', ['date' => $date]);
            }
        }

        return null;
    }

    private function getField(mixed $post, string $arrayKey, string $method): mixed
    {
        if (is_array($post)) {
            return $post[$arrayKey] ?? null;
        }
        return method_exists($post, $method) ? $post->$method() : null;
    }
}
