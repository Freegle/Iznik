<?php

namespace Tests\Feature\Servers;

use App\Services\Servers\KatapultClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * KatapultClient reads the account over the Katapult API. These tests pin what it asks for and how
 * it reads the answers, against canned responses in the API's own shapes.
 */
class KatapultClientTest extends TestCase
{
    use ServerSizingTestData;

    public function test_it_is_not_configured_without_a_token(): void
    {
        config(['freegle.servers.katapult_token' => ' ']);
        $this->assertFalse((new KatapultClient)->configured());

        config(['freegle.servers.katapult_token' => 'kp-token']);
        $this->assertTrue((new KatapultClient)->configured());
    }

    public function test_machines_carry_state_package_and_a_count_of_ipv4_addresses(): void
    {
        $this->fakeKatapult();

        $machines = (new KatapultClient)->machines();

        $this->assertSame(['Alpha', 'Beta', 'Gamma'], array_column($machines, 'name'));
        $this->assertSame(['started', 'started', 'stopped'], array_column($machines, 'state'));
        $this->assertSame(['p36', 'p3', 'p1'], array_column($machines, 'package_id'));
        // Alpha has one IPv4 and one IPv6; Beta has two IPv4.
        $this->assertSame([1, 2, 1], array_column($machines, 'ipv4'));
        $this->assertSame('alpha', $machines[0]['hostname']);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer kp-token'));
    }

    public function test_packages_disks_and_prices_are_read(): void
    {
        $this->fakeKatapult();
        $client = new KatapultClient;

        $packages = $client->packages();
        $this->assertSame(['id' => 'p12', 'name' => 'C-12', 'cpus' => 4, 'memory_gb' => 12, 'disk_gb' => 150, 'dedicated' => false], $packages[2]);
        $this->assertTrue($packages[4]['dedicated']);

        $disks = $client->disks();
        $this->assertSame(['name' => 'System Disk', 'size_gb' => 300, 'state' => 'built', 'machine_id' => 'vm_a', 'trashed_until' => null], $disks[0]);
        $this->assertNull($disks[3]['machine_id']);
        // A deleted disk is still listed, with when it leaves the trash.
        $this->assertSame(['name' => 'deleted-disk', 'size_gb' => 75, 'state' => 'not_built', 'machine_id' => 'vm_b', 'trashed_until' => 1796083200], $disks[4]);

        $prices = $client->prices();
        $this->assertSame(['p1' => 8.0, 'p3' => 12.0, 'p12' => 44.0, 'p36' => 136.0], $prices['packages']);
        $this->assertSame(0.2, $prices['disk_gb']);
        $this->assertSame(2.0, $prices['ipv4']);
    }

    public function test_the_organisation_is_looked_up_once(): void
    {
        $this->fakeKatapult();
        $client = new KatapultClient;

        $client->disks();
        $client->prices();

        // The organisation, the trash, the disks, the prices.
        Http::assertSentCount(4);
    }

    public function test_a_list_is_followed_through_its_pages(): void
    {
        config(['freegle.servers.katapult_token' => 'kp-token', 'freegle.servers.katapult_api' => 'https://katapult.test/core/v1/']);
        $package = fn (string $id) => ['id' => $id, 'name' => $id, 'cpu_cores' => 1, 'memory_in_gb' => 1, 'storage_in_gb' => 10];
        Http::fake([
            'katapult.test/core/v1/virtual_machine_packages?per_page=100&page=1' => Http::response(['pagination' => ['total_pages' => 2], 'virtual_machine_packages' => [$package('a')]]),
            'katapult.test/core/v1/virtual_machine_packages?per_page=100&page=2' => Http::response(['pagination' => ['total_pages' => 2], 'virtual_machine_packages' => [$package('b')]]),
        ]);

        $this->assertSame(['a', 'b'], array_column((new KatapultClient)->packages(), 'id'));
    }

    public function test_a_refused_request_is_an_error_not_an_empty_estate(): void
    {
        config(['freegle.servers.katapult_token' => 'kp-token', 'freegle.servers.katapult_api' => 'https://katapult.test/core/v1']);
        Http::fake(['*' => Http::response(['error' => ['code' => 'unauthorized']], 403)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Katapult: virtual_machine_packages answered 403');
        (new KatapultClient)->packages();
    }

    public function test_a_refusal_is_not_tried_again(): void
    {
        config(['freegle.servers.katapult_token' => 'kp-token', 'freegle.servers.katapult_api' => 'https://katapult.test/core/v1']);
        Http::fake(['*' => Http::response([], 404)]);

        try {
            (new KatapultClient)->packages();
            $this->fail('expected an exception');
        } catch (\RuntimeException) {
            Http::assertSentCount(1);
        }
    }

    public function test_a_failing_service_is_tried_again(): void
    {
        config(['freegle.servers.katapult_token' => 'kp-token', 'freegle.servers.katapult_api' => 'https://katapult.test/core/v1']);
        Http::fake(['*' => Http::sequence()
            ->push([], 503)
            ->push(['pagination' => ['total_pages' => 1], 'virtual_machine_packages' => []])]);

        $this->assertSame([], (new KatapultClient)->packages());
        Http::assertSentCount(2);
    }

    public function test_a_price_list_without_package_or_disk_prices_is_an_error(): void
    {
        config(['freegle.servers.katapult_token' => 'kp-token', 'freegle.servers.katapult_api' => 'https://katapult.test/core/v1']);
        Http::fake([
            'katapult.test/core/v1/organizations' => Http::response(['organizations' => [['id' => 'org_1']]]),
            'katapult.test/core/v1/organizations/org_1/prices' => Http::response(['prices' => [
                ['resource' => 'virtual_machine_package.p1', 'price' => 8],
                ['resource' => 'ip_address.ipv4', 'price' => 2],
            ]]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the price list has no package or disk prices');
        (new KatapultClient)->prices();
    }

    public function test_a_token_that_sees_no_organisation_is_an_error(): void
    {
        config(['freegle.servers.katapult_token' => 'kp-token', 'freegle.servers.katapult_api' => 'https://katapult.test/core/v1']);
        Http::fake(['*' => Http::response(['organizations' => []])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the token sees no organisation');
        (new KatapultClient)->disks();
    }
}
