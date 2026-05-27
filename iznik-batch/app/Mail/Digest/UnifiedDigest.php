<?php

namespace App\Mail\Digest;

use App\Mail\Concerns\BulkRenderable;
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
class UnifiedDigest extends MjmlMailable implements RetryableMailable, BulkRenderable
{
    use AmpEmail;
    use AvatarResolver;
    use LoggableEmail;
    use TrackableEmail;

    public string $userSite;

    public string $deliveryUrl;

    protected Collection $preparedPosts;

    /** @var array<int,object> primary group rows (id => {nameshort, namefull}) for post bylines + header list */
    protected array $groupLookup = [];

    protected int $digestNumber;

    /**
     * Cached User::getJobAds() result. Avoids running the (cross-table /
     * spatial) jobs query three times per send for the bulk path (once each
     * for shapeKey, bulkData, mergeVars).
     */
    protected ?Collection $cachedJobAds = null;

    public function __construct(
        public User $user,
        protected Collection $posts,
        public string $mode,
        protected Collection $sponsors = new Collection()
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
            $trackingGroupId = $this->posts->first()['postedToGroups'][0] ?? null;
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
    }

    /**
     * Get the recipient's user ID for common header tracking.
     */
    protected function getRecipientUserId(): ?int
    {
        return $this->user->id ?? null;
    }

    /**
     * IDs needed to rebuild this digest for a durable retry.
     * Stores only scalars (user id, mode, message ids + group ids).
     *
     * {@see RetryableMailable}
     */
    public function mailDescriptor(): array
    {
        return [
            'userid' => $this->user->id,
            'mode' => $this->mode,
            'posts' => $this->posts->map(fn ($p) => [
                'msgid' => $p['message']->id,
                'groups' => $p['postedToGroups'],
            ])->values()->all(),
        ];
    }

    /**
     * Rebuild a fresh digest from a descriptor, re-fetching from the DB.
     *
     * Returns null (cancel the retry) when the user no longer exists or has
     * no usable address, or when none of the posts still exist in the DB.
     *
     * {@see RetryableMailable}
     */
    public static function rebuildFromDescriptor(array $descriptor): ?self
    {
        $user = User::find($descriptor['userid'] ?? null);
        if (!$user) {
            return null;
        }

        $posts = collect();
        foreach ($descriptor['posts'] ?? [] as $postData) {
            $message = Message::find($postData['msgid'] ?? null);
            if ($message) {
                $posts->push([
                    'message' => $message,
                    'postedToGroups' => $postData['groups'] ?? [],
                ]);
            }
        }

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
            $groupId = (int) ($descriptor['posts'][0]['groups'][0] ?? 0);
            $sponsors = $service->getSponsorsForGroup($groupId);
        } else {
            $sponsors = $service->getSponsorsForUser($user);
        }

