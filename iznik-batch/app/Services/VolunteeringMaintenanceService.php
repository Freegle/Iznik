<?php

namespace App\Services;

use App\Mail\Volunteering\VolunteeringRenewMail;
use App\Models\User;
use App\Models\Volunteering;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Volunteering opportunity renewal + expiry maintenance.
 *
 * Ported from iznik-server Volunteering::askRenew() and Volunteering::expire()
 * (include/group/Volunteering.php). The V1 weekly cron
 * (scripts/cron/volunteering.php) ran askRenew() then expire() before emailing
 * the digest. That cron was disabled ~2026-05-12 when digest emailing moved to
 * Laravel (mail:volunteering-digest), but the renewal + expiry maintenance was
 * never migrated — so dateless opportunities stopped being asked-to-renew and
 * stopped being expired. This service restores that maintenance.
 *
 * "Active" = pending=0 AND deleted=0 AND expired=0. A dateless opportunity must
 * be periodically renewed (owner clicks "still active" → renewed=NOW(),
 * expired=0, handled in the Go API) to stay live.
 */
class VolunteeringMaintenanceService
{
    /** Days after which a dateless opportunity expires (iznik-server Volunteering::EXPIRE_AGE). */
    public const EXPIRE_AGE = 31;

    /**
     * Days after which a dateless opportunity's owner is asked to renew. V1 used
     * "Midnight 24 days ago", giving ~7 days' warning before EXPIRE_AGE.
     */
    public const ASK_AGE = 24;

    /**
     * Login-link source tag. V1 askRenew() used User::SRC_VOLUNTEERING_DIGEST
     * ('voldigest') here — ported exactly. (A dedicated SRC_VOLUNTEERING_RENEWAL
     * = 'volrenew' constant exists in V1 but askRenew() never used it.)
     */
    private const LOGIN_SRC = 'voldigest';

