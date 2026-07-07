<?php

namespace App\Console\Commands\Authority;

use App\Services\AuthorityStatsService;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Produces the quarterly statistics spreadsheet that local authorities receive:
 * membership, weight reused, CO2 and financial benefit, gifts made, a per-group
 * breakdown, shortlink clicks, member stories and a per-postcode breakdown.
 *
 *   php artisan authority:stats --i=72467,117233 --q="3 months ago"
 */
class AuthorityStatsCommand extends Command
{
    // Explicit number formats for the values we write, so display never depends
    // on whatever formatting the template cell happened to carry.
    private const FMT_INT = '#,##0';
    private const FMT_TONNES = '#,##0.0';
    private const FMT_GBP = '"£"#,##0';
    private const FMT_WEIGHT = '#,##0.0';
    private const FMT_CO2_2DP = '#,##0.00';
    private const FMT_GBP_2DP = '"£"#,##0.00';
    private const FMT_TEXT = '@';

    // Section-header green (matches the historical council report).
    private const GREEN = '5B8930';

    protected $signature = 'authority:stats
                            {--i= : Comma-separated authority IDs}
                            {--q=3 months ago : Quarter start date (any parseable date; defaults to the last full quarter)}
                            {--output= : Directory to write spreadsheets to (default: storage/app/authority-stats)}';

    protected $description = 'Generate the quarterly per-authority statistics spreadsheet(s)';

    public function handle(AuthorityStatsService $service): int
    {
        $idsOption = (string) $this->option('i');
        if ($idsOption === '') {
            $this->error('Provide authority IDs with --i=<id,id,...>');
            return Command::FAILURE;
        }

        $quarter = (string) ($this->option('q') ?: '3 months ago');
        $ids = array_filter(array_map('trim', explode(',', $idsOption)), static fn ($v) => $v !== '');

        $outputDir = (string) ($this->option('output') ?: storage_path('app/authority-stats'));
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            $this->error("Could not create output directory: {$outputDir}");
            return Command::FAILURE;
        }

        $template = resource_path('templates/authority_stats.xlsx');
        if (!is_file($template)) {
            $this->error("Template not found: {$template}");
            return Command::FAILURE;
        }

