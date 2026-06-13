<?php

namespace Tests\Feature\Volunteering;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MaintainVolunteeringCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['volunteering_dates', 'volunteering_images', 'volunteering_groups', 'volunteering', 'memberships', 'users_emails', 'users', 'groups'] as $table) {
            DB::table($table)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function makeOpportunity(array $attrs = []): int
    {
        return DB::table('volunteering')->insertGetId(array_merge([
            'title' => 'Dateless Opportunity',
            'location' => 'Community Centre',
            'description' => 'Ongoing help needed.',
            'pending' => 0,
            'deleted' => 0,
            'expired' => 0,
            'renewed' => null,
            'askedtorenew' => null,
            'added' => now()->subDays(25),
        ], $attrs));
    }

    public function test_smoke_no_opportunities(): void
    {
        Mail::fake();

        $this->artisan('volunteering:maintain')
            ->expectsOutputToContain('Asked 0')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_command_asks_and_expires_and_reports(): void
    {
        Mail::fake();
        $owner = $this->createTestUser();

        // Dateless, 25 days old → in the renewal-ask window, not yet expirable.
        $askId = $this->makeOpportunity(['userid' => $owner->id, 'added' => now()->subDays(25)]);

        // Dated, only past dates → expirable, never asked (it has dates).
        $expireId = $this->makeOpportunity(['userid' => $owner->id, 'title' => 'Past Event', 'added' => now()->subDays(5)]);
        DB::table('volunteering_dates')->insert([
            'volunteeringid' => $expireId,
            'end' => now()->subDays(2)->format('Y-m-d H:i:s'),
        ]);

        // Capture the command output directly rather than chaining two
        // ->expectsOutputToContain() calls. The command prints ONE summary line
        // containing both counts; Laravel registers a Mockery doWrite matcher per
        // expected substring, and a single output line that matches both only
        // satisfies one matcher — leaving the other reported as "not contained"
        // (PendingCommand::createABufferedOutputMock). assertStringContainsString
        // on the captured output has no such limitation.
        $code = Artisan::call('volunteering:maintain');
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Asked 1', $output);
        $this->assertStringContainsString('expired 1', $output);
        Mail::assertSentCount(1);
        $this->assertNotNull(DB::table('volunteering')->where('id', $askId)->value('askedtorenew'));
        $this->assertSame(1, (int) DB::table('volunteering')->where('id', $expireId)->value('expired'));
    }

    public function test_command_dry_run_changes_nothing(): void
    {
        Mail::fake();
        $owner = $this->createTestUser();
        $askId = $this->makeOpportunity(['userid' => $owner->id, 'added' => now()->subDays(25)]);

        $this->artisan('volunteering:maintain', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertNull(DB::table('volunteering')->where('id', $askId)->value('askedtorenew'));
    }
}
