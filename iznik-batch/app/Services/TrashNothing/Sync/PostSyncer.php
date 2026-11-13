<?php

namespace App\Services\TrashNothing\Sync;

use App\Models\Group;
use App\Models\Location;
use App\Services\ItemService;
use App\Services\LokiService;
use App\Services\Mail\Incoming\RoutingResult;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use App\Services\TrashNothing\Ingestion\TnUserProvisioner;
use Illuminate\Support\Facades\Log;
use OpenAPI\Client\Api\PostsApi;
use OpenAPI\Client\ApiException;
use OpenAPI\Client\Configuration;

class PostSyncer
{
    // ISO-8601 UTC, the format TN's API returns dates in and the one every
    // date this class logs or compares is normalized to.
    private const ISO_UTC = 'Y-m-d\TH:i:s\Z';

    // /posts/all enforces per_page <= 50.
    private const PAGE_SIZE = 50;

    /**
     * Outcomes meaning the post was never going to be posted to FD anyway, so
     * /posts/all correctly excludes it — absence is not a coverage regression.
     *
     * Per the OpenAPI Post model, outcome is one of satisfied/withdrawn/
     * promised/expired (offers) or satisfied/withdrawn/expired (wanted).
     * 'deleted' isn't a real outcome value — a deleted post 404s, and
     * lookupPostById() reports that as status=not_found — but is matched
     * defensively in case TN ever returns it as one.
     *
     * Lives here, next to lookupPostById() which produces the values it is
     * compared against, so tn:parity-check (Layer 1 reclassification) and
     * tn:verify-email-coverage (section S.4) share one definition.
     */
    public const RESOLVED_OUTCOMES = ['satisfied', 'withdrawn', 'deleted'];

    // routing_reason values for the skips this class decides itself, before
    // ingestion is reached. API-only — no email-path branch produces them.
    private const REASON_NO_COORDINATES = 'no-coordinates';
    private const REASON_NOT_IN_ANY_GROUP_BOUNDS = 'not-in-any-group-bounds';
    private const REASON_INGESTION_EXCEPTION = 'ingestion-exception';

    private GroupPostIngestionService $ingestionService;

    // Shared with RatingsSyncer and UserChangesSyncer — TN rate-limits per API
    // key, and one tn:sync run calls all three endpoints with the same key.
    private TrashNothingRateLimiter $rateLimiter;

    // Set only for the duration of ingestFetchedPost(), so a backfilled post's
    // routed Loki entry is distinguishable from one the scheduled sync caught.
    private bool $backfill = false;

    // Whether this run has already reported a post with no freegle_group_ids field, so a key
    // without the field reports once a run rather than once a post.
    private bool $missingConsentFieldReported = false;

    // Set by processPost() when TN could not be asked who a post's poster is. sync() stops
    // there; ingestFetchedPost() answers false.
    private bool $posterLookupFailed = false;

    // Where the last sync() stopped for a poster it could not look up: the start of the
    // day window the post was in. Null when the run got through every post.
    private ?string $stalledAt = null;

    public function __construct(
        private bool $dryRun,
        private bool $localTesting,
        private string $apiKey,
        private string $apiBaseUrl,
        private LokiService $loki,
        // Override for --local-testing fixture lookup, e.g. for parity tests that
        // need a scenario-specific fixture directory instead of the shared default.
        // Defaults to tests/fixtures/tn_sync.
        private ?string $fixtureDir = null,
        ?TrashNothingRateLimiter $rateLimiter = null,
    ) {
        $this->rateLimiter = $rateLimiter ?? app(TrashNothingRateLimiter::class);
        $this->ingestionService = new GroupPostIngestionService(
            dryRun: $this->dryRun,
            loki: $this->loki,
            itemService: app(ItemService::class),
            localTesting: $this->localTesting,
            userProvisioner: new TnUserProvisioner(
                dryRun: $this->dryRun,
                localTesting: $this->localTesting,
                publicApiKey: $this->apiKey,
                loki: $this->loki,
                rateLimiter: $this->rateLimiter,
            ),
        );
    }

