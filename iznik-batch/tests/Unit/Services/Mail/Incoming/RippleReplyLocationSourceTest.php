<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Services\Mail\Incoming\IncomingMailService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * The held-reply location source must match the immediate-mail / digest reach-gate: resolve a
 * replier's point as settings.mylocation else lastlocation. Previously the hold used
 * lastlocation ONLY, so a replier whose mylocation differed from lastlocation could be told
 * (by mail/read-path, which use mylocation) that they can reply while their email reply was
 * silently held against the wrong point — and vice versa.
 */
class RippleReplyLocationSourceTest extends TestCase
{
    use DatabaseTransactions;

    private IncomingMailService $service;
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(IncomingMailService::class);
        $this->reflection = new ReflectionClass($this->service);
    }

    private function resolve($user): mixed
    {
        $method = $this->reflection->getMethod('resolveReplierLatLng');
        $method->setAccessible(true);

        return $method->invokeArgs($this->service, [$user]);
    }

    #[Test]
    public function it_prefers_mylocation_over_lastlocation(): void
    {
        $user = $this->createTestUser();
        // mylocation set, no lastlocation — mylocation must be used (it was ignored before).
        $user->settings = ['mylocation' => ['lat' => 51.6, 'lng' => -0.2]];
        $user->lastlocation = null;
        $user->save();
        $user->refresh();

        $latlng = $this->resolve($user);

        $this->assertIsArray($latlng);
        $this->assertEqualsWithDelta(51.6, $latlng[0], 0.0001);
        $this->assertEqualsWithDelta(-0.2, $latlng[1], 0.0001);
    }

    #[Test]
    public function it_returns_null_when_no_location_known(): void
    {
        $user = $this->createTestUser();
        $user->settings = [];
        $user->lastlocation = null;
        $user->save();
        $user->refresh();

        $this->assertNull($this->resolve($user));
    }
}
