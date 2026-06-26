<?php

namespace App\Console\Commands\Eee;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Push fresh EEELabel rows from microactions to the eee-browser labels DB.
 *
 * Source: microactions where actiontype = 'EEELabel'
 *   userid -> labeller name 'mv-<userid>'
 *   eee_attachment_id -> messages_attachments.id -> resolve to (messageid, attid)
 *   eee_condition / eee_weight / eee_size -> one eee_field_labels row each
 *
 * Sink: POST to https://freegle-eee-browser.fly.dev/api/review/sync-mv-labels
 * authenticated by EEE_SYNC_SECRET shared secret.
 *
 * Last-synced cursor stored in cache key 'eee:sync-mv-labels:since'; pass
 * --since=<datetime> to override or --full to resend everything.
 *
 * Run via scheduler every 10 minutes; safe to re-run because the endpoint
 * upserts on (messageid, attid, field, labeller).
 */
class EeeSyncMvLabelsCommand extends Command
{
    protected $signature = 'eee:sync-mv-labels
                            {--url=         : Override eee-browser URL (default: https://freegle-eee-browser.fly.dev)}
                            {--since=       : Only push rows newer than this YYYY-MM-DD HH:MM:SS (default: cached cursor)}
                            {--full         : Push ALL EEELabel rows (ignores cursor)}
                            {--dry-run      : Show what would be pushed without sending}
                            {--batch-size=500 : Rows per HTTP POST}';

    protected $description = 'Sync EEELabel micro-volunteering rows to the eee-browser labels DB';

    private const CACHE_KEY = 'eee:sync-mv-labels:since';

    public function handle(): int
    {
        $url    = $this->option('url') ?: env('EEE_SYNC_URL', 'https://freegle-eee-browser.fly.dev');
        $secret = env('EEE_SYNC_SECRET');
        $batchSize = max(1, (int) $this->option('batch-size'));
        $dryRun = (bool) $this->option('dry-run');

        if (!$dryRun && !$secret) {
            $this->error('EEE_SYNC_SECRET env var is required (set on iznik-batch and on freegle-eee-browser).');
            return Command::FAILURE;
        }

        $since = $this->option('full')
            ? '1970-01-01 00:00:00'
            : ($this->option('since') ?: Cache::get(self::CACHE_KEY, '1970-01-01 00:00:00'));

        $this->info("Syncing EEELabel rows newer than {$since} -> {$url}");

        // Pull source rows. Join to messages_attachments so we know the msgid.
        $rows = DB::table('microactions as ma')
            ->join('messages_attachments as att', 'att.id', '=', 'ma.eee_attachment_id')
            ->where('ma.actiontype', 'EEELabel')
            ->where('ma.timestamp', '>', $since)
            ->orderBy('ma.timestamp')
            ->select(
                'ma.userid', 'ma.eee_attachment_id', 'ma.timestamp',
                'ma.eee_condition', 'ma.eee_weight', 'ma.eee_size',
                'att.msgid'
            )
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No new EEELabel rows to sync.');
            return Command::SUCCESS;
        }

        $labels = [];
        $newestSeen = $since;
        foreach ($rows as $r) {
            $labeller = 'mv-' . (int) $r->userid;
            $whenIso  = date('Y-m-d\TH:i:s', strtotime($r->timestamp));
            if ($r->timestamp > $newestSeen) $newestSeen = $r->timestamp;

            if (!empty($r->eee_condition)) {
                $labels[] = [
                    'messageid' => (int) $r->msgid,
                    'attid'     => (int) $r->eee_attachment_id,
                    'field'     => 'Condition',
                    'labeller'  => $labeller,
                    'label'     => $r->eee_condition,
                    'labelled_at' => $whenIso,
                ];
            }
            if (!empty($r->eee_weight)) {
                $labels[] = [
                    'messageid' => (int) $r->msgid,
                    'attid'     => (int) $r->eee_attachment_id,
                    'field'     => 'Weight (kg)',
                    'labeller'  => $labeller,
                    'label'     => $r->eee_weight,
                    'labelled_at' => $whenIso,
                ];
            }
            if (!empty($r->eee_size)) {
                $labels[] = [
                    'messageid' => (int) $r->msgid,
                    'attid'     => (int) $r->eee_attachment_id,
                    'field'     => 'Size',
                    'labeller'  => $labeller,
                    'label'     => $r->eee_size,
                    'labelled_at' => $whenIso,
                ];
            }
        }

        $this->info(sprintf('Built %d label rows from %d microactions.', count($labels), $rows->count()));

        if ($dryRun) {
            $this->line('First 5:');
            foreach (array_slice($labels, 0, 5) as $l) {
                $this->line('  ' . json_encode($l));
            }
            return Command::SUCCESS;
        }

        $totalInserted = 0; $totalRejected = 0;
        foreach (array_chunk($labels, $batchSize) as $i => $chunk) {
            $resp = Http::timeout(30)
                ->withHeaders(['X-EEE-Sync-Secret' => $secret])
                ->post($url . '/api/review/sync-mv-labels', ['labels' => $chunk]);

            if (!$resp->successful()) {
                $this->error(sprintf('Batch %d failed: HTTP %d %s', $i, $resp->status(), $resp->body()));
                return Command::FAILURE;
            }
            $body = $resp->json();
            $totalInserted += $body['inserted'] ?? 0;
            $totalRejected += $body['rejected'] ?? 0;
            $this->line(sprintf('  batch %d: inserted=%d rejected=%d', $i, $body['inserted'] ?? 0, $body['rejected'] ?? 0));
        }

        $this->info("Done. Inserted {$totalInserted}, rejected {$totalRejected}.");
        Cache::put(self::CACHE_KEY, $newestSeen, now()->addYear());
        $this->line("Cursor advanced to {$newestSeen}.");
        return Command::SUCCESS;
    }
}
