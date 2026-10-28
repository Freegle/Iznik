<?php

namespace Tests\Unit\Services;

use App\Mail\Chat\ChatNotification;
use App\Mail\Digest\UnifiedDigest;
use App\Mail\Volunteering\VolunteeringDigestMail;
use App\Models\ChatRoom;
use App\Models\User;
use App\Services\BirthdayService;
use App\Services\DonationService;
use App\Services\UnifiedDigestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A donor can ask never to be asked to donate again (users.settings.nodonationasks), from
 * their own Settings or through support. That has to stop every donation ask we send by
 * email: the ask email itself, the birthday appeal, and the donate buttons that ride along
 * in the digest, chat notification and volunteering digest.
 */
class DonationAskOptOutTest extends TestCase
{
    private function optedOutUser(): User
    {
        $user = $this->createTestUser();
        $user->settings = ['nodonationasks' => TRUE];
        $user->save();

        return $user->fresh();
    }

    public function test_absent_setting_means_we_may_ask(): void
    {
        $user = $this->createTestUser();
        $user->settings = ['simplemail' => 'Full'];
        $user->save();

        $this->assertTrue($user->fresh()->wantsDonationAsks());
    }

    public function test_null_settings_means_we_may_ask(): void
    {
        $user = $this->createTestUser();
        $user->settings = NULL;
        $user->save();

        $this->assertTrue($user->fresh()->wantsDonationAsks());
    }

    public function test_setting_false_means_we_may_ask(): void
    {
        $user = $this->createTestUser();
        $user->settings = ['nodonationasks' => FALSE];
        $user->save();

        $this->assertTrue($user->fresh()->wantsDonationAsks());
    }

    public function test_setting_true_opts_them_out(): void
    {
        $this->assertFalse($this->optedOutUser()->wantsDonationAsks());
    }

    public function test_raw_settings_json_is_understood(): void
    {
        $this->assertFalse(User::settingsAllowDonationAsks('{"nodonationasks":true}'));
        $this->assertTrue(User::settingsAllowDonationAsks('{"nodonationasks":false}'));
        $this->assertTrue(User::settingsAllowDonationAsks('{}'));
        $this->assertTrue(User::settingsAllowDonationAsks(NULL));
    }

    public function test_opt_out_holds_until_its_date_then_lapses(): void
    {
        $future = now()->utc()->addMonth()->format('Y-m-d\TH:i:s\Z');
        $past = now()->utc()->subDay()->format('Y-m-d\TH:i:s\Z');

        $this->assertFalse(User::settingsAllowDonationAsks(['nodonationasks' => TRUE, 'nodonationasksuntil' => $future]));
        $this->assertTrue(User::settingsAllowDonationAsks(['nodonationasks' => TRUE, 'nodonationasksuntil' => $past]),
            'a lapsed opt-out no longer holds asks back');
        $this->assertFalse(User::settingsAllowDonationAsks(['nodonationasks' => TRUE, 'nodonationasksuntil' => NULL]),
            'an undated opt-out runs until donations:lapse-optouts dates it');
        $this->assertFalse(User::settingsAllowDonationAsks(['nodonationasks' => TRUE, 'nodonationasksuntil' => 'junk']),
            'an unreadable date errs on not asking');
    }

    public function test_ask_email_skips_opted_out_recipient(): void
    {
        Mail::fake();

        $askedRecipient = $this->createTestUser();
        $optedOut = $this->optedOutUser();

        $this->giveRecentlyReceivedItem($askedRecipient);
        $this->giveRecentlyReceivedItem($optedOut);

        app(DonationService::class)->askForDonations();

        $this->assertTrue(
            DB::table('users_donations_asks')->where('userid', $askedRecipient->id)->exists(),
            'a member who has not opted out is still asked'
        );
        $this->assertFalse(
            DB::table('users_donations_asks')->where('userid', $optedOut->id)->exists(),
            'a donor who asked not to be asked must not get the ask email'
        );
    }

