<?php

namespace App\Services\TrashNothing\Sync;

use App\Models\Location;
use App\Models\User;
use App\Models\UserAboutMe;
use App\Models\UserEmail;
use App\Models\UserReplyTime;
use App\Services\LokiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UserChangesSyncer
{
    use ReadsTnFeedByDay;

    private const PAGE_SIZE = 100;

    // The fields of a change that are applied by comparing with what the member has now, so
    // that applying an older change after a newer one would undo it. The date of the latest
    // change applied for each is kept per member, and a change older than that is skipped.
    private const FIELD_USERNAME = 'username';
    private const FIELD_LOCATION = 'location';

    // tn:sync reads a window more than once: it overlaps the one before, and it holds its stored
    // date while a feed cannot be read. The kept dates have to last as long as that.
    private const APPLIED_KEY_PREFIX = 'tn-sync:change-applied:';
    private const APPLIED_TTL_HOURS = 24;

    // Whether the last sync() stopped because TN's user changes feed could not be read.
    private bool $fetchFailed = false;

    public function __construct(
        private readonly bool $dryRun,
        private readonly bool $localTesting,
        private readonly string $apiKey,
        private readonly string $apiBaseUrl,
        private readonly LokiService $loki,
        // Shared with the other TN syncers — TN rate-limits per API key and one
        // tn:sync run calls three endpoints with the same key, so a throttle
        // that only paces this class's own requests protects nothing.
        private readonly ?TrashNothingRateLimiter $rateLimiter = null,
        // Log each response whole, to see what TN is answering. Off unless asked for: a body names
        // members, and tn:sync reads this feed on a schedule.
        private readonly bool $logResponses = false,
    ) {}

    /**
     * Whether the last sync() stopped at a page it could not fetch. Some of the user changes after
     * it were never seen, and the feed's order is TN's, so the page's position is not known:
     * the caller must not store a sync date this run, or the next run starts after them.
     */
    public function fetchFailed(): bool
    {
        return $this->fetchFailed;
    }

    /**
     * @return array{int, string|null} [count, maxDate]
     */
    public function sync(string $from, string $to): array
    {
        $count   = 0;
        $maxDate = null;

        foreach ($this->pages($from, $to) as $page => $changes) {
            Log::info('TN-SYNC-TRACE [CHANGES-PAGE] page=' . $page . ' count=' . count($changes));

            foreach ($changes as $change) {
                $count++;

                if (!$maxDate || $change['date'] > $maxDate) {
                    $maxDate = $change['date'];
                }

                try {
                    $match = TnMemberMatch::find((int) ($change['fd_user_id'] ?? 0), (int) ($change['tn_user_id'] ?? 0));
                    $user = $this->resolveMember($change, $match);
                    if ($user === null) {
                        continue;
                    }

                    if (!empty($change['account_removed'])) {
                        if ($this->holdsTwoUsernames($user)) {
                            $this->skipChange('tn-two-usernames', $change, $user->id, (int) $user->tnuserid);
                            continue;
                        }

                        Log::info("FD #{$user->id} TN account removed");
                        Log::info('TN-SYNC-TRACE [USER-CHANGE] fd_user_id=' . $user->id . ' action=account-removed');
                        $user->forget('TN account removed', $this->dryRun);
                        $this->loki->logEvent('tn-sync', 'user-forget', ['user_id' => $user->id]);

                        // Only now the removal has been applied: before, it could still be skipped or fail.
                        if ($match->heldElsewhere()) {
                            $this->reportNamedAccountLeft($change, $match);
                        }
                        continue;
                    }

                    // isset() not !empty(): a reply time of 0 is legitimate.
                    if (isset($change['reply_time'])) {
                        $replyTime = UserReplyTime::firstOrNew(['userid' => $user->id]);
                        $isNew = !$replyTime->exists;
                        $replyTime->replytime = $change['reply_time'];
                        $replyTime->timestamp = $change['date'];
                        Log::info('TN-SYNC-TRACE [WRITE] table=users_replytime op=replace where=userid=' . $user->id . ' set=replytime=' . $change['reply_time'] . ',timestamp=' . $change['date']);
                        if (!$this->dryRun) {
                            $replyTime->save();
                        }
                        $this->loki->logEvent('tn-sync', 'user-reply-time-upsert', [
                            'action'  => $isNew ? 'insert' : 'update',
                            'user_id' => $user->id,
                        ]);
                    }

                    // isset() not !empty(): an empty string means the member cleared their bio.
                    if (isset($change['about_me'])) {
                        try {
                            $aboutMe = UserAboutMe::firstOrNew(['userid' => $user->id]);
                            $isNew = !$aboutMe->exists;
                            $aboutMe->timestamp = $change['date'];
                            $aboutMe->text      = $change['about_me'];
                            Log::info('TN-SYNC-TRACE [WRITE] table=users_aboutme op=replace where=userid=' . $user->id . ' set=timestamp=' . $change['date'] . ',text=len=' . strlen($change['about_me']));
                            if (!$this->dryRun) {
                                $aboutMe->save();
                            }
                            $this->loki->logEvent('tn-sync', 'user-about-me-upsert', [
                                'action'  => $isNew ? 'insert' : 'update',
                                'user_id' => $user->id,
                            ]);
                        } catch (\Exception $e) {
                            if (function_exists('\Sentry\captureException')) {
                                \Sentry\captureException($e);
                            }
                        }
                    }

                    if (!empty($change['username'])) {
                        if ($this->superseded(self::FIELD_USERNAME, $user->id, $change['date'])) {
                            $this->traceSuperseded($user->id, self::FIELD_USERNAME);
                        } else {
                            $this->applyUsername($user, $change['username']);
                            $this->markApplied(self::FIELD_USERNAME, $user->id, $change['date']);
                        }
                    }

                    $movedTo = NULL;

                    $applyLocation = !empty($change['location']);
                    if ($applyLocation && $this->superseded(self::FIELD_LOCATION, $user->id, $change['date'])) {
                        $this->traceSuperseded($user->id, self::FIELD_LOCATION);
                        $applyLocation = false;
                    }

                    if ($applyLocation) {
                        $lat = $change['location']['latitude'] ?? null;
                        $lng = $change['location']['longitude'] ?? null;

                        if ($lat !== null && $lng !== null) {
                            $this->markApplied(self::FIELD_LOCATION, $user->id, $change['date']);
                            $loc = Location::closestPostcode((float) $lat, (float) $lng);

                            if ($loc && $loc->id !== $user->lastlocation) {
                                Log::info("FD #{$user->id} TN lat/lng {$lat},{$lng} has changed  => {$loc->id} {$loc->name}");
                                Log::info('TN-SYNC-TRACE [LOCATION] fd_user_id=' . $user->id . ' lat=' . $lat . ' lng=' . $lng . ' old_loc=' . $user->lastlocation . ' new_loc=' . $loc->id);
                                $user->lastlocation = $loc->id;
                                $movedTo = $loc->name;
                            }

                            // TN is the master for a TN member's location. settings.mylocation
                            // is read before lastlocation almost everywhere, and on TN accounts
                            // it is stale V1 data from before the account was linked to TN, so
                            // drop it and let lastlocation stand.
                            $settings = $user->settings;
                            if (is_array($settings) && array_key_exists('mylocation', $settings)) {
                                Log::info('TN-SYNC-TRACE [WRITE] table=users op=update where=id=' . $user->id . ' set=settings.mylocation=removed');
                                unset($settings['mylocation']);
                                $user->settings = $settings;
                            }
                        }
                    }

                    if (!$this->dryRun) {
                        $user->save();

                        // The same member log row a postcode change in Settings writes, so
                        // moderators can see a TN member's moves (Discourse 10277).
                        if ($movedTo !== NULL) {
                            DB::table('logs')->insert([
                                'timestamp' => now(),
                                'type'      => 'User',
                                'subtype'   => 'PostcodeChange',
                                'user'      => $user->id,
                                'byuser'    => $user->id,
                                'text'      => $movedTo,
                            ]);
                        }
                    }
                    $this->loki->logEvent('tn-sync', 'user-update', ['user_id' => $user->id]);
                    Log::info('TN-SYNC-TRACE [USER-CHANGE] fd_user_id=' . $user->id . ' action=processed');

                } catch (\Exception $e) {
                    // Not $user: finding the member is what may have failed.
                    Log::info('TN-SYNC-TRACE [USER-CHANGE] fd_user_id=' . ($change['fd_user_id'] ?? 'null') . ' action=error');
                    Log::error('TN sync: user changes sync failed', [
                        'error'  => $e->getMessage(),
                        'change' => $change,
                    ]);
                    if (function_exists('\Sentry\captureException')) {
                        \Sentry\captureException($e);
                    }
                }
            }
        }

        return [$count, $maxDate];
    }

    /**
     * Whether a change to this field is older than one already applied for the member. Changes
     * are not always seen in the order they were made, and a window is read more than once, so
     * a member's two changes to the same field come round together: applying them in turn each
     * time would write the first, then the second, over and over (a member log row for every
     * location, an address created and deleted for every username) when only the later one
     * stands. The same change coming round again is not older than itself, and is applied as
     * before, when it finds nothing to change.
     */
    private function superseded(string $field, int $userId, string $date): bool
    {
        $time   = strtotime($date);
        $latest = Cache::get(self::APPLIED_KEY_PREFIX . $field . ':' . $userId);

        return $time !== false && is_int($latest) && $time < $latest;
    }

    /**
     * Keep the date of the latest change applied to this field for the member. A dry run applies
     * nothing, so it keeps nothing.
     */
    private function markApplied(string $field, int $userId, string $date): void
    {
        $time = strtotime($date);
        if ($this->dryRun || $time === false) {
            return;
        }

        $key    = self::APPLIED_KEY_PREFIX . $field . ':' . $userId;
        $latest = Cache::get($key);

        if (!is_int($latest) || $time > $latest) {
            Cache::put($key, $time, now()->addHours(self::APPLIED_TTL_HOURS));
        }
    }

    private function traceSuperseded(int $userId, string $field): void
    {
        Log::info('TN-SYNC-TRACE [USER-CHANGE] fd_user_id=' . $userId . ' action=superseded field=' . $field);
    }

    /**
     * Apply the username from a change event. The old username is read from the
     * member's preferred TN address, not from fullname: fullname is the display name
     * ("Tricia Hayes", as a provisioned or partner-created member has it), so comparing
     * it with the raw username made every event look like a rename.
     *
     * A rename moves every address of the old username, bare and per-group alias alike,
     * to the new one, keeping each address's group suffix and its preferred flag. The
     * caller saves $user.
     *
     * An account holding two TN usernames is left alone. Its preferred address may be the other
     * member's, and every change repeats the member's username, so even a change that renames
     * nobody would rewrite those addresses. A member whose new alias arrived before the change
     * looks the same, and is reported for a person to tidy up.
     */
    private function applyUsername(User $user, string $newUsername): void
    {
        $oldUsername = User::tnUsernameFromEmail($user->email_preferred ?? '');

        if ($oldUsername === null || $oldUsername === strtolower($newUsername)) {
            return;
        }

        if ($this->holdsTwoUsernames($user)) {
            $this->renameBlocked($user, $oldUsername, $newUsername, 'two-usernames',
                "TN rename of user {$user->id} to {$newUsername} not applied: the account holds two TN usernames");

            return;
        }

        // Each old address keeps whatever follows the username ("-g123@user.trashnothing.com"
        // or "@user.trashnothing.com").
        $renames = [];
        foreach ($user->emails()->get(['email', 'preferred']) as $row) {
            if (User::tnUsernameFromEmail($row->email) === $oldUsername) {
                $renames[] = [$row->email, $newUsername . substr(trim($row->email), strlen($oldUsername)), (int) $row->preferred];
            }
        }

        // The username becomes part of an address, so it has to make a valid one, as the
        // provisioner requires of the same username. Nothing is written otherwise.
        foreach ($renames as [, $newEmail]) {
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $this->renameBlocked($user, $oldUsername, $newUsername, 'invalid-address=' . $newEmail,
                    "TN rename of user {$user->id} to {$newEmail} is not a valid address");

                return;
            }
        }

        // users_emails.email is UNIQUE, so an address another member holds cannot be
        // added. Change nothing rather than throw: the exception would also stop this
        // change row's location and reply time being applied.
        foreach ($renames as [, $newEmail]) {
            $holder = UserEmail::where('email', $newEmail)->where('userid', '!=', $user->id)->value('userid');
            if ($holder) {
                $this->renameBlocked($user, $oldUsername, $newUsername, 'email-clash=' . $newEmail . ' held_by=' . $holder,
                    "TN rename of user {$user->id} to {$newEmail} clashes with user {$holder}");

                return;
            }
        }

        Log::info("Name change for {$user->id} {$oldUsername} => {$newUsername}");
        Log::info('TN-SYNC-TRACE [NAME-CHANGE] fd_user_id=' . $user->id . ' old=' . $oldUsername . ' new=' . $newUsername);
        $user->fullname = User::tnDisplayName($newUsername);

        // Add before removing, so the member is never left without a preferred TN
        // address: isTN() reads it, and this sync skips members without one.
        foreach ($renames as [$oldEmail, $newEmail, $preferred]) {
            $user->addEmail($newEmail, primary: $preferred, dryRun: $this->dryRun);
            $user->removeEmail($oldEmail, $this->dryRun);
            Log::info("...{$oldEmail} => {$newEmail}");
            $this->loki->logEvent('tn-sync', 'user-email-rename', [
                'user_id'   => $user->id,
                'old_email' => $oldEmail,
                'new_email' => $newEmail,
            ]);
        }
    }

    /**
     * A rename that cannot be applied. Every change event for the member carries their
     * username, so the same rename is met again on each later event: it is logged each
     * time and reported to Sentry once per member per day.
     */
    private function renameBlocked(User $user, string $oldUsername, string $newUsername, string $why, string $report): void
    {
        Log::info('TN-SYNC-TRACE [NAME-CHANGE] fd_user_id=' . $user->id . ' old=' . $oldUsername . ' new=' . $newUsername . ' ' . $why);

        if (Cache::add('tn-sync:rename-blocked:' . $user->id, true, now()->addDay()) && app()->bound('sentry')) {
            app('sentry')->captureMessage($report);
        }
    }

    /**
     * The Freegle account a change from TN's user-changes feed is about, or null to skip it.
     * TnMemberMatch says which account TN means, so that a member's removal and renames reach
     * them when TN has no Freegle id for them, or an out of date one. The rest is for this feed.
     *
     * The account holding TN's id is the member, whatever account TN names. A partner call that
     * moves an address off an account with no id leaves things this way, until TN learns the new
     * account. The account named is not changed, and is logged here: it may be the member's old
     * one, and a removal does not reach it. sync() reports that once the removal has been applied
     * to the holder, because it can still be skipped there.
     *
     * Only a TN account is changed. When TN names one holding a different TN user id and no account
     * holds this one, the change is about another TN member sharing that account, and applying it
     * would forget or rename the wrong person, so it is skipped and reported. An account with no
     * TN user id is given this one, since TN has just said which member it is. Not while it holds
     * two TN usernames, as the id could be either member's, and not a deleted one, which would
     * keep the unique id from any other account until it is forgotten.
     */
    private function resolveMember(array $change, TnMemberMatch $match): ?User
    {
        $tnId  = (int) ($change['tn_user_id'] ?? 0);
        $named = $match->named;

        if ($match->heldElsewhere()) {
            $this->traceSkip('tn-user-id-held-elsewhere', $change, $named->id, $match->holder->id);
        }

        $user = $match->account();
        if ($user === null || !$this->isTnAccount($user)) {
            if ($named !== null && !$match->heldElsewhere() && $this->isTnAccount($named)) {
                $this->skipChange('tn-user-id-mismatch', $change, $named->id, (int) $named->tnuserid);
            }

            return null;
        }

        if ($user->tnuserid === null && $tnId !== 0 && $user->deleted === null) {
            if ($this->holdsTwoUsernames($user)) {
                Log::info('TN-SYNC-TRACE [CHANGE-SKIP] reason=tn-two-usernames field=tnuserid fd_user_id=' . $user->id . ' tn_user_id=' . $tnId);

                return $user;
            }

            $this->stampTnId($user, $tnId);
        }

        return $user;
    }

    /**
     * An account with a TN address, or one holding a TN id with no address left, because another
     * member can take an address and the id still says whose account it is. Not one with a Freegle
     * address: leaving TN says nothing about that.
     */
    private function isTnAccount(User $user): bool
    {
        return $user->isTN() || ($user->tnuserid !== null && $user->email_preferred === null);
    }

    private function stampTnId(User $user, int $tnId): void
    {
        Log::info('TN-SYNC-TRACE [WRITE] table=users op=update where=id=' . $user->id . ' set=tnuserid=' . $tnId);
        if (!$this->dryRun) {
            DB::table('users')->where('id', $user->id)->whereNull('tnuserid')->update(['tnuserid' => $tnId]);
            $user->tnuserid = $tnId;
            $user->syncOriginalAttribute('tnuserid');
        }
    }

    /**
     * Give accounts their TN user id from the feed, and do nothing else with it. A change names an
     * account by both ids, which is TN saying which member it is with no address or username
     * involved, so this settles accounts with no TN id before anything has to split them from
     * their member. It reads old changes, so it applies none: a removal would be months late.
     *
     * An id is unique and hard to take back, so anything that casts doubt on one leaves the account
     * for a person, and logs its ids so they can find it. It reads the whole window before writing,
     * because a later page can do that, and writes nothing if it cannot read every page.
     *
     * @return array<string, int> how many accounts came to each outcome
     */
    public function backfillTnUserIds(string $from, string $to): array
    {
        $counts = [
            'stamped'           => 0,
            'already-has-an-id' => 0,
            'deleted'           => 0,
            'two-tn-ids'        => 0,
            'two-accounts'      => 0,
            'held-elsewhere'    => 0,
            'two-usernames'     => 0,
            'no-tn-address'     => 0,
            'not-a-tn-account'  => 0,
            'username-differs'  => 0,
        ];

        $named = $this->tnIdsNamedPerAccount($from, $to);
        if ($named === null) {
            return $counts;
        }

        $accountsById = [];
        foreach ($named as $fdId => $tnIds) {
            foreach (array_keys($tnIds) as $tnId) {
                $accountsById[$tnId][] = $fdId;
            }
        }

        foreach ($named as $fdId => $tnIds) {
            $user = User::find($fdId);
            if ($user !== null) {
                $outcome = $this->backfillOne($user, $tnIds, $accountsById);
                $counts[$outcome]++;

                if (!in_array($outcome, ['stamped', 'already-has-an-id'], true)) {
                    Log::info("TN-SYNC-TRACE [BACKFILL-LEFT] reason={$outcome} fd_user_id={$fdId} tn_user_ids=" . implode(',', array_keys($tnIds)));
                }
            }
        }

        return $counts;
    }

    /**
     * @return array<int, array<int, array<string, true>>>|null account => TN id => the usernames the
     *                                                         feed gave, or null if a page failed
     */
    private function tnIdsNamedPerAccount(string $from, string $to): ?array
    {
        $named = [];

        foreach ($this->pages($from, $to) as $changes) {
            foreach ($changes as $change) {
                $fdId = (int) ($change['fd_user_id'] ?? 0);
                $tnId = (int) ($change['tn_user_id'] ?? 0);

                if ($fdId === 0 || $tnId === 0) {
                    continue;
                }

                $named[$fdId][$tnId] ??= [];
                if (!empty($change['username'])) {
                    $named[$fdId][$tnId][strtolower((string) $change['username'])] = true;
                }
            }
        }

        return $this->fetchFailed ? null : $named;
    }

    /**
     * @param  array<int, array<string, true>>  $tnIds  the TN ids the feed named on this account
     * @param  array<int, list<int>>  $accountsById  the accounts the feed named each TN id on
     * @return string the outcome, as a key of backfillTnUserIds()'s counts
     */
    private function backfillOne(User $user, array $tnIds, array $accountsById): string
    {
        if ($user->tnuserid !== null) {
            return 'already-has-an-id';
        }

        if ($user->deleted !== null) {
            return 'deleted';
        }

        if (count($tnIds) > 1) {
            return 'two-tn-ids';
        }

        $tnId = array_key_first($tnIds);

        if (User::whereIn('id', $accountsById[$tnId])->count() > 1) {
            return 'two-accounts';
        }

        if (TnMemberMatch::find($user->id, $tnId)->heldElsewhere()) {
            return 'held-elsewhere';
        }

        $usernames = $user->tnUsernames();
        if (count($usernames) !== 1) {
            return $usernames === [] ? 'no-tn-address' : 'two-usernames';
        }

        if (!$user->isTN()) {
            return 'not-a-tn-account';
        }

        // A username may only refuse: a feed row with none is no evidence either way.
        if ($tnIds[$tnId] !== [] && !isset($tnIds[$tnId][$usernames[0]])) {
            return 'username-differs';
        }

        $this->stampTnId($user, $tnId);

        return 'stamped';
    }

    /** Whether the account holds addresses for two TN usernames: see User::tnUsernames(). */
    private function holdsTwoUsernames(User $user): bool
    {
        return count($user->tnUsernames()) > 1;
    }

    /**
     * Leave a change unapplied and say so. The sync date moves past it regardless, so a removal
     * skipped here is not retried: the member has left TN and their data stays until a person
     * acts on the report. A window is read more than once, so it is reported once a day.
     */
    private function skipChange(string $reason, array $change, int $userId, int $other): void
    {
        $this->traceSkip($reason, $change, $userId, $other);

        if (!empty($change['account_removed'])) {
            $tnId = (int) ($change['tn_user_id'] ?? 0);
            $this->reportRemoval("{$reason}:{$userId}:{$tnId}",
                "TN member {$tnId} has left, but their removal was not applied to user {$userId} ({$reason}, {$other})");
        }
    }

    /** Log that a change was not applied to this account, without reporting it. */
    private function traceSkip(string $reason, array $change, int $userId, int $other): void
    {
        $tnId = (int) ($change['tn_user_id'] ?? 0);

        Log::warning("TN sync: change for TN user {$tnId} not applied to FD #{$userId} ({$reason}, {$other})");
        Log::info("TN-SYNC-TRACE [CHANGE-SKIP] reason={$reason} fd_user_id={$userId} tn_user_id={$tnId} other={$other}");
        $this->loki->logEvent('tn-sync', 'user-change-skip', [
            'reason'     => $reason,
            'user_id'    => $userId,
            'tn_user_id' => $tnId,
            'other'      => $other,
        ]);
    }

    /**
     * A removal has been applied to the account holding the member's TN id. The account TN named
     * was left alone, and it may be the member's old one, so a person should look at it.
     */
    private function reportNamedAccountLeft(array $change, TnMemberMatch $match): void
    {
        $tnId = (int) ($change['tn_user_id'] ?? 0);

        $this->reportRemoval("tn-user-id-held-elsewhere:{$match->named->id}:{$tnId}",
            "TN member {$tnId} has left and was removed from user {$match->holder->id}, which holds their TN id; user {$match->named->id}, which TN named, was left alone and may be their old account");
    }

    /**
     * Tell Sentry about a removal, once a day per key. Keyed by reason too: a held-elsewhere report
     * must not use up a real skip's for the day. A dry run applies nothing, so it reports nothing.
     */
    private function reportRemoval(string $key, string $message): void
    {
        if (!$this->dryRun && Cache::add("tn-sync:removal-skipped:{$key}", true, now()->addDay()) && app()->bound('sentry')) {
            app('sentry')->captureMessage($message);
        }
    }

    /**
     * @return array|null  Change rows, or null on API error.
     */
    private function fetchPage(int $page, string $from, string $to): ?array
    {
        if ($this->localTesting) {
            $file = base_path("tests/fixtures/tn_sync/user_changes_page_{$page}.json");
            if (!file_exists($file)) {
                Log::info('TN-SYNC-TRACE [CHANGES-PAGE] missing fixture file=' . $file);
                return [];
            }
            $payload = json_decode(file_get_contents($file), true);
            return is_array($payload) ? ($payload['changes'] ?? []) : [];
        }

        ($this->rateLimiter ?? app(TrashNothingRateLimiter::class))->await();

        $response = Http::get("{$this->apiBaseUrl}/user-changes", [
            'key'      => $this->apiKey,
            'page'     => $page,
            'per_page' => self::PAGE_SIZE,
            'date_min' => $from,
            'date_max' => $to,
        ]);

        // The response only: the request's URL holds the API key.
        if ($this->logResponses) {
            Log::info('TN sync: user-changes API response on page ' . $page, [
                'status'  => $response->status(),
                'headers' => $response->headers(),
                'body'    => $response->body(),
            ]);
        }

        if (!$response->successful()) {
            Log::error('TN sync: user-changes API failed on page ' . $page, [
                'status'   => $response->status(),
                'date_min' => $from,
                'date_max' => $to,
                // TN's reason. An error is not a page of members, so unlike logResponses this is
                // always on, but the request held the key and TN could quote it.
                'body'     => substr(str_replace($this->apiKey, '[key]', $response->body()), 0, 500),
            ]);
            return null;
        }

        return $response->json('changes', []);
    }
}
