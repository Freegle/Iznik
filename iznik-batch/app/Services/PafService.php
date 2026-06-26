<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles importing and updating PAF (Postal Address File) data.
 * PAF is the Royal Mail database of UK postal addresses.
 *
 * CSV field order (0-indexed):
 *  0: postcode, 1: posttown, 2: dependent_locality, 3: double_dependent_locality,
 *  4: thoroughfare_descriptor, 5: dependent_thoroughfare_descriptor,
 *  6: building_number, 7: building_name, 8: sub_building_name,
 *  9: po_box, 10: department_name, 11: organisation_name,
 *  12: udprn, 13: postcode_type, 14: su_organisation_indicator, 15: delivery_point_suffix
 */
class PafService
{
    private array $cache = [];

    private const FIELD_TYPES_1 = [
        'posttown', 'dependentlocality', 'doubledependentlocality',
        'thoroughfaredescriptor', 'dependentthoroughfaredescriptor',
    ];

    private const FIELD_TYPES_2 = [
        'buildingname', 'subbuildingname', 'pobox', 'departmentname', 'organisationname',
    ];

    private function getRefId(string $table, string $column, ?string $value): ?int
    {
        if (!$value || !strlen(trim($value))) {
            return null;
        }

        $cacheKey = "{$table}.{$value}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $row = DB::table($table)->where($column, $value)->first(['id']);

        if ($row) {
            $id = $row->id;
        } else {
            $id = DB::table($table)->insertGetId([$column => $value]);
        }

        $this->cache[$cacheKey] = $id;
        return $id;
    }

    private function findPostcodeId(string $postcode): ?int
    {
        $normalised = strtoupper(trim($postcode));
        $row = DB::table('locations')
            ->where('type', 'Postcode')
            ->where('name', $normalised)
            ->first(['id']);

        return $row?->id;
    }

    /**
     * Process a PAF CSV file (initial load), inserting new records.
     * Returns [processed, inserted, unknown_postcodes] counts.
     */
    public function load(string $inputPath, callable $progress = null): array
    {
        if (!file_exists($inputPath)) {
            throw new \InvalidArgumentException("Input file not found: {$inputPath}");
        }

        $fh = fopen($inputPath, 'r');
        if (!$fh) {
            throw new \RuntimeException("Cannot open file: {$inputPath}");
        }

        $this->cache = [];
        $processed = 0;
        $inserted = 0;
        $unknownPostcodes = [];

        while (($line = fgets($fh)) !== false) {
            $fields = str_getcsv(trim($line));

            if (count($fields) < 16) {
                $processed++;
                continue;
            }

            $postcodeId = $this->findPostcodeId($fields[0]);

            if (!$postcodeId) {
                if (!in_array($fields[0], $unknownPostcodes, true)) {
                    $unknownPostcodes[] = $fields[0];
                    Log::warning('PafService: unknown postcode', ['postcode' => $fields[0]]);
                }
                $processed++;
                continue;
            }

            $udprn = (int) $fields[12];

            $existing = DB::table('paf_addresses')->where('udprn', $udprn)->exists();
            if (!$existing) {
                $ix = 1;
                $idFields1 = [];
                foreach (self::FIELD_TYPES_1 as $field) {
                    $idFields1["{$field}id"] = $this->getRefId("paf_{$field}", $field, $fields[$ix++] ?? null);
                }

                $ix = 7;
                $idFields2 = [];
                foreach (self::FIELD_TYPES_2 as $field) {
                    $idFields2["{$field}id"] = $this->getRefId("paf_{$field}", $field, $fields[$ix++] ?? null);
                }

                DB::table('paf_addresses')->insert(array_merge([
                    'postcodeid'      => $postcodeId,
                    'udprn'           => $udprn,
                    'buildingnumber'  => ($fields[6] !== '') ? (int) $fields[6] : null,
                    'postcodetype'    => $fields[13] ?? null,
                    'suorganisationindicator' => $fields[14] ?? null,
                    'deliverypointsuffix'     => $fields[15] ?? null,
                ], $idFields1, $idFields2));

                $inserted++;
            }

            $processed++;

            if ($progress && $processed % 1000 === 0) {
                ($progress)($processed, $inserted);
            }
        }

        fclose($fh);
        $this->cache = [];

        return [$processed, $inserted, $unknownPostcodes];
    }

    /**
     * Process a PAF CSV file (update pass), updating changed records.
     */
    public function update(string $inputPath, callable $progress = null): array
    {
        if (!file_exists($inputPath)) {
            throw new \InvalidArgumentException("Input file not found: {$inputPath}");
        }

        $fh = fopen($inputPath, 'r');
        if (!$fh) {
            throw new \RuntimeException("Cannot open file: {$inputPath}");
        }

        $this->cache = [];
        $processed = 0;
        $updated = 0;

        while (($line = fgets($fh)) !== false) {
            $fields = str_getcsv(trim($line));

            if (count($fields) < 16) {
                $processed++;
                continue;
            }

            $postcodeId = $this->findPostcodeId($fields[0]);

            if (!$postcodeId) {
                $processed++;
                continue;
            }

            $udprn = (int) $fields[12];
            $ix = 1;
            $idFields1 = [];
            foreach (self::FIELD_TYPES_1 as $field) {
                $idFields1["{$field}id"] = $this->getRefId("paf_{$field}", $field, $fields[$ix++] ?? null);
            }

            $ix = 7;
            $idFields2 = [];
            foreach (self::FIELD_TYPES_2 as $field) {
                $idFields2["{$field}id"] = $this->getRefId("paf_{$field}", $field, $fields[$ix++] ?? null);
            }

            $newValues = array_merge([
                'postcodeid'      => $postcodeId,
                'buildingnumber'  => ($fields[6] !== '') ? (int) $fields[6] : null,
                'postcodetype'    => $fields[13] ?? null,
                'suorganisationindicator' => $fields[14] ?? null,
                'deliverypointsuffix'     => $fields[15] ?? null,
            ], $idFields1, $idFields2);

            $changed = DB::table('paf_addresses')
                ->where('udprn', $udprn)
                ->update($newValues);

            if ($changed) {
                $updated++;
            }

            $processed++;

            if ($progress && $processed % 1000 === 0) {
                ($progress)($processed, $updated);
            }
        }

        fclose($fh);
        $this->cache = [];

        return [$processed, $updated];
    }

    /**
     * Preprocess a raw PAF CSV: replace empty fields with \N (MySQL NULL sentinel).
     */
    public function preprocessCsv(string $inputPath, string $outputPath): int
    {
        if (!file_exists($inputPath)) {
            throw new \InvalidArgumentException("Input file not found: {$inputPath}");
        }

        $fhIn = fopen($inputPath, 'r');
        $fhOut = fopen($outputPath, 'w');
        $lines = 0;

        while (($line = fgets($fhIn)) !== false) {
            $line = str_replace(',,', ',\N,', $line);
            $line = str_replace(',,', ',\N,', $line);
            $line = preg_replace('/,$/', ',\N', $line);
            $line = str_replace('" "', '\N', $line);
            fwrite($fhOut, $line);
            $lines++;
        }

        fclose($fhIn);
        fclose($fhOut);

        return $lines;
    }
}
