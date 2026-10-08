<?php

namespace App\Services\TrashNothing\Sync;

use App\Models\Location;
use App\Models\User;
use App\Models\UserAboutMe;
use App\Models\UserEmail;
use App\Models\UserReplyTime;
use App\Services\LokiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UserChangesSyncer
{
    private const PAGE_SIZE = 100;

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
     * @return array{int, string|null} [count, maxDate]
     */
    public function sync(string $from, string $to): array
    {
        $page    = 1;
        $count   = 0;
        $maxDate = null;

        do {
            $changes = $this->fetchPage($page, $from, $to);
            if ($changes === null) {
                break;
            }

            $page++;
            Log::info('TN-SYNC-TRACE [CHANGES-PAGE] page=' . ($page - 1) . ' count=' . count($changes));

            foreach ($changes as $change) {
                $count++;

                if (!$maxDate || $change['date'] > $maxDate) {
                    $maxDate = $change['date'];
                }

                if (!($change['fd_user_id'] ?? null)) {
                    continue;
                }

                try {
                    $user = User::find($change['fd_user_id']);
                    if (!$user || !$user->isTN()) {
                        continue;
                    }

                    if (!empty($change['account_removed'])) {
                        Log::info("FD #{$change['fd_user_id']} TN account removed");
                        Log::info('TN-SYNC-TRACE [USER-CHANGE] fd_user_id=' . $change['fd_user_id'] . ' action=account-removed');
                        $user->forget('TN account removed', $this->dryRun);
                        $this->loki->logEvent('tn-sync', 'user-forget', ['user_id' => $change['fd_user_id']]);
                        continue;
                    }

                    // isset() not !empty(): a reply time of 0 is legitimate.
                    if (isset($change['reply_time'])) {
                        $replyTime = UserReplyTime::firstOrNew(['userid' => $change['fd_user_id']]);
                        $isNew = !$replyTime->exists;
                        $replyTime->replytime = $change['reply_time'];
                        $replyTime->timestamp = $change['date'];
                        Log::info('TN-SYNC-TRACE [WRITE] table=users_replytime op=replace where=userid=' . $change['fd_user_id'] . ' set=replytime=' . $change['reply_time'] . ',timestamp=' . $change['date']);
                        if (!$this->dryRun) {
                            $replyTime->save();
                        }
                        $this->loki->logEvent('tn-sync', 'user-reply-time-upsert', [
                            'action'  => $isNew ? 'insert' : 'update',
                            'user_id' => $change['fd_user_id'],
                        ]);
                    }

                    // isset() not !empty(): an empty string means the member cleared their bio.
                    if (isset($change['about_me'])) {
                        try {
                            $aboutMe = UserAboutMe::firstOrNew(['userid' => $change['fd_user_id']]);
                            $isNew = !$aboutMe->exists;
                            $aboutMe->timestamp = $change['date'];
                            $aboutMe->text      = $change['about_me'];
                            Log::info('TN-SYNC-TRACE [WRITE] table=users_aboutme op=replace where=userid=' . $change['fd_user_id'] . ' set=timestamp=' . $change['date'] . ',text=len=' . strlen($change['about_me']));
                            if (!$this->dryRun) {
                                $aboutMe->save();
                            }
                            $this->loki->logEvent('tn-sync', 'user-about-me-upsert', [
                                'action'  => $isNew ? 'insert' : 'update',
                                'user_id' => $change['fd_user_id'],
                            ]);
                        } catch (\Exception $e) {
                            if (function_exists('\Sentry\captureException')) {
                                \Sentry\captureException($e);
                            }
                        }
                    }

                    if (!empty($change['username'])) {
                        $this->applyUsername($user, $change['username']);
                    }

                    if (!empty($change['location'])) {
                        $lat = $change['location']['latitude'] ?? null;
                        $lng = $change['location']['longitude'] ?? null;

                        if ($lat !== null && $lng !== null) {
                            $loc = Location::closestPostcode((float) $lat, (float) $lng);

                            if ($loc && $loc->id !== $user->lastlocation) {
                                Log::info("FD #{$change['fd_user_id']} TN lat/lng {$lat},{$lng} has changed  => {$loc->id} {$loc->name}");
                                Log::info('TN-SYNC-TRACE [LOCATION] fd_user_id=' . $change['fd_user_id'] . ' lat=' . $lat . ' lng=' . $lng . ' old_loc=' . $user->lastlocation . ' new_loc=' . $loc->id);
                                $user->lastlocation = $loc->id;
                            }

                            // TN is the master for a TN member's location. settings.mylocation
                            // is read before lastlocation almost everywhere, and on TN accounts
                            // it is stale V1 data from before the account was linked to TN, so
                            // drop it and let lastlocation stand.
                            $settings = $user->settings;
                            if (is_array($settings) && array_key_exists('mylocation', $settings)) {
                                Log::info('TN-SYNC-TRACE [WRITE] table=users op=update where=id=' . $change['fd_user_id'] . ' set=settings.mylocation=removed');
                                unset($settings['mylocation']);
                                $user->settings = $settings;
                            }
                        }
                    }

                    if (!$this->dryRun) {
                        $user->save();
                    }
                    $this->loki->logEvent('tn-sync', 'user-update', ['user_id' => $change['fd_user_id']]);
                    Log::info('TN-SYNC-TRACE [USER-CHANGE] fd_user_id=' . $change['fd_user_id'] . ' action=processed');

                } catch (\Exception $e) {
                    Log::info('TN-SYNC-TRACE [USER-CHANGE] fd_user_id=' . $change['fd_user_id'] . ' action=error');
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
     * Apply the username from a change event. The old username is read from the
     * member's preferred TN address, not from fullname: fullname is the
     * prettified display name ("Tricia Hayes"), so comparing it with the raw
     * username made every event look like a rename.
     *
     * A rename collapses the member's TN addresses for the old username, bare
     * and -gNNN aliases alike, into one bare new@user.trashnothing.com. Inbound
     * mail from new-gNNN@ aliases still resolves through the canon fallback.
     * The caller saves $user.
     */
    private function applyUsername(User $user, string $newUsername): void
    {
        $oldUsername = User::tnUsernameFromEmail($user->email_preferred ?? '');

        if ($oldUsername === strtolower($newUsername)) {
            return;
        }

        Log::info("Name change for {$user->id} {$oldUsername} => {$newUsername}");
        $user->fullname = User::tnDisplayName($newUsername);

        $newEmail = User::tnEmailForUsername($newUsername);
        $oldEmails = $user->emails()
            ->get(['email', 'preferred'])
            ->filter(fn ($row) => User::tnUsernameFromEmail($row->email) === $oldUsername);

        // users_emails.email is UNIQUE, so the new address cannot be added while
        // another member holds it. Keep this user's addresses rather than throw.
        $clash = UserEmail::where('email', $newEmail)->where('userid', '!=', $user->id)->value('userid');
        if ($clash) {
            Log::info('TN-SYNC-TRACE [NAME-CHANGE] fd_user_id=' . $user->id . ' old=' . $oldUsername . ' new=' . $newUsername . ' email-clash=' . $newEmail . ' held_by=' . $clash);
            if (function_exists('\Sentry\captureMessage')) {
                \Sentry\captureMessage("TN rename of user {$user->id} to {$newEmail} clashes with user {$clash}");
            }
            return;
        }

        Log::info('TN-SYNC-TRACE [NAME-CHANGE] fd_user_id=' . $user->id . ' old=' . $oldUsername . ' new=' . $newUsername);

        if ($oldEmails->isEmpty()) {
            return;
        }

        // Add before removing, so the user is never left without a preferred TN
        // address: isTN() reads it, and this sync skips users without one.
        $user->addEmail($newEmail, primary: $oldEmails->contains('preferred', 1) ? 1 : 0, dryRun: $this->dryRun);

        foreach ($oldEmails as $row) {
            $user->removeEmail($row->email, $this->dryRun);
            Log::info("...{$row->email} => {$newEmail}");
            $this->loki->logEvent('tn-sync', 'user-email-rename', [
                'user_id'   => $user->id,
                'old_email' => $row->email,
                'new_email' => $newEmail,
            ]);
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