        return new self(
            $user,
            $posts,
            $mode,
            $sponsors
        );
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
        $jobAds = $this->getCachedJobAds();
        $jobCount = count($jobAds);
        foreach ($jobAds as $index => $job) {
            $job->tracked_url = $this->trackedUrl(
                $this->userSite . '/job/' . $job->id .
                    '?source=email&campaign=unified_digest&position=' . $index .
                    '&list_length=' . $jobCount,
                'job_ad_' . $index,
                'job_click'
            );
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
            $groupId = $firstPost['postedToGroups'][0] ?? null;
            if ($groupId) {
                $row = DB::table('groups')->where('id', $groupId)->first(['nameshort', 'namefull']);
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

        $result = $this->mjmlView('emails.mjml.digest.unified', array_merge([
            'user' => $this->user,
            'userEmail' => $this->user->email_preferred ?? '',
            'posts' => $this->preparedPosts,
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
     * Bucket recipients that share compiled HTML. See BulkRenderable.
     *
     * For immediate-mode digests, the structural inputs that change the
     * rendered template are:
     *   - the message (drives all the shared content)
     *   - sponsor set (shared across users in the same group anyway)
     *   - has_distance: changes the @if branch in the post card
     *   - has_jobs: when true, the jobs loop has a per-user body (each user
     *     has their own job set), so users with jobs go solo — unique shape
     *     per user, no cache sharing. Phase 1 simplification.
     */
    public function shapeKey(): string
    {
        if ($this->mode !== UnifiedDigestService::MODE_IMMEDIATE) {
            // Daily digest is per-user (each user has their own union of posts).
            // Force a unique shape so the bulk path produces correct output
            // even though there's no sharing benefit. See spec for follow-up
            // work on per-post fragment sharing.
            return 'unique-daily-'.$this->user->id;
        }

        if ($this->getCachedJobAds()->isNotEmpty()) {
            return 'unique-jobs-'.$this->user->id.'-'.($this->posts->first()['message']->id ?? 0);
        }

        $messageId = $this->posts->first()['message']->id ?? 0;
        $sponsorIds = $this->sponsors->pluck('id')->sort()->implode(',');

        // has_distance depends on BOTH the user having a location AND the
        // message having lat/lng (preparePosts computes the actual text). Use
        // the rendered distanceText from preparedPosts so the bucket reflects
        // the same @if branch the template will take. Users-with-location
        // viewing a message-without-lat-lng land in the same bucket as
        // users-without-location.
        $firstPrepared = $this->preparedPosts->first();
        $hasDistance = ($firstPrepared['distanceText'] ?? null) !== null ? '1' : '0';

        return sha1("immediate|{$messageId}|{$sponsorIds}|{$hasDistance}");
    }

    /**
     * Memoised wrapper around $this->user->getJobAds(). Returns the 'jobs'
     * collection (possibly empty). Cached for the lifetime of this Mailable.
     */
    protected function getCachedJobAds(): Collection
    {
        if ($this->cachedJobAds === null) {
            $this->cachedJobAds = $this->user->getJobAds()['jobs'] ?? collect();
        }
        return $this->cachedJobAds;
    }

    public function bulkTemplate(): string
    {
        return 'emails.mjml.digest.unified';
    }

    /**
     * Data for the SHARED compile pass. Per-recipient fields are literal
     * "{{name}}" placeholder strings; shared fields are real values.
     *
     * Mirrors the data array that build() passes to mjmlView(), but with
     * placeholder substitutions in place of trackedUrl()-derived values.
     */
    public function bulkData(): array
    {
        $bulkPosts = $this->preparePostsForBulk();
        $bulkJobAds = $this->buildBulkJobAds();

        return array_merge([
            'user' => $this->user,
            'userEmail' => $this->ph('userEmail'),
            'posts' => $bulkPosts,
            'postCount' => $this->posts->count(),
            'mode' => $this->mode,
            'sponsors' => $this->sponsors,
            'settingsUrl' => $this->ph('settingsUrl'),
            'unsubscribeUrl' => $this->ph('unsubscribeUrl'),
            'browseUrl' => $this->ph('browseUrl'),
            'userSite' => $this->userSite,
            'jobAds' => $bulkJobAds,
            'jobsUrl' => $this->ph('jobsUrl'),
            'donateUrl' => $this->ph('donateUrl'),
            'primaryGroupName' => $this->getPrimaryGroupName(),
            'frequencyText' => $this->mode === UnifiedDigestService::MODE_IMMEDIATE ? 'immediately' : 'daily',
        ], [
            // Overrides for getTrackingData() so the tracking pixel URL is a
            // merge placeholder instead of the first recipient's real URL.
            'tracking' => $this->tracking,
            'trackingPixelMjml' => '<mj-image src="'.$this->ph('trackingPixelUrl').'" width="1px" height="1px" alt="" padding="0" />',
            'trackingPixelHtml' => '',
        ]);
    }

    /**
     * Per-recipient substitution map. Keys match the literal "{{name}}"
     * placeholders in bulkData(); values are the real per-recipient strings.
     */
    public function mergeVars(): array
    {
        $vars = [
            'userEmail' => $this->user->email_preferred ?? '',
            'browseUrl' => $this->trackedUrl($this->userSite.'/browse', 'browse_cta', 'browse'),
            'donateUrl' => $this->trackedUrl(
                config('freegle.donate.url', 'https://freegle.in/paypal1510'),
                'donate',
                'donate'
            ),
            'jobsUrl' => $this->trackedUrl($this->userSite.'/jobs', 'jobs_view_more', 'jobs_view_more'),
            'unsubscribeUrl' => $this->trackedUrl($this->userSite.'/unsubscribe', 'footer_unsubscribe', 'unsubscribe'),
            'settingsUrl' => $this->trackedUrl($this->userSite.'/settings', 'footer_settings', 'settings'),
            'messageUrl' => $this->preparedPosts->isNotEmpty()
                ? $this->preparedPosts->first()['messageUrl']
                : '',
            'trackingPixelUrl' => $this->tracking?->getPixelUrl() ?? '',
        ];

        // distanceText is only emitted by the template when the user has a
        // location (handled via shape key). Provide the value when present.
        if ($this->user->lastlocation !== null && $this->preparedPosts->isNotEmpty()) {
            $first = $this->preparedPosts->first();
            $vars['distanceText'] = $first['distanceText'] ?? '';
        }

        // Job slots — same indexing as buildBulkJobAds().
        $jobAds = $this->getCachedJobAds();
        $jobCount = count($jobAds);
        foreach ($jobAds as $idx => $job) {
            $vars["job_{$idx}_url"] = $this->trackedUrl(
                $this->userSite.'/job/'.$job->id.
                    '?source=email&campaign=unified_digest&position='.$idx.
                    '&list_length='.$jobCount,
                'job_ad_'.$idx,
                'job_click'
            );
        }

        return $vars;
    }

    /**
     * Build a placeholder version of $this->preparedPosts where per-recipient
     * fields (messageUrl, distanceText) are literal "{{name}}" strings.
     *
     * Shared fields (itemName, locationName, posterAvatarUrl, arrival timestamps,
     * displayImageUrl, etc.) are real values — same as preparePosts().
     */
    protected function preparePostsForBulk(): Collection
    {
        return $this->posts->map(function ($post) {
            $message = $post['message'];
            $postedToGroups = $post['postedToGroups'];
            $isOffer = $message->type === 'Offer';

            $postedToText = count($postedToGroups) > 1
                ? $this->formatPostedTo($postedToGroups)
                : null;

            $imageUrl = $this->getMessageImageUrl($message);
            $placeholderUrl = $isOffer
                ? config('freegle.images.offer_placeholder')
                : config('freegle.images.wanted_placeholder');
            $displayImageUrl = $imageUrl ?? $placeholderUrl;

            $messageText = $message->textbody
                ? EmojiUtils::decodeEmojis($message->textbody)
                : null;

            $arrival = $message->arrival instanceof Carbon ? $message->arrival : Carbon::parse($message->arrival);
            $arrivalFormatted = $arrival->setTimezone('Europe/London')->format('D j M, g:ia');
            $arrivalIso = $arrival->toIso8601String();

            // distanceText: truthy placeholder when the user has a location
            // (the template @if branches on it). Shape key includes
            // has_distance, so within a shape this is consistent.
            $distanceText = $this->user->lastlocation !== null ? $this->ph('distanceText') : null;

            $posterUser = $message->relationLoaded('fromUser') ? $message->fromUser : null;
            $posterAvatarUrl = $this->resolveAvatarUrl($posterUser, 36);
            $posterName = $posterUser?->displayname ?? $message->fromname ?? 'Freegler';

            $firstPostedFormatted = null;
            $originalDate = $message->date instanceof Carbon
                ? $message->date
                : ($message->date ? Carbon::parse($message->date) : null);
            if ($originalDate && $arrival->diffInMinutes($originalDate) > 60) {
                $firstPostedFormatted = $originalDate->setTimezone('Europe/London')->format('D, jS F g:ia');
            }

            return [
                'message' => $message,
                'messageText' => $messageText,
                'messageUrl' => '{{messageUrl}}',
                'imageUrl' => $imageUrl,
                'displayImageUrl' => $displayImageUrl,
                'trackedImageUrl' => null,
                'isPlaceholder' => $imageUrl === null,
                'postedToText' => $postedToText,
                'type' => $message->type,
                'subject' => $message->subject,
                'itemName' => $this->extractItemName($message->subject),
                'locationName' => $this->extractLocationName($message->subject),
                'arrivalFormatted' => $arrivalFormatted,
                'arrivalIso' => $arrivalIso,
                'distanceText' => $distanceText,
                'posterName' => $posterName,
                'posterAvatarUrl' => $posterAvatarUrl,
                'firstPostedFormatted' => $firstPostedFormatted,
            ];
        });
    }

    /**
     * Build a placeholder version of the user's jobs collection — same shape
     * and count as the real jobs but with each tracked_url replaced by a
     * position-indexed "{{job_N_url}}" placeholder.
     */
    protected function buildBulkJobAds(): Collection
    {
        return $this->getCachedJobAds()->map(function ($job, $idx) {
            $clone = clone $job;
            $clone->tracked_url = $this->ph('job_'.$idx.'_url');
            return $clone;
        });
    }

    /**
     * Look up the primary group name (the post's first group) for the per-group
     * footer line. Shared across all recipients of one message.
     */
    protected function getPrimaryGroupName(): ?string
    {
        if ($this->mode !== UnifiedDigestService::MODE_IMMEDIATE || $this->posts->isEmpty()) {
            return null;
        }

        $firstPost = $this->posts->first();
        $groupId = $firstPost['postedToGroups'][0] ?? null;
        if (!$groupId) {
            return null;
        }

        $row = DB::table('groups')->where('id', $groupId)->first(['nameshort', 'namefull']);
        return $row ? ($row->namefull ?: $row->nameshort) : null;
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

    protected function getSubject(): string
    {
        if ($this->mode === UnifiedDigestService::MODE_IMMEDIATE && $this->posts->isNotEmpty()) {
            $firstPost = $this->posts->first();
            $groupId = $firstPost['postedToGroups'][0] ?? null;
            $groupRow = $groupId
                ? DB::table('groups')->where('id', $groupId)->first(['nameshort', 'namefull'])
                : null;
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

        // Batch-load the primary group (first in postedToGroups) for every post
        // so each card can show "on <group>" without an N+1 per post. namefull
        // is the friendly name; nameshort drives the /explore link.
        $primaryGroupIds = $this->posts
            ->map(fn ($p) => $p['postedToGroups'][0] ?? null)
            ->filter()
            ->unique()
            ->values();
        $this->groupLookup = $primaryGroupIds->isNotEmpty()
            ? DB::table('groups')->whereIn('id', $primaryGroupIds)
                ->get(['id', 'nameshort', 'namefull'])->keyBy('id')->all()
            : [];

        return $this->posts->map(function ($post, $index) use ($totalPosts, $userLat, $userLng) {
            $message = $post['message'];
            $postedToGroups = $post['postedToGroups'];
            $isOffer = $message->type === 'Offer';

            // Primary group name (friendly full name) + /explore link for the
            // "Posted by … on <group>" byline.
            $groupName = null;
            $groupUrl = null;
            $primaryGroupId = $postedToGroups[0] ?? null;
            if ($primaryGroupId && isset($this->groupLookup[$primaryGroupId])) {
                $g = $this->groupLookup[$primaryGroupId];
                $groupName = $g->namefull ?: $g->nameshort;
                if ($g->nameshort) {
                    $groupUrl = $this->trackedUrl(
                        $this->userSite . '/explore/' . urlencode($g->nameshort),
                        "group_{$index}",
                        'group'
                    );
                }
            }

            // Get image URL via delivery service.
            $imageUrl = $this->getMessageImageUrl($message);

            // Use type-specific placeholder if no photo.
            $placeholderUrl = $isOffer
                ? config('freegle.images.offer_placeholder')
                : config('freegle.images.wanted_placeholder');

            // Create tracked image URL with scroll depth.
            $scrollPercent = $totalPosts > 0 ? round(($index / $totalPosts) * 100) : 0;
            $displayImageUrl = $imageUrl ?? $placeholderUrl;
            $trackedImage = $displayImageUrl !== null
                ? $this->trackedImageUrl($displayImageUrl, "image_{$index}", $scrollPercent)
                : null;

            // Hero image for immediate (single-post) mode: a larger, height-
            // capped crop (600x400) so the photo is the hero — V1's immediate
            // single.mjml used a full-width image. Cropping to a 3:2 box bounds
            // the height so portrait photos don't dominate. Falls back to the
            // type placeholder when the post has no usable photo.
            $heroImageUrl = $this->getMessageImageUrl($message, 600, 400) ?? $placeholderUrl;

            // Thumbnail image for multi-post (daily) cards: a fixed 4:3
            // cover-crop (240x180) so every card has the same image height
            // regardless of the original photo's aspect ratio. Without this
            // the email rendered with the post's natural aspect — a portrait
            // photo made a card three times taller than a landscape one.
            // Square thumbnail (240x240) — used by the daily multi-post cards.
            // Square crop makes the photo height match a typical content
            // column height closely, so the Reply button at the bottom of
            // the card sits flush with the photo rather than dangling below.
            $thumbImageUrl = $this->getMessageImageUrl($message, 240, 240) ?? $placeholderUrl;

            // Decode emoji sequences in message text.
            $messageText = $message->textbody
                ? EmojiUtils::decodeEmojis($message->textbody)
                : null;

            // Create tracked URL for this message (with position tracking).
            // ?reply=1 tells the message page to auto-open the reply compose
            // form on mount — saves the recipient an extra click since the
            // CTA is literally labelled "Reply".
            $messageUrl = $this->trackedUrl(
                $this->userSite . '/message/' . $message->id . '?reply=1',
                "post_{$index}",
                'view_message'
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
            $posterAvatarUrl = $this->resolveAvatarUrl($posterUser, 36);
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

            return [
                'message' => $message,
                'messageText' => $messageText,
                'messageUrl' => $messageUrl,
                'imageUrl' => $imageUrl,
                'displayImageUrl' => $displayImageUrl,
                'heroImageUrl' => $heroImageUrl,
                'thumbImageUrl' => $thumbImageUrl,
                'trackedImageUrl' => $trackedImage,
                'isPlaceholder' => $imageUrl === null,
                'groupName' => $groupName,
                'groupUrl' => $groupUrl,
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
            ];
        });
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
    protected function getMessageImageUrl($message, int $width = 300, ?int $height = null): ?string
    {
        if (!$message->attachments || $message->attachments->isEmpty()) {
            return null;
        }

        $attachment = $message->attachments
            ->filter(fn($a) => !empty($a->externaluid) || !empty($a->externalurl) || (int) ($a->archived ?? 0) === 1)
            ->sortByDesc('primary')
            ->first();

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
