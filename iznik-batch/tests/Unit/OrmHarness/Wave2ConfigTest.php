<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 2: the config key/value upserts.
 *
 * Four call sites of one statement -
 * INSERT INTO config (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?
 * - across the app-release classifier, the CPI importer and the donation thank
 * prep. Laravel's ->upsert() expresses this directly, so it is the third IGNORE
 * or ON DUPLICATE shape in this migration that turns out to be convertible
 * after all: insertOrIgnore, upsert, and only UPDATE IGNORE genuinely has no
 * builder form.
 *
 * The bind ORDER is the thing to get right and is checked by the golden:
 * compileUpsert emits the VALUES binds first, then the update binds, which is
 * why the raw statement passed $sha twice.
 */
class Wave2ConfigTest extends TestCase
{
    private const SITE_LAST_PRODUCTION_SHA = '134a701c2455';
    private const SITE_CLASSIFIER_OTHER = '921547aec4be';
    private const SITE_CPI = '78fb60f58d16';
    private const SITE_DONATION_THANK = '09285fda88fd';
    private const SITE_GIT_SUMMARY = '7b872a43116c';

    public function test_config_upserts(): void
    {
        $build = fn () => [
            DB::table('config'),
            [['key' => 'somekey', 'value' => 'somevalue']],
            ['key'],
            ['value' => 'somevalue'],
        ];

        GoldenSql::assertUpsert(self::SITE_LAST_PRODUCTION_SHA, $build);
        GoldenSql::assertUpsert(self::SITE_CLASSIFIER_OTHER, $build);
        GoldenSql::assertUpsert(self::SITE_CPI, $build);
        GoldenSql::assertUpsert(self::SITE_DONATION_THANK, $build);
        GoldenSql::assertUpsert(self::SITE_GIT_SUMMARY, $build);
    }
}
