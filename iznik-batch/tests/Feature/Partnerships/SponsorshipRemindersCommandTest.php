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
        DB::table('partnerships')->update(['agreed' => 0]);
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
            'agreed' => 1,
            'visible' => 1,
            'contactname' => 'Waste Team',
            'contactemail' => 'waste@example.gov.uk',
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

    public function test_ignores_a_deal_that_is_not_agreed(): void
    {
        $this->partnership(30, ['agreed' => 0]);

        $this->artisan('partnerships:reminders')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_ignores_a_deal_that_has_been_hidden(): void
    {
        $this->partnership(30, ['visible' => 0]);

        $this->artisan('partnerships:reminders')->assertExitCode(0);

        Mail::assertNothingSent();
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

    public function test_mail_reports_the_covered_community_count(): void
    {
        $id = $this->partnership(60);

        $groupId = (int) DB::table('groups')->insertGetId([
            'nameshort' => 'remindergrp' . uniqid(),
            'type' => 'Freegle',
            // groups.polyindex has no default, so a catchment has to be supplied.
            'polyindex' => DB::raw("ST_GeomFromText('POLYGON((-0.5 10.5, 0.5 10.5, 0.5 11.5, -0.5 11.5, -0.5 10.5))', 3857)"),
        ]);
        DB::table('partnerships_groups')->insert([
            'partnershipid' => $id,
            'groupid' => $groupId,
        ]);

        $this->artisan('partnerships:reminders')->assertExitCode(0);

        Mail::assertSent(SponsorshipExpiringMail::class, function ($mail) {
            return $mail->groupCount === 1
                && $mail->amount === 4800.0
                && $mail->contactEmail === 'waste@example.gov.uk';
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
