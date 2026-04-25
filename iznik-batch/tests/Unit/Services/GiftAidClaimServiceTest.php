<?php

namespace Tests\Unit\Services;

use App\Services\GiftAidClaimService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GiftAidClaimServiceTest extends TestCase
{
    protected GiftAidClaimService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GiftAidClaimService();
    }

    // -------------------------------------------------------------------------
    // splitName tests
    // -------------------------------------------------------------------------

    public function test_split_name_uses_dedicated_columns_when_both_set(): void
    {
        [$first, $last] = $this->service->splitName('John Smith', 'Jon', 'Smyth');
        $this->assertEquals('Jon', $first);
        $this->assertEquals('Smyth', $last);
    }

    public function test_split_name_falls_back_when_dedicated_columns_null(): void
    {
        [$first, $last] = $this->service->splitName('Jane Doe', null, null);
        $this->assertEquals('Jane', $first);
        $this->assertEquals('Doe', $last);
    }

    public function test_split_name_falls_back_when_firstname_empty(): void
    {
        [$first, $last] = $this->service->splitName('Jane Doe', '', 'Doe');
        $this->assertEquals('Jane', $first);
        $this->assertEquals('Doe', $last);
    }

    public function test_split_name_falls_back_when_lastname_empty(): void
    {
        [$first, $last] = $this->service->splitName('Jane Doe', 'Jane', '');
        $this->assertEquals('Jane', $first);
        $this->assertEquals('Doe', $last);
    }

    public function test_split_name_handles_multiple_word_last_name(): void
    {
        [$first, $last] = $this->service->splitName('Maria Garcia Lopez', null, null);
        $this->assertEquals('Maria', $first);
        $this->assertEquals('Garcia Lopez', $last);
    }

    public function test_split_name_returns_empty_last_name_for_mononym(): void
    {
        [$first, $last] = $this->service->splitName('Sukarno', null, null);
        $this->assertEquals('Sukarno', $first);
        $this->assertEquals('', $last);
    }

    // -------------------------------------------------------------------------
    // identifyPostcodes tests
    // -------------------------------------------------------------------------

    public function test_identify_postcodes_uses_saved_address_lookup(): void
    {
        $user = $this->createTestUser();

        // Insert a locations record for the postcode
        DB::insert(
            "INSERT INTO locations (name, type) VALUES ('SW1A 1AA', 'Postcode')"
        );
        $locationId = DB::getPdo()->lastInsertId();

        // Insert paf_addresses record pointing to that location
        DB::insert(
            'INSERT INTO paf_addresses (postcodeid) VALUES (?)',
            [$locationId]
        );
        $pafId = DB::getPdo()->lastInsertId();

        // Link the user to that address
        DB::insert(
            'INSERT INTO users_addresses (userid, pafid) VALUES (?, ?)',
            [$user->id, $pafId]
        );

        // Gift aid record with no postcode, but homeaddress contains the postcode
        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, homeaddress, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'Test User', '1 Downing Street SW1A 1AA London', NOW(), NOW())",
            [$user->id]
        );

        $found = $this->service->identifyPostcodes();

        $this->assertGreaterThanOrEqual(1, $found);
        $postcode = DB::table('giftaid')->where('userid', $user->id)->value('postcode');
        $this->assertEquals('SW1A 1AA', $postcode);

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
        DB::delete('DELETE FROM users_addresses WHERE userid = ?', [$user->id]);
        DB::delete('DELETE FROM paf_addresses WHERE id = ?', [$pafId]);
        DB::delete('DELETE FROM locations WHERE id = ?', [$locationId]);
    }

    public function test_identify_postcodes_falls_back_to_regex(): void
    {
        $user = $this->createTestUser();

        // Gift aid record with no postcode and no saved addresses; postcode in homeaddress text
        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, homeaddress, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'Regex User', '42 High Street, Manchester M1 1AA', NOW(), NOW())",
            [$user->id]
        );

        $found = $this->service->identifyPostcodes();

        $this->assertGreaterThanOrEqual(1, $found);
        $postcode = DB::table('giftaid')->where('userid', $user->id)->value('postcode');
        $this->assertEquals('M1 1AA', $postcode);

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
    }

    // -------------------------------------------------------------------------
    // generateClaim dry run tests
    // -------------------------------------------------------------------------

    public function test_generate_claim_outputs_header_and_row(): void
    {
        $user = $this->createTestUser();

        // Create a reviewed gift aid declaration
        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, homeaddress, postcode, housenameornumber, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'John Smith', '1 Test St, London', 'SW1A 1AA', '1', NOW(), NOW())",
            [$user->id]
        );

        $giftaidId = DB::table('giftaid')->where('userid', $user->id)->value('id');

        // Create a claimable donation
        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 10.00, 1, NULL, 'Stripe', NOW(), 'test@example.com')",
            [$user->id]
        );

        $rows = [];
        $result = $this->service->generateClaim(
            dryRun: true,
            rowCallback: function (array $row) use (&$rows) {
                $rows[] = $row;
            }
        );

        // Should have header + 1 data row
        $this->assertCount(2, $rows);
        $this->assertEquals('First name or initial', $rows[0][1]);
        $this->assertEquals('John', $rows[1][1]);
        $this->assertEquals('Smith', $rows[1][2]);
        $this->assertEquals(1, $result['rows']);
        $this->assertEquals(0, $result['invalid']);
        $this->assertEquals(10.0, $result['total']);

        // Dry run: donation should NOT be marked as claimed
        $claimed = DB::table('users_donations')
            ->where('userid', $user->id)
            ->value('giftaidclaimed');
        $this->assertNull($claimed);

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
        DB::delete('DELETE FROM users_donations WHERE userid = ?', [$user->id]);
    }

    public function test_generate_claim_marks_donations_claimed_when_not_dry_run(): void
    {
        $user = $this->createTestUser();

        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, homeaddress, postcode, housenameornumber, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'Jane Brown', '5 High St', 'EC1A 1BB', '5', NOW(), NOW())",
            [$user->id]
        );

        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 25.00, 1, NULL, 'DonateWithPayPal', NOW(), 'jane@example.com')",
            [$user->id]
        );

        $result = $this->service->generateClaim(dryRun: false);

        $this->assertGreaterThanOrEqual(1, $result['rows']);

        // Donation should now be marked claimed
        $claimed = DB::table('users_donations')
            ->where('userid', $user->id)
            ->whereNotNull('giftaidclaimed')
            ->exists();
        $this->assertTrue($claimed);

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
        DB::delete('DELETE FROM users_donations WHERE userid = ?', [$user->id]);
    }

    public function test_generate_claim_uses_firstname_lastname_columns_when_set(): void
    {
        $user = $this->createTestUser();

        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, firstname, lastname, homeaddress, postcode, housenameornumber, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'Firstname Lastname', 'Budi', 'Santoso', '7 Test Road', 'W1A 0AX', '7', NOW(), NOW())",
            [$user->id]
        );

        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 5.00, 1, NULL, 'Stripe', NOW(), 'budi@example.com')",
            [$user->id]
        );

        $rows = [];
        $this->service->generateClaim(
            dryRun: true,
            rowCallback: function (array $row) use (&$rows) {
                $rows[] = $row;
            }
        );

        // Header + 1 data row
        $this->assertCount(2, $rows);
        $this->assertEquals('Budi', $rows[1][1]);
        $this->assertEquals('Santoso', $rows[1][2]);

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
        DB::delete('DELETE FROM users_donations WHERE userid = ?', [$user->id]);
    }

    public function test_generate_claim_invalidates_record_without_house_number(): void
    {
        $user = $this->createTestUser();

        // No housenameornumber and no postcode pattern that can be extracted
        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, homeaddress, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'Ann Lee', 'Flat unknown, nowhere', NOW(), NOW())",
            [$user->id]
        );

        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 15.00, 1, NULL, 'Stripe', NOW(), 'ann@example.com')",
            [$user->id]
        );

        $result = $this->service->generateClaim(dryRun: false);

        $this->assertEquals(1, $result['invalid']);
        $this->assertEquals(0, $result['rows']);

        // reviewed should now be NULL (reset for review)
        $reviewed = DB::table('giftaid')
            ->where('userid', $user->id)
            ->value('reviewed');
        $this->assertNull($reviewed);

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
        DB::delete('DELETE FROM users_donations WHERE userid = ?', [$user->id]);
    }

    public function test_generate_claim_skips_duplicate_donations_same_amount_same_day(): void
    {
        $user = $this->createTestUser();

        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, homeaddress, postcode, housenameornumber, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'Tom Jones', '3 Main Rd', 'CF10 1AA', '3', NOW(), NOW())",
            [$user->id]
        );

        // Two identical donations on same day — only one should appear in output
        $today = now()->format('Y-m-d H:i:s');
        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 20.00, 1, NULL, 'Stripe', ?, 'tom@example.com')",
            [$user->id, $today]
        );
        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 20.00, 1, NULL, 'Stripe', ?, 'tom@example.com')",
            [$user->id, $today]
        );

        $rows = [];
        $result = $this->service->generateClaim(
            dryRun: true,
            rowCallback: function (array $row) use (&$rows) {
                $rows[] = $row;
            }
        );

        // header + 1 data row (duplicate skipped)
        $this->assertCount(2, $rows);
        $this->assertEquals(1, $result['rows']);

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
        DB::delete('DELETE FROM users_donations WHERE userid = ?', [$user->id]);
    }

    // -------------------------------------------------------------------------
    // end-date filter
    // -------------------------------------------------------------------------

    public function test_generate_claim_excludes_donations_after_end_date(): void
    {
        $user = $this->createTestUser();

        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, homeaddress, postcode, housenameornumber, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'Eve End', '8 Cutoff Way', 'EC2A 3AA', '8', NOW(), NOW())",
            [$user->id]
        );

        // Two donations: one before the cutoff and one after.
        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 11.00, 1, NULL, 'Stripe', '2026-03-31 23:30:00', 'eve@example.com')",
            [$user->id]
        );
        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 22.00, 1, NULL, 'Stripe', '2026-04-01 00:30:00', 'eve@example.com')",
            [$user->id]
        );

        $rows = [];
        $result = $this->service->generateClaim(
            dryRun: true,
            rowCallback: function (array $row) use (&$rows) {
                $rows[] = $row;
            },
            outputPath: null,
            endDate: '2026-03-31'
        );

        // Header + only the pre-cutoff donation
        $this->assertCount(2, $rows);
        $this->assertEquals(1, $result['rows']);
        $this->assertEquals(11.0, $result['total']);
        $this->assertEquals('11.00', $rows[1][8]);

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
        DB::delete('DELETE FROM users_donations WHERE userid = ?', [$user->id]);
    }

    public function test_generate_claim_includes_donations_on_end_date_inclusive(): void
    {
        $user = $this->createTestUser();

        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, homeaddress, postcode, housenameornumber, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'Ian Inclusive', '9 Edge St', 'BS1 4AA', '9', NOW(), NOW())",
            [$user->id]
        );

        // Donation right at the very end of the cutoff day
        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 7.00, 1, NULL, 'Stripe', '2026-03-31 23:59:59', 'ian@example.com')",
            [$user->id]
        );

        $rows = [];
        $result = $this->service->generateClaim(
            dryRun: true,
            rowCallback: function (array $row) use (&$rows) {
                $rows[] = $row;
            },
            outputPath: null,
            endDate: '2026-03-31'
        );

        $this->assertCount(2, $rows);
        $this->assertEquals(1, $result['rows']);

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
        DB::delete('DELETE FROM users_donations WHERE userid = ?', [$user->id]);
    }

    // -------------------------------------------------------------------------
    // duplicate donations marked claimed
    // -------------------------------------------------------------------------

    public function test_generate_claim_marks_skipped_duplicate_as_claimed(): void
    {
        // Mirrors V1 donations_giftaid_claim.php: duplicates are skipped from the
        // CSV but still flagged claimed so they don't resurface on the next run.
        $user = $this->createTestUser();

        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, homeaddress, postcode, housenameornumber, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'Dee Duplicate', '11 Mirror Rd', 'NW1 6AA', '11', NOW(), NOW())",
            [$user->id]
        );

        $today = now()->format('Y-m-d H:i:s');
        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 8.00, 1, NULL, 'Stripe', ?, 'dee@example.com')",
            [$user->id, $today]
        );
        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 8.00, 1, NULL, 'Stripe', ?, 'dee@example.com')",
            [$user->id, $today]
        );

        $result = $this->service->generateClaim(dryRun: false);

        // 1 row in CSV, but BOTH donations should be marked claimed.
        $this->assertEquals(1, $result['rows']);

        $unclaimed = DB::table('users_donations')
            ->where('userid', $user->id)
            ->whereNull('giftaidclaimed')
            ->count();
        $this->assertEquals(0, $unclaimed, 'Both donations (including the skipped duplicate) must be marked claimed');

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
        DB::delete('DELETE FROM users_donations WHERE userid = ?', [$user->id]);
    }

    // -------------------------------------------------------------------------
    // correctUserIdInDonations
    // -------------------------------------------------------------------------

    public function test_correct_user_id_in_donations_links_anonymous_donations_via_payer_email(): void
    {
        $user = $this->createTestUser();

        $email = 'orphan-donor-' . $user->id . '@example.com';
        DB::insert(
            'INSERT INTO users_emails (userid, email) VALUES (?, ?)',
            [$user->id, $email]
        );

        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, source, timestamp, Payer)
             VALUES (NULL, 12.50, 0, 'Stripe', NOW(), ?)",
            [$email]
        );

        $found = $this->service->correctUserIdInDonations();

        $this->assertGreaterThanOrEqual(1, $found);
        $linked = DB::table('users_donations')
            ->where('Payer', $email)
            ->value('userid');
        $this->assertEquals($user->id, $linked);

        // Cleanup
        DB::delete('DELETE FROM users_donations WHERE Payer = ?', [$email]);
        DB::delete('DELETE FROM users_emails WHERE email = ?', [$email]);
    }

    public function test_correct_user_id_skips_donations_without_matching_email(): void
    {
        $user = $this->createTestUser();

        $email = 'no-match-' . $user->id . '@example.com';
        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, source, timestamp, Payer)
             VALUES (NULL, 5.00, 0, 'Stripe', NOW(), ?)",
            [$email]
        );

        $this->service->correctUserIdInDonations();

        $userid = DB::table('users_donations')->where('Payer', $email)->value('userid');
        $this->assertNull($userid);

        // Cleanup
        DB::delete('DELETE FROM users_donations WHERE Payer = ?', [$email]);
    }

    // -------------------------------------------------------------------------
    // postcode canonical-form lookup
    // -------------------------------------------------------------------------

    public function test_identify_postcodes_uses_locations_canonical_name_for_regex_hit(): void
    {
        $user = $this->createTestUser();

        // Locations table holds the canonical form with a single space
        DB::insert(
            "INSERT INTO locations (name, type) VALUES ('M1 1AA', 'Postcode')"
        );
        $locationId = DB::getPdo()->lastInsertId();

        // Gift aid record where homeaddress contains the postcode without a space
        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, homeaddress, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'Pat Postcode', '4 Canon St, Manchester M11AA', NOW(), NOW())",
            [$user->id]
        );

        $found = $this->service->identifyPostcodes();

        $this->assertGreaterThanOrEqual(1, $found);
        $stored = DB::table('giftaid')->where('userid', $user->id)->value('postcode');
        $this->assertEquals('M1 1AA', $stored, 'Should store canonical form from locations table');

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
        DB::delete('DELETE FROM locations WHERE id = ?', [$locationId]);
    }

    public function test_identify_postcodes_skips_when_no_matching_location(): void
    {
        $user = $this->createTestUser();

        // homeaddress contains a syntactically valid postcode that doesn't exist in locations
        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, homeaddress, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'Nope Nobody', '1 Made Up St ZZ99 9ZZ', NOW(), NOW())",
            [$user->id]
        );

        $this->service->identifyPostcodes();

        $stored = DB::table('giftaid')->where('userid', $user->id)->value('postcode');
        $this->assertNull($stored, 'Postcode not in locations should not be stored');

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
    }
}
