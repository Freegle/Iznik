<?php

namespace Tests\Feature\User;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RestoreUserCommandTest extends TestCase
{
    public function test_restore_brings_a_deleted_post_back_on_its_group(): void
    {
        // A demerge hard-deletes the member's messages rows, and messages_groups.msgid is a
        // foreign key with ON DELETE CASCADE, so the group rows go with them. A restore from a
        // dump must put the post back on its group, not just back in messages.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);

        $path = sys_get_temp_dir().'/restore-messages-groups-'.$user->id.'.json';
        $this->artisan('user:dump', ['--id' => $user->id, '--output' => $path])->assertExitCode(0);

        DB::table('messages')->where('id', $message->id)->delete();
        $this->assertSame(0, DB::table('messages_groups')->where('msgid', $message->id)->count());

        $this->artisan('user:restore', ['--input' => $path])->assertExitCode(0);
        unlink($path);

        $this->assertSame(1, DB::table('messages')->where('id', $message->id)->count());
        $this->assertSame(
            1,
            DB::table('messages_groups')
                ->where('msgid', $message->id)
                ->where('groupid', $group->id)
                ->count()
        );
    }
}