    /**
     * @return array{int, string|null} [count, maxDate]
     */
    public function sync(string $from, string $to): array
    {
        $count   = 0;
        $maxDate = null;
        $api     = $this->buildApiClient();

        $this->missingConsentFieldReported = false;
        $this->stalledAt = null;

        // /posts/all requires date_max within 1 day of date_min, so walk day-by-day.
        $cursor = new \DateTime($from, new \DateTimeZone('UTC'));
        $end    = new \DateTime($to,   new \DateTimeZone('UTC'));

        while ($cursor < $end) {
            $next      = (clone $cursor)->modify('+1 day');
            $windowEnd = $next < $end ? $next : $end;

            $page = 1;
            do {
                [$posts, $hasMore] = $this->fetchPage($api, $page, $cursor, $windowEnd);
                if ($posts === null) {
                    break 2; // API error — abort entire sync
                }

                Log::info('TN-SYNC-TRACE [POSTS-PAGE] page=' . $page . ' count=' . count($posts));

                foreach ($posts as $post) {
                    $count++;
                    $maxDate = $this->processPost($post, $maxDate);

                    // TN could not say who posted this, so Freegle cannot tell whether it
                    // is theirs to ingest. Passing over it would put it behind the stored
                    // cursor for good, so the window stops here and the next run asks again.
                    // Like a failed page above, but the failure is in a post, not the feed.
                    if ($this->posterLookupFailed) {
                        $this->stalledAt = $cursor->format(self::ISO_UTC);
                        break 3;
                    }
                }

                $page++;
            } while ($hasMore);

            $cursor = $windowEnd;
        }

        // The feed's order is TN's, so any post in the window that stopped may not have been
        // reached: the cursor must not pass the start of that window.
        if ($this->stalledAt !== null) {
            if ($maxDate !== null && strtotime($maxDate) > strtotime($this->stalledAt)) {
                $maxDate = $this->stalledAt;
            }
            Log::warning('TN-SYNC-TRACE [POSTS-STALLED] a poster could not be looked up; the next run resumes from ' . $this->stalledAt);
        }

        Log::info('TN-SYNC-TRACE [POSTS-DONE] total=' . $count . ' max_date=' . ($maxDate ?? 'null'));

        // Run-level volume, for comparison against the email path's own volume
        // over the same window. Not a routing outcome, so it stays on the batch
        // stream — the routed stream carries one entry per post and nothing else.
        $this->loki->logBatchJob('tn:sync-posts', 'completed', [
            'total'    => $count,
            'max_date' => $maxDate,
            'from'     => $from,
            'to'       => $to,
            'dry_run'  => $this->dryRun,
            'stalled_at' => $this->stalledAt,
        ]);

        return [$count, $maxDate];
    }

    /**
     * Where the last sync() stopped because TN could not be asked about a poster, or null
     * if it got through every post. The stored sync date must not go past it, whatever
     * the other syncers saw: a later run has to fetch that window again.
     */
    public function stalledAt(): ?string
    {
        return $this->stalledAt;
    }

