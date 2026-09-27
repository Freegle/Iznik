<?php

namespace App\Services\Mail;

use App\Monitoring\HostCommandRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turn the outbound relay's maillog into logs_emails rows.
 *
 * This is the port of V1's scripts/cron/eximlogs.php, which ran from root's
 * crontab on the relay every ten minutes. It is the ONLY producer of
 * logs_emails, and that table has three live consumers: the mod-only "recent
 * emails to this user" endpoint (iznik-server-go user.go), the GDPR user dump
 * (userdump/collect_db.go, up to 20,000 rows) and the AI support helper, which
 * reads an ABSENT row as "we never sent it" and a 250 as "the recipient's MX
 * accepted it". So the rows are member-visible and they are used to answer
 * complaints; a wrong status here becomes a wrong answer to a member.
 *
 * Two things differ from V1, both deliberate.
 *
 * 1. IT READS ONLY WHAT IS NEW. V1 opened the whole maillog every run and
 *    skipped lines by timestamp - a 3.3GB read every ten minutes on a 2GB box
 *    whose page cache cannot hold it, and the reason its stdout file had grown
 *    to 1.38GB of "Unmatched line". We keep a byte offset and fetch only the
 *    bytes appended since, which is about 5MB a run.
 *
 * 2. IT KNOWS THE RELAY HAS TWO POSTFIX INSTANCES. Throttled providers are not
 *    delivered by the primary at all: it hands them to a second instance over
 *    a loopback SMTP hop, and THAT HOP LOGS LIKE A DELIVERY -
 *    `to=<someone@yahoo.com> ... status=sent (250 ... queued as <id>)` - while
 *    having reached nothing but our own second instance. Recorded as a send it
 *    would tell the support helper "yes, accepted by their mail server" about a
 *    message the provider may not see for hours, which is exactly the question
 *    the helper exists to answer. So a handover recipient is not a delivery and
 *    is not recorded; the second instance logs the real outcome under its own
 *    queue id, with its own Subject line (header_checks is enabled there for
 *    that reason), and that is the row that gets written.
 */
class RelayLogIngestService
{
    /** Where we got to in the relay's maillog, so we only fetch new bytes. */
    private const OFFSET_KEY = 'mail.relaylog.offset';

    /** Lines whose only content is connection noise; V1 skipped these too. */
    private const NOISE = '/(disconnect from)|(connect to)|(connect from)|(timeout after)|(lost connection after)|(configuration reloaded)/';

    /** `<15 char date> <host> <proc>: <queue id>: <rest>` */
    private const LINE = '/^(.{15}) (\S+) (\S+?): ([A-F0-9]{6,16}): (.*)$/';

    public function __construct(
        private readonly HostCommandRunner $runner,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config('freegle.mail.relay_logs.enabled')
            && config('freegle.mail.relay_logs.host') !== '';
    }

    /**
     * Fetch, parse and record. Returns counts for the command to report.
     */
    public function ingest(bool $dryRun = false): array
    {
        if (!$this->enabled()) {
            return ['skipped' => true, 'written' => 0, 'handovers' => 0, 'lines' => 0];
        }

        $slice = $this->fetch();
        if ($slice === null) {
            // Do NOT advance the offset on a failed fetch: the next run should
            // re-read the same bytes rather than skip them silently.
            return ['failed' => true, 'written' => 0, 'handovers' => 0, 'lines' => 0];
        }

        [$offset, $lines] = $slice;
        $parsed = $this->parseLines($lines);

        $written = 0;
        foreach ($parsed['records'] as $record) {
            if ($dryRun) {
                $written++;

                continue;
            }
            if ($this->record($record)) {
                $written++;
            }
        }

        // A dry run must not move the offset either, or it would hide the
        // lines it just reported from the next real run.
        if (!$dryRun) {
            Cache::forever(self::OFFSET_KEY, $offset);
        }

        return [
            'written' => $written,
            'handovers' => $parsed['handovers'],
            'lines' => count($lines),
            'offset' => $offset,
        ];
    }

