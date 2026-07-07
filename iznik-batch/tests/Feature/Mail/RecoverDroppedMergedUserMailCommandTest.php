<?php

namespace Tests\Feature\Mail;

use App\Models\ChatMessage;
use App\Models\User;
use App\Models\UserEmail;
use Illuminate\Support\Facades\DB;
use Tests\Support\EmailFixtures;
use Tests\TestCase;

class RecoverDroppedMergedUserMailCommandTest extends TestCase
{
    use EmailFixtures;

    private string $archiveDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archiveDir = storage_path('incoming-archive/test-recover');
        @mkdir($this->archiveDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up test archive files
        if (is_dir($this->archiveDir)) {
            foreach (scandir($this->archiveDir) as $f) {
                if ($f !== '.' && $f !== '..') {
                    @unlink($this->archiveDir.'/'.$f);
                }
            }
            @rmdir($this->archiveDir);
        }
        parent::tearDown();
    }

    private function createArchiveFile(string $from, string $to, string $rawEmail, string $outcome = 'Dropped'): string
    {
        $filename = date('His').'_'.mt_rand(100000, 999999).'.json';
        $path = $this->archiveDir.'/'.$filename;

        $data = [
            'version' => 3,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'envelope' => [
                'from' => $from,
                'to' => $to,
            ],
            'raw_email' => base64_encode($rawEmail),
            'routing_outcome' => $outcome,
        ];

        file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES));

        return $path;
    }

    public function test_recovers_dropped_mail_for_merged_user(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        // Create a deleted user to simulate the merged-away user
        $deletedUser = User::create(['fullname' => 'Deleted Merged User', 'added' => now()]);
        $fakeOldUid = $deletedUser->id;
        $deletedUser->delete();

        // Register the old proxy email against the surviving user
        $oldProxyEmail = "testslug-{$fakeOldUid}@users.ilovefreegle.org";
        UserEmail::create([
            'userid' => $poster->id,
            'email' => $oldProxyEmail,
            'preferred' => 0,
            'added' => now(),
        ]);

        $replierEmail = $replier->emails->first()->email;

        // Create archive file with the dropped email
        $rawEmail = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => $oldProxyEmail,
            'Subject' => 'Re: '.$message->subject,
            'x-fd-msgid' => (string) $message->id,
        ], 'I would love this item!');

        $archivePath = $this->createArchiveFile($replierEmail, $oldProxyEmail, $rawEmail);

        // Execute recovery on the single file
        $this->artisan('mail:recover-dropped-merged', [
            '--file' => $archivePath,
            '--execute' => true,
            '--prefix' => 'Apology note',
        ])->assertExitCode(0);

        // Verify chat message was created
        $chatMsg = DB::table('chat_messages')
            ->where('userid', $replier->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($chatMsg, 'Chat message should be created');
        $this->assertEquals($message->id, $chatMsg->refmsgid);
        $this->assertStringContainsString('Apology note', $chatMsg->message);
        $this->assertStringContainsString('I would love this item!', $chatMsg->message);
    }

    public function test_skips_duplicate_on_second_run(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        $deletedUser = User::create(['fullname' => 'Deleted Merged User', 'added' => now()]);
        $fakeOldUid = $deletedUser->id;
        $deletedUser->delete();

        $oldProxyEmail = "testslug-{$fakeOldUid}@users.ilovefreegle.org";
        UserEmail::create([
            'userid' => $poster->id,
            'email' => $oldProxyEmail,
            'preferred' => 0,
            'added' => now(),
        ]);

        $replierEmail = $replier->emails->first()->email;

        $rawEmail = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => $oldProxyEmail,
            'Subject' => 'Re: '.$message->subject,
            'x-fd-msgid' => (string) $message->id,
        ], 'I would love this item!');

        $archivePath = $this->createArchiveFile($replierEmail, $oldProxyEmail, $rawEmail);

        // First run - should create message
        $this->artisan('mail:recover-dropped-merged', [
            '--file' => $archivePath,
            '--execute' => true,
        ])->assertExitCode(0);

        $countAfterFirst = DB::table('chat_messages')
            ->where('userid', $replier->id)
            ->count();

        // Second run - should skip duplicate
        $this->artisan('mail:recover-dropped-merged', [
            '--file' => $archivePath,
            '--execute' => true,
        ])->assertExitCode(0);

        $countAfterSecond = DB::table('chat_messages')
            ->where('userid', $replier->id)
            ->count();

        $this->assertEquals($countAfterFirst, $countAfterSecond, 'Second run should not create duplicate messages');
    }

    public function test_no_prefix_when_omitted(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        $deletedUser = User::create(['fullname' => 'Deleted Merged User', 'added' => now()]);
        $fakeOldUid = $deletedUser->id;
        $deletedUser->delete();

        $oldProxyEmail = "testslug-{$fakeOldUid}@users.ilovefreegle.org";
        UserEmail::create([
            'userid' => $poster->id,
            'email' => $oldProxyEmail,
            'preferred' => 0,
            'added' => now(),
        ]);

        $replierEmail = $replier->emails->first()->email;

        $rawEmail = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => $oldProxyEmail,
            'Subject' => 'Re: '.$message->subject,
        ], 'Just the message body');

        $archivePath = $this->createArchiveFile($replierEmail, $oldProxyEmail, $rawEmail);

        $this->artisan('mail:recover-dropped-merged', [
            '--file' => $archivePath,
            '--execute' => true,
        ])->assertExitCode(0);

        $chatMsg = DB::table('chat_messages')
            ->where('userid', $replier->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($chatMsg);
        $this->assertStringNotContainsString('---', $chatMsg->message);
        $this->assertStringContainsString('Just the message body', $chatMsg->message);
    }

    public function test_dry_run_does_not_create_messages(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        $deletedUser = User::create(['fullname' => 'Deleted Merged User', 'added' => now()]);
        $fakeOldUid = $deletedUser->id;
        $deletedUser->delete();

        $oldProxyEmail = "testslug-{$fakeOldUid}@users.ilovefreegle.org";
        UserEmail::create([
            'userid' => $poster->id,
            'email' => $oldProxyEmail,
            'preferred' => 0,
            'added' => now(),
        ]);

        $replierEmail = $replier->emails->first()->email;

        $rawEmail = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => $oldProxyEmail,
            'Subject' => 'Re: '.$message->subject,
        ], 'Should not be delivered');

        $archivePath = $this->createArchiveFile($replierEmail, $oldProxyEmail, $rawEmail);

        // Run without --execute (dry run)
        $this->artisan('mail:recover-dropped-merged', [
            '--file' => $archivePath,
        ])->assertExitCode(0);

        $chatMsg = DB::table('chat_messages')
            ->where('userid', $replier->id)
            ->count();

        $this->assertEquals(0, $chatMsg, 'Dry run should not create any messages');
    }
}