    /**
     * @return array{array|null, bool} [posts, hasMore] — posts is null on API error
     */
    private function fetchPage(PostsApi $api, int $page, \DateTime $from, \DateTime $to): array
    {
        if ($this->localTesting) {
            return $this->fetchPageFromFixture($page);
        }

        $this->throttle();

        try {
            $response = $api->getAllPosts(
                types: 'offer,wanted',
                date_min: $from->format('Y-m-d\TH:i:s'),
                date_max: $to->format('Y-m-d\TH:i:s'),
                per_page: self::PAGE_SIZE,
                page: $page,
            );
        } catch (ApiException $e) {
            // The client's message quotes the request URL, key included.
            Log::error('TN sync: posts API failed on page ' . $page, [
                'status' => $e->getCode(),
                'error'  => self::redactApiKey($e->getMessage()),
            ]);
            // Aborts the whole sync (see sync()'s `break 2`). Without this a
            // failed run is indistinguishable in Loki from a window that simply
            // had no posts — the difference between "nothing happened" and
            // "ingestion is down". Not a per-post routing outcome, so it goes on
            // the batch stream rather than the routed one.
            $this->loki->logBatchJob('tn:sync-posts', 'failed', [
                'page'   => $page,
                'status' => $e->getCode(),
                'error'  => $e->getMessage(),
                'from'   => $from->format(self::ISO_UTC),
                'to'     => $to->format(self::ISO_UTC),
            ]);
            return [null, false];
        }

        $posts = $response->getPosts() ?? [];

        return [$posts, count($posts) >= self::PAGE_SIZE];
    }

    /**
     * @return array{array, bool} [posts, hasMore]
     */
    private function fetchPageFromFixture(int $page): array
    {
        $fixtureDir  = $this->fixtureDir ?? 'tests/fixtures/tn_sync';
        $fixtureFile = base_path("{$fixtureDir}/posts_page_{$page}.json");

        if (!file_exists($fixtureFile)) {
            Log::info('TN-SYNC-TRACE [POSTS-PAGE] missing fixture file=' . $fixtureFile);
            return [[], false];
        }

        $payload = json_decode(file_get_contents($fixtureFile), true);
        $posts   = is_array($payload) ? ($payload['posts'] ?? []) : [];

        return [$posts, count($posts) >= self::PAGE_SIZE];
    }

