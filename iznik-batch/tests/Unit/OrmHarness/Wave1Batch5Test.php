<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 1, batch 5: the group-deletion foreign-key sweep.
 *
 * INFORMATION_SCHEMA is queried through DB::table() like any other table -
 * the builder does not care that it is a catalog view, and quoting
 * `INFORMATION_SCHEMA`.`KEY_COLUMN_USAGE` is valid MySQL. Worth noting because
 * catalog queries look like something that must stay raw and are not: what
 * makes a statement unconvertible here is a FUNCTION the builder cannot
 * express, not an unusual table.
 */
class Wave1Batch5Test extends TestCase
{
    // SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    //   WHERE REFERENCED_TABLE_NAME = 'groups' AND TABLE_NAME != 'groups' AND TABLE_SCHEMA = ?
    private const SITE_GROUP_REFERENCES = '4d2a758e2882';

    public function test_group_foreign_key_references(): void
    {
        GoldenSql::assert(self::SITE_GROUP_REFERENCES, fn () => DB::table('INFORMATION_SCHEMA.KEY_COLUMN_USAGE')
            ->select('TABLE_NAME', 'COLUMN_NAME')
            ->where('REFERENCED_TABLE_NAME', 'groups')
            ->where('TABLE_NAME', '!=', 'groups')
            ->where('TABLE_SCHEMA', 'iznik'));
    }
}
