<?php

namespace App\Services\Ripple;

use App\Mail\Ripple\RippleIntroMail;
use App\Models\User;
use App\Services\EmailSpoolerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill the Rippling Out auto-join mitigations onto members who were auto-joined BEFORE those
 * mitigations existed (their memberships carry a Group/Joined text='Rippled' provenance log but
 * rippled=0 and possibly an un-downgraded emailfrequency, and they never got the intro email).
 *
 * Per user, idempotently:
 *  - mark every rippled membership rippled=1,
 *  - apply the live email policy (downgrade only immediate -1 -> daily 24; preserve everything else),
 *  - send the one bundled intro email once (tracked by a User/Mailed text='RippleIntro' log so a
 *    re-run never re-sends).
 *
 * Dry-run (default) computes the same counts but writes nothing and sends nothing.
 */
class RippleMembershipBackfillService
{
    /**
     * @return array{users:int,memberships:int,downgraded:int,flagged:int,intros:int}
     */
    public function backfill(?int $userId, int $limit, bool $send): array
    {
        $stats = ['users' => 0, 'memberships' => 0, 'downgraded' => 0, 'flagged' => 0, 'intros' => 0];

        // Distinct users who have a rippled membership, identified by its durable provenance log.
        $userIds = DB::table('memberships as m')
            ->join('logs as l', function ($j) {
                $j->on('l.user', '=', 'm.userid')
                    ->on('l.groupid', '=', 'm.groupid')
                    ->where('l.type', 'Group')
                    ->where('l.subtype', 'Joined')
                    ->where('l.text', 'Rippled');
            })
            ->when($userId !== null, fn ($q) => $q->where('m.userid', $userId))
            ->distinct()
            ->orderBy('m.userid')
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->pluck('m.userid');

        foreach ($userIds as $uid) {
            $memberships = DB::table('memberships as m')
                ->join('logs as l', function ($j) {
                    $j->on('l.user', '=', 'm.userid')
                        ->on('l.groupid', '=', 'm.groupid')
                        ->where('l.type', 'Group')
                        ->where('l.subtype', 'Joined')
                        ->where('l.text', 'Rippled');
                })
                ->where('m.userid', $uid)
                ->select('m.id', 'm.emailfrequency', 'm.rippled')
                ->distinct()
                ->get();

            if ($memberships->isEmpty()) {
                continue;
            }
            $stats['users']++;

            foreach ($memberships as $mem) {
                $stats['memberships']++;
                $currentFreq = (int) $mem->emailfrequency;
                $newFreq = ($currentFreq === -1) ? 24 : $currentFreq;
                $needsFlag = !$mem->rippled;
                $needsDowngrade = $newFreq !== $currentFreq;

                if ($needsFlag) {
                    $stats['flagged']++;
                }
                if ($needsDowngrade) {
                    $stats['downgraded']++;
                }

                if ($send && ($needsFlag || $needsDowngrade)) {
                    DB::table('memberships')->where('id', $mem->id)->update([
                        'rippled' => 1,
                        'emailfrequency' => $newFreq,
                    ]);
                }
            }

            // One intro email per user, once ever (marker log keeps re-runs idempotent).
            $alreadySent = DB::table('logs')
                ->where('user', $uid)->where('type', 'User')
                ->where('subtype', 'Mailed')->where('text', 'RippleIntro')
                ->exists();

            if (!$alreadySent) {
                $user = User::find($uid);
                if ($user && $user->email_preferred) {
                    $stats['intros']++;
                    if ($send) {
                        $this->sendIntro($user);
                    }
                }
            }
        }

        return $stats;
    }

    private function sendIntro(User $user): void
    {
        // Claim first (write the once-marker) so a transient render/spool failure can never cause a
        // re-send on the next run; the spool itself has its own durable retry.
        DB::table('logs')->insert([
            'timestamp' => now(),
            'type' => 'User',
            'subtype' => 'Mailed',
            'user' => $user->id,
            'text' => 'RippleIntro',
        ]);

        try {
            // Bundle each rippled-into community's own welcome text into the one intro email, the
            // same as the live ExpandService path - but per user, across every group they were
            // rippled into (the backfill is not tied to a single post). Without this, backfilled
            // posters got the intro with no "welcome from the communities your post reached"
            // section. Only groups live here with a welcome configured are included.
            // keep-raw: COALESCE() over two columns has no builder method.
            $welcomeGroups = array_map(
                static fn ($r) => ['name' => $r->name, 'welcome' => $r->welcome],
                DB::select(
                    "SELECT COALESCE(g.namefull, g.nameshort) AS name, g.welcomemail AS welcome
                     FROM memberships m
                     JOIN `groups` g ON g.id = m.groupid
                     WHERE m.userid = ? AND m.rippled = 1
                       AND g.onhere = 1 AND g.welcomemail IS NOT NULL AND g.welcomemail <> ''
                     ORDER BY m.groupid ASC",
                    [$user->id]
                )
            );

            app(EmailSpoolerService::class)->spool(new RippleIntroMail($user, null, $welcomeGroups));
        } catch (\Throwable $e) {
            Log::warning("ripple backfill: intro email failed for user {$user->id}: {$e->getMessage()}");
        }
    }
}
