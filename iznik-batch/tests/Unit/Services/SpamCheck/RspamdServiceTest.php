<?php

namespace Tests\Unit\Services\SpamCheck;

use App\Services\SpamCheck\RspamdService;
use Tests\TestCase;

class RspamdServiceTest extends TestCase
{
    public function test_class_exists_under_new_name(): void
    {
        $this->assertTrue(class_exists(RspamdService::class));
    }

    public function test_has_check_rspamd_method(): void
    {
        $service = new RspamdService();
        $this->assertTrue(method_exists($service, 'checkRspamd'));
    }

    public function test_has_check_all_method(): void
    {
        $service = new RspamdService();
        $this->assertTrue(method_exists($service, 'checkAll'));
    }
}
