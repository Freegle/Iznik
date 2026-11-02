<?php

namespace App\Services\TrashNothing\Ingestion;

use App\Models\User;
use App\Models\UserAboutMe;
use App\Models\UserEmail;
use App\Models\UserReplyTime;
use App\Services\LokiService;
use App\Services\TrashNothing\Sync\PostSyncer;
use App\Services\TrashNothing\Sync\TrashNothingRateLimiter;
use App\Support\TransactionPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenAPI\Client\Configuration;

/**
 * The Freegle user behind a TN user id, creating one when Freegle has never met
 * the poster.
 *
 * users.tnuserid is the mapping, written by the partner membership-add flow, so
 * nearly every TN member who has touched Freegle already resolves. For the rest
 * TN's public API (GET /users/{id}) gives a username, and
 * username@user.trashnothing.com is a deliverable address (TN confirmed both),
 * so a reply to the post can reach them.
 *
 * It writes user data only: the user, its address, and the about-me and reply
 * time TN reports. Group membership is not its business. GroupPostIngestionService
 * gives the poster the one membership their post needs, as it does for every
 * poster, and TN's partner flow adds the rest if the member joins other
 * communities. No lastlocation either, so a new user's first post goes Pending
 * as an unmapped user.
 *
 * With $dryRun = true nothing is written. Every would-be write emits a
 * TN-SYNC-TRACE [WRITE] line, and a would-be new user comes back unsaved.
 */
class TnUserProvisioner
{
    /**
     * TN has no user with this id (404), or gives no usable username for it. TN
     * answered, so this is final: the post is skipped and the sync moves on. The answer is
     * remembered for ANSWER_TTL_MINUTES, so TN is not asked again for each of the poster's posts.
     */
    public const REASON_NOT_FOUND = 'tn-user-not-found';

    /**
     * TN could not be asked (network error, timeout, 408, 429, 5xx, a challenge, a rejected
     * key), so nothing is known about the poster. This is transient: the post sync stops
     * at the post and the next run asks again (PostSyncer::sync()), until
     * LOOKUP_GIVE_UP_AT runs have failed and the reason becomes REASON_LOOKUP_GAVE_UP.
     */
    public const REASON_LOOKUP_FAILED = 'tn-user-lookup-failed';

    /**
     * TN answered with a client error other than 404 that no retry will change (400, 410,
     * 422, 451 and the like). It is about this poster, so it is final like a 404: the post
     * is skipped and the sync moves on. Reported to Sentry once a day for each status. Remembered
     * for ANSWER_TTL_MINUTES like a 404.
     */
    public const REASON_LOOKUP_REFUSED = 'tn-user-lookup-refused';

    /**
     * The lookups for this post's poster failed LOOKUP_GIVE_UP_AT times, run after run.
     * Final: the post is skipped so that one poster cannot hold up every post behind it.
     */
    public const REASON_LOOKUP_GAVE_UP = 'tn-user-lookup-gave-up';

    /**
     * The username's address belongs to a Freegle user holding a DIFFERENT
     * tnuserid. TN says usernames are not guaranteed unique, so this is two
     * people, and neither account may be handed the other's posts.
     */
    public const REASON_USERNAME_CLASH = 'tn-username-clash';

    /**
     * The username's bare address is still held by a deleted Freegle account.
     * users_emails.email is unique, so the address cannot go to a new user, and
     * taking it back from a deleted account is not this class's decision.
     */
    public const REASON_ADDRESS_HELD_BY_DELETED_USER = 'tn-address-held-by-deleted-user';

    /**
     * A deleted Freegle account still holds this tnuserid. users.tnuserid is unique, so no
     * live account can be given it, and a post must not go to a deleted one. Deleting is a
     * decision (the member leaving, or a merge), and it is not this class's to undo by
     * taking the id back. The id is released when the account is forgotten (User::forget()
     * and UserManagementService::forgetUser() both clear it), after which the poster's next
     * post finds or makes a live account.
     */
    public const REASON_ACCOUNT_DELETED = 'tn-account-deleted';

    // MySQL ER_DUP_ENTRY.
    private const DUPLICATE_KEY = 1062;

    // A lookup holds up the whole post sync, so it gives up quickly. Laravel's defaults
    // are 30 seconds and 10.
    private const LOOKUP_TIMEOUT_SECONDS = 10;

