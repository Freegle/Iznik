<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 1, gift aid: Layer 1 parity for the converted sites in
 * app/Services/GiftAidClaimService.php.
 */
class Wave1GiftAidTest extends TestCase
{
    // SELECT id, userid, homeaddress FROM giftaid WHERE postcode IS NULL AND deleted IS NULL
    private const SITE_RECORDS_NEEDING_POSTCODE = '53cb63e88326';

    // UPDATE giftaid SET postcode = ? WHERE id = ?
    private const SITE_SET_POSTCODE = 'bc2557d9769e';

    // SELECT name FROM locations WHERE type = 'Postcode' AND name = ? LIMIT 1
    private const SITE_POSTCODE_EXACT = '7afcdec295c4';

    // SELECT name FROM locations WHERE type = 'Postcode' AND name LIKE ? ORDER BY name LIMIT 1
    private const SITE_POSTCODE_PREFIX = '2f142c0c73a9';

    // SELECT id, homeaddress FROM giftaid WHERE housenameornumber IS NULL AND deleted IS NULL
    private const SITE_RECORDS_NEEDING_HOUSE = 'ac33e0b57bf6';

    // UPDATE giftaid SET housenameornumber = ? WHERE id = ?
    private const SITE_SET_HOUSE = 'a8a1d63024b8';

    // UPDATE users_donations SET userid = ? WHERE id = ?
    private const SITE_LINK_DONATION_USER = '48cc35d6f779';

    // SELECT * FROM giftaid WHERE reviewed IS NOT NULL
    private const SITE_REVIEWED_GIFTAIDS = '854e1ce3d702';

    // UPDATE giftaid SET reviewed = NULL WHERE userid = ?
    private const SITE_CLEAR_REVIEWED = '6b8dcb20450d';

    // UPDATE users_donations SET giftaidclaimed = NOW() WHERE id = ?
    private const SITE_MARK_CLAIMED = '029b3600e99d';

    // SELECT l.name AS postcode FROM users_addresses ua
    //   JOIN paf_addresses pa ON ua.pafid = pa.id
    //   JOIN locations l ON pa.postcodeid = l.id
    //   WHERE ua.userid = ? AND l.type = ?
    private const SITE_SAVED_POSTCODES = '9759f105c180';

    public function test_records_needing_postcode(): void
    {
        GoldenSql::assert(self::SITE_RECORDS_NEEDING_POSTCODE, fn () => DB::table('giftaid')
            ->select('id', 'userid', 'homeaddress')
            ->whereNull('postcode')
            ->whereNull('deleted'));
    }

    public function test_set_postcode(): void
    {
        GoldenSql::assertUpdate(self::SITE_SET_POSTCODE, fn () => [
            DB::table('giftaid')->where('id', 1),
            ['postcode' => 'SW1A 1AA'],
        ]);
    }

    public function test_postcode_exact_match(): void
    {
        GoldenSql::assert(self::SITE_POSTCODE_EXACT, fn () => DB::table('locations')
            ->select('name')
            ->where('type', 'Postcode')
            ->where('name', 'SW1A 1AA')
            ->limit(1));
    }

    public function test_postcode_prefix_match(): void
    {
        GoldenSql::assert(self::SITE_POSTCODE_PREFIX, fn () => DB::table('locations')
            ->select('name')
            ->where('type', 'Postcode')
            ->where('name', 'LIKE', 'SW1A%')
            ->orderBy('name')
            ->limit(1));
    }

    public function test_records_needing_house_number(): void
    {
        GoldenSql::assert(self::SITE_RECORDS_NEEDING_HOUSE, fn () => DB::table('giftaid')
            ->select('id', 'homeaddress')
            ->whereNull('housenameornumber')
            ->whereNull('deleted'));
    }

    public function test_set_house_number(): void
    {
        GoldenSql::assertUpdate(self::SITE_SET_HOUSE, fn () => [
            DB::table('giftaid')->where('id', 1),
            ['housenameornumber' => '12'],
        ]);
    }

    public function test_link_donation_to_user(): void
    {
        GoldenSql::assertUpdate(self::SITE_LINK_DONATION_USER, fn () => [
            DB::table('users_donations')->where('id', 1),
            ['userid' => 2],
        ]);
    }

    public function test_reviewed_giftaids(): void
    {
        GoldenSql::assert(self::SITE_REVIEWED_GIFTAIDS, fn () => DB::table('giftaid')
            ->whereNotNull('reviewed'));
    }

    public function test_clear_reviewed(): void
    {
        GoldenSql::assertUpdate(self::SITE_CLEAR_REVIEWED, fn () => [
            DB::table('giftaid')->where('userid', 1),
            ['reviewed' => null],
        ]);
    }

    /**
     * This site USED to keep its DB::raw('NOW()'), on the argument that binding
     * a PHP timestamp "moves the clock from the database to the application - a
     * real behaviour change on a claimed-at timestamp". That argument was
     * wrong, and the correction is worth leaving visible: MySQL and PHP share
     * one UTC clock in this deployment (app.timezone=UTC, php
     * date.timezone=UTC, MySQL SYSTEM resolving to the same wall clock,
     * measured one second apart), so there is no second clock to move to.
     *
     * The golden now diverges - NOW() versus a bound timestamp - and carries an
     * approved diff recording exactly that.
     */
    public function test_mark_donation_claimed(): void
    {
        GoldenSql::assertUpdate(self::SITE_MARK_CLAIMED, fn () => [
            DB::table('users_donations')->where('id', 1),
            ['giftaidclaimed' => now()],
        ]);
    }

    /**
     * A three-table join, and the join ORDER is load-bearing: paf_addresses
     * must be joined before locations, because the second join's ON clause
     * refers to pa.postcodeid. Laravel emits the joins in call order, so the
     * golden catches a reordering.
     */
    public function test_saved_address_postcodes(): void
    {
        GoldenSql::assert(self::SITE_SAVED_POSTCODES, fn () => DB::table('users_addresses as ua')
            ->select('l.name as postcode')
            ->join('paf_addresses as pa', 'ua.pafid', '=', 'pa.id')
            ->join('locations as l', 'pa.postcodeid', '=', 'l.id')
            ->where('ua.userid', 1)
            ->where('l.type', 'Postcode'));
    }
}
