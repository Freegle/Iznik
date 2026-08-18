<?php

namespace App\Services\Mail\Deferrals;

use App\Services\Mail\MailSuppressionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Decides what to suppress and what to release, from one probe of the relay.
 *
 * Two tiers, and the first is the one that matters.
 *
 * MX-group is primary because providers block per relay, not per domain. It
 * fires when a relay family has a large deferred backlog AND has all but
 * stopped accepting our mail. Both halves are needed: a big backlog on its
 * own can just be a slow evening, and a quiet hour on its own can just be a
 * quiet hour.
 *
 * Per-address is secondary and slower, for "452 4.2.2 ... over quota". That
 * is one person's mailbox rather than our reputation, and it wants a
 * forgiving trigger because a mailbox that is full today is often fine
 * tomorrow.
 *
 * Release is deliberately harder than suppression. It needs deliveries to
 * have actually resumed and the backlog to have drained, held across two
 * consecutive scans, so a single quiet moment inside a provider's own backoff
 * window cannot reopen the floodgates.
 */
class DeferralScanService
{
    public function __construct(
        private readonly MailSuppressionService $suppressions,
    ) {}

    /**
     * Apply a snapshot to the suppression table.
     *
     * @return array{suppressed: array<int, array<string, mixed>>, released: array<int, array<string, mixed>>, checked: int}
     */
    public function apply(RelayQueueSnapshot $snapshot, bool $dryRun = false): array
    {
        $cfg = (array) config('freegle.mail.deferrals', []);

        $suppressed = [];
        $released = [];

        $active = $this->activeByKey();

        $suppressed = array_merge(
            $suppressed,
            $this->applyMxGroups($snapshot, $active, $cfg, $dryRun)
        );

        $suppressed = array_merge(
            $suppressed,
            $this->applyAddresses($snapshot, $active, $cfg, $dryRun)
        );

        $released = $this->applyReleases($snapshot, $active, $cfg, $dryRun);

        if (! $dryRun && ($suppressed !== [] || $released !== [])) {
            $this->suppressions->flushCache();
        }

        return [
            'suppressed' => $suppressed,
            'released' => $released,
            'checked' => count($snapshot->groups) + count($snapshot->addresses),
        ];
    }

    /**
     * @param  array<string, object>  $active
     * @return array<int, array<string, mixed>>
     */
    private function applyMxGroups(RelayQueueSnapshot $snapshot, array $active, array $cfg, bool $dryRun): array
    {
        $minDeferred = (int) ($cfg['mxgroup_min_deferred'] ?? 500);
        $maxDelivered = (int) ($cfg['mxgroup_max_delivered_per_hour'] ?? 10);

        $newlySuppressed = [];

        foreach ($snapshot->groups as $group => $stats) {
            $key = MailSuppressionService::SCOPE_MXGROUP."\0".$group;
            $existing = $active[$key] ?? null;

            if ($stats['count'] < $minDeferred) {
                continue;
            }

            $delivered = $snapshot->deliveriesFor($group);

            // Without delivery data we cannot tell "they have stopped taking
            // our mail" from "it is 3am and nobody is sending". A backlog
            // this size is still worth acting on, but say so, because a
            // suppression made on half the evidence should be visible as
            // such when someone reads the log afterwards.
            $blind = ! $snapshot->hasDeliveryData();

            if (! $blind && $delivered > $maxDelivered) {
                // Still being accepted at a healthy rate: a backlog here is
                // congestion, not a block.
                continue;
            }

            if ($existing !== null) {
                // Already suppressed. Refresh the evidence and reset the
                // clear-scan counter, because this scan is not clear.
                if (! $dryRun) {
                    $this->refresh($existing->id, $stats, clearScans: 0);
                }

                continue;
            }

            $record = [
                'scope' => MailSuppressionService::SCOPE_MXGROUP,
                'value' => $group,
                'provider' => MxGrouper::providerName($group),
                'deferred' => $stats['count'],
                'delivered' => $delivered,
                'blind' => $blind,
                'reason' => $stats['reason'],
                'deferred_since' => $this->toDate($stats['oldest']),
                'domains' => array_keys($stats['domains']),
            ];

            if (! $dryRun) {
                $record['id'] = $this->createMxGroupSuppression($group, $stats, $delivered, $blind);
            }

            $newlySuppressed[] = $record;
        }

        return $newlySuppressed;
    }