    private function processPost(mixed $post, ?string $maxDate): ?string
    {
        $this->posterLookupFailed = false;
        $previousMax = $maxDate;

        $date      = is_array($post) ? ($post['date'] ?? null) : $post->getDate()?->format(self::ISO_UTC);
        $postId    = is_array($post) ? ($post['post_id'] ?? '') : $post->getPostId();
        $tnUserId  = is_array($post) ? ($post['user_id'] ?? null) : $post->getUserId();
        $type      = is_array($post) ? ($post['type'] ?? '') : $post->getType();
        $groupId   = is_array($post) ? ($post['group_id'] ?? '') : $post->getGroupId();
        $title     = is_array($post) ? ($post['title'] ?? '') : $post->getTitle();
        $lat       = is_array($post) ? ($post['latitude'] ?? null) : $post->getLatitude();
        $lng       = is_array($post) ? ($post['longitude'] ?? null) : $post->getLongitude();

        if ($date && (!$maxDate || $date > $maxDate)) {
            $maxDate = $date;
        }

        // tn_group_id, not group_id: this is TN's OWN group id (empty on a
        // source post, set on a per-group copy), which is never used to place
        // the post: see placement() below. The email path's [POST]
        // line carries a `group_id=` that is the resolved FREEGLE group id, so
        // reusing that name here would put two different things under one
        // label in a stream the two paths are meant to be read side by side.
        // Matches the name [POST-SKIP] reason=crosspost already uses.
        Log::info('TN-SYNC-TRACE [POST] post_id=' . $postId . ' type=' . $type . ' tn_group_id=' . $groupId . ' date=' . $date . ' title=' . substr((string) $title, 0, 60));

        // The subject the ingestion service would have synthesized, so a skip
        // entry carries the same subject text as a successful one.
        $subject = strtoupper((string) $type) . ': ' . $title;

        // The member chooses the community on TrashNothing. TN's out-of-spec
        // freegle_group_ids field (see the FREEGLE LOCAL MODIFICATION note on the Post model)
        // lists the Freegle communities the member picked for this post, and the post goes on
        // one of them (placement()). Only a post that names none is placed by its own lat/lng,
        // on the local community, and that one is unaddressed: its poster never chose it, so
        // messages_groups.mod_messaging_allowed is 0 (Discourse 10315). TN's own group_id is
        // never used: it is where the member happened to post on TN, which drifts out of step
        // with Freegle's group boundaries.
        //
        // A missing field (e.g. a non-FD API key) is no choice at all, so every post would be
        // placed by location and unaddressed; it is reported. An empty list is the member's
        // choice and is not.
        if ($lat === null || $lng === null) {
            Log::info('TN-SYNC-TRACE [POST-SKIP] reason=no-coordinates tn_group_id=' . $groupId . ' post_id=' . $postId);
            // No group resolved, so no group context — which matches the shape
            // of the email path's own unknown-group drop (case 1: routing_reason
            // and nothing else).
            $this->logRoutedPost($postId, $tnUserId, $subject, null, RoutingResult::DROPPED, [
                'routing_reason' => self::REASON_NO_COORDINATES,
            ]);

            return $maxDate;
        }

        if (($post['freegle_group_ids'] ?? null) === null) {
            $this->reportMissingConsentField($postId);
        }

        [$group, $moderatorMessagingAllowed] = self::placement((float) $lat, (float) $lng, self::chosenGroupIds($post));
        if ($group === null) {
            Log::info('TN-SYNC-TRACE [POST-SKIP] reason=not-in-any-group-bounds lat=' . $lat . ' lng=' . $lng . ' post_id=' . $postId);
            // The closest analogue to the email path's "Post to unknown group"
            // (case 1), and the single most important entry in this whole
            // stream: this is the coverage-regression case Layer 1 of
            // tn:parity-check exists to catch, and it was previously invisible
            // outside the trace logs.
            $this->logRoutedPost($postId, $tnUserId, $subject, null, RoutingResult::DROPPED, [
                'routing_reason' => self::REASON_NOT_IN_ANY_GROUP_BOUNDS,
            ]);

            return $maxDate;
        }

        // Own trace tag (not [POST-RESULT]/[POST-SKIP]/[WRITE]) — EmailApiParityTest diffs
        // those tags line-for-line against the email path, which has no equivalent of this
        // API-only field.
        Log::info('TN-SYNC-TRACE [POST-META] post_id=' . $postId . ' group_id=' . $group->id . ' placed=' . ($moderatorMessagingAllowed ? 'chosen' : 'location') . ' moderator_messaging_allowed=' . ($moderatorMessagingAllowed ? 'true' : 'false'));

        try {
            $result = $this->ingestionService->ingest($post, $group, $moderatorMessagingAllowed);
            Log::info('TN-SYNC-TRACE [POST-RESULT] post_id=' . $postId . ' result=' . $result);

            $context = $this->ingestionService->getLastRoutingContext();

            $reason = $context['routing_reason'] ?? null;

            // A rejected key holds nothing in the sync (TnUserProvisioner::REASON_LOOKUP_KEY_REJECTED):
            // the post is skipped as a miss below. The coverage check's backfill asks about a missed
            // post again on its next run, so there it is "try later", like a failed lookup.
            if ($reason === GroupPostIngestionService::REASON_TN_USER_LOOKUP_FAILED
                || ($this->backfill && $reason === GroupPostIngestionService::REASON_TN_USER_LOOKUP_KEY_REJECTED)) {
                // Held for a retry, not decided: the cursor must not move to this post's date
                // and the next run reaches the post again. The routed stream carries one entry
                // per post, so it gets its entry when the post is finally ingested or skipped
                // (a post whose lookups keep failing is skipped, see TnUserProvisioner), and a
                // wedge does not write one every minute.
                $this->posterLookupFailed = true;

                return $previousMax;
            }

            // THE emission point for an ingested post, mirroring
            // IncomingMailController::receive(): the router returns an outcome
            // plus its accumulated context, and the caller turns that into
            // exactly one Loki entry. Because this is a single statement after
            // ingest() returns, a post can never produce two entries — if
            // ingest() throws, nothing was emitted and the catch below emits
            // once instead.
            $this->logRoutedPost(
                $postId,
                $tnUserId,
                $subject,
                $group,
                GroupPostIngestionService::outcomeFor($result),
                $context,
            );
        } catch (\Throwable $e) {
            // Reported, not just logged: the sync moves past this post, and only
            // tn:verify-email-coverage, hours later, fetches it again.
            GroupPostIngestionService::reportFailure('TN sync: post ingestion failed', $e, [
                'post_id' => $postId,
            ]);
            // The one place this path deliberately does NOT mirror the email
            // path: IncomingMailController's own catch emits no Loki entry, so a
            // post lost to an exception leaves no trace in the routed stream at
            // all. Reproducing that would mean silently losing posts here too,
            // which is the opposite of what this stream is for. RoutingResult::
            // FAILURE is the email path's own value for "routing failed", so the
            // vocabulary still lines up even though the email path never emits it.
            $this->logRoutedPost($postId, $tnUserId, $subject, $group, RoutingResult::FAILURE, [
                'routing_reason' => self::REASON_INGESTION_EXCEPTION,
            ]);
        }

        return $maxDate;
    }

