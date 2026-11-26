<?php

namespace App\Services\Servers;

/**
 * The one command servers:sample runs on each host, and how its output is read.
 *
 * Every line carries a marker, as HostHealthCheck's probe does, so shell noise before or after
 * cannot be mistaken for a reading. Only real disks are listed: memory, container and network
 * filesystems are left out because they are not Katapult disk we pay for.
 */
class ServerProbe
{
    public const SCRIPT = <<<'SH'
echo "HOST:$(hostname)"
echo "CPUS:$(nproc)"
echo "LOAD:$(cat /proc/loadavg)"
echo "STAT:$(head -1 /proc/stat)"
grep -E '^(MemTotal|MemAvailable|SwapTotal|SwapFree):' /proc/meminfo | sed 's/^/MEM:/'
df -B1 -x tmpfs -x devtmpfs -x overlay -x squashfs -x nfs -x nfs4 -x efivarfs --output=source,size,used,target 2>/dev/null | tail -n +2 | sed 's/^/DISK:/'
SH;

    /** Filesystems smaller than this are boot and firmware partitions, not somewhere data grows. */
    private const MIN_DISK_GB = 1.0;

    /**
     * @return array{host: string, cpus: int, load1: float, load5: float, load15: float, cpu_total: int,
     *   cpu_idle: int, mem_total_mb: int, mem_available_mb: int, swap_total_mb: int, swap_used_mb: int,
     *   disks: list<array{mount: string, size_gb: float, used_gb: float}>}|null null when the output is
     *   not a complete reading
     */
    public function parse(string $output): ?array
    {
        $host = null;
        $cpus = null;
        $load = null;
        $stat = null;
        $mem = [];
        $disks = [];
        $seenSources = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (! preg_match('/^(HOST|CPUS|LOAD|STAT|MEM|DISK):(.*)$/', $line, $m)) {
                continue;
            }
            $value = trim($m[2]);

            switch ($m[1]) {
                case 'HOST':
                    $host = $value !== '' ? $value : null;
                    break;
                case 'CPUS':
                    $cpus = ctype_digit($value) ? (int) $value : null;
                    break;
                case 'LOAD':
                    $parts = preg_split('/\s+/', $value) ?: [];
                    if (count($parts) >= 3 && is_numeric($parts[0]) && is_numeric($parts[1]) && is_numeric($parts[2])) {
                        $load = [(float) $parts[0], (float) $parts[1], (float) $parts[2]];
                    }
                    break;
                case 'STAT':
                    $stat = $this->parseStat($value);
                    break;
                case 'MEM':
                    if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $value, $mm)) {
                        $mem[$mm[1]] = (int) $mm[2];
                    }
                    break;
                case 'DISK':
                    // source, size, used, target. A mount point may hold spaces, so it is the rest.
                    if (preg_match('/^(\S+)\s+(\d+)\s+(\d+)\s+(.+)$/', $value, $dm)) {
                        // One device mounted in several places is one disk.
                        if (isset($seenSources[$dm[1]])) {
                            break;
                        }
                        $seenSources[$dm[1]] = true;
                        $sizeGb = (int) $dm[2] / 1024 ** 3;
                        if ($sizeGb >= self::MIN_DISK_GB) {
                            $disks[] = [
                                'mount' => $dm[4],
                                'size_gb' => round($sizeGb, 1),
                                'used_gb' => round((int) $dm[3] / 1024 ** 3, 1),
                            ];
                        }
                    }
                    break;
            }
        }

        if ($host === null || ! $cpus || $load === null || $stat === null
            || ! isset($mem['MemTotal'], $mem['MemAvailable'])) {
            return null;
        }

        $swapTotal = $mem['SwapTotal'] ?? 0;
        $swapFree = $mem['SwapFree'] ?? 0;

        return [
            'host' => $host,
            'cpus' => $cpus,
            'load1' => $load[0],
            'load5' => $load[1],
            'load15' => $load[2],
            'cpu_total' => $stat['total'],
            'cpu_idle' => $stat['idle'],
            'mem_total_mb' => intdiv($mem['MemTotal'], 1024),
            'mem_available_mb' => intdiv($mem['MemAvailable'], 1024),
            'swap_total_mb' => intdiv($swapTotal, 1024),
            'swap_used_mb' => intdiv(max(0, $swapTotal - $swapFree), 1024),
            'disks' => $disks,
        ];
    }

    /**
     * The first line of /proc/stat: "cpu user nice system idle iowait irq softirq steal ...".
     * Waiting for disk counts as idle, because a bigger processor would not help it.
     *
     * @return array{total: int, idle: int}|null
     */
    private function parseStat(string $value): ?array
    {
        $parts = preg_split('/\s+/', $value) ?: [];
        if (($parts[0] ?? '') !== 'cpu' || count($parts) < 6) {
            return null;
        }

        $numbers = array_map('intval', array_slice($parts, 1, 8));

        return [
            'total' => array_sum($numbers),
            'idle' => $numbers[3] + ($numbers[4] ?? 0),
        ];
    }
}
