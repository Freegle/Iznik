<?php

namespace App\Mail\Digest;

use App\Mail\Contracts\RetryableMailable;
use App\Mail\MjmlMailable;
use App\Mail\Traits\AmpEmail;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\AvatarResolver;
use App\Mail\Traits\TrackableEmail;
use App\Models\Message;
use App\Models\User;
use App\Services\UnifiedDigestService;
use App\Support\EmojiUtils;
use Carbon\Carbon;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Unified Freegle digest email.
 *
 * Contains posts from all communities the user is a member of,
 * with cross-posted items deduplicated.
 */
class UnifiedDigest extends MjmlMailable implements RetryableMailable
{
    use AmpEmail;
    use AvatarResolver;
    use LoggableEmail;
    use TrackableEmail;

    public string $userSite;

    public string $deliveryUrl;

    protected Collection $preparedPosts;

    /** @var Collection lightweight "came and went" (Taken/Received) entries for the greyed daily section */
    protected Collection $preparedCompletedPosts;

    /** @var array<int,object> primary group rows (id => {nameshort, namefull}) for post bylines + header list */
    protected array $groupLookup = [];

    protected int $digestNumber;

    public function __construct(
        public User $user,
        protected Collection $posts,
        public string $mode,
        protected Collection $sponsors = new Collection(),
        protected Collection $completedPosts = new Collection()
    ) {
        parent::__construct();

        $this->userSite = config('freegle.sites.user');
        $this->deliveryUrl = config('freegle.delivery.base_url');

        // Look up digest number for this user.
        $this->digestNumber = $this->getDigestNumber();

        // Initialize email tracking BEFORE preparePosts so trackedUrl() works.
        $userId = $this->user->exists ? $this->user->id : null;

        // Group dimension: an immediate digest is about a single community,
        // so record which one; a daily digest spans the member's groups and
        // isn't tied to one, so leave it null.
        $trackingGroupId = null;
        if ($this->mode === UnifiedDigestService::MODE_IMMEDIATE && $this->posts->isNotEmpty()) {
            $trackingGroupId = $this->preferredGroupForPost($this->posts->first());
        }

        $this->initTracking(
            $this->getEmailType(),
            $this->user->email_preferred,
            $userId,
            $trackingGroupId,
            $this->getSubject(),
            [
                'mode' => $this->mode,
                'post_count' => $this->posts->count(),
                // Ordered msgids of every post shown, position = array index.
                // Matches the per-post link/image position labels ("post_{i}"
                // / "image_{i}") so an OPEN with no click is still attributable
                // across the posts shown (position-weighted), image-load
                // positions map back to a msgid, and a post's "shown at
                // position P in N digests" denominator is recoverable. Without
                // this only the rare clicked post can be attributed.
                'post_msgids' => $this->posts->map(fn ($p) => $p['message']->id)->values()->all(),
                'digest_number' => $this->digestNumber,
                'has_amp' => $this->isAmpEnabled(),
            ]
        );

        // Prepare posts with tracking URLs and decoded text.
        $this->preparedPosts = $this->preparePosts();

        // "Came and went" list (daily only) — lightweight, no reply CTA.
        $this->preparedCompletedPosts = $this->prepareCompletedPosts();
    }

    /**
     * Prepare the "came and went" (Taken/Received) section.
     *
     * Reuses the SAME card field set as the live posts ({@see prepareCard()})
     * so the greyed cards render from the identical card markup — just without
     * the Reply button and with a muted palette (template-side $showReply /
     * $muted flags). The only data difference is the meta line, which reads
     * "Taken/Received · <date>" via the `metaText` key rather than the live
     * distance · arrival pairing.
     *
     * Completed posts arrive as raw Message objects with ->groups loaded, so we
     * derive postedToGroups from those (matching the live path's array) and
     * fold any groups not already in $this->groupLookup into it.
     */
    protected function prepareCompletedPosts(): Collection
    {
        if ($this->completedPosts->isEmpty()) {
            return collect();
        }

        // Derive each completed message's group ids from its loaded ->groups
        // (falling back to the single join groupid), then make sure the byline
        // lookup carries any group not already loaded by preparePosts().
        $completedGroups = $this->completedPosts->map(function ($message) {
            $ids = $message->relationLoaded('groups')
                ? $message->groups->pluck('id')->filter()->values()->all()
                : [];
            if (empty($ids) && !empty($message->groupid)) {
                $ids = [(int) $message->groupid];
            }
            return $ids;
        });

        $missingGroupIds = $completedGroups->flatten()
            ->filter(fn ($gid) => !isset($this->groupLookup[$gid]))
            ->unique()
            ->values();
        if ($missingGroupIds->isNotEmpty()) {
            $extra = DB::table('groups')->whereIn('id', $missingGroupIds)
                ->get(['id', 'nameshort', 'namefull'])->keyBy('id')->all();
            $this->groupLookup = $this->groupLookup + $extra;
        }

        $total = $this->completedPosts->count();

        // No distance shown for came-and-went (the item is gone), so pass null
        // lat/lng — prepareCard() then leaves distanceText null. metaText below
        // carries the "Taken/Received · <date>" line the muted card renders.
        return $this->completedPosts->values()->map(function ($message, $index) use ($completedGroups, $total) {
            $card = $this->prepareCard(
                $message,
                $completedGroups[$index] ?? [],
                $index,
                $total,
                null,
                null
            );

            // Offer→Taken, Wanted→Received. Same "D j M, g:ia" arrival format
            // the live cards use, so the muted card's meta line matches shape.
            $verb = $message->type === 'Offer' ? 'Taken' : 'Received';
            $card['metaText'] = $verb . ' · ' . $card['arrivalFormatted'];

            // The item is gone, so the card links to the (closed) post page for
            // a look rather than the reply-compose flow — drop ?reply=1.
            $viewUrl = $this->trackedUrl(
                $this->userSite . '/message/' . $message->id,
                'completed_' . $message->id,
                'view_completed'
            );
            $card['messageUrl'] = $viewUrl;
            $card['fallbackReplyUrl'] = $viewUrl;

            return $card;
        });
    }

