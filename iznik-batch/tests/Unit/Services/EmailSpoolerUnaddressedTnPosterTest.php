<?php

namespace Tests\Unit\Services;

use App\Mail\Chat\ChatNotification;
use App\Mail\Welcome\WelcomeMail;
use App\Models\ChatRoom;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\TestCase;

/**
 * A TN poster whose only posts were placed on communities they never chose has not
 * opted in to Freegle, so the spooler refuses Freegle's own mail to them - whatever
 * mailer sent it - while members answering their post still reach them.
 */
class EmailSpoolerUnaddressedTnPosterTest extends TestCase
{
    use IsolatedSpoolDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedSpoolDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSpoolDirectory();
        parent::tearDown();
    }

    /**
     * A TN member with the given posts, each origin row carrying the given
     * mod_messaging_allowed. Returns the user and their TN address.
     *
     * @param  int[]  $postsAllowed
     * @return array{0: User, 1: string}
     */
    private function tnPoster(array $postsAllowed): array
    {
        $user = $this->createTestUser();
        $address = 'tn' . uniqid() . '@user.trashnothing.com';
        // Raw insert: the address is all that matters here.
        DB::table('users_emails')->insert([
            'userid'    => $user->id,
            'email'     => $address,
            'preferred' => 1,
            'added'     => now(),
        ]);

        $group = $this->createTestGroup();
        foreach ($postsAllowed as $allowed) {
            $msgId = DB::table('messages')->insertGetId([
                'fromuser' => $user->id,
                'subject'  => 'OFFER: Test item ' . $allowed,
                'date'     => now(),
            ]);
            DB::table('messages_groups')->insert([
                'msgid'                 => $msgId,
                'groupid'               => $group->id,
                'collection'            => 'Pending',
                'rippled_in'            => 0,
                'mod_messaging_allowed' => $allowed,
            ]);
        }

        return [$user, $address];
    }

    public function test_freegle_mail_to_an_unaddressed_tn_poster_is_not_spooled(): void
    {
        [, $address] = $this->tnPoster([0]);

        $this->assertSame('', $this->spooler->spool(new WelcomeMail($address), $address, 'welcome'));
        $this->assertEmpty(glob($this->testSpoolDir . '/pending/*.json'));
    }

    public function test_mail_to_a_tn_poster_with_an_addressed_post_is_spooled(): void
    {
        // A "mixed" poster chose a Freegle community at least once, so is a real member.
        [, $address] = $this->tnPoster([0, 1]);

        $this->assertNotSame('', $this->spooler->spool(new WelcomeMail($address), $address, 'welcome'));
    }

    public function test_mail_to_a_tn_member_with_no_posts_is_spooled(): void
    {
        [, $address] = $this->tnPoster([]);

        $this->assertNotSame('', $this->spooler->spool(new WelcomeMail($address), $address, 'welcome'));
    }

    public function test_a_member_replying_to_their_post_still_reaches_them(): void
    {
        [$poster, $address] = $this->tnPoster([0]);
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($replier, $poster);
        $message = $this->createTestChatMessage($room, $replier, ['message' => 'Is this still available?']);

        $mail = new ChatNotification($poster, $replier, $room, $message, ChatRoom::TYPE_USER2USER);

        $this->assertNotSame('', $this->spooler->spool($mail, $address, 'chat'));
    }

    public function test_account_mail_they_asked_for_is_still_spooled(): void
    {
        [, $address] = $this->tnPoster([0]);

        $this->assertNotSame('', $this->spooler->spool(new WelcomeMail($address), $address, 'password_reset'));
    }
}
