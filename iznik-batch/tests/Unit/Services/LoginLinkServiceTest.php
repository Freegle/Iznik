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

    public function test_returns_the_stored_key_not_its_own_candidate(): void
    {
        // The race fix works by re-reading after insertOrIgnore: whatever key is in
        // the table is the one handed out, never a candidate that failed to land.
        $user = $this->createTestUser();
        DB::table('users_logins')->insert([
            'userid' => $user->id,
            'type' => 'Link',
            'uid' => (string) $user->id,
            'credentials' => 'preexisting0000000000000000000000',
            'added' => now(),
        ]);

        $key = app(LoginLinkService::class)->getOrCreateKey((int) $user->id);

        $this->assertSame('preexisting0000000000000000000000', $key);
    }

    public function test_user_get_user_key_delegates_to_the_service(): void
    {
        // User::getUserKey used to be a second copy of get-or-create, which both
        // drifted (it never set uid) and raced this service on (userid, type).
        $user = $this->createTestUser();

        $fromModel = \App\Models\User::find($user->id)->getUserKey();
        $fromService = app(LoginLinkService::class)->getOrCreateKey((int) $user->id);

        $this->assertSame($fromModel, $fromService);
        $this->assertEquals(1, DB::table('users_logins')->where('userid', $user->id)->where('type', 'Link')->count());
        $this->assertSame((string) $user->id, DB::table('users_logins')->where('userid', $user->id)->where('type', 'Link')->value('uid'));
    }
}
