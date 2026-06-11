<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * One-shot backfill that re-links unmatched donations (users_donations.userid
 * IS NULL) to a Freegle account.
 *
 * It restores V1 correctUserIdInDonations() parity (exact-email matching) and
 * adds the same two extra signals now used by the live Go IPN handlers
 * (donations.MatchUserByEmailOrPriorDonation):
 *
 *   1. canonical-email matching (gmail dots/+tags, googlemail, TN variants) via
 *      the shared App\Models\User::canonMail(); and
 *   2. a prior donation from the same Payer already linked to a non-deleted
 *      user — recovering recurring donors whose Freegle email later changed
 *      while the payment processor keeps billing the original address.
 *
 * Going forward the IPN handlers match at receipt time, so this command is NOT
 * scheduled; it exists to clean up the historical backlog.
 */
class DonationUserIdBackfillService
{
    /**
     * @return array{scanned:int, matched_email:int, matched_prior:int, updated:int}
     */
    public function backfill(bool $dryRun = false, ?int $limit = null): array
    {
        $scanned      = 0;
        $matchedEmail = 0;
        $matchedPrior = 0;
        $updated      = 0;

        // Resolve once per distinct Payer — recurring donors have many unmatched
        // rows that all resolve to the same account.
        $resolved = [];

        $query = DB::table('users_donations')
            ->whereNull('userid')
            ->whereNotNull('Payer')
            ->where('Payer', '<>', '')
            ->orderBy('id');

        $remaining = $limit;

        $query->chunkById(500, function ($rows) use (
            &$scanned, &$matchedEmail, &$matchedPrior, &$updated, &$resolved, &$remaining, $dryRun, $limit
        ) {
            foreach ($rows as $row) {
                if ($limit !== null && $remaining !== null && $remaining <= 0) {
                    return false; // stop chunking
                }

                $scanned++;
                if ($remaining !== null) {
                    $remaining--;
                }

                $payer = (string) $row->Payer;
                if (!array_key_exists($payer, $resolved)) {
                    $resolved[$payer] = $this->resolve($payer);
                }
                [$uid, $via] = $resolved[$payer];

                if ($uid === null) {
                    continue;
                }

                if ($via === 'email') {
                    $matchedEmail++;
                } else {
                    $matchedPrior++;
                }

                if (!$dryRun) {
                    DB::table('users_donations')->where('id', $row->id)->update(['userid' => $uid]);
                }
                $updated++;
            }

            return true;
        });

        return [
            'scanned'       => $scanned,
            'matched_email' => $matchedEmail,
            'matched_prior' => $matchedPrior,
            'updated'       => $updated,
        ];
    }

    /**
     * Resolve a Payer email to a still-valid user id, mirroring the Go handler's
     * MatchUserByEmailOrPriorDonation.
     *
     * @return array{0:int|null, 1:string} [userid|null, 'email'|'prior'|'']
     */
    private function resolve(string $payer): array
    {
        $canon = User::canonMail($payer);

        // 1. Registered address (exact or canonical), on a live account.
        $uid = DB::table('users_emails')
            ->join('users', 'users.id', '=', 'users_emails.userid')
            ->whereNull('users.deleted')
            ->whereNotNull('users_emails.userid')
            ->where(function ($q) use ($payer, $canon) {
                $q->where('users_emails.email', $payer)
                  ->orWhere('users_emails.canon', $canon);
            })
            ->value('users_emails.userid');

        if ($uid) {
            return [(int) $uid, 'email'];
        }

        // 2. A prior donation from the same Payer, linked to a still-valid user.
        $uid = DB::table('users_donations')
            ->join('users', 'users.id', '=', 'users_donations.userid')
            ->whereNull('users.deleted')
            ->whereNotNull('users_donations.userid')
            ->where('users_donations.Payer', $payer)
            ->orderByDesc('users_donations.timestamp')
            ->value('users_donations.userid');

        if ($uid) {
            return [(int) $uid, 'prior'];
        }

        return [null, ''];
    }
}