    /**
     * A post with no freegle_group_ids field at all is a configuration fault, not a poster's
     * choice: TN turns the field on per API key, and a key without it reads every post as
     * unaddressed. That makes every poster an implicit member of their post's group, and takes
     * moderator contact away. That looks to be what happened for 27 hours after the key changed
     * on 2026-09-25. The post is still read as "no consent", but the run reports it, once, so
     * a key change is caught in minutes rather than weeks.
     */
    private function reportMissingConsentField(string $postId): void
    {
        if ($this->missingConsentFieldReported) {
            return;
        }
        $this->missingConsentFieldReported = true;

        $message = 'TrashNothing posts API returned a post with no freegle_group_ids field (post '
            . $postId . '): every post is read as unaddressed until TN enables the field for this API key';
        Log::error($message);
        if (app()->bound('sentry')) {
            app('sentry')->captureMessage($message, \Sentry\Severity::error());
        }
    }

    /**
     * Emit one type=routed Loki entry for one TN post.
     *
     * The API-path counterpart of the single
     * `app(LokiService::class)->logIncomingEmail(...)` call in
     * IncomingMailController::receive() / IncomingMailCommand — same schema,
     * same labels, same subtype vocabulary, differing only on the source label.
     * Factored into a helper purely because this class has four call sites where
     * the controller has one; the emission still lives in the caller, never in
     * the ingestion service, exactly as on the email path.
     *
     * @param  mixed  $tnUserId  The TN user id on the post, as TN sent it; null when it sent none
     * @param  Group|null  $group  Null when no group was resolved (pre-ingest skips)
     * @param  array  $context  The ingestion service's accumulated routing context
     */
    private function logRoutedPost(
        string $postId,
        mixed $tnUserId,
        string $subject,
        ?Group $group,
        RoutingResult $outcome,
        array $context,
    ): void {
        // API-only, and the one field with no email-path counterpart: the TN
        // post id lives in the X-Trash-Nothing-Post-Id header, which the email
        // path never logs, and adding it there would mean modifying a frozen
        // path. Correlation therefore runs the other way — email-side message_id
        // -> messages.tnpostid. See plans/tn-api-post-ingestion.md section I.5a.
        $context['tn_post_id'] = $postId;

        // API-only too, and the record of who TN said wrote the post. messages keeps no TN id on
        // purpose, and users.tnuserid only says who holds an id now.
        $context['tn_user_id'] = $tnUserId ? (int) $tnUserId : null;

        if ($this->dryRun) {
            $context['dry_run'] = true;
        }

        // Additive, like dry_run and tn_post_id: ModTools' incoming dashboard
        // maps a fixed allowlist of keys rather than iterating them, so an extra
        // key cannot break it. Section S.6.
        if ($this->backfill) {
            $context['backfill'] = true;
        }

        $entry = $this->loki->logIngestedPost(
            // No SMTP envelope on this path. createMessage() records the member's TN
            // address as the sender on the message, but this entry is written whether
            // or not a message was made, so it keeps both empty.
            envelopeFrom: '',
            envelopeTo: $group !== null
                ? $group->nameshort . '@' . config('freegle.mail.group_domain', 'groups.ilovefreegle.org')
                : '',
            fromAddress: null,
            subject: $subject,
            // Matches messages.messageid. Overwritten by the context's own
            // message_id (the FD msgid) when ingestion created a message, which
            // is exactly what the email path's context does.
            messageId: $group !== null ? $postId . '@tn.trashnothing.com-' . $group->id : '',
            routingOutcome: $outcome->value,
            context: $context,
        );

        // The entry itself, keyed by post_id, for ParityComparer's Loki layer —
        // the API-side counterpart of EmailReplaySyncer's identical line.
        if ($entry !== null) {
            Log::info('TN-SYNC-TRACE [LOKI] post_id=' . $postId . ' entry=' . json_encode($entry));
        }
    }

