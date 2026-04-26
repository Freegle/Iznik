<?php

namespace App\Services;

use App\Models\GiftAid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generates HMRC Gift Aid claim CSVs from reviewed, unclaimed donation records.
 *
 * This is a Laravel port of iznik-server/scripts/cli/donations_giftaid_claim.php.
 * The claim CSV format matches HMRC's Gift Aid schedule spreadsheet layout.
 */
class GiftAidClaimService
{
    /** Earliest date from which Gift Aid can be claimed. */
    private const GIFT_AID_EARLIEST_DATE = '2016-04-06';

    /**
     * Identify and store postcodes for giftaid records that lack one.
     *
     * First checks the user's saved addresses (users_addresses → paf_addresses → locations)
     * for a postcode that appears in their homeaddress text. Falls back to UK postcode
     * regex extraction from homeaddress if no saved address matches. Any candidate
     * found is validated against the locations table (canonical name) before storing,
     * matching V1 Donations::identifyGiftAidPostcode().
     */
    public function identifyPostcodes(): int
    {
        $found = 0;

        $records = DB::select(
            'SELECT id, userid, homeaddress FROM giftaid WHERE postcode IS NULL AND deleted IS NULL'
        );

        foreach ($records as $record) {
            $candidate = $this->findPostcodeFromSavedAddresses($record->userid, $record->homeaddress)
                ?? $this->extractPostcodeFromAddress($record->homeaddress);

            if ($candidate === null) {
                continue;
            }

            $canonical = $this->lookupCanonicalPostcode($candidate);
            if ($canonical === null) {
                continue;
            }

            DB::update('UPDATE giftaid SET postcode = ? WHERE id = ?', [$canonical, $record->id]);
            $found++;
        }

        return $found;
    }

    /**
     * Validate a postcode candidate against the locations table and return the
     * canonical name. Mirrors V1 Location::typeahead() — exact match preferred,
     * else first prefix match.
     */
    private function lookupCanonicalPostcode(string $candidate): ?string
    {
        $normalised = strtoupper(preg_replace('/\s+/', ' ', trim($candidate)));

        $exact = DB::select(
            "SELECT name FROM locations WHERE type = 'Postcode' AND name = ? LIMIT 1",
            [$normalised]
        );
        if (!empty($exact)) {
            return $exact[0]->name;
        }

        $prefix = DB::select(
            "SELECT name FROM locations WHERE type = 'Postcode' AND name LIKE ? ORDER BY name LIMIT 1",
            [$normalised.'%']
        );
        if (!empty($prefix)) {
            return $prefix[0]->name;
        }

        return null;
    }

    /**
     * Look up the user's saved addresses and return any postcode that appears in homeaddress.
     */
    private function findPostcodeFromSavedAddresses(int $userid, string $homeaddress): ?string
    {
        $postcodes = DB::select(
            'SELECT l.name AS postcode
             FROM users_addresses ua
             JOIN paf_addresses pa ON ua.pafid = pa.id
             JOIN locations l ON pa.postcodeid = l.id
             WHERE ua.userid = ? AND l.type = ?',
            [$userid, 'Postcode']
        );

        foreach ($postcodes as $row) {
            if (stripos($homeaddress, $row->postcode) !== false) {
                return $row->postcode;
            }
        }

        return null;
    }

    /**
     * Identify and store house names/numbers for giftaid records that lack one.
     *
     * Uses a regex to find a leading house number (possibly with letter suffix)
     * from homeaddress.
     */
    public function identifyHouseNumbers(): int
    {
        $found = 0;

        $records = DB::select(
            'SELECT id, homeaddress FROM giftaid WHERE housenameornumber IS NULL AND deleted IS NULL'
        );

        foreach ($records as $record) {
            if (preg_match('/^([\d\/\-]+[a-z]{0,1})[\w\s]/im', $record->homeaddress, $matches)) {
                $number = trim($matches[0]);
                DB::update(
                    'UPDATE giftaid SET housenameornumber = ? WHERE id = ?',
                    [$number, $record->id]
                );
                $found++;
            }
        }

        return $found;
    }