    /**
     * How long the relay's maillog is right now, so a first run knows where to
     * start. One cheap round trip; null if the relay could not answer.
     */
    private function logSize(): ?int
    {
        $log = escapeshellarg((string) config('freegle.mail.relay_logs.path'));
        $out = $this->runner->run(
            (string) config('freegle.mail.relay_logs.host'),
            "echo \"SIZE \$(stat -c %s $log 2>/dev/null || echo 0)\""
        );

        if ($out === null || preg_match('/SIZE (\d+)/', $out, $m) !== 1) {
            Log::warning('Relay log ingest: could not read the log size');

            return null;
        }

        return (int) $m[1];
    }

    /**
     * Pull the bytes appended to the relay's maillog since we last looked.
     *
     * @return array{0:int,1:array<int,string>}|null [new offset, lines]
     */
    private function fetch(): ?array
    {
        $offset = Cache::get(self::OFFSET_KEY);

        // COLD START. With no offset we do not want history: we want to keep up
        // from here. Asking for "everything up to the cap" on a first run means
        // dragging the cap's worth of log through ssh and into memory to record
        // deliveries that have already happened - which is both the slowest
        // possible request and the least useful. So learn where the end is and
        // start from there next run.
        if ($offset === null) {
            $end = $this->logSize();
            if ($end === null) {
                return null;
            }
            Cache::forever(self::OFFSET_KEY, $end);
            Log::info('Relay log ingest: first run, starting from the end of the log', ['offset' => $end]);

            return [$end, []];
        }

        $offset = (int) $offset;
        $log = config('freegle.mail.relay_logs.path');
        $max = (int) config('freegle.mail.relay_logs.max_slice_bytes');

        // Rotation is detected by the file being SHORTER than our offset, in
        // which case the offset points past the end of a new file and we start
        // again from its beginning rather than re-reading the whole archive.
        //
        // The cap exists for the case where the offset is lost (a flushed
        // cache): without it the first run after that would try to pull 3.3GB
        // through ssh and into PHP's memory. Skipping ahead loses history,
        // which is the right trade - the alternative is an OOM every run,
        // forever, and no rows at all.
        $script = <<<SH
LOG=%LOG%
SZ=\$(stat -c %s "\$LOG" 2>/dev/null || echo 0)
OFF=%OFF%
[ "\$SZ" -lt "\$OFF" ] && OFF=0
MAX=%MAX%
if [ \$(( SZ - OFF )) -gt "\$MAX" ]; then OFF=\$(( SZ - MAX )); fi
echo "OFFSET \$SZ"
[ "\$SZ" -le "\$OFF" ] && exit 0
tail -c +\$(( OFF + 1 )) "\$LOG" 2>/dev/null | head -c \$(( SZ - OFF ))
SH;
        $script = str_replace(
            ['%LOG%', '%OFF%', '%MAX%'],
            [escapeshellarg($log), (string) $offset, (string) $max],
            $script
        );

        $out = $this->runner->run((string) config('freegle.mail.relay_logs.host'), $script);
        if ($out === null || $out === '') {
            Log::warning('relay log ingest: no output from relay');

            return null;
        }

        $lines = explode("\n", $out);
        $header = array_shift($lines);
        if (!preg_match('/^OFFSET (\d+)$/', (string) $header, $m)) {
            // Without the header we do not know where we got to, and guessing
            // would either re-read or skip. Treat it as a failed run.
            Log::warning('relay log ingest: relay did not report an offset');

            return null;
        }

        return [(int) $m[1], $lines];
    }