    public function test_birthday_appeal_skips_opted_out_member(): void
    {
        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)->update([
            'type' => 'Freegle',
            'publish' => 1,
            'onmap' => 1,
            'founded' => now()->subYear()->format('Y-m-d H:i:s'),
        ]);

        $asked = $this->makeBirthdayRecipient([]);
        $optedOut = $this->makeBirthdayRecipient(['nodonationasks' => TRUE]);

        foreach ([$asked, $optedOut] as $user) {
            DB::table('memberships')->insert([
                'userid' => $user->id, 'groupid' => $group->id,
                'role' => 'Member', 'collection' => 'Approved', 'rippled' => 0, 'added' => now(),
            ]);
        }

        $count = (new BirthdayService())->sendBirthdayEmails(NULL, [$group->id], dryRun: TRUE);

        $this->assertSame(1, $count, 'only the member who has not opted out gets the birthday appeal');
    }

    public function test_digest_has_no_donate_url_for_opted_out_member(): void
    {
        $this->assertNotNull($this->digestData($this->createTestUser())['donateUrl']);
        $this->assertNull($this->digestData($this->optedOutUser())['donateUrl']);
    }

    public function test_chat_notification_has_no_donate_url_for_opted_out_recipient(): void
    {
        $sender = $this->createTestUser();

        $this->assertNotNull($this->chatData($this->createTestUser(), $sender)['donateUrl']);
        $this->assertNull($this->chatData($this->optedOutUser(), $sender)['donateUrl']);
    }

    public function test_volunteering_digest_leaves_out_donate_button_for_opted_out_member(): void
    {
        $this->assertStringContainsString('Donating helps too!', $this->volunteeringHtml($this->createTestUser()));
        $this->assertStringNotContainsString('Donating helps too!', $this->volunteeringHtml($this->optedOutUser()));
    }

    public function test_digest_jobs_block_leaves_out_donate_without_url(): void
    {
        $job = (object) [
            'title' => 'Warehouse Operative',
            'location' => 'Edinburgh',
            'tracked_url' => 'https://example.com/job/1?t=1',
            'image_url' => 'https://example.com/job1.png',
        ];

        $withJobs = $this->renderJobsPartial([$job], NULL);
        $this->assertStringContainsString('More jobs', $withJobs);
        $this->assertStringNotContainsString('Donate', $withJobs);

        // With no jobs the block is just the donate ask, so nothing is left.
        $this->assertStringNotContainsString('Donat', $this->renderJobsPartial([], NULL));

        // And the ask is still there for everyone else.
        $this->assertStringContainsString('Donate', $this->renderJobsPartial([$job], 'https://example.com/donate'));
        $this->assertStringContainsString('Donating helps too!', $this->renderJobsPartial([], 'https://example.com/donate'));
    }

    public function test_amp_digest_leaves_out_donate_without_url(): void
    {
        $job = (object) [
            'title' => 'Warehouse Operative',
            'location' => 'Edinburgh',
            'tracked_url' => 'https://example.com/job/1?t=1',
            'image_url' => 'https://example.com/job1.png',
        ];
        $data = [
            'user' => (object) ['email_preferred' => 'r@example.com', 'displayname' => 'Sam'],
            'posts' => collect(),
            'postCount' => 0,
            'mode' => UnifiedDigestService::MODE_DAILY,
            'isSingle' => FALSE,
            'sponsors' => collect(),
            'jobAds' => collect([$job]),
            'jobsUrl' => 'https://example.com/jobs?t=1',
            'donateUrl' => NULL,
            'browseUrl' => 'https://example.com/browse?t=1',
            'settingsUrl' => 'https://example.com/settings?t=1',
            'unsubscribeUrl' => 'https://example.com/unsubscribe?t=1',
            'userSite' => 'https://example.com',
            'siteName' => 'Freegle',
        ];

        $html = view('emails.amp.digest.unified', $data)->render();

        $this->assertStringContainsString('View more jobs', $html);
        $this->assertStringNotContainsString('Donating helps too!', $html);
    }

    private function renderJobsPartial(array $jobs, ?string $donateUrl): string
    {
        return view('emails.mjml.digest._jobs', [
            'jobAds' => collect($jobs),
            'jobsUrl' => 'https://example.com/jobs',
            'donateUrl' => $donateUrl,
            'donateMarksUrl' => 'https://example.com/marks.png',
            'accentColor' => '#338808',
        ])->render();
    }

    private function mjmlData(object $mail): array
    {
        $ref = new \ReflectionProperty($mail, 'mjmlData');
        $ref->setAccessible(TRUE);

        return $ref->getValue($mail);
    }

    private function digestData(User $user): array
    {
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Sofa (London)']);

        $mail = new UnifiedDigest($user, collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]), UnifiedDigestService::MODE_DAILY);
        $mail->build();

        return $this->mjmlData($mail);
    }

    private function chatData(User $recipient, User $sender): array
    {
        $room = $this->createTestChatRoom($sender, $recipient);
        $message = $this->createTestChatMessage($room, $sender, ['message' => 'Is it still there?']);

        $mail = new ChatNotification($recipient, $sender, $room, $message, ChatRoom::TYPE_USER2USER);
        $mail->build();

        return $this->mjmlData($mail);
    }

    private function volunteeringHtml(User $user): string
    {
        $userSite = config('freegle.sites.user');

        $mail = new VolunteeringDigestMail(
            recipientEmail: 'test@example.com',
            volunteerings: [[
                'id' => 1,
                'title' => 'Help at local food bank',
                'location' => 'Town Hall',
                'description' => 'We need helpers every Saturday.',
                'timecommitment' => '3 hours per week',
                'online' => FALSE,
                'contactname' => NULL,
                'contactphone' => NULL,
                'contactemail' => NULL,
                'contacturl' => NULL,
                'photo_thumb' => NULL,
                'applyby' => NULL,
                'url' => "{$userSite}/volunteering/1",
            ]],
            unsubscribeUrl: "{$userSite}/unsubscribe",
            jobAds: collect([(object) [
                'id' => 99,
                'title' => 'Warehouse Operative',
                'location' => 'Edinburgh',
                'image_url' => NULL,
            ]]),
            userId: $user->id,
        );

        return $mail->render();
    }

    /** A user who passes every birthday gate: consenting, active, contactable, no recent appeal. */
    private function makeBirthdayRecipient(array $extraSettings): User
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        DB::table('users')->where('id', $user->id)->update([
            'marketingconsent' => 1,
            'bouncing' => 0,
            'deleted' => NULL,
            'onholidaytill' => NULL,
            'lastaccess' => now(),
            'settings' => json_encode(array_merge(['notifications' => ['email' => TRUE]], $extraSettings)),
        ]);

        return $user;
    }

    /** A member who received someone's offer within yesterday-17:00 to today-17:00. */
    private function giveRecentlyReceivedItem(User $recipient): void
    {
        $sender = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($sender, $group);
        $this->createMembership($recipient, $group);

        $message = \App\Models\Message::create([
            'type' => \App\Models\Message::TYPE_OFFER,
            'fromuser' => $sender->id,
            'subject' => 'OFFER: Test Item (Location)',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
        ]);
        \App\Models\MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => \App\Models\MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(5),
        ]);

        $room = \App\Models\ChatRoom::create([
            'chattype' => \App\Models\ChatRoom::TYPE_USER2USER,
            'user1' => $sender->id,
            'user2' => $recipient->id,
            'created' => now()->subDays(4),
        ]);
        \App\Models\ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'message' => 'Is this available?',
            'type' => 'Interested',
            'refmsgid' => $message->id,
            'date' => now()->subDays(4),
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        DB::table('messages_by')->insert([
            'userid' => $recipient->id,
            'msgid' => $message->id,
            'timestamp' => now()->subDay()->setTime(17, 30),
        ]);
    }
}
