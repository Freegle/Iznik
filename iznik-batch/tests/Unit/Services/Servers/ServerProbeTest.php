<?php

namespace Tests\Unit\Services\Servers;

use App\Services\Servers\ServerProbe;
use Tests\TestCase;

/**
 * ServerProbe::parse reads the marked lines servers:sample gets back from a host. A reading with a
 * piece missing is refused whole, so a half-answered probe never becomes a row of zeros.
 */
class ServerProbeTest extends TestCase
{
    private function probeOutput(array $replace = []): string
    {
        $lines = array_merge([
            'HOST' => 'HOST:db-9',
            'CPUS' => 'CPUS:8',
            'LOAD' => 'LOAD:8.43 8.27 8.05 15/439 87602',
            'STAT' => 'STAT:cpu  1000 50 400 8000 500 10 20 20 0 0',
            'MEM' => "MEM:MemTotal:       24000000 kB\nMEM:MemAvailable:    6000000 kB\nMEM:SwapTotal:       4194304 kB\nMEM:SwapFree:        2097152 kB",
            'DISK' => 'DISK:/dev/vda1 '.(300 * 1024 ** 3).' '.(210 * 1024 ** 3).' /',
        ], $replace);

        return "Welcome banner\n".implode("\n", array_filter($lines))."\ntrailing noise\n";
    }

    public function test_a_complete_reading_is_parsed(): void
    {
        $reading = (new ServerProbe)->parse($this->probeOutput());

        $this->assertSame('db-9', $reading['host']);
        $this->assertSame(8, $reading['cpus']);
        $this->assertSame([8.43, 8.27, 8.05], [$reading['load1'], $reading['load5'], $reading['load15']]);
        // user+nice+system+idle+iowait+irq+softirq+steal; idle counts waiting for disk too.
        $this->assertSame(10000, $reading['cpu_total']);
        $this->assertSame(8500, $reading['cpu_idle']);
        $this->assertSame(23437, $reading['mem_total_mb']);
        $this->assertSame(5859, $reading['mem_available_mb']);
        $this->assertSame(4096, $reading['swap_total_mb']);
        $this->assertSame(2048, $reading['swap_used_mb']);
        $this->assertSame([['mount' => '/', 'size_gb' => 300.0, 'used_gb' => 210.0]], $reading['disks']);
    }

    public function test_one_device_mounted_twice_is_one_disk_and_small_partitions_are_left_out(): void
    {
        $gb = 1024 ** 3;
        $reading = (new ServerProbe)->parse($this->probeOutput(['DISK' => implode("\n", [
            'DISK:/dev/vda1 '.(100 * $gb).' '.(40 * $gb).' /',
            'DISK:/dev/vda1 '.(100 * $gb).' '.(40 * $gb).' /var/lib/docker/bind',
            'DISK:/dev/vda15 '.(100 * 1024 ** 2).' '.(6 * 1024 ** 2).' /boot/efi',
            'DISK:/dev/vdb '.(75 * $gb).' '.(30 * $gb).' /mnt/cache data',
        ])]));

        $this->assertSame(['/', '/mnt/cache data'], array_column($reading['disks'], 'mount'));
    }

    public function test_a_host_with_no_swap_reads_as_none_used(): void
    {
        $reading = (new ServerProbe)->parse($this->probeOutput([
            'MEM' => "MEM:MemTotal:       3000000 kB\nMEM:MemAvailable:    2000000 kB",
        ]));

        $this->assertSame(0, $reading['swap_total_mb']);
        $this->assertSame(0, $reading['swap_used_mb']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('incompleteOutputs')]
    public function test_an_incomplete_reading_is_refused(array $replace): void
    {
        $this->assertNull((new ServerProbe)->parse($this->probeOutput($replace)));
    }

    public static function incompleteOutputs(): array
    {
        return [
            'no host' => [['HOST' => 'HOST:']],
            'no processor count' => [['CPUS' => 'CPUS:many']],
            'no load' => [['LOAD' => 'LOAD:']],
            'processor line of another shape' => [['STAT' => 'STAT:intr 1 2 3 4 5 6']],
            'no memory' => [['MEM' => null]],
        ];
    }

    public function test_empty_output_is_refused(): void
    {
        $this->assertNull((new ServerProbe)->parse(''));
    }
}