    /**
     * Group log lines into per-message records. Pure - no IO, so the whole of
     * the interesting behaviour is testable without a relay.
     *
     * @param  array<int,string>  $lines
     * @return array{records:array<string,array<string,mixed>>,handovers:int}
     */
    public function parseLines(array $lines): array
    {
        $port = (int) config('freegle.mail.relay_logs.handover_port');
        $transport = (string) config('freegle.mail.relay_logs.handover_transport');

        $msgs = [];
        $handover = [];
        $handovers = 0;
        $done = [];

        foreach ($lines as $line) {
            if ($line === '' || preg_match(self::NOISE, $line)) {
                continue;
            }
            if (!preg_match(self::LINE, $line, $m)) {
                continue;
            }

            [, $date, , $proc, $qid, $log] = $m;

            if (!array_key_exists($qid, $msgs)) {
                $msgs[$qid] = ['date' => $date, 'eximid' => $qid];
            }

            if ($log === 'removed') {
                $msgs[$qid]['date'] = $date;
                // Finished with this queue id. A record whose ONLY recipient
                // was the loopback handover describes a hop, not a delivery,
                // and the real outcome will be logged by the other instance
                // under its own queue id - so there is nothing to write here.
                if (!isset($handover[$qid]) || isset($msgs[$qid]['to'])) {
                    $done[$qid] = $msgs[$qid];
                }
                unset($msgs[$qid]);

                continue;
            }

            if (preg_match('/info: header Subject: (.*) from /', $line, $m2)) {
                $msgs[$qid]['subject'] = $m2[1];
            } elseif (preg_match('/message-id=<(.*)>/', $line, $m2)) {
                $msgs[$qid]['messageid'] = $m2[1];
            } elseif (preg_match('/from=<(.*)>,/', $line, $m2)) {
                $msgs[$qid]['from'] = $m2[1];
            } elseif (preg_match('/to=<(.*)>.*status=(.*)$/', $line, $m2)) {
                // Is this recipient the loopback hop into the other instance
                // rather than a real delivery? Two independent signals, so that
                // one going stale on its own - a renamed transport, a changed
                // port - cannot quietly turn hops back into "sent".
                $isHandover = str_contains($line, "relay=127.0.0.1[127.0.0.1]:$port")
                    || str_starts_with($proc, "postfix-$transport/");

                if ($isHandover) {
                    $handover[$qid] = true;
                    $handovers++;

                    continue;
                }

                $msgs[$qid]['to'] = $m2[1];
                $msgs[$qid]['status'] = $m2[2];
            }
        }

        // Messages still in flight when the slice ended. V1 wrote these too and
        // filled them in on a later run, which is what keeps a row present for
        // a message that is merely slow. Anything that is only a handover is
        // still skipped.
        foreach ($msgs as $qid => $msg) {
            if (isset($handover[$qid]) && !isset($msg['to'])) {
                continue;
            }
            $done[$qid] = $msg;
        }

        return ['records' => $done, 'handovers' => $handovers];
    }

    /**
     * Insert the row, or fill in fields we did not have last time.
     *
     * Keyed on the queue id, because a message's lines can straddle two runs
     * and we would otherwise write it twice.
     *
     * Query builder rather than Eloquent, deliberately: logs_emails is an
     * append-only log with no model and no auditing (PurgeService touches it
     * the same way), and a run writes thousands of rows. The house rule about
     * preferring Eloquent exists so model events fire for auditing, and there
     * is nothing here for them to fire on.
     */
    private function record(array $msg): bool
    {
        $when = strtotime($msg['date'] ?? '');
        if ($when === false || $when > time() + 60) {
            // V1 hit this every New Year: a "Dec 31" line parsed after midnight
            // reads as eleven months in the future. Dropping it loses one row.
            return false;
        }

        $existing = DB::table('logs_emails')->where('eximid', $msg['eximid'])->first();

        $fields = [];
        foreach (['from', 'to', 'messageid', 'subject', 'status'] as $key) {
            if (isset($msg[$key]) && $msg[$key] !== '') {
                $fields[$key] = mb_substr((string) $msg[$key], 0, 255);
            }
        }

        if ($existing === null) {
            $userid = isset($fields['to'])
                ? DB::table('users_emails')->where('email', $fields['to'])->value('userid')
                : null;

            DB::table('logs_emails')->insert($fields + [
                'timestamp' => date('Y-m-d H:i:s', $when),
                'eximid' => $msg['eximid'],
                'userid' => $userid,
            ]);

            return true;
        }

        // Only ever ADD what we did not know; never overwrite, so a later
        // partial line cannot blank a status we already recorded.
        $update = [];
        foreach ($fields as $key => $value) {
            if (($existing->$key ?? null) === null || $existing->$key === '') {
                $update[$key] = $value;
            }
        }
        if (!isset($existing->userid) && isset($fields['to'])) {
            $uid = DB::table('users_emails')->where('email', $fields['to'])->value('userid');
            if ($uid) {
                $update['userid'] = $uid;
            }
        }
        if ($update !== []) {
            DB::table('logs_emails')->where('id', $existing->id)->update($update);

            return true;
        }

        return false;
    }
}
