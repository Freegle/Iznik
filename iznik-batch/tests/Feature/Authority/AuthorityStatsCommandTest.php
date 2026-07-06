<?php

namespace Tests\Feature\Authority;

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
}
