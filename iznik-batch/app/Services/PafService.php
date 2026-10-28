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

        // From the write node, like refIds: a value added a moment ago may not have reached a read
        // node yet, and inserting it again would break the unique index.
        $row = DB::table($table)->useWritePdo()->where($column, $value)->first(['id']);

        if ($row) {
            $id = $row->id;
        } else {
            DB::table($table)->insertOrIgnore([$column => $value]);
            $id = DB::table($table)->useWritePdo()->where($column, $value)->value('id');
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
    public function load(string $inputPath, ?callable $progress = null): array
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

            // Every PAF line has a numeric UDPRN. A line without one is from some other file:
            // a postcode directory loaded this way once put 1.9M non-addresses in paf_addresses.
            if (count($fields) < 16 || !ctype_digit(trim($fields[12]))) {
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
    public function update(string $inputPath, ?callable $progress = null): array
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

            // Every PAF line has a numeric UDPRN. A line without one is from some other file:
            // a postcode directory loaded this way once put 1.9M non-addresses in paf_addresses.
            if (count($fields) < 16 || !ctype_digit(trim($fields[12]))) {
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

    /** Values remembered per lookup table before the cache is emptied, to bound memory. */
    private const REF_CACHE_LIMIT = 200000;

    /** @var array<string, array<string, int>> lookup field => value => id */
    private array $refCache = [];

    /**
     * Apply a batch of CSV PAF lines, as paf:import-run does: insert addresses not seen
     * before and update those that have changed, matching on UDPRN. A whole batch costs a
     * handful of queries, where load() and update() cost several per line.
     *
     * Lines whose postcode is not in locations yet are returned unapplied, so the caller
     * can keep them and try again with the next release.
     *
     * @param string[] $lines
     * @return array{inserted: int, updated: int, skipped: string[]}
     */
    public function applyLines(array $lines): array
    {
        $parsed = [];
        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");
            $fields = array_map('trim', str_getcsv($line));
            if (count($fields) < 16 || !ctype_digit($fields[12])) {
                // Not an address line. Nothing to retry.
                continue;
            }
            $parsed[] = [$line, $fields];
        }

        $postcodes = $this->postcodeIds(array_map(fn($p) => $p[1][0], $parsed));

        $skipped = [];
        $known = [];
        foreach ($parsed as [$line, $f]) {
            $postcodeId = $postcodes[strtoupper($f[0])] ?? null;
            if ($postcodeId) {
                $known[] = [$postcodeId, $f];
            } else {
                $skipped[] = $line;
            }
        }

        // CSV column => lookup field. Joined with +, not array_merge, which would renumber
        // the columns and shift every field along by one.
        $refFields = array_combine(range(1, 5), self::FIELD_TYPES_1)
            + array_combine(range(7, 11), self::FIELD_TYPES_2);
        $refIds = [];
        foreach ($refFields as $ix => $field) {
            $refIds[$field] = $this->refIds($field, array_map(fn($k) => $k[1][$ix], $known));
        }

        $records = [];
        foreach ($known as [$postcodeId, $f]) {
            $record = [
                'udprn' => (int) $f[12],
                'postcodeid' => $postcodeId,
                'buildingnumber' => $f[6] !== '' ? (int) $f[6] : null,
                'postcodetype' => $f[13] !== '' ? $f[13] : null,
                'suorganisationindicator' => $f[14] !== '' ? $f[14] : null,
                'deliverypointsuffix' => $f[15] !== '' ? $f[15] : null,
            ];
            foreach ($refFields as $ix => $field) {
                $record["{$field}id"] = $f[$ix] !== '' ? $refIds[$field][$f[$ix]] : null;
            }

            // A UDPRN twice in one batch would be one row; the later line wins.
            $records[$record['udprn']] = $record;
        }

        $inserted = 0;
        $updated = 0;
        if ($records) {
            $udprns = array_keys($records);
            $existing = DB::table('paf_addresses')->whereIn('udprn', $udprns)->count();
            $columns = array_keys(reset($records));
            $affected = DB::table('paf_addresses')->upsert(
                array_values($records),
                ['udprn'],
                array_values(array_diff($columns, ['udprn']))
            );

            // MySQL counts 1 for each row inserted, 2 for each updated, 0 for one already
            // the same.
            $inserted = count($records) - $existing;
            $updated = intdiv(max(0, $affected - $inserted), 2);
        }

        return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @param string[] $postcodes
     * @return array<string, int> upper-cased postcode => locations.id
     */
    private function postcodeIds(array $postcodes): array
    {
        $wanted = array_values(array_unique(array_map('strtoupper', array_filter($postcodes))));
        if (!$wanted) {
            return [];
        }

        $ids = [];
        foreach (DB::table('locations')
            ->where('type', 'Postcode')
            ->whereIn('name', $wanted)
            ->get(['id', 'name']) as $row) {
            $ids[strtoupper($row->name)] = $row->id;
        }

        return $ids;
    }

    /**
     * The ids in a paf_<field> lookup table for some values, adding any that are new.
     *
     * @param string[] $values
     * @return array<string, int> value => id
     */
    private function refIds(string $field, array $values): array
    {
        $table = "paf_{$field}";
        $cache = $this->refCache[$field] ?? [];
        if (count($cache) > self::REF_CACHE_LIMIT) {
            $cache = [];
        }

        $missing = [];
        foreach (array_unique($values) as $value) {
            if ($value !== '' && !isset($cache[$value])) {
                $missing[] = $value;
            }
        }

        if ($missing) {
            $found = $this->selectRefs($table, $field, $missing);
            $new = array_values(array_filter($missing, fn($v) => !isset($found[$v])));

            if ($new) {
                DB::table($table)->insertOrIgnore(array_map(fn($v) => [$field => $v], $new));
                $found += $this->selectRefs($table, $field, $new);
            }

            foreach ($missing as $value) {
                // The unique index's collation can fold values the lookup above did not
                // (accents, say). One query each for those is fine; they are rare.
                $cache[$value] = $found[$value] ?? $this->getRefId($table, $field, $value);
            }
        }

        $this->refCache[$field] = $cache;

        return $cache;
    }

    /**
     * @param string[] $values
     * @return array<string, int> value => id, matching case-insensitively as the
     *                            unique index does
     *
     * Read from the write node. refIds reads again straight after inserting the new values, and a
     * read node that is behind does not have them yet: the fallback would then insert them a second
     * time and fail on the unique index ("Duplicate entry ... for key paf_departmentname...").
     */
    private function selectRefs(string $table, string $field, array $values): array
    {
        $byLower = [];
        foreach (DB::table($table)->useWritePdo()->whereIn($field, $values)->get(['id', $field]) as $row) {
            $byLower[mb_strtolower($row->$field)] = $row->id;
        }

        $ids = [];
        foreach ($values as $value) {
            $id = $byLower[mb_strtolower($value)] ?? null;
            if ($id) {
                $ids[$value] = $id;
            }
        }

        return $ids;
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
