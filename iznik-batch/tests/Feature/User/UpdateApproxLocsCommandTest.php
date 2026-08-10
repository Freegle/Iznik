<?php

namespace Tests\Feature\User;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpdateApproxLocsCommandTest extends TestCase
{
    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->group = $this->createTestGroup();
    }

    private function activeMemberAt(float $lat, float $lng): User
    {
        $user = $this->createTestUser();
        $this->createMembership($user, $this->group);
        DB::table('users')->where('id', $user->id)->update([
            'lastaccess' => now()->subDay(),
            'settings' => json_encode(['mylocation' => ['lat' => $lat, 'lng' => $lng]]),
        ]);

        return $user->fresh();
    }

    public function test_command_writes_a_point_for_an_active_member(): void
    {
        $user = $this->activeMemberAt(51.5010, -0.1416);

        $this->artisan('users:update-approx-locs')
            ->assertExitCode(0);

        $this->assertNotNull(DB::table('users_approxlocs')->where('userid', $user->id)->first());
    }

    public function test_command_reports_what_it_did(): void
    {
        $this->activeMemberAt(51.5010, -0.1416);

        $this->artisan('users:update-approx-locs')
            ->expectsOutputToContain('upserted')
            ->assertExitCode(0);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $user = $this->activeMemberAt(51.5010, -0.1416);

        $this->artisan('users:update-approx-locs', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertNull(DB::table('users_approxlocs')->where('userid', $user->id)->first());
    }

    /**
     * Asserted on the reported count rather than on which rows appeared: the test database is
     * shared, so --limit 1 may well pick an active member committed by an earlier test rather
     * than one of ours.
     */
    public function test_limit_bounds_how_many_members_are_considered(): void
    {
        $this->activeMemberAt(51.5010, -0.1416);
        $this->activeMemberAt(53.4084, -2.9916);

        $this->artisan('users:update-approx-locs', ['--limit' => 1])
            ->expectsOutputToContain('considered 1')
            ->assertExitCode(0);
    }
}
