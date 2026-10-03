<?php

namespace Tests\Feature\Partnerships;

use App\Mail\Partnerships\SponsorshipExpiringMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Council budget rounds take months, so the Partnerships team needs warning well before a
 * sponsorship lapses. These tests pin who gets chased, who does not, and that nobody gets
 * chased twice for the same window.
 */
class SponsorshipRemindersCommandTest extends TestCase
{
    private int $authorityId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->authorityId = (int) DB::table('authorities')->insertGetId([
            'name' => 'Reminder Test Council ' . uniqid(),
            'polygon' => DB::raw("ST_GeomFromText('POLYGON((-1 10, 1 10, 1 12, -1 12, -1 10))', 3857)"),
        ]);

        // Other tests share this database; park anything already due so each test only sees
        // the partnership it created.
        DB::table('partnerships')->update(['status' => 'Quoted']);
    }

    /** Create a partnership ending $daysAway from today. */
    private function partnership(int $daysAway, array $overrides = []): int
    {
        $end = Carbon::today()->addDays($daysAway);

        return (int) DB::table('partnerships')->insertGetId(array_merge([
            'authorityid' => $this->authorityId,
            'name' => 'Reminder Test Partnership',
            'startdate' => $end->copy()->subYear()->toDateString(),
            'enddate' => $end->toDateString(),
            'amount' => 4800,
            'status' => 'Confirmed',
            'visible' => 1,
        ], $overrides));
    }

    public function test_chases_a_sponsorship_inside_the_window(): void
    {
        $id = $this->partnership(60);

        $this->artisan('partnerships:reminders')->assertExitCode(0);

        Mail::assertSent(SponsorshipExpiringMail::class, function ($mail) {
            return $mail->recipientEmail === 'partnerships@ilovefreegle.org'
                && $mail->partnershipName === 'Reminder Test Partnership'
                && $mail->daysLeft === 60;
        });

        $this->assertSame(1, DB::table('partnerships_reminders')
            ->where('partnershipid', $id)->where('type', '3months')->count());
    }

    public function test_does_not_chase_the_same_deal_twice(): void
    {
        $this->partnership(60);

        $this->artisan('partnerships:reminders')->assertExitCode(0);
        Mail::assertSentCount(1);

        // Running daily must not nag - the recorded reminder holds it back.
        $this->artisan('partnerships:reminders')->assertExitCode(0);
        Mail::assertSentCount(1);
    }

    public function test_ignores_a_deal_ending_beyond_the_window(): void
    {
        $this->partnership(200);

        $this->artisan('partnerships:reminders')
            ->expectsOutputToContain('No sponsorships are due a reminder')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_ignores_a_deal_that_has_already_expired(): void
    {
        // Chasing something that lapsed last month is not a renewal reminder.
        $this->partnership(-10);

        $this->artisan('partnerships:reminders')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_ignores_a_deal_that_is_not_committed(): void
    {
        $this->partnership(30, ['status' => 'Quoted']);
        $this->partnership(30, ['status' => 'InPrinciple']);

        $this->artisan('partnerships:reminders')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_chases_paid_and_overdue_deals_too(): void
    {
        $this->partnership(30, ['status' => 'Paid']);

        // A different council, or the later deal would count as renewing the first.
        $otherAuthority = (int) DB::table('authorities')->insertGetId([
            'name' => 'Other Reminder Council ' . uniqid(),
            'polygon' => DB::raw("ST_GeomFromText('POLYGON((-1 10, 1 10, 1 12, -1 12, -1 10))', 3857)"),
        ]);
        $this->partnership(40, ['status' => 'Overdue', 'authorityid' => $otherAuthority]);

        $this->artisan('partnerships:reminders')->assertExitCode(0);

        Mail::assertSentCount(2);
    }

    public function test_chases_a_deal_hidden_from_members(): void
    {
        // A council that asked not to be named still needs asking about next year.
        $this->partnership(30, ['visible' => 0]);

        $this->artisan('partnerships:reminders')->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_ignores_a_deal_that_has_already_been_renewed(): void
    {
        $this->partnership(30);
        // Next year's deal with the same council is already in.
        $this->partnership(395, ['status' => 'InPrinciple']);

        $this->artisan('partnerships:reminders')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_ended_chases_a_deal_that_ran_out_without_renewal(): void
    {
        $id = $this->partnership(-10);

        $this->artisan('partnerships:reminders', ['--ended' => true, '--days' => 30, '--type' => 'ended'])
            ->assertExitCode(0);

        Mail::assertSent(SponsorshipExpiringMail::class, function ($mail) {
            return $mail->ended
                && $mail->daysLeft === -10
                && str_contains($mail->envelope()->subject, 'ended without renewal');
        });
        $this->assertSame(1, DB::table('partnerships_reminders')
            ->where('partnershipid', $id)->where('type', 'ended')->count());
    }

    public function test_ended_ignores_deals_still_running_or_long_gone(): void
    {
        $this->partnership(10);
        $this->partnership(-100);

        $this->artisan('partnerships:reminders', ['--ended' => true, '--days' => 30, '--type' => 'ended'])
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_ended_mail_renders(): void
    {
        $this->partnership(-5);

        $this->artisan('partnerships:reminders', ['--ended' => true, '--days' => 30, '--type' => 'ended'])
            ->assertExitCode(0);

        Mail::assertSent(SponsorshipExpiringMail::class, function ($mail) {
            $text = $mail->render();

            return str_contains($text, 'nothing has been agreed to follow it')
                && str_contains($text, 'should have renewed and paid');
        });
    }

    public function test_dry_run_reports_without_sending_or_recording(): void
    {
        $id = $this->partnership(45);

        $this->artisan('partnerships:reminders', ['--dry-run' => true])
            ->expectsOutputToContain('Would send')
            ->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('partnerships_reminders')->where('partnershipid', $id)->count());
    }

    public function test_a_shorter_window_can_be_chased_separately(): void
    {
        $id = $this->partnership(20);

        // The three-month chase has already gone out.
        DB::table('partnerships_reminders')->insert([
            'partnershipid' => $id,
            'type' => '3months',
            'sent' => now(),
        ]);

        $this->artisan('partnerships:reminders', ['--days' => 30, '--type' => '1month'])->assertExitCode(0);

        Mail::assertSent(SponsorshipExpiringMail::class);
        $this->assertSame(1, DB::table('partnerships_reminders')
            ->where('partnershipid', $id)->where('type', '1month')->count());
    }

    public function test_mail_lists_every_council_contact(): void
    {
        $id = $this->partnership(60);
        DB::table('partnerships_contacts')->insert([
            ['partnershipid' => $id, 'name' => 'Wendy Waste', 'email' => 'waste@example.gov.uk', 'role' => 'Waste'],
            ['partnershipid' => $id, 'name' => 'Fred Finance', 'email' => 'finance@example.gov.uk', 'role' => 'Finance'],
        ]);

        $this->artisan('partnerships:reminders')->assertExitCode(0);

        Mail::assertSent(SponsorshipExpiringMail::class, function ($mail) {
            $text = $mail->render();

            return $mail->contacts === [
                ['name' => 'Wendy Waste', 'email' => 'waste@example.gov.uk', 'role' => 'waste team'],
                ['name' => 'Fred Finance', 'email' => 'finance@example.gov.uk', 'role' => 'finance'],
            ] && str_contains($text, 'Fred Finance')
                && str_contains($text, 'mailto:finance@example.gov.uk');
        });
    }

    public function test_reminders_go_to_the_teams_own_address(): void
    {
        DB::table('teams')->where('name', 'Partnerships')->update(['email' => 'newpartnerships@ilovefreegle.org']);
        $this->partnership(60);

        $this->artisan('partnerships:reminders')->assertExitCode(0);

        Mail::assertSent(SponsorshipExpiringMail::class, function ($mail) {
            return $mail->recipientEmail === 'newpartnerships@ilovefreegle.org';
        });
    }
}
