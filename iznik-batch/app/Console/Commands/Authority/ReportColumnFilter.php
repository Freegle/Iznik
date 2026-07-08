<?php

namespace App\Console\Commands\Authority;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Limits the template read to the columns the report actually uses (A-U). The
 * template carries a large block of empty but styled cells stretching ~1000
 * columns to the right; loading them inflates the sheet dimension so much that
 * every row insertion rescans a huge grid. Dropping them keeps rendering quick
 * without changing anything visible.
 */
class ReportColumnFilter implements IReadFilter
{
    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return Coordinate::columnIndexFromString($columnAddress) <= 21; // column U
    }
}
