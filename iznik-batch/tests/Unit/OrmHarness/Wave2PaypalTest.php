<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 2: the PayPal donation upsert.
 *
 * The mirror image of the embeddings upsert, and the pair is worth reading
 * together. That one passes update columns as a LIST, because its raw
 * statement wrote "col = VALUES(col)" - update from the incoming row. This one
 * passes a MAP, because its raw statement wrote "userid = ?, timestamp = ?" -
 * update to bound values. Same builder method, two renderings, and picking the
 * wrong one changes what a duplicate donation row gets written.
 */
class Wave2PaypalTest extends TestCase
{
    private const SITE_DONATION_UPSERT = '5eec44b70c3d';

    public function test_donation_upsert(): void
    {
        GoldenSql::assertUpsert(self::SITE_DONATION_UPSERT, fn () => [
            DB::table('users_donations'),
            [[
                'userid' => 1,
                'Payer' => 'a@example.com',
                'PayerDisplayName' => 'A Payer',
                'timestamp' => '2026-01-01 00:00:00',
                'TransactionID' => 'TX1',
                'GrossAmount' => '10.00',
            ]],
            ['TransactionID'],
            ['userid' => 1, 'timestamp' => '2026-01-01 00:00:00'],
        ]);
    }
}
