<?php

namespace App\Services\TrashNothing\Sync;

use App\Models\Location;
use App\Models\User;
use App\Models\UserAboutMe;
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

                    if (!empty($change['reply_time'])) {
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

                    if (!empty($change['about_me'])) {
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
                        $oldname = User::removeTNGroup($user->fullname ?? '');

                        if ($oldname != $change['username']) {
                            Log::info("Name change for {$change['fd_user_id']} {$oldname} => {$change['username']}");
                            Log::info('TN-SYNC-TRACE [NAME-CHANGE] fd_user_id=' . $change['fd_user_id'] . ' old=' . $oldname . ' new=' . $change['username']);
                            $user->fullname = $change['username'];

                            foreach ($user->emails()->pluck('email') as $email) {
                                if (str_contains($email, "{$oldname}-")) {
                                    $newEmail = str_replace("{$oldname}-", "{$change['username']}-", $email);
                                    $user->removeEmail($email, $this->dryRun);
                                    Log::info("...{$email} => {$newEmail}");
                                    $user->addEmail($newEmail, dryRun: $this->dryRun);
                                    $this->loki->logEvent('tn-sync', 'user-email-rename', [
                                        'user_id'   => $change['fd_user_id'],
                                        'old_email' => $email,
                                        'new_email' => $newEmail,
                                    ]);
                                }
                            }
                        }
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
