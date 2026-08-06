<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fallback downloader for PayPal donations the IPN missed. Faithful migration
 * of V1 scripts/cron/paypal_download.php.
 *
 * Scans the last N days one day at a time via the PayPal NVP TransactionSearch
 * API and upserts each qualifying payment into users_donations (keyed on the
 * unique TransactionID). The donate IPN catches donations normally; this only
 * backfills gaps, so it deliberately tolerates per-day failures and carries on.
 */
class PaypalDownloadService
{
    /**
     * @param  callable|null  $progress  fn(string $date, int $found, int $upserted)
     * @return array{skipped:bool, days:int, found:int, upserted:int}
     */
    public function download(?callable $progress = null): array
    {
        $username = (string) config('freegle.paypal.username');
        if ($username === '') {
            Log::warning('PaypalDownloadService: PAYPAL_USERNAME not configured — skipping.');

            return ['skipped' => true, 'days' => 0, 'found' => 0, 'upserted' => 0];
        }

        $days = (int) config('freegle.paypal.download_days', 30);
        $tz = config('freegle.timezone', 'Europe/London');

        $start = Carbon::today($tz);
        $end = Carbon::tomorrow($tz);
        $limit = Carbon::today($tz)->subDays($days);

        $found = 0;
        $upserted = 0;
        $daysScanned = 0;

        do {
            $daysScanned++;

            if ($progress) {
                ($progress)($start->toDateString(), $found, $upserted);
            }

            try {
                foreach ($this->searchTransactions($start, $end) as $t) {
                    // Skip non-payments, refunds/zero amounts, and PayPal Giving
                    // Fund donations (processed separately).
                    if ($t['email'] === '' || $t['amount'] <= 0 || $t['name'] === 'PayPal Giving Fund UK') {
                        continue;
                    }

                    $found++;
                    if ($this->upsertDonation($t)) {
                        $upserted++;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('PaypalDownloadService: search failed', [
                    'date' => $start->toDateString(),
                    'error' => $e->getMessage(),
                ]);
                report($e);
            }

            $start = $start->copy()->subDay();
            $end = $end->copy()->subDay();
        } while ($start->greaterThan($limit));

        Log::info('PaypalDownloadService: done', [
            'days' => $daysScanned,
            'found' => $found,
            'upserted' => $upserted,
        ]);

        return ['skipped' => false, 'days' => $daysScanned, 'found' => $found, 'upserted' => $upserted];
    }

    /**
     * Call PayPal NVP TransactionSearch for a [start, end) window and return a
     * normalised list of transactions.
     *
     * @return array<int, array{email:string, name:string, timestamp:string, transactionid:string, amount:float}>
     */
    private function searchTransactions(Carbon $start, Carbon $end): array
    {
        $response = Http::asForm()
            ->timeout(120)
            ->post(config('freegle.paypal.nvp_endpoint'), [
                'USER' => config('freegle.paypal.username'),
                'PWD' => config('freegle.paypal.password'),
                'SIGNATURE' => config('freegle.paypal.signature'),
                'METHOD' => 'TransactionSearch',
                'VERSION' => '204.0',
                'STARTDATE' => $start->toIso8601String(),
                'ENDDATE' => $end->toIso8601String(),
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('PayPal NVP HTTP '.$response->status());
        }

        parse_str($response->body(), $parsed);

        $ack = $parsed['ACK'] ?? '';
        if (stripos((string) $ack, 'Success') === false) {
            $err = $parsed['L_LONGMESSAGE0'] ?? 'unknown error';
            throw new \RuntimeException("PayPal NVP ACK={$ack}: {$err}");
        }

        $txns = [];
        for ($i = 0; isset($parsed["L_TRANSACTIONID{$i}"]); $i++) {
            $txns[] = [
                'email' => (string) ($parsed["L_EMAIL{$i}"] ?? ''),
                'name' => (string) ($parsed["L_NAME{$i}"] ?? ''),
                'timestamp' => (string) ($parsed["L_TIMESTAMP{$i}"] ?? ''),
                'transactionid' => (string) $parsed["L_TRANSACTIONID{$i}"],
                'amount' => (float) ($parsed["L_AMT{$i}"] ?? 0),
            ];
        }

        return $txns;
    }

    /**
     * Upsert a transaction into users_donations keyed on the unique
     * TransactionID, matching the donor to an account by email/canon. Mirrors
     * V1's INSERT ... ON DUPLICATE KEY UPDATE userid, timestamp.
     */
    private function upsertDonation(array $t): bool
    {
        $userid = $this->findUserByEmail($t['email']);
        $timestamp = $t['timestamp'] !== ''
            ? date('Y-m-d H:i:s', strtotime($t['timestamp']))
            : now()->format('Y-m-d H:i:s');

        // A MAP for the update columns, not a list: the raw statement wrote
        // "userid = ?, timestamp = ?" - bound values - rather than
        // "userid = VALUES(userid)". A list would emit the VALUES() form and
        // change what a duplicate row gets written. MySQL ignores the uniqueBy
        // argument (it uses its own unique key, here TransactionID), but
        // Laravel requires it.
        return DB::table('users_donations')->upsert(
            [[
                'userid' => $userid,
                'Payer' => $t['email'],
                'PayerDisplayName' => $t['name'],
                'timestamp' => $timestamp,
                'TransactionID' => $t['transactionid'],
                'GrossAmount' => $t['amount'],
            ]],
            ['TransactionID'],
            ['userid' => $userid, 'timestamp' => $timestamp]
        );
    }

    /**
     * Resolve a payer email to a Freegle user id, matching V1
     * User::findByEmail (exact address or canonical form).
     */
    private function findUserByEmail(string $email): ?int
    {
        $canon = User::canonMail($email);

        $uid = DB::table('users_emails')
            ->where(function ($q) use ($email, $canon) {
                $q->where('email', $email)->orWhere('canon', $canon);
            })
            ->whereNotNull('canon')
            // keep-raw: LENGTH() has no builder method.
            ->whereRaw('LENGTH(canon) > 0')
            ->value('userid');

        return $uid ? (int) $uid : null;
    }
}
