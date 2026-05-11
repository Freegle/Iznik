<?php

namespace Tests\Feature\Birthday;

use App\Mail\Birthday\BirthdayMail;
use App\Services\BirthdayService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BirthdayCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function createMemberInGroup(int $groupId, array $userAttributes = []): object
    {
        $user = $this->createTestUser(array_merge([
            'marketingconsent' => 1,
            'bouncing'         => 0,
            'lastaccess'       => now(),
        ], $userAttributes));

        DB::table('memberships')->insert([
            'userid'     => $user->id,
            'groupid'    => $groupId,
            'role'       => 'Member',
            'collection' => 'Approved',
            'added'      => now(),
        ]);

        return $user;
    }

    public function test_command_runs_cleanly_with_no_birthday_groups(): void
    {
        $this->artisan('birthday:send-emails')
            ->assertExitCode(0);
    }

    public function test_dry_run_is_accepted(): void
    {
        $this->artisan('birthday:send-emails', ['--dry-run' => true])
            ->assertExitCode(0);
    }

    public function test_sends_birthday_email_to_group_member(): void
    {
        // Founded exactly 1 year ago — same month/day, prior year
        $founded = now()->subYear()->format('Y-m-d');
        $group = $this->createTestGroup(['founded' => $founded]);
        $this->createMemberInGroup($group->id);

        (new BirthdayService())->sendBirthdayEmails();

        Mail::assertSent(BirthdayMail::class, 1);
    }

    public function test_skips_group_not_founded_today(): void
    {
        // Founded last year but on a different day
        $founded = now()->subYear()->subDays(5)->format('Y-m-d');
        $group = $this->createTestGroup(['founded' => $founded]);
        $this->createMemberInGroup($group->id);

        (new BirthdayService())->sendBirthdayEmails();

        Mail::assertNothingSent();
    }

    public function test_skips_group_with_null_founded(): void
    {
        $group = $this->createTestGroup(['founded' => null]);
        $this->createMemberInGroup($group->id);

        (new BirthdayService())->sendBirthdayEmails();

        Mail::assertNothingSent();
    }

    public function test_skips_group_founded_this_year(): void
    {
        // Founded today but in the current year (age = 0) — should not trigger
        $founded = now()->format('Y-m-d');
        $group = $this->createTestGroup(['founded' => $founded]);
        $this->createMemberInGroup($group->id);

        (new BirthdayService())->sendBirthdayEmails();

        Mail::assertNothingSent();
    }

    public function test_skips_member_without_marketing_consent(): void
    {
        $founded = now()->subYear()->format('Y-m-d');
        $group = $this->createTestGroup(['founded' => $founded]);
        $this->createMemberInGroup($group->id, ['marketingconsent' => 0]);

        (new BirthdayService())->sendBirthdayEmails();

        Mail::assertNothingSent();
    }

    public function test_skips_bouncing_member(): void
    {
        $founded = now()->subYear()->format('Y-m-d');
        $group = $this->createTestGroup(['founded' => $founded]);
        $this->createMemberInGroup($group->id, ['bouncing' => 1]);

        (new BirthdayService())->sendBirthdayEmails();

        Mail::assertNothingSent();
    }

    public function test_skips_deleted_user(): void
    {
        $founded = now()->subYear()->format('Y-m-d');
        $group = $this->createTestGroup(['founded' => $founded]);
        $this->createMemberInGroup($group->id, ['deleted' => now()]);

        (new BirthdayService())->sendBirthdayEmails();

        Mail::assertNothingSent();
    }

    public function test_skips_inactive_member(): void
    {
        $founded = now()->subYear()->format('Y-m-d');
        $group = $this->createTestGroup(['founded' => $founded]);
        $this->createMemberInGroup($group->id, ['lastaccess' => now()->subDays(200)]);

        (new BirthdayService())->sendBirthdayEmails();

        Mail::assertNothingSent();
    }

    public function test_skips_member_recently_sent_birthday_appeal(): void
    {
        $founded = now()->subYear()->format('Y-m-d');
        $group = $this->createTestGroup(['founded' => $founded]);
        $settings = json_encode(['lastbirthdayappeal' => now()->subDays(10)->format('Y-m-d H:i:s')]);
        $this->createMemberInGroup($group->id, ['settings' => $settings]);

        (new BirthdayService())->sendBirthdayEmails();

        Mail::assertNothingSent();
    }

    public function test_sends_to_member_with_old_birthday_appeal(): void
    {
        $founded = now()->subYear()->format('Y-m-d');
        $group = $this->createTestGroup(['founded' => $founded]);
        $settings = json_encode(['lastbirthdayappeal' => now()->subDays(40)->format('Y-m-d H:i:s')]);
        $this->createMemberInGroup($group->id, ['settings' => $settings]);

        (new BirthdayService())->sendBirthdayEmails();

        Mail::assertSent(BirthdayMail::class, 1);
    }

    public function test_records_birthday_appeal_sent_in_settings(): void
    {
        $founded = now()->subYear()->format('Y-m-d');
        $group = $this->createTestGroup(['founded' => $founded]);
        $member = $this->createMemberInGroup($group->id);

        (new BirthdayService())->sendBirthdayEmails();

        $settings = json_decode(DB::table('users')->where('id', $member->id)->value('settings'), true);
        $this->assertNotNull($settings['lastbirthdayappeal'] ?? null);
    }

    public function test_filters_to_specified_group_ids(): void
    {
        $founded = now()->subYear()->format('Y-m-d');
        $group1 = $this->createTestGroup(['founded' => $founded]);
        $group2 = $this->createTestGroup(['founded' => $founded]);
        $this->createMemberInGroup($group1->id);
        $this->createMemberInGroup($group2->id);

        (new BirthdayService())->sendBirthdayEmails(null, [$group1->id]);

        Mail::assertSent(BirthdayMail::class, 1);
    }

    public function test_email_override_sends_to_specified_address(): void
    {
        $founded = now()->subYear()->format('Y-m-d');
        $group = $this->createTestGroup(['founded' => $founded]);
        $this->createMemberInGroup($group->id);

        $overrideEmail = 'override@test.com';
        (new BirthdayService())->sendBirthdayEmails($overrideEmail);

        Mail::assertSent(BirthdayMail::class, function (BirthdayMail $mail) use ($overrideEmail) {
            return $mail->recipientEmail === $overrideEmail;
        });
    }

    public function test_email_override_stops_after_one_email(): void
    {
        $founded = now()->subYear()->format('Y-m-d');
        $group = $this->createTestGroup(['founded' => $founded]);
        $this->createMemberInGroup($group->id);
        $this->createMemberInGroup($group->id);

        (new BirthdayService())->sendBirthdayEmails('override@test.com');

        Mail::assertSent(BirthdayMail::class, 1);
    }

    public function test_returns_count_of_emails_sent(): void
    {
        $founded = now()->subYear()->format('Y-m-d');
        $group = $this->createTestGroup(['founded' => $founded]);
        $this->createMemberInGroup($group->id);
        $this->createMemberInGroup($group->id);

        $count = (new BirthdayService())->sendBirthdayEmails();

        $this->assertSame(2, $count);
    }

    public function test_uses_contactmail_when_set(): void
    {
        $founded = now()->subYear()->format('Y-m-d');
        $group = $this->createTestGroup([
            'founded'     => $founded,
            'contactmail' => 'custom@example.com',
        ]);
        $this->createMemberInGroup($group->id);

        (new BirthdayService())->sendBirthdayEmails();

        Mail::assertSent(BirthdayMail::class, function (BirthdayMail $mail) {
            return $mail->fromEmail === 'custom@example.com';
        });
    }

    public function test_uses_volunteers_group_address_when_no_contactmail(): void
    {
        $founded = now()->subYear()->format('Y-m-d');
        $group = $this->createTestGroup([
            'founded'     => $founded,
            'contactmail' => null,
        ]);
        $this->createMemberInGroup($group->id);

        (new BirthdayService())->sendBirthdayEmails();

        Mail::assertSent(BirthdayMail::class, function (BirthdayMail $mail) use ($group) {
            return str_ends_with($mail->fromEmail, '-volunteers@' . config('freegle.mail.group_domain'));
        });
    }
}