        $failed = false;
        foreach ($ids as $id) {
            $this->info("Generating statistics for authority {$id} ...");
            $report = $service->computeReport((int) $id, $quarter);

            if ($report === null) {
                $this->warn("  Authority {$id} not found - skipping.");
                $failed = true;
                continue;
            }

            $path = $this->render($report, $template, $outputDir);
            $this->info("  Wrote {$path}");
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Fill the template with one authority's report and save it. Returns the
     * path of the file written.
     *
     * @param  array<string, mixed>  $report
     */
    public function render(array $report, string $template, string $outputDir): string
    {
        $months = $report['months'];
        $benefitPerTonne = $report['benefitPerTonne'];
        $co2PerTonne = $report['co2PerTonne'];

        // Convert a weight in kilograms to tonnes of CO2 / GBP of benefit, each
        // to one decimal place, as the summary rows present them.
        $co2 = static fn (float $kg): float => round($kg * $co2PerTonne / 100) / 10;
        $benefit = static fn (float $kg): float => round($kg * $benefitPerTonne / 100) / 10;

        $reader = new XlsxReader();
        $reader->setReadFilter(new ReportColumnFilter());
        $spreadsheet = $reader->load($template);
        $sheet = $spreadsheet->getSheetByName('Standard report');

        $sheet->setCellValue('A1', 'Freegle in ' . $report['name']);

        // Month column headers.
        $sheet->setCellValue('B8', $months[0]['formatted']);
        $sheet->setCellValue('C8', $months[1]['formatted']);
        $sheet->setCellValue('D8', $months[2]['formatted']);

        $t = $report['totals'];
        $totWeight = $t[0]['weight'] + $t[1]['weight'] + $t[2]['weight'];

        // Membership (the "total" column shows the latest month's figure).
        $sheet->setCellValue('B9', $t[0]['members']);
        $sheet->setCellValue('C9', $t[1]['members']);
        $sheet->setCellValue('D9', $t[2]['members']);
        $sheet->setCellValue('E9', $t[2]['members']);

        // Kgs reused.
        $sheet->setCellValue('B10', round($t[0]['weight']));
        $sheet->setCellValue('C10', round($t[1]['weight']));
        $sheet->setCellValue('D10', round($t[2]['weight']));
        $sheet->setCellValue('E10', round($totWeight));

        // CO2 saved (tonnes).
        $sheet->setCellValue('B11', $co2($t[0]['weight']));
        $sheet->setCellValue('C11', $co2($t[1]['weight']));
        $sheet->setCellValue('D11', $co2($t[2]['weight']));
        $sheet->setCellValue('E11', $co2($totWeight));

        // Benefit (GBP).
        $sheet->setCellValue('B12', $benefit($t[0]['weight']));
        $sheet->setCellValue('C12', $benefit($t[1]['weight']));
        $sheet->setCellValue('D12', $benefit($t[2]['weight']));
        $sheet->setCellValue('E12', $benefit($totWeight));

        // Number of gifts made.
        $sheet->setCellValue('B13', round($t[0]['outcomes']));
        $sheet->setCellValue('C13', round($t[1]['outcomes']));
        $sheet->setCellValue('D13', round($t[2]['outcomes']));
        $sheet->setCellValue('E13', round($t[0]['outcomes'] + $t[1]['outcomes'] + $t[2]['outcomes']));

        // Some template cells carry a stray date format, which would render these
        // numbers as dates. Force explicit numeric formats on every value we set.
        $this->numberFormat($sheet, 'B9:E10', self::FMT_INT);      // membership, kgs
        $this->numberFormat($sheet, 'B11:E11', self::FMT_TONNES);  // CO2 tonnes
        $this->numberFormat($sheet, 'B12:E12', self::FMT_GBP);     // benefit
        $this->numberFormat($sheet, 'B13:E13', self::FMT_INT);     // gifts

        // Per-group table month headers (five metric blocks across the row).
        foreach ([['B', 'C', 'D'], ['F', 'G', 'H'], ['J', 'K', 'L'], ['N', 'O', 'P'], ['R', 'S', 'T']] as $block) {
            $sheet->setCellValue("{$block[0]}18", $months[0]['formatted']);
            $sheet->setCellValue("{$block[1]}18", $months[1]['formatted']);
            $sheet->setCellValue("{$block[2]}18", $months[2]['formatted']);
        }

        // Per-group rows. A blank row is inserted after each so the styled rows
        // below the table are pushed down and preserved.
        $grouprow = 19;
        foreach ($report['groups'] as $group) {
            $w = $group['weight'];
            $wSum = $w[0] + $w[1] + $w[2];

            $sheet->setCellValue("A$grouprow", $group['namedisplay']);

            $sheet->setCellValue("B$grouprow", $group['members'][0]);
            $sheet->setCellValue("C$grouprow", $group['members'][1]);
            $sheet->setCellValue("D$grouprow", $group['members'][2]);
            $sheet->setCellValue("E$grouprow", $group['members'][2]);

            $sheet->setCellValue("F$grouprow", round($w[0]));
            $sheet->setCellValue("G$grouprow", round($w[1]));
            $sheet->setCellValue("H$grouprow", round($w[2]));
            $sheet->setCellValue("I$grouprow", round($wSum));

            $sheet->setCellValue("J$grouprow", $co2($w[0]));
            $sheet->setCellValue("K$grouprow", $co2($w[1]));
            $sheet->setCellValue("L$grouprow", $co2($w[2]));
            $sheet->setCellValue("M$grouprow", $co2($wSum));

            $sheet->setCellValue("N$grouprow", $benefit($w[0]));
            $sheet->setCellValue("O$grouprow", $benefit($w[1]));
            $sheet->setCellValue("P$grouprow", $benefit($w[2]));
            $sheet->setCellValue("Q$grouprow", $benefit($wSum));

            $sheet->setCellValue("R$grouprow", round($group['outcomes'][0]));
            $sheet->setCellValue("S$grouprow", round($group['outcomes'][1]));
            $sheet->setCellValue("T$grouprow", round($group['outcomes'][2]));
            $sheet->setCellValue("U$grouprow", round($group['outcomes'][0] + $group['outcomes'][1] + $group['outcomes'][2]));

            $grouprow++;
            $sheet->insertNewRowBefore($grouprow, 1);
        }
        $sheet->removeRow($grouprow);

        // Number formats for the per-group table (rows 19..last).
        $groupCount = count($report['groups']);
        if ($groupCount > 0) {
            $end = 18 + $groupCount;
            $this->numberFormat($sheet, "B19:I$end", self::FMT_INT);     // members, weight
            $this->numberFormat($sheet, "J19:M$end", self::FMT_TONNES);  // CO2
            $this->numberFormat($sheet, "N19:Q$end", self::FMT_GBP);     // benefit
            $this->numberFormat($sheet, "R19:U$end", self::FMT_INT);     // outcomes
        }

        // Shortlinks table, a few rows below the group table.
        $shortlinkrow = $grouprow + 4;
        $sheet->setCellValue("B$shortlinkrow", $months[0]['formatted']);
        $sheet->setCellValue("C$shortlinkrow", $months[1]['formatted']);
        $sheet->setCellValue("D$shortlinkrow", $months[2]['formatted']);
        $sheet->setCellValue("E$shortlinkrow", 'Total');
        $shortlinkrow++;
        $shortlinkStart = $shortlinkrow;

        foreach ($report['shortlinks'] as $link) {
            $sheet->setCellValue("A$shortlinkrow", $link['name']);
            $sheet->setCellValue("B$shortlinkrow", $link['clicks'][0]);
            $sheet->setCellValue("C$shortlinkrow", $link['clicks'][1]);
            $sheet->setCellValue("D$shortlinkrow", $link['clicks'][2]);
            $sheet->setCellValue("E$shortlinkrow", $link['clicks'][0] + $link['clicks'][1] + $link['clicks'][2]);

            $shortlinkrow++;
            $sheet->insertNewRowBefore($shortlinkrow, 1);
        }
        $sheet->removeRow($shortlinkrow);

        if (count($report['shortlinks']) > 0) {
            $range = "B$shortlinkStart:E" . ($shortlinkStart + count($report['shortlinks']) - 1);
            $this->numberFormat($sheet, $range, self::FMT_INT);
            // Natalie centres the monthly click figures under Jan/Feb/Mar.
            $sheet->getStyle($range)->getAlignment()->setHorizontal('center');
        }

        $this->fillFooterSections($sheet, $shortlinkrow, $report);

        // Standardise the table fonts to Segoe UI 10pt (the title row stays large).
        // This also normalises the blank rows inserted for groups/shortlinks/stories,
        // which would otherwise fall back to the default font.
        $sheet->getStyle('A6:U' . $sheet->getHighestRow())->getFont()->setName('Segoe UI')->setSize(10);

        // Restore the green section-header bars (stripped from the template).
        $this->applyGreenHeaders($sheet);

        $this->fillPostcodeSheet($spreadsheet, $report, $co2PerTonne, $benefitPerTonne);

        $spreadsheet->setActiveSheetIndexByName('Standard report');

        $filename = "Freegle-Statistics-{$report['name']}-{$report['year']}-Q{$report['quarter']}.xlsx";
        $path = rtrim($outputDir, '/') . '/' . $filename;
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * Fill the "all data correct" date, the per-community shortlink URLs and the
     * member stories that sit below the shortlink clicks table.
     *
     * @param  array<string, mixed>  $report
     */
    private function fillFooterSections($sheet, int $shortlinkrow, array $report): void
    {
        $links = $report['shortlinks'];
        $max = $sheet->getHighestRow();

        // The intro line and the single URL placeholder are in the template,
        // somewhere below the shortlink click table.
        $shortlinksTextRow = $shortlinksUrlRow = null;
        for ($r = $shortlinkrow; $r <= $max; $r++) {
            $val = (string) $sheet->getCell("A$r")->getValue();
            if ($val === '') {
                continue;
            }
            if (str_contains($val, 'The full data associated with any shortlinks')) {
                $shortlinksTextRow = $r;
            }
            if (str_contains($val, 'ilovefreegle.org/shortlinks')) {
                $shortlinksUrlRow = $r;
            }
        }

        if ($shortlinksTextRow) {
            $sheet->setCellValue("A$shortlinksTextRow", 'The full data associated with any shortlinks can be viewed by clicking the links below:');
        }

        // Expand the placeholder into one clickable link per community.
        $lastRow = $shortlinksUrlRow ?? $shortlinkrow;
        if ($shortlinksUrlRow && count($links) > 0) {
            $urlRow = $shortlinksUrlRow;
            foreach ($links as $link) {
                $url = 'https://www.ilovefreegle.org/shortlinks/' . $link['id'];
                $sheet->setCellValue("A$urlRow", $link['name']);
                $sheet->setCellValue("B$urlRow", $url);
                $sheet->getCell("B$urlRow")->getHyperlink()->setUrl($url);
                $urlRow++;
                $sheet->insertNewRowBefore($urlRow, 1);
            }
            $sheet->removeRow($urlRow);
            $lastRow = $urlRow - 1;
        }

        // The "all data correct" line and the stories section are not in the
        // template, so write them below the shortlink URLs.
        $allDataRow = $lastRow + 2;
        $sheet->setCellValue("A$allDataRow", 'All data correct at ' . date('d/m/Y'));
        $sheet->getStyle("A$allDataRow")->getFont()->setName('Segoe UI')->setSize(10)->setBold(true)->getColor()->setRGB('316F0F');

        // Only show the User stories section when there are stories to include.
        if (count($report['stories']) > 0) {
            $storiesHeaderRow = $allDataRow + 2;
            $sheet->setCellValue("A$storiesHeaderRow", 'User stories');

            $storyrow = $storiesHeaderRow + 1;
            foreach ($report['stories'] as $story) {
                $sheet->setCellValue("A$storyrow", $story['headline']);
                $sheet->getStyle("A$storyrow")->getFont()->setName('Segoe UI')->setSize(10)->setBold(true);
                $sheet->setCellValue("B$storyrow", $story['story']);
                $storyrow++;
            }
        }
    }

    /** Paint a section-header cell with the green background and white bold text. */
    private function greenHeader($sheet, int $row): void
    {
        $style = $sheet->getStyle("A$row");
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GREEN);
        $style->getFont()->setName('Segoe UI')->setSize(10)->setBold(true)->getColor()->setRGB('FFFFFF');
    }

    /** Restore the green background on the fixed and dynamic section headers. */
    private function applyGreenHeaders($sheet): void
    {
        $headers = ['Local Authority data', 'Freegle community data', 'Shortlink data summary', 'User stories'];
        for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
            $val = (string) $sheet->getCell("A$r")->getValue();
            if ($val === '') {
                continue;
            }
            foreach ($headers as $h) {
                if (str_starts_with($val, $h)) {
                    $this->greenHeader($sheet, $r);
                    break;
                }
            }
        }
    }

