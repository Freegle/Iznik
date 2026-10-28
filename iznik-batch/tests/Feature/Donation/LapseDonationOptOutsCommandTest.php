<?php

namespace Tests\Feature\Donation;

use App\Models\User;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * `donations:lapse-optouts` keeps the stored "don't ask me to donate" switch in step with
 * its one-year date: undated opt-outs get a date, lapsed ones are switched off.
 */
class LapseDonationOptOutsCommandTest extends TestCase
{
    private function userWithSettings(array $settings): User
    {
        $user = $this->createTestUser();
        $user->settings = $settings;
        $user->save();

        return $user;
    }

    private function iso(Carbon $when): string
    {
        return $when->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public function test_undated_opt_out_is_dated_a_year_ahead(): void
    {
        $user = $this->userWithSettings(['nodonationasks' => TRUE, 'somecustom' => 'keep']);

        $this->artisan('donations:lapse-optouts')->assertExitCode(0);

        $settings = $user->fresh()->settings;
        $this->assertTrue($settings['nodonationasks']);
        $this->assertEquals('keep', $settings['somecustom'], 'other settings are untouched');

        $until = Carbon::parse($settings['nodonationasksuntil']);
        $this->assertEqualsWithDelta(now()->addDays(User::DONATION_ASK_OPTOUT_DAYS)->timestamp, $until->timestamp, 120);
        $this->assertFalse($user->fresh()->wantsDonationAsks(), 'still opted out until the new date');
    }

    public function test_lapsed_opt_out_is_switched_off(): void
    {
        $user = $this->userWithSettings([
            'nodonationasks' => TRUE,
            'nodonationasksuntil' => $this->iso(now()->subDay()),
        ]);

        $this->artisan('donations:lapse-optouts')->assertExitCode(0);

        $settings = $user->fresh()->settings;
        $this->assertFalse($settings['nodonationasks']);
        $this->assertNull($settings['nodonationasksuntil'] ?? NULL);
        $this->assertTrue($user->fresh()->wantsDonationAsks());
    }

    public function test_running_opt_out_and_members_who_never_opted_out_are_left_alone(): void
    {
        $until = $this->iso(now()->addMonths(3));
        $running = $this->userWithSettings(['nodonationasks' => TRUE, 'nodonationasksuntil' => $until]);
        $never = $this->userWithSettings(['simplemail' => 'Full']);
        $off = $this->userWithSettings(['nodonationasks' => FALSE]);

        $this->artisan('donations:lapse-optouts')->assertExitCode(0);

        $this->assertEquals($until, $running->fresh()->settings['nodonationasksuntil']);
        $this->assertTrue($running->fresh()->settings['nodonationasks']);
        $this->assertArrayNotHasKey('nodonationasksuntil', $never->fresh()->settings);
        $this->assertArrayNotHasKey('nodonationasksuntil', $off->fresh()->settings);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $undated = $this->userWithSettings(['nodonationasks' => TRUE]);
        $lapsed = $this->userWithSettings([
            'nodonationasks' => TRUE,
            'nodonationasksuntil' => $this->iso(now()->subDay()),
        ]);

        $this->artisan('donations:lapse-optouts', ['--dry-run' => TRUE])->assertExitCode(0);

        $this->assertArrayNotHasKey('nodonationasksuntil', $undated->fresh()->settings);
        $this->assertTrue($lapsed->fresh()->settings['nodonationasks']);
    }
}
