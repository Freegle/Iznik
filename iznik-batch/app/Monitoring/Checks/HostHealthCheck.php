<?php

namespace App\Monitoring\Checks;

use App\Monitoring\HostCommandRunner;
use App\Monitoring\OutcomeResult;
use Carbon\CarbonInterface;

/**
 * Per-host OS/service health, ported from V1's scripts/cron/status.php — the
 * ssh-round-the-estate cron that died with the V1 PHP removal and left the
 * ModTools status dot blind to host state (reboot-required sat unreported on
 * every host). It checks what V1 checked, where the check still applies:
 *
 *  - pending security updates  (V1: "Security patches to apply")   → warning
 *  - /var/run/reboot-required  (V1: "Server reboot required")      → warning
 *  - monit summary             (V1: per-line OK-pattern match)     → warning/error
 *
 * V1's beanstalkd, exim-queue and V1-spool checks are not ported: beanstalkd
 * and the V1 spool went with the V1 removal, and mail health has its own
 * monitoring (email:health).
 *
 * The ssh target comes from configuration (FREEGLE_MONITORING_HOSTS) so the
 * estate's topology never appears in the codebase.
 */
class HostHealthCheck extends AbstractOutcomeCheck
{
    /**
     * One ssh round-trip gathers everything. Markers keep the parse
     * independent of shell noise, and the monit block is fenced so its lines
     * can't be confused with the scalar findings. `monit summary -B` is batch
     * (plain-text) format — no box-drawing characters to parse around.
     */
    public const PROBE = <<<'SH'
echo "REBOOT:$([ -f /var/run/reboot-required ] && echo yes || echo no)"
echo "REBOOT_PKGS:$(sort -u /var/run/reboot-required.pkgs 2>/dev/null | head -3 | tr '\n' ' ')"
echo "SECURITY:$(apt-get upgrade -s 2>/dev/null | grep -c '^Inst.*[Ss]ecurit')"
if command -v monit >/dev/null 2>&1; then echo "MONIT_BEGIN"; monit summary -B 2>&1; echo "MONIT_END"; else echo "MONIT_ABSENT"; fi
SH;

    /**
     * Monit statuses that mean the service is fine. V1's pattern list, plus
     * "Waiting" (a Program between runs).
     */
    private const MONIT_OK = [
        'Online with all services',
        'Status ok',
        'Accessible',
        'Running',
        'Waiting',
        'OK',
    ];

    /**
     * Monit statuses that need attention but don't mean the service is down:
     * "Not monitored" (V1 treated it as a warning too), "Initializing" (first
     * cycle after a monit restart) and "Resource limit matched" (service up,
     * resource rule breached). Anything matching neither list is an error.
     */
    private const MONIT_WARNING = [
        'Resource limit matched',
        'Not monitored',
        'Initializing',
    ];

    private readonly string $host;

    public function __construct(
        private readonly string $target,
        private readonly HostCommandRunner $runner,
    ) {
        // Slug on the bare host, not the ssh target — "root@" is transport
        // detail, and the slug is what the ModTools status modal displays.
        $at = strrpos($target, '@');
        $this->host = $at === false ? $target : substr($target, $at + 1);

        $this->slug = "host:{$this->host}";
        $this->category = 'host-health';
        $this->description = "OS and service health on {$this->host}";
    }

    protected function check(CarbonInterface $now): OutcomeResult
    {
        $output = $this->runner->run($this->target, self::PROBE);

        if ($output === null) {
            return OutcomeResult::breach(
                $this->slug,
                "{$this->host} is unreachable over ssh — host down, or the monitoring key/route is not set up",
                'warning',
            );
        }

        [$errors, $warnings] = $this->interpret($output);

        if (! empty($errors)) {
            // Warnings ride along so the modal shows the whole host picture.
            return OutcomeResult::breach($this->slug, implode('; ', array_merge($errors, $warnings)), 'error');
        }

        if (! empty($warnings)) {
            return OutcomeResult::breach($this->slug, implode('; ', $warnings), 'warning');
        }

        return OutcomeResult::ok($this->slug, "{$this->host} healthy (no reboot needed, no pending security updates)");
    }

    /**
     * @return array{0: list<string>, 1: list<string>} [errors, warnings]
     */
    private function interpret(string $output): array
    {
        $errors = [];
        $warnings = [];

        if (preg_match('/^REBOOT:yes$/m', $output)) {
            $pkgs = '';
            if (preg_match('/^REBOOT_PKGS:(.+)$/m', $output, $m) && trim($m[1]) !== '') {
                // Versioned kernel package names (linux-image-5.15.0-186-generic) say
                // nothing a mod acts on and change every kernel: strip the version
                // segments so the modal reads "linux-image-generic", and dedupe in
                // case two kernel versions are pending at once.
                $names = array_unique(array_map(
                    fn (string $pkg): string => preg_replace('/-\d+(?:\.\d+)*(?:-\d+)?/', '', $pkg),
                    preg_split('/\s+/', trim($m[1]))
                ));
                $pkgs = ' (' . implode(' ', $names) . ')';
            }
            $warnings[] = "reboot required{$pkgs}";
        }

        if (preg_match('/^SECURITY:(\d+)$/m', $output, $m) && (int) $m[1] > 0) {
            $warnings[] = "{$m[1]} security update(s) to apply";
        }

        if (preg_match('/^MONIT_BEGIN$(.*?)^MONIT_END$/ms', $output, $m)) {
            [$monitErrors, $monitWarnings] = $this->interpretMonit($m[1]);
            $errors = array_merge($errors, $monitErrors);
            $warnings = array_merge($warnings, $monitWarnings);
        }
        // MONIT_ABSENT: fine — not every host runs monit, same as V1's
        // tolerance of hosts without exim.

        return [$errors, $warnings];
    }

    /**
     * Classify each service line of `monit summary -B` output. V1 pattern-
     * matched each line against known-good statuses and alarmed on the rest;
     * we do the same but with an explicit warning tier for states that don't
     * mean the service is down.
     *
     * @return array{0: list<string>, 1: list<string>} [errors, warnings]
     */
    private function interpretMonit(string $monitOutput): array
    {
        $errors = [];
        $warnings = [];
        $sawDaemonHeader = false;

        foreach (preg_split('/\R/', $monitOutput) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, 'Service Name')) {
                continue;
            }

            if (str_starts_with($line, 'Monit ')) {
                $sawDaemonHeader = true;
                continue;
            }

            $matched = false;
            foreach (self::MONIT_WARNING as $status) {
                if (str_contains($line, $status)) {
                    $warnings[] = "monit: " . preg_replace('/\s{2,}/', ' ', $line);
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                foreach (self::MONIT_OK as $status) {
                    if (str_contains($line, $status)) {
                        $matched = true;
                        break;
                    }
                }
            }

            if (! $matched) {
                // Includes "Does not exist", "Execution failed", and monit's
                // "Status not available -- the monit daemon is not running".
                $errors[] = "monit: " . preg_replace('/\s{2,}/', ' ', $line);
            }
        }

        // Output that never identified itself as monit at all (V1: no "Monit"
        // in the output). The daemon-not-running message is already an error
        // from the loop above; this catches a mangled or empty summary. A dead
        // monit is an ERROR, not the warning V1 gave it: monit is what
        // restarts the API services, and a silent monit death has caused a
        // real outage before.
        if (! $sawDaemonHeader && empty($errors) && empty($warnings)) {
            $errors[] = 'monit: summary produced no recognisable output — daemon dead?';
        }

        return [$errors, $warnings];
    }
}
