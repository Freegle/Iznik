<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\PurgeService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurgeSessionsTest extends TestCase
{
    protected PurgeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PurgeService();
    }

    public function test_purge_sessions_deletes_old_sessions(): void
    {
        $user = $this->createTestUser();

        // Create an old session (40 days ago).
        DB::table('sessions')->insert([
            'userid' => $user->id,
            'series' => 1,
            'token' => 'old-token-' . uniqid(),
            'date' => now()->subDays(40),
            'lastactive' => now()->subDays(40),
        ]);

        // Create a recent session (5 days ago).
        DB::table('sessions')->insert([
            'userid' => $user->id,
            'series' => 2,
            'token' => 'recent-token-' . uniqid(),
            'date' => now()->subDays(5),
            'lastactive' => now()->subDays(5),
        ]);

        $purged = $this->service->purgeSessions(31);

        $this->assertEquals(1, $purged);

        // Verify old session was deleted.
        $this->assertEquals(0, DB::table('sessions')
            ->where('userid', $user->id)
            ->where('series', 1)
            ->count());

        // Verify recent session still exists.
        $this->assertEquals(1, DB::table('sessions')
            ->where('userid', $user->id)
            ->where('series', 2)
            ->count());
    }

    public function test_purge_sessions_with_no_old_sessions(): void
    {
        $purged = $this->service->purgeSessions(31);

        $this->assertEquals(0, $purged);
    }

    public function test_purge_sessions_respects_days_parameter(): void
    {
        $user = $this->createTestUser();

        // Create a session 10 days ago.
        DB::table('sessions')->insert([
            'userid' => $user->id,
            'series' => 1,
            'token' => 'token-10d-' . uniqid(),
            'date' => now()->subDays(10),
            'lastactive' => now()->subDays(10),
        ]);

        // With 31 days threshold, session should NOT be purged.
        $purged = $this->service->purgeSessions(31);
        $this->assertEquals(0, $purged);

        // With 7 days threshold, session SHOULD be purged.
        $purged = $this->service->purgeSessions(7);
        $this->assertEquals(1, $purged);
    }

    public function test_purge_old_login_links(): void
    {
        $user = $this->createTestUser();

        // Create an old login link.
        DB::table('users_logins')->insert([
            'userid' => $user->id,
            'type' => User::LOGIN_LINK,
            'uid' => 'old-link-' . uniqid(),
            'lastaccess' => now()->subDays(40),
            'added' => now()->subDays(40),
        ]);

        // Create a recent login link.
        DB::table('users_logins')->insert([
            'userid' => $user->id,
            'type' => User::LOGIN_LINK,
            'uid' => 'recent-link-' . uniqid(),
            'lastaccess' => now()->subDays(5),
            'added' => now()->subDays(5),
        ]);

        // Create an old non-link login (should NOT be deleted).
        DB::table('users_logins')->insert([
            'userid' => $user->id,
            'type' => 'Native',
            'uid' => 'native-' . uniqid(),
            'lastaccess' => now()->subDays(40),
            'added' => now()->subDays(40),
        ]);

        $purged = $this->service->purgeOldLoginLinks(31);

        $this->assertEquals(1, $purged);

        // Verify recent link still exists.
        $this->assertEquals(1, DB::table('users_logins')
            ->where('userid', $user->id)
            ->where('type', User::LOGIN_LINK)
            ->where('lastaccess', '>=', now()->subDays(31))
            ->count());

        // Verify non-link login still exists.
        $this->assertEquals(1, DB::table('users_logins')
            ->where('userid', $user->id)
            ->where('type', 'Native')
            ->count());
    }

    public function test_purge_login_links_with_none_to_purge(): void
    {
        $purged = $this->service->purgeOldLoginLinks(31);

        $this->assertEquals(0, $purged);
    }
}
