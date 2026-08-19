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

    public const MARK_ACCEPTING = '===FREEGLE-DEFERRALS-ACCEPTING===';

    public const MARK_CANPURGE = '===FREEGLE-DEFERRALS-CANPURGE===';

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
     * @return int number of messages the relay CONFIRMED it deleted
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
            // postsuper is root-only. We connect to the relay as an
            // unprivileged user, so it needs sudo - see canPurge(), which says
            // so in as many words rather than leaving a bare "fatal" to be
            // decoded. Kept conditional so a root relay still works untouched.
            $script = 'SUDO=""; [ "$(id -u)" -ne 0 ] && SUDO="sudo -n"; '
                .'printf "%s\n" '.implode(' ', $chunk).' | $SUDO postsuper -d - 2>&1';

            $out = $this->runner->run($target, $script);

            if ($out === null) {
                Log::error('Mail deferral purge: relay became unreachable partway', [
                    'target' => $target,
                    'deleted_before_failure' => $deleted,
                ]);
                break;
            }

            // Believe postsuper, not the fact that ssh returned. This counted
            // every id it SENT as deleted, so on 2026-08-19 it reported purging
            // 100,153 messages while deleting none: postsuper is root-only and
            // we connect as an unprivileged user, so every chunk came back
            // "fatal: use of this command is reserved for the superuser" and
            // was counted as a success. A purge that cannot purge must say so.
            if (stripos($out, 'fatal:') !== false) {
                Log::error('Mail deferral purge: the relay refused postsuper', [
                    'target' => $target,
                    'error' => trim($out),
                    'deleted_before_failure' => $deleted,
                ]);

                throw new \RuntimeException('Relay refused postsuper: '.trim($out));
            }

            // postsuper reports "postsuper: Deleted: N messages" (singular for
            // one). Absent that line nothing was removed, whatever ssh said.
            if (preg_match('/Deleted:\s+(\d+)\s+message/i', $out, $m) === 1) {
                $deleted += (int) $m[1];
            } else {
                Log::error('Mail deferral purge: no deletion confirmed for chunk', [
                    'target' => $target,
                    'chunk' => count($chunk),
                    'output' => trim($out),
                ]);

                throw new \RuntimeException('Relay confirmed no deletions: '.trim($out));
            }
        }

        Log::warning('Mail deferral purge: asked relay to delete queued messages', [
            'target' => $target,
            'requested' => count($safe),
            'deleted' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * Can this relay actually delete queued mail for us?
     *
     * postsuper is root-only, and we connect as an unprivileged user. Without a
     * sudoers grant --purge cannot work at all, and the failure is easy to miss:
     * it surfaces as one "fatal: use of this command is reserved for the
     * superuser" per chunk, which the caller used to count as a success. Asked
     * up front, a relay that has not been granted the right says so plainly
     * instead of being diagnosed from a queue that never shrinks.
     *
     * @return bool|null null if we could not reach the relay to find out
     */
    public function canPurge(string $target): ?bool
    {
        // `sudo -l` ASKS whether the command is permitted and runs nothing. Do
        // not probe with `postsuper -s`: that is a real queue structure repair,
        // not a no-op.
        $script = "echo '".self::MARK_CANPURGE."'\n"
            .'if [ "$(id -u)" -eq 0 ]; then echo "CANPURGE root"; '
            .'elif sudo -n -l postsuper >/dev/null 2>&1; then echo "CANPURGE sudo"; '
            .'else echo "NOPURGE $(id -un)"; fi';

        $out = $this->runner->run($target, $script);

        if ($out === null || ! str_contains($out, self::MARK_CANPURGE)) {
            return null;
        }

        return str_contains($out, 'CANPURGE ');
    }

    /**
     * Ask a provider directly whether it is accepting our mail again.
     *
     * Release used to be inferred from organic traffic - "are messages to them
     * succeeding?" - which the system can destroy the evidence for. Suppression
     * exists to stop generating that mail, and a purge deletes what is already
     * queued, so a fully suppressed and purged provider produces neither
     * deliveries nor deferrals and can never look recovered. On 2026-08-19 only
     * 360 stragglers that arrived after the purge snapshot kept Yahoo
     * observable at all; a cleanly timed purge would have left the 24-hour
     * fail-open as the sole exit.
     *
     * So ask. EHLO + MAIL FROM + QUIT is an aborted transaction, which every
     * receiver sees constantly; it delivers nothing and costs one connection
     * per scan. It MUST run on the relay, because the throttle is against the
     * relay's sending ip - probing from anywhere else answers a question we did
     * not ask.
     *
     * @return bool|null true = accepting, false = still refusing, null = we
     *                   could not tell, so the caller should fall back to
     *                   organic evidence rather than assume either way
     */
    public function providerAccepting(string $target, string $domain, string $sender): ?bool
    {
        // Guarded: $domain reaches a shell on a production host.
        if (preg_match('/^[A-Za-z0-9.-]{3,253}$/', $domain) !== 1) {
            return null;
        }

        // echo the marker - a bare marker line would be run as a command, so
        // the section never appears in stdout and every probe reads as
        // "could not tell". That is exactly what production did at 08:01 on
        // 2026-08-19: it failed safe, and therefore silently.
        $script = "echo '".self::MARK_ACCEPTING."'\n".
            'python3 - '.escapeshellarg($domain).' '.escapeshellarg($sender).' <<\'PYEOF\'
import smtplib, socket, sys

domain, sender = sys.argv[1], sys.argv[2]

try:
    import subprocess
    mx = subprocess.run(["dig", "+short", "MX", domain], capture_output=True,
                        text=True, timeout=10).stdout.split()
    hosts = sorted((int(mx[i]), mx[i + 1].rstrip(".")) for i in range(0, len(mx) - 1, 2))
    if not hosts:
        print("UNKNOWN no-mx")
        sys.exit(0)
    host = hosts[0][1]
except Exception as e:
    print("UNKNOWN mx-lookup %s" % e)
    sys.exit(0)

try:
    s = smtplib.SMTP(timeout=20)
    s.connect(host, 25)
    s.ehlo("bulk2")
    code, msg = s.mail(sender)
    try:
        s.quit()
    except Exception:
        pass
    # 2xx to MAIL FROM means they are taking mail from this ip right now.
    # 4xx here is the throttle itself (421 4.7.0 [TSSnn]).
    if 200 <= code < 300:
        print("ACCEPTING %s %d" % (host, code))
    elif 400 <= code < 500:
        print("REFUSING %s %d %s" % (host, code, msg.decode("utf8", "replace")[:120]))
    else:
        print("UNKNOWN %s %d" % (host, code))
except (socket.timeout, OSError, smtplib.SMTPException) as e:
    # Could not reach them at all: that is our network or theirs being down,
    # not a verdict on our reputation.
    print("UNKNOWN %s %s" % (host, e))
PYEOF';

        $out = $this->runner->run($target, $script);

        if ($out === null || ! str_contains($out, self::MARK_ACCEPTING)) {
            Log::warning('Mail deferral probe: could not ask provider directly', [
                'target' => $target,
                'domain' => $domain,
            ]);

            return null;
        }

        if (str_contains($out, 'ACCEPTING ')) {
            Log::info('Mail deferral probe: provider is accepting again', [
                'domain' => $domain,
                'detail' => trim(str_replace(self::MARK_ACCEPTING, '', $out)),
            ]);

            return true;
        }

        if (str_contains($out, 'REFUSING ')) {
            return false;
        }

        return null;
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