    /**
     * @param  array<string, object>  $active
     * @return array<int, array<string, mixed>>
     */
    private function applyAddresses(RelayQueueSnapshot $snapshot, array $active, array $cfg, bool $dryRun): array
    {
        $minDeferred = (int) ($cfg['address_min_deferred'] ?? 5);
        $minHours = (int) ($cfg['address_min_hours'] ?? 24);
        $cutoff = now()->subHours($minHours)->getTimestamp();

        $newlySuppressed = [];

        foreach ($snapshot->addresses as $address => $stats) {
            if ($stats['count'] < $minDeferred) {
                continue;
            }

            // The backlog has to have been stuck for a while, not just be big.
            // A burst of retries within an hour is the relay working normally.
            if ($stats['oldest'] === null || $stats['oldest'] > $cutoff) {
                continue;
            }

            // If the address's whole provider is already suppressed, the
            // address row would add nothing but noise.
            if ($this->coveredByGroup($address, $active)) {
                continue;
            }

            $key = MailSuppressionService::SCOPE_ADDRESS."\0".$address;
            if (isset($active[$key])) {
                if (! $dryRun) {
                    $this->refresh($active[$key]->id, $stats, clearScans: 0);
                }

                continue;
            }

            $record = [
                'scope' => MailSuppressionService::SCOPE_ADDRESS,
                'value' => $address,
                'deferred' => $stats['count'],
                'reason' => $stats['reason'],
                'deferred_since' => $this->toDate($stats['oldest']),
            ];

            if (! $dryRun) {
                $record['id'] = DB::table('mail_suppressions')->insertGetId([
                    'scope' => MailSuppressionService::SCOPE_ADDRESS,
                    'value' => $address,
                    'reason' => $stats['reason'],
                    'deferred_since' => $this->toDate($stats['oldest']),
                    'first_seen' => now(),
                    'last_seen' => now(),
                    'message_count' => $stats['count'],
                ]);
            }

            $newlySuppressed[] = $record;
        }

        return $newlySuppressed;
    }

    /**
     * @param  array<string, object>  $active
     * @return array<int, array<string, mixed>>
     */
    private function applyReleases(RelayQueueSnapshot $snapshot, array $active, array $cfg, bool $dryRun): array
    {
        $releaseMax = (int) ($cfg['release_max_deferred'] ?? 100);
        $needClear = max(1, (int) ($cfg['release_clear_scans'] ?? 2));
        $staleHours = (int) ($cfg['stale_after_hours'] ?? 24);

        $released = [];

        foreach ($active as $key => $row) {
            [$scope, $value] = explode("\0", $key, 2);

            // Domain rows are children of an mxgroup row; releasing the
            // parent cascades, so evaluating them separately would race.
            if ($scope === MailSuppressionService::SCOPE_DOMAIN) {
                continue;
            }

            $stats = $scope === MailSuppressionService::SCOPE_MXGROUP
                ? ($snapshot->groups[$value] ?? null)
                : ($snapshot->addresses[$value] ?? null);

            $stillDeferred = $stats['count'] ?? 0;

            $deliveriesResumed = $scope !== MailSuppressionService::SCOPE_MXGROUP
                || ! $snapshot->hasDeliveryData()
                || $snapshot->deliveriesFor($value) > 0;

            $clear = $stillDeferred <= $releaseMax && $deliveriesResumed;

            // Fail open. If the probe has been unable to confirm a
            // suppression for this long, we are more likely to be broken than
            // the provider is, and quietly not mailing a whole provider for
            // ever is the worse failure.
            $stale = $staleHours > 0
                && $row->last_seen !== null
                && Carbon::parse($row->last_seen)->lt(now()->subHours($staleHours));

            if (! $clear && ! $stale) {
                continue;
            }

            $clearScans = ((int) $row->clear_scans) + 1;

            if (! $stale && $clearScans < $needClear) {
                if (! $dryRun) {
                    DB::table('mail_suppressions')
                        ->where('id', $row->id)
                        ->update(['clear_scans' => $clearScans, 'message_count' => $stillDeferred]);
                }

                continue;
            }

            $released[] = [
                'id' => $row->id,
                'scope' => $scope,
                'value' => $value,
                'provider' => $row->provider,
                'deferred_since' => $row->deferred_since,
                'still_deferred' => $stillDeferred,
                'stale' => $stale,
            ];

            if (! $dryRun) {
                // Children go with the parent, so a released provider does
                // not leave orphan domain rows gating mail for ever.
                DB::table('mail_suppressions')
                    ->where('id', $row->id)
                    ->orWhere('parentid', $row->id)
                    ->update(['released_at' => now(), 'clear_scans' => 0]);
            }
        }

        return $released;
    }

