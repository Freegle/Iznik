<?php

namespace App\Services\TrashNothing\Ingestion;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageGroup;
use App\Models\User;
use App\Models\UserEmail;
use App\Services\ItemService;
use App\Services\LokiService;
use App\Services\SpatialQueryService;
use App\Services\TusService;
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
 * With $dryRun = true, no DB writes occur. Every would-be write emits a
 * TN-SYNC-TRACE [WRITE] log line for diffing against the email path.
 */
class GroupPostIngestionService
{
    // How close (in metres) a candidate message's coordinates must be to a new
    // TN post's coordinates to be considered a repost of it, once fromuser/
    // group/subject already match. Generous enough to absorb GPS/geocoding
    // jitter between two posts of the same item, tight enough not to match a
    // different item at a similar address.
    private const REPOST_MATCH_RADIUS_METERS = 50;

    public function __construct(
        private readonly bool $dryRun,
        private readonly LokiService $loki,
        private readonly ItemService $itemService,
    ) {}

    /**
     * Ingest a single TN API post for the given Freegle group.
     *
     * @param  mixed  $post  OpenAPI Post object or fixture array
     * @param  Group  $group  Resolved Freegle group
     * @param  bool  $modMessagingAllowed  Whether mods on $group may message this poster directly
     * @return string  'approved'|'pending'|'duplicate'|'dropped'|'skipped'|'reposted'
     */
    public function ingest(mixed $post, Group $group, bool $modMessagingAllowed = true): string
    {
        $postId   = $this->getField($post, 'post_id', 'getPostId');
        $fdUserId = $this->getField($post, 'user_id', 'getUserId');
        $title    = $this->getField($post, 'title', 'getTitle') ?? '';
        $content  = $this->getField($post, 'content', 'getContent') ?? '';
        $date     = $this->getField($post, 'date', 'getDate');
        $lat      = $this->getField($post, 'latitude', 'getLatitude');
        $lng      = $this->getField($post, 'longitude', 'getLongitude');
        $tnType   = strtolower((string) $this->getField($post, 'type', 'getType'));
        $photos   = $this->getField($post, 'photos', 'getPhotos') ?? [];

        $subject = strtoupper($tnType) . ': ' . $title;

        // Idempotency: skip if this post was already ingested for this group.
        $isDuplicate = $this->postAlreadyExists((string) $postId, $group->id);
        if ($isDuplicate) {
            Log::info('TN-SYNC-TRACE [POST-SKIP] reason=duplicate tnpostid=' . $postId . ' groupid=' . $group->id . ($this->dryRun ? ' would_be_duplicate=true' : ''));
            $this->loki->logEvent('tn-sync', 'post-skip-duplicate', ['tn_post_id' => $postId, 'group_id' => $group->id]);
            return 'duplicate';
        }

        // Resolve Freegle user from TN fd_user_id, creating a stub account if needed.
        $user = $fdUserId ? $this->findOrCreateUser((int) $fdUserId, $group) : null;
        if ($user === null) {
            $reason = $fdUserId ? 'unknown-user' : 'no-user-id';
            Log::info('TN-SYNC-TRACE [POST-SKIP] reason=' . $reason . ' tnpostid=' . $postId . ' fd_user_id=' . $fdUserId);
            $this->loki->logEvent('tn-sync', 'post-skip-unknown-user', ['tn_post_id' => $postId, 'fd_user_id' => $fdUserId]);
            return 'skipped';
        }

        // Update user's last access.
        Log::info('TN-SYNC-TRACE [WRITE] table=users op=update where=id=' . $user->id . ' set=lastaccess=now()');
        if (!$this->dryRun) {
            DB::table('users')->where('id', $user->id)->update(['lastaccess' => now()]);
        }

        // Repost/crosspost detection: TN gives no explicit link between a
        // repost (or a crosspost to another group) and the original post —
        // both create an entirely new post_id with a new published date, not
        // a mutation/reference to the original (confirmed by the TN team).
        // We detect either by matching an existing live message ANYWHERE
        // (not scoped to $group — see findRepostCandidate()), with a
        // normalized-matching subject and coordinates within
        // REPOST_MATCH_RADIUS_METERS — deliberately NOT requiring the same
        // resolved fromuser, since TN's numeric user id is scoped per
        // group-affiliation, not stable per real person (see
        // findRepostCandidate()'s docblock for the live example that proved
        // this). When matched, bump the EXISTING message in its OWN group
        // (same DB pattern as AutoRepostService::repost(), which handles
        // Freegle's own inactivity-triggered auto-reposts) rather than
        // creating a duplicate new one in $group — Freegle already has its
        // own cross-posting/rippling mechanisms, so a TN crosspost to a
        // different group must never result in a second independent FD
        // message for the same real-world donation. Skips entirely if
        // $lat/$lng are missing — no coordinates, no reliable match.
        $repostCandidate = $this->findRepostCandidate($subject, $lat, $lng);
        if ($repostCandidate !== null) {
            $postDate = $this->normalizePostDate($date);
            // Idempotency compares against the candidate's own `date` (the latest
            // known TN content date it reflects), NOT messages_groups.arrival —
            // arrival is the ingestion/bump wall-clock time, which is always "now"
            // and therefore always >= any TN post's own (necessarily past) date;
            // comparing against it would make every genuine repost look
            // "already bumped". `date` starts as the original post's date and is
            // advanced to the repost's date by bumpAsRepost() below, so comparing
            // against it correctly distinguishes "this exact repost (or a later
            // one) was already applied" from "this is newer than what we have".
            if ($postDate !== null && $repostCandidate->date !== null && $repostCandidate->date->gte($postDate)) {
                Log::info('TN-SYNC-TRACE [POST-SKIP] reason=repost-already-bumped tnpostid=' . $postId . ' msgid=' . $repostCandidate->msgid);
                return 'duplicate';
            }

            Log::info('TN-SYNC-TRACE [WRITE] table=messages_groups op=update where=msgid=' . $repostCandidate->msgid . ',groupid=' . $repostCandidate->groupid . ' set=arrival=now(),autoreposts+1 (tn-repost)');
            Log::info('TN-SYNC-TRACE [WRITE] table=messages op=update where=id=' . $repostCandidate->msgid . ' set=tnpostid=' . $postId . ' (tn-repost)');
            if (!$this->dryRun) {
                // Bumped in the candidate's OWN group — which may differ from
                // $group when this TN post_id is a crosspost rather than a same-
                // group repost — and logged against the ORIGINAL message's own
                // poster, not this repost's resolved user. See
                // findRepostCandidate()'s docblock for why the two can
                // legitimately be different Freegle stub users for the same person.
                $this->bumpAsRepost($repostCandidate->msgid, $repostCandidate->groupid, $repostCandidate->fromuser, $postDate, (string) $postId);
            }
            $this->loki->logEvent('tn-sync', 'post-repost-bump', ['tn_post_id' => $postId, 'msg_id' => $repostCandidate->msgid, 'group_id' => $repostCandidate->groupid]);
            return 'reposted';
        }

        // No membership gate: the group here was chosen for the post (via
        // Location::groupsNear() on its own coordinates), not supplied by a member
        // posting to a group they belong to, so the poster is frequently not an
        // Approved member of the resolved group — that's expected, not an error.
        // If a membership does exist, its ourPostingStatus still applies (matches
        // the email path for genuine members); otherwise fall back to the same
        // 'DEFAULT' a brand-new member gets. The group's own moderation settings
        // below still apply regardless.
        $membership = Membership::where('userid', $user->id)
            ->where('groupid', $group->id)
            ->where('collection', 'Approved')
            ->first();

        if ($membership === null) {
            Log::info('TN-SYNC-TRACE [POST-META] reason=non-member tnpostid=' . $postId . ' user_id=' . $user->id . ' group_id=' . $group->id);
        }

        // Determine posting status, applying the same override hierarchy as the email path.
        $postingStatus = $membership->ourPostingStatus ?? 'DEFAULT';

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

        if ($user->lastlocation === null) {
            $pendingReason = 'unmapped user';
        } elseif ($this->subjectContainsWorryWords($subject, $content)) {
            $pendingReason = 'worry words';
        } else {
            $routingResult = match ($postingStatus) {
                'DEFAULT', 'UNMODERATED' => 'approved',
                'PROHIBITED'             => 'dropped',
                default                  => 'pending',
            };
        }

        if ($routingResult === 'dropped') {
            Log::info('TN-SYNC-TRACE [POST-SKIP] reason=prohibited tnpostid=' . $postId . ' user_id=' . $user->id);
            return 'dropped';
        }

        // Create the message record.
        $messageId = $this->createMessage($user, $group, $subject, $content, $lat, $lng, $date, $postId, $photos, $modMessagingAllowed);

        if ($messageId === null) {
            return 'skipped';
        }

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

        if ($routingResult === 'approved') {
            Log::info('TN-SYNC-TRACE [WRITE] table=messages_groups op=update where=msgid=' . $messageId . ' set=collection=Approved,approvedat=now()');
            if (!$this->dryRun) {
                MessageGroup::where('msgid', $messageId)->update([
                    'collection' => MessageGroup::COLLECTION_APPROVED,
                    'approvedat' => now(),
                ]);
                $this->addToSpatialIndex($messageId, $group->id);
            }
            $this->loki->logEvent('tn-sync', 'post-create', ['tn_post_id' => $postId, 'msg_id' => $messageId, 'collection' => 'Approved']);
        } else {
            Log::info('TN-SYNC-TRACE [WRITE] table=messages_groups op=update where=msgid=' . $messageId . ' set=collection=Pending reason=' . ($pendingReason ?? 'posting-status'));
            if (!$this->dryRun) {
                MessageGroup::where('msgid', $messageId)->update(['collection' => MessageGroup::COLLECTION_PENDING]);
                $this->notifyGroupMods($group->id);
            }
            $this->loki->logEvent('tn-sync', 'post-create', ['tn_post_id' => $postId, 'msg_id' => $messageId, 'collection' => 'Pending', 'reason' => $pendingReason]);
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

            if ($locationId && $user->id) {
                Log::info('TN-SYNC-TRACE [WRITE] table=users op=update where=id=' . $user->id . ' set=lastlocation=' . $locationId);
                if (!$this->dryRun) {
                    DB::table('users')->where('id', $user->id)->update(['lastlocation' => $locationId]);
                }
            }

            $fromName = $user->fullname ?? $user->firstname ?? null;

            // Synthesize minimal RFC822 message blob so downstream code that
            // re-parses messages.message still recovers the key fields.
            $dateStr = $date instanceof \DateTime
                ? $date->format('D, d M Y H:i:s +0000')
                : now()->format('D, d M Y H:i:s +0000');
            $groupEmail = $group->nameshort . '@' . config('freegle.mail.group_domain', 'groups.ilovefreegle.org');
            $rfc822 = $this->synthesizeRfc822($fromName, $groupEmail, $subject, $dateStr, $messageid, $postId, $lat, $lng, $content);

            $msgData = [
                'date'            => $date instanceof \DateTime ? $date->format('Y-m-d H:i:s') : now(),
                'source'          => Message::SOURCE_EMAIL,
                'sourceheader'    => Message::SOURCE_EMAIL,
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
     * Return the Freegle user for the given fd_user_id, creating a stub account if none exists.
     *
     * The normal flow is: TN calls the partner membership-add endpoint → Freegle creates the
     * user and returns the fd_user_id → TN uses that id in subsequent post API responses.
     * When that flow was missed (fresh environment, failed webhook, etc.) the user won't exist
     * locally. Rather than silently dropping the post, we create a minimal stub so ingestion
     * can proceed. UserChangesSyncer will fill in the real name/email on the next sync cycle.
     *
     * In dry-run mode no DB writes are made, so we return null and the caller skips the post.
     */
    private function findOrCreateUser(int $fdUserId, Group $group): ?User
    {
        $user = User::find($fdUserId);
        if ($user !== null) {
            return $user;
        }

        // Synthesize a unique TN-style email. Using "tn{id}@user.trashnothing.com" (no hyphen)
        // avoids the canonicalization regex that strips the last hyphen-segment on that domain.
        $syntheticEmail = "tn{$fdUserId}@user.trashnothing.com";

        Log::info('TN-SYNC-TRACE [WRITE] table=users op=insert set=id=' . $fdUserId . ',fullname=TN User,added=now()');
        Log::info('TN-SYNC-TRACE [WRITE] table=users_emails op=insert set=userid=' . $fdUserId . ',email=' . $syntheticEmail);
        Log::info('TN-SYNC-TRACE [WRITE] table=memberships op=insert set=userid=' . $fdUserId . ',groupid=' . $group->id . ',collection=Approved');

        if ($this->dryRun) {
            $this->loki->logEvent('tn-sync', 'user-stub-create', ['fd_user_id' => $fdUserId, 'group_id' => $group->id, 'dry_run' => true]);
            return null;
        }

        // Use the query builder so we can supply the explicit id that TN knows about.
        // Eloquent::create() respects $guarded = ['id'] and would assign a new auto-increment id
        // instead, breaking future User::find($fdUserId) lookups.
        DB::table('users')->insert([
            'id'         => $fdUserId,
            'fullname'   => 'TN User',
            'systemrole' => 'User',
            'added'      => now(),
            'lastaccess' => now(),
        ]);

        UserEmail::create([
            'userid'    => $fdUserId,
            'email'     => $syntheticEmail,
            'preferred' => 1,
            'added'     => now(),
            'canon'     => $syntheticEmail,
        ]);

        // Create an Approved membership so the membership check in ingest() passes.
        // TN only delivers posts for group members, so this mirrors the state TN holds.
        if (!Membership::where('userid', $fdUserId)->where('groupid', $group->id)->exists()) {
            Membership::create([
                'userid'           => $fdUserId,
                'groupid'          => $group->id,
                'role'             => Membership::ROLE_MEMBER,
                'collection'       => Membership::COLLECTION_APPROVED,
                'emailfrequency'   => Membership::EMAIL_FREQUENCY_IMMEDIATE,
                'ourPostingStatus' => 'DEFAULT',
                'added'            => now(),
            ]);
        }

        $this->loki->logEvent('tn-sync', 'user-stub-create', ['fd_user_id' => $fdUserId, 'group_id' => $group->id]);

        return User::find($fdUserId);
    }

    private function postAlreadyExists(string $tnPostId, int $groupId): bool
    {
        return DB::table('messages')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->where('messages.tnpostid', $tnPostId)
            ->where('messages_groups.groupid', $groupId)
            ->exists();
    }

    /**
     * Finds an existing live message ANYWHERE — not scoped to any particular
     * group — with a normalized-matching subject and coordinates within
     * REPOST_MATCH_RADIUS_METERS, to detect a TN repost or crosspost.
     * "Live" means still Approved/Pending, not deleted, and with no outcome
     * recorded (an already-taken/withdrawn item isn't a repost target — a
     * new post with the same subject/location after that is a fresh item,
     * not a repost).
     *
     * Deliberately group-agnostic: TN gives every crosspost (a poster
     * cross-posting the same real item to multiple TN groups) its own
     * post_id too, exactly like a repost, and resolves each one via
     * Location::groupsNear() independently — which can legitimately land on
     * a different Freegle group per post_id even though it's the same
     * donation. Freegle already has its own cross-posting/rippling
     * mechanism, so a TN crosspost must never create a second independent
     * FD message; searching across all groups (rather than just the
     * newly-resolved $group) is what makes that hold regardless of which
     * group either post_id happens to resolve to.
     *
     * Deliberately does NOT filter by fromuser. TN's own numeric user id is
     * scoped per group-affiliation, not stable per real person — confirmed
     * live: two TN post_ids for the same "Electric sander" item, same
     * subject/coordinates/group, resolved to two different Freegle stub
     * users (99010031 vs 5595742) because TN supplied two different
     * fd_user_ids for what all other evidence points to being the same
     * poster. Matching on subject + tight coordinate radius is accepted as
     * sufficient — the risk of two different real people independently
     * posting identical subject text at the same ~50m spot is negligible.
     *
     * @return object{msgid: int, groupid: int, date: ?\Illuminate\Support\Carbon, fromuser: int}|null
     */
    private function findRepostCandidate(string $subject, mixed $lat, mixed $lng): ?object
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        $normalizedSubject = $this->normalizeSubjectForRepostMatch($subject);

        // Now that the search isn't scoped to a single group (see docblock),
        // an ORDER BY arrival / LIMIT alone would just return the most
        // recently active messages system-wide — almost never the real
        // candidate. A coordinate bounding box around REPOST_MATCH_RADIUS_METERS
        // keeps the query narrow and correct; haversineMeters() below still
        // does the precise circular check within that box.
        $latDelta = self::REPOST_MATCH_RADIUS_METERS / 111320;
        $lngDelta = self::REPOST_MATCH_RADIUS_METERS / (111320 * max(cos(deg2rad((float) $lat)), 0.01));

        $candidates = DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->leftJoin('messages_outcomes as mo', 'mo.msgid', '=', 'mg.msgid')
            ->where('mg.deleted', 0)
            ->whereIn('mg.collection', [MessageGroup::COLLECTION_APPROVED, MessageGroup::COLLECTION_PENDING])
            ->whereNull('mo.id')
            ->whereBetween('m.lat', [(float) $lat - $latDelta, (float) $lat + $latDelta])
            ->whereBetween('m.lng', [(float) $lng - $lngDelta, (float) $lng + $lngDelta])
            ->orderByDesc('mg.arrival')
            ->limit(50)
            ->select(['mg.msgid', 'mg.groupid', 'm.date', 'm.subject', 'm.lat', 'm.lng', 'm.fromuser'])
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->normalizeSubjectForRepostMatch((string) $candidate->subject) !== $normalizedSubject) {
                continue;
            }
            if ($this->haversineMeters((float) $lat, (float) $lng, (float) $candidate->lat, (float) $candidate->lng) <= self::REPOST_MATCH_RADIUS_METERS) {
                return (object) [
                    'msgid'    => (int) $candidate->msgid,
                    'groupid'  => (int) $candidate->groupid,
                    'date'     => $candidate->date ? \Illuminate\Support\Carbon::parse($candidate->date) : null,
                    'fromuser' => (int) $candidate->fromuser,
                ];
            }
        }

        return null;
    }

