<?php

namespace App\Services\Mail\Deferrals;

use App\Monitoring\HostCommandRunner;
use Illuminate\Support\Facades\Log;

/**
 * Reads the outbound relay's Postfix queue and recent delivery log.
 *
 * The relay is a separate machine: batch hands mail to it over SMTP and gets
 * a 250 back, so by the time a receiving provider refuses the mail we have
 * long since stopped watching. The only place that refusal is written down
 * is on the relay itself, in its queue and its maillog. This class is the
 * one-way window onto that.
 *
 * This only sees a provider that REFUSES us - a 4xx in the SMTP conversation, which leaves the
 * message sitting in the queue where we can count it. A provider that accepts everything and
 * then bins it silently leaves no queue entry at all, and is invisible here;
 * App\Services\Mail\DeliveryHealthService watches open rates for that shape instead.
 *
 * It reuses App\Monitoring\HostCommandRunner - the same ssh-and-parse-stdout
 * shape the host health probes already use - rather than inventing a second
 * way to reach a production host. The runner is injected, so tests feed it
 * canned relay output and never go near ssh.
 */
class DeferralProbe
{
    // Section markers. The probe prints them unconditionally, so their absence
    // means the script did not run rather than "the queue happens to be empty".
    public const MARK_QUEUE = '===FREEGLE-DEFERRALS-QUEUE===';

    public const MARK_DELIVERED = '===FREEGLE-DEFERRALS-DELIVERED===';

    public const MARK_END = '===FREEGLE-DEFERRALS-END===';

    public const MARK_TRUNCATED = '===FREEGLE-DEFERRALS-TRUNCATED===';

    public function __construct(
        private readonly HostCommandRunner $runner,
    ) {}

    /**
     * Run the probe against the relay.
     *
     * @param  string  $target  ssh target, e.g. "deferrals@10.0.0.1"
     * @param  int  $maxQueueBytes  cap on the queue listing we will accept back
     * @return RelayQueueSnapshot|null null if the relay was unreachable
     */
    public function probe(string $target, int $maxQueueBytes): ?RelayQueueSnapshot
    {
        $output = $this->runner->run($target, $this->script($maxQueueBytes));

        if ($output === null) {
            Log::warning('Mail deferral probe: relay unreachable', ['target' => $target]);

            return null;
        }

        if (! str_contains($output, self::MARK_END)) {
            // Output without the closing marker means the script died partway
            // or the transport cut it off. Half a queue listing would silently
            // understate every count, so refuse it rather than act on it.
            Log::error('Mail deferral probe: incomplete output from relay', [
                'target' => $target,
                'bytes' => strlen($output),
            ]);

            return null;
        }

        return $this->parse($output);
    }

    /**
     * The script we run on the relay.
     *
     * Deliberately one round trip and deliberately read-only. `postqueue -j`
     * emits one JSON object per line (not an array), which is what lets us
     * cap the transfer with head and still hand back parseable records.
     *
     * The delivered-count section is best-effort: relays differ in whether
     * they log to journald or a flat maillog, and we would rather have the
     * queue half of the picture than nothing at all. Missing delivery data
     * degrades the release check rather than breaking the scan - see
     * DeferralScanService.
     */
    private function script(int $maxQueueBytes): string
    {
        $bytes = max(1024, $maxQueueBytes);

        return <<<SH
            set -u
            echo '{$this->markQueue()}'
            if command -v postqueue >/dev/null 2>&1; then
                OUT=\$(postqueue -j 2>/dev/null | head -c {$bytes})
                echo "\$OUT"
                # head -c cuts mid-line, so say so rather than let the scan
                # treat a truncated tail as a complete picture.
                if [ "\$(printf '%s' "\$OUT" | wc -c)" -ge {$bytes} ]; then
                    echo '{$this->markTruncated()}'
                fi
            fi
            echo '{$this->markDelivered()}'
            # status=sent lines in the last hour, bucketed by the relay host
            # Postfix recorded for the delivery. Try journald first, then the
            # traditional flat log; emit nothing if neither is readable.
            # Try journald, then fall back to the flat log IF JOURNALD GAVE
            # NOTHING. Testing only that journalctl RUNS is not enough: on a
            # relay that logs mail via syslog, `journalctl -u postfix` exits 0
            # and prints nothing, so the old form took this branch and reported
            # no delivery data at all. Every relay family then looked like
            # "0 delivered/hr", which is the suppression trigger - on bulk2
            # 2026-08-18 that would have suppressed google.com (4457 deferred,
            # 2171 status=sent lines sitting unread in /var/log/mail.log).
            # Silence must never be mistaken for evidence of no deliveries.
            DELIVERED=''
            if command -v journalctl >/dev/null 2>&1; then
                DELIVERED=\$(journalctl -u postfix --since '1 hour ago' -q 2>/dev/null | grep 'status=sent' || true)
            fi
            if [ -z "\$DELIVERED" ] && [ -r /var/log/mail.log ]; then
                # No time filter available without parsing syslog timestamps,
                # so take the tail as an approximation of "recent".
                DELIVERED=\$(tail -n 200000 /var/log/mail.log 2>/dev/null | grep 'status=sent' || true)
            fi
            printf '%s\n' "\$DELIVERED"
            echo '{$this->markEnd()}'
            SH;
    }

