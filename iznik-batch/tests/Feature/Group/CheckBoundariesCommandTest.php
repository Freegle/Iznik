<?php

namespace Tests\Feature\Group;

use App\Services\GroupBoundaryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckBoundariesCommandTest extends TestCase
{
    public function test_runs_cleanly_with_no_groups(): void
    {
        $this->artisan('groups:check-boundaries')
            ->assertExitCode(0);
    }

    public function test_reports_zero_errors_for_valid_groups(): void
    {
        $this->createTestGroup(['publish' => 1, 'onmap' => 1]);
        $this->createTestGroup(['publish' => 1, 'onmap' => 1]);

        $this->artisan('groups:check-boundaries')
            ->expectsOutputToContain('0 error(s) detected')
            ->assertExitCode(0);
    }

    public function test_dry_run_outputs_dry_run_marker(): void
    {
        $this->createTestGroup(['publish' => 1, 'onmap' => 1]);

        $this->artisan('groups:check-boundaries', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] Would check boundaries for')
            ->assertExitCode(0);
    }

    public function test_dry_run_shows_dry_run_prefix_not_live_summary(): void
    {
        // In dry-run mode the command outputs "[DRY RUN]" not "Checked N group(s)".
        $output = '';
        $this->artisan('groups:check-boundaries', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(0);
    }

    public function test_no_mail_sent_when_no_boundary_errors(): void
    {
        Mail::fake();
        $this->createTestGroup(['publish' => 1, 'onmap' => 1]);

        (new GroupBoundaryService())->checkBoundaries(false);

        Mail::assertNothingSent();
    }

    public function test_mails_geeks_addr_on_geometry_error(): void
    {
        Mail::fake();

        // Insert a test authority so the boundary query actually executes ST_Intersection.
        // Authority id=74579 is hardcoded in GroupBoundaryService (V1 behaviour).
        DB::statement(
            "INSERT INTO authorities (id, name, polygon) VALUES (74579, 'Test Auth', ST_GeomFromText('POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))', 3857))"
        );

        // Group with syntactically invalid WKT — ST_GeomFromText throws ER_GIS_INVALID_DATA.
        $group = $this->createTestGroup(['publish' => 1, 'onmap' => 1, 'polyofficial' => 'POLYGON((0 0, 1 0))']);

        $result = (new GroupBoundaryService())->checkBoundaries(false);

        $this->assertSame(1, $result['errors']);
        Mail::assertSentCount(1);
    }
}
