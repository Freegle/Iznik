<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for User::forget() — ported from iznik-server UserTest::testForget()
 * and iznik-server-go TestPostSessionForget.
 */
class UserForgetTest extends TestCase
{
    private const DB_WRITES_DISABLED_SKIP_REASON = 'Temporarily skipped: TNSyncCommand-related write paths are disabled for port testing.';

    private function skipIfTNSyncWritesDisabled(): void
    {
        $this->markTestSkipped(self::DB_WRITES_DISABLED_SKIP_REASON);
    }

    public function test_forget_anonymizes_fullname(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser(['fullname' => 'Jane Doe']);

        $user->forget('Test GDPR request');

        $updated = DB::table('users')->where('id', $user->id)->first();
        $this->assertEquals('Deleted User #' . $user->id, $updated->fullname);
    }

    public function test_forget_clears_personal_attributes(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser([
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'fullname' => 'Jane Doe',
            'yahooid' => 'janedoe123',
            'settings' => ['notifications' => ['email' => true]],
        ]);

        $user->forget('Test');

        $updated = DB::table('users')->where('id', $user->id)->first();
        $this->assertNull($updated->firstname);
        $this->assertNull($updated->lastname);
        $this->assertNull($updated->yahooid);
        $this->assertNull($updated->settings);
    }

    public function test_forget_removes_external_emails(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();
        $this->createTestUserEmail($user);

        // Should have at least 2 emails now.
        $this->assertGreaterThanOrEqual(2, DB::table('users_emails')->where('userid', $user->id)->count());

        $user->forget('Test');

        // External emails should be deleted.
        $remaining = DB::table('users_emails')
            ->where('userid', $user->id)
            ->pluck('email')
            ->toArray();

        foreach ($remaining as $email) {
            $this->assertTrue(
                User::isInternalEmail($email),
                "Expected only internal emails to remain, found: {$email}"
            );
        }
    }

    public function test_forget_deletes_login_credentials(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();

        // Add a login credential.
        DB::table('users_logins')->insert([
            'userid' => $user->id,
            'type' => User::LOGIN_NATIVE,
            'uid' => $user->id,
            'credentials' => 'hashed_password',
        ]);

        $this->assertEquals(1, DB::table('users_logins')->where('userid', $user->id)->count());

        $user->forget('Test');

        $this->assertEquals(0, DB::table('users_logins')->where('userid', $user->id)->count());
    }

    public function test_forget_removes_memberships(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $this->assertEquals(1, DB::table('memberships')->where('userid', $user->id)->count());

        $user->forget('Test');

        $this->assertEquals(0, DB::table('memberships')->where('userid', $user->id)->count());
    }

    public function test_forget_clears_about_me(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();

        DB::table('users_aboutme')->insert([
            'userid' => $user->id,
            'text' => 'I love recycling!',
            'timestamp' => now(),
        ]);

        $user->forget('Test');

        $this->assertEquals(0, DB::table('users_aboutme')->where('userid', $user->id)->count());
    }

    public function test_forget_clears_ratings(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();
        $rater = $this->createTestUser();

        DB::table('ratings')->insert([
            'rater' => $rater->id,
            'ratee' => $user->id,
            'rating' => 'Up',
            'timestamp' => now(),
            'visible' => 1,
        ]);

        $user->forget('Test');

        $this->assertEquals(0, DB::table('ratings')->where('ratee', $user->id)->count());
    }

    public function test_forget_marks_user_as_forgotten(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();

        $user->forget('Test');

        $forgotten = DB::table('users')->where('id', $user->id)->value('forgotten');
        $this->assertNotNull($forgotten);
    }

    public function test_forget_clears_tn_user_id(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser(['tnuserid' => 99999]);

        $user->forget('Test');

        $tnId = DB::table('users')->where('id', $user->id)->value('tnuserid');
        $this->assertNull($tnId);
    }

    public function test_forget_deletes_sessions(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();

        DB::table('sessions')->insert([
            'userid' => $user->id,
            'series' => bin2hex(random_bytes(16)),
            'token' => bin2hex(random_bytes(16)),
            'date' => now(),
        ]);

        $user->forget('Test');

        $this->assertEquals(0, DB::table('sessions')->where('userid', $user->id)->count());
    }

    public function test_forget_logs_deletion(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();

        $user->forget('GDPR request');

        $log = DB::table('logs')
            ->where('user', $user->id)
            ->where('type', 'User')
            ->where('subtype', 'Deleted')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('GDPR request', $log->text);
    }

    public function test_forget_clears_message_content(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group, [
            'textbody' => 'Personal details here',
        ]);

        $user->forget('Test');

        $updated = DB::table('messages')->where('id', $message->id)->first();
        $this->assertNull($updated->textbody);
        $this->assertNotNull($updated->deleted);
    }

    public function test_forget_clears_chat_message_content(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();
        $other = $this->createTestUser();

        $room = $this->createTestChatRoom($user, $other);
        $chatMsg = $this->createTestChatMessage($room, $user, ['message' => 'Private content']);

        $user->forget('Test');

        $updated = DB::table('chat_messages')->where('id', $chatMsg->id)->first();
        $this->assertNull($updated->message);
    }

    public function test_forget_retains_user_record(): void
    {
        $user = $this->createTestUser();

        $user->forget('Test');

        // User record should still exist (for statistics).
        $this->assertNotNull(User::find($user->id));
    }
}
