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
        $page    = 1;
        $count   = 0;
        $maxDate = null;

        $this->fetchFailed = false;

        do {
            $changes = $this->fetchPage($page, $from, $to);
            if ($changes === null) {
                $this->fetchFailed = true;
                break;
            }

            $page++;
            Log::info('TN-SYNC-TRACE [CHANGES-PAGE] page=' . ($page - 1) . ' count=' . count($changes));

            foreach ($changes as $change) {
                $count++;

                if (!$maxDate || $change['date'] > $maxDate) {
                    $maxDate = $change['date'];
                }

                try {
                    $user = $this->resolveMember($change);
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
        } while ($changes && count($changes) === self::PAGE_SIZE);

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
     * Only a TN account is changed. When TN names one holding a different TN user id and no account
     * holds this one, the change is about another TN member sharing that account, and applying it
     * would forget or rename the wrong person, so it is skipped and reported. An account with no
     * TN user id is given this one, since TN has just said which member it is, unless another
     * account already holds it; then a person has to say which is right, and the change is
     * skipped and reported. Nor is it given one while it holds two TN usernames, as the id could
     * be either member's.
     */
    private function resolveMember(array $change): ?User
    {
        $tnId  = (int) ($change['tn_user_id'] ?? 0);
        $match = TnMemberMatch::find((int) ($change['fd_user_id'] ?? 0), $tnId);
        $named = $match->named;

        if ($named !== null && !$named->isTN()) {
            return null;
        }

        if ($match->heldElsewhere()) {
            $this->skipChange('tn-user-id-held-elsewhere', $change, $named->id, $match->holder->id);

            return null;
        }

        $user = $match->account();
        if ($user === null || !$user->isTN()) {
            if ($named !== null) {
                $this->skipChange('tn-user-id-mismatch', $change, $named->id, (int) $named->tnuserid);
            }

            return null;
        }

        if ($user->tnuserid === null && $tnId !== 0) {
            if ($this->holdsTwoUsernames($user)) {
                Log::info('TN-SYNC-TRACE [CHANGE-SKIP] reason=tn-two-usernames field=tnuserid fd_user_id=' . $user->id . ' tn_user_id=' . $tnId);

                return $user;
            }

            Log::info('TN-SYNC-TRACE [WRITE] table=users op=update where=id=' . $user->id . ' set=tnuserid=' . $tnId);
            if (!$this->dryRun) {
                DB::table('users')->where('id', $user->id)->whereNull('tnuserid')->update(['tnuserid' => $tnId]);
                $user->tnuserid = $tnId;
                $user->syncOriginalAttribute('tnuserid');
            }
        }

        return $user;
    }

    /**
     * Whether the account holds addresses for two TN usernames. That is two members sharing it, or
     * one member who renamed, and the account alone cannot say which.
     */
    private function holdsTwoUsernames(User $user): bool
    {
        return $user->emails()->pluck('email')
            ->map(fn ($email) => User::tnUsernameFromEmail((string) $email))
            ->filter()
            ->unique()
            ->count() > 1;
    }

    /**
     * Leave a change unapplied and say so. The sync date moves past it regardless, so a removal
     * skipped here is not retried: the member has left TN and their data stays until a person
     * acts on the report. A window is read more than once, so it is reported once a day.
     */
    private function skipChange(string $reason, array $change, int $userId, int $other): void
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

        if (!$this->dryRun && !empty($change['account_removed']) && Cache::add("tn-sync:removal-skipped:{$userId}:{$tnId}", true, now()->addDay()) && app()->bound('sentry')) {
            app('sentry')->captureMessage("TN member {$tnId} has left, but their removal was not applied to user {$userId} ({$reason}, {$other})");
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

        if (!$response->successful()) {
            Log::error('TN sync: user-changes API failed on page ' . $page, ['status' => $response->status()]);
            return null;
        }

        return $response->json('changes', []);
    }
}