    /**
     * Link anonymous donations (userid IS NULL) to a user when their `Payer`
     * email matches a known users_emails row. Mirrors V1
     * Donations::correctUserIdInDonations(). Without this, donations made
     * before a user signed up — or after they leave and rejoin — never get
     * their userid backfilled and so are never gift-aided.
     */
    public function correctUserIdInDonations(): int
    {
        $missing = DB::select(
            "SELECT users_emails.userid AS emailid, users_donations.id AS donationid
             FROM users_donations
             INNER JOIN users_emails ON users_emails.email = users_donations.Payer
             WHERE users_donations.userid IS NULL
               AND users_donations.Payer != ''
               AND users_emails.email != ''"
        );

        foreach ($missing as $m) {
            DB::update(
                'UPDATE users_donations SET userid = ? WHERE id = ?',
                [$m->emailid, $m->donationid]
            );
        }

        return count($missing);
    }

    /**
     * Mark user_donations as giftaidconsent=1 based on each user's gift aid period.
     *
     * Mirrors the V1 identifyGiftAidedDonations() logic.
     */
    public function identifyGiftAidedDonations(): int
    {
        $found = 0;

        $giftaids = DB::select('SELECT * FROM giftaid WHERE reviewed IS NOT NULL');

        foreach ($giftaids as $giftaid) {
            $rows = match ($giftaid->period) {
                'Past4YearsAndFuture' => DB::update(
                    'UPDATE users_donations SET giftaidconsent = 1
                     WHERE userid = ? AND giftaidconsent = 0 AND timestamp >= ?',
                    [$giftaid->userid, now()->subYears(4)->format('Y-m-d')]
                ),
                'Since' => DB::update(
                    'UPDATE users_donations SET giftaidconsent = 1
                     WHERE userid = ? AND giftaidconsent = 0 AND timestamp >= ?',
                    [$giftaid->userid, self::GIFT_AID_EARLIEST_DATE]
                ),
                'This' => DB::update(
                    'UPDATE users_donations SET giftaidconsent = 1
                     WHERE userid = ? AND giftaidconsent = 0
                       AND timestamp >= ? AND date(timestamp) = ?',
                    [
                        $giftaid->userid,
                        self::GIFT_AID_EARLIEST_DATE,
                        date('Y-m-d', strtotime($giftaid->timestamp)),
                    ]
                ),
                'Future' => DB::update(
                    'UPDATE users_donations SET giftaidconsent = 1
                     WHERE userid = ? AND giftaidconsent = 0
                       AND timestamp >= ? AND date(timestamp) >= ?',
                    [
                        $giftaid->userid,
                        self::GIFT_AID_EARLIEST_DATE,
                        date('Y-m-d', strtotime($giftaid->timestamp)),
                    ]
                ),
                default => 0,
            };

            $found += $rows;
        }

        return $found;
    }