    /**
     * Get the recipient's user ID for common header tracking.
     */
    protected function getRecipientUserId(): ?int
    {
        return $this->user->id ?? null;
    }

    /**
     * IDs needed to rebuild this digest for a durable retry. We store the
     * recipient, the mode, the (message id, group ids) of each live post, and
     * the message ids of the daily "came and went" (Taken/Received) section —
     * never the built Message/User objects — so the retry re-fetches current
     * data. Sponsors are recomputed from the user on rebuild, so they're not
     * stored.
     *
     * {@see RetryableMailable}
     */
    public function mailDescriptor(): array
    {
        return [
            'userid' => $this->user->id,
            'mode' => $this->mode,
            'posts' => $this->posts->map(fn ($post) => [
                'msgid' => $post['message']->id,
                'groups' => array_values($post['postedToGroups'] ?? []),
            ])->all(),
            // "Came and went" (Taken/Received) message ids for the daily greyed
            // section. Ids only, so the retry re-fetches current data; empty for
            // immediate digests, which have no completed section. Without this a
            // rebuilt daily digest silently dropped the whole section.
            'completed' => $this->completedPosts->map(fn ($message) => $message->id)->values()->all(),
        ];
    }

    /**
     * Rebuild a fresh digest from a descriptor, re-fetching from the DB.
     *
     * Re-fetches the live posts, the recipient's sponsors, and the daily "came
     * and went" (Taken/Received) section so the rebuilt email matches the live
     * send path rather than silently dropping the completed section.
     *
     * Returns null (cancel the retry — nothing to send) when the recipient or
     * every referenced live post has since been deleted, or the recipient no
     * longer has a usable address.
     *
     * {@see RetryableMailable}
     */
    public static function rebuildFromDescriptor(array $descriptor): ?self
    {
        $user = User::with(['emails', 'memberships'])->find($descriptor['userid'] ?? null);
        if (!$user || !$user->email_preferred) {
            return null;
        }

        $posts = collect($descriptor['posts'] ?? [])
            ->map(function ($post) {
                $message = Message::with(['attachments', 'fromUser', 'groups'])->find($post['msgid'] ?? null);

                return $message
                    ? ['message' => $message, 'postedToGroups' => $post['groups'] ?? []]
                    : null;
            })
            ->filter()
            ->values();

        if ($posts->isEmpty()) {
            return null;
        }

        // Re-derive sponsors so a durable retry still ships sponsor credit
        // (constructing with an empty Collection dropped it on every retry).
        // Match the live send-path scope: immediate = the post's single group,
        // daily = the union across the recipient's groups.
        $mode = $descriptor['mode'] ?? UnifiedDigestService::MODE_IMMEDIATE;
        $service = app(UnifiedDigestService::class);
        if ($mode === UnifiedDigestService::MODE_IMMEDIATE) {
            $postGroups = $descriptor['posts'][0]['groups'] ?? [];
            $userGroupIds = $user->memberships->pluck('groupid')->all();
            $groupId = self::selectPreferredGroup($postGroups, $userGroupIds) ?? 0;
            $sponsors = $service->getSponsorsForGroup($groupId);
        } else {
            $sponsors = $service->getSponsorsForUser($user);
        }

        // Re-fetch the daily "came and went" (Taken/Received) messages so the
        // rebuilt daily digest still renders that greyed section. Ids that have
        // since been deleted drop out; immediate digests carry none.
        $completed = collect($descriptor['completed'] ?? [])
            ->map(fn ($msgid) => Message::with(['attachments'])->find($msgid))
            ->filter()
            ->values();

        return new self(
            $user,
            $posts,
            $mode,
            $sponsors,
            $completed
        );
    }

    /**
     * Read-only accessor for the deduplicated posts this digest carries.
     * Exposed for assertions in tests (the backing property stays protected
     * so callers can't mutate the post set after construction).
     */
    public function getPosts(): Collection
    {
        return $this->posts;
    }

    /**
     * Select a preferred group from a list, given the user's memberships.
     *
     * Prefer a group the user is a member of; fall back to the first group.
     * Static + public so it can be used in both instance and static contexts,
     * including from UnifiedDigestService when scoping a per-post group.
     */
    public static function selectPreferredGroup(array $groups, array $userGroupIds): ?int
    {
        if (empty($groups)) {
            return null;
        }
        if (count($groups) === 1) {
            return $groups[0];
        }

        foreach ($groups as $groupId) {
            if (in_array($groupId, $userGroupIds, true)) {
                return $groupId;
            }
        }

        return $groups[0];
    }

    /**
     * Choose the single group to feature for a post in this recipient's digest.
     *
     * A post can be cross-posted to several groups, but the subject prefix and
     * footer can only name one. Prefer a group the recipient is actually a
     * member of; fall back to the first posted-to group. Today the immediate
     * path supplies a single-group postedToGroups so this is unambiguous, but
     * this keeps the choice correct if digests become cross-group.
     */
    protected function preferredGroupForPost(array $post): ?int
    {
        $groups = $post['postedToGroups'] ?? [];
        $myGroupIds = $this->user->memberships->pluck('groupid')->all();
        return self::selectPreferredGroup($groups, $myGroupIds);
    }

