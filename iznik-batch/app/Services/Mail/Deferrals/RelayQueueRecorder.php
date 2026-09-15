<?php

namespace App\Services\Mail\Deferrals;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes down what the relay's queue currently holds, per recipient domain.
 *
 * Deliberately separate from DeferralScanService. That service DECIDES things -
 * it suppresses providers and releases them, and a wrong number there stops real
 * mail being generated. This one only records, and nothing downstream acts on it
 * automatically. Keeping the two apart means the reporting half can count
 * everything in the queue, including the mail that is perfectly healthy and
 * merely waiting, without any risk of that widened view reaching the gate.
 *
 * The distinction it exists to draw:
 *
 *   deferred  a provider gave us a 4xx. Their decision. The remedy is to stop
 *             generating mail for them and wait for our reputation to recover.
 *   waiting   nothing has refused it; it is queued behind our own rate limit.
 *             Our decision. The remedy is more sending capacity, or less mail.
 *
 * They look identical to a member - an email that has not arrived - and the
 * remedies are opposites, so a view that shows only the first will send someone
 * looking for a provider problem that does not exist.
 */
class RelayQueueRecorder
{
    /**
     * Replace the stored queue snapshot with what this probe saw.
     *
     * @return array{rows:int, waiting:int, deferred:int} what was recorded
     */
    public function record(RelayQueueSnapshot $snapshot, bool $dryRun = false): array
    {
        $cfg = (array) config('freegle.mail.relay_queue', []);
        $minQueued = (int) ($cfg['min_queued'] ?? 25);
        $minAgeMinutes = (int) ($cfg['min_age_minutes'] ?? 120);
        $maxRows = (int) ($cfg['max_rows'] ?? 500);

        $now = Carbon::now();
        $rows = [];

        foreach ($this->perDomain($snapshot) as $domain => $d) {
            $queued = $d['waiting'] + $d['deferred'];
            $ageMinutes = $d['oldest'] !== null ? ($now->getTimestamp() - $d['oldest']) / 60 : 0;

            // Two ways to be worth showing, because depth and age catch
            // different failures. A big backlog draining fast is worth seeing
            // early; a handful of messages stuck for a day is worth seeing at
            // all, and would never clear a depth threshold.
            if ($queued < $minQueued && $ageMinutes < $minAgeMinutes) {
                continue;
            }

            $rows[] = [
                'domain' => $domain,
                'waiting' => $d['waiting'],
                'deferred' => $d['deferred'],
                'oldest' => $d['oldest'] !== null ? Carbon::createFromTimestamp($d['oldest'])->toDateTimeString() : null,
                'deliveredperhour' => $snapshot->deliveriesForDomain($domain),
                'instance' => $d['instance'],
                'scanned' => $now->toDateTimeString(),
            ];
        }

        // Worst first, then capped: an estate-wide episode can name thousands
        // of domains, and the hundredth is not going to be read.
        usort($rows, fn ($a, $b) => ($b['waiting'] + $b['deferred']) <=> ($a['waiting'] + $a['deferred']));
        $rows = array_slice($rows, 0, $maxRows);

        $totals = [
            'rows' => count($rows),
            'waiting' => array_sum(array_column($rows, 'waiting')),
            'deferred' => array_sum(array_column($rows, 'deferred')),
        ];

        if ($dryRun) {
            return $totals;
        }

        $keep = array_column($rows, 'domain');

        // A snapshot, so a domain that has cleared loses its row rather than
        // being left at zero for someone to misread as current.
        DB::table('mail_relay_queue')
            ->when($keep !== [], fn ($q) => $q->whereNotIn('domain', $keep))
            ->delete();

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('mail_relay_queue')->upsert(
                $chunk,
                ['domain'],
                ['waiting', 'deferred', 'oldest', 'deliveredperhour', 'instance', 'scanned']
            );
        }

        return $totals;
    }

    /**
     * Fold the snapshot's two views - waiting by domain, deferred by relay
     * family - into one row per recipient domain.
     *
     * @return array<string, array{waiting:int, deferred:int, oldest:?int, instance:?string}>
     */
    private function perDomain(RelayQueueSnapshot $snapshot): array
    {
        $out = [];

        foreach ($snapshot->waiting as $domain => $w) {
            $out[$domain] = [
                'waiting' => $w['count'],
                'deferred' => 0,
                'oldest' => $w['oldest'],
                'instance' => $w['instance'],
            ];
        }

        foreach ($snapshot->groups as $stats) {
            foreach ($stats['domains'] ?? [] as $domain => $count) {
                if (! isset($out[$domain])) {
                    $out[$domain] = ['waiting' => 0, 'deferred' => 0, 'oldest' => null, 'instance' => null];
                }
                $out[$domain]['deferred'] += $count;

                // The family's oldest is the best age we have for a domain
                // with nothing waiting - every entry in it has been refused,
                // so none of them carries a waiting arrival time.
                if ($out[$domain]['oldest'] === null) {
                    $out[$domain]['oldest'] = $stats['oldest'] ?? null;
                }
            }
        }

        return $out;
    }
}