    private function normalizeSubjectForRepostMatch(string $subject): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $subject)));
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusMeters = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusMeters * $c;
    }

    private function normalizePostDate(mixed $date): ?\Illuminate\Support\Carbon
    {
        if ($date instanceof \DateTime) {
            return \Illuminate\Support\Carbon::instance($date);
        }
        if (is_string($date) && $date !== '') {
            try {
                return \Illuminate\Support\Carbon::parse($date);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Bumps an existing message on $groupId to represent a TN repost —
     * updates its `arrival`/`autoreposts`, advances its `date` to $postDate
     * (used by the idempotency check in ingest(), see there), logs it, and
     * records a messages_postings row — mirroring AutoRepostService::
     * repost()'s DB pattern (Freegle's own inactivity-triggered auto-repost),
     * but with subtype='Repost' (not 'Autoreposted') and repost=1/
     * autorepost=0, since this is triggered by the poster reposting on TN,
     * not Freegle's own inactivity timer.
     *
     * Also re-points `tnpostid` at $newTnPostId. TN's inbound
     * `PATCH /message/tn/:tnpostid` API (iznik-server-go) looks messages up
     * by tnpostid, and TN always sends edits using the post_id of the most
     * recent repost/crosspost it delivered — not the original one. Leaving
     * the original tnpostid in place would make that lookup silently miss
     * once a message has been reposted.
     */
    private function bumpAsRepost(int $msgId, int $groupId, int $fromUser, ?\Illuminate\Support\Carbon $postDate, string $newTnPostId): void
    {
        DB::table('messages_groups')
            ->where('msgid', $msgId)
            ->where('groupid', $groupId)
            ->update([
                'arrival'     => now(),
                'autoreposts' => DB::raw('autoreposts + 1'),
            ]);

        // Advances the message's own `date` to this repost's TN content date, so
        // the idempotency check in ingest() (comparing a future repost's date
        // against this field) correctly recognizes this specific repost as
        // applied — see the comment at the call site for why `arrival` can't be
        // used for that comparison.
        $updates = ['tnpostid' => $newTnPostId];
        if ($postDate !== null) {
            $updates['date'] = $postDate;
        }
        DB::table('messages')->where('id', $msgId)->update($updates);

        DB::table('logs')->insert([
            'timestamp' => now(),
            'type'      => 'Message',
            'subtype'   => 'Repost',
            'msgid'     => $msgId,
            'groupid'   => $groupId,
            'user'      => $fromUser,
            'text'      => 'TN repost',
        ]);

        DB::table('messages_postings')->insert([
            'msgid'      => $msgId,
            'groupid'    => $groupId,
            'repost'     => 1,
            'autorepost' => 0,
            'date'       => now(),
        ]);
    }

    /**
     * Duplicate of IncomingMailService::containsWorryWords (subject + body).
     * Kept separate per plan: no extraction until email path parity is proven.
     */
    private function subjectContainsWorryWords(string $subject, string $body): bool
    {
        if (str_contains($subject, '£') || str_contains($body, '£')) {
            return true;
        }

        $worryWords = DB::table('worrywords')->get();

        foreach ($worryWords as $ww) {
            if ($ww->type === 'Allowed') {
                $pattern  = '/\b' . preg_quote($ww->keyword, '/') . '\b/i';
                $subject  = preg_replace($pattern, '', $subject) ?? $subject;
                $body     = preg_replace($pattern, '', $body) ?? $body;
            }
        }

        foreach ($worryWords as $ww) {
            if ($ww->type !== 'Allowed' && str_contains($ww->keyword, ' ')) {
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
                if ($ww->type !== 'Allowed' && !empty($ww->keyword)) {
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
        if ($this->dryRun) {
            $count = count($photos);
            Log::info('TN-SYNC-TRACE [WRITE] table=message_attachments op=insert set=msgid=' . $messageId . ' count=' . $count . ' (dry-run, not fetching)');
            return $count;
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
     * Prefers the highest-resolution image in the images array, falls back to photo url.
     */
    private function bestPhotoUrl(mixed $photo): ?string
    {
        if (is_array($photo)) {
            $images = $photo['images'] ?? [];
            if (!empty($images)) {
                return $images[0]['url'] ?? null;
            }
            return $photo['url'] ?? null;
        }

        // OpenAPI Photo object
        $images = $photo->getImages() ?? [];
        if (!empty($images)) {
            return $images[0]->getUrl();
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
    private function getField(mixed $post, string $arrayKey, string $method): mixed
    {
        if (is_array($post)) {
            return $post[$arrayKey] ?? null;
        }
        return method_exists($post, $method) ? $post->$method() : null;
    }
}