    /**
     * Create the mxgroup row plus one child domain row per recipient domain we
     * actually saw deferring through it.
     *
     * Materialising the domains is what keeps the sending-loop check to a
     * plain indexed lookup with no DNS: the queue has already told us which
     * domains this relay serves, and it is better evidence than an MX lookup
     * would be, because it is what actually happened to our mail.
     */
    private function createMxGroupSuppression(string $group, array $stats, int $delivered, bool $blind): int
    {
        $provider = MxGrouper::providerName($group);
        $since = $this->toDate($stats['oldest']);

        return DB::transaction(function () use ($group, $stats, $delivered, $blind, $provider, $since) {
            $reason = $stats['reason'];
            if ($blind) {
                $reason = '[no delivery data available] ' . $reason;
            }

            $id = DB::table('mail_suppressions')->insertGetId([
                'scope' => MailSuppressionService::SCOPE_MXGROUP,
                'value' => $group,
                'provider' => $provider,
                'reason' => $reason,
                'deferred_since' => $since,
                'first_seen' => now(),
                'last_seen' => now(),
                'message_count' => $stats['count'],
            ]);

            $rows = [];
            foreach (array_keys($stats['domains']) as $domain) {
                $rows[] = [
                    'scope' => MailSuppressionService::SCOPE_DOMAIN,
                    'value' => $domain,
                    'parentid' => $id,
                    'provider' => $provider,
                    'reason' => $reason,
                    'deferred_since' => $since,
                    'first_seen' => now(),
                    'last_seen' => now(),
                    'message_count' => $stats['domains'][$domain],
                ];
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('mail_suppressions')->insert($chunk);
            }

            Log::warning('Mail deferrals: suppressing relay family', [
                'mxgroup' => $group,
                'provider' => $provider,
                'deferred' => $stats['count'],
                'delivered_last_hour' => $delivered,
                'domains' => count($rows),
                'deferred_since' => $since,
            ]);

            return $id;
        });
    }

    private function refresh(int $id, array $stats, int $clearScans): void
    {
        DB::table('mail_suppressions')
            ->where('id', $id)
            ->orWhere('parentid', $id)
            ->update([
                'last_seen' => now(),
                'clear_scans' => $clearScans,
            ]);

        DB::table('mail_suppressions')
            ->where('id', $id)
            ->update(['message_count' => $stats['count']]);
    }

    /**
     * @param  array<string, object>  $active
     */
    private function coveredByGroup(string $address, array $active): bool
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return false;
        }

        $domain = substr($address, $at + 1);

        return isset($active[MailSuppressionService::SCOPE_DOMAIN."\0".$domain]);
    }

    /**
     * @return array<string, object>
     */
    private function activeByKey(): array
    {
        $rows = DB::table('mail_suppressions')->whereNull('released_at')->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->scope."\0".strtolower((string) $row->value)] = $row;
        }

        return $map;
    }

    private function toDate(?int $ts): ?string
    {
        if ($ts === null || $ts <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp($ts)->toDateTimeString();
    }
}