    private const LOOKUP_CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * Failed lookups of one post's poster, counted across runs, at which Sentry first hears
     * of it. A run stops at the first post it cannot look up, so this is runs: a blip or
     * two is not worth a report.
     */
    public const LOOKUP_WARN_AT = 5;

    /**
     * Failed lookups of one post's poster after which the post is skipped for good
     * (REASON_LOOKUP_GAVE_UP). tn:sync runs every minute, so this is about half an hour.
     */
    public const LOOKUP_GIVE_UP_AT = 30;

    private const ATTEMPTS_KEY_PREFIX = 'tn-user-lookup:failed-attempts:';

    // The count outlives the half hour it takes to reach the limit, so a post read again
    // after it gave up is not given a fresh thirty runs straight away.
    private const ATTEMPTS_TTL_HOURS = 24;

    private const ANSWER_KEY_PREFIX = 'tn-user-lookup:final-answer:';

    // TN's final answer about a poster is remembered this long, so a run that meets several of
    // their posts asks once. It is short because the answer can be wrong (a bad base URL, a 404
    // from an edge, a user too new to be on TN's replica): until it expires every post by the
    // poster is skipped, and fixing the cause does not bring them back. The cost of asking
    // again is one lookup for each poster an hour, within the rate limit the posts sync shares.
    private const ANSWER_TTL_MINUTES = 60;

    // The only answers worth remembering: TN replied, and a retry would get the same reply.
    private const REMEMBERED_REASONS = [self::REASON_NOT_FOUND, self::REASON_LOOKUP_REFUSED];

    private const REJECTED_KEY_REPORTED_KEY = 'tn-user-lookup:rejected-key-reported';

    private const REFUSED_REPORTED_KEY_PREFIX = 'tn-user-lookup:refused-reported:';

    // Client errors that say nothing about the poster: 401/403 are the key (or a block),
    // 408 and 429 are TN being slow or busy. Every other 4xx is about this poster.
    private const TRANSIENT_CLIENT_ERRORS = [401, 403, 408, 429];

    private ?string $lastFailureReason = null;

    // The TN post being resolved, so a failed lookup is counted against it. Null when the
    // caller has no post (nothing is counted then).
    private ?string $tnPostId = null;

    public function __construct(
        private readonly bool $dryRun,
        private readonly bool $localTesting,
        private readonly string $publicApiKey,
        private readonly LokiService $loki,
        // Shared with the TN syncers - TN rate-limits per key, and this calls
        // the public API with the same key as the posts sync.
        private readonly ?TrashNothingRateLimiter $rateLimiter = null,
    ) {}

    /**
     * Why the last resolveOrCreate() returned null: one of the REASON_*
     * constants, or null after a success.
     */
    public function lastFailureReason(): ?string
    {
        return $this->lastFailureReason;
    }

    /**
     * @param  string|null  $tnPostId  The TN post this poster is wanted for. A failed lookup is
     *                                 counted against it, across runs, so that a poster TN keeps
     *                                 failing for stops holding up the sync (LOOKUP_GIVE_UP_AT).
     */
    public function resolveOrCreate(int $tnUserId, ?string $tnPostId = null): ?User
    {
        $this->lastFailureReason = null;
        $this->tnPostId = $tnPostId === '' ? null : $tnPostId;

        $user = $this->findByTnUserId($tnUserId);
        if ($user !== null) {
            return $user;
        }

        // Checked before TN is asked: whatever TN says, this id cannot go to a live account
        // while a deleted one holds it.
        if ($this->deletedAccountHolds($tnUserId)) {
            $this->trace($tnUserId, 'account-deleted');
            return $this->fail(self::REASON_ACCOUNT_DELETED);
        }

        $remembered = $this->rememberedAnswer($tnUserId);
        if ($remembered !== null) {
            $this->trace($tnUserId, 'remembered result=' . $remembered);
            return $this->fail($remembered);
        }

        $tnUser = $this->fetchTnUser($tnUserId);
        if ($tnUser === null) {
            // fetchTnUser() set the reason.
            return null;
        }

        $username = trim((string) ($tnUser['username'] ?? ''));
        $email    = $username === '' ? '' : User::tnEmailForUsername($username);
        if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->trace($tnUserId, 'no-usable-username');
            return $this->failFinal($tnUserId, self::REASON_NOT_FOUND);
        }

        try {
            return $this->provision($tnUserId, $username, $email, $tnUser);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== self::DUPLICATE_KEY) {
                throw $e;
            }

