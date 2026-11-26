<?php

namespace Tests\Feature\Servers;

use App\Models\ServerSample;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * A small made-up Katapult account and its readings, shared by the server sizing tests.
 */
trait ServerSizingTestData
{
    protected function fakeKatapult(): void
    {
        config([
            'freegle.servers.katapult_token' => 'kp-token',
            'freegle.servers.katapult_api' => 'https://katapult.test/core/v1',
        ]);

        $vm = fn (string $id, string $name, string $state, string $package, string $packageName, array $ips) => [
            'virtual_machine' => [
                'id' => $id, 'name' => $name, 'hostname' => strtolower($name), 'state' => $state,
                'package' => ['id' => $package, 'name' => $packageName],
                'ip_addresses' => array_map(fn (string $ip) => ['address' => $ip], $ips),
            ],
        ];

        Http::fake([
            'katapult.test/core/v1/organizations' => Http::response(['organizations' => [['id' => 'org_1', 'name' => 'Test']]]),
            'katapult.test/core/v1/organizations/org_1/virtual_machines*' => Http::response([
                'pagination' => ['total_pages' => 1],
                'virtual_machines' => [
                    ['id' => 'vm_a', 'name' => 'Alpha', 'hostname' => 'alpha', 'package' => ['name' => 'C-36']],
                    ['id' => 'vm_b', 'name' => 'Beta', 'hostname' => 'beta', 'package' => ['name' => 'C-3']],
                    ['id' => 'vm_c', 'name' => 'Gamma', 'hostname' => 'gamma', 'package' => ['name' => 'C-1']],
                ],
            ]),
            'katapult.test/core/v1/virtual_machines/vm_a' => Http::response($vm('vm_a', 'Alpha', 'started', 'p36', 'C-36', ['192.0.2.1', '2001:db8::1'])),
            'katapult.test/core/v1/virtual_machines/vm_b' => Http::response($vm('vm_b', 'Beta', 'started', 'p3', 'C-3', ['192.0.2.2', '192.0.2.3'])),
            'katapult.test/core/v1/virtual_machines/vm_c' => Http::response($vm('vm_c', 'Gamma', 'stopped', 'p1', 'C-1', ['192.0.2.4'])),
            'katapult.test/core/v1/virtual_machine_packages*' => Http::response([
                'pagination' => ['total_pages' => 1],
                'virtual_machine_packages' => [
                    ['id' => 'p1', 'name' => 'C-1', 'cpu_cores' => 1, 'memory_in_gb' => 1, 'storage_in_gb' => 10, 'use_dedicated_cpus' => false],
                    ['id' => 'p3', 'name' => 'C-3', 'cpu_cores' => 1, 'memory_in_gb' => 3, 'storage_in_gb' => 25, 'use_dedicated_cpus' => false],
                    ['id' => 'p12', 'name' => 'C-12', 'cpu_cores' => 4, 'memory_in_gb' => 12, 'storage_in_gb' => 150, 'use_dedicated_cpus' => false],
                    ['id' => 'p36', 'name' => 'C-36', 'cpu_cores' => 12, 'memory_in_gb' => 36, 'storage_in_gb' => 300, 'use_dedicated_cpus' => false],
                    ['id' => 'p36d', 'name' => 'C-36 (Dedicated)', 'cpu_cores' => 12, 'memory_in_gb' => 36, 'storage_in_gb' => 300, 'use_dedicated_cpus' => true],
                ],
            ]),
            'katapult.test/core/v1/organizations/org_1/disks*' => Http::response([
                'pagination' => ['total_pages' => 1],
                'disk' => [
                    ['name' => 'System Disk', 'size_in_gb' => 300, 'state' => 'built', 'virtual_machine_disk' => ['virtual_machine' => ['id' => 'vm_a']]],
                    ['name' => 'System Disk', 'size_in_gb' => 50, 'state' => 'built', 'virtual_machine_disk' => ['virtual_machine' => ['id' => 'vm_b']]],
                    ['name' => 'System Disk', 'size_in_gb' => 150, 'state' => 'built', 'virtual_machine_disk' => ['virtual_machine' => ['id' => 'vm_c']]],
                    ['name' => 'old-cache', 'size_in_gb' => 200, 'state' => 'built'],
                ],
            ]),
            'katapult.test/core/v1/organizations/org_1/prices' => Http::response(['prices' => [
                ['resource' => 'virtual_machine_package.p1', 'price' => 8],
                ['resource' => 'virtual_machine_package.p3', 'price' => 12],
                ['resource' => 'virtual_machine_package.p12', 'price' => 44],
                ['resource' => 'virtual_machine_package.p36', 'price' => 136],
                ['resource' => 'disk_space.nvme', 'price' => 0.2],
                ['resource' => 'ip_address.ipv4', 'price' => 2],
                ['resource' => 'backups.usage', 'price' => 0.05],
            ]]),
        ]);
    }

    /**
     * Half-hourly readings for a host over the last $days days.
     */
    protected function seedSamples(string $host, int $days, int $cpus, float $load, int $memTotalMb, int $memUsedMb): void
    {
        $rows = [];
        $count = $days * 48;
        $now = Carbon::now();

        for ($i = 0; $i <= $count; $i++) {
            $rows[] = [
                'host' => $host,
                'sampled_at' => $now->copy()->subMinutes(30 * ($count - $i)),
                'cpus' => $cpus,
                'load1' => $load,
                'load5' => $load,
                'load15' => $load,
                'cpu_total' => ($i + 1) * 1000,
                'cpu_idle' => ($i + 1) * 900,
                'mem_total_mb' => $memTotalMb,
                'mem_available_mb' => $memTotalMb - $memUsedMb,
                'swap_total_mb' => 0,
                'swap_used_mb' => 0,
                'disks' => json_encode([['mount' => '/', 'size_gb' => 300.0, 'used_gb' => 120.0]]),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            ServerSample::insert($chunk);
        }
    }
}
