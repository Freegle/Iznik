<?php

namespace Tests\Unit\Commands\Ripple;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconcileReachMembersCommandTest extends TestCase
{
    public function test_command_queues_members_and_reports_the_count(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['added' => now()->subHours(2)]);

        $exit = Artisan::call('ripple:reconcile-reach-members');
        $output = Artisan::output(); // fetch() clears the buffer, so read it once

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('queued', $output);
        $this->assertSame('joined', DB::table('rippling_reach_member_pending')->where('userid', $user->id)->value('reason'));
    }

    public function test_dry_run_queues_nobody(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['added' => now()->subHours(2)]);

        $exit = Artisan::call('ripple:reconcile-reach-members', ['--dry-run' => true]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertNull(DB::table('rippling_reach_member_pending')->where('userid', $user->id)->value('reason'));
    }
}
