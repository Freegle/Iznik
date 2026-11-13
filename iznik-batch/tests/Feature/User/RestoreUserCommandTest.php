<?php

namespace Tests\Feature\User;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RestoreUserCommandTest extends TestCase
{
    public function test_restore_brings_a_ban_back(): void
    {
        // users_banned has no id column, and the restore's upsert named one, so MySQL refused
        // the statement and the ban was skipped with a warning.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        DB::table('users_banned')->insert(['userid' => $user->id, 'groupid' => $group->id]);

        $path = sys_get_temp_dir().'/restore-ban-'.$user->id.'.json';
        $this->artisan('user:dump', ['--id' => $user->id, '--output' => $path])->assertExitCode(0);

        DB::table('users_banned')->where('userid', $user->id)->delete();

        $this->artisan('user:restore', ['--input' => $path])
            ->doesntExpectOutputToContain('skipped row in users_banned')
            ->assertExitCode(0);
        unlink($path);

        $this->assertSame(
            1,
            DB::table('users_banned')->where('userid', $user->id)->where('groupid', $group->id)->count()
        );

        // Restoring over a ban that is still there leaves the one row.
        $this->artisan('user:dump', ['--id' => $user->id, '--output' => $path])->assertExitCode(0);
        $this->artisan('user:restore', ['--input' => $path])
            ->doesntExpectOutputToContain('skipped row in users_banned')
            ->assertExitCode(0);
        unlink($path);
        $this->assertSame(1, DB::table('users_banned')->where('userid', $user->id)->count());
    }

    public function test_restore_brings_back_a_ban_whose_moderator_has_gone(): void
    {
        $user = $this->createTestUser();
        $moderator = $this->createTestUser();
        $group = $this->createTestGroup();
        DB::table('users_banned')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'byuser' => $moderator->id,
            'date' => '2026-01-02 03:04:05',
        ]);

        $path = sys_get_temp_dir().'/restore-ban-nomod-'.$user->id.'.json';
        $this->artisan('user:dump', ['--id' => $user->id, '--output' => $path])->assertExitCode(0);

        DB::table('users_banned')->where('userid', $user->id)->delete();
        DB::table('users')->where('id', $moderator->id)->delete();

        $this->artisan('user:restore', ['--input' => $path])
            ->doesntExpectOutputToContain('skipped row in users_banned')
            ->assertExitCode(0);
        unlink($path);

        $ban = DB::table('users_banned')->where('userid', $user->id)->where('groupid', $group->id)->first();
        $this->assertNotNull($ban, 'the ban was lost because its moderator no longer exists');
        $this->assertNull($ban->byuser);
        $this->assertSame('2026-01-02 03:04:05', (string) $ban->date, 'the ban keeps the date it was made');
    }

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