    /**
     * Generate the HMRC Gift Aid claim CSV.
     *
     * @param  bool  $dryRun  When true, no DB writes are performed.
     * @param  callable|null  $rowCallback  Called for each output row (array of strings).
     *                                      Used when writing to stdout row-by-row.
     * @param  string|null  $outputPath  Write CSV to this file; null means use $rowCallback.
     * @param  string|null  $endDate  Only include donations on or before this YYYY-MM-DD (inclusive).
     * @return array{rows: int, invalid: int, total: float}
     */
    public function generateClaim(bool $dryRun, ?callable $rowCallback = null, ?string $outputPath = null, ?string $endDate = null): array
    {
        $this->identifyPostcodes();
        $this->identifyHouseNumbers();
        $this->correctUserIdInDonations();
        $this->identifyGiftAidedDonations();

        $sql = "
            SELECT users_donations.*,
                   giftaid.id AS giftaidid,
                   giftaid.fullname,
                   giftaid.firstname,
                   giftaid.lastname,
                   giftaid.postcode,
                   giftaid.housenameornumber,
                   giftaid.timestamp AS declarationdate
            FROM users_donations
            INNER JOIN giftaid ON users_donations.userid = giftaid.userid
            WHERE giftaidconsent = 1
              AND giftaidclaimed IS NULL
              AND giftaid.deleted IS NULL
              AND giftaid.reviewed IS NOT NULL
              AND GrossAmount > 0
              AND source IN ('DonateWithPayPal', 'Stripe')
        ";
        $bindings = [];
        if ($endDate !== null) {
            $sql .= ' AND users_donations.timestamp < ? ';
            $bindings[] = date('Y-m-d', strtotime($endDate.' +1 day'));
        }
        $sql .= ' ORDER BY users_donations.timestamp ASC';

        $donations = DB::select($sql, $bindings);

        $handle = null;
        if ($outputPath !== null) {
            $handle = fopen($outputPath, 'w');
        }

        $header = [
            'Title',
            'First name or initial',
            'Last name',
            'House name or number',
            'Postcode',
            'Aggregated donations',
            'Sponsored event',
            'Donation date',
            'Amount',
            'Email',
            'UserId',
            'GiftAidId',
            'Declaration date',
        ];

        $this->writeRow($header, $handle, $rowCallback);

        $rows = 0;
        $invalid = 0;
        $total = 0.0;
        $dups = [];

        foreach ($donations as $donation) {
            [$firstname, $lastname] = $this->splitName(
                $donation->fullname,
                $donation->firstname ?? null,
                $donation->lastname ?? null
            );

            if ($lastname === '' || !$donation->housenameornumber || !$donation->postcode) {
                Log::warning('Invalid gift aid donation reset for review', [
                    'userid' => $donation->userid,
                    'giftaidid' => $donation->giftaidid,
                    'reason' => $lastname === '' ? 'no_last_name' : ($donation->housenameornumber ? 'no_postcode' : 'no_house'),
                ]);

                if (!$dryRun) {
                    DB::update('UPDATE giftaid SET reviewed = NULL WHERE userid = ?', [$donation->userid]);
                }

                $invalid++;
                continue;
            }

            $date = date('d/m/y', strtotime($donation->timestamp));
            $key = "{$donation->userid}-{$donation->GrossAmount}-{$date}";

            if (isset($dups[$key])) {
                // Same user, same amount, same day — skipped from CSV but still
                // marked as claimed so it doesn't resurface on the next run.
                // Matches V1 donations_giftaid_claim.php behaviour.
                Log::info('Skipping duplicate donation', [
                    'userid' => $donation->userid,
                    'amount' => $donation->GrossAmount,
                    'date' => $date,
                ]);
            } else {
                $dups[$key] = true;

                $row = [
                    '',
                    $firstname,
                    $lastname,
                    $donation->housenameornumber . "\t",  // tab forces quoting in Excel
                    $donation->postcode,
                    '',
                    '',
                    $date,
                    $donation->GrossAmount,
                    $donation->Payer ?? '',
                    $donation->userid,
                    $donation->giftaidid,
                    date('d/m/y', strtotime($donation->declarationdate)),
                ];

                $this->writeRow($row, $handle, $rowCallback);
                $rows++;
                $total += (float) $donation->GrossAmount;
            }

            if (!$dryRun) {
                DB::update(
                    'UPDATE users_donations SET giftaidclaimed = NOW() WHERE id = ?',
                    [$donation->id]
                );
            }
        }

        if ($handle !== null) {
            fclose($handle);
        }

        return compact('rows', 'invalid', 'total');
    }

    /**
     * Determine first and last name.
     *
     * Prefers dedicated firstname/lastname columns. Falls back to splitting
     * fullname on the first space. Returns ['', ''] for unresolvable names.
     *
     * @return array{string, string}
     */
    public function splitName(string $fullname, ?string $firstname, ?string $lastname): array
    {
        if ($firstname !== null && $firstname !== '' && $lastname !== null && $lastname !== '') {
            return [$firstname, $lastname];
        }

        $spacePos = strpos($fullname, ' ');
        if ($spacePos === false) {
            return [$fullname, ''];
        }

        return [
            substr($fullname, 0, $spacePos),
            substr($fullname, $spacePos + 1),
        ];
    }

    /**
     * Write a CSV row either to a file handle or via callback (stdout).
     *
     * @param  string[]  $row
     */
    private function writeRow(array $row, mixed $handle, ?callable $rowCallback): void
    {
        if ($handle !== null) {
            fputcsv($handle, $row);
        } elseif ($rowCallback !== null) {
            $rowCallback($row);
        }
    }

    /**
     * Extract a UK postcode from the homeaddress text using regex.
     */
    private function extractPostcodeFromAddress(string $homeaddress): ?string
    {
        $pattern = '/([A-Z]{1,2}[0-9][0-9A-Z]?)\s*([0-9][A-Z]{2})/i';
        if (preg_match($pattern, $homeaddress, $matches)) {
            return strtoupper($matches[1] . ' ' . $matches[2]);
        }

        return null;
    }
}