    /**
     * Looks up a single post by ID directly (GET /posts/{id}), bypassing the
     * date-range listing. Used by TNParityCheckCommand to distinguish a
     * genuine Layer 1 miss (post exists, in-window, unresolved outcome, but
     * /posts/all never returned it — a real bug) from false-positive causes
     * confirmed in production: TN mutating a post's `date` on repost/edit —
     * which moves it out of any date-window anchored to when it was first
     * emailed — the post being deleted outright after the fact, or the post
     * having reached an outcome (satisfied/withdrawn) that means it was never
     * going to be posted to FD in the first place. See plans/
     * tn-api-post-ingestion.md section Q for the confirmed live examples.
     *
     * `group_id`, `lat`/`lng` and `post` additionally serve
     * tn:verify-email-coverage (see plans/tn-api-post-ingestion.md section
     * S.4): a non-empty `group_id` marks a TN per-group COPY of a source post,
     * which the API path discards by design
     * (GroupPostIngestionService::REASON_CROSSPOST), so its absence from
     * `messages` is expected rather than a coverage gap. `lat`/`lng` are the
     * coordinates processPost() would place the post from — the only
     * authoritative answer to "would the API path have dropped this as
     * unplaceable?", since TN can edit a post's location after it was emailed
     * and a post that was never mapped has none at all. `post` is the fetched
     * model itself, returned so a caller that decides to ingest the post can
     * hand it straight to ingestFetchedPost() instead of spending a second
     * request against a 2-req/s rate limit.
     *
     * @return array{status: 'found'|'not_found'|'error', date: string|null, outcome: string|null, group_id: string|null, lat: float|null, lng: float|null, post: \OpenAPI\Client\Model\Post|null}
     */
    public function lookupPostById(string $postId): array
    {
        $this->throttle();

        try {
            $post = $this->buildApiClient()->getPost($postId);
            $lat  = $post->getLatitude();
            $lng  = $post->getLongitude();

            return [
                'status'   => 'found',
                'date'     => $post->getDate()?->format(self::ISO_UTC),
                'outcome'  => $post->getOutcome(),
                'group_id' => $post->getGroupId(),
                'lat'      => $lat === null ? null : (float) $lat,
                'lng'      => $lng === null ? null : (float) $lng,
                'post'     => $post,
            ];
        } catch (ApiException $e) {
            if ($e->getCode() === 404) {
                return ['status' => 'not_found', 'date' => null, 'outcome' => null, 'group_id' => null, 'lat' => null, 'lng' => null, 'post' => null];
            }
            Log::warning('TN parity: single-post lookup failed', ['post_id' => $postId, 'error' => $e->getMessage()]);
            return ['status' => 'error', 'date' => null, 'outcome' => null, 'group_id' => null, 'lat' => null, 'lng' => null, 'post' => null];
        }
    }

