<?php

namespace Tests\Unit\Services\Lockdown;

use App\Models\MessageGroup;
use App\Models\SpamUser;
use App\Models\User;
use App\Services\Lockdown\LockdownFilterSpoolService;
use App\Services\Lockdown\LockdownService;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\TestCase;

/**
 * LockdownFilterSpoolService::filter() (plan 2026-09-27-lockdown-switch.md, section 11.8):
 * removes a waiting spooled file once the member content it names is no longer fit to
 * send. Fixture pending files are written directly as minimal JSON (the same pattern
 * EmailSpoolerProcessSpoolRaceTest uses), not through real Mailables, so each bucket's
 * check is exercised on its own; EmailSpoolerServiceTest/EmailSpoolerServiceLockdownTest
 * cover spool()'s own writing of the `about` field.
 */
class LockdownFilterSpoolServiceTest extends TestCase
{
    use IsolatedSpoolDirectory;

    private LockdownService $lockdown;
    private LockdownFilterSpoolService $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedSpoolDirectory();
        DB::table('lockdowns')->delete();
        DB::table('lockdown_counters')->delete();
        // Static, process-wide cache (section 11.6) - flush so an earlier test class in
        // this same PHPUnit process cannot leak a row in.
        LockdownService::flushCache();
        $this->lockdown = new LockdownService();
        $this->filter = new LockdownFilterSpoolService($this->spooler, $this->lockdown);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSpoolDirectory();
        parent::tearDown();
    }

    private function writePending(string $id, ?array $about, string $type = 'test_type'): string
    {
        $path = $this->testSpoolDir.'/pending/'.$id.'.json';
        file_put_contents($path, json_encode([
            'id' => $id,
            'email_type' => $type,
            'mailable_class' => 'Test\\Mailable',
            'about' => $about,
        ]));

        return $path;
    }

    private function counterFor(string $kind): int
    {
        $row = DB::table('lockdown_counters')->where('kind', $kind)->first();

        return $row ? (int) $row->count : 0;
    }

    private function makeNewsfeedPost(User $user, bool $hidden = false, bool $deleted = false): int
    {
        $srid = (int) config('freegle.srid', 3857);

        return (int) DB::table('newsfeed')->insertGetId([
            'type' => 'Message',
            'userid' => $user->id,
            'message' => 'Test chitchat post',
            'added' => now(),
            'timestamp' => now(),
            'hidden' => $hidden ? now() : null,
            'hiddenby' => $hidden ? $user->id : null,
            'deleted' => $deleted ? now() : null,
            'deletedby' => $deleted ? $user->id : null,
            'position' => DB::raw("ST_GeomFromText('POINT(0 0)', {$srid})"),
        ]);
    }

    // --- passthrough: nothing to check ---

    private function writeAdminMail(string $id, int $adminId, int $userId): string
    {
        $path = $this->testSpoolDir.'/pending/'.$id.'.json';
        file_put_contents($path, json_encode([
            'id' => $id,
            'email_type' => 'Admin',
            'mailable_class' => 'App\\Mail\\Admin\\AdminMail',
            'headers' => ['X-Freegle-User-Id' => (string) $userId],
            'about' => ['chatmessages' => [], 'messages' => [], 'newsfeed' => [], 'users' => [], 'admins' => [$adminId]],
        ]));

        return $path;
    }

    private function makeAdmin(int $pending, ?int $parentId = null): int
    {
        return (int) DB::table('admins')->insertGetId([
            'groupid' => $this->createTestGroup()->id,
            'subject' => 'Admin',
            'text' => 'Admin text',
            'pending' => $pending,
            'parentid' => $parentId,
        ]);
    }

    public function test_removes_a_queued_admin_mail_once_its_admin_is_withdrawn(): void
    {
        $this->lockdown->press(null, 'wave');
        $user = $this->createTestUser();
        $adminId = $this->makeAdmin(1);
        DB::table('admins_users')->insert(['userid' => $user->id, 'adminid' => $adminId]);
        $path = $this->writeAdminMail('admin_withdrawn', $adminId, $user->id);

        $stats = $this->filter->filter();

        $this->assertSame(1, $stats['removed']);
        $this->assertFileDoesNotExist($path);
        $this->assertSame(0, DB::table('admins_users')->where(['userid' => $user->id, 'adminid' => $adminId])->count(),
            'forgotten as sent, so the member gets it if it is approved again');
    }

    public function test_forgets_a_suggested_admin_against_its_parent(): void
    {
        $this->lockdown->press(null, 'wave');
        $user = $this->createTestUser();
        $parentId = $this->makeAdmin(0);
        $copyId = $this->makeAdmin(1, $parentId);
        DB::table('admins_users')->insert(['userid' => $user->id, 'adminid' => $parentId]);
        $this->writeAdminMail('admin_copy', $copyId, $user->id);

        $this->filter->filter();

        $this->assertSame(0, DB::table('admins_users')->where(['userid' => $user->id, 'adminid' => $parentId])->count());
    }

    public function test_keeps_a_queued_admin_mail_whose_admin_is_still_approved(): void
    {
        $this->lockdown->press(null, 'wave');
        $user = $this->createTestUser();
        $adminId = $this->makeAdmin(0);
        $path = $this->writeAdminMail('admin_ok', $adminId, $user->id);

        $stats = $this->filter->filter();

        $this->assertSame(0, $stats['removed']);
        $this->assertFileExists($path);
    }

    public function test_a_file_with_no_about_key_is_left_alone_and_not_counted(): void
    {
        $path = $this->testSpoolDir.'/pending/no_about.json';
        file_put_contents($path, json_encode(['id' => 'no_about', 'email_type' => 'welcome']));

        $stats = $this->filter->filter();

        $this->assertSame(0, $stats['checked'], 'a file with no about key is not counted as checked');
        $this->assertSame(0, $stats['removed']);
        $this->assertFileExists($path);
    }

    public function test_a_file_with_a_null_about_is_left_alone_and_not_counted(): void
    {
        $path = $this->writePending('null_about', null);

        $stats = $this->filter->filter();

        $this->assertSame(0, $stats['checked']);
        $this->assertSame(0, $stats['removed']);
        $this->assertFileExists($path);
    }

    public function test_a_file_with_all_empty_buckets_is_checked_but_kept(): void
    {
        $path = $this->writePending('empty_about', [
            'chatmessages' => [], 'messages' => [], 'newsfeed' => [], 'users' => [],
        ]);

        $stats = $this->filter->filter();

        $this->assertSame(1, $stats['checked']);
        $this->assertSame(0, $stats['removed']);
        $this->assertFileExists($path);
    }

    // --- chatmessages bucket ---

    public function test_removes_a_file_naming_a_rejected_chat_message(): void
    {
        $sender = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $this->createTestUser());
        $message = $this->createTestChatMessage($room, $sender, ['reviewrejected' => 1]);

        $path = $this->writePending('bad_chat', [
            'chatmessages' => [$message->id], 'messages' => [], 'newsfeed' => [], 'users' => [],
        ], 'chat_notification');

        // count() only writes a lockdown_counters row while a lockdown is active
        // (section 11.6); filter-spool only ever runs during one in production.
        $this->lockdown->press(null, 'test');

        $stats = $this->filter->filter();

        $this->assertSame(1, $stats['removed']);
        $this->assertFileDoesNotExist($path);
        $this->assertFileExists($this->testSpoolDir.'/lockdown-removed/bad_chat.json');
        $this->assertSame(1, $this->counterFor('filtered:email:chat_notification'));
    }

    public function test_removes_a_file_naming_an_unreviewed_chat_message(): void
    {
        $sender = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $this->createTestUser());
        $message = $this->createTestChatMessage($room, $sender, ['reviewrequired' => 1, 'processingsuccessful' => 0]);

        $path = $this->writePending('unreviewed_chat', [
            'chatmessages' => [$message->id], 'messages' => [], 'newsfeed' => [], 'users' => [],
        ]);

        $stats = $this->filter->filter();

        $this->assertSame(1, $stats['removed']);
        $this->assertFileDoesNotExist($path);
    }

    public function test_removes_a_file_naming_a_missing_chat_message(): void
    {
        $path = $this->writePending('missing_chat', [
            'chatmessages' => [999999999], 'messages' => [], 'newsfeed' => [], 'users' => [],
        ]);

        $stats = $this->filter->filter();

        $this->assertSame(1, $stats['removed']);
        $this->assertFileDoesNotExist($path);
    }

    public function test_keeps_a_file_naming_a_visible_chat_message(): void
    {
        $sender = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $this->createTestUser());
        $message = $this->createTestChatMessage($room, $sender);

        $path = $this->writePending('good_chat', [
            'chatmessages' => [$message->id], 'messages' => [], 'newsfeed' => [], 'users' => [],
        ]);

        $stats = $this->filter->filter();

        $this->assertSame(0, $stats['removed']);
        $this->assertFileExists($path);
    }

    // --- messages bucket ---

    public function test_removes_a_file_naming_a_post_not_approved(): void
    {
        $sender = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($sender, $group);
        $message = $this->createTestMessage($sender, $group);
        MessageGroup::where('msgid', $message->id)->update(['collection' => MessageGroup::COLLECTION_PENDING]);

        $path = $this->writePending('bad_post', [
            'chatmessages' => [], 'messages' => [$message->id], 'newsfeed' => [], 'users' => [],
        ]);

        $stats = $this->filter->filter();

        $this->assertSame(1, $stats['removed']);
        $this->assertFileDoesNotExist($path);
    }

    public function test_removes_a_file_naming_a_retracted_rippled_post(): void
    {
        // A retracted rippled copy keeps collection=Approved - deleted=1 is what says it
        // is gone (.claude/rules/laravel-batch-traps.md: "A retracted rippled copy still
        // looks Approved").
        $sender = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($sender, $group);
        $message = $this->createTestMessage($sender, $group);
        MessageGroup::where('msgid', $message->id)->update(['deleted' => 1]);

        $path = $this->writePending('retracted_post', [
            'chatmessages' => [], 'messages' => [$message->id], 'newsfeed' => [], 'users' => [],
        ]);

        $stats = $this->filter->filter();

        $this->assertSame(1, $stats['removed']);
        $this->assertFileDoesNotExist($path);
    }

    public function test_removes_a_file_naming_a_missing_post(): void
    {
        $path = $this->writePending('missing_post', [
            'chatmessages' => [], 'messages' => [999999999], 'newsfeed' => [], 'users' => [],
        ]);

        $stats = $this->filter->filter();

        $this->assertSame(1, $stats['removed']);
        $this->assertFileDoesNotExist($path);
    }

    public function test_keeps_a_file_naming_an_approved_post(): void
    {
        $sender = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($sender, $group);
        $message = $this->createTestMessage($sender, $group);

        $path = $this->writePending('good_post', [
            'chatmessages' => [], 'messages' => [$message->id], 'newsfeed' => [], 'users' => [],
        ]);

        $stats = $this->filter->filter();

        $this->assertSame(0, $stats['removed']);
        $this->assertFileExists($path);
    }

    // --- newsfeed bucket ---

    public function test_removes_a_file_naming_a_hidden_newsfeed_post(): void
    {
        $user = $this->createTestUser();
        $nfid = $this->makeNewsfeedPost($user, hidden: true);

        $path = $this->writePending('hidden_nf', [
            'chatmessages' => [], 'messages' => [], 'newsfeed' => [$nfid], 'users' => [],
        ]);

        $stats = $this->filter->filter();

        $this->assertSame(1, $stats['removed']);
        $this->assertFileDoesNotExist($path);
    }

    public function test_removes_a_file_naming_a_deleted_newsfeed_post(): void
    {
        $user = $this->createTestUser();
        $nfid = $this->makeNewsfeedPost($user, deleted: true);

        $path = $this->writePending('deleted_nf', [
            'chatmessages' => [], 'messages' => [], 'newsfeed' => [$nfid], 'users' => [],
        ]);

        $stats = $this->filter->filter();

        $this->assertSame(1, $stats['removed']);
        $this->assertFileDoesNotExist($path);
    }

    public function test_removes_a_file_naming_a_missing_newsfeed_post(): void
    {
        $path = $this->writePending('missing_nf', [
            'chatmessages' => [], 'messages' => [], 'newsfeed' => [999999999], 'users' => [],
        ]);

        $stats = $this->filter->filter();

        $this->assertSame(1, $stats['removed']);
        $this->assertFileDoesNotExist($path);
    }

    public function test_keeps_a_file_naming_a_visible_newsfeed_post(): void
    {
        $user = $this->createTestUser();
        $nfid = $this->makeNewsfeedPost($user);

        $path = $this->writePending('good_nf', [
            'chatmessages' => [], 'messages' => [], 'newsfeed' => [$nfid], 'users' => [],
        ]);

        $stats = $this->filter->filter();

        $this->assertSame(0, $stats['removed']);
        $this->assertFileExists($path);
    }

    // --- users bucket ---

    public function test_removes_a_file_naming_a_now_spammer_author(): void
    {
        $user = $this->createTestUser();
        SpamUser::create([
            'userid' => $user->id,
            'collection' => 'Spammer',
            'added' => now(),
        ]);

        $path = $this->writePending('spammer_author', [
            'chatmessages' => [], 'messages' => [], 'newsfeed' => [], 'users' => [$user->id],
        ], 'ask_for_donation');

        // count() only writes a lockdown_counters row while a lockdown is active
        // (section 11.6); filter-spool only ever runs during one in production.
        $this->lockdown->press(null, 'test');

        $stats = $this->filter->filter();

        $this->assertSame(1, $stats['removed']);
        $this->assertFileDoesNotExist($path);
        $this->assertSame(1, $this->counterFor('filtered:email:ask_for_donation'));
    }

    public function test_keeps_a_file_naming_an_author_not_a_spammer(): void
    {
        $user = $this->createTestUser();

        $path = $this->writePending('ok_author', [
            'chatmessages' => [], 'messages' => [], 'newsfeed' => [], 'users' => [$user->id],
        ]);

        $stats = $this->filter->filter();

        $this->assertSame(0, $stats['removed']);
        $this->assertFileExists($path);
    }

    public function test_keeps_a_file_naming_a_whitelisted_author(): void
    {
        // Whitelisted is a different collection to Spammer - only Spammer removes.
        $user = $this->createTestUser();
        SpamUser::create([
            'userid' => $user->id,
            'collection' => 'Whitelisted',
            'added' => now(),
        ]);

        $path = $this->writePending('whitelisted_author', [
            'chatmessages' => [], 'messages' => [], 'newsfeed' => [], 'users' => [$user->id],
        ]);

        $stats = $this->filter->filter();

        $this->assertSame(0, $stats['removed']);
        $this->assertFileExists($path);
    }

    // --- multiple files, moved-not-deleted ---

    public function test_checks_every_pending_file_independently_and_moves_removed_ones(): void
    {
        $sender = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $this->createTestUser());
        $goodMessage = $this->createTestChatMessage($room, $sender);
        $badMessage = $this->createTestChatMessage($room, $sender, ['reviewrejected' => 1]);

        $goodPath = $this->writePending('good', [
            'chatmessages' => [$goodMessage->id], 'messages' => [], 'newsfeed' => [], 'users' => [],
        ]);
        $badPath = $this->writePending('bad', [
            'chatmessages' => [$badMessage->id], 'messages' => [], 'newsfeed' => [], 'users' => [],
        ]);

        $stats = $this->filter->filter();

        $this->assertSame(2, $stats['checked']);
        $this->assertSame(1, $stats['removed']);
        $this->assertSame(0, $stats['errors']);
        $this->assertFileExists($goodPath);
        $this->assertFileDoesNotExist($badPath);
        // Moved for the incident report, never straight deletion (section 11.8).
        $this->assertFileExists($this->testSpoolDir.'/lockdown-removed/bad.json');
    }
}
