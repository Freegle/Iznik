<?php

namespace App\Services\Servers;

use Illuminate\Support\Facades\Http;

/**
 * Reads what we rent from Katapult (Krystal Cloud): the machines, the packages on offer, the disks
 * and the prices for our organisation. Read only; nothing here changes a machine.
 *
 * The organisation is looked up from the token, so no account identifier lives in the code.
 */
class KatapultClient
{
    private ?string $organisation = null;

    public function configured(): bool
    {
        return $this->token() !== '';
    }

    /**
     * Every machine, with its state, package and addresses.
     *
     * @return list<array{id: string, name: string, hostname: string, state: string, package_id: ?string,
     *   package_name: ?string, ipv4: int}>
     */
    public function machines(): array
    {
        $machines = [];

        foreach ($this->all('organizations/'.$this->organisation().'/virtual_machines', 'virtual_machines') as $row) {
            // The list leaves out the state and the package's identifier; the single record has both.
            $vm = $this->get('virtual_machines/'.$row['id'])['virtual_machine'] ?? [];

            $ipv4 = 0;
            foreach ($vm['ip_addresses'] ?? [] as $address) {
                if (! str_contains((string) ($address['address'] ?? ''), ':')) {
                    $ipv4++;
                }
            }

            $machines[] = [
                'id' => (string) $row['id'],
                'name' => (string) ($vm['name'] ?? $row['name'] ?? ''),
                'hostname' => (string) ($vm['hostname'] ?? $row['hostname'] ?? ''),
                'state' => (string) ($vm['state'] ?? 'unknown'),
                'package_id' => $vm['package']['id'] ?? null,
                'package_name' => $vm['package']['name'] ?? ($row['package']['name'] ?? null),
                'ipv4' => $ipv4,
            ];
        }

        return $machines;
    }

    /**
     * Every package on offer, smallest first by price where a price is known.
     *
     * @return list<array{id: string, name: string, cpus: int, memory_gb: int, disk_gb: int, dedicated: bool}>
     */
    public function packages(): array
    {
        $packages = [];

        foreach ($this->all('virtual_machine_packages', 'virtual_machine_packages') as $row) {
            $packages[] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'cpus' => (int) $row['cpu_cores'],
                'memory_gb' => (int) $row['memory_in_gb'],
                'disk_gb' => (int) $row['storage_in_gb'],
                'dedicated' => (bool) ($row['use_dedicated_cpus'] ?? false),
            ];
        }

        return $packages;
    }

    /**
     * Every disk, with the machine it is attached to. A deleted disk stays in the list, and on the
     * bill, until Katapult purges it from the trash; trashed_until is when that happens.
     *
     * @return list<array{name: string, size_gb: int, state: string, machine_id: ?string, trashed_until: ?int}>
     */
    public function disks(): array
    {
        $trash = [];
        foreach ($this->all('organizations/'.$this->organisation().'/trash_objects', 'trash_objects') as $row) {
            $trash[(string) ($row['object_id'] ?? '')] = (int) ($row['purge_after'] ?? $row['keep_until'] ?? 0);
        }

        $disks = [];

        // This one list is keyed in the singular by the API.
        foreach ($this->all('organizations/'.$this->organisation().'/disks', 'disk') as $row) {
            $disks[] = [
                'name' => (string) ($row['name'] ?? ''),
                'size_gb' => (int) ($row['size_in_gb'] ?? 0),
                'state' => (string) ($row['state'] ?? 'unknown'),
                'machine_id' => $row['virtual_machine_disk']['virtual_machine']['id'] ?? null,
                'trashed_until' => $trash[(string) ($row['id'] ?? '')] ?? null,
            ];
        }

        return $disks;
    }

    /**
     * Monthly prices for our organisation before any discount.
     *
     * @return array{packages: array<string, float>, disk_gb: float, ipv4: float}
     */
    public function prices(): array
    {
        $prices = ['packages' => [], 'disk_gb' => 0.0, 'ipv4' => 0.0];

        foreach ($this->get('organizations/'.$this->organisation().'/prices')['prices'] ?? [] as $row) {
            $resource = (string) ($row['resource'] ?? '');
            $price = (float) ($row['price'] ?? 0);

            if (str_starts_with($resource, 'virtual_machine_package.')) {
                $prices['packages'][substr($resource, strlen('virtual_machine_package.'))] = $price;
            } elseif ($resource === 'disk_space.nvme') {
                $prices['disk_gb'] = $price;
            } elseif ($resource === 'ip_address.ipv4') {
                $prices['ipv4'] = $price;
            }
        }

        // Without these every cost in the report would be quietly wrong, so say so instead.
        if ($prices['packages'] === [] || $prices['disk_gb'] <= 0) {
            throw new \RuntimeException('Katapult: the price list has no package or disk prices');
        }

        return $prices;
    }

    private function organisation(): string
    {
        if ($this->organisation === null) {
            $id = $this->get('organizations')['organizations'][0]['id'] ?? null;
            if (! is_string($id) || $id === '') {
                throw new \RuntimeException('Katapult: the token sees no organisation');
            }
            $this->organisation = $id;
        }

        return $this->organisation;
    }

    /**
     * Follow a list through all its pages.
     *
     * @return list<array<string, mixed>>
     */
    private function all(string $path, string $key): array
    {
        $rows = [];
        $page = 1;

        do {
            $body = $this->get($path, ['per_page' => 100, 'page' => $page]);
            foreach ($body[$key] ?? [] as $row) {
                $rows[] = $row;
            }
            $pages = (int) ($body['pagination']['total_pages'] ?? 1);
        } while (++$page <= $pages && $page <= 20);

        return $rows;
    }

    /**
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $response = Http::withToken($this->token())
            ->acceptJson()
            ->timeout(30)
            // Try again when the service is unreachable or failing, not when it has refused us.
            ->retry(2, 500, fn (\Throwable $e) => ! $e instanceof \Illuminate\Http\Client\RequestException || $e->response->serverError(), throw: false)
            ->get(rtrim((string) config('freegle.servers.katapult_api'), '/').'/'.$path, $query);

        if (! $response->successful()) {
            throw new \RuntimeException("Katapult: {$path} answered {$response->status()}");
        }

        return (array) $response->json();
    }

    private function token(): string
    {
        return trim((string) config('freegle.servers.katapult_token', ''));
    }
}
