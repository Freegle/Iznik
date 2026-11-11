<?php

namespace Tests\Unit\OrmHarness;

use App\Models\MessageOutcome;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 2: ExpandService's terminal-outcome check, converted to ->exists().
 */
class Wave2ExistsTest extends TestCase
{
    private const SITE_TERMINAL_OUTCOME = '07087e8cc794';

    public function test_has_terminal_outcome(): void
    {
        GoldenSql::assertExists(self::SITE_TERMINAL_OUTCOME, fn () => DB::table('messages_outcomes')
            ->where('msgid', 1)
            ->whereIn('outcome', [
                MessageOutcome::OUTCOME_TAKEN,
                MessageOutcome::OUTCOME_RECEIVED,
                MessageOutcome::OUTCOME_WITHDRAWN,
            ]));
    }
}