    /**
     * Split UnifiedDigest into immediate vs daily for sysadmin dropdowns
     * and tracking stats. Legacy rows written before this split keep the
     * plain 'UnifiedDigest' value; getDigestNumber() includes both.
     */
    public function getEmailType(): string
    {
        return $this->mode === UnifiedDigestService::MODE_IMMEDIATE
            ? 'UnifiedDigestImmediate'
            : 'UnifiedDigestDaily';
    }

    /**
     * Whether this digest is about a single post — either an immediate (-1)
     * notification. A single-post digest uses the full-width hero layout
     * and a personalised "<poster> on Freegle" From line with a per-message
     * Reply-To, matching V1's SingleDigest. The multi-post daily digest uses
     * the generic Freegle sender and the compact multi-post layout.
     */
    protected function isSinglePost(): bool
    {
        return $this->mode === UnifiedDigestService::MODE_IMMEDIATE;
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        // Jobs section — V1 single.html parity. Same shape ChatNotification
        // already uses, so the MJML loop is a drop-in.
        $jobAdsData = $this->user->getJobAds();
        $jobAds = $jobAdsData['jobs'] ?? collect();
        $jobCount = count($jobAds);
        foreach ($jobAds as $index => $job) {
            $job->tracked_url = $this->trackedUrl(
                $this->userSite . '/job/' . $job->id .
                    '?source=email&campaign=unified_digest&position=' . $index .
                    '&list_length=' . $jobCount,
                'job_ad_' . $index,
                'job_click'
            );

            // Intelligent truncation, shared by the MJML + AMP templates so the
            // narrow 2-column rows clamp to ~2 lines. The job title can be long
            // and already carry its own location ("Pricing Manager - Halifax;
            // Home Based; Reading"); appending $job->location in brackets on top
            // can run to 3+ lines. Budget the combined "title (location)" to
            // ~JOB_TEXT_MAX chars, prioritising the title: a title that alone
            // exceeds the budget is truncated and the location dropped;
            // otherwise the location fills whatever budget remains.
            $jobMax = 48;
            $title = trim((string) $job->title);
            if (mb_strlen($title) > $jobMax) {
                $job->display_title = \Illuminate\Support\Str::limit($title, $jobMax, '…');
                $job->display_location = null;
            } else {
                $job->display_title = $title;
                $loc = trim((string) ($job->location ?? ''));
                $remaining = $jobMax - mb_strlen($title) - 3; // room for " ()"
                $job->display_location = ($loc !== '' && $remaining >= 6)
                    ? \Illuminate\Support\Str::limit($loc, $remaining, '…')
                    : null;
            }
        }
        $jobsUrl = $this->trackedUrl($this->userSite . '/jobs', 'jobs_view_more', 'jobs_view_more');
        $donateUrl = $this->trackedUrl(config('freegle.donate.url', 'https://freegle.in/paypal1510'), 'donate', 'donate');

        // Per-group footer heading: "you're a member of {group}, set to
        // receive {frequency}". An immediate digest is about a single
        // community, so name it. Daily digests span all the user's groups
        // and don't single one out, so we leave it null.
        $primaryGroupName = null;
        if ($this->mode === UnifiedDigestService::MODE_IMMEDIATE && $this->posts->isNotEmpty()) {
            $firstPost = $this->posts->first();
            $groupId = $this->preferredGroupForPost($firstPost);
            if ($groupId) {
                $row = $this->groupRow($groupId);
                $primaryGroupName = $row ? ($row->namefull ?: $row->nameshort) : null;
            }
        }
        // Footer cadence wording. Immediate is "immediately"; daily is "daily".
        $frequencyText = $this->mode === UnifiedDigestService::MODE_IMMEDIATE
            ? 'immediately'
            : 'daily';

        // Header group list: the distinct groups represented by THIS digest's
        // posts (not the user's whole membership), each linking to /explore.
        // Used by the daily multi-group header.
        $digestGroups = $this->preparedPosts
            ->filter(fn ($p) => !empty($p['groupName']))
            ->map(fn ($p) => ['name' => $p['groupName'], 'url' => $p['groupUrl']])
            ->unique('name')
            ->values();

        // Immediate-mode template uses $post (singular), $isOffer, and $accentColor
        // directly — pass them here so the template doesn't see undefined variables.
        $immediatePost = $this->mode === UnifiedDigestService::MODE_IMMEDIATE
            ? $this->preparedPosts->first()
            : null;
        $immediateIsOffer = $immediatePost ? ($immediatePost['type'] === 'Offer') : false;
        $immediateAccentColor = $immediateIsOffer ? '#3c763d' : '#4895DD';

        $result = $this->mjmlView('emails.mjml.digest.unified', array_merge([
            'user' => $this->user,
            'posts' => $this->preparedPosts,
            'completedPosts' => $this->preparedCompletedPosts,
            'post' => $immediatePost,
            'isOffer' => $immediateIsOffer,
            'accentColor' => $immediateAccentColor,
            'postCount' => $this->posts->count(),
            'mode' => $this->mode,
            'sponsors' => $this->sponsors,
            'settingsUrl' => $this->trackedUrl($this->userSite . '/settings', 'footer_settings', 'settings'),
            'unsubscribeUrl' => $this->trackedUrl($this->userSite . '/unsubscribe', 'footer_unsubscribe', 'unsubscribe'),
            'browseUrl' => $this->trackedUrl($this->userSite . '/browse', 'browse_cta', 'browse'),
            'userSite' => $this->userSite,
            'jobAds' => $jobAds,
            'jobsUrl' => $jobsUrl,
            'donateUrl' => $donateUrl,
            'primaryGroupName' => $primaryGroupName,
            'frequencyText' => $frequencyText,
            'digestGroups' => $digestGroups,
        ], $this->getTrackingData()), 'emails.text.digest.unified')
            ->to($this->user->email_preferred)
            ->applyLogging('UnifiedDigest');

        // Attach the X-Freegle-* tracking headers and RFC 8058
        // List-Unsubscribe headers that MjmlMailable provides.
        $this->configureMessage();

        // V1-parity headers added on every digest (immediate + daily).
        // - X-Freegle-Mail-Type: legacy V1 header name TN consumes (sits next
        //   to the V2-style X-Freegle-Email-Type that MjmlMailable already adds).
        // - Feedback-ID: Google FBL spec — {qualifier}:{userid}:{type}:{sender}.
        //   For a single-post digest (immediate, or a group digest with one
        //   post) the qualifier is the message id; for multi-post digests we
        //   use 0 since the email isn't tied to one specific message.
        // - Reply-To (single-post only): even though From is the replyto-
        //   address and most clients reply to From, some (and some webmails)
        //   prefer Reply-To when set, so we set both.
        $messageQualifier = 0;
        $replyToAddr = null;
        $replyToName = null;
        if ($this->isSinglePost() && $this->posts->isNotEmpty()) {
            $firstPost = $this->posts->first();
            $message = $firstPost['message'];
            $messageQualifier = $message->id;
            $domain = config('freegle.mail.user_domain');
            $replyToAddr = "replyto-{$message->id}-{$this->user->id}@{$domain}";
            // Match the From display-name resolution in envelope(): prefer
            // the User's displayname over the legacy messages.fromname.
            $posterUser = $message->relationLoaded('fromUser') ? $message->fromUser : null;
            $replyToName = $posterUser?->displayname ?: ($message->fromname ?: 'Freegler');
        }
        $userId = $this->user->id ?? 0;
        $result->withSymfonyMessage(function ($symfonyMessage) use (
            $messageQualifier, $userId, $replyToAddr, $replyToName
        ) {
            $headers = $symfonyMessage->getHeaders();
            if (!$headers->has('Feedback-ID')) {
                $headers->addTextHeader('Feedback-ID', "{$messageQualifier}:{$userId}:Digest:freegle");
            }
            if (!$headers->has('X-Freegle-Mail-Type')) {
                $headers->addTextHeader('X-Freegle-Mail-Type', 'Digest');
            }
            if ($replyToAddr) {
                $symfonyMessage->replyTo(new \Symfony\Component\Mime\Address($replyToAddr, $replyToName));
            }
        });

        // Render AMP variant if enabled.
        if ($this->isAmpEnabled()) {
            $ampPosts = $this->prepareAmpPosts();

            // AMP-ONLY post cap. AMP for Email hard-limits the AMP document to
            // 200,000 bytes; a large daily digest's cards push it over and Gmail
            // rejects the WHOLE AMP. Cap the cards here (the summary and the
            // <amp-state> map are derived from $ampPosts too, so capping once
            // shrinks all three) and surface an "and N more — browse all" link.
            // The HTML and text parts still carry EVERY post; this cap is AMP
            // only. applyAmpToMessage()'s 199KB guard remains the final backstop.
            $ampCap = 65;
            $ampMorePosts = max(0, $ampPosts->count() - $ampCap);
            if ($ampMorePosts > 0) {
                $ampPosts = $ampPosts->take($ampCap)->values();
            }

            // Build the shared per-post metadata map for the AMP template.
            // Storing {title, token, expiry} once per message in an
            // <amp-state> at the top of the body — instead of inlining the
            // full token + title in each post's tap-state — drops the
            // per-post button payload from ~400 bytes to ~110 bytes, which
            // is what brings a 200-post AMP digest in under the 200 KB cap.
            $ampPostMeta = $ampPosts->mapWithKeys(fn ($p) => [
                (int) $p['message']->id => [
                    't' => (string) $p['itemName'],
                    'k' => (string) ($p['ampReplyToken'] ?? ''),
                    'e' => (int) ($p['ampReplyExp'] ?? 0),
                ],
            ])->toArray();
            $ampApiUrl = rtrim(config('freegle.amp.api_url', 'https://api.ilovefreegle.org/amp'), '/');

            $this->renderAmpTemplate('emails.amp.digest.unified', [
                'user' => $this->user,
                'posts' => $ampPosts,
                'ampMorePosts' => $ampMorePosts,
                'completedPosts' => $this->preparedCompletedPosts,
                'postCount' => $this->posts->count(),
                'settingsUrl' => $this->trackedUrl($this->userSite . '/settings', 'amp_settings', 'settings'),
                'unsubscribeUrl' => $this->trackedUrl($this->userSite . '/unsubscribe', 'amp_unsubscribe', 'unsubscribe'),
                'browseUrl' => $this->trackedUrl($this->userSite . '/browse', 'amp_browse', 'browse'),
                'userSite' => $this->userSite,
                'ampPostMeta' => $ampPostMeta,
                'ampApiUrl' => $ampApiUrl,
                'ampUserId' => (int) $this->user->id,
                // Jobs + sponsors reach the AMP body too (V1 parity — the MJML
                // and text parts already carry these; AMP previously dropped
                // them). Same data the MJML view above receives.
                'sponsors' => $this->sponsors,
                'jobAds' => $jobAds,
                'jobsUrl' => $jobsUrl,
                'donateUrl' => $donateUrl,
                'digestGroups' => $digestGroups,
            ]);

            $result->withSymfonyMessage(function ($message) {
                $this->applyAmpToMessage($message);
            });
        }

        return $result;
    }

