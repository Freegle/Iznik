<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reconcile users_donations.userid against live Freegle accounts. It handles two
 * populations of mis-linked donations:
 *
 *   A. donations never linked to any account (userid IS NULL) — the historical
 *      backlog from before the IPN handlers matched at receipt time; and
 *   B. donations stranded on a since-DELETED account — the donate/leave/rejoin
 *      gap. Their userid is non-null (so population A's whereNull() never sees
 *      them) but points at an account that was later deleted without its
 *      donations being carried across. Where the payer's email now lives on a
 *      live account, the donation is re-pointed there.
 *
 * Both populations resolve a payer using the same signals as the live Go IPN
 * handlers (donations.MatchUserByEmailOrPriorDonation):
 *
 *   1. exact/canonical email match against a live account (gmail dots/+tags,
 *      googlemail, TN variants) via the shared App\Models\User::canonMail(); and
 *   2. a prior donation from the same Payer already linked to a non-deleted
 *      user — recovering recurring donors whose Freegle email later changed
 *      while the payment processor keeps billing the original address.
 *
 * resolve() only ever returns a LIVE account, so donations already on a live
 * account (the duplicate-account case) are deliberately never moved — that is
 * resolved by a human account merge. Because new strandings keep appearing when
 * accounts holding donations are deleted, the command is scheduled to run
 * periodically as a safety net rather than being a one-shot backfill.
 */
class DonationUserIdBackfillService
{
    /**
     * @return array{scanned:int, matched_email:int, matched_prior:int, updated:int, null_backfilled:int, deleted_repointed:int}
     */
    public function backfill(bool $dryRun = false, ?int $limit = null): array
    {
        // Shared state across both passes. Resolve once per distinct Payer —
        // recurring donors have many rows that all resolve to the same account.
        $state = [
            'scanned'           => 0,
            'matched_email'     => 0,
            'matched_prior'     => 0,
            'updated'           => 0,
            'null_backfilled'   => 0, // rows that had NO account (userid IS NULL)
            'deleted_repointed' => 0, // rows stranded on a since-deleted account
            'resolved'          => [],
            'remaining'         => $limit,
        ];

        // Pass 1 — donations that were never linked to any account. These landed
        // before the IPN handlers matched at receipt time.
        $nullQuery = DB::table('users_donations')
            ->whereNull('userid')
            ->whereNotNull('Payer')
            ->where('Payer', '<>', '')
            ->orderBy('id');
        $this->processDonations($nullQuery, 'null_backfilled', $state, $dryRun, $limit);

        // Pass 2 — the donate/leave/rejoin gap. Donations recorded against an
        // account that was LATER deleted stay pinned to it: their userid is
        // non-null, so pass 1's whereNull() never sees them, and the delete/merge
        // that removed the account did not carry the donations across. When the
        // same person's email now lives on a live account, re-point them there.
        // resolve() only ever returns a LIVE account, so donations already on a
        // live account (the duplicate-account/merge case) are deliberately left
        // untouched — that is resolved by a human account merge, not here.
        $deletedQuery = DB::table('users_donations')
            ->whereNotNull('users_donations.userid')
            ->whereNotNull('Payer')
            ->where('Payer', '<>', '')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.id', 'users_donations.userid')
                    ->whereNotNull('users.deleted');
            })
            ->orderBy('users_donations.id');
        $this->processDonations($deletedQuery, 'deleted_repointed', $state, $dryRun, $limit);

        return [
            'scanned'           => $state['scanned'],
            'matched_email'     => $state['matched_email'],
            'matched_prior'     => $state['matched_prior'],
            'updated'           => $state['updated'],
            'null_backfilled'   => $state['null_backfilled'],
            'deleted_repointed' => $state['deleted_repointed'],
        ];
    }

    /**
     * Scan a donation query in id-ordered chunks, resolve each Payer to a live
     * account and (unless dry-running) re-point the donation. $bucket names the
     * counter incremented for a successful update ('null_backfilled' or
     * 'deleted_repointed'); $state carries the shared counters, per-Payer resolve
     * cache and the combined remaining-limit budget across passes.
     */
    private function processDonations($query, string $bucket, array &$state, bool $dryRun, ?int $limit): void
    {
        $query->chunkById(500, function ($rows) use (&$state, $bucket, $dryRun, $limit) {
            foreach ($rows as $row) {
                if ($limit !== null && $state['remaining'] !== null && $state['remaining'] <= 0) {
                    return false; // stop chunking — combined budget spent
                }

                $state['scanned']++;
                if ($state['remaining'] !== null) {
                    $state['remaining']--;
                }

                $payer = (string) $row->Payer;
                if (!array_key_exists($payer, $state['resolved'])) {
                    $state['resolved'][$payer] = $this->resolve($payer);
                }
                [$uid, $via] = $state['resolved'][$payer];

                if ($uid === null || (int) $uid === (int) $row->userid) {
                    // No confident live account, or already there (defensive).
                    continue;
                }

                if ($via === 'email') {
                    $state['matched_email']++;
                } else {
                    $state['matched_prior']++;
                }

                if (!$dryRun) {
                    DB::table('users_donations')->where('id', $row->id)->update(['userid' => $uid]);
                }
                $state['updated']++;
                $state[$bucket]++;
            }

            return true;
        }, 'users_donations.id', 'id');
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
