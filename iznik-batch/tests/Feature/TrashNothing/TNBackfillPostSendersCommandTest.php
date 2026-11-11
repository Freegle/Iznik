<?php

namespace Tests\Feature\TrashNothing;

use App\Models\Message;
use App\Models\User;
use App\Models\UserEmail;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * tn:backfill-post-senders gives posts the TN API ingested before it recorded a sender the
 * member's TrashNothing address as their sender, as ingestion does now.
 */
class TNBackfillPostSendersCommandTest extends TestCase
{
    private function tnAddress(): string
    {
        return 'tricia.hayes' . uniqid() . '-g298' . User::TN_EMAIL_SUFFIX;
    }

    /**
     * A post as the TN API ingested it before this fix: no sender anywhere, and TN's noreply
     * address on the From line of the stored message.
     */
    private function tnApiPost(User $user, array $attributes = []): Message
    {
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group, array_merge([
            'source'       => Message::SOURCE_EMAIL,
            'sourceheader' => 'TN-API',
            'tnpostid'     => 'tn-' . uniqid(),
            'fromname'     => 'Tricia Hayes',
            'fromaddr'     => null,
            'envelopefrom' => null,
            'message'      => "From: Tricia Hayes <noreply@trashnothing.com>\r\nTo: x@groups.ilovefreegle.org\r\n\r\nBlue bike.",
        ], $attributes));

        DB::table('messages_history')->insert([
            'msgid'    => $message->id,
            'groupid'  => $group->id,
            'source'   => Message::SOURCE_EMAIL,
            'fromuser' => $user->id,
            'fromname' => 'Tricia Hayes',
            'subject'  => $message->subject,
        ]);

        return $message;
    }

    private function history(Message $message): object
    {
        return DB::table('messages_history')->where('msgid', $message->id)->first();
    }

    public function test_dry_run_reports_and_writes_nothing(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->tnAddress()]);
        $message = $this->tnApiPost($user);

        $this->artisan('tn:backfill-post-senders', ['--since' => now()->subDay()->toDateString()])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertNull($message->fresh()->fromaddr);
        $this->assertNull($this->history($message)->fromaddr);
    }

    public function test_apply_sets_the_sender_on_the_post_its_history_and_its_from_line(): void
    {
        $address = $this->tnAddress();
        $user = $this->createTestUser(['email_preferred' => $address]);
        $message = $this->tnApiPost($user);

        $this->artisan('tn:backfill-post-senders', ['--apply' => TRUE, '--since' => now()->subDay()->toDateString()])
            ->assertSuccessful();

        $fresh = $message->fresh();
        $this->assertSame($address, $fresh->fromaddr);
        $this->assertSame($address, $fresh->envelopefrom);
        $this->assertStringStartsWith("From: Tricia Hayes <{$address}>\r\nTo: ", $fresh->message);
        $this->assertSame($address, $this->history($message)->fromaddr);
        $this->assertSame($address, $this->history($message)->envelopefrom);
    }

    public function test_uses_the_newest_tn_address_when_the_preferred_email_is_not_one(): void
    {
        $user = $this->createTestUser();
        $older = $this->tnAddress();
        $newer = $this->tnAddress();
        foreach ([$older, $newer] as $email) {
            UserEmail::create(['userid' => $user->id, 'email' => $email, 'preferred' => 0, 'added' => now()]);
        }
        $message = $this->tnApiPost($user);

        $this->artisan('tn:backfill-post-senders', ['--apply' => TRUE, '--since' => now()->subDay()->toDateString()])
            ->assertSuccessful();

        $this->assertSame($newer, $message->fresh()->fromaddr);
    }

    public function test_a_member_with_no_tn_address_is_left_alone(): void
    {
        $message = $this->tnApiPost($this->createTestUser());

        $this->artisan('tn:backfill-post-senders', ['--apply' => TRUE, '--since' => now()->subDay()->toDateString()])
            ->assertSuccessful();

        $this->assertNull($message->fresh()->fromaddr);
        $this->assertStringStartsWith('From: Tricia Hayes <noreply@trashnothing.com>', $message->fresh()->message);
    }

    public function test_leaves_posts_with_a_sender_other_sources_and_older_posts(): void
    {
        $address = $this->tnAddress();
        $user = $this->createTestUser(['email_preferred' => $address]);

        $hasSender = $this->tnApiPost($user, ['fromaddr' => 'already@user.trashnothing.com']);
        $byEmail   = $this->tnApiPost($user, ['sourceheader' => 'TN-native-app']);
        $older     = $this->tnApiPost($user, ['arrival' => now()->subDays(10)]);

        $this->artisan('tn:backfill-post-senders', ['--apply' => TRUE, '--since' => now()->subDay()->toDateString()])
            ->assertSuccessful();

        $this->assertSame('already@user.trashnothing.com', $hasSender->fresh()->fromaddr);
        $this->assertNull($byEmail->fresh()->fromaddr);
        $this->assertNull($older->fresh()->fromaddr);
    }

    public function test_a_post_with_no_stored_message_still_gets_its_sender(): void
    {
        $address = $this->tnAddress();
        $user = $this->createTestUser(['email_preferred' => $address]);
        $message = $this->tnApiPost($user, ['message' => '']);

        $this->artisan('tn:backfill-post-senders', ['--apply' => TRUE, '--since' => now()->subDay()->toDateString()])
            ->assertSuccessful();

        $this->assertSame($address, $message->fresh()->fromaddr);
        $this->assertSame('', $message->fresh()->message);
    }
}
