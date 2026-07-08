<?php

namespace Tests\Feature\Authority;

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Tests\Support\SeedsAuthorityStats;
use Tests\TestCase;

class AuthorityStatsCommandTest extends TestCase
{
    use SeedsAuthorityStats;

    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputDir = sys_get_temp_dir() . '/authority-stats-test-' . getmypid();
        $this->cleanOutput();
    }

    protected function tearDown(): void
    {
        $this->cleanOutput();
        parent::tearDown();
    }

    private function cleanOutput(): void
    {
        if (is_dir($this->outputDir)) {
            foreach (glob($this->outputDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->outputDir);
        }
    }

    public function test_requires_authority_ids(): void
    {
        $this->artisan('authority:stats')
            ->expectsOutputToContain('Provide authority IDs')
            ->assertExitCode(1);
    }

    public function test_generates_spreadsheet_with_expected_values(): void
    {
        $this->seedAuthorityScenario();

        $this->artisan('authority:stats', [
            '--i' => (string) $this->authorityId,
            '--q' => $this->quarterStart,
            '--output' => $this->outputDir,
        ])->assertExitCode(0);

        $files = glob($this->outputDir . '/*.xlsx') ?: [];
        $this->assertCount(1, $files, 'one spreadsheet is written');

        $sheet = (new XlsxReader())->load($files[0]);

        $standard = $sheet->getSheetByName('Standard report');
        $this->assertSame('Freegle in Test Authority (B)', $standard->getCell('A1')->getValue());

        // Membership row (latest month repeated as the "total").
        $this->assertSame(120, (int) $standard->getCell('B9')->getValue());
        $this->assertSame(132, (int) $standard->getCell('C9')->getValue());
        $this->assertSame(145, (int) $standard->getCell('D9')->getValue());

        // Kgs reused, and gifts made, for the first month.
        $this->assertSame(150, (int) $standard->getCell('B10')->getValue());
        $this->assertSame(14, (int) $standard->getCell('B13')->getValue());

        // Both non-trivial groups appear in the per-group table (rows 19-20).
        $names = [
            $standard->getCell('A19')->getValue(),
            $standard->getCell('A20')->getValue(),
        ];
        sort($names);
        $this->assertSame(['Full Group', 'Half Group *'], $names);

        // Postcode breakdown sheet.
        $postcode = $sheet->getSheetByName('Postcode breakdown');
        $this->assertSame('AB1 2', $postcode->getCell('A2')->getValue());
        $this->assertSame(2, (int) $postcode->getCell('B2')->getValue());
        $this->assertSame(1, (int) $postcode->getCell('C2')->getValue());
        $this->assertSame(2, (int) $postcode->getCell('D2')->getValue());
        $this->assertSame(1, (int) $postcode->getCell('E2')->getValue());
        $this->assertEqualsWithDelta(25.0, (float) $postcode->getCell('F2')->getValue(), 0.001);

        $sheet->disconnectWorksheets();
    }

    public function test_shows_user_stories_section_when_present(): void
    {
        $this->seedAuthorityScenario();
        $this->runCommand();

        $this->assertTrue($this->standardReportContains('User stories'), 'header present when there are stories');
    }

    public function test_omits_user_stories_section_when_none(): void
    {
        $this->seedAuthorityScenario();
        DB::table('users_stories')->delete();
        $this->runCommand();

        $this->assertFalse($this->standardReportContains('User stories'), 'header omitted when there are no stories');
    }

    public function test_postcode_sheet_has_no_stray_borders(): void
    {
        $this->seedAuthorityScenario();
        $this->runCommand();

        $sheet = (new XlsxReader())->load($this->generatedFile());
        $pc = $sheet->getSheetByName('Postcode breakdown');
        // The template leaves a stray partial box around C10:C14; confirm it's gone.
        foreach (['C10', 'C14', 'B14'] as $addr) {
            $b = $pc->getStyle($addr)->getBorders();
            $this->assertSame('none', $b->getBottom()->getBorderStyle(), "$addr bottom border cleared");
            $this->assertSame('none', $b->getLeft()->getBorderStyle(), "$addr left border cleared");
        }
        $sheet->disconnectWorksheets();
    }

    private function runCommand(): void
    {
        $this->artisan('authority:stats', [
            '--i' => (string) $this->authorityId,
            '--q' => $this->quarterStart,
            '--output' => $this->outputDir,
        ])->assertExitCode(0);
    }

    private function generatedFile(): string
    {
        $files = glob($this->outputDir . '/*.xlsx') ?: [];
        $this->assertCount(1, $files);

        return $files[0];
    }

    private function standardReportContains(string $text): bool
    {
        $sheet = (new XlsxReader())->load($this->generatedFile());
        $s = $sheet->getSheetByName('Standard report');
        $found = false;
        for ($r = 1; $r <= $s->getHighestRow(); $r++) {
            if (str_contains((string) $s->getCell("A$r")->getValue(), $text)) {
                $found = true;
                break;
            }
        }
        $sheet->disconnectWorksheets();

        return $found;
    }
}