            return $this->afterLostRace($tnUserId, $e);
        }
    }

    private function provision(int $tnUserId, string $username, string $email, array $tnUser): ?User
    {
        $existing = $this->findExistingAccount($username, $email);

        if ($existing !== null && $existing->tnuserid !== null) {
            Log::warning('TN user provisioning: username belongs to a different TN user', [
                'tn_user_id'          => $tnUserId,
                'username'            => $username,
                'existing_user_id'    => $existing->id,
                'existing_tn_user_id' => $existing->tnuserid,
            ]);
            $this->trace($tnUserId, 'username-clash user_id=' . $existing->id);
            return $this->fail(self::REASON_USERNAME_CLASH);
        }

        // No live account to link, so a new user needs the bare address, and a row
        // already holding it can only belong to a deleted account.
        if ($existing === null && UserEmail::where('email', $email)->exists()) {
            Log::warning('TN user provisioning: the address is held by a deleted user', [
                'tn_user_id' => $tnUserId,
                'username'   => $username,
            ]);
            $this->trace($tnUserId, 'address-held-by-deleted-user');
            return $this->fail(self::REASON_ADDRESS_HELD_BY_DELETED_USER);
        }

        $user = $existing !== null
            ? $this->linkExisting($existing, $tnUserId, $email)
            : $this->create($tnUserId, $username, $email, $tnUser);

        $this->trace($tnUserId, ($existing !== null ? 'linked' : 'created') . ' user_id=' . ($user->id ?? 'dry-run'));
        $this->loki->logEvent('tn-sync', 'user-create-from-tn', [
            'tn_user_id'      => $tnUserId,
            'user_id'         => $user->id,
            'linked_existing' => $existing !== null,
        ]);

        return $user;
    }

    /**
     * A duplicate key while writing means another process got there first:
     * tn:sync and the hourly tn:verify-email-coverage backfill can provision
     * the same poster at the same moment. Its user is the answer.
     */
    private function afterLostRace(int $tnUserId, QueryException $e): ?User
    {
        // findByTnUserId() reads the primary, where the winner's row is.
        $user = $this->findByTnUserId($tnUserId);
        if ($user === null) {
            Log::warning('TN user provisioning: duplicate key without a tnuserid winner', [
                'tn_user_id' => $tnUserId,
                'error'      => $e->getMessage(),
            ]);
            return $this->lookupFailed($tnUserId);
        }

        $this->trace($tnUserId, 'lost-race user_id=' . $user->id);

        return $user;
    }

    private function trace(int $tnUserId, string $result): void
    {
        Log::info('TN-SYNC-TRACE [TN-USER] tn_user_id=' . $tnUserId . ' result=' . $result);
    }

    /**
     * Read from the primary: a poster created a moment ago, by this run or another, may
     * not have reached the replica yet. Missing them there asks TN again, tries to create
     * them and fails on the unique tnuserid index.
     */
    private function findByTnUserId(int $tnUserId): ?User
    {
        return User::where('tnuserid', $tnUserId)->whereNull('deleted')->orderBy('id')->useWritePdo()->first();
    }

    /**
     * Whether a deleted account holds this tnuserid. Read from the primary, like the live
     * lookup, so the two always agree about who holds the id.
     */
    private function deletedAccountHolds(int $tnUserId): bool
    {
        return User::where('tnuserid', $tnUserId)->whereNotNull('deleted')->useWritePdo()->exists();
    }

    private function fail(string $reason): null
    {
        $this->lastFailureReason = $reason;

        return null;
    }

    /**
     * TN's own final answer about this poster (REMEMBERED_REASONS): remembered for
     * ANSWER_TTL_MINUTES so that the same poster is not asked about again for every post they
     * make, in every sync window that overlaps. Only an answer is remembered, never a
     * failure to get one, and nothing is written by a dry run or from fixtures.
     */
    private function failFinal(int $tnUserId, string $reason): null
    {
        if (!$this->dryRun && !$this->localTesting) {
            Cache::put(self::ANSWER_KEY_PREFIX . $tnUserId, $reason, now()->addMinutes(self::ANSWER_TTL_MINUTES));
        }

        return $this->fail($reason);
    }

    private function rememberedAnswer(int $tnUserId): ?string
    {
        if ($this->localTesting) {
            return null;
        }

        $reason = Cache::get(self::ANSWER_KEY_PREFIX . $tnUserId);

        return in_array($reason, self::REMEMBERED_REASONS, true) ? $reason : null;
    }

    /**
     * TN's record for the user, or null with the reason set.
     *
     * TN answering is final. A 404 is REASON_NOT_FOUND, and a client error that no retry
     * would change (400, 410, 422, 451...) is REASON_LOOKUP_REFUSED. Anything that says
     * nothing about the poster is REASON_LOOKUP_FAILED and is retried: a network error or
     * timeout, 408, 429, 5xx, a Cloudflare challenge, a 200 that is not JSON, and the
     * key being rejected (401/403), which is also reported since every lookup fails until
     * it is fixed.
     */
    private function fetchTnUser(int $tnUserId): ?array
    {
        if ($this->localTesting) {
            $file = base_path("tests/fixtures/tn_sync/users/{$tnUserId}.json");
            if (!file_exists($file)) {
                Log::info('TN-SYNC-TRACE [TN-USER] missing fixture file=' . $file);
                return $this->fail(self::REASON_NOT_FOUND);
            }

            $payload = json_decode(file_get_contents($file), true);
            return is_array($payload) ? $payload : $this->lookupFailed($tnUserId);
        }

        ($this->rateLimiter ?? app(TrashNothingRateLimiter::class))->await();

        // The generated client has no getUser, but its host is this API's.
        $base = rtrim(Configuration::getDefaultConfiguration()->getHost(), '/');

        try {
            // The key is only accepted as ?api_key= - a header gives 401.
            $response = Http::timeout(self::LOOKUP_TIMEOUT_SECONDS)
                ->connectTimeout(self::LOOKUP_CONNECT_TIMEOUT_SECONDS)
                ->get("{$base}/users/{$tnUserId}", ['api_key' => $this->publicApiKey]);
        } catch (ConnectionException $e) {
            Log::warning('TN user provisioning: lookup failed', [
                'tn_user_id' => $tnUserId,
                'error'      => PostSyncer::redactApiKey($e->getMessage()),
            ]);
            return $this->lookupFailed($tnUserId);
        }

        // TN's errors are text/html, not JSON, so check the status before
        // decoding. A Cloudflare challenge is a block, not an answer.
        $challenged = (bool) $response->header('cf-mitigated');
        $status     = $response->status();

        if ($status === 404 && !$challenged) {
            $this->trace($tnUserId, 'not-found');
            return $this->failFinal($tnUserId, self::REASON_NOT_FOUND);
        }

        if (!$challenged && $status >= 400 && $status < 500 && !in_array($status, self::TRANSIENT_CLIENT_ERRORS, true)) {
            return $this->lookupRefused($tnUserId, $response);
        }

        $payload = $response->successful() && !$challenged
            ? $response->json()
            : null;

        if (!is_array($payload)) {
            Log::warning('TN user provisioning: lookup failed', [
                'tn_user_id'  => $tnUserId,
                'status'      => $status,
                'retry_after' => $response->header('Retry-After') ?: null,
                'body'        => PostSyncer::redactApiKey(mb_substr($response->body(), 0, 200)),
            ]);

            if (in_array($status, [401, 403], true) && !$challenged) {
                $this->reportRejectedKey($status);
            }

            return $this->lookupFailed($tnUserId);
        }

        return $payload;
    }

    /**
     * TN answered with a client error about this poster that a retry would not change. Logged
     * every time; Sentry hears of each status once a day, enough to learn that TN says it
     * without a burst of reports when a run meets several.
     */
    private function lookupRefused(int $tnUserId, Response $response): null
    {
        $status = $response->status();

        Log::warning('TN user provisioning: lookup refused', [
            'tn_user_id' => $tnUserId,
            'status'     => $status,
            'body'       => PostSyncer::redactApiKey(mb_substr($response->body(), 0, 200)),
        ]);
        $this->trace($tnUserId, 'refused status=' . $status);

        if (Cache::add(self::REFUSED_REPORTED_KEY_PREFIX . $status, true, now()->addDay())) {
            $this->report("TrashNothing refused the lookup of a poster with HTTP {$status} (TN user {$tnUserId}):"
                . ' that is final for the poster, so their posts are skipped. Reported once a day for each status');
        }

        return $this->failFinal($tnUserId, self::REASON_LOOKUP_REFUSED);
    }

    /**
     * TN could not be asked about this poster. Counted against the post across runs, because
     * the post sync stops at it and asks again next run, and a poster that always fails
     * would otherwise hold up every post behind it for ever.
     *
     * Sentry hears of it when the count reaches LOOKUP_WARN_AT, so a wedge is seen while it
     * is still short. At LOOKUP_GIVE_UP_AT the post is given up (REASON_LOOKUP_GAVE_UP):
     * skipped, and Sentry hears of that too. After that every further failure is a give-up
     * with no new report, and a lookup that works still ingests the post.
     *
     * Nothing is counted without a post, or in a dry run, which must not change the next
     * real run's count.
     */
    private function lookupFailed(int $tnUserId): null
    {
        $attempts = $this->countFailedLookup();

        if ($attempts >= self::LOOKUP_GIVE_UP_AT) {
            if ($attempts === self::LOOKUP_GIVE_UP_AT) {
                $this->report("TrashNothing post {$this->tnPostId} was skipped after its poster (TN user {$tnUserId})"
                    . ' could not be looked up in ' . self::LOOKUP_GIVE_UP_AT . ' runs: the sync moves on without it');
            }

            return $this->fail(self::REASON_LOOKUP_GAVE_UP);
        }

        if ($attempts === self::LOOKUP_WARN_AT) {
            $this->report("TrashNothing post {$this->tnPostId} has been held up for " . self::LOOKUP_WARN_AT
                . " runs because its poster (TN user {$tnUserId}) cannot be looked up: the post sync stops at the"
                . ' post, so later posts are waiting. It is skipped after ' . self::LOOKUP_GIVE_UP_AT . ' runs');
        }

        return $this->fail(self::REASON_LOOKUP_FAILED);
    }

    /**
     * This failure added to the post's count, or 0 when nothing is counted.
     */
    private function countFailedLookup(): int
    {
        if ($this->tnPostId === null || $this->dryRun) {
            return 0;
        }

        $key = self::ATTEMPTS_KEY_PREFIX . $this->tnPostId;
        Cache::add($key, 0, now()->addHours(self::ATTEMPTS_TTL_HOURS));
        $attempts = Cache::increment($key);

        // False when the entry expired between the add and the increment.
        return is_int($attempts) ? $attempts : 1;
    }

    /**
     * At most once an hour: the sync runs every minute and a rejected key fails every run.
     */
    private function reportRejectedKey(int $status): void
    {
        if (!Cache::add(self::REJECTED_KEY_REPORTED_KEY, true, now()->addHour())) {
            return;
        }

        $this->report("TrashNothing user lookup was rejected with HTTP {$status}: every poster Freegle has not"
            . ' met is waiting until the public API key is accepted. Reported at most once an hour');
    }

    private function report(string $message): void
    {
        Log::error($message);

        if (app()->bound('sentry')) {
            app('sentry')->captureMessage($message, \Sentry\Severity::error());
        }
    }

    /**
     * A live Freegle account already holding this username's address, found the
     * way IncomingMailService::findUserByEmail finds one: the exact address
     * first, then the canon, which also matches the per-group -gNNN aliases the
     * email path created.
     *
     * A canon match must also carry exactly this username, compared lowercased.
     * canonMail strips only a -g<digits> suffix now, but it used to strip
     * everything after the LAST hyphen of a TN local part, and rows written then
     * keep that canon until users:backfill-email-canon rewrites them. So a stale
     * row for someone else's "mary-jane-x@" holds canon
     * "mary-jane@usertrashnothingcom", the same as this member's "mary-jane@",
     * and without the check would be taken for this member.
     */
    private function findExistingAccount(string $username, string $email): ?User
    {
        $exact = UserEmail::where('email', $email)->first();
        if ($exact !== null) {
            $user = User::where('id', $exact->userid)->whereNull('deleted')->first();
            if ($user !== null) {
                return $user;
            }
        }

        $wanted = strtolower($username);

        $candidates = UserEmail::query()
            ->join('users', 'users.id', '=', 'users_emails.userid')
            ->where('users_emails.canon', User::canonMail($email))
            ->whereNull('users.deleted')
            ->orderBy('users_emails.userid')
            ->orderBy('users_emails.id')
            ->get(['users_emails.userid', 'users_emails.email']);

        foreach ($candidates as $row) {
            if (User::tnUsernameFromEmail($row->email) === $wanted) {
                return User::find($row->userid);
            }
        }

        return null;
    }

    /**
     * Stamp the tnuserid on an account the email path created for this member,
     * rather than minting a twin. Mirrors EnsurePartnerIdentifiers in
     * iznik-server-go/user/partner.go. The bare address is attached as a
     * non-preferred one, so later exact-match lookups hit and the member's
     * current preferred address stays. changeprimary: false, because the
     * address may be the one already held and preferred, and addEmail would
     * otherwise demote it, leaving no preferred address (isTN() false).
     *
     * The address is left off when another account holds it, a deleted one: the
     * stamp is the point, and users_emails.email is unique.
     */
    private function linkExisting(User $user, int $tnUserId, string $email): User
    {
        Log::info('TN-SYNC-TRACE [WRITE] table=users op=update where=id=' . $user->id . ' set=tnuserid=' . $tnUserId);

        $addressTaken = UserEmail::where('email', $email)->where('userid', '!=', $user->id)->exists();

        if ($this->dryRun) {
            if (!$addressTaken) {
                $user->addEmail($email, primary: 0, changeprimary: false, dryRun: true);
            }

            return $user;
        }

        TransactionPolicy::atomic(function () use ($user, $tnUserId, $email, $addressTaken) {
            $user->tnuserid = $tnUserId;
            $user->save();

            if (!$addressTaken) {
                $user->addEmail($email, primary: 0, changeprimary: false);
            }
        }, 'TN user link: the tnuserid stamp and the bare address go together');

        return $user;
    }

    private function create(int $tnUserId, string $username, string $email, array $tnUser): User
    {
        $attributes = [
            'fullname'     => User::tnDisplayName($username),
            'systemrole'   => 'User',
            'added'        => now(),
            'lastaccess'   => now(),
            'tnuserid'     => $tnUserId,
            // Unmapped by decision, and set explicitly because the caller reads
            // it: a model built from attributes has no key for an unset column,
            // so $user->lastlocation would resolve the lastLocation() relation
            // and throw instead of giving null.
            'lastlocation' => null,
        ];
        foreach (['firstname', 'lastname'] as $field) {
            $value = trim((string) ($tnUser[$field] ?? ''));
            if ($value !== '') {
                $attributes[$field] = $value;
            }
        }

        $aboutMe = isset($tnUser['about_me']) ? (string) $tnUser['about_me'] : null;
        // isset() not !empty(): a reply time of 0 is legitimate.
        $replyTime = isset($tnUser['reply_time']) ? (int) $tnUser['reply_time'] : null;

        Log::info('TN-SYNC-TRACE [WRITE] table=users op=insert set=tnuserid=' . $tnUserId . ',fullname=' . $attributes['fullname']);

        if ($this->dryRun) {
            // Not addEmail(dryRun: true): it reads $this->id, which an unsaved
            // user does not have (assignUserToToDonation takes an int).
            $user = new User($attributes);
            Log::info('TN-SYNC-TRACE [WRITE] table=users_emails op=insert where=userid=new set=email=' . $email . ',preferred=1');
            $this->traceAboutMeAndReplyTime($user, $aboutMe, $replyTime);
            return $user;
        }

        return TransactionPolicy::atomic(function () use ($attributes, $email, $aboutMe, $replyTime) {
            $user = User::create($attributes);
            $user->addEmail($email);
            $this->traceAboutMeAndReplyTime($user, $aboutMe, $replyTime);

            if ($aboutMe !== null) {
                UserAboutMe::create(['userid' => $user->id, 'timestamp' => now(), 'text' => $aboutMe]);
            }
            if ($replyTime !== null) {
                UserReplyTime::create(['userid' => $user->id, 'replytime' => $replyTime, 'timestamp' => now()]);
            }

            return $user;
        }, 'TN user create: the user, its address, about-me and reply time exist together');
    }

    private function traceAboutMeAndReplyTime(User $user, ?string $aboutMe, ?int $replyTime): void
    {
        if ($aboutMe !== null) {
            Log::info('TN-SYNC-TRACE [WRITE] table=users_aboutme op=insert where=userid=' . $user->id . ' set=text=len=' . strlen($aboutMe));
        }
        if ($replyTime !== null) {
            Log::info('TN-SYNC-TRACE [WRITE] table=users_replytime op=insert where=userid=' . $user->id . ' set=replytime=' . $replyTime);
        }
    }
}