    /**
     * Ask the relay to delete specific queued messages.
     *
     * This is not tidying. Postfix retries a deferred message until
     * maximal_queue_lifetime and then emits one bounce per message - tens of
     * thousands of them, each carrying the provider's original 4xx text, each
     * landing back in our own bounce processing. `postsuper -d` removes them
     * silently with no DSN at all, which is the only thing that stops that
     * cascade.
     *
     * Callers must have established that the relay family is suppressed;
     * this method only does what it is told.
     *
     * @param  string[]  $queueIds
     * @return int number of ids we asked the relay to delete
     */
    public function purge(string $target, array $queueIds): int
    {
        // The ids come from the relay's own listing, but they are still being
        // pasted into a shell on a production host, so anything that does not
        // look like a queue id is dropped rather than trusted.
        $safe = array_values(array_filter(
            $queueIds,
            fn ($id) => is_string($id) && preg_match('/^[A-Za-z0-9]{6,32}$/', $id) === 1
        ));

        if ($safe === []) {
            return 0;
        }

        $deleted = 0;

        foreach (array_chunk($safe, 500) as $chunk) {
            // postsuper reads ids from stdin when given "-".
            $script = 'printf "%s\n" '.implode(' ', $chunk).' | postsuper -d - 2>&1';

            $out = $this->runner->run($target, $script);

            if ($out === null) {
                Log::error('Mail deferral purge: relay became unreachable partway', [
                    'target' => $target,
                    'deleted_before_failure' => $deleted,
                ]);
                break;
            }

            $deleted += count($chunk);
        }

        Log::warning('Mail deferral purge: asked relay to delete queued messages', [
            'target' => $target,
            'requested' => count($safe),
            'deleted' => $deleted,
        ]);

        return $deleted;
    }

    private function markQueue(): string
    {
        return self::MARK_QUEUE;
    }

    private function markDelivered(): string
    {
        return self::MARK_DELIVERED;
    }

    private function markEnd(): string
    {
        return self::MARK_END;
    }

    private function markTruncated(): string
    {
        return self::MARK_TRUNCATED;
    }

    /**
     * Turn the probe's stdout into a snapshot.
     */
    private function parse(string $output): RelayQueueSnapshot
    {
        $snapshot = new RelayQueueSnapshot;

        $section = null;
        $truncated = false;

        foreach (explode("\n", $output) as $line) {
            $line = rtrim($line, "\r");

            if ($line === self::MARK_QUEUE) {
                $section = 'queue';

                continue;
            }
            if ($line === self::MARK_DELIVERED) {
                $section = 'delivered';

                continue;
            }
            if ($line === self::MARK_END) {
                break;
            }
            if ($line === self::MARK_TRUNCATED) {
                $truncated = true;

                continue;
            }
            if ($line === '') {
                continue;
            }

            if ($section === 'queue') {
                $this->parseQueueLine($line, $snapshot);
            } elseif ($section === 'delivered') {
                $this->parseDeliveredLine($line, $snapshot);
            }
        }

        $snapshot->truncated = $truncated;

        return $snapshot;
    }

    /**
     * One queue file, as emitted by `postqueue -j`.
     *
     * A message that delivers leaves the queue altogether, so everything here
     * is work still owed. We only care about mail that has actually been
     * attempted and pushed back: `incoming` and `hold` have not been tried,
     * and `corrupt` is a different problem entirely.
     */
    private function parseQueueLine(string $line, RelayQueueSnapshot $snapshot): void
    {
        $entry = json_decode($line, true);

        if (! is_array($entry) || ! isset($entry['recipients']) || ! is_array($entry['recipients'])) {
            // A truncated final line is expected when we capped the transfer;
            // anything else is worth knowing about but not worth aborting for.
            $snapshot->unparseableLines++;

            return;
        }

        $queue = $entry['queue_name'] ?? '';
        if ($queue !== 'deferred' && $queue !== 'active') {
            return;
        }

        $arrival = isset($entry['arrival_time']) ? (int) $entry['arrival_time'] : null;
        $queueId = isset($entry['queue_id']) ? (string) $entry['queue_id'] : null;

        foreach ($entry['recipients'] as $recipient) {
            if (! is_array($recipient) || empty($recipient['address'])) {
                continue;
            }

            // Absent for a recipient that has not been attempted yet. Those
            // are not evidence of a deferral, so they do not count.
            $reason = $recipient['delay_reason'] ?? null;
            if (! is_string($reason) || $reason === '') {
                continue;
            }

            $snapshot->addDeferral(
                address: (string) $recipient['address'],
                reason: $reason,
                arrivalTime: $arrival,
                queueId: $queueId,
            );
        }
    }

    /**
     * One `status=sent` maillog line, e.g.
     *   ... postfix/smtp[123]: ABC123: to=<a@b.com>, relay=mx.b.com[1.2.3.4]:25,
     *   delay=1.2, ..., status=sent (250 ok)
     *
     * We only need the relay host, to know which providers are still taking
     * our mail.
     */
    private function parseDeliveredLine(string $line, RelayQueueSnapshot $snapshot): void
    {
        if (! preg_match('/relay=([^\[\s,:]+)/', $line, $m)) {
            return;
        }

        $host = strtolower(rtrim($m[1], '.'));

        // Local deliveries and pipe transports are not a provider taking our
        // mail, so they must not count as evidence that one has recovered.
        if ($host === '' || $host === 'none' || $host === 'local') {
            return;
        }

        $snapshot->addDelivery(MxGrouper::group($host));
    }
}