    /**
     * Email owners of dateless opportunities approaching expiry to ask whether the
     * opportunity is still active.
     *
     * Ports iznik-server Volunteering::askRenew(): for opportunities with no end
     * date, not deleted, not expired, added more than ASK_AGE days ago and not
     * renewed within the last ASK_AGE days, email the owner.
     *
     * DELIBERATE IMPROVEMENT over V1: we stamp askedtorenew = NOW() after asking
     * and gate the query on it, so a daily schedule asks ONCE per renewal cycle
     * rather than re-emailing every run (V1 ran weekly and re-mailed every run
     * inside the window).
     *
     * @param  int|null  $id      Restrict to a single opportunity (tests / manual runs).
     * @param  bool       $dryRun  Count who would be asked without emailing or stamping.
     * @return int  Number of renewal asks sent (or, in dry run, that would be sent).
     */
    public function askRenew(?int $id = null, bool $dryRun = false): int
    {
        $cutoff = Carbon::today()->subDays(self::ASK_AGE);

        $ids = DB::table('volunteering')
            ->leftJoin('volunteering_dates', 'volunteering.id', '=', 'volunteering_dates.volunteeringid')
            ->whereNull('volunteering_dates.end')
            ->where('volunteering.deleted', 0)
            ->where('volunteering.expired', 0)
            ->where('volunteering.added', '<', $cutoff)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('volunteering.renewed')
                    ->orWhere('volunteering.renewed', '<', $cutoff);
            })
            // DELIBERATE IMPROVEMENT vs V1: don't re-ask within a renewal cycle.
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('volunteering.askedtorenew')
                    ->orWhere('volunteering.askedtorenew', '<', $cutoff);
            })
            ->when($id !== null, fn ($q) => $q->where('volunteering.id', $id))
            ->distinct()
            ->pluck('volunteering.id');

        $count = 0;

        foreach ($ids as $volId) {
            $vol = Volunteering::find($volId);
            if (!$vol) {
                continue;
            }

            // V1 only mails when the owning user still exists and has an address.
            $user = $vol->userid ? User::find($vol->userid) : null;
            if (!$user) {
                continue;
            }

            $email = $user->email_preferred;
            if (!$email) {
                continue;
            }

            if ($dryRun) {
                $count++;
                continue;
            }

            $url = $user->loginLink('/volunteering/' . $volId, self::LOGIN_SRC);

            try {
                app(EmailSpoolerService::class)->spool(new VolunteeringRenewMail(
                    recipientEmail: $email,
                    title: (string) $vol->title,
                    renewUrl: $url,
                    groupName: $this->groupNameFor((int) $volId),
                    userId: (int) $user->id,
                ), $email);
            } catch (\Throwable $e) {
                // spool() re-throws transient render/build errors; one bad
                // recipient must not abort the whole run. askedtorenew is left
                // unstamped so this opportunity is retried next run.
                Log::warning('Skipping volunteering renewal ask after spool failure; continuing', [
                    'volunteering_id' => $volId,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            // Eloquent save() (not a bulk UPDATE) so model events fire for Auditing.
            $vol->askedtorenew = now();
            $vol->save();
            $count++;
        }

        return $count;
    }

    /**
     * Expire opportunities that are no longer current.
     *
     * Ports iznik-server Volunteering::expire():
     *  (a) Opportunities WITH dates: expired once no date is still in the future.
     *  (b) Dateless opportunities: expired once older than EXPIRE_AGE days and not
     *      renewed within EXPIRE_AGE days.
     *
     * @param  int|null  $id      Restrict to a single opportunity (tests / manual runs).
     * @param  bool       $dryRun  Count what would expire without changing any rows.
     * @return int  Number of opportunities expired (or, in dry run, that would expire).
     */
    public function expire(?int $id = null, bool $dryRun = false): int
    {
        $count = 0;

        // (a) Dated opportunities whose dates have all passed.
        $datedIds = DB::table('volunteering')
            ->join('volunteering_dates', 'volunteering.id', '=', 'volunteering_dates.volunteeringid')
            ->where('volunteering_dates.end', '<=', now())
            ->where('volunteering.expired', 0)
            ->when($id !== null, fn ($q) => $q->where('volunteering.id', $id))
            ->distinct()
            ->pluck('volunteering.id');

        foreach ($datedIds as $volId) {
            $hasFuture = DB::table('volunteering_dates')
                ->where('volunteeringid', $volId)
                ->where('end', '>', now())
                ->exists();

            if (!$hasFuture && $this->markExpired((int) $volId, $dryRun)) {
                $count++;
            }
        }

        // (b) Dateless opportunities older than EXPIRE_AGE not renewed in EXPIRE_AGE days.
        $cutoff = Carbon::today()->subDays(self::EXPIRE_AGE);

        $datelessIds = DB::table('volunteering')
            ->leftJoin('volunteering_dates', 'volunteering.id', '=', 'volunteering_dates.volunteeringid')
            ->whereNull('volunteering_dates.end')
            ->where('volunteering.expired', 0)
            ->where('volunteering.added', '<', $cutoff)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('volunteering.renewed')
                    ->orWhere('volunteering.renewed', '<', $cutoff);
            })
            ->when($id !== null, fn ($q) => $q->where('volunteering.id', $id))
            ->distinct()
            ->pluck('volunteering.id');

        foreach ($datelessIds as $volId) {
            if ($this->markExpired((int) $volId, $dryRun)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Mark a single opportunity expired via Eloquent (so Auditing fires), unless
     * this is a dry run. Returns true if the row was (or would be) expired.
     */
    private function markExpired(int $volId, bool $dryRun): bool
    {
        $vol = Volunteering::find($volId);
        if (!$vol) {
            return false;
        }

        if ($dryRun) {
            return true;
        }

        $vol->expired = true;
        $vol->save();

        return true;
    }

    /**
     * Display name of a group the opportunity is posted on, defaulting to the
     * site name. Mirrors V1's namedisplay (namefull if set, else nameshort).
     */
    private function groupNameFor(int $volId): string
    {
        $name = DB::table('volunteering_groups')
            ->join('groups', 'groups.id', '=', 'volunteering_groups.groupid')
            ->where('volunteering_groups.volunteeringid', $volId)
            ->selectRaw("COALESCE(NULLIF(groups.namefull, ''), groups.nameshort) AS name")
            ->value('name');

        return $name ?: config('freegle.site_name', 'Freegle');
    }
}
