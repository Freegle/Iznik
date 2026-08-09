<?php

namespace App\Monitoring;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Publishes the platform status that ModTools' status dot reads back through
 * the Go API's /api/status.
 *
 * The old pipeline was iznik-server/scripts/cron/status.php, which SSH'd every
 * host, ran monit, and wrote /tmp/iznik.status for the API to serve. That cron
 * went with the V1 PHP removal (c14a7125b) and nothing replaced it, so the
 * endpoint returned "Cannot access status file" for a month while the dot
 * showed green to ordinary mods.
 *
 * A file on the API host is no longer a viable channel: the writer is Laravel
 * (iznik-batch) and the reader is Go (apiv2), and they are separate containers
 * on separate hosts. The blob therefore goes in the `config` table, the same
 * channel deploy:record-commit already uses for /api/version.
 *
 * The verdict is not computed here. It is whatever monitor:scheduled-outcomes
 * just decided, so the dot cannot drift away from the monitoring that actually
 * alerts — one evaluation, two consumers. That also makes a dead writer
 * self-reporting: the blob carries generated_at, and the Go reader turns a
 * stale one into a warning rather than serving it as current.
 */
class PlatformStatusWriter
{
    /** The config key the Go API reads. */
    public const CONFIG_KEY = 'status.platform';

    /**
     * Store the outcome of a full monitoring pass.
     *
     * @param  list<OutcomeResult>  $results  every check evaluated this pass
     */
    public function write(array $results, CarbonInterface $now): void
    {
        $info = [];
        $anyError = false;
        $anyWarning = false;

        foreach ($results as $result) {
            if (! $result->isBreach()) {
                // Only breaches are published. The modal renders one row per
                // entry, so carrying the OK checks would just be noise a mod
                // has to read past to find the thing that is actually wrong.
                continue;
            }

            // OutcomeResult severity is 'error' unless a check downgrades it.
            // An error means part of the platform is not working; a warning
            // means it still works but something needs attention. That is the
            // same split the dot has always drawn.
            $isError = $result->severity === 'error';
            $anyError = $anyError || $isError;
            $anyWarning = $anyWarning || ! $isError;

            $info[$result->slug] = [
                'error' => $isError,
                'errortext' => $isError ? $result->message : null,
                'warning' => ! $isError,
                'warningtext' => $isError ? null : $result->message,
            ];
        }

        $payload = [
            'ret' => 0,
            'status' => 'Success',
            'error' => $anyError,
            'warning' => $anyWarning,
            // Cast so an empty set encodes as {} rather than []. The Go reader
            // adds its own entry to this map when the status is stale, and the
            // Vue modal iterates it; both are simpler if the shape never
            // changes just because nothing happens to be wrong.
            'info' => (object) $info,
            // The reader uses this to decide whether the feed itself has died.
            // ISO-8601 with an explicit offset so Go can parse it unambiguously.
            'generated_at' => $now->copy()->utc()->toIso8601String(),
        ];

        DB::table('config')->upsert(
            [['key' => self::CONFIG_KEY, 'value' => json_encode($payload)]],
            ['key'],
            ['value'],
        );
    }
}
