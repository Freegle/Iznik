<?php

namespace Tests\Unit\Services;

use App\Services\LoginLinkService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoginLinkServiceTest extends TestCase
{
    public function test_creates_a_link_key_when_none_exists(): void
    {
        $user = $this->createTestUser();
        $key = app(LoginLinkService::class)->getOrCreateKey((int) $user->id);

        $this->assertNotEmpty($key);
        $this->assertDatabaseHas('users_logins', [
            'userid' => $user->id,
            'type' => 'Link',
            'credentials' => $key,
        ]);
    }

    public function test_reuses_the_existing_link_key(): void
    {
        $user = $this->createTestUser();
        $svc = app(LoginLinkService::class);

        $first = $svc->getOrCreateKey((int) $user->id);
        $second = $svc->getOrCreateKey((int) $user->id);

        $this->assertSame($first, $second, 'must not mint a new key each call (would break links already emailed)');
        $this->assertEquals(1, DB::table('users_logins')->where('userid', $user->id)->where('type', 'Link')->count());
    }
}