    /**
     * Ingest one already-fetched post, outside the date-range sync loop.
     *
     * The backfill entry point for tn:verify-email-coverage (section S.5 rail
     * 1): rather than reimplementing ingestion, it reuses processPost() whole,
     * so group placement (placement()), freegle_group_ids consent handling,
     * duplicate suppression and the exactly-one-Loki-entry-per-post invariant
     * all behave identically to a normal sync. The only difference is the
     * `backfill` marker added to the routed entry (see logRoutedPost()), so the
     * stream distinguishes "the sync caught this" from "the verifier had to go
     * back for it".
     *
     * Callers must check group_id/outcome/status themselves first — this method
     * deliberately does not re-litigate whether the post *should* be ingested,
     * because the decision needs context (the email inventory) that PostSyncer
     * does not have.
     *
     * @return bool false when TN could not be asked who the poster is, so nothing was
     *              decided and the caller should try the post again later; true otherwise
     *              (including a post ingestion deliberately skipped)
     */
    public function ingestFetchedPost(mixed $post): bool
    {
        $this->backfill = true;

        try {
            $this->processPost($post, null);

            return !$this->posterLookupFailed;
        } finally {
            $this->backfill = false;
        }
    }

    /**
     * The community a TN post goes on, and whether the member chose it.
     *
     * A post that names communities goes on one of them: the one whose area contains it,
     * or failing that the one whose centre is nearest. Wherever the post is, even outside
     * every community's area, because the member chose it. A chosen community that is not
     * published is passed over. A post that names none (or none we can use) goes on the
     * local community by Location::groupsNear(), not chosen, or nowhere when no community
     * is near enough.
     *
     * Public and static so tn:verify-email-coverage (CoverageVerifier) asks the same
     * question ingestion does.
     *
     * @param  int[]  $chosenIds  the post's freegle_group_ids (chosenGroupIds())
     * @return array{0: Group|null, 1: bool}
     */
    public static function placement(float $lat, float $lng, array $chosenIds): array
    {
        if (!empty($chosenIds)) {
            // keep-raw: ST_Contains/ST_GeomFromText/haversine() have no builder method.
            $chosen = Group::query()
                ->whereIn('id', $chosenIds)
                ->where('publish', 1)
                ->orderByRaw('ST_Contains(polyindex, ST_GeomFromText(?, ?)) DESC', ["POINT($lng $lat)", config('freegle.srid', 3857)])
                ->orderByRaw('COALESCE(haversine(lat, lng, ?, ?), 999999) ASC', [$lat, $lng])
                ->first();

            if ($chosen !== null) {
                return [$chosen, true];
            }
        }

        $groupIds = Location::groupsNear($lat, $lng, limit: 1);

        return [empty($groupIds) ? null : Group::find($groupIds[0]), false];
    }

    /**
     * The Freegle community ids a post names in freegle_group_ids, as ints. Both post shapes
     * (the deserialized Post and the array fixtures use) support ArrayAccess. Fixtures are raw
     * JSON that can carry the ids as strings, which would otherwise never match an id.
     *
     * @return int[]
     */
    public static function chosenGroupIds(mixed $post): array
    {
        $ids = (is_array($post) || $post instanceof \ArrayAccess) ? ($post['freegle_group_ids'] ?? null) : null;

        return array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [])));
    }

    private function throttle(): void
    {
        $this->rateLimiter->await();
    }

    /**
     * Strips the api_key value from anything about to be logged. Guzzle quotes
     * the full request URL in its exception message, so without this a failed
     * call writes the developer key into the batch log and Loki.
     */
    public static function redactApiKey(string $text): string
    {
        return preg_replace('/(api_key=)[^&\s`\'"]+/', '$1<redacted>', $text) ?? $text;
    }

    private function buildApiClient(): PostsApi
    {
        $config = Configuration::getDefaultConfiguration()
            ->setApiKey('api_key', $this->apiKey);

        return new PostsApi(config: $config);
    }
}