    /**
     * Get the subject line.
     *
     * Format: "5 new posts near you - Sofa, Coffee table, Books..."
     */
    public function envelope(): Envelope
    {
        if ($this->isSinglePost() && $this->posts->isNotEmpty()) {
            $post = $this->posts->first();
            $message = $post['message'];
            // Resolve poster name from the user relation first (displayname is
            // what shows everywhere else in the email body); fall back to the
            // legacy fromname column and finally 'Freegler'. Avoids the
            // mismatch where the inbox shows "Freegler via Freegle" because
            // messages.fromname is empty, while the body correctly shows
            // "Ewalina via Freegle" from the User model.
            $posterUser = $message->relationLoaded('fromUser') ? $message->fromUser : null;
            $posterName = $posterUser?->displayname ?: ($message->fromname ?: 'Freegler');
            // Partner (Trash Nothing) posters carry a name that already ends in
            // " via Trash Nothing" (the raw From-header display name). Strip it
            // so we don't surface the partner branding in the digest From line.
            $posterName = preg_replace('/\s+via\s+Trash\s*Nothing\s*$/i', '', $posterName);
            // V1 parity (iznik-server/include/mail/Digest.php): the immediate
            // digest From name is "<poster> on <SITE_NAME>".
            $posterDisplayName = $posterName . ' on ' . config('freegle.branding.name');

            // From MUST stay as the Gmail-registered noreply sender for AMP
            // for Email to render — Gmail's Dynamic Mail allowlist keys on
            // the From: domain. Putting the per-message replyto address in
            // From caused Gmail to fall back to the HTML alternative and
            // surface the AMP part as an attachment. Reply-To is set via the
            // withSymfonyMessage callback in content() so replies still hit
            // the per-message inbound address.
            return new Envelope(
                from: new Address(
                    config('freegle.mail.noreply_addr'),
                    $posterDisplayName
                ),
                subject: $this->getSubject(),
            );
        }

        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                config('freegle.branding.name')
            ),
            subject: $this->getSubject(),
        );
    }

    /**
     * Resolve a group's {nameshort, namefull} row, reusing the batch groupLookup
     * loaded by preparePosts() so we don't issue a redundant single-row SELECT
     * per immediate-digest email. Falls back to a query only if the id wasn't
     * among the digest's post groups.
     */
    protected function groupRow(?int $groupId): ?object
    {
        if (!$groupId) {
            return null;
        }
        if (isset($this->groupLookup[$groupId])) {
            return $this->groupLookup[$groupId];
        }

        return DB::table('groups')->where('id', $groupId)->first(['nameshort', 'namefull']);
    }

    protected function getSubject(): string
    {
        if ($this->mode === UnifiedDigestService::MODE_IMMEDIATE && $this->posts->isNotEmpty()) {
            $firstPost = $this->posts->first();
            $groupId = $this->preferredGroupForPost($firstPost);
            $groupRow = $this->groupRow($groupId);
            $groupName = $groupRow ? ($groupRow->namefull ?: $groupRow->nameshort) : null;
            // Decode HTML entities: the DB stores subjects HTML-encoded (e.g.
            // "Coffee &amp; Cake"); the email subject line is plain text and
            // must contain a literal "&" rather than the encoded form.
            $postSubject = html_entity_decode(
                $firstPost['message']->subject ?? '',
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            return $groupName ? "[{$groupName}] {$postSubject}" : $postSubject;
        }

        // Daily roll-up ("What's New (N posts)") — V1 digest parity, which led
        // with the title and a parenthetical post count. Keep the item-name
        // teaser after it since it lifts open rates and mirrors the immediate
        // subject's "[Group] Item" shape.
        $count = $this->posts->count();
        $itemNames = $this->getItemNamesForSubject();

        $subject = "What's New ({$count} post" . ($count === 1 ? '' : 's') . ')';

        if ($itemNames) {
            $subject .= " - {$itemNames}";
        }

        return $subject;
    }

    /**
     * Get item names for the subject line teaser.
     *
     * @return string Comma-separated item names, max 50 chars
     */
    protected function getItemNamesForSubject(): string
    {
        $names = [];
        $totalLength = 0;
        $maxLength = 50;

        foreach ($this->posts as $post) {
            $message = $post['message'];
            // Decode HTML entities before extracting the item name so the email
            // subject teaser contains a literal "&" rather than "&amp;".
            $decodedSubject = html_entity_decode($message->subject ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $itemName = $this->extractItemName($decodedSubject);

            // Truncate individual item names to 25 chars.
            if (strlen($itemName) > 25) {
                $itemName = substr($itemName, 0, 22) . '...';
            }

            $newLength = $totalLength + strlen($itemName) + ($totalLength > 0 ? 2 : 0);

            if ($newLength > $maxLength) {
                break;
            }

            $names[] = $itemName;
            $totalLength = $newLength;
        }

        return implode(', ', $names);
    }

    /**
     * Extract item name from subject line.
     * Removes OFFER/WANTED prefix and location suffix.
     */
    protected function extractItemName(string $subject): string
    {
        // Remove OFFER/WANTED prefix.
        $name = preg_replace('/^(OFFER|WANTED)\s*:\s*/i', '', $subject);

        // Remove location suffix (stuff in parentheses at the end).
        $name = preg_replace('/\s*\([^)]+\)\s*$/', '', $name);

        return trim($name);
    }

    /**
     * Prepare posts with tracking URLs and processed data.
     */
    protected function preparePosts(): Collection
    {
        $totalPosts = $this->posts->count();

        // Get user's location for distance calculation.
        $userLat = null;
        $userLng = null;
        if ($this->user->lastlocation) {
            $loc = DB::table('locations')->where('id', $this->user->lastlocation)->first(['lat', 'lng']);
            if ($loc) {
                $userLat = (float) $loc->lat;
                $userLng = (float) $loc->lng;
            }
        }

        // Batch-load all groups referenced by any post so each card can show
        // group name(s) without an N+1 per post. namefull is the friendly name;
        // nameshort drives the /explore link.
        $allGroupIds = $this->posts
            ->flatMap(fn ($p) => $p['postedToGroups'])
            ->filter()
            ->unique()
            ->values();
        $this->groupLookup = $allGroupIds->isNotEmpty()
            ? DB::table('groups')->whereIn('id', $allGroupIds)
                ->get(['id', 'nameshort', 'namefull'])->keyBy('id')->all()
            : [];

        return $this->posts->map(fn ($post, $index) => $this->prepareCard(
            $post['message'],
            $post['postedToGroups'],
            $index,
            $totalPosts,
            $userLat,
            $userLng
        ));
    }

    /**
     * Build the full card field set for a single message.
     *
     * Shared by both the live post loop ({@see preparePosts()}) and the greyed
     * "came and went" list ({@see prepareCompletedPosts()}) so the two render
     * from the EXACT same card markup and can never drift. Relies on
     * $this->groupLookup already holding the rows for $postedToGroups.
     *
     * @param  array<int,int>  $postedToGroups  group ids this message went to
     * @param  int             $index           position in the digest (tracking)
     * @param  int             $total           total posts (scroll-depth tracking)
     */
    protected function prepareCard(
        Message $message,
        array $postedToGroups,
        int $index,
        int $total,
        ?float $userLat,
        ?float $userLng
    ): array {
        $isOffer = $message->type === 'Offer';

        // Primary group name (friendly full name) + /explore link for the
        // "Posted by … on <group>" byline. For a cross-post, prefer a group the
        // recipient is a member of (matching the digest header/subject group)
        // rather than an arbitrary first group.
        $groupName = null;
        $groupUrl = null;
        $primaryGroupId = self::selectPreferredGroup(
            $postedToGroups,
            $this->user->memberships->pluck('groupid')->all()
        );
        if ($primaryGroupId && isset($this->groupLookup[$primaryGroupId])) {
            $g = $this->groupLookup[$primaryGroupId];
            $groupName = $g->namefull ?: $g->nameshort;
            // Link by group id — /explore/{id} resolves the same as
            // /explore/{nameshort} (group store does isNaN() -> fetch by id),
            // so the compact form needs no nameshort in the URL and no
            // server-side lookup.
            $groupUrl = $this->trackedResourceUrl(
                'g',
                (int) $primaryGroupId,
                "g{$index}",
                $this->userSite . '/explore/' . $primaryGroupId
            );
        }

        // Primary displayable attachment — drives both "has a photo?" and the
        // compact image URL (keyed by attachment id). External-URL attachments
        // have no timg_ id we can reconstruct server-side, so they keep the
        // direct delivery URL.
        $attachment = $this->getPrimaryAttachment($message);
        $hasInternalImage = $attachment && empty($attachment->externalurl);

        // Get image URL via delivery service.
        $imageUrl = $this->getMessageImageUrl($message);

        // Use type-specific placeholder if no photo.
        $placeholderUrl = $isOffer
            ? config('freegle.images.offer_placeholder')
            : config('freegle.images.wanted_placeholder');

        // Create tracked image URL with scroll depth.
        // BUT: don't proxy the placeholder. The tracking proxy returns a
        // 302 to the underlying URL, and Gmail's own image proxy doesn't
        // always follow that redirect (Edward 2026-06-11 report — cards
        // for no-photo posts rendered as broken). Open/click tracking on
        // a placeholder is meaningless anyway (every no-photo card maps
        // to the same URL), so serve the raw URL directly for those.
        $scrollPercent = $total > 0 ? round(($index / $total) * 100) : 0;
        $displayImageUrl = $imageUrl ?? $placeholderUrl;
        $trackedImage = $imageUrl !== null
            ? $this->trackedImageUrl($displayImageUrl, "image_{$index}", $scrollPercent)
            : $displayImageUrl;

        // Hero image for immediate (single-post) mode: a larger, height-
        // capped crop (600x400) so the photo is the hero — V1's immediate
        // single.mjml used a full-width image. Cropping to a 3:2 box bounds
        // the height so portrait photos don't dominate. Falls back to the
        // type placeholder when the post has no usable photo. Our own (timg_)
        // photos use the compact /e/d/i/{ref}/t/{attachId}/{preset}/{pos} form
        // (preset 1 = 600x400 hero); external/placeholder keep the direct URL.
        $heroImageUrl = $hasInternalImage
            ? $this->trackedResourceImageUrl('t', (int) $attachment->id, 1, "h{$index}", $this->getMessageImageUrl($message, 600, 400) ?? $placeholderUrl)
            : ($this->getMessageImageUrl($message, 600, 400) ?? $placeholderUrl);

        // Thumbnail image for multi-post (daily) cards: a fixed 4:3
        // cover-crop (240x180) so every card has the same image height
        // regardless of the original photo's aspect ratio. Without this
        // the email rendered with the post's natural aspect — a portrait
        // photo made a card three times taller than a landscape one.
        // Square thumbnail (240x240) — used by the daily multi-post cards.
        // Square crop makes the photo height match a typical content
        // column height closely, so the Reply button at the bottom of
        // the card sits flush with the photo rather than dangling below.
        // Compact form for our own photos (preset 0 = 240x240 card thumb).
        $thumbImageUrl = $hasInternalImage
            ? $this->trackedResourceImageUrl('t', (int) $attachment->id, 0, "i{$index}", $this->getMessageImageUrl($message, 240, 240) ?? $placeholderUrl)
            : ($this->getMessageImageUrl($message, 240, 240) ?? $placeholderUrl);

        // Decode emoji sequences in message text.
        $messageText = $message->textbody
            ? EmojiUtils::decodeEmojis($message->textbody)
            : null;

        // Create tracked URL for this message (with position tracking).
        // ?reply=1 tells the message page to auto-open the reply compose
        // form on mount — saves the recipient an extra click since the
        // CTA is literally labelled "Reply".
        // Compact form: type 'm' -> handler reconstructs /message/{id}?reply=1.
        $messageUrl = $this->trackedResourceUrl(
            'm',
            (int) $message->id,
            "p{$index}",
            $this->userSite . '/message/' . $message->id . '?reply=1'
        );

        // Summary-index link: the top-of-digest "In this digest" list is a
        // jump-to-post index, so it points at the message's web page WITHOUT
        // ?reply=1 (we're not asking the reader to reply, just to look) and
        // carries its own tracking tag so summary clicks are distinguishable
        // from card clicks. V1's summary used an in-email #anchor that
        // rendered inconsistently across clients; a real web URL is reliable.
        // Compact form: type 's' -> handler reconstructs /message/{id}
        // (no ?reply=1 — the summary index just jumps to the post).
        $summaryUrl = $this->trackedResourceUrl(
            's',
            (int) $message->id,
            "y{$index}",
            $this->userSite . '/message/' . $message->id
        );

        // Format arrival time for display in UK local time (BST in summer, GMT in winter).
        // Always include minutes (e.g. "Sun 1 Mar, 11:00am") so the time
        // shape is consistent — V1 single.html-style "Mon, 25th May 9:00am".
        $arrival = $message->arrival instanceof Carbon ? $message->arrival : Carbon::parse($message->arrival);
        $arrivalFormatted = $arrival->setTimezone('Europe/London')->format('D j M, g:ia');
        $arrivalIso = $arrival->toIso8601String();

        // Calculate distance from user.
        $distanceText = null;
        if ($userLat !== null && $userLng !== null && $message->lat && $message->lng) {
            $miles = $this->haversineDistance($userLat, $userLng, (float) $message->lat, (float) $message->lng);
            $distanceText = $miles < 1 ? '< 1 mile' : round($miles) . ' miles';
        }

        $posterUser = $message->relationLoaded('fromUser') ? $message->fromUser : null;
        // Compact form: type 'u' preset 2 (avatar) -> handler reconstructs the
        // poster's avatar at 36px. Falls back to the direct avatar URL.
        $posterAvatarUrl = ($posterUser && $posterUser->id)
            ? $this->trackedResourceImageUrl('u', (int) $posterUser->id, 2, "a{$index}", $this->resolveAvatarUrl($posterUser, 36))
            : $this->resolveAvatarUrl($posterUser, 36);
        $posterName = $posterUser?->displayname ?? $message->fromname ?? 'Freegler';

        // firstPosted: show "First posted ..." line only when the message has
        // actually been reposted to this group — i.e. the pivot arrival is
        // materially after the original messages.date. Matches V1
        // Digest.php's `count($atts['postings']) > 1` rule and uses the same
        // "Mon, 25th May 9:00am" format the V1 template renders.
        $firstPostedFormatted = null;
        $originalDate = $message->date instanceof Carbon
            ? $message->date
            : ($message->date ? Carbon::parse($message->date) : null);
        if ($originalDate && $arrival->diffInMinutes($originalDate) > 60) {
            $firstPostedFormatted = $originalDate->setTimezone('Europe/London')->format('D, jS F g:ia');
        }

        // The DB stores message subjects HTML-encoded (e.g. "OFFER: Coffee &amp; Cake (London)").
        // Decode before extracting itemName / locationName so that Blade's
        // {{ }} auto-escaping produces correct HTML source ("&amp;") rather
        // than the double-encoded "&amp;amp;" that email clients render as
        // the literal string "&amp;" instead of the intended "&".
        $subject = html_entity_decode($message->subject ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Build postedToText: show group names for cross-posted items
        // (posted to more than one group). Single-group posts use null so
        // the template suppresses the italic "also on" line entirely.
        $groupNames = collect($postedToGroups)
            ->map(fn ($gid) => isset($this->groupLookup[$gid])
                ? ($this->groupLookup[$gid]->namefull ?: $this->groupLookup[$gid]->nameshort)
                : null)
            ->filter()
            ->values();
        $postedToText = $groupNames->count() > 1 ? $groupNames->implode(', ') : null;

        return [
            'message' => $message,
            // True when this post is the recipient's own — used by both the
            // AMP and MJML cards to suppress the Reply control (you can't
            // reply to yourself; the Go reply handler rejects self-replies).
            'isOwnPost' => (int) ($message->fromuser ?? 0) === (int) $this->user->id,
            'messageText' => $messageText,
            'messageUrl' => $messageUrl,
            'summaryUrl' => $summaryUrl,
            // Both templates' card links (image href, title, Reply fallback)
            // use these keys; the live path's messageUrl IS the reply URL.
            'fallbackReplyUrl' => $messageUrl,
            'imageUrl' => $imageUrl,
            'displayImageUrl' => $displayImageUrl,
            'heroImageUrl' => $heroImageUrl,
            'thumbImageUrl' => $thumbImageUrl,
            'trackedImageUrl' => $trackedImage,
            'isPlaceholder' => $imageUrl === null,
            'groupName' => $groupName,
            'groupUrl' => $groupUrl,
            'postedToText' => $postedToText,
            'type' => $message->type,
            'subject' => $subject,
            'itemName' => $this->extractItemName($subject),
            'locationName' => $this->extractLocationName($subject),
            'arrivalFormatted' => $arrivalFormatted,
            'arrivalIso' => $arrivalIso,
            'distanceText' => $distanceText,
            'posterName' => $posterName,
            'posterAvatarUrl' => $posterAvatarUrl,
            'firstPostedFormatted' => $firstPostedFormatted,
            // Card meta line. Live cards show distance · arrival; the came-and-
            // went cards override this to "Taken/Received · <date>" (see
            // prepareCompletedPosts()). Defaulted here so every card carries it.
            'metaText' => null,
        ];
    }

    /**
     * Extract location name from subject line.
     * Returns the text in parentheses at the end, e.g. "Edinburgh" from "OFFER: Sofa (Edinburgh)".
     */
    protected function extractLocationName(string $subject): ?string
    {
        if (preg_match('/\(([^)]+)\)\s*$/', $subject, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Haversine distance in miles between two lat/lng points.
     */
    protected function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusMiles = 3959;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusMiles * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Prepare posts with AMP reply URLs and tokens.
     */
    protected function prepareAmpPosts(): Collection
    {
        // The AmpEmail trait + Go API expose freegle.amp.api_url (default
        // https://api.ilovefreegle.org/amp), which is the host Gmail has
        // registered for Freegle AMP forms. Earlier code looked up the
        // non-existent freegle.amp.api_base key and fell back to the user
        // site (www.ilovefreegle.org/amp/digest/...) — Gmail rejected the
        // AMP form because the action-xhr host was not a registered sender
        // domain, which surfaced as "INVALID_AMP" in the email.
        $ampApiUrl = rtrim(config('freegle.amp.api_url', 'https://api.ilovefreegle.org/amp'), '/');
        $userId = $this->user->id;

        return $this->preparedPosts->map(function ($post) use ($ampApiUrl, $userId) {
            $messageId = $post['message']->id;
            $token = $this->generateToken($userId, $messageId);

            // Path-style id matches the chat AMP route shape (api_url is
            // ".../amp" already, so the format string just appends
            // "/digest/{id}/reply?..." — same shape as chat's
            // "/chat/{id}/reply?...").
            $post['ampReplyUrl'] = sprintf(
                '%s/digest/%d/reply?rt=%s&uid=%d&exp=%d',
                $ampApiUrl,
                $messageId,
                $token['token'],
                $userId,
                $token['expiry']
            );

            // Expose the token components separately so the AMP template's
            // shared reply form can rebuild the action-xhr URL via amp-bind
            // from the active post's state (each post embeds these in its
            // setState tap, rather than carrying its own form).
            $post['ampReplyToken'] = $token['token'];
            $post['ampReplyUid']   = $userId;
            $post['ampReplyExp']   = $token['expiry'];

            // Fallback URL for non-AMP clients or AMP form errors.
            $post['fallbackReplyUrl'] = $post['messageUrl'];

            return $post;
        });
    }

    /**
     * Get the digest number for this user (count of previously sent digests).
     */
    protected function getDigestNumber(): int
    {
        return (int) DB::table('email_tracking')
            ->where('userid', $this->user->id)
            ->whereIn('email_type', ['UnifiedDigest', 'UnifiedDigestImmediate', 'UnifiedDigestDaily'])
            ->count();
    }

    /**
     * Format the "Posted to" text for display.
     */
    protected function formatPostedTo(array $groupIds): string
    {
        $groupNames = DB::table('groups')
            ->whereIn('id', $groupIds)
            ->pluck('nameshort');

        return 'Posted to: ' . $groupNames->implode(', ');
    }

    /**
     * Get the message image URL via delivery service.
     *
     * V1 parity (iznik-server Attachment::getByIds ORDER BY `primary` DESC,
     * id): prefer the user-uploaded photo (primary=1) over AI-generated
     * fallbacks (primary=0). Also skip attachment rows that haven't been
     * fully populated — same condition V1 uses to exclude in-flight rows
     * with no data/externaluid/externalurl/archived flag set. Without this
     * filter, we'd pick a half-written attachment and 404.
     */
    /**
     * The primary displayable attachment for a message, or null. A photo is
     * "displayable" if it has an external uid/url or has been archived to the
     * image store; the primary flag wins when there are several.
     */
    protected function getPrimaryAttachment($message)
    {
        if (!$message->attachments || $message->attachments->isEmpty()) {
            return null;
        }

        return $message->attachments
            ->filter(fn($a) => !empty($a->externaluid) || !empty($a->externalurl) || (int) ($a->archived ?? 0) === 1)
            ->sortByDesc('primary')
            ->first();
    }

    protected function getMessageImageUrl($message, int $width = 300, ?int $height = null): ?string
    {
        $attachment = $this->getPrimaryAttachment($message);

        if (!$attachment) {
            return null;
        }

        // If there's an external URL, use it directly.
        if (!empty($attachment->externalurl)) {
            return $this->getDeliveryUrl($attachment->externalurl, $width, $height);
        }

        // Build URL from image domain - message images use timg_ prefix for thumbnails.
        $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
        $sourceUrl = "{$imagesDomain}/timg_{$attachment->id}.jpg";

        return $this->getDeliveryUrl($sourceUrl, $width, $height);
    }

    /**
     * Get a URL via the delivery service for image resizing/optimization.
     */
    protected function getDeliveryUrl(string $sourceUrl, int $width, ?int $height = null): string
    {
        if (!$this->deliveryUrl) {
            return $sourceUrl;
        }

        $url = $this->deliveryUrl . '/?url=' . urlencode($sourceUrl) . '&w=' . $width;

        if ($height !== null) {
            // Crop to a fixed box (cover) so a tall portrait photo can't
            // dominate the email — this is what bounds the hero's max height.
            $url .= '&h=' . $height . '&fit=cover';
        }

        return $url;
    }
}
