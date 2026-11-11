<?php

namespace App\Monitoring;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Judges whether a host is being patched and rebooted on schedule, and records
 * the verdict on the ModTools SysAdmin Cron Jobs tab (a `cron_job_status` row
 * named `host-patch <host>`, the same table `artisan cron:record` writes for
 * scripts/maintenance/freegle-maint).
 *
 * Pending security updates or a pending reboot are normal for most of a week,
 * so the fact alone is not an alert. What matters is how long it has been
 * pending. The row is a failure, and so a red number on SysAdmin, once
 * something has been pending for longer than the host's maintenance interval
 * plus a grace period (freegle.monitoring.patch_max_age_days and
 * patch_max_age_overrides). That covers every host, whether freegle-maint is
 * live on it or still doing dry runs, and the packages freegle-maint leaves
 * alone.
 *
 * "Pending since" is kept in the row itself, on its last line, as JSON after
 * "state:". That needs no schema change, survives a cache flush, and is read
 * back next run: the first probe that saw the condition starts the clock, and
 * a probe that does not see it stops the clock. It is a lower bound, since the
 * condition may have started before the first probe saw it, which is why a
 * pending reboot starts from the mtime of /var/run/reboot-required when that
 * is available. When the host cannot be reached nothing is recorded, so the
 * row stops being refreshed and the registry's interval turns it overdue.
 */
class HostPatchTracker
{
    /** The cron_job_status command is this prefix plus the bare host name. */
    public const JOB_PREFIX = 'host-patch ';

    private const STATE_MARKER = 'state: ';

    /**
     * Record this probe's verdict. Does nothing when the probe output carries
     * neither finding, so a garbled reply cannot overwrite a good row.
     */
    public function record(string $host, string $probeOutput, CarbonInterface $now): void
    {
        $hasReboot = preg_match('/^REBOOT:(yes|no)$/m', $probeOutput, $r) === 1;
        $hasSecurity = preg_match('/^SECURITY:(\d+)$/m', $probeOutput, $s) === 1;

        if (! $hasReboot && ! $hasSecurity) {
            return;
        }

        $job = self::JOB_PREFIX.$host;
        $previous = $this->previousState($job);

        $security = $hasSecurity ? (int) $s[1] : 0;
        $securitySince = null;
        if ($security > 0) {
            $securitySince = $this->parse($previous['security_since'] ?? null) ?? $now->copy();
        }

        $rebootSince = null;
        if ($hasReboot && $r[1] === 'yes') {
            $mtime = preg_match('/^REBOOT_SINCE:(\d+)$/m', $probeOutput, $m) ? (int) $m[1] : 0;
            $rebootSince = $this->parse($previous['reboot_since'] ?? null)
                ?? ($mtime > 0 ? Carbon::createFromTimestamp($mtime) : $now->copy());
        }

        $limitDays = $this->limitDays($host);
        $overdue = [];
        $pending = [];

        if ($securitySince !== null) {
            $text = "{$security} security update".($security === 1 ? '' : 's').' pending for '.$this->age($securitySince, $now);
            $pending[] = $text;
            if ($securitySince->lt($now->copy()->subDays($limitDays))) {
                $overdue[] = $text;
            }
        }

        if ($rebootSince !== null) {
            $text = 'reboot pending for '.$this->age($rebootSince, $now);
            $pending[] = $text;
            if ($rebootSince->lt($now->copy()->subDays($limitDays))) {
                $overdue[] = $text;
            }
        }

        if ($overdue !== []) {
            $summary = implode('; ', $overdue)." (more than {$limitDays} days is too long on {$host})";
        } elseif ($pending !== []) {
            $summary = implode('; ', $pending)." (up to {$limitDays} days is normal on {$host})";
        } else {
            $summary = 'up to date';
        }

        $state = json_encode(array_filter([
            'security_since' => $securitySince?->toIso8601String(),
            'reboot_since' => $rebootSince?->toIso8601String(),
        ]), JSON_UNESCAPED_SLASHES);

        DB::table('cron_job_status')->updateOrInsert(
            ['command' => $job],
            [
                'last_run_at' => $now,
                'last_finished_at' => $now,
                'last_exit_code' => $overdue === [] ? 0 : 1,
                'last_output' => $summary."\n\n".self::STATE_MARKER.$state,
                'updated_at' => $now,
            ]
        );
    }

    /**
     * Days something may stay pending on this host. The default suits the
     * weekly hosts: a 7 day maintenance interval plus 2 days of grace. db2 and
     * db3 are patched in alternate weeks, so each is done every 14 days:
     * 14 plus 2. Overrides are "host=days,host=days" in
     * freegle.monitoring.patch_max_age_overrides, keyed by the bare host name.
     */
    public function limitDays(string $host): int
    {
        foreach (explode(',', (string) config('freegle.monitoring.patch_max_age_overrides', '')) as $pair) {
            [$name, $days] = array_pad(explode('=', trim($pair), 2), 2, null);

            if ($name !== null && trim($name) === $host && is_numeric($days) && (int) $days > 0) {
                return (int) $days;
            }
        }

        return (int) config('freegle.monitoring.patch_max_age_days', 9);
    }

    /** @return array<string, mixed> */
    private function previousState(string $job): array
    {
        $output = DB::table('cron_job_status')->where('command', $job)->value('last_output');

        if (! is_string($output)) {
            return [];
        }

        $pos = strrpos($output, self::STATE_MARKER);
        if ($pos === false) {
            return [];
        }

        $decoded = json_decode(substr($output, $pos + strlen(self::STATE_MARKER)), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function parse(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function age(CarbonInterface $since, CarbonInterface $now): string
    {
        $hours = (int) floor(max(0, $now->getTimestamp() - $since->getTimestamp()) / 3600);

        if ($hours < 24) {
            return $hours === 1 ? '1 hour' : "{$hours} hours";
        }

        $days = intdiv($hours, 24);

        return $days === 1 ? '1 day' : "{$days} days";
    }
}
