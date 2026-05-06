<?php

namespace Tests\Unit\Services\SpamCheck;

use App\Services\SpamCheck\SpamCheckService;
use Tests\TestCase;

class SpamCheckServiceTest extends TestCase
{
    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists(SpamCheckService::class));
    }

    public function test_has_check_rspamd_method(): void
    {
        $service = new SpamCheckService();
        $this->assertTrue(method_exists($service, 'checkRspamd'));
    }

    public function test_has_check_all_method(): void
    {
        $service = new SpamCheckService();
        $this->assertTrue(method_exists($service, 'checkAll'));
    }
}
