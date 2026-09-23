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
     * independent of shell noise, and the monit blocks are fenced so their
     * lines can't be confused with the scalar findings. `monit summary -B` is
     * batch (plain-text) format — no box-drawing characters to parse around.
     * `monit status -B` follows it because the summary does not say why a
     * service is "Not monitored": a service in monit's manual mode is one a
     * person switched off on purpose, and only the status output carries the
     * mode.
     */
    public const PROBE = <<<'SH'
echo "REBOOT:$([ -f /var/run/reboot-required ] && echo yes || echo no)"
echo "SECURITY:$(apt-get upgrade -s 2>/dev/null | grep -c '^Inst.*[Ss]ecurit')"
if command -v monit >/dev/null 2>&1; then echo "MONIT_BEGIN"; monit summary -B 2>&1; echo "MONIT_END"; echo "MONIT_STATUS_BEGIN"; monit status -B 2>/dev/null; echo "MONIT_STATUS_END"; else echo "MONIT_ABSENT"; fi
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
     *
     * One exception: "Not monitored" on a service whose monitoring mode is
     * `manual` is the state a person put it in (a retired service whose
     * configuration is kept in place), not a fault, so it is reported as held
     * rather than as a warning.
     */
    private const MONIT_WARNING = [
        'Resource limit matched',
        'Not monitored',
        'Initializing',
    ];

    private const MONIT_MODE_MANUAL = 'manual';

    /** Services found "Not monitored" in manual mode on the last run. @var list<string> */
    private array $held = [];

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

        $held = $this->held === []
            ? ''
            : '; monit holds ' . implode(', ', $this->held) . ' in manual mode';

        return OutcomeResult::ok($this->slug, "{$this->host} healthy (no reboot needed, no pending security updates{$held})");
    }

    /**
     * @return array{0: list<string>, 1: list<string>} [errors, warnings]
     */
    private function interpret(string $output): array
    {
        $errors = [];
        $warnings = [];

        if (preg_match('/^REBOOT:yes$/m', $output)) {
            // Just the fact. The package list (even version-stripped) tells a
            // mod nothing they act on — the action is identical whatever
            // triggered the flag, and the operator who reboots can read
            // /var/run/reboot-required.pkgs on the host.
            $warnings[] = 'reboot required';
        }

        if (preg_match('/^SECURITY:(\d+)$/m', $output, $m) && (int) $m[1] > 0) {
            $warnings[] = "{$m[1]} security update(s) to apply";
        }

        if (preg_match('/^MONIT_BEGIN$(.*?)^MONIT_END$/ms', $output, $m)) {
            $modes = preg_match('/^MONIT_STATUS_BEGIN$(.*?)^MONIT_STATUS_END$/ms', $output, $s)
                ? $this->monitoringModes($s[1])
                : [];
            [$monitErrors, $monitWarnings] = $this->interpretMonit($m[1], $modes);
            $errors = array_merge($errors, $monitErrors);
            $warnings = array_merge($warnings, $monitWarnings);
        }
        // MONIT_ABSENT: fine — not every host runs monit, same as V1's
        // tolerance of hosts without exim.

        return [$errors, $warnings];
    }

    /**
     * Service name → monitoring mode (active, passive, manual) from
     * `monit status -B`, whose output is one block per service headed by
     * `<Type> '<name>'` with a `monitoring mode <mode>` line inside it.
     *
     * @return array<string, string>
     */
    private function monitoringModes(string $statusOutput): array
    {
        $modes = [];
        $service = null;

        foreach (preg_split('/\R/', $statusOutput) as $line) {
            if (preg_match("/^\\S.*?'([^']+)'\\s*$/", $line, $m)) {
                $service = $m[1];
            } elseif ($service !== null && preg_match('/^\s*monitoring mode\s+(\S+)/', $line, $m)) {
                $modes[$service] = strtolower($m[1]);
            }
        }

        return $modes;
    }

    /**
     * Classify each service line of `monit summary -B` output. V1 pattern-
     * matched each line against known-good statuses and alarmed on the rest;
     * we do the same but with an explicit warning tier for states that don't
     * mean the service is down.
     *
     * @param  array<string, string>  $modes  service name → monitoring mode
     * @return array{0: list<string>, 1: list<string>} [errors, warnings]
     */
    private function interpretMonit(string $monitOutput, array $modes = []): array
    {
        $errors = [];
        $warnings = [];
        $this->held = [];
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

            $service = preg_split('/\s{2,}/', $line)[0];
            if (str_contains($line, 'Not monitored') && ($modes[$service] ?? null) === self::MONIT_MODE_MANUAL) {
                $this->held[] = $service;
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
