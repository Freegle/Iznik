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
     * answered, so this is final: the post is skipped and the sync moves on.
     */
    public const REASON_NOT_FOUND = 'tn-user-not-found';

    /**
     * TN could not be asked (network error, timeout, 5xx, 429, a challenge, a rejected
     * key), so nothing is known about the poster. This is transient: the post sync
     * stops at it and the next run asks again (PostSyncer::sync()).
     */
    public const REASON_LOOKUP_FAILED = 'tn-user-lookup-failed';

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

    // MySQL ER_DUP_ENTRY.
    private const DUPLICATE_KEY = 1062;

    // A lookup holds up the whole post sync, so it gives up quickly. Laravel's defaults
    // are 30 seconds and 10.
    private const LOOKUP_TIMEOUT_SECONDS = 10;

    private const LOOKUP_CONNECT_TIMEOUT_SECONDS = 5;

    // Consecutive runs that failed to ask TN before Sentry hears of it. A run stops at its
    // first failure, so a streak counts runs. A blip or two is not worth a report.
    private const FAILURE_STREAK_REPORT_AT = 3;

    private const FAILURE_STREAK_CACHE_KEY = 'tn-user-lookup:failure-streak';

    private const FAILURE_STREAK_TTL_HOURS = 24;

    private ?string $lastFailureReason = null;

    // Each reported at most once per instance, which is once per run.
    private bool $rejectedKeyReported = false;

    private bool $failureCountedThisRun = false;

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

    public function resolveOrCreate(int $tnUserId): ?User
    {
        $this->lastFailureReason = null;

        $user = $this->findByTnUserId($tnUserId);
        if ($user !== null) {
            return $user;
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
            return $this->fail(self::REASON_NOT_FOUND);
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
            return $this->fail(self::REASON_LOOKUP_FAILED);
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
        return User::where('tnuserid', $tnUserId)->orderBy('id')->useWritePdo()->first();
    }

    private function fail(string $reason): null
    {
        $this->lastFailureReason = $reason;

        return null;
    }

    /**
     * TN's record for the user, or null with the reason set.
     *
     * A 404 is REASON_NOT_FOUND: TN answered, and the answer is no. Anything else that is
     * not a 200 with a JSON body is REASON_LOOKUP_FAILED, because it says nothing about
     * the user. A key TN rejects (401/403) is reported, since every lookup will fail
     * until it is fixed.
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
            return is_array($payload) ? $payload : $this->fail(self::REASON_LOOKUP_FAILED);
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
            return $this->lookupFailed();
        }

        // TN's errors are text/html, not JSON, so check the status before
        // decoding. A Cloudflare challenge is a block, not an answer.
        $challenged = (bool) $response->header('cf-mitigated');

        if ($response->status() === 404 && !$challenged) {
            $this->lookupAnswered();
            $this->trace($tnUserId, 'not-found');
            return $this->fail(self::REASON_NOT_FOUND);
        }

        $payload = $response->successful() && !$challenged
            ? $response->json()
            : null;

        if (!is_array($payload)) {
            Log::warning('TN user provisioning: lookup failed', [
                'tn_user_id'  => $tnUserId,
                'status'      => $response->status(),
                'retry_after' => $response->header('Retry-After') ?: null,
                'body'        => PostSyncer::redactApiKey(mb_substr($response->body(), 0, 200)),
            ]);

            if (in_array($response->status(), [401, 403], true) && !$challenged) {
                $this->reportRejectedKey($response->status());
            }

            return $this->lookupFailed();
        }

        $this->lookupAnswered();

        return $payload;
    }

    /**
     * TN could not be asked. Counts toward the failing streak, once per run.
     */
    private function lookupFailed(): null
    {
        if (!$this->failureCountedThisRun) {
            $this->failureCountedThisRun = true;

            Cache::add(self::FAILURE_STREAK_CACHE_KEY, 0, now()->addHours(self::FAILURE_STREAK_TTL_HOURS));
            $streak = Cache::increment(self::FAILURE_STREAK_CACHE_KEY);

            if ($streak === self::FAILURE_STREAK_REPORT_AT) {
                $this->report('TrashNothing user lookup has failed in ' . self::FAILURE_STREAK_REPORT_AT
                    . ' consecutive runs: the post sync stops at the first poster it cannot look up, so'
                    . ' posts from members Freegle has not met are waiting');
            }
        }

        return $this->fail(self::REASON_LOOKUP_FAILED);
    }

    /**
     * TN answered, whatever the answer was, so it is not failing.
     */
    private function lookupAnswered(): void
    {
        Cache::forget(self::FAILURE_STREAK_CACHE_KEY);
    }

    private function reportRejectedKey(int $status): void
    {
        if ($this->rejectedKeyReported) {
            return;
        }
        $this->rejectedKeyReported = true;

        $this->report("TrashNothing user lookup was rejected with HTTP {$status}: every poster Freegle has not"
            . ' met is waiting until the public API key is accepted');
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