    /**
     * Fill the "Postcode breakdown" sheet.
     *
     * @param  array<string, mixed>  $report
     */
    private function fillPostcodeSheet($spreadsheet, array $report, float $co2PerTonne, float $benefitPerTonne): void
    {
        $sheet = $spreadsheet->getSheetByName('Postcode breakdown');
        $row = 2;

        foreach ($report['postcodes'] as $pc => $stat) {
            $sheet->setCellValue("A$row", $pc);
            $sheet->setCellValue("B$row", $stat['Offer']);
            $sheet->setCellValue("C$row", $stat['Wanted']);
            $sheet->setCellValue("D$row", $stat[AuthorityStatsService::SEARCHES]);
            $sheet->setCellValue("E$row", $stat[AuthorityStatsService::OUTCOMES]);
            $sheet->setCellValue("F$row", round($stat[AuthorityStatsService::WEIGHT], 1));
            $sheet->setCellValue("G$row", round($stat[AuthorityStatsService::WEIGHT] * $co2PerTonne, 2));
            $sheet->setCellValue("H$row", round($stat[AuthorityStatsService::WEIGHT] * $benefitPerTonne / 10) / 100);
            $row++;
        }

        $lastRow = max($row - 1, 1);
        $sheet->getStyle("A1:H$lastRow")->getFont()->setName('Segoe UI')->setSize(10);

        // The template leaves a stray partial border on a few cells (which can sit
        // below the data for a small authority), so clear borders across the whole
        // used range - no box should hang over part-way down the table.
        $borderEnd = max($lastRow, $sheet->getHighestRow());
        $sheet->getStyle("A1:H$borderEnd")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_NONE);

        // Auto-fit each column to its content (the manual "select all, double-click
        // to auto-size" step) so the count columns aren't needlessly wide.
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Green header bar with white bold text, matching the Standard report.
        $sheet->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GREEN);
        $sheet->getStyle('A1:H1')->getFont()->setName('Segoe UI')->setSize(10)->setBold(true)->getColor()->setRGB('FFFFFF');

        if ($row > 2) {
            $dataRows = $row - 1;
            $this->numberFormat($sheet, "A2:A$dataRows", self::FMT_TEXT);      // postcode
            $this->numberFormat($sheet, "B2:E$dataRows", self::FMT_INT);       // offers, wanteds, searches, gifts
            $this->numberFormat($sheet, "F2:F$dataRows", self::FMT_WEIGHT);    // weight kg
            $this->numberFormat($sheet, "G2:G$dataRows", self::FMT_CO2_2DP);   // CO2
            $this->numberFormat($sheet, "H2:H$dataRows", self::FMT_GBP_2DP);   // benefit
        }
    }

    /** Apply a number format code to a cell range. */
    private function numberFormat($sheet, string $range, string $code): void
    {
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode($code);
    }
}
