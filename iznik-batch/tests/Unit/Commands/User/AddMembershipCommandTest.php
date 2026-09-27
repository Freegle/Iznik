<?php

namespace Tests\Unit\Commands\User;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddMembershipCommandTest extends TestCase
{
    public function test_adds_the_membership_and_queues_the_member_for_reach_mail(): void
    {
        $email = $this->uniqueEmail();
        $user = $this->createTestUser(['email_preferred' => $email]);
        $group = $this->createTestGroup();

        $exit = Artisan::call('user:add-membership', ['--email' => $email, '--group' => $group->nameshort]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertDatabaseHas('memberships', ['userid' => $user->id, 'groupid' => $group->id, 'collection' => 'Approved']);
        $this->assertSame(
            'joined',
            DB::table('rippling_reach_member_pending')->where('userid', $user->id)->value('reason'),
            'a support-added membership queues the member like any other join'
        );
    }
}
