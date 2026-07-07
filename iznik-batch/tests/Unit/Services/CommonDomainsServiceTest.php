<?php

namespace Tests\Unit\Services;

use App\Services\CommonDomainsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CommonDomainsServiceTest extends TestCase
{
    protected CommonDomainsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CommonDomainsService();
        DB::table('domains_common')->delete();
    }

    public function test_empty_input_returns_zero(): void
    {
        // No users_emails rows with our test prefix.
        $result = $this->service->updateCommonDomains();

        $this->assertEquals(0, $result['domains_inserted']);
    }

    public function test_below_threshold_not_inserted(): void
    {
        // Insert 1000 users with @example.com — exactly at threshold, not over.
        $user = $this->createTestUser();
        for ($i = 0; $i < 1000; $i++) {
            DB::table('users_emails')->insert([
                'userid' => $user->id,
                'email'  => "user{$i}@threshold-test-domain.com",
            ]);
        }

        $this->service->updateCommonDomains();

        $this->assertEquals(
            0,
            DB::table('domains_common')->where('domain', 'threshold-test-domain.com')->count()
        );
    }

    public function test_above_threshold_inserted(): void
    {
        $user = $this->createTestUser();
        for ($i = 0; $i < 1001; $i++) {
            DB::table('users_emails')->insert([
                'userid' => $user->id,
                'email'  => "user{$i}@common-domain-test.com",
            ]);
        }

        $result = $this->service->updateCommonDomains();

        $this->assertGreaterThanOrEqual(1, $result['domains_inserted']);
        $row = DB::table('domains_common')->where('domain', 'common-domain-test.com')->first();
        $this->assertNotNull($row);
        $this->assertEquals(1001, $row->count);
    }

    public function test_upserts_existing_domain(): void
    {
        // Pre-seed with old count.
        DB::table('domains_common')->insert(['domain' => 'upsert-domain.com', 'count' => 500]);

        $user = $this->createTestUser();
        for ($i = 0; $i < 1001; $i++) {
            DB::table('users_emails')->insert([
                'userid' => $user->id,
                'email'  => "user{$i}@upsert-domain.com",
            ]);
        }

        $this->service->updateCommonDomains();

        $row = DB::table('domains_common')->where('domain', 'upsert-domain.com')->first();
        $this->assertEquals(1001, $row->count);
    }

    public function test_ignores_addresses_without_at_sign(): void
    {
        $user = $this->createTestUser();
        for ($i = 0; $i < 1001; $i++) {
            DB::table('users_emails')->insert([
                'userid' => $user->id,
                'email'  => "invalidemail{$i}",
            ]);
        }

        $result = $this->service->updateCommonDomains();
        $this->assertEquals(0, $result['domains_inserted']);
    }
}
