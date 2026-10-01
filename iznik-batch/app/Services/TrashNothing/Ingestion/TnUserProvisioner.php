<?php

namespace App\Services\TrashNothing\Ingestion;

use App\Models\User;
use App\Models\UserAboutMe;
use App\Models\UserEmail;
use App\Models\UserReplyTime;
use App\Services\LokiService;
use App\Services\TrashNothing\Sync\PostSyncer;
use App\Services\TrashNothing\Sync\TrashNothingRateLimiter;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
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
 * The user gets the user-level fields the two existing creation paths write -
 * IncomingMailService::handleSubscribe and Go's CreatePartnerUser - and none of
 * their membership side effects: no memberships row, no memberships_history
 * (so no welcome mail or digests), no Group/Joined log, no reach queue. TN's
 * partner sync adds the membership if and when the member joins. No
 * lastlocation either, so a new user's first post goes Pending as an
 * unmapped user.
 *
 * With $dryRun = true nothing is written. Every would-be write emits a
 * TN-SYNC-TRACE [WRITE] line, and a would-be new user comes back unsaved.
 */
class TnUserProvisioner
{
    /** TN has no user with this id, or gives no usable username for it. */
    public const REASON_NOT_FOUND = 'tn-user-not-found';

    /** TN could not be asked (5xx, 429, timeout, challenge). */
    public const REASON_LOOKUP_FAILED = 'tn-user-lookup-failed';

    /**
     * The username's address belongs to a Freegle user holding a DIFFERENT
     * tnuserid. TN says usernames are not guaranteed unique, so this is two
     * people, and neither account may be handed the other's posts.
     */
    public const REASON_USERNAME_CLASH = 'tn-username-clash';

    // MySQL ER_DUP_ENTRY.
    private const DUPLICATE_KEY = 1062;

    private ?string $lastFailureReason = null;

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

    private function findByTnUserId(int $tnUserId): ?User
    {
        return User::where('tnuserid', $tnUserId)->orderBy('id')->first();
    }

    private function fail(string $reason): null
    {
        $this->lastFailureReason = $reason;

        return null;
    }

    /**
     * TN's record for the user, or null with the reason set: a 404 is
     * REASON_NOT_FOUND, anything else that is not a 200 with a JSON body is
     * REASON_LOOKUP_FAILED.
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
            $response = Http::get("{$base}/users/{$tnUserId}", ['api_key' => $this->publicApiKey]);
        } catch (ConnectionException $e) {
            Log::warning('TN user provisioning: lookup failed', [
                'tn_user_id' => $tnUserId,
                'error'      => PostSyncer::redactApiKey($e->getMessage()),
            ]);
            return $this->fail(self::REASON_LOOKUP_FAILED);
        }

        // TN's errors are text/html, not JSON, so check the status before
        // decoding. A Cloudflare challenge is a block, not an answer.
        if ($response->status() === 404) {
            $this->trace($tnUserId, 'not-found');
            return $this->fail(self::REASON_NOT_FOUND);
        }

        $payload = $response->successful() && !$response->header('cf-mitigated')
            ? $response->json()
            : null;

        if (!is_array($payload)) {
            Log::warning('TN user provisioning: lookup failed', [
                'tn_user_id'  => $tnUserId,
                'status'      => $response->status(),
                'retry_after' => $response->header('Retry-After') ?: null,
                'body'        => PostSyncer::redactApiKey(mb_substr($response->body(), 0, 200)),
            ]);
            return $this->fail(self::REASON_LOOKUP_FAILED);
        }

        return $payload;
    }

    /**
     * A live Freegle account already holding this username's address, found
     * the way IncomingMailService::findUserByEmail finds one: the exact address
     * first, then the canon, which also matches the per-group -gNNN aliases the
     * email path created.
     *
     * A canon match must also carry exactly this username. Today's canonMail
     * strips everything after the LAST hyphen of a TN local part, so the canon
     * of "mary-jane@" is "mary@", and without the check a "mary-g12@" alias
     * belonging to someone else would be taken for this member.
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
     * current preferred address stays.
     */
    private function linkExisting(User $user, int $tnUserId, string $email): User
    {
        Log::info('TN-SYNC-TRACE [WRITE] table=users op=update where=id=' . $user->id . ' set=tnuserid=' . $tnUserId);

        if ($this->dryRun) {
            $user->addEmail($email, primary: 0, dryRun: true);
            return $user;
        }

        DB::transaction(function () use ($user, $tnUserId, $email) {
            $user->tnuserid = $tnUserId;
            $user->save();
            $user->addEmail($email, primary: 0);
        });

        return $user;
    }

    private function create(int $tnUserId, string $username, string $email, array $tnUser): User
    {
        $attributes = [
            'fullname'   => User::tnDisplayName($username),
            'systemrole' => 'User',
            'added'      => now(),
            'lastaccess' => now(),
            'tnuserid'   => $tnUserId,
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

        return DB::transaction(function () use ($attributes, $email, $aboutMe, $replyTime) {
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
        });
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
